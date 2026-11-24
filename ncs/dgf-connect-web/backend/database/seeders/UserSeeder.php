<?php

namespace Database\Seeders;

use App\Enums\RoleId;
use App\Enums\UserPaperStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /** @var User $user */
        $user = User::query()->firstOrCreate(
            ['id' => 1],
            [
                'email' => 'admin@stafflink.jp',
                'first_name' => 'Global',
                'last_name' => 'Admin',
                'first_name_kana' => 'グローバル',
                'last_name_kana' => 'アドミン',
                'password' => bcrypt('123123'),
                'email_verified_at' => now(),
                'paper_status' => UserPaperStatus::Approved,
            ]
        );

        $user->addRole(RoleId::GlobalAdmin);
    }
}
