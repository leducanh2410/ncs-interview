<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleId;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $defaultRoles = [
            RoleId::GlobalAdmin->value => [
                'name' => RoleName::GlobalAdmin,
                'display_name' => 'Global Admin',
            ],
            RoleId::Admin->value => [
                'name' => RoleName::Admin,
                'display_name' => 'Admin',
            ],
            RoleId::User->value => [
                'name' => RoleName::User,
                'display_name' => 'User',
            ],
        ];

        foreach ($defaultRoles as $id => $role) {
            $r = Role::find($id);
            if (!$r) {
                Role::query()->create([
                    'id' => $id,
                    'name' => $role['name'],
                    'display_name' => $role['display_name'],
                ]);
            }
        }

        $allPermissionNames = PermissionName::values();
        foreach ($allPermissionNames as $permissionName) {
            Permission::query()->firstOrCreate(
                [
                    'name' => $permissionName,
                ],
                [
                    'display_name' => $permissionName,
                ]
            );
        }

        $gadmin = Role::find(RoleId::GlobalAdmin->value);
        $admin = Role::find(RoleId::Admin->value);
        $allPermissions = Permission::all();
        $gadmin->permissions()->sync($allPermissions);

        if ($admin->permissions()->count() === 0) {
            $admin
                ->permissions()
                ->sync($allPermissions->filter(fn($permission) => !Str::startsWith($permission->name, 'role.')));
        }
    }
}
