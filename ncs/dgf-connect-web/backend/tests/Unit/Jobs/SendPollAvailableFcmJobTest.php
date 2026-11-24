<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\Notification\CalculateUserBadgeCountAction;
use App\Jobs\SendPollAvailableFcmJob;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendPollAvailableFcmJobTest extends TestCase
{
    use RefreshDatabase;

    private array $titleByLocale = ['en' => 'New poll', 'ja' => '新しい投票', 'vi' => 'Bình chọn mới'];

    private array $contentByLocale = ['en' => 'Content EN', 'ja' => 'Content JA', 'vi' => 'Content VI'];

    private array $data = ['type' => 'poll_available', 'poll_id' => '1', 'poll_type' => 'feedback'];

    public function testSendsMulticastToUserDeviceTokens(): void
    {
        $user = $this->createUserWithDevice('token-1');

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->withArgs(fn($message, $tokens) => $tokens === ['token-1']);

        $this->job([$user->id])->handle($this->firebaseResolver($messaging));
    }

    public function testUsesUserLanguageSettingForNotificationCopy(): void
    {
        $user = $this->createUserWithDevice('token-ja', ['lang' => 'ja']);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) {
                $notification = $message->jsonSerialize()['notification'];

                return $notification['title'] === '新しい投票' && $notification['body'] === 'Content JA';
            });

        $this->job([$user->id])->handle($this->firebaseResolver($messaging));
    }

    public function testFallsBackToEnglishWhenUserHasNoLanguageSetting(): void
    {
        $user = $this->createUserWithDevice('token-en');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) {
                $notification = $message->jsonSerialize()['notification'];

                return $notification['title'] === 'New poll' && $notification['body'] === 'Content EN';
            });

        $this->job([$user->id])->handle($this->firebaseResolver($messaging));
    }

    public function testAttachesPollDataPayload(): void
    {
        $user = $this->createUserWithDevice('token-1');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(fn($message, $tokens) => $message->jsonSerialize()['data'] === $this->data);

        $this->job([$user->id])->handle($this->firebaseResolver($messaging));
    }

    public function testNormalizesMessageDataOnceForAllRecipients(): void
    {
        $firstUser = $this->createUserWithDevice('token-1');
        $secondUser = $this->createUserWithDevice('token-2');

        $normalizer = Mockery::mock(NormalizeFirebaseMessageDataAction::class);
        $normalizer->shouldReceive('execute')->once()->with($this->data)->andReturn($this->data);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->twice()
            ->withArgs(fn(CloudMessage $message, array $tokens) => $message->jsonSerialize()['data'] === $this->data);

        $this->job([$firstUser->id, $secondUser->id])->handle($this->firebaseResolver($messaging), $normalizer);
    }

    public function testSkipsUsersWithoutFcmToken(): void
    {
        $user = $this->createUserWithDevice(null);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('sendMulticast');

        $this->job([$user->id])->handle($this->firebaseResolver($messaging));
    }

    public function testDoesNothingWhenMessagingIsUnavailable(): void
    {
        $user = $this->createUserWithDevice('token-1');

        $this->job([$user->id])->handle($this->firebaseResolver(null));

        $this->assertTrue(true);
    }

    public function testSwallowsSendFailuresSoOtherUsersStillReceiveNotification(): void
    {
        $failing = $this->createUserWithDevice('token-fail');
        $succeeding = $this->createUserWithDevice('token-ok');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->twice()
            ->andReturnUsing(function ($message, $tokens) {
                if ($tokens === ['token-fail']) {
                    throw new RuntimeException('FCM down');
                }

                return null;
            });

        $this->job([$failing->id, $succeeding->id])->handle($this->firebaseResolver($messaging));
    }

    public function testAttachesApnsBadgeWithUnreadCount(): void
    {
        $user = $this->createUserWithDevice('token-badge');
        ChatParticipant::factory()
            ->count(2)
            ->create(['user_id' => $user->id, 'unread_count' => 1]);

        $capturedMessage = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturnNull();

        $this->job([$user->id])->handle($this->firebaseResolver($messaging));

        $this->assertNotNull($capturedMessage);
        $array = $capturedMessage->jsonSerialize();
        $this->assertSame(2, $array['apns']['payload']['aps']['badge'] ?? null);
    }

    public function testCountsBadgesOnlyForIosRecipients(): void
    {
        $iosUser = $this->createUserWithDevice('ios-token');
        $androidUser = User::factory()->create();
        UserDevice::query()->create([
            'user_id' => $androidUser->id,
            'device_id' => 'android-device',
            'name' => 'Android device',
            'platform' => 'android',
            'fcm_token' => 'android-token',
        ]);

        $badgeCounter = Mockery::mock(CalculateUserBadgeCountAction::class);
        $badgeCounter
            ->shouldReceive('executeForUsers')
            ->once()
            ->with([$iosUser->id])
            ->andReturn([$iosUser->id => 7]);

        $capturedMessages = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->twice()
            ->withArgs(function (CloudMessage $message, array $tokens) use (&$capturedMessages) {
                $capturedMessages[$tokens[0]] = $message;

                return true;
            })
            ->andReturnNull();

        $this->job([$iosUser->id, $androidUser->id])->handle(
            $this->firebaseResolver($messaging),
            badgeCounter: $badgeCounter
        );

        $this->assertSame(
            7,
            $capturedMessages['ios-token']->jsonSerialize()['apns']['payload']['aps']['badge'] ?? null
        );
        $this->assertArrayNotHasKey('apns', $capturedMessages['android-token']->jsonSerialize());
    }

    private function firebaseResolver(?Messaging $messaging): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        return $resolver;
    }

    private function createUserWithDevice(?string $fcmToken, ?array $settings = null): User
    {
        $user = User::factory()->create(['settings' => $settings]);

        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-' . $user->id,
            'name' => 'Device',
            'platform' => 'iOS 26.5',
            'fcm_token' => $fcmToken,
        ]);

        return $user;
    }

    private function job(array $userIds): SendPollAvailableFcmJob
    {
        return new SendPollAvailableFcmJob($userIds, $this->titleByLocale, $this->contentByLocale, $this->data);
    }
}
