<?php

namespace App\Actions\Admin\CompanyHoliday;

use App\Actions\ActionResult;
use App\Models\CompanyHoliday;

class UpdateCompanyHolidayAction
{
    public function __invoke(CompanyHoliday $holiday, array $data): ActionResult
    {
        // A library-sourced row is edited exactly like a manual one — `source` records where the
        // row came from, it does not lock it. The source is left as-is so regeneration still
        // recognises the row as one it already provided.
        $holiday->update([
            'date' => $data['date'],
            'name' => $data['name'],
        ]);

        return new ActionResult(data: $holiday->fresh());
    }
}
