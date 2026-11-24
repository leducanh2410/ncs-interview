<?php

namespace Tests\Feature\Admin\FixedScheduleChangeRequest;

use App\Enums\FixedScheduleChangeRequestStatus;
use App\Enums\PermissionName;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleChangeRequestDay;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class ApproveTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplaceShift $shiftA;

    private WorkplaceShift $shiftB;

    private FixedSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $employee = User::factory()->create();
        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
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
        $this->schedule = FixedSchedule::create(['user_id' => $employee->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftA->id,
        ]);
    }

    private function createRequest(): FixedScheduleChangeRequest
    {
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $this->schedule->id,
            'workplace_id' => $this->workplace->id,
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

    public function testRequiresProcessPermission(): void
    {
        $manager = User::factory()->create();
        $request = $this->createRequest();

        $response = $this->actingAs($manager, 'api')->postJson(
            "/admin/fixed-schedule-change-requests/{$request->id}/approve"
        );

        $response->assertForbidden();
    }

    public function testAppliesScheduleChangeOnApproval(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );
        $request = $this->createRequest();

        $response = $this->actingAs($manager, 'api')->postJson(
            "/admin/fixed-schedule-change-requests/{$request->id}/approve"
        );

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftB->id,
        ]);
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $request->id,
            'status' => FixedScheduleChangeRequestStatus::Approved->value,
        ]);
    }

    public function testReturns409ForAlreadyResolvedRequest(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );
        $request = $this->createRequest();
        $request->update(['status' => FixedScheduleChangeRequestStatus::Approved->value]);

        $response = $this->actingAs($manager, 'api')->postJson(
            "/admin/fixed-schedule-change-requests/{$request->id}/approve"
        );

        $response->assertStatus(409);
    }

    public function testReturns403ForUnmanagedWorkplace(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Addr',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestProcess,
            $otherWorkplace->id
        );
        $request = $this->createRequest();

        $response = $this->actingAs($manager, 'api')->postJson(
            "/admin/fixed-schedule-change-requests/{$request->id}/approve"
        );

        $response->assertForbidden();
    }

    public function testApprovalKeepsExistingPositionOfChangedDay(): void
    {
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);
        $this->schedule
            ->details()
            ->where('day', 1)
            ->update(['workplace_position_id' => $position->id]);
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );
        $request = $this->createRequest();

        $response = $this->actingAs($manager, 'api')->postJson(
            "/admin/fixed-schedule-change-requests/{$request->id}/approve"
        );

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $this->schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftB->id,
            'workplace_position_id' => $position->id,
        ]);
    }
}
