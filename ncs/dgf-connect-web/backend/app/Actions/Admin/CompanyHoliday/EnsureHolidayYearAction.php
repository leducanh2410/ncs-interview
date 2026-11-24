<?php

namespace App\Actions\Admin\CompanyHoliday;

use App\Actions\ActionResult;
use App\Contracts\HolidayProviderContract;
use App\Enums\CompanyHolidaySource;
use App\Models\CompanyHoliday;

/**
 * Guarantees a calendar year has holiday rows. Called both by the manager's "generate" button and
 * by LeaveDayCalculator when a request lands in a year nobody has generated yet, so it has to be
 * safe to run repeatedly: it only ever inserts dates that are missing.
 */
class EnsureHolidayYearAction
{
    public function __construct(private readonly HolidayProviderContract $provider) {}

    public function __invoke(int $year): ActionResult
    {
        $libraryHolidays = $this->provider->holidaysForYear($year);
        if ($libraryHolidays === []) {
            return new ActionResult(data: ['inserted' => 0]);
        }

        $existingDates = CompanyHoliday::query()
            ->whereYear('date', $year)
            ->pluck('date')
            ->map(fn($date) => $date->toDateString())
            ->all();

        $missing = array_diff_key($libraryHolidays, array_flip($existingDates));
        if ($missing === []) {
            return new ActionResult(data: ['inserted' => 0]);
        }

        $now = now();
        $rows = [];
        foreach ($missing as $date => $name) {
            $rows[] = [
                'date' => $date,
                'name' => $name,
                'source' => CompanyHolidaySource::Library->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // insertOrIgnore against the unique index on `date`: two generations of the same year
        // racing each other both see the same "missing" set, and the loser must be a no-op
        // rather than an integrity error. Its own return value — not a recount of the table — is
        // what actually landed, so a losing race correctly reports 0 instead of the winner's count.
        $inserted = CompanyHoliday::query()->insertOrIgnore($rows);

        return new ActionResult(data: ['inserted' => $inserted]);
    }
}
