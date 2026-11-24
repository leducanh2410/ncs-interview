<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperStep;
use App\Services\User\DocumentStepService;
use Illuminate\Support\Facades\DB;

/**
 * Sends an approved employee back to complete documents their residence status requires.
 *
 * An employee approved before a document became mandatory keeps working — nothing reopens
 * their status on its own, and the papers screen only tells the admin what is absent. This
 * is the admin acting on that: the file goes back to `pending`, which CheckUserPaperStatus
 * reads to close check-in, shift registration and salary until the set is complete.
 *
 * The one other write is the narrowing of `user_papers.approved_step_keys`: the documents
 * the resolver just reported absent stop counting as covered by the last approval, so an
 * employee with a renewal already open cannot answer the request by re-filing nothing.
 *
 * A 留学 file whose student card carries no expiry date is sent back for the same reason
 * even though nothing is absent: the date only reaches the profile through the confirm form
 * of a student card upload, so asking for the card again is the only way to obtain it.
 *
 * The employee's app switches its profile tab to the document overview for any status but
 * `approved`, listing every step the status still requires, and filing them flips the file
 * back to review through the ordinary completeness check. A file already waiting for review
 * is not the target: the admin decides that one through the approve modal, which can reject
 * individual documents and reopen exactly those steps.
 */
class RequestDocumentCompletionAction
{
    public function __construct(
        private readonly ResolveDocumentCompletionRequestAction $resolveDocumentCompletionRequest,
        private readonly NotifyDocumentsRequiredAction $notifyDocumentsRequired,
        private readonly DocumentStepService $stepService,
        private readonly ReopenOwedDocumentStepsAction $reopenOwedDocumentSteps
    ) {}

    public function __invoke(User $user): ActionResult
    {
        $resolved = ($this->resolveDocumentCompletionRequest)($user);
        if ($resolved->getError()) {
            return $resolved;
        }

        [
            'missing_steps' => $missing,
            'reopened_steps' => $reopen,
            'student_card_expiry_missing' => $studentCardExpiryMissing,
        ] = $resolved->getData();

        /** @var UserPaper $paper */
        $paper = $user->paper()->firstOrFail();

        $sent = DB::transaction(function () use ($user, $paper, $missing, $reopen, $studentCardExpiryMissing) {
            // Conditional update rather than acting on the status read above: the employee
            // may finish a complete submission in between, and overwriting their
            // wait_for_approval with pending strands the file — every step row is already
            // confirmed, so nothing can re-upload, complete it again, or approve it.
            $sent = User::query()
                ->whereKey($user->id)
                ->where('paper_status', UserPaperStatus::Approved->value)
                ->update(['paper_status' => UserPaperStatus::Pending->value]);

            if ($sent === 0) {
                return false;
            }

            // The resolver just proved, against the documents actually on file, that the
            // last approval never covered these. A renewal opened before this request would
            // otherwise inherit them and answer the admin by filing nothing.
            $this->stepService->excludeFromApprovedStepKeys($paper, $reopen);

            ($this->reopenOwedDocumentSteps)(
                $paper->id,
                $this->reopenOwedDocumentSteps->stepsStuckOnImagesOnFile($user, $missing)
            );

            // The student card is the one document already sitting in the file, so dropping
            // it from the approved set is not enough: the approved images still cover the
            // step for both the submit gate and the app's overview, and the employee would
            // be shown a card that needs nothing. The re-upload row is what makes the step
            // open again — and what keeps the paper out of review until it is confirmed,
            // which is the moment the date is finally asked for.
            //
            // Not while the employee is already part-way through filing it. An unconfirmed
            // row holds the cycle back by itself and its confirm asks for the date just the
            // same, so resetting it would only throw away images they have already uploaded
            // — an approved employee renewing a document keeps paper_status `approved`, so
            // this is reachable, and the admin cannot see they are mid-upload.
            if ($studentCardExpiryMissing) {
                $status = UserPaperStep::query()
                    ->where('user_paper_id', $paper->id)
                    ->where('step_key', UserPaperStepKey::StudentCard->value)
                    ->value('status');

                if ($status === null || $status === UserPaperStepStatus::Confirmed->value) {
                    $this->stepService->markStepsForReupload($paper->id, [UserPaperStepKey::StudentCard->value]);
                }
            }

            ($this->notifyDocumentsRequired)($user, $missing, $studentCardExpiryMissing);

            return true;
        });

        if (!$sent) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'Only an approved file can be sent back for completion']
            );
        }

        $user->refresh();

        return new ActionResult(
            data: [
                'paper_status' => $user->paper_status,
                'missing_steps' => $missing,
                'reopened_steps' => $reopen,
            ]
        );
    }
}
