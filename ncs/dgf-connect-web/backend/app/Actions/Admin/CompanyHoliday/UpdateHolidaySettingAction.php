<?php

namespace App\Actions\Admin\CompanyHoliday;

use App\Actions\ActionResult;
use App\Models\CompanyHolidaySetting;

class UpdateHolidaySettingAction
{
    public function __invoke(int $year, bool $excludeWeekends): ActionResult
    {
        // Written for this year only. Earlier years keep whatever they were counted under, and
        // later years without a row of their own inherit this value at read time.
        $setting = CompanyHolidaySetting::query()->updateOrCreate(
            ['year' => $year],
            ['exclude_weekends' => $excludeWeekends]
        );

        return new ActionResult(
            data: [
                'year' => $setting->year,
                'exclude_weekends' => $setting->exclude_weekends,
            ]
        );
    }
}
