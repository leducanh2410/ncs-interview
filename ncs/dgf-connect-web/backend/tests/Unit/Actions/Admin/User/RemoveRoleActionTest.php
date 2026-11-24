<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\RemoveRoleAction;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RemoveRoleActionTest extends TestCase
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

        // Create test roles
        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
    }

    public function testRemoveGlobalRole()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $user->addRole('admin', null); // Assign global role
        Auth::login($admin);

        $this->assertTrue($user->hasRole('admin'));

        $action = new RemoveRoleAction();
        $result = $action($user->id, 'admin', null);

        $this->assertNull($result->getError());
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function testRemoveWorkplaceScopedRole()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $user->addRole('shift_manager', 1); // Assign to workplace 1
        Auth::login($admin);

        $this->assertTrue($user->hasRole('shift_manager'));

        $action = new RemoveRoleAction();
        $result = $action($user->id, 'shift_manager', 1);

        $this->assertNull($result->getError());
        $this->assertFalse($user->fresh()->hasRole('shift_manager'));
    }

    public function testRemoveSpecificWorkplaceScopedRoleKeepsOthers()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        // Add role to multiple workplaces
        $user->addRole('shift_manager', 1);
        $user->addRole('shift_manager', 2);
        Auth::login($admin);

        $this->assertCount(2, $user->roles);

        // Remove only workplace 1 assignment
        $action = new RemoveRoleAction();
        $result = $action($user->id, 'shift_manager', 1);

        $this->assertNull($result->getError());

        // User should still have shift_manager for workplace 2
        $user = $user->fresh();
        $this->assertTrue($user->hasRole('shift_manager'));
        $this->assertCount(1, $user->roles);
    }

    public function testCannotRemoveRoleFromSelf()
    {
        $admin = User::factory()->create();
        $admin->addRole('admin', null);
        Auth::login($admin);

        $action = new RemoveRoleAction();
        $result = $action($admin->id, 'admin', null);

        $this->assertNotNull($result->getError());
        $this->assertEquals(ErrorCode::UserCannotAssignRoleSelf, $result->getError());
        $this->assertEquals(403, $result->getStatus());
    }

    public function testOnlyUserOneCanRemoveGlobalAdminRole()
    {
        // Create user with ID 1
        $userOne = User::factory()->create(['id' => 1]);
        $admin = User::factory()->create();
        $targetUser = User::factory()->create();
        $targetUser->addRole(RoleName::GlobalAdmin->value, null);

        // Test with non-user-1 admin
        Auth::login($admin);
        $action = new RemoveRoleAction();
        $result = $action($targetUser->id, RoleName::GlobalAdmin->value, null);

        $this->assertNotNull($result->getError());
        $this->assertEquals(ErrorCode::UserCannotAssignRoleGlobalAdmin, $result->getError());
        $this->assertEquals(403, $result->getStatus());

        // Test with user ID 1
        Auth::login($userOne);
        $result = $action($targetUser->id, RoleName::GlobalAdmin->value, null);

        $this->assertNull($result->getError());
        $this->assertFalse($targetUser->fresh()->hasRole(RoleName::GlobalAdmin->value));
    }

    public function testRemoveRoleWithInvalidRoleName()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        Auth::login($admin);

        $action = new RemoveRoleAction();
        $result = $action($user->id, 'invalid_role', null);

        $this->assertNotNull($result->getError());
        $this->assertEquals(ErrorCode::RoleNotFound, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }
}
