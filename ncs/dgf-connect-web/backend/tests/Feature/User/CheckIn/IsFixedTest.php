<?php

namespace Tests\Feature\User\CheckIn;

use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IsFixedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testCheckInShiftResponseIncludesIsFixed(): void
    {
        Carbon::setTestNow('2026-07-20 10:00:00');

        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $workplacePosition = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $employee->id,
            'date' => '2026-07-20',
            'adjusted_date' => '2026-07-20',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_fixed' => true,
        ]);

        $response = $this->actingAs($employee, 'api')->getJson('/user/check-in/shift');

        $response->assertOk();
        $response->assertJsonPath('data.is_fixed', true);
    }
}
