<?php

namespace Tests\Unit\Actions\Chat\GuestSupport;

use App\Actions\Chat\GuestSupport\CreateGuestSupportConversationAction;
use App\Enums\ChatBannedKeywordAction;
use App\Enums\ChatConversationType;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Events\ChatConversationCreated;
use App\Events\NewChatMessage;
use App\Jobs\AutoAssignSupportAdminJob;
use App\Models\ChatBannedKeyword;
use App\Models\ChatConversation;
use App\Models\GuestSupportSession;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CreateGuestSupportConversationActionTest extends TestCase
{
    use RefreshDatabase;

    public function testCreatesSessionConversationAndMessages(): void
    {
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);

        $action = app(CreateGuestSupportConversationAction::class);
        $result = $action($this->makeParams());

        $this->assertNull($result->getError());
        $this->assertNotNull($result->getData()['conversation_id']);
        $this->assertNotNull($result->getData()['guest_session_token']);

        $this->assertDatabaseCount('guest_support_sessions', 1);
        // Scoped by type: the internal admin group always exists, so a table-wide count would
        // measure the seeded system chat rather than what this action created.
        $this->assertSame(
            1,
            ChatConversation::query()->where('type', ChatConversationType::GuestSupport->value)->count()
        );
        $this->assertDatabaseCount('chat_messages', 2); // system + opening message

        $this->assertDatabaseHas('chat_conversations', [
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'body' => 'Hello, I need help.',
            'sender_user_id' => null,
        ]);
    }

    public function testSessionTokenInDbIsDifferentFromPlaintextReturned(): void
    {
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);

        $action = app(CreateGuestSupportConversationAction::class);
        $result = $action($this->makeParams());

        $plaintext = $result->getData()['guest_session_token'];
        $session = GuestSupportSession::query()->first();

        $this->assertNotEquals($plaintext, $session->session_token);
        $this->assertSame($plaintext, Crypt::decryptString($session->session_token));
    }

    public function testReturns409WhenSameDeviceIdAlreadyExists(): void
    {
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);

        $action = app(CreateGuestSupportConversationAction::class);
        $action($this->makeParams(['device_id' => 'dup-device']));

        $result = $action($this->makeParams(['device_id' => 'dup-device', 'email' => 'other@example.com']));

        $this->assertSame(409, $result->getStatus());
        $this->assertSame(ErrorCode::GuestSessionAlreadyExists, $result->getError());
        $this->assertDatabaseCount('guest_support_sessions', 1);
    }

    public function testDispatchesNewChatMessageAndBatchesChatConversationCreated(): void
    {
        Bus::fake([AutoAssignSupportAdminJob::class]);
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);

        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();
        $this->grantChatAssign($admin1);
        $this->grantChatAssign($admin2);

        $action = app(CreateGuestSupportConversationAction::class);
        $action($this->makeParams());

        Event::assertDispatched(NewChatMessage::class);

        // All admins must be batched into a single event, not one event per admin.
        Event::assertDispatchedTimes(ChatConversationCreated::class, 1);
        Event::assertDispatched(ChatConversationCreated::class, function (ChatConversationCreated $event) use (
            $admin1,
            $admin2
        ) {
            return in_array((int) $admin1->id, $event->userIds, true) &&
                in_array((int) $admin2->id, $event->userIds, true);
        });
    }

    public function testConversationLinkedToSession(): void
    {
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);

        $action = app(CreateGuestSupportConversationAction::class);
        $result = $action($this->makeParams());

        $conversationId = $result->getData()['conversation_id'];
        $session = GuestSupportSession::query()->first();

        $this->assertSame($conversationId, $session->conversation_id);

        $conversation = ChatConversation::query()->find($conversationId);
        $this->assertSame($session->id, $conversation->guest_support_session_id);
    }

    public function testBlockedKeywordReturns422WithoutCreatingConversation(): void
    {
        Cache::forget('chat:banned_keywords_v2');
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);

        ChatBannedKeyword::factory()->create([
            'keyword' => 'forbidden',
            'action' => ChatBannedKeywordAction::Block->value,
            'is_active' => true,
        ]);

        $result = app(CreateGuestSupportConversationAction::class)(
            $this->makeParams(['content' => 'this is forbidden content'])
        );

        $this->assertSame(422, $result->getStatus());
        $this->assertSame(ErrorCode::ChatMessageContainsBannedKeyword, $result->getError());
        $this->assertDatabaseMissing('chat_conversations', ['type' => ChatConversationType::GuestSupport->value]);
        $this->assertDatabaseCount('guest_support_sessions', 0);
    }

    private function makeParams(array $overrides = []): array
    {
        return array_merge(
            [
                'name' => 'John Guest',
                'email' => 'john@example.com',
                'content' => 'Hello, I need help.',
                'device_id' => 'device-abc-123',
                'platform' => 'ios',
                'fcm_token' => 'fcm-token-xyz',
            ],
            $overrides
        );
    }

    private function grantChatAssign(User $user): void
    {
        $perm = Permission::query()->firstOrCreate(
            ['name' => PermissionName::ChatAssign->value],
            ['display_name' => 'Chat Assign']
        );
        $user->permissions()->syncWithoutDetaching([$perm->id]);
    }
}
