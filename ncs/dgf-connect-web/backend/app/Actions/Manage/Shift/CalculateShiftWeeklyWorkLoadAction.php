<?php

namespace App\Actions\Manage\Shift;

use App\Enums\ShiftUserStatus;
use App\Enums\WorkLoadStatus;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Models\UserConfig;
use Illuminate\Support\Carbon;

class CalculateShiftWeeklyWorkLoadAction
{
    private const WINDOW_DAYS = 7;

    /**
     * @param list<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(Shift $shift, array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }

        $shiftDate = Carbon::parse($shift->date)->startOfDay();
        $rangeStart = $shiftDate->clone()->subDays(self::WINDOW_DAYS - 1);
        $rangeEnd = $shiftDate
            ->clone()
            ->addDays(self::WINDOW_DAYS - 1)
            ->endOfDay();

        $otherWorkHoursByUser = WorkHelper::getWorkHours($userIds, $rangeStart, $rangeEnd, $shift->id);
        $countedShiftUsers = $shift
            ->shiftUsers()
            ->with('checkIn')
            ->where('status', ShiftUserStatus::Approved->value)
            ->where('is_absent', false)
            ->get()
            ->keyBy('user_id');
        $shiftHours = $shift->getDuration();
        $systemConfig = SystemConfig::query()->first();
        $userConfigs = UserConfig::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');

        $workLoads = [];
        foreach ($userIds as $userId) {
            $countedShiftUser = $countedShiftUsers->get($userId);
            $isShiftCounted = $countedShiftUser !== null;
            $userConfig = $userConfigs->get($userId);
            $otherWorkHours = $otherWorkHoursByUser[$userId] ?? [];
            $thisShiftDay = $isShiftCounted
                ? $this->countedShiftDay($shift, $countedShiftUser)
                : ['hours' => $shiftHours, 'actual_hours' => 0.0, 'scheduled_hours' => $shiftHours];

            $window = WorkHelper::findTightestWeekWindow(
                $rangeStart,
                $otherWorkHours,
                $thisShiftDay['hours'],
                $systemConfig,
                $userConfig
            );
            $days = $this->withThisShift(
                WorkHelper::getDailyHours($otherWorkHours, $window['window_start'], self::WINDOW_DAYS),
                $shiftDate->toDateString(),
                $thisShiftDay
            );
            $projectedHours = $window['total'];
            $maxHourPerWeek = $window['max_hour_per_week'];
            $weeklyHours = $projectedHours - $thisShiftDay['hours'];
            $overHours = $projectedHours - $maxHourPerWeek;
            $isBlocked = !$isShiftCounted && $overHours > 0;
            $isCustomLimit = $this->isCustomLimitBinding($days, $maxHourPerWeek, $systemConfig, $userConfig);

            $workLoads[$userId] = [
                'user_id' => $userId,
                'weekly_hours' => $weeklyHours,
                'shift_hours' => $thisShiftDay['hours'],
                'is_shift_counted' => $isShiftCounted,
                'projected_hours' => $projectedHours,
                'max_hour_per_week' => $maxHourPerWeek,
                'remaining_hours' => $maxHourPerWeek - $projectedHours,
                'status' => $isBlocked
                    ? WorkLoadStatus::Over->value
                    : WorkLoadStatus::fromHours($projectedHours, $maxHourPerWeek)->value,
                'is_blocked' => $isBlocked,
                'blocked_over_hours' => $isBlocked ? $overHours : null,
                'is_custom_limit' => $isCustomLimit,
                'limit_start_date' => $isCustomLimit
                    ? $this->formatDate($userConfig->max_hour_per_week_start_date)
                    : null,
                'limit_expiry_date' => $isCustomLimit
                    ? $this->formatDate($userConfig->max_hour_per_week_expiry_date)
                    : null,
                'window_start' => $days[0]['date'],
                'window_end' => $days[self::WINDOW_DAYS - 1]['date'],
                'days' => $days,
            ];
        }

        return $workLoads;
    }

    /**
     * @param array<string, mixed> $workLoad
     * @return array<string, mixed>
     */
    public static function roundForResponse(array $workLoad): array
    {
        foreach (['weekly_hours', 'shift_hours', 'projected_hours', 'remaining_hours'] as $key) {
            $workLoad[$key] = round($workLoad[$key], 2);
        }
        if ($workLoad['blocked_over_hours'] !== null) {
            $workLoad['blocked_over_hours'] = round($workLoad['blocked_over_hours'], 2);
        }
        $workLoad['days'] = array_map(
            fn(array $day) => [
                'date' => $day['date'],
                'hours' => round($day['hours'], 2),
                'actual_hours' => round($day['actual_hours'], 2),
                'scheduled_hours' => round($day['scheduled_hours'], 2),
            ],
            $workLoad['days']
        );

        return $workLoad;
    }

    /**
     * @param list<array{date: string, hours: float, actual_hours: float, scheduled_hours: float}> $days
     */
    private function isCustomLimitBinding(
        array $days,
        int $maxHourPerWeek,
        ?SystemConfig $systemConfig,
        ?UserConfig $userConfig
    ): bool {
        foreach ($days as $day) {
            $date = Carbon::parse($day['date']);
            if (
                WorkHelper::isUserMaxHourPerWeekApplied($userConfig, $date) &&
                WorkHelper::getMaxHourPerWeekOnDate($systemConfig, $userConfig, $date) === $maxHourPerWeek
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{hours: float, actual_hours: float, scheduled_hours: float}
     */
    private function countedShiftDay(Shift $shift, ShiftUser $shiftUser): array
    {
        $checkIn = $shiftUser->checkIn;
        [$start, $end, $startBreak, $endBreak] = $shift->getTimeRange();
        $scheduledHours = WorkHelper::getShiftWorkDuration(
            $start,
            $end,
            $startBreak,
            $endBreak,
            $checkIn?->break_minutes ?? $shift->break_minutes
        );
        $actualHours = $checkIn?->check_in_time && $checkIn->check_out_time ? $checkIn->work_minutes / 60 : null;

        return [
            'hours' => $actualHours ?? $scheduledHours,
            'actual_hours' => $actualHours ?? 0.0,
            'scheduled_hours' => $scheduledHours,
        ];
    }

    /**
     * @param list<array{date: string, hours: float, actual_hours: float, scheduled_hours: float}> $days
     * @param array{hours: float, actual_hours: float, scheduled_hours: float} $thisShiftDay
     * @return list<array{date: string, hours: float, actual_hours: float, scheduled_hours: float}>
     */
    private function withThisShift(array $days, string $shiftDate, array $thisShiftDay): array
    {
        return array_map(function (array $day) use ($shiftDate, $thisShiftDay) {
            if ($day['date'] === $shiftDate) {
                $day['hours'] += $thisShiftDay['hours'];
                $day['actual_hours'] += $thisShiftDay['actual_hours'];
                $day['scheduled_hours'] += $thisShiftDay['scheduled_hours'];
            }

            return $day;
        }, $days);
    }

    private function formatDate(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }
}
