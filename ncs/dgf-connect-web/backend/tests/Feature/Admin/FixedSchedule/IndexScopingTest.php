<?php

namespace Tests\Feature\Admin\FixedSchedule;

use App\Enums\PermissionName;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class IndexScopingTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    private WorkplaceShift $shift;

    private WorkplaceShift $otherShift;

    private User $employee;

    private User $otherEmployee;

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
        $this->otherShift = WorkplaceShift::create([
            'workplace_id' => $this->otherWorkplace->id,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $this->employee = User::factory()->create();
        $this->employee->workplaces()->attach($this->workplace->id);
        $this->otherEmployee = User::factory()->create();
        $this->otherEmployee->workplaces()->attach($this->otherWorkplace->id);

        $schedule = FixedSchedule::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);

        $otherSchedule = FixedSchedule::create([
            'user_id' => $this->otherEmployee->id,
            'workplace_id' => $this->otherWorkplace->id,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $otherSchedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->otherShift->id,
        ]);
    }

    public function testIndexOnlyReturnsScopedWorkplacesForNonGlobalAdmin(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleList, $this->workplace->id);

        $response = $this->actingAs($manager, 'api')->getJson('/admin/fixed-schedules');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.workplace_id', $this->workplace->id);
    }

    public function testIndexReturnsAllWorkplacesForGlobalAdmin(): void
    {
        $globalAdmin = User::factory()->create();
        $this->giveGlobalPermission($globalAdmin, PermissionName::FixedScheduleList);

        $response = $this->actingAs($globalAdmin, 'api')->getJson('/admin/fixed-schedules');

        $response->assertOk();
        $response->assertJsonPath('data.total', 2);
    }

    public function testStoreReturns403ForUnmanagedWorkplace(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleCreate, $this->otherWorkplace->id);

        $newEmployee = User::factory()->create();
        $newEmployee->workplaces()->attach($this->workplace->id);

        $response = $this->actingAs($manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $newEmployee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 2, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('fixed_schedules', 2);
    }

    public function testStoreSucceedsForManagedWorkplaceHappyPath(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleCreate, $this->workplace->id);

        $newEmployee = User::factory()->create();
        $newEmployee->workplaces()->attach($this->workplace->id);

        $response = $this->actingAs($manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $newEmployee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 2, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedules', [
            'user_id' => $newEmployee->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    public function testUpdateReturns403ForUnmanagedSchedule(): void
    {
        $schedule = FixedSchedule::where('workplace_id', $this->otherWorkplace->id)->firstOrFail();

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleEdit, $this->workplace->id);

        $response = $this->actingAs($manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->otherEmployee->id,
            'workplace_id' => $this->otherWorkplace->id,
            'schedules' => [['day' => 2, 'workplace_shift_id' => $this->otherShift->id]],
        ]);

        $response->assertForbidden();
    }

    public function testDestroyReturns403ForUnmanagedSchedule(): void
    {
        $schedule = FixedSchedule::where('workplace_id', $this->otherWorkplace->id)->firstOrFail();

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleDelete, $this->workplace->id);

        $response = $this->actingAs($manager, 'api')->deleteJson("/admin/fixed-schedules/{$schedule->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('fixed_schedules', ['id' => $schedule->id]);
    }
}
