<?php

namespace App\Actions\Admin\CompanyHoliday;

use App\Actions\ActionResult;
use App\Enums\CompanyHolidaySource;
use App\Models\CompanyHoliday;

class CreateCompanyHolidayAction
{
    public function __invoke(array $data): ActionResult
    {
        // `source` is assigned here, never taken from input: a client cannot pass a row off as
        // library-sourced.
        $holiday = CompanyHoliday::query()->create([
            'date' => $data['date'],
            'name' => $data['name'],
            'source' => CompanyHolidaySource::Manual->value,
        ]);

        return new ActionResult(data: $holiday, status: 201);
    }
}
