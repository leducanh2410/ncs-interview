<?php

namespace App\Actions\Manage\Reconciliation;

use App\Enums\ReconciliationCompareStatus;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Services\Reconciliation\CanonicalRow;

class PersistReconciliationRowsAction
{
    public function __construct(private readonly ApplyReconciliationMatchAction $applyMatch) {}

    /**
     * @param list<CanonicalRow> $canonicalRows
     */
    public function __invoke(ReconciliationBatch $batch, array $canonicalRows, User $actor): void
    {
        $users = User::query()->get([
            'id',
            'first_name',
            'last_name',
            'first_name_kana',
            'last_name_kana',
            'short_name',
            'staff_no',
            'profile_picture',
        ]);

        $workDates = array_unique(array_map(fn(CanonicalRow $row) => $row->workDate, $canonicalRows));
        $priorityUserIds = $this->applyMatch->priorityUserIds($workDates, $actor);
        $usersById = $users->keyBy('id');
        $checkInsOnDates = $this->applyMatch->findCheckInsOnDates(
            $workDates,
            $this->applyMatch->showTestWorkplaces($actor)
        );
        $checkInsByUser = $checkInsOnDates->groupBy('user_id');
        $pendingCheckInIds = $this->applyMatch->pendingCheckInIds($checkInsOnDates->pluck('id')->all());

        $duplicateKeys = [];
        foreach ($canonicalRows as $index => $canonical) {
            $duplicateKeys[$canonical->duplicateKey()][] = $index;
        }

        foreach ($canonicalRows as $index => $canonical) {
            $row = new ReconciliationRow([
                'batch_id' => $batch->id,
                'source_row_index' => $canonical->raw['source_row'] ?? $index + 1,
                'external_name' => $canonical->externalName,
                'work_date' => $canonical->workDate,
                'external_check_in' => $canonical->checkIn,
                'external_check_out' => $canonical->checkOut,
                'external_break_minutes' => $canonical->breakMinutes,
                'raw' => $canonical->raw,
                'match_status' => '',
                'compare_status' => ReconciliationCompareStatus::NotComparable->value,
            ]);
            $this->applyMatch->apply(
                $row,
                $canonical,
                $actor,
                $usersById,
                $priorityUserIds,
                $duplicateKeys,
                null,
                null,
                $checkInsByUser,
                $pendingCheckInIds
            );
            $row->save();
        }
    }
}
