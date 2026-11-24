<?php

namespace Tests\Unit\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\Manage\WorkHistoryEditRequest\RejectWorkHistoryEditRequestAction;
use App\Enums\ErrorCode;
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
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class RejectWorkHistoryEditRequestActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private RejectWorkHistoryEditRequestAction $action;

    private User $approver;

    private User $requester;

    private Workplace $workplace;

    private CheckIn $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(RejectWorkHistoryEditRequestAction::class);

        $this->requester = User::factory()->create();
        $employee = User::factory()->create();

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);

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
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->requester->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        WorkplaceUser::create(['user_id' => $employee->id, 'workplace_id' => $this->workplace->id]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $employee->id,
            'check_in_time' => '2026-05-10 09:00:00',
            'check_out_time' => '2026-05-10 18:00:00',
            'check_in_time_rounded' => '2026-05-10 09:00:00',
            'check_out_time_rounded' => '2026-05-10 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        $this->approver = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->approver,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
    }

    private function createPendingRequest(): WorkHistoryEditRequest
    {
        $request = WorkHistoryEditRequest::create([
            'check_in_id' => $this->checkIn->id,
            'workplace_id' => $this->workplace->id,
            'requested_by_id' => $this->requester->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        WorkHistoryEditRequestItem::create([
            'work_history_edit_request_id' => $request->id,
            'field' => 'check_in_time',
            'old_value' => '2026-05-10 09:00:00',
            'new_value' => '2026-05-10 09:05:00',
            'reason' => 'Máy chấm công lỗi',
        ]);

        return $request->fresh(['items']);
    }

    public function testRejectionDoesNotChangeCheckIn(): void
    {
        $request = $this->createPendingRequest();

        ($this->action)($this->approver, $request, 'Không hợp lệ');

        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $this->checkIn->check_in_time);
    }

    public function testRequiresRejectReason(): void
    {
        $request = $this->createPendingRequest();

        $result = ($this->action)($this->approver, $request, '   ');

        $this->assertSame(ErrorCode::WorkHistoryEditRequestRejectReasonRequired, $result->getError());
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
    }

    public function testSetsProcessedByAndProcessedAtAndRejectReason(): void
    {
        $request = $this->createPendingRequest();

        $result = ($this->action)($this->approver, $request, 'Giờ đề xuất không khớp camera chấm công');

        $data = $result->getData();
        $this->assertSame(WorkHistoryEditRequestStatus::Rejected->value, $data->status);
        $this->assertSame($this->approver->id, $data->processed_by_id);
        $this->assertNotNull($data->processed_at);
        $this->assertSame('Giờ đề xuất không khớp camera chấm công', $data->reject_reason);
    }

    public function testReturns409ForAlreadyResolvedRequest(): void
    {
        $request = $this->createPendingRequest();
        ($this->action)($this->approver, $request, 'Lần 1');

        $result = ($this->action)($this->approver, $request, 'Lần 2');

        $this->assertSame(ErrorCode::WorkHistoryEditRequestNotPending, $result->getError());
    }

    public function testForbidsApproverOutsideManagedWorkplace(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Addr',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $outsideApprover = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $outsideApprover,
            PermissionName::WorkHistoryEditRequestProcess,
            $otherWorkplace->id
        );
        $request = $this->createPendingRequest();

        $result = ($this->action)($outsideApprover, $request, 'Không hợp lệ');

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
    }
}
