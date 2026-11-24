<?php

namespace Tests\Unit\Helpers;

use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkHelperRoundCheckTimesTest extends TestCase
{
    private const DATE = '2026-08-25';

    public function testRoundsCheckInUpAndCheckOutDown(): void
    {
        $shift = $this->makeShift('09:00:00', '18:00:00', checkInRoundType: 1, checkOutRoundType: 0);

        $result = $this->round($shift, '09:07:00', '17:52:00');

        $this->assertSame('2026-08-25 09:15:00', $result['check_in_time']->toDateTimeString());
        $this->assertSame('2026-08-25 17:45:00', $result['check_out_time']->toDateTimeString());
        $this->assertFalse($result['within_break']);
    }

    public function testClampsToShiftRangeWhenOvertimeIsNotAllowed(): void
    {
        $shift = $this->makeShift('09:00:00', '18:00:00');

        $result = $this->round($shift, '08:40:00', '18:30:00', allowOvertime: false);

        $this->assertSame('2026-08-25 09:00:00', $result['check_in_time']->toDateTimeString());
        $this->assertSame('2026-08-25 18:00:00', $result['check_out_time']->toDateTimeString());
    }

    public function testKeepsTimesOutsideShiftWhenOvertimeIsAllowed(): void
    {
        $shift = $this->makeShift('09:00:00', '18:00:00');

        $result = $this->round($shift, '08:45:00', '18:30:00', allowOvertime: true);

        $this->assertSame('2026-08-25 08:45:00', $result['check_in_time']->toDateTimeString());
        $this->assertSame('2026-08-25 18:30:00', $result['check_out_time']->toDateTimeString());
    }

    public function testReportsAttendanceInsideTheBreakWindowWithoutRounding(): void
    {
        $shift = $this->makeShift('09:00:00', '18:00:00', startBreakTime: '12:00:00', endBreakTime: '13:00:00');

        $result = $this->round($shift, '12:07:00', '12:52:00');

        $this->assertTrue($result['within_break']);
        $this->assertSame('2026-08-25 12:07:00', $result['check_in_time']->toDateTimeString());
        $this->assertSame('2026-08-25 12:52:00', $result['check_out_time']->toDateTimeString());
    }

    public function testMatchesTheTimesCalculateWorkHourStores(): void
    {
        $shift = $this->makeShift('21:00:00', '06:00:00', checkInRoundType: 1, checkOutRoundType: 0);
        $checkIn = Carbon::parse('2026-08-25 21:07:00');
        $checkOut = Carbon::parse('2026-08-26 05:52:00');

        $rounded = WorkHelper::roundCheckTimes($shift, $checkIn->copy(), $checkOut->copy());
        $calculated = WorkHelper::calculateWorkHour($shift, $checkIn->copy(), $checkOut->copy(), false, 60);

        $this->assertSame(
            $calculated['check_in_time']->toDateTimeString(),
            $rounded['check_in_time']->toDateTimeString()
        );
        $this->assertSame(
            $calculated['check_out_time']->toDateTimeString(),
            $rounded['check_out_time']->toDateTimeString()
        );
    }

    /**
     * @return array{check_in_time: Carbon, check_out_time: Carbon, within_break: bool}
     */
    private function round(Shift $shift, string $checkIn, string $checkOut, bool $allowOvertime = false): array
    {
        return WorkHelper::roundCheckTimes(
            $shift,
            Carbon::parse(self::DATE . ' ' . $checkIn),
            Carbon::parse(self::DATE . ' ' . $checkOut),
            $allowOvertime
        );
    }

    private function makeShift(
        string $startTime,
        string $endTime,
        int $checkInRoundType = 1,
        int $checkOutRoundType = 1,
        ?string $startBreakTime = null,
        ?string $endBreakTime = null
    ): Shift {
        $workplaceShift = new WorkplaceShift();
        $workplaceShift->forceFill([
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_break_time' => $startBreakTime,
            'end_break_time' => $endBreakTime,
            'check_in_round_type' => $checkInRoundType,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => $checkOutRoundType,
            'check_out_round_minutes' => 15,
        ]);

        $shift = new Shift(['date' => self::DATE]);
        $shift->setRelation('workplaceShift', $workplaceShift);
        $shift->setRelation('workplace', new Workplace());

        return $shift;
    }
}
