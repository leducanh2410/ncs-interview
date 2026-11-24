<?php

namespace Tests\Feature\Manage\WorkHistoryEditRequest;

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

class BulkResolveTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    private function createRequest(Workplace $workplace): WorkHistoryEditRequest
    {
        $employee = User::factory()->create();
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
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
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
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
        $checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $employee->id,
            'check_in_time' => '2026-05-10 09:00:00',
            'check_out_time' => '2026-05-10 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        $request = WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $workplace->id,
            'requested_by_id' => $employee->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        WorkHistoryEditRequestItem::create([
            'work_history_edit_request_id' => $request->id,
            'field' => 'check_in_time',
            'old_value' => '2026-05-10 09:00:00',
            'new_value' => '2026-05-10 09:05:00',
            'reason' => 'Máy chấm công lỗi',
        ]);

        return $request;
    }

    public function testApprovesMultipleRequests(): void
    {
        Queue::fake();
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);

        $response = $this->actingAs($manager, 'api')->postJson('/manage/work-history-edit-requests/bulk-resolve', [
            'ids' => [$requestA->id, $requestB->id],
            'action' => 'approve',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $requestA->id,
            'status' => WorkHistoryEditRequestStatus::Approved->value,
        ]);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $requestB->id,
            'status' => WorkHistoryEditRequestStatus::Approved->value,
        ]);
    }

    public function testRejectsMultipleRequestsWithSharedReason(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);

        $response = $this->actingAs($manager, 'api')->postJson('/manage/work-history-edit-requests/bulk-resolve', [
            'ids' => [$requestA->id, $requestB->id],
            'action' => 'reject',
            'reject_reason' => 'Thiếu bằng chứng đối chiếu',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $requestA->id,
            'status' => WorkHistoryEditRequestStatus::Rejected->value,
            'reject_reason' => 'Thiếu bằng chứng đối chiếu',
        ]);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $requestB->id,
            'status' => WorkHistoryEditRequestStatus::Rejected->value,
            'reject_reason' => 'Thiếu bằng chứng đối chiếu',
        ]);
    }

    public function testReturns403WhenAnyRequestOutsideScope(): void
    {
        Queue::fake();
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $requestA = $this->createRequest($this->workplace);
        $requestOutOfScope = $this->createRequest($this->otherWorkplace);

        $response = $this->actingAs($manager, 'api')->postJson('/manage/work-history-edit-requests/bulk-resolve', [
            'ids' => [$requestA->id, $requestOutOfScope->id],
            'action' => 'approve',
        ]);

        $response->assertForbidden();
        $this->assertNull($response->json('data.employee_name'));
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $requestA->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
    }

    public function testIdentifiesTheConflictingRequestAndAppliesNothingWhenOneRequestDrifted(): void
    {
        Queue::fake();
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);
        $requestB->checkIn->update(['check_in_time' => '2026-05-10 09:30:00']);

        $response = $this->actingAs($manager, 'api')->postJson('/manage/work-history-edit-requests/bulk-resolve', [
            'ids' => [$requestA->id, $requestB->id],
            'action' => 'approve',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('error', 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED')
            ->assertJsonPath('data.request_id', $requestB->id)
            ->assertJsonPath('data.employee_name', $requestB->checkIn->user->name)
            ->assertJsonPath('data.date', '2026/5/10')
            ->assertJsonPath('data.conflicts.0.field', 'check_in_time');
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $requestA->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('check_ins', [
            'id' => $requestA->check_in_id,
            'check_in_time' => '2026-05-10 09:00:00',
        ]);
    }

    public function testIdentifiesTheAlreadyDecidedRequestWhenBulkRejecting(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);
        $this->actingAs($manager, 'api')
            ->postJson("/manage/work-history-edit-requests/{$requestB->id}/reject", ['reject_reason' => 'Sai'])
            ->assertOk();

        $response = $this->actingAs($manager, 'api')->postJson('/manage/work-history-edit-requests/bulk-resolve', [
            'ids' => [$requestA->id, $requestB->id],
            'action' => 'reject',
            'reject_reason' => 'Thiếu bằng chứng',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('error', 'WORK_HISTORY_EDIT_REQUEST_NOT_PENDING')
            ->assertJsonPath('data.request_id', $requestB->id)
            ->assertJsonPath('data.employee_name', $requestB->checkIn->user->name);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $requestA->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
    }
}
