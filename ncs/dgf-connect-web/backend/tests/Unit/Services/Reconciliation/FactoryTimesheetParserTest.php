<?php

namespace Tests\Unit\Services\Reconciliation;

use App\Services\Reconciliation\FactoryTimesheetParser;
use App\Services\Reconciliation\ReconciliationFormatException;
use Tests\Concerns\BuildsStaffTimesheetWorkbooks;
use Tests\TestCase;

class FactoryTimesheetParserTest extends TestCase
{
    use BuildsStaffTimesheetWorkbooks;

    private FactoryTimesheetParser $parser;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new FactoryTimesheetParser();
        $this->path = sys_get_temp_dir() . '/factory-' . uniqid() . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function testParsesTimesBreakAndHoursBreakdown(): void
    {
        $this->writeFactoryFile($this->path, [
            [
                'staff_no' => 39008,
                'name' => 'BIYAGAMA LAKRUWAN',
                'date' => '2026-08-02',
                'in' => '17:00',
                'out' => '25:30',
                'break' => 60,
                'summary' => [
                    'work_minutes_normal' => 300,
                    'work_minutes_overtime' => 0,
                    'work_minutes_night' => 150,
                    'work_minutes_night_overtime' => 0,
                ],
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $row = $parsed['punch_rows'][0];

        $this->assertSame('支払い8月', $parsed['company_label']);
        $this->assertSame('39008', $row->staffNo);
        $this->assertSame('BIYAGAMA LAKRUWAN', $row->externalName);
        $this->assertSame('2026-08-02 17:00:00', $row->checkIn->toDateTimeString());
        $this->assertSame('2026-08-03 01:30:00', $row->checkOut->toDateTimeString());
        $this->assertSame(60, $row->breakMinutes);
        $this->assertSame(
            [
                'work_minutes' => null,
                'work_minutes_normal' => 300,
                'work_minutes_overtime' => 0,
                'work_minutes_night' => 150,
                'work_minutes_night_overtime' => 0,
            ],
            $row->raw['summary']
        );
    }

    public function testCheckOutWrittenAsTimeOfDayRollsOverMidnight(): void
    {
        $this->writeFactoryFile($this->path, [
            ['staff_no' => 1, 'name' => 'A', 'date' => '2026-08-02', 'in' => '22:00', 'out' => '05:00'],
        ]);

        $row = $this->parser->parse($this->path)['punch_rows'][0];

        $this->assertSame('2026-08-03 05:00:00', $row->checkOut->toDateTimeString());
    }

    public function testStaffNoWithoutNameUsesStaffNoAsExternalName(): void
    {
        $this->writeFactoryFile($this->path, [
            ['staff_no' => 's57', 'date' => '2026-08-02', 'in' => '07:00', 'out' => '16:00'],
        ]);

        $row = $this->parser->parse($this->path)['punch_rows'][0];

        $this->assertSame('s57', $row->externalName);
        $this->assertSame('s57', $row->staffNo);
    }

    public function testPaymentSheetIsPreferredOverWeeklySheetsWithTheSameHeader(): void
    {
        $this->writeFactoryFile(
            $this->path,
            [['staff_no' => 1, 'name' => 'A', 'date' => '2026-08-02', 'in' => '07:00', 'out' => '16:00']],
            '支払い8月',
            ['2026年8月2日~8月6日', '2026年8月9日~8月13日']
        );

        $parsed = $this->parser->parse($this->path);

        $this->assertSame('支払い8月', $parsed['company_label']);
        $this->assertCount(1, $parsed['punch_rows']);
    }

    public function testSeveralMatchingSheetsWithoutPaymentSheetAreRejected(): void
    {
        $this->writeFactoryFile($this->path, [], '2026年8月16日~8月20日', ['2026年8月2日~8月6日']);

        try {
            $this->parser->parse($this->path);
            $this->fail('Expected ReconciliationFormatException');
        } catch (ReconciliationFormatException $exception) {
            $this->assertSame('multiple_sheets', $exception->reason);
            $this->assertSame(['2026年8月2日~8月6日', '2026年8月16日~8月20日'], $exception->details['sheets']);
        }
    }

    public function testRowsWithoutTimesAreCountedAsSkipped(): void
    {
        $this->writeFactoryFile($this->path, [
            ['staff_no' => 1, 'name' => 'A', 'date' => '2026-08-02', 'in' => '07:00', 'out' => '16:00'],
            ['staff_no' => 2, 'name' => 'B', 'date' => '2026-08-02'],
        ]);

        $parsed = $this->parser->parse($this->path);

        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(1, $parsed['skipped_non_punch_count']);
    }

    public function testBreakTypedAsPlainMinutesIsRejectedWithItsRowAndColumn(): void
    {
        $this->writeFactoryFile($this->path, [
            [
                'staff_no' => 1,
                'name' => 'A',
                'date' => '2026-08-02',
                'in' => '07:00',
                'out' => '16:00',
                'raw_cells' => ['H' => 60],
            ],
        ]);

        try {
            $this->parser->parse($this->path);
            $this->fail('Expected ReconciliationFormatException');
        } catch (ReconciliationFormatException $exception) {
            $this->assertSame('invalid_time', $exception->reason);
            $this->assertSame('H', $exception->details['column']);
            $this->assertIsInt($exception->details['row']);
        }
    }

    public function testCheckInTypedAsDecimalHoursIsRejected(): void
    {
        $this->writeFactoryFile($this->path, [
            [
                'staff_no' => 1,
                'name' => 'A',
                'date' => '2026-08-02',
                'out' => '16:00',
                'raw_cells' => ['F' => 8.5],
            ],
        ]);

        try {
            $this->parser->parse($this->path);
            $this->fail('Expected ReconciliationFormatException');
        } catch (ReconciliationFormatException $exception) {
            $this->assertSame('invalid_time', $exception->reason);
            $this->assertSame('F', $exception->details['column']);
        }
    }

    public function testOvernightClockPastTwentyFourHoursIsStillAccepted(): void
    {
        $this->writeFactoryFile($this->path, [
            [
                'staff_no' => 1,
                'name' => 'A',
                'date' => '2026-08-02',
                'in' => '22:00',
                'raw_cells' => ['G' => '31:15'],
            ],
        ]);

        $row = $this->parser->parse($this->path)['punch_rows'][0];

        $this->assertSame('2026-08-03 07:15:00', $row->checkOut->toDateTimeString());
    }

    public function testYamatoWorkbookIsRejectedAsHeaderMismatch(): void
    {
        $this->writeYamatoFile($this->path, []);

        $this->expectException(ReconciliationFormatException::class);
        $this->expectExceptionMessage('header_mismatch');

        $this->parser->parse($this->path);
    }
}
