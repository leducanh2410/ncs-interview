<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Models\SalaryRequest;

class GetSalaryRequestAction
{
    public function __invoke(array $conditions): ActionResult
    {
        $requests = SalaryRequest::getPaginateFiltered($conditions, ['user'], null, $conditions['page_size'] ?? null);

        return new ActionResult(data: $requests);
    }
}
