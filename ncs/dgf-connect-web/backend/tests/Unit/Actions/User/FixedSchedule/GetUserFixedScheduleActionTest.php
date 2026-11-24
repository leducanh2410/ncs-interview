<?php

namespace Tests\Unit\Actions\User\FixedSchedule;

use App\Actions\User\FixedSchedule\GetUserFixedScheduleAction;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeConfig;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleChangeRequestDay;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetUserFixedScheduleActionTest extends TestCase
{
    use RefreshDatabase;

    private GetUserFixedScheduleAction $action;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(GetUserFixedScheduleAction::class);

        $this->employee = User::factory()->create();
        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->shift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    public function testReturnsScheduleWithChangeWindowState(): void
    {
        $this->travelTo('2026-07-16');
        $schedule = $this->createSchedule();
        FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => true,
            'deadline' => '2026-07-20',
        ]);

        $result = ($this->action)($this->employee, $this->workplace->id);

        $this->assertNull($result->getError());
        $data = $result->getData();
        $this->assertSame($schedule->id, $data[0]['fixed_schedule_id']);
        $this->assertTrue($data[0]['change_window']['is_open']);
        $this->assertSame('2026-07-20', $data[0]['change_window']['deadline']);
    }

    public function testReturnsPendingRequestAndPerDayChangeWhenOneExists(): void
    {
        $schedule = $this->createSchedule();
        $otherShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '13:00:00',
            'end_time' => '21:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $this->workplace->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
        FixedScheduleChangeRequestDay::create([
            'fixed_schedule_change_request_id' => $request->id,
            'day' => 1,
            'current_workplace_shift_id' => $this->shift->id,
            'requested_workplace_shift_id' => $otherShift->id,
        ]);

        $result = ($this->action)($this->employee, $this->workplace->id);

        $data = $result->getData();
        $this->assertSame($request->id, $data[0]['pending_request']['id']);
        $dayOne = collect($data[0]['days'])->firstWhere('day', 1);
        $this->assertSame($otherShift->id, $dayOne['pending_or_recent_change']['requested_workplace_shift']['id']);
        $this->assertSame('pending', $dayOne['pending_or_recent_change']['status']);
    }

    public function testReturnsNullPendingRequestWhenNoneExists(): void
    {
        $this->createSchedule();

        $result = ($this->action)($this->employee, $this->workplace->id);

        $this->assertNull($result->getData()[0]['pending_request']);
    }

    public function testIncludesTheWorkplacesActiveShiftCatalogOrderedByStartTime(): void
    {
        $this->createSchedule();
        $eveningShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '18:00:00',
            'end_time' => '22:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $inactiveShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '05:00:00',
            'end_time' => '06:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'is_active' => false,
        ]);

        $result = ($this->action)($this->employee, $this->workplace->id);

        $catalogIds = collect($result->getData()[0]['shift_catalog'])
            ->pluck('id')
            ->all();
        $this->assertSame([$this->shift->id, $eveningShift->id], $catalogIds);
        $this->assertNotContains($inactiveShift->id, $catalogIds);
    }

    public function testReturnsEmptyListWhenUserHasNoSchedule(): void
    {
        $result = ($this->action)($this->employee, null);

        $this->assertNull($result->getError());
        $this->assertCount(0, $result->getData());
    }

    public function testReturnsAllSchedulesWhenNoWorkplaceIdGivenAndUserHasMultiple(): void
    {
        $schedule = $this->createSchedule();
        $otherWorkplace = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $otherSchedule = FixedSchedule::create([
            'user_id' => $this->employee->id,
            'workplace_id' => $otherWorkplace->id,
        ]);

        $result = ($this->action)($this->employee, null);

        $this->assertNull($result->getError());
        $ids = collect($result->getData())->pluck('fixed_schedule_id')->all();
        $this->assertEqualsCanonicalizing([$schedule->id, $otherSchedule->id], $ids);
    }

    public function testFiltersToOneScheduleWhenWorkplaceIdGiven(): void
    {
        $this->createSchedule();
        $otherWorkplace = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        FixedSchedule::create(['user_id' => $this->employee->id, 'workplace_id' => $otherWorkplace->id]);

        $result = ($this->action)($this->employee, $this->workplace->id);

        $this->assertCount(1, $result->getData());
        $this->assertSame($this->workplace->id, $result->getData()[0]['workplace']['id']);
    }

    private function createSchedule(): FixedSchedule
    {
        $schedule = FixedSchedule::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);

        return $schedule;
    }
}
