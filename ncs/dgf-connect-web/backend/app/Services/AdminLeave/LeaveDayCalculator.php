<?php

namespace App\Services\AdminLeave;

use App\Actions\Admin\CompanyHoliday\EnsureHolidayYearAction;
use App\Models\CompanyHoliday;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The single definition of "how many days does this leave consume". Its result is written once
 * onto the request row and never recomputed, so every consumer — the API, the mobile preview, any
 * later payroll reader — has to agree here rather than each re-deriving the rule.
 */
class LeaveDayCalculator
{
    public function __construct(
        private readonly EnsureHolidayYearAction $ensureHolidayYear,
        private readonly WeekendSettingResolver $weekendSettings
    ) {}

    /**
     * Working days in `[start, end]`, both endpoints included. `0` is a legitimate result: a
     * request covering only holidays consumes nothing but is still a request.
     */
    public function countDays(CarbonInterface $start, CarbonInterface $end): int
    {
        $timezone = config('app.timezone');
        $startDate = Carbon::parse($start->format('Y-m-d'), $timezone)->startOfDay();
        $endDate = Carbon::parse($end->format('Y-m-d'), $timezone)->startOfDay();

        if ($endDate->lt($startDate)) {
            return 0;
        }

        $years = range((int) $startDate->year, (int) $endDate->year);

        // A year nobody has generated yet would otherwise be counted as having no holidays at
        // all, and the snapshot rule would freeze that wrong number forever. Generating here
        // costs one write the first time a year is touched and nothing afterwards.
        $this->ensureYearsGenerated($years);

        $holidays = $this->loadHolidays($startDate, $endDate);
        $excludeWeekends = $this->weekendSettings->resolveMany($years);

        $count = 0;
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $key = $date->toDateString();
            if (isset($holidays[$key])) {
                continue;
            }

            // Each date answers to its own year's policy, so a range crossing 31 December can
            // count its December days one way and its January days another.
            if (($excludeWeekends[(int) $date->year] ?? false) && $date->isWeekend()) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @param int[] $years
     */
    private function ensureYearsGenerated(array $years): void
    {
        // The spanned years are contiguous, so one range scan over the unique `date` index tells
        // us which of them already have rows.
        $populatedYears = CompanyHoliday::query()
            ->whereBetween('date', [min($years) . '-01-01', max($years) . '-12-31'])
            ->selectRaw('YEAR(`date`) as holiday_year')
            ->distinct()
            ->pluck('holiday_year')
            ->map(fn($year) => (int) $year)
            ->all();

        foreach (array_diff($years, $populatedYears) as $year) {
            ($this->ensureHolidayYear)($year);
        }
    }

    /**
     * @return array<string, true> holiday dates keyed by `Y-m-d`
     */
    private function loadHolidays(Carbon $start, Carbon $end): array
    {
        return CompanyHoliday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->mapWithKeys(fn($date) => [$date->toDateString() => true])
            ->all();
    }
}
