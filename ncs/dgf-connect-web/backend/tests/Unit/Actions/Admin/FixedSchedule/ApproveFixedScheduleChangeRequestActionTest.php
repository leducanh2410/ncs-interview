<?php

namespace Tests\Unit\Actions\Admin\FixedSchedule;

use App\Actions\Admin\FixedSchedule\ApproveFixedScheduleChangeRequestAction;
use App\Enums\ErrorCode;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Enums\PermissionName;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleChangeRequestDay;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class ApproveFixedScheduleChangeRequestActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private ApproveFixedScheduleChangeRequestAction $action;

    private User $admin;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $shiftA;

    private WorkplaceShift $shiftB;

    private FixedSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ApproveFixedScheduleChangeRequestAction::class);

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
        $this->schedule = FixedSchedule::create([
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftA->id,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 2,
            'workplace_shift_id' => $this->shiftA->id,
        ]);

        $this->admin = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->admin,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );
    }

    public function testApprovalUpdatesFixedScheduleDetailForEveryDayInTheRequest(): void
    {
        $request = $this->createRequest([1 => $this->shiftB->id, 2 => $this->shiftB->id]);

        ($this->action)($this->admin, $request);

        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftB->id,
        ]);
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 2,
            'workplace_shift_id' => $this->shiftB->id,
        ]);
    }

    public function testApprovalRemovesFixedScheduleDetailForDayOffRequestDays(): void
    {
        $request = $this->createRequest([1 => null]);

        ($this->action)($this->admin, $request);

        $this->assertDatabaseMissing('fixed_schedule_details', [
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 1,
        ]);
    }

    public function testApprovalSetsProcessedByAndProcessedAt(): void
    {
        $request = $this->createRequest([1 => $this->shiftB->id]);

        $result = ($this->action)($this->admin, $request);

        $data = $result->getData();
        $this->assertSame(FixedScheduleChangeRequestStatus::Approved->value, $data->status);
        $this->assertSame($this->admin->id, $data->processed_by_id);
        $this->assertNotNull($data->processed_at);
    }

    public function testRejectsActionOnAlreadyResolvedRequest(): void
    {
        $request = $this->createRequest([1 => $this->shiftB->id]);
        $request->update(['status' => FixedScheduleChangeRequestStatus::Approved->value]);

        $result = ($this->action)($this->admin, $request);

        $this->assertSame(ErrorCode::FixedScheduleRequestNotPending, $result->getError());
    }

    public function testRejectsStaleInMemoryRequestAlreadyResolvedByAnotherAdmin(): void
    {
        $request = $this->createRequest([1 => $this->shiftB->id]);
        // Simulate route-model-binding having resolved $request before another admin's
        // request updated the row: the in-memory object still reads status "pending".
        $staleRequest = FixedScheduleChangeRequest::find($request->id);
        FixedScheduleChangeRequest::query()
            ->whereKey($request->id)
            ->update(['status' => FixedScheduleChangeRequestStatus::Rejected->value]);

        $result = ($this->action)($this->admin, $staleRequest);

        $this->assertSame(ErrorCode::FixedScheduleRequestNotPending, $result->getError());
        $this->assertDatabaseMissing('fixed_schedule_details', [
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftB->id,
        ]);
    }

    public function testRejectsApprovalWhenRequestedShiftBecameInactiveAfterSubmission(): void
    {
        $request = $this->createRequest([1 => $this->shiftB->id]);
        $this->shiftB->update(['is_active' => false]);

        $result = ($this->action)($this->admin, $request);

        $this->assertSame(ErrorCode::FixedScheduleInvalidShift, $result->getError());
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftA->id,
        ]);
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $request->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
    }

    public function testForbidsAdminOutsideManagedWorkplace(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Addr',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $outsideAdmin = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $outsideAdmin,
            PermissionName::FixedScheduleChangeRequestProcess,
            $otherWorkplace->id
        );
        $request = $this->createRequest([1 => $this->shiftB->id]);

        $result = ($this->action)($outsideAdmin, $request);

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $request->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
    }

    private function createRequest(array $days): FixedScheduleChangeRequest
    {
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $this->schedule->id,
            'workplace_id' => $this->workplace->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
        foreach ($days as $day => $requestedShiftId) {
            FixedScheduleChangeRequestDay::create([
                'fixed_schedule_change_request_id' => $request->id,
                'day' => $day,
                'current_workplace_shift_id' => $this->shiftA->id,
                'requested_workplace_shift_id' => $requestedShiftId,
            ]);
        }

        return $request;
    }
}
