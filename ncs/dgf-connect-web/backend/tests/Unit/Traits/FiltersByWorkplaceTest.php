<?php

namespace Tests\Unit\Traits;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiltersByWorkplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test workplaces
        Workplace::create([
            'id' => 1,
            'name' => 'Workplace 1',
            'address' => 'Address 1',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        Workplace::create([
            'id' => 2,
            'name' => 'Workplace 2',
            'address' => 'Address 2',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        Workplace::create([
            'id' => 3,
            'name' => 'Workplace 3',
            'address' => 'Address 3',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        // Create workplace shifts for each workplace
        WorkplaceShift::create([
            'workplace_id' => 1,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        WorkplaceShift::create([
            'workplace_id' => 2,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        WorkplaceShift::create([
            'workplace_id' => 3,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        // Create test permissions
        Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);

        // Create test roles
        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
    }

    protected function createShiftForWorkplace(int $workplaceId): Shift
    {
        $user = User::factory()->create();
        $workplaceShift = WorkplaceShift::where('workplace_id', $workplaceId)->first();

        return Shift::create([
            'workplace_id' => $workplaceId,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
    }

    public function testGlobalAdminCanSeeAllShifts()
    {
        // Create shifts in all workplaces
        $this->createShiftForWorkplace(1);
        $this->createShiftForWorkplace(2);
        $this->createShiftForWorkplace(3);

        // Create GlobalAdmin user
        $admin = User::factory()->create();
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $admin->addRole($role);

        // Query with filter
        $shifts = Shift::query()->applyWorkplaceFilter($admin, 'shift.list')->get();

        $this->assertCount(3, $shifts, 'GlobalAdmin should see all shifts');
    }

    public function testUserWithGlobalPermissionCanSeeAllShifts()
    {
        // Create shifts in all workplaces
        $this->createShiftForWorkplace(1);
        $this->createShiftForWorkplace(2);
        $this->createShiftForWorkplace(3);

        // Create user with global permission
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.list')->first();
        $role->permissions()->attach($permission);
        $user->addRole($role, null); // team_id = null for global access

        // Query with filter
        $shifts = Shift::query()->applyWorkplaceFilter($user, 'shift.list')->get();

        $this->assertCount(3, $shifts, 'User with global permission should see all shifts');
    }

    public function testUserWithWorkplaceScopedPermissionSeesOnlyAccessibleShifts()
    {
        // Create shifts in all workplaces
        $this->createShiftForWorkplace(1);
        $this->createShiftForWorkplace(2);
        $this->createShiftForWorkplace(3);

        // Create user with permission for workplace 1 only
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.list')->first();
        $role->permissions()->attach($permission);
        $user->addRole($role, 1); // Only workplace 1

        // Query with filter
        $shifts = Shift::query()->applyWorkplaceFilter($user, 'shift.list')->get();

        $this->assertCount(1, $shifts, 'User should only see shifts from workplace 1');
        $this->assertEquals(1, $shifts->first()->workplace_id);
    }

    public function testUserWithMultipleWorkplaceScopedPermissions()
    {
        // Create shifts in all workplaces
        $this->createShiftForWorkplace(1);
        $this->createShiftForWorkplace(2);
        $this->createShiftForWorkplace(3);

        // Create user with permission for workplaces 1 and 2
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.list')->first();
        $role->permissions()->attach($permission);
        $user->addRole($role, 1); // Workplace 1
        $user->addRole($role, 2); // Workplace 2

        // Query with filter
        $shifts = Shift::query()->applyWorkplaceFilter($user, 'shift.list')->get();

        $this->assertCount(2, $shifts, 'User should see shifts from workplaces 1 and 2');
        $workplaceIds = $shifts->pluck('workplace_id')->toArray();
        $this->assertContains(1, $workplaceIds);
        $this->assertContains(2, $workplaceIds);
        $this->assertNotContains(3, $workplaceIds);
    }

    public function testUserWithNoPermissionSeesNoShifts()
    {
        // Create shifts in all workplaces
        $this->createShiftForWorkplace(1);
        $this->createShiftForWorkplace(2);
        $this->createShiftForWorkplace(3);

        // Create user with no shift permissions
        $user = User::factory()->create();
        $role = Role::create(['name' => 'no_shift_role', 'display_name' => 'No Shift Role']);
        $user->addRole($role, 1);

        // Query with filter
        $shifts = Shift::query()->applyWorkplaceFilter($user, 'shift.list')->get();

        $this->assertCount(0, $shifts, 'User without permission should see no shifts');
    }

    public function testFilterWorksWithOtherQueryConstraints()
    {
        // Create shifts in workplaces 1 and 2
        $shift1 = $this->createShiftForWorkplace(1);
        $shift3 = $this->createShiftForWorkplace(2);

        // Create user with permission for workplace 1 only
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.list')->first();
        $role->permissions()->attach($permission);
        $user->addRole($role, 1);

        // Query with filter AND additional where clause
        $shifts = Shift::query()->where('id', $shift1->id)->applyWorkplaceFilter($user, 'shift.list')->get();

        $this->assertCount(1, $shifts, 'Should combine workplace filter with other conditions');
        $this->assertEquals($shift1->id, $shifts->first()->id);

        // Query that should return nothing because shift3 is in workplace 2
        $shifts = Shift::query()->where('id', $shift3->id)->applyWorkplaceFilter($user, 'shift.list')->get();

        $this->assertCount(0, $shifts, 'Should not return shifts from inaccessible workplaces');
    }
}
