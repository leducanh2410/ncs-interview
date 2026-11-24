<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ReconciliationDecision;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Services\Reconciliation\CanonicalRow;
use App\Services\Reconciliation\ReconciliationExportEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateReconciliationRowAction
{
    public function __construct(
        private readonly ApplyReconciliationMatchAction $applyMatch,
        private readonly RecalculateReconciliationBatchCountsAction $recalculateCounts,
        private readonly SerializeReconciliationBatchAction $serialize
    ) {}

    public function __invoke(User $actor, ReconciliationBatch $batch, ReconciliationRow $row, array $data): ActionResult
    {
        return DB::transaction(function () use ($actor, $batch, $row, $data) {
            $batch = ReconciliationBatch::lockById($batch->id);

            $row = ReconciliationRow::query()->findOrFail($row->id);
            if ($row->batch_id !== $batch->id) {
                return new ActionResult(error: ErrorCode::NotFound, status: 404);
            }

            if (!$batch->isDraft()) {
                // A Confirmed batch is frozen, with one exception. When the
                // Check_In a row was decided against is hard-deleted afterwards
                // (shift re-planning, the user dropped from the shift), the FK
                // nulls `check_in_id` and Export reports the row as stale — for
                // good: the check-in cannot come back with the same id, the
                // batch cannot return to Draft, and a single such row keeps the
                // whole workbook from being produced, leaving "delete the batch
                // and redo the entire reconciliation" as the only way out.
                // Skipping exactly that row is the escape hatch. It only takes
                // the row out of the export; mapping, decisions and snapshots
                // stay untouched, so the audit trail still shows what was
                // confirmed and against what.
                if (!$this->isOrphanRowSkip($row, $data)) {
                    return new ActionResult(error: ErrorCode::ReconciliationBatchNotDraft, status: 409);
                }

                // No recalculateCounts(): none of the batch counters look at
                // `skipped`, so re-running it here would only bump the confirmed
                // batch's updated_at for nothing.
                $row->skipped = true;
                $row->save();

                return new ActionResult(data: ($this->serialize)($this->loadForResponse($batch), $actor));
            }

            $canonical = CanonicalRow::fromRow($row);

            if (array_key_exists('skipped', $data)) {
                $row->skipped = (bool) $data['skipped'];
            }

            if (array_key_exists('user_id', $data)) {
                $forcedUserId = $data['user_id'] !== null ? (int) $data['user_id'] : null;

                // Re-matching (forcedUserId === null, i.e. the admin is unmapping the row)
                // must recompute the batch's current duplicate-name protection and
                // priority pool, otherwise a row flagged DuplicateExternal loses that
                // protection and a fresh nameMatcher::match() runs unscoped/unranked.
                // Manual map of a single user only needs User::find() inside apply(),
                // so skip hydrating the whole directory on that path.
                $users = collect();
                $duplicateKeys = [];
                $priorityUserIds = [];
                if ($forcedUserId === null) {
                    $users = User::query()
                        ->get([
                            'id',
                            'first_name',
                            'last_name',
                            'first_name_kana',
                            'last_name_kana',
                            'short_name',
                            'staff_no',
                            'profile_picture',
                        ])
                        ->keyBy('id');
                    $workDate = Carbon::parse($row->work_date)->toDateString();
                    $duplicateKeys = $this->duplicateKeysFor($row, $batch, $workDate);
                    $priorityUserIds = $this->applyMatch->priorityUserIds([$workDate], $actor);
                }

                $this->applyMatch->apply(
                    $row,
                    $canonical,
                    $actor,
                    $users,
                    $priorityUserIds,
                    $duplicateKeys,
                    $forcedUserId,
                    array_key_exists('check_in_id', $data) && $data['check_in_id'] !== null
                        ? (int) $data['check_in_id']
                        : null
                );
            } elseif (array_key_exists('check_in_id', $data)) {
                $user = $row->user_id ? User::query()->find($row->user_id) : null;
                $this->applyMatch->attachCheckIn(
                    $row,
                    $user,
                    Carbon::parse($row->work_date)->toDateString(),
                    $actor,
                    $data['check_in_id'] !== null ? (int) $data['check_in_id'] : null
                );
                $this->applyMatch->applyComparison($row, $canonical);
            }

            if (isset($data['decisions']) && is_array($data['decisions'])) {
                // The serializer shows `dgf_check_in/out/break` live, so the admin
                // may be picking a decision against a DGF value that changed since
                // upload. Resync snapshot_dgf_* to that live value first, or
                // Confirm's refreshComparisonAgainstLiveCheckIn() would see the
                // stale upload-time snapshot vs. live DGF as drift and null the
                // decision the admin just made.
                $this->applyMatch->refreshComparisonAgainstLiveCheckIn($row, $canonical, $actor);
                $this->applyDecisions($row, $data['decisions']);
            }

            $row->save();
            ($this->recalculateCounts)($batch->refresh());

            return new ActionResult(data: ($this->serialize)($this->loadForResponse($batch), $actor));
        });
    }

    /**
     * The only write a Confirmed batch accepts: `skipped: true`, on its own, for
     * a row that has lost its check-in *and* would otherwise be exported.
     * Anything else — a mapping, a decision, un-skipping — would change a
     * confirmed result.
     *
     * The eligibility check is not cosmetic. Export applies it before it ever
     * looks at the check-in, so a row with no accepted Amazon change (already
     * matched, or decided DGF on every field) is left out of the workbook
     * whether or not its check-in still exists, and never blocks anything.
     * Without this the API would take a write on a frozen batch that unblocks
     * nothing, and the screen would send admins chasing rows that were never in
     * the way.
     *
     * hasAcceptedAmazonChange() rather than shouldExport(): the two differ only
     * on `skipped`, and refusing an already-skipped row would make the write
     * non-idempotent for no gain. A second PATCH — a double click, or a second
     * admin whose page loaded before the first one skipped the row — would come
     * back 409 and be reported as "could not save" on a row that is skipped and
     * a batch that is no longer blocked. It also keeps this predicate exactly
     * equal to the `can_skip` the serializer advertises.
     *
     * @param array<string, mixed> $data
     */
    private function isOrphanRowSkip(ReconciliationRow $row, array $data): bool
    {
        return count($data) === 1 &&
            array_key_exists('skipped', $data) &&
            (bool) $data['skipped'] === true &&
            $row->check_in_id === null &&
            ReconciliationExportEligibility::hasAcceptedAmazonChange($row);
    }

    private function loadForResponse(ReconciliationBatch $batch): ReconciliationBatch
    {
        return $batch->load([
            'rows.user',
            'rows.checkIn.shift.workplace',
            'rows.workplace',
            'uploadedBy',
            'confirmedBy',
            'exportedBy',
        ]);
    }

    /**
     * @param array<string, mixed> $decisions
     */
    private function applyDecisions(ReconciliationRow $row, array $decisions): void
    {
        $allowed = ReconciliationDecision::values();
        $fieldColumns = [
            'check_in_time' => 'decision_check_in',
            'check_out_time' => 'decision_check_out',
            'break_minutes' => 'decision_break',
        ];
        foreach ($fieldColumns as $field => $column) {
            if (!array_key_exists($field, $decisions)) {
                continue;
            }
            $value = $decisions[$field];
            if ($value !== null && !in_array($value, $allowed, true)) {
                throw ValidationException::withMessages([$field => 'Invalid decision']);
            }
            $row->{$column} = $value;
        }
    }

    /**
     * @return array<string, list<int>>
     */
    private function duplicateKeysFor(ReconciliationRow $row, ReconciliationBatch $batch, string $workDate): array
    {
        $key = CanonicalRow::duplicateKeyFor($row->external_name, $row->raw['staff_no'] ?? null, $workDate);

        $matchingIds = ReconciliationRow::query()
            ->where('batch_id', $batch->id)
            ->whereDate('work_date', $workDate)
            ->get(['id', 'external_name', 'raw'])
            ->filter(
                fn(ReconciliationRow $sibling) => CanonicalRow::duplicateKeyFor(
                    $sibling->external_name,
                    $sibling->raw['staff_no'] ?? null,
                    $workDate
                ) === $key
            )
            ->pluck('id')
            ->all();

        return [$key => $matchingIds];
    }
}
