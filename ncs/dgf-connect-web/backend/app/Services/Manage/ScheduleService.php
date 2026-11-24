<?php

namespace App\Services\Manage;

use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ScheduleService
{
    public function getUserSchedule(int $userId, Carbon $startDate, Carbon $endDate): array
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return [
                'error' => ErrorCode::NotFound,
                'status' => 404,
            ];
        }

        return [
            'error' => null,
            'status' => 200,
            'data' => [
                'user' => $user,
                'shifts' => ShiftUser::query()
                    ->with(['shift.workplace', 'shift.workplaceShift', 'workplacePosition', 'checkIn'])
                    ->where('user_id', $userId)
                    ->whereHas('shift', function (Builder $builder) use ($startDate, $endDate) {
                        $builder->whereBetween('date', [$startDate, $endDate]);
                    })
                    ->where('status', ShiftUserStatus::Approved)
                    ->get(),
            ],
        ];
    }
}
