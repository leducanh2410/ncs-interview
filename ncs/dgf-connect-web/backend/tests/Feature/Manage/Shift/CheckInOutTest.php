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

class CheckInOutTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private Shift $shift;

    private ShiftUser $shiftUser;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 20:00:00');

        $this->manager = User::factory()->create();
        $permission = Permission::create([
            'name' => PermissionName::ShiftCheckIn->value,
            'display_name' => 'Shift Check In',
        ]);
        $this->manager->givePermission($permission, null);

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

        $this->shift = Shift::query()->create([
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
            'shift_id' => $this->shift->id,
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

    public function testCheckInSucceeds(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson($this->checkInUrl(), [
            'date' => '2026-05-10',
            'time' => '09:05',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->shiftUser->user_id,
        ]);
    }

    public function testCheckInSucceedsForManagerScopedToThatWorkplace(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftCheckIn, $this->workplace->id);

        $response = $this->actingAs($manager, 'api')->postJson($this->checkInUrl(), [
            'date' => '2026-05-10',
            'time' => '09:05',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->shiftUser->user_id,
        ]);
    }

    public function testCheckInForbiddenWhenWorkplaceOutOfScope(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftCheckIn, $otherWorkplace->id);

        $response = $this->actingAs($manager, 'api')->postJson($this->checkInUrl(), [
            'date' => '2026-05-10',
            'time' => '09:05',
        ]);

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::CheckInWorkplaceOutOfScope->value);
        $this->assertDatabaseMissing('check_ins', ['shift_user_id' => $this->shiftUser->id]);
    }

    public function testCheckOutForbiddenWhenWorkplaceOutOfScope(): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson($this->checkInUrl(), [
                'date' => '2026-05-10',
                'time' => '09:00',
            ])
            ->assertOk();

        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftCheckIn, $otherWorkplace->id);

        $response = $this->actingAs($manager, 'api')->postJson($this->checkOutUrl(), [
            'date' => '2026-05-10',
            'time' => '18:00',
        ]);

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::CheckInWorkplaceOutOfScope->value);
    }

    public function testCheckOutWithoutCheckInReturns422(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson($this->checkOutUrl(), [
            'date' => '2026-05-10',
            'time' => '18:00',
        ]);

        $response->assertStatus(422);
    }

    public function testCheckOutSucceeds(): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson($this->checkInUrl(), [
                'date' => '2026-05-10',
                'time' => '09:00',
            ])
            ->assertOk();

        $response = $this->actingAs($this->manager, 'api')->postJson($this->checkOutUrl(), [
            'date' => '2026-05-10',
            'time' => '18:00',
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'check_out_time' => null,
        ]);
    }

    public function testCheckOutBeforeCheckInReturns422(): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson($this->checkInUrl(), [
                'date' => '2026-05-10',
                'time' => '09:00',
            ])
            ->assertOk();

        $response = $this->actingAs($this->manager, 'api')->postJson($this->checkOutUrl(), [
            'date' => '2026-05-10',
            'time' => '08:00',
        ]);

        $response->assertStatus(422);
    }

    public function testCheckOutSplitsOvertimeUsingWorkplaceThreshold(): void
    {
        $this->workplace->update(['overtime_after_hours' => 9]);
        $this->shiftUser->update(['is_overtime_allowed' => true]);

        $this->checkInAndOut('09:00', '20:00');

        // 11 worked hours against a 9 hour threshold, instead of the system default 8.
        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'work_minutes' => 660,
            'work_minutes_normal' => 540,
            'work_minutes_overtime' => 120,
            'work_minutes_night' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    public function testShiftFlagCountsWorkBeyondShiftDurationForEmployeeWithOvertimePermission(): void
    {
        $this->workplace->update(['overtime_after_hours' => 9]);
        $this->shiftUser->update(['is_overtime_allowed' => true]);
        $this->enableOvertimeByDurationWithOneHourBreak();

        $this->checkInAndOut('09:00', '20:00');

        // The employee is allowed to work past 18:00, and the flagged shift pays everything
        // beyond its own 8 hour standard duration as overtime instead of the workplace's 9h.
        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'work_minutes' => 600,
            'work_minutes_normal' => 480,
            'work_minutes_overtime' => 120,
            'work_minutes_night' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    public function testShiftFlagStillClipsWorkOutsideShiftRangeWithoutOvertimePermission(): void
    {
        $this->workplace->update(['overtime_after_hours' => 9]);
        $this->enableOvertimeByDurationWithOneHourBreak();

        $this->checkInAndOut('08:15', '20:00');

        // No per-employee overtime permission: the check-in is pulled back to 09:00 and the
        // check-out down to 18:00, so the flag lowers the threshold without paying extra time.
        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'check_in_time_rounded' => '2026-05-10 09:00:00',
            'check_out_time_rounded' => '2026-05-10 18:00:00',
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_overtime' => 0,
            'work_minutes_night' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    /**
     * A one hour break leaves this shift a standard duration of 8h, under the workplace's 9h.
     * The flag is set through the real config endpoint rather than written straight to the row.
     */
    private function enableOvertimeByDurationWithOneHourBreak(): void
    {
        $this->giveShiftEditPermission();

        $this->actingAs($this->manager, 'api')
            ->patchJson("/manage/shifts/{$this->shift->id}/config", [
                'fixed_total' => 1,
                'temp_total' => 1,
                'break_minutes' => 60,
                'is_overtime_by_duration' => true,
            ])
            ->assertOk();
    }

    public function testWorkOutsideShiftRangeIsClippedWhenShiftFlagIsOff(): void
    {
        $this->workplace->update(['overtime_after_hours' => 9]);

        $this->checkInAndOut('09:00', '20:00');

        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'work_minutes' => 540,
            'work_minutes_normal' => 540,
            'work_minutes_overtime' => 0,
        ]);
    }

    public function testCheckInForbiddenWithoutPermission(): void
    {
        /** @var User $other */
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->postJson($this->checkInUrl(), []);

        $response->assertStatus(403);
    }

    private function checkInAndOut(string $checkInTime, string $checkOutTime): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson($this->checkInUrl(), [
                'date' => '2026-05-10',
                'time' => $checkInTime,
            ])
            ->assertOk();

        $this->actingAs($this->manager, 'api')
            ->postJson($this->checkOutUrl(), [
                'date' => '2026-05-10',
                'time' => $checkOutTime,
            ])
            ->assertOk();
    }

    private function giveShiftEditPermission(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => PermissionName::ShiftEdit->value],
            ['display_name' => 'Shift Edit']
        );
        $this->manager->givePermission($permission, null);
    }

    private function checkInUrl(): string
    {
        return "/manage/shifts/shift-users/{$this->shiftUser->id}/check-in";
    }

    private function checkOutUrl(): string
    {
        return "/manage/shifts/shift-users/{$this->shiftUser->id}/check-out";
    }
}
