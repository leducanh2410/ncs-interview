<?php

namespace Tests\Unit\Actions\Manage\Notification;

use App\Actions\Manage\Notification\ResolveWorkplaceRecipientsAction;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

class ResolveWorkplaceRecipientsActionTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    public function testResolvesDistinctActiveMembersOfEverySelectedWorkplace(): void
    {
        $workplaceA = $this->createWorkplaceAt(35.0, 139.0, 'A');
        $workplaceB = $this->createWorkplaceAt(35.1, 139.1, 'B');
        $workplaceC = $this->createWorkplaceAt(35.2, 139.2, 'C');
        $onlyA = $this->createEmployeeAt($workplaceA);
        $both = $this->createEmployeeAt($workplaceA);
        $workplaceB->users()->syncWithoutDetaching([$both->id]);
        $onlyB = $this->createEmployeeAt($workplaceB);
        $disabled = $this->createEmployeeAt($workplaceB);
        $disabled->update(['is_disabled' => true]);
        $this->createEmployeeAt($workplaceC);

        $notification = $this->workplaceNotification([$workplaceA, $workplaceB]);

        $ids = app(ResolveWorkplaceRecipientsAction::class)($notification);

        $this->assertEqualsCanonicalizing([$onlyA->id, $both->id, $onlyB->id], $ids);
        $this->assertSame(3, $notification->fresh()->recipients_resolved_count);
        $this->assertSame(3, NotificationRecipient::query()->where('notification_id', $notification->id)->count());
    }

    public function testResolvesToNobodyWhenWorkplacesWereRemoved(): void
    {
        $notification = $this->workplaceNotification([]);

        $ids = app(ResolveWorkplaceRecipientsAction::class)($notification);

        $this->assertSame([], $ids);
        $this->assertSame(0, $notification->fresh()->recipients_resolved_count);
    }

    public function testTestOnlyWorkplacesReachOnlyTestUsers(): void
    {
        $testWorkplace = $this->createWorkplaceAt(35.0, 139.0, 'Test');
        $testWorkplace->update(['is_test' => true]);
        $testUser = $this->createEmployeeAt($testWorkplace);
        $testUser->update(['is_test' => true]);
        $productionUser = $this->createEmployeeAt($testWorkplace);

        $ids = app(ResolveWorkplaceRecipientsAction::class)($this->workplaceNotification([$testWorkplace]));

        $this->assertSame([$testUser->id], $ids);
        $this->assertNotContains($productionUser->id, $ids);
    }

    /**
     * @param Workplace[] $workplaces
     */
    private function workplaceNotification(array $workplaces): Notification
    {
        $notification = Notification::create([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'title' => 'Title',
            'content' => 'Content',
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
        ]);
        $notification->workplaces()->sync(array_map(fn(Workplace $workplace) => $workplace->id, $workplaces));

        return $notification;
    }
}
