<?php

use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    private const PERMISSION_NAME = 'shift.import_order';

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
