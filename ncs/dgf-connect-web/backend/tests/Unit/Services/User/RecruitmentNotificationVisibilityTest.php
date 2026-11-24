<?php

namespace Tests\Unit\Services\User;

use App\Enums\ErrorCode;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationReadState;
use App\Models\NotificationRecipient;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Services\User\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

/**
 * Recruitment notifications stop being visible to everyone, so every employee-facing read
 * path has to agree on who may see them.
 *
 * The pair of tests around "resolved to nobody" versus "never resolved" is the point of
 * this class: both states have zero recipient rows and are distinguishable only by
 * recipients_resolved_count, and they must behave in exactly opposite ways.
 */
class RecruitmentNotificationVisibilityTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private NotificationService $service;

    private Workplace $workplace;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NotificationService::class);
        $this->workplace = $this->createWorkplaceAt();
        $this->shift = $this->createShiftAt($this->workplace);
    }

    public function testRecipientSeesTheNotificationButNonRecipientDoesNot()
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $stranger = $this->createEmployeeAt($this->workplace);
        $notification = $this->sentRecruitmentNotificationFor([$recipient]);

        $this->actingAs($recipient, 'api');
        $this->assertContains($notification->id, $this->service->getNotifications()->pluck('id')->all());

        $this->actingAs($stranger, 'api');
        $this->assertNotContains($notification->id, $this->service->getNotifications()->pluck('id')->all());
    }

    public function testUnreadCountMatchesWhatEachEmployeeCanSee()
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $stranger = $this->createEmployeeAt($this->workplace);
        $this->sentRecruitmentNotificationFor([$recipient]);

        $this->actingAs($recipient, 'api');
        $this->assertSame(1, $this->service->countUnread());

        $this->actingAs($stranger, 'api');
        $this->assertSame(0, $this->service->countUnread());
    }

    public function testNonRecipientIsForbiddenFromMarkingItRead()
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $stranger = $this->createEmployeeAt($this->workplace);
        $notification = $this->sentRecruitmentNotificationFor([$recipient]);

        $this->actingAs($stranger, 'api');
        $result = $this->service->readNotification($notification);

        $this->assertSame(ErrorCode::Forbidden, $result['error']);
        $this->assertSame(403, $result['status']);
        $this->assertSame(0, NotificationReadState::query()->where('notification_id', $notification->id)->count());
    }

    public function testRecipientCanMarkItRead()
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $notification = $this->sentRecruitmentNotificationFor([$recipient]);

        $this->actingAs($recipient, 'api');
        $result = $this->service->readNotification($notification);

        $this->assertNull($result['error']);
        $this->assertSame(1, NotificationReadState::query()->where('notification_id', $notification->id)->count());
    }

    public function testMarkAllAsReadDoesNotCreateReadStatesForNonRecipients()
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $stranger = $this->createEmployeeAt($this->workplace);
        $notification = $this->sentRecruitmentNotificationFor([$recipient]);

        $this->actingAs($stranger, 'api');
        $this->service->readAllNotifications();

        // Left unguarded this is what would push the admin's read count above the number of
        // people the notification actually reached.
        $this->assertSame(0, NotificationReadState::query()->where('notification_id', $notification->id)->count());
    }

    public function testNotificationResolvedToNobodyIsVisibleToNobody()
    {
        $employee = $this->createEmployeeAt($this->workplace);

        $notification = $this->createRecruitmentNotification($this->shift, [], NotificationStatus::SENT);
        $notification->forceFill(['recipients_resolved_count' => 0, 'processed_at' => now()])->saveQuietly();

        $this->actingAs($employee, 'api');

        $this->assertNotContains($notification->id, $this->service->getNotifications()->pluck('id')->all());
        $this->assertSame(0, $this->service->countUnread());
        $this->assertSame(ErrorCode::Forbidden, $this->service->readNotification($notification)['error']);
    }

    public function testLegacyNotificationNeverResolvedStaysVisibleToEveryone()
    {
        $employee = $this->createEmployeeAt($this->workplace);

        $notification = $this->createRecruitmentNotification($this->shift, [], NotificationStatus::SENT);
        // Null, not zero: this notification predates targeting and was never resolved.
        $notification->forceFill(['recipients_resolved_count' => null, 'processed_at' => now()])->saveQuietly();

        $this->actingAs($employee, 'api');

        $this->assertContains($notification->id, $this->service->getNotifications()->pluck('id')->all());
        $this->assertSame(1, $this->service->countUnread());
        $this->assertNull($this->service->readNotification($notification)['error']);
    }

    public function testRecruitmentNotificationsNeverReachTheCachedSystemEndpoint()
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $stranger = $this->createEmployeeAt($this->workplace);
        // Most recent processed_at, so it would otherwise occupy the cached top-five slot.
        $notification = $this->sentRecruitmentNotificationFor([$recipient]);

        $this->actingAs($stranger, 'api');
        $this->assertNotContains(
            $notification->id,
            collect($this->service->getLatestSystemNotifications())
                ->pluck('id')
                ->all()
        );

        // Even the recipient does not get it from here — the cache is shared by everyone, so
        // it can only ever hold notifications that are safe for all callers.
        $this->actingAs($recipient, 'api');
        $this->assertNotContains(
            $notification->id,
            collect($this->service->getLatestSystemNotifications())
                ->pluck('id')
                ->all()
        );

        // ...but the recipient still receives it through the regular, per-user scoped list.
        $this->assertContains($notification->id, $this->service->getNotifications()->pluck('id')->all());
    }

    public function testWarmingTheSystemCacheAsANonRecipientDoesNotLeakItLater()
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $stranger = $this->createEmployeeAt($this->workplace);
        $notification = $this->sentRecruitmentNotificationFor([$recipient]);

        $this->actingAs($stranger, 'api');
        $this->service->getLatestSystemNotifications();

        $this->actingAs($recipient, 'api');
        $this->assertNotContains(
            $notification->id,
            collect($this->service->getLatestSystemNotifications())
                ->pluck('id')
                ->all()
        );
    }

    public function testNonRecruitmentBroadcastsRemainVisibleToEveryone()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $broadcast = Notification::create([
            'type' => NotificationType::NORMAL->value,
            'title' => 'Company announcement',
            'content' => 'Everyone should see this',
            'status' => NotificationStatus::SENT,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'processed_at' => now(),
            'user_id' => null,
        ]);

        $this->actingAs($employee, 'api');

        $this->assertContains($broadcast->id, $this->service->getNotifications()->pluck('id')->all());
        $this->assertContains(
            $broadcast->id,
            collect($this->service->getLatestSystemNotifications())
                ->pluck('id')
                ->all()
        );
        $this->assertNull($this->service->readNotification($broadcast)['error']);
    }

    /**
     * @param array<int, User> $recipients
     */
    private function sentRecruitmentNotificationFor(array $recipients): Notification
    {
        Cache::forget(NotificationService::SYSTEM_NOTIFICATIONS_CACHE_KEY);

        $notification = $this->createRecruitmentNotification($this->shift, [], NotificationStatus::SENT);
        $notification
            ->forceFill([
                'recipients_resolved_count' => count($recipients),
                'processed_at' => now(),
            ])
            ->saveQuietly();

        foreach ($recipients as $recipient) {
            NotificationRecipient::create([
                'notification_id' => $notification->id,
                'user_id' => $recipient->id,
            ]);
        }

        return $notification;
    }
}
