<?php

namespace App\Actions\Manage\WorkHistory;

use App\Models\CheckIn;

class BuildAttendanceExportColumnsAction
{
    /**
     * @return array{attendance_status: string, attendance_reason: string}
     */
    public function __invoke(CheckIn $checkIn, bool $isAbsent): array
    {
        $statuses = [];
        $reasons = [];

        if ($checkIn->late_minutes > 0) {
            $statuses[] = "Đi muộn ({$checkIn->late_minutes} phút)";
        }
        if ($checkIn->early_minutes > 0) {
            $statuses[] = "Về sớm ({$checkIn->early_minutes} phút)";
        }
        if ($isAbsent) {
            $statuses[] = 'Bỏ ca';
        }

        if ($checkIn->late_minutes > 0 && $checkIn->late_reason !== null) {
            $reasons[] = "Đi muộn: {$checkIn->late_reason}";
        }
        if ($checkIn->early_minutes > 0 && $checkIn->early_reason !== null) {
            $reasons[] = "Về sớm: {$checkIn->early_reason}";
        }

        return [
            'attendance_status' => implode(', ', $statuses),
            'attendance_reason' => implode('; ', $reasons),
        ];
    }
}
