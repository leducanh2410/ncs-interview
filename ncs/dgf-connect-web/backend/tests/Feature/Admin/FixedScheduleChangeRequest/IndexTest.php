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

class IndexTest extends TestCase
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

    private function createRequest(Workplace $workplace, string $status = 'pending'): FixedScheduleChangeRequest
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
            'status' => $status,
        ]);
        FixedScheduleChangeRequestDay::create([
            'fixed_schedule_change_request_id' => $request->id,
            'day' => 1,
            'current_workplace_shift_id' => $this->shift->id,
            'requested_workplace_shift_id' => null,
        ]);

        return $request;
    }

    public function testRequiresListPermission(): void
    {
        $manager = User::factory()->create();

        $response = $this->actingAs($manager, 'api')->getJson('/admin/fixed-schedule-change-requests');

        $response->assertForbidden();
    }

    public function testDefaultsToPendingStatus(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );
        $this->createRequest($this->workplace, FixedScheduleChangeRequestStatus::Approved->value);
        $this->createRequest($this->workplace, FixedScheduleChangeRequestStatus::Pending->value);

        $response = $this->actingAs($manager, 'api')->getJson('/admin/fixed-schedule-change-requests');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
    }

    public function testFiltersByWorkplaceScoped(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );
        $this->createRequest($this->workplace);
        $this->createRequest($this->otherWorkplace);

        $response = $this->actingAs($manager, 'api')->getJson('/admin/fixed-schedule-change-requests');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
    }

    public function testFiltersByEmployeeName(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );
        $this->createRequest($this->workplace);

        $response = $this->actingAs($manager, 'api')->getJson(
            '/admin/fixed-schedule-change-requests?employee_name=nonexistent-name-zzz'
        );

        $response->assertOk();
        $response->assertJsonPath('data.total', 0);
    }

    public function testFiltersByStatus(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );
        $this->createRequest($this->workplace, FixedScheduleChangeRequestStatus::Rejected->value);

        $response = $this->actingAs($manager, 'api')->getJson(
            '/admin/fixed-schedule-change-requests?status=' . FixedScheduleChangeRequestStatus::Rejected->value
        );

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
    }

    public function testFiltersByDateRange(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );
        $this->travelTo('2026-01-05');
        $this->createRequest($this->workplace);

        $response = $this->actingAs($manager, 'api')->getJson(
            '/admin/fixed-schedule-change-requests?dates[]=2026-02-01&dates[]=2026-02-28'
        );

        $response->assertOk();
        $response->assertJsonPath('data.total', 0);
    }

    public function testDaysExposeCurrentWorkplacePositionOfTheFixedSchedule(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Picking']);
        $request = $this->createRequest($this->workplace);
        FixedScheduleDetail::query()
            ->where('fixed_schedule_id', $request->fixed_schedule_id)
            ->where('day', 1)
            ->update(['workplace_position_id' => $position->id]);

        $response = $this->actingAs($manager, 'api')->getJson('/admin/fixed-schedule-change-requests');

        $response->assertOk();
        $response->assertJsonPath('data.data.0.days.0.current_workplace_position.id', $position->id);
        $response->assertJsonPath('data.data.0.days.0.current_workplace_position.name', 'Picking');
        $this->assertArrayNotHasKey('fixed_schedule', $response->json('data.data.0'));
    }
}
