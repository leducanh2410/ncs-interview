<?php

namespace Tests\Unit\Services\Manage;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Shift;
use App\Models\Workplace;
use App\Services\Manage\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

/**
 * Covers the read/received statistic added to the admin notification list: the denominator
 * comes straight from recipients_resolved_count, and the numerator is a read count
 * constrained to actual recipients so it can never exceed the denominator — a real risk
 * since "mark all as read" creates read states for people who were never targeted.
 */
class NotificationStatisticsTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private NotificationService $notificationService;

    private Workplace $workplace;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationService = app(NotificationService::class);
        $this->workplace = $this->createWorkplaceAt();
        $this->shift = $this->createShiftAt($this->workplace);
    }

    public function testRecipientCountComesFromTheResolvedCountColumn()
    {
        $notification = $this->sentRecruitmentNotification(recipientCount: 5, readCount: 0);

        $row = $this->fetchRow($notification->id);

        $this->assertSame(5, $row->recipients_resolved_count);
    }

    public function testReadCountOnlyCountsReadStatesFromActualRecipients()
    {
        $notification = $this->sentRecruitmentNotification(recipientCount: 2, readCount: 0);
        [$recipientA, $recipientB] = $notification->recipients()->pluck('user_id')->all();

        // A genuine recipient reads it.
        $this->markNotificationRead($notification, \App\Models\User::find($recipientA));

        // A stranger's read state for the same notification must not inflate the count —
        // this is exactly what "mark all as read" can otherwise produce for a non-recipient.
        $stranger = $this->createEmployeeAt($this->createWorkplaceAt(40.0, 140.0, 'Elsewhere'));
        $this->markNotificationRead($notification, $stranger);

        $row = $this->fetchRow($notification->id);

        $this->assertSame(2, $row->recipients_resolved_count);
        $this->assertSame(1, $row->read_count, 'Only the genuine recipient\'s read should count');
    }

    public function testReadCountCanNeverExceedRecipientCount()
    {
        $notification = $this->sentRecruitmentNotification(recipientCount: 1, readCount: 0);
        [$recipientId] = $notification->recipients()->pluck('user_id')->all();
        $recipient = \App\Models\User::find($recipientId);

        $this->markNotificationRead($notification, $recipient);
        // A duplicate/late read-state write for the same recipient (e.g. "mark all as read"
        // running again) must not push the numerator past the denominator.
        \App\Models\NotificationReadState::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $recipient->id)
            ->update(['read_at' => now()]);

        $row = $this->fetchRow($notification->id);

        $this->assertLessThanOrEqual($row->recipients_resolved_count, $row->read_count);
    }

    public function testCountsDoNotDriftAsEmployeesRegisterForTheShiftAfterDelivery()
    {
        $notification = $this->sentRecruitmentNotification(recipientCount: 3, readCount: 1);

        $before = $this->fetchRow($notification->id);

        // An employee registers for the shift after delivery — this must not retroactively
        // change a figure that already describes a past event.
        $latecomer = $this->createEmployeeAt($this->workplace);
        $this->assignToShift($this->shift, $latecomer);

        $after = $this->fetchRow($notification->id);

        $this->assertSame($before->recipients_resolved_count, $after->recipients_resolved_count);
        $this->assertSame($before->read_count, $after->read_count);
    }

    public function testAPendingNotificationHasNoResolvedCountYet()
    {
        $notification = $this->createRecruitmentNotification($this->shift, [], NotificationStatus::PENDING);

        $row = $this->fetchRow($notification->id);

        $this->assertNull($row->recipients_resolved_count, 'Unresolved must be distinguishable from resolved-to-zero');
    }

    public function testNonRecruitmentNotificationsHaveNoResolvedCount()
    {
        $broadcast = Notification::query()->create([
            'type' => NotificationType::NORMAL->value,
            'title' => 'Announcement',
            'content' => 'Announcement',
            'status' => NotificationStatus::SENT,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'processed_at' => now(),
            'user_id' => null,
        ]);

        $row = $this->fetchRow($broadcast->id);

        $this->assertNull($row->recipients_resolved_count);
    }

    public function testReadCountOnABroadcastCountsEveryReaderNotJustRecipients()
    {
        // A broadcast has no recipient list at all. Constraining the count to
        // notification_recipients — as the recruitment rule requires — makes the subquery
        // match nothing here, so the figure silently reads zero however many people opened it.
        $broadcast = Notification::query()->create([
            'type' => NotificationType::NORMAL->value,
            'title' => 'Announcement',
            'content' => 'Announcement',
            'status' => NotificationStatus::SENT,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'processed_at' => now(),
            'user_id' => null,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->markNotificationRead($broadcast, $this->createEmployeeAt($this->workplace));
        }

        $row = $this->fetchRow($broadcast->id);

        $this->assertSame(3, (int) $row->read_count);
    }

    public function testListingAPageIssuesNoPerRowQueries()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->sentRecruitmentNotification(recipientCount: 2, readCount: 1);
        }

        DB::enableQueryLog();
        $this->notificationService->getNotificationList([]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // A handful of fixed queries regardless of row count (list + count + translations +
        // shifts + the two withCount aggregates) — not one growing with the number of rows.
        $this->assertLessThanOrEqual(10, count($queries));
    }

    private function sentRecruitmentNotification(int $recipientCount, int $readCount): Notification
    {
        $notification = $this->createRecruitmentNotification($this->shift, [], NotificationStatus::SENT);
        $notification
            ->forceFill(['recipients_resolved_count' => $recipientCount, 'processed_at' => now()])
            ->saveQuietly();

        for ($i = 0; $i < $recipientCount; $i++) {
            $employee = $this->createEmployeeAt($this->workplace);
            NotificationRecipient::create([
                'notification_id' => $notification->id,
                'user_id' => $employee->id,
            ]);
            if ($i < $readCount) {
                $this->markNotificationRead($notification, $employee);
            }
        }

        return $notification->fresh();
    }

    private function fetchRow(int $notificationId): object
    {
        return $this->notificationService->getNotificationList([])->getCollection()->firstWhere('id', $notificationId);
    }
}
