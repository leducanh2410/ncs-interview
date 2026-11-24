<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatParticipantRole;
use App\Enums\PermissionName;
use App\Events\ChatConversationRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class ReadReceiptChannelTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
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

        require base_path('routes/channels.php');
    }

    public function testTheEventStaysOffTheConversationChannel(): void
    {
        $event = new ChatConversationRead(1, 2, 3, null);

        $channels = array_map(fn($channel) => (string) $channel, $event->broadcastOn());

        $this->assertSame(['private-chat.conversation.1.read'], $channels);
    }

    public function testAnAdminMemberMaySubscribe(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, ['role' => ChatParticipantRole::Admin->value]);

        $this->authorize($admin, $conversation->id)
            ->assertStatus(200)
            ->assertJsonStructure(['auth']);
    }

    public function testAnEmployeeMemberMayNotSubscribe(): void
    {
        $employee = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $employee, ['role' => ChatParticipantRole::Employee->value]);

        $this->authorize($employee, $conversation->id)->assertStatus(403);
    }

    public function testAnEmployeeMaySubscribeToTheConversationItself(): void
    {
        $employee = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $employee, ['role' => ChatParticipantRole::Employee->value]);

        $response = $this->actingAs($employee, 'api')->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chat.conversation.{$conversation->id}",
        ]);

        $response->assertStatus(200);
    }

    public function testANonMemberMayNotSubscribe(): void
    {
        $outsider = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, User::factory()->create());

        $this->authorize($outsider, $conversation->id)->assertStatus(403);
    }

    private function authorize(User $user, int $conversationId)
    {
        return $this->actingAs($user, 'api')->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chat.conversation.{$conversationId}.read",
        ]);
    }
}
