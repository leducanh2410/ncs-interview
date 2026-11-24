<?php

namespace Tests\Unit\Helpers;

use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers how the overtime threshold is resolved: the workplace setting acts as the ceiling,
 * a shift flagged with is_overtime_by_duration narrows it to that shift's own duration, and
 * the threshold is always compared against minutes actually worked rather than elapsed time.
 *
 * Models are built in memory rather than persisted: calculateWorkHour must not query, so every
 * relation it reads is set explicitly here.
 */
class WorkHelperCalculateWorkHourTest extends TestCase
{
    private const DATE = '2026-08-20';

    public function testUsesSystemDefaultThresholdWhenWorkplaceHasNoSetting(): void
    {
        $shift = $this->makeShift('09:00:00', '19:00:00');

        $result = $this->calculate($shift, '09:00:00', '19:00:00');

        $this->assertSame(600, $result['total']);
        $this->assertSame(480, $result['normal']);
        $this->assertSame(120, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testUsesWorkplaceThresholdWhenConfigured(): void
    {
        $shift = $this->makeShift('09:00:00', '19:00:00', overtimeAfterHours: 9);

        $result = $this->calculate($shift, '09:00:00', '19:00:00');

        $this->assertSame(600, $result['total']);
        $this->assertSame(540, $result['normal']);
        $this->assertSame(60, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testBreakDurationPushesThresholdBackSoRestIsNotOvertime(): void
    {
        $shift = $this->makeShift('09:00:00', '19:00:00', overtimeAfterHours: 9, breakMinutes: 60);

        $result = $this->calculate($shift, '09:00:00', '19:00:00');

        $this->assertSame(540, $result['total']);
        $this->assertSame(540, $result['normal']);
        $this->assertSame(0, $result['overtime']);
        $this->assertSame(0, $result['night_overtime']);
        $this->assertConsistent($result);
    }

    public function testBreakWindowMinutesAreSkippedAndThresholdIsNotShifted(): void
    {
        $shift = $this->makeShift('09:00:00', '19:00:00', startBreakTime: '12:00:00', endBreakTime: '13:00:00');

        $result = $this->calculate($shift, '09:00:00', '19:00:00');

        $this->assertSame(540, $result['total']);
        $this->assertSame(480, $result['normal']);
        $this->assertSame(60, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testFallsBackToSystemDefaultWhenShiftHasNoWorkplace(): void
    {
        $shift = $this->makeShift('09:00:00', '19:00:00', withWorkplace: false);

        $result = $this->calculate($shift, '09:00:00', '19:00:00');

        $this->assertSame(480, $result['normal']);
        $this->assertSame(120, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testSplitsNightAndNightOvertimeUsingWorkplaceThreshold(): void
    {
        $shift = $this->makeShift('16:00:00', '03:00:00', overtimeAfterHours: 9);

        $result = $this->calculate($shift, '16:00:00', '03:00:00', checkOutNextDay: true);

        $this->assertSame(660, $result['total']);
        $this->assertSame(360, $result['normal']);
        $this->assertSame(180, $result['night']);
        $this->assertSame(120, $result['night_overtime']);
        $this->assertSame(0, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testShiftFlagCountsWorkBeyondShiftDurationAsOvertime(): void
    {
        $shift = $this->makeShift('09:00:00', '17:00:00', overtimeAfterHours: 9, isOvertimeByDuration: true);

        $result = $this->calculate($shift, '09:00:00', '18:00:00');

        $this->assertSame(540, $result['total']);
        $this->assertSame(480, $result['normal']);
        $this->assertSame(60, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testShiftFlagNeverRaisesThresholdAboveWorkplaceSetting(): void
    {
        $shift = $this->makeShift('09:00:00', '19:00:00', overtimeAfterHours: 9, isOvertimeByDuration: true);

        $result = $this->calculate($shift, '09:00:00', '20:00:00');

        $this->assertSame(660, $result['total']);
        $this->assertSame(540, $result['normal']);
        $this->assertSame(120, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testShiftFlagDoesNotKeepWorkAfterShiftEndWhenEmployeeOvertimeIsNotAllowed(): void
    {
        $shift = $this->makeShift('09:00:00', '17:00:00', isOvertimeByDuration: true);

        $result = $this->calculate($shift, '09:00:00', '18:00:00', allowOvertime: false);

        $this->assertSame(480, $result['total']);
        $this->assertSame(480, $result['normal']);
        $this->assertSame(0, $result['overtime']);
        $this->assertSame('17:00:00', $result['check_out_time']->format('H:i:s'));
        $this->assertConsistent($result);
    }

    public function testShiftFlagDoesNotTurnAnEarlyCheckInIntoOvertime(): void
    {
        // Employees may check in up to an hour before the shift on their own; those minutes
        // must not become paid overtime just because the shift carries the duration flag.
        $shift = $this->makeShift('09:00:00', '17:00:00', overtimeAfterHours: 9, isOvertimeByDuration: true);

        $result = $this->calculate($shift, '08:05:00', '17:00:00', allowOvertime: false);

        $this->assertSame(480, $result['total']);
        $this->assertSame(480, $result['normal']);
        $this->assertSame(0, $result['overtime']);
        $this->assertSame('09:00:00', $result['check_in_time']->format('H:i:s'));
        $this->assertConsistent($result);
    }

    public function testShiftFlagKeepsWorkOutsideShiftRangeWhenEmployeeOvertimeIsAllowed(): void
    {
        $shift = $this->makeShift('09:00:00', '17:00:00', isOvertimeByDuration: true);

        $result = $this->calculate($shift, '09:00:00', '18:00:00', allowOvertime: true);

        $this->assertSame(540, $result['total']);
        $this->assertSame(480, $result['normal']);
        $this->assertSame(60, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testWorkOutsideShiftRangeIsStillClippedWhenFlagIsOff(): void
    {
        $shift = $this->makeShift('09:00:00', '17:00:00');

        $result = $this->calculate($shift, '08:00:00', '18:00:00', allowOvertime: false);

        $this->assertSame(480, $result['total']);
        $this->assertSame(480, $result['normal']);
        $this->assertSame(0, $result['overtime']);
        $this->assertSame('09:00:00', $result['check_in_time']->format('H:i:s'));
        $this->assertSame('17:00:00', $result['check_out_time']->format('H:i:s'));
        $this->assertConsistent($result);
    }

    public function testShiftFlagIsIgnoredWhenShiftDurationIsNotPositive(): void
    {
        // Break declared as long as the shift itself leaves a standard duration of zero.
        $shift = $this->makeShift(
            '09:00:00',
            '17:00:00',
            overtimeAfterHours: 9,
            isOvertimeByDuration: true,
            breakMinutes: 480
        );

        $result = $this->calculate($shift, '09:00:00', '19:00:00', customBreakDuration: 0);

        $this->assertSame(600, $result['total']);
        $this->assertSame(540, $result['normal']);
        $this->assertSame(60, $result['overtime']);
        $this->assertConsistent($result);
    }

    public function testLeavingBeforeTheBreakIsOverNeverProducesNegativeMinutes(): void
    {
        $shift = $this->makeShift('09:00:00', '18:00:00', breakMinutes: 60);

        $result = $this->calculate($shift, '09:00:00', '09:30:00');

        $this->assertSame(0, $result['total']);
        $this->assertConsistent($result);
    }

    private function calculate(
        Shift $shift,
        string $checkInTime,
        string $checkOutTime,
        bool $allowOvertime = true,
        ?int $customBreakDuration = null,
        bool $checkOutNextDay = false
    ): array {
        $checkOutDate = $checkOutNextDay ? Carbon::parse(self::DATE)->addDay()->format('Y-m-d') : self::DATE;

        return WorkHelper::calculateWorkHour(
            $shift,
            Carbon::parse(self::DATE . ' ' . $checkInTime),
            Carbon::parse($checkOutDate . ' ' . $checkOutTime),
            $allowOvertime,
            $customBreakDuration
        );
    }

    private function makeShift(
        string $startTime,
        string $endTime,
        ?int $overtimeAfterHours = null,
        bool $isOvertimeByDuration = false,
        ?int $breakMinutes = null,
        ?string $startBreakTime = null,
        ?string $endBreakTime = null,
        bool $withWorkplace = true
    ): Shift {
        $workplaceShift = new WorkplaceShift([
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_break_time' => $startBreakTime,
            'end_break_time' => $endBreakTime,
        ]);

        $shift = new Shift([
            'date' => self::DATE,
            'break_minutes' => $breakMinutes,
            'is_overtime_by_duration' => $isOvertimeByDuration,
        ]);
        $shift->setRelation('workplaceShift', $workplaceShift);
        $shift->setRelation(
            'workplace',
            $withWorkplace ? new Workplace(['overtime_after_hours' => $overtimeAfterHours]) : null
        );

        return $shift;
    }

    private function assertConsistent(array $result): void
    {
        $this->assertSame(
            $result['total'],
            $result['normal'] + $result['night'] + $result['overtime'] + $result['night_overtime'],
            'The four categories must add up to the total worked minutes'
        );

        foreach (['total', 'normal', 'night', 'overtime', 'night_overtime'] as $key) {
            $this->assertGreaterThanOrEqual(0, $result[$key], "{$key} must never be negative");
        }
    }
}
