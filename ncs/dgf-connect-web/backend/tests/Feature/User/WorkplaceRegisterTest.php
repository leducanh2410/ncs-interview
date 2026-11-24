<?php

namespace Tests\Feature\User;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class WorkplaceRegisterTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $user;

    private Workplace $workplace;

    private Workplace $keepWorkplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-21 10:00:00');

        $this->user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->keepWorkplace = Workplace::create([
            'name' => 'Keep Workplace',
            'address' => 'Keep Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testBlocksExitWhenUserHasPendingFutureShift(): void
    {
        WorkplaceUser::create(['user_id' => $this->user->id, 'workplace_id' => $this->workplace->id]);
        $shift = $this->createShift('2026-05-25');
        $this->createShiftUser($shift, ShiftUserStatus::Pending);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->keepWorkplace->id],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'WORKPLACE_CANNOT_EXIT_WITH_ACTIVE_SCHEDULE');
    }

    public function testBlocksExitWhenUserHasApprovedFutureShift(): void
    {
        WorkplaceUser::create(['user_id' => $this->user->id, 'workplace_id' => $this->workplace->id]);
        $shift = $this->createShift('2026-05-25');
        $this->createShiftUser($shift, ShiftUserStatus::Approved);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->keepWorkplace->id],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'WORKPLACE_CANNOT_EXIT_WITH_ACTIVE_SCHEDULE');
    }

    public function testAllowsExitWhenAllFutureShiftsAreRejected(): void
    {
        WorkplaceUser::create(['user_id' => $this->user->id, 'workplace_id' => $this->workplace->id]);
        $shift = $this->createShift('2026-05-25');
        $this->createShiftUser($shift, ShiftUserStatus::Rejected);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->keepWorkplace->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    public function testAllowsExitWhenShiftsAreInThePast(): void
    {
        WorkplaceUser::create(['user_id' => $this->user->id, 'workplace_id' => $this->workplace->id]);
        $shift = $this->createShift('2026-05-10');
        $this->createShiftUser($shift, ShiftUserStatus::Approved);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->keepWorkplace->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    public function testAllowsExitWhenUserHasNoShifts(): void
    {
        WorkplaceUser::create(['user_id' => $this->user->id, 'workplace_id' => $this->workplace->id]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->keepWorkplace->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    public function testBlocksNormalUserFromRegisteringATestWorkplace(): void
    {
        $testWorkplace = Workplace::factory()->create(['is_test' => true]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$testWorkplace->id],
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH');
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $testWorkplace->id,
        ]);
    }

    public function testBlocksTestUserFromRegisteringANormalWorkplace(): void
    {
        $testUser = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'is_test' => true,
        ]);

        $response = $this->actingAs($testUser, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->workplace->id],
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH');
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $testUser->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    public function testBlocksJoiningSelfRegisterDisabledWorkplace(): void
    {
        $restrictedWorkplace = Workplace::factory()->create(['is_self_register_disabled' => true]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$restrictedWorkplace->id],
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_SELF_REGISTRATION_NOT_ALLOWED');
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $restrictedWorkplace->id,
        ]);
    }

    public function testBlocksJoiningSelfRegisterDisabledWorkplaceAlongsideANormalOne(): void
    {
        $restrictedWorkplace = Workplace::factory()->create(['is_self_register_disabled' => true]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->workplace->id, $restrictedWorkplace->id],
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_SELF_REGISTRATION_NOT_ALLOWED');
        // The whole request is rejected, so the normal workplace is not attached either.
        $this->assertDatabaseCount('workplace_users', 0);
    }

    public function testAllowsRegisteringNormalWorkplacesWhenNoRestrictedWorkplaceIsTouched(): void
    {
        Workplace::factory()->create(['is_self_register_disabled' => true]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->workplace->id, $this->keepWorkplace->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    /**
     * Full lifecycle: an admin attaches the user to a restricted workplace through the real
     * admin endpoint, then the user tries to manage their own registrations from mobile.
     */
    public function testUserCannotLeaveARestrictedWorkplaceAdminAssignedThroughTheAdminEndpoint(): void
    {
        $restrictedWorkplace = Workplace::factory()->create(['is_self_register_disabled' => true]);

        $admin = User::factory()->create();
        $this->giveGlobalPermission($admin, PermissionName::WorkplaceUserAdd);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/workplaces/{$restrictedWorkplace->id}/users", ['user_ids' => [$this->user->id]])
            ->assertOk();

        // Dropping the restricted workplace from the payload is rejected.
        $this->actingAs($this->user, 'api')
            ->postJson('/user/workplaces/register', ['workplace_ids' => [$this->workplace->id]])
            ->assertStatus(400)
            ->assertJsonPath('error', 'WORKPLACE_SELF_REGISTRATION_NOT_ALLOWED');

        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $restrictedWorkplace->id,
        ]);
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $this->workplace->id,
        ]);

        // Keeping it in the payload while adding a normal workplace is allowed.
        $this->actingAs($this->user, 'api')
            ->postJson('/user/workplaces/register', [
                'workplace_ids' => [$restrictedWorkplace->id, $this->workplace->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $restrictedWorkplace->id,
        ]);
        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    public function testBlocksLeavingARestrictedWorkplaceEvenWhenClearingEverything(): void
    {
        $restrictedWorkplace = Workplace::factory()->create(['is_self_register_disabled' => true]);
        WorkplaceUser::create(['user_id' => $this->user->id, 'workplace_id' => $restrictedWorkplace->id]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->keepWorkplace->id],
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_SELF_REGISTRATION_NOT_ALLOWED');
        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $restrictedWorkplace->id,
        ]);
    }

    public function testAllowsResubmittingTheSameRestrictedWorkplaceWithoutChanges(): void
    {
        $restrictedWorkplace = Workplace::factory()->create(['is_self_register_disabled' => true]);
        WorkplaceUser::create(['user_id' => $this->user->id, 'workplace_id' => $restrictedWorkplace->id]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$restrictedWorkplace->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $this->user->id,
            'workplace_id' => $restrictedWorkplace->id,
        ]);
    }

    public function testBlocksJoiningAWorkplaceRestrictedAfterTheUserOpenedTheScreen(): void
    {
        // The flag is turned on by an admin while the user is on the registration screen:
        // the stale client payload must still be rejected.
        $this->workplace->update(['is_self_register_disabled' => true]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/workplaces/register', [
            'workplace_ids' => [$this->workplace->id],
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_SELF_REGISTRATION_NOT_ALLOWED');
    }

    private function createShift(string $date): Shift
    {
        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);
    }

    private function createShiftUser(Shift $shift, ShiftUserStatus $status): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => $status->value,
        ]);
    }
}
