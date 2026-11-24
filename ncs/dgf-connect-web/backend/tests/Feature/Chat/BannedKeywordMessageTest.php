<?php

namespace Tests\Feature\Chat;

use App\Actions\Chat\GuestSupport\CreateGuestSupportConversationAction;
use App\Actions\Chat\Keyword\LoadBannedKeywordsAction;
use App\Enums\ChatBannedKeywordAction;
use App\Enums\ChatConversationType;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Events\ChatConversationCreated;
use App\Events\NewChatMessage;
use App\Models\ChatBannedKeyword;
use App\Models\ChatConversation;
use App\Models\GuestSupportSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class BannedKeywordMessageTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('chat:banned_keywords_v2');
    }

    public function testBlockedKeywordRejects422WithErrorCode(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'badword',
            'action' => ChatBannedKeywordAction::Block->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'this has badword inside',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD');
    }

    public function testBlockedKeywordErrorSurfacesMatchedKeyword(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'badword',
            'action' => ChatBannedKeywordAction::Block->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'this has badword inside',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD');
        $this->assertContains('badword', $response->json('data.keywords'));
    }

    public function testMaskSendResponseSurfacesMaskedKeywords(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'lương tháng này cao',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.body', '*** tháng này cao');
        $this->assertContains('lương', $response->json('data.masked_keywords'));
    }

    public function testCleanMessageReportsNoMaskedKeywords(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'tin nhắn bình thường',
        ]);

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.masked_keywords'));
    }

    public function testInactiveBlockKeywordDoesNotReject(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'badword',
            'action' => ChatBannedKeywordAction::Block->value,
            'is_active' => false,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'this has badword inside',
        ]);

        $response->assertStatus(200);
    }

    public function testMaskKeywordAllowsSendButMasksBody(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'lương tháng này cao',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.body', '*** tháng này cao');

        $this->assertDatabaseHas('chat_messages', ['body' => 'lương tháng này cao']);
    }

    public function testGetMessagesReturnsMaskedBody(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $this->addMessage($conversation, $user, ['body' => 'lương tháng này cao']);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}/messages");

        $response->assertStatus(200);
        $bodies = collect($response->json('data.messages'))->pluck('body')->all();
        $this->assertContains('*** tháng này cao', $bodies);
    }

    public function testCleanMessagePassesThroughUnchanged(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'tin nhắn bình thường',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.body', 'tin nhắn bình thường');
    }

    public function testCacheIsInvalidatedAfterKeywordAdded(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        // Warm the cache (no keywords)
        Cache::remember('chat:banned_keywords_v2', 300, fn() => ['block' => [], 'mask' => []]);

        // Add a block keyword via model (simulating admin action that invalidates cache)
        ChatBannedKeyword::factory()->create([
            'keyword' => 'newblock',
            'action' => ChatBannedKeywordAction::Block->value,
            'is_active' => true,
        ]);
        LoadBannedKeywordsAction::invalidate();

        $response = $this->actingAs($user, 'api')->postJson("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'has newblock word',
        ]);

        $response->assertStatus(422);
    }

    public function testLocaleSpecificKeywordBlocksAllUsersForConsistency(): void
    {
        // Locale field on a keyword is metadata only; all keywords are applied regardless of
        // the sender's locale so that masking and blocking behave consistently across every channel.
        ChatBannedKeyword::factory()->create([
            'keyword' => 'nguy hiểm',
            'locale' => 'vi',
            'action' => ChatBannedKeywordAction::Block->value,
            'is_active' => true,
        ]);

        $conversation = $this->makeConversation();

        foreach (['vi', 'ja', 'en'] as $lang) {
            $user = User::factory()->create(['settings' => ['lang' => $lang]]);
            $this->addParticipant($conversation, $user);

            $this->actingAs($user, 'api')
                ->postJson("/user/chat/conversations/{$conversation->id}/messages", ['body' => 'từ nguy hiểm'])
                ->assertStatus(422)
                ->assertJsonPath('error', 'CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD');
        }
    }

    public function testGlobalKeywordBlocksAllLocales(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'forbidden',
            'locale' => null,
            'action' => ChatBannedKeywordAction::Block->value,
            'is_active' => true,
        ]);

        $conversation = $this->makeConversation();

        foreach (['ja', 'vi', 'en'] as $lang) {
            $user = User::factory()->create(['settings' => ['lang' => $lang]]);
            $this->addParticipant($conversation, $user);

            $this->actingAs($user, 'api')
                ->postJson("/user/chat/conversations/{$conversation->id}/messages", ['body' => 'this is forbidden'])
                ->assertStatus(422);
        }
    }

    public function testGetMessagesMasksKeywordRegardlessOfViewerLocale(): void
    {
        // Mask keyword with a specific locale — must still be applied when a different-locale
        // user loads history, so that history and broadcast are consistent.
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'locale' => 'vi',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $jaUser = User::factory()->create(['settings' => ['lang' => 'ja']]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $jaUser);
        $this->addMessage($conversation, $jaUser, ['body' => 'lương cao']);

        $response = $this->actingAs($jaUser, 'api')->getJson("/user/chat/conversations/{$conversation->id}/messages");

        $response->assertStatus(200);
        $bodies = collect($response->json('data.messages'))->pluck('body')->all();
        $this->assertContains('*** cao', $bodies);
    }

    public function testGuestSendResponseIsMasked(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        ['session' => $session, 'token' => $token] = $this->makeGuestSession();

        $response = $this->withToken($token)->postJson(
            "/guest/chat/conversations/{$session->conversation_id}/messages",
            [
                'body' => 'lương của tôi cao',
                'client_message_id' => (string) Str::uuid(),
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.body', '*** của tôi cao');
        $this->assertDatabaseHas('chat_messages', ['body' => 'lương của tôi cao']);
    }

    public function testGuestBlockKeywordIsRejected(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'badword',
            'action' => ChatBannedKeywordAction::Block->value,
            'is_active' => true,
        ]);

        ['session' => $session, 'token' => $token] = $this->makeGuestSession();

        $response = $this->withToken($token)->postJson(
            "/guest/chat/conversations/{$session->conversation_id}/messages",
            [
                'body' => 'message with badword inside',
                'client_message_id' => (string) Str::uuid(),
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD');
        $this->assertContains('badword', $response->json('data.keywords'));
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function testBlockedKeywordRejectsSupportConversationCreation(): void
    {
        ChatBannedKeyword::factory()
            ->block()
            ->create([
                'keyword' => 'badword',
                'is_active' => true,
            ]);

        $employee = User::factory()->create();

        $response = $this->actingAs($employee, 'api')->postJson('/user/chat/support-conversations', [
            'body' => 'I need help with badword please',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD');
        $this->assertContains('badword', $response->json('data.keywords'));
        // Scoped by type: the internal admin group always exists, so a table-wide count would
        // measure the seeded system chat rather than the rejected support request.
        $this->assertDatabaseMissing('chat_conversations', ['type' => ChatConversationType::Support->value]);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function testBlockedKeywordRejectsGuestSupportConversationCreation(): void
    {
        Event::fake([NewChatMessage::class, ChatConversationCreated::class]);

        ChatBannedKeyword::factory()
            ->block()
            ->create([
                'keyword' => 'forbidden',
                'is_active' => true,
            ]);

        $result = app(CreateGuestSupportConversationAction::class)([
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'content' => 'this is forbidden content',
            'device_id' => 'device-block-test',
            'platform' => 'ios',
        ]);

        $this->assertSame(422, $result->getStatus());
        $this->assertSame(ErrorCode::ChatMessageContainsBannedKeyword, $result->getError());
        $this->assertContains('forbidden', $result->getData()['keywords']);
        $this->assertDatabaseMissing('chat_conversations', ['type' => ChatConversationType::GuestSupport->value]);
        $this->assertDatabaseCount('guest_support_sessions', 0);
    }

    public function testConversationListMasksLastMessageBody(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $message = $this->addMessage($conversation, $user, ['body' => 'lương tháng này cao']);
        $conversation
            ->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])
            ->save();

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertOk();
        $this->assertSame('*** tháng này cao', $response->json('data.data.0.last_message.body'));
    }

    public function testSupportListMasksLastMessageBody(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $employee = User::factory()->create();
        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $conversation = $this->makeConversation([
            'type' => ChatConversationType::Support->value,
            'created_by_user_id' => $employee->id,
        ]);
        $message = $this->addMessage($conversation, $employee, ['body' => 'lương thấp quá']);
        $conversation
            ->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])
            ->save();

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/support-conversations?status=all');

        $response->assertOk();
        $this->assertSame('*** thấp quá', $response->json('data.data.0.last_message.body'));
    }

    public function testGuestIdempotentRetryReturnsMaskedBody(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        ['session' => $session, 'token' => $token] = $this->makeGuestSession();
        $clientId = (string) Str::uuid();

        $first = $this->withToken($token)->postJson("/guest/chat/conversations/{$session->conversation_id}/messages", [
            'body' => 'lương tôi thấp',
            'client_message_id' => $clientId,
        ]);
        $first->assertStatus(201);
        $first->assertJsonPath('data.body', '*** tôi thấp');

        $retry = $this->withToken($token)->postJson("/guest/chat/conversations/{$session->conversation_id}/messages", [
            'body' => 'lương tôi thấp',
            'client_message_id' => $clientId,
        ]);
        $retry->assertStatus(200);
        $retry->assertJsonPath('data.body', '*** tôi thấp');
    }

    public function testGuestMessageHistoryReturnsMaskedBody(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        ['session' => $session, 'token' => $token] = $this->makeGuestSession();

        $this->withToken($token)
            ->postJson("/guest/chat/conversations/{$session->conversation_id}/messages", [
                'body' => 'lương của tôi cao',
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertStatus(201);

        $list = $this->withToken($token)->getJson("/guest/chat/conversations/{$session->conversation_id}/messages");

        $list->assertStatus(200);
        $bodies = collect($list->json('data.messages'))->pluck('body')->all();
        $this->assertContains('*** của tôi cao', $bodies);
        $this->assertNotContains('lương của tôi cao', $bodies);
    }

    public function testConversationDetailMasksPinnedMessageBody(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $message = $this->addMessage($conversation, $user, ['body' => 'lương tháng này cao']);
        $conversation->forceFill(['pinned_message_id' => $message->id])->save();

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.pinned_message.body', '*** tháng này cao');
    }

    public function testSystemMessageBodyIsNotMasked(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'admin',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $this->addMessage($conversation, $user, [
            'message_type' => 'system',
            'body' => 'chat.system.support_admin_assigned',
            'sender_user_id' => null,
        ]);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}/messages");

        $response->assertStatus(200);
        $bodies = collect($response->json('data.messages'))->pluck('body')->all();
        $this->assertContains('chat.system.support_admin_assigned', $bodies);
    }

    public function testConversationListSystemLastMessageIsNotMasked(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'admin',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $message = $this->addMessage($conversation, $user, [
            'message_type' => 'system',
            'body' => 'chat.system.support_admin_assigned',
            'sender_user_id' => null,
        ]);
        $conversation
            ->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])
            ->save();

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertOk();
        $this->assertSame('chat.system.support_admin_assigned', $response->json('data.data.0.last_message.body'));
    }

    public function testSupportListSystemLastMessageIsNotMasked(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'admin',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $employee = User::factory()->create();
        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $conversation = $this->makeConversation([
            'type' => ChatConversationType::Support->value,
            'created_by_user_id' => $employee->id,
        ]);
        $message = $this->addMessage($conversation, $employee, [
            'message_type' => 'system',
            'body' => 'chat.system.support_admin_assigned',
            'sender_user_id' => null,
        ]);
        $conversation
            ->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])
            ->save();

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/support-conversations?status=all');

        $response->assertOk();
        $this->assertSame('chat.system.support_admin_assigned', $response->json('data.data.0.last_message.body'));
    }

    public function testGuestSystemMessageBodyIsNotMasked(): void
    {
        ChatBannedKeyword::factory()->create([
            'keyword' => 'admin',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        ['session' => $session, 'token' => $token] = $this->makeGuestSession();
        $this->addMessage($session->conversation, User::factory()->create(), [
            'message_type' => 'system',
            'body' => 'chat.system.support_admin_assigned',
            'sender_user_id' => null,
        ]);

        $list = $this->withToken($token)->getJson("/guest/chat/conversations/{$session->conversation_id}/messages");

        $list->assertStatus(200);
        $bodies = collect($list->json('data.messages'))->pluck('body')->all();
        $this->assertContains('chat.system.support_admin_assigned', $bodies);
    }

    private function makeGuestSession(): array
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);

        $plaintext = Str::random(64);
        $session = GuestSupportSession::query()->create([
            'device_id' => Str::uuid(),
            'platform' => 'android',
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@test.com',
            'session_token' => Crypt::encryptString($plaintext),
            'session_token_hash' => hash_hmac('sha256', $plaintext, config('app.key')),
            'conversation_id' => $conversation->id,
        ]);

        $conversation->forceFill(['guest_support_session_id' => $session->id])->save();

        return ['session' => $session->fresh(['conversation']), 'token' => $plaintext];
    }
}
