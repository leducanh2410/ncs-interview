<?php

namespace App\Actions\Manage\AbsentNotice;

use App\Enums\AbsentNoticeStatus;
use App\Models\ShiftAbsentNotice;

class MarkAbsentNoticeProcessedAction
{
    /**
     * Closes the absence request attached to a shift row the admin just rejected: taking the
     * employee off the shift is exactly what processing the request means, so the notice must not
     * stay in the pending list afterwards.
     *
     * Only a pending notice moves. A notice the admin already rejected is a deliberate decision
     * ("you still have to work"), and reversing it here would silently overwrite that. The
     * `status = pending` condition also makes the write idempotent, so the notice screen's own
     * "mark processed" action can dispatch the rejection event without racing this.
     *
     * @return bool whether a notice was actually moved to processed
     */
    public function __invoke(int $shiftUserId, ?int $processedById): bool
    {
        return ShiftAbsentNotice::query()
            ->where('shift_user_id', $shiftUserId)
            ->where('status', AbsentNoticeStatus::Pending->value)
            ->update([
                'status' => AbsentNoticeStatus::Processed->value,
                'processed_by_id' => $processedById,
                'processed_at' => now(),
            ]) > 0;
    }
}
