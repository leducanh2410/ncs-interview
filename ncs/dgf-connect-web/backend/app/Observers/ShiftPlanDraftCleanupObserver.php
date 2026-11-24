<?php

namespace App\Observers;

use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;

/**
 * Keeps Shift Planner drafts from fighting real live rows (requirements §4.2). When a
 * live ShiftUser is created/updated by ANY path (shift-detail write, employee
 * self-registration, finalize), a stale planned `add` for the same cell is dropped;
 * when a live row is rejected/removed, a stale planned `remove` is dropped. Idempotent,
 * so it is harmless when finalize also deletes the committed drafts itself.
 */
class ShiftPlanDraftCleanupObserver
{
    public function created(ShiftUser $shiftUser): void
    {
        if ($this->status($shiftUser) !== ShiftUserStatus::Rejected->value) {
            $this->deleteMatchingDraft($shiftUser, ShiftPlanDraftAction::Add);
            $this->deleteMatchingDraft($shiftUser, ShiftPlanDraftAction::Update);
        }
    }

    public function updated(ShiftUser $shiftUser): void
    {
        if ($this->status($shiftUser) === ShiftUserStatus::Rejected->value) {
            $this->deleteMatchingDraft($shiftUser, ShiftPlanDraftAction::Remove);
            $this->deleteMatchingDraft($shiftUser, ShiftPlanDraftAction::Update);
        } else {
            $this->deleteMatchingDraft($shiftUser, ShiftPlanDraftAction::Add);
            $this->deleteMatchingDraft($shiftUser, ShiftPlanDraftAction::Update);
        }
    }

    public function deleted(ShiftUser $shiftUser): void
    {
        $this->deleteMatchingDraft($shiftUser, ShiftPlanDraftAction::Remove);
    }

    private function deleteMatchingDraft(ShiftUser $shiftUser, ShiftPlanDraftAction $action): void
    {
        $shift = $shiftUser->shift;
        if (!$shift) {
            return;
        }

        ShiftPlanDraft::query()
            ->where('workplace_id', $shift->workplace_id)
            ->where('date', $shift->date)
            ->where('workplace_shift_id', $shift->workplace_shift_id)
            ->where('user_id', $shiftUser->user_id)
            ->where('action', $action->value)
            ->when(
                $action === ShiftPlanDraftAction::Update,
                // A cross-shift `update` draft is stored against its TARGET cell but points at its
                // SOURCE live row via shift_user_id, so the cell match above misses it. Also clear any
                // update draft that references THIS live row, regardless of where it is staged.
                fn($query) => $query->orWhere(function ($query) use ($shiftUser): void {
                    $query
                        ->where('shift_user_id', $shiftUser->id)
                        ->where('action', ShiftPlanDraftAction::Update->value);
                })
            )
            ->delete();
    }

    /**
     * Resolve the live row's status as a plain string. Returns null when the model was
     * hydrated without its `status` column (e.g. a column-limited select in a job), so
     * callers can treat the status as unknown instead of crashing an unrelated save.
     */
    private function status(ShiftUser $shiftUser): ?string
    {
        $status = $shiftUser->status;

        return $status instanceof ShiftUserStatus ? $status->value : $status;
    }
}
