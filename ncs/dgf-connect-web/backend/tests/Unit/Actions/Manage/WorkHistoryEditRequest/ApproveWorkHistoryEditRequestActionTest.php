<?php

namespace Tests\Unit\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\Manage\WorkHistoryEditRequest\ApproveWorkHistoryEditRequestAction;
use App\Actions\Manage\WorkHistoryEditRequest\CreateWorkHistoryEditRequestAction;
use App\Actions\Manage\WorkHistoryEditRequest\RejectWorkHistoryEditRequestAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Jobs\CollectWorkTime;
use App\Jobs\CreateShiftReport;
use App\Jobs\UpdateUserSalary;
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

class ApproveWorkHistoryEditRequestActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private ApproveWorkHistoryEditRequestAction $action;

    private User $approver;

    private User $requester;

    private User $employee;

    private Workplace $workplace;

    private Shift $shift;

    private ShiftUser $shiftUser;

    private CheckIn $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ApproveWorkHistoryEditRequestAction::class);

        $this->requester = User::factory()->create();
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

        $this->shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->requester->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);

        WorkplaceUser::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);

        $this->shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $this->checkIn = CheckIn::create([
            'shift_id' => $this->shift->id,
            'shift_user_id' => $this->shiftUser->id,
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

        $this->approver = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->approver,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
    }

    private function createPendingRequest(User $requester, array $items): WorkHistoryEditRequest
    {
        $request = WorkHistoryEditRequest::create([
            'check_in_id' => $this->checkIn->id,
            'workplace_id' => $this->workplace->id,
            'requested_by_id' => $requester->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);

        foreach ($items as $item) {
            WorkHistoryEditRequestItem::create([
                'work_history_edit_request_id' => $request->id,
                ...$item,
            ]);
        }

        return $request->fresh(['items']);
    }

    public function testAppliesItemsToCheckIn(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_in_time',
                'old_value' => '2026-05-10 09:00:00',
                'new_value' => '2026-05-10 09:05:00',
                'reason' => 'Máy chấm công lỗi',
            ],
            [
                'field' => 'check_out_time',
                'old_value' => '2026-05-10 18:00:00',
                'new_value' => '2026-05-10 18:10:00',
                'reason' => 'Về muộn do tăng ca',
            ],
        ]);

        $result = ($this->action)($this->approver, $request);

        $this->assertNull($result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:05:00', $this->checkIn->check_in_time);
        $this->assertSame('2026-05-10 18:10:00', $this->checkIn->check_out_time);
    }

    public function testRecalculatesWorkMinutesIdenticallyToDirectEditFlow(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => '2026-05-10 18:00:00',
                'new_value' => '2026-05-10 19:00:00',
                'reason' => 'Tăng ca thêm 1 tiếng',
            ],
        ]);

        ($this->action)($this->approver, $request);

        $this->checkIn->refresh();
        // Overtime is not allowed for this shift user, so the extra hour is clipped back to the
        // shift's own end time (18:00) by WorkHelper::calculateWorkHour - same behavior the old
        // direct-edit flow already had.
        $this->assertEquals(480, $this->checkIn->work_minutes);
    }

    public function testSetsCheckedInByAndCheckedOutByToRequesterNotApprover(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_in_time',
                'old_value' => '2026-05-10 09:00:00',
                'new_value' => '2026-05-10 09:05:00',
                'reason' => 'Máy chấm công lỗi',
            ],
        ]);

        ($this->action)($this->approver, $request);

        $this->checkIn->refresh();
        $this->assertSame($this->requester->id, $this->checkIn->checked_in_by);
        $this->assertNotSame($this->approver->id, $this->checkIn->checked_in_by);
    }

    public function testAllowsSelfApproval(): void
    {
        Queue::fake();
        $this->giveWorkplaceScopedPermission(
            $this->requester,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_in_time',
                'old_value' => '2026-05-10 09:00:00',
                'new_value' => '2026-05-10 09:05:00',
                'reason' => 'Tự duyệt',
            ],
        ]);

        $result = ($this->action)($this->requester, $request);

        $this->assertNull($result->getError());
        $this->assertSame(WorkHistoryEditRequestStatus::Approved->value, $result->getData()->status);
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

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_in_time',
                'old_value' => '2026-05-10 09:00:00',
                'new_value' => '2026-05-10 09:05:00',
                'reason' => 'Máy chấm công lỗi',
            ],
        ]);

        $result = ($this->action)($outsideApprover, $request);

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
    }

    public function testReturns409ForAlreadyResolvedRequest(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_in_time',
                'old_value' => '2026-05-10 09:00:00',
                'new_value' => '2026-05-10 09:05:00',
                'reason' => 'Máy chấm công lỗi',
            ],
        ]);

        // Reach the "already resolved" state via the real reject action, not a hand-set fixture.
        $rejectAction = app(RejectWorkHistoryEditRequestAction::class);
        $rejectAction($this->approver, $request, 'Không hợp lệ');

        $result = ($this->action)($this->approver, $request);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestNotPending, $result->getError());
    }

    public function testRollsBackEverythingWhenRecalculationFails(): void
    {
        Queue::fake();

        // A malformed new_value (not a parseable datetime) forces Carbon::parse() to throw while
        // building the applyValues() data array, after the request/check-in locks are already
        // held inside the transaction - simulating malformed data blowing up mid-approval.
        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => '2026-05-10 18:00:00',
                'new_value' => 'not-a-valid-datetime',
                'reason' => 'Tăng ca thêm 1 tiếng',
            ],
        ]);

        try {
            ($this->action)($this->approver, $request);
            $this->fail('Expected an exception from the recalculation step.');
        } catch (\Throwable $e) {
            // expected - Carbon::parse() throws an InvalidFormatException-family exception
        }

        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 18:00:00', $this->checkIn->check_out_time);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
    }

    public function testReturnsConflictWhenTheEditedFieldChangedSinceSubmission(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_in_time',
                'old_value' => '2026-05-10 09:00:00',
                'new_value' => '2026-05-10 09:05:00',
                'reason' => 'Máy chấm công lỗi',
            ],
        ]);

        // Simulate the check-in moving after the request was submitted but before it was
        // approved (e.g. a different, already-approved edit request touched it in the meantime).
        $this->checkIn->update(['check_in_time' => '2026-05-10 09:30:00']);

        $result = ($this->action)($this->approver, $request);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestDataChanged, $result->getError());
        $this->assertSame(409, $result->getStatus());
        $this->assertSame(
            [
                'conflicts' => [
                    [
                        'field' => 'check_in_time',
                        'old_value' => '2026-05-10 09:00:00',
                        'current_value' => '2026-05-10 09:30:00',
                        'new_value' => '2026-05-10 09:05:00',
                    ],
                ],
            ],
            $result->getData()
        );
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:30:00', $this->checkIn->check_in_time);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        Queue::assertNothingPushed();
    }

    public function testReturnsConflictWhenTheCheckOutTimeChangedSinceSubmission(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => '2026-05-10 18:00:00',
                'new_value' => '2026-05-10 18:10:00',
                'reason' => 'Về muộn do tăng ca',
            ],
        ]);

        // The employee checks out again via kiosk (a different time than what the request was
        // built against) before the approver acts on the request.
        $this->checkIn->update(['check_out_time' => '2026-05-10 19:00:00']);

        $result = ($this->action)($this->approver, $request);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestDataChanged, $result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 19:00:00', $this->checkIn->check_out_time);
    }

    public function testAppliesRequestWhenAFieldTheRequestNeverTouchedChangedSinceSubmission(): void
    {
        Queue::fake();

        // The employee hasn't checked out yet when the leader submits a check-in time fix -
        // reached through the real submit flow so the full-state snapshot is actually recorded.
        $this->checkIn->update(['check_out_time' => null]);
        $this->giveWorkplaceScopedPermission($this->requester, PermissionName::WorkHistoryEdit, $this->workplace->id);
        $submitResult = app(CreateWorkHistoryEditRequestAction::class)($this->requester, $this->checkIn->fresh(), [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);
        $request = $submitResult->getData();

        // The employee then checks out via kiosk before the approver acts on the request - a
        // field this particular request never touched, so no item exists for it.
        $this->checkIn->update(['check_out_time' => '2026-05-10 18:00:00']);

        $result = ($this->action)($this->approver, $request);

        $this->assertNull($result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:05:00', $this->checkIn->check_in_time);
        $this->assertSame('2026-05-10 18:00:00', $this->checkIn->check_out_time);
        $this->assertSame('2026-05-10 09:15:00', $this->checkIn->check_in_time_rounded);
        $this->assertSame('2026-05-10 18:00:00', $this->checkIn->check_out_time_rounded);
        $this->assertSame(465, (int) $this->checkIn->work_minutes);
        Queue::assertPushed(CreateShiftReport::class);
        Queue::assertPushed(UpdateUserSalary::class);
        Queue::assertPushed(CollectWorkTime::class);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Approved->value,
        ]);
    }

    public function testRejectsCheckInFixThatWouldLandAfterALaterCheckOut(): void
    {
        Queue::fake();

        $this->checkIn->update(['check_out_time' => null]);
        $this->giveWorkplaceScopedPermission($this->requester, PermissionName::WorkHistoryEdit, $this->workplace->id);
        $submitResult = app(CreateWorkHistoryEditRequestAction::class)($this->requester, $this->checkIn->fresh(), [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '17:00:00',
            'check_in_reason' => 'Vào ca trễ',
        ]);
        $this->assertNull($submitResult->getError());
        $request = $submitResult->getData();

        $this->checkIn->update(['check_out_time' => '2026-05-10 16:00:00']);

        $result = ($this->action)($this->approver, $request);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestInvalidTimeOrder, $result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $this->checkIn->check_in_time);
        $this->assertSame('2026-05-10 16:00:00', $this->checkIn->check_out_time);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        Queue::assertNothingPushed();
    }

    public function testAllowsApprovalWhenNoFieldDriftedSinceSubmission(): void
    {
        Queue::fake();

        $this->giveWorkplaceScopedPermission($this->requester, PermissionName::WorkHistoryEdit, $this->workplace->id);
        $submitResult = app(CreateWorkHistoryEditRequestAction::class)($this->requester, $this->checkIn->fresh(), [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_out_date' => '2026-05-10',
            'check_out_time' => '18:00:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);
        $request = $submitResult->getData();

        $result = ($this->action)($this->approver, $request);

        $this->assertNull($result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:05:00', $this->checkIn->check_in_time);
    }

    public function testAllowsDeletingAnExistingCheckOutAndZeroesWorkMinutes(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => '2026-05-10 18:00:00',
                'new_value' => null,
                'reason' => 'Chấm nhầm giờ ra',
            ],
        ]);

        $result = ($this->action)($this->approver, $request);

        $this->assertNull($result->getError());
        $this->checkIn->refresh();
        $this->assertNull($this->checkIn->check_out_time);
        $this->assertNull($this->checkIn->check_out_time_rounded);
        $this->assertNull($this->checkIn->checked_out_by);
        $this->assertEquals(0, $this->checkIn->work_minutes);
        Queue::assertPushed(CreateShiftReport::class);
    }

    public function testDispatchesReportJobsForABreakMinutesOnlyChange(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'break_minutes',
                'old_value' => '60',
                'new_value' => '30',
                'reason' => 'Nghỉ giữa ca ngắn hơn',
            ],
        ]);

        ($this->action)($this->approver, $request);

        $this->checkIn->refresh();
        // A shorter break with the same check-in/check-out span recalculates to more work
        // minutes than the original 480 - and, unlike before this fix, now also re-dispatches
        // the report/salary/work-time jobs even though neither timestamp itself changed.
        $this->assertGreaterThan(480, $this->checkIn->work_minutes);
        Queue::assertPushed(CreateShiftReport::class);
        Queue::assertPushed(UpdateUserSalary::class);
        Queue::assertPushed(CollectWorkTime::class);
    }

    public function testReturnsInvalidTimeOrderWhenTheAssembledPairWouldHaveCheckOutBeforeCheckIn(): void
    {
        Queue::fake();

        // Fixture-created request (no snapshot recorded), matching how a request predating the
        // snapshot column would look. The item alone still matches live data (check-in hasn't
        // moved from '09:00' since this exact item was recorded), so neither drift guard fires;
        // only the final applied-pair sanity check catches the new check-in (20:00) landing
        // after the untouched, still-live check-out (18:00).
        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_in_time',
                'old_value' => '2026-05-10 09:00:00',
                'new_value' => '2026-05-10 20:00:00',
                'reason' => 'Vào ca trễ',
            ],
        ]);

        $result = ($this->action)($this->approver, $request);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestInvalidTimeOrder, $result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $this->checkIn->check_in_time);
        Queue::assertNothingPushed();
    }

    public function testForceAppliesTheRequestedValueOverAConflictingLiveValue(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => '2026-05-10 18:00:00',
                'new_value' => '2026-05-10 18:10:00',
                'reason' => 'Về muộn do tăng ca',
            ],
        ]);
        $this->checkIn->update(['check_out_time' => '2026-05-10 19:00:00']);

        $result = ($this->action)($this->approver, $request, true);

        $this->assertNull($result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 18:10:00', $this->checkIn->check_out_time);
        $this->assertSame(WorkHistoryEditRequestStatus::Approved->value, $request->fresh()->status);
    }

    public function testForceStillRefusesAnInvertedCheckInCheckOutPair(): void
    {
        Queue::fake();

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_in_time',
                'old_value' => '2026-05-10 08:00:00',
                'new_value' => '2026-05-10 20:00:00',
                'reason' => 'Vào ca trễ',
            ],
        ]);

        $result = ($this->action)($this->approver, $request, true);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestInvalidTimeOrder, $result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $this->checkIn->check_in_time);
        $this->assertSame(WorkHistoryEditRequestStatus::Pending->value, $request->fresh()->status);
    }

    public function testDispatchesReportJobsWhenApprovingAFirstTimeCheckOut(): void
    {
        Queue::fake();

        // The employee forgot to check out; the check-in has no check-out at all yet.
        $this->checkIn->update(['check_out_time' => null]);

        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => null,
                'new_value' => '2026-05-10 18:00:00',
                'reason' => 'Quên chấm công ra',
            ],
        ]);

        $result = ($this->action)($this->approver, $request);

        $this->assertNull($result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 18:00:00', $this->checkIn->check_out_time);
        Queue::assertPushed(CreateShiftReport::class);
        Queue::assertPushed(UpdateUserSalary::class);
        Queue::assertPushed(CollectWorkTime::class);
    }

    public function testAppliesFirstTimeCheckOutFixAfterTheSystemAutoCheckedOut(): void
    {
        Queue::fake();

        $this->checkIn->update(['check_out_time' => null, 'check_out_time_rounded' => null]);
        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => null,
                'new_value' => '2026-05-10 17:30:00',
                'reason' => 'Quên chấm công ra',
            ],
        ]);
        $this->checkIn->update(['check_out_time' => '2026-05-10 18:00:00', 'checked_out_by' => 0]);

        $result = ($this->action)($this->approver, $request);

        $this->assertNull($result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 17:30:00', $this->checkIn->check_out_time);
        $this->assertSame($this->requester->id, (int) $this->checkIn->checked_out_by);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Approved->value,
        ]);
        Queue::assertPushed(CreateShiftReport::class);
    }

    public function testReturnsConflictForFirstTimeCheckOutFixAfterTheEmployeeCheckedOutThemselves(): void
    {
        Queue::fake();

        $this->checkIn->update(['check_out_time' => null, 'checked_out_by' => null]);
        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => null,
                'new_value' => '2026-05-10 17:30:00',
                'reason' => 'Quên chấm công ra',
            ],
        ]);
        $this->checkIn->update(['check_out_time' => '2026-05-10 18:02:00']);

        $result = ($this->action)($this->approver, $request);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestDataChanged, $result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 18:02:00', $this->checkIn->check_out_time);
        Queue::assertNothingPushed();
    }

    public function testReturnsConflictForFirstTimeCheckOutFixAfterAnAdminCheckedOutTheEmployee(): void
    {
        Queue::fake();

        $this->checkIn->update(['check_out_time' => null, 'checked_out_by' => null]);
        $request = $this->createPendingRequest($this->requester, [
            [
                'field' => 'check_out_time',
                'old_value' => null,
                'new_value' => '2026-05-10 17:30:00',
                'reason' => 'Quên chấm công ra',
            ],
        ]);
        $this->checkIn->update(['check_out_time' => '2026-05-10 18:05:00', 'checked_out_by' => $this->approver->id]);

        $result = ($this->action)($this->approver, $request);

        $this->assertSame(ErrorCode::WorkHistoryEditRequestDataChanged, $result->getError());
        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 18:05:00', $this->checkIn->check_out_time);
        Queue::assertNothingPushed();
    }
}
