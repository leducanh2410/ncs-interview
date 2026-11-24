<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class ShiftWeeklyWorkLoadTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $position;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-07 08:00:00');
        config(['work.max_hour_per_week' => 28]);

        $this->manager = User::factory()->create();

        $this->workplace = Workplace::create([
            'name' => 'Main',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);
        $this->position = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
        $this->giveWorkplaceScopedPermission($this->manager, PermissionName::ShiftList, $this->workplace->id);

        $this->shift = $this->createShift('2026-09-09');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testApprovedMemberCountsThisShiftOnlyOnceInTheSevenDaysUpToIt(): void
    {
        $employee = $this->createMember();
        $this->assign($this->shift, $employee);
        $this->assign($this->createShift('2026-09-07'), $employee);
        $this->assign($this->createShift('2026-09-08'), $employee);
        $this->assign($this->createShift('2026-09-02'), $employee);
        $this->assign($this->createShift('2026-09-16'), $employee);

        $workLoad = $this->fetch([$employee->id])
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($workLoad['is_shift_counted']);
        $this->assertFalse($workLoad['is_blocked']);
        $this->assertEqualsWithDelta(18.0, $workLoad['weekly_hours'], 0.01);
        $this->assertEqualsWithDelta(27.0, $workLoad['projected_hours'], 0.01);
        $this->assertEqualsWithDelta(1.0, $workLoad['remaining_hours'], 0.01);
        $this->assertSame(28, $workLoad['max_hour_per_week']);
        $this->assertSame('near', $workLoad['status']);
        $this->assertCount(7, $workLoad['days']);
        $this->assertEqualsWithDelta(
            9.0,
            collect($workLoad['days'])->firstWhere('date', '2026-09-08')['scheduled_hours'],
            0.01
        );
        $this->assertSame('2026-09-03', $workLoad['window_start']);
        $this->assertSame('2026-09-09', $workLoad['window_end']);
        $this->assertSame('2026-09-03', $workLoad['days'][0]['date']);
        $this->assertSame('2026-09-09', $workLoad['days'][6]['date']);
    }

    public function testPendingRegistrantProjectsThisShiftOnTopOfTheSevenDaysUpToIt(): void
    {
        $employee = $this->createMember();
        $this->assign($this->shift, $employee, ShiftUserStatus::Pending);
        $this->assign($this->createShift('2026-09-02'), $employee);
        $this->assign($this->createShift('2026-09-03'), $employee);
        $this->assign($this->createShift('2026-09-04'), $employee);
        $this->assign($this->createShift('2026-09-08'), $employee);
        $this->assign($this->createShift('2026-09-10'), $employee);
        $this->assign($this->createShift('2026-09-11'), $employee);

        $workLoad = $this->fetch([$employee->id])
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($workLoad['is_shift_counted']);
        $this->assertEqualsWithDelta(27.0, $workLoad['weekly_hours'], 0.01);
        $this->assertEqualsWithDelta(9.0, $workLoad['shift_hours'], 0.01);
        $this->assertEqualsWithDelta(36.0, $workLoad['projected_hours'], 0.01);
        $this->assertEqualsWithDelta(-8.0, $workLoad['remaining_hours'], 0.01);
        $this->assertSame('over', $workLoad['status']);
        $this->assertSame('2026-09-03', $workLoad['window_start']);
        $this->assertSame('2026-09-09', $workLoad['window_end']);
    }

    public function testShiftsAfterThisShiftDateCountTowardTheLimit(): void
    {
        $employee = $this->createMember();
        $this->assign($this->shift, $employee, ShiftUserStatus::Pending);
        foreach (['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13'] as $date) {
            $this->assign($this->createShift($date), $employee);
        }

        $workLoad = $this->fetch([$employee->id])
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($workLoad['is_blocked']);
        $this->assertEqualsWithDelta(17.0, $workLoad['blocked_over_hours'], 0.01);
        $this->assertEqualsWithDelta(45.0, $workLoad['projected_hours'], 0.01);
        $this->assertSame('over', $workLoad['status']);
        $this->assertSame('2026-09-07', $workLoad['window_start']);
        $this->assertSame('2026-09-13', $workLoad['window_end']);
    }

    public function testEachWindowIsHeldToTheLowestLimitAmongItsDays(): void
    {
        $employee = $this->createMember();
        UserConfig::create([
            'user_id' => $employee->id,
            'max_shift_per_day' => 1,
            'max_hour_per_week' => 40,
            'max_hour_per_week_expiry_date' => '2026-09-09',
        ]);
        $this->assign($this->shift, $employee, ShiftUserStatus::Pending);
        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $date) {
            $this->assign($this->createShift($date), $employee);
        }

        $workLoad = $this->fetch([$employee->id])
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($workLoad['is_blocked']);
        $this->assertEqualsWithDelta(8.0, $workLoad['blocked_over_hours'], 0.01);
        $this->assertSame(28, $workLoad['max_hour_per_week']);
        $this->assertFalse($workLoad['is_custom_limit']);
    }

    public function testBlockedMemberShowsTheOverAmountForTheSevenDaysUpToThisShift(): void
    {
        $employee = $this->createMember();
        $this->assign($this->shift, $employee, ShiftUserStatus::Pending);
        foreach (['2026-09-05', '2026-09-06', '2026-09-07'] as $date) {
            $this->assign($this->createShift($date), $employee);
        }

        $workLoad = $this->fetch([$employee->id])
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($workLoad['is_blocked']);
        $this->assertEqualsWithDelta(8.0, $workLoad['blocked_over_hours'], 0.01);
        $this->assertSame('over', $workLoad['status']);
        $this->assertSame('2026-09-03', $workLoad['window_start']);
        $this->assertSame('2026-09-09', $workLoad['window_end']);
        $this->assertEqualsWithDelta(27.0, $workLoad['weekly_hours'], 0.01);
        $this->assertEqualsWithDelta(36.0, $workLoad['projected_hours'], 0.01);
        $this->assertEqualsWithDelta(-8.0, $workLoad['remaining_hours'], 0.01);
    }

    public function testBlockedFlagMatchesWhatSavingTheAssignmentAccepts(): void
    {
        $this->giveWorkplaceScopedPermission($this->manager, PermissionName::ShiftEdit, $this->workplace->id);
        $overworked = $this->createMember('3001');
        $relaxed = $this->createMember('3002');
        $this->assign($this->shift, $overworked, ShiftUserStatus::Pending);
        $this->assign($this->shift, $relaxed, ShiftUserStatus::Pending);
        $this->assign($this->createShift('2026-09-06'), $overworked);
        $this->assign($this->createShift('2026-09-07'), $overworked);
        $sharedShift = $this->createShift('2026-09-08');
        $this->assign($sharedShift, $overworked);
        $this->assign($sharedShift, $relaxed);

        $workLoads = collect(
            $this->fetch([$overworked->id, $relaxed->id])
                ->assertOk()
                ->json('data')
        )->keyBy('user_id');
        $this->assertTrue($workLoads[$overworked->id]['is_blocked']);
        $this->assertFalse($workLoads[$relaxed->id]['is_blocked']);
        $this->assertNull($workLoads[$relaxed->id]['blocked_over_hours']);

        $this->saveApproved([$overworked->id, $relaxed->id])->assertStatus(422);
        $this->saveApproved([$relaxed->id], [$overworked->id])->assertOk();

        $afterSave = $this->fetch([$relaxed->id])
            ->assertOk()
            ->json('data.0');
        $this->assertTrue($afterSave['is_shift_counted']);
        $this->assertFalse($afterSave['is_blocked']);
        $this->assertEqualsWithDelta(18.0, $afterSave['projected_hours'], 0.01);
    }

    public function testApprovedMemberWhoCheckedOutEarlyCountsTheActualHoursOfThisShift(): void
    {
        $this->giveWorkplaceScopedPermission($this->manager, PermissionName::ShiftCheckIn, $this->workplace->id);
        $employee = $this->createMember();
        $shiftUser = $this->assign($this->shift, $employee);
        foreach (['2026-09-06', '2026-09-07', '2026-09-08'] as $date) {
            $this->assign($this->createShift($date), $employee);
        }
        $this->actingAs($this->manager, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-in", [
                'date' => '2026-09-09',
                'time' => '09:00',
            ])
            ->assertOk();
        $this->actingAs($this->manager, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-out", [
                'date' => '2026-09-09',
                'time' => '10:00',
            ])
            ->assertOk();

        $workLoad = $this->fetch([$employee->id])
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($workLoad['is_shift_counted']);
        $this->assertFalse($workLoad['is_blocked']);
        $this->assertEqualsWithDelta(27.0, $workLoad['weekly_hours'], 0.01);
        $this->assertEqualsWithDelta(1.0, $workLoad['shift_hours'], 0.01);
        $this->assertEqualsWithDelta(28.0, $workLoad['projected_hours'], 0.01);
        $this->assertEqualsWithDelta(0.0, $workLoad['remaining_hours'], 0.01);
        $shiftDay = collect($workLoad['days'])->firstWhere('date', '2026-09-09');
        $this->assertEqualsWithDelta(1.0, $shiftDay['hours'], 0.01);
        $this->assertEqualsWithDelta(1.0, $shiftDay['actual_hours'], 0.01);
        $this->assertEqualsWithDelta(9.0, $shiftDay['scheduled_hours'], 0.01);
    }

    public function testApprovedMemberAlreadyOverTheLimitIsNotBlockedFromUnrelatedSaves(): void
    {
        $this->giveWorkplaceScopedPermission($this->manager, PermissionName::ShiftEdit, $this->workplace->id);
        $employee = $this->createMember('3001');
        $this->assign($this->shift, $employee);
        foreach (['2026-09-06', '2026-09-07', '2026-09-08'] as $date) {
            $this->assign($this->createShift($date), $employee);
        }

        $workLoad = $this->fetch([$employee->id])
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($workLoad['is_blocked']);
        $this->assertSame('over', $workLoad['status']);
        $this->assertEqualsWithDelta(36.0, $workLoad['projected_hours'], 0.01);
        $this->saveApproved([$employee->id])->assertOk();
    }

    public function testCandidateWithoutScheduleShowsThisShiftInTheDailyBreakdown(): void
    {
        $candidate = $this->createMember();

        $workLoad = $this->fetch([$candidate->id])
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($workLoad['is_shift_counted']);
        $this->assertEqualsWithDelta(0.0, $workLoad['weekly_hours'], 0.01);
        $this->assertEqualsWithDelta(9.0, $workLoad['projected_hours'], 0.01);
        $this->assertEqualsWithDelta(
            $workLoad['projected_hours'],
            array_sum(array_column($workLoad['days'], 'hours')),
            0.01
        );
        $shiftDay = collect($workLoad['days'])->firstWhere('date', '2026-09-09');
        $this->assertEqualsWithDelta(9.0, $shiftDay['hours'], 0.01);
        $this->assertEqualsWithDelta(9.0, $shiftDay['scheduled_hours'], 0.01);
    }

    public function testAbsentMemberIsProjectedLikeANewAssignment(): void
    {
        $employee = $this->createMember();
        $this->assign($this->shift, $employee, isAbsent: true);

        $workLoad = $this->fetch([$employee->id])
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($workLoad['is_shift_counted']);
        $this->assertEqualsWithDelta(0.0, $workLoad['weekly_hours'], 0.01);
        $this->assertEqualsWithDelta(9.0, $workLoad['projected_hours'], 0.01);
        $this->assertSame('normal', $workLoad['status']);
    }

    public function testWorkplaceMemberCandidateUsesTheirCustomLimitForTheShiftDate(): void
    {
        $candidate = $this->createMember();
        UserConfig::create([
            'user_id' => $candidate->id,
            'max_shift_per_day' => 1,
            'max_hour_per_week' => 40,
            'max_hour_per_week_start_date' => '2026-09-01',
            'max_hour_per_week_expiry_date' => '2026-09-30',
        ]);
        $this->assign($this->createShift('2026-09-08'), $candidate);

        $workLoad = $this->fetch([$candidate->id])
            ->assertOk()
            ->json('data.0');

        $this->assertEqualsWithDelta(18.0, $workLoad['projected_hours'], 0.01);
        $this->assertSame(40, $workLoad['max_hour_per_week']);
        $this->assertTrue($workLoad['is_custom_limit']);
        $this->assertSame('2026-09-01', $workLoad['limit_start_date']);
        $this->assertSame('2026-09-30', $workLoad['limit_expiry_date']);
    }

    public function testExpiredCustomLimitFallsBackToTheDefault(): void
    {
        $candidate = $this->createMember();
        UserConfig::create([
            'user_id' => $candidate->id,
            'max_shift_per_day' => 1,
            'max_hour_per_week' => 40,
            'max_hour_per_week_expiry_date' => '2026-09-08',
        ]);

        $workLoad = $this->fetch([$candidate->id])
            ->assertOk()
            ->json('data.0');

        $this->assertSame(28, $workLoad['max_hour_per_week']);
        $this->assertFalse($workLoad['is_custom_limit']);
        $this->assertNull($workLoad['limit_expiry_date']);
    }

    public function testIgnoresUsersOutsideTheShiftAndItsWorkplace(): void
    {
        $member = $this->createMember();
        $outsider = User::factory()->create();

        $userIds = $this->collectUserIds($this->fetch([$member->id, $outsider->id])->assertOk());

        $this->assertSame([$member->id], $userIds->all());
    }

    public function testForbidsManagerWithoutAccessToTheShiftWorkplace(): void
    {
        $otherManager = User::factory()->create();
        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->giveWorkplaceScopedPermission($otherManager, PermissionName::ShiftList, $otherWorkplace->id);

        $this->fetch([$this->createMember()->id], $otherManager)->assertStatus(403);
    }

    public function testRequiresUserIds(): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson("/manage/shifts/{$this->shift->id}/weekly-work-load")
            ->assertStatus(422);
    }

    /**
     * @param list<int> $userIds
     */
    private function fetch(array $userIds, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager, 'api')->postJson(
            "/manage/shifts/{$this->shift->id}/weekly-work-load",
            ['user_ids' => $userIds]
        );
    }

    /**
     * @return Collection<int, int>
     */
    private function collectUserIds(TestResponse $response): Collection
    {
        return collect($response->json('data'))->pluck('user_id');
    }

    private function createMember(?string $staffNo = null): User
    {
        $user = User::factory()->create(['staff_no' => $staffNo]);
        $user->info->update([
            'residence_expiry_date_1' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        $this->workplace->users()->attach($user->id);

        return $user;
    }

    /**
     * @param list<int> $approvedUserIds
     * @param list<int> $pendingUserIds
     */
    private function saveApproved(array $approvedUserIds, array $pendingUserIds = []): TestResponse
    {
        return $this->actingAs($this->manager, 'api')->putJson("/manage/shifts/{$this->shift->id}", [
            'fixed_total' => 0,
            'temp_total' => 10,
            'fixed' => ['pending_ids' => [], 'rejected_ids' => [], 'approved_ids' => []],
            'temp' => [
                'pending_ids' => $pendingUserIds,
                'rejected_ids' => [],
                'approved_ids' => [$this->position->id => $approvedUserIds],
            ],
        ]);
    }

    private function createShift(string $date): Shift
    {
        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 10,
            'fixed_total' => 0,
        ]);
    }

    private function assign(
        Shift $shift,
        User $employee,
        ShiftUserStatus $status = ShiftUserStatus::Approved,
        bool $isAbsent = false
    ): ShiftUser {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $this->position->id,
            'status' => $status->value,
            'is_absent' => $isAbsent,
        ]);
    }
}
