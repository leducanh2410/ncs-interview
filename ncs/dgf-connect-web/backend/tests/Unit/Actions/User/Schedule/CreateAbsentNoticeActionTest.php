<?php

namespace Tests\Unit\Actions\User\Schedule;

use App\Actions\Manage\AbsentNotice\UpdateAbsentNoticeStatusAction;
use App\Actions\User\Schedule\CreateAbsentNoticeAction;
use App\Enums\AbsentNoticeStatus;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Mail\ShiftAbsentNoticeEmail;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CreateAbsentNoticeActionTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'work.absent_notice.before_hour' => 12,
            'work.system_notification_emails' => ['manager1@example.com', 'manager2@example.com'],
        ]);

        $this->user = User::factory()->create();

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

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
    }

    public function testSuccessfullyCreatesAbsentNoticeForApprovedShiftWithinDeadline()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(20, 0));
        $shiftUser = $this->createShiftUser($this->user, '2026-03-10');

        $result = app(CreateAbsentNoticeAction::class)($shiftUser, $this->user);

        $this->assertNull($result->getError());
        $this->assertEquals(201, $result->getStatus());
        $this->assertDatabaseHas('shift_absent_notices', ['shift_user_id' => $shiftUser->id]);
    }

    public function testRejectsWhenShiftUserBelongsToAnotherUser()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(20, 0));
        $shiftUser = $this->createShiftUser($this->user, '2026-03-10');
        $anotherUser = User::factory()->create();

        $result = app(CreateAbsentNoticeAction::class)($shiftUser, $anotherUser);

        $this->assertEquals(ErrorCode::Forbidden, $result->getError());
        $this->assertEquals(403, $result->getStatus());
        $this->assertDatabaseCount('shift_absent_notices', 0);
    }

    public function testRejectsWhenShiftUserStatusIsNotApproved()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(20, 0));
        $shiftUser = $this->createShiftUser($this->user, '2026-03-10', ShiftUserStatus::Pending->value);

        $result = app(CreateAbsentNoticeAction::class)($shiftUser, $this->user);

        $this->assertEquals(ErrorCode::ShiftUserInvalidStatus, $result->getError());
        $this->assertEquals(400, $result->getStatus());
        $this->assertDatabaseCount('shift_absent_notices', 0);
    }

    public function testRejectsWhenCurrentTimeIsPastDeadline()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(21, 1));
        $shiftUser = $this->createShiftUser($this->user, '2026-03-10');

        $result = app(CreateAbsentNoticeAction::class)($shiftUser, $this->user);

        $this->assertEquals(ErrorCode::AbsentNoticeDeadlinePassed, $result->getError());
        $this->assertEquals(400, $result->getStatus());
        $this->assertDatabaseCount('shift_absent_notices', 0);
    }

    public function testRejectsWhenNoticeAlreadyExists()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(20, 0));
        $shiftUser = $this->createShiftUser($this->user, '2026-03-10');
        ShiftAbsentNotice::create(['shift_user_id' => $shiftUser->id]);

        $result = app(CreateAbsentNoticeAction::class)($shiftUser, $this->user);

        $this->assertEquals(ErrorCode::AbsentNoticeAlreadyExists, $result->getError());
        $this->assertEquals(409, $result->getStatus());
    }

    public function testReopensARefusedNoticeAndRefreshesTheRequestTime()
    {
        $this->travelTo(now()->setDate(2026, 3, 8)->setTime(20, 0));
        $shiftUser = $this->createShiftUser($this->user, '2026-03-10');
        $notice = ShiftAbsentNotice::create(['shift_user_id' => $shiftUser->id]);
        // Refusal goes through the admin action, the only thing that produces a refused notice.
        $this->actingAs(User::factory()->create(), 'api');
        app(UpdateAbsentNoticeStatusAction::class)($notice, AbsentNoticeStatus::Rejected);

        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(20, 0));
        $result = app(CreateAbsentNoticeAction::class)($shiftUser, $this->user);

        $this->assertNull($result->getError());
        $this->assertEquals(201, $result->getStatus());
        $this->assertEquals(1, ShiftAbsentNotice::query()->count());
        $notice->refresh();
        $this->assertSame(AbsentNoticeStatus::Pending, $notice->status);
        $this->assertNull($notice->processed_by_id);
        $this->assertNull($notice->processed_at);
        $this->assertSame('2026-03-09 20:00:00', $notice->created_at->format('Y-m-d H:i:s'));
    }

    public function testQueuesEmailOnSuccessfulCreation()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(20, 0));
        $shiftUser = $this->createShiftUser($this->user, '2026-03-10');

        app(CreateAbsentNoticeAction::class)($shiftUser, $this->user);

        Mail::assertQueued(ShiftAbsentNoticeEmail::class, function (ShiftAbsentNoticeEmail $mail) {
            $recipients = collect($mail->to)->pluck('address')->sort()->values()->all();

            return $recipients === ['manager1@example.com', 'manager2@example.com'];
        });
    }

    public function testSkipsEmailWhenSystemNotificationEmailsAreEmpty()
    {
        config(['work.system_notification_emails' => []]);
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(20, 0));
        $shiftUser = $this->createShiftUser($this->user, '2026-03-10');

        $result = app(CreateAbsentNoticeAction::class)($shiftUser, $this->user);

        $this->assertNull($result->getError());
        Mail::assertNothingQueued();
    }

    private function createShiftUser(
        User $user,
        string $shiftDate,
        string $status = ShiftUserStatus::Approved->value
    ): ShiftUser {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $shiftDate,
            'adjusted_date' => $shiftDate,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => $status,
        ]);
    }
}
