<?php

namespace Tests\Unit\Exports;

use App\Exports\WorkHistoryDetailExport;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\TestCase;

class WorkHistoryDetailExportTest extends TestCase
{
    public function testWorkDateColumnIsWrittenAsExcelDate(): void
    {
        $content = Excel::raw(
            new WorkHistoryDetailExport([
                1 => [
                    [
                        'workplace_name' => 'WP',
                        'date' => '2026/5/9',
                        'shift_time' => '09:00-18:00',
                        'staff_no' => 'S1',
                        'name' => 'User',
                        'name_kana' => 'ユーザー',
                        'check_in_time' => 0.375,
                        'check_out_time' => 0.75,
                        'check_in_time_rounded' => 0.375,
                        'check_out_time_rounded' => 0.75,
                        'break_time' => '60',
                        'work_minutes' => 480,
                        'work_minutes_normal' => 480,
                        'work_minutes_night' => 0,
                        'work_minutes_overtime' => 0,
                        'work_minutes_night_overtime' => 0,
                        'check_in_id' => 1,
                    ],
                ],
            ]),
            ExcelFormat::XLSX
        );
        $path = tempnam(sys_get_temp_dir(), 'work_history_export_test_') . '.xlsx';
        file_put_contents($path, $content);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $cell = $sheet->getCell('B3');

            $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
            $this->assertSame(
                WorkHistoryDetailExport::DATE_FORMAT,
                $cell->getStyle()->getNumberFormat()->getFormatCode()
            );
            $this->assertSame('2026-05-09', ExcelDate::excelToDateTimeObject($cell->getValue())->format('Y-m-d'));
            $this->assertSame('Ngày làm', $sheet->getCell('B1')->getValue());
        } finally {
            unlink($path);
        }
    }
}
