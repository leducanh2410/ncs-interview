<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsAggregateOnlyResponse;
use Tests\TestCase;

class GetShowUpRateActionTest extends TestCase
{
    use AssertsAggregateOnlyResponse;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    private WorkplaceShift $morningShift;

    private WorkplaceShift $eveningShift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-22 10:00:00');

        $this->workplace = Workplace::create([
            'id' => 1,
            'name' => 'WP1',
            'address' => 'A',
            'latitude' => 35.6,
            'longitude' => 139.6,
        ]);
        $this->morningShift = WorkplaceShift::create([
            'workplace_id' => 1,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 0,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 0,
        ]);
        $this->eveningShift = WorkplaceShift::create([
            'workplace_id' => 1,
            'start_time' => '17:00:00',
            'end_time' => '22:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 0,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 0,
        ]);

        $shiftListPerm = Permission::create(['name' => 'shift.list', 'display_name' => 'Shift List']);
        Permission::create(['name' => 'work_history.list', 'display_name' => 'Work History']);

        $role = Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching([$shiftListPerm->id]);

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);
    }

    public function testPerWorkplaceMetrics(): void
    {
        $workers = User::factory()->count(10)->create();

        // 8 shows out of 10
        foreach ($workers as $i => $w) {
            $shift = $this->createShift('2026-05-10', $this->morningShift);
            $su = $this->createApprovedShiftUser($shift, $w);
            if ($i < 8) {
                $this->createCheckIn($su, $shift, $w);
            }
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/show-up-rate?window=90');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data['by_workplace']);
        $row = $data['by_workplace'][0];
        $this->assertEquals(1, $row['workplace_id']);
        $this->assertEquals('WP1', $row['workplace_name']);
        $this->assertEquals(10, $row['assigned_count']);
        $this->assertEquals(8, $row['check_in_count']);
        $this->assertEquals(80.0, $row['show_up_rate']);
        $this->assertFalse($row['low_sample']);
    }

    public function testPerWorkplaceSlotMetrics(): void
    {
        $workers = User::factory()->count(6)->create();

        // Morning: 4 assigned, 3 shows
        foreach ($workers->take(4) as $i => $w) {
            $shift = $this->createShift('2026-05-10', $this->morningShift);
            $su = $this->createApprovedShiftUser($shift, $w);
            if ($i < 3) {
                $this->createCheckIn($su, $shift, $w);
            }
        }

        // Evening: 2 assigned, 2 shows
        foreach ($workers->slice(4) as $w) {
            $shift = $this->createShift('2026-05-10', $this->eveningShift);
            $su = $this->createApprovedShiftUser($shift, $w);
            $this->createCheckIn($su, $shift, $w);
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/show-up-rate?window=90');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data['by_workplace_slot']);

        // Sorted by show_up_rate ASC — morning (75%) comes before evening (100%)
        $this->assertEquals($this->morningShift->id, $data['by_workplace_slot'][0]['workplace_shift_id']);
        $this->assertEquals(75.0, $data['by_workplace_slot'][0]['show_up_rate']);
        $this->assertEquals('09:00–18:00', $data['by_workplace_slot'][0]['slot_label']);

        $this->assertEquals($this->eveningShift->id, $data['by_workplace_slot'][1]['workplace_shift_id']);
        $this->assertEquals(100.0, $data['by_workplace_slot'][1]['show_up_rate']);
    }

    public function testLowSampleFlagBelowFive(): void
    {
        $workers = User::factory()->count(3)->create();

        foreach ($workers as $w) {
            $shift = $this->createShift('2026-05-10', $this->morningShift);
            $su = $this->createApprovedShiftUser($shift, $w);
            $this->createCheckIn($su, $shift, $w);
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/show-up-rate?window=90');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertTrue($data['by_workplace'][0]['low_sample']);
    }

    public function testRespondsOnlyWithWorkplaceLevelIdentifiers(): void
    {
        $worker = User::factory()->create();
        $shift = $this->createShift('2026-05-10', $this->morningShift);
        $su = $this->createApprovedShiftUser($shift, $worker);
        $this->createCheckIn($su, $shift, $worker);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/show-up-rate?window=90');

        $response->assertOk();
        $this->assertNoUserIdentifyingFields($response->json('data'));
    }

    // One concrete Shift per (date, slot): repeated calls for the same date/slot reuse it so several
    // workers share the shift (the realistic model and what the shifts unique index now enforces).
    private function createShift(string $date, WorkplaceShift $ws): Shift
    {
        return Shift::firstOrCreate(
            [
                'workplace_id' => $ws->workplace_id,
                'workplace_shift_id' => $ws->id,
                'date' => $date,
            ],
            [
                'creator_id' => $this->admin->id,
                'adjusted_date' => $date,
                'fixed_total' => 5,
                'temp_total' => 5,
                'max_registered_temp' => 10,
                'break_minutes' => 60,
            ]
        );
    }

    private function createApprovedShiftUser(Shift $shift, User $user): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_fixed' => false,
            'is_overtime_allowed' => false,
            'is_absent' => false,
        ]);
    }

    private function createCheckIn(ShiftUser $su, Shift $shift, User $user): CheckIn
    {
        return CheckIn::create([
            'shift_user_id' => $su->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'check_in_time' => $shift->date . ' 09:00:00',
        ]);
    }
}
