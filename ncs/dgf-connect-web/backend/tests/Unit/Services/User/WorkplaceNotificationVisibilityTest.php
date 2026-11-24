<?php

namespace Tests\Unit\Services\User;

use App\Enums\NotificationStatus;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\User;
use App\Models\Workplace;
use App\Services\User\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

class WorkplaceNotificationVisibilityTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private NotificationService $service;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NotificationService::class);
        $this->workplace = $this->createWorkplaceAt();
        Cache::forget(NotificationService::SYSTEM_NOTIFICATIONS_CACHE_KEY);
    }

    public function testOnlyResolvedRecipientsSeeAWorkplaceTargetedNotification(): void
    {
        $member = $this->createEmployeeAt($this->workplace);
        $stranger = $this->createEmployeeAt($this->workplace);
        $notification = $this->sentWorkplaceNotification([$member]);

        $this->actingAs($member, 'api');
        $this->assertContains($notification->id, $this->service->getNotifications()->pluck('id')->all());
        $this->assertSame(1, $this->service->countUnread());

        $this->actingAs($stranger, 'api');
        $this->assertNotContains($notification->id, $this->service->getNotifications()->pluck('id')->all());
        $this->assertSame(0, $this->service->countUnread());
        $this->assertFalse($notification->isVisibleToUser($stranger->id));
    }

    public function testWorkplaceTargetedNotificationIsNotServedFromTheSharedSystemCache(): void
    {
        $member = $this->createEmployeeAt($this->workplace);
        $stranger = $this->createEmployeeAt($this->workplace);
        $targeted = $this->sentWorkplaceNotification([$member]);
        $broadcast = $this->sentBroadcast();

        $this->actingAs($stranger, 'api');
        $ids = $this->service->getLatestSystemNotifications()->pluck('id')->all();

        $this->assertContains($broadcast->id, $ids);
        $this->assertNotContains($targeted->id, $ids);
    }

    public function testPendingWorkplaceTargetedNotificationIsVisibleToNobody(): void
    {
        $member = $this->createEmployeeAt($this->workplace);
        $notification = Notification::create([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'title' => 'Title',
            'content' => 'Content',
            'status' => NotificationStatus::PENDING->value,
            'is_manually_scheduled' => false,
            'scheduled_at' => now()->addHour(),
        ]);

        $this->assertFalse($notification->isVisibleToUser($member->id));
    }

    public function testBroadcastAndLegacyRowsStayVisibleToEveryone(): void
    {
        $anyone = $this->createEmployeeAt($this->workplace);
        $broadcast = $this->sentBroadcast();

        $this->actingAs($anyone, 'api');
        $this->assertContains($broadcast->id, $this->service->getNotifications()->pluck('id')->all());
        $this->assertTrue($broadcast->isVisibleToUser($anyone->id));
    }

    /**
     * @param User[] $recipients
     */
    private function sentWorkplaceNotification(array $recipients): Notification
    {
        $notification = Notification::create([
            'type' => NotificationType::BANK_ACCOUNT_REQUIRED->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'title' => 'Bank account',
            'content' => 'Please submit',
            'status' => NotificationStatus::SENT->value,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'processed_at' => now(),
        ]);
        $notification->workplaces()->sync([$this->workplace->id]);
        $notification->forceFill(['recipients_resolved_count' => count($recipients)])->saveQuietly();
        foreach ($recipients as $recipient) {
            NotificationRecipient::create(['notification_id' => $notification->id, 'user_id' => $recipient->id]);
        }

        return $notification;
    }

    private function sentBroadcast(): Notification
    {
        return Notification::create([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::All->value,
            'title' => 'Broadcast',
            'content' => 'Everyone',
            'status' => NotificationStatus::SENT->value,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
