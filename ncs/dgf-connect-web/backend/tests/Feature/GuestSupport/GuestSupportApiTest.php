<?php

namespace Tests\Feature\GuestSupport;

use App\Actions\Chat\GuestSupport\SendGuestMessageAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\SupportType;
use App\Events\ChatConversationCreated;
use App\Events\NewChatMessage;
use App\Jobs\AutoAssignSupportAdminJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\GuestSupportSession;
use App\Services\TurnstileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class GuestSupportApiTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Create guest support conversation
    // ──────────────────────────────────────────────

    public function testCreateGuestSessionHappyPath(): void
    {
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);
        $this->mockTurnstile(true);

        $response = $this->postJson('/guest/chat/support-conversations', [
            'name' => 'John',
            'email' => 'john@example.com',
            'content' => 'I need help',
            'device_id' => 'device-001',
            'platform' => 'ios',
            'turnstile_token' => 'fake-token',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['conversation_id', 'guest_session_token']]);

        $this->assertDatabaseHas('guest_support_sessions', [
            'device_id' => 'device-001',
            'guest_name' => 'John',
        ]);
        $this->assertDatabaseHas('chat_conversations', [
            'type' => ChatConversationType::GuestSupport->value,
        ]);
    }

    public function testCreateGuestSessionReturns409ForDuplicateDeviceId(): void
    {
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);
        $this->mockTurnstile(true);

        $this->postJson('/guest/chat/support-conversations', [
            'name' => 'John',
            'email' => 'john@example.com',
            'content' => 'First',
            'device_id' => 'dup-device',
            'platform' => 'android',
            'turnstile_token' => 'token',
        ])->assertOk();

        $response = $this->postJson('/guest/chat/support-conversations', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'content' => 'Second',
            'device_id' => 'dup-device',
            'platform' => 'android',
            'turnstile_token' => 'token',
        ]);

        $response->assertStatus(409)->assertJsonPath('error', ErrorCode::GuestSessionAlreadyExists->value);
    }

    public function testCreateGuestSessionReturns400WhenTurnstileFails(): void
    {
        $this->mockTurnstile(false);

        $response = $this->postJson('/guest/chat/support-conversations', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'content' => 'Spam',
            'device_id' => 'bot-device',
            'platform' => 'android',
            'turnstile_token' => 'bad-token',
        ]);

        $response->assertStatus(400)->assertJsonPath('error', ErrorCode::CaptchaFailed->value);
        $this->assertDatabaseCount('guest_support_sessions', 0);
    }

    // ──────────────────────────────────────────────
    // Check active session
    // ──────────────────────────────────────────────

    public function testCheckActiveReturnsSessionTokenForExistingDevice(): void
    {
        $this->mockTurnstile(true);
        [$session, $plaintext, $conversation] = $this->createGuestSession(['device_id' => 'known-device']);

        $response = $this->postJson('/guest/chat/conversations/active', [
            'device_id' => 'known-device',
            'turnstile_token' => 'fake-token',
        ]);

        $response->assertOk()->assertJsonPath('data.has_active_session', true);
        $this->assertSame($plaintext, $response->json('data.guest_session_token'));
    }

    public function testCheckActiveUpdatesFcmTokenWhenProvided(): void
    {
        $this->mockTurnstile(true);
        [$session] = $this->createGuestSession([
            'device_id' => 'token-refresh-device',
            'fcm_token' => 'old-fcm-token',
        ]);

        $this->postJson('/guest/chat/conversations/active', [
            'device_id' => 'token-refresh-device',
            'turnstile_token' => 'fake-token',
            'fcm_token' => 'new-fcm-token',
        ])->assertOk();

        $this->assertDatabaseHas('guest_support_sessions', [
            'id' => $session->id,
            'fcm_token' => 'new-fcm-token',
        ]);
    }

    public function testCheckActiveDoesNotClearFcmTokenWhenNotProvided(): void
    {
        $this->mockTurnstile(true);
        [$session] = $this->createGuestSession([
            'device_id' => 'no-token-device',
            'fcm_token' => 'existing-token',
        ]);

        $this->postJson('/guest/chat/conversations/active', [
            'device_id' => 'no-token-device',
            'turnstile_token' => 'fake-token',
        ])->assertOk();

        $this->assertDatabaseHas('guest_support_sessions', [
            'id' => $session->id,
            'fcm_token' => 'existing-token',
        ]);
    }

    public function testCheckActiveReturnsNoSessionForUnknownDevice(): void
    {
        $this->mockTurnstile(true);

        $response = $this->postJson('/guest/chat/conversations/active', [
            'device_id' => 'unknown-device',
            'turnstile_token' => 'fake-token',
        ]);

        $response->assertOk()->assertJsonPath('data.has_active_session', false);
    }

    // ──────────────────────────────────────────────
    // Conversation detail
    // ──────────────────────────────────────────────

    public function testShowConversationReturnsDetail(): void
    {
        [$session, $plaintext, $conversation] = $this->createGuestSession();

        $response = $this->withToken($plaintext)->getJson("/guest/chat/conversations/{$conversation->id}");

        $response->assertOk()->assertJsonPath('data.id', $conversation->id);
    }

    public function testShowConversationReturns401WithoutToken(): void
    {
        [$session, , $conversation] = $this->createGuestSession();

        $this->getJson("/guest/chat/conversations/{$conversation->id}")->assertStatus(401);
    }

    // ──────────────────────────────────────────────
    // Send and list messages
    // ──────────────────────────────────────────────

    public function testSendAndRetrieveMessages(): void
    {
        Event::fake([NewChatMessage::class]);
        [$session, $plaintext, $conversation] = $this->createGuestSession();

        $send = $this->withToken($plaintext)->postJson("/guest/chat/conversations/{$conversation->id}/messages", [
            'body' => 'Hello from guest',
        ]);
        $send->assertStatus(201);

        $list = $this->withToken($plaintext)->getJson("/guest/chat/conversations/{$conversation->id}/messages");
        $list->assertOk();

        $messages = collect($list->json('data.messages'));
        $this->assertTrue($messages->contains(fn($m) => $m['body'] === 'Hello from guest'));
    }

    public function testSendMessageReturns422WhenConversationIsClosed(): void
    {
        [$session, $plaintext, $conversation] = $this->createGuestSession();
        $conversation->forceFill(['status' => ChatConversationStatus::Closed->value])->save();

        $response = $this->withToken($plaintext)->postJson("/guest/chat/conversations/{$conversation->id}/messages", [
            'body' => 'Should fail',
        ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────
    // Reopen
    // ──────────────────────────────────────────────

    public function testReopenClosedConversation(): void
    {
        [$session, $plaintext, $conversation] = $this->createGuestSession();
        $conversation->forceFill(['status' => ChatConversationStatus::Closed->value])->save();

        $response = $this->withToken($plaintext)->postJson("/guest/chat/conversations/{$conversation->id}/reopen");

        $response->assertOk()->assertJsonPath('data.status', ChatConversationStatus::Open->value);
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'status' => ChatConversationStatus::Open->value,
        ]);
    }

    // ──────────────────────────────────────────────
    // Admin API extension
    // ──────────────────────────────────────────────

    public function testAdminSupportListIncludesGuestConversations(): void
    {
        [$session, , $conversation] = $this->createGuestSession();
        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/support-conversations?status=all');

        $response->assertOk();
        $items = collect($response->json('data.data'));
        $item = $items->firstWhere('id', $conversation->id);

        $this->assertNotNull($item);
        $this->assertTrue($item['is_guest']);
        $this->assertSame($session->guest_name, $item['guest_name']);
        $this->assertSame($session->guest_email, $item['guest_email']);
    }

    public function testAdminConversationDetailIncludesGuestInfo(): void
    {
        [$session, , $conversation] = $this->createGuestSession();
        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign, PermissionName::ChatView]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}");

        $response->assertOk();
        $guestInfo = $response->json('data.guest_info');

        $this->assertNotNull($guestInfo);
        $this->assertSame($session->guest_name, $guestInfo['name']);
        $this->assertSame($session->guest_email, $guestInfo['email']);
    }

    // ──────────────────────────────────────────────
    // Regression gates for multi-guest-support bugs
    // ──────────────────────────────────────────────

    public function testCheckActiveReturnsLatestSessionForDuplicateDeviceId(): void
    {
        $this->mockTurnstile(true);

        $deviceId = 'dup-device-order-test';

        $conversation1 = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);
        $plaintext1 = Str::random(64);
        $session1 = GuestSupportSession::query()->create([
            'device_id' => $deviceId,
            'platform' => 'ios',
            'guest_name' => 'Old Guest',
            'guest_email' => 'old@example.com',
            'session_token' => Crypt::encryptString($plaintext1),
            'session_token_hash' => hash_hmac('sha256', $plaintext1, config('app.key')),
            'conversation_id' => $conversation1->id,
        ]);
        $conversation1->forceFill(['guest_support_session_id' => $session1->id])->save();

        $conversation2 = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);
        $plaintext2 = Str::random(64);
        $session2 = GuestSupportSession::query()->create([
            'device_id' => $deviceId,
            'platform' => 'ios',
            'guest_name' => 'New Guest',
            'guest_email' => 'new@example.com',
            'session_token' => Crypt::encryptString($plaintext2),
            'session_token_hash' => hash_hmac('sha256', $plaintext2, config('app.key')),
            'conversation_id' => $conversation2->id,
        ]);
        $conversation2->forceFill(['guest_support_session_id' => $session2->id])->save();

        $response = $this->postJson('/guest/chat/conversations/active', [
            'device_id' => $deviceId,
            'turnstile_token' => 'fake-token',
        ]);

        $response->assertOk();
        // Must return the newer (higher id) session — not an arbitrary one.
        $this->assertSame($plaintext2, $response->json('data.guest_session_token'));
    }

    public function testSendMessageIncrementsAdminUnreadCount(): void
    {
        Event::fake([NewChatMessage::class]);
        Bus::fake();

        [$session, , $conversation] = $this->createGuestSession();

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $participant = $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
            'unread_count' => 0,
        ]);

        app(SendGuestMessageAction::class)($session, ['body' => 'Hello, is anyone there?']);

        $this->assertSame(1, $participant->fresh()->unread_count);
    }

    public function testGuestMessageListIncludesGuestSenderName(): void
    {
        [$session, , $conversation] = $this->createGuestSession();

        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => null,
            'guest_support_session_id' => $session->id,
            'message_type' => 'text',
            'body' => 'Guest question body',
        ]);

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign, PermissionName::ChatView]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}/messages");

        $response->assertOk();

        $guestMsg = collect($response->json('data.messages'))->firstWhere('id', $message->id);

        $this->assertNotNull($guestMsg, 'Guest message not found in response');
        $this->assertNull($guestMsg['sender_user_id']);
        $this->assertSame($session->guest_name, $guestMsg['sender_name']);
    }

    public function testGuestPusherAuthReturnsAuthSignature(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'dgf-connect',
            'broadcasting.connections.pusher.secret' => 'soketi_app_secret',
            'broadcasting.connections.pusher.app_id' => '1',
        ]);

        [$session, $plaintext, $conversation] = $this->createGuestSession();

        $response = $this->withToken($plaintext)->postJson('/guest/pusher/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chat.conversation.{$conversation->id}",
        ]);

        $response->assertOk()->assertJsonStructure(['auth']);
    }

    public function testGuestPusherAuthRejectsForeignConversation(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'dgf-connect',
            'broadcasting.connections.pusher.secret' => 'soketi_app_secret',
            'broadcasting.connections.pusher.app_id' => '1',
        ]);

        [$sessionA, $plaintextA] = $this->createGuestSession();
        [, , $conversationB] = $this->createGuestSession();

        $response = $this->withToken($plaintextA)->postJson('/guest/pusher/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chat.conversation.{$conversationB->id}",
        ]);

        $response->assertStatus(403)->assertJsonPath('error', 'E_FORBIDDEN');
    }

    // ──────────────────────────────────────────────
    // Support type tests
    // ──────────────────────────────────────────────

    public function testGuestCreateSupportWithSupportType(): void
    {
        Bus::fake([AutoAssignSupportAdminJob::class]);
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);
        $this->mockTurnstile(true);

        $response = $this->postJson('/guest/chat/support-conversations', [
            'name' => 'John',
            'email' => 'john@example.com',
            'content' => 'Salary issue.',
            'device_id' => 'device-type-001',
            'platform' => 'ios',
            'turnstile_token' => 'token',
            'support_type' => SupportType::Salary->value,
        ]);

        $response->assertOk();
        $conversationId = $response->json('data.conversation_id');
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversationId,
            'support_type' => SupportType::Salary->value,
        ]);
        Bus::assertDispatched(AutoAssignSupportAdminJob::class);
    }

    public function testGuestCreateSupportWithoutSupportType(): void
    {
        Bus::fake([AutoAssignSupportAdminJob::class]);
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);
        $this->mockTurnstile(true);

        $response = $this->postJson('/guest/chat/support-conversations', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'content' => 'General issue.',
            'device_id' => 'device-type-002',
            'platform' => 'android',
            'turnstile_token' => 'token',
        ]);

        $response->assertOk();
        $conversationId = $response->json('data.conversation_id');
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversationId,
            'support_type' => null,
        ]);
        Bus::assertDispatched(AutoAssignSupportAdminJob::class);
    }

    public function testGuestCreateSupportReturns422ForInvalidType(): void
    {
        $this->mockTurnstile(true);

        $this->postJson('/guest/chat/support-conversations', [
            'name' => 'John',
            'email' => 'john@example.com',
            'content' => 'Help.',
            'device_id' => 'device-type-003',
            'platform' => 'ios',
            'turnstile_token' => 'token',
            'support_type' => 'invalid_type',
        ])->assertStatus(422);
    }

    public function testGuestCreateSupportDoesNotAcceptShiftId(): void
    {
        Bus::fake([AutoAssignSupportAdminJob::class]);
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);
        $this->mockTurnstile(true);

        $response = $this->postJson('/guest/chat/support-conversations', [
            'name' => 'John',
            'email' => 'john@example.com',
            'content' => 'Help.',
            'device_id' => 'device-type-004',
            'platform' => 'ios',
            'turnstile_token' => 'token',
            'shift_id' => 99999,
        ]);

        // shift_id is not in guest validation rules — should be ignored, not 422
        $response->assertOk();
    }

    private function mockTurnstile(bool $passes = true): void
    {
        $service = Mockery::mock(TurnstileService::class);
        $service->shouldReceive('verify')->andReturn($passes);
        $this->app->instance(TurnstileService::class, $service);
    }

    private function createGuestSession(array $overrides = []): array
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);
        $plaintext = Str::random(64);
        $session = GuestSupportSession::query()->create(
            array_merge(
                [
                    'device_id' => Str::uuid(),
                    'platform' => 'ios',
                    'guest_name' => 'Test Guest',
                    'guest_email' => 'guest@example.com',
                    'session_token' => Crypt::encryptString($plaintext),
                    'session_token_hash' => hash_hmac('sha256', $plaintext, config('app.key')),
                    'conversation_id' => $conversation->id,
                ],
                $overrides
            )
        );
        $conversation->forceFill(['guest_support_session_id' => $session->id])->save();

        return [$session, $plaintext, $conversation];
    }
}
