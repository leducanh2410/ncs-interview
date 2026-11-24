<?php

namespace App\Actions\Common\CheckIn;

use App\Models\CheckIn;
use Illuminate\Support\Carbon;

class CalculateAttendanceDeviationAction
{
    /**
     * @return array{late_minutes: int, early_minutes: int}
     */
    public function __invoke(CheckIn $checkIn): array
    {
        [$start, $end] = $checkIn->shift->getTimeRange();
        $threshold = (int) config('work.check_in.late_early_threshold_minute');

        $late = (int) floor($start->diffInMinutes(Carbon::parse($checkIn->check_in_time)));

        $early = 0;
        if ($checkIn->check_out_time) {
            $early = (int) floor(Carbon::parse($checkIn->check_out_time)->diffInMinutes($end));
        }

        return [
            'late_minutes' => $late > $threshold ? $late : 0,
            'early_minutes' => $early > $threshold ? $early : 0,
        ];
    }
}
