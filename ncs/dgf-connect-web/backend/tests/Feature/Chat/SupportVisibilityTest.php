<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\GuestSupportSession;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class SupportVisibilityTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    private function makeUnassignedSupportChat(User $owner): ChatConversation
    {
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['created_by_user_id' => $owner->id]);
        $this->addParticipant($conversation, $owner, ['role' => ChatParticipantRole::Employee->value]);
        $this->addMessage($conversation, $owner, ['body' => 'I need help']);

        return $conversation;
    }

    public function testAdminWithoutChatAssignCannotViewUnassignedSupportChat(): void
    {
        $owner = User::factory()->create();
        $conversation = $this->makeUnassignedSupportChat($owner);
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(404);
        $this->actingAs($admin, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}/messages")
            ->assertStatus(404);
    }

    public function testChatAssignAdminCanViewUnassignedSupportChatButNotSend(): void
    {
        $owner = User::factory()->create();
        $conversation = $this->makeUnassignedSupportChat($owner);
        $admin = $this->createUserWithPermissions([PermissionName::ChatView, PermissionName::ChatAssign]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.self', null);

        $messages = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}/messages");
        $messages->assertOk();
        $this->assertNotEmpty($messages->json('data.messages'));

        // View-only until assigned: sending is rejected.
        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/messages", ['body' => 'On it'])
            ->assertStatus(404);
    }

    public function testChatAssignAdminCanSendAfterAssigningSelf(): void
    {
        $owner = User::factory()->create();
        $conversation = $this->makeUnassignedSupportChat($owner);
        $admin = $this->createUserWithPermissions([
            PermissionName::ChatView,
            PermissionName::ChatAssign,
            PermissionName::ChatSupport,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/support-conversations/{$conversation->id}/assign-admins", [
                'admin_user_ids' => [$admin->id],
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/messages", ['body' => 'On it'])
            ->assertOk();
    }

    public function testRemovedParticipantIsDeniedAccess(): void
    {
        $owner = User::factory()->create();
        $conversation = $this->makeUnassignedSupportChat($owner);
        $removed = User::factory()->create();
        $this->addParticipant($conversation, $removed, [
            'role' => ChatParticipantRole::Employee->value,
        ]);
        // Now remove them.
        $conversation
            ->participants()
            ->where('user_id', $removed->id)
            ->update([
                'status' => ChatParticipantStatus::Removed->value,
                'removed_at' => now(),
            ]);

        $this->actingAs($removed, 'api')
            ->getJson("/user/chat/conversations/{$conversation->id}")
            ->assertStatus(404);
        $this->actingAs($removed, 'api')
            ->getJson("/user/chat/conversations/{$conversation->id}/messages")
            ->assertStatus(404);
        $this->actingAs($removed, 'api')
            ->postJson("/user/chat/conversations/{$conversation->id}/messages", ['body' => 'still here?'])
            ->assertStatus(404);
    }

    public function testSupportListLastMessageShowsGuestSenderName(): void
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);
        $session = GuestSupportSession::query()->create([
            'device_id' => Str::uuid(),
            'platform' => 'ios',
            'guest_name' => 'Support Visitor',
            'guest_email' => 'visitor@example.com',
            'session_token' => Crypt::encryptString(Str::random(64)),
            'session_token_hash' => hash_hmac('sha256', Str::random(64), config('app.key')),
            'conversation_id' => $conversation->id,
        ]);
        $conversation->forceFill(['guest_support_session_id' => $session->id])->save();

        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => null,
            'guest_support_session_id' => $session->id,
            'message_type' => 'text',
            'body' => 'I need some assistance',
        ]);
        $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => now()])->save();

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/support-conversations?status=all');

        $response->assertOk();
        $item = collect($response->json('data.data'))->firstWhere('id', $conversation->id);
        $this->assertNotNull($item, 'GuestSupport conversation not found in support list');
        $this->assertSame($session->guest_name, $item['last_message']['sender_name']);
    }

    public function testOwnerAlwaysHasAccess(): void
    {
        $owner = User::factory()->create();
        $conversation = $this->makeUnassignedSupportChat($owner);

        $this->actingAs($owner, 'api')
            ->getJson("/user/chat/conversations/{$conversation->id}")
            ->assertOk();
        $this->actingAs($owner, 'api')
            ->getJson("/user/chat/conversations/{$conversation->id}/messages")
            ->assertOk();
    }
}
