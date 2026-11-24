<?php

namespace Database\Seeders;

use App\Enums\RoleId;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (User::query()->exists()) {
            return;
        }

        /** @var User $user */
        $user = User::query()->firstOrCreate(
            ['id' => 1],
            [
                'login_id' => '123456',
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => bcrypt('123123'),
                // 'email_verified_at' => now(),
            ]
        );

        $user->addRole(RoleId::SuperAdmin);
    }
}
