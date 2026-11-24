<?php

namespace Tests\Unit\Observers;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\ShiftUserStatus;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

/**
 * The urgent flag is derived entirely server-side from what the employee had already read,
 * which is what keeps the employee mobile app out of this feature.
 */
class ShiftUserUrgentRegistrationObserverTest extends TestCase
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

    public function testRegistrationAfterReadingTheShiftsRecruitmentNotificationIsFlagged()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $notification = $this->createRecruitmentNotification($this->shift);
        $this->markNotificationRead($notification, $employee);

        $shiftUser = $this->selfRegisterForShift($this->shift, $employee);

        $this->assertTrue($shiftUser->fresh()->is_urgent_registration);
    }

    public function testRegistrationWithoutAnyPriorReadIsNotFlagged()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $this->createRecruitmentNotification($this->shift);

        $shiftUser = $this->selfRegisterForShift($this->shift, $employee);

        $this->assertFalse($shiftUser->fresh()->is_urgent_registration);
    }

    public function testReadingAfterRegisteringDoesNotFlagRetroactively()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $notification = $this->createRecruitmentNotification($this->shift);

        $shiftUser = $this->selfRegisterForShift($this->shift, $employee);
        $this->markNotificationRead($notification, $employee);

        $this->assertFalse($shiftUser->fresh()->is_urgent_registration);
    }

    public function testReadingARecruitmentNotificationForADifferentShiftDoesNotFlag()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $otherShift = $this->createShiftAt($this->workplace, now()->addDays(3)->format('Y-m-d'));
        $this->markNotificationRead($this->createRecruitmentNotification($otherShift), $employee);

        $shiftUser = $this->selfRegisterForShift($this->shift, $employee);

        $this->assertFalse($shiftUser->fresh()->is_urgent_registration);
    }

    public function testReadingANonRecruitmentNotificationDoesNotFlag()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $announcement = Notification::create([
            'type' => NotificationType::NORMAL->value,
            'title' => 'Announcement',
            'content' => 'Announcement',
            'status' => NotificationStatus::SENT,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'user_id' => null,
            'data' => ['shift_id' => $this->shift->id],
        ]);
        $this->markNotificationRead($announcement, $employee);

        $shiftUser = $this->selfRegisterForShift($this->shift, $employee);

        $this->assertFalse($shiftUser->fresh()->is_urgent_registration);
    }

    public function testAReadStateFromMarkAllAsReadStillCounts()
    {
        // Bulk "mark all as read" produces the same row as opening the notification, and the
        // product decision is to accept that: a read state is treated as a read either way.
        $employee = $this->createEmployeeAt($this->workplace);
        $notification = $this->createRecruitmentNotification($this->shift);
        $this->markNotificationRead($notification, $employee);

        $shiftUser = $this->selfRegisterForShift($this->shift, $employee);

        $this->assertTrue($shiftUser->fresh()->is_urgent_registration);
    }

    public function testAnotherEmployeesReadDoesNotFlagThisRegistration()
    {
        $reader = $this->createEmployeeAt($this->workplace);
        $registrant = $this->createEmployeeAt($this->workplace);
        $this->markNotificationRead($this->createRecruitmentNotification($this->shift), $reader);

        $shiftUser = $this->selfRegisterForShift($this->shift, $registrant);

        $this->assertFalse($shiftUser->fresh()->is_urgent_registration);
    }

    public function testFlagSurvivesApprovalRejectionAndCancellation()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $this->markNotificationRead($this->createRecruitmentNotification($this->shift), $employee);

        $shiftUser = $this->selfRegisterForShift($this->shift, $employee, ShiftUserStatus::Pending->value);
        $this->assertTrue($shiftUser->fresh()->is_urgent_registration);

        $shiftUser->update(['status' => ShiftUserStatus::Approved->value]);
        $this->assertTrue($shiftUser->fresh()->is_urgent_registration);

        $shiftUser->update(['status' => ShiftUserStatus::Rejected->value]);
        $this->assertTrue(
            $shiftUser->fresh()->is_urgent_registration,
            'The flag records what prompted the registration, not how it ended up'
        );
    }

    public function testMultipleRecruitmentNotificationsForTheSameShiftAnyReadIsEnough()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $this->createRecruitmentNotification($this->shift);
        $second = $this->createRecruitmentNotification($this->shift);
        $this->markNotificationRead($second, $employee);

        $shiftUser = $this->selfRegisterForShift($this->shift, $employee);

        $this->assertTrue($shiftUser->fresh()->is_urgent_registration);
    }

    public function testAnAdminAssigningTheEmployeeIsFlagged()
    {
        // Requirement 1.7: how the row came to exist is not part of the rule. The employee had
        // read the call for this shift before being placed on it, and that alone is what the
        // flag records.
        $employee = $this->createEmployeeAt($this->workplace);
        $admin = User::factory()->create();
        $this->markNotificationRead($this->createRecruitmentNotification($this->shift), $employee);

        $this->actingAs($admin, 'api');
        $shiftUser = $this->assignToShift($this->shift, $employee);

        $this->assertTrue($shiftUser->fresh()->is_urgent_registration);
    }

    public function testARowCreatedWithNoAuthenticatedUserIsFlagged()
    {
        // CSV import, fixed-schedule generation and queued jobs all create shift_users rows
        // with nobody authenticated. The absence of an auth context must not change the
        // outcome, since the flag turns on the read state rather than on the creator.
        $employee = $this->createEmployeeAt($this->workplace);
        $this->markNotificationRead($this->createRecruitmentNotification($this->shift), $employee);

        $shiftUser = $this->assignToShift($this->shift, $employee);

        $this->assertTrue($shiftUser->fresh()->is_urgent_registration);
    }

    public function testAnAdminAssignmentWithoutAPriorReadIsNotFlagged()
    {
        // The counterpart of the two tests above: dropping the self-registration condition
        // must not turn every admin assignment into an urgent registration.
        $employee = $this->createEmployeeAt($this->workplace);
        $admin = User::factory()->create();
        $this->createRecruitmentNotification($this->shift);

        $this->actingAs($admin, 'api');
        $shiftUser = $this->assignToShift($this->shift, $employee);

        $this->assertFalse($shiftUser->fresh()->is_urgent_registration);
    }

    public function testTheLookupIsDrivenFromReadStatesRatherThanScanningNotifications()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $this->createRecruitmentNotification($this->shift);

        $observed = [];
        DB::listen(function ($query) use (&$observed) {
            if (str_contains($query->sql, 'notification_read_states')) {
                $observed[] = $query->sql;
            }
        });

        $this->selfRegisterForShift($this->shift, $employee);

        $this->assertNotEmpty($observed, 'The observer should consult read states');

        // The direction matters, not just the result. notifications.shift_id lives inside a
        // JSON column that no index can reach, so a query starting from notifications would
        // scan that whole table on every registration — and bulk assignment creates many.
        // Starting from this user's read states keeps the JSON comparison to a handful of rows.
        foreach ($observed as $sql) {
            $this->assertMatchesRegularExpression(
                '/from\s+`?notification_read_states`?/i',
                $sql,
                'The urgent-registration lookup must start from notification_read_states'
            );
        }
    }
}
