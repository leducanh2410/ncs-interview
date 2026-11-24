<?php

namespace Tests\Feature\Manage\ShiftPlanning;

use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ShiftPlanDraftCleanupObserver keeps planner drafts from fighting live rows mutated by
 * OTHER paths (shift-detail write, employee self-registration, another finalize). The cell
 * match (date|workplace_shift_id|user_id) covers add/remove and same-cell update drafts, but a
 * cross-shift `update` draft is stored against its TARGET cell and only references its source
 * live row via shift_user_id. These tests pin that a mutation on the source row clears such a
 * draft, while leaving unrelated drafts untouched.
 */
class ShiftPlanDraftCleanupObserverTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplaceShift $amType;

    private WorkplaceShift $pmType;

    private WorkplacePosition $position;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time so fixture dates sit clear of the planning date gate elsewhere.
        Carbon::setTestNow('2026-06-18 08:00:00');

        $this->manager = User::factory()->create();
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testClearsCrossShiftUpdateDraftWhenSourceRowRejectedByAnotherPath(): void
    {
        $member = $this->createMember('99020');
        $amShift = $this->createShift($this->amType, '2026-06-22');
        $live = ShiftUser::create([
            'shift_id' => $amShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        // Cross-shift update draft: stored against the PM target cell, points at the AM source row.
        $update = ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-22',
            'workplace_shift_id' => $this->pmType->id,
            'user_id' => $member->id,
            'shift_user_id' => $live->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Update->value,
            'created_by' => $this->manager->id,
        ]);

        // Another path rejects the source live row; the draft must be cleared.
        $live->update(['status' => ShiftUserStatus::Rejected->value]);

        $this->assertDatabaseMissing('shift_plan_drafts', ['id' => $update->id]);
    }

    public function testClearsCrossShiftUpdateDraftWhenSourceRowUpdatedByAnotherPath(): void
    {
        $member = $this->createMember('99021');
        $amShift = $this->createShift($this->amType, '2026-06-22');
        $newPosition = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Leader']);
        $live = ShiftUser::create([
            'shift_id' => $amShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $update = ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-22',
            'workplace_shift_id' => $this->pmType->id,
            'user_id' => $member->id,
            'shift_user_id' => $live->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => true,
            'action' => ShiftPlanDraftAction::Update->value,
            'created_by' => $this->manager->id,
        ]);

        // Another path edits the still-approved source row; the draft must be cleared.
        $live->update(['workplace_position_id' => $newPosition->id]);

        $this->assertDatabaseMissing('shift_plan_drafts', ['id' => $update->id]);
    }

    public function testDoesNotClearUnrelatedUpdateDraftWhenAnotherRowMutated(): void
    {
        $memberA = $this->createMember('99022');
        $memberB = $this->createMember('99023');
        $amShift = $this->createShift($this->amType, '2026-06-22');
        $sourceA = ShiftUser::create([
            'shift_id' => $amShift->id,
            'user_id' => $memberA->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $sourceB = ShiftUser::create([
            'shift_id' => $amShift->id,
            'user_id' => $memberB->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        // Update draft pointing at memberA's source row — must NOT be touched by memberB's mutation.
        $update = ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-22',
            'workplace_shift_id' => $this->pmType->id,
            'user_id' => $memberA->id,
            'shift_user_id' => $sourceA->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Update->value,
            'created_by' => $this->manager->id,
        ]);

        $sourceB->update(['status' => ShiftUserStatus::Rejected->value]);

        $this->assertDatabaseHas('shift_plan_drafts', ['id' => $update->id]);
    }

    public function testDoesNotClearCrossShiftUpdateDraftWhenUnrelatedCellMutated(): void
    {
        $member = $this->createMember('99024');
        $amShift = $this->createShift($this->amType, '2026-06-22');
        $live = ShiftUser::create([
            'shift_id' => $amShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $update = ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => '2026-06-22',
            'workplace_shift_id' => $this->pmType->id,
            'user_id' => $member->id,
            'shift_user_id' => $live->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Update->value,
            'created_by' => $this->manager->id,
        ]);
        // An entirely separate live row on a different date — mutating it must leave the draft alone.
        $otherShift = $this->createShift($this->amType, '2026-06-23');
        $other = ShiftUser::create([
            'shift_id' => $otherShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $other->update(['status' => ShiftUserStatus::Rejected->value]);

        $this->assertDatabaseHas('shift_plan_drafts', ['id' => $update->id]);
    }

    public function testSavingShiftUserHydratedWithoutStatusDoesNotCrashObserver(): void
    {
        $member = $this->createMember('99025');
        $amShift = $this->createShift($this->amType, '2026-06-22');
        ShiftUser::create([
            'shift_id' => $amShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        // Mirror ShiftReminder's column-limited hydration: `status` is intentionally NOT selected,
        // so $shiftUser->status is null. Before the fix, the `updated` observer's status() helper
        // (typed `: string`) threw a TypeError on save, rolling back the whole reminder transaction.
        $partial = ShiftUser::query()
            ->select(['id', 'user_id', 'shift_id', 'shift_reminded_at'])
            ->firstOrFail();
        $this->assertNull($partial->status);

        $partial->shift_reminded_at = Carbon::now();
        $partial->save();

        $this->assertDatabaseHas('shift_users', [
            'id' => $partial->id,
            'shift_reminded_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ---- helpers ----------------------------------------------------------

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
}
