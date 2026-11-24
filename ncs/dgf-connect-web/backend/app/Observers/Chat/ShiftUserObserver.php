<?php

namespace App\Observers\Chat;

use App\Actions\Chat\Shift\SyncShiftChatParticipantsAction;
use App\Enums\ShiftUserStatus;
use App\Models\ShiftUser;

class ShiftUserObserver
{
    public function __construct(private readonly SyncShiftChatParticipantsAction $sync) {}

    public function created(ShiftUser $shiftUser): void
    {
        if ($this->isApproved($shiftUser)) {
            $this->sync((int) $shiftUser->shift_id);
        }
    }

    public function updated(ShiftUser $shiftUser): void
    {
        // Moving an already-approved row to another shift keeps status === approved, so a
        // status-only check misses it. Sync both the old shift (drop the member there) and the
        // new shift (add them) whenever shift_id changes.
        if ($shiftUser->wasChanged('shift_id')) {
            $originalShiftId = $shiftUser->getOriginal('shift_id');
            if ($originalShiftId !== null && (int) $originalShiftId !== (int) $shiftUser->shift_id) {
                $this->sync((int) $originalShiftId);
            }
            $this->sync((int) $shiftUser->shift_id);

            return;
        }

        if ($shiftUser->wasChanged('status')) {
            $this->sync((int) $shiftUser->shift_id);
        }
    }

    public function deleted(ShiftUser $shiftUser): void
    {
        if ($this->isApproved($shiftUser)) {
            $this->sync((int) $shiftUser->shift_id);
        }
    }

    /**
     * Defer to the active batch when a bulk shift mutation is in progress so each affected shift
     * is reconciled once after the batch; otherwise reconcile immediately.
     */
    private function sync(int $shiftId): void
    {
        if (SyncShiftChatParticipantsAction::defer($shiftId)) {
            return;
        }

        ($this->sync)($shiftId);
    }

    /**
     * ShiftUser does not cast `status`, so callers may set it as a ShiftUserStatus enum object
     * (e.g. AddUsersToShiftAction) or as its string value. Normalize before comparing so the
     * approved check matches regardless of how the caller assigned it.
     */
    private function isApproved(ShiftUser $shiftUser): bool
    {
        $status = $shiftUser->status;
        if ($status instanceof ShiftUserStatus) {
            $status = $status->value;
        }

        return $status === ShiftUserStatus::Approved->value;
    }
}
