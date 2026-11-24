<?php

namespace Tests\Unit\Actions\Common\Notification;

use App\Actions\Common\Notification\CalculateUserBadgeCountAction;
use App\Enums\ChatParticipantStatus;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\ChatParticipant;
use App\Models\Notification;
use App\Models\NotificationReadState;
use App\Models\NotificationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CalculateUserBadgeCountActionTest extends TestCase
{
    use RefreshDatabase;

    private CalculateUserBadgeCountAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CalculateUserBadgeCountAction();
    }

    public function testCountsSentAndDuePendingNotifications(): void
    {
        $user = User::factory()->create();

        // Personal SENT notification (unread) → counted.
        $this->makeNotification(['user_id' => $user->id, 'status' => NotificationStatus::SENT->value]);
        // System (user_id null) SENT notification → counted.
        $this->makeNotification(['user_id' => null, 'status' => NotificationStatus::SENT->value]);
        // PENDING but already due → counted (the notification being delivered right now).
        $this->makeNotification([
            'user_id' => $user->id,
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->assertSame(3, $this->action->execute($user->id));
    }

    public function testExcludesFuturePendingReadAndOtherUsersNotifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Future-scheduled PENDING → not yet delivered, excluded.
        $this->makeNotification([
            'user_id' => $user->id,
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->addHour(),
        ]);
        // Belongs to another user → excluded.
        $this->makeNotification(['user_id' => $other->id, 'status' => NotificationStatus::SENT->value]);
        // Already read by the user → excluded.
        $read = $this->makeNotification(['user_id' => $user->id, 'status' => NotificationStatus::SENT->value]);
        NotificationReadState::query()->create([
            'notification_id' => $read->id,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        $this->assertSame(0, $this->action->execute($user->id));
    }

    public function testCountsUnreadActiveConversationsOnly(): void
    {
        $user = User::factory()->create();

        // Two active conversations with unread messages → counted.
        ChatParticipant::factory()
            ->count(2)
            ->create(['user_id' => $user->id, 'unread_count' => 3]);
        // Active but read → excluded.
        ChatParticipant::factory()->create(['user_id' => $user->id, 'unread_count' => 0]);
        // Unread but the participant was removed → excluded.
        ChatParticipant::factory()->create([
            'user_id' => $user->id,
            'unread_count' => 5,
            'status' => ChatParticipantStatus::Removed->value,
            'removed_at' => now(),
        ]);

        $this->assertSame(2, $this->action->execute($user->id));
    }

    public function testCombinesNotificationsAndConversations(): void
    {
        $user = User::factory()->create();

        $this->makeNotification(['user_id' => $user->id, 'status' => NotificationStatus::SENT->value]);
        ChatParticipant::factory()->create(['user_id' => $user->id, 'unread_count' => 1]);

        $this->assertSame(2, $this->action->execute($user->id));
    }

    public function testExecuteForUsersReturnsPerUserCountsWithSharedSystemNotification(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // One system notification (user_id null) applies to both users.
        $system = $this->makeNotification(['user_id' => null, 'status' => NotificationStatus::SENT->value]);
        // userB already read it → excluded for userB only.
        NotificationReadState::query()->create([
            'notification_id' => $system->id,
            'user_id' => $userB->id,
            'read_at' => now(),
        ]);

        // userA: extra personal notification + 1 unread conversation.
        $this->makeNotification(['user_id' => $userA->id, 'status' => NotificationStatus::SENT->value]);
        ChatParticipant::factory()->create(['user_id' => $userA->id, 'unread_count' => 1]);

        // userB: 2 unread conversations only.
        ChatParticipant::factory()
            ->count(2)
            ->create(['user_id' => $userB->id, 'unread_count' => 4]);

        $result = $this->action->executeForUsers([$userA->id, $userB->id]);

        // userA = system(1) + personal(1) + conversations(1) = 3.
        $this->assertSame(3, $result[$userA->id]);
        // userB = system read(0) + conversations(2) = 2.
        $this->assertSame(2, $result[$userB->id]);
    }

    public function testExecuteForUsersReturnsEmptyForNoUsers(): void
    {
        $this->assertSame([], $this->action->executeForUsers([]));
    }

    public function testRecruitmentNotificationOnlyRaisesItsOwnRecipientsBadge(): void
    {
        // Recruitment rows are user_id null like any broadcast, but visibility comes from
        // notification_recipients — so a campaign targeting only $recipient must leave
        // $bystander's badge alone, and $bystander cannot read it to work the count back down.
        $recipient = User::factory()->create();
        $bystander = User::factory()->create();

        $notification = $this->makeNotification([
            'user_id' => null,
            'type' => NotificationType::RECRUITMENT->value,
            'status' => NotificationStatus::SENT->value,
        ]);
        $notification->forceFill(['recipients_resolved_count' => 1])->saveQuietly();
        NotificationRecipient::query()->create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
        ]);

        $counts = $this->action->executeForUsers([$recipient->id, $bystander->id]);

        $this->assertSame(1, $counts[$recipient->id]);
        $this->assertSame(0, $counts[$bystander->id]);
    }

    public function testRecruitmentNotificationResolvedToNobodyRaisesNobodysBadge(): void
    {
        $user = User::factory()->create();

        $notification = $this->makeNotification([
            'user_id' => null,
            'type' => NotificationType::RECRUITMENT->value,
            'status' => NotificationStatus::SENT->value,
        ]);
        // Resolved, and reached no one — distinct from "never resolved" below.
        $notification->forceFill(['recipients_resolved_count' => 0])->saveQuietly();

        $this->assertSame(0, $this->action->execute($user->id));
    }

    public function testLegacyUnresolvedRecruitmentNotificationStillCountsForEveryone(): void
    {
        $user = User::factory()->create();

        // Predates targeting: a null count is the escape hatch that keeps it a broadcast.
        $this->makeNotification([
            'user_id' => null,
            'type' => NotificationType::RECRUITMENT->value,
            'status' => NotificationStatus::SENT->value,
        ]);

        $this->assertSame(1, $this->action->execute($user->id));
    }

    public function testDuePendingRecruitmentNotificationDoesNotCountBeforeItIsResolved(): void
    {
        $user = User::factory()->create();

        // Due, so the broadcast branch would have counted it, and its recipient list does not
        // exist yet — counting it would badge everyone for a campaign that may target nobody.
        $this->makeNotification([
            'user_id' => null,
            'type' => NotificationType::RECRUITMENT->value,
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, $this->action->execute($user->id));
    }

    public function testRecipientWhoReadTheRecruitmentNotificationDropsBackToZero(): void
    {
        $recipient = User::factory()->create();

        $notification = $this->makeNotification([
            'user_id' => null,
            'type' => NotificationType::RECRUITMENT->value,
            'status' => NotificationStatus::SENT->value,
        ]);
        $notification->forceFill(['recipients_resolved_count' => 1])->saveQuietly();
        NotificationRecipient::query()->create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
        ]);
        NotificationReadState::query()->create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
            'read_at' => now(),
        ]);

        $this->assertSame(0, $this->action->execute($recipient->id));
    }

    public function testTargetedRecruitmentHistoryIsNotLoadedIntoMemoryToComputeABadge(): void
    {
        // Targeted recruitment notifications are never pruned and keep being created, so
        // anything that pulls their ids into PHP makes the cost of one push grow with every
        // campaign ever sent. Legacy (never-resolved) broadcasts are different: that set has
        // been closed since targeting shipped, so the action is allowed to bind their ids.
        // Seed a targeted history much larger than the legacy set, then assert no query
        // carries one binding per targeted campaign.
        $recipient = User::factory()->create();
        $legacyCount = 5;
        $targetedCount = 40;

        foreach (range(1, $legacyCount) as $index) {
            $this->makeNotification([
                'user_id' => null,
                'type' => NotificationType::RECRUITMENT->value,
                'status' => NotificationStatus::SENT->value,
            ]);
        }

        foreach (range(1, $targetedCount) as $index) {
            $notification = $this->makeNotification([
                'user_id' => null,
                'type' => NotificationType::RECRUITMENT->value,
                'status' => NotificationStatus::SENT->value,
            ]);
            $notification->forceFill(['recipients_resolved_count' => 1])->saveQuietly();
            NotificationRecipient::query()->create([
                'notification_id' => $notification->id,
                'user_id' => $recipient->id,
            ]);
        }

        $bindingCounts = [];
        DB::listen(function ($query) use (&$bindingCounts) {
            $bindingCounts[] = count($query->bindings);
        });

        $this->assertSame($legacyCount + $targetedCount, $this->action->execute($recipient->id));

        $this->assertNotEmpty($bindingCounts);
        foreach ($bindingCounts as $count) {
            $this->assertLessThan(
                $targetedCount,
                $count,
                'A badge query bound one value per historical targeted recruitment ' .
                    'notification, so its cost grows with the whole history of the system'
            );
        }
    }

    private function makeNotification(array $overrides = []): Notification
    {
        return Notification::query()->create(
            array_merge(
                [
                    'type' => 'test',
                    'title' => 'Title',
                    'content' => 'Content',
                    'status' => NotificationStatus::SENT->value,
                    'scheduled_at' => now()->subMinute(),
                    'data' => [],
                ],
                $overrides
            )
        );
    }
}
