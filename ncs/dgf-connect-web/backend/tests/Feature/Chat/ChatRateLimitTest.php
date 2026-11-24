<?php

namespace Tests\Feature\Chat;

use App\Enums\ErrorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class ChatRateLimitTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testSendMessageIsRateLimited(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $url = "/user/chat/conversations/{$conversation->id}/messages";

        // chat-send-limit allows 30/min; the 31st request is throttled.
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user, 'api')
                ->postJson($url, ['body' => "msg {$i}"])
                ->assertOk();
        }

        // The app maps throttling to 400 + ErrorCode::Throttle (see bootstrap/app.php).
        $this->actingAs($user, 'api')
            ->postJson($url, ['body' => 'over the limit'])
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::Throttle->value);
    }

    public function testSupportCreateIsRateLimited(): void
    {
        $user = User::factory()->create();
        $url = '/user/chat/support-conversations';

        // chat-support-create-limit allows 5/min; the 6th request is throttled.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user, 'api')
                ->postJson($url, ['title' => "Help {$i}", 'body' => "Issue {$i}"])
                ->assertOk();
        }

        $this->actingAs($user, 'api')
            ->postJson($url, ['title' => 'Too many', 'body' => 'Throttled'])
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::Throttle->value);
    }
}
