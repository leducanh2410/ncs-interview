<?php

namespace Tests\Unit\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\Manage\WorkHistoryEditRequest\CreateWorkHistoryEditRequestAction;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class CreateWorkHistoryEditRequestActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private CreateWorkHistoryEditRequestAction $action;

    private User $manager;

    private User $employee;

    private Workplace $workplace;

    private CheckIn $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(CreateWorkHistoryEditRequestAction::class);

        $this->manager = User::factory()->create();
        $this->employee = User::factory()->create();

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
            'creator_id' => $this->manager->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);

        WorkplaceUser::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $this->checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $this->employee->id,
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

        $this->giveWorkplaceScopedPermission($this->manager, PermissionName::WorkHistoryEdit, $this->workplace->id);
    }

    public function testCreatesPendingRequestWithOneItemPerChangedField(): void
    {
        Queue::fake();

        $result = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_out_date' => '2026-05-10',
            'check_out_time' => '18:10:00',
            'break_minutes' => 30,
            'check_in_reason' => 'Máy chấm công lỗi',
            'check_out_reason' => 'Về muộn do tăng ca',
            'break_minutes_reason' => 'Nghỉ giữa ca ngắn hơn',
        ]);

        $this->assertNull($result->getError());
        /** @var WorkHistoryEditRequest $request */
        $request = $result->getData();
        $this->assertSame(WorkHistoryEditRequestStatus::Pending->value, $request->status);
        $this->assertSame($this->manager->id, $request->requested_by_id);
        $this->assertSame($this->workplace->id, $request->workplace_id);
        $this->assertCount(3, $request->items);

        $this->assertDatabaseHas('work_history_edit_request_items', [
            'work_history_edit_request_id' => $request->id,
            'field' => 'check_in_time',
            'old_value' => '2026-05-10 09:00:00',
            'new_value' => '2026-05-10 09:05:00',
            'reason' => 'Máy chấm công lỗi',
        ]);
        $this->assertDatabaseHas('work_history_edit_request_items', [
            'work_history_edit_request_id' => $request->id,
            'field' => 'check_out_time',
            'old_value' => '2026-05-10 18:00:00',
            'new_value' => '2026-05-10 18:10:00',
            'reason' => 'Về muộn do tăng ca',
        ]);
        $this->assertDatabaseHas('work_history_edit_request_items', [
            'work_history_edit_request_id' => $request->id,
            'field' => 'break_minutes',
            'old_value' => '60',
            'new_value' => '30',
            'reason' => 'Nghỉ giữa ca ngắn hơn',
        ]);
    }

    public function testOmitsUnchangedFieldsFromTheCreatedRequest(): void
    {
        Queue::fake();

        $result = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:10:00',
            'check_out_date' => '2026-05-10',
            'check_out_time' => '18:00:00',
            'break_minutes' => 60,
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $this->assertNull($result->getError());
        $request = $result->getData();
        $this->assertCount(1, $request->items);
        $this->assertSame('check_in_time', $request->items->first()->field);
    }

    public function testRejectsWhenNoFieldChanged(): void
    {
        $result = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:00:00',
            'check_out_date' => '2026-05-10',
            'check_out_time' => '18:00:00',
            'break_minutes' => 60,
        ]);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestNoChanges, $result->getError());
        $this->assertDatabaseCount('work_history_edit_requests', 0);
    }

    public function testRejectsWhenAPendingRequestAlreadyExistsForTheCheckIn(): void
    {
        WorkHistoryEditRequest::create([
            'check_in_id' => $this->checkIn->id,
            'workplace_id' => $this->workplace->id,
            'requested_by_id' => $this->manager->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);

        $result = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestAlreadyPending, $result->getError());
        $this->assertDatabaseCount('work_history_edit_requests', 1);
    }

    public function testAllowsNewRequestAfterPriorOneWasRejected(): void
    {
        Queue::fake();

        $firstResult = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Lần 1',
        ]);
        $firstRequest = $firstResult->getData();
        // RejectWorkHistoryEditRequestAction ships in Phase 3; this hand-sets the status for now.
        // Phase 3's LifecycleTest (testSubmitAllowedAfterPriorRequestRejected) reaches this same
        // state via the real reject endpoint, satisfying the Test Design Rule at the suite level.
        $firstRequest->update([
            'status' => WorkHistoryEditRequestStatus::Rejected->value,
            'processed_by_id' => $this->manager->id,
            'processed_at' => now(),
            'reject_reason' => 'Không hợp lệ',
        ]);

        $secondResult = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:07:00',
            'check_in_reason' => 'Lần 2',
        ]);

        $this->assertNull($secondResult->getError());
        $this->assertDatabaseCount('work_history_edit_requests', 2);
    }

    public function testDoesNotModifyCheckInOrDispatchPayrollJobs(): void
    {
        Queue::fake();

        ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $this->checkIn->check_in_time);
        Queue::assertNothingPushed();
    }

    public function testStoresWorkplaceIdResolvedFromTheShift(): void
    {
        $result = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $this->assertSame($this->workplace->id, $result->getData()->workplace_id);
    }

    public function testForbidsManagerScopedToADifferentWorkplace(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $outsideManager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($outsideManager, PermissionName::WorkHistoryEdit, $otherWorkplace->id);

        $result = ($this->action)($outsideManager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
        $this->assertDatabaseCount('work_history_edit_requests', 0);
    }

    public function testAllowsDeletingAnExistingCheckOut(): void
    {
        Queue::fake();

        $result = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:00:00',
            'check_out_date' => null,
            'check_out_time' => '',
            'check_out_reason' => 'Chấm nhầm giờ ra',
        ]);

        $this->assertNull($result->getError());
        $request = $result->getData();
        $this->assertCount(1, $request->items);
        $this->assertDatabaseHas('work_history_edit_request_items', [
            'work_history_edit_request_id' => $request->id,
            'field' => 'check_out_time',
            'old_value' => '2026-05-10 18:00:00',
            'new_value' => null,
            'reason' => 'Chấm nhầm giờ ra',
        ]);
    }

    public function testRejectsWhenCheckOutIsAlreadyAbsentAndNoneIsSubmitted(): void
    {
        $this->checkIn->update(['check_out_time' => null]);

        $result = ($this->action)($this->manager, $this->checkIn, [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:00:00',
            'check_out_date' => null,
            'check_out_time' => '',
            'break_minutes' => 60,
        ]);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestNoChanges, $result->getError());
    }
}
