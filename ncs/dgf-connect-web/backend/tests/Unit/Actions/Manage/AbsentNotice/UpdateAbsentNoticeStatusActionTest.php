<?php

namespace Tests\Unit\Actions\Manage\AbsentNotice;

use App\Actions\ActionResult;
use App\Actions\Manage\AbsentNotice\UpdateAbsentNoticeStatusAction;
use App\Enums\AbsentNoticeShiftOutcome;
use App\Enums\AbsentNoticeStatus;
use App\Enums\ErrorCode;
use App\Enums\NotificationType;
use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateAbsentNoticeStatusActionTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    public function testMarkingProcessedRecordsActorAndTimestamp()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $admin = User::factory()->create();
        $this->actingAs($admin, 'api');
        $notice = $this->createAbsentNotice();

        $result = app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);

        $this->assertNull($result->getError());
        $notice->refresh();
        $this->assertSame(AbsentNoticeStatus::Processed, $notice->status);
        $this->assertSame($admin->id, $notice->processed_by_id);
        $this->assertNotNull($notice->processed_at);
    }

    public function testRevertingToPendingClearsActorAndTimestamp()
    {
        $admin = User::factory()->create();
        $this->actingAs($admin, 'api');
        $notice = $this->createAbsentNotice();
        $notice->update([
            'status' => AbsentNoticeStatus::Processed,
            'processed_by_id' => $admin->id,
            'processed_at' => now(),
        ]);

        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Pending);

        $notice->refresh();
        $this->assertSame(AbsentNoticeStatus::Pending, $notice->status);
        $this->assertNull($notice->processed_by_id);
        $this->assertNull($notice->processed_at);
    }

    public function testMarkingProcessedMovesShiftUserToRejectedList()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $admin = User::factory()->create();
        $this->actingAs($admin, 'api');
        $notice = $this->createAbsentNotice();
        $shiftUser = $notice->shiftUser;
        $shiftUser->update(['confirmed_at' => now()]);

        $result = app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);

        $shiftUser->refresh();
        $this->assertSame(ShiftUserStatus::Rejected->value, $shiftUser->status);
        $this->assertNull($shiftUser->confirmed_at);
        $this->assertSame($admin->id, $shiftUser->approver_id);
        $this->assertShiftOutcome($result, AbsentNoticeShiftOutcome::ShiftRejected, notified: true);
    }

    public function testMarkingProcessedKeepsTheShiftWhenTheEmployeeAlreadyCheckedInEarly()
    {
        // 08:30 the morning of a 09:00 shift: check-in opens an hour before the start, so an
        // employee who filed a request but turned up anyway is already on the clock. Closing the
        // request must not delete that attendance or strand them off a shift they are working.
        $this->travelTo(now()->setDate(2026, 3, 10)->setTime(8, 45));
        $this->actingAs(User::factory()->create(), 'api');
        $notice = $this->createAbsentNotice();
        $shiftUser = $notice->shiftUser;
        $checkIn = CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shiftUser->shift_id,
            'user_id' => $shiftUser->user_id,
            'check_in_time' => '2026-03-10 08:30:00',
        ]);

        $result = app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);

        $this->assertSame(AbsentNoticeStatus::Processed, $notice->fresh()->status);
        $this->assertSame(ShiftUserStatus::Approved->value, $shiftUser->fresh()->status);
        $this->assertDatabaseHas('check_ins', ['id' => $checkIn->id]);
        $this->assertDatabaseMissing('notifications', ['type' => NotificationType::SHIFT_REJECTED->value]);
        $this->assertShiftOutcome($result, AbsentNoticeShiftOutcome::ShiftKeptCheckedIn, notified: false);
    }

    public function testMarkingProcessedReportsThatAManagerAssignedEmployeeWasNotNotified()
    {
        // CreateNotificationForUser stays quiet for rows a manager created, so the admin screen
        // must not claim the employee was told the shift is off.
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $admin = User::factory()->create();
        $this->actingAs($admin, 'api');
        $notice = $this->createAbsentNotice();
        $notice->shiftUser->update(['created_by' => $admin->id]);

        $result = app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);

        $this->assertSame(ShiftUserStatus::Rejected->value, $notice->shiftUser->fresh()->status);
        $this->assertDatabaseMissing('notifications', ['type' => NotificationType::SHIFT_REJECTED->value]);
        $this->assertShiftOutcome($result, AbsentNoticeShiftOutcome::ShiftRejected, notified: false);
    }

    public function testMarkingProcessedNotifiesEmployeeOfTheShiftRejection()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $this->actingAs(User::factory()->create(), 'api');
        $notice = $this->createAbsentNotice();

        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_REJECTED->value,
            'user_id' => $notice->shiftUser->user_id,
        ]);
    }

    public function testMarkingProcessedLeavesAShiftThatAlreadyStartedUntouched()
    {
        // 09:30 on the shift day: the employee may already have checked in, so the shift row and
        // its check-in must survive; only the notice is closed.
        $this->travelTo(now()->setDate(2026, 3, 10)->setTime(9, 30));
        $this->actingAs(User::factory()->create(), 'api');
        $notice = $this->createAbsentNotice();
        $shiftUser = $notice->shiftUser;
        $checkIn = CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shiftUser->shift_id,
            'user_id' => $shiftUser->user_id,
            'check_in_time' => '2026-03-10 09:05:00',
        ]);

        $result = app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);

        $this->assertSame(AbsentNoticeStatus::Processed, $notice->fresh()->status);
        $this->assertSame(ShiftUserStatus::Approved->value, $shiftUser->fresh()->status);
        $this->assertDatabaseHas('check_ins', ['id' => $checkIn->id]);
        $this->assertShiftOutcome($result, AbsentNoticeShiftOutcome::ShiftKeptAlreadyStarted, notified: false);
    }

    public function testMarkingProcessedTwiceDoesNotNotifyTheEmployeeAgain()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $this->actingAs(User::factory()->create(), 'api');
        $notice = $this->createAbsentNotice();

        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);
        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);

        $this->assertSame(1, Notification::query()->where('type', NotificationType::SHIFT_REJECTED->value)->count());
    }

    public function testRejectingKeepsTheEmployeeOnTheShiftAndRecordsActor()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $admin = User::factory()->create();
        $this->actingAs($admin, 'api');
        $notice = $this->createAbsentNotice();
        $shiftUser = $notice->shiftUser;
        $shiftUser->update(['confirmed_at' => now()]);

        $result = app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Rejected);

        $this->assertShiftOutcome($result, AbsentNoticeShiftOutcome::ShiftUnchanged, notified: true);
        $notice->refresh();
        $this->assertSame(AbsentNoticeStatus::Rejected, $notice->status);
        $this->assertSame($admin->id, $notice->processed_by_id);
        $this->assertNotNull($notice->processed_at);

        $shiftUser->refresh();
        $this->assertSame(ShiftUserStatus::Approved->value, $shiftUser->status);
        $this->assertNotNull($shiftUser->confirmed_at);
    }

    public function testRejectingNotifiesTheEmployee()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $this->actingAs(User::factory()->create(), 'api');
        $notice = $this->createAbsentNotice();

        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Rejected);

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::ABSENT_NOTICE_REJECTED->value,
            'user_id' => $notice->shiftUser->user_id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::SHIFT_REJECTED->value,
        ]);
    }

    public function testRevertingToPendingDoesNotPutTheEmployeeBackOnTheShift()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $this->actingAs(User::factory()->create(), 'api');
        $notice = $this->createAbsentNotice();

        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);
        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Pending);

        $this->assertSame(AbsentNoticeStatus::Pending, $notice->fresh()->status);
        $this->assertSame(ShiftUserStatus::Rejected->value, $notice->shiftUser->fresh()->status);
    }

    public function testRejectingIsBlockedOnceTheEmployeeIsOffTheShift()
    {
        // Refusing the request tells the employee to work the shift as planned. Processing already
        // took the shift away from them, so the refusal is refused instead of sending an
        // instruction they cannot follow.
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $this->actingAs(User::factory()->create(), 'api');
        $notice = $this->createAbsentNotice();

        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Processed);
        $result = app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Rejected);

        $this->assertSame(ErrorCode::AbsentNoticeShiftNotApproved, $result->getError());
        $this->assertSame(409, $result->getStatus());
        $this->assertSame(AbsentNoticeStatus::Processed, $notice->fresh()->status);
        $this->assertSame(ShiftUserStatus::Rejected->value, $notice->shiftUser->fresh()->status);
        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::ABSENT_NOTICE_REJECTED->value,
        ]);
    }

    public function testRejectingIsBlockedWhileTheShiftRowIsStillPending()
    {
        // A row waiting for approval is not a shift the employee can be told to work yet.
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $this->actingAs(User::factory()->create(), 'api');
        $notice = $this->createAbsentNotice();
        $notice->shiftUser->update(['status' => ShiftUserStatus::Pending->value]);

        $result = app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Rejected);

        $this->assertSame(ErrorCode::AbsentNoticeShiftNotApproved, $result->getError());
        $this->assertSame(AbsentNoticeStatus::Pending, $notice->fresh()->status);
    }

    private function assertShiftOutcome(ActionResult $result, AbsentNoticeShiftOutcome $outcome, bool $notified): void
    {
        $this->assertNull($result->getError());
        $payload = $result->toResponse()->getData(true);
        $this->assertSame($outcome->value, $payload['shift_outcome']);
        $this->assertSame($notified, $payload['notified']);
    }

    private function createAbsentNotice(): ShiftAbsentNotice
    {
        $user = User::factory()->create();

        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $user->id,
            'date' => '2026-03-10',
            'adjusted_date' => '2026-03-10',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        return ShiftAbsentNotice::create(['shift_user_id' => $shiftUser->id]);
    }
}
