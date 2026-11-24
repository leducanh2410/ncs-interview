<?php

namespace Tests\Unit\Checkers;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Test suite for WorkplaceAwareUserChecker.
 *
 * This test suite verifies that the custom checker correctly handles global permissions
 * (team_id = null) as granting access to all workplaces, while maintaining proper scoping
 * for workplace-specific permissions.
 */
class WorkplaceAwareUserCheckerTest extends TestCase
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
        Permission::create(['name' => 'shift.create', 'display_name' => 'Create Shift']);
        Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);
        Permission::create(['name' => 'shift.update', 'display_name' => 'Update Shift']);
        Permission::create(['name' => 'work_history.list', 'display_name' => 'List Work History']);

        // Create test roles
        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
    }

    /**
     * Test 3.3: Global permission grants access to specific workplace.
     *
     * When a user has a permission with team_id = null (global access),
     * isAbleTo($permission, $workplaceId) should return true for any workplace.
     */
    public function testGlobalPermissionGrantsAccessToSpecificWorkplace()
    {
        $user = User::factory()->create();
        $permission = Permission::where('name', 'shift.create')->first();

        // Attach permission directly to user with team_id = NULL (global access)
        $user->givePermission($permission, null);

        // User should have access to any specific workplace
        $this->assertTrue($user->isAbleTo('shift.create', 1), 'Global permission should grant access to workplace 1');
        $this->assertTrue($user->isAbleTo('shift.create', 2), 'Global permission should grant access to workplace 2');
        $this->assertTrue($user->isAbleTo('shift.create', 3), 'Global permission should grant access to workplace 3');

        // User should also have access when checking without workplace context
        $this->assertTrue(
            $user->isAbleTo('shift.create'),
            'Global permission should grant access without workplace context'
        );
    }

    /**
     * Test 3.4: Workplace-scoped permission only grants access to that workplace.
     *
     * When a user has a permission scoped to a specific workplace (team_id = X),
     * isAbleTo($permission, $workplaceId) should only return true for that workplace.
     */
    public function testWorkplaceScopedPermissionOnlyGrantsAccessToThatWorkplace()
    {
        $user = User::factory()->create();
        $permission = Permission::where('name', 'shift.create')->first();

        // Attach permission to user for workplace 1 only
        $user->givePermission($permission, 1);

        // User should have access to workplace 1
        $this->assertTrue(
            $user->isAbleTo('shift.create', 1),
            'Workplace-scoped permission should grant access to workplace 1'
        );

        // User should NOT have access to other workplaces
        $this->assertFalse(
            $user->isAbleTo('shift.create', 2),
            'Workplace-scoped permission should NOT grant access to workplace 2'
        );
        $this->assertFalse(
            $user->isAbleTo('shift.create', 3),
            'Workplace-scoped permission should NOT grant access to workplace 3'
        );
    }

    /**
     * Test 3.5: User with multiple workplace-scoped permissions.
     *
     * When a user has the same permission assigned to multiple workplaces,
     * they should have access to all those workplaces but not others.
     */
    public function testUserWithMultipleWorkplaceScopedPermissions()
    {
        $user = User::factory()->create();
        $permission = Permission::where('name', 'shift.create')->first();

        // Attach permission to user for workplaces 1 and 2
        $user->givePermission($permission, 1);
        $user->givePermission($permission, 2);

        // User should have access to workplaces 1 and 2
        $this->assertTrue($user->isAbleTo('shift.create', 1), 'User should have access to workplace 1');
        $this->assertTrue($user->isAbleTo('shift.create', 2), 'User should have access to workplace 2');

        // User should NOT have access to workplace 3
        $this->assertFalse($user->isAbleTo('shift.create', 3), 'User should NOT have access to workplace 3');
    }

    /**
     * Test 3.6: Mixed global and scoped permissions on same user.
     *
     * When a user has global access to one permission and scoped access to another,
     * each permission should behave according to its scope.
     */
    public function testMixedGlobalAndScopedPermissionsOnSameUser()
    {
        $user = User::factory()->create();
        $shiftCreate = Permission::where('name', 'shift.create')->first();
        $shiftList = Permission::where('name', 'shift.list')->first();

        // Global access to shift.create
        $user->givePermission($shiftCreate, null);

        // Scoped access to shift.list for workplace 1 only
        $user->givePermission($shiftList, 1);

        // shift.create should work for all workplaces (global)
        $this->assertTrue($user->isAbleTo('shift.create', 1), 'Global shift.create should work for workplace 1');
        $this->assertTrue($user->isAbleTo('shift.create', 2), 'Global shift.create should work for workplace 2');

        // shift.list should only work for workplace 1 (scoped)
        $this->assertTrue($user->isAbleTo('shift.list', 1), 'Scoped shift.list should work for workplace 1');
        $this->assertFalse($user->isAbleTo('shift.list', 2), 'Scoped shift.list should NOT work for workplace 2');
    }

    /**
     * Test 3.7: Array of permissions with global access.
     *
     * When checking multiple permissions at once using an array,
     * the checker should correctly evaluate each permission's scope.
     */
    public function testArrayOfPermissionsWithGlobalAccess()
    {
        $user = User::factory()->create();
        $shiftCreate = Permission::where('name', 'shift.create')->first();
        $shiftList = Permission::where('name', 'shift.list')->first();

        // Give global access to both permissions
        $user->givePermission($shiftCreate, null);
        $user->givePermission($shiftList, null);

        // Test with requireAll = false (any permission)
        $this->assertTrue(
            $user->isAbleTo(['shift.create', 'shift.list'], 1, false),
            'User should have at least one of the permissions for workplace 1'
        );

        // Test with requireAll = true (all permissions)
        $this->assertTrue(
            $user->isAbleTo(['shift.create', 'shift.list'], 1, true),
            'User should have all permissions for workplace 1'
        );

        // Test with one missing permission
        $this->assertFalse(
            $user->isAbleTo(['shift.create', 'shift.update'], 1, true),
            'User should NOT have all permissions (missing shift.update)'
        );

        // Test with requireAll = false and one valid permission
        $this->assertTrue(
            $user->isAbleTo(['shift.create', 'shift.update'], 1, false),
            'User should have at least one permission (shift.create)'
        );
    }

    /**
     * Test 3.8: GlobalAdmin role with global permissions.
     *
     * A user with GlobalAdmin role that has permissions attached
     * should have access to those permissions across all workplaces.
     */
    public function testGlobalAdminRoleWithGlobalPermissions()
    {
        $user = User::factory()->create();
        $globalAdminRole = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $shiftCreate = Permission::where('name', 'shift.create')->first();
        $shiftList = Permission::where('name', 'shift.list')->first();
        $workHistoryList = Permission::where('name', 'work_history.list')->first();

        // Attach permissions to GlobalAdmin role
        $globalAdminRole->permissions()->attach([$shiftCreate->id, $shiftList->id, $workHistoryList->id]);

        // Assign GlobalAdmin role to user (globally)
        $user->addRole($globalAdminRole);

        // GlobalAdmin with permissions should have access to all workplaces
        $this->assertTrue(
            $user->isAbleTo('shift.create', 1),
            'GlobalAdmin should have access to shift.create for workplace 1'
        );
        $this->assertTrue(
            $user->isAbleTo('shift.list', 2),
            'GlobalAdmin should have access to shift.list for workplace 2'
        );
        $this->assertTrue(
            $user->isAbleTo('work_history.list', 3),
            'GlobalAdmin should have access to work_history.list for workplace 3'
        );

        // Test with array of permissions
        $this->assertTrue(
            $user->isAbleTo(['shift.create', 'shift.list'], 1, true),
            'GlobalAdmin should have all assigned permissions'
        );

        // Verify GlobalAdmin role is detected correctly
        $this->assertTrue($user->hasRole(RoleName::GlobalAdmin->value), 'User should have GlobalAdmin role');
    }

    /**
     * Test 3.9: Cache invalidation after role changes.
     *
     * After modifying a user's roles or permissions, the cache should be
     * properly invalidated and subsequent checks should reflect the changes.
     */
    public function testCacheInvalidationAfterRoleChanges()
    {
        $user = User::factory()->create();
        $permission = Permission::where('name', 'shift.create')->first();
        $role = Role::where('name', 'shift_manager')->first();
        $role->permissions()->attach($permission);

        // Initially, user has no access
        $this->assertFalse($user->isAbleTo('shift.create', 1), 'User should NOT have access initially');

        // Grant role with global access
        $user->addRole($role, null);

        // Clear cache manually to ensure fresh check
        Cache::tags('laratrust')->flush();

        // After adding global role, user should have access
        $this->assertTrue($user->isAbleTo('shift.create', 1), 'User should have access after adding global role');
        $this->assertTrue($user->isAbleTo('shift.create', 2), 'User should have access to all workplaces');

        // Remove the role
        $user->removeRole($role);

        // Clear cache again
        Cache::tags('laratrust')->flush();

        // After removing role, user should lose access
        $this->assertFalse($user->isAbleTo('shift.create', 1), 'User should NOT have access after removing role');
    }

    /**
     * Additional test: Global role grants access via role permissions.
     *
     * When a user has a role assigned globally (team_id = null),
     * they should have access to all permissions that the role has,
     * for any workplace.
     */
    public function testGlobalRoleGrantsAccessViaRolePermissions()
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.create')->first();

        // Attach permission to role
        $role->permissions()->attach($permission);

        // Assign role to user globally (team_id = null)
        $user->addRole($role, null);

        // User should have access to permission via global role for any workplace
        $this->assertTrue($user->isAbleTo('shift.create', 1), 'Global role should grant access to workplace 1');
        $this->assertTrue($user->isAbleTo('shift.create', 2), 'Global role should grant access to workplace 2');
        $this->assertTrue($user->isAbleTo('shift.create', 3), 'Global role should grant access to workplace 3');
    }

    /**
     * Additional test: Workplace-scoped role only grants access to that workplace.
     *
     * When a user has a role assigned to a specific workplace (team_id = X),
     * they should only have access to that workplace for the role's permissions.
     */
    public function testWorkplaceScopedRoleOnlyGrantsAccessToThatWorkplace()
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.create')->first();

        // Attach permission to role
        $role->permissions()->attach($permission);

        // Assign role to user for workplace 1 only
        $user->addRole($role, 1);

        // User should have access to workplace 1
        $this->assertTrue(
            $user->isAbleTo('shift.create', 1),
            'Workplace-scoped role should grant access to workplace 1'
        );

        // User should NOT have access to other workplaces
        $this->assertFalse(
            $user->isAbleTo('shift.create', 2),
            'Workplace-scoped role should NOT grant access to workplace 2'
        );
        $this->assertFalse(
            $user->isAbleTo('shift.create', 3),
            'Workplace-scoped role should NOT grant access to workplace 3'
        );
    }

    /**
     * Additional test: User with both global and scoped roles.
     *
     * When a user has a role assigned both globally and to specific workplaces,
     * the global assignment should take precedence.
     */
    public function testUserWithBothGlobalAndScopedRoles()
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'shift_manager')->first();
        $permission = Permission::where('name', 'shift.create')->first();

        // Attach permission to role
        $role->permissions()->attach($permission);

        // Assign role to user for workplace 1 (scoped)
        $user->addRole($role, 1);

        // User should only have access to workplace 1 at this point
        $this->assertTrue($user->isAbleTo('shift.create', 1));
        $this->assertFalse($user->isAbleTo('shift.create', 2));

        // Now also assign the same role globally
        $user->addRole($role, null);

        // Clear cache
        Cache::tags('laratrust')->flush();

        // User should now have access to all workplaces
        $this->assertTrue($user->isAbleTo('shift.create', 1), 'User should have access to workplace 1');
        $this->assertTrue($user->isAbleTo('shift.create', 2), 'User should have access to workplace 2 via global role');
        $this->assertTrue($user->isAbleTo('shift.create', 3), 'User should have access to workplace 3 via global role');
    }
}
