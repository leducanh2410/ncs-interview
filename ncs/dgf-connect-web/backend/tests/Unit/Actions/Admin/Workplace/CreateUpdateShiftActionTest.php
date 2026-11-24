<?php

namespace Tests\Unit\Actions\Admin\Workplace;

use App\Actions\Admin\Workplace\CreateUpdateShiftAction;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceShiftLeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreateUpdateShiftActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateUpdateShiftAction $action;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(CreateUpdateShiftAction::class);
        $this->workplace = Workplace::factory()->create();
    }

    /****************************************
     * Break duration                       *
     ****************************************/

    public function testCreatesShiftWithZeroBreakDurationWhenBreakTimesAreMissing(): void
    {
        $result = ($this->action)($this->workplace, null, $this->shiftParams());

        $shift = $result->getData();
        $this->assertInstanceOf(WorkplaceShift::class, $shift);
        $this->assertSame(0, (int) $shift->break_duration);
        $this->assertSame($this->workplace->id, $shift->workplace_id);
    }

    public function testComputesBreakDurationForBreakWithinTheSameDay(): void
    {
        $result = ($this->action)(
            $this->workplace,
            null,
            $this->shiftParams([
                'start_break_time' => '12:00',
                'end_break_time' => '12:45',
            ])
        );

        $this->assertSame(45, (int) $result->getData()->break_duration);
    }

    public function testComputesBreakDurationForBreakCrossingMidnight(): void
    {
        // 23:30 -> 00:15 wraps past midnight, so the end time is pushed to the next day.
        $result = ($this->action)(
            $this->workplace,
            null,
            $this->shiftParams([
                'start_time' => '22:00',
                'end_time' => '06:00',
                'start_break_time' => '23:30',
                'end_break_time' => '00:15',
            ])
        );

        $this->assertSame(45, (int) $result->getData()->break_duration);
    }

    public function testTreatsEqualBreakTimesAsAFullDayBreak(): void
    {
        $result = ($this->action)(
            $this->workplace,
            null,
            $this->shiftParams([
                'start_time' => '09:00',
                'end_time' => '09:00',
                'start_break_time' => '09:00',
                'end_break_time' => '09:00',
            ])
        );

        $this->assertSame(1440, (int) $result->getData()->break_duration);
    }

    /****************************************
     * Create vs update                     *
     ****************************************/

    public function testUpdatesExistingShiftInsteadOfCreatingANewOne(): void
    {
        $shift = $this->createShift();

        $result = ($this->action)($this->workplace, $shift, $this->shiftParams(['end_time' => '18:00']));

        $this->assertSame($shift->id, $result->getData()->id);
        $this->assertSame(1, WorkplaceShift::query()->count());
        $this->assertSame('18:00', $shift->fresh()->end_time);
    }

    /****************************************
     * Leaders                              *
     ****************************************/

    public function testCreatesLeadersWithTheirBonus(): void
    {
        $leader = User::factory()->create();

        $result = ($this->action)(
            $this->workplace,
            null,
            $this->shiftParams([
                'leaders' => [['user_id' => $leader->id, 'bonus' => 500]],
            ])
        );

        $this->assertDatabaseHas('workplace_shift_leaders', [
            'workplace_shift_id' => $result->getData()->id,
            'user_id' => $leader->id,
            'bonus_per_hour' => 500,
        ]);
    }

    public function testUpdatesBonusOfAnExistingLeader(): void
    {
        $shift = $this->createShift();
        $leader = User::factory()->create();
        $shift->leaders()->create(['user_id' => $leader->id, 'bonus_per_hour' => 100]);

        ($this->action)(
            $this->workplace,
            $shift,
            $this->shiftParams([
                'leaders' => [['user_id' => $leader->id, 'bonus' => 800]],
            ])
        );

        $this->assertSame(1, WorkplaceShiftLeader::query()->count());
        $this->assertSame(800, (int) WorkplaceShiftLeader::query()->first()->bonus_per_hour);
    }

    public function testRemovesLeadersThatAreNoLongerInThePayload(): void
    {
        $shift = $this->createShift();
        $kept = User::factory()->create();
        $dropped = User::factory()->create();
        $shift->leaders()->create(['user_id' => $kept->id, 'bonus_per_hour' => 100]);
        $shift->leaders()->create(['user_id' => $dropped->id, 'bonus_per_hour' => 200]);

        ($this->action)(
            $this->workplace,
            $shift,
            $this->shiftParams([
                'leaders' => [['user_id' => $kept->id, 'bonus' => 100]],
            ])
        );

        $this->assertDatabaseHas('workplace_shift_leaders', ['user_id' => $kept->id]);
        $this->assertDatabaseMissing('workplace_shift_leaders', ['user_id' => $dropped->id]);
    }

    public function testRemovesAllLeadersWhenThePayloadHasNone(): void
    {
        $shift = $this->createShift();
        $leader = User::factory()->create();
        $shift->leaders()->create(['user_id' => $leader->id, 'bonus_per_hour' => 100]);

        ($this->action)($this->workplace, $shift, $this->shiftParams(['leaders' => []]));

        $this->assertSame(0, WorkplaceShiftLeader::query()->count());
    }

    public function testDoesNotPersistTheLeadersPayloadOnTheShiftRow(): void
    {
        $leader = User::factory()->create();

        $result = ($this->action)(
            $this->workplace,
            null,
            $this->shiftParams([
                'leaders' => [['user_id' => $leader->id, 'bonus' => 500]],
            ])
        );

        $this->assertArrayNotHasKey('leaders', $result->getData()->getAttributes());
    }

    /****************************************
     * Weekly hour limit                    *
     ****************************************/

    public function testRefusesToLengthenAShiftWhenAnUpcomingAssignmentWouldExceedTheWeeklyLimit(): void
    {
        Carbon::setTestNow('2026-09-07 08:00:00');
        config(['work.max_hour_per_week' => 28]);
        $workplaceShift = $this->createShift();
        $employee = User::factory()->create(['staff_no' => '5001']);
        $this->approveOn($workplaceShift, $employee, ['2026-09-08', '2026-09-09', '2026-09-10']);
        $this->approveOn($this->createShortShift(), $employee, ['2026-09-11']);
        $leader = User::factory()->create();

        $result = ($this->action)(
            $this->workplace,
            $workplaceShift,
            $this->shiftParams(['end_time' => '18:00', 'leaders' => [['user_id' => $leader->id, 'bonus' => 100]]])
        );

        $this->assertSame(ErrorCode::ScheduleMaxWorkHoursExceeded, $result->getError());
        $this->assertSame($employee->id, $result->getData()[0]['user_id']);
        $this->assertSame('5001', $result->getData()[0]['staff_no']);
        $this->assertEqualsWithDelta(1.0, $result->getData()[0]['over_hours'], 0.01);
        $this->assertSame('17:00:00', Carbon::parse($workplaceShift->fresh()->end_time)->format('H:i:s'));
        $this->assertSame(0, WorkplaceShiftLeader::query()->count());

        Carbon::setTestNow();
    }

    public function testLengthensAShiftWhenUpcomingAssignmentsStayWithinTheWeeklyLimit(): void
    {
        Carbon::setTestNow('2026-09-07 08:00:00');
        config(['work.max_hour_per_week' => 28]);
        $workplaceShift = $this->createShift();
        $employee = User::factory()->create();
        $this->approveOn($workplaceShift, $employee, ['2026-09-08', '2026-09-09', '2026-09-10']);
        $this->approveOn($this->createShortShift(), $employee, ['2026-09-11']);

        $result = ($this->action)($this->workplace, $workplaceShift, $this->shiftParams(['end_time' => '17:30']));

        $this->assertNull($result->getError());
        $this->assertSame('17:30:00', Carbon::parse($workplaceShift->fresh()->end_time)->format('H:i:s'));

        Carbon::setTestNow();
    }

    private function shiftParams(array $overrides = []): array
    {
        return array_merge(
            [
                'start_time' => '09:00',
                'end_time' => '17:00',
                'start_break_time' => null,
                'end_break_time' => null,
                'check_in_round_type' => 1,
                'check_in_round_minutes' => 15,
                'check_out_round_type' => 1,
                'check_out_round_minutes' => 15,
                'date_adjustment' => 0,
                'leaders' => [],
            ],
            $overrides
        );
    }

    private function createShift(): WorkplaceShift
    {
        $params = $this->shiftParams();
        unset($params['leaders']);

        return $this->workplace->shifts()->create($params + ['break_duration' => 0]);
    }

    private function createShortShift(): WorkplaceShift
    {
        $params = $this->shiftParams(['start_time' => '09:00', 'end_time' => '11:00']);
        unset($params['leaders']);

        return $this->workplace->shifts()->create($params + ['break_duration' => 0]);
    }

    /**
     * @param list<string> $dates
     */
    private function approveOn(WorkplaceShift $workplaceShift, User $employee, array $dates): void
    {
        $position = WorkplacePosition::firstOrCreate(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);
        foreach ($dates as $date) {
            $shift = Shift::create([
                'workplace_id' => $this->workplace->id,
                'workplace_shift_id' => $workplaceShift->id,
                'creator_id' => $employee->id,
                'date' => $date,
                'adjusted_date' => $date,
                'temp_total' => 5,
                'fixed_total' => 0,
            ]);
            ShiftUser::create([
                'shift_id' => $shift->id,
                'user_id' => $employee->id,
                'workplace_position_id' => $position->id,
                'status' => ShiftUserStatus::Approved->value,
            ]);
        }
    }
}
