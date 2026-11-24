<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminChatPinMessageTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    // ─── Permission guards ────────────────────────────────────────────────────

    public function testPinRequiresChatEditPermission(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $message = $this->addMessage($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message",
            ['message_id' => $message->id]
        );

        $response->assertStatus(403);
    }

    public function testUnpinRequiresChatEditPermission(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();

        $response = $this->actingAs($user, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message"
        );

        $response->assertStatus(403);
    }

    public function testPinRequiresActiveAdminParticipant(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $message = $this->addMessage($conversation, $sender);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message",
            ['message_id' => $message->id]
        );

        $response->assertStatus(403);
    }

    public function testPinRequiresAdminRoleNotEmployee(): void
    {
        // $editor holds chat.edit but participates as Employee — pin must be rejected.
        $editor = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $editor, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $editor);

        $response = $this->actingAs($editor, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message",
            ['message_id' => $message->id]
        );

        $response->assertStatus(403);
    }

    public function testUnpinRequiresActiveAdminParticipant(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $message = $this->addMessage($conversation, $sender);
        $conversation
            ->forceFill(['pinned_message_id' => $message->id, 'pinned_at' => now(), 'pinned_by_user_id' => $admin->id])
            ->save();

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message"
        );

        $response->assertStatus(403);
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public function testPinFailsWhenMessageBelongsToDifferentConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $otherConversation = $this->makeConversation();
        $sender = User::factory()->create();
        $message = $this->addMessage($otherConversation, $sender);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message",
            ['message_id' => $message->id]
        );

        $response->assertStatus(422);
    }

    public function testPinValidationRejectsNonExistentMessage(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message",
            ['message_id' => 99999999]
        );

        $response->assertStatus(422);
    }

    // ─── Happy path — pin ─────────────────────────────────────────────────────

    public function testAdminCanPinMessage(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $sender);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message",
            ['message_id' => $message->id]
        );

        $response->assertOk();
        $response->assertJsonPath('data.conversation_id', $conversation->id);
        $response->assertJsonPath('data.pinned_message.id', $message->id);

        $conversation->refresh();
        $this->assertSame($message->id, $conversation->pinned_message_id);
        $this->assertNotNull($conversation->pinned_at);
        $this->assertSame($admin->id, $conversation->pinned_by_user_id);
    }

    public function testPinningNewMessageReplacesExistingPin(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $first = $this->addMessage($conversation, $sender);
        $second = $this->addMessage($conversation, $sender);

        $this->actingAs($admin, 'api')->postJson("/admin/chat/conversations/{$conversation->id}/pinned-message", [
            'message_id' => $first->id,
        ]);
        $this->actingAs($admin, 'api')->postJson("/admin/chat/conversations/{$conversation->id}/pinned-message", [
            'message_id' => $second->id,
        ]);

        $this->assertSame($second->id, $conversation->fresh()->pinned_message_id);
    }

    // ─── Happy path — unpin ───────────────────────────────────────────────────

    public function testAdminCanUnpinMessage(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $sender);
        $conversation
            ->forceFill([
                'pinned_message_id' => $message->id,
                'pinned_at' => now(),
                'pinned_by_user_id' => $admin->id,
            ])
            ->save();

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message"
        );

        $response->assertOk();
        $response->assertJsonPath('data.pinned_message', null);

        $conversation->refresh();
        $this->assertNull($conversation->pinned_message_id);
        $this->assertNull($conversation->pinned_at);
        $this->assertNull($conversation->pinned_by_user_id);
    }

    public function testUnpinIsIdempotentWhenNothingIsPinned(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message"
        );

        $response->assertOk();
        $response->assertJsonPath('data.pinned_message', null);
    }

    // ─── Conversation detail includes pinned_message ──────────────────────────

    public function testConversationDetailIncludesPinnedMessage(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $sender, ['body' => 'Hello!']);
        $conversation
            ->forceFill([
                'pinned_message_id' => $message->id,
                'pinned_at' => now(),
                'pinned_by_user_id' => $admin->id,
            ])
            ->save();

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}");

        $response->assertOk();
        $response->assertJsonPath('data.pinned_message.id', $message->id);
        $response->assertJsonPath('data.pinned_message.body', 'Hello!');
    }

    public function testConversationDetailHasNullPinnedMessageWhenNoneSet(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}");

        $response->assertOk();
        $response->assertJsonPath('data.pinned_message', null);
    }

    // ─── Support conversation type ────────────────────────────────────────────

    public function testAdminParticipantCanPinInSupportConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::Support->value]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $sender);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message",
            ['message_id' => $message->id]
        );

        $response->assertOk();
        $response->assertJsonPath('data.pinned_message.id', $message->id);
    }

    public function testAdminParticipantCanUnpinInSupportConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::Support->value]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $sender);
        $conversation
            ->forceFill([
                'pinned_message_id' => $message->id,
                'pinned_at' => now(),
                'pinned_by_user_id' => $admin->id,
            ])
            ->save();

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/pinned-message"
        );

        $response->assertOk();
        $response->assertJsonPath('data.pinned_message', null);
    }
}
