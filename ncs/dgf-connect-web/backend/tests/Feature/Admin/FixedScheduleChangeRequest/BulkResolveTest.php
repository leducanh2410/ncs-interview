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
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class BulkResolveTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    private WorkplaceShift $shift;

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
        $this->shift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    private function createRequest(Workplace $workplace): FixedScheduleChangeRequest
    {
        $employee = User::factory()->create();
        $schedule = FixedSchedule::create(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $workplace->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
        FixedScheduleChangeRequestDay::create([
            'fixed_schedule_change_request_id' => $request->id,
            'day' => 1,
            'current_workplace_shift_id' => $this->shift->id,
            'requested_workplace_shift_id' => null,
        ]);

        return $request;
    }

    public function testApprovesMultipleRequests(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);

        $response = $this->actingAs($manager, 'api')->postJson('/admin/fixed-schedule-change-requests/bulk-resolve', [
            'ids' => [$requestA->id, $requestB->id],
            'action' => 'approve',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestA->id,
            'status' => FixedScheduleChangeRequestStatus::Approved->value,
        ]);
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestB->id,
            'status' => FixedScheduleChangeRequestStatus::Approved->value,
        ]);
    }

    public function testRejectsMultipleRequests(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );
        $requestA = $this->createRequest($this->workplace);
        $requestB = $this->createRequest($this->workplace);

        $response = $this->actingAs($manager, 'api')->postJson('/admin/fixed-schedule-change-requests/bulk-resolve', [
            'ids' => [$requestA->id, $requestB->id],
            'action' => 'reject',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestA->id,
            'status' => FixedScheduleChangeRequestStatus::Rejected->value,
        ]);
    }

    public function testReturns403WhenAnyRequestOutsideScope(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestProcess,
            $this->workplace->id
        );
        $requestA = $this->createRequest($this->workplace);
        $requestOutOfScope = $this->createRequest($this->otherWorkplace);

        $response = $this->actingAs($manager, 'api')->postJson('/admin/fixed-schedule-change-requests/bulk-resolve', [
            'ids' => [$requestA->id, $requestOutOfScope->id],
            'action' => 'approve',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'id' => $requestA->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
    }
}
