<?php

namespace Tests\Feature\Manage\WorkHistoryEditRequest;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private User $employee;

    private CheckIn $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 20:00:00');

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);
        $this->employee = User::factory()->create();
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->employee->id,
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
            'break_minutes' => 60,
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dualPermissionManager(): User
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::WorkHistoryEdit, $this->workplace->id);
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $this->giveWorkplaceScopedPermission($manager, PermissionName::WorkHistoryList, $this->workplace->id);

        return $manager;
    }

    public function testSubmitThenApproveAppliesValuesAndPayrollJobsFire(): void
    {
        Queue::fake();
        $manager = $this->dualPermissionManager();

        $submitResponse = $this->actingAs($manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);
        $submitResponse->assertStatus(201);
        $requestId = $submitResponse->json('data.id');

        $approveResponse = $this->actingAs($manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$requestId}/approve"
        );

        $approveResponse->assertOk();
        $this->assertDatabaseHas('check_ins', [
            'id' => $this->checkIn->id,
            'check_in_time' => '2026-05-10 09:05:00',
        ]);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $requestId,
            'status' => WorkHistoryEditRequestStatus::Approved->value,
        ]);
        Queue::assertPushed(CreateShiftReport::class);
        Queue::assertPushed(UpdateUserSalary::class);
        Queue::assertPushed(CollectWorkTime::class);

        // Requirement 8.3: Work_History_List surfaces the resolved request without an extra query.
        $listResponse = $this->actingAs($manager, 'api')->getJson('/manage/work-histories');
        $listResponse->assertOk();
        $listResponse->assertJsonPath('data.data.0.latest_edit_request.status', 'approved');
    }

    public function testSubmitBlockedWhileAPendingRequestExists(): void
    {
        Queue::fake();
        $manager = $this->dualPermissionManager();

        $this->actingAs($manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Lần 1',
        ]);

        $secondResponse = $this->actingAs($manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:07:00',
            'check_in_reason' => 'Lần 2',
        ]);

        $secondResponse->assertStatus(409);
        $this->assertDatabaseCount('work_history_edit_requests', 1);
    }

    public function testSubmitAllowedAfterPriorRequestRejected(): void
    {
        Queue::fake();
        $manager = $this->dualPermissionManager();

        $firstSubmit = $this->actingAs($manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Lần 1',
        ]);
        $firstRequestId = $firstSubmit->json('data.id');

        $rejectResponse = $this->actingAs($manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$firstRequestId}/reject",
            ['reject_reason' => 'Không hợp lệ']
        );
        $rejectResponse->assertOk();

        $secondSubmit = $this->actingAs($manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:07:00',
            'check_in_reason' => 'Lần 2',
        ]);

        $secondSubmit->assertStatus(201);
        $this->assertDatabaseCount('work_history_edit_requests', 2);

        $approveResponse = $this->actingAs($manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$secondSubmit->json('data.id')}/approve"
        );
        $approveResponse->assertOk();
        $this->assertDatabaseHas('check_ins', [
            'id' => $this->checkIn->id,
            'check_in_time' => '2026-05-10 09:07:00',
        ]);
    }

    public function testEmployeeWithHistoryOfOneRejectedAndOneNewPendingRequestShowsBothWhenStatusAllFiltered(): void
    {
        Queue::fake();
        $manager = $this->dualPermissionManager();

        $firstSubmit = $this->actingAs($manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Lần 1',
        ]);
        $this->actingAs($manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$firstSubmit->json('data.id')}/reject",
            ['reject_reason' => 'Không hợp lệ']
        );
        $this->actingAs($manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:07:00',
            'check_in_reason' => 'Lần 2',
        ]);

        $pendingOnly = $this->actingAs($manager, 'api')->getJson(
            '/manage/work-history-edit-requests?employee_name=' . $this->employee->first_name
        );
        $pendingOnly->assertOk();
        $pendingOnly->assertJsonPath('data.total', 1);

        $all = $this->actingAs($manager, 'api')->getJson(
            '/manage/work-history-edit-requests?status=all&employee_name=' . $this->employee->first_name
        );
        $all->assertOk();
        $all->assertJsonPath('data.total', 2);
    }
}
