<?php

namespace App\Services\Reconciliation;

use App\Models\CheckIn;
use Illuminate\Support\Carbon;

class ReconciliationFieldCompare
{
    public const SUMMARY_FIELDS = [
        'work_minutes',
        'work_minutes_normal',
        'work_minutes_overtime',
        'work_minutes_night',
        'work_minutes_night_overtime',
    ];

    public static function effectiveBreakMinutes(CheckIn $checkIn): int
    {
        $checkIn->loadMissing('shift.workplaceShift');

        return (int) ($checkIn->break_minutes ??
            ($checkIn->shift->break_minutes ?? ($checkIn->shift->workplaceShift->break_duration ?? 0)));
    }

    public static function paidCheckIn(CheckIn $checkIn): ?string
    {
        return self::toDateTimeString($checkIn->check_in_time_rounded ?? $checkIn->check_in_time);
    }

    public static function paidCheckOut(CheckIn $checkIn): ?string
    {
        return self::toDateTimeString($checkIn->check_out_time_rounded ?? $checkIn->check_out_time);
    }

    /**
     * @return array{check_in: ?string, check_out: ?string, work_minutes: ?int, work_minutes_normal: ?int, work_minutes_overtime: ?int, work_minutes_night: ?int, work_minutes_night_overtime: ?int}
     */
    public static function paidSnapshot(CheckIn $checkIn): array
    {
        $snapshot = [
            'check_in' => self::paidCheckIn($checkIn),
            'check_out' => self::paidCheckOut($checkIn),
        ];
        foreach (self::SUMMARY_FIELDS as $field) {
            $value = $checkIn->getAttribute($field);
            $snapshot[$field] = $value === null ? null : (int) $value;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed>|null $external
     * @param array<string, mixed>|null $dgf
     * @return list<string>
     */
    public static function summaryDiffers(?array $external, ?array $dgf): array
    {
        if ($external === null || $dgf === null) {
            return [];
        }

        $differs = [];
        foreach (self::SUMMARY_FIELDS as $field) {
            $amazon = $external[$field] ?? null;
            if ($amazon === null) {
                continue;
            }
            if ((int) $amazon !== (int) ($dgf[$field] ?? 0)) {
                $differs[] = $field;
            }
        }

        return $differs;
    }

    public static function timesMatch(?string $left, ?string $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if ($left === null || $right === null) {
            return false;
        }

        $leftParsed = Carbon::parse($left);
        $rightParsed = Carbon::parse($right);

        return $leftParsed->hour === $rightParsed->hour && $leftParsed->minute === $rightParsed->minute;
    }

    /**
     * @return array{check_in: bool, check_out: bool, break: bool}
     */
    public static function differs(CanonicalRow $external, CheckIn $checkIn): array
    {
        return [
            'check_in' => !self::timesMatch($external->checkIn->toDateTimeString(), self::paidCheckIn($checkIn)),
            'check_out' => !self::timesMatch($external->checkOut->toDateTimeString(), self::paidCheckOut($checkIn)),
            'break' => $external->breakMinutes !== self::effectiveBreakMinutes($checkIn),
        ];
    }

    private static function toDateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateTimeString();
    }
}
