<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ReconciliationCompareStatus;
use App\Enums\ReconciliationDecision;
use App\Models\ReconciliationBatch;
use App\Models\User;
use App\Services\Reconciliation\CanonicalRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BulkDecideReconciliationBatchAction
{
    public function __construct(
        private readonly ApplyReconciliationMatchAction $applyMatch,
        private readonly RecalculateReconciliationBatchCountsAction $recalculateCounts,
        private readonly SerializeReconciliationBatchAction $serialize
    ) {}

    public function __invoke(User $actor, ReconciliationBatch $batch, string $side): ActionResult
    {
        if (!in_array($side, ReconciliationDecision::values(), true)) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        return DB::transaction(function () use ($actor, $batch, $side) {
            $batch = ReconciliationBatch::lockById($batch->id);
            if (!$batch->isDraft()) {
                return new ActionResult(error: ErrorCode::ReconciliationBatchNotDraft, status: 409);
            }

            // Rows mapped to a user but not yet bound to a check-in are included
            // so refreshComparisonAgainstLiveCheckIn() can pick up DGF
            // attendance entered after upload; rows with neither can never
            // become comparable here, so they stay out of the query.
            $rows = $batch
                ->rows()
                ->where('skipped', false)
                ->where(fn(Builder $query) => $query->whereNotNull('check_in_id')->orWhereNotNull('user_id'))
                ->with(['checkIn.shift.workplaceShift', 'user'])
                ->get();
            $unattachedCheckIns = $this->applyMatch->loadUnattachedCheckIns($rows, $actor);
            foreach ($rows as $row) {
                $this->applyMatch->refreshComparisonAgainstLiveCheckIn(
                    $row,
                    CanonicalRow::fromRow($row),
                    $actor,
                    $this->applyMatch->unattachedCheckInsFor($row, $unattachedCheckIns)
                );
                if ($row->compare_status === ReconciliationCompareStatus::Differs->value) {
                    foreach (['decision_check_in', 'decision_check_out', 'decision_break'] as $column) {
                        if ($row->{$column} === null) {
                            $row->{$column} = $side;
                        }
                    }
                }
                $row->save();
            }

            ($this->recalculateCounts)($batch->refresh());
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
}
