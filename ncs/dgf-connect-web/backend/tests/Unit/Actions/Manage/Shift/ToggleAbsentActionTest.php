<?php

namespace Tests\Unit\Actions\Manage\Shift;

use App\Actions\Manage\Shift\ToggleAbsentAction;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Jobs\CollectWorkTime;
use App\Jobs\UpdateUserSalary;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ToggleAbsentActionTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function testToggleAbsentFromFalseToTrue()
    {
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value, false);

        $action = app(ToggleAbsentAction::class);
        $result = $action($shiftUser->id);

        $this->assertNull($result->getError());
        $this->assertTrue($shiftUser->fresh()->is_absent);
    }

    public function testToggleAbsentFromTrueToFalse()
    {
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value, true);

        $action = app(ToggleAbsentAction::class);
        $result = $action($shiftUser->id);

        $this->assertNull($result->getError());
        $this->assertFalse($shiftUser->fresh()->is_absent);
    }

    public function testToggleAbsentMultipleTimes()
    {
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value, false);

        $action = app(ToggleAbsentAction::class);

        $action($shiftUser->id);
        $this->assertTrue($shiftUser->fresh()->is_absent);

        $action($shiftUser->id);
        $this->assertFalse($shiftUser->fresh()->is_absent);

        $action($shiftUser->id);
        $this->assertTrue($shiftUser->fresh()->is_absent);
    }

    public function testReturnsErrorWhenShiftUserNotFound()
    {
        $action = app(ToggleAbsentAction::class);
        $result = $action(99999);

        $this->assertEquals(ErrorCode::ShiftUserNotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
    }

    public function testReturnsErrorWhenStatusIsPending()
    {
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Pending->value);

        $action = app(ToggleAbsentAction::class);
        $result = $action($shiftUser->id);

        $this->assertEquals(ErrorCode::ShiftUserInvalidStatus, $result->getError());
        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($shiftUser->fresh()->is_absent);
    }

    public function testReturnsErrorWhenStatusIsRejected()
    {
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Rejected->value);

        $action = app(ToggleAbsentAction::class);
        $result = $action($shiftUser->id);

        $this->assertEquals(ErrorCode::ShiftUserInvalidStatus, $result->getError());
        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($shiftUser->fresh()->is_absent);
    }

    public function testUpdatesTimestamp()
    {
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value);
        $originalUpdatedAt = $shiftUser->updated_at;

        $this->travel(5)->seconds();

        $action = app(ToggleAbsentAction::class);
        $action($shiftUser->id);

        $this->assertGreaterThan($originalUpdatedAt, $shiftUser->fresh()->updated_at);
    }

    public function testDispatchesRecollectJobsWhenACheckInExists()
    {
        Queue::fake();
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value);
        CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shiftUser->shift_id,
            'user_id' => $this->user->id,
            'check_in_time' => now()->setTime(9, 0),
            'check_out_time' => now()->setTime(18, 0),
            'work_minutes' => 480,
        ]);

        $action = app(ToggleAbsentAction::class);
        $action($shiftUser->id);

        Queue::assertPushed(UpdateUserSalary::class);
        Queue::assertPushed(CollectWorkTime::class);
    }

    public function testDoesNotDispatchRecollectJobsWithoutACheckIn()
    {
        Queue::fake();
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value);

        $action = app(ToggleAbsentAction::class);
        $action($shiftUser->id);

        Queue::assertNothingPushed();
    }

    public function testReturnsShiftUserDataOnSuccess()
    {
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value);

        $action = app(ToggleAbsentAction::class);
        $result = $action($shiftUser->id);

        $this->assertNull($result->getError());
        $data = $result->getData();
        $this->assertEquals($shiftUser->id, $data->id);
        $this->assertTrue($data->is_absent);
    }

    public function testRefusesToCountAnAbsentShiftAgainWhenItWouldExceedTheWeeklyLimit()
    {
        config(['work.max_hour_per_week' => 28]);
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value);
        $action = app(ToggleAbsentAction::class);
        $this->assertNull($action($shiftUser->id)->getError());
        foreach ([1, 2, 3] as $daysAhead) {
            $this->approveOn(now()->addDays($daysAhead)->format('Y-m-d'));
        }

        $result = $action($shiftUser->id);

        $this->assertSame(ErrorCode::ScheduleMaxWorkHoursExceeded, $result->getError());
        $this->assertEqualsWithDelta(8.0, $result->getData()['over_hours'], 0.01);
        $this->assertTrue($shiftUser->fresh()->is_absent);
    }

    public function testCountsAnAbsentShiftAgainWhenItStaysWithinTheWeeklyLimit()
    {
        config(['work.max_hour_per_week' => 28]);
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value);
        $action = app(ToggleAbsentAction::class);
        $this->assertNull($action($shiftUser->id)->getError());
        foreach ([1, 2] as $daysAhead) {
            $this->approveOn(now()->addDays($daysAhead)->format('Y-m-d'));
        }

        $this->assertNull($action($shiftUser->id)->getError());
        $this->assertFalse($shiftUser->fresh()->is_absent);
    }

    public function testCountsAnAbsentShiftAgainWhenTheHoursWereAlreadyWorked()
    {
        config(['work.max_hour_per_week' => 28]);
        $shiftUser = $this->createShiftUser(ShiftUserStatus::Approved->value);
        CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shiftUser->shift_id,
            'user_id' => $this->user->id,
            'check_in_time' => now()->setTime(9, 0),
            'check_out_time' => now()->setTime(18, 0),
            'work_minutes' => 480,
        ]);
        $action = app(ToggleAbsentAction::class);
        $this->assertNull($action($shiftUser->id)->getError());
        foreach ([1, 2, 3] as $daysAhead) {
            $this->approveOn(now()->addDays($daysAhead)->format('Y-m-d'));
        }

        $this->assertNull($action($shiftUser->id)->getError());
        $this->assertFalse($shiftUser->fresh()->is_absent);
    }

    private function createShiftUser(string $status = 'approved', bool $isAbsent = false): ShiftUser
    {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => $status,
            'is_absent' => $isAbsent,
        ]);
    }

    private function approveOn(string $date): void
    {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }
}
