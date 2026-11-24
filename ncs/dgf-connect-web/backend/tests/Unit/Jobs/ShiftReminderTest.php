<?php

namespace Tests\Unit\Jobs;

use App\Enums\NotificationType;
use App\Enums\ShiftUserStatus;
use App\Jobs\ShiftReminder;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ShiftReminder hydrates ShiftUser with a column-limited select and then save()s a reminder
 * timestamp, which fires the `updated` model event. These tests run the real job end to end:
 * they pin that reminders are produced AND that the save no longer trips
 * ShiftPlanDraftCleanupObserver::status() on a partially-hydrated model (the production
 * TypeError this job's `select` must keep `shift_users.status` in to avoid).
 */
class ShiftReminderTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplacePosition $position;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // 08:00 today; the shift below starts at 10:00, i.e. 120 min out — inside the
        // 360-min shift-reminder window but outside the 15-min check-in window.
        Carbon::setTestNow('2026-06-22 08:00:00');

        $this->manager = User::factory()->create();
        $this->workplace = Workplace::create([
            'name' => 'Reminder WP',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testSendsShiftReminderForApprovedUpcomingShift(): void
    {
        $shiftUser = $this->approvedShiftUser('10:00:00', '14:00:00');

        (new ShiftReminder())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
        $this->assertNotNull($shiftUser->fresh()->shift_reminded_at);

        $notification = Notification::query()
            ->where('type', NotificationType::SHIFT_REMINDER->value)
            ->where('user_id', $shiftUser->user_id)
            ->firstOrFail();
        $this->assertGreaterThan(0, $notification->translations()->count());
    }

    public function testDoesNotRemindTwice(): void
    {
        $shiftUser = $this->approvedShiftUser('10:00:00', '14:00:00');
        $shiftUser->update(['shift_reminded_at' => now()->subHour()]);

        (new ShiftReminder())->handle();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::SHIFT_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
    }

    public function testSkipsUnapprovedShiftUser(): void
    {
        $shiftUser = $this->approvedShiftUser('10:00:00', '14:00:00');
        $shiftUser->update(['status' => ShiftUserStatus::Pending->value]);

        (new ShiftReminder())->handle();

        $this->assertDatabaseMissing('notifications', ['user_id' => $shiftUser->user_id]);
        $this->assertNull($shiftUser->fresh()->shift_reminded_at);
    }

    public function testSendsFinalConfirmationReminderForApprovedUnconfirmedShiftWithinFinalWindow(): void
    {
        // 08:00 now, shift at 10:30 -> 150 min out, inside the default 180-min final window.
        $shiftUser = $this->approvedShiftUser('10:30:00', '14:30:00');

        (new ShiftReminder())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
        $this->assertNotNull($shiftUser->fresh()->final_confirmation_reminded_at);

        $notification = Notification::query()
            ->where('type', NotificationType::CONFIRM_REMINDER->value)
            ->where('user_id', $shiftUser->user_id)
            ->firstOrFail();
        $this->assertGreaterThan(0, $notification->translations()->count());
    }

    public function testDoesNotSendFinalConfirmationReminderWhenAlreadyConfirmed(): void
    {
        $shiftUser = $this->approvedShiftUser('10:30:00', '14:30:00', ['confirmed_at' => now()]);

        (new ShiftReminder())->handle();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
        $this->assertNull($shiftUser->fresh()->final_confirmation_reminded_at);
    }

    public function testDoesNotSendFinalConfirmationReminderForNonApprovedStatus(): void
    {
        foreach ([ShiftUserStatus::Pending->value, ShiftUserStatus::Rejected->value] as $status) {
            $shiftUser = $this->approvedShiftUser('10:30:00', '14:30:00', ['status' => $status]);

            (new ShiftReminder())->handle();

            $this->assertDatabaseMissing('notifications', [
                'type' => NotificationType::CONFIRM_REMINDER->value,
                'user_id' => $shiftUser->user_id,
            ]);
        }
    }

    public function testDoesNotSendFinalConfirmationReminderWhenAlreadyReminded(): void
    {
        $remindedAt = now()->subMinutes(30);
        $shiftUser = $this->approvedShiftUser('10:30:00', '14:30:00', [
            'final_confirmation_reminded_at' => $remindedAt,
        ]);

        (new ShiftReminder())->handle();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
        $this->assertEquals($remindedAt->toDateTimeString(), $shiftUser->fresh()->final_confirmation_reminded_at);
    }

    public function testDoesNotSendFinalConfirmationReminderOnceShiftHasStarted(): void
    {
        // Shift started 30 min ago and ends in 210 min, so it is mid-shift, not near the end.
        $shiftUser = $this->approvedShiftUser('10:00:00', '14:00:00');
        Carbon::setTestNow('2026-06-22 10:30:00');

        (new ShiftReminder())->handle();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
        $this->assertNull($shiftUser->fresh()->final_confirmation_reminded_at);
    }

    public function testSendsFinalConfirmationReminderForOvernightShiftUsingRealStartTimestamp(): void
    {
        // Shift crosses midnight: 22:00 -> 06:00. Now is 21:00, 60 min before the real start.
        $shiftUser = $this->approvedShiftUser('22:00:00', '06:00:00');
        Carbon::setTestNow('2026-06-22 21:00:00');

        (new ShiftReminder())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
    }

    public function testEmitsFinalConfirmationReminderWhenTwoDayReminderNeverRan(): void
    {
        // No confirmation_reminded_at set: the two-day reminder never ran for this assignment.
        $shiftUser = $this->approvedShiftUser('10:30:00', '14:30:00');
        $this->assertNull($shiftUser->confirmation_reminded_at);

        (new ShiftReminder())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
    }

    public function testWidenedTooEarlyGuardAdmitsTheFinalConfirmationWindow(): void
    {
        // Raise the final window above the shift/check-in windows so a shift that is too
        // early for either of those, but inside the raised final window, must still remind.
        config(['work.reminder.confirmation.final_before_minute' => 400]);
        // 08:00 now, shift at 14:40 -> 400 min out: outside the 360-min shift window and the
        // 15-min check-in window, but exactly inside the raised 400-min final window.
        $shiftUser = $this->approvedShiftUser('14:40:00', '18:40:00');

        (new ShiftReminder())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::SHIFT_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
    }

    public function testStillSendsCheckInReminderWithinWindowRegressionCheck(): void
    {
        // 08:00 now, shift at 08:10 -> 10 min out, inside the 15-min check-in window.
        $shiftUser = $this->approvedShiftUser('08:10:00', '12:10:00');

        (new ShiftReminder())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::CHECK_IN_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
    }

    public function testStillSendsCheckOutReminderNearShiftEndRegressionCheck(): void
    {
        // Shift 06:00 -> 08:05: started, and 5 min from ending, inside the 15-min check-out window.
        $shiftUser = $this->approvedShiftUser('06:00:00', '08:05:00');
        $shiftUser->checkIn()->create([
            'shift_id' => $shiftUser->shift_id,
            'user_id' => $shiftUser->user_id,
            'check_in_time' => Carbon::parse('2026-06-22 06:00:00'),
        ]);

        (new ShiftReminder())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::CHECK_OUT_REMINDER->value,
            'user_id' => $shiftUser->user_id,
        ]);
    }

    private function approvedShiftUser(string $start, string $end, array $extra = []): ShiftUser
    {
        $type = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $start,
            'end_time' => $end,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'is_active' => true,
        ]);
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $type->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-06-22',
            'adjusted_date' => '2026-06-22',
            'fixed_total' => 0,
            'temp_total' => 5,
        ]);
        $member = User::factory()->create();

        return ShiftUser::create(
            array_merge(
                [
                    'shift_id' => $shift->id,
                    'user_id' => $member->id,
                    'workplace_position_id' => $this->position->id,
                    'status' => ShiftUserStatus::Approved->value,
                ],
                $extra
            )
        );
    }
}
