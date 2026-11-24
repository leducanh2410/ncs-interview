<?php

namespace App\Actions\User\Document;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserPaperStep;
use App\Services\External\CrossStaffApiService;
use App\Services\User\DocumentStepService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfirmDocumentStepAction
{
    public function __construct(
        private readonly DocumentStepService $stepService,
        private readonly ApplyDocumentStepConfirmationAction $applyStepConfirmation
    ) {}

    public function __invoke(User $user, UserPaperStepKey $step, array $data): ActionResult
    {
        if ($step->ocrGroup() === null) {
            return new ActionResult(
                error: ErrorCode::UserPaperStepNotConfirmable,
                status: 422,
                data: ['message' => 'This step does not need a confirmation']
            );
        }

        $userPaper = $user->paper()->first();
        $stepRow = $userPaper
            ? UserPaperStep::query()->where('user_paper_id', $userPaper->id)->where('step_key', $step->value)->first()
            : null;

        if (!$stepRow) {
            return new ActionResult(
                error: ErrorCode::UserPaperStepNotUploaded,
                status: 422,
                data: ['message' => 'Upload the document images before confirming']
            );
        }

        if ($stepRow->status === UserPaperStepStatus::NeedsReupload->value) {
            return new ActionResult(
                error: ErrorCode::UserPaperStepNotUploaded,
                status: 422,
                data: ['message' => 'Upload the replacement document images before confirming']
            );
        }

        if ($stepRow->status === UserPaperStepStatus::Confirmed->value) {
            return new ActionResult(
                error: ErrorCode::UserPaperStepLocked,
                status: 422,
                data: ['message' => 'This step is already confirmed and waiting for review']
            );
        }

        $error = DB::transaction(function () use ($user, $step, $data) {
            // Cover uploads that were already in flight when the cycle marker was deployed.
            // Snapshots what a prior approval covers while the profile still carries the
            // status it was granted under — the card below is about to replace it.
            if ($user->paper_status === UserPaperStatus::Approved->value) {
                $this->stepService->markSubmissionStartedFromApproved($user);
            }

            // Re-read under lock: a concurrent confirm must not pass the pre-transaction
            // status check twice and write the same document into two residence slots
            /** @var UserInfo $info */
            $info = UserInfo::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrCreate(['user_id' => $user->id]);

            $error = ($this->applyStepConfirmation)($user, $info, $step, $data);
            if ($error !== null) {
                return $error;
            }

            $info->save();

            $user->unsetRelation('info');
            $this->stepService->markWaitForApprovalIfComplete($user);

            return null;
        });

        if ($error !== null) {
            return new ActionResult(
                error: $error,
                status: 422,
                data: [
                    'message' => match ($error) {
                        ErrorCode::UserPaperStepChanged => 'The step was re-uploaded; reload it and confirm again',
                        ErrorCode::UserPaperStepOcrStillProcessing
                            => 'The document is still being read; wait for it to finish and confirm again',
                        ErrorCode::UserPaperStepOcrRequiredFieldsMissing
                            => 'Required fields could not be read; upload clearer document images',
                        ErrorCode::UserPaperStepNotUploaded
                            => 'Upload the replacement document images before confirming',
                        default => 'This step is already confirmed and waiting for review',
                    },
                ]
            );
        }

        // First residence-card confirm is the moment this user becomes known to CrossStaff.
        // Re-read the user so a link created by a concurrent request is not missed.
        $user->refresh();
        if ($step === UserPaperStepKey::ResidenceCard && !$user->cross_staff_id) {
            $this->createCrossStaffUser($user);
        }

        $missingSteps = $this->blockingStepKeys($user);
        if ($missingSteps !== []) {
            Log::warning('Deprecated per-step confirm left the submission incomplete', [
                'user_id' => $user->id,
                'step' => $step->value,
                'missing_steps' => $missingSteps,
            ]);
        }

        // Additive on purpose: the released apps this endpoint exists for read the step
        // row itself, so the key rides along with it rather than replacing the payload.
        return new ActionResult(
            data: array_merge($this->stepService->stepRow($user, $step)?->toArray() ?? [], [
                'missing_steps' => $missingSteps,
            ])
        );
    }

    /**
     * Steps that keep this submission out of the review queue after the confirm.
     *
     * The per-step endpoint answers one step at a time and never learned what the whole
     * submission still owes, so a status that pulls in a document the client never asked
     * for (家族滞在 now needs the 資格外活動許可) left the paper sitting in pending behind a
     * 200 with nothing to explain it. Naming the steps is what makes that visible.
     *
     * @return string[]
     */
    private function blockingStepKeys(User $user): array
    {
        if ($user->paper_status === UserPaperStatus::WaitForApproval->value) {
            return [];
        }

        return array_values(
            array_unique([
                ...$this->stepService->missingRequiredStepKeys($user, [], null),
                ...$this->stepService->unconfirmedStepKeysOutsideSubmission($user, []),
            ])
        );
    }

    private function createCrossStaffUser(User $user): void
    {
        $apiService = new CrossStaffApiService();
        $user->load('info');
        $result = $apiService->createUser($user);
        if ($result['cross_staff_id']) {
            $user->update(['cross_staff_id' => $result['cross_staff_id']]);
        }
        if ($result['error']) {
            Log::error('Failed to create user in CrossStaff during document step confirm', [
                'user_id' => $user->id,
                'error' => $result['error'],
            ]);
        }
    }
}
