<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ReconciliationBatchStatus;
use App\Enums\ReconciliationCheckInStatus;
use App\Enums\ReconciliationCompareStatus;
use App\Enums\ReconciliationMatchStatus;
use App\Models\CheckIn;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Services\Reconciliation\CanonicalRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConfirmReconciliationBatchAction
{
    public function __construct(
        private readonly ApplyReconciliationMatchAction $applyMatch,
        private readonly SerializeReconciliationBatchAction $serialize,
        private readonly RecalculateReconciliationBatchCountsAction $recalculateCounts
    ) {}

    public function __invoke(User $actor, ReconciliationBatch $batch): ActionResult
    {
        return DB::transaction(function () use ($actor, $batch) {
            $batch = ReconciliationBatch::lockById($batch->id);

            if ($batch->isConfirmed()) {
                $batch->load([
                    'rows.user',
                    'rows.checkIn.shift.workplace',
                    'rows.workplace',
                    'uploadedBy',
                    'confirmedBy',
                    'exportedBy',
                ]);

                return new ActionResult(data: ($this->serialize)($batch, $actor));
            }

            $rows = $batch
                ->rows()
                ->with(['checkIn.shift.workplaceShift', 'user'])
                ->get();

            // Loaded once, up front, and reused by both passes below: the
            // refresh needs it to re-bind a check-in entered after upload, and
            // isUnfinished() needs it to spot rows with several candidates.
            // Rows the refresh binds simply stop being looked up.
            $unattachedCheckIns = $this->applyMatch->loadUnattachedCheckIns($rows, $actor);

            foreach ($rows as $row) {
                if (!$row->skipped) {
                    $this->applyMatch->refreshComparisonAgainstLiveCheckIn(
                        $row,
                        CanonicalRow::fromRow($row),
                        $actor,
                        $this->applyMatch->unattachedCheckInsFor($row, $unattachedCheckIns)
                    );
                }
            }
            foreach ($rows as $row) {
                $row->save();
            }
            ($this->recalculateCounts)($batch);

            $unfinished = [];
            foreach ($rows as $row) {
                if ($this->isUnfinished($row, $actor, $unattachedCheckIns)) {
                    $unfinished[] = $row->id;
                }
            }

            if ($unfinished !== []) {
                return new ActionResult(
                    error: ErrorCode::ReconciliationDecisionsIncomplete,
                    status: 422,
                    data: ['unfinished_row_ids' => $unfinished]
                );
            }

            // Checked only once every row is individually complete, and reported
            // under its own error code: two rows sharing a check-in can each look
            // fully mapped/decided on its own, so folding this into
            // ReconciliationDecisionsIncomplete would tell the admin to fix a
            // per-field decision that doesn't exist instead of pointing at the
            // actual conflicting rows.
            $duplicateCheckInRowIds = $rows
                ->filter(fn(ReconciliationRow $row) => !$row->skipped && $row->check_in_id !== null)
                ->groupBy('check_in_id')
                ->filter(fn(Collection $group) => $group->count() > 1)
                ->flatMap(fn(Collection $group) => $group->pluck('id'))
                ->values()
                ->all();

            if ($duplicateCheckInRowIds !== []) {
                return new ActionResult(
                    error: ErrorCode::ReconciliationDuplicateCheckIn,
                    status: 422,
                    data: ['row_ids' => $duplicateCheckInRowIds]
                );
            }

            $pendingCheckInIds = $this->applyMatch->pendingCheckInIds(
                $rows->pluck('check_in_id')->filter()->unique()->values()->all()
            );
            foreach ($rows as $row) {
                $row->has_pending_edit_request =
                    $row->check_in_id !== null && isset($pendingCheckInIds[$row->check_in_id]);
                $row->save();
            }

            $batch->status = ReconciliationBatchStatus::Confirmed->value;
            $batch->confirmed_by = $actor->id;
            $batch->confirmed_at = now();
            $batch->save();

            $batch->load([
                'rows.user',
                'rows.checkIn.shift.workplace',
                'rows.workplace',
                'uploadedBy',
                'confirmedBy',
                'exportedBy',
            ]);

            return new ActionResult(data: ($this->serialize)($batch, $actor));
        });
    }

    /**
     * @param array<int, array<string, Collection<int, CheckIn>>> $unattachedCheckIns
     */
    private function isUnfinished(ReconciliationRow $row, User $actor, array $unattachedCheckIns): bool
    {
        if ($row->skipped) {
            return false;
        }

        $needsMap = in_array(
            $row->match_status,
            [
                ReconciliationMatchStatus::Unmatched->value,
                ReconciliationMatchStatus::Similar->value,
                ReconciliationMatchStatus::Ambiguous->value,
                ReconciliationMatchStatus::DuplicateExternal->value,
            ],
            true
        );
        if ($needsMap && !$row->user_id) {
            return true;
        }

        $preloaded = $this->applyMatch->unattachedCheckInsFor($row, $unattachedCheckIns);
        if ($this->applyMatch->checkInStatus($row, $actor, $preloaded) === ReconciliationCheckInStatus::Multiple) {
            return true;
        }

        if ($row->compare_status === ReconciliationCompareStatus::Differs->value) {
            return $row->decision_check_in === null ||
                $row->decision_check_out === null ||
                $row->decision_break === null;
        }

        return false;
    }
}
