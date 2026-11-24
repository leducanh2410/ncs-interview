<?php

namespace Tests\Feature\Manage;

use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Jobs\CollectWorkTime;
use App\Jobs\UpdateUserSalary;
use App\Jobs\WorkHistoryExportJob;
use App\Models\CheckIn;
use App\Models\ExportBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Models\WorkHistoryEditRequest;
use App\Models\WorkTimeReport;
use App\Models\WorkTimeReportDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class WorkHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplacePosition $position;

    private Shift $shift;

    private ShiftUser $shiftUser;

    private User $employee;

    private CheckIn $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 20:00:00');

        $this->manager = User::factory()->create();

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        foreach (
            [
                PermissionName::WorkHistoryList,
                PermissionName::WorkHistoryEdit,
                PermissionName::WorkHistoryAdd,
                PermissionName::WorkHistoryExport,
                PermissionName::WorkHistoryEditRequestProcess,
            ]
            as $permName
        ) {
            $permission = Permission::firstOrCreate(['name' => $permName->value], ['display_name' => $permName->value]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->manager->addRole($role);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
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

        $this->position = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);

        $this->shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);

        $this->employee = User::factory()->create();
        WorkplaceUser::create([
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
        ]);

        $this->shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $this->position->id,
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

    // -------------------------------------------------------------------------
    // getWorkHistories
    // -------------------------------------------------------------------------

    public function testGetWorkHistoriesSuccess(): void
    {
        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/work-histories');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function testGetWorkHistoriesIncludesLatestEditRequestProcessedByName(): void
    {
        WorkHistoryEditRequest::create([
            'check_in_id' => $this->checkIn->id,
            'workplace_id' => $this->workplace->id,
            'requested_by_id' => $this->manager->id,
            'status' => 'rejected',
            'reject_reason' => 'Không khớp camera',
            'processed_by_id' => $this->manager->id,
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/work-histories');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.latest_edit_request.status', 'rejected');
        $response->assertJsonPath('data.data.0.latest_edit_request.processed_by.id', $this->manager->id);
    }

    public function testGetWorkHistoriesFilteredByDateRange(): void
    {
        $response = $this->actingAs($this->manager, 'api')->getJson(
            '/manage/work-histories?dates[]=2026-05-10&dates[]=2026-05-10'
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function testGetWorkHistoriesShowShortenedNameForSriLankaUser(): void
    {
        $this->employee->update([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $this->employee->info->update(['nationality' => Nationality::SriLanka->value]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/work-histories');

        $response->assertStatus(200)->assertJsonPath('data.data.0.user.name', 'THARINDU RAVISHANN');
    }

    public function testGetWorkHistoriesDoesNotErrorForCheckInWithOrphanedUser(): void
    {
        // No API flow can leave a check_in row without its user (user_id cascade-deletes),
        // so this state is simulated directly at the DB level to cover the null-safe accessor.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('check_ins')
                ->where('id', $this->checkIn->id)
                ->update(['user_id' => 999999]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/work-histories');

        $response->assertStatus(200)->assertJsonPath('data.data.0.user', null);
    }

    public function testGetWorkHistoriesForbiddenWithoutPermission(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->getJson('/manage/work-histories');

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // updateCheckIn
    // -------------------------------------------------------------------------

    public function testUpdateCheckInSuccess(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_out_date' => '2026-05-10',
            'check_out_time' => '18:00:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'check_in_id' => $this->checkIn->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('check_ins', [
            'id' => $this->checkIn->id,
            'check_in_time' => '2026-05-10 09:00:00',
        ]);
    }

    public function testUpdateCheckInAppliesWorkplaceOvertimeThreshold(): void
    {
        Queue::fake();
        $this->workplace->update(['overtime_after_hours' => 9]);

        $submitResponse = $this->actingAs($this->manager, 'api')->patchJson(
            "/manage/work-histories/{$this->checkIn->id}",
            [
                'check_in_date' => '2026-05-10',
                'check_in_time' => '08:30:00',
                'check_out_date' => '2026-05-10',
                'check_out_time' => '18:00:00',
                'check_in_reason' => 'Máy chấm công lỗi',
            ]
        );
        $submitResponse->assertStatus(201);
        $requestId = $submitResponse->json('data.id');

        $approveResponse = $this->actingAs($this->manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$requestId}/approve"
        );
        $approveResponse->assertStatus(200);

        // Arriving early is clipped back to 09:00, and the 9 worked hours stay under the
        // workplace threshold instead of producing an hour of overtime.
        $this->assertDatabaseHas('check_ins', [
            'id' => $this->checkIn->id,
            'work_minutes' => 540,
            'work_minutes_normal' => 540,
            'work_minutes_overtime' => 0,
        ]);
        Queue::assertPushed(CollectWorkTime::class);
        Queue::assertPushed(UpdateUserSalary::class);
    }

    public function testUpdateCheckInFallsBackToDefaultOvertimeThreshold(): void
    {
        Queue::fake();

        $submitResponse = $this->actingAs($this->manager, 'api')->patchJson(
            "/manage/work-histories/{$this->checkIn->id}",
            [
                'check_in_date' => '2026-05-10',
                'check_in_time' => '08:30:00',
                'check_out_date' => '2026-05-10',
                'check_out_time' => '18:00:00',
                'check_in_reason' => 'Máy chấm công lỗi',
            ]
        );
        $submitResponse->assertStatus(201);
        $requestId = $submitResponse->json('data.id');

        $approveResponse = $this->actingAs($this->manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$requestId}/approve"
        );
        $approveResponse->assertStatus(200);

        $this->assertDatabaseHas('check_ins', [
            'id' => $this->checkIn->id,
            'work_minutes' => 540,
            'work_minutes_normal' => 480,
            'work_minutes_overtime' => 60,
        ]);
    }

    public function testUpdateCheckInOnlyCheckInTime(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:10:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('work_history_edit_request_items', [
            'field' => 'check_in_time',
            'old_value' => '2026-05-10 09:00:00',
            'new_value' => '2026-05-10 09:10:00',
        ]);
        $this->assertDatabaseHas('check_ins', [
            'id' => $this->checkIn->id,
            'check_in_time' => '2026-05-10 09:00:00',
        ]);
    }

    public function testUpdateCheckInValidationFails(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            // check_in_time is missing
        ]);

        $response->assertStatus(422);
    }

    public function testUpdateCheckInForbiddenWithoutPermission(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:00:00',
        ]);

        $response->assertStatus(403);
    }

    public function testUpdateCheckInForbiddenForManagerScopedToADifferentWorkplace(): void
    {
        // The `work_history.edit` middleware only proves this permission at *some* workplace;
        // it does not know which workplace the route's {checkIn} belongs to. A manager who only
        // has the permission on a different workplace must still be rejected by the action.
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $scopedManager = User::factory()->create();
        $role = Role::create(['name' => 'scoped_work_history_edit', 'display_name' => 'Scoped']);
        $permission = Permission::firstOrCreate(
            ['name' => PermissionName::WorkHistoryEdit->value],
            ['display_name' => PermissionName::WorkHistoryEdit->value]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $scopedManager->addRole($role, $otherWorkplace->id);

        $response = $this->actingAs($scopedManager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('work_history_edit_requests', 0);
    }

    public function testUpdateCheckInReturns422WhenChangedFieldMissingReason(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('work_history_edit_requests', 0);
    }

    public function testUpdateCheckInRejectsAWhitespaceOnlyReason(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => '   ',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('work_history_edit_requests', 0);
    }

    public function testUpdateCheckInDeletingCheckOutRequiresAReason(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:00:00',
            'check_out_date' => null,
            'check_out_time' => '',
        ]);

        $response->assertStatus(422);
    }

    public function testUpdateCheckInAllowsDeletingAnExistingCheckOut(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:00:00',
            'check_out_date' => null,
            'check_out_time' => '',
            'check_out_reason' => 'Chấm nhầm giờ ra',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('work_history_edit_request_items', [
            'field' => 'check_out_time',
            'old_value' => '2026-05-10 18:00:00',
            'new_value' => null,
        ]);
    }

    public function testUpdateCheckInReturns409WhenPendingRequestAlreadyExists(): void
    {
        WorkHistoryEditRequest::create([
            'check_in_id' => $this->checkIn->id,
            'workplace_id' => $this->workplace->id,
            'requested_by_id' => $this->manager->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('work_history_edit_requests', 1);
    }

    public function testUpdateCheckInLeavesCheckInUnchangedAfterSubmission(): void
    {
        Queue::fake();

        $this->actingAs($this->manager, 'api')->patchJson("/manage/work-histories/{$this->checkIn->id}", [
            'check_in_date' => '2026-05-10',
            'check_in_time' => '09:05:00',
            'check_in_reason' => 'Máy chấm công lỗi',
        ]);

        $this->checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $this->checkIn->check_in_time);
    }

    // -------------------------------------------------------------------------
    // addUsersToShift
    // -------------------------------------------------------------------------

    public function testAddUsersToShiftSuccess(): void
    {
        Queue::fake();

        $newEmployee = User::factory()->create();
        WorkplaceUser::create([
            'user_id' => $newEmployee->id,
            'workplace_id' => $this->workplace->id,
        ]);

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [$newEmployee->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('check_ins', [
            'shift_id' => $this->shift->id,
            'user_id' => $newEmployee->id,
        ]);
        $this->assertNotEmpty($response->json('data.added'));
    }

    public function testAddUsersToShiftAppliesWorkplaceOvertimeThreshold(): void
    {
        Queue::fake();
        $this->workplace->update(['overtime_after_hours' => 9]);

        $newEmployee = User::factory()->create();
        WorkplaceUser::create([
            'user_id' => $newEmployee->id,
            'workplace_id' => $this->workplace->id,
        ]);

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [$newEmployee->id],
        ]);

        $response->assertStatus(200);
        // A full 9 hour shift stays under the workplace threshold, where the system default
        // of 8 hours would have produced an hour of overtime.
        $this->assertDatabaseHas('check_ins', [
            'shift_id' => $this->shift->id,
            'user_id' => $newEmployee->id,
            'work_minutes' => 540,
            'work_minutes_normal' => 540,
            'work_minutes_overtime' => 0,
        ]);
    }

    public function testAddUsersToShiftForbiddenWithoutPermission(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [$this->employee->id],
        ]);

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // getMonthlyWorkHistories
    // -------------------------------------------------------------------------

    public function testGetMonthlyWorkHistoriesSuccess(): void
    {
        $report = WorkTimeReport::create([
            'month' => '2026-05',
            'user_id' => $this->employee->id,
            'work_days' => 20,
            'work_minutes' => 9600,
            'work_minutes_normal' => 9600,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        WorkTimeReportDetail::create([
            'work_time_report_id' => $report->id,
            'workplace_id' => $this->workplace->id,
            'work_days' => 20,
            'work_minutes' => 9600,
            'work_minutes_normal' => 9600,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/work-histories/monthly?month=2026-05');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function testGetMonthlyWorkHistoriesDefaultsToCurrentMonth(): void
    {
        $report = WorkTimeReport::create([
            'month' => '2026-05',
            'user_id' => $this->employee->id,
            'work_days' => 20,
            'work_minutes' => 9600,
            'work_minutes_normal' => 9600,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        WorkTimeReportDetail::create([
            'work_time_report_id' => $report->id,
            'workplace_id' => $this->workplace->id,
            'work_days' => 20,
            'work_minutes' => 9600,
            'work_minutes_normal' => 9600,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        // No month param — defaults to current month (2026-05)
        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/work-histories/monthly');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function testGetMonthlyWorkHistoriesShowShortenedNameForSriLankaUser(): void
    {
        $this->employee->update([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $this->employee->info->update(['nationality' => Nationality::SriLanka->value]);

        $report = WorkTimeReport::create([
            'month' => '2026-05',
            'user_id' => $this->employee->id,
            'work_days' => 20,
            'work_minutes' => 9600,
            'work_minutes_normal' => 9600,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        WorkTimeReportDetail::create([
            'work_time_report_id' => $report->id,
            'workplace_id' => $this->workplace->id,
            'work_days' => 20,
            'work_minutes' => 9600,
            'work_minutes_normal' => 9600,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/work-histories/monthly?month=2026-05');

        $response->assertStatus(200)->assertJsonPath('data.data.0.user.name', 'THARINDU RAVISHANN');
    }

    public function testGetMonthlyWorkHistoriesForbiddenWithoutPermission(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->getJson('/manage/work-histories/monthly?month=2026-05');

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // exportDetailReport
    // -------------------------------------------------------------------------

    public function testExportDetailReportCreatesBatchAndDispatchesJob(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/export', [
            'dates' => ['2026-05-01', '2026-05-31'],
        ]);

        $response->assertStatus(202);

        $batch = ExportBatch::query()->find($response->json('data.id'));
        $this->assertNotNull($batch);
        $this->assertEquals(ExportBatchType::WorkHistory, $batch->type);
        $this->assertEquals(ExportBatchStatus::Pending, $batch->status);
        $this->assertEquals($this->manager->id, $batch->exported_by);
        $this->assertEquals(1, $batch->csv_total_count);
        $this->assertEquals(['2026-05-01', '2026-05-31'], $batch->meta['conditions']['dates']);

        Queue::assertPushed(WorkHistoryExportJob::class);
    }

    public function testExportDetailReportReturnsBadRequestWhenNoData(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/export', [
            'dates' => ['2020-01-01', '2020-01-31'],
        ]);

        $response->assertStatus(400);
        $this->assertEquals(0, ExportBatch::query()->count());
        Queue::assertNotPushed(WorkHistoryExportJob::class);
    }

    public function testExportDetailReportForbiddenWithoutPermission(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->postJson('/manage/work-histories/export');

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // exportMonthlyReport
    // -------------------------------------------------------------------------

    public function testExportMonthlyReportSuccess(): void
    {
        Excel::fake();

        $report = WorkTimeReport::create([
            'month' => '2026-05',
            'user_id' => $this->employee->id,
            'work_days' => 20,
            'work_minutes' => 9600,
            'work_minutes_normal' => 9600,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        WorkTimeReportDetail::create([
            'work_time_report_id' => $report->id,
            'workplace_id' => $this->workplace->id,
            'work_days' => 20,
            'work_minutes' => 9600,
            'work_minutes_normal' => 9600,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson(
            '/manage/work-histories/monthly/export?month=2026-05'
        );

        $response->assertStatus(200);
    }

    public function testExportMonthlyReportForbiddenWithoutPermission(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->getJson('/manage/work-histories/monthly/export?month=2026-05');

        $response->assertStatus(403);
    }
}
