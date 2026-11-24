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

class GetDemandHeatmapActionTest extends TestCase
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

    public function testAveragesCheckInsAcrossOccurrences(): void
    {
        $workers = User::factory()->count(3)->create();

        // Monday (2026-05-18): 3 check-ins on morning shift
        $shift1 = $this->createShift('2026-05-18', $this->morningShift);
        foreach ($workers as $w) {
            $su = $this->createApprovedShiftUser($shift1, $w);
            $this->createCheckIn($su, $shift1, $w);
        }

        // Another Monday (2026-05-11): 1 check-in on morning shift
        $shift2 = $this->createShift('2026-05-11', $this->morningShift);
        $su2 = $this->createApprovedShiftUser($shift2, $workers[0]);
        $this->createCheckIn($su2, $shift2, $workers[0]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/dashboard/demand-heatmap?window=90&mode=groups'
        );

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals('groups', $data['mode']);
        $this->assertContains('Morning', $data['slots']);

        // Average for Monday (index 0) on Morning group = (3+1)/2 = 2
        $this->assertEquals(2, $data['cells']['Morning'][0]);
    }

    public function testReturnsNullForEmptyDayOfWeekSlotPair(): void
    {
        // Only create a shift on Monday morning
        $worker = User::factory()->create();
        $shift = $this->createShift('2026-05-18', $this->morningShift); // Monday
        $su = $this->createApprovedShiftUser($shift, $worker);
        $this->createCheckIn($su, $shift, $worker);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/dashboard/demand-heatmap?window=90&mode=groups'
        );

        $response->assertOk();
        $data = $response->json('data');

        // Tuesday (index 1) should be null for Morning group
        $this->assertNull($data['cells']['Morning'][1]);
    }

    public function testTopSlotsModeCollapsesIdenticalLabels(): void
    {
        // Create a second workplace with same shift times
        $wp2 = Workplace::create([
            'id' => 2,
            'name' => 'WP2',
            'address' => 'B',
            'latitude' => 35.6,
            'longitude' => 139.6,
        ]);
        $ws2 = WorkplaceShift::create([
            'workplace_id' => 2,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 0,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 0,
        ]);

        $workers = User::factory()->count(2)->create();

        // WP1 Monday: 2 check-ins
        $shift1 = $this->createShift('2026-05-18', $this->morningShift);
        foreach ($workers as $w) {
            $su = $this->createApprovedShiftUser($shift1, $w);
            $this->createCheckIn($su, $shift1, $w);
        }

        // WP2 Monday: 4 check-ins
        $shift2 = Shift::create([
            'workplace_id' => $wp2->id,
            'workplace_shift_id' => $ws2->id,
            'creator_id' => $this->admin->id,
            'date' => '2026-05-18',
            'adjusted_date' => '2026-05-18',
            'fixed_total' => 5,
            'temp_total' => 5,
            'max_registered_temp' => 10,
            'break_minutes' => 60,
        ]);
        $moreWorkers = User::factory()->count(4)->create();
        foreach ($moreWorkers as $w) {
            $su = $this->createApprovedShiftUser($shift2, $w);
            $this->createCheckIn($su, $shift2, $w);
        }

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/dashboard/demand-heatmap?window=90&mode=top_slots&limit=12'
        );

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals('top_slots', $data['mode']);
        // Both workplaces share '09:00–18:00' label; averaged: (2+4)/2 = 3
        $this->assertContains('09:00–18:00', $data['slots']);
        $this->assertEquals(3, $data['cells']['09:00–18:00'][0]); // Monday
    }

    public function testResponseShapeContainsNoUserIdentifyingFields(): void
    {
        $worker = User::factory()->create();
        $shift = $this->createShift('2026-05-18', $this->morningShift);
        $su = $this->createApprovedShiftUser($shift, $worker);
        $this->createCheckIn($su, $shift, $worker);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/dashboard/demand-heatmap?window=90&mode=groups'
        );

        $response->assertOk();
        $this->assertNoUserIdentifyingFields($response->json('data'));
    }

    private function createShift(string $date, WorkplaceShift $ws): Shift
    {
        return Shift::create([
            'workplace_id' => $ws->workplace_id,
            'workplace_shift_id' => $ws->id,
            'creator_id' => $this->admin->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 5,
            'temp_total' => 5,
            'max_registered_temp' => 10,
            'break_minutes' => 60,
        ]);
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
