<?php

namespace Tests\Feature\Manage\AbsentNotice;

use App\Enums\AbsentNoticeShiftOutcome;
use App\Enums\AbsentNoticeStatus;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Services\Common\AbsenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The absence-request screen and the shift screen describe the same decision from two sides, so
 * every case here drives the real endpoints in sequence instead of seeding an intermediate state.
 */
class AbsentNoticeShiftSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    private Shift $shift;

    private ShiftUser $shiftUser;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow('2026-05-21 08:00:00');
        config([
            'work.absent_notice.before_hour' => 12,
            'work.system_notification_emails' => [],
        ]);

        $this->manager = User::factory()->create();
        $permission = Permission::create([
            'name' => PermissionName::ShiftEdit->value,
            'display_name' => 'Shift Edit',
        ]);
        $this->manager->givePermission($permission, null);

        $this->employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        WorkplaceUser::create(['workplace_id' => $workplace->id, 'user_id' => $this->employee->id]);

        $this->shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-25',
            'adjusted_date' => '2026-05-25',
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);

        $this->shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testProcessingTheRequestMovesTheEmployeeToTheRejectedList(): void
    {
        $notice = $this->requestAbsence();

        $response = $this->actingAs($this->manager, 'api')->patchJson($this->statusUrl($notice->id), [
            'status' => AbsentNoticeStatus::Processed->value,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'id' => $this->shiftUser->id,
            'status' => ShiftUserStatus::Rejected->value,
            'approver_id' => $this->manager->id,
        ]);
    }

    public function testRejectingOnTheShiftScreenMarksTheRequestProcessed(): void
    {
        $notice = $this->requestAbsence();

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->assignPayload(rejectedIds: [$this->employee->id])
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_absent_notices', [
            'id' => $notice->id,
            'status' => AbsentNoticeStatus::Processed->value,
            'processed_by_id' => $this->manager->id,
        ]);
        $this->assertNotNull($notice->fresh()->processed_at);
    }

    public function testRejectingOnTheShiftScreenKeepsARefusedRequestRefused(): void
    {
        $notice = $this->requestAbsence();
        $this->actingAs($this->manager, 'api')
            ->patchJson($this->statusUrl($notice->id), ['status' => AbsentNoticeStatus::Rejected->value])
            ->assertOk();

        $this->actingAs($this->manager, 'api')
            ->putJson("/manage/shifts/{$this->shift->id}", $this->assignPayload(rejectedIds: [$this->employee->id]))
            ->assertOk();

        $this->assertDatabaseHas('shift_absent_notices', [
            'id' => $notice->id,
            'status' => AbsentNoticeStatus::Rejected->value,
        ]);
    }

    public function testAShiftSaveThatDoesNotRejectTheEmployeeLeavesTheRequestAlone(): void
    {
        $notice = $this->requestAbsence();

        // The employee moves back to the pending list rather than the rejected one, so nobody has
        // decided on the absence yet and the notice must stay in the admin's pending queue.
        $this->actingAs($this->manager, 'api')
            ->putJson("/manage/shifts/{$this->shift->id}", $this->assignPayload(pendingIds: [$this->employee->id]))
            ->assertOk();

        $this->assertDatabaseHas('shift_absent_notices', [
            'id' => $notice->id,
            'status' => AbsentNoticeStatus::Pending->value,
            'processed_by_id' => null,
        ]);
    }

    public function testEmployeeCanRequestAgainAfterTheRequestWasRefused(): void
    {
        $notice = $this->requestAbsence();
        $this->actingAs($this->manager, 'api')
            ->patchJson($this->statusUrl($notice->id), ['status' => AbsentNoticeStatus::Rejected->value])
            ->assertOk();

        $this->travelTo(Carbon::parse('2026-05-24 08:00:00'));
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/my-schedules/{$this->shiftUser->id}/absent-notice")
            ->assertCreated();

        $this->assertSame(1, ShiftAbsentNotice::query()->where('shift_user_id', $this->shiftUser->id)->count());
        $this->assertDatabaseHas('shift_absent_notices', [
            'id' => $notice->id,
            'status' => AbsentNoticeStatus::Pending->value,
            'processed_by_id' => null,
            'processed_at' => null,
        ]);
    }

    public function testRefusingTheRequestIsBlockedOnceTheEmployeeIsOffTheShift(): void
    {
        $notice = $this->requestAbsence();
        $this->actingAs($this->manager, 'api')
            ->patchJson($this->statusUrl($notice->id), ['status' => AbsentNoticeStatus::Processed->value])
            ->assertOk();

        // Processing took the shift away; "come to work as planned" is no longer a thing the
        // manager can tell this employee, so the screen is refused rather than lying to them.
        $response = $this->actingAs($this->manager, 'api')->patchJson($this->statusUrl($notice->id), [
            'status' => AbsentNoticeStatus::Rejected->value,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error', ErrorCode::AbsentNoticeShiftNotApproved->value);
        $this->assertDatabaseHas('shift_absent_notices', [
            'id' => $notice->id,
            'status' => AbsentNoticeStatus::Processed->value,
        ]);
    }

    public function testProcessingReportsWhatHappenedToTheShift(): void
    {
        $notice = $this->requestAbsence();

        $response = $this->actingAs($this->manager, 'api')->patchJson($this->statusUrl($notice->id), [
            'status' => AbsentNoticeStatus::Processed->value,
        ]);

        $response->assertOk();
        $response->assertJsonPath('shift_outcome', AbsentNoticeShiftOutcome::ShiftRejected->value);
        $response->assertJsonPath('notified', true);
    }

    public function testProcessingReportsThatAShiftInProgressWasLeftAlone(): void
    {
        $notice = $this->requestAbsence();

        // Nobody handled the request before the shift started, so the row and any attendance on it
        // stay put and the screen has to say so instead of claiming a removal.
        $this->travelTo(Carbon::parse('2026-05-25 10:00:00'));
        $response = $this->actingAs($this->manager, 'api')->patchJson($this->statusUrl($notice->id), [
            'status' => AbsentNoticeStatus::Processed->value,
        ]);

        $response->assertOk();
        $response->assertJsonPath('shift_outcome', AbsentNoticeShiftOutcome::ShiftKeptAlreadyStarted->value);
        $response->assertJsonPath('notified', false);
        $this->assertDatabaseHas('shift_users', [
            'id' => $this->shiftUser->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    public function testARefusedRequestNoLongerExcusesTheMissedShift(): void
    {
        $notice = $this->requestAbsence();
        $this->actingAs($this->manager, 'api')
            ->patchJson($this->statusUrl($notice->id), ['status' => AbsentNoticeStatus::Rejected->value])
            ->assertOk();

        // The employee kept the shift, was told to work it, and never checked in. Once the two-day
        // grace period is over that is a plain absence, request or no request.
        $this->travelTo(Carbon::parse('2026-05-28 10:00:00'));

        $this->assertSame(1, app(AbsenceService::class)->getUserAbsenceCount($this->employee->id));
    }

    public function testAProcessedRequestStillExcusesTheMissedShift(): void
    {
        $notice = $this->requestAbsence();
        $this->actingAs($this->manager, 'api')
            ->patchJson($this->statusUrl($notice->id), ['status' => AbsentNoticeStatus::Processed->value])
            ->assertOk();

        $this->travelTo(Carbon::parse('2026-05-28 10:00:00'));

        $this->assertSame(0, app(AbsenceService::class)->getUserAbsenceCount($this->employee->id));
    }

    public function testEmployeeCannotRequestAgainWhileTheRequestIsStillPending(): void
    {
        $this->requestAbsence();

        $response = $this->actingAs($this->employee, 'api')->postJson(
            "/user/my-schedules/{$this->shiftUser->id}/absent-notice"
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error', ErrorCode::AbsentNoticeAlreadyExists->value);
    }

    public function testEmployeeCannotRequestAgainOnceTheRequestWasProcessed(): void
    {
        $notice = $this->requestAbsence();
        $this->actingAs($this->manager, 'api')
            ->patchJson($this->statusUrl($notice->id), ['status' => AbsentNoticeStatus::Processed->value])
            ->assertOk();

        // Processing took the employee off the shift, so there is no shift left to be absent from.
        $response = $this->actingAs($this->employee, 'api')->postJson(
            "/user/my-schedules/{$this->shiftUser->id}/absent-notice"
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::ShiftUserInvalidStatus->value);
    }

    private function requestAbsence(): ShiftAbsentNotice
    {
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/my-schedules/{$this->shiftUser->id}/absent-notice")
            ->assertCreated();

        return ShiftAbsentNotice::query()->where('shift_user_id', $this->shiftUser->id)->firstOrFail();
    }

    private function statusUrl(int $noticeId): string
    {
        return "/manage/absent-notices/{$noticeId}/status";
    }

    private function assignPayload(array $rejectedIds = [], array $pendingIds = []): array
    {
        return [
            'fixed_total' => 0,
            'temp_total' => 5,
            'fixed' => [
                'pending_ids' => [],
                'rejected_ids' => [],
                'approved_ids' => [],
            ],
            'temp' => [
                'pending_ids' => $pendingIds,
                'rejected_ids' => $rejectedIds,
                'approved_ids' => [],
            ],
        ];
    }
}
