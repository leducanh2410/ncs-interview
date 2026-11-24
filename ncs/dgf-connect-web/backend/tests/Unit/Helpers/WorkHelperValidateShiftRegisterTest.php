<?php

namespace Tests\Unit\Helpers;

use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkHelperValidateShiftRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplacePosition $workplacePosition;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'min_rest_hours' => 9,
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
    }

    public function testReturnsNullWhenMinRestHoursDisabled(): void
    {
        $this->workplace->update(['min_rest_hours' => null]);

        // Existing shift ends at 18:00 on 2026-03-08
        $this->createApprovedShift('2026-03-08', '09:00:00', '18:00:00');

        // New shift starts at 02:00 on 2026-03-09 (only 8h rest)
        $newShift = $this->createShift('2026-03-09', '02:00:00', '10:00:00');

        $result = $this->validate($newShift);

        $this->assertNull($result);
    }

    public function testReturnsErrorWhenPriorDayShiftEndsTooClose(): void
    {
        // Existing shift ends at 18:00 on 2026-03-08
        $this->createApprovedShift('2026-03-08', '09:00:00', '18:00:00');

        // New shift starts at 02:00 on 2026-03-09 (8h gap < 9h)
        $newShift = $this->createShift('2026-03-09', '02:00:00', '10:00:00');

        $result = $this->validate($newShift);

        $this->assertNotNull($result);
        $this->assertSame(ErrorCode::ScheduleMinRestHoursViolated, $result['error']);
    }

    public function testReturnsErrorWhenNextDayShiftStartsTooClose(): void
    {
        // Existing shift on 2026-03-09 starts 06:00
        $this->createApprovedShift('2026-03-09', '06:00:00', '14:00:00');

        // New shift on 2026-03-08 ends 22:00 (only 8h until next-day shift)
        $newShift = $this->createShift('2026-03-08', '14:00:00', '22:00:00');

        $result = $this->validate($newShift);

        $this->assertNotNull($result);
        $this->assertSame(ErrorCode::ScheduleMinRestHoursViolated, $result['error']);
    }

    public function testReturnsNullWhenGapMeetsThreshold(): void
    {
        // Existing shift ends at 18:00 on 2026-03-08
        $this->createApprovedShift('2026-03-08', '09:00:00', '18:00:00');

        // New shift starts at 03:00 on 2026-03-09 (exactly 9h gap)
        $newShift = $this->createShift('2026-03-09', '03:00:00', '10:00:00');

        $result = $this->validate($newShift);

        $this->assertNull($result);
    }

    public function testIgnoresSameDateShifts(): void
    {
        // Existing shift 06:00-10:00 on same date as new shift
        $this->createApprovedShift('2026-03-08', '06:00:00', '10:00:00');

        // New shift 14:00-18:00 same day, only 4h gap — should be ignored by rest check
        $newShift = $this->createShift('2026-03-08', '14:00:00', '18:00:00');

        $result = $this->validate($newShift);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenNoAdjacentShifts(): void
    {
        $newShift = $this->createShift('2026-03-08', '09:00:00', '18:00:00');

        $result = $this->validate($newShift);

        $this->assertNull($result);
    }

    public function testOvernightShiftRestComputedAcrossDateBoundary(): void
    {
        // Existing overnight shift: date=2026-03-08, 22:00 → 2026-03-09 05:00
        $this->createApprovedShift('2026-03-08', '22:00:00', '05:00:00');

        // New shift on 2026-03-09 at 13:00 — gap from prev end 05:00 is 8h < 9h
        $newShift = $this->createShift('2026-03-09', '13:00:00', '17:00:00');

        $result = $this->validate($newShift);

        $this->assertNotNull($result);
        $this->assertSame(ErrorCode::ScheduleMinRestHoursViolated, $result['error']);
    }

    private function createShift(string $date, string $startTime, string $endTime): Shift
    {
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
    }

    private function createApprovedShift(string $date, string $startTime, string $endTime): Shift
    {
        $shift = $this->createShift($date, $startTime, $endTime);
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        return $shift;
    }

    private function validate(Shift $newShift): ?array
    {
        $shiftDate = Carbon::parse($newShift->date);
        $rangeStart = $shiftDate->clone()->subDays(6)->startOfDay();
        $rangeEnd = $shiftDate->clone()->addDays(6)->endOfDay();

        $workHours = WorkHelper::getWorkHours([$this->user->id], $rangeStart, $rangeEnd, $newShift->id);

        return WorkHelper::validateShiftRegister($newShift, $rangeStart, $workHours[$this->user->id] ?? [], null, null);
    }
}
