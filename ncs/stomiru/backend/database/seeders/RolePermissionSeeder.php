<?php

namespace Database\Seeders;

use App\Enums\RoleId;
use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Role::query()->firstOrCreate([
            'id' => RoleId::SuperAdmin,
            'name' => RoleName::SuperAdmin,
            'display_name' => RoleName::SuperAdmin,
        ]);
        Role::query()->firstOrCreate([
            'id' => RoleId::Admin,
            'name' => RoleName::Admin,
            'display_name' => RoleName::Admin,
        ]);
        Role::query()->firstOrCreate([
            'id' => RoleId::Doctor,
            'name' => RoleName::Doctor,
            'display_name' => RoleName::Doctor,
        ]);
        Role::query()->firstOrCreate([
            'id' => RoleId::Staff,
            'name' => RoleName::Staff,
            'display_name' => RoleName::Staff,
        ]);
        Role::query()->firstOrCreate([
            'id' => RoleId::StaffManager,
            'name' => RoleName::StaffManager,
            'display_name' => RoleName::StaffManager,
        ]);
    }
}
