<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\Notification\CalculateUserBadgeCountAction;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Jobs\PushNotificationToUser;
use App\Models\ChatParticipant;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use Tests\TestCase;

class PushNotificationToUserTest extends TestCase
{
    use RefreshDatabase;

    public function testMarksUserNotificationSentWhenFirebaseMessagingIsUnavailable(): void
    {
        $user = User::factory()->create();
        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'iOS 26.5',
            'fcm_token' => 'token-1',
        ]);

        $notification = Notification::query()->create([
            'type' => 'test',
            'title' => 'Title',
            'content' => 'Content',
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->subMinute(),
            'user_id' => $user->id,
            'data' => [],
        ]);

        $normalizer = Mockery::mock(NormalizeFirebaseMessageDataAction::class);
        $normalizer
            ->shouldReceive('execute')
            ->once()
            ->with([
                'id' => $notification->id,
                'type' => 'test',
                'icon' => null,
                'user_id' => $user->id,
            ])
            ->andReturn([
                'id' => (string) $notification->id,
                'type' => 'test',
                'icon' => '',
                'user_id' => (string) $user->id,
            ]);

        (new PushNotificationToUser())->handle($this->firebaseResolver(null), $normalizer);

        $notification->refresh();

        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertNotNull($notification->processed_at);
    }

    public function testAttachesApnsBadgeWithUnreadCount(): void
    {
        $user = User::factory()->create();
        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'iOS 26.5',
            'fcm_token' => 'token-1',
        ]);

        // One unread chat conversation on top of the notification being delivered → badge 2.
        ChatParticipant::factory()->create(['user_id' => $user->id, 'unread_count' => 1]);

        Notification::query()->create([
            'type' => 'test',
            'title' => 'Title',
            'content' => 'Content',
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->subMinute(),
            'user_id' => $user->id,
            'data' => [],
        ]);

        $capturedMessage = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging));

        $this->assertNotNull($capturedMessage);
        $array = $capturedMessage->jsonSerialize();
        $this->assertSame(2, $array['apns']['payload']['aps']['badge'] ?? null);
    }

    public function testSkipsBadgeCountingForAndroidOnlyRecipient(): void
    {
        $user = User::factory()->create();
        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'android-device',
            'name' => 'Android device',
            'platform' => 'android',
            'fcm_token' => 'android-token',
        ]);

        $notification = Notification::query()->create([
            'type' => 'test',
            'title' => 'Title',
            'content' => 'Content',
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->subMinute(),
            'user_id' => $user->id,
            'data' => [],
        ]);

        $badgeCounter = Mockery::mock(CalculateUserBadgeCountAction::class);
        $badgeCounter->shouldNotReceive('executeForUsers');

        (new PushNotificationToUser())->handle(
            resolveMessaging: $this->firebaseResolver(null),
            badgeCounter: $badgeCounter
        );

        $this->assertSame(NotificationStatus::SENT->value, $notification->refresh()->status);
    }

    public function testMarksOrdinaryUserNotificationFailedWhenTheUserHasNoDevice(): void
    {
        $user = User::factory()->create();
        $notification = Notification::query()->create([
            'type' => NotificationType::PAPER_APPROVED->value,
            'title' => 'Title',
            'content' => 'Content',
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->subMinute(),
            'user_id' => $user->id,
            'data' => [],
        ]);

        (new PushNotificationToUser())->handle($this->firebaseResolver(null));

        $this->assertSame(NotificationStatus::FAILED->value, $notification->refresh()->status);
    }

    public function testKeepsDocumentsRequiredNotificationInAppWhenTheUserHasNoDevice(): void
    {
        $user = User::factory()->create();
        $notification = Notification::query()->create([
            'type' => NotificationType::PAPER_DOCUMENTS_REQUIRED->value,
            'title' => 'Title',
            'content' => 'Content',
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->subMinute(),
            'user_id' => $user->id,
            'data' => [],
        ]);

        (new PushNotificationToUser())->handle($this->firebaseResolver(null));

        $notification->refresh();
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertNotNull($notification->processed_at);
    }

    public function testKeepsAttendanceReasonNotificationInAppWhenTheAdminHasNoDevice(): void
    {
        $admin = User::factory()->create();
        $notification = Notification::query()->create([
            'type' => NotificationType::ATTENDANCE_REASON_SUBMITTED->value,
            'title' => 'Title',
            'content' => 'Content',
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->subMinute(),
            'user_id' => $admin->id,
            'data' => [],
        ]);

        (new PushNotificationToUser())->handle($this->firebaseResolver(null));

        $this->assertSame(NotificationStatus::SENT->value, $notification->refresh()->status);
    }

    private function firebaseResolver(?Messaging $messaging): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        return $resolver;
    }
}
