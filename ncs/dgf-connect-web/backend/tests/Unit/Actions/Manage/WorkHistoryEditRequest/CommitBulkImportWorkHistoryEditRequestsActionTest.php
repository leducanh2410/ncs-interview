<?php

namespace Tests\Unit\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\Manage\WorkHistoryEditRequest\CommitBulkImportWorkHistoryEditRequestsAction;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkHistoryEditRequest;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class CommitBulkImportWorkHistoryEditRequestsActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private CommitBulkImportWorkHistoryEditRequestsAction $action;

    private User $manager;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(CommitBulkImportWorkHistoryEditRequestsAction::class);
        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
    }

    public function testAllApplyBatchUpdatesEveryCheckInAndApprovesEveryRequest(): void
    {
        $checkInA = $this->seedCheckIn();
        $checkInB = $this->seedCheckIn();

        $rows = [
            $this->applyRow($checkInA, 'check_in_time', $checkInA->check_in_time, '2026-05-10 08:45:00'),
            $this->applyRow($checkInB, 'break_minutes', null, '30'),
        ];

        $result = ($this->action)($this->manager, $rows);

        $this->assertNull($result->getError());
        $this->assertSame(['applied_count' => 2, 'rejected_count' => 0], $result->getData());
        $this->assertSame('2026-05-10 08:45:00', $checkInA->fresh()->check_in_time);
        $this->assertSame(30, $checkInB->fresh()->break_minutes);
        $this->assertSame(
            2,
            WorkHistoryEditRequest::query()->where('status', WorkHistoryEditRequestStatus::Approved->value)->count()
        );
    }

    public function testMixedApplyAndRejectBatch(): void
    {
        $applied = $this->seedCheckIn();
        $rejected = $this->seedCheckIn();

        $rows = [
            $this->applyRow($applied, 'check_in_time', $applied->check_in_time, '2026-05-10 08:45:00'),
            $this->rejectRow($rejected, 'break_minutes', null, '30', 'Không hợp lệ, cần kiểm tra lại'),
        ];

        $result = ($this->action)($this->manager, $rows);

        $this->assertNull($result->getError());
        $this->assertSame(['applied_count' => 1, 'rejected_count' => 1], $result->getData());
        $this->assertSame('2026-05-10 08:45:00', $applied->fresh()->check_in_time);
        // Rejected row leaves the Check_In untouched.
        $this->assertSame('2026-05-10 09:00:00', $rejected->fresh()->check_in_time);
        $this->assertNull($rejected->fresh()->break_minutes);

        $rejectedRequest = WorkHistoryEditRequest::query()->where('check_in_id', $rejected->id)->first();
        $this->assertSame(WorkHistoryEditRequestStatus::Rejected->value, $rejectedRequest->status);
        $this->assertSame('Không hợp lệ, cần kiểm tra lại', $rejectedRequest->reject_reason);
    }

    public function testRowDriftedSincePreviewAbortsTheWholeBatchWithNoPartialWrites(): void
    {
        $untouched = $this->seedCheckIn();
        $drifted = $this->seedCheckIn();
        // Simulate the check-in changing after preview was computed but before commit.
        $drifted->update(['check_in_time' => '2026-05-10 10:00:00']);

        $rows = [
            $this->applyRow($untouched, 'check_in_time', $untouched->check_in_time, '2026-05-10 08:45:00'),
            // old_value below is stale (the value at preview time), no longer matching live data.
            $this->applyRow($drifted, 'check_in_time', '2026-05-10 09:00:00', '2026-05-10 08:45:00'),
        ];

        $result = ($this->action)($this->manager, $rows);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED', $result->getError()->value);
        $this->assertSame('2026-05-10 09:00:00', $untouched->fresh()->check_in_time);
        $this->assertSame(0, WorkHistoryEditRequest::query()->count());
        // The aborting row's identity must be surfaced - the admin has no other way to tell which
        // of a large batch failed, and re-uploading blindly would loop forever (Requirement 10.4).
        $this->assertSame($drifted->id, $result->getData()['check_in_id']);
        $this->assertSame($drifted->user->name, $result->getData()['employee_name']);
        $this->assertSame('2026/5/10', $result->getData()['date']);
    }

    public function testRowThatGainedAPendingRequestSincePreviewAbortsTheWholeBatch(): void
    {
        $untouched = $this->seedCheckIn();
        $checkIn = $this->seedCheckIn();
        WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $this->workplace->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);

        $rows = [
            $this->applyRow($untouched, 'check_in_time', $untouched->check_in_time, '2026-05-10 08:45:00'),
            $this->applyRow($checkIn, 'check_in_time', $checkIn->check_in_time, '2026-05-10 08:45:00'),
        ];

        $result = ($this->action)($this->manager, $rows);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_ALREADY_PENDING', $result->getError()->value);
        $this->assertSame('2026-05-10 09:00:00', $untouched->fresh()->check_in_time);
        // Only the pre-existing pending request remains - the batch created no new rows.
        $this->assertSame(1, WorkHistoryEditRequest::query()->count());
        $this->assertSame($checkIn->id, $result->getData()['check_in_id']);
        $this->assertSame($checkIn->user->name, $result->getData()['employee_name']);
    }

    public function testProcessesRowsInDeterministicCheckInIdOrderRegardlessOfInputOrder(): void
    {
        $first = $this->seedCheckIn();
        $second = $this->seedCheckIn();
        $this->assertLessThan($second->id, $first->id);

        // Submit in reverse id order - the action must still resolve deterministically.
        $rows = [
            $this->applyRow($second, 'check_in_time', $second->check_in_time, '2026-05-10 08:00:00'),
            $this->applyRow($first, 'check_in_time', $first->check_in_time, '2026-05-10 08:45:00'),
        ];

        $result = ($this->action)($this->manager, $rows);

        $this->assertNull($result->getError());
        $this->assertSame('2026-05-10 08:45:00', $first->fresh()->check_in_time);
        $this->assertSame('2026-05-10 08:00:00', $second->fresh()->check_in_time);
    }

    private function applyRow(CheckIn $checkIn, string $field, ?string $oldValue, ?string $newValue): array
    {
        return [
            'check_in_id' => $checkIn->id,
            'items' => [['field' => $field, 'old_value' => $oldValue, 'new_value' => $newValue]],
            'decision' => 'apply',
        ];
    }

    private function rejectRow(
        CheckIn $checkIn,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        string $reason
    ): array {
        return [
            'check_in_id' => $checkIn->id,
            'items' => [['field' => $field, 'old_value' => $oldValue, 'new_value' => $newValue]],
            'decision' => 'reject',
            'reject_reason' => $reason,
        ];
    }

    private function seedCheckIn(): CheckIn
    {
        $employee = User::factory()->create();
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
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
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
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

        return CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $employee->id,
            'check_in_time' => '2026-05-10 09:00:00',
            'check_out_time' => '2026-05-10 18:00:00',
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }
}
