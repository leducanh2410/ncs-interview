<?php

namespace App\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PeakLevelCalculator
{
    /**
     * Peak level constants
     */
    private const PEAK_LEVEL_IDLE = 0;

    private const PEAK_LEVEL_LOW = 1;

    private const PEAK_LEVEL_MEDIUM = 2;

    private const PEAK_LEVEL_HIGH = 3;

    /**
     * Time windows for check-in peaks (minutes before/after shift start)
     */
    private const CHECK_IN_WINDOWS = [
        ['from' => -45, 'to' => -30, 'level' => 1], // 45→30 min before
        ['from' => -30, 'to' => -15, 'level' => 2], // 30→15 min before
        ['from' => -15, 'to' => 5, 'level' => 3], // 15 min before → 5 min after
        ['from' => 10, 'to' => 30, 'level' => 1], // 10→30 min after
    ];

    /**
     * Time windows for check-out peaks (minutes before/after shift end)
     */
    private const CHECK_OUT_WINDOWS = [
        ['from' => -30, 'to' => -10, 'level' => 2], // 30→10 min before
        ['from' => -10, 'to' => 10, 'level' => 3], // 10 min before → 10 min after
        ['from' => 10, 'to' => 30, 'level' => 1], // 10→30 min after
    ];

    /**
     * Calculate peak level based on shifts and current time
     *
     * @param Collection<int, array{starts_at: string, ends_at: string, approved_users_count: int}> $shifts
     * @param Carbon $currentTime Current time for calculation
     * @return array{peakLevel: int, activeUserCount: int} Peak level (0-3) and count of users in active shifts
     */
    public static function calculatePeakLevel(Collection $shifts, Carbon $currentTime): array
    {
        if ($shifts->isEmpty()) {
            return [
                'peakLevel' => self::PEAK_LEVEL_IDLE,
                'activeUserCount' => 0,
            ];
        }

        $maxPeakLevel = self::PEAK_LEVEL_IDLE;
        $activeUserCount = 0;

        foreach ($shifts as $shift) {
            $shiftPeak = self::calculateShiftPeakLevel($shift, $currentTime);
            $maxPeakLevel = max($maxPeakLevel, $shiftPeak);

            // Count users from shifts with peak level > 0
            if ($shiftPeak > 0) {
                $activeUserCount += $shift['approved_users_count'];
            }
        }

        return [
            'peakLevel' => $maxPeakLevel,
            'activeUserCount' => $activeUserCount,
        ];
    }

    /**
     * Calculate peak level for a single shift
     *
     * @param array{starts_at: string, ends_at: string, approved_users_count: int} $shift
     * @param Carbon $currentTime Current time for calculation
     * @return int Peak level (0-3)
     */
    public static function calculateShiftPeakLevel(array $shift, Carbon $currentTime): int
    {
        $startTime = Carbon::parse($shift['starts_at']);
        $endTime = Carbon::parse($shift['ends_at']);

        // Calculate check-in peak
        $checkInPeak = self::calculateWindowPeak($currentTime, $startTime, self::CHECK_IN_WINDOWS);

        // Calculate check-out peak
        $checkOutPeak = self::calculateWindowPeak($currentTime, $endTime, self::CHECK_OUT_WINDOWS);

        // Take the highest peak from this shift
        return max($checkInPeak, $checkOutPeak);
    }

    /**
     * Get ping interval in minutes for a given peak level
     *
     * @param int $level Peak level (0-3)
     * @return int Interval in minutes
     */
    public static function getPingInterval(int $level): int
    {
        return match ($level) {
            self::PEAK_LEVEL_HIGH => 0, // Level 3: handled by setActiveWorkers
            self::PEAK_LEVEL_MEDIUM => 1, // Level 2: every 1 minute
            self::PEAK_LEVEL_LOW => 3, // Level 1: every 3 minutes
            self::PEAK_LEVEL_IDLE => 5, // Level 0: every 5 minutes
            default => 5,
        };
    }

    /**
     * Calculate required workers based on total employees
     *
     * @param int $totalEmployees Total approved employees across all shifts
     * @return int Number of workers (1 or 2)
     */
    public static function calculateRequiredWorkers(int $totalEmployees): int
    {
        return $totalEmployees > 100 ? 2 : 1;
    }

    /**
     * Calculate peak level for a specific time reference and windows
     *
     * @param Carbon $currentTime Current time
     * @param Carbon $referenceTime Reference time (shift start or end)
     * @param array $windows Array of time windows
     * @return int Peak level for this reference time
     */
    private static function calculateWindowPeak(Carbon $currentTime, Carbon $referenceTime, array $windows): int
    {
        $diffMinutes = $referenceTime->diffInMinutes($currentTime, false);

        foreach ($windows as $window) {
            if ($diffMinutes >= $window['from'] && $diffMinutes < $window['to']) {
                return $window['level'];
            }
        }

        return self::PEAK_LEVEL_IDLE;
    }
}
