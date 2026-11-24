<?php

use App\Enums\PermissionName;
use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Renames the attendance.self.check_in permission to manager.attendance.check_in so it
 * groups under "Manager" in the role management UI instead of standing alone. Falls back to
 * creating it fresh (and granting Admin/GlobalAdmin) for environments where the original
 * grant migration never ran.
 */
return new class extends Migration {
    public function up(): void
    {
        $permission = Permission::query()->where('name', 'attendance.self.check_in')->first();

        if ($permission !== null) {
            $permission->update([
                'name' => PermissionName::ManagerAttendanceCheckIn->value,
                'display_name' => PermissionName::ManagerAttendanceCheckIn->value,
            ]);

            return;
        }

        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::ManagerAttendanceCheckIn->value],
            ['display_name' => PermissionName::ManagerAttendanceCheckIn->value]
        );

        $admin = Role::find(RoleId::Admin->value);
        $admin?->permissions()->syncWithoutDetaching([$permission->id]);

        $globalAdmin = Role::find(RoleId::GlobalAdmin->value);
        $globalAdmin?->permissions()->syncWithoutDetaching([$permission->id]);
    }

    public function down(): void
    {
        $permission = Permission::query()->where('name', PermissionName::ManagerAttendanceCheckIn->value)->first();
        if ($permission === null) {
            return;
        }

        $permission->update([
            'name' => 'attendance.self.check_in',
            'display_name' => 'attendance.self.check_in',
        ]);
    }
};
