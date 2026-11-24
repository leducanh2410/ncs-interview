<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Manage\Notification\ResolveWorkplaceRecipientsAction;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Jobs\PushNotificationToUser;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\UserDevice;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

class PushNotificationToUserWorkplaceTargetTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = $this->createWorkplaceAt(35.0, 139.0, 'Target');
        $this->otherWorkplace = $this->createWorkplaceAt(35.1, 139.1, 'Other');
    }

    public function testPushesToWorkplaceMembersOnlyAndRecordsRecipients(): void
    {
        $member = $this->createEmployeeAt($this->workplace);
        $this->device($member->id, 'member-token');
        $outsider = $this->createEmployeeAt($this->otherWorkplace);
        $this->device($outsider->id, 'outsider-token');
        $notification = $this->pendingWorkplaceNotification(NotificationType::BANK_ACCOUNT_REQUIRED);

        $captured = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('send');
        $messaging
            ->shouldReceive('sendMulticast')
            ->withArgs(function (CloudMessage $message, array $tokens) use (&$captured) {
                $captured[] = ['tokens' => $tokens, 'data' => $message->jsonSerialize()['data'] ?? []];

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertSame(1, $notification->recipients_resolved_count);
        $this->assertTrue(
            NotificationRecipient::query()
                ->where('notification_id', $notification->id)
                ->where('user_id', $member->id)
                ->exists()
        );
        $this->assertFalse(
            NotificationRecipient::query()
                ->where('notification_id', $notification->id)
                ->where('user_id', $outsider->id)
                ->exists()
        );
        $this->assertNotEmpty($captured);
        $this->assertSame(['member-token'], $captured[0]['tokens']);
        $this->assertSame(NotificationType::BANK_ACCOUNT_REQUIRED->value, $captured[0]['data']['type']);
    }

    public function testEmptyWorkplaceAudienceIsSentToNobodyAndNeverBroadcast(): void
    {
        $notification = $this->pendingWorkplaceNotification();

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('send');
        $messaging->shouldNotReceive('sendMulticast');

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertSame(0, $notification->recipients_resolved_count);
    }

    public function testResolverFailureMarksFailedWithoutPushing(): void
    {
        $notification = $this->pendingWorkplaceNotification();
        $this->mock(ResolveWorkplaceRecipientsAction::class)
            ->shouldReceive('__invoke')
            ->andThrow(new RuntimeException('boom'));
        Log::shouldReceive('error')->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('send');
        $messaging->shouldNotReceive('sendMulticast');

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::FAILED->value, $notification->status);
        $this->assertNull($notification->recipients_resolved_count);
    }

    public function testAllTargetStillBroadcastsToTopics(): void
    {
        Notification::create([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::All->value,
            'title' => 'Title',
            'content' => 'Content',
            'status' => NotificationStatus::PENDING->value,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('sendMulticast');
        $messaging->shouldReceive('send')->atLeast()->once();

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $this->assertSame(NotificationStatus::SENT->value, Notification::query()->firstOrFail()->status);
    }

    private function pendingWorkplaceNotification(NotificationType $type = NotificationType::NORMAL): Notification
    {
        $notification = Notification::create([
            'type' => $type->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'title' => 'Please add your bank account',
            'content' => 'Open the app',
            'status' => NotificationStatus::PENDING->value,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
        ]);
        $notification->workplaces()->sync([$this->workplace->id]);

        return $notification;
    }

    private function device(int $userId, string $token): void
    {
        UserDevice::query()->create([
            'user_id' => $userId,
            'device_id' => "device-{$userId}",
            'name' => 'Device',
            'platform' => 'android',
            'fcm_token' => $token,
        ]);
    }

    private function firebaseResolver(?Messaging $messaging): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        return $resolver;
    }

    private function passthroughNormalizer(): NormalizeFirebaseMessageDataAction
    {
        $normalizer = Mockery::mock(NormalizeFirebaseMessageDataAction::class);
        $normalizer
            ->shouldReceive('execute')
            ->andReturnUsing(fn(array $data) => array_map(static fn($value) => (string) ($value ?? ''), $data));

        return $normalizer;
    }
}
