<?php

namespace Tests\Feature\Manage\WorkHistory;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AddUsersToShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplacePosition $position;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-21 20:00:00');

        $this->manager = User::factory()->create();
        $permission = Permission::create([
            'name' => PermissionName::WorkHistoryAdd->value,
            'display_name' => 'Work History Add',
        ]);
        $this->manager->givePermission($permission, null);

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
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->position = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);

        // Shift in the past so now()->isBefore($end) is false
        $this->shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-21',
            'adjusted_date' => '2026-05-21',
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testRequiresWorkHistoryAddPermission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [999],
        ]);

        $response->assertStatus(403);
    }

    public function testRejectsIfShiftHasNotEnded(): void
    {
        Carbon::setTestNow('2026-05-21 10:00:00');

        $employee = $this->createWorkplaceMember();

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [$employee->id],
        ]);

        $response->assertStatus(400);
    }

    public function testRejectsWhenAllUsersNotInWorkplace(): void
    {
        $outsider = User::factory()->create();

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [$outsider->id],
        ]);

        $response->assertStatus(422);
    }

    public function testAddsNewUserToShift(): void
    {
        $employee = $this->createWorkplaceMember();

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [$employee->id],
        ]);

        $response->assertOk();
        $this->assertContains($employee->id, $response->json('data.added'));
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $employee->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->assertDatabaseHas('check_ins', [
            'shift_id' => $this->shift->id,
            'user_id' => $employee->id,
        ]);
    }

    public function testUpdatesExistingNonApprovedShiftUser(): void
    {
        $employee = $this->createWorkplaceMember();
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [$employee->id],
        ]);

        $response->assertOk();
        $this->assertContains($employee->id, $response->json('data.updated'));
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $employee->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    public function testIgnoresAlreadyApprovedUser(): void
    {
        $employee = $this->createWorkplaceMember();
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/work-histories/add', [
            'shift_id' => $this->shift->id,
            'user_ids' => [$employee->id],
        ]);

        $response->assertOk();
        $this->assertContains($employee->id, $response->json('data.ignored'));
    }

    private function createWorkplaceMember(): User
    {
        $employee = User::factory()->create();
        WorkplaceUser::create(['user_id' => $employee->id, 'workplace_id' => $this->workplace->id]);

        return $employee;
    }
}
