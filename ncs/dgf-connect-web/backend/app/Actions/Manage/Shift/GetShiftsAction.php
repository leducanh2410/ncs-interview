<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\User;

class GetShiftsAction
{
    public function __invoke(array $conditions, bool $withDetailInfo, User $user): ActionResult
    {
        if (!isset($conditions['date'])) {
            $conditions['date'] = now()->format('Y-m-d');
        }

        if (!$withDetailInfo) {
            $shifts = Shift::getFilteredQuery($conditions, ['workplace', 'workplaceShift'])
                ->applyWorkplaceFilter($user, PermissionName::ShiftList->value)
                ->get();

            // Sort by workplace_id -> workplace_shift.start_time -> workplace_shift.end_time
            $shifts = $shifts
                ->sortBy(function ($shift) {
                    return [$shift->workplace_id, $shift->workplaceShift->start_time, $shift->workplaceShift->end_time];
                })
                ->values();

            return new ActionResult(data: $shifts);
        }

        $shifts = Shift::getFilteredQuery($conditions, ['workplace', 'workplaceShift', 'shiftUsers'])
            ->applyWorkplaceFilter($user, PermissionName::ShiftList->value)
            ->withCount([
                'fixedUsers as fixed_check_in' => function ($query) {
                    $query->whereHas('checkIn');
                },
                'tempUsers as temp_check_in' => function ($query) {
                    $query->whereHas('checkIn');
                },
                'fixedUsers as fixed_confirmed' => function ($query) {
                    $query->whereNotNull('confirmed_at');
                },
                'tempUsers as temp_confirmed' => function ($query) {
                    $query->whereNotNull('confirmed_at');
                },
            ])
            ->get();
        $shifts = $shifts
            ->sortBy(function ($shift) {
                return [$shift->workplace_id, $shift->workplaceShift->start_time, $shift->workplaceShift->end_time];
            })
            ->values();
        foreach ($shifts as $shift) {
            $tempCount = 0;
            $fixedCount = 0;
            $tempPending = 0;
            $fixedPending = 0;
            foreach ($shift->shiftUsers as $shiftUser) {
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
            unset($shift->shiftUsers);
            $shift->temp_count = $tempCount;
            $shift->fixed_count = $fixedCount;
            $shift->temp_pending_count = $tempPending;
            $shift->fixed_pending_count = $fixedPending;
        }

        return new ActionResult(data: $shifts);
    }
}
