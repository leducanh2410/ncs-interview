<?php

namespace Tests\Feature\Chat;

use App\Actions\Chat\Access\ResolveParticipantAccessAction;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class ChatSchemaTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testEnforcesUniqueParticipantPerConversation(): void
    {
        $conversation = $this->makeConversation();
        $user = User::factory()->create();
        $this->addParticipant($conversation, $user);

        $this->expectException(QueryException::class);
        ChatParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
    }

    public function testEnforcesUniqueClientMessageIdPerConversation(): void
    {
        $conversation = $this->makeConversation();
        $user = User::factory()->create();
        $this->addMessage($conversation, $user, ['client_message_id' => 'dup-1']);

        $this->expectException(QueryException::class);
        $this->addMessage($conversation, $user, ['client_message_id' => 'dup-1']);
    }

    public function testAllowsMultipleNullClientMessageIds(): void
    {
        $conversation = $this->makeConversation();
        $user = User::factory()->create();

        $this->addMessage($conversation, $user, ['client_message_id' => null]);
        $this->addMessage($conversation, $user, ['client_message_id' => null]);

        $this->assertSame(2, ChatMessage::query()->where('conversation_id', $conversation->id)->count());
    }

    public function testResolveParticipantAccessReturnsActiveParticipant(): void
    {
        $conversation = $this->makeConversation();
        $user = User::factory()->create();
        $this->addParticipant($conversation, $user);

        $result = app(ResolveParticipantAccessAction::class)($user, $conversation->id);

        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->user_id);
    }

    public function testResolveParticipantAccessReturnsNullForRemovedParticipant(): void
    {
        $conversation = $this->makeConversation();
        $user = User::factory()->create();
        $this->addParticipant($conversation, $user, ['status' => 'removed', 'removed_at' => now()]);

        $this->assertNull(app(ResolveParticipantAccessAction::class)($user, $conversation->id));
    }

    public function testResolveParticipantAccessReturnsNullForNonParticipant(): void
    {
        $conversation = $this->makeConversation();
        $user = User::factory()->create();

        $this->assertNull(app(ResolveParticipantAccessAction::class)($user, $conversation->id));
    }
}
