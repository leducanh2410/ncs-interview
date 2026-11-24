<?php

namespace App\Services\Manage;

use App\Actions\Admin\User\ResolveOutstandingDocumentStepsAction;
use App\Actions\Manage\Shift\AttachFixedSchedulePositionsAction;
use App\Actions\Manage\Shift\CreateShiftAction;
use App\Actions\Manage\Shift\FindLengthenedShiftViolationsAction;
use App\Enums\ErrorCode;
use App\Enums\Gender;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Exports\ShiftExport;
use App\Helpers\WorkHelper;
use App\Models\CheckIn;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\Common\AbsenceService;
use App\Services\Common\SimilarShiftService;
use App\ValueObjects\RecruitmentCriteria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShiftService
{
    public function __construct(
        private readonly AbsenceService $absenceService,
        private readonly SimilarShiftService $similarShiftService,
        private readonly CreateShiftAction $createShiftAction,
        private readonly ResolveOutstandingDocumentStepsAction $resolveOutstandingDocumentSteps,
        private readonly AttachFixedSchedulePositionsAction $attachFixedSchedulePositions
    ) {}

    public function searchUserForShift(User $user, array $conditions): array
    {
        $workplaceId = $conditions['workplace_id'];
        if (!$user->isAbleTo(PermissionName::ShiftEdit->value, $workplaceId)) {
            return [];
        }

        $query = $conditions['query'] ?? null;
        $exactStaffNo = $conditions['staff_no'] ?? null;

        $searchConditions = [];
        if ($exactStaffNo) {
            $searchConditions['exactStaffNo'] = $exactStaffNo;
        } elseif ($query) {
            $searchConditions['query'] = $query;
        }
        if (!empty($conditions['group_ids'])) {
            $searchConditions['groups'] = $conditions['group_ids'];
        }
        $searchConditions['workplace'] = $workplaceId;

        $users = User::getFiltered($searchConditions, ['info'], ['id' => 'asc']);

        $userIds = $users->pluck('id')->toArray();
        $absenceCounts = $this->absenceService->getUserAbsenceCount($userIds);
        $similarShiftCounts = $this->getSimilarShiftCounts(
            $userIds,
            (int) $workplaceId,
            $conditions['workplace_shift_id'] ?? null
        );

        return $users
            ->map(function (User $u) use ($absenceCounts, $similarShiftCounts) {
                return [
                    'id' => $u->id,
                    'staff_no' => $u->staff_no,
                    'name' => $u->short_name,
                    'email' => $u->email,
                    'paper_status' => $u->paper_status,
                    'student_card_expiry_alert' => $u->student_card_expiry_alert,
                    'residence_card_expiry_alert' => $u->residence_card_expiry_alert,
                    'passport_expiry_alert' => $u->passport_expiry_alert,
                    'absence_count' => $absenceCounts[$u->id] ?? 0,
                    'similar_shift_count' => $similarShiftCounts[$u->id] ?? null,
                ];
            })
            ->toArray();
    }

    public function getActiveShifts(User $user)
    {
        $shifts = Shift::query()
            ->with(['workplaceShift', 'workplace'])
            ->where('date', '>=', Carbon::yesterday()->format('Y-m-d'))
            ->applyWorkplaceFilter($user, PermissionName::ShiftList->value)
            ->get();

        return $shifts
            ->filter(function (Shift $shift) {
                [$start, $end] = $shift->getTimeRange();

                return $end->isFuture();
            })
            ->values();
    }

    public function findByDateAndWorkplaceShiftId(string $date, int $workplaceShiftId): ?Shift
    {
        return Shift::query()->where('date', $date)->where('workplace_shift_id', $workplaceShiftId)->first();
    }

    public function createShift(
        User $user,
        string $date,
        int $workplaceShiftId,
        int $fixedTotal,
        int $tempTotal,
        ?int $maxRegisteredTemp,
        ?int $break_minutes
    ): array {
        $workplaceShift = WorkplaceShift::query()->find($workplaceShiftId);
        if (!$workplaceShift->is_active) {
            return [
                'error' => ErrorCode::ShiftCannotCreateInactive,
                'status' => 400,
            ];
        }

        // Validate workplace permission
        if (!$user->isAbleTo(PermissionName::ShiftCreate->value, $workplaceShift->workplace_id)) {
            return [
                'error' => ErrorCode::Unauthorized,
                'status' => 403,
            ];
        }

        $shift = ($this->createShiftAction)(
            $workplaceShift,
            $date,
            auth()->id(),
            $fixedTotal,
            $tempTotal,
            $maxRegisteredTemp,
            $break_minutes
        );

        return [
            'error' => null,
            'data' => $shift,
        ];
    }

    public function getShiftDetail(User $user, Shift $shift): array
    {
        // Validate workplace permission
        if (!$user->isAbleTo(PermissionName::ShiftList->value, $shift->workplace_id)) {
            return [
                'error' => ErrorCode::Unauthorized,
                'status' => 403,
            ];
        }

        $shift->load(['workplaceShift.leaders', 'workplace', 'creator']);
        $fixedScheduleUsers = User::query()
            ->select('users.*')
            ->addSelect(
                'fixed_schedule_details.workplace_position_id as ' . AttachFixedSchedulePositionsAction::SOURCE_COLUMN
            )
            ->with('info')
            ->join('fixed_schedules', 'users.id', '=', 'fixed_schedules.user_id')
            ->join('fixed_schedule_details', 'fixed_schedules.id', '=', 'fixed_schedule_details.fixed_schedule_id')
            ->where('fixed_schedule_details.workplace_shift_id', $shift->workplace_shift_id)
            ->where('fixed_schedule_details.day', Carbon::parse($shift->date)->dayOfWeek)
            ->get();
        ($this->attachFixedSchedulePositions)($fixedScheduleUsers);
        $shiftUsers = $shift
            ->shiftUsers()
            ->with(['user.info', 'absentNotice', 'workplacePosition', 'checkIn'])
            ->orderBy('id')
            ->get();

        // Collect all distinct user ids and attach absence count
        $userIds = $shiftUsers
            ->pluck('user_id')
            ->unique()
            ->merge($fixedScheduleUsers->pluck('id')->unique())
            ->unique()
            ->values()
            ->toArray();
        $absenceCounts = empty($userIds) ? [] : $this->absenceService->getUserAbsenceCount($userIds);

        foreach ($shiftUsers as $shiftUser) {
            if ($shiftUser->user) {
                $shiftUser->user->setAttribute('absence_count', $absenceCounts[$shiftUser->user_id] ?? 0);
            }
            if ($shiftUser->checkIn) {
                $shiftUser->checkIn->setRelation('shiftUser', $shiftUser);
                $shiftUser->checkIn->withReasonRequirements()->unsetRelation('shiftUser');
            }
        }

        foreach ($fixedScheduleUsers as $fixedUser) {
            $fixedUser->setAttribute('absence_count', $absenceCounts[$fixedUser->id] ?? 0);
        }

        $shiftMembers = $shiftUsers->pluck('user')->filter()->merge($fixedScheduleUsers);
        $outstandingDocumentSteps = $this->resolveOutstandingDocumentSteps->forUsers($shiftMembers);
        foreach ($shiftMembers as $shiftMember) {
            $shiftMember->setAttribute('outstanding_document_steps', $outstandingDocumentSteps[$shiftMember->id] ?? []);
            $shiftMember->unsetRelation('paper');
        }

        $positionTotals = $shift->positionTotals()->get();
        $positionIds = $shiftUsers
            ->pluck('workplace_position_id')
            ->merge($positionTotals->pluck('workplace_position_id'))
            ->filter()
            ->unique();
        $positions = WorkplacePosition::query()
            ->where('workplace_id', $shift->workplace_id)
            ->where(function (Builder $builder) use ($positionIds) {
                if ($positionIds->isEmpty()) {
                    return $builder->where('is_active', true);
                }

                return $builder->whereIn('id', $positionIds)->orWhere('is_active', true);
            })
            ->get();

        $tempCount = 0;
        $fixedCount = 0;
        $tempPending = 0;
        $fixedPending = 0;
        foreach ($shiftUsers as $shiftUser) {
            if ($shiftUser->status === ShiftUserStatus::Approved->value) {
                if ($shiftUser->is_fixed) {
                    $fixedCount++;
                } else {
                    $tempCount++;
                }
            } elseif ($shiftUser->status === ShiftUserStatus::Pending->value) {
                if ($shiftUser->is_fixed) {
                    $fixedPending++;
                } else {
                    $tempPending++;
                }
            }
        }
        $shift->temp_count = $tempCount;
        $shift->fixed_count = $fixedCount;
        $shift->temp_pending_count = $tempPending;
        $shift->fixed_pending_count = $fixedPending;

        // Compute salary_per_hour (most specific override wins)
        $salary = config('salary.salary_per_hour');
        $systemConfig = SystemConfig::query()->first();
        if ($systemConfig) {
            $salary = $systemConfig->salary_per_hour;
        }
        if ($shift->workplace->salary_per_hour) {
            $salary = $shift->workplace->salary_per_hour;
        }
        if ($shift->workplaceShift->salary_per_hour) {
            $salary = $shift->workplaceShift->salary_per_hour;
        }
        $shift->salary_per_hour = $salary;
        $shift->work_hours = round($shift->getDuration(), 2);

        return [
            'shift' => $shift,
            'shift_users' => $shiftUsers,
            'fixed_schedule_users' => $fixedScheduleUsers,
            'positions' => $positions,
            'position_totals' => $positionTotals,
            'hot_bonus_amounts' => $this->hotBonusAmountsForShift($shift),
        ];
    }

    public function updateConfig(User $user, Shift $shift, array $data): array
    {
        if (!$user->isAbleTo(PermissionName::ShiftEdit->value, $shift->workplace_id)) {
            return [
                'error' => ErrorCode::Unauthorized,
                'status' => 403,
            ];
        }

        $previousShiftHours = $shift->getDuration();
        $shift->break_minutes = $data['break_minutes'] ?? null;
        $violations = app(FindLengthenedShiftViolationsAction::class)($shift, $previousShiftHours);
        if (!empty($violations)) {
            return [
                'error' => ErrorCode::ScheduleMaxWorkHoursExceeded,
                'status' => 422,
                'data' => $this->describeWeeklyHourViolations($violations),
            ];
        }

        $shift->update([
            'fixed_total' => $data['fixed_total'],
            'temp_total' => $data['temp_total'],
            'max_registered_temp' => $data['max_registered_temp'] ?? null,
            'break_minutes' => $data['break_minutes'] ?? null,
            // The config form always submits every field, so an absent flag means unticked.
            'is_overtime_by_duration' => $data['is_overtime_by_duration'] ?? false,
        ]);

        return ['error' => null, 'data' => $shift];
    }

    public function exportShiftDetail(User $user, Shift $shift): ShiftExport
    {
        // Validate workplace permission
        if (!$user->isAbleTo(PermissionName::ShiftList->value, $shift->workplace_id)) {
            throw new \Exception('Permission denied', 403);
        }

        $shiftUsers = $shift
            ->shiftUsers()
            ->where('status', ShiftUserStatus::Approved)
            ->with(['user.info'])
            ->get()
            ->values();

        $userIds = $shiftUsers->pluck('user_id')->unique()->toArray();
        $positionIds = $shiftUsers->pluck('workplace_position_id')->unique()->toArray();

        $workplacePositions = WorkplacePosition::query()
            ->where('workplace_id', $shift->workplace_id)
            ->where(function (Builder $builder) use ($positionIds) {
                if (empty($positionIds)) {
                    return $builder->where('is_active', true);
                }

                return $builder->whereIn('id', $positionIds)->orWhere('is_active', true);
            })
            ->get()
            ->pluck('name', 'id');

        if ($shiftUsers->isEmpty()) {
            return new ShiftExport(collect(), $workplacePositions->values());
        }

        $historyData = DB::table('check_ins as ci')
            ->select([
                'ci.user_id',
                DB::raw("GROUP_CONCAT(DISTINCT su.workplace_position_id SEPARATOR ',') as position_ids"),
                DB::raw('COUNT(*) as work_days'),
                DB::raw('MIN(ci.check_in_time) as first_time'),
            ])
            ->join('shift_users as su', 'ci.shift_user_id', '=', 'su.id')
            ->join('shifts as s', 'ci.shift_id', '=', 's.id')
            ->where('s.workplace_id', $shift->workplace_id)
            ->where('su.is_absent', false)
            ->whereIn('ci.user_id', $userIds)
            ->groupBy('ci.user_id')
            ->get()
            ->keyBy('user_id');

        $data = $shiftUsers->map(function ($shiftUser, $key) use ($workplacePositions, $historyData) {
            $user = $shiftUser->user;
            $userHistoryData = $historyData->get($user->id);

            $totalWorkDays = $userHistoryData ? $userHistoryData->work_days : 0;
            $firstDayAtWork = $userHistoryData ? Carbon::parse($userHistoryData->first_time)->format('Y-m-d') : '';

            $positions = $workplacePositions
                ->mapWithKeys(function ($name, $id) use ($userHistoryData) {
                    if (!$userHistoryData) {
                        return ["position_{$id}" => ''];
                    }
                    $ids = explode(',', $userHistoryData->position_ids);

                    return ["position_{$id}" => in_array($id, $ids) ? '●' : ''];
                })
                ->toArray();

            $isAbove18 = true;
            if ($user->info && $user->info->birthday) {
                $isAbove18 = Carbon::parse($user->info->birthday)->age > 18;
            }

            return [
                'id' => $key + 1,
                'staff_no' => $user->staff_no,
                'name' => $user->short_name,
                'nameKana' => $user->name_kana,
                'gender' => $user->info?->gender === Gender::Female->value ? 'F' : 'M',
                'is_above_18' => $isAbove18 ? '成年' : '未成年',
                'nationality' => Nationality::toCrossStaffString($user->info?->nationality),
                'japanese_level' => Str::upper($user->info?->japanese_level),
                'today_position' => $workplacePositions[$shiftUser->workplace_position_id],
                ...$positions,
                'number_work_day' => $totalWorkDays,
                'first_day_at_work' => $firstDayAtWork,
            ];
        });

        return new ShiftExport($data, $workplacePositions->values());
    }

    public function checkInUser(ShiftUser $shiftUser, ?string $date, ?string $time): CheckIn
    {
        $shift = $shiftUser->shift;
        if (!$date) {
            $date = $shift->date;
        }
        if (!$time) {
            $time = $shift->workplaceShift->start_time;
        }
        $checkInTime = new Carbon($date . ' ' . $time);

        return CheckIn::query()->firstOrCreate(
            [
                'shift_user_id' => $shiftUser->id,
                'shift_id' => $shiftUser->shift_id,
                'user_id' => $shiftUser->user_id,
            ],
            [
                'check_in_time' => $checkInTime,
                'checked_in_by' => Auth::id(),
            ]
        );
    }

    public function checkOutUser(ShiftUser $shiftUser, ?string $date, ?string $time): bool|array
    {
        $checkIn = $shiftUser->checkIn;
        if (empty($checkIn)) {
            return [
                'error' => ErrorCode::UnprocessableEntity,
                'status' => 422,
                'data' => ['time' => ['User has not checked in yet.']],
            ];
        }

        if ($checkIn->pendingCheckOutEditRequests()->exists()) {
            return [
                'error' => ErrorCode::WorkHistoryEditRequestPendingCheckOutChange,
                'status' => 409,
            ];
        }

        $shift = $shiftUser->shift;
        if (!$date) {
            $date = $shift->date;
        }
        if (!$time) {
            $time = $shift->workplaceShift->end_time;
        }
        $checkOutTime = new Carbon($date . ' ' . $time);
        $checkInTime = Carbon::parse($checkIn->check_in_time);
        if ($checkOutTime->lt($checkInTime)) {
            return [
                'error' => ErrorCode::UnprocessableEntity,
                'status' => 422,
                'data' => ['time' => ['Check out time must be later than check in time.']],
            ];
        }

        $shift->loadMissing('workplace', 'workplaceShift');
        $minutes = WorkHelper::calculateWorkHour(
            $shift,
            Carbon::parse($checkIn->check_in_time),
            $checkOutTime,
            $shiftUser->is_overtime_allowed,
            $checkIn->break_minutes
        );

        $checkIn->update([
            'check_in_time_rounded' => $minutes['check_in_time'],
            'check_out_time' => $checkOutTime,
            'check_out_time_rounded' => $minutes['check_out_time'],
            'work_minutes' => $minutes['total'],
            'work_minutes_normal' => $minutes['normal'],
            'work_minutes_night' => $minutes['night'],
            'work_minutes_overtime' => $minutes['overtime'],
            'work_minutes_night_overtime' => $minutes['night_overtime'],
            'checked_out_by' => Auth::id(),
        ]);

        return [
            'error' => null,
        ];
    }

    /**
     * @param array<int, float> $violations
     * @return list<array{user_id: int, staff_no: ?string, name: string, over_hours: float}>
     */
    private function describeWeeklyHourViolations(array $violations): array
    {
        return User::query()
            ->whereIn('id', array_keys($violations))
            ->get()
            ->map(
                fn(User $user) => [
                    'user_id' => $user->id,
                    'staff_no' => $user->staff_no,
                    'name' => $user->short_name,
                    'over_hours' => round($violations[$user->id], 2),
                ]
            )
            ->values()
            ->all();
    }

    /**
     * The on-the-spot bonuses advertised by this shift's recruitment notifications.
     *
     * Returned as a list rather than a single value, and shown once for the shift rather
     * than against each employee, because the system cannot attribute a specific campaign
     * to a specific person: shift_users records only that the employee had read *a*
     * recruitment notification for this shift, not which one. Where a shift was advertised
     * twice at different amounts, showing one of them next to a name would be a guess.
     *
     * @return array<int, int>
     */
    private function hotBonusAmountsForShift(Shift $shift): array
    {
        return Notification::query()
            ->recruitmentForShift($shift->id)
            ->orderBy('id')
            ->get()
            ->map(
                fn(Notification $notification) => RecruitmentCriteria::fromNotification($notification)->hotBonusAmount
            )
            ->filter(fn(?int $amount) => $amount !== null && $amount > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Number of shifts (at a similar time range) each user actually worked at this workplace in
     * the last 90 days, keyed by user id. Returns an empty map when no reference shift is given
     * or it cannot be resolved, so the count is simply omitted from the response.
     *
     * @param  int[]  $userIds
     * @return array<int, int>
     */
    private function getSimilarShiftCounts(array $userIds, int $workplaceId, $workplaceShiftId): array
    {
        if (empty($userIds) || !$workplaceShiftId) {
            return [];
        }

        $workplaceShift = WorkplaceShift::query()
            ->where('id', $workplaceShiftId)
            ->where('workplace_id', $workplaceId)
            ->first();

        if (!$workplaceShift) {
            return [];
        }

        $similarWorkplaceShiftIds = $this->similarShiftService->similarWorkplaceShiftIds(
            [$workplaceId],
            $workplaceShift
        );

        return $this->similarShiftService->getSimilarShiftWorkedCount(
            $userIds,
            $workplaceId,
            $similarWorkplaceShiftIds
        );
    }
}
