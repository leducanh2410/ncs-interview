<?php

namespace Tests\Unit\Helpers;

use App\Helpers\WorkHelper;
use Tests\TestCase;

/**
 * Guards the parity of the per-shift math extracted from getWorkHours, which the Shift
 * Planner reuses to layer not-yet-live draft additions into the validation map.
 */
class ComputeShiftWorkDataTest extends TestCase
{
    public function testComputesDurationAndRangeWithoutBreak(): void
    {
        $data = WorkHelper::computeShiftWorkData('2026-06-22', '09:00:00', '13:00:00', null, null, null);

        $this->assertSame(4.0, $data['hour']);
        $this->assertSame('2026-06-22 09:00:00', $data['range']['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-22 13:00:00', $data['range']['end']->format('Y-m-d H:i:s'));
    }

    public function testSubtractsCustomBreakMinutes(): void
    {
        $data = WorkHelper::computeShiftWorkData('2026-06-22', '09:00:00', '13:00:00', null, null, 30);

        $this->assertSame(3.5, $data['hour']);
    }

    public function testWrapsOvernightShiftToNextDay(): void
    {
        $data = WorkHelper::computeShiftWorkData('2026-06-22', '22:00:00', '05:00:00', null, null, null);

        $this->assertSame(7.0, $data['hour']);
        $this->assertSame('2026-06-23 05:00:00', $data['range']['end']->format('Y-m-d H:i:s'));
    }
}
