<?php

namespace Tests\Feature\User\Schedule;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

/**
 * GET /user/shifts/{shift} through the HTTP layer.
 *
 * Recruitment targeting added a second door into this endpoint — being a resolved recipient
 * rather than a member of the shift's workplace — and that branch is only reachable by an
 * employee who is *not* in the workplace, which no test exercised before. It is covered here
 * end-to-end rather than at the service level because the surrounding middleware
 * (CheckUserPaperStatus in particular) is part of what the recipient list has to agree with.
 */
class GetShiftDetailTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private Workplace $workplace;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = $this->createWorkplaceAt();
        $this->shift = $this->createShiftAt($this->workplace);
    }

    public function testWorkplaceMemberCanOpenTheShift()
    {
        $member = $this->createEmployeeAt($this->workplace);

        $response = $this->actingAs($member, 'api')->getJson("/user/shifts/{$this->shift->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $this->shift->id);
    }

    public function testOutsiderWithoutARecruitmentNotificationIsForbidden()
    {
        $outsider = $this->createEmployeeAt($this->createWorkplaceAt(40.0, 140.0, 'Other'));

        $this->actingAs($outsider, 'api')
            ->getJson("/user/shifts/{$this->shift->id}")
            ->assertForbidden();
    }

    public function testResolvedRecipientOutsideTheWorkplaceCanOpenTheShift()
    {
        // The nearby-workplace / frequent-urgent-group case: pushed the notification, but
        // never attached to the workplace running the shift.
        $outsider = $this->createEmployeeAt($this->createWorkplaceAt(40.0, 140.0, 'Other'));
        $this->sentRecruitmentNotificationFor([$outsider]);

        $response = $this->actingAs($outsider, 'api')->getJson("/user/shifts/{$this->shift->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $this->shift->id);
    }

    public function testOutsiderWhoWasNotAResolvedRecipientStaysForbidden()
    {
        $recipient = $this->createEmployeeAt($this->createWorkplaceAt(40.0, 140.0, 'Other'));
        $bystander = $this->createEmployeeAt($this->createWorkplaceAt(41.0, 141.0, 'Another'));
        $this->sentRecruitmentNotificationFor([$recipient]);

        $this->actingAs($bystander, 'api')
            ->getJson("/user/shifts/{$this->shift->id}")
            ->assertForbidden();
    }

    public function testRecipientOfANotificationForAnotherShiftStaysForbidden()
    {
        $outsider = $this->createEmployeeAt($this->createWorkplaceAt(40.0, 140.0, 'Other'));
        $otherShift = $this->createShiftAt($this->workplace, now()->addDays(2)->format('Y-m-d'));
        $this->sentRecruitmentNotificationFor([$outsider], $otherShift);

        $this->actingAs($outsider, 'api')
            ->getJson("/user/shifts/{$this->shift->id}")
            ->assertForbidden();
    }

    public function testRecipientOfANotificationThatHasNotBeenSentYetStaysForbidden()
    {
        // Recipient rows are only written at send time, but a PENDING row with rows attached
        // must still not open the door early — the access check requires SENT.
        $outsider = $this->createEmployeeAt($this->createWorkplaceAt(40.0, 140.0, 'Other'));
        $notification = $this->createRecruitmentNotification($this->shift, [], NotificationStatus::PENDING);
        $notification->forceFill(['recipients_resolved_count' => 1])->saveQuietly();
        NotificationRecipient::create(['notification_id' => $notification->id, 'user_id' => $outsider->id]);

        $this->actingAs($outsider, 'api')
            ->getJson("/user/shifts/{$this->shift->id}")
            ->assertForbidden();
    }

    /**
     * @param array<int, User> $recipients
     */
    private function sentRecruitmentNotificationFor(array $recipients, ?Shift $shift = null): Notification
    {
        $notification = $this->createRecruitmentNotification($shift ?? $this->shift, [], NotificationStatus::SENT);
        $notification
            ->forceFill(['recipients_resolved_count' => count($recipients), 'processed_at' => now()])
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
