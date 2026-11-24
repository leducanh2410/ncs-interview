<?php

namespace App\Actions\Admin\CompanyHoliday;

use App\Actions\ActionResult;
use App\Models\CompanyHoliday;
use App\Services\AdminLeave\WeekendSettingResolver;

class GetHolidayCalendarAction
{
    public function __construct(private readonly WeekendSettingResolver $weekendSettings) {}

    public function __invoke(int $year): ActionResult
    {
        $holidays = CompanyHoliday::query()
            ->whereBetween('date', [$year . '-01-01', $year . '-12-31'])
            ->orderBy('date')
            ->get()
            ->map(
                fn(CompanyHoliday $holiday) => [
                    'id' => $holiday->id,
                    'date' => $holiday->date->toDateString(),
                    'name' => $holiday->name,
                    'source' => $holiday->source->value,
                ]
            );

        return new ActionResult(
            data: [
                'year' => $year,
                // Resolved rather than raw, so a year with no row of its own shows the value the
                // day count will actually use instead of a misleading "off".
                'exclude_weekends' => $this->weekendSettings->resolve($year),
                'holidays' => $holidays,
            ]
        );
    }
}
