<?php

use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Grants exam.result.export so the Export CSV button on the exam result screen works
 * for existing roles. The RolePermissionSeeder only bulk-syncs the Admin role on a fresh
 * install (when it has zero permissions), so on an already-seeded environment a new
 * permission never reaches Admin without an explicit grant like this one.
 *
 * The permission name is hardcoded rather than read from PermissionName::ExamResultExport
 * so this migration keeps replaying correctly if that enum case is ever renamed -- the same
 * reason the grant_attendance_self_check_in_permission_to_admin_role migration does it.
 */
return new class extends Migration {
    private const PERMISSION_NAME = 'exam.result.export';

    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => self::PERMISSION_NAME],
            ['display_name' => self::PERMISSION_NAME]
        );

        foreach ([RoleId::GlobalAdmin, RoleId::Admin] as $roleId) {
            Role::find($roleId->value)
                ?->permissions()
                ->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        $permission = Permission::query()->where('name', self::PERMISSION_NAME)->first();
        if ($permission === null) {
            return;
        }

        foreach ([RoleId::GlobalAdmin, RoleId::Admin] as $roleId) {
            Role::find($roleId->value)
                ?->permissions()
                ->detach([$permission->id]);
        }
    }
};
