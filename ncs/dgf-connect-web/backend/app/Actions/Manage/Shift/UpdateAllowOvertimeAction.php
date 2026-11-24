<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Models\ShiftUser;

class UpdateAllowOvertimeAction
{
    public function __invoke(ShiftUser $shiftUser, bool $isAllowed): ActionResult
    {
        $shiftUser->update(['is_overtime_allowed' => $isAllowed]);

        return new ActionResult(data: $shiftUser);
    }
}
