<?php

namespace Tests\Unit\Jobs;

use App\Enums\ShiftUserStatus;
use App\Jobs\AutoCheckOut;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AutoCheckOut closes shifts an employee forgot to check out of, writing the same payroll
 * columns as a real check-out. These tests pin that it resolves the overtime threshold from
 * the workplace and from the shift flag exactly like the interactive path does.
 */
class AutoCheckOutTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplacePosition $position;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // The job only closes shifts that ended at least 2 hours ago.
        Carbon::setTestNow('2026-06-23 09:00:00');

        $this->manager = User::factory()->create();

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->position = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testAutoCheckOutUsesWorkplaceOvertimeThreshold(): void
    {
        $this->workplace->update(['overtime_after_hours' => 9]);
        $checkIn = $this->makeOpenCheckIn('09:00:00', '20:00:00', '2026-06-22 09:00:00');

        (new AutoCheckOut())->handle();

        // Closed at the shift end, 11 hours worked against the workplace's 9 hour threshold.
        $checkIn->refresh();
        $this->assertSame('2026-06-22 20:00:00', $checkIn->check_out_time);
        $this->assertEquals(660, $checkIn->work_minutes);
        $this->assertEquals(540, $checkIn->work_minutes_normal);
        $this->assertEquals(120, $checkIn->work_minutes_overtime);
    }

    public function testAutoCheckOutFallsBackToSystemDefaultThreshold(): void
    {
        $checkIn = $this->makeOpenCheckIn('09:00:00', '20:00:00', '2026-06-22 09:00:00');

        (new AutoCheckOut())->handle();

        $checkIn->refresh();
        $this->assertEquals(660, $checkIn->work_minutes);
        $this->assertEquals(480, $checkIn->work_minutes_normal);
        $this->assertEquals(180, $checkIn->work_minutes_overtime);
    }

    public function testAutoCheckOutAppliesShiftDurationFlag(): void
    {
        // AutoCheckOut always closes at the shift's own end time, so the flag can only show
        // up through the check-in side: the employee needs is_overtime_allowed so their early
        // check-in isn't clipped back to the shift start, which is what lets the worked time
        // exceed the shift's standard duration in the first place.
        $this->workplace->update(['overtime_after_hours' => 10]);
        $checkIn = $this->makeOpenCheckIn('09:00:00', '19:00:00', '2026-06-22 08:00:00', [
            'break_minutes' => 60,
            'is_overtime_by_duration' => true,
        ]);
        $checkIn->shiftUser->update(['is_overtime_allowed' => true]);

        (new AutoCheckOut())->handle();

        // Standard duration is 9h (10h shift range minus the hour break); the flag narrows
        // the threshold below the workplace's 10h ceiling, so the 1h of early check-in that
        // pushes the total to 10h worked comes out as overtime instead of normal time.
        $checkIn->refresh();
        $this->assertEquals(600, $checkIn->work_minutes);
        $this->assertEquals(540, $checkIn->work_minutes_normal);
        $this->assertEquals(60, $checkIn->work_minutes_overtime);
    }

    public function testAutoCheckOutLeavesShiftsThatEndedLessThanTwoHoursAgo(): void
    {
        Carbon::setTestNow('2026-06-22 20:30:00');
        $checkIn = $this->makeOpenCheckIn('09:00:00', '20:00:00', '2026-06-22 09:00:00');

        (new AutoCheckOut())->handle();

        $this->assertNull($checkIn->refresh()->check_out_time);
    }

    private function makeOpenCheckIn(
        string $startTime,
        string $endTime,
        string $checkInTime,
        array $shiftAttributes = []
    ): CheckIn {
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        $shift = Shift::create(
            array_merge(
                [
                    'workplace_id' => $this->workplace->id,
                    'workplace_shift_id' => $workplaceShift->id,
                    'creator_id' => $this->manager->id,
                    'date' => '2026-06-22',
                    'adjusted_date' => '2026-06-22',
                    'fixed_total' => 1,
                    'temp_total' => 1,
                ],
                $shiftAttributes
            )
        );

        $employee = User::factory()->create();
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        return CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $employee->id,
            'check_in_time' => $checkInTime,
        ]);
    }
}
