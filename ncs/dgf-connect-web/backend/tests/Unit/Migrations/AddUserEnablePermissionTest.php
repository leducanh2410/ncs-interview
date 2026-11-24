<?php

namespace Tests\Unit\Migrations;

use App\Enums\PermissionName;
use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddUserEnablePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_07_28_000001_add_user_enable_permission.php');
    }

    private function makeRole(int $id, string $name): Role
    {
        // Force the primary key: the migration looks roles up by their fixed IDs
        // (RoleId::GlobalAdmin, RoleId::Admin), and "id" is not mass-assignable.
        $role = new Role();
        $role->forceFill(['id' => $id, 'name' => $name, 'display_name' => $name])->save();

        return $role;
    }

    private function disablePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['name' => PermissionName::UserDisable->value],
            ['display_name' => 'User Disable']
        );
    }

    private function roleHasEnable(int $roleId): bool
    {
        return Role::query()
            ->findOrFail($roleId)
            ->permissions()
            ->where('name', PermissionName::UserEnable->value)
            ->exists();
    }

    public function testAdminWithDisableIsGrantedEnable(): void
    {
        $admin = $this->makeRole(RoleId::Admin->value, 'admin');
        $admin->permissions()->attach($this->disablePermission()->id);

        $this->migration()->up();

        $this->assertTrue($this->roleHasEnable(RoleId::Admin->value));
    }

    public function testAdminWithoutDisableIsNotGrantedEnable(): void
    {
        $this->makeRole(RoleId::Admin->value, 'admin');
        // The disable permission exists in the system, but Admin does not hold it.
        $this->disablePermission();

        $this->migration()->up();

        $this->assertFalse($this->roleHasEnable(RoleId::Admin->value));
    }

    public function testGlobalAdminIsAlwaysGrantedEnable(): void
    {
        // Global Admin gets the new permission regardless of holding disable.
        $this->makeRole(RoleId::GlobalAdmin->value, 'global_admin');

        $this->migration()->up();

        $this->assertTrue($this->roleHasEnable(RoleId::GlobalAdmin->value));
    }

    public function testCustomRoleWithDisableIsLeftUntouched(): void
    {
        $customRole = $this->makeRole(999, 'leader');
        $customRole->permissions()->attach($this->disablePermission()->id);

        $this->migration()->up();

        $this->assertFalse($this->roleHasEnable(999));
    }

    public function testBackfillIsIdempotent(): void
    {
        $admin = $this->makeRole(RoleId::Admin->value, 'admin');
        $admin->permissions()->attach($this->disablePermission()->id);

        $this->migration()->up();
        $this->migration()->up();

        // Exactly one enable permission and one pivot row for Admin.
        $enable = Permission::query()->where('name', PermissionName::UserEnable->value)->get();
        $this->assertCount(1, $enable);
        $this->assertSame(
            1,
            DB::table('permission_role')
                ->where('permission_id', $enable->first()->id)
                ->where('role_id', RoleId::Admin->value)
                ->count()
        );
    }
}
