<?php

use App\Enums\PermissionName;
use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Grants chat.support to the Admin role so that regular support handlers can be registered
 * as support type admins. chat.assign remains Global Admin-only and controls who can manage
 * the support type admin roster.
 */
return new class extends Migration {
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::ChatSupport->value],
            ['display_name' => PermissionName::ChatSupport->value]
        );

        $admin = Role::find(RoleId::Admin->value);
        $admin?->permissions()->syncWithoutDetaching([$permission->id]);

        $globalAdmin = Role::find(RoleId::GlobalAdmin->value);
        $globalAdmin?->permissions()->syncWithoutDetaching([$permission->id]);
    }

    public function down(): void
    {
        $permission = Permission::query()->where('name', PermissionName::ChatSupport->value)->first();
        if ($permission === null) {
            return;
        }

        $admin = Role::find(RoleId::Admin->value);
        $admin?->permissions()->detach([$permission->id]);

        $globalAdmin = Role::find(RoleId::GlobalAdmin->value);
        $globalAdmin?->permissions()->detach([$permission->id]);
    }
};
