<?php

namespace App\Actions\User\Schedule;

use App\Actions\ActionResult;
use App\Actions\Manage\Shift\LockUsersForShiftWriteAction;
use App\Actions\Manage\Shift\ValidatesShiftUser;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Models\UserConfig;
use App\Services\Common\AbsenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegisterShiftAction
{
    use ValidatesShiftUser;

    public function __construct(
        private readonly AbsenceService $absenceService,
        private readonly LockUsersForShiftWriteAction $lockUsersForShiftWrite
    ) {}

    public function __invoke(Shift $shift): ActionResult
    {
        $user = Auth::user()->load('info');
        $now = now();
        $shiftDate = Carbon::parse($shift->date);

        if ($shift->isMaxRegisteredTempReached()) {
            return new ActionResult(error: ErrorCode::ScheduleShiftMaxRegisteredTemp, status: 400);
        }

        $validateUserError = $this->checkUserExpiration($user, $shiftDate);
        if ($validateUserError) {
            return new ActionResult(error: $validateUserError, status: 400);
        }

        $shift->load(['workplaceShift']);
        [$startTime, $endTime] = $shift->getTimeRange();

        if ($now->gte($endTime)) {
            return new ActionResult(error: ErrorCode::ScheduleShiftAlreadyEnded, status: 400);
        }

        $systemConfig = SystemConfig::query()->first();
        $userConfig = UserConfig::query()->where('user_id', $user->id)->first();

        $countSameDayShifts = ShiftUser::query()
            ->where('user_id', $user->id)
            ->where('status', ShiftUserStatus::Approved->value)
            ->whereHas('shift', function ($query) use ($shift) {
                return $query->where('date', $shift->date);
            })
            ->count();
        if ($countSameDayShifts >= WorkHelper::getMaxShiftPerDay($systemConfig, $userConfig, $shift)) {
            return new ActionResult(error: ErrorCode::ScheduleMaxShiftsExceeded, status: 400);
        }

        $rangeStart = $startTime->clone()->subDays(6)->startOfDay();
        $rangeEnd = $startTime->clone()->addDays(6)->endOfDay();
        $workHoursByDate = WorkHelper::getWorkHours([$user->id], $rangeStart, $rangeEnd)[$user->id] ?? [];

        $error = WorkHelper::validateShiftRegister($shift, $rangeStart, $workHoursByDate, $systemConfig, $userConfig);
        if ($error) {
            return new ActionResult(error: $error['error'], status: 400);
        }

        $shiftUser = DB::transaction(function () use ($shift, $user) {
            $lockedUser = ($this->lockUsersForShiftWrite)([$user->id])->get($user->id);
            if (!$lockedUser || $lockedUser->is_disabled) {
                return null;
            }

            return $shift->shiftUsers()->create([
                'user_id' => $user->id,
                'workplace_position_id' => null,
                'status' => ShiftUserStatus::Pending->value,
                'is_fixed' => false,
            ]);
        });

        if (!$shiftUser) {
            return new ActionResult(error: ErrorCode::ScheduleUserDisabled, status: 400);
        }

        $absenceCount = $this->absenceService->getUserAbsenceCount($user->id);

        return new ActionResult(
            error: null,
            data: array_merge($shiftUser->toArray(), [
                'absence_count' => $absenceCount,
            ])
        );
    }
}
