<?php

namespace Tests\Feature\Chat;

use App\Enums\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class MessageListTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testReturnsRecentMessagesByDefault(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        for ($i = 0; $i < 3; $i++) {
            $this->addMessage($conversation, $user);
        }

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}/messages");

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.messages'));
        $this->assertFalse($response->json('data.has_more'));
    }

    public function testSenderNameIsShortenedForSriLankaSender(): void
    {
        // Guards against the column-select regression: the message payload
        // eager-loads the sender with a limited column set, which must include
        // `short_name` for the Sri Lanka short-name display to survive.
        $viewer = User::factory()->create();
        $sender = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $sender->info->update(['nationality' => Nationality::SriLanka->value]);

        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $viewer);
        $this->addParticipant($conversation, $sender);
        $this->addMessage($conversation, $sender);

        $response = $this->actingAs($viewer, 'api')->getJson("/user/chat/conversations/{$conversation->id}/messages");

        $response->assertStatus(200);
        $this->assertSame('THARINDU RAVISHANN', $response->json('data.messages.0.sender_name'));
    }

    public function testReturnsOlderMessagesWithBeforeIdCursor(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $messages = collect(range(1, 5))->map(fn() => $this->addMessage($conversation, $user));

        $cursor = $messages[2]->id; // third message
        $response = $this->actingAs($user, 'api')->getJson(
            "/user/chat/conversations/{$conversation->id}/messages?before_id={$cursor}"
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data.messages'))->pluck('id')->all();
        $this->assertSame([$messages[0]->id, $messages[1]->id], $ids);
    }

    public function testReturnsNewerMessagesWithAfterIdCursor(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $messages = collect(range(1, 5))->map(fn() => $this->addMessage($conversation, $user));

        $cursor = $messages[2]->id;
        $response = $this->actingAs($user, 'api')->getJson(
            "/user/chat/conversations/{$conversation->id}/messages?after_id={$cursor}"
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data.messages'))->pluck('id')->all();
        $this->assertSame([$messages[3]->id, $messages[4]->id], $ids);
    }

    public function testReportsHasMoreWhenMorePagesExist(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        for ($i = 0; $i < 5; $i++) {
            $this->addMessage($conversation, $user);
        }

        $response = $this->actingAs($user, 'api')->getJson(
            "/user/chat/conversations/{$conversation->id}/messages?limit=2"
        );

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.messages'));
        $this->assertTrue($response->json('data.has_more'));
    }

    public function testRejectsBothCursorsTogether(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->getJson(
            "/user/chat/conversations/{$conversation->id}/messages?before_id=5&after_id=2"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_INVALID_CURSOR');
    }

    public function testDeniesMessageListForRemovedParticipant(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user, ['status' => 'removed', 'removed_at' => now()]);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}/messages");

        $response->assertStatus(404);
    }
}
