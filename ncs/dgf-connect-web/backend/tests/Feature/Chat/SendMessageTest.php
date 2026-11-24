<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatParticipantRole;
use App\Events\NewChatMessage;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class SendMessageTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testSendsATextMessage(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'hi there',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.body', 'hi there');
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $user->id,
            'body' => 'hi there',
        ]);
    }

    public function testRejectsEmptyMessageWithNoBodyAndNoFiles(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson(
            "/user/chat/conversations/{$conversation->id}/messages",
            []
        );

        $response->assertStatus(422);
    }

    public function testUpdatesConversationLastMessageFieldsOnSend(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'latest',
        ]);

        $message = ChatMessage::query()->where('conversation_id', $conversation->id)->latest('id')->first();
        $conversation->refresh();
        $this->assertSame($message->id, $conversation->last_message_id);
        $this->assertNotNull($conversation->last_message_at);
        $this->assertNotNull($conversation->last_activity_at);
    }

    public function testIncrementsUnreadForOtherParticipantsOnSend(): void
    {
        $sender = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $sender);
        $otherParticipant = $this->addParticipant($conversation, $other);

        $this->actingAs($sender, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'ping',
        ]);

        $this->assertSame(1, $otherParticipant->refresh()->unread_count);
    }

    public function testDoesNotIncrementSenderUnread(): void
    {
        $sender = User::factory()->create();
        $conversation = $this->makeConversation();
        $senderParticipant = $this->addParticipant($conversation, $sender);

        $this->actingAs($sender, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'ping',
        ]);

        $senderParticipant->refresh();
        $this->assertSame(0, $senderParticipant->unread_count);
        $this->assertNotNull($senderParticipant->last_read_message_id);
    }

    public function testReturnsExistingMessageOnDuplicateClientMessageId(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $clientId = (string) Str::uuid();

        $first = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'once',
            'client_message_id' => $clientId,
        ]);
        $second = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'once again',
            'client_message_id' => $clientId,
        ]);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ChatMessage::query()->where('conversation_id', $conversation->id)->count());
    }

    public function testDoesNotDoubleIncrementUnreadOnDuplicateSend(): void
    {
        $sender = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $sender);
        $otherParticipant = $this->addParticipant($conversation, $other);
        $clientId = (string) Str::uuid();

        $payload = ['body' => 'ping', 'client_message_id' => $clientId];
        $this->actingAs($sender, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", $payload);
        $this->actingAs($sender, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", $payload);

        $this->assertSame(1, $otherParticipant->refresh()->unread_count);
    }

    public function testDeniesSendForNonParticipant(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'nope',
        ]);

        $response->assertStatus(404);
    }

    public function testEmployeeCannotSendTextWhenEmployeeMessagePermissionIsDisabled(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation(['employee_can_send_message' => false]);
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'blocked',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('chat_messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $user->id,
            'body' => 'blocked',
        ]);
    }

    public function testAdminParticipantCanSendWhenEmployeeMessagePermissionIsDisabled(): void
    {
        $admin = User::factory()->create();
        $conversation = $this->makeConversation(['employee_can_send_message' => false]);
        $this->addParticipant($conversation, $admin, ['role' => ChatParticipantRole::Admin->value]);

        $response = $this->actingAs($admin, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'admin allowed',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $admin->id,
            'body' => 'admin allowed',
        ]);
    }

    public function testDispatchesNewChatMessageEventAfterSend(): void
    {
        Event::fake([NewChatMessage::class]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'broadcast me',
        ]);

        Event::assertDispatched(
            NewChatMessage::class,
            fn(NewChatMessage $event) => $event->message->conversation_id === $conversation->id
        );
    }

    public function testDoesNotDispatchEventOnDuplicateSend(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $clientId = (string) Str::uuid();
        $payload = ['body' => 'once', 'client_message_id' => $clientId];

        // First send creates the message.
        $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", $payload);

        Event::fake([NewChatMessage::class]);
        $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", $payload);
        Event::assertNotDispatched(NewChatMessage::class);
    }
}
