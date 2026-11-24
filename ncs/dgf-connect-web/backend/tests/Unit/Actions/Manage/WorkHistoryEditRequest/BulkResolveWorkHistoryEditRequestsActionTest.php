<?php

namespace Tests\Unit\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\Manage\WorkHistoryEditRequest\BulkResolveWorkHistoryEditRequestsAction;
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
use App\Models\WorkHistoryEditRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class BulkResolveWorkHistoryEditRequestsActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private BulkResolveWorkHistoryEditRequestsAction $action;

    private User $approver;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(BulkResolveWorkHistoryEditRequestsAction::class);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);

        $this->approver = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->approver,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
    }

    private function createPendingRequest(string $date): WorkHistoryEditRequest
    {
        $employee = User::factory()->create();
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_duration' => 0,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::query()->firstOrCreate([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $employee->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        WorkplaceUser::query()->firstOrCreate(['user_id' => $employee->id, 'workplace_id' => $this->workplace->id]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $checkIn = CheckIn::create([
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

        $request = WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $this->workplace->id,
            'requested_by_id' => $employee->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        WorkHistoryEditRequestItem::create([
            'work_history_edit_request_id' => $request->id,
            'field' => 'check_in_time',
            'old_value' => "{$date} 09:00:00",
            'new_value' => "{$date} 09:05:00",
            'reason' => 'Máy chấm công lỗi',
        ]);

        return $request;
    }

    public function testApprovesAllSelectedRequests(): void
    {
        Queue::fake();
        $request1 = $this->createPendingRequest('2026-05-10');
        $request2 = $this->createPendingRequest('2026-05-11');

        $result = ($this->action)($this->approver, [$request1->id, $request2->id], 'approve');

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request1->id,
            'status' => WorkHistoryEditRequestStatus::Approved->value,
        ]);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request2->id,
            'status' => WorkHistoryEditRequestStatus::Approved->value,
        ]);
    }

    public function testRejectsAllSelectedRequestsWithSharedReason(): void
    {
        $request1 = $this->createPendingRequest('2026-05-10');
        $request2 = $this->createPendingRequest('2026-05-11');

        $result = ($this->action)(
            $this->approver,
            [$request1->id, $request2->id],
            'reject',
            'Thiếu bằng chứng đối chiếu'
        );

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request1->id,
            'status' => WorkHistoryEditRequestStatus::Rejected->value,
            'reject_reason' => 'Thiếu bằng chứng đối chiếu',
        ]);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request2->id,
            'status' => WorkHistoryEditRequestStatus::Rejected->value,
            'reject_reason' => 'Thiếu bằng chứng đối chiếu',
        ]);
    }

    public function testRollsBackWholeBatchOnPartialFailure(): void
    {
        Queue::fake();
        $request1 = $this->createPendingRequest('2026-05-10');
        $request2 = $this->createPendingRequest('2026-05-11');
        // Already resolved - approving it inside the batch must fail and roll back request1 too.
        $request2->update(['status' => WorkHistoryEditRequestStatus::Approved->value]);

        $result = ($this->action)($this->approver, [$request1->id, $request2->id], 'approve');

        $this->assertNotNull($result->getError());
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request1->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
    }
}
