<?php

namespace App\Services\AdminLeave;

use App\Models\CompanyHolidaySetting;

/**
 * Answers "does this year exclude weekends?" including the inheritance rule: a year with no row of
 * its own takes the most recent earlier year's value, and falls back to disabled when no earlier
 * year has one. Shared by the day calculator and the holiday screen so the number the screen shows
 * is the number the count uses.
 */
class WeekendSettingResolver
{
    public function resolve(int $year): bool
    {
        return $this->resolveMany([$year])[$year];
    }

    /**
     * @param int[] $years
     * @return array<int, bool>
     */
    public function resolveMany(array $years): array
    {
        // At most one row per year, so reading everything up to the latest year asked about is
        // cheap and answers the inheritance question in a single query.
        $stored = CompanyHolidaySetting::query()
            ->where('year', '<=', max($years))
            ->orderBy('year')
            ->pluck('exclude_weekends', 'year');

        $resolved = [];
        foreach ($years as $year) {
            $value = false;
            foreach ($stored as $storedYear => $excludeWeekends) {
                if ((int) $storedYear > $year) {
                    break;
                }
                $value = (bool) $excludeWeekends;
            }
            $resolved[$year] = $value;
        }

        return $resolved;
    }
}
