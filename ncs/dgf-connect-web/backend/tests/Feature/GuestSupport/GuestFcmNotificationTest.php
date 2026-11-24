<?php

namespace Tests\Feature\GuestSupport;

use App\Actions\Chat\GuestSupport\CreateGuestSupportConversationAction;
use App\Actions\Chat\GuestSupport\SendGuestMessageAction;
use App\Actions\Chat\Message\SendMessageAction;
use App\Enums\ChatBannedKeywordAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Events\ChatConversationCreated;
use App\Events\NewChatMessage;
use App\Jobs\SendChatMessageFcmJob;
use App\Jobs\SendGuestChatFcmJob;
use App\Models\ChatBannedKeyword;
use App\Models\ChatConversation;
use App\Models\GuestSupportSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class GuestFcmNotificationTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testAdminMessageDispatchesSendGuestChatFcmJobToGuestDevice(): void
    {
        Bus::fake();

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign, PermissionName::ChatView]);
        [$session, $conversation] = $this->makeGuestSession('guest-device-fcm-token');

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(SendMessageAction::class)($admin, $conversation, ['body' => 'Admin reply']);

        Bus::assertDispatched(SendGuestChatFcmJob::class, function (SendGuestChatFcmJob $job) use ($conversation) {
            return $job->fcmToken === 'guest-device-fcm-token' &&
                $job->conversationId === $conversation->id &&
                $job->data['type'] === NotificationType::CHAT_MESSAGE->value;
        });
    }

    public function testGuestMessageDispatchesSendChatMessageFcmJobToAssignedAdmins(): void
    {
        Bus::fake();

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        [$session, $conversation] = $this->makeGuestSession();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(SendGuestMessageAction::class)($session, ['body' => 'Guest follow-up']);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use (
            $conversation,
            $admin
        ) {
            return $job->conversationId === $conversation->id &&
                in_array($admin->id, $job->recipientUserIds, true) &&
                $job->data['type'] === NotificationType::CHAT_MESSAGE->value;
        });
    }

    public function testGuestSupportCreatedNotifiesAllChatAssignAdmins(): void
    {
        Bus::fake();
        Event::fake([ChatConversationCreated::class, NewChatMessage::class]);

        $admin1 = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $admin2 = $this->createUserWithPermissions([PermissionName::ChatAssign]);

        app(CreateGuestSupportConversationAction::class)([
            'name' => 'New Guest',
            'email' => 'newguest@example.com',
            'content' => 'Help!',
            'device_id' => 'fresh-device',
            'platform' => 'android',
        ]);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use (
            $admin1,
            $admin2
        ) {
            return in_array($admin1->id, $job->recipientUserIds, true) &&
                in_array($admin2->id, $job->recipientUserIds, true) &&
                $job->data['type'] === NotificationType::CHAT_SUPPORT_CREATED->value;
        });
    }

    public function testGuestMessageFallsBackToAllChatAssignAdminsWhenNoneAssigned(): void
    {
        Bus::fake();

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        [$session, $conversation] = $this->makeGuestSession();

        // no participant added — no assigned admin

        app(SendGuestMessageAction::class)($session, ['body' => 'Anyone there?']);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use (
            $conversation,
            $admin
        ) {
            return $job->conversationId === $conversation->id && in_array($admin->id, $job->recipientUserIds, true);
        });
    }

    public function testAdminMessageDoesNotDispatchGuestFcmWhenNoFcmToken(): void
    {
        Bus::fake();

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign, PermissionName::ChatView]);
        [$session, $conversation] = $this->makeGuestSession(fcmToken: '');

        // Clear the fcm_token so guest has no device token
        $session->forceFill(['fcm_token' => null])->save();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(SendMessageAction::class)($admin, $conversation, ['body' => 'Admin reply without guest token']);

        Bus::assertNotDispatched(SendGuestChatFcmJob::class);
    }

    public function testGuestSupportCreatedNotificationBodyIsMasked(): void
    {
        Cache::forget('chat:banned_keywords_v2');
        Bus::fake();
        Event::fake([ChatConversationCreated::class, NewChatMessage::class]);

        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);

        app(CreateGuestSupportConversationAction::class)([
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'content' => 'lương thấp quá',
            'device_id' => 'masked-device',
            'platform' => 'android',
        ]);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use ($admin) {
            return in_array($admin->id, $job->recipientUserIds, true) &&
                $job->data['type'] === NotificationType::CHAT_SUPPORT_CREATED->value &&
                $job->bodyByLocale['en'] === '*** thấp quá';
        });
    }

    public function testAdminReplyToGuestFcmBodyIsMasked(): void
    {
        Cache::forget('chat:banned_keywords_v2');
        Bus::fake();

        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign, PermissionName::ChatView]);
        [$session, $conversation] = $this->makeGuestSession('guest-device-token');

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(SendMessageAction::class)($admin, $conversation, ['body' => 'lương của bạn đã được cập nhật']);

        Bus::assertDispatched(SendGuestChatFcmJob::class, function (SendGuestChatFcmJob $job) {
            return $job->fcmToken === 'guest-device-token' && $job->body === '*** của bạn đã được cập nhật';
        });
    }

    public function testGuestFollowUpNotificationBodyIsMasked(): void
    {
        Cache::forget('chat:banned_keywords_v2');
        Bus::fake();

        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        [$session, $conversation] = $this->makeGuestSession();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(SendGuestMessageAction::class)($session, ['body' => 'lương của tôi cao nhỉ']);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use ($admin) {
            return in_array($admin->id, $job->recipientUserIds, true) &&
                $job->bodyByLocale['en'] === '*** của tôi cao nhỉ';
        });
    }

    private function makeGuestSession(string $fcmToken = 'guest-fcm-token'): array
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);
        $session = GuestSupportSession::query()->create([
            'device_id' => Str::uuid(),
            'platform' => 'ios',
            'fcm_token' => $fcmToken,
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'session_token' => Crypt::encryptString(Str::random(64)),
            'conversation_id' => $conversation->id,
        ]);
        $conversation->forceFill(['guest_support_session_id' => $session->id])->save();

        return [$session->fresh(['conversation']), $conversation->fresh()];
    }
}
