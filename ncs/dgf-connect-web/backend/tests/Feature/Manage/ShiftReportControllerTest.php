<?php

namespace Tests\Feature\Manage;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Shift $shift;

    private ShiftUser $shiftUser;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 20:00:00');

        $this->manager = User::factory()->create();
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $permission = Permission::firstOrCreate(
            ['name' => PermissionName::WorkReportList->value],
            ['display_name' => 'Work Report List']
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->manager->addRole($role);

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

        $position = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $this->shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);

        $this->employee = User::factory()->create();
        $this->shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // index (GET /manage/reports)
    // -------------------------------------------------------------------------

    public function testShiftReportIndexSuccess(): void
    {
        ShiftReport::create([
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'date' => '2026-05-10',
            'late_minutes' => 10,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/reports');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals(10, $response->json('data.data.0.late_minutes'));
    }

    public function testShiftReportIndexReturnsEmptyWhenNoReports(): void
    {
        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/reports');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }

    public function testShiftReportIndexForbiddenWithoutPermission(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->getJson('/manage/reports');

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // getMonthlyReport (GET /manage/reports/monthly)
    // -------------------------------------------------------------------------

    public function testGetMonthlyReportSuccess(): void
    {
        ShiftReport::create([
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'date' => '2026-05-10',
            'late_minutes' => 10,
            'early_minutes' => 5,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/reports/monthly?month=2026-05');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals(10, $response->json('data.data.0.late_minutes'));
        $this->assertEquals(5, $response->json('data.data.0.early_minutes'));
    }

    public function testGetMonthlyReportDefaultsToCurrentMonth(): void
    {
        ShiftReport::create([
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'date' => '2026-05-10',
            'late_minutes' => 15,
        ]);

        // No month param — should default to current month (2026-05)
        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/reports/monthly');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function testGetMonthlyReportReturnsEmptyForDifferentMonth(): void
    {
        ShiftReport::create([
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'date' => '2026-05-10',
            'late_minutes' => 10,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/reports/monthly?month=2026-04');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }

    public function testGetMonthlyReportForbiddenWithoutPermission(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->getJson('/manage/reports/monthly?month=2026-05');

        $response->assertStatus(403);
    }
}
