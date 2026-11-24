<?php

namespace Tests\Unit\Actions\Admin\CompanyHoliday;

use App\Actions\Admin\CompanyHoliday\EnsureHolidayYearAction;
use App\Contracts\HolidayProviderContract;
use App\Enums\CompanyHolidaySource;
use App\Models\CompanyHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeHolidayProvider;
use Tests\TestCase;

class EnsureHolidayYearActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeHolidayProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeHolidayProvider([
            2027 => [
                '2027-01-01' => '元日',
                '2027-01-11' => '成人の日',
                '2027-02-11' => '建国記念の日',
            ],
        ]);
        $this->app->bind(HolidayProviderContract::class, fn() => $this->provider);
    }

    public function testPopulatesAnEmptyYearFromTheProvider(): void
    {
        $result = $this->ensureYear(2027);

        $this->assertSame(['inserted' => 3], $result);
        $this->assertSame(3, CompanyHoliday::query()->count());
        $this->assertSame(
            ['2027-01-01', '2027-01-11', '2027-02-11'],
            CompanyHoliday::query()->orderBy('date')->pluck('date')->map(fn($date) => $date->toDateString())->all()
        );
        $this->assertSame(
            CompanyHolidaySource::Library,
            CompanyHoliday::query()->where('date', '2027-01-01')->first()->source
        );
    }

    public function testInsertsOnlyMissingDatesAndLeavesARenamedRowUntouched(): void
    {
        // A manager renamed the generated 元日 row and added a company-only closure. Regeneration
        // must not undo either.
        CompanyHoliday::query()->create([
            'date' => '2027-01-01',
            'name' => '年始休業（社内）',
            'source' => CompanyHolidaySource::Library->value,
        ]);
        CompanyHoliday::query()->create([
            'date' => '2027-08-13',
            'name' => '夏季休業',
            'source' => CompanyHolidaySource::Manual->value,
        ]);

        $result = $this->ensureYear(2027);

        $this->assertSame(['inserted' => 2], $result);
        $this->assertSame(4, CompanyHoliday::query()->count());

        $renamed = CompanyHoliday::query()->where('date', '2027-01-01')->first();
        $this->assertSame('年始休業（社内）', $renamed->name);

        $manual = CompanyHoliday::query()->where('date', '2027-08-13')->first();
        $this->assertSame('夏季休業', $manual->name);
        $this->assertSame(CompanyHolidaySource::Manual, $manual->source);
    }

    public function testIsIdempotentAcrossTwoConsecutiveCalls(): void
    {
        $this->assertSame(['inserted' => 3], $this->ensureYear(2027));
        $this->assertSame(['inserted' => 0], $this->ensureYear(2027));

        $this->assertSame(3, CompanyHoliday::query()->count());
    }

    public function testInsertsNothingWhenTheProviderKnowsNoHolidaysForTheYear(): void
    {
        $this->assertSame(['inserted' => 0], $this->ensureYear(2099));

        $this->assertSame(0, CompanyHoliday::query()->count());
    }

    public function testDoesNotTouchOtherYears(): void
    {
        CompanyHoliday::query()->create([
            'date' => '2026-05-03',
            'name' => '憲法記念日',
            'source' => CompanyHolidaySource::Library->value,
        ]);

        $this->assertSame(['inserted' => 3], $this->ensureYear(2027));
        $this->assertSame(1, CompanyHoliday::query()->whereYear('date', 2026)->count());
    }

    private function ensureYear(int $year): array
    {
        return $this->app->make(EnsureHolidayYearAction::class)($year)->getData();
    }
}
