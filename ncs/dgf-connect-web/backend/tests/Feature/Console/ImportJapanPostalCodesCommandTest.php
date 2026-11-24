<?php

namespace Tests\Feature\Console;

use App\Models\JapanPostalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\SeedsJapanPostalCodes;
use Tests\TestCase;

class ImportJapanPostalCodesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsJapanPostalCodes;

    /** @var string[] */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function testImportsAZipFileAndSplitsTownQualifiers(): void
    {
        Log::spy();
        $zip = $this->temporary($this->writePostalCodeZip($this->postalCodeFixtureRows()));

        $this->artisan('postal-codes:import', ['--file' => $zip])
            ->expectsOutputToContain('Imported ' . count($this->postalCodeFixtureRows()) . ' rows')
            ->assertSuccessful();

        $this->assertSame(count($this->postalCodeFixtureRows()), JapanPostalCode::query()->count());
        Http::assertNothingSent();
        // The scheduled run has no console, so the outcome must reach the log
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(
                fn(string $message, array $context) => $message === 'Japan Post postal code import finished' &&
                    $context['source'] === $zip &&
                    $context['rows'] === count($this->postalCodeFixtureRows()) &&
                    $context['deleted'] === 0
            );
        Log::shouldNotHaveReceived('error');

        $plain = JapanPostalCode::query()->where('postal_code', '3500235')->firstOrFail();
        $this->assertSame(
            ['埼玉県', '坂戸市', '坂戸市', '三光町', '三光町', null, false],
            [
                $plain->prefecture,
                $plain->city,
                $plain->city_key,
                $plain->town,
                $plain->town_key,
                $plain->town_detail,
                $plain->is_city_default,
            ]
        );

        $split = JapanPostalCode::query()->where('postal_code', '0600042')->firstOrFail();
        $this->assertSame(['大通西', '大通西', '１〜１９丁目'], [$split->town, $split->town_key, $split->town_detail]);

        $default = JapanPostalCode::query()->where('postal_code', '3500200')->firstOrFail();
        $this->assertSame(
            ['', '', null, true],
            [$default->town, $default->town_key, $default->town_detail, $default->is_city_default]
        );

        $wholeVillage = JapanPostalCode::query()->where('postal_code', '1000301')->firstOrFail();
        $this->assertSame(
            ['', '利島村一円', false],
            [$wholeVillage->town, $wholeVillage->town_detail, $wholeVillage->is_city_default]
        );

        $banchiFollowsCity = JapanPostalCode::query()->where('postal_code', '3060433')->firstOrFail();
        $this->assertSame(
            ['', '境町の次に番地がくる場合'],
            [$banchiFollowsCity->town, $banchiFollowsCity->town_detail]
        );

        $ichien = JapanPostalCode::query()->where('postal_code', '5220317')->firstOrFail();
        $this->assertSame(['一円', '一円', null], [$ichien->town, $ichien->town_key, $ichien->town_detail]);

        $kana = JapanPostalCode::query()->where('postal_code', '0861153')->firstOrFail();
        $this->assertSame(['桜ヶ丘', '桜ケ丘'], [$kana->town, $kana->town_key]);
    }

    public function testAcceptsAPlainCsvAndDropsMalformedLines(): void
    {
        $csv = $this->temporary($this->writePostalCodeCsv([['3500235', '埼玉県', '坂戸市', '三光町']], "\n"));
        file_put_contents(
            $csv,
            "garbage line\n\n01101,\"350  \",\"35002\",\"カナ\",\"カナ\",\"カナ\",\"埼玉県\",\"坂戸市\",\"短すぎ\",0,0,0,0,0,0\n",
            FILE_APPEND
        );

        $this->artisan('postal-codes:import', ['--file' => $csv])->assertSuccessful();

        $this->assertSame(['3500235'], JapanPostalCode::query()->pluck('postal_code')->all());
    }

    public function testStitchesTownQualifiersContinuedOnTheNextLine(): void
    {
        // Older KEN_ALL files wrap a long parenthetical across lines sharing the postal code
        $csv = $this->temporary(
            $this->writePostalCodeCsv([
                ['0600042', '北海道', '札幌市中央区', '大通西（１〜１０、'],
                ['0600042', '北海道', '札幌市中央区', '１１〜１９丁目）'],
                ['0640820', '北海道', '札幌市中央区', '大通西（２０〜２８丁目）'],
            ])
        );

        $this->artisan('postal-codes:import', ['--file' => $csv])->assertSuccessful();

        $this->assertSame(2, JapanPostalCode::query()->count());
        $stitched = JapanPostalCode::query()->where('postal_code', '0600042')->firstOrFail();
        $this->assertSame('大通西', $stitched->town);
        $this->assertSame('１〜１０、１１〜１９丁目', $stitched->town_detail);
    }

    public function testReimportUpdatesChangedRowsAndPrunesRemovedOnes(): void
    {
        $first = $this->seedJapanPostalCodes([
            ['3500235', '埼玉県', '坂戸市', '三光町'],
            ['3500201', '埼玉県', '坂戸市', '赤尾'],
            ['9999999', '埼玉県', '坂戸市', '廃止町'],
        ]);
        $this->assertSame(0, $first['deleted']);

        $second = $this->seedJapanPostalCodes([
            ['3500235', '埼玉県', '坂戸市', '三光町'],
            // Same area, renumbered: a new row replaces the old one
            ['3500299', '埼玉県', '坂戸市', '赤尾'],
        ]);

        $this->assertSame(2, $second['rows']);
        $this->assertSame(2, $second['deleted'], 'the removed town and the superseded code are pruned');
        $this->assertSame(
            ['3500235', '3500299'],
            JapanPostalCode::query()->orderBy('postal_code')->pluck('postal_code')->all()
        );
        $this->assertSame(
            [$second['batch']],
            JapanPostalCode::query()->distinct()->pluck('import_batch')->all(),
            'every surviving row belongs to the latest batch'
        );
    }

    public function testDuplicateLinesInTheFileAreImportedOnce(): void
    {
        $csv = $this->temporary(
            $this->writePostalCodeCsv([
                ['3500235', '埼玉県', '坂戸市', '三光町'],
                ['3500235', '埼玉県', '坂戸市', '三光町'],
            ])
        );

        $this->artisan('postal-codes:import', ['--file' => $csv])
            ->expectsOutputToContain('Imported 1 rows')
            ->assertSuccessful();

        $this->assertSame(1, JapanPostalCode::query()->count());
    }

    public function testEmptyFileLeavesTheExistingTableUntouched(): void
    {
        Log::spy();
        $this->seedJapanPostalCodes([['3500235', '埼玉県', '坂戸市', '三光町']]);
        $empty = $this->temporary(tempnam(sys_get_temp_dir(), 'ken_all_empty_'));

        $this->artisan('postal-codes:import', ['--file' => $empty])
            ->expectsOutputToContain('Import failed')
            ->assertFailed();

        $this->assertSame(['3500235'], JapanPostalCode::query()->pluck('postal_code')->all());
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(
                fn(string $message, array $context) => $message === 'Japan Post postal code import failed' &&
                    $context['source'] === $empty &&
                    $context['exception'] instanceof RuntimeException
            );
    }

    public function testFileTruncatedInsideAWrappedLineFailsAndLeavesTheTableUntouched(): void
    {
        $this->seedJapanPostalCodes([['3500235', '埼玉県', '坂戸市', '三光町']]);
        $csv = $this->temporary(
            $this->writePostalCodeCsv([
                ['3500201', '埼玉県', '坂戸市', '赤尾'],
                ['0600042', '北海道', '札幌市中央区', '大通西（１〜１０、'],
            ])
        );

        $this->artisan('postal-codes:import', ['--file' => $csv])
            ->expectsOutputToContain('probably truncated')
            ->assertFailed();

        $this->assertSame(['3500235'], JapanPostalCode::query()->pluck('postal_code')->all());
    }

    public function testDownloadsTheConfiguredZipWhenNoFileIsGiven(): void
    {
        config(['postal_codes.source_url' => 'https://example.test/utf_ken_all.zip']);
        $zip = $this->temporary($this->writePostalCodeZip([['3500235', '埼玉県', '坂戸市', '三光町']]));
        Http::fake(['example.test/*' => Http::response(file_get_contents($zip))]);

        $this->artisan('postal-codes:import')->assertSuccessful();

        Http::assertSent(fn($request) => $request->url() === 'https://example.test/utf_ken_all.zip');
        $this->assertSame(['3500235'], JapanPostalCode::query()->pluck('postal_code')->all());
    }

    public function testFailedDownloadFailsTheCommand(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->artisan('postal-codes:import', ['--url' => 'https://example.test/missing.zip'])
            ->expectsOutputToContain('HTTP 404')
            ->assertFailed();

        $this->assertSame(0, JapanPostalCode::query()->count());
    }

    private function temporary(string $path): string
    {
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
