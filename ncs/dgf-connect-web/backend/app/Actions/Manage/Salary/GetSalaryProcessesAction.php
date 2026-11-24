<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Models\SalaryProcess;

class GetSalaryProcessesAction
{
    public function __invoke(array $conditions): ActionResult
    {
        return new ActionResult(
            data: SalaryProcess::getPaginateFiltered(
                $conditions,
                ['workplace'],
                ['id' => 'desc'],
                $conditions['page_size'] ?? null
            )
        );
    }
}
