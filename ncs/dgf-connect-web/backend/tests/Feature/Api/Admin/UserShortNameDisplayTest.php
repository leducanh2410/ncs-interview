<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class UserShortNameDisplayTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testUserListShowsShortenedNameForSriLankaNationality(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::UserList]);

        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/users')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $user->id, 'name' => 'THARINDU RAVISHANN']);
    }

    public function testUserDetailShowsShortenedNameForSriLankaNationality(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::UserList]);

        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/users/{$user->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testUserListKeepsFullNameForNonSriLankaNationality(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::UserList]);

        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
        ]);
        $user->info->update(['nationality' => Nationality::Vietnam->value]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/users')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $user->id, 'name' => 'YAMADA TARO']);
    }
}
