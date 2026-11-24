<?php

namespace Tests\Unit\Exports;

use App\Exports\ReconciliationWorkHistoryExport;
use App\Exports\WorkHistoryDetailExport;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class ReconciliationWorkHistoryExportTest extends TestCase
{
    public function testAddsHiddenCheckInIdWhenInnerHeadingsLackIt(): void
    {
        $group = [
            1 => [
                [
                    'workplace_name' => 'WP A',
                    'date' => '2026/8/25',
                    'shift_time' => '21:00-30:00',
                    'staff_no' => 'S1',
                    'name' => 'Test User',
                    'name_kana' => 'テスト',
                    'check_in_time' => 20.19 / 24,
                    'check_out_time' => 6.03 / 24,
                    'check_in_time_rounded' => 21 / 24,
                    'check_out_time_rounded' => 6 / 24,
                    'break_time' => '60',
                    'work_minutes' => 480,
                    'work_minutes_normal' => 480,
                    'work_minutes_night' => 0,
                    'work_minutes_overtime' => 0,
                    'work_minutes_night_overtime' => 0,
                    'check_in_id' => 99,
                ],
            ],
        ];

        $export = new ReconciliationWorkHistoryExport($group);
        $headings = $export->headings()[0];

        $this->assertSame('Xưởng', $headings[0]);
        $this->assertSame('Check_In_ID', $headings[16]);
        $this->assertSame(1, count(array_filter($headings, fn($h) => $h === 'Check_In_ID')));

        $dataRows = $export->collection()->filter(fn($row) => is_array($row) && isset($row['staff_no']))->values();
        $this->assertSame(99, $dataRows[0]['check_in_id']);
    }

    public function testDoesNotDuplicateCheckInIdWhenInnerAlreadyHasIt(): void
    {
        $inner = new class ([]) extends WorkHistoryDetailExport {
            public function headings(): array
            {
                return [['Xưởng', 'Ngày làm', 'Check_In_ID']];
            }

            public function collection(): Collection
            {
                return collect([
                    [
                        'workplace_name' => 'WP',
                        'date' => '2026/8/25',
                        'staff_no' => 'S1',
                        'check_in_id' => 7,
                    ],
                ]);
            }

            public function columnWidths(): array
            {
                return [];
            }

            public function defaultStyles(Style $defaultStyle): array
            {
                return [];
            }

            public function styles(Worksheet $sheet): void {}
        };

        $export = new ReconciliationWorkHistoryExport([], $inner);
        $headings = $export->headings()[0];

        $this->assertSame(1, count(array_filter($headings, fn($h) => $h === 'Check_In_ID')));
        $this->assertSame('Check_In_ID', $headings[array_key_last($headings)]);
        $this->assertSame(7, $export->collection()->first()['check_in_id']);
    }
}
