<?php

namespace App\Actions\Manage\Shift;

use App\Enums\ShiftUserStatus;
use App\Models\Shift;

class FindLengthenedShiftViolationsAction
{
    public function __construct(private readonly FindWeeklyHourViolationsAction $findWeeklyHourViolations) {}

    /**
     * @param list<int>|null $onlyUserIds
     * @return array<int, float>
     */
    public function __invoke(Shift $shift, float $previousShiftHours, ?array $onlyUserIds = null): array
    {
        $shiftHours = $shift->getDuration();
        if ($shiftHours <= $previousShiftHours) {
            return [];
        }

        $userIds = $shift
            ->shiftUsers()
            ->when($onlyUserIds !== null, fn($query) => $query->whereIn('user_id', $onlyUserIds))
            ->where('status', ShiftUserStatus::Approved->value)
            ->where('is_absent', false)
            ->whereDoesntHave('checkIn', fn($query) => $query->whereNotNull('check_out_time'))
            ->pluck('user_id')
            ->all();

        return ($this->findWeeklyHourViolations)($shift, $userIds, $shiftHours);
    }
}
