<?php

namespace Tests\Unit\Services\Manage;

use App\Services\Manage\WorkHistoryService;
use Tests\TestCase;

class WorkHistoryServiceTest extends TestCase
{
    private WorkHistoryService $workHistoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workHistoryService = new WorkHistoryService();
    }

    public function testToDayFractionReturnsNullForNull(): void
    {
        $this->assertNull($this->workHistoryService->toDayFraction(null));
    }

    public function testToDayFractionAtMidnight(): void
    {
        $this->assertSame(0.0, $this->workHistoryService->toDayFraction('2026-08-20 00:00:00'));
    }

    public function testToDayFractionAtNoon(): void
    {
        $this->assertSame(0.5, $this->workHistoryService->toDayFraction('2026-08-20 12:00:00'));
    }

    public function testToDayFractionForNineAm(): void
    {
        $this->assertSame(0.375, $this->workHistoryService->toDayFraction('2026-08-20 09:00:00'));
    }

    public function testToDayFractionJustAfterMidnight(): void
    {
        // Crossing-midnight value (e.g. an overnight shift's check-out at 00:15): the fraction is
        // computed purely from the time-of-day, with no awareness of which calendar day it is on.
        $this->assertEqualsWithDelta(
            0.010416666666667,
            $this->workHistoryService->toDayFraction('2026-08-21 00:15:00'),
            1e-9
        );
    }

    public function testToDayFractionIgnoresDateComponent(): void
    {
        $this->assertSame(
            $this->workHistoryService->toDayFraction('2026-01-01 09:00:00'),
            $this->workHistoryService->toDayFraction('2026-12-31 09:00:00')
        );
    }
}
