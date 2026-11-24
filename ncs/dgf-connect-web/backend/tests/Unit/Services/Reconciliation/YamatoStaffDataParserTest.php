<?php

namespace Tests\Unit\Services\Reconciliation;

use App\Services\Reconciliation\ReconciliationFormatException;
use App\Services\Reconciliation\YamatoStaffDataParser;
use Tests\Concerns\BuildsStaffTimesheetWorkbooks;
use Tests\TestCase;

class YamatoStaffDataParserTest extends TestCase
{
    use BuildsStaffTimesheetWorkbooks;

    private YamatoStaffDataParser $parser;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new YamatoStaffDataParser();
        $this->path = sys_get_temp_dir() . '/yamato-' . uniqid() . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function testParsesOvernightPaidTimesBreakAndSummary(): void
    {
        $this->writeYamatoFile($this->path, [
            [
                'staff_no' => 25608,
                'name' => 'ADHIKA SUDIP',
                'name_kana' => 'アドカリ スヂップ',
                'date' => '2026-09-01',
                'in' => '22:00',
                'out' => '30:00',
                'breaks' => [['25:00', '26:00']],
                'summary' => [
                    'work_minutes' => 420,
                    'break_total' => 60,
                    'work_minutes_normal' => 60,
                    'work_minutes_overtime' => 0,
                    'work_minutes_night' => 360,
                    'work_minutes_night_overtime' => 0,
                ],
            ],
        ]);

        $parsed = $this->parser->parse($this->path);
        $row = $parsed['punch_rows'][0];

        $this->assertSame('ヤマト運輸株式会社 武蔵野ベース', $parsed['company_label']);
        $this->assertSame('25608', $row->staffNo);
        $this->assertSame('アドカリ スヂップ', $row->externalName);
        $this->assertSame('2026-09-01', $row->workDate);
        $this->assertSame('2026-09-01 22:00:00', $row->checkIn->toDateTimeString());
        $this->assertSame('2026-09-02 06:00:00', $row->checkOut->toDateTimeString());
        $this->assertSame(60, $row->breakMinutes);
        $this->assertSame(
            [
                'work_minutes' => 420,
                'work_minutes_normal' => 60,
                'work_minutes_overtime' => 0,
                'work_minutes_night' => 360,
                'work_minutes_night_overtime' => 0,
            ],
            $row->raw['summary']
        );
        $this->assertSame(3, $row->raw['source_row']);
    }

    public function testHeaderOnFirstRowIsAlsoAccepted(): void
    {
        $this->writeYamatoFile(
            $this->path,
            [['staff_no' => 1, 'name_kana' => 'テスト', 'date' => '2026-09-01', 'in' => '09:00', 'out' => '18:00']],
            false
        );

        $this->assertCount(1, $this->parser->parse($this->path)['punch_rows']);
    }

    public function testSumsEveryBreakSlot(): void
    {
        $this->writeYamatoFile($this->path, [
            [
                'staff_no' => 1,
                'name_kana' => 'テスト',
                'date' => '2026-09-01',
                'in' => '09:00',
                'out' => '20:00',
                'breaks' => [['12:00', '13:00'], ['17:00', '17:15']],
            ],
        ]);

        $this->assertSame(75, $this->parser->parse($this->path)['punch_rows'][0]->breakMinutes);
    }

    public function testFallsBackToBreakTotalWithoutBreakSlots(): void
    {
        $this->writeYamatoFile($this->path, [
            [
                'staff_no' => 1,
                'name_kana' => 'テスト',
                'date' => '2026-09-01',
                'in' => '09:00',
                'out' => '18:00',
                'summary' => ['break_total' => 45],
            ],
        ]);

        $this->assertSame(45, $this->parser->parse($this->path)['punch_rows'][0]->breakMinutes);
    }

    public function testAbsentRowWithoutPaidTimesIsCountedAsSkipped(): void
    {
        $this->writeYamatoFile($this->path, [
            ['staff_no' => 1, 'name_kana' => 'テスト', 'date' => '2026-09-01', 'in' => '09:00', 'out' => '18:00'],
            ['staff_no' => 2, 'name_kana' => 'ケッキン', 'date' => '2026-09-01'],
        ]);

        $parsed = $this->parser->parse($this->path);

        $this->assertCount(1, $parsed['punch_rows']);
        $this->assertSame(1, $parsed['skipped_non_punch_count']);
    }

    public function testRowWithTimesButNoStaffNoOrNameIsRejected(): void
    {
        $this->writeYamatoFile($this->path, [['date' => '2026-09-01', 'in' => '09:00', 'out' => '18:00']]);

        try {
            $this->parser->parse($this->path);
            $this->fail('Expected ReconciliationFormatException');
        } catch (ReconciliationFormatException $exception) {
            $this->assertSame('missing_name', $exception->reason);
            $this->assertSame(['row' => 3], $exception->details);
        }
    }

    public function testRowWithTimesButNoWorkDateIsRejected(): void
    {
        $this->writeYamatoFile($this->path, [
            ['staff_no' => 1, 'name_kana' => 'テスト', 'in' => '09:00', 'out' => '18:00'],
        ]);

        $this->expectException(ReconciliationFormatException::class);
        $this->expectExceptionMessage('invalid_work_date');

        $this->parser->parse($this->path);
    }

    public function testFactoryWorkbookIsRejectedAsHeaderMismatch(): void
    {
        $this->writeFactoryFile($this->path, []);

        $this->expectException(ReconciliationFormatException::class);
        $this->expectExceptionMessage('header_mismatch');

        $this->parser->parse($this->path);
    }
}
