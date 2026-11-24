<?php

namespace Tests\Feature\Manage\Shift;

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
use Tests\TestCase;

class OvertimeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-21 08:00:00');

        $this->manager = User::factory()->create();

        $permission = Permission::create([
            'name' => PermissionName::ShiftUpdateOvertime->value,
            'display_name' => 'Shift Update Overtime',
        ]);
        $this->manager->givePermission($permission, null);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);

        // 9-hour shift (09:00-18:00, no break). Weekly limit defaults to 28h.
        $this->shift = $this->createShift('2026-05-25');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createShift(string $date): Shift
    {
        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);
    }

    private function assignApproved(Shift $shift, User $user, bool $isAbsent = false): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => $isAbsent,
        ]);
    }

    public function testEligibilityFlagsCandidatesAgainstWeeklyLimit(): void
    {
        // Eligible: only this 9h shift in the week -> 19h headroom.
        $eligibleUser = User::factory()->create();
        $eligibleShiftUser = $this->assignApproved($this->shift, $eligibleUser);

        // Near-limit: three 9h shifts in the same week -> 27h, only 1h headroom.
        $busyUser = User::factory()->create();
        $busyShiftUser = $this->assignApproved($this->shift, $busyUser);
        $this->assignApproved($this->createShift('2026-05-26'), $busyUser);
        $this->assignApproved($this->createShift('2026-05-27'), $busyUser);

        // Requesting 2h (120 min) of overtime.
        $response = $this->actingAs($this->manager, 'api')->getJson(
            "/manage/shifts/{$this->shift->id}/overtime-eligibility?overtime_minutes=120"
        );

        $response->assertOk();
        $response->assertJsonPath('data.overtime_minutes', 120);

        $candidates = collect($response->json('data.candidates'))->keyBy('shift_user_id');

        $this->assertTrue($candidates[$eligibleShiftUser->id]['is_eligible']);
        $this->assertEqualsWithDelta(19.0, $candidates[$eligibleShiftUser->id]['remaining_hours'], 0.01);

        $this->assertFalse($candidates[$busyShiftUser->id]['is_eligible']);
        $this->assertEqualsWithDelta(1.0, $candidates[$busyShiftUser->id]['remaining_hours'], 0.01);
    }

    public function testEligibilityExcludesAbsentAndNonApproved(): void
    {
        $absentUser = User::factory()->create();
        $this->assignApproved($this->shift, $absentUser, isAbsent: true);

        $pendingUser = User::factory()->create();
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $pendingUser->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson(
            "/manage/shifts/{$this->shift->id}/overtime-eligibility?overtime_minutes=60"
        );

        $response->assertOk();
        $this->assertCount(0, $response->json('data.candidates'));
    }

    public function testEligibilityValidatesOvertimeMinutes(): void
    {
        $response = $this->actingAs($this->manager, 'api')->getJson(
            "/manage/shifts/{$this->shift->id}/overtime-eligibility?overtime_minutes=0"
        );

        $response->assertStatus(422);
    }

    public function testEligibilityRequiresPermission(): void
    {
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger, 'api')->getJson(
            "/manage/shifts/{$this->shift->id}/overtime-eligibility?overtime_minutes=60"
        );

        $response->assertStatus(403);
    }

    public function testBulkUpdateSetsExactOvertimeState(): void
    {
        $userToEnable = User::factory()->create();
        $enableShiftUser = $this->assignApproved($this->shift, $userToEnable);

        // Already enabled, but not in the list -> should be turned off.
        $userToDisable = User::factory()->create();
        $disableShiftUser = $this->assignApproved($this->shift, $userToDisable);
        $disableShiftUser->update(['is_overtime_allowed' => true]);

        $response = $this->actingAs($this->manager, 'api')->putJson("/manage/shifts/{$this->shift->id}/overtime/bulk", [
            'shift_user_ids' => [$enableShiftUser->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'id' => $enableShiftUser->id,
            'is_overtime_allowed' => true,
        ]);
        $this->assertDatabaseHas('shift_users', [
            'id' => $disableShiftUser->id,
            'is_overtime_allowed' => false,
        ]);
    }

    public function testBulkUpdateRequiresPermission(): void
    {
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger, 'api')->putJson("/manage/shifts/{$this->shift->id}/overtime/bulk", [
            'shift_user_ids' => [],
        ]);

        $response->assertStatus(403);
    }

    public function testEligibilityRejectsPermissionScopedToAnotherWorkplace(): void
    {
        // Manager has shift.update_overtime, but only scoped to a different workplace.
        // The team-less route middleware would let this through (strict_check=false),
        // so the action must re-check against the shift's workplace.
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $scopedManager = User::factory()->create();
        $permission = Permission::where('name', PermissionName::ShiftUpdateOvertime->value)->firstOrFail();
        $scopedManager->givePermission($permission, $otherWorkplace->id);

        $response = $this->actingAs($scopedManager, 'api')->getJson(
            "/manage/shifts/{$this->shift->id}/overtime-eligibility?overtime_minutes=60"
        );

        $response->assertStatus(403);
    }

    public function testBulkUpdateRejectsPermissionScopedToAnotherWorkplace(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $scopedManager = User::factory()->create();
        $permission = Permission::where('name', PermissionName::ShiftUpdateOvertime->value)->firstOrFail();
        $scopedManager->givePermission($permission, $otherWorkplace->id);

        $userToEnable = User::factory()->create();
        $enableShiftUser = $this->assignApproved($this->shift, $userToEnable);

        $response = $this->actingAs($scopedManager, 'api')->putJson("/manage/shifts/{$this->shift->id}/overtime/bulk", [
            'shift_user_ids' => [$enableShiftUser->id],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('shift_users', [
            'id' => $enableShiftUser->id,
            'is_overtime_allowed' => false,
        ]);
    }
}
