<?php

namespace Tests\Feature\Chat;

use App\Actions\Chat\Lifecycle\CloseConversationAction;
use App\Actions\Chat\Message\SendMessageAction;
use App\Actions\Chat\Notification\SendChatMessageFcmBatchAction;
use App\Actions\Chat\Support\AssignSupportAdminsAction;
use App\Actions\Chat\Support\CreateSupportConversationAction;
use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\PruneInvalidFirebaseTokensAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\Notification\CalculateUserBadgeCountAction;
use App\Enums\ChatBannedKeywordAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Jobs\SendChatMessageFcmJob;
use App\Models\ChatBannedKeyword;
use App\Models\ChatMessage;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Chat\ChatPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class ChatNotificationTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testSendMessageNotifiesOtherActiveParticipantsExceptSender(): void
    {
        Bus::fake();

        $sender = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation(['title' => 'Team chat']);
        $this->addParticipant($conversation, $sender, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $other, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(SendMessageAction::class)($sender, $conversation, ['body' => 'hello team']);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use (
            $conversation,
            $sender,
            $other
        ) {
            return $job->conversationId === $conversation->id &&
                $job->recipientUserIds === [$other->id] &&
                !in_array($sender->id, $job->recipientUserIds, true) &&
                $job->data['type'] === NotificationType::CHAT_MESSAGE->value &&
                $job->bodyByLocale['en'] === 'hello team' &&
                array_keys($job->titleByLocale) === ['vi', 'en', 'ja', 'ne', 'si'];
        });
    }

    public function testSupportCreatedNotifiesChatAssignmentAdmins(): void
    {
        Bus::fake();

        $admin = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $employee = User::factory()->create();

        app(CreateSupportConversationAction::class)($employee, ['body' => 'I need help']);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use (
            $admin,
            $employee
        ) {
            return in_array($admin->id, $job->recipientUserIds, true) &&
                !in_array($employee->id, $job->recipientUserIds, true) &&
                $job->data['type'] === NotificationType::CHAT_SUPPORT_CREATED->value;
        });
    }

    public function testCloseNotifiesOtherParticipants(): void
    {
        Bus::fake();

        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $employee = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::AdminGroup->value]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(CloseConversationAction::class)($admin, $conversation, []);
        $systemMessage = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('message_type', ChatMessageType::System->value)
            ->where('body', ChatSystemMessageType::ConversationClosed->value)
            ->firstOrFail();

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use (
            $employee,
            $admin,
            $systemMessage
        ) {
            return $job->recipientUserIds === [$employee->id] &&
                !in_array($admin->id, $job->recipientUserIds, true) &&
                $job->data['type'] === NotificationType::CHAT_STATE_CHANGED->value &&
                $job->data['message_id'] === (string) $systemMessage->id;
        });
    }

    public function testAssignSupportAdminsNotifiesActiveParticipantsExceptActorWithMessageId(): void
    {
        Bus::fake();

        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $employee = User::factory()->create();
        $target = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $conversation = $this->makeConversation([
            'type' => ChatConversationType::Support->value,
            'created_by_user_id' => $employee->id,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $actor, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(AssignSupportAdminsAction::class)($actor, $conversation, ['admin_user_ids' => [$target->id]]);
        $systemMessage = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('message_type', ChatMessageType::System->value)
            ->where('body', ChatSystemMessageType::SupportAdminAssigned->value)
            ->firstOrFail();

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use (
            $conversation,
            $actor,
            $employee,
            $target,
            $systemMessage
        ) {
            $recipientIds = $job->recipientUserIds;
            sort($recipientIds);
            $expectedRecipientIds = [$employee->id, $target->id];
            sort($expectedRecipientIds);

            return $job->conversationId === $conversation->id &&
                $recipientIds === $expectedRecipientIds &&
                !in_array($actor->id, $job->recipientUserIds, true) &&
                $job->data['type'] === NotificationType::CHAT_ADMIN_ASSIGNED->value &&
                $job->data['message_id'] === (string) $systemMessage->id;
        });
    }

    public function testAssigningSelfDoesNotNotifyActor(): void
    {
        Bus::fake();

        $actor = $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $employee = User::factory()->create();
        $conversation = $this->makeConversation([
            'type' => ChatConversationType::Support->value,
            'created_by_user_id' => $employee->id,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(AssignSupportAdminsAction::class)($actor, $conversation, ['admin_user_ids' => [$actor->id]]);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) use (
            $actor,
            $employee
        ) {
            return $job->recipientUserIds === [$employee->id] &&
                !in_array($actor->id, $job->recipientUserIds, true) &&
                $job->data['type'] === NotificationType::CHAT_ADMIN_ASSIGNED->value;
        });
    }

    public function testJobSuppressesRecipientActivelyViewingConversation(): void
    {
        config(['chat.notifications.presence_store' => 'array']);

        $viewing = User::factory()->create();
        $away = User::factory()->create();
        UserDevice::create([
            'user_id' => $viewing->id,
            'name' => 'phone',
            'device_id' => 'dev-viewing',
            'platform' => 'android',
            'fcm_token' => 'token-viewing',
        ]);
        UserDevice::create([
            'user_id' => $away->id,
            'name' => 'phone',
            'device_id' => 'dev-away',
            'platform' => 'android',
            'fcm_token' => 'token-away',
        ]);

        $conversationId = 4242;
        $presence = app(ChatPresenceService::class);
        $presence->markActive($viewing->id, $conversationId);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendAll')
            ->once()
            ->with(
                Mockery::on(
                    fn(array $messages) => count($messages) === 1 &&
                        $messages[0] instanceof CloudMessage &&
                        $messages[0]->jsonSerialize()['token'] === 'token-away'
                )
            )
            ->andReturn(MulticastSendReport::withItems([]));
        $resolver = $this->firebaseResolver($messaging);

        $job = new SendChatMessageFcmJob(
            [$viewing->id, $away->id],
            $conversationId,
            ['en' => 'Title'],
            ['en' => 'Body'],
            ['type' => NotificationType::CHAT_MESSAGE->value]
        );

        $job->handle($this->chatBatchAction($presence, $resolver));

        Mockery::close();
        $this->assertTrue(true);
    }

    public function testJobSkipsRecipientsWithoutDevices(): void
    {
        config(['chat.notifications.presence_store' => 'array']);

        $noDevice = User::factory()->create();

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('sendAll');
        $resolver = $this->firebaseResolver($messaging);

        $job = new SendChatMessageFcmJob(
            [$noDevice->id],
            1,
            ['en' => 'Title'],
            ['en' => 'Body'],
            ['type' => NotificationType::CHAT_MESSAGE->value]
        );

        $job->handle($this->chatBatchAction(app(ChatPresenceService::class), $resolver));

        Mockery::close();
        $this->assertTrue(true);
    }

    public function testJobSkipsFcmSendInLocalEnvironmentButStillChecksSuppression(): void
    {
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'local';

        $user = User::factory()->create();
        UserDevice::create([
            'user_id' => $user->id,
            'name' => 'phone',
            'device_id' => 'dev-local',
            'platform' => 'android',
            'fcm_token' => 'token-local',
        ]);

        $presence = Mockery::mock(ChatPresenceService::class);
        $presence->shouldReceive('isViewing')->once()->with($user->id, 1)->andReturn(false);

        try {
            $job = new SendChatMessageFcmJob(
                [$user->id],
                1,
                ['en' => 'Title'],
                ['en' => 'Body'],
                ['type' => NotificationType::CHAT_MESSAGE->value]
            );

            $job->handle($this->chatBatchAction($presence, app(ResolveFirebaseMessagingAction::class)));
        } finally {
            $this->app['env'] = $originalEnv;
        }

        Mockery::close();
        $this->assertTrue(true);
    }

    public function testJobSendsVersionedMobilePlatformsOnlyThroughAppProject(): void
    {
        config(['chat.notifications.presence_store' => 'array']);

        $user = User::factory()->create();
        UserDevice::create([
            'user_id' => $user->id,
            'name' => 'android-phone',
            'device_id' => 'dev-android',
            'platform' => 'Android 35',
            'fcm_token' => 'android-token',
        ]);
        UserDevice::create([
            'user_id' => $user->id,
            'name' => 'iphone',
            'device_id' => 'dev-ios',
            'platform' => 'iOS 26.5',
            'fcm_token' => 'ios-token',
        ]);

        $appMessaging = Mockery::mock(Messaging::class);
        $appMessaging
            ->shouldReceive('sendAll')
            ->once()
            ->withArgs(function (array $messages) {
                $tokens = array_map(static fn(CloudMessage $message) => $message->jsonSerialize()['token'], $messages);
                sort($tokens);

                return $tokens === ['android-token', 'ios-token'];
            })
            ->andReturn(MulticastSendReport::withItems([]));

        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->with('app')->andReturn($appMessaging);
        $resolver->shouldNotReceive('execute')->with('admin');

        $job = new SendChatMessageFcmJob(
            [$user->id],
            999,
            ['en' => 'Title'],
            ['en' => 'Body'],
            ['type' => NotificationType::CHAT_MESSAGE->value]
        );

        $job->handle($this->chatBatchAction(app(ChatPresenceService::class), $resolver));

        Mockery::close();
        $this->assertTrue(true);
    }

    public function testJobSendsAdminRecipientThroughAdminMobileProject(): void
    {
        config(['chat.notifications.presence_store' => 'array']);

        $admin = $this->createUserWithPermissions([PermissionName::ManagerAuth]);
        UserDevice::create([
            'user_id' => $admin->id,
            'name' => 'admin-phone',
            'device_id' => 'admin-device',
            'platform' => 'iOS 26.5',
            'fcm_token' => 'admin-app-token',
        ]);

        $adminMessaging = Mockery::mock(Messaging::class);
        $adminMessaging
            ->shouldReceive('sendAll')
            ->once()
            ->withArgs(
                fn(array $messages) => count($messages) === 1 &&
                    $messages[0]->jsonSerialize()['token'] === 'admin-app-token'
            )
            ->andReturn(MulticastSendReport::withItems([]));

        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->once()->with('admin')->andReturn($adminMessaging);
        $resolver->shouldNotReceive('execute')->with('app');

        $job = new SendChatMessageFcmJob(
            [$admin->id],
            999,
            ['en' => 'Title'],
            ['en' => 'Body'],
            ['type' => NotificationType::CHAT_MESSAGE->value]
        );

        $job->handle($this->chatBatchAction(app(ChatPresenceService::class), $resolver));

        Mockery::close();
        $this->assertTrue(true);
    }

    public function testActiveHeartbeatEndpointMarksAndClearsPresence(): void
    {
        config(['chat.notifications.presence_store' => 'array']);

        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $presence = app(ChatPresenceService::class);

        $this->actingAs($user, 'api')
            ->postJson('/user/chat/active', ['conversation_id' => $conversation->id])
            ->assertOk();
        $this->assertSame($conversation->id, $presence->activeConversationId($user->id));

        $this->actingAs($user, 'api')->deleteJson('/user/chat/active')->assertOk();
        $this->assertNull($presence->activeConversationId($user->id));
    }

    public function testNotificationBodyIsMaskedForMaskKeyword(): void
    {
        Cache::forget('chat:banned_keywords_v2');
        Bus::fake();

        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $sender = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $sender, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $other, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(SendMessageAction::class)($sender, $conversation, ['body' => 'lương tháng này cao']);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) {
            return $job->bodyByLocale['en'] === '*** tháng này cao';
        });
    }

    public function testSupportCreatedNotificationBodyIsMasked(): void
    {
        Cache::forget('chat:banned_keywords_v2');
        Bus::fake();

        ChatBannedKeyword::factory()->create([
            'keyword' => 'lương',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $this->createUserWithPermissions([PermissionName::ChatAssign]);
        $employee = User::factory()->create();

        app(CreateSupportConversationAction::class)($employee, ['body' => 'lương thấp quá']);

        Bus::assertDispatched(SendChatMessageFcmJob::class, function (SendChatMessageFcmJob $job) {
            return $job->data['type'] === NotificationType::CHAT_SUPPORT_CREATED->value &&
                $job->bodyByLocale['en'] === '*** thấp quá';
        });
    }

    private function firebaseResolver(?Messaging $messaging): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        return $resolver;
    }

    private function chatBatchAction(
        ChatPresenceService $presence,
        ResolveFirebaseMessagingAction $resolver
    ): SendChatMessageFcmBatchAction {
        return new SendChatMessageFcmBatchAction(
            $presence,
            $resolver,
            app(NormalizeFirebaseMessageDataAction::class),
            app(CalculateUserBadgeCountAction::class),
            app(PruneInvalidFirebaseTokensAction::class)
        );
    }
}
