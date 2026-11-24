<?php

namespace Tests\Support;

use App\Contracts\HolidayProviderContract;

/**
 * Deterministic stand-in for YasumiHolidayProvider. The library's data for a future year can shift
 * (substitute holidays, one-off imperial dates), so tests that assert on day counts bind this
 * instead and own the calendar they are asserting against.
 */
class FakeHolidayProvider implements HolidayProviderContract
{
    public int $callCount = 0;

    /** @var array<int, array<string, string>> */
    private array $holidaysByYear;

    /**
     * @param array<int, array<string, string>> $holidaysByYear year => (`Y-m-d` => name)
     */
    public function __construct(array $holidaysByYear = [])
    {
        $this->holidaysByYear = $holidaysByYear;
    }

    public function holidaysForYear(int $year): array
    {
        ++$this->callCount;

        return $this->holidaysByYear[$year] ?? [];
    }
}
