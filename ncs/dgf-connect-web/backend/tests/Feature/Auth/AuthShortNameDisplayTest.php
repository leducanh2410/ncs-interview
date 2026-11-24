<?php

namespace Tests\Feature\Auth;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function testLoginReturnsShortenedNameForSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
            'password' => bcrypt('password'),
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $user->addRole(
            Role::create([
                'name' => RoleName::User->value,
                'display_name' => 'User',
                'description' => 'Regular user',
            ])
        );

        $response = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'device-1',
            'device_name' => 'Test Device',
            'platform' => 'ios',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user.name', 'THARINDU RAVISHANN');
    }

    public function testLoginKeepsFullNameForNonSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
            'password' => bcrypt('password'),
        ]);
        $user->info->update(['nationality' => Nationality::Vietnam->value]);
        $user->addRole(
            Role::create([
                'name' => RoleName::User->value,
                'display_name' => 'User',
                'description' => 'Regular user',
            ])
        );

        $response = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'device-1',
            'device_name' => 'Test Device',
            'platform' => 'ios',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user.name', 'YAMADA TARO');
    }

    public function testAuthCheckReturnsShortenedNameForSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $user->addRole(
            Role::create([
                'name' => RoleName::User->value,
                'display_name' => 'User',
                'description' => 'Regular user',
            ])
        );

        // actingAs() injects this instance directly into the guard, so it
        // must be refreshed to reflect what a real request's auth
        // middleware would load: the nationality committed just above.
        $this->actingAs($user->refresh(), 'api')
            ->getJson('/auth/check')
            ->assertStatus(200)
            ->assertJsonPath('data.user.name', 'THARINDU RAVISHANN');
    }

    public function testManageAuthCheckReturnsShortenedNameForSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $permission = Permission::create([
            'name' => PermissionName::ManagerAuth->value,
            'display_name' => 'Manager Auth',
        ]);
        $user->permissions()->attach($permission);

        $this->actingAs($user->refresh(), 'api')
            ->getJson('/manage/auth/check')
            ->assertStatus(200)
            ->assertJsonPath('data.user.name', 'THARINDU RAVISHANN');
    }
}
