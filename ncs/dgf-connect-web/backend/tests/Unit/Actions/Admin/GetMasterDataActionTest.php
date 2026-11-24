<?php

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\GetMasterDataAction;
use App\Actions\Workplace\ShouldShowTestWorkplacesAction;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetMasterDataActionTest extends TestCase
{
    use RefreshDatabase;

    private GetMasterDataAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new GetMasterDataAction(new ShouldShowTestWorkplacesAction());

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

        // Create test permissions
        Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);
        Permission::create(['name' => 'shift.create', 'display_name' => 'Create Shift']);
        Permission::create(['name' => 'work_history.list', 'display_name' => 'List Work History']);
        Permission::create(['name' => 'work_report.list', 'display_name' => 'List Work Report']);
        Permission::create(['name' => 'check_in.list', 'display_name' => 'List Check In']);
        Permission::create(['name' => 'workplace.list', 'display_name' => 'List Workplace']);

        // Create test roles
        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
    }

    public function testExecuteReturnsSuccessWithAllRequiredFields()
    {
        $user = User::factory()->create();
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $user->addRole($role);

        $result = ($this->action)($user);

        $this->assertNull($result->getError());
        $data = $result->getData();

        $this->assertArrayHasKey('workplaces', $data);
        $this->assertArrayHasKey('roles', $data);
        $this->assertArrayHasKey('workplace_access', $data);
    }

    public function testExecuteReturnsWorkplaceAccessForGlobalAdmin()
    {
        $user = User::factory()->create();
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $user->addRole($role);

        $result = ($this->action)($user);
        $data = $result->getData();
        $workplaceAccess = $data['workplace_access'];

        $this->assertArrayHasKey('shift', $workplaceAccess);
        $this->assertArrayHasKey('work_history', $workplaceAccess);
        $this->assertArrayHasKey('work_report', $workplaceAccess);
        $this->assertArrayHasKey('check_in', $workplaceAccess);
        $this->assertArrayHasKey('workplace', $workplaceAccess);

        // GlobalAdmin should have null (global access) for all permissions
        $this->assertNull($workplaceAccess['shift']);
        $this->assertNull($workplaceAccess['shift_check_in']);
        $this->assertNull($workplaceAccess['work_history']);
        $this->assertNull($workplaceAccess['work_report']);
        $this->assertNull($workplaceAccess['check_in']);
        $this->assertNull($workplaceAccess['workplace']);
    }

    public function testExecuteReturnsWorkplaceAccessForWorkplaceScopedUser()
    {
        $user = User::factory()->create();

        // Create role with shift permissions
        $shiftRole = Role::where('name', 'shift_manager')->first();
        $shiftPermission = Permission::where('name', 'shift.list')->first();
        $shiftRole->permissions()->attach($shiftPermission);

        // Create another role with work_history permissions
        $workHistoryRole = Role::create(['name' => 'work_history_viewer', 'display_name' => 'Work History Viewer']);
        $workHistoryPermission = Permission::where('name', 'work_history.list')->first();
        $workHistoryRole->permissions()->attach($workHistoryPermission);

        // Assign shift role to workplaces 1 and 2
        $user->addRole($shiftRole, 1);
        $user->addRole($shiftRole, 2);

        // Assign work_history role to workplace 3
        $user->addRole($workHistoryRole, 3);

        $result = ($this->action)($user);
        $data = $result->getData();
        $workplaceAccess = $data['workplace_access'];

        // User should have access to workplaces 1 and 2 for shift
        $this->assertIsArray($workplaceAccess['shift']);
        $this->assertCount(2, $workplaceAccess['shift']);
        $this->assertContains(1, $workplaceAccess['shift']);
        $this->assertContains(2, $workplaceAccess['shift']);
        // shift.list does not grant shift.check_in
        $this->assertIsArray($workplaceAccess['shift_check_in']);
        $this->assertEmpty($workplaceAccess['shift_check_in']);

        // User should have access to workplace 3 for work_history
        $this->assertIsArray($workplaceAccess['work_history']);
        $this->assertCount(1, $workplaceAccess['work_history']);
        $this->assertContains(3, $workplaceAccess['work_history']);

        // User should have no access to work_report, check_in, and workplace
        $this->assertIsArray($workplaceAccess['work_report']);
        $this->assertEmpty($workplaceAccess['work_report']);
        $this->assertIsArray($workplaceAccess['check_in']);
        $this->assertEmpty($workplaceAccess['check_in']);
        $this->assertIsArray($workplaceAccess['workplace']);
        $this->assertEmpty($workplaceAccess['workplace']);
    }

    public function testExecuteReturnsShiftCheckInSeparateFromShiftPrefix(): void
    {
        Permission::create(['name' => 'shift.check_in', 'display_name' => 'Shift Check In']);
        $checkInRole = Role::create(['name' => 'check_in_manager', 'display_name' => 'Check In Manager']);
        $checkInRole->permissions()->attach(Permission::where('name', 'shift.check_in')->first());

        $shiftRole = Role::where('name', 'shift_manager')->first();
        $shiftRole->permissions()->attach(Permission::where('name', 'shift.list')->first());

        $user = User::factory()->create();
        $user->addRole($shiftRole, 1);
        $user->addRole($checkInRole, 2);

        $workplaceAccess = ($this->action)($user)->getData()['workplace_access'];

        $this->assertEqualsCanonicalizing([1, 2], $workplaceAccess['shift']);
        $this->assertEquals([2], $workplaceAccess['shift_check_in']);
    }

    public function testExecuteReturnsWorkplaceAccessWithMixedAccess()
    {
        $user = User::factory()->create();

        // Create role with shift permissions
        $shiftRole = Role::where('name', 'shift_manager')->first();
        $shiftPermission = Permission::where('name', 'shift.list')->first();
        $shiftRole->permissions()->attach($shiftPermission);

        // Create another role with work_history permissions
        $workHistoryRole = Role::create(['name' => 'work_history_viewer', 'display_name' => 'Work History Viewer']);
        $workHistoryPermission = Permission::where('name', 'work_history.list')->first();
        $workHistoryRole->permissions()->attach($workHistoryPermission);

        // Assign shift role to specific workplaces
        $user->addRole($shiftRole, 1);
        $user->addRole($shiftRole, 2);

        // Assign work_history role globally (team_id = null)
        $user->addRole($workHistoryRole, null);

        $result = ($this->action)($user);
        $data = $result->getData();
        $workplaceAccess = $data['workplace_access'];

        // User should have workplace-scoped access for shift
        $this->assertIsArray($workplaceAccess['shift']);
        $this->assertCount(2, $workplaceAccess['shift']);

        // User should have global access (null) for work_history
        $this->assertNull($workplaceAccess['work_history']);
    }

    public function testExecuteReturnsWorkplaceAccessForUserWithNoPermissions()
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'admin')->first();
        // Don't attach any permissions to role
        $user->addRole($role, 1);

        $result = ($this->action)($user);
        $data = $result->getData();
        $workplaceAccess = $data['workplace_access'];

        // User should have empty arrays for all permission groups
        $this->assertIsArray($workplaceAccess['shift']);
        $this->assertEmpty($workplaceAccess['shift']);
        $this->assertIsArray($workplaceAccess['shift_check_in']);
        $this->assertEmpty($workplaceAccess['shift_check_in']);
        $this->assertIsArray($workplaceAccess['work_history']);
        $this->assertEmpty($workplaceAccess['work_history']);
        $this->assertIsArray($workplaceAccess['work_report']);
        $this->assertEmpty($workplaceAccess['work_report']);
        $this->assertIsArray($workplaceAccess['check_in']);
        $this->assertEmpty($workplaceAccess['check_in']);
        $this->assertIsArray($workplaceAccess['workplace']);
        $this->assertEmpty($workplaceAccess['workplace']);
    }

    public function testExecuteExcludesTestWorkplacesByDefault()
    {
        Workplace::create([
            'id' => 100,
            'name' => 'Test Workplace',
            'address' => 'Address 100',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'is_test' => true,
        ]);

        config()->set('app.show_test_workplaces', false);

        $user = User::factory()->create();
        $user->addRole(Role::where('name', RoleName::GlobalAdmin->value)->first());

        $workplaceIds = ($this->action)($user)->getData()['workplaces']->pluck('id')->all();

        $this->assertNotContains(100, $workplaceIds);
        $this->assertContains(1, $workplaceIds);
    }

    public function testExecuteIncludesTestWorkplacesWhenEnabled()
    {
        Workplace::create([
            'id' => 100,
            'name' => 'Test Workplace',
            'address' => 'Address 100',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'is_test' => true,
        ]);

        config()->set('app.show_test_workplaces', true);

        $user = User::factory()->create();
        $user->addRole(Role::where('name', RoleName::GlobalAdmin->value)->first());

        $workplaceIds = ($this->action)($user)->getData()['workplaces']->pluck('id')->all();

        $this->assertContains(100, $workplaceIds);
    }

    public function testExecuteIncludesTestWorkplacesForTestAdminEvenWhenDisabled()
    {
        Workplace::create([
            'id' => 100,
            'name' => 'Test Workplace',
            'address' => 'Address 100',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'is_test' => true,
        ]);

        config()->set('app.show_test_workplaces', false);

        $user = User::factory()->create(['is_test' => true]);
        $user->addRole(Role::where('name', RoleName::GlobalAdmin->value)->first());

        $workplaceIds = ($this->action)($user)->getData()['workplaces']->pluck('id')->all();

        $this->assertContains(100, $workplaceIds);
    }
}
