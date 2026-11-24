<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\PermissionName;
use App\Enums\SupportType;
use App\Models\SupportTypeAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class SupportTypeControllerTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // GET /admin/chat/support-types
    // -----------------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $this->getJson('/admin/chat/support-types')->assertStatus(401);
    }

    public function testIndexRequiresChatAssignPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson('/admin/chat/support-types')->assertStatus(403);
    }

    public function testIndexReturnsAllTypesWithAdminsAndProfilePicture(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $admin->update(['profile_picture' => 'https://example.com/avatar.jpg']);
        SupportTypeAdmin::create(['support_type' => SupportType::Shift->value, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/support-types');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(count(SupportType::cases()), $data);

        $types = array_column($data, 'type');
        foreach (SupportType::values() as $value) {
            $this->assertContains($value, $types);
        }
        $this->assertContains(SupportType::Other->value, $types);

        $shiftItem = collect($data)->firstWhere('type', SupportType::Shift->value);
        $this->assertCount(1, $shiftItem['admins']);
        $this->assertSame($admin->id, $shiftItem['admins'][0]['user_id']);
        $this->assertSame('https://example.com/avatar.jpg', $shiftItem['admins'][0]['profile_picture']);
    }

    // -----------------------------------------------------------------------
    // POST /admin/chat/support-types/{type}/admins
    // -----------------------------------------------------------------------

    public function testAddAdminRequiresChatAssignPermission(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/admin/chat/support-types/shift/admins', ['user_id' => $target->id])
            ->assertStatus(403);
    }

    public function testAddAdminReturns201AndPersists(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport, PermissionName::ChatView]);

        $response = $this->actingAs($actor, 'api')->postJson('/admin/chat/support-types/shift/admins', [
            'user_id' => $target->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['user_id' => $target->id]);
        $this->assertDatabaseHas('support_type_admins', [
            'support_type' => SupportType::Shift->value,
            'user_id' => $target->id,
        ]);
    }

    public function testAddAdminIsIdempotent(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport, PermissionName::ChatView]);

        $this->actingAs($actor, 'api')
            ->postJson('/admin/chat/support-types/shift/admins', ['user_id' => $target->id])
            ->assertStatus(201);

        $this->actingAs($actor, 'api')
            ->postJson('/admin/chat/support-types/shift/admins', ['user_id' => $target->id])
            ->assertStatus(200);

        $this->assertDatabaseCount('support_type_admins', 1);
    }

    public function testAddAdminReturns422WhenUserLacksChatSupportPermission(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = User::factory()->create();

        $this->actingAs($actor, 'api')
            ->postJson('/admin/chat/support-types/shift/admins', ['user_id' => $target->id])
            ->assertStatus(422);

        $this->assertDatabaseMissing('support_type_admins', [
            'support_type' => SupportType::Shift->value,
            'user_id' => $target->id,
        ]);
    }

    public function testAddAdminReturns422WhenUserLacksChatViewPermission(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport]);

        $this->actingAs($actor, 'api')
            ->postJson('/admin/chat/support-types/shift/admins', ['user_id' => $target->id])
            ->assertStatus(422);

        $this->assertDatabaseMissing('support_type_admins', [
            'support_type' => SupportType::Shift->value,
            'user_id' => $target->id,
        ]);
    }

    public function testAddAdminReturns422ForInvalidType(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = User::factory()->create();

        $this->actingAs($actor, 'api')
            ->postJson('/admin/chat/support-types/invalid_type/admins', ['user_id' => $target->id])
            ->assertStatus(422);
    }

    public function testAddAdminReturns422ForInvalidUserId(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);

        $this->actingAs($actor, 'api')
            ->postJson('/admin/chat/support-types/shift/admins', ['user_id' => 99999])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // DELETE /admin/chat/support-types/{type}/admins/{userId}
    // -----------------------------------------------------------------------

    public function testRemoveAdminRequiresChatAssignPermission(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/chat/support-types/shift/admins/{$target->id}")
            ->assertStatus(403);
    }

    public function testRemoveAdminReturns200(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = User::factory()->create();
        SupportTypeAdmin::create(['support_type' => SupportType::Shift->value, 'user_id' => $target->id]);

        $this->actingAs($actor, 'api')
            ->deleteJson("/admin/chat/support-types/shift/admins/{$target->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('support_type_admins', [
            'support_type' => SupportType::Shift->value,
            'user_id' => $target->id,
        ]);
    }

    public function testRemoveAdminNoopWhenNotAssigned(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = User::factory()->create();

        $this->actingAs($actor, 'api')
            ->deleteJson("/admin/chat/support-types/shift/admins/{$target->id}")
            ->assertStatus(200);
    }

    public function testRemoveAdminReturns422ForInvalidType(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);

        $this->actingAs($actor, 'api')
            ->deleteJson('/admin/chat/support-types/invalid_type/admins/1')
            ->assertStatus(422);
    }
}
