<?php

namespace App\Actions\Manage\ShiftPlanning;

use App\Actions\ActionResult;
use App\Actions\Chat\Shift\SyncShiftChatParticipantsAction;
use App\Actions\Manage\Shift\CreateShiftAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Events\ShiftUserRejected;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Persists one workplace's planning changes for the loaded window. Three kinds of change ride
 * in the same payload and are treated differently:
 *
 *  - `add` rows stage as silent ShiftPlanDraft rows (employee-invisible) and only become live
 *    ShiftUser rows at Finalize (requirements §3.2, §4.1).
 *  - `update` rows stage a silent overlay for an existing live ShiftUser, identified by
 *    shift_user_id, and replace that live row only at Finalize.
 *  - `remove` rows take effect on live data immediately: the matching live ShiftUser is
 *    rejected, its CheckIn deleted, and ShiftUserRejected dispatched — the same branch Finalize
 *    used to run. A removal is therefore NOT stored as a draft; "Finalize draft" only ever
 *    converts adds/updates.
 *
 * Removals are applied first, then the resulting draft add/update set is validated set-aware over each
 * touched date's ±6-day neighbourhood (the validator Finalize also uses). Everything runs in one
 * transaction, so a failing validation rolls back the live removals too; because ShiftUserRejected
 * dispatches after commit, a rolled-back save sends no notification. On failure the per-cell errors
 * are returned (422) so the admin can fix them before retrying. Requires the workplace planning
 * lock; add-reconciliation is window-scoped so drafts paged out of view survive.
 */
class SaveShiftPlanDraftAction
{
    private const ROLLBACK_SIGNAL = '__shift_plan_draft_validation_failed__';

    private const SHIFT_CREATE_DENIED_SIGNAL = '__shift_plan_draft_shift_create_denied__';

    public function __construct(
        private readonly ShiftPlanningLockService $lockService,
        private readonly ValidateShiftPlanDateAction $validateDate,
        private readonly CreateShiftAction $createShift
    ) {}

    /**
     * @param array<int,array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null}> $drafts
     */
    public function __invoke(User $user, int $workplaceId, array $drafts, string $from, string $to): ActionResult
    {
        if (!$user->isAbleTo(PermissionName::ShiftEdit->value, $workplaceId)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }
        // The planning lock is advisory and frees itself after an idle TTL (requirements §4.1),
        // so a sole editor's lock can lapse mid-session even though nobody else is editing. Don't
        // punish that: if we no longer hold the lock, re-acquire it when it's free and only block
        // when another admin is actively holding it.
        if (
            !$this->lockService->isHeldBy($workplaceId, $user->id) &&
            !$this->lockService->acquire($workplaceId, $user->id, $user->short_name)
        ) {
            return new ActionResult(error: ErrorCode::ShiftPlanningLockRequired, status: 423);
        }

        $workplace = Workplace::query()->find($workplaceId);
        if (!$workplace) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $payloadErrors = array_merge($this->dateErrors($drafts), $this->updateTargetErrors($workplace, $drafts));
        if (!empty($payloadErrors)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['errors' => $payloadErrors]
            );
        }

        // Validation errors that, when present, roll back the whole save via the sentinel below
        // so a blocking save commits neither the live removals nor the draft add/update rows.
        $validationErrors = [];

        try {
            // applyRemovals rejects each live ShiftUser one by one, firing ShiftUserObserver per row.
            // Batch defers the chat sync until the transaction settles, so each affected shift is
            // reconciled once and a rolled-back save recomputes membership from the restored state.
            SyncShiftChatParticipantsAction::batch(function () use (
                $user,
                $workplace,
                $drafts,
                $from,
                $to,
                &$validationErrors
            ) {
                DB::transaction(function () use ($user, $workplace, $drafts, $from, $to, &$validationErrors) {
                    // Apply removals to live first so the add validation below sees freed capacity.
                    $this->applyRemovals($user, $workplace, $drafts);
                    $this->reconcileDrafts($user, $workplace, $drafts, $from, $to);
                    // Materialize the live Shift for any add landing on a bare date, so the
                    // validation below finds it instead of reporting ShiftPlanningShiftMissing.
                    $this->ensureShiftsForAdds($user, $workplace, $drafts);

                    $validationErrors = $this->validateDates($workplace, $this->draftDates($drafts));
                    if (!empty($validationErrors)) {
                        throw new RuntimeException(self::ROLLBACK_SIGNAL);
                    }
                });
            });
        } catch (Throwable $e) {
            if ($e->getMessage() === self::SHIFT_CREATE_DENIED_SIGNAL) {
                return new ActionResult(error: ErrorCode::Forbidden, status: 403);
            }
            if ($e->getMessage() !== self::ROLLBACK_SIGNAL) {
                throw $e;
            }
        }

        if (!empty($validationErrors)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['errors' => $validationErrors]
            );
        }

        return new ActionResult(data: ['saved' => count($drafts)]);
    }

    /**
     * Apply every `remove` row to live data: reject the matching live ShiftUser, delete its
     * CheckIn, and dispatch ShiftUserRejected. Mirrors Finalize's former remove branch, so the
     * manager-created notification skip is handled identically downstream.
     *
     * @param array<int,array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null}> $drafts
     */
    private function applyRemovals(User $user, Workplace $workplace, array $drafts): void
    {
        $approverId = $user->id;
        $approvedAt = now();

        foreach ($drafts as $row) {
            if ($row['action'] !== ShiftPlanDraftAction::Remove->value) {
                continue;
            }

            $record = $this->liveRecordForRemoval($workplace, $row);
            if (!$record) {
                continue;
            }

            $record->update([
                'status' => ShiftUserStatus::Rejected->value,
                'confirmed_at' => null,
                'approver_id' => $approverId,
                'approved_at' => $approvedAt,
            ]);
            CheckIn::query()->where('shift_id', $record->shift_id)->where('user_id', $row['user_id'])->delete();
            ShiftUserRejected::dispatch(Carbon::parse($row['date']), $record);
        }
    }

    /**
     * Window-scoped upsert of `add` and `update` rows into silent draft storage. Draft rows in the window
     * absent from this (full-state) payload are deleted; drafts outside the window are untouched
     * so paged-out plans survive.
     *
     * @param array<int,array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null}> $drafts
     */
    private function reconcileDrafts(User $user, Workplace $workplace, array $drafts, string $from, string $to): void
    {
        $existing = ShiftPlanDraft::query()
            ->where('workplace_id', $workplace->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy(
                fn(ShiftPlanDraft $d) => $this->rowKey($d->date->format('Y-m-d'), $d->workplace_shift_id, $d->user_id)
            );

        $seen = [];
        foreach ($drafts as $row) {
            if (
                !in_array($row['action'], [ShiftPlanDraftAction::Add->value, ShiftPlanDraftAction::Update->value], true)
            ) {
                continue;
            }
            $key = $this->rowKey($row['date'], $row['workplace_shift_id'], $row['user_id']);
            $seen[$key] = true;

            $draft =
                $existing->get($key) ??
                new ShiftPlanDraft([
                    'workplace_id' => $workplace->id,
                    'date' => $row['date'],
                    'workplace_shift_id' => $row['workplace_shift_id'],
                    'user_id' => $row['user_id'],
                    'created_by' => $user->id,
                ]);
            if (!$draft->exists) {
                $draft->created_by = $user->id;
            }
            $draft->workplace_position_id = $row['workplace_position_id'] ?? null;
            $draft->is_fixed = $row['is_fixed'];
            $draft->action = $row['action'];
            $draft->shift_user_id =
                $row['action'] === ShiftPlanDraftAction::Update->value ? $row['shift_user_id'] ?? null : null;
            $draft->updated_by = $user->id;
            $draft->save();
        }

        $stale = $existing->reject(fn(ShiftPlanDraft $d, string $key) => isset($seen[$key]));
        if ($stale->isNotEmpty()) {
            ShiftPlanDraft::query()->whereIn('id', $stale->pluck('id'))->delete();
        }
    }

    /**
     * Auto-create the live Shift for any `add` whose (date, workplace_shift) has none yet, so the
     * planner can stamp assignments onto bare dates (e.g. copy/paste) without first opening the
     * create-shift form. Created with `max_registered_temp = 0` so employees can't self-register
     * into the planner-made slot (an admin can lift that later); `temp_total`/`fixed_total` are
     * capped by the target shift totals sent by the planner when available. Inactive or unknown
     * shift types are skipped, so the validator still reports them as ShiftPlanningShiftMissing.
     *
     * @param array<int,array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null}> $drafts
     */
    private function ensureShiftsForAdds(User $user, Workplace $workplace, array $drafts): void
    {
        // Tally fixed/temp adds per (date, workplace_shift_id), keeping the copied source
        // shift totals as optional caps for the auto-created concrete shift.
        $needed = [];
        foreach ($drafts as $row) {
            if ($row['action'] !== ShiftPlanDraftAction::Add->value) {
                continue;
            }
            $date = Carbon::parse($row['date'])->format('Y-m-d');
            $key = "{$date}|{$row['workplace_shift_id']}";
            $needed[$key] ??= [
                'date' => $date,
                'workplace_shift_id' => $row['workplace_shift_id'],
                'fixed' => 0,
                'temp' => 0,
                'target_fixed_total' => null,
                'target_temp_total' => null,
            ];
            $row['is_fixed'] ? $needed[$key]['fixed']++ : $needed[$key]['temp']++;
            foreach (['target_fixed_total', 'target_temp_total'] as $totalKey) {
                if (array_key_exists($totalKey, $row) && $row[$totalKey] !== null) {
                    $needed[$key][$totalKey] =
                        $needed[$key][$totalKey] === null
                            ? (int) $row[$totalKey]
                            : max((int) $needed[$key][$totalKey], (int) $row[$totalKey]);
                }
            }
        }
        if (empty($needed)) {
            return;
        }

        $dates = array_values(array_unique(array_column($needed, 'date')));
        $shiftTypeIds = array_values(array_unique(array_column($needed, 'workplace_shift_id')));

        $existing = Shift::query()
            ->where('workplace_id', $workplace->id)
            ->whereIn('date', $dates)
            ->whereIn('workplace_shift_id', $shiftTypeIds)
            ->get()
            ->keyBy(fn(Shift $shift) => Carbon::parse($shift->date)->format('Y-m-d') . "|{$shift->workplace_shift_id}");

        $types = WorkplaceShift::query()
            ->where('workplace_id', $workplace->id)
            ->whereIn('id', $shiftTypeIds)
            ->get()
            ->keyBy('id');

        $toCreate = [];
        foreach ($needed as $key => $slot) {
            if ($existing->has($key)) {
                continue;
            }
            $type = $types->get($slot['workplace_shift_id']);
            if (!$type || !$type->is_active) {
                continue; // validator reports ShiftPlanningShiftMissing
            }
            $toCreate[] = [$type, $slot];
        }

        if (empty($toCreate)) {
            return;
        }

        // Auto-create materializes real Shift rows, so it requires shift.create on the workplace —
        // not just the shift.edit this endpoint is gated on. A shift.edit-only admin can still edit
        // slots that already have a concrete Shift; they just can't conjure new ones here, the same
        // way ShiftController::create is gated. Roll the whole save back (no partial removals/adds).
        if (!$user->isAbleTo(PermissionName::ShiftCreate->value, $workplace->id)) {
            throw new RuntimeException(self::SHIFT_CREATE_DENIED_SIGNAL);
        }

        foreach ($toCreate as [$type, $slot]) {
            ($this->createShift)(
                $type,
                $slot['date'],
                $user->id,
                $this->cappedTotal($slot['fixed'], $slot['target_fixed_total']),
                $this->cappedTotal($slot['temp'], $slot['target_temp_total']),
                0,
                $type->break_duration
            );
        }
    }

    private function cappedTotal(int $needed, ?int $targetTotal): int
    {
        return min($needed, $targetTotal ?? $needed);
    }

    /**
     * Distinct dates carrying an `add` or `update` in this (full-window) payload — the dates whose
     * resulting draft set must be validated.
     *
     * @param array<int,array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null}> $drafts
     * @return list<string>
     */
    private function draftDates(array $drafts): array
    {
        $dates = [];
        foreach ($drafts as $row) {
            if (
                in_array($row['action'], [ShiftPlanDraftAction::Add->value, ShiftPlanDraftAction::Update->value], true)
            ) {
                $dates[$row['date']] = true;
            }
        }

        return array_keys($dates);
    }

    /**
     * Run the set-aware per-date validator over the given dates and flatten the errors.
     *
     * @param list<string> $dates
     * @return list<array{user_id:int, workplace_shift_id:int, date:string, code:string, extra:array|null}>
     */
    private function validateDates(Workplace $workplace, array $dates): array
    {
        $errors = [];
        foreach ($dates as $date) {
            foreach (($this->validateDate)($workplace, $date) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param array<int,array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null}> $drafts
     * @return list<array{user_id:int, workplace_shift_id:int, date:string, code:string, extra:array|null}>
     */
    private function dateErrors(array $drafts): array
    {
        $errors = [];
        $minDate = Carbon::today()->addDays(2)->startOfDay();

        foreach ($drafts as $row) {
            if ($row['action'] === ShiftPlanDraftAction::Remove->value) {
                continue;
            }
            if (Carbon::parse($row['date'])->lt($minDate)) {
                $errors[] = $this->rowError($row, ErrorCode::ShiftPlanningDateTooSoon);
            }
        }

        return $errors;
    }

    /**
     * @param array<int,array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null}> $drafts
     * @return list<array{user_id:int, workplace_shift_id:int, date:string, code:string, extra:array|null}>
     */
    private function updateTargetErrors(Workplace $workplace, array $drafts): array
    {
        $errors = [];

        foreach ($drafts as $row) {
            if ($row['action'] !== ShiftPlanDraftAction::Update->value) {
                continue;
            }

            $shiftUserId = $row['shift_user_id'] ?? null;
            $exists =
                $shiftUserId &&
                ShiftUser::query()
                    ->whereKey($shiftUserId)
                    ->where('user_id', $row['user_id'])
                    ->where('status', ShiftUserStatus::Approved->value)
                    ->whereHas(
                        'shift',
                        fn($q) => $q->where('workplace_id', $workplace->id)->whereDate('date', $row['date'])
                    )
                    ->exists();

            if (!$exists) {
                $errors[] = $this->rowError($row, ErrorCode::ShiftUserNotFound);

                continue;
            }

            $targetExists = ShiftUser::query()
                ->where('user_id', $row['user_id'])
                ->whereKeyNot($shiftUserId)
                ->whereHas(
                    'shift',
                    fn($q) => $q
                        ->where('workplace_id', $workplace->id)
                        ->whereDate('date', $row['date'])
                        ->where('workplace_shift_id', $row['workplace_shift_id'])
                )
                ->exists();

            if ($targetExists) {
                $errors[] = $this->rowError($row, ErrorCode::ScheduleShiftOverlap);
            }
        }

        return $errors;
    }

    /**
     * @param array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null} $row
     */
    private function liveRecordForRemoval(Workplace $workplace, array $row): ?ShiftUser
    {
        $query = ShiftUser::query()
            ->where('user_id', $row['user_id'])
            ->where('status', '!=', ShiftUserStatus::Rejected->value)
            ->whereHas(
                'shift',
                fn($q) => $q
                    ->where('workplace_id', $workplace->id)
                    ->where('date', $row['date'])
                    ->where('workplace_shift_id', $row['workplace_shift_id'])
            );

        if (!empty($row['shift_user_id'])) {
            $query->whereKey($row['shift_user_id']);
        }

        return $query->first();
    }

    /**
     * @param array{date:string, workplace_shift_id:int, user_id:int, shift_user_id?:int|null, workplace_position_id:?int, is_fixed:bool, action:string, target_fixed_total?:int|null, target_temp_total?:int|null} $row
     * @return array{user_id:int, workplace_shift_id:int, date:string, code:string, extra:array|null}
     */
    private function rowError(array $row, ErrorCode $code): array
    {
        return [
            'user_id' => $row['user_id'],
            'workplace_shift_id' => $row['workplace_shift_id'],
            'date' => $row['date'],
            'code' => $code->value,
            'extra' => null,
        ];
    }

    private function rowKey(string $date, int $workplaceShiftId, int $userId): string
    {
        return "{$date}|{$workplaceShiftId}|{$userId}";
    }
}
