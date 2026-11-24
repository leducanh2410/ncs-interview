<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\AssignRolesAction;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AssignRolesActionTest extends TestCase
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

        // Create test roles
        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        Role::create(['name' => RoleName::Admin->value, 'display_name' => 'Admin']);
        Role::create(['name' => RoleName::User->value, 'display_name' => 'User']);
        Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);

        // Create test permission
        Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);
    }

    public function testAssignRoleToUserWithoutWorkplace()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        $action = new AssignRolesAction();
        $result = $action($user->id, 'admin', null);

        $this->assertNull($result->getError());
        $this->assertTrue($user->fresh()->hasRole('admin'));

        // Check that the role was assigned globally (team_id = null)
        $roleAssignment = $user->roles()->first()->pivot;
        $this->assertNull($roleAssignment->team_id);
    }

    public function testAssignRoleToUserWithWorkplace()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        $action = new AssignRolesAction();
        $result = $action($user->id, 'shift_manager', 1);

        $this->assertNull($result->getError());
        $this->assertTrue($user->fresh()->hasRole('shift_manager'));

        // Check that the role was assigned with workplace_id = 1
        $roleAssignment = $user->roles()->first()->pivot;
        $this->assertEquals(1, $roleAssignment->team_id);
    }

    public function testAssignMultipleRolesToSameUser()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        $action = new AssignRolesAction();

        // Assign shift_manager for workplace 1
        $result1 = $action($user->id, 'shift_manager', 1);
        $this->assertNull($result1->getError());

        // Assign admin role globally
        $result2 = $action($user->id, 'admin', null);
        $this->assertNull($result2->getError());

        // User should have both roles
        $user = $user->fresh();
        $this->assertTrue($user->hasRole('shift_manager'));
        $this->assertTrue($user->hasRole('admin'));
        $this->assertCount(2, $user->roles);
    }

    public function testCannotAssignRoleToSelf()
    {
        $admin = User::factory()->create();
        Auth::login($admin);

        $action = new AssignRolesAction();
        $result = $action($admin->id, 'admin', null);

        $this->assertNotNull($result->getError());
        $this->assertEquals(ErrorCode::UserCannotAssignRoleSelf, $result->getError());
        $this->assertEquals(403, $result->getStatus());
    }

    public function testAssignRoleWithInvalidRoleName()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        $action = new AssignRolesAction();
        $result = $action($user->id, 'invalid_role', null);

        $this->assertNotNull($result->getError());
        $this->assertEquals(ErrorCode::RoleNotFound, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testAssignRoleWithInvalidWorkplaceId()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        $action = new AssignRolesAction();
        $result = $action($user->id, 'shift_manager', 999);

        $this->assertNotNull($result->getError());
        $this->assertEquals(ErrorCode::WorkplaceNotFound, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testCannotAddUserRoleWhenUserHasOtherRoles()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        // First, assign admin role to user
        $user->addRole(Role::query()->where('name', RoleName::Admin->value)->first());

        $action = new AssignRolesAction();
        $result = $action($user->id, RoleName::User->value, null);

        $this->assertNotNull($result->getError());
        $this->assertEquals(ErrorCode::RoleCannotAddUserWithOtherRoles, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testCannotAddOtherRoleWhenUserHasUserRole()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        // First, assign user role
        $user->addRole(Role::query()->where('name', RoleName::User->value)->first());

        $action = new AssignRolesAction();
        $result = $action($user->id, RoleName::Admin->value, null);

        $this->assertNotNull($result->getError());
        $this->assertEquals(ErrorCode::RoleUserCannotHaveOtherRoles, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testCanAddUserRoleWhenUserHasNoRoles()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        $action = new AssignRolesAction();
        $result = $action($user->id, RoleName::User->value, null);

        $this->assertNull($result->getError());
        $this->assertTrue($user->fresh()->hasRole(RoleName::User->value));
    }

    public function testCanAddOtherRoleWhenUserHasNoUserRole()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        $action = new AssignRolesAction();
        $result = $action($user->id, RoleName::Admin->value, null);

        $this->assertNull($result->getError());
        $this->assertTrue($user->fresh()->hasRole(RoleName::Admin->value));
    }
}
