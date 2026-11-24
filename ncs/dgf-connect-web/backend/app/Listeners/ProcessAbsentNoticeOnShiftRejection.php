<?php

namespace App\Listeners;

use App\Actions\Manage\AbsentNotice\MarkAbsentNoticeProcessedAction;
use App\Events\ShiftUserRejected;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps the absence-request screen in step with the shift screen. Every path that rejects a shift
 * row dispatches ShiftUserRejected — the shift assignment screen, account locking, shift plan
 * drafts — so listening to the event covers them all instead of each action remembering to do it.
 */
class ProcessAbsentNoticeOnShiftRejection
{
    public function __construct(private readonly MarkAbsentNoticeProcessedAction $markProcessed) {}

    public function handle(ShiftUserRejected $event): void
    {
        ($this->markProcessed)((int) $event->shiftUser->id, Auth::id());
    }
}
