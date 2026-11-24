<?php

use App\Enums\PermissionName;
use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits unlocking an account out of the "disable" permission into a dedicated
 * "user.general.enable" permission. Global Admin keeps everything, and the
 * built-in Admin role is pre-granted the new permission when it currently has
 * "disable" so it keeps unlocking out of the box. Any other (custom) role must
 * be granted the permission explicitly from the role management screen.
 *
 * The pivot is updated directly, which does not trigger Laratrust's cache
 * invalidation, so we flush the touched roles' caches explicitly. Otherwise, in
 * production (where Laratrust caching is on with a 1h TTL) an already-cached
 * Admin/Global Admin could keep getting 403 on the unlock route until the cache
 * expires.
 */
return new class extends Migration {
    public function up(): void
    {
        $enable = Permission::query()->firstOrCreate(
            ['name' => PermissionName::UserEnable->value],
            ['display_name' => PermissionName::UserEnable->value]
        );

        $disable = Permission::query()->where('name', PermissionName::UserDisable->value)->first();

        $globalAdmin = Role::find(RoleId::GlobalAdmin->value);
        $globalAdmin?->permissions()->syncWithoutDetaching([$enable->id]);
        $globalAdmin?->flushCache();

        if ($disable === null) {
            return;
        }

        // Pre-grant unlock to the built-in Admin role only when it already has
        // "disable"; custom roles are left untouched.
        $adminHasDisable = DB::table('permission_role')
            ->where('permission_id', $disable->id)
            ->where('role_id', RoleId::Admin->value)
            ->exists();

        if ($adminHasDisable) {
            $admin = Role::find(RoleId::Admin->value);
            $admin?->permissions()->syncWithoutDetaching([$enable->id]);
            $admin?->flushCache();
        }
    }

    public function down(): void
    {
        $enable = Permission::query()->where('name', PermissionName::UserEnable->value)->first();
        if ($enable === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $enable->id)->delete();
        $enable->delete();

        // Drop the now-stale permission from the touched roles' caches.
        Role::find(RoleId::GlobalAdmin->value)?->flushCache();
        Role::find(RoleId::Admin->value)?->flushCache();
    }
};
