<?php

namespace Tests\Unit\Actions\Chat\GuestSupport;

use App\Actions\Chat\GuestSupport\SendGuestMessageAction;
use App\Enums\ChatBannedKeywordAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ErrorCode;
use App\Events\NewChatMessage;
use App\Models\ChatBannedKeyword;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\GuestSupportSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendGuestMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function testCreatesMessageWithCorrectGuestSessionId(): void
    {
        Event::fake([NewChatMessage::class]);

        $session = $this->makeSession();
        $action = app(SendGuestMessageAction::class);

        $result = $action($session, ['body' => 'Hello from guest']);

        $this->assertSame(201, $result->getStatus());
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $session->conversation_id,
            'sender_user_id' => null,
            'guest_support_session_id' => $session->id,
            'body' => 'Hello from guest',
        ]);
    }

    public function testReturns422WhenConversationIsClosed(): void
    {
        Event::fake([NewChatMessage::class]);

        $session = $this->makeSession(['status' => ChatConversationStatus::Closed->value]);
        $action = app(SendGuestMessageAction::class);

        $result = $action($session, ['body' => 'This should fail']);

        $this->assertSame(422, $result->getStatus());
        $this->assertSame(ErrorCode::ChatConversationClosed, $result->getError());
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function testDispatchesNewChatMessageOnSuccess(): void
    {
        Event::fake([NewChatMessage::class]);

        $session = $this->makeSession();
        $action = app(SendGuestMessageAction::class);

        $action($session, ['body' => 'Trigger broadcast']);

        Event::assertDispatched(NewChatMessage::class);
    }

    public function testIdempotentWhenSameClientMessageId(): void
    {
        Event::fake([NewChatMessage::class]);

        $session = $this->makeSession();
        $action = app(SendGuestMessageAction::class);
        $clientId = (string) Str::uuid();

        $action($session, ['body' => 'First send', 'client_message_id' => $clientId]);
        $action($session, ['body' => 'Retry send', 'client_message_id' => $clientId]);

        $this->assertSame(
            1,
            ChatMessage::query()
                ->where('conversation_id', $session->conversation_id)
                ->where('client_message_id', $clientId)
                ->count()
        );
    }

    public function testUpdatesConversationLastMessageFields(): void
    {
        Event::fake([NewChatMessage::class]);

        $session = $this->makeSession();
        $action = app(SendGuestMessageAction::class);
        $result = $action($session, ['body' => 'Update last message']);

        $conversation = ChatConversation::query()->find($session->conversation_id);
        $messageId = $result->getData()['id'];

        $this->assertSame($messageId, $conversation->last_message_id);
        $this->assertNotNull($conversation->last_message_at);
    }

    public function testIdempotentRetryReturnsMaskedBody(): void
    {
        Cache::forget('chat:banned_keywords_v2');
        Event::fake([NewChatMessage::class]);

        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $session = $this->makeSession();
        $action = app(SendGuestMessageAction::class);
        $clientId = (string) Str::uuid();

        $action($session, ['body' => 'lương tôi thấp', 'client_message_id' => $clientId]);

        $retry = $action($session, ['body' => 'lương tôi thấp', 'client_message_id' => $clientId]);

        $this->assertNull($retry->getError());
        $this->assertSame('*** tôi thấp', $retry->getData()['body']);
    }

    private function makeSession(array $conversationOverrides = []): GuestSupportSession
    {
        $conversation = ChatConversation::factory()->create(
            array_merge(
                [
                    'type' => ChatConversationType::GuestSupport->value,
                    'created_by_user_id' => null,
                ],
                $conversationOverrides
            )
        );

        $session = GuestSupportSession::query()->create([
            'device_id' => Str::uuid(),
            'platform' => 'android',
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@test.com',
            'session_token' => Crypt::encryptString(Str::random(64)),
            'conversation_id' => $conversation->id,
        ]);

        $conversation->forceFill(['guest_support_session_id' => $session->id])->save();

        return $session->fresh(['conversation']);
    }
}
