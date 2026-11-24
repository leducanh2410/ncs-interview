<?php

namespace Tests\Unit\ShiftPlanning;

use App\Actions\Manage\ShiftPlanning\BuildShiftPlanWorkDataAction;
use App\Enums\ShiftPlanDraftAction;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\WorkplaceShift;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Verifies the set-aware map = live base − updated live originals + other draft adds/updates
 * − target-date removes − the candidate itself (requirements §4.3), in isolation and without
 * the DB.
 */
class BuildShiftPlanWorkDataActionTest extends TestCase
{
    public function testLayersDraftDeltasAndExcludesCandidate(): void
    {
        $target = '2026-06-22';
        $am = $this->workplaceShift(1, '09:00:00', '13:00:00'); // 4h
        $pm = $this->workplaceShift(2, '14:00:00', '18:00:00'); // 4h
        $ev = $this->workplaceShift(3, '19:00:00', '22:00:00'); // 3h
        $workplaceShiftsById = [1 => $am, 2 => $pm, 3 => $ev];
        $shiftBreakByKey = [];

        // Live base on the target date: AM (4h) + EV (3h). Build the EV/AM ranges with the
        // same helper so the removal subtraction matches by range.
        $amData = WorkHelper::computeShiftWorkData($target, '09:00:00', '13:00:00', null, null, null);
        $evData = WorkHelper::computeShiftWorkData($target, '19:00:00', '22:00:00', null, null, null);
        $liveBaseForUser = [
            $target => [
                'total' => 7.0,
                'hours' => [4.0, 3.0],
                'ranges' => [$amData['range'], $evData['range']],
                'shift_count' => 2,
            ],
        ];

        $candidate = $this->draft(10, $target, 2, ShiftPlanDraftAction::Add); // PM on target (validated)
        $drafts = new Collection([
            $candidate,
            $this->draft(11, '2026-06-23', 1, ShiftPlanDraftAction::Add), // AM next day (other add)
            $this->draft(12, $target, 3, ShiftPlanDraftAction::Remove), // remove EV on target
        ]);

        $map = (new BuildShiftPlanWorkDataAction())(
            $liveBaseForUser,
            $drafts,
            $candidate,
            $target,
            $workplaceShiftsById,
            $shiftBreakByKey
        );

        // Target date: EV removed, AM live remains, PM candidate excluded => 1 shift, 4h.
        $this->assertSame(1, $map[$target]['shift_count']);
        $this->assertSame(4.0, $map[$target]['total']);

        // Cross-date add layered in.
        $this->assertSame(1, $map['2026-06-23']['shift_count']);
        $this->assertSame(4.0, $map['2026-06-23']['total']);
    }

    public function testRemoveOnOtherDateIsNotSubtracted(): void
    {
        $target = '2026-06-22';
        $am = $this->workplaceShift(1, '09:00:00', '13:00:00');
        $otherDate = '2026-06-23';
        $amOtherData = WorkHelper::computeShiftWorkData($otherDate, '09:00:00', '13:00:00', null, null, null);
        $liveBaseForUser = [
            $otherDate => ['total' => 4.0, 'hours' => [4.0], 'ranges' => [$amOtherData['range']], 'shift_count' => 1],
        ];

        $candidate = $this->draft(10, $target, 1, ShiftPlanDraftAction::Add);
        $drafts = new Collection([
            $candidate,
            // A removal on a different (not-yet-committing) date must NOT free hours.
            $this->draft(13, $otherDate, 1, ShiftPlanDraftAction::Remove),
        ]);

        $map = (new BuildShiftPlanWorkDataAction())($liveBaseForUser, $drafts, $candidate, $target, [1 => $am], []);

        $this->assertSame(1, $map[$otherDate]['shift_count']);
        $this->assertSame(4.0, $map[$otherDate]['total']);
    }

    public function testUpdateSubtractsOriginalLiveRowAndExcludesCandidate(): void
    {
        $target = '2026-06-22';
        $am = $this->workplaceShift(1, '09:00:00', '13:00:00');
        $pm = $this->workplaceShift(2, '14:00:00', '18:00:00');
        $amData = WorkHelper::computeShiftWorkData($target, '09:00:00', '13:00:00', null, null, null);
        $liveBaseForUser = [
            $target => ['total' => 4.0, 'hours' => [4.0], 'ranges' => [$amData['range']], 'shift_count' => 1],
        ];

        $candidate = $this->draft(10, $target, 2, ShiftPlanDraftAction::Update);
        $candidate->setRelation('shiftUser', $this->liveShiftUser(99, $target, $am));
        $drafts = new Collection([$candidate]);

        $map = (new BuildShiftPlanWorkDataAction())(
            $liveBaseForUser,
            $drafts,
            $candidate,
            $target,
            [1 => $am, 2 => $pm],
            []
        );

        // The original live AM row is removed from the validation base, and the candidate PM
        // update is excluded because validateShiftRegister layers that candidate itself.
        $this->assertSame(0, $map[$target]['shift_count']);
        $this->assertSame(0.0, $map[$target]['total']);
    }

    private function workplaceShift(int $id, string $start, string $end): WorkplaceShift
    {
        $ws = new WorkplaceShift([
            'start_time' => $start,
            'end_time' => $end,
            'start_break_time' => null,
            'end_break_time' => null,
        ]);
        $ws->id = $id;

        return $ws;
    }

    private function draft(int $id, string $date, int $workplaceShiftId, ShiftPlanDraftAction $action): ShiftPlanDraft
    {
        $draft = new ShiftPlanDraft([
            'date' => $date,
            'workplace_shift_id' => $workplaceShiftId,
            'user_id' => 500,
            'is_fixed' => false,
            'action' => $action->value,
        ]);
        $draft->id = $id;

        return $draft;
    }

    private function liveShiftUser(int $id, string $date, WorkplaceShift $workplaceShift): ShiftUser
    {
        $shift = new Shift([
            'date' => $date,
            'workplace_shift_id' => $workplaceShift->id,
            'break_minutes' => null,
        ]);
        $shift->id = $id + 1000;
        $shift->setRelation('workplaceShift', $workplaceShift);

        $shiftUser = new ShiftUser([
            'shift_id' => $shift->id,
            'user_id' => 500,
        ]);
        $shiftUser->id = $id;
        $shiftUser->setRelation('shift', $shift);

        return $shiftUser;
    }
}
