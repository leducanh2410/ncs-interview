<?php

namespace Tests\Concerns;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

trait GrantsWorkplacePermissions
{
    /**
     * Grant a permission scoped to a single workplace (role-based, so it is visible to
     * both `isAbleTo()` and `getWorkplaceIdsByPermission()`/`applyWorkplaceFilter()`).
     */
    protected function giveWorkplaceScopedPermission(User $user, PermissionName $permissionName, int $workplaceId): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName->value],
            ['display_name' => $permissionName->value]
        );
        $role = Role::create([
            'name' => Str::random(16),
            'display_name' => 'Test scoped role',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->addRole($role, $workplaceId);
    }

    /**
     * Grant a permission with global (unrestricted, team_id = null) scope, role-based.
     */
    protected function giveGlobalPermission(User $user, PermissionName $permissionName): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName->value],
            ['display_name' => $permissionName->value]
        );
        $role = Role::create([
            'name' => Str::random(16),
            'display_name' => 'Test global role',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->addRole($role, null);
    }
}
