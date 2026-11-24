<?php

namespace Tests\Unit\Actions\User\FixedSchedule;

use App\Actions\User\FixedSchedule\CreateFixedScheduleChangeRequestAction;
use App\Enums\ErrorCode;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeConfig;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateFixedScheduleChangeRequestActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateFixedScheduleChangeRequestAction $action;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $shiftA;

    private WorkplaceShift $shiftB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(CreateFixedScheduleChangeRequestAction::class);

        $this->employee = User::factory()->create();
        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->shiftA = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $this->shiftB = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '13:00:00',
            'end_time' => '21:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    public function testCreatesRequestWithOneDayPerChangedDay(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule([1 => $this->shiftA]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $this->assertNull($result->getError());
        $this->assertDatabaseCount('fixed_schedule_change_request_days', 1);
        $this->assertDatabaseHas('fixed_schedule_change_request_days', [
            'day' => 1,
            'current_workplace_shift_id' => $this->shiftA->id,
            'requested_workplace_shift_id' => $this->shiftB->id,
        ]);
    }

    public function testCreatesRequestCoveringMultipleChangedDaysInOneSubmission(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule([1 => $this->shiftA, 2 => $this->shiftA]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [
                ['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id],
                ['day' => 2, 'requested_workplace_shift_id' => $this->shiftB->id],
            ],
        ]);

        $this->assertNull($result->getError());
        $this->assertDatabaseCount('fixed_schedule_change_request_days', 2);
    }

    public function testOmitsUnchangedDaysFromTheCreatedRequest(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule([1 => $this->shiftA, 2 => $this->shiftA]);

        ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [
                ['day' => 1, 'requested_workplace_shift_id' => $this->shiftA->id],
                ['day' => 2, 'requested_workplace_shift_id' => $this->shiftB->id],
            ],
        ]);

        $this->assertDatabaseCount('fixed_schedule_change_request_days', 1);
        $this->assertDatabaseHas('fixed_schedule_change_request_days', ['day' => 2]);
    }

    public function testCreatesRequestDayForDayOff(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule([1 => $this->shiftA]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => null]],
        ]);

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('fixed_schedule_change_request_days', [
            'day' => 1,
            'current_workplace_shift_id' => $this->shiftA->id,
            'requested_workplace_shift_id' => null,
        ]);
    }

    public function testRejectsWhenWindowClosed(): void
    {
        $this->travelTo('2026-07-16');
        FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => false,
            'deadline' => '2026-07-20',
        ]);
        $schedule = $this->createSchedule([1 => $this->shiftA]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $this->assertSame(ErrorCode::FixedScheduleWindowClosed, $result->getError());
        $this->assertDatabaseCount('fixed_schedule_change_requests', 0);
    }

    public function testRejectsWhenDeadlinePassedEvenIfEnabledStillTrue(): void
    {
        $this->travelTo('2026-07-21');
        FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => true,
            'deadline' => '2026-07-20',
        ]);
        $schedule = $this->createSchedule([1 => $this->shiftA]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $this->assertSame(ErrorCode::FixedScheduleWindowClosed, $result->getError());
    }

    public function testRejectsWhenNoDayActuallyDiffersFromCurrentSchedule(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule([1 => $this->shiftA]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftA->id]],
        ]);

        $this->assertSame(ErrorCode::FixedScheduleNoChangedDays, $result->getError());
    }

    public function testRejectsWhenAnExistingPendingRequestForThisScheduleAlreadyExists(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule([1 => $this->shiftA, 2 => $this->shiftA]);
        FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $this->workplace->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 2, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $this->assertSame(ErrorCode::FixedScheduleRequestAlreadyPending, $result->getError());
    }

    public function testAllowsNewRequestAfterPriorOneResolved(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule([1 => $this->shiftA]);
        FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $this->workplace->id,
            'status' => FixedScheduleChangeRequestStatus::Rejected->value,
        ]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $this->assertNull($result->getError());
    }

    public function testRejectsEntireSubmissionWhenAnyDayUsesAShiftFromAnotherWorkplace(): void
    {
        $this->openWindow();
        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Addr',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $foreignShift = WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $schedule = $this->createSchedule([1 => $this->shiftA]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $foreignShift->id]],
        ]);

        $this->assertSame(ErrorCode::FixedScheduleInvalidShift, $result->getError());
        $this->assertDatabaseCount('fixed_schedule_change_requests', 0);
    }

    public function testRejectsWhenRequestedShiftIsInactive(): void
    {
        $this->openWindow();
        $this->shiftB->update(['is_active' => false]);
        $schedule = $this->createSchedule([1 => $this->shiftA]);

        $result = ($this->action)($this->employee, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $this->assertSame(ErrorCode::FixedScheduleInvalidShift, $result->getError());
        $this->assertDatabaseCount('fixed_schedule_change_requests', 0);
    }

    public function testRejectsWhenScheduleNotOwnedByUser(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule([1 => $this->shiftA]);
        $otherUser = User::factory()->create();

        $result = ($this->action)($otherUser, [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
    }

    private function openWindow(): void
    {
        $this->travelTo('2026-07-16');
        FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => true,
            'deadline' => '2026-07-20',
        ]);
    }

    private function createSchedule(array $days = [1 => null]): FixedSchedule
    {
        $schedule = FixedSchedule::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);
        foreach ($days as $day => $shift) {
            if ($shift !== null) {
                FixedScheduleDetail::create([
                    'fixed_schedule_id' => $schedule->id,
                    'day' => $day,
                    'workplace_shift_id' => $shift->id,
                ]);
            }
        }

        return $schedule;
    }
}
