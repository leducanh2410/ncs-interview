<?php

namespace App\Actions\Admin\CompanyHoliday;

use App\Actions\ActionResult;
use App\Models\CompanyHoliday;

class DeleteCompanyHolidayAction
{
    public function __invoke(CompanyHoliday $holiday): ActionResult
    {
        $holiday->delete();

        return new ActionResult(data: true);
    }
}
