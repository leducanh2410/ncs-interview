<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Models\ShiftUser;

class UpdateUserSalaryAction
{
    public function __invoke(ShiftUser $shiftUser, ?int $salary): ActionResult
    {
        $shiftUser->update(['salary_per_hour' => $salary]);

        return new ActionResult(data: $shiftUser);
    }
}
