<?php

namespace App\ModelFilters;

use App\Enums\AttendanceStatus;

class CheckInFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [
        'shift' => ['workplace_id', 'dates'],
    ];

    public function user($userId): CheckInFilter
    {
        return $this->where('user_id', $userId);
    }

    public function attendanceStatus($status): CheckInFilter
    {
        return match (AttendanceStatus::tryFrom($status)) {
            AttendanceStatus::Late => $this->where('late_minutes', '>', 0),
            AttendanceStatus::EarlyLeave => $this->where('early_minutes', '>', 0),
            AttendanceStatus::Absent => $this->whereHas('shiftUser', fn($query) => $query->where('is_absent', true)),
            default => $this,
        };
    }
}
