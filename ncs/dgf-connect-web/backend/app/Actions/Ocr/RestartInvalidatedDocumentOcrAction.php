<?php

namespace App\Actions\Ocr;

use App\Enums\UserPaperStepStatus;
use App\Jobs\ExtractDocumentStepOcrJob;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use App\Services\Ocr\OcrResultCacheService;
use Illuminate\Support\Facades\DB;

class RestartInvalidatedDocumentOcrAction
{
    private const RESTARTABLE_STATUSES = [
        UserPaperStepStatus::OcrProcessing,
        UserPaperStepStatus::OcrReady,
        UserPaperStepStatus::OcrFailed,
    ];

    public function __construct(private readonly OcrResultCacheService $cacheService) {}

    /**
     * Replace active employee OCR runs that referenced a modified image.
     * Maintenance reruns do not consume the employee's re-upload budget.
     *
     * @param int[] $itemIds
     * @param int[] $excludedItemIds
     * @return int number of document steps restarted
     */
    public function __invoke(array $itemIds, array $excludedItemIds = []): int
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        $excludedItemIds = array_values(array_unique(array_map('intval', $excludedItemIds)));
        if ($itemIds === []) {
            return 0;
        }

        $stepIds = UserPaperStep::query()
            ->whereIn(
                'status',
                array_map(fn(UserPaperStepStatus $status) => $status->value, self::RESTARTABLE_STATUSES)
            )
            ->whereHas('ocrResults', function ($query) use ($itemIds, $excludedItemIds) {
                $query->whereNotNull('invalidated_at')->where(function ($query) use ($itemIds) {
                    foreach ($itemIds as $itemId) {
                        $query->orWhereJsonContains('item_ids', $itemId);
                    }
                });
                foreach ($excludedItemIds as $itemId) {
                    $query->whereJsonDoesntContain('item_ids', $itemId);
                }
            })
            ->pluck('id');

        $restarted = 0;
        foreach ($stepIds as $stepId) {
            $restarted += DB::transaction(function () use ($stepId, $itemIds, $excludedItemIds): int {
                /** @var ?UserPaperStep $step */
                $step = UserPaperStep::query()->whereKey($stepId)->lockForUpdate()->first();
                if (!$step || !in_array($step->status, $this->restartableStatusValues(), true)) {
                    return 0;
                }

                // A step can read several groups (the residence card's sides and its scan),
                // so every invalidated run of it needs its own replacement — fixing the scan
                // alone must restart the scan's run, not the sides' one
                $invalidatedResultQuery = UserPaperOcrResult::query()
                    ->where('user_paper_step_id', $step->id)
                    ->whereNotNull('invalidated_at')
                    ->where(function ($query) use ($itemIds) {
                        foreach ($itemIds as $itemId) {
                            $query->orWhereJsonContains('item_ids', $itemId);
                        }
                    });
                foreach ($excludedItemIds as $itemId) {
                    $invalidatedResultQuery->whereJsonDoesntContain('item_ids', $itemId);
                }
                /** @var \Illuminate\Database\Eloquent\Collection<int, UserPaperOcrResult> $invalidatedResults */
                $invalidatedResults = $invalidatedResultQuery->orderBy('id')->get();
                if ($invalidatedResults->isEmpty()) {
                    return 0;
                }

                // A step keeps every run it has ever owned, so the same invalidated row is
                // still matched on a second pass over the same images. Restart a group only
                // while it has no live run, otherwise re-running the command would spend a
                // second LLM call and leave two live runs of one group behind.
                $liveGroups = UserPaperOcrResult::query()
                    ->where('user_paper_step_id', $step->id)
                    ->whereNull('invalidated_at')
                    ->pluck('ocr_group')
                    ->unique()
                    ->all();

                $invalidatedResults = $invalidatedResults
                    ->reject(fn(UserPaperOcrResult $result) => in_array($result->ocr_group, $liveGroups, true))
                    // The newest run of a group describes the images as they are now
                    ->groupBy('ocr_group')
                    ->map(fn($group) => $group->last())
                    ->values();
                if ($invalidatedResults->isEmpty()) {
                    return 0;
                }

                $primaryResultId = $step->ocr_result_id;
                foreach ($invalidatedResults as $invalidatedResult) {
                    $newResult = $this->cacheService->createPending(
                        $invalidatedResult->ocr_group,
                        $invalidatedResult->item_ids,
                        OcrResultCacheService::SOURCE_EMPLOYEE,
                        $invalidatedResult->requested_by_user_id
                    );
                    $newResult->update(['user_paper_step_id' => $step->id]);

                    if ($invalidatedResult->id === $step->ocr_result_id) {
                        $primaryResultId = $newResult->id;
                    }

                    ExtractDocumentStepOcrJob::dispatch($newResult->id, $step->id)->afterCommit();
                }

                $step->update([
                    'status' => UserPaperStepStatus::OcrProcessing->value,
                    'ocr_result_id' => $primaryResultId,
                ]);

                return 1;
            });
        }

        return $restarted;
    }

    /**
     * @return string[]
     */
    private function restartableStatusValues(): array
    {
        return array_map(fn(UserPaperStepStatus $status) => $status->value, self::RESTARTABLE_STATUSES);
    }
}
