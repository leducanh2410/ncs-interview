<?php

use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Creates work_history.process_edit_request and grants it to the built-in Global Admin and
 * Admin roles. The RolePermissionSeeder only bulk-syncs a role's permissions on a fresh
 * install (when that role has zero permissions), so on an already-seeded environment this new
 * permission would otherwise never reach anyone, and the new
 * permission:work_history.process_edit_request middleware would 403 every list/approve/reject
 * call while the edit endpoint keeps creating pending requests no one can act on.
 *
 * The permission name is hardcoded rather than read from PermissionName::WorkHistoryEditRequestProcess
 * so this migration keeps replaying correctly if that enum case is ever renamed.
 *
 * Uses the relation's syncWithoutDetaching() (idempotent, unlike givePermission()'s attach())
 * followed by an explicit flushCache(): pivot writes made this way don't fire the Role model's
 * `saved` event that Laratrust normally hooks to invalidate its cache, so without the explicit
 * call an already-cached Admin/Global Admin could keep getting 403 in production (1h cache TTL)
 * until the cache naturally expired.
 */
return new class extends Migration {
    private const PERMISSION_NAME = 'work_history.process_edit_request';

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
