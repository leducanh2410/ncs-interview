<?php

namespace App\Helpers;

use Exception;
use Illuminate\Support\Carbon;

class DateTimeHelper
{
    public static function formatNumericHour(float $hour): string
    {
        // Format hour from float to HH:mm
        $hours = floor($hour);
        $minutes = ($hour - $hours) * 60;

        return sprintf('%d:%02d', $hours, $minutes);
    }

    public static function formatMinuteToTime(?int $minutes): string
    {
        if (null === $minutes) {
            return '0:00';
        }
        $hours = floor($minutes / 60);
        $minutes = $minutes % 60;

        return sprintf('%d:%02d', $hours, $minutes);
    }

    public static function convertTo24Hour(?string $time): ?string
    {
        if (!$time) {
            return $time;
        }
        // Split hour and minute
        $parts = explode(':', $time);
        $hour = (int) $parts[0];
        $minute = (int) $parts[1];

        if ($hour >= 24) {
            $hour -= 24;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    public static function formatHour(
        string $startTime,
        string $endTime,
        ?string $breakStartTime = null,
        ?string $breakEndTime = null
    ): array {
        if (!$startTime || !$endTime) {
            return [$startTime, $endTime, $breakStartTime, $breakEndTime];
        }
        $startTimeCarbon = Carbon::parse($startTime);
        $endTimeCarbon = Carbon::parse($endTime);
        if ($endTimeCarbon->isAfter($startTimeCarbon)) {
            return [$startTime, $endTime, $breakStartTime, $breakEndTime];
        }
        $endHour = $endTimeCarbon->hour;
        $endMinute = $endTimeCarbon->minute;

        $newEndTime = $endHour + 24 . ':' . mb_str_pad($endMinute, 2, '0', STR_PAD_LEFT);
        $newBreakStartTime = $breakStartTime;
        $newBreakEndTime = $breakEndTime;
        if ($newBreakStartTime && $newBreakEndTime) {
            $breakStartTimeCarbon = Carbon::parse($breakStartTime);
            $breakEndTimeCarbon = Carbon::parse($breakEndTime);
            if ($breakStartTimeCarbon->isBefore($startTimeCarbon)) {
                $newBreakStartTime =
                    $breakStartTimeCarbon->hour +
                    24 .
                    ':' .
                    mb_str_pad($breakStartTimeCarbon->minute, 2, '0', STR_PAD_LEFT);
            }
            if ($breakEndTimeCarbon->isBefore($endTimeCarbon)) {
                $newBreakEndTime =
                    $breakEndTimeCarbon->hour +
                    24 .
                    ':' .
                    mb_str_pad($breakEndTimeCarbon->minute, 2, '0', STR_PAD_LEFT);
            }
        }

        return [$startTime, $newEndTime, $newBreakStartTime, $newBreakEndTime];
    }

    public static function isValidFormat(string $datetime, string $format): bool
    {
        try {
            $date = Carbon::createFromFormat($format, $datetime);
        } catch (Exception $e) {
            return false;
        }

        return $date->format($format) === $datetime;
    }
}
