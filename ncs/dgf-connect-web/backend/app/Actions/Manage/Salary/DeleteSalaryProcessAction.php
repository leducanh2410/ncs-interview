<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\SalaryProcessStatus;
use App\Models\SalaryProcess;

class DeleteSalaryProcessAction
{
    public function __invoke(SalaryProcess $salaryProcess): ActionResult
    {
        if ($salaryProcess->status !== SalaryProcessStatus::Pending->value) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $salaryProcess->delete();

        return new ActionResult(data: true);
    }
}
