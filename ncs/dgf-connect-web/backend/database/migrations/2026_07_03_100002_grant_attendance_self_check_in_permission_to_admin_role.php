<?php

use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Grants attendance.self.check_in to the Admin role so team-scoped admins can resolve their
 * assigned workplaces for mobile check-in/out. The RolePermissionSeeder only bulk-syncs the
 * Admin role's permissions on a fresh install (when it has zero permissions), so this new
 * permission needs an explicit grant on environments that were already seeded.
 *
 * The permission name is hardcoded here (not via PermissionName::AttendanceSelfCheckIn) because
 * that enum case was later renamed to ManagerAttendanceCheckIn -- see the
 * rename_attendance_self_check_in_to_manager_attendance_check_in migration.
 */
return new class extends Migration {
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'attendance.self.check_in'],
            ['display_name' => 'attendance.self.check_in']
        );

        $admin = Role::find(RoleId::Admin->value);
        $admin?->permissions()->syncWithoutDetaching([$permission->id]);

        $globalAdmin = Role::find(RoleId::GlobalAdmin->value);
        $globalAdmin?->permissions()->syncWithoutDetaching([$permission->id]);
    }

    public function down(): void
    {
        $permission = Permission::query()->where('name', 'attendance.self.check_in')->first();
        if ($permission === null) {
            return;
        }

        $admin = Role::find(RoleId::Admin->value);
        $admin?->permissions()->detach([$permission->id]);

        $globalAdmin = Role::find(RoleId::GlobalAdmin->value);
        $globalAdmin?->permissions()->detach([$permission->id]);
    }
};
