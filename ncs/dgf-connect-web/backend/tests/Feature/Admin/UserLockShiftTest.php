<?php

namespace Tests\Feature\Admin;

use App\Enums\PermissionName;
use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Events\ShiftUserRejected;
use App\Models\CheckIn;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserLockShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-26 10:00:00');

        $disablePermission = Permission::create([
            'name' => PermissionName::UserDisable->value,
            'display_name' => 'User Disable',
        ]);
        $enablePermission = Permission::firstOrCreate(
            ['name' => PermissionName::UserEnable->value],
            ['display_name' => 'User Enable']
        );

        $this->admin = User::factory()->create();
        $this->admin->givePermission($disablePermission, null);
        $this->admin->givePermission($enablePermission, null);

        $this->employee = User::factory()->create();

        $this->workplace = Workplace::create([
            'name' => 'Test Warehouse',
            'address' => '1-1 Tokyo',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);
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
            'creator_id' => $this->admin->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 5,
            'fixed_total' => 5,
        ]);
    }

    private function assign(Shift $shift, ShiftUserStatus $status): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'status' => $status->value,
            'is_fixed' => false,
            'confirmed_at' => now(),
        ]);
    }

    private function lock(): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$this->employee->id}/disable", [
            'reason' => 'Resigned',
        ]);
    }

    // --- GET /admin/users/{user}/upcoming-shifts ---

    public function testUpcomingShiftsRequiresDisablePermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}/upcoming-shifts")
            ->assertForbidden();
    }

    public function testUpcomingShiftsReturnsOnlyActiveShiftsFromTomorrow(): void
    {
        $this->assign($this->createShift('2026-06-25'), ShiftUserStatus::Approved);
        $this->assign($this->createShift('2026-06-26'), ShiftUserStatus::Approved);
        $this->assign($this->createShift('2026-06-27'), ShiftUserStatus::Approved);
        $this->assign($this->createShift('2026-06-28'), ShiftUserStatus::Pending);
        $this->assign($this->createShift('2026-06-29'), ShiftUserStatus::Rejected);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/upcoming-shifts")
            ->assertOk();

        $shifts = $response->json('data');
        $this->assertCount(2, $shifts);
        $this->assertSame('2026-06-27', $shifts[0]['date']);
        $this->assertSame(ShiftUserStatus::Approved->value, $shifts[0]['status']);
        $this->assertSame('Test Warehouse', $shifts[0]['workplace_name']);
        $this->assertSame('09:00', $shifts[0]['start_time']);
        $this->assertSame('2026-06-28', $shifts[1]['date']);
        $this->assertSame(ShiftUserStatus::Pending->value, $shifts[1]['status']);
    }

    // --- POST /admin/users/{id}/disable ---

    public function testLockRejectsPendingAndApprovedShiftsFromTomorrow(): void
    {
        $pending = $this->assign($this->createShift('2026-06-27'), ShiftUserStatus::Pending);
        $approved = $this->assign($this->createShift('2026-06-28'), ShiftUserStatus::Approved);

        $this->lock()->assertOk();

        $this->assertTrue($this->employee->refresh()->is_disabled);

        foreach ([$pending, $approved] as $shiftUser) {
            $shiftUser->refresh();
            $this->assertSame(ShiftUserStatus::Rejected->value, $shiftUser->status);
            $this->assertNull($shiftUser->confirmed_at);
            $this->assertSame($this->admin->id, $shiftUser->approver_id);
        }
    }

    public function testLockKeepsTodayAndPastShifts(): void
    {
        $past = $this->assign($this->createShift('2026-06-20'), ShiftUserStatus::Approved);
        $today = $this->assign($this->createShift('2026-06-26'), ShiftUserStatus::Approved);

        $this->lock()->assertOk();

        $this->assertSame(ShiftUserStatus::Approved->value, $past->refresh()->status);
        $this->assertSame(ShiftUserStatus::Approved->value, $today->refresh()->status);
    }

    public function testLockDeletesCheckInsOfRejectedShifts(): void
    {
        $shift = $this->createShift('2026-06-27');
        $shiftUser = $this->assign($shift, ShiftUserStatus::Approved);
        $checkIn = CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'check_in_time' => '2026-06-27 09:00:00',
        ]);

        $this->lock()->assertOk();

        $this->assertDatabaseMissing('check_ins', ['id' => $checkIn->id]);
    }

    public function testLockDeletesUpcomingPlannerDrafts(): void
    {
        $upcomingDraft = ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-27',
            'workplace_shift_id' => $this->workplaceShift->id,
            'user_id' => $this->employee->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Add->value,
        ]);
        $todayDraft = ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-26',
            'workplace_shift_id' => $this->workplaceShift->id,
            'user_id' => $this->employee->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Add->value,
        ]);

        $this->lock()->assertOk();

        $this->assertDatabaseMissing('shift_plan_drafts', ['id' => $upcomingDraft->id]);
        $this->assertDatabaseHas('shift_plan_drafts', ['id' => $todayDraft->id]);
    }

    public function testLockDoesNotTouchOtherUsersShifts(): void
    {
        $other = User::factory()->create();
        $shift = $this->createShift('2026-06-27');
        $this->assign($shift, ShiftUserStatus::Approved);
        $otherShiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $other->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_fixed' => false,
        ]);

        $this->lock()->assertOk();

        $this->assertSame(ShiftUserStatus::Approved->value, $otherShiftUser->refresh()->status);
    }

    public function testUpcomingShiftsOrdersSameDayShiftsByStartTime(): void
    {
        $lateShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '13:00:00',
            'end_time' => '22:00:00',
        ]);
        $late = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $lateShift->id,
            'creator_id' => $this->admin->id,
            'date' => '2026-06-27',
            'adjusted_date' => '2026-06-27',
            'temp_total' => 5,
            'fixed_total' => 5,
        ]);
        $this->assign($late, ShiftUserStatus::Approved);
        $this->assign($this->createShift('2026-06-27'), ShiftUserStatus::Approved);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/upcoming-shifts")
            ->assertOk();

        $shifts = $response->json('data');
        $this->assertCount(2, $shifts);
        $this->assertSame('09:00', $shifts[0]['start_time']);
        $this->assertSame('13:00', $shifts[1]['start_time']);
    }

    public function testLockDispatchesRejectedEventOnlyForTheRejectedShifts(): void
    {
        Event::fake([ShiftUserRejected::class]);
        $pending = $this->assign($this->createShift('2026-06-27'), ShiftUserStatus::Pending);
        $approved = $this->assign($this->createShift('2026-06-28'), ShiftUserStatus::Approved);
        $today = $this->assign($this->createShift('2026-06-26'), ShiftUserStatus::Approved);

        $this->lock()->assertOk();

        Event::assertDispatchedTimes(ShiftUserRejected::class, 2);
        foreach ([$pending, $approved] as $shiftUser) {
            Event::assertDispatched(
                ShiftUserRejected::class,
                fn(ShiftUserRejected $event) => $event->shiftUser->id === $shiftUser->id
            );
        }
        Event::assertNotDispatched(
            ShiftUserRejected::class,
            fn(ShiftUserRejected $event) => $event->shiftUser->id === $today->id
        );
    }

    public function testLockRejectsShiftsRegisteredAndApprovedThroughTheirRealFlows(): void
    {
        $manager = User::factory()->create();
        $manager->givePermission(
            Permission::create(['name' => PermissionName::ShiftEdit->value, 'display_name' => 'Shift Edit']),
            null
        );
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);

        $employee = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'STAFF-8100',
        ]);
        $employee->info()->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['workplace_id' => $this->workplace->id, 'user_id' => $employee->id]);

        $shift = $this->createShift('2026-06-27');
        $this->actingAs($employee, 'api')
            ->postJson("/user/shifts/{$shift->id}/register")
            ->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $this->actingAs($manager, 'api')
            ->putJson("/manage/shifts/{$shift->id}", [
                'fixed_total' => 0,
                'temp_total' => 5,
                'fixed' => ['pending_ids' => [], 'rejected_ids' => [], 'approved_ids' => []],
                'temp' => [
                    'pending_ids' => [],
                    'rejected_ids' => [],
                    'approved_ids' => [$position->id => [$employee->id]],
                ],
            ])
            ->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$employee->id}/disable", ['reason' => 'Resigned'])
            ->assertOk();

        $this->assertTrue($employee->refresh()->is_disabled);
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'status' => ShiftUserStatus::Rejected->value,
            'approver_id' => $this->admin->id,
        ]);
    }

    public function testUnlockDoesNotRestoreRejectedShifts(): void
    {
        $shiftUser = $this->assign($this->createShift('2026-06-27'), ShiftUserStatus::Approved);

        $this->lock()->assertOk();
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'Back to work'])
            ->assertOk();

        $this->assertFalse($this->employee->refresh()->is_disabled);
        $this->assertSame(ShiftUserStatus::Rejected->value, $shiftUser->refresh()->status);
    }
}
