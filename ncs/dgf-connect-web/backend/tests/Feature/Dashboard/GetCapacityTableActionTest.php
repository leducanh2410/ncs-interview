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

class GetCapacityTableActionTest extends TestCase
{
    use AssertsAggregateOnlyResponse;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

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
        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => 1,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
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

    public function testReturnsFourteenRowsForSingleWorkplace(): void
    {
        // Create a shift for one of the 14 days
        $shift = $this->createShift('2026-05-25');
        $worker = User::factory()->create();
        $this->createApprovedShiftUser($shift, $worker);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/capacity?workplace_ids[]=1');

        $response->assertOk();
        $rows = $response->json('data.rows');

        $this->assertCount(14, $rows);

        // Verify date range covers the next 14 days
        $dates = array_column($rows, 'date');
        sort($dates);
        $this->assertEquals('2026-05-22', $dates[0]);
        $this->assertEquals('2026-06-04', $dates[13]);
    }

    public function testFreeBenchFlooredAtZero(): void
    {
        // Create pool: 2 workers with check-ins in last 90 days
        $workers = User::factory()->count(2)->create();
        foreach ($workers as $worker) {
            $shift = $this->createShift('2026-04-01');
            $su = $this->createApprovedShiftUser($shift, $worker);
            $this->createCheckIn($su, $shift, $worker);
        }

        // Assign 5 users for a future date (more than pool of 2)
        $futureShift = $this->createShift('2026-05-25');
        $assignees = User::factory()->count(5)->create();
        foreach ($assignees as $user) {
            $this->createApprovedShiftUser($futureShift, $user);
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/capacity?workplace_ids[]=1');

        $response->assertOk();
        $rows = collect($response->json('data.rows'));
        $row = $rows->firstWhere('date', '2026-05-25');

        $this->assertNotNull($row);
        $this->assertEquals(0, $row['free_bench']); // max(0, 2 - 5) = 0
        $this->assertEquals(5, $row['assigned']);
        $this->assertEquals(2, $row['active_pool']);
    }

    public function testCapacityTagThresholds(): void
    {
        // Active pool = 10
        $workers = User::factory()->count(10)->create();
        foreach ($workers as $worker) {
            $shift = $this->createShift('2026-04-01');
            $su = $this->createApprovedShiftUser($shift, $worker);
            $this->createCheckIn($su, $shift, $worker);
        }

        // healthy: assign 5 (50% utilization)
        $healthyShift = $this->createShift('2026-05-23');
        foreach (User::factory()->count(5)->create() as $u) {
            $this->createApprovedShiftUser($healthyShift, $u);
        }

        // tight: assign 8 (80% utilization)
        $tightShift = $this->createShift('2026-05-24');
        foreach (User::factory()->count(8)->create() as $u) {
            $this->createApprovedShiftUser($tightShift, $u);
        }

        // critical: assign 10 (100% utilization)
        $criticalShift = $this->createShift('2026-05-25');
        foreach (User::factory()->count(10)->create() as $u) {
            $this->createApprovedShiftUser($criticalShift, $u);
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/capacity?workplace_ids[]=1');

        $response->assertOk();
        $rows = collect($response->json('data.rows'));

        $this->assertEquals('healthy', $rows->firstWhere('date', '2026-05-23')['capacity_tag']);
        $this->assertEquals('tight', $rows->firstWhere('date', '2026-05-24')['capacity_tag']);
        $this->assertEquals('critical', $rows->firstWhere('date', '2026-05-25')['capacity_tag']);
    }

    public function testFreeBenchReturnsNullWhenReliabilityEmpty(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'shift_only', 'display_name' => 'Shift Only']);
        $shiftPerm = Permission::where('name', 'shift.list')->first();
        $role->permissions()->syncWithoutDetaching([$shiftPerm->id]);
        $user->addRole($role, $this->workplace->id);

        $shift = $this->createShift('2026-05-25');
        $worker = User::factory()->create();
        $this->createApprovedShiftUser($shift, $worker);

        $response = $this->actingAs($user, 'api')->getJson('/admin/dashboard/capacity?workplace_ids[]=1');

        $response->assertOk();
        $rows = collect($response->json('data.rows'));
        $row = $rows->firstWhere('date', '2026-05-25');

        $this->assertNotNull($row);
        $this->assertNull($row['active_pool']);
        $this->assertNull($row['free_bench']);
        $this->assertNull($row['utilization']);
        $this->assertNull($row['capacity_tag']);
    }

    public function testResponseShapeContainsNoUserIdentifyingFields(): void
    {
        $shift = $this->createShift('2026-05-25');
        $worker = User::factory()->create();
        $this->createApprovedShiftUser($shift, $worker);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/capacity?workplace_ids[]=1');

        $response->assertOk();
        $this->assertNoUserIdentifyingFields($response->json('data'));
    }

    // One concrete Shift per (date, slot): repeated calls for the same date reuse it so several workers
    // share the shift (the realistic model and what the shifts unique index now enforces).
    private function createShift(string $date): Shift
    {
        return Shift::firstOrCreate(
            [
                'workplace_id' => $this->workplace->id,
                'workplace_shift_id' => $this->workplaceShift->id,
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
