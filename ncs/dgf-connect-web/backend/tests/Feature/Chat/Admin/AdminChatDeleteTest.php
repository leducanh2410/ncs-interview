<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\PermissionName;
use App\Events\ChatConversationDeleted;
use App\Events\ChatMessageDeleted;
use App\Models\ChatAttachment;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminChatDeleteTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    // ─── DELETE MESSAGE — permission & role guards ────────────────────────────

    public function testDeleteMessageForbiddenWithoutChatPermission(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $message = $this->addMessage($conversation, $user);

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(403);
    }

    public function testDeleteMessageRequiresDeleteMessagePermission(): void
    {
        // Holds other chat permissions (including conversation delete) but NOT chat.delete_message.
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit, PermissionName::ChatDeleteConversation]);
        $conversation = $this->makeConversation();
        $message = $this->addMessage($conversation, $admin);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(403);
    }

    public function testDeleteMessageRequiresActorToBeActiveParticipant(): void
    {
        $deleter = $this->createChatDeleter();
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $message = $this->addMessage($conversation, $sender);
        // deleter has the permission but is NOT added as a participant

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(403);
    }

    public function testDeleteMessageRejects404ForMessageNotInConversation(): void
    {
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $otherConversation = $this->makeConversation();
        $message = $this->addMessage($otherConversation, $deleter);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(404);
    }

    public function testDeleteMessageRejectsSystemMessages(): void
    {
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $systemMessage = ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'message_type' => ChatMessageType::System->value,
            'body' => 'system',
        ]);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$systemMessage->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('chat_messages', ['id' => $systemMessage->id]);
    }

    // ─── DELETE MESSAGE — happy path ──────────────────────────────────────────

    public function testDeleteMessageRemovesMessageFromDatabase(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $deleter);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
    }

    public function testDeleteMessageWorksInSupportConversation(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $sender = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::Support->value]);
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $sender);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
    }

    public function testDeleteMessageDispatchesScalarDeletedEventAndWritesAuditLog(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $deleter, ['body' => 'sensitive message body']);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(200);

        Event::assertDispatched(
            ChatMessageDeleted::class,
            fn(ChatMessageDeleted $event) => $event->id === $message->id && $event->conversationId === $conversation->id
        );

        $audit = ChatAuditLog::query()->where('action', ChatAuditAction::MessageDeleted->value)->firstOrFail();
        $this->assertSame($conversation->id, $audit->conversation_id);
        $this->assertSame($deleter->id, $audit->actor_user_id);
        $this->assertSame($message->id, $audit->payload['message_id']);
        $this->assertSame('sensitive message body', $audit->payload['body_preview']);
    }

    public function testDeleteMessageRemovesAttachmentRecordAndFile(): void
    {
        Storage::fake('local');
        Event::fake();

        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $deleter);

        Storage::disk('local')->put('chat/file.jpg', 'content');
        $attachment = ChatAttachment::create([
            'disk' => 'local',
            'path' => 'chat/file.jpg',
            'original_name' => 'file.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 7,
        ]);
        ChatMessageAttachment::create([
            'message_id' => $message->id,
            'attachment_id' => $attachment->id,
            'position' => 0,
        ]);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('chat_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing('chat/file.jpg');
    }

    public function testDeleteMessageKeepsAttachmentStillReferencedByAnotherMessage(): void
    {
        Storage::fake('local');
        Event::fake();

        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $deleter);
        $otherMessage = $this->addMessage($conversation, $deleter);

        Storage::disk('local')->put('chat/shared-file.jpg', 'content');
        $attachment = ChatAttachment::create([
            'disk' => 'local',
            'path' => 'chat/shared-file.jpg',
            'original_name' => 'shared-file.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 7,
        ]);
        ChatMessageAttachment::create([
            'message_id' => $message->id,
            'attachment_id' => $attachment->id,
            'position' => 0,
        ]);
        ChatMessageAttachment::create([
            'message_id' => $otherMessage->id,
            'attachment_id' => $attachment->id,
            'position' => 0,
        ]);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
        $this->assertDatabaseHas('chat_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseHas('chat_message_attachments', [
            'message_id' => $otherMessage->id,
            'attachment_id' => $attachment->id,
        ]);
        Storage::disk('local')->assertExists('chat/shared-file.jpg');
    }

    public function testDeleteLastMessageUpdatesConversationLastMessage(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $first = $this->addMessage($conversation, $deleter, ['body' => 'first']);
        $last = $this->addMessage($conversation, $deleter, ['body' => 'last']);
        $conversation->forceFill(['last_message_id' => $last->id, 'last_message_at' => $last->created_at])->save();

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$last->id}")
            ->assertStatus(200);

        $conversation->refresh();
        $this->assertSame($first->id, $conversation->last_message_id);
    }

    public function testDeleteNonLastMessageDoesNotChangeLastMessage(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $first = $this->addMessage($conversation, $deleter, ['body' => 'first']);
        $last = $this->addMessage($conversation, $deleter, ['body' => 'last']);
        $conversation->forceFill(['last_message_id' => $last->id, 'last_message_at' => $last->created_at])->save();

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$first->id}")
            ->assertStatus(200);

        $conversation->refresh();
        $this->assertSame($last->id, $conversation->last_message_id);
    }

    public function testDeleteMessageRecalculatesUnreadCounts(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $adminParticipant = $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $senderParticipant = $this->addParticipant($conversation, $sender, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $readMessage = $this->addMessage($conversation, $sender, ['body' => 'read']);
        $unreadMessage = $this->addMessage($conversation, $sender, ['body' => 'unread']);
        $adminParticipant
            ->forceFill([
                'last_read_message_id' => $readMessage->id,
                'unread_count' => 1,
            ])
            ->save();
        $conversation
            ->forceFill([
                'last_message_id' => $unreadMessage->id,
                'last_message_at' => $unreadMessage->created_at,
                'last_activity_at' => $unreadMessage->created_at,
            ])
            ->save();

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$unreadMessage->id}")
            ->assertStatus(200);

        $this->assertSame(0, $adminParticipant->refresh()->unread_count);
        $this->assertSame(0, $senderParticipant->refresh()->unread_count);
    }

    public function testDeleteMessageRecalculatesUnreadCountsPerActiveParticipant(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $sender = User::factory()->create();
        $observer = User::factory()->create();
        $removedUser = User::factory()->create();
        $conversation = $this->makeConversation();
        $anchor = now()->startOfSecond();

        $adminParticipant = $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => $anchor->copy()->subMinutes(11),
            'unread_count' => 99,
        ]);
        $senderParticipant = $this->addParticipant($conversation, $sender, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => $anchor->copy()->subMinutes(11),
            'unread_count' => 99,
        ]);
        $observerParticipant = $this->addParticipant($conversation, $observer, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => $anchor->copy()->subMinutes(5),
            'unread_count' => 99,
        ]);
        $removedParticipant = $this->addParticipant($conversation, $removedUser, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Removed->value,
            'unread_count' => 99,
        ]);

        $readMessage = $this->addMessage($conversation, $sender, [
            'body' => 'already read',
            'created_at' => $anchor->copy()->subMinutes(10),
            'updated_at' => $anchor->copy()->subMinutes(10),
        ]);
        $this->addMessage($conversation, $sender, [
            'body' => 'still unread for admin',
            'created_at' => $anchor->copy()->subMinutes(4),
            'updated_at' => $anchor->copy()->subMinutes(4),
        ]);
        $this->addMessage($conversation, $deleter, [
            'body' => 'own admin message',
            'created_at' => $anchor->copy()->subMinutes(3),
            'updated_at' => $anchor->copy()->subMinutes(3),
        ]);
        $deletedMessage = $this->addMessage($conversation, $sender, [
            'body' => 'deleted unread message',
            'created_at' => $anchor->copy()->subMinutes(2),
            'updated_at' => $anchor->copy()->subMinutes(2),
        ]);
        ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => null,
            'message_type' => ChatMessageType::System->value,
            'body' => 'system message',
            'created_at' => $anchor->copy()->subMinute(),
            'updated_at' => $anchor->copy()->subMinute(),
        ]);
        $latestMessage = ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => null,
            'message_type' => ChatMessageType::Text->value,
            'body' => 'guest-style message',
            'created_at' => $anchor,
            'updated_at' => $anchor,
        ]);

        $adminParticipant->forceFill(['last_read_message_id' => $readMessage->id])->save();
        $conversation
            ->forceFill([
                'last_message_id' => $latestMessage->id,
                'last_message_at' => $latestMessage->created_at,
                'last_activity_at' => $latestMessage->created_at,
            ])
            ->save();

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$deletedMessage->id}")
            ->assertStatus(200);

        $this->assertSame(2, $adminParticipant->refresh()->unread_count);
        $this->assertSame(2, $senderParticipant->refresh()->unread_count);
        $this->assertSame(3, $observerParticipant->refresh()->unread_count);
        $this->assertSame(99, $removedParticipant->refresh()->unread_count);
    }

    public function testDeleteMessageUpdatesLastActivityAtToPreviousRealMessage(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $firstRealMessage = $this->addMessage($conversation, $sender, [
            'body' => 'first real message',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        $systemMessage = ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => null,
            'message_type' => ChatMessageType::System->value,
            'body' => 'system',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
        $lastRealMessage = $this->addMessage($conversation, $sender, [
            'body' => 'last real message',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $conversation
            ->forceFill([
                'last_message_id' => $lastRealMessage->id,
                'last_message_at' => $lastRealMessage->created_at,
                'last_activity_at' => $lastRealMessage->created_at,
            ])
            ->save();

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$lastRealMessage->id}")
            ->assertStatus(200);

        $conversation->refresh();
        $this->assertSame($systemMessage->id, $conversation->last_message_id);
        $this->assertTrue($firstRealMessage->created_at->equalTo($conversation->last_activity_at));
    }

    public function testDeletePinnedMessageClearsConversationPinnedMessage(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $deleter);
        $conversation
            ->forceFill([
                'pinned_message_id' => $message->id,
                'pinned_at' => now(),
                'pinned_by_user_id' => $deleter->id,
            ])
            ->save();

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}/messages/{$message->id}")
            ->assertStatus(200);

        $conversation->refresh();
        $this->assertNull($conversation->pinned_message_id);
        $this->assertNull($conversation->pinned_at);
        $this->assertNull($conversation->pinned_by_user_id);
    }

    // ─── DELETE CONVERSATION — permission & role guards ───────────────────────

    public function testDeleteConversationForbiddenWithoutChatPermission(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(403);
    }

    public function testDeleteConversationRequiresDeleteConversationPermission(): void
    {
        // Holds other chat permissions (including message delete) but NOT chat.delete_conversation.
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit, PermissionName::ChatDeleteMessage]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(403);
    }

    public function testDeleteConversationRequiresActorToBeActiveParticipant(): void
    {
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation();
        // deleter has the permission but is NOT added as a participant

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(403);
    }

    // ─── DELETE CONVERSATION — happy path ─────────────────────────────────────

    public function testDeleteConversationRemovesConversationAndMessages(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $sender);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
    }

    public function testDeleteConversationDispatchesScalarDeletedEventAndKeepsAuditLog(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation(['title' => 'Sensitive chat']);
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addMessage($conversation, $deleter);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(200);

        Event::assertDispatched(
            ChatConversationDeleted::class,
            fn(ChatConversationDeleted $event) => $event->id === $conversation->id
        );

        $audit = ChatAuditLog::query()->where('action', ChatAuditAction::ConversationDeleted->value)->firstOrFail();
        $this->assertNull($audit->conversation_id);
        $this->assertSame($deleter->id, $audit->actor_user_id);
        $this->assertSame($conversation->id, $audit->payload['conversation_id']);
        $this->assertSame('Sensitive chat', $audit->payload['title']);
        $this->assertSame(1, $audit->payload['message_count']);
    }

    public function testDeleteConversationPreservesConversationIdInExistingAuditPayloads(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();
        $conversation = $this->makeConversation(['title' => 'Audited chat']);
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $existingAudit = ChatAuditLog::query()->create([
            'conversation_id' => $conversation->id,
            'actor_user_id' => $deleter->id,
            'action' => ChatAuditAction::ConversationCreated->value,
            'payload' => ['title' => 'Audited chat'],
        ]);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(200);

        $existingAudit->refresh();
        $this->assertNull($existingAudit->conversation_id);
        $this->assertSame($conversation->id, $existingAudit->payload['conversation_id']);
    }

    public function testDeleteConversationRemovesAttachmentsAndFiles(): void
    {
        Storage::fake('local');
        Event::fake();

        $deleter = $this->createChatDeleter();
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $deleter, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $message = $this->addMessage($conversation, $sender);

        Storage::disk('local')->put('chat/conv-file.jpg', 'content');
        $attachment = ChatAttachment::create([
            'disk' => 'local',
            'path' => 'chat/conv-file.jpg',
            'original_name' => 'conv-file.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 7,
        ]);
        ChatMessageAttachment::create([
            'message_id' => $message->id,
            'attachment_id' => $attachment->id,
            'position' => 0,
        ]);

        $this->actingAs($deleter, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('chat_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing('chat/conv-file.jpg');
    }

    public function testDeleteConversationWorksForAllTypes(): void
    {
        Event::fake();
        $deleter = $this->createChatDeleter();

        $types = [
            ChatConversationType::AdminGroup->value,
            ChatConversationType::Support->value,
            ChatConversationType::GuestSupport->value,
        ];

        foreach ($types as $type) {
            $conversation = ChatConversation::factory()->create(['type' => $type]);
            $this->addParticipant($conversation, $deleter, [
                'role' => ChatParticipantRole::Admin->value,
                'status' => ChatParticipantStatus::Active->value,
            ]);

            $this->actingAs($deleter, 'api')
                ->deleteJson("/admin/chat/conversations/{$conversation->id}")
                ->assertStatus(200);

            $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createChatDeleter(): User
    {
        return $this->createUserWithPermissions([
            PermissionName::ChatDeleteMessage,
            PermissionName::ChatDeleteConversation,
        ]);
    }
}
