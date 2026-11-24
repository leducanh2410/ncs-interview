<?php

namespace Tests\Unit\Jobs;

use App\Actions\Chat\Notification\SendChatMessageFcmBatchAction;
use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\PruneInvalidFirebaseTokensAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\Notification\CalculateUserBadgeCountAction;
use App\Jobs\SendChatMessageFcmJob;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Chat\ChatPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use Tests\TestCase;

class SendChatMessageFcmJobTest extends TestCase
{
    use RefreshDatabase;

    public function testUsesDedicatedQueueAndBoundedRetrySettings(): void
    {
        $job = $this->makeJob([]);

        $this->assertSame(SendChatMessageFcmJob::QUEUE, $job->queue);
        $this->assertSame(300, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
    }

    public function testSendsVersionedIosPlatformThroughAppProjectWithBadge(): void
    {
        $user = User::factory()->create();
        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'iOS 26.5',
            'fcm_token' => 'token-1',
        ]);

        ChatParticipant::factory()
            ->count(2)
            ->create(['user_id' => $user->id, 'unread_count' => 1]);

        $presence = Mockery::mock(ChatPresenceService::class);
        $presence->shouldReceive('isViewing')->once()->andReturnFalse();

        $capturedMessages = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendAll')
            ->once()
            ->withArgs(function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->once()->with('app')->andReturn($messaging);
        $resolver->shouldNotReceive('execute')->with('admin');

        $this->makeJob([$user->id])->handle($this->makeBatchAction($presence, $resolver));

        $this->assertCount(1, $capturedMessages);
        $message = $capturedMessages[0]->jsonSerialize();
        $this->assertSame('token-1', $message['token']);
        $this->assertSame(2, $message['apns']['payload']['aps']['badge'] ?? null);
    }

    public function testSkipsBadgeCountingForSuppressedIosAndAndroidRecipients(): void
    {
        $viewingIosUser = $this->createUserWithDevice('ios-token', 'iOS 18.7');
        $androidUser = $this->createUserWithDevice('android-token', 'Android 35');

        $presence = Mockery::mock(ChatPresenceService::class);
        $presence->shouldReceive('isViewing')->once()->with($viewingIosUser->id, 1)->andReturnTrue();
        $presence->shouldReceive('isViewing')->once()->with($androidUser->id, 1)->andReturnFalse();

        $badgeCounter = Mockery::mock(CalculateUserBadgeCountAction::class);
        $badgeCounter->shouldNotReceive('executeForUsers');

        $capturedMessages = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendAll')
            ->once()
            ->withArgs(function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->once()->with('app')->andReturn($messaging);

        $this->makeJob([$viewingIosUser->id, $androidUser->id])->handle(
            $this->makeBatchAction($presence, $resolver, badgeCounter: $badgeCounter)
        );

        $this->assertCount(1, $capturedMessages);
        $message = $capturedMessages[0]->jsonSerialize();
        $this->assertSame('android-token', $message['token']);
        $this->assertArrayNotHasKey('apns', $message);
    }

    public function testNormalizesOnceAndSendsRecipientsConcurrently(): void
    {
        $firstUser = $this->createUserWithDevice('token-1', 'Android 35');
        $secondUser = $this->createUserWithDevice('token-2', 'Android 36');

        $presence = Mockery::mock(ChatPresenceService::class);
        $presence->shouldReceive('isViewing')->twice()->andReturnFalse();

        $normalizer = Mockery::mock(NormalizeFirebaseMessageDataAction::class);
        $normalizer
            ->shouldReceive('execute')
            ->once()
            ->with(['conversation_id' => '1', 'type' => 'chat_message'])
            ->andReturn(['conversation_id' => '1', 'type' => 'chat_message']);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendAll')
            ->once()
            ->withArgs(function (array $messages) {
                $tokens = array_map(static fn(CloudMessage $message) => $message->jsonSerialize()['token'], $messages);
                sort($tokens);

                return $tokens === ['token-1', 'token-2'];
            })
            ->andReturn(MulticastSendReport::withItems([]));

        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->once()->with('app')->andReturn($messaging);

        $this->makeJob([$firstUser->id, $secondUser->id])->handle(
            $this->makeBatchAction($presence, $resolver, $normalizer)
        );
    }

    public function testSplitsFirebaseRequestsAtFiveHundredMessages(): void
    {
        $user = User::factory()->create();
        $now = now();
        $devices = [];
        for ($index = 1; $index <= 501; $index++) {
            $devices[] = [
                'user_id' => $user->id,
                'device_id' => "device-{$index}",
                'name' => "Device {$index}",
                'platform' => 'Android 35',
                'fcm_token' => "token-{$index}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        UserDevice::query()->insert($devices);

        $presence = Mockery::mock(ChatPresenceService::class);
        $presence->shouldReceive('isViewing')->once()->andReturnFalse();

        $batchSizes = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendAll')
            ->twice()
            ->andReturnUsing(function (array $messages) use (&$batchSizes) {
                $batchSizes[] = count($messages);

                return MulticastSendReport::withItems([]);
            });

        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->once()->with('app')->andReturn($messaging);

        $this->makeJob([$user->id])->handle($this->makeBatchAction($presence, $resolver));

        $this->assertSame([500, 1], $batchSizes);
    }

    private function createUserWithDevice(string $fcmToken, string $platform): User
    {
        $user = User::factory()->create();
        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-' . $user->id,
            'name' => 'Mobile device',
            'platform' => $platform,
            'fcm_token' => $fcmToken,
        ]);

        return $user;
    }

    private function makeBatchAction(
        ChatPresenceService $presence,
        ResolveFirebaseMessagingAction $resolver,
        ?NormalizeFirebaseMessageDataAction $normalizer = null,
        ?CalculateUserBadgeCountAction $badgeCounter = null
    ): SendChatMessageFcmBatchAction {
        return new SendChatMessageFcmBatchAction(
            $presence,
            $resolver,
            $normalizer ?? app(NormalizeFirebaseMessageDataAction::class),
            $badgeCounter ?? app(CalculateUserBadgeCountAction::class),
            app(PruneInvalidFirebaseTokensAction::class)
        );
    }

    /**
     * @param  array<int, int>  $recipientUserIds
     */
    private function makeJob(array $recipientUserIds): SendChatMessageFcmJob
    {
        return new SendChatMessageFcmJob(
            recipientUserIds: $recipientUserIds,
            conversationId: 1,
            titleByLocale: ['en' => 'New message'],
            bodyByLocale: ['en' => 'You have a new message'],
            data: ['conversation_id' => '1', 'type' => 'chat_message']
        );
    }
}
