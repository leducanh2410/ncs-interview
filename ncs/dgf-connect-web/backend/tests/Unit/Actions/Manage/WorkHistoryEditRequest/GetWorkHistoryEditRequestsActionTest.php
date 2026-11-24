<?php

namespace Tests\Unit\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\Manage\WorkHistoryEditRequest\GetWorkHistoryEditRequestsAction;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Models\WorkHistoryEditRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class GetWorkHistoryEditRequestsActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private GetWorkHistoryEditRequestsAction $action;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    private User $employeeA;

    private User $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(GetWorkHistoryEditRequestsAction::class);

        $this->workplaceA = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->workplaceB = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);

        $this->employeeA = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Nguyen']);
        $this->employeeB = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Tran']);
    }

    private function createCheckIn(Workplace $workplace, User $employee, string $date): CheckIn
    {
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_duration' => 0,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::query()->firstOrCreate(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $employee->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        WorkplaceUser::query()->firstOrCreate(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        return CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $employee->id,
            'check_in_time' => "{$date} 09:00:00",
            'check_out_time' => "{$date} 18:00:00",
            'break_minutes' => 60,
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    private function createRequest(
        Workplace $workplace,
        CheckIn $checkIn,
        string $status,
        ?Carbon $createdAt = null
    ): WorkHistoryEditRequest {
        $request = WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $workplace->id,
            'requested_by_id' => $checkIn->user_id,
            'status' => $status,
        ]);

        if ($createdAt) {
            $request->forceFill(['created_at' => $createdAt])->save();
        }

        return $request;
    }

    public function testFiltersByWorkplaceScopedToManagedWorkplaces(): void
    {
        $checkInA = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-10');
        $checkInB = $this->createCheckIn($this->workplaceB, $this->employeeB, '2026-05-10');
        $this->createRequest($this->workplaceA, $checkInA, WorkHistoryEditRequestStatus::Pending->value);
        $this->createRequest($this->workplaceB, $checkInB, WorkHistoryEditRequestStatus::Pending->value);

        $leader = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $leader,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplaceA->id
        );

        $result = ($this->action)($leader, []);

        $items = $result->getData()->items();
        $this->assertCount(1, $items);
        $this->assertSame($this->workplaceA->id, $items[0]->workplace_id);
    }

    public function testFiltersByEmployeeNameSearch(): void
    {
        $checkInA = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-10');
        $checkInB = $this->createCheckIn($this->workplaceA, $this->employeeB, '2026-05-10');
        $this->createRequest($this->workplaceA, $checkInA, WorkHistoryEditRequestStatus::Pending->value);
        $this->createRequest($this->workplaceA, $checkInB, WorkHistoryEditRequestStatus::Pending->value);

        $leader = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $leader,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplaceA->id
        );

        $result = ($this->action)($leader, ['employee_name' => 'Alice']);

        $items = $result->getData()->items();
        $this->assertCount(1, $items);
        $this->assertSame($checkInA->id, $items[0]->check_in_id);
    }

    public function testFiltersByStatusDefaultingToPending(): void
    {
        $checkIn = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-10');
        $this->createRequest($this->workplaceA, $checkIn, WorkHistoryEditRequestStatus::Approved->value);
        $checkIn2 = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-11');
        $this->createRequest($this->workplaceA, $checkIn2, WorkHistoryEditRequestStatus::Pending->value);

        $leader = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $leader,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplaceA->id
        );

        $result = ($this->action)($leader, []);

        $items = $result->getData()->items();
        $this->assertCount(1, $items);
        $this->assertSame(WorkHistoryEditRequestStatus::Pending->value, $items[0]->status);
    }

    public function testStatusAllClearsTheDefault(): void
    {
        $checkIn = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-10');
        $this->createRequest($this->workplaceA, $checkIn, WorkHistoryEditRequestStatus::Approved->value);
        $checkIn2 = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-11');
        $this->createRequest($this->workplaceA, $checkIn2, WorkHistoryEditRequestStatus::Pending->value);

        $leader = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $leader,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplaceA->id
        );

        $result = ($this->action)($leader, ['status' => 'all']);

        $this->assertCount(2, $result->getData()->items());
    }

    public function testFiltersByCreatedAtDateRange(): void
    {
        $checkIn1 = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-10');
        $this->createRequest(
            $this->workplaceA,
            $checkIn1,
            WorkHistoryEditRequestStatus::Pending->value,
            Carbon::parse('2026-05-01 10:00:00')
        );
        $checkIn2 = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-11');
        $this->createRequest(
            $this->workplaceA,
            $checkIn2,
            WorkHistoryEditRequestStatus::Pending->value,
            Carbon::parse('2026-06-01 10:00:00')
        );

        $leader = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $leader,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplaceA->id
        );

        $result = ($this->action)($leader, ['dates' => ['2026-05-01', '2026-05-31']]);

        $items = $result->getData()->items();
        $this->assertCount(1, $items);
        $this->assertSame($checkIn1->id, $items[0]->check_in_id);
    }

    public function testGlobalAdminSeesRequestsAcrossAllWorkplaces(): void
    {
        $checkInA = $this->createCheckIn($this->workplaceA, $this->employeeA, '2026-05-10');
        $checkInB = $this->createCheckIn($this->workplaceB, $this->employeeB, '2026-05-10');
        $this->createRequest($this->workplaceA, $checkInA, WorkHistoryEditRequestStatus::Pending->value);
        $this->createRequest($this->workplaceB, $checkInB, WorkHistoryEditRequestStatus::Pending->value);

        $globalAdmin = User::factory()->create();
        $this->giveGlobalPermission($globalAdmin, PermissionName::WorkHistoryEditRequestProcess);

        $result = ($this->action)($globalAdmin, ['status' => 'all']);

        $this->assertCount(2, $result->getData()->items());
    }
}
