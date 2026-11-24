<?php

namespace App\Actions\Manage\ShiftPlanning;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\Common\AbsenceService;
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the whole Shift Planner window payload: workplace, shift types, positions,
 * members (with fixed-schedule + paper-validity + last-used-position metadata),
 * concrete shifts, live (approved) assignments, draft rows, and the current lock
 * holder. The grid and the finalize validator both project from live + draft rows.
 */
class GetShiftPlanningAction
{
    private const DEFAULT_MEMBER_ACTIVITY_DAYS = 60;

    public function __construct(
        private readonly AbsenceService $absenceService,
        private readonly ShiftPlanningLockService $lockService
    ) {}

    public function __invoke(User $user, int $workplaceId, ?string $from, ?int $memberActivityDays = null): ActionResult
    {
        if (!$user->isAbleTo(PermissionName::ShiftList->value, $workplaceId)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $workplace = Workplace::query()->find($workplaceId);
        if (!$workplace) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        // Minimum planning date is today + 2 days in JST (app timezone); today/tomorrow
        // are not plannable here. The window runs from `from` (clamped) through the end
        // of the following month.
        $minDate = Carbon::today()->addDays(2);
        $anchor = $from ? Carbon::parse($from) : $minDate->clone();
        $start = $anchor->lt($minDate) ? $minDate->clone() : $anchor->clone();
        $end = $anchor->clone()->startOfMonth()->addMonth()->endOfMonth();
        $fromDate = $start->format('Y-m-d');
        $toDate = $end->format('Y-m-d');

        $concreteShifts = Shift::query()
            ->where('workplace_id', $workplaceId)
            ->whereBetween('date', [$fromDate, $toDate])
            ->get([
                'id',
                'date',
                'workplace_shift_id',
                'fixed_total',
                'temp_total',
                'max_registered_temp',
                'break_minutes',
            ]);

        $liveAssignments = ShiftUser::query()
            ->whereIn('shift_id', $concreteShifts->pluck('id'))
            ->where('status', ShiftUserStatus::Approved->value)
            ->get(['id', 'shift_id', 'user_id', 'workplace_position_id', 'is_fixed', 'status', 'confirmed_at']);

        $drafts = ShiftPlanDraft::query()
            ->where('workplace_id', $workplaceId)
            ->whereBetween('date', [$fromDate, $toDate])
            ->get([
                'id',
                'date',
                'workplace_shift_id',
                'user_id',
                'shift_user_id',
                'workplace_position_id',
                'is_fixed',
                'action',
            ]);

        // Shift types: active ones, plus any inactive type still referenced by a concrete
        // shift in the window so old data keeps rendering.
        $referencedShiftTypeIds = $concreteShifts->pluck('workplace_shift_id')->unique();
        $workplaceShifts = WorkplaceShift::query()
            ->where('workplace_id', $workplaceId)
            ->where(fn($q) => $q->where('is_active', true)->orWhereIn('id', $referencedShiftTypeIds))
            ->orderBy('start_time')
            ->orderBy('end_time')
            ->get([
                'id',
                'start_time',
                'end_time',
                'start_break_time',
                'end_break_time',
                'break_duration',
                'date_adjustment',
                'is_active',
                'salary_per_hour',
            ]);

        $referencedPositionIds = $liveAssignments
            ->pluck('workplace_position_id')
            ->merge($drafts->pluck('workplace_position_id'))
            ->filter()
            ->unique();
        $fixedSchedulePositionIds = FixedScheduleDetail::query()
            ->join('fixed_schedules', 'fixed_schedules.id', '=', 'fixed_schedule_details.fixed_schedule_id')
            ->where('fixed_schedules.workplace_id', $workplaceId)
            ->whereNotNull('fixed_schedule_details.workplace_position_id')
            ->select('fixed_schedule_details.workplace_position_id');
        $positions = WorkplacePosition::query()
            ->where('workplace_id', $workplaceId)
            ->where(
                fn($q) => $q
                    ->where('is_active', true)
                    ->orWhereIn('id', $referencedPositionIds)
                    ->orWhereIn('id', $fixedSchedulePositionIds)
            )
            ->orderBy('sort_order')
            ->get(['id', 'name', 'is_active', 'sort_order']);

        $memberActivityDays ??= self::DEFAULT_MEMBER_ACTIVITY_DAYS;
        // Trailing window for the "recent workload" count on the employee cell. Mirrors
        // the member-activity window; clamps to the default when the roster is unfiltered.
        $recentShiftDays = $memberActivityDays > 0 ? $memberActivityDays : self::DEFAULT_MEMBER_ACTIVITY_DAYS;

        return new ActionResult(
            data: [
                'workplace' => [
                    'id' => $workplace->id,
                    'name' => $workplace->name,
                    'min_rest_hours' => $workplace->min_rest_hours,
                ],
                'window' => [
                    'from' => $fromDate,
                    'to' => $toDate,
                    'min_date' => $minDate->format('Y-m-d'),
                    'recent_shift_days' => $recentShiftDays,
                ],
                'workplace_shifts' => $workplaceShifts,
                'positions' => $positions,
                'members' => $this->members(
                    $workplaceId,
                    $memberActivityDays,
                    $liveAssignments->pluck('user_id')->all(),
                    $drafts->pluck('user_id')->all()
                ),
                'shifts' => $concreteShifts,
                'live_assignments' => $liveAssignments,
                'drafts' => $drafts,
                'lock' => $this->lockState($workplaceId, $user->id),
            ]
        );
    }

    /**
     * @param  array<int,int>  $windowAssignmentUserIds
     * @param  array<int,int>  $draftUserIds
     * @return array<int,array<string,mixed>>
     */
    private function members(
        int $workplaceId,
        int $memberActivityDays,
        array $windowAssignmentUserIds,
        array $draftUserIds
    ): array {
        $memberIds = $this->memberIds($workplaceId, $memberActivityDays, $windowAssignmentUserIds, $draftUserIds);

        if ($memberIds !== null && empty($memberIds)) {
            return [];
        }

        $usersQuery = DB::table('users')
            ->leftJoin('workplace_users', function ($join) use ($workplaceId) {
                $join
                    ->on('workplace_users.user_id', '=', 'users.id')
                    ->where('workplace_users.workplace_id', $workplaceId);
            })
            ->leftJoin('user_infos', 'user_infos.user_id', '=', 'users.id');

        if ($memberIds === null) {
            $usersQuery->whereNotNull('workplace_users.user_id');
        } else {
            $usersQuery->whereIn('users.id', $memberIds);
        }

        $users = $usersQuery
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.staff_no',
                'users.first_name',
                'users.last_name',
                'users.short_name',
                'users.paper_status',
                'users.is_disabled',
                'user_infos.student_card_expiry_date',
                'user_infos.residence_expiry_date_1',
                'user_infos.residence_expiry_date_2',
                'user_infos.residence_expiry_date_3',
                'user_infos.passport_expiry_date',
            ]);
        $userIds = $users->pluck('id')->all();
        $documentExpiryAlertDate = Carbon::now()->addDays((int) config('work.document_expiry_alert'))->toDateString();

        $absenceCounts =
            $memberIds === null || count($userIds) > 2000
                ? $this->absenceService->getWorkplaceMemberAbsenceCount($workplaceId)
                : $this->absenceService->getUserAbsenceCount($userIds);
        $fixedSchedules = $this->fixedSchedules($workplaceId, $userIds);
        $recentShiftCounts = $this->recentShiftCounts($workplaceId, $userIds, $memberActivityDays);

        return $users
            ->map(
                fn($u) => [
                    'id' => $u->id,
                    'staff_no' => $u->staff_no,
                    'name' => $u->short_name ?: $u->last_name . ' ' . $u->first_name,
                    'paper_status' => $u->paper_status,
                    'is_disabled' => (bool) $u->is_disabled,
                    'student_card_expiry_alert' => $this->expiresBefore(
                        $u->student_card_expiry_date,
                        $documentExpiryAlertDate
                    ),
                    'residence_card_expiry_alert' => $this->residenceCardExpiresBefore($u, $documentExpiryAlertDate),
                    'passport_expiry_alert' => $this->expiresBefore($u->passport_expiry_date, $documentExpiryAlertDate),
                    'absence_count' => $absenceCounts[$u->id] ?? 0,
                    'fixed_schedules' => $fixedSchedules[$u->id] ?? [],
                    'recent_shift_count' => $recentShiftCounts[$u->id] ?? 0,
                ]
            )
            ->values()
            ->all();
    }

    /**
     * Return null to load the full workplace roster. Otherwise returns the smart
     * default roster: active members who recently worked here, plus anyone already
     * visible in this planning window via live assignment/draft/fixed schedule.
     *
     * @param  array<int,int>  $windowAssignmentUserIds
     * @param  array<int,int>  $draftUserIds
     * @return array<int,int>|null
     */
    private function memberIds(
        int $workplaceId,
        int $memberActivityDays,
        array $windowAssignmentUserIds,
        array $draftUserIds
    ): ?array {
        if ($memberActivityDays <= 0) {
            return null;
        }

        $recentFromDate = Carbon::today()->subDays($memberActivityDays)->toDateString();
        $recentToDate = Carbon::today()->toDateString();

        $recentShiftUserIds = ShiftUser::query()
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->join('workplace_users', function ($join) use ($workplaceId) {
                $join
                    ->on('workplace_users.user_id', '=', 'shift_users.user_id')
                    ->where('workplace_users.workplace_id', $workplaceId);
            })
            ->join('users', 'users.id', '=', 'shift_users.user_id')
            ->where('shifts.workplace_id', $workplaceId)
            ->where('users.is_disabled', false)
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->whereBetween('shifts.date', [$recentFromDate, $recentToDate])
            ->distinct()
            ->pluck('shift_users.user_id')
            ->all();

        $fixedScheduleUserIds = FixedSchedule::query()
            ->join('workplace_users', function ($join) use ($workplaceId) {
                $join
                    ->on('workplace_users.user_id', '=', 'fixed_schedules.user_id')
                    ->where('workplace_users.workplace_id', $workplaceId);
            })
            ->join('users', 'users.id', '=', 'fixed_schedules.user_id')
            ->where('fixed_schedules.workplace_id', $workplaceId)
            ->where('users.is_disabled', false)
            ->distinct()
            ->pluck('fixed_schedules.user_id')
            ->all();

        return collect($recentShiftUserIds)
            ->merge($windowAssignmentUserIds)
            ->merge($draftUserIds)
            ->merge($fixedScheduleUserIds)
            ->filter(fn($userId) => $userId !== null)
            ->map(fn($userId) => (int) $userId)
            ->unique()
            ->values()
            ->all();
    }

    private function expiresBefore(?string $date, string $alertDate): bool
    {
        return !empty($date) && $date <= $alertDate;
    }

    private function residenceCardExpiresBefore(object $user, string $alertDate): bool
    {
        $expiryDates = array_filter([
            $user->residence_expiry_date_1,
            $user->residence_expiry_date_2,
            $user->residence_expiry_date_3,
        ]);
        $latestResidenceExpiryDate = empty($expiryDates) ? null : max($expiryDates);

        return $this->expiresBefore($latestResidenceExpiryDate, $alertDate);
    }

    /**
     * @return array<int,array<int,array{workplace_shift_id:int, day:int, workplace_position_id:int|null}>>
     */
    private function fixedSchedules(int $workplaceId, array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return FixedSchedule::query()
            ->where('workplace_id', $workplaceId)
            ->whereIn('user_id', $userIds)
            ->with('details:id,fixed_schedule_id,workplace_shift_id,workplace_position_id,day')
            ->get(['id', 'user_id'])
            ->mapWithKeys(
                fn(FixedSchedule $fs) => [
                    $fs->user_id => $fs->details
                        ->map(
                            fn($d) => [
                                'workplace_shift_id' => $d->workplace_shift_id,
                                'day' => $d->day,
                                'workplace_position_id' => $d->workplace_position_id,
                            ]
                        )
                        ->all(),
                ]
            )
            ->all();
    }

    /**
     * Approved shifts each member actually worked on this workplace over the trailing
     * activity window (today − N … today). Drives the "recent workload" count shown on
     * the planner's employee cell.
     *
     * @return array<int,int>
     */
    private function recentShiftCounts(int $workplaceId, array $userIds, int $memberActivityDays): array
    {
        if (empty($userIds)) {
            return [];
        }

        $days = $memberActivityDays > 0 ? $memberActivityDays : self::DEFAULT_MEMBER_ACTIVITY_DAYS;
        $fromDate = Carbon::today()->subDays($days)->toDateString();
        $toDate = Carbon::today()->toDateString();

        return DB::table('shift_users')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->where('shifts.workplace_id', $workplaceId)
            ->whereIn('shift_users.user_id', $userIds)
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->whereBetween('shifts.date', [$fromDate, $toDate])
            ->groupBy('shift_users.user_id')
            ->selectRaw('shift_users.user_id, COUNT(*) as cnt')
            ->pluck('cnt', 'shift_users.user_id')
            ->map(fn($c) => (int) $c)
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lockState(int $workplaceId, int $userId): ?array
    {
        $lock = $this->lockService->get($workplaceId);
        if (!$lock) {
            return null;
        }
        $lock['is_me'] = $lock['user_id'] === $userId;

        return $lock;
    }
}
