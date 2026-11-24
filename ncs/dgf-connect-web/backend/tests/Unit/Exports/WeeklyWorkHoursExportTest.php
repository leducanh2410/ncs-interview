<?php

namespace Tests\Unit\Exports;

use App\Exports\WeeklyWorkHoursExport;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class WeeklyWorkHoursExportTest extends TestCase
{
    public function testRendersTranslatedHeadingsAndOneColumnPerWindow(): void
    {
        $sheet = $this->render($this->buildExport('vi'));

        $this->assertSame('Giờ làm theo tuần', $sheet->getTitle());
        $this->assertSame('Mã nhân viên', $sheet->getCell('A1')->getValue());
        $this->assertSame('Nhân viên', $sheet->getCell('B1')->getValue());
        $this->assertSame('Tình trạng cư trú', $sheet->getCell('C1')->getValue());
        $this->assertSame('Tình trạng giờ làm', $sheet->getCell('D1')->getValue());
        $this->assertSame('01/09/2026 ~ 07/09/2026', $sheet->getCell('E1')->getValue());
        $this->assertSame('02/09/2026 ~ 08/09/2026', $sheet->getCell('F1')->getValue());
        $this->assertSame('F', $sheet->getHighestColumn());
        $this->assertSame('C2', $sheet->getFreezePane());
    }

    public function testWritesStaffNoHoursAgainstLimitAndCustomLimitMarker(): void
    {
        $sheet = $this->render($this->buildExport('en'));

        $this->assertSame('0042', $sheet->getCell('A2')->getValue());
        $this->assertSame('YAMADA TARO', $sheet->getCell('B2')->getValue());
        $this->assertSame('Study Abroad', $sheet->getCell('C2')->getValue());
        $this->assertSame('Over limit', $sheet->getCell('D2')->getValue());
        $this->assertSame('27h / 28h', $sheet->getCell('E2')->getValue());
        $this->assertSame('36.5h / 40h*', $sheet->getCell('F2')->getValue());
        $this->assertNull($sheet->getCell('G2')->getValue());
        $this->assertSame('0043', $sheet->getCell('A3')->getValue());
        $this->assertNull($sheet->getCell('C3')->getValue());
        $this->assertSame('Within limit', $sheet->getCell('D3')->getValue());
        $this->assertSame('brand_new_status', $sheet->getCell('C4')->getValue());
    }

    public function testColorsStatusCellsLikeTheScreen(): void
    {
        $sheet = $this->render($this->buildExport('en'));

        $this->assertSame(WeeklyWorkHoursExport::STATUS_COLORS['over']['fill'], $this->fillOf($sheet, 'D2'));
        $this->assertSame(WeeklyWorkHoursExport::STATUS_COLORS['over']['font'], $this->fontColorOf($sheet, 'D2'));
        $this->assertSame(WeeklyWorkHoursExport::STATUS_COLORS['near']['fill'], $this->fillOf($sheet, 'E2'));
        $this->assertSame(WeeklyWorkHoursExport::STATUS_COLORS['over']['fill'], $this->fillOf($sheet, 'F2'));
        $this->assertSame(WeeklyWorkHoursExport::STATUS_COLORS['normal']['fill'], $this->fillOf($sheet, 'D3'));
        $this->assertSame(WeeklyWorkHoursExport::STATUS_COLORS['normal']['fill'], $this->fillOf($sheet, 'E3'));
        $this->assertTrue($sheet->getStyle('F2')->getFont()->getBold());
        $this->assertFalse($sheet->getStyle('E3')->getFont()->getBold());
        $this->assertNotSame(WeeklyWorkHoursExport::STATUS_COLORS['over']['fill'], $this->fillOf($sheet, 'A2'));
        $this->assertSame(WeeklyWorkHoursExport::STATUS_COLORS['over']['fill'], $this->fillOf($sheet, 'D4'));
        $this->assertSame(WeeklyWorkHoursExport::STATUS_COLORS['over']['fill'], $this->fillOf($sheet, 'F4'));
        $this->assertNotSame(WeeklyWorkHoursExport::STATUS_COLORS['over']['fill'], $this->fillOf($sheet, 'G4'));
    }

    private function buildExport(string $locale): WeeklyWorkHoursExport
    {
        $windows = [['start' => '2026-09-01', 'end' => '2026-09-07'], ['start' => '2026-09-02', 'end' => '2026-09-08']];
        $near = $this->summary('2026-09-01', '2026-09-07', 27, 28, false, 'near');
        $over = $this->summary('2026-09-02', '2026-09-08', 36.5, 40, true, 'over');
        $normal = $this->summary('2026-09-01', '2026-09-07', 9, 28, false, 'normal');
        $quiet = $this->summary('2026-09-02', '2026-09-08', 0, 28, false, 'normal');

        return new WeeklyWorkHoursExport(
            $windows,
            [
                [
                    'user' => ['id' => 1, 'name' => 'YAMADA TARO', 'staff_no' => '0042', 'profile_picture' => null],
                    'residence_status' => 'study_abroad',
                    'weeks' => [$near, $over],
                    'status' => 'over',
                ],
                [
                    'user' => ['id' => 2, 'name' => 'SUZUKI HANA', 'staff_no' => '0043', 'profile_picture' => null],
                    'residence_status' => null,
                    'weeks' => [$normal, $quiet],
                    'status' => 'normal',
                ],
                [
                    'user' => ['id' => 3, 'name' => 'SATO KEN', 'staff_no' => '0044', 'profile_picture' => null],
                    'residence_status' => 'brand_new_status',
                    'weeks' => [$over, $over],
                    'status' => 'over',
                ],
            ],
            $locale
        );
    }

    private function summary(
        string $start,
        string $end,
        float $totalHours,
        int $maxHourPerWeek,
        bool $isCustomLimit,
        string $status
    ): array {
        return [
            'start' => $start,
            'end' => $end,
            'total_hours' => $totalHours,
            'actual_hours' => 0,
            'scheduled_hours' => $totalHours,
            'max_hour_per_week' => $maxHourPerWeek,
            'is_custom_limit' => $isCustomLimit,
            'is_limit_changed' => false,
            'status' => $status,
        ];
    }

    private function fillOf(Worksheet $sheet, string $cell): string
    {
        return $sheet->getStyle($cell)->getFill()->getStartColor()->getRGB();
    }

    private function fontColorOf(Worksheet $sheet, string $cell): string
    {
        return $sheet->getStyle($cell)->getFont()->getColor()->getRGB();
    }

    private function render(WeeklyWorkHoursExport $export): Worksheet
    {
        $reserved = tempnam(sys_get_temp_dir(), 'export');
        $path = $reserved . '.xlsx';
        file_put_contents($path, Excel::raw($export, ExcelWriter::XLSX));

        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
            @unlink($reserved);
        }
    }
}
