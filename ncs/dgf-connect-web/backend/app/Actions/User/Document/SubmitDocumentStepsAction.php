<?php

namespace App\Actions\User\Document;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Exceptions\DocumentStepConfirmationFailedException;
use App\Models\User;
use App\Models\UserInfo;
use App\Services\External\CrossStaffApiService;
use App\Services\User\DocumentStepService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Confirms the whole submission at once.
 *
 * Picking 留学 or 家族滞在 on the residence card pulls further documents into the
 * submission, which the old per-step confirm could not enforce: the card was already
 * written to the profile before the client learned what else was needed, leaving a
 * profile whose status demands a student card that was never asked for. Submitting
 * every step together makes the set atomic — either the submission is complete for the
 * status it declares, or nothing is written.
 */
class SubmitDocumentStepsAction
{
    public function __construct(
        private readonly DocumentStepService $stepService,
        private readonly ApplyDocumentStepConfirmationAction $applyStepConfirmation
    ) {}

    /**
     * @param array<string, array> $steps confirmed fields keyed by step key
     */
    public function __invoke(User $user, array $steps): ActionResult
    {
        $missing = array_values(
            array_unique([
                ...$this->stepService->missingRequiredStepKeys(
                    $user,
                    array_keys($steps),
                    // The status the user just picked is what decides the rest of the set,
                    // not the one on the profile, which this submission is about to replace
                    $steps[UserPaperStepKey::ResidenceCard->value]['residence_status'] ?? null
                ),
                // A step uploaded earlier but left unconfirmed holds the whole cycle back
                // just as hard as one that was never uploaded, so it belongs in the same
                // answer — otherwise the submit is accepted and the paper never moves.
                ...$this->stepService->unconfirmedStepKeysOutsideSubmission($user, array_keys($steps)),
            ])
        );
        if ($missing !== []) {
            return new ActionResult(
                error: ErrorCode::UserPaperSubmissionIncomplete,
                status: 422,
                data: [
                    'message' =>
                        'The submission is missing documents this residence status requires,' .
                        ' or leaves an uploaded document unconfirmed',
                    'missing_steps' => $missing,
                ]
            );
        }

        try {
            DB::transaction(function () use ($user, $steps) {
                // Cover uploads that were already in flight when the cycle marker was deployed.
                // Snapshots the set a prior approval covers before the card below overwrites
                // the status it was granted under; the completeness check runs after that
                // write and cannot ask the question itself.
                if ($user->paper_status === UserPaperStatus::Approved->value) {
                    $this->stepService->markSubmissionStartedFromApproved($user);
                }

                /** @var UserInfo $info */
                $info = UserInfo::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->firstOrCreate(['user_id' => $user->id]);

                // Enum order puts the residence card first, so the card slot is written before
                // anything that reads the status back out of it
                foreach (UserPaperStepKey::cases() as $step) {
                    if (!array_key_exists($step->value, $steps)) {
                        continue;
                    }

                    $error = ($this->applyStepConfirmation)($user, $info, $step, $steps[$step->value]);
                    if ($error !== null) {
                        // Roll back the steps already applied in this submit
                        throw new DocumentStepConfirmationFailedException($step->value, $error);
                    }
                }

                $info->save();

                $user->unsetRelation('info');
                $this->stepService->markWaitForApprovalIfComplete($user);
            });
        } catch (DocumentStepConfirmationFailedException $failure) {
            return new ActionResult(
                error: $failure->errorCode,
                status: 422,
                data: [
                    'step' => $failure->stepKey,
                    'message' => match ($failure->errorCode) {
                        ErrorCode::UserPaperStepChanged => 'A step was re-uploaded; reload the form and submit again',
                        ErrorCode::UserPaperStepOcrStillProcessing
                            => 'A document is still being read; wait for it to finish and submit again',
                        ErrorCode::UserPaperStepOcrRequiredFieldsMissing
                            => 'Required fields could not be read; upload clearer document images',
                        ErrorCode::UserPaperStepNotUploaded => 'Upload the document images before submitting',
                        default => 'This submission is already confirmed and waiting for review',
                    },
                ]
            );
        }

        if (array_key_exists(UserPaperStepKey::ResidenceCard->value, $steps) && !$user->refresh()->cross_staff_id) {
            $this->createCrossStaffUser($user);
        }

        return new ActionResult(data: ['mismatches' => $user->paper()->value('document_mismatches') ?? []]);
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
            Log::error('Failed to create user in CrossStaff during document submit', [
                'user_id' => $user->id,
                'error' => $result['error'],
            ]);
        }
    }
}
