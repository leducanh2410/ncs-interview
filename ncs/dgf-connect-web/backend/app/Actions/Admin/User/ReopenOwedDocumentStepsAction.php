<?php

namespace App\Actions\Admin\User;

use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserPaperStep;
use App\Services\User\DocumentStepService;

class ReopenOwedDocumentStepsAction
{
    public function __construct(private readonly DocumentStepService $stepService) {}

    /**
     * @param string[] $stepKeys
     * @return string[]
     */
    public function __invoke(int $userPaperId, array $stepKeys): array
    {
        $now = now();

        return array_values(
            array_filter(
                $stepKeys,
                fn(string $stepKey) => UserPaperStep::query()->insertOrIgnore([
                    'user_paper_id' => $userPaperId,
                    'step_key' => $stepKey,
                    'status' => UserPaperStepStatus::NeedsReupload->value,
                    'ocr_run_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]) === 1
            )
        );
    }

    /**
     * @param string[] $missingSteps
     * @return string[]
     */
    public function stepsStuckOnImagesOnFile(User $user, array $missingSteps): array
    {
        return array_values(
            array_filter(
                $this->stepsNotYetOpened($user, $missingSteps),
                fn(string $stepKey) => $this->stepService
                    ->activeStepItems($user, UserPaperStepKey::from($stepKey))
                    ->isNotEmpty()
            )
        );
    }

    /**
     * @param string[] $missingSteps
     * @return string[]
     */
    private function stepsNotYetOpened(User $user, array $missingSteps): array
    {
        $userPaperId = $user->paper()->value('id');
        if (!$userPaperId || $missingSteps === []) {
            return [];
        }

        $stepKeysWithRow = UserPaperStep::query()
            ->where('user_paper_id', $userPaperId)
            ->whereIn('step_key', $missingSteps)
            ->pluck('step_key')
            ->all();

        return array_values(array_diff($missingSteps, $stepKeysWithRow));
    }
}
