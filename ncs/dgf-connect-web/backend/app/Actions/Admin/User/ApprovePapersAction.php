<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Actions\Admin\UserFace\UpdateNewestUserFaceAction;
use App\Actions\Common\Poll\EvaluateUserAfterStaffNoAssignmentAction;
use App\Enums\ErrorCode;
use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationType;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperType;
use App\Events\UserPaperApproved;
use App\Mail\ApprovalNotificationEmail;
use App\Mail\RejectionNotificationEmail;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Services\External\CrossStaffApiService;
use App\Services\FaceRecognitionService;
use App\Services\User\DocumentStepService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ApprovePapersAction
{
    private FaceRecognitionService $faceRecognitionService;

    private EvaluateUserAfterStaffNoAssignmentAction $evaluateUserAfterStaffNoAssignmentAction;

    private SyncUserAvatarFromPortraitAction $syncUserAvatarFromPortraitAction;

    public function __construct(
        FaceRecognitionService $faceRecognitionService,
        EvaluateUserAfterStaffNoAssignmentAction $evaluateUserAfterStaffNoAssignmentAction,
        SyncUserAvatarFromPortraitAction $syncUserAvatarFromPortraitAction
    ) {
        $this->faceRecognitionService = $faceRecognitionService;
        $this->evaluateUserAfterStaffNoAssignmentAction = $evaluateUserAfterStaffNoAssignmentAction;
        $this->syncUserAvatarFromPortraitAction = $syncUserAvatarFromPortraitAction;
    }

    /**
     * @throws Throwable
     */
    public function __invoke(User $user, array $itemDecisions, ?string $rejectedNote = null): ActionResult
    {
        // Decisions are only valid on a complete submission. Deciding mid-cycle would
        // advance user_papers.approved_at and shrink the rejected-batch window that
        // GetCurrentRejectedDocumentStepKeysAction reconstructs for legacy users.
        if ($user->paper_status !== UserPaperStatus::WaitForApproval->value) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: [
                    'message' => 'Papers can only be processed while the user is waiting for approval',
                ]
            );
        }

        // Validate that all pending items are included in the request
        $pendingItems = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->get();

        $requestedItemIds = array_map('intval', array_column($itemDecisions, 'id'));

        $pendingIds = $pendingItems->pluck('id')->sort()->values()->toArray();
        $requestedIds = collect($requestedItemIds)->sort()->values()->toArray();

        if ($pendingIds !== $requestedIds) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: [
                    'message' => 'Decisions must cover exactly the pending items',
                    'pending_item_ids' => $pendingIds,
                ]
            );
        }

        $needAssignStaffNo = false;
        $shouldEvaluatePendingPolls = false;
        $hasRejection = false;

        // Check if any item is rejected
        foreach ($itemDecisions as $decision) {
            if (!$decision['approved']) {
                $hasRejection = true;
                break;
            }
        }

        // Determine final status based on item decisions
        $finalStatus = $hasRejection ? UserPaperStatus::Rejected : UserPaperStatus::Approved;

        // A 留学 file may not be approved until the student card expiry date is on the
        // employee detail: approval is what makes the employee rosterable, and the shift
        // checks read that date to refuse anyone whose enrolment has lapsed. A rejection
        // is let through — it sends the documents back rather than granting anything.
        //
        // Not for an account that signs in to the admin, which is reachable: an employee
        // whose file is already waiting for review can be promoted before it is decided, and
        // the file survives the role change untouched. It is never put on a shift, so the
        // date the hold exists to obtain is one nothing will read — and the screens draw the
        // same line, so holding the submit here would leave the admin with a modal that
        // warns about nothing and a button that only answers 422. Asked last, so the role
        // query is skipped on every file that was never going to be held.
        if (
            $finalStatus === UserPaperStatus::Approved &&
            app(ResolveMissingStudentCardExpiryAction::class)($user) &&
            !app(ResolveWebAdminAccessAction::class)($user)
        ) {
            return new ActionResult(
                error: ErrorCode::UserPaperStudentCardExpiryMissing,
                status: 422,
                data: [
                    'message' =>
                        'The student card expiry date must be filled in on the employee detail' .
                        ' before a study abroad file can be approved',
                ]
            );
        }

        $faceApprovalError = null;

        try {
            $user = DB::transaction(function () use (
                $user,
                $itemDecisions,
                $rejectedNote,
                $finalStatus,
                &$needAssignStaffNo,
                &$shouldEvaluatePendingPolls,
                &$faceApprovalError
            ) {
                $data = [
                    'paper_status' => $finalStatus,
                ];

                $wasPending = $user->paper_status === UserPaperStatus::Pending;

                // Only assign staff no if fully approved and user doesn't have one
                if ($finalStatus === UserPaperStatus::Approved && $user->staff_no === null) {
                    $needAssignStaffNo = true;
                    $data['staff_no'] = User::getNextStaffNo();
                }

                $user->update($data);
                $user->load('paper');
                $paperData = [
                    'approver_id' => Auth::id(),
                    'approved_at' => now(),
                    'rejected_note' => $finalStatus === UserPaperStatus::Rejected ? $rejectedNote : null,
                ];
                if ($finalStatus === UserPaperStatus::Approved) {
                    $paperData['submission_started_from_approved'] = false;

                    // Record what this approval covers, here and nowhere else. The profile
                    // carries the residence card that was just reviewed, so the status is
                    // the reviewed one by definition — the only moment that is true. A
                    // later renewal lets this stand in for documents it need not file
                    // again, and asking the question then would read a status the employee
                    // has since declared themselves.
                    $paperData['approved_step_keys'] = array_map(
                        fn(UserPaperStepKey $key) => $key->value,
                        app(DocumentStepService::class)->approvedStepKeys($user)
                    );
                }
                $user->paper->update($paperData);

                if ($finalStatus === UserPaperStatus::Approved && $wasPending) {
                    UserPaperApproved::dispatch($user);
                }

                $shouldEvaluatePendingPolls = $finalStatus === UserPaperStatus::Approved && $user->staff_no !== null;

                // Separate approved and rejected item IDs for batch updates
                $approvedItemIds = [];
                $rejectedItemIds = [];

                foreach ($itemDecisions as $decision) {
                    if ($decision['approved']) {
                        $approvedItemIds[] = $decision['id'];
                    } else {
                        $rejectedItemIds[] = $decision['id'];
                    }
                }

                // Batch update approved items
                if (!empty($approvedItemIds)) {
                    UserPaperItem::query()
                        ->whereIn('id', $approvedItemIds)
                        ->update([
                            'approved_at' => now(),
                            'folder' => UserPaperItemFolder::Current->value,
                        ]);
                }

                // Batch update rejected items
                if (!empty($rejectedItemIds)) {
                    UserPaperItem::query()
                        ->whereIn('id', $rejectedItemIds)
                        ->update([
                            'rejected_at' => now(),
                            'folder' => UserPaperItemFolder::Rejected->value,
                        ]);
                }

                $this->markStepsForReupload($user, $rejectedItemIds);

                // A full approval completes this submission; open every step again so the
                // employee can renew any document later (a renewal sends them back to review)
                //
                // The mismatch verdict describes the submission just decided, so it goes
                // with it: leaving it behind would keep the admin badge lit for a file that
                // has been dealt with, and the next submission recomputes its own.
                //
                // A rejection keeps it. Only the rejected steps reopen, and the documents
                // that disagreed are usually not among them — clearing the verdict here
                // would drop the warning for good whenever the replacement runs no OCR
                // (a portrait, a work permit), because nothing recomputes it on that road.
                if ($finalStatus === UserPaperStatus::Approved) {
                    UserPaperStep::query()->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))->delete();
                    $user->paper()->update(['document_mismatches' => null]);
                }

                // Only register user face if fully approved
                if ($finalStatus === UserPaperStatus::Approved) {
                    $updateAction = new UpdateNewestUserFaceAction($this->faceRecognitionService);
                    $faceResult = $updateAction($user->id);
                    $faceResultData = $faceResult->getData();

                    if ($faceResult->getError()) {
                        $userFace = $faceResultData['user_face'] ?? null;
                        $failedPortraits = $faceResultData['failed_portraits'] ?? [];

                        if (!empty($failedPortraits)) {
                            // Reject all failed portraits
                            UserPaperItem::query()
                                ->whereIn('id', $failedPortraits)
                                ->update([
                                    'rejected_at' => now(),
                                    'approved_at' => null,
                                    'folder' => UserPaperItemFolder::Rejected->value,
                                ]);

                            $this->markStepsForReupload($user, $failedPortraits);

                            if (!$userFace) {
                                // Store the error to return after transaction rollback
                                $faceApprovalError = new ActionResult(
                                    error: ErrorCode::UserFaceApprovalFailed,
                                    status: 422,
                                    data: [
                                        'message' =>
                                            'Failed to register user face. Please retry or reject the portrait image.',
                                        'failed_portrait_ids' => $failedPortraits,
                                    ]
                                );

                                Log::error('Failed to register user face. Do not approve', [
                                    'user_id' => $user->id,
                                    'failed_portraits' => $failedPortraits,
                                ]);

                                // Trigger rollback by throwing exception
                                throw new Exception('User face registration failed');
                            }
                        }
                    }
                }

                // Create notification
                $notificationType =
                    $finalStatus === UserPaperStatus::Approved
                        ? NotificationType::PAPER_APPROVED
                        : NotificationType::PAPER_REJECTED;

                $langKey = $finalStatus === UserPaperStatus::Approved ? 'paper_approved' : 'paper_rejected';

                $notification = Notification::query()->create([
                    'type' => $notificationType->value,
                    'title' => __("lang.{$langKey}.title"),
                    'content' => __("lang.{$langKey}.content"),
                    'icon' => NotificationIcon::INFO->value,
                    'scheduled_at' => now(),
                    'user_id' => $user->id,
                    'data' => null,
                ]);
                $notification->translations()->createMany(
                    array_map(
                        fn($lang) => [
                            'lang' => $lang,
                            'title' => __("lang.{$langKey}.title", locale: $lang),
                            'content' => __("lang.{$langKey}.content", locale: $lang),
                        ],
                        Locale::getTranslationLocales()
                    )
                );

                // Send email
                if ($finalStatus === UserPaperStatus::Approved) {
                    Mail::to($user->email)->send(new ApprovalNotificationEmail($user));
                } else {
                    Mail::to($user->email)->send(new RejectionNotificationEmail($user, $rejectedNote));
                }

                return $user;
            });
        } catch (Throwable $e) {
            // If we stored a face approval error, return it
            if ($faceApprovalError !== null) {
                return $faceApprovalError;
            }

            // Otherwise, re-throw the exception
            throw $e;
        }

        $user->refresh();

        // Not gated on $finalStatus: an approved portrait is no longer pending, so this
        // is the only round that lists it. Accepted gap: face registration runs only on
        // a full approval and may reject that portrait later, leaving the avatar out of
        // step with user_faces until the next one is approved. Display-only.
        if ($this->hasApprovedPortrait($user, $itemDecisions)) {
            ($this->syncUserAvatarFromPortraitAction)($user);
        }

        if ($needAssignStaffNo) {
            $apiService = new CrossStaffApiService();
            if ($user->cross_staff_id) {
                $error = $apiService->updateUser($user->cross_staff_id, ['staffNo' => $user->staff_no]);
                if ($error) {
                    Log::error('Failed to update staffNo in CrossStaff', [
                        'user_id' => $user->id,
                        'cross_staff_id' => $user->cross_staff_id,
                        'error' => $error,
                    ]);
                }
            } else {
                $user->load('info');
                $result = $apiService->createUser($user, $user->staff_no);
                if ($result['cross_staff_id']) {
                    $user->update(['cross_staff_id' => $result['cross_staff_id']]);
                }
                if ($result['error']) {
                    Log::error('Failed to create user in CrossStaff during paper approval', [
                        'user_id' => $user->id,
                        'error' => $result['error'],
                    ]);
                }
            }
        }

        if ($shouldEvaluatePendingPolls) {
            $this->evaluateUserAfterStaffNoAssignmentAction->__invoke($user);
        }

        return new ActionResult(data: true);
    }

    private function hasApprovedPortrait(User $user, array $itemDecisions): bool
    {
        $approvedItemIds = array_column(array_filter($itemDecisions, fn($decision) => $decision['approved']), 'id');

        if (empty($approvedItemIds)) {
            return false;
        }

        // Re-read the state after the transaction: face registration can have flipped a
        // portrait approved in this very batch to rejected.
        return UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->whereIn('id', $approvedItemIds)
            ->where('type', UserPaperType::PortraitImage->value)
            ->whereNotNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Rejecting a document re-opens its step in the employee step flow,
     * so the employee can upload and confirm it again.
     */
    private function markStepsForReupload(User $user, array $rejectedItemIds): void
    {
        if (empty($rejectedItemIds)) {
            return;
        }

        $stepKeys = UserPaperItem::query()
            ->whereIn('id', $rejectedItemIds)
            ->pluck('type')
            ->unique()
            ->map(fn(string $type) => UserPaperStepKey::fromPaperType($type)?->value)
            ->filter()
            ->values()
            ->all();

        if (empty($stepKeys)) {
            return;
        }

        $userPaperId = $user->paper()->value('id');
        if (!$userPaperId) {
            return;
        }

        app(DocumentStepService::class)->markStepsForReupload($userPaperId, $stepKeys);
    }
}
