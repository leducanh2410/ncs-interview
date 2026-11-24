<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class UpdateShiftUserTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private ShiftUser $shiftUser;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');

        $this->manager = User::factory()->create();
        foreach ([PermissionName::ShiftUpdateSalary->value, PermissionName::ShiftUpdateOvertime->value] as $name) {
            $permission = Permission::create(['name' => $name, 'display_name' => $name]);
            $this->manager->givePermission($permission, null);
        }

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplace = $this->workplace;

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $position = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);

        $employee = User::factory()->create();
        $this->shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testUpdateSalarySucceeds(): void
    {
        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/shift-users/{$this->shiftUser->id}/salary",
            ['salary_per_hour' => 1500]
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'id' => $this->shiftUser->id,
            'salary_per_hour' => 1500,
        ]);
    }

    public function testUpdateSalaryValidationFailsForNegativeValue(): void
    {
        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/shift-users/{$this->shiftUser->id}/salary",
            ['salary_per_hour' => -10]
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('salary_per_hour', $response->json('data') ?? []);
    }

    public function testUpdateAllowOvertimeSucceeds(): void
    {
        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/shift-users/{$this->shiftUser->id}/allow-overtime",
            ['is_overtime_allowed' => true]
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'id' => $this->shiftUser->id,
            'is_overtime_allowed' => true,
        ]);
    }

    public function testUpdateAllowOvertimeValidationFails(): void
    {
        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/shift-users/{$this->shiftUser->id}/allow-overtime",
            []
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('is_overtime_allowed', $response->json('data') ?? []);
    }

    public function testUpdateSalaryForbiddenWithoutPermission(): void
    {
        /** @var User $other */
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->putJson(
            "/manage/shifts/shift-users/{$this->shiftUser->id}/salary",
            ['salary_per_hour' => 1000]
        );

        $response->assertStatus(403);
    }

    public function testUpdateSalaryForbiddenWhenWorkplaceOutOfScope(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftUpdateSalary, $otherWorkplace->id);

        $response = $this->actingAs($manager, 'api')->putJson(
            "/manage/shifts/shift-users/{$this->shiftUser->id}/salary",
            ['salary_per_hour' => 1500]
        );

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::Forbidden->value);
        $this->assertDatabaseMissing('shift_users', [
            'id' => $this->shiftUser->id,
            'salary_per_hour' => 1500,
        ]);
    }

    public function testUpdateAllowOvertimeForbiddenWhenWorkplaceOutOfScope(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftUpdateOvertime, $otherWorkplace->id);

        $response = $this->actingAs($manager, 'api')->putJson(
            "/manage/shifts/shift-users/{$this->shiftUser->id}/allow-overtime",
            ['is_overtime_allowed' => true]
        );

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::Forbidden->value);
        $this->assertDatabaseMissing('shift_users', [
            'id' => $this->shiftUser->id,
            'is_overtime_allowed' => true,
        ]);
    }

    public function testUpdateSalarySucceedsForManagerScopedToThatWorkplace(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftUpdateSalary, $this->workplace->id);

        $response = $this->actingAs($manager, 'api')->putJson(
            "/manage/shifts/shift-users/{$this->shiftUser->id}/salary",
            ['salary_per_hour' => 1600]
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'id' => $this->shiftUser->id,
            'salary_per_hour' => 1600,
        ]);
    }
}
