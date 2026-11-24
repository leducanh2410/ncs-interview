<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class SupportAssignmentTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testUnassignedListingRequiresChatAssignPermission(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin, 'api')->getJson('/admin/chat/support-conversations')->assertStatus(403);
    }

    public function testUnassignedListingShowsSupportChatsWithoutAdmins(): void
    {
        $assignAdmin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $employee = User::factory()->create();
        $unassigned = ChatConversation::factory()
            ->support()
            ->create(['created_by_user_id' => $employee->id]);
        $this->addParticipant($unassigned, $employee, ['role' => ChatParticipantRole::Employee->value]);

        $assigned = ChatConversation::factory()->support()->create();
        $this->addParticipant($assigned, $assignAdmin, ['role' => ChatParticipantRole::Admin->value]);

        $response = $this->actingAs($assignAdmin, 'api')->getJson('/admin/chat/support-conversations');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($unassigned->id));
        $this->assertFalse($ids->contains($assigned->id));
    }

    public function testAssignAddsAdminParticipantAndAudit(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport, PermissionName::ChatView]);
        $employee = User::factory()->create();
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['created_by_user_id' => $employee->id]);
        $this->addParticipant($conversation, $employee, ['role' => ChatParticipantRole::Employee->value]);

        $response = $this->actingAs($actor, 'api')->postJson(
            "/admin/chat/support-conversations/{$conversation->id}/assign-admins",
            ['admin_user_ids' => [$target->id]]
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $target->id,
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertDatabaseHas('chat_audit_logs', [
            'conversation_id' => $conversation->id,
            'action' => ChatAuditAction::SupportAdminAssigned->value,
        ]);

        // Assigned admin can now view the conversation via the normal detail endpoint.
        $this->actingAs($target, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertOk();
    }

    public function testAssignRejectsUsersWithoutChatSupportPermission(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $ineligible = User::factory()->create();
        $conversation = ChatConversation::factory()->support()->create();

        $response = $this->actingAs($actor, 'api')->postJson(
            "/admin/chat/support-conversations/{$conversation->id}/assign-admins",
            ['admin_user_ids' => [$ineligible->id]]
        );

        $response->assertStatus(422);
    }

    public function testReassigningActiveAdminIsIdempotent(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport, PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->support()->create();

        $payload = ['admin_user_ids' => [$target->id]];
        $this->actingAs($actor, 'api')
            ->postJson("/admin/chat/support-conversations/{$conversation->id}/assign-admins", $payload)
            ->assertOk();
        $this->actingAs($actor, 'api')
            ->postJson("/admin/chat/support-conversations/{$conversation->id}/assign-admins", $payload)
            ->assertOk();

        $this->assertSame(
            1,
            $conversation
                ->participants()
                ->where('user_id', $target->id)
                ->where('status', ChatParticipantStatus::Active->value)
                ->count()
        );
    }

    public function testAssignAdminsSucceedsForGuestSupportConversation(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport, PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);

        $response = $this->actingAs($actor, 'api')->postJson(
            "/admin/chat/support-conversations/{$conversation->id}/assign-admins",
            ['admin_user_ids' => [$target->id]]
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $target->id,
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    public function testRemoveAdminsSucceedsForGuestSupportConversation(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);
        $this->addParticipant($conversation, $target, ['role' => ChatParticipantRole::Admin->value]);

        $response = $this->actingAs($actor, 'api')->postJson(
            "/admin/chat/support-conversations/{$conversation->id}/remove-admins",
            ['admin_user_ids' => [$target->id]]
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $target->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
    }

    public function testRemoveDeniesFurtherAccessAndKeepsOwner(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport]);
        $employee = User::factory()->create();
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['created_by_user_id' => $employee->id]);
        $this->addParticipant($conversation, $employee, ['role' => ChatParticipantRole::Employee->value]);
        $this->addParticipant($conversation, $actor, ['role' => ChatParticipantRole::Admin->value]);
        $this->addParticipant($conversation, $target, ['role' => ChatParticipantRole::Admin->value]);

        $response = $this->actingAs($actor, 'api')->postJson(
            "/admin/chat/support-conversations/{$conversation->id}/remove-admins",
            ['admin_user_ids' => [$target->id, $employee->id]]
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $target->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
        // The owning employee is never removed.
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $employee->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }
}
