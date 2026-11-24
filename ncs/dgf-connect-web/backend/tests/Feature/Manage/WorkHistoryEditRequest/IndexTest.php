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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class IndexTest extends TestCase
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

    private function createRequest(
        Workplace $workplace,
        string $status = 'pending',
        ?string $employeeName = null
    ): WorkHistoryEditRequest {
        $employee = User::factory()->create($employeeName ? ['first_name' => $employeeName, 'last_name' => ''] : []);
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

        return WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $workplace->id,
            'requested_by_id' => $employee->id,
            'status' => $status,
        ]);
    }

    public function testRequiresProcessPermission(): void
    {
        $manager = User::factory()->create();

        $response = $this->actingAs($manager, 'api')->getJson('/manage/work-history-edit-requests');

        $response->assertForbidden();
    }

    public function testDefaultsToPendingStatus(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $this->createRequest($this->workplace, WorkHistoryEditRequestStatus::Approved->value);
        $this->createRequest($this->workplace, WorkHistoryEditRequestStatus::Pending->value);

        $response = $this->actingAs($manager, 'api')->getJson('/manage/work-history-edit-requests');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
    }

    public function testIncludesWorkplaceNameInTheResponse(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $this->createRequest($this->workplace);

        $response = $this->actingAs($manager, 'api')->getJson('/manage/work-history-edit-requests');

        $response->assertOk();
        $response->assertJsonPath('data.data.0.workplace.name', $this->workplace->name);
    }

    public function testFiltersScopedToManagedWorkplaces(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $this->createRequest($this->workplace);
        $this->createRequest($this->otherWorkplace);

        $response = $this->actingAs($manager, 'api')->getJson('/manage/work-history-edit-requests');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
    }

    public function testFiltersByEmployeeName(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $this->createRequest($this->workplace, 'pending', 'Alice');

        $response = $this->actingAs($manager, 'api')->getJson(
            '/manage/work-history-edit-requests?employee_name=nonexistent-name-zzz'
        );

        $response->assertOk();
        $response->assertJsonPath('data.total', 0);
    }

    public function testFiltersByDateRange(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $this->travelTo('2026-01-05');
        $this->createRequest($this->workplace);

        $response = $this->actingAs($manager, 'api')->getJson(
            '/manage/work-history-edit-requests?dates[]=2026-02-01&dates[]=2026-02-28'
        );

        $response->assertOk();
        $response->assertJsonPath('data.total', 0);
    }
}
