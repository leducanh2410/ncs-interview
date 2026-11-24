<?php

namespace Tests\Unit\Services\Reconciliation;

use App\Services\Reconciliation\AmazonKadouParser;
use App\Services\Reconciliation\ReconciliationFormatException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsAmazonKadouWorkbook;
use Tests\TestCase;

class AmazonKadouParserTest extends TestCase
{
    use BuildsAmazonKadouWorkbook;

    private AmazonKadouParser $parser;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AmazonKadouParser();
        $this->path = sys_get_temp_dir() . '/amazon-kadou-' . uniqid() . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function testParsesSampleCountsAndWorkDate(): void
    {
        $rows = [];
        for ($i = 0; $i < 48; $i++) {
            $rows[] = [
                'name' => "Punch User {$i}",
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ];
        }
        for ($i = 0; $i < 208; $i++) {
            $rows[] = [
                'name' => "Skip User {$i}",
                'date' => '2026-08-25',
            ];
        }
        $this->writeAmazonKadouFile($this->path, $rows);

        $parsed = $this->parser->parse($this->path);

        $this->assertCount(48, $parsed['punch_rows']);
        $this->assertSame(208, $parsed['skipped_non_punch_count']);
        $this->assertSame('2026-08-25', $parsed['punch_rows'][0]->workDate);
    }

    public function testParsesOvernightPunchDatetimes(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $row = $this->parser->parse($this->path)['punch_rows'][0];

        $this->assertSame('20:19', $row->checkIn->format('H:i'));
        $this->assertSame('2026-08-25', $row->checkIn->format('Y-m-d'));
        $this->assertSame('06:03', $row->checkOut->format('H:i'));
        $this->assertSame('2026-08-26', $row->checkOut->format('Y-m-d'));
    }

    public function testConvertsBreakDayFractionTo60Minutes(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Test User',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->assertSame(60, $this->parser->parse($this->path)['punch_rows'][0]->breakMinutes);
    }

    public function testRejectsBreakTypedAsPlainMinutes(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Test User',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60,
            ],
        ]);

        try {
            $this->parser->parse($this->path);
            $this->fail('Expected ReconciliationFormatException');
        } catch (ReconciliationFormatException $exception) {
            $this->assertSame('invalid_time', $exception->reason);
            $this->assertIsInt($exception->details['row']);
        }
    }

    public function testCollapsesIdeographicSpaceInName(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => "パテル バルジュ\u{3000}クマル",
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->assertSame('パテル バルジュ クマル', $this->parser->parse($this->path)['punch_rows'][0]->externalName);
    }

    public function testIgnoresSoukeiSummaryRowWithoutIncrementingSkipped(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => '総計',
            ],
            [
                'name' => 'Has Punch',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(0, $parsed['skipped_non_punch_count']);
        $this->assertSame('Has Punch', $parsed['punch_rows'][0]->externalName);
    }

    public function testIgnoresSoukeiSummaryRowEvenWhenPunchCellsHaveValues(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => '総合計',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 8 / 1440,
            ],
            [
                'name' => 'Has Punch',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(0, $parsed['skipped_non_punch_count']);
    }

    public function testParsesEmployeeNameStartingWithKei(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => '計良 太郎',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame('計良 太郎', $parsed['punch_rows'][0]->externalName);
        $this->assertSame(0, $parsed['skipped_non_punch_count']);
    }

    public function testSkipsPartialPunchAsNonPunch(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Partial Punch',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
            ],
            [
                'name' => 'Has Punch',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(1, $parsed['skipped_non_punch_count']);
        $this->assertSame('Has Punch', $parsed['punch_rows'][0]->externalName);
    }

    public function testSkipsRowsWithoutPunchLog(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            ['name' => 'No Punch', 'date' => '2026-08-25'],
            [
                'name' => 'Has Punch',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(1, $parsed['skipped_non_punch_count']);
        $this->assertSame('Has Punch', $parsed['punch_rows'][0]->externalName);
    }

    public function testRejectsPunchRowWithoutName(): void
    {
        // 氏名 merged/dragged away, but 作業日 and both 作業時間 ends are there:
        // dropping this row silently would hide a real Amazon punch from the
        // batch with nothing in the counts to show for it.
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => '',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->expectException(ReconciliationFormatException::class);
        $this->parser->parse($this->path);
    }

    public function testReadsNameMergedDownAnEmployeesRows(): void
    {
        // Amazon reports routinely merge 氏名 vertically across an employee's
        // daily rows; PhpSpreadsheet holds the value only in the range's
        // top-left cell, so every later day reads back as blank.
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => '',
                'date' => '2026-08-26',
                'punch_in' => '2026-08-26 20:19:00',
                'punch_out' => '2026-08-27 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $spreadsheet = IOFactory::load($this->path);
        $spreadsheet->getActiveSheet()->mergeCells('A6:A7');
        (new Xlsx($spreadsheet))->save($this->path);
        $spreadsheet->disconnectWorksheets();

        $parsed = $this->parser->parse($this->path);

        $this->assertCount(2, $parsed['punch_rows']);
        $this->assertSame(0, $parsed['skipped_non_punch_count']);
        $this->assertSame('ウィジェシンヘ サミシカ', $parsed['punch_rows'][1]->externalName);
        $this->assertSame('2026-08-26', $parsed['punch_rows'][1]->workDate);
    }

    public function testIgnoresUnnamedTotalRowWithPunchLikeValues(): void
    {
        // A totals row carries no 作業日 (that is what isSummaryRow() relies on),
        // so time-formatted totals in the 作業時間 columns must not be mistaken for
        // an unattributable punch row and reject the whole file.
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => '',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 8 / 1440,
            ],
            [
                'name' => 'Has Punch',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(0, $parsed['skipped_non_punch_count']);
    }

    public function testCountsUnnamedNonPunchRowAsSkipped(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            ['name' => '', 'date' => '2026-08-25'],
            [
                'name' => 'Has Punch',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(1, $parsed['skipped_non_punch_count']);
    }

    public function testIgnoresRowWithNeitherNameNorWorkDateWithoutCountingIt(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            ['name' => ''],
            [
                'name' => 'Has Punch',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:00:00',
                'punch_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(0, $parsed['skipped_non_punch_count']);
    }

    public function testReadsWorkDateAndPunchesWrittenAsFormulas(): void
    {
        // Hand-edited files routinely carry `=B6`-style references in 作業日 /
        // 作業時間; reading getValue() there would return the formula text and
        // silently demote a real punch row to non-punch.
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Source Row',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => 'Formula Row',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $spreadsheet = IOFactory::load($this->path);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('B7', '=B6');
        $sheet->setCellValue('I7', '=I6');
        $sheet->setCellValue('K7', '=K6');
        (new Xlsx($spreadsheet))->save($this->path);
        $spreadsheet->disconnectWorksheets();

        $parsed = $this->parser->parse($this->path);

        $this->assertCount(2, $parsed['punch_rows']);
        $this->assertSame(0, $parsed['skipped_non_punch_count']);
        $this->assertSame('2026-08-25', $parsed['punch_rows'][1]->workDate);
        $this->assertSame('20:19', $parsed['punch_rows'][1]->checkIn->format('H:i'));
        $this->assertSame('06:03', $parsed['punch_rows'][1]->checkOut->format('H:i'));
    }

    public function testReportsAnUnresolvableFormulaAsAFormatError(): void
    {
        // getCalculatedValue() evaluates formulas, so unlike getValue() it can
        // throw. That has to surface as a ReconciliationFormatException the
        // upload maps to 422, not as a raw PhpSpreadsheet 500.
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Formula Row',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $spreadsheet = IOFactory::load($this->path);
        $spreadsheet->getActiveSheet()->setCellValue('I6', '=SUM(');
        $writer = new Xlsx($spreadsheet);
        // The writer evaluates formulas on save, so it would blow up before the
        // file even exists.
        $writer->setPreCalculateFormulas(false);
        $writer->save($this->path);
        $spreadsheet->disconnectWorksheets();

        try {
            $this->parser->parse($this->path);
            $this->fail('Expected a ReconciliationFormatException.');
        } catch (ReconciliationFormatException $exception) {
            $this->assertSame('invalid_formula', $exception->reason);
            $this->assertSame(6, $exception->details['row']);
        }
    }

    public function testUsesOnlyPaidWorkTimeColumns(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Test User',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'scheduled_in' => '2026-08-25 20:30:00',
                'scheduled_out' => '2026-08-26 05:30:00',
                'work_in' => '2026-08-25 21:00:00',
                'work_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);

        $row = $this->parser->parse($this->path)['punch_rows'][0];
        $this->assertSame('2026-08-25 21:00', $row->checkIn->format('Y-m-d H:i'));
        $this->assertSame('2026-08-26 06:00', $row->checkOut->format('Y-m-d H:i'));
        $this->assertArrayNotHasKey('scheduled_range', $row->raw);
        $this->assertArrayNotHasKey('punch_range', $row->raw);
        $this->assertSame('21:00 ~ 06:00', $row->raw['work_time_range']);
    }

    public function testReadsHourBreakdownColumns(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Test User',
                'date' => '2026-08-25',
                'work_in' => '2026-08-25 21:00:00',
                'work_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
                'summary' => [
                    'work_minutes' => 480 / 1440,
                    'work_minutes_normal' => 60 / 1440,
                    'work_minutes_overtime' => 0,
                    'work_minutes_night' => 405 / 1440,
                    'work_minutes_night_overtime' => 15 / 1440,
                ],
            ],
        ]);

        $row = $this->parser->parse($this->path)['punch_rows'][0];
        $this->assertSame(
            [
                'work_minutes' => 480,
                'work_minutes_normal' => 60,
                'work_minutes_overtime' => 0,
                'work_minutes_night' => 405,
                'work_minutes_night_overtime' => 15,
            ],
            $row->raw['summary']
        );
    }

    public function testReadsHourBreakdownHeaderWrittenAcrossLines(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Test User',
                'date' => '2026-08-25',
                'work_in' => '2026-08-25 09:00:00',
                'work_out' => '2026-08-25 18:00:00',
                'summary' => ['work_minutes_normal' => 480 / 1440],
            ],
        ]);
        $spreadsheet = IOFactory::load($this->path);
        $spreadsheet->getActiveSheet()->setCellValue('P4', "所定内\n労働時間");
        (new Xlsx($spreadsheet))->save($this->path);
        $spreadsheet->disconnectWorksheets();

        $row = $this->parser->parse($this->path)['punch_rows'][0];
        $this->assertSame(480, $row->raw['summary']['work_minutes_normal']);
    }

    public function testRowWithPaidWorkTimeButNoPunchIsImported(): void
    {
        $this->writeAmazonKadouFile($this->path, [
            [
                'name' => 'Test User',
                'date' => '2026-08-25',
                'work_in' => '2026-08-25 09:00:00',
                'work_out' => '2026-08-25 11:00:00',
            ],
            [
                'name' => 'Punch Only',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 09:00:00',
                'punch_out' => '2026-08-25 11:00:00',
                'work_in' => null,
                'work_out' => null,
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame('Test User', $parsed['punch_rows'][0]->externalName);
        $this->assertSame(1, $parsed['skipped_non_punch_count']);
    }

    public function testRejectsMissingHeaders(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'not a kadou file');
        (new Xlsx($spreadsheet))->save($this->path);

        $this->expectException(ReconciliationFormatException::class);
        $this->parser->parse($this->path);
    }

    public function testStoresCompanyLabelWithoutUsingItAsWorkplace(): void
    {
        $this->writeAmazonKadouFile(
            $this->path,
            [
                [
                    'name' => 'Test User',
                    'date' => '2026-08-25',
                    'punch_in' => '2026-08-25 20:19:00',
                    'punch_out' => '2026-08-26 06:03:00',
                    'break' => 60 / 1440,
                ],
            ],
            'DGF人材開発'
        );

        $parsed = $this->parser->parse($this->path);
        $this->assertSame('DGF人材開発', $parsed['company_label']);
        $this->assertArrayNotHasKey('workplace', $parsed['punch_rows'][0]->raw);
    }

    public function testKeepsDuplicateNameAndDateAsTwoRows(): void
    {
        $row = [
            'name' => 'Same Name',
            'date' => '2026-08-25',
            'punch_in' => '2026-08-25 20:19:00',
            'punch_out' => '2026-08-26 06:03:00',
            'break' => 60 / 1440,
        ];
        $this->writeAmazonKadouFile($this->path, [$row, $row]);

        $parsed = $this->parser->parse($this->path);
        $this->assertCount(2, $parsed['punch_rows']);
        $this->assertSame('Same Name', $parsed['punch_rows'][0]->externalName);
        $this->assertSame('Same Name', $parsed['punch_rows'][1]->externalName);
    }
}
