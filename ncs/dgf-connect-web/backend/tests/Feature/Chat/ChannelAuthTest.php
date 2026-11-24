<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class ChannelAuthTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'dgf-connect',
            'broadcasting.connections.pusher.secret' => 'soketi_app_secret',
            'broadcasting.connections.pusher.app_id' => '1',
        ]);

        Broadcast::channel(
            'chat.conversation.{conversationId}',
            fn($user, $conversationId) => app(\App\Actions\Chat\Access\ResolveParticipantAccessAction::class)(
                $user,
                (int) $conversationId
            ) !== null,
            ['guards' => ['api']]
        );
    }

    private function authorize(User $user, int $conversationId)
    {
        return $this->actingAs($user, 'api')->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chat.conversation.{$conversationId}",
        ]);
    }

    public function testChannelAuthorizesActiveParticipant(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->authorize($user, $conversation->id);

        $response->assertStatus(200);
        $response->assertJsonStructure(['auth']);
    }

    public function testChannelRejectsNonParticipant(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();

        $this->authorize($user, $conversation->id)->assertStatus(403);
    }

    public function testChannelRejectsRemovedParticipant(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user, ['status' => 'removed', 'removed_at' => now()]);

        $this->authorize($user, $conversation->id)->assertStatus(403);
    }
}
