<?php

namespace App\Helpers;

use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Models\UserConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class WorkHelper
{
    /**
     * @return array{check_in_time: Carbon, check_out_time: Carbon, within_break: bool}
     */
    public static function roundCheckTimes(
        Shift $shift,
        Carbon $checkInTime,
        Carbon $checkOutTime,
        bool $allowOvertime = false
    ): array {
        // Keeping check-in/check-out time outside the shift range stays gated on the
        // per-employee shift_users.is_overtime_allowed flag alone. is_overtime_by_duration only
        // lowers the threshold (see getOvertimeAfterMinutes); letting it also unclip the range
        // would turn an unapproved early check-in into paid overtime minutes.
        [$startTime, $endTime, $breakTimeStart, $breakTimeEnd] = $shift->getTimeRange();
        if (!$allowOvertime) {
            if ($checkInTime->lt($startTime)) {
                $checkInTime = $startTime;
            }
            if ($checkOutTime->gt($endTime)) {
                $checkOutTime = $endTime;
            }
        }

        if (
            $breakTimeStart &&
            $breakTimeEnd &&
            $checkInTime->between($breakTimeStart, $breakTimeEnd) &&
            $checkOutTime->between($breakTimeStart, $breakTimeEnd)
        ) {
            return [
                'check_in_time' => $checkInTime,
                'check_out_time' => $checkOutTime,
                'within_break' => true,
            ];
        }

        $workplaceShift = $shift->workplaceShift;
        if ($workplaceShift->check_in_round_minutes) {
            $checkInRoundMinutes = $workplaceShift->check_in_round_minutes % 60;
            $checkInMinute = $checkInTime->minute + $checkInTime->second / 60;
            if ($workplaceShift->check_in_round_type === 1) {
                // Round up
                $checkInMinute = ceil($checkInMinute / $checkInRoundMinutes) * $checkInRoundMinutes;
            } else {
                // Round down
                $checkInMinute = floor($checkInMinute / $checkInRoundMinutes) * $checkInRoundMinutes;
            }

            $checkInTime = $checkInTime->clone()->minute($checkInMinute)->startOfMinute();
        }

        if ($workplaceShift->check_out_round_minutes) {
            $checkOutRoundMinutes = $workplaceShift->check_out_round_minutes % 60;
            $checkOutMinute = $checkOutTime->minute + $checkOutTime->second / 60;
            if ($workplaceShift->check_out_round_type === 1) {
                // Round up
                $checkOutMinute = ceil($checkOutMinute / $checkOutRoundMinutes) * $checkOutRoundMinutes;
            } else {
                // Round down
                $checkOutMinute = floor($checkOutMinute / $checkOutRoundMinutes) * $checkOutRoundMinutes;
            }

            $checkOutTime = $checkOutTime->clone()->minute($checkOutMinute)->startOfMinute();
        }

        return [
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
            'within_break' => false,
        ];
    }

    public static function calculateWorkHour(
        Shift $shift,
        Carbon $checkInTime,
        Carbon $checkOutTime,
        bool $allowOvertime = false,
        ?int $customBreakDuration = null
    ): array {
        $rounded = self::roundCheckTimes($shift, $checkInTime, $checkOutTime, $allowOvertime);
        $checkInTime = $rounded['check_in_time'];
        $checkOutTime = $rounded['check_out_time'];
        if ($rounded['within_break']) {
            return [
                'total' => 0,
                'normal' => 0,
                'night' => 0,
                'overtime' => 0,
                'night_overtime' => 0,
                'check_in_time' => $checkInTime,
                'check_out_time' => $checkOutTime,
            ];
        }
        [, , $breakTimeStart, $breakTimeEnd] = $shift->getTimeRange();

        $nightShiftStart = Carbon::parse($shift->date . ' ' . config('work.time.night_shift.start_time'));
        $nightShiftEnd = Carbon::parse($shift->date . ' ' . config('work.time.night_shift.end_time'));
        if ($nightShiftEnd->lt($nightShiftStart)) {
            $nightShiftEnd->addDay();
        }
        $nightShiftRanges = [
            [$nightShiftStart->clone()->subDay(), $nightShiftEnd->clone()->subDay()],
            [$nightShiftStart, $nightShiftEnd],
            [$nightShiftStart->clone()->addDay(), $nightShiftEnd->clone()->addDay()],
        ];
        $totalMinutes = 0;
        $nightMinutes = 0;
        $overTimeMinutes = 0;
        $nightOverTimeMinutes = 0;

        $currentTime = $checkInTime->clone();
        if ($customBreakDuration === null) {
            $customBreakDuration = $shift->break_minutes;
        }

        // $totalMinutes below counts every elapsed minute, break included, whenever the break
        // is declared as a duration rather than a window. Pushing the threshold back by that
        // duration is what makes it compare against the minutes actually worked.
        $overTimeAfter = self::getOvertimeAfterMinutes($shift) + (int) $customBreakDuration;

        while ($currentTime->lt($checkOutTime)) {
            if ($breakTimeStart && $breakTimeEnd && $customBreakDuration === null) {
                if (
                    $currentTime->isBetween($breakTimeStart, $breakTimeEnd, false) ||
                    $currentTime->eq($breakTimeStart)
                ) {
                    $currentTime = $breakTimeEnd->clone();

                    continue;
                }
            }

            $totalMinutes++;
            $isNightShift = false;
            foreach ($nightShiftRanges as $nightShiftRange) {
                if (
                    $currentTime->between($nightShiftRange[0], $nightShiftRange[1], false) ||
                    $currentTime->eq($nightShiftRange[0])
                ) {
                    $isNightShift = true;
                    break;
                }
            }
            if ($isNightShift) {
                if ($totalMinutes > $overTimeAfter) {
                    $nightOverTimeMinutes++;
                } else {
                    $nightMinutes++;
                }
            } elseif ($totalMinutes > $overTimeAfter) {
                $overTimeMinutes++;
            }
            $currentTime->addMinute();
        }

        if ($customBreakDuration) {
            // Leaving before the declared break is over must not produce negative minutes:
            // they would flow into check_ins and be summed into payroll as they are.
            $totalMinutes = max(0, $totalMinutes - $customBreakDuration);
        }

        $normalMinutes = $totalMinutes - $nightMinutes - $overTimeMinutes - $nightOverTimeMinutes;
        if ($normalMinutes < 0) {
            $normalMinutes = 0;
            $nightMinutes = $totalMinutes - $overTimeMinutes - $nightOverTimeMinutes;
            if ($nightMinutes < 0) {
                $nightMinutes = 0;
                $overTimeMinutes = $totalMinutes - $nightOverTimeMinutes;
                if ($overTimeMinutes < 0) {
                    $overTimeMinutes = 0;
                    $nightOverTimeMinutes = $totalMinutes;
                }
            }
        }

        return [
            'total' => $totalMinutes,
            'normal' => $normalMinutes,
            'night' => $nightMinutes,
            'overtime' => $overTimeMinutes,
            'night_overtime' => $nightOverTimeMinutes,
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
        ];
    }

    /**
     * Minutes of actual work after which every further minute is overtime, for one shift.
     *
     * The workplace setting is the ceiling; a shift flagged with is_overtime_by_duration
     * narrows it down to that shift's own standard duration, so an unusually long day on a
     * short shift is paid as overtime even though the workplace threshold was never reached.
     * Reads only already-loaded relations so the per-minute loop stays query free.
     */
    public static function getOvertimeAfterMinutes(Shift $shift): int
    {
        $hours = $shift->workplace?->overtime_after_hours ?? config('work.time.over_time_after');
        $thresholdMinutes = (int) round($hours * 60);

        if ($shift->is_overtime_by_duration) {
            $shiftMinutes = (int) round($shift->getDuration() * 60);
            // A non-positive duration means the break covers the whole shift; falling back to
            // the workplace threshold beats turning every worked minute into overtime.
            if ($shiftMinutes > 0) {
                $thresholdMinutes = min($thresholdMinutes, $shiftMinutes);
            }
        }

        return $thresholdMinutes;
    }

    public static function calculateDistance(
        float $latitudeFrom,
        float $longitudeFrom,
        float $latitudeTo,
        float $longitudeTo
    ): float {
        $latFromRad = deg2rad($latitudeFrom);
        $lonFromRad = deg2rad($longitudeFrom);
        $latToRad = deg2rad($latitudeTo);
        $lonToRad = deg2rad($longitudeTo);

        $latDelta = $latToRad - $latFromRad;
        $lonDelta = $lonToRad - $lonFromRad;

        $a = sin($latDelta / 2) ** 2 + cos($latFromRad) * cos($latToRad) * sin($lonDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return 6371000 * $c;
    }

    /**
     * @param string $date
     * @param string $startTime
     * @param string $endTime
     * @param string|null $startBreakTime
     * @param string|null $endBreakTime
     * @return Carbon[]
     */
    public static function getShiftTimes(
        string $date,
        string $startTime,
        string $endTime,
        ?string $startBreakTime,
        ?string $endBreakTime
    ): array {
        $start = Carbon::parse($date . ' ' . $startTime);
        $end = Carbon::parse($date . ' ' . $endTime);
        if ($end->lte($start)) {
            $end->addDay();
        }

        if (empty($startBreakTime) || empty($endBreakTime)) {
            return [$start, $end, null, null];
        }
        $startBreak = Carbon::parse($date . ' ' . $startBreakTime);
        $endBreak = Carbon::parse($date . ' ' . $endBreakTime);
        if ($startBreak->lt($start)) {
            $startBreak->addDay();
        }
        if ($endBreak->lte($startBreak)) {
            $endBreak->addDay();
        }

        return [$start, $end, $startBreak, $endBreak];
    }

    /**
     * Per-shift work-data math for one (planned) shift: its time range and worked
     * duration in hours. Extracted from getWorkHours so the Shift Planner can layer
     * not-yet-live draft additions into a validation map using the exact same math as
     * the live path (see App\Actions\Manage\ShiftPlanning\BuildShiftPlanWorkDataAction).
     *
     * @return array{hour: float, range: array{start: Carbon, end: Carbon}}
     */
    public static function computeShiftWorkData(
        string $date,
        string $startTime,
        string $endTime,
        ?string $startBreakTime,
        ?string $endBreakTime,
        ?int $breakMinutes
    ): array {
        [$start, $end, $startBreak, $endBreak] = self::getShiftTimes(
            $date,
            $startTime,
            $endTime,
            $startBreakTime,
            $endBreakTime
        );

        return [
            'hour' => self::getShiftWorkDuration($start, $end, $startBreak, $endBreak, $breakMinutes),
            'range' => ['start' => $start, 'end' => $end],
        ];
    }

    public static function getShiftWorkDuration(
        Carbon $start,
        Carbon $end,
        ?Carbon $startBreak,
        ?Carbon $endBreak,
        ?int $customBreakDuration,
        bool $breakTimeExcluded = true
    ): float {
        $totalDuration = $start->diffInHours($end);

        if ($breakTimeExcluded && $customBreakDuration) {
            return $totalDuration - $customBreakDuration / 60;
        }

        if (!$breakTimeExcluded || empty($startBreak) || empty($endBreak)) {
            return $totalDuration;
        }
        $breakDuration = $startBreak->diffInHours($endBreak);

        return $totalDuration - $breakDuration;
    }

    public static function getWorkHours(array $userIds, Carbon $from, Carbon $to, ?int $ignoreShiftId = null): array
    {
        $records = ShiftUser::query()
            ->select([
                'shift_users.user_id',
                'shifts.date',
                'shifts.break_minutes',
                'workplace_shifts.*',
                'check_ins.check_in_time',
                'check_ins.check_out_time',
                'check_ins.work_minutes',
                'check_ins.break_minutes as custom_break_minutes',
            ])
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->join('workplace_shifts', 'shifts.workplace_shift_id', '=', 'workplace_shifts.id')
            ->leftJoin('check_ins', 'shift_users.id', '=', 'check_ins.shift_user_id')
            ->whereIn('shift_users.user_id', $userIds)
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->when($ignoreShiftId, function (Builder $builder, $ignoreShiftId) {
                $builder->where('shifts.id', '!=', $ignoreShiftId);
            })
            ->whereBetween('shifts.date', [$from, $to])
            ->where('shift_users.is_absent', false)
            ->get()
            ->groupBy('user_id');

        $result = [];
        foreach ($records as $userId => $userRecords) {
            $groupByDate = $userRecords->groupBy('date');
            $userWorkHours = [];
            foreach ($groupByDate as $date => $items) {
                $totalHour = 0.0;
                $actualHour = 0.0;
                $scheduledHour = 0.0;
                $hours = [];
                $ranges = [];
                foreach ($items as $item) {
                    $customBreakMinutes = $item->custom_break_minutes ?? $item->break_minutes;
                    $workData = self::computeShiftWorkData(
                        $date,
                        $item->start_time,
                        $item->end_time,
                        $item->start_break_time,
                        $item->end_break_time,
                        $customBreakMinutes
                    );

                    if ($item->check_in_time && $item->check_out_time) {
                        $hour = $item->work_minutes / 60;
                        $actualHour += $hour;
                    } else {
                        // ZA 1201: Customer requested to still count absent shift
                        // if ($end->lt(now()) && !$item->check_in_time) {
                        // // Shift has ended and no check-in -> absent
                        // continue;
                        // }
                        $hour = $workData['hour'];
                    }
                    $scheduledHour += $workData['hour'];
                    $totalHour += $hour;
                    $hours[] = $hour;
                    $ranges[] = $workData['range'];
                }
                $userWorkHours[$date] = [
                    'total' => $totalHour,
                    'actual' => $actualHour,
                    'scheduled' => $scheduledHour,
                    'hours' => $hours,
                    'ranges' => $ranges,
                    'shift_count' => count($hours),
                ];
            }
            $result[$userId] = $userWorkHours;
        }

        return $result;
    }

    /**
     * @return list<array{date: string, hours: float, actual_hours: float, scheduled_hours: float}>
     */
    public static function getDailyHours(array $workHoursByDate, Carbon $from, int $days): array
    {
        $dailyHours = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->clone()->addDays($i)->format('Y-m-d');
            $dailyHours[] = [
                'date' => $date,
                'hours' => (float) Arr::get($workHoursByDate, "{$date}.total", 0.0),
                'actual_hours' => (float) Arr::get($workHoursByDate, "{$date}.actual", 0.0),
                'scheduled_hours' => (float) Arr::get($workHoursByDate, "{$date}.scheduled", 0.0),
            ];
        }

        return $dailyHours;
    }

    public static function isOverlap(Shift $shift, array $timeRanges): bool
    {
        [$start, $end] = $shift->getTimeRange();
        foreach ($timeRanges as $timeRange) {
            $startItem = $timeRange['start'];
            $endItem = $timeRange['end'];
            if ($startItem->isBetween($start, $end, false) || $endItem->isBetween($start, $end, false)) {
                return true;
            }
        }

        return false;
    }

    public static function validateShiftRegister(
        Shift $shift,
        Carbon $rangeStart,
        array $userWorkDataGroupByDate,
        ?SystemConfig $systemConfig,
        ?UserConfig $userConfig
    ): ?array {
        $shiftDate = Carbon::parse($shift->date);
        // Check overlapping shifts
        $dateBefore = $shiftDate->clone()->subDay();
        $dateAfter = $shiftDate->clone()->addDay();
        $timeRangeBefore = Arr::get($userWorkDataGroupByDate, "{$dateBefore->format('Y-m-d')}.ranges", []);
        $timeRangeSameDay = Arr::get($userWorkDataGroupByDate, "{$shift->date}.ranges", []);
        $timeRangeAfter = Arr::get($userWorkDataGroupByDate, "{$dateAfter->format('Y-m-d')}.ranges", []);
        $isOverlap = WorkHelper::isOverlap($shift, array_merge($timeRangeBefore, $timeRangeSameDay, $timeRangeAfter));
        if ($isOverlap) {
            return [
                'error' => ErrorCode::ScheduleShiftOverlap,
            ];
        }

        // Check minimum rest hours between shifts on adjacent dates
        $minRestHours = $shift->workplace?->min_rest_hours;
        if ($minRestHours !== null) {
            [$newStart, $newEnd] = $shift->getTimeRange();
            $adjacentRanges = array_merge($timeRangeBefore, $timeRangeAfter);
            foreach ($adjacentRanges as $range) {
                if ($range['end']->lte($newStart)) {
                    $gapHours = $range['end']->diffInMinutes($newStart) / 60;
                } elseif ($range['start']->gte($newEnd)) {
                    $gapHours = $newEnd->diffInMinutes($range['start']) / 60;
                } else {
                    continue;
                }
                if ($gapHours < $minRestHours) {
                    return [
                        'error' => ErrorCode::ScheduleMinRestHoursViolated,
                    ];
                }
            }
        }

        // Check max shift per day
        $shiftCount = Arr::get($userWorkDataGroupByDate, "{$shift->date}.shift_count", 0);
        if ($shiftCount >= self::getMaxShiftPerDay($systemConfig, $userConfig, $shift)) {
            return [
                'error' => ErrorCode::ScheduleMaxShiftsExceeded,
            ];
        }

        // Check max hour per week
        $violation = self::findMaxHourPerWeekViolation(
            $rangeStart,
            $userWorkDataGroupByDate,
            $shift->getDuration(),
            $systemConfig,
            $userConfig
        );
        if ($violation !== null) {
            return [
                'error' => ErrorCode::ScheduleMaxWorkHoursExceeded,
                'extraData' => $violation['over_hours'],
            ];
        }

        return null;
    }

    /**
     * @return array{window_start: Carbon, over_hours: float, max_hour_per_week: int}|null
     */
    public static function findMaxHourPerWeekViolation(
        Carbon $rangeStart,
        array $userWorkDataGroupByDate,
        float $workingHours,
        ?SystemConfig $systemConfig,
        ?UserConfig $userConfig
    ): ?array {
        $window = self::findTightestWeekWindow(
            $rangeStart,
            $userWorkDataGroupByDate,
            $workingHours,
            $systemConfig,
            $userConfig
        );
        $overHours = $window['total'] - $window['max_hour_per_week'];
        if ($overHours <= 0) {
            return null;
        }

        return [
            'window_start' => $window['window_start'],
            'over_hours' => $overHours,
            'max_hour_per_week' => $window['max_hour_per_week'],
        ];
    }

    /**
     * @return array{window_start: Carbon, total: float, max_hour_per_week: int}
     */
    public static function findTightestWeekWindow(
        Carbon $rangeStart,
        array $userWorkDataGroupByDate,
        float $workingHours,
        ?SystemConfig $systemConfig,
        ?UserConfig $userConfig
    ): array {
        $dailyLimits = [];
        $dailyTotals = [];
        for ($i = 0; $i < 13; $i++) {
            $date = $rangeStart->clone()->addDays($i);
            $dailyLimits[] = self::getMaxHourPerWeekOnDate($systemConfig, $userConfig, $date);
            $dailyTotals[] = (float) Arr::get($userWorkDataGroupByDate, "{$date->format('Y-m-d')}.total", 0.0);
        }

        $tightest = null;
        for ($i = 0; $i < 7; $i++) {
            $total = array_sum(array_slice($dailyTotals, $i, 7)) + $workingHours;
            $limit = min(array_slice($dailyLimits, $i, 7));
            if ($tightest === null || $total - $limit > $tightest['total'] - $tightest['max_hour_per_week']) {
                $tightest = [
                    'window_start' => $rangeStart->clone()->addDays($i),
                    'total' => $total,
                    'max_hour_per_week' => $limit,
                ];
            }
        }

        return $tightest;
    }

    public static function getMaxShiftPerDay(?SystemConfig $systemConfig, ?UserConfig $userConfig, Shift $shift): int
    {
        return self::getConfigValue(
            $userConfig,
            'max_shift_per_day',
            $systemConfig?->max_shift_per_day,
            config('work.max_shift_per_day'),
            Carbon::parse($shift->date)
        );
    }

    public static function getMaxHourPerWeekOnDate(
        ?SystemConfig $systemConfig,
        ?UserConfig $userConfig,
        Carbon $date
    ): int {
        return self::getConfigValue(
            $userConfig,
            'max_hour_per_week',
            $systemConfig?->max_hour_per_week,
            config('work.max_hour_per_week'),
            $date
        );
    }

    public static function isUserMaxHourPerWeekApplied(?UserConfig $userConfig, Carbon $date): bool
    {
        return self::isUserConfigApplied($userConfig, 'max_hour_per_week', $date);
    }

    private static function getConfigValue(
        ?UserConfig $userConfig,
        string $configKey,
        ?int $systemValue,
        int $defaultValue,
        Carbon $date
    ): int {
        if (self::isUserConfigApplied($userConfig, $configKey, $date)) {
            return $userConfig->{$configKey};
        }

        return $systemValue ?? $defaultValue;
    }

    private static function isUserConfigApplied(?UserConfig $userConfig, string $configKey, Carbon $date): bool
    {
        if (!$userConfig || $userConfig->{$configKey} === null) {
            return false;
        }

        $startDate = $userConfig->{$configKey . '_start_date'};
        $expiryDate = $userConfig->{$configKey . '_expiry_date'};

        return match (true) {
            $startDate === null && $expiryDate === null => true,
            $startDate === null => Carbon::parse($expiryDate)->gte($date),
            $expiryDate === null => Carbon::parse($startDate)->lte($date),
            default => Carbon::parse($startDate)->lte($date) && Carbon::parse($expiryDate)->gte($date),
        };
    }
}
