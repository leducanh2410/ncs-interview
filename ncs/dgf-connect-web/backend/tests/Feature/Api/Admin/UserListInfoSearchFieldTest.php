<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Models\User;
use App\Models\UserInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class UserListInfoSearchFieldTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testRejectsInfoSearchFieldOutsideAllowList(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::UserList]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/users?info_search_field=nationality&info_search_value=Vietnam')
            ->assertStatus(422)
            ->assertJsonPath('error', 'E_UNPROCESSABLE_ENTITY')
            ->assertJsonStructure(['data' => ['info_search_field']]);
    }

    public function testRejectsUnknownColumnInsteadOfFailingWithServerError(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::UserList]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/users?info_search_field=no_such_column&info_search_value=x')
            ->assertStatus(422)
            ->assertJsonPath('error', 'E_UNPROCESSABLE_ENTITY')
            ->assertJsonStructure(['data' => ['info_search_field']]);
    }

    public function testAllowsInfoSearchFieldFromAllowList(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::UserList]);

        $match = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $match->id,
            'passport_number' => 'PASS123',
        ]);

        User::factory()->create();

        $this->actingAs($admin, 'api')
            ->getJson('/admin/users?info_search_field=passport_number&info_search_value=PASS')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $match->id);
    }
}
