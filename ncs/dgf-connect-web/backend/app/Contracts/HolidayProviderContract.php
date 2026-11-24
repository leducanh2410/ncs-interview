<?php

namespace App\Contracts;

interface HolidayProviderContract
{
    /**
     * Public holidays of a calendar year, as a `Y-m-d => name` map.
     *
     * The map is the only shape the holiday calendar consumes, so tests can substitute a fake
     * year without pulling in the third-party library's data for that year.
     *
     * @return array<string, string>
     */
    public function holidaysForYear(int $year): array;
}
