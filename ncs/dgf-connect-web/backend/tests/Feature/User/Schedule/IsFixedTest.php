<?php

namespace Tests\Feature\User\Schedule;

use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsFixedTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
    }

    public function testResponseIncludesIsFixedForFixedShift(): void
    {
        $this->createShiftUser('2026-07-20', true);

        $response = $this->actingAs($this->employee, 'api')->getJson($this->myScheduleUrl());

        $response->assertOk();
        $response->assertJsonPath('data.0.is_fixed', true);
    }

    public function testResponseIncludesIsFixedFalseForTempShift(): void
    {
        $this->createShiftUser('2026-07-20', false);

        $response = $this->actingAs($this->employee, 'api')->getJson($this->myScheduleUrl());

        $response->assertOk();
        $response->assertJsonPath('data.0.is_fixed', false);
    }

    private function createShiftUser(string $date, bool $isFixed): ShiftUser
    {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->employee->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_fixed' => $isFixed,
        ]);
    }

    private function myScheduleUrl(): string
    {
        return '/user/my-schedules?status=' .
            ShiftUserStatus::Approved->value .
            '&dates[]=2026-07-01&dates[]=2026-07-31';
    }
}
