<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Models\SalaryProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class CreateSalaryProcessAction
{
    public function __invoke(array $values): ActionResult
    {
        $scheduledAt = Arr::get($values, 'scheduled_at');
        if (!$scheduledAt) {
            $values['scheduled_at'] = now();
        } else {
            $values['scheduled_at'] = Carbon::parse($scheduledAt)->setHour(3);
        }

        $salaryProcess = SalaryProcess::query()->create($values);

        return new ActionResult(data: $salaryProcess);
    }
}
