<?php

namespace App\Enums;

/**
 * What actually happened to the shift row while an absence request was handled.
 *
 * Marking a request processed usually takes the employee off the shift, but there are normal
 * cases where it deliberately does not. The admin screen only says "removed from the shift" when
 * that is true, so the outcome travels back with the response instead of being assumed.
 */
enum AbsentNoticeShiftOutcome: string
{
    use EnumTrait;

    /** The employee was moved to the rejected list of the shift. */
    case ShiftRejected = 'shift_rejected';

    /** The shift had already started, so the row and its attendance were left alone. */
    case ShiftKeptAlreadyStarted = 'shift_kept_already_started';

    /** The employee had already checked in, so the row and the check-in were left alone. */
    case ShiftKeptCheckedIn = 'shift_kept_checked_in';

    /** Nothing on the shift needed to change (refusing the request, or the row was already out). */
    case ShiftUnchanged = 'shift_unchanged';
}
