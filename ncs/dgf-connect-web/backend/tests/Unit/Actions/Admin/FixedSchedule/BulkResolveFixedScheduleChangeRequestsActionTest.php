<?php

namespace Tests\Unit\Actions\Admin\FixedSchedule;

use App\Actions\Admin\FixedSchedule\BulkResolveFixedScheduleChangeRequestsAction;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Enums\PermissionName;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleChangeRequestDay;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class BulkResolveFixedScheduleChangeRequestsActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private BulkResolveFixedScheduleChangeRequestsAction $action;

    private User $admin;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    private WorkplaceShift $shiftA;

    private WorkplaceShift $shiftB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(BulkResolveFixedScheduleChangeRequestsAction::class);

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->otherWorkplace = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
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

        $this->admin = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->admin,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );
        $this->giveWorkplaceScopedPermission(
            $this->admin,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->otherWorkplace->id
        );
    }

    private function createRequest(Workplace $workplace): FixedScheduleChangeRequest
    {
        $employee = User::factory()->create();
        $schedule = FixedSchedule::create(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftA->id,
        ]);
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $workplace->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
        FixedScheduleChangeRequestDay::create([
            'fixed_schedule_change_request_id' => $request->id,
            'day' => 1,
            'current_workplace_shift_id' => $this->shiftA->id,
            'requested_workplace_shift_id' => $this->shiftB->id,
        ]);

        return $request;
    }

    public function testApprovesAllSelectedRequests(): void
    {
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);

        $result = ($this->action)($this->admin, [$requestA->id, $requestB->id], 'approve');

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestA->id,
            'status' => FixedScheduleChangeRequestStatus::Approved->value,
        ]);
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestB->id,
            'status' => FixedScheduleChangeRequestStatus::Approved->value,
        ]);
    }

    public function testRejectsAllSelectedRequests(): void
    {
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);

        ($this->action)($this->admin, [$requestA->id, $requestB->id], 'reject');

        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestA->id,
            'status' => FixedScheduleChangeRequestStatus::Rejected->value,
        ]);
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestB->id,
            'status' => FixedScheduleChangeRequestStatus::Rejected->value,
        ]);
    }

    public function testRollsBackWholeBatchOnPartialFailure(): void
    {
        $requestA = $this->createRequest($this->workplace);
        $requestOutOfScope = $this->createRequest($this->otherWorkplace);
        $adminMissingSecondScope = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $adminMissingSecondScope,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );

        $result = ($this->action)($adminMissingSecondScope, [$requestA->id, $requestOutOfScope->id], 'approve');

        $this->assertNotNull($result->getError());
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestA->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
    }

    public function testRollsBackWholeBatchWhenARequestIsDeletedMidBatch(): void
    {
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);
        // Simulate the request being deleted after BulkResolveRequest's `exists` validation ran
        // but before this action's loop reaches it, so findOrFail throws mid-transaction.
        $requestB->delete();

        $this->expectException(ModelNotFoundException::class);

        try {
            ($this->action)($this->admin, [$requestA->id, $requestB->id], 'approve');
        } finally {
            $this->assertDatabaseHas('fixed_schedule_change_requests', [
                'id' => $requestA->id,
                'status' => FixedScheduleChangeRequestStatus::Pending->value,
            ]);
        }
    }
}
