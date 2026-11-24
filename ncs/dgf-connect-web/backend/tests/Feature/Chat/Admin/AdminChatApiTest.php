<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\PermissionName;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminChatApiTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testConversationListRequiresChatViewPermission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->getJson('/admin/chat/conversations');

        $response->assertStatus(403);
    }

    public function testConversationListReturnsParticipantConversations(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->makeConversation(['title' => 'Visible chat']);
        $hiddenConversation = $this->makeConversation(['title' => 'Hidden chat']);
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->getJson('/admin/chat/conversations');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($conversation->id, $ids);
        $this->assertNotContains($hiddenConversation->id, $ids);
    }

    public function testShowsConversationMessagesAndMarksReadViaAdminEndpoints(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ChatView]);
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $participant = $this->addParticipant($conversation, $user, ['unread_count' => 1]);
        $this->addParticipant($conversation, $sender);
        $message = $this->addMessage($conversation, $sender, ['body' => 'hello admin']);
        $conversation
            ->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])
            ->save();

        $this->actingAs($user, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id);

        $this->actingAs($user, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'hello admin');

        $this->actingAs($user, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/read", ['message_id' => $message->id])
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $participant->refresh();
        $this->assertSame($message->id, $participant->last_read_message_id);
    }

    public function testSendsMessageViaAdminEndpoint(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/admin/chat/conversations/{$conversation->id}/messages", [
            'body' => 'admin message',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.body', 'admin message');
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $user->id,
            'body' => 'admin message',
        ]);
    }

    public function testAdminEndpointsStillHideNonParticipantConversations(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->makeConversation();

        $this->actingAs($user, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(404);

        $this->actingAs($user, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}/messages")
            ->assertStatus(404);

        $this->actingAs($user, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/messages", ['body' => 'blocked'])
            ->assertStatus(404);

        $this->assertSame(0, ChatMessage::query()->where('conversation_id', $conversation->id)->count());
    }
}
