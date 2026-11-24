<?php

namespace App\Actions\User\Document;

use App\Enums\OcrResultStatus;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use App\Support\Ocr\DocumentFieldComparator;

/**
 * Recompute which identity fields the current submission's documents disagree on and
 * store the verdict on the paper, so the employee's warning and the admin's badge are
 * always the same answer rather than two independent recalculations.
 */
class RefreshDocumentMismatchesAction
{
    public function __construct(private readonly DocumentFieldComparator $comparator) {}

    /**
     * @return array<int, array{field: string, values: array<int, array{group: string, value: string}>}>
     */
    public function __invoke(User $user): array
    {
        /** @var ?UserPaper $userPaper */
        $userPaper = $user->paper()->first();
        if (!$userPaper) {
            return [];
        }

        $stepIds = UserPaperStep::query()->where('user_paper_id', $userPaper->id)->pluck('id');
        if ($stepIds->isEmpty()) {
            $userPaper->update(['document_mismatches' => []]);

            return [];
        }

        // Only runs of the current upload count: a superseded run describes images the
        // user has already replaced, and comparing against those would warn about nothing
        $results = UserPaperOcrResult::query()
            ->whereIn('user_paper_step_id', $stepIds)
            ->whereNull('invalidated_at')
            ->where('status', OcrResultStatus::Completed->value)
            ->orderBy('id')
            ->get();

        $fieldsByGroup = [];
        foreach ($results as $result) {
            $fieldsByGroup[$result->ocr_group] = $result->fields ?? [];
        }

        $mismatches = ($this->comparator)($fieldsByGroup);
        $userPaper->update(['document_mismatches' => $mismatches]);

        return $mismatches;
    }
}
