<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatSystemMessageType;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class SystemMessageTimelineTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testCloseInsertsSystemMessageWithActorAndKey(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create();
        $this->addParticipant($conversation, $admin, ['role' => ChatParticipantRole::Admin->value]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/close")
            ->assertOk();

        $system = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('message_type', ChatMessageType::System->value)
            ->where('body', ChatSystemMessageType::ConversationClosed->value)
            ->firstOrFail();
        $this->assertNull($system->sender_user_id);
        $this->assertSame($admin->id, $system->system_payload['actor_user_id']);
    }

    public function testSystemMessagesAppearInMessageList(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit, PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create();
        $this->addParticipant($conversation, $admin, ['role' => ChatParticipantRole::Admin->value]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/close")
            ->assertOk();

        $messages = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}/messages");
        $messages->assertOk();
        $types = collect($messages->json('data.messages'))->pluck('message_type');
        $this->assertTrue($types->contains(ChatMessageType::System->value));
    }

    public function testSystemMessageUpdatesPreviewButNotActivityOrUnread(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $employee = User::factory()->create();
        $conversation = ChatConversation::factory()->create();
        $this->addParticipant($conversation, $admin, ['role' => ChatParticipantRole::Admin->value]);
        $this->addParticipant($conversation, $employee, ['role' => ChatParticipantRole::Employee->value]);

        // A real employee message sets last_activity_at and bumps the admin's unread counter.
        $this->actingAs($employee, 'api')
            ->postJson("/user/chat/conversations/{$conversation->id}/messages", ['body' => 'A real message'])
            ->assertOk();

        $conversation->refresh();
        $activityBefore = $conversation->last_activity_at;
        $adminUnreadBefore = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $admin->id)
            ->value('unread_count');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/close")
            ->assertOk();

        $conversation->refresh();
        $system = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('message_type', ChatMessageType::System->value)
            ->firstOrFail();

        // Preview moves to the system message...
        $this->assertSame($system->id, $conversation->last_message_id);
        // ...but the inactivity timer and unread counts are untouched by system events.
        $this->assertTrue($activityBefore->equalTo($conversation->last_activity_at));
        $adminUnreadAfter = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $admin->id)
            ->value('unread_count');
        $this->assertSame($adminUnreadBefore, $adminUnreadAfter);
    }

    public function testAssignInsertsSupportAdminAssignedSystemMessage(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $target = $this->createUserWithPermissions([PermissionName::ChatSupport, PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->support()->create();

        $this->actingAs($actor, 'api')
            ->postJson("/admin/chat/support-conversations/{$conversation->id}/assign-admins", [
                'admin_user_ids' => [$target->id],
            ])
            ->assertOk();

        $system = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('message_type', ChatMessageType::System->value)
            ->where('body', ChatSystemMessageType::SupportAdminAssigned->value)
            ->firstOrFail();
        $this->assertSame($target->id, $system->system_payload['user_id']);
    }
}
