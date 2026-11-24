<?php

use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    private const PERMISSION_NAMES = [
        'announcement.list',
        'announcement.create',
        'announcement.edit',
        'announcement.delete',
    ];

    public function up(): void
    {
        $permissionIds = [];
        foreach (self::PERMISSION_NAMES as $name) {
            $permissionIds[] = Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name])->id;
        }

        foreach ([RoleId::GlobalAdmin, RoleId::Admin] as $roleId) {
            $role = Role::find($roleId->value);
            $role?->permissions()->syncWithoutDetaching($permissionIds);
            $role?->flushCache();
        }
    }

    public function down(): void
    {
        $permissionIds = Permission::query()->whereIn('name', self::PERMISSION_NAMES)->pluck('id')->all();
        if ($permissionIds === []) {
            return;
        }

        foreach ([RoleId::GlobalAdmin, RoleId::Admin] as $roleId) {
            $role = Role::find($roleId->value);
            $role?->permissions()->detach($permissionIds);
            $role?->flushCache();
        }

        Permission::query()->whereIn('id', $permissionIds)->delete();
    }
};
