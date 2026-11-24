<?php

namespace Tests\Unit\Imports;

use App\Exports\WorkHistoryDetailExport;
use App\Imports\WorkHistoryDetailImport;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class WorkHistoryDetailImportTest extends TestCase
{
    public function testRoundTripsARealExportedWorkbookIntoARawGrid(): void
    {
        $rows = [
            'workplace_name' => 'Tokyo Office',
            'date' => '2026/8/20',
            'shift_time' => '09:00-18:00',
            'staff_no' => 'E001',
            'name' => 'Yamada Taro',
            'name_kana' => 'ヤマダ タロウ',
            'check_in_time' => (9 * 60) / 1440,
            'check_out_time' => (18 * 60) / 1440,
            'check_in_time_rounded' => (9 * 60) / 1440,
            'check_out_time_rounded' => (18 * 60) / 1440,
            'break_time' => 60 / 1440,
            'work_minutes' => 480 / 1440,
            'work_minutes_normal' => 480 / 1440,
            'work_minutes_night' => 0.0,
            'work_minutes_overtime' => 0.0,
            'work_minutes_night_overtime' => 0.0,
            'check_in_id' => 4821,
        ];

        $path = $this->writeRealExportToTempFile([1 => [$rows]]);

        try {
            $import = new WorkHistoryDetailImport();
            Excel::import($import, $path);
            $grid = $import->rows();

            // Header row (1), workplace-header row (2), one data row (3), two blank separator
            // rows (4, 5) - the exact shape App\Exports\WorkHistoryDetailExport writes.
            $this->assertCount(5, $grid);
            $this->assertSame('Xưởng', $grid[0][0]);
            $this->assertSame('Check_In_ID', $grid[0][16]);
            $this->assertSame('Tokyo Office', $grid[1][0]);
            $this->assertSame('Tokyo Office', $grid[2][0]);
            $this->assertSame('E001', $grid[2][3]);
            $this->assertSame(4821, $grid[2][16]);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $groupByWorkplace
     */
    private function writeRealExportToTempFile(array $groupByWorkplace): string
    {
        $content = Excel::raw(new WorkHistoryDetailExport($groupByWorkplace), ExcelFormat::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'work_history_import_test_') . '.xlsx';
        file_put_contents($path, $content);

        return $path;
    }
}
