<?php

namespace App\Enums;

enum AdminLeaveType: string
{
    use EnumTrait;

    case PaidLeave = 'paid_leave';
    case DayOff = 'day_off';
    case LongLeave = 'long_leave';
    case ExpenseClaim = 'expense_claim';

    /**
     * Whether a request of this type must carry a date range. An expense claim documents money
     * already spent and need not name any days off, so it is the only type that may omit them.
     */
    public function requiresDateRange(): bool
    {
        return $this !== self::ExpenseClaim;
    }
}
