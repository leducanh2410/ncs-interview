<?php

namespace App\Services\User;

use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\WorkplaceUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class ScheduleService
{
    public function getListSchedules(array $conditions)
    {
        return ShiftUser::getFilteredQuery($conditions, [
            'shift:id,workplace_id,workplace_shift_id,date',
            'shift.workplace:id,name,address',
            'shift.workplace.images',
            'shift.workplaceShift:id,start_time,end_time',
            'workplacePosition:id,name',
            'absentNotice',
        ])
            ->where('user_id', Auth::id())
            ->get()
            ->sortBy(function ($shiftUser) {
                return $shiftUser->shift->date;
            })
            ->values();
    }

    public function confirmSchedule(ShiftUser $shiftUser): array
    {
        if ($shiftUser->status !== ShiftUserStatus::Approved->value) {
            return [
                'error' => ErrorCode::ShiftNotYetApproved,
                'status' => 400,
            ];
        }

        if ($shiftUser->user_id !== Auth::id()) {
            return [
                'error' => ErrorCode::Forbidden,
                'status' => 403,
            ];
        }

        $shiftUser->update(['confirmed_at' => now()]);

        return [
            'error' => null,
            'data' => $shiftUser,
        ];
    }

    public function searchShifts(array $conditions): Collection
    {
        $shifts = Shift::getFilteredQuery($conditions, ['workplace.images', 'workplaceShift'])
            ->whereHas('workplace.users', function ($query) {
                return $query->where('user_id', Auth::id());
            })
            ->whereDoesntHave('shiftUsers', function ($query) {
                return $query->where('user_id', Auth::id());
            })
            ->get();
        $shifts->loadCount('registeredTempUsers');

        // A shift is only listed while an employee could still register for it: it has not ended,
        // it actually needs staff, and a temp slot is left. "Registered" counts pending as well as
        // approved rows (Shift::registeredTempUsers) — the same definition RegisterShiftAction
        // enforces — so a shift never shows up here only to be refused as full on the register tap.
        return $shifts
            ->filter(function (Shift $shift) {
                [, $endTime] = $shift->getTimeRange();
                if ($endTime->isPast()) {
                    return false;
                }
                if ($shift->fixed_total + $shift->temp_total === 0) {
                    return false;
                }
                if ($shift->hasNoTempSlotLeft((int) $shift->registered_temp_users_count)) {
                    return false;
                }
                unset($shift->registered_temp_users_count);

                return true;
            })
            ->values();
    }

    public function getShiftDetail(Shift $shift): array
    {
        $userInWorkplace = WorkplaceUser::query()
            ->where('user_id', Auth::id())
            ->where('workplace_id', $shift->workplace_id)
            ->exists();

        // Recruitment notifications can target nearby-workplace or frequent-urgent-group
        // employees who are not members of the shift's own workplace. They still need to be
        // able to open the shift they were pushed a notification about, so membership is not
        // the only door in — being a resolved recipient for this exact shift is another.
        if (!$userInWorkplace && !Notification::hasRecruitmentRecipientForShift($shift->id, (int) Auth::id())) {
            return [
                'error' => ErrorCode::Forbidden,
                'status' => 403,
            ];
        }

        $shift->load(['workplace:id,name,address', 'workplaceShift:id,start_time,end_time']);

        return [
            'error' => null,
            'data' => $shift,
        ];
    }

    public function getShiftUserDetail(ShiftUser $shiftUser): ShiftUser
    {
        $shiftUser->load([
            'shift:id,workplace_id,workplace_shift_id,date',
            'shift.workplace:id,name,address',
            'shift.workplaceShift:id,start_time,end_time',
            'workplacePosition:id,name',
            'absentNotice',
        ]);

        return $shiftUser;
    }
}
