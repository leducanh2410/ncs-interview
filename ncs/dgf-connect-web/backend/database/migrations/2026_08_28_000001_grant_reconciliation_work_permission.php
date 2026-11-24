<?php

use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Creates reconciliation.work and grants it to the built-in Global Admin and Admin roles.
 * The permission name is hardcoded rather than read from PermissionName::ReconciliationWork
 * so this migration keeps replaying correctly if that enum case is ever renamed.
 *
 * Uses syncWithoutDetaching() plus an explicit flushCache() because pivot writes made this
 * way do not fire the Role model's `saved` event that Laratrust hooks to invalidate its cache.
 */
return new class extends Migration {
    private const PERMISSION_NAME = 'reconciliation.work';

    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => self::PERMISSION_NAME],
            ['display_name' => self::PERMISSION_NAME]
        );

        foreach ([RoleId::GlobalAdmin, RoleId::Admin] as $roleId) {
            $role = Role::find($roleId->value);
            $role?->permissions()->syncWithoutDetaching([$permission->id]);
            $role?->flushCache();
        }
    }

    public function down(): void
    {
        $permission = Permission::query()->where('name', self::PERMISSION_NAME)->first();
        if ($permission === null) {
            return;
        }

        foreach ([RoleId::GlobalAdmin, RoleId::Admin] as $roleId) {
            $role = Role::find($roleId->value);
            $role?->permissions()->detach([$permission->id]);
            $role?->flushCache();
        }
    }
};
