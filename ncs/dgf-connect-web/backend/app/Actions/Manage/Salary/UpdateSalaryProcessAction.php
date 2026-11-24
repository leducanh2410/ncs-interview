<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\SalaryProcessStatus;
use App\Models\SalaryProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class UpdateSalaryProcessAction
{
    public function __invoke(SalaryProcess $salaryProcess, array $values): ActionResult
    {
        if ($salaryProcess->status !== SalaryProcessStatus::Pending->value) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $scheduledAt = Arr::get($values, 'scheduled_at');
        if (!$scheduledAt) {
            $values['scheduled_at'] = now();
        } else {
            $values['scheduled_at'] = Carbon::parse($scheduledAt)->setHour(3);
        }

        $salaryProcess->update($values);

        return new ActionResult(data: $salaryProcess);
    }
}
