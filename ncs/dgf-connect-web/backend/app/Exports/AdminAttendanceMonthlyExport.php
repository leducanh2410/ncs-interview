<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AdminAttendanceMonthlyExport implements WithMultipleSheets
{
    public function __construct(private readonly Collection $sessions, private readonly Carbon $month) {}

    public function sheets(): array
    {
        $sheets = [];
        $titleCounts = [];

        foreach ($this->sessions->groupBy('user_id') as $userSessions) {
            $name = $userSessions->first()->user?->short_name ?? '-';

            $title = AdminAttendanceMonthlySheet::sanitizeTitle($name);
            $titleCounts[$title] = ($titleCounts[$title] ?? 0) + 1;
            $uniqueTitle = $title;
            if ($titleCounts[$title] > 1) {
                $suffix = ' (' . $titleCounts[$title] . ')';
                $uniqueTitle = mb_substr($title, 0, AdminAttendanceMonthlySheet::MAX_TITLE_LENGTH - mb_strlen($suffix));
                $uniqueTitle .= $suffix;
            }

            $sheets[] = new AdminAttendanceMonthlySheet($name, $this->month, $userSessions, $uniqueTitle);
        }

        return $sheets;
    }
}
