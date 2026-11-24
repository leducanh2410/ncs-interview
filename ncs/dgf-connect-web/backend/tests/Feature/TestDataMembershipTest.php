<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\UserPaperStatus;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestDataMembershipTest extends TestCase
{
    use RefreshDatabase;

    private const MISMATCH = 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH';

    private function createUser(bool $isTest): User
    {
        return User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'is_test' => $isTest,
        ]);
    }

    private function createWorkplace(bool $isTest): Workplace
    {
        return Workplace::create([
            'name' => 'Workplace ' . uniqid(),
            'address' => 'Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'is_test' => $isTest,
        ]);
    }

    private function createAdminWith(PermissionName ...$permissions): User
    {
        $admin = $this->createUser(false);
        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName->value],
                ['display_name' => $permissionName->value]
            );
            $admin->permissions()->attach($permission);
        }

        return $admin;
    }

    /****************************************
     * Employee self-registration           *
     ****************************************/

    public function testTestEmployeeCanRegisterTestWorkplace(): void
    {
        $user = $this->createUser(true);
        $workplace = $this->createWorkplace(true);

        $response = $this->actingAs($user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$workplace->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $user->id,
            'workplace_id' => $workplace->id,
        ]);
    }

    public function testTestEmployeeCannotRegisterNormalWorkplace(): void
    {
        $user = $this->createUser(true);
        $workplace = $this->createWorkplace(false);

        $response = $this->actingAs($user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$workplace->id],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', self::MISMATCH);
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $user->id,
            'workplace_id' => $workplace->id,
        ]);
    }

    public function testNormalEmployeeCannotRegisterTestWorkplace(): void
    {
        $user = $this->createUser(false);
        $workplace = $this->createWorkplace(true);

        $response = $this->actingAs($user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$workplace->id],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', self::MISMATCH);
    }

    /****************************************
     * Admin: add user into workplaces       *
     ****************************************/

    public function testAdminCannotAddNormalUserToTestWorkplace(): void
    {
        $admin = $this->createAdminWith(PermissionName::UserWorkplaceAdd);
        $user = $this->createUser(false);
        $workplace = $this->createWorkplace(true);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/workplaces", [
            'workplace_ids' => [$workplace->id],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', self::MISMATCH);
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $user->id,
            'workplace_id' => $workplace->id,
        ]);
    }

    public function testAdminCanAddTestUserToTestWorkplace(): void
    {
        $admin = $this->createAdminWith(PermissionName::UserWorkplaceAdd);
        $user = $this->createUser(true);
        $workplace = $this->createWorkplace(true);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/workplaces", [
            'workplace_ids' => [$workplace->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $user->id,
            'workplace_id' => $workplace->id,
        ]);
    }

    /****************************************
     * Admin: attach users to a workplace    *
     ****************************************/

    public function testAdminCannotAttachNormalUserToTestWorkplace(): void
    {
        $admin = $this->createAdminWith(PermissionName::WorkplaceUserAdd);
        $user = $this->createUser(false);
        $workplace = $this->createWorkplace(true);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/users", [
            'user_ids' => [$user->id],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', self::MISMATCH);
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $user->id,
            'workplace_id' => $workplace->id,
        ]);
    }

    /****************************************
     * Toggling the flag with conflicts      *
     ****************************************/

    public function testCannotMarkWorkplaceAsTestWhileNormalMembersAssigned(): void
    {
        $admin = $this->createAdminWith(PermissionName::WorkplaceEdit);
        $user = $this->createUser(false);
        $workplace = $this->createWorkplace(false);
        WorkplaceUser::create(['user_id' => $user->id, 'workplace_id' => $workplace->id]);

        $response = $this->actingAs($admin, 'api')->putJson("/admin/workplaces/{$workplace->id}", [
            'name' => $workplace->name,
            'address' => $workplace->address,
            'coordinates' => '35.6895, 139.6917',
            'is_test' => true,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', self::MISMATCH);
        $this->assertDatabaseHas('workplaces', ['id' => $workplace->id, 'is_test' => false]);
    }

    public function testCannotMarkUserAsTestWhileNormalWorkplacesAssigned(): void
    {
        $admin = $this->createAdminWith(PermissionName::UserEdit);
        $user = $this->createUser(false);
        $workplace = $this->createWorkplace(false);
        WorkplaceUser::create(['user_id' => $user->id, 'workplace_id' => $workplace->id]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'first_name_kana' => $user->first_name_kana,
            'last_name_kana' => $user->last_name_kana,
            'email' => $user->email,
            'is_test' => true,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', self::MISMATCH);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_test' => false]);
    }

    /****************************************
     * Employee-facing visibility            *
     ****************************************/

    public function testWorkplaceListShowsOnlyMatchingIsTest(): void
    {
        $testWorkplace = $this->createWorkplace(true);
        $normalWorkplace = $this->createWorkplace(false);
        $testUser = $this->createUser(true);

        $response = $this->actingAs($testUser, 'api')->getJson('/user/workplaces');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($testWorkplace->id, $ids);
        $this->assertNotContains($normalWorkplace->id, $ids);
    }
}
