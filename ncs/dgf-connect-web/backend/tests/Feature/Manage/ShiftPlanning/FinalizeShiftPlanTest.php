<?php

namespace Tests\Feature\Manage\ShiftPlanning;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Events\ShiftUserApproved;
use App\Events\ShiftUserRejected;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FinalizeShiftPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $amType;

    private WorkplaceShift $pmType;

    private WorkplacePosition $position;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-18 08:00:00');

        $this->manager = User::factory()->create();
        foreach ([PermissionName::ShiftList, PermissionName::ShiftCreate, PermissionName::ShiftEdit] as $name) {
            $permission = Permission::create(['name' => $name->value, 'display_name' => $name->value]);
            $this->manager->givePermission($permission, null);
        }

        $this->workplace = Workplace::create([
            'name' => 'Planner WP',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'min_rest_hours' => 9,
        ]);

        $this->amType = $this->shiftType('09:00:00', '13:00:00');
        $this->pmType = $this->shiftType('14:00:00', '18:00:00');
        $this->position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);

        app(ShiftPlanningLockService::class)->acquire($this->workplace->id, $this->manager->id, $this->manager->name);
    }

    protected function tearDown(): void
    {
        $lockService = app(ShiftPlanningLockService::class);
        $lock = $lockService->get($this->workplace->id);
        if ($lock) {
            $lockService->release($this->workplace->id, $lock['user_id']);
        }
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testFinalizeApprovesAmAndPmOnSameDate(): void
    {
        Event::fake([ShiftUserApproved::class, ShiftUserRejected::class]);

        $member = $this->createMember('99001');
        $amShift = $this->createShift($this->amType, '2026-06-22', tempTotal: 5);
        $pmShift = $this->createShift($this->pmType, '2026-06-22', tempTotal: 5);
        $this->createDraft('2026-06-22', $this->amType, $member);
        $this->createDraft('2026-06-22', $this->pmType, $member);

        $response = $this->finalize([['user_id' => $member->id, 'date' => '2026-06-22']]);

        $response->assertOk();
        $results = $response->json('data.results');
        $this->assertTrue($results[0]['committed']);
        $this->assertSame(2, $results[0]['notified_count']);

        foreach ([$amShift, $pmShift] as $shift) {
            $this->assertDatabaseHas('shift_users', [
                'shift_id' => $shift->id,
                'user_id' => $member->id,
                'status' => ShiftUserStatus::Approved->value,
            ]);
        }
        $this->assertDatabaseCount('shift_plan_drafts', 0);
        Event::assertDispatchedTimes(ShiftUserApproved::class, 2);
    }

    public function testFinalizeCommitsOnlySelectedCellsOnSharedDate(): void
    {
        Event::fake([ShiftUserApproved::class, ShiftUserRejected::class]);

        $selected = $this->createMember('99011');
        $other = $this->createMember('99012');
        $shift = $this->createShift($this->amType, '2026-06-22', tempTotal: 5);
        $this->createDraft('2026-06-22', $this->amType, $selected);
        $this->createDraft('2026-06-22', $this->amType, $other);

        // Only the selected member's cell is finalized, even though both share the date.
        $response = $this->finalize([['user_id' => $selected->id, 'date' => '2026-06-22']]);

        $response->assertOk();
        $this->assertTrue($response->json('data.results')[0]['committed']);
        $this->assertSame(1, $response->json('data.results')[0]['notified_count']);

        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $selected->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $other->id,
        ]);
        // The selected cell's draft is cleaned up; the unselected one stays a draft.
        $this->assertDatabaseMissing('shift_plan_drafts', ['user_id' => $selected->id]);
        $this->assertDatabaseHas('shift_plan_drafts', ['user_id' => $other->id, 'date' => '2026-06-22']);
        Event::assertDispatchedTimes(ShiftUserApproved::class, 1);
    }

    public function testFinalizeCommitsMultipleDatesIndependently(): void
    {
        $member = $this->createMember('99002');
        $this->createShift($this->amType, '2026-06-22', tempTotal: 5);
        $this->createShift($this->amType, '2026-06-23', tempTotal: 5);
        $this->createDraft('2026-06-22', $this->amType, $member);
        $this->createDraft('2026-06-23', $this->amType, $member);

        $response = $this->finalize([
            ['user_id' => $member->id, 'date' => '2026-06-22'],
            ['user_id' => $member->id, 'date' => '2026-06-23'],
        ]);

        $response->assertOk();
        $results = collect($response->json('data.results'));
        $this->assertCount(2, $results);
        $this->assertTrue($results->every(fn($r) => $r['committed'] === true));
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testFinalizeCommitsAddsAndIgnoresRemoveDrafts(): void
    {
        Event::fake([ShiftUserApproved::class, ShiftUserRejected::class]);

        $member = $this->createMember('99003');
        $amShift = $this->createShift($this->amType, '2026-06-22', tempTotal: 5);
        $pmShift = $this->createShift($this->pmType, '2026-06-22', tempTotal: 5);
        // Pre-existing live AM approval. Removals take effect at Save, not Finalize, so even a
        // stray Remove draft must leave this live row untouched.
        ShiftUser::create([
            'shift_id' => $amShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->createDraft('2026-06-22', $this->amType, $member, ShiftPlanDraftAction::Remove);
        $this->createDraft('2026-06-22', $this->pmType, $member, ShiftPlanDraftAction::Add);

        $response = $this->finalize([['user_id' => $member->id, 'date' => '2026-06-22']]);

        $response->assertOk();
        $this->assertTrue($response->json('data.results')[0]['committed']);
        // The Remove draft is ignored: the live AM row stays Approved and no rejection fires.
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $amShift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        // The Add draft commits to live.
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $pmShift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        // Both drafts for the date are cleaned up regardless of action.
        $this->assertDatabaseCount('shift_plan_drafts', 0);
        Event::assertNotDispatched(ShiftUserRejected::class);
        Event::assertDispatched(ShiftUserApproved::class);
    }

    public function testFinalizeAppliesUpdateDraftToExistingLiveRow(): void
    {
        Event::fake([ShiftUserApproved::class, ShiftUserRejected::class]);

        $member = $this->createMember('99009');
        $shift = $this->createShift($this->amType, '2026-06-22', fixedTotal: 1, tempTotal: 5);
        $newPosition = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Leader']);
        $live = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->createDraft(
            '2026-06-22',
            $this->amType,
            $member,
            ShiftPlanDraftAction::Update,
            true,
            $newPosition->id,
            $live->id
        );

        $response = $this->finalize([['user_id' => $member->id, 'date' => '2026-06-22']]);

        $response->assertOk();
        $this->assertTrue($response->json('data.results')[0]['committed']);
        $this->assertSame(0, $response->json('data.results')[0]['notified_count']);
        $this->assertDatabaseHas('shift_users', [
            'id' => $live->id,
            'shift_id' => $shift->id,
            'workplace_position_id' => $newPosition->id,
            'is_fixed' => true,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
        Event::assertNotDispatched(ShiftUserApproved::class);
        Event::assertNotDispatched(ShiftUserRejected::class);
    }

    public function testFinalizeMovesUpdateDraftInPlaceWithoutNotifications(): void
    {
        Event::fake([ShiftUserApproved::class, ShiftUserRejected::class]);

        $member = $this->createMember('99010');
        $amShift = $this->createShift($this->amType, '2026-06-22', tempTotal: 5);
        $pmShift = $this->createShift($this->pmType, '2026-06-22', tempTotal: 5);
        $live = ShiftUser::create([
            'shift_id' => $amShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->createDraft(
            '2026-06-22',
            $this->pmType,
            $member,
            ShiftPlanDraftAction::Update,
            false,
            $this->position->id,
            $live->id
        );

        $response = $this->finalize([['user_id' => $member->id, 'date' => '2026-06-22']]);

        $response->assertOk();
        $this->assertTrue($response->json('data.results')[0]['committed']);
        $this->assertSame(0, $response->json('data.results')[0]['notified_count']);
        $this->assertDatabaseCount('shift_users', 1);
        $this->assertDatabaseHas('shift_users', [
            'id' => $live->id,
            'shift_id' => $pmShift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $amShift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Rejected->value,
        ]);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
        Event::assertNotDispatched(ShiftUserApproved::class);
        Event::assertNotDispatched(ShiftUserRejected::class);
    }

    public function testFinalizeBlocksNightShiftAdjacentToDayShift(): void
    {
        $member = $this->createMember('99004');
        $nightType = $this->shiftType('22:00:00', '05:00:00');
        $dayType = $this->shiftType('09:00:00', '18:00:00');

        // Pre-existing live night shift the evening before the planned day shift.
        $nightShift = $this->createShift($nightType, '2026-06-21', tempTotal: 5);
        ShiftUser::create([
            'shift_id' => $nightShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $dayShift = $this->createShift($dayType, '2026-06-22', tempTotal: 5);
        $this->createDraft('2026-06-22', $dayType, $member);

        $response = $this->finalize([['user_id' => $member->id, 'date' => '2026-06-22']]);

        $response->assertOk();
        $result = $response->json('data.results')[0];
        $this->assertFalse($result['committed']);
        $this->assertSame(ErrorCode::ScheduleMinRestHoursViolated->value, $result['errors'][0]['code']);
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $dayShift->id,
            'user_id' => $member->id,
        ]);
    }

    public function testFinalizeReportsCapacitySplitAndCommitsNothing(): void
    {
        $memberA = $this->createMember('99005');
        $memberB = $this->createMember('99006');
        $shift = $this->createShift($this->amType, '2026-06-22', tempTotal: 1);
        // memberA already holds the only temp slot live.
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $memberA->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->createDraft('2026-06-22', $this->amType, $memberB);

        $response = $this->finalize([['user_id' => $memberB->id, 'date' => '2026-06-22']]);

        $response->assertOk();
        $result = $response->json('data.results')[0];
        $this->assertFalse($result['committed']);
        $error = $result['errors'][0];
        $this->assertSame(ErrorCode::ScheduleCapacityExceeded->value, $error['code']);
        $this->assertSame(1, $error['extra']['pre_existing']);
        $this->assertSame(1, $error['extra']['planner']);
        $this->assertSame(1, $error['extra']['capacity']);
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $memberB->id,
        ]);
    }

    public function testFinalizeSkipsUserLockedAfterThePreCheckReadTheAccount(): void
    {
        Event::fake([ShiftUserApproved::class, ShiftUserRejected::class]);

        $member = $this->createMember('99020');
        $shift = $this->createShift($this->amType, '2026-06-22', tempTotal: 5);
        $this->createDraft('2026-06-22', $this->amType, $member);
        $this->lockAccountAfterFirstRead($member);

        $response = $this->finalize([['user_id' => $member->id, 'date' => '2026-06-22']]);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.results')[0]['notified_count']);
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $member->id,
        ]);
        Event::assertNotDispatched(ShiftUserApproved::class);
    }

    public function testFinalizeRejectsDateBeforeMinimumWindow(): void
    {
        $member = $this->createMember('99007');
        $this->createShift($this->amType, '2026-06-19', tempTotal: 5);
        // A draft on a too-soon date (today + 1).
        ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-19',
            'workplace_shift_id' => $this->amType->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Add->value,
        ]);

        $response = $this->finalize([['user_id' => $member->id, 'date' => '2026-06-19']]);

        $response->assertOk();
        $result = $response->json('data.results')[0];
        $this->assertFalse($result['committed']);
        $this->assertSame(ErrorCode::ShiftPlanningDateTooSoon->value, $result['errors'][0]['code']);
    }

    public function testFinalizeRequiresLock(): void
    {
        app(ShiftPlanningLockService::class)->release($this->workplace->id, $this->manager->id);
        $member = $this->createMember('99008');
        $this->createShift($this->amType, '2026-06-22', tempTotal: 5);
        $this->createDraft('2026-06-22', $this->amType, $member);

        $response = $this->finalize([['user_id' => $member->id, 'date' => '2026-06-22']]);

        $response->assertStatus(423);
        $response->assertJsonPath('error', ErrorCode::ShiftPlanningLockRequired->value);
    }

    public function testFinalizeDeniedWithoutShiftPermissions(): void
    {
        // A member of the workplace who holds neither shift.create nor shift.edit must get a
        // clean 403 — the permission gate previously referenced a non-existent ErrorCode case
        // and would have fatally errored instead of returning Forbidden.
        $outsider = $this->createMember('99020');
        app(ShiftPlanningLockService::class)->release($this->workplace->id, $this->manager->id);
        app(ShiftPlanningLockService::class)->acquire($this->workplace->id, $outsider->id, $outsider->name);

        $member = $this->createMember('99021');
        $this->createShift($this->amType, '2026-06-22', tempTotal: 5);
        $this->createDraft('2026-06-22', $this->amType, $member);

        $response = $this->actingAs($outsider, 'api')->postJson('/manage/shift-planning/finalize', [
            'workplace_id' => $this->workplace->id,
            'cells' => [['user_id' => $member->id, 'date' => '2026-06-22']],
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', ErrorCode::Forbidden->value);
        $this->assertDatabaseHas('shift_plan_drafts', [
            'workplace_id' => $this->workplace->id,
            'user_id' => $member->id,
            'date' => '2026-06-22',
        ]);
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @param list<array{user_id:int, date:string}> $cells
     */
    private function finalize(array $cells): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager, 'api')->postJson('/manage/shift-planning/finalize', [
            'workplace_id' => $this->workplace->id,
            'cells' => $cells,
        ]);
    }

    private function shiftType(string $start, string $end): WorkplaceShift
    {
        return WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $start,
            'end_time' => $end,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'is_active' => true,
        ]);
    }

    private function lockAccountAfterFirstRead(User $member): void
    {
        $locked = false;
        User::retrieved(function (User $retrieved) use ($member, &$locked) {
            if ($locked || $retrieved->id !== $member->id) {
                return;
            }
            $locked = true;
            DB::table('users')
                ->where('id', $member->id)
                ->update(['is_disabled' => true]);
        });
    }

    private function createMember(string $staffNo): User
    {
        $member = User::factory()->create(['staff_no' => $staffNo]);
        $member->info->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);

        return $member;
    }

    private function createShift(WorkplaceShift $type, string $date, int $fixedTotal = 0, int $tempTotal = 5): Shift
    {
        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $type->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => $fixedTotal,
            'temp_total' => $tempTotal,
        ]);
    }

    private function createDraft(
        string $date,
        WorkplaceShift $type,
        User $user,
        ShiftPlanDraftAction $action = ShiftPlanDraftAction::Add,
        bool $isFixed = false,
        ?int $positionId = null,
        ?int $shiftUserId = null
    ): ShiftPlanDraft {
        return ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => $date,
            'workplace_shift_id' => $type->id,
            'user_id' => $user->id,
            'shift_user_id' => $shiftUserId,
            'workplace_position_id' => $positionId ?? $this->position->id,
            'is_fixed' => $isFixed,
            'action' => $action->value,
            'created_by' => $this->manager->id,
        ]);
    }
}
