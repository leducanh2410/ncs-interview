<?php

namespace Tests\Unit\Helpers;

use App\Helpers\PeakLevelCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PeakLevelCalculatorTest extends TestCase
{
    /**
     * Test calculatePeakLevel with empty shifts returns idle level
     */
    public function testCalculatePeakLevelWithEmptyShifts()
    {
        $shifts = collect();
        $currentTime = Carbon::parse('2025-01-15 10:00:00');

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(0, $result['peakLevel']);
        $this->assertEquals(0, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-in peak: 45-30 minutes before (level 1)
     */
    public function testCalculatePeakLevelCheckInWindow45to30MinutesBefore()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 09:20:00'); // 40 minutes before

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 10);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(1, $result['peakLevel']);
        $this->assertEquals(10, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-in peak: 30-15 minutes before (level 2)
     */
    public function testCalculatePeakLevelCheckInWindow30to15MinutesBefore()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 09:40:00'); // 20 minutes before

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 15);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(2, $result['peakLevel']);
        $this->assertEquals(15, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-in peak: 15 minutes before to 5 minutes after (level 3)
     */
    public function testCalculatePeakLevelCheckInWindow15MinutesBeforeTo5MinutesAfter()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 09:50:00'); // 10 minutes before

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 20);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(3, $result['peakLevel']);
        $this->assertEquals(20, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-in peak: at shift start time (level 3)
     */
    public function testCalculatePeakLevelCheckInAtShiftStart()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 10:00:00'); // exactly at start

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 25);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(3, $result['peakLevel']);
        $this->assertEquals(25, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-in peak: 10-30 minutes after (level 1)
     */
    public function testCalculatePeakLevelCheckInWindow10to30MinutesAfter()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 10:15:00'); // 15 minutes after

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 8);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(1, $result['peakLevel']);
        $this->assertEquals(8, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-out peak: 30-10 minutes before (level 2)
     */
    public function testCalculatePeakLevelCheckOutWindow30to10MinutesBefore()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 17:40:00'); // 20 minutes before end

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 12);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(2, $result['peakLevel']);
        $this->assertEquals(12, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-out peak: 10 minutes before to 10 minutes after (level 3)
     */
    public function testCalculatePeakLevelCheckOutWindow10MinutesBeforeTo10MinutesAfter()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 17:55:00'); // 5 minutes before end

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 30);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(3, $result['peakLevel']);
        $this->assertEquals(30, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-out peak: at shift end time (level 3)
     */
    public function testCalculatePeakLevelCheckOutAtShiftEnd()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 18:00:00'); // exactly at end

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 18);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(3, $result['peakLevel']);
        $this->assertEquals(18, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel with check-out peak: 10-30 minutes after (level 1)
     */
    public function testCalculatePeakLevelCheckOutWindow10to30MinutesAfter()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 18:15:00'); // 15 minutes after end

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 5);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(1, $result['peakLevel']);
        $this->assertEquals(5, $result['activeUserCount']);
    }

    /**
     * Test calculatePeakLevel outside all peak windows returns idle level
     */
    public function testCalculatePeakLevelOutsidePeakWindows()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 14:00:00'); // midday, outside all windows

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 100);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(0, $result['peakLevel']);
        $this->assertEquals(0, $result['activeUserCount']); // Should be 0 since peak level is 0
    }

    /**
     * Test calculatePeakLevel with multiple shifts returns highest peak level
     */
    public function testCalculatePeakLevelWithMultipleShifts()
    {
        // Shift 1: outside peak windows (level 0)
        $shift1StartTime = Carbon::parse('2025-01-15 08:00:00');
        $shift1EndTime = Carbon::parse('2025-01-15 12:00:00');

        // Shift 2: check-in peak window (level 3)
        $shift2StartTime = Carbon::parse('2025-01-15 14:00:00');
        $shift2EndTime = Carbon::parse('2025-01-15 18:00:00');

        $currentTime = Carbon::parse('2025-01-15 13:50:00'); // 10 minutes before shift 2 start

        $shift1 = $this->createMockShift($shift1StartTime, $shift1EndTime, 50);
        $shift2 = $this->createMockShift($shift2StartTime, $shift2EndTime, 60);
        $shifts = collect([$shift1, $shift2]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(3, $result['peakLevel']);
        $this->assertEquals(60, $result['activeUserCount']); // Only shift2 has peak > 0
    }

    /**
     * Test calculatePeakLevel with multiple shifts where one has higher check-out peak
     */
    public function testCalculatePeakLevelWithMultipleShiftsHighestCheckOut()
    {
        // Shift 1: check-in level 1
        $shift1StartTime = Carbon::parse('2025-01-15 14:00:00');
        $shift1EndTime = Carbon::parse('2025-01-15 18:00:00');

        // Shift 2: check-out level 2
        $shift2StartTime = Carbon::parse('2025-01-15 10:00:00');
        $shift2EndTime = Carbon::parse('2025-01-15 14:30:00');

        $currentTime = Carbon::parse('2025-01-15 14:10:00'); // 10 min after shift1 start, 20 min before shift2 end

        $shift1 = $this->createMockShift($shift1StartTime, $shift1EndTime, 40);
        $shift2 = $this->createMockShift($shift2StartTime, $shift2EndTime, 35);
        $shifts = collect([$shift1, $shift2]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(2, $result['peakLevel']);
        $this->assertEquals(75, $result['activeUserCount']); // Both shifts have peak > 0
    }

    /**
     * Test getPingInterval for level 0 (idle) returns 5 minutes
     */
    public function testGetPingIntervalForLevelIdle()
    {
        $result = PeakLevelCalculator::getPingInterval(0);

        $this->assertEquals(5, $result);
    }

    /**
     * Test getPingInterval for level 1 (low) returns 3 minutes
     */
    public function testGetPingIntervalForLevelLow()
    {
        $result = PeakLevelCalculator::getPingInterval(1);

        $this->assertEquals(3, $result);
    }

    /**
     * Test getPingInterval for level 2 (medium) returns 1 minute
     */
    public function testGetPingIntervalForLevelMedium()
    {
        $result = PeakLevelCalculator::getPingInterval(2);

        $this->assertEquals(1, $result);
    }

    /**
     * Test getPingInterval for level 3 (high) returns 0 minutes
     */
    public function testGetPingIntervalForLevelHigh()
    {
        $result = PeakLevelCalculator::getPingInterval(3);

        $this->assertEquals(0, $result);
    }

    /**
     * Test getPingInterval for invalid level returns default 5 minutes
     */
    public function testGetPingIntervalForInvalidLevel()
    {
        $result = PeakLevelCalculator::getPingInterval(999);

        $this->assertEquals(5, $result);
    }

    /**
     * Test calculateRequiredWorkers with 100 or fewer employees returns 1 worker
     */
    public function testCalculateRequiredWorkersAt100Employees()
    {
        $result = PeakLevelCalculator::calculateRequiredWorkers(100);

        $this->assertEquals(1, $result);
    }

    /**
     * Test calculateRequiredWorkers with more than 100 employees returns 2 workers
     */
    public function testCalculateRequiredWorkersAbove100Employees()
    {
        $result = PeakLevelCalculator::calculateRequiredWorkers(101);

        $this->assertEquals(2, $result);
    }

    /**
     * Test calculateRequiredWorkers with 0 employees returns 1 worker
     */
    public function testCalculateRequiredWorkersWithZeroEmployees()
    {
        $result = PeakLevelCalculator::calculateRequiredWorkers(0);

        $this->assertEquals(1, $result);
    }

    /**
     * Test calculateRequiredWorkers with 1 employee returns 1 worker
     */
    public function testCalculateRequiredWorkersWithOneEmployee()
    {
        $result = PeakLevelCalculator::calculateRequiredWorkers(1);

        $this->assertEquals(1, $result);
    }

    /**
     * Test calculateRequiredWorkers with many employees returns 2 workers
     */
    public function testCalculateRequiredWorkersWithManyEmployees()
    {
        $result = PeakLevelCalculator::calculateRequiredWorkers(500);

        $this->assertEquals(2, $result);
    }

    /**
     * Test boundary case: exactly at the edge of check-in window (from)
     */
    public function testCalculatePeakLevelAtCheckInWindowBoundaryFrom()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 09:15:00'); // exactly -45 minutes

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 7);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(1, $result['peakLevel']);
        $this->assertEquals(7, $result['activeUserCount']);
    }

    /**
     * Test boundary case: exactly at the edge of check-in window (to)
     */
    public function testCalculatePeakLevelAtCheckInWindowBoundaryTo()
    {
        $shiftStartTime = Carbon::parse('2025-01-15 10:00:00');
        $shiftEndTime = Carbon::parse('2025-01-15 18:00:00');
        $currentTime = Carbon::parse('2025-01-15 09:30:00'); // exactly -30 minutes

        $shift = $this->createMockShift($shiftStartTime, $shiftEndTime, 14);
        $shifts = collect([$shift]);

        $result = PeakLevelCalculator::calculatePeakLevel($shifts, $currentTime);

        $this->assertEquals(2, $result['peakLevel']);
        $this->assertEquals(14, $result['activeUserCount']);
    }

    /**
     * @return array{starts_at: string, ends_at: string, approved_users_count: int}
     */
    private function createMockShift(Carbon $startTime, Carbon $endTime, int $approvedUsersCount = 0): array
    {
        return [
            'starts_at' => $startTime->toDateTimeString(),
            'ends_at' => $endTime->toDateTimeString(),
            'approved_users_count' => $approvedUsersCount,
        ];
    }
}
