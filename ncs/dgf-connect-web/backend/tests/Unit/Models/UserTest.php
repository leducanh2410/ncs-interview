<?php

namespace Tests\Unit\Models;

use App\Enums\Nationality;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
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

        // Create test permissions
        Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);
        Permission::create(['name' => 'shift.create', 'display_name' => 'Create Shift']);
        Permission::create(['name' => 'work_history.list', 'display_name' => 'List Work History']);
        Permission::create(['name' => 'work_report.list', 'display_name' => 'List Work Report']);

        // Create test roles
        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
    }

    public function testGlobalAdminHasAccessToAllWorkplaces()
    {
        $user = User::factory()->create();
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $user->addRole($role);

        $workplaceIds = $user->getWorkplaceIdsByPermission('shift.list');
        $this->assertNull($workplaceIds, 'GlobalAdmin should have access to all workplaces (null)');

        $canAccessAll = $user->canAccessAllWorkplaces('shift.list');
        $this->assertTrue($canAccessAll, 'GlobalAdmin should be able to access all workplaces');
    }

    public function testUserWithGlobalPermissionHasAccessToAllWorkplaces()
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'admin')->first();
        $permission = Permission::where('name', 'shift.list')->first();

        // Attach permission to role
        $role->permissions()->attach($permission);

        // Attach role to user with team_id = NULL (global access)
        $user->addRole($role, null);

        $workplaceIds = $user->getWorkplaceIdsByPermission('shift.list');
        $this->assertNull($workplaceIds, 'User with global permission should have access to all workplaces (null)');

        $canAccessAll = $user->canAccessAllWorkplaces('shift.list');
        $this->assertTrue($canAccessAll, 'User with global permission should be able to access all workplaces');
    }

    public function testUserWithWorkplaceScopedPermissionHasLimitedAccess()
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.list')->first();

        // Attach permission to role
        $role->permissions()->attach($permission);

        // Attach role to user for workplace 1
        $user->addRole($role, 1);

        $workplaceIds = $user->getWorkplaceIdsByPermission('shift.list');
        $this->assertIsArray($workplaceIds);
        $this->assertEquals([1], $workplaceIds, 'User should only have access to workplace 1');

        $canAccessAll = $user->canAccessAllWorkplaces('shift.list');
        $this->assertFalse(
            $canAccessAll,
            'User with workplace-scoped permission should not have access to all workplaces'
        );
    }

    public function testUserWithMultipleWorkplaceScopedPermissions()
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.list')->first();

        // Attach permission to role
        $role->permissions()->attach($permission);

        // Attach role to user for workplaces 1 and 2
        $user->addRole($role, 1);
        $user->addRole($role, 2);

        $workplaceIds = $user->getWorkplaceIdsByPermission('shift.list');
        $this->assertIsArray($workplaceIds);
        $this->assertCount(2, $workplaceIds);
        $this->assertContains(1, $workplaceIds);
        $this->assertContains(2, $workplaceIds);
    }

    public function testUserWithNoPermissionHasNoAccess()
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'admin')->first();
        // Don't attach any permissions to role
        $user->addRole($role, 1);

        $workplaceIds = $user->getWorkplaceIdsByPermission('shift.list');
        $this->assertIsArray($workplaceIds);
        $this->assertEmpty($workplaceIds, 'User without permission should have empty array');

        $canAccessAll = $user->canAccessAllWorkplaces('shift.list');
        $this->assertFalse($canAccessAll, 'User without permission should not have access to all workplaces');
    }

    public function testCanAccessWorkplaceHonoursGlobalAndScopedPermissions(): void
    {
        $global = User::factory()->create();
        $global->addRole(Role::where('name', RoleName::GlobalAdmin->value)->first());
        $this->assertTrue($global->canAccessWorkplace('shift.list', 99));

        $scoped = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $role->permissions()->attach(Permission::where('name', 'shift.list')->first());
        $scoped->addRole($role, 1);

        $this->assertTrue($scoped->canAccessWorkplace('shift.list', 1));
        $this->assertFalse($scoped->canAccessWorkplace('shift.list', 2));
    }

    public function testCanAccessWorkplaceHonoursGlobalAdminAssignedToAWorkplace(): void
    {
        $user = User::factory()->create();
        $user->addRole(Role::where('name', RoleName::GlobalAdmin->value)->first(), 1);

        $this->assertNull($user->getWorkplaceIdsByPermission('shift.list'));
        $this->assertTrue($user->canAccessAllWorkplaces('shift.list'));
        $this->assertTrue($user->canAccessWorkplace('shift.list', 99));
    }

    public function testCanAccessWorkplaceHonoursDirectPermission(): void
    {
        $user = User::factory()->create();
        $permission = Permission::where('name', 'shift.list')->first();
        $user->givePermission($permission, 1);

        $this->assertTrue($user->canAccessWorkplace('shift.list', 1));
        $this->assertFalse($user->canAccessWorkplace('shift.list', 2));
    }

    public function testHasUnrestrictedWorkplaceAccessHonoursDirectGlobalPermission(): void
    {
        $user = User::factory()->create();
        $permission = Permission::where('name', 'shift.list')->first();
        $user->givePermission($permission, null);

        $this->assertFalse($user->canAccessAllWorkplaces('shift.list'));
        $this->assertTrue($user->hasUnrestrictedWorkplaceAccess('shift.list'));

        $scoped = User::factory()->create();
        $scoped->givePermission($permission, 1);
        $this->assertFalse($scoped->hasUnrestrictedWorkplaceAccess('shift.list'));
    }

    public function testGetAccessibleWorkplacesByPermissionGroupForGlobalAdmin()
    {
        $user = User::factory()->create();
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $user->addRole($role);

        $result = $user->getAccessibleWorkplacesByPermissionGroup(['shift', 'work_history', 'work_report']);

        $this->assertArrayHasKey('shift', $result);
        $this->assertArrayHasKey('work_history', $result);
        $this->assertArrayHasKey('work_report', $result);
        $this->assertNull($result['shift']);
        $this->assertNull($result['work_history']);
        $this->assertNull($result['work_report']);
    }

    public function testGetAccessibleWorkplacesByPermissionGroupForWorkplaceScopedUser()
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

        $result = $user->getAccessibleWorkplacesByPermissionGroup(['shift', 'work_history', 'work_report']);

        $this->assertIsArray($result['shift']);
        $this->assertCount(2, $result['shift']);
        $this->assertContains(1, $result['shift']);
        $this->assertContains(2, $result['shift']);

        $this->assertIsArray($result['work_history']);
        $this->assertCount(1, $result['work_history']);
        $this->assertContains(3, $result['work_history']);

        $this->assertIsArray($result['work_report']);
        $this->assertEmpty($result['work_report'], 'User should have no access to work_report');
    }

    public function testGetAccessibleWorkplacesByPermissionGroupWithMixedAccess()
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

        $result = $user->getAccessibleWorkplacesByPermissionGroup(['shift', 'work_history']);

        $this->assertIsArray($result['shift']);
        $this->assertCount(2, $result['shift']);

        $this->assertNull($result['work_history'], 'User should have global access to work_history');
    }

    public function testNameIsAlwaysTheFullNameRegardlessOfNationality()
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        // UserFactory::configure() already creates a UserInfo with a random
        // nationality; update it in place rather than creating a second row.
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $reloaded = User::with('info')->find($user->id);

        $this->assertSame('KARNASOORIYA RAGALAGE THARINDU RAVISHANN', $reloaded->name);
    }

    public function testShortNameIsShortenedForSriLankaNationality()
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $reloaded = User::with('info')->find($user->id);

        $this->assertSame('THARINDU RAVISHANN', $reloaded->short_name);
    }

    public function testShortNameIsUnchangedForNonSriLankaNationality()
    {
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
        ]);
        $user->info->update(['nationality' => Nationality::Vietnam->value]);

        $reloaded = User::with('info')->find($user->id);

        $this->assertSame('YAMADA TARO', $reloaded->short_name);
    }

    public function testShortNameIsUnchangedWhenUserHasNoInfo()
    {
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
        ]);
        $user->info->delete();

        $reloaded = User::with('info')->find($user->id);

        $this->assertNull($reloaded->info);
        $this->assertSame('YAMADA TARO', $reloaded->short_name);
    }

    public function testShortNameShortensEvenWhenInfoRelationIsNotEagerLoaded()
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        // Unlike `name`, `short_name` is an explicit opt-in accessor (not in
        // $appends), so it lazy-loads `info` on demand rather than silently
        // falling back to the full name.
        $reloaded = $user->fresh();

        $this->assertFalse($reloaded->relationLoaded('info'));
        $this->assertSame('THARINDU RAVISHANN', $reloaded->short_name);
    }

    public function testToArrayNameUsesShortNameForSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $reloaded = User::find($user->id);

        $this->assertSame('THARINDU RAVISHANN', $reloaded->toArray()['name']);
    }

    public function testToArrayNameFallsBackToFullNameWhenShortNameColumnNotSelected(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        // A select() that omits short_name (e.g. `select('id', 'first_name', 'last_name')`
        // on a relation) reads as an unset attribute, not null, so this must not be
        // mistaken for a non-Sri-Lankan user — it should silently fall back to the
        // full name rather than error or emit an empty name.
        $reloaded = User::query()
            ->select(['id', 'first_name', 'last_name'])
            ->find($user->id);

        $this->assertSame('KARNASOORIYA RAGALAGE THARINDU RAVISHANN', $reloaded->toArray()['name']);
    }
}
