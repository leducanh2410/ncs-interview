<?php

namespace App\Events;

use App\Models\ShiftAbsentNotice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * An admin refused an employee's absence request: the employee is still expected on the shift.
 */
class AbsentNoticeRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Carbon $shiftDate, public ShiftAbsentNotice $absentNotice) {}
}
