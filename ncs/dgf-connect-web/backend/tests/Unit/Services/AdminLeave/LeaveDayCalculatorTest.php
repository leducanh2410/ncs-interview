<?php

namespace Tests\Unit\Services\AdminLeave;

use App\Contracts\HolidayProviderContract;
use App\Enums\CompanyHolidaySource;
use App\Models\CompanyHoliday;
use App\Models\CompanyHolidaySetting;
use App\Services\AdminLeave\LeaveDayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeHolidayProvider;
use Tests\TestCase;

class LeaveDayCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private FakeHolidayProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeHolidayProvider([
            2027 => ['2027-01-01' => '元日', '2027-01-11' => '成人の日'],
        ]);
        $this->app->bind(HolidayProviderContract::class, fn() => $this->provider);
    }

    public function testCountsAnOrdinaryRangeInclusiveOfBothEndpoints(): void
    {
        // 2026-09-07 (Mon) .. 2026-09-11 (Fri): five days, no holidays, no weekend rule.
        $this->markYearGenerated(2026);

        $this->assertSame(5, $this->countDays('2026-09-07', '2026-09-11'));
    }

    public function testExcludesAHolidayInsideTheRange(): void
    {
        $this->markYearGenerated(2026);
        $this->holiday('2026-09-09', '社内休業日');

        $this->assertSame(4, $this->countDays('2026-09-07', '2026-09-11'));
    }

    public function testKeepsWeekendsWhenTheYearSettingIsOff(): void
    {
        // 2026-09-07 (Mon) .. 2026-09-13 (Sun) covers one full weekend.
        $this->markYearGenerated(2026);

        $this->assertSame(7, $this->countDays('2026-09-07', '2026-09-13'));
    }

    public function testExcludesWeekendsWhenTheYearSettingIsOn(): void
    {
        $this->markYearGenerated(2026);
        $this->weekendSetting(2026, true);

        $this->assertSame(5, $this->countDays('2026-09-07', '2026-09-13'));
    }

    public function testAppliesEachYearsOwnHolidaysAndWeekendSettingAcrossTheYearBoundary(): void
    {
        // 2026 excludes weekends, 2027 does not; each side of 31 December answers to its own rule.
        $this->markYearGenerated(2026);
        // markYearGenerated seeds 2027-01-01 (元日), which is the holiday this case relies on.
        $this->markYearGenerated(2027);
        $this->weekendSetting(2026, true);
        $this->weekendSetting(2027, false);
        $this->holiday('2026-12-31', '年末休業');

        // 2026-12-26 (Sat) .. 2027-01-04 (Mon) = 10 dates.
        // 2026 side: 26(Sat), 27(Sun) dropped as weekend; 28, 29, 30 counted; 31 dropped as holiday -> 3.
        // 2027 side: 01 dropped as holiday; 02(Sat), 03(Sun) counted since 2027 keeps weekends; 04 counted -> 3.
        $this->assertSame(6, $this->countDays('2026-12-26', '2027-01-04'));
    }

    public function testInheritsTheWeekendSettingFromTheNearestEarlierYear(): void
    {
        // 2026 turned the rule on and 2027 never recorded one, so 2027 must not silently reset.
        $this->markYearGenerated(2027);
        $this->weekendSetting(2026, true);

        // 2027-09-06 (Mon) .. 2027-09-12 (Sun).
        $this->assertSame(5, $this->countDays('2027-09-06', '2027-09-12'));
    }

    public function testTreatsWeekendExclusionAsDisabledWhenNoEarlierSettingExists(): void
    {
        $this->markYearGenerated(2026);

        $this->assertSame(7, $this->countDays('2026-09-07', '2026-09-13'));
    }

    public function testReturnsZeroWhenEveryDateInTheRangeIsExcluded(): void
    {
        $this->markYearGenerated(2026);
        $this->weekendSetting(2026, true);
        // 2026-09-11 is a Friday; making it a holiday leaves only the weekend behind it.
        $this->holiday('2026-09-11', '創立記念日');

        $this->assertSame(0, $this->countDays('2026-09-11', '2026-09-13'));
    }

    public function testCountsASingleDayRange(): void
    {
        $this->markYearGenerated(2026);

        $this->assertSame(1, $this->countDays('2026-09-07', '2026-09-07'));
    }

    public function testGeneratesAYearThatHasNoHolidayRowsBeforeCounting(): void
    {
        // Nothing seeded for 2027: without generation the two January holidays would be invisible
        // and the frozen day count would be wrong forever.
        $this->assertSame(0, CompanyHoliday::query()->count());

        // 2027-01-01 (Fri) .. 2027-01-11 (Mon) = 11 dates, minus the two generated holidays.
        $this->assertSame(9, $this->countDays('2027-01-01', '2027-01-11'));
        $this->assertSame(2, CompanyHoliday::query()->count());
        $this->assertSame(1, $this->provider->callCount);
    }

    public function testDoesNotRegenerateAYearThatAlreadyHasRows(): void
    {
        $this->holiday('2027-06-01', '手動休業日');

        $this->countDays('2027-01-01', '2027-01-11');

        $this->assertSame(0, $this->provider->callCount);
        $this->assertSame(1, CompanyHoliday::query()->count());
    }

    public function testAStoredCountIsUnaffectedByALaterCalendarChange(): void
    {
        // The calculator has no update path — a caller writes its result once. This asserts the
        // consequence: recomputing later would give a different number, and the stored one must
        // not follow it.
        $this->markYearGenerated(2026);
        $storedCount = $this->countDays('2026-09-07', '2026-09-11');
        $this->assertSame(5, $storedCount);

        $this->holiday('2026-09-09', '追加休業日');
        $this->weekendSetting(2026, true);

        $this->assertSame(4, $this->countDays('2026-09-07', '2026-09-11'));
        $this->assertSame(5, $storedCount);
    }

    public function testReturnsZeroForAReversedRange(): void
    {
        $this->markYearGenerated(2026);

        $this->assertSame(0, $this->countDays('2026-09-11', '2026-09-07'));
    }

    private function countDays(string $start, string $end): int
    {
        return $this->app->make(LeaveDayCalculator::class)->countDays(Carbon::parse($start), Carbon::parse($end));
    }

    private function holiday(string $date, string $name): CompanyHoliday
    {
        return CompanyHoliday::query()->create([
            'date' => $date,
            'name' => $name,
            'source' => CompanyHolidaySource::Manual->value,
        ]);
    }

    /**
     * Put one out-of-range row in the year so the calculator sees it as generated and the test
     * controls the calendar rather than the provider.
     */
    private function markYearGenerated(int $year): void
    {
        $this->holiday($year . '-01-01', '元日');
    }

    private function weekendSetting(int $year, bool $excludeWeekends): void
    {
        CompanyHolidaySetting::query()->create([
            'year' => $year,
            'exclude_weekends' => $excludeWeekends,
        ]);
    }
}
