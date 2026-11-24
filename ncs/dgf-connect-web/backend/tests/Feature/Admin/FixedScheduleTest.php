<?php

namespace Tests\Feature\Admin;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class FixedScheduleTest extends TestCase
{
    use RefreshDatabase;
    use GrantsWorkplacePermissions;

    private User $manager;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->employee = User::factory()->create();

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
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

        $this->employee->workplaces()->attach($this->workplace->id);
    }

    // index

    public function testIndexRequiresPermission(): void
    {
        $response = $this->actingAs($this->manager, 'api')->getJson('/admin/fixed-schedules');

        $response->assertForbidden();
    }

    public function testIndexReturnsPaginatedList(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleList);
        $this->createFixedSchedule();

        $response = $this->actingAs($this->manager, 'api')->getJson('/admin/fixed-schedules');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.user_id', $this->employee->id);
        $response->assertJsonPath('data.data.0.workplace_id', $this->workplace->id);
    }

    public function testIndexFiltersByWorkplace(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleList);

        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $otherEmployee = User::factory()->create();
        $otherEmployee->workplaces()->attach($otherWorkplace->id);
        $otherShift = WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->createFixedSchedule();
        $otherSchedule = FixedSchedule::create([
            'user_id' => $otherEmployee->id,
            'workplace_id' => $otherWorkplace->id,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $otherSchedule->id,
            'day' => 2,
            'workplace_shift_id' => $otherShift->id,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson(
            '/admin/fixed-schedules?workplace_id=' . $this->workplace->id
        );

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.workplace_id', $this->workplace->id);
    }

    // store

    public function testStoreRequiresPermission(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 1, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertForbidden();
    }

    public function testStoreCreatesFixedScheduleWithDetails(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [
                ['day' => 1, 'workplace_shift_id' => $this->shift->id],
                ['day' => 3, 'workplace_shift_id' => $this->shift->id],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
        ]);
        $this->assertDatabaseCount('fixed_schedule_details', 2);
    }

    public function testStoreFailsWhenUserNotInWorkplace(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);
        $outsider = User::factory()->create();

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $outsider->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 1, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertUnprocessable();
    }

    public function testStoreFailsWhenUserAlreadyHasScheduleInWorkplace(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);
        $this->createFixedSchedule();

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 2, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertUnprocessable();
    }

    public function testStoreFailsWhenShiftBelongsToDifferentWorkplace(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);

        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Addr',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $foreignShift = WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 1, 'workplace_shift_id' => $foreignShift->id]],
        ]);

        $response->assertUnprocessable();
    }

    public function testStoreFailsWhenUserAlreadyScheduledOnSameDay(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);

        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Addr',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->employee->workplaces()->attach($otherWorkplace->id);
        $otherShift = WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $existing = FixedSchedule::create([
            'user_id' => $this->employee->id,
            'workplace_id' => $otherWorkplace->id,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $existing->id,
            'day' => 1,
            'workplace_shift_id' => $otherShift->id,
        ]);

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 1, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertUnprocessable();
    }

    // update

    public function testUpdateRequiresPermission(): void
    {
        $schedule = $this->createFixedSchedule();

        $response = $this->actingAs($this->manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 2, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertForbidden();
    }

    public function testUpdateAddsAndRemovesDetails(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleEdit);
        $schedule = $this->createFixedSchedule();

        $response = $this->actingAs($this->manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [
                ['day' => 2, 'workplace_shift_id' => $this->shift->id],
                ['day' => 4, 'workplace_shift_id' => $this->shift->id],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
        ]);
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 2,
        ]);
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 4,
        ]);
    }

    public function testUpdateAllowsKeepingExistingDay(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleEdit);
        $schedule = $this->createFixedSchedule();

        $response = $this->actingAs($this->manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 1, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
        ]);
    }

    public function testUpdateFailsWhenDayConflictsWithAnotherSchedule(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleEdit);

        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Addr',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->employee->workplaces()->attach($otherWorkplace->id);
        $otherShift = WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $conflicting = FixedSchedule::create([
            'user_id' => $this->employee->id,
            'workplace_id' => $otherWorkplace->id,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $conflicting->id,
            'day' => 3,
            'workplace_shift_id' => $otherShift->id,
        ]);

        $schedule = $this->createFixedSchedule();

        $response = $this->actingAs($this->manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 3, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $response->assertUnprocessable();
    }

    // destroy

    public function testDestroyRequiresPermission(): void
    {
        $schedule = $this->createFixedSchedule();

        $response = $this->actingAs($this->manager, 'api')->deleteJson("/admin/fixed-schedules/{$schedule->id}");

        $response->assertForbidden();
    }

    public function testDestroyDeletesSchedule(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleDelete);
        $schedule = $this->createFixedSchedule();

        $response = $this->actingAs($this->manager, 'api')->deleteJson("/admin/fixed-schedules/{$schedule->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('fixed_schedules', ['id' => $schedule->id]);
    }

    public function testDestroyReturns404ForNonExistentSchedule(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleDelete);

        $response = $this->actingAs($this->manager, 'api')->deleteJson('/admin/fixed-schedules/9999');

        $response->assertNotFound();
    }

    private function givePermission(User $user, PermissionName $permissionName): void
    {
        $this->giveGlobalPermission($user, $permissionName);
    }

    private function createFixedSchedule(?User $employee = null, ?Workplace $workplace = null): FixedSchedule
    {
        $employee ??= $this->employee;
        $workplace ??= $this->workplace;

        $schedule = FixedSchedule::create([
            'user_id' => $employee->id,
            'workplace_id' => $workplace->id,
        ]);

        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);

        return $schedule;
    }

    public function testStoreSavesPositionPerDay(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);
        $positionA = $this->createPosition('Position A');
        $positionB = $this->createPosition('Position B');

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [
                ['day' => 1, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $positionA->id],
                ['day' => 2, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $positionB->id],
                ['day' => 3, 'workplace_shift_id' => $this->shift->id],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_details', ['day' => 1, 'workplace_position_id' => $positionA->id]);
        $this->assertDatabaseHas('fixed_schedule_details', ['day' => 2, 'workplace_position_id' => $positionB->id]);
        $this->assertDatabaseHas('fixed_schedule_details', ['day' => 3, 'workplace_position_id' => null]);
    }

    public function testStoreFailsWhenPositionBelongsToDifferentWorkplace(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $foreignPosition = WorkplacePosition::create(['workplace_id' => $otherWorkplace->id, 'name' => 'Foreign']);

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [
                ['day' => 1, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $foreignPosition->id],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertSame(['Invalid position.'], $response->json('data')['schedules.0.workplace_position_id']);
        $this->assertDatabaseCount('fixed_schedules', 0);
    }

    public function testStoreFailsWhenPositionIsInactive(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);
        $inactivePosition = $this->createPosition('Inactive', false);

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/fixed-schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [
                [
                    'day' => 1,
                    'workplace_shift_id' => $this->shift->id,
                    'workplace_position_id' => $inactivePosition->id,
                ],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertSame(['Invalid position.'], $response->json('data')['schedules.0.workplace_position_id']);
        $this->assertDatabaseCount('fixed_schedules', 0);
    }

    public function testUpdateChangesAndClearsPositionPerDay(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleEdit);
        $positionA = $this->createPosition('Position A');
        $positionB = $this->createPosition('Position B');
        $schedule = $this->createFixedSchedule();
        $schedule
            ->details()
            ->where('day', 1)
            ->update(['workplace_position_id' => $positionA->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 2,
            'workplace_shift_id' => $this->shift->id,
            'workplace_position_id' => $positionA->id,
        ]);

        $response = $this->actingAs($this->manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [
                ['day' => 1, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $positionB->id],
                ['day' => 2, 'workplace_shift_id' => $this->shift->id],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_position_id' => $positionB->id,
        ]);
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 2,
            'workplace_position_id' => null,
        ]);
    }

    public function testIndexIncludesWorkplacePositionOnDetails(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleList);
        $position = $this->createPosition('Position A');
        $schedule = $this->createFixedSchedule();
        $schedule
            ->details()
            ->where('day', 1)
            ->update(['workplace_position_id' => $position->id]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/admin/fixed-schedules');

        $response->assertOk();
        $response->assertJsonPath('data.data.0.details.0.workplace_position_id', $position->id);
        $response->assertJsonPath('data.data.0.details.0.workplace_position.name', 'Position A');
    }

    public function testUpdateValidatesPositionAgainstTheScheduleWorkplaceNotTheBody(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleEdit);
        $schedule = $this->createFixedSchedule();
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->employee->workplaces()->attach($otherWorkplace->id);
        $foreignPosition = WorkplacePosition::create(['workplace_id' => $otherWorkplace->id, 'name' => 'Foreign']);

        $response = $this->actingAs($this->manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->employee->id,
            'workplace_id' => $otherWorkplace->id,
            'schedules' => [
                ['day' => 1, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $foreignPosition->id],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_position_id' => null,
        ]);
    }

    public function testUpdateKeepsStoredPositionAfterItWasDeactivated(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);
        $this->givePermission($this->manager, PermissionName::FixedScheduleEdit);
        $this->givePermission($this->manager, PermissionName::WorkplacePositionDelete);
        $position = $this->createPosition('Position A');
        $otherShift = $this->createShift('13:00:00', '21:00:00');

        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/fixed-schedules', [
                'user_id' => $this->employee->id,
                'workplace_id' => $this->workplace->id,
                'schedules' => [
                    ['day' => 1, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $position->id],
                    ['day' => 2, 'workplace_shift_id' => $this->shift->id],
                ],
            ])
            ->assertOk();
        $schedule = FixedSchedule::query()->firstOrFail();

        $this->deactivatePositionThroughDeleteEndpoint($position);

        $response = $this->actingAs($this->manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [
                ['day' => 1, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $position->id],
                ['day' => 2, 'workplace_shift_id' => $otherShift->id],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_position_id' => $position->id,
        ]);
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 2,
            'workplace_shift_id' => $otherShift->id,
            'workplace_position_id' => null,
        ]);
    }

    public function testUpdateRejectsAssigningDeactivatedPositionToAnotherDay(): void
    {
        $this->givePermission($this->manager, PermissionName::FixedScheduleCreate);
        $this->givePermission($this->manager, PermissionName::FixedScheduleEdit);
        $this->givePermission($this->manager, PermissionName::WorkplacePositionDelete);
        $position = $this->createPosition('Position A');

        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/fixed-schedules', [
                'user_id' => $this->employee->id,
                'workplace_id' => $this->workplace->id,
                'schedules' => [
                    ['day' => 1, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $position->id],
                    ['day' => 2, 'workplace_shift_id' => $this->shift->id],
                ],
            ])
            ->assertOk();
        $schedule = FixedSchedule::query()->firstOrFail();

        $this->deactivatePositionThroughDeleteEndpoint($position);

        $response = $this->actingAs($this->manager, 'api')->putJson("/admin/fixed-schedules/{$schedule->id}", [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [
                ['day' => 1, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $position->id],
                ['day' => 2, 'workplace_shift_id' => $this->shift->id, 'workplace_position_id' => $position->id],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertArrayNotHasKey('schedules.0.workplace_position_id', $response->json('data'));
        $this->assertSame(['Invalid position.'], $response->json('data')['schedules.1.workplace_position_id']);
        $this->assertDatabaseHas('fixed_schedule_details', [
            'fixed_schedule_id' => $schedule->id,
            'day' => 2,
            'workplace_position_id' => null,
        ]);
    }

    private function deactivatePositionThroughDeleteEndpoint(WorkplacePosition $position): void
    {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->shift->id,
            'creator_id' => $this->manager->id,
            'date' => now()->addDay()->toDateString(),
            'adjusted_date' => now()->addDay()->toDateString(),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $this->actingAs($this->manager, 'api')
            ->deleteJson("/admin/workplaces/{$this->workplace->id}/positions/{$position->id}")
            ->assertOk();

        $this->assertDatabaseHas('workplace_positions', ['id' => $position->id, 'is_active' => false]);
    }

    private function createShift(string $startTime, string $endTime): WorkplaceShift
    {
        return WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    private function createPosition(string $name, bool $isActive = true): WorkplacePosition
    {
        return WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => $name,
            'is_active' => $isActive,
        ]);
    }
}
