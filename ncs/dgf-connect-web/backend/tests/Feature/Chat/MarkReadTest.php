<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatMessageType;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class MarkReadTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testMarksConversationReadUpToLastMessage(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $participant = $this->addParticipant($conversation, $user, ['unread_count' => 2]);
        $this->addMessage($conversation, $other);
        $last = $this->addMessage($conversation, $other);
        $conversation->forceFill(['last_message_id' => $last->id])->save();

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read");

        $response->assertStatus(200);
        $participant->refresh();
        $this->assertSame($last->id, $participant->last_read_message_id);
        $this->assertSame(0, $participant->unread_count);
    }

    public function testMarksConversationReadUpToSpecificMessageId(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $participant = $this->addParticipant($conversation, $user);
        $first = $this->addMessage($conversation, $other);
        $second = $this->addMessage($conversation, $other);
        $conversation->forceFill(['last_message_id' => $second->id])->save();

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read", [
            'message_id' => $first->id,
        ]);

        $response->assertStatus(200);
        $participant->refresh();
        $this->assertSame($first->id, $participant->last_read_message_id);
        // One message (the second) remains unread.
        $this->assertSame(1, $participant->unread_count);
    }

    public function testSystemMessagesDoNotCountAsUnreadWhenReconcilingReadMarker(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $participant = $this->addParticipant($conversation, $user, ['unread_count' => 1]);
        $readMessage = $this->addMessage($conversation, $other);
        $systemMessage = ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => null,
            'message_type' => ChatMessageType::System->value,
            'body' => 'system',
        ]);
        $conversation->forceFill(['last_message_id' => $systemMessage->id])->save();

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read", [
            'message_id' => $readMessage->id,
        ]);

        $response->assertStatus(200);
        $participant->refresh();
        $this->assertSame($readMessage->id, $participant->last_read_message_id);
        $this->assertSame(0, $participant->unread_count);
    }

    public function testResetsUnreadCountToZeroOnFullRead(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $participant = $this->addParticipant($conversation, $user, ['unread_count' => 3]);
        $last = $this->addMessage($conversation, $other);
        $conversation->forceFill(['last_message_id' => $last->id])->save();

        $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read");

        $this->assertSame(0, $participant->refresh()->unread_count);
    }

    public function testDoesNotMoveReadMarkerBackwards(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $first = $this->addMessage($conversation, $other);
        $second = $this->addMessage($conversation, $other);
        $participant = $this->addParticipant($conversation, $user, ['last_read_message_id' => $second->id]);
        $conversation->forceFill(['last_message_id' => $second->id])->save();

        $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read", [
            'message_id' => $first->id,
        ]);

        $this->assertSame($second->id, $participant->refresh()->last_read_message_id);
    }

    public function testRejectsMessageIdFromAnotherConversation(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $otherConversation = $this->makeConversation();
        $foreignMessage = $this->addMessage($otherConversation, User::factory()->create());

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read", [
            'message_id' => $foreignMessage->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_MESSAGE_NOT_IN_CONVERSATION');
    }

    public function testDeniesMarkReadForRemovedParticipant(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user, ['status' => 'removed', 'removed_at' => now()]);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read");

        $response->assertStatus(404);
    }

    public function testReadMarkerIsIdempotent(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $participant = $this->addParticipant($conversation, $user, ['unread_count' => 1]);
        $last = $this->addMessage($conversation, $other);
        $conversation->forceFill(['last_message_id' => $last->id])->save();

        $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read");
        $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/read");

        $participant->refresh();
        $this->assertSame($last->id, $participant->last_read_message_id);
        $this->assertSame(0, $participant->unread_count);
    }
}
