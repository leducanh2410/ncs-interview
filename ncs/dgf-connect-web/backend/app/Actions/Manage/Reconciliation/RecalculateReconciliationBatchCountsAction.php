<?php

namespace App\Actions\Manage\Reconciliation;

use App\Enums\ReconciliationCompareStatus;
use App\Enums\ReconciliationMatchMethod;
use App\Enums\ReconciliationMatchStatus;
use App\Models\ReconciliationBatch;

class RecalculateReconciliationBatchCountsAction
{
    public function __invoke(ReconciliationBatch $batch): void
    {
        $rows = $batch->rows()->get();
        $batch->punch_count = $rows->count();
        $batch->matched_count = $rows->where('compare_status', ReconciliationCompareStatus::Matched->value)->count();
        $batch->differs_count = $rows->where('compare_status', ReconciliationCompareStatus::Differs->value)->count();
        $batch->unmatched_count = $rows
            ->whereIn('match_status', [
                ReconciliationMatchStatus::Unmatched->value,
                ReconciliationMatchStatus::Similar->value,
                ReconciliationMatchStatus::Ambiguous->value,
                ReconciliationMatchStatus::DuplicateExternal->value,
            ])
            ->whereNull('user_id')
            ->count();
        $batch->manual_map_count = $rows->where('match_method', ReconciliationMatchMethod::Manual->value)->count();
        $batch->save();
    }
}
