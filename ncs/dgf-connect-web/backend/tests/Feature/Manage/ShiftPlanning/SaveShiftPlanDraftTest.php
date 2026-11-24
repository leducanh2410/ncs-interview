<?php

namespace Tests\Feature\Manage\ShiftPlanning;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
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
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Covers the Shift Planner save path: PUT /manage/shift-planning/draft ->
 * SaveShiftPlanDraftAction. Verifies permission + lock gating; the window-scoped reconciliation
 * of `add` rows into silent draft storage (upsert by (date|workplace_shift_id|user_id), update
 * in place, delete stale rows inside the window, preserve drafts paged out of view); that `update`
 * rows stage edits to existing live ShiftUsers without self-overlap; that `remove` rows take
 * effect on live data immediately (reject the live ShiftUser, delete its CheckIn, dispatch
 * ShiftUserRejected) rather than persisting as drafts; that an `add` onto a bare date auto-creates
 * the live Shift (max_registered_temp = 0, totals capped by the copied target totals) for active types only;
 * and the set-aware validation gate that blocks a save — rolling back both the draft changes and
 * the live removals — when any add/update in the window fails validation.
 */
class SaveShiftPlanDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $amType;

    private WorkplaceShift $pmType;

    private WorkplacePosition $position;

    /** Window used by every save call. */
    private string $from = '2026-06-20';

    private string $to = '2026-06-30';

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
        // Release whoever currently holds the advisory lock (some tests hand it to another user),
        // so the array-cached lock can't leak into the next test in this process.
        $lockService = app(ShiftPlanningLockService::class);
        $lock = $lockService->get($this->workplace->id);
        if ($lock) {
            $lockService->release($this->workplace->id, $lock['user_id']);
        }
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testPermissionDeniedWithoutShiftEdit(): void
    {
        $unauthorized = User::factory()->create();

        $response = $this->actingAs($unauthorized, 'api')->putJson('/manage/shift-planning/draft', [
            'workplace_id' => $this->workplace->id,
            'from' => $this->from,
            'to' => $this->to,
            'drafts' => [],
        ]);

        $response->assertStatus(403);
    }

    public function testBlocksSaveWhenAnotherAdminHoldsLock(): void
    {
        $other = User::factory()->create();
        app(ShiftPlanningLockService::class)->release($this->workplace->id, $this->manager->id);
        app(ShiftPlanningLockService::class)->acquire($this->workplace->id, $other->id, $other->name);

        $response = $this->save([]);

        $response->assertStatus(423);
        $response->assertJsonPath('error', ErrorCode::ShiftPlanningLockRequired->value);

        app(ShiftPlanningLockService::class)->release($this->workplace->id, $other->id);
    }

    public function testReacquiresFreeLockOnSaveWhenOwnLockLapsed(): void
    {
        // The advisory lock frees itself on idle; a sole editor whose lock lapsed must
        // still be able to save, transparently re-taking the now-free lock.
        app(ShiftPlanningLockService::class)->release($this->workplace->id, $this->manager->id);

        $response = $this->save([]);

        $response->assertOk();
        $this->assertTrue(app(ShiftPlanningLockService::class)->isHeldBy($this->workplace->id, $this->manager->id));
    }

    public function testCreatesNewDraftRows(): void
    {
        $memberA = $this->createMember('97001');
        $memberB = $this->createMember('97002');
        // Draft additions only validate (and persist) when a concrete shift exists.
        $this->createShift($this->amType, '2026-06-22');
        $this->createShift($this->pmType, '2026-06-22');

        $response = $this->save([
            $this->row('2026-06-22', $this->amType->id, $memberA->id, ShiftPlanDraftAction::Add),
            $this->row('2026-06-22', $this->pmType->id, $memberB->id, ShiftPlanDraftAction::Add),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.saved', 2);
        $this->assertDatabaseCount('shift_plan_drafts', 2);
        $this->assertDatabaseHas('shift_plan_drafts', [
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-22',
            'workplace_shift_id' => $this->amType->id,
            'user_id' => $memberA->id,
            'action' => ShiftPlanDraftAction::Add->value,
        ]);
    }

    public function testUpdatesExistingDraftInPlaceWithoutDuplicating(): void
    {
        $member = $this->createMember('97003');
        $this->createShift($this->amType, '2026-06-22', fixedTotal: 1);
        $existing = ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-22',
            'workplace_shift_id' => $this->amType->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Add->value,
            'created_by' => $this->manager->id,
        ]);

        // Same key, is_fixed flipped -> same row, mutated in place (no duplicate).
        $response = $this->save([
            [
                'date' => '2026-06-22',
                'workplace_shift_id' => $this->amType->id,
                'user_id' => $member->id,
                'workplace_position_id' => $this->position->id,
                'is_fixed' => true,
                'action' => ShiftPlanDraftAction::Add->value,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.saved', 1);
        $this->assertDatabaseCount('shift_plan_drafts', 1);
        // Same row, mutated in place (is_fixed/updated_by changed; created_by kept).
        $this->assertDatabaseHas('shift_plan_drafts', [
            'id' => $existing->id,
            'workplace_shift_id' => $this->amType->id,
            'user_id' => $member->id,
            'is_fixed' => true,
            'action' => ShiftPlanDraftAction::Add->value,
            'updated_by' => $this->manager->id,
            'created_by' => $this->manager->id,
        ]);
    }

    public function testDeletesStaleRowsInWindowNotInPayload(): void
    {
        $memberA = $this->createMember('97004');
        $memberB = $this->createMember('97005');
        $this->createShift($this->amType, '2026-06-22');
        ShiftPlanDraft::create($this->draftAttrs('2026-06-22', $this->amType->id, $memberA->id));
        $stale = ShiftPlanDraft::create($this->draftAttrs('2026-06-23', $this->amType->id, $memberB->id));

        // Re-send only memberA's row: memberB's row falls out and must be deleted.
        $response = $this->save([$this->row('2026-06-22', $this->amType->id, $memberA->id, ShiftPlanDraftAction::Add)]);

        $response->assertOk();
        $this->assertDatabaseMissing('shift_plan_drafts', ['id' => $stale->id]);
        $this->assertDatabaseCount('shift_plan_drafts', 1);
    }

    public function testPreservesDraftsOutsideWindow(): void
    {
        $member = $this->createMember('97006');
        // A draft paged out of view (outside [from, to]) must survive reconciliation.
        $outOfWindow = ShiftPlanDraft::create($this->draftAttrs('2026-07-15', $this->amType->id, $member->id));

        $response = $this->save([]);

        $response->assertOk();
        $response->assertJsonPath('data.saved', 0);
        $this->assertDatabaseHas('shift_plan_drafts', ['id' => $outOfWindow->id]);
    }

    public function testBlocksSaveWhenCapacityExceededAndPersistsNothing(): void
    {
        $memberA = $this->createMember('97007');
        $memberB = $this->createMember('97008');
        $shift = $this->createShift($this->amType, '2026-06-22', tempTotal: 1);
        // memberA already holds the only temp slot live.
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $memberA->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        // Staging memberB into the full slot must be rejected before anything persists.
        $response = $this->save([$this->row('2026-06-22', $this->amType->id, $memberB->id, ShiftPlanDraftAction::Add)]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $response->assertJsonPath('data.errors.0.code', ErrorCode::ScheduleCapacityExceeded->value);
        // Blocking save rolls back: the draft never persisted.
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testAutoCreatesMissingShiftForActiveTypeOnAdd(): void
    {
        $memberA = $this->createMember('97009');
        $memberB = $this->createMember('97021');
        // No concrete Shift exists for 06-22/amType: saving an add materializes one rather than
        // blocking. max_registered_temp = 0 keeps employees from self-registering into it, and the
        // totals are capped at min(placed count, copied target total).
        $response = $this->save([
            $this->row('2026-06-22', $this->amType->id, $memberA->id, ShiftPlanDraftAction::Add, targetTempTotal: 3),
            $this->row(
                '2026-06-22',
                $this->amType->id,
                $memberB->id,
                ShiftPlanDraftAction::Add,
                isFixed: true,
                targetFixedTotal: 3
            ),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.saved', 2);
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseHas('shifts', [
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-22',
            'workplace_shift_id' => $this->amType->id,
            'creator_id' => $this->manager->id,
            'temp_total' => 1,
            'fixed_total' => 1,
            'max_registered_temp' => 0,
        ]);
        $this->assertDatabaseCount('shift_plan_drafts', 2);
    }

    public function testAutoCreatedMissingShiftTotalsUseLowerTargetTotalAsCapacity(): void
    {
        $memberA = $this->createMember('97024');
        $memberB = $this->createMember('97025');

        $response = $this->save([
            $this->row('2026-06-22', $this->amType->id, $memberA->id, ShiftPlanDraftAction::Add, targetTempTotal: 1),
            $this->row('2026-06-22', $this->amType->id, $memberB->id, ShiftPlanDraftAction::Add, targetTempTotal: 1),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', ErrorCode::ScheduleCapacityExceeded->value);
        $response->assertJsonPath('data.errors.0.extra.capacity', 1);
        $response->assertJsonPath('data.errors.0.extra.projected', 2);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testDoesNotAutoCreateShiftForInactiveType(): void
    {
        $member = $this->createMember('97022');
        $inactive = $this->shiftType('19:00:00', '22:00:00', isActive: false);

        // An inactive type can't be created, so the missing-shift error still stands.
        $response = $this->save([$this->row('2026-06-22', $inactive->id, $member->id, ShiftPlanDraftAction::Add)]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', ErrorCode::ShiftPlanningShiftMissing->value);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testAddRequiringAutoCreateIsDeniedWithoutShiftCreatePermission(): void
    {
        // shift.edit alone reaches this endpoint, but auto-creating a concrete Shift requires
        // shift.create. An add onto a bare date (no concrete shift) must be rejected with 403 and
        // persist nothing, rather than silently materializing a Shift the user can't create.
        $editor = $this->editorWithoutShiftCreate();
        $member = $this->createMember('97040');

        $response = $this->actingAs($editor, 'api')->putJson('/manage/shift-planning/draft', [
            'workplace_id' => $this->workplace->id,
            'from' => $this->from,
            'to' => $this->to,
            'drafts' => [$this->row('2026-06-22', $this->amType->id, $member->id, ShiftPlanDraftAction::Add)],
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', ErrorCode::Forbidden->value);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testAddOntoExistingShiftSucceedsWithoutShiftCreatePermission(): void
    {
        // No auto-create needed when the slot already has a concrete Shift, so shift.edit suffices.
        $editor = $this->editorWithoutShiftCreate();
        $member = $this->createMember('97041');
        $this->createShift($this->amType, '2026-06-22');

        $response = $this->actingAs($editor, 'api')->putJson('/manage/shift-planning/draft', [
            'workplace_id' => $this->workplace->id,
            'from' => $this->from,
            'to' => $this->to,
            'drafts' => [$this->row('2026-06-22', $this->amType->id, $member->id, ShiftPlanDraftAction::Add)],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('shift_plan_drafts', 1);
    }

    public function testDoesNotDuplicateOrOverwriteExistingShiftOnAdd(): void
    {
        $member = $this->createMember('97023');
        $existing = $this->createShift($this->amType, '2026-06-22', tempTotal: 5);

        $response = $this->save([$this->row('2026-06-22', $this->amType->id, $member->id, ShiftPlanDraftAction::Add)]);

        $response->assertOk();
        // The existing shift is reused untouched — not duplicated, and its capacity not reset.
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseHas('shifts', ['id' => $existing->id, 'temp_total' => 5]);
    }

    public function testLiveUpdateSavesAsDraftWithoutSelfOverlap(): void
    {
        $member = $this->createMember('97016');
        $shift = $this->createShift($this->amType, '2026-06-22', fixedTotal: 1);
        $newPosition = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Leader']);
        $live = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $response = $this->save([
            $this->row(
                '2026-06-22',
                $this->amType->id,
                $member->id,
                ShiftPlanDraftAction::Update,
                $live->id,
                $newPosition->id,
                true
            ),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shift_plan_drafts', [
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-22',
            'workplace_shift_id' => $this->amType->id,
            'user_id' => $member->id,
            'shift_user_id' => $live->id,
            'workplace_position_id' => $newPosition->id,
            'is_fixed' => true,
            'action' => ShiftPlanDraftAction::Update->value,
        ]);
        $this->assertDatabaseHas('shift_users', [
            'id' => $live->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    public function testLiveUpdateMustReferenceLiveRowOnSameDate(): void
    {
        $member = $this->createMember('97018');
        $sourceShift = $this->createShift($this->amType, '2026-06-23');
        $this->createShift($this->amType, '2026-06-22');
        $live = ShiftUser::create([
            'shift_id' => $sourceShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $response = $this->save([
            $this->row('2026-06-22', $this->amType->id, $member->id, ShiftPlanDraftAction::Update, $live->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', ErrorCode::ShiftUserNotFound->value);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testLiveUpdateCannotTargetExistingShiftUserRow(): void
    {
        $member = $this->createMember('97019');
        $sourceShift = $this->createShift($this->amType, '2026-06-22');
        $targetShift = $this->createShift($this->pmType, '2026-06-22');
        $source = ShiftUser::create([
            'shift_id' => $sourceShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        ShiftUser::create([
            'shift_id' => $targetShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $response = $this->save([
            $this->row('2026-06-22', $this->pmType->id, $member->id, ShiftPlanDraftAction::Update, $source->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', ErrorCode::ScheduleShiftOverlap->value);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testRemovalRejectsLiveRowAndDeletesCheckInImmediately(): void
    {
        Event::fake([ShiftUserRejected::class]);

        $member = $this->createMember('97010');
        $shift = $this->createShift($this->amType, '2026-06-22');
        $live = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        CheckIn::create([
            'shift_user_id' => $live->id,
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'check_in_time' => '2026-06-22 09:05:00',
        ]);

        // A removal takes effect on live data at save — it is not stored as a draft.
        $response = $this->save([
            $this->row('2026-06-22', $this->amType->id, $member->id, ShiftPlanDraftAction::Remove),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'id' => $live->id,
            'status' => ShiftUserStatus::Rejected->value,
        ]);
        $this->assertDatabaseMissing('check_ins', ['shift_id' => $shift->id, 'user_id' => $member->id]);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
        Event::assertDispatched(ShiftUserRejected::class);
    }

    public function testRemovalBeforeMinimumPlanningDateStillMutatesLiveRow(): void
    {
        Event::fake([ShiftUserRejected::class]);

        $member = $this->createMember('97017');
        $shift = $this->createShift($this->amType, '2026-06-19');
        $live = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $response = $this->save([
            $this->row('2026-06-19', $this->amType->id, $member->id, ShiftPlanDraftAction::Remove, $live->id),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'id' => $live->id,
            'status' => ShiftUserStatus::Rejected->value,
        ]);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
        Event::assertDispatched(ShiftUserRejected::class);
    }

    public function testAddBeforeMinimumPlanningDateIsBlockedBeforePersistingDraft(): void
    {
        $member = $this->createMember('97020');
        $this->createShift($this->amType, '2026-06-19');

        $response = $this->save([$this->row('2026-06-19', $this->amType->id, $member->id, ShiftPlanDraftAction::Add)]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', ErrorCode::ShiftPlanningDateTooSoon->value);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testRemovalWithNoMatchingLiveRowIsNoop(): void
    {
        Event::fake([ShiftUserRejected::class]);

        $member = $this->createMember('97011');
        // No live ShiftUser (and no concrete shift) exists: the removal is a harmless no-op.
        $response = $this->save([
            $this->row('2026-06-22', $this->amType->id, $member->id, ShiftPlanDraftAction::Remove),
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('shift_plan_drafts', 0);
        $this->assertDatabaseCount('shift_users', 0);
        Event::assertNotDispatched(ShiftUserRejected::class);
    }

    public function testFailedValidationRollsBackLiveRemoval(): void
    {
        $memberA = $this->createMember('97012');
        $memberB = $this->createMember('97013');
        $shift = $this->createShift($this->amType, '2026-06-22');
        $inactive = $this->shiftType('19:00:00', '22:00:00', isActive: false);
        $live = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $memberA->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        // memberA's removal is valid, but memberB's add targets an inactive type with no concrete
        // shift (and none can be auto-created) -> the whole save rolls back, including the removal.
        $response = $this->save([
            $this->row('2026-06-22', $this->amType->id, $memberA->id, ShiftPlanDraftAction::Remove),
            $this->row('2026-06-24', $inactive->id, $memberB->id, ShiftPlanDraftAction::Add),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', ErrorCode::ShiftPlanningShiftMissing->value);
        // Atomic: the live row is restored, so the removal never took effect. (ShiftUserRejected
        // dispatches after commit, so the rolled-back transaction also fires no notification.)
        $this->assertDatabaseHas('shift_users', [
            'id' => $live->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    public function testValidatesEveryAddDateAndRollsBackAcrossDates(): void
    {
        $memberA = $this->createMember('97014');
        $memberB = $this->createMember('97015');
        // 06-22 add is valid; 06-24 add targets an inactive type whose shift can't be auto-created
        // -> invalid. The full resulting add set is validated, so the valid 06-22 add rolls back too.
        $this->createShift($this->amType, '2026-06-22');
        $inactive = $this->shiftType('19:00:00', '22:00:00', isActive: false);

        $response = $this->save([
            $this->row('2026-06-22', $this->amType->id, $memberA->id, ShiftPlanDraftAction::Add),
            $this->row('2026-06-24', $inactive->id, $memberB->id, ShiftPlanDraftAction::Add),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', ErrorCode::ShiftPlanningShiftMissing->value);
        $this->assertDatabaseCount('shift_plan_drafts', 0);
    }

    // ---- helpers ----------------------------------------------------------

    private function save(array $drafts): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager, 'api')->putJson('/manage/shift-planning/draft', [
            'workplace_id' => $this->workplace->id,
            'from' => $this->from,
            'to' => $this->to,
            'drafts' => $drafts,
        ]);
    }

    private function row(
        string $date,
        int $shiftId,
        int $userId,
        ShiftPlanDraftAction $action,
        ?int $shiftUserId = null,
        ?int $positionId = null,
        bool $isFixed = false,
        ?int $targetFixedTotal = null,
        ?int $targetTempTotal = null
    ): array {
        $row = [
            'date' => $date,
            'workplace_shift_id' => $shiftId,
            'user_id' => $userId,
            'shift_user_id' => $shiftUserId,
            'workplace_position_id' => $positionId ?? $this->position->id,
            'is_fixed' => $isFixed,
            'action' => $action->value,
        ];

        if ($targetFixedTotal !== null) {
            $row['target_fixed_total'] = $targetFixedTotal;
        }
        if ($targetTempTotal !== null) {
            $row['target_temp_total'] = $targetTempTotal;
        }

        return $row;
    }

    private function draftAttrs(string $date, int $shiftId, int $userId): array
    {
        return [
            'workplace_id' => $this->workplace->id,
            'date' => $date,
            'workplace_shift_id' => $shiftId,
            'user_id' => $userId,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Add->value,
            'created_by' => $this->manager->id,
        ];
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

    private function shiftType(string $start, string $end, bool $isActive = true): WorkplaceShift
    {
        return WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $start,
            'end_time' => $end,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'is_active' => $isActive,
        ]);
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

    /**
     * An admin with shift.edit but not shift.create, holding the planning lock so saves get past the
     * lock gate and exercise the auto-create permission check.
     */
    private function editorWithoutShiftCreate(): User
    {
        $editor = User::factory()->create();
        $editor->givePermission(Permission::where('name', PermissionName::ShiftEdit->value)->firstOrFail(), null);

        app(ShiftPlanningLockService::class)->release($this->workplace->id, $this->manager->id);
        app(ShiftPlanningLockService::class)->acquire($this->workplace->id, $editor->id, $editor->name);

        return $editor;
    }
}
