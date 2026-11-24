<?php

namespace App\Actions\Manage\ShiftPlanning;

use App\Actions\ActionResult;
use App\Actions\Chat\Shift\SyncShiftChatParticipantsAction;
use App\Actions\Manage\Shift\LockUsersForShiftWriteAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Events\ShiftUserApproved;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Commits the admin's selected (user, date) cells from draft to live (requirements §3.3, §4.1).
 *
 * Converts draft adds into approved live ShiftUser rows and applies draft updates to their
 * original live ShiftUser rows in place. Removals are not handled here — they take effect on
 * live data at Save (see SaveShiftPlanDraftAction), so any remove draft is skipped defensively.
 *
 * Per-date atomic, but commit-scoped to the selected cells: each affected date is validated
 * set-aware over its whole planned set immediately before committing, and is its own
 * transaction. Because the committed cells are a subset of the validated set, committing them is
 * always safe (capacity / work-hour / rest checks are monotonic under removing shifts). A date
 * whose full planned set fails validation commits nothing and reports its exact per-cell errors
 * so the admin can fix and resubmit. Only the selected users' draft rows are committed and
 * cleaned up; other cells on the same date stay as drafts. Notifications/emails come only from
 * the existing ShiftUserApproved listener stack — no Shift Planning-specific notification code.
 */
class FinalizeShiftPlanAction
{
    public function __construct(
        private readonly ShiftPlanningLockService $lockService,
        private readonly ValidateShiftPlanDateAction $validateDate,
        private readonly LockUsersForShiftWriteAction $lockUsersForShiftWrite
    ) {}

    /**
     * @param list<array{user_id:int, date:string}> $cells selected (user, physical Shift.date) cells
     */
    public function __invoke(User $user, int $workplaceId, array $cells): ActionResult
    {
        if (
            !$user->isAbleTo(PermissionName::ShiftCreate->value, $workplaceId) ||
            !$user->isAbleTo(PermissionName::ShiftEdit->value, $workplaceId)
        ) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }
        if (!$this->lockService->isHeldBy($workplaceId, $user->id)) {
            return new ActionResult(error: ErrorCode::ShiftPlanningLockRequired, status: 423);
        }

        $workplace = Workplace::query()->find($workplaceId);
        if (!$workplace) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        // Selected user ids grouped by date — each date commits as its own unit.
        $userIdsByDate = [];
        foreach ($cells as $cell) {
            $userIdsByDate[$cell['date']][] = (int) $cell['user_id'];
        }
        ksort($userIdsByDate);

        $minDate = Carbon::today()->addDays(2)->startOfDay();
        $results = [];

        foreach ($userIdsByDate as $date => $userIds) {
            $userIds = array_values(array_unique($userIds));

            if (Carbon::parse($date)->lt($minDate)) {
                $results[] = $this->failure($date, [
                    [
                        'date' => $date,
                        'code' => ErrorCode::ShiftPlanningDateTooSoon->value,
                        'extra' => null,
                    ],
                ]);

                continue;
            }

            // Validate set-aware (live + draft) immediately before committing this date.
            $errors = ($this->validateDate)($workplace, $date);
            if (!empty($errors)) {
                $results[] = $this->failure($date, $errors);

                continue;
            }

            $results[] = [
                'date' => $date,
                'committed' => true,
                'notified_count' => $this->commitDate($user, $workplace, $date, $userIds),
                'errors' => [],
            ];
        }

        return new ActionResult(data: ['results' => $results]);
    }

    /**
     * @param int[] $userIds the selected users whose drafts commit on this date
     * @return int number of notifications emitted (one per newly-approved assignment)
     */
    private function commitDate(User $user, Workplace $workplace, string $date, array $userIds): int
    {
        $notified = 0;

        // Finalize moves/approves several rows on the same shift; batch defers the chat sync so each
        // affected shift is reconciled once after the transaction instead of once per ShiftUser save.
        SyncShiftChatParticipantsAction::batch(function () use ($user, $workplace, $date, $userIds, &$notified) {
            DB::transaction(function () use ($user, $workplace, $date, $userIds, &$notified) {
                $disabledUserIds = $this->lockUsersForShiftWrite->disabledIds($userIds);

                $drafts = ShiftPlanDraft::query()
                    ->with('shiftUser.shift')
                    ->where('workplace_id', $workplace->id)
                    ->where('date', $date)
                    ->whereIn('user_id', $userIds)
                    ->get();

                $shifts = Shift::query()->where('workplace_id', $workplace->id)->where('date', $date)->get();
                $shiftsByType = $shifts->keyBy('workplace_shift_id');
                $shiftById = $shifts->keyBy('id');

                $shiftDate = Carbon::parse($date);
                $approverId = $user->id;
                $approvedAt = now();
                $approvedUserIdsByShiftId = [];

                foreach ($drafts as $draft) {
                    // Finalize only converts add/update drafts to live; removals already took effect at Save.
                    if (!in_array($draft->action, [ShiftPlanDraftAction::Add, ShiftPlanDraftAction::Update], true)) {
                        continue;
                    }

                    if (in_array($draft->user_id, $disabledUserIds, true)) {
                        continue;
                    }

                    $shift = $shiftsByType->get($draft->workplace_shift_id);
                    if (!$shift) {
                        continue; // guarded by validation; defensive
                    }

                    $record = $this->recordForDraft($draft, $shift);
                    if (!$record && $draft->action === ShiftPlanDraftAction::Update) {
                        continue; // guarded by save validation and cleanup observers; defensive
                    }
                    $wasApproved = $record && $record->status === ShiftUserStatus::Approved->value;

                    if ($record) {
                        $record->update([
                            'shift_id' => $shift->id,
                            'workplace_position_id' => $draft->workplace_position_id,
                            'is_fixed' => $draft->is_fixed,
                            'status' => ShiftUserStatus::Approved->value,
                            'approver_id' => $approverId,
                            'approved_at' => $approvedAt,
                        ]);
                    } else {
                        $record = $shift->shiftUsers()->create([
                            'user_id' => $draft->user_id,
                            'workplace_position_id' => $draft->workplace_position_id,
                            'is_fixed' => $draft->is_fixed,
                            'status' => ShiftUserStatus::Approved->value,
                            'created_by' => $approverId,
                            'approver_id' => $approverId,
                            'approved_at' => $approvedAt,
                        ]);
                    }

                    if (!$wasApproved) {
                        ShiftUserApproved::dispatch($shiftDate, $record);
                        $notified++;
                        $approvedUserIdsByShiftId[$shift->id][] = $draft->user_id;
                    }
                }

                $this->rejectSiblingPending($shiftById, $approvedUserIdsByShiftId);

                // Cleanup: delete the committed cells' draft rows (selected users on this date).
                ShiftPlanDraft::query()->whereIn('id', $drafts->pluck('id'))->delete();
            });
        });

        return $notified;
    }

    private function recordForDraft(ShiftPlanDraft $draft, Shift $shift): ?ShiftUser
    {
        if ($draft->action === ShiftPlanDraftAction::Update) {
            return $draft->shiftUser && $draft->shiftUser->status === ShiftUserStatus::Approved->value
                ? $draft->shiftUser
                : null;
        }

        return ShiftUser::query()->where('shift_id', $shift->id)->where('user_id', $draft->user_id)->first();
    }

    /**
     * Mirror AssignShiftUsersAction: a newly-approved user's pending registrations on
     * sibling shifts sharing the same adjusted_date are rejected. Bulk query-builder
     * update bypasses the observer (safe: only pending rows, never chat participants).
     *
     * @param \Illuminate\Support\Collection<int,Shift> $shiftById
     * @param array<int,int[]> $approvedUserIdsByShiftId
     */
    private function rejectSiblingPending($shiftById, array $approvedUserIdsByShiftId): void
    {
        foreach ($approvedUserIdsByShiftId as $shiftId => $userIds) {
            $shift = $shiftById->get($shiftId);
            if (!$shift) {
                continue;
            }
            $siblingShiftIds = Shift::query()
                ->where('adjusted_date', $shift->adjusted_date)
                ->where('id', '!=', $shift->id)
                ->pluck('id');
            if ($siblingShiftIds->isEmpty()) {
                continue;
            }
            ShiftUser::query()
                ->whereIn('shift_id', $siblingShiftIds)
                ->whereIn('user_id', $userIds)
                ->where('status', ShiftUserStatus::Pending->value)
                ->update(['status' => ShiftUserStatus::Rejected->value]);
        }
    }

    /**
     * @param list<array<string,mixed>> $errors
     * @return array<string,mixed>
     */
    private function failure(string $date, array $errors): array
    {
        return ['date' => $date, 'committed' => false, 'notified_count' => 0, 'errors' => $errors];
    }
}
