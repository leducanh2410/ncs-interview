<?php

use App\Enums\PermissionName;
use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Grants the admin-facing chat permissions to the Admin role on existing environments. The seeder
 * only syncs the Admin role when it has no permissions, so fresh chat permission cases would
 * otherwise never reach already-seeded databases. `chat.assign` intentionally remains Global Admin
 * only and is not backfilled here.
 */
return new class extends Migration {
    public function up(): void
    {
        $permissionIds = collect($this->adminPermissionNames())
            ->map(
                fn(string $permissionName) => Permission::query()->firstOrCreate(
                    ['name' => $permissionName],
                    ['display_name' => $permissionName]
                )->id
            )
            ->all();

        $admin = Role::find(RoleId::Admin->value);
        $admin?->permissions()->syncWithoutDetaching($permissionIds);
    }

    public function down(): void
    {
        $permissionIds = Permission::query()->whereIn('name', $this->adminPermissionNames())->pluck('id')->all();
        if ($permissionIds === []) {
            return;
        }

        $admin = Role::find(RoleId::Admin->value);
        $admin?->permissions()->detach($permissionIds);
    }

    /**
     * @return array<int, string>
     */
    private function adminPermissionNames(): array
    {
        return [PermissionName::ChatView->value, PermissionName::ChatCreate->value, PermissionName::ChatEdit->value];
    }
};
