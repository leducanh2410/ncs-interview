<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Models\SystemConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class ConfigControllerTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testIndexRequiresConfigListPermission(): void
    {
        $user = $this->createUserWithPermissions([]);

        $this->actingAs($user, 'api')->getJson('/admin/configs')->assertStatus(403);
    }

    public function testIndexReturnsCurrentConfig(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigList]);
        SystemConfig::create([
            'max_hour_per_week' => 40,
            'max_shift_per_day' => 3,
            'salary_per_hour' => 1500,
            'login_url' => 'https://kintai.matchbb.jp/staff/stampshift',
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/admin/configs');

        $response->assertStatus(200)->assertJsonPath('data.login_url', 'https://kintai.matchbb.jp/staff/stampshift');
    }

    public function testUpdateRequiresConfigEditPermission(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigList]);

        $this->actingAs($user, 'api')
            ->putJson('/admin/configs', [
                'max_hour_per_week' => 40,
                'max_shift_per_day' => 3,
                'salary_per_hour' => 1500,
                'login_url' => 'https://example.com/login',
            ])
            ->assertStatus(403);
    }

    public function testUpdateSavesLoginUrl(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $response = $this->actingAs($user, 'api')->putJson('/admin/configs', [
            'max_hour_per_week' => 40,
            'max_shift_per_day' => 3,
            'salary_per_hour' => 1500,
            'login_url' => 'https://example.com/new-login',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('system_configs', ['login_url' => 'https://example.com/new-login']);
    }

    public function testUpdateFailsValidationWhenLoginUrlMissing(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')
            ->putJson('/admin/configs', [
                'max_hour_per_week' => 40,
                'max_shift_per_day' => 3,
                'salary_per_hour' => 1500,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['login_url']]);
    }

    public function testUpdateFailsValidationWhenLoginUrlIsNotHttp(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')
            ->putJson('/admin/configs', [
                'max_hour_per_week' => 40,
                'max_shift_per_day' => 3,
                'salary_per_hour' => 1500,
                'login_url' => 'ftp://example.com/login',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['login_url']]);
    }

    public function testUpdateFailsValidationWhenLoginUrlIsMalformed(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')
            ->putJson('/admin/configs', [
                'max_hour_per_week' => 40,
                'max_shift_per_day' => 3,
                'salary_per_hour' => 1500,
                'login_url' => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['login_url']]);
    }

    public function testUpdateFailsValidationWhenLoginUrlExceedsMaxLength(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')
            ->putJson('/admin/configs', [
                'max_hour_per_week' => 40,
                'max_shift_per_day' => 3,
                'salary_per_hour' => 1500,
                'login_url' => 'https://example.com/' . str_repeat('a', 255),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['login_url']]);
    }
}
