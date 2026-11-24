<?php

namespace Tests\Concerns;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait BuildsAmazonKadouWorkbook
{
    /**
     * @param list<array{name: string, date?: ?string, punch_in?: ?string, punch_out?: ?string, break?: float, scheduled_in?: ?string, scheduled_out?: ?string, work_in?: ?string, work_out?: ?string, summary?: array<string, float>}> $rows
     */
    protected function writeAmazonKadouFile(string $path, array $rows, string $company = 'DGF人材開発'): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('DGF人材開発');
        $sheet->setCellValue('A1', '稼働実績一覧カドウジッセキイチラン');
        $sheet->setCellValue('A2', '会社名：カイシャメイ');
        $sheet->setCellValue('B2', $company);
        $sheet->setCellValue('A4', '氏名シメイ');
        $sheet->setCellValue('B4', '作業日サギョウビ');
        $sheet->setCellValue('C4', '予定時間ヨテイジカン');
        $sheet->setCellValue('F4', '実打刻時間ジツダコクジカン');
        $sheet->setCellValue('I4', '作業時間');
        $sheet->setCellValue('L4', '実働時間');
        $sheet->setCellValue('M4', '休憩時間');
        $sheet->setCellValue('P4', '所定内労働時間');
        $sheet->setCellValue('Q4', '残業時間');
        $sheet->setCellValue('R4', '深夜時間');
        $sheet->setCellValue('S4', '深夜残業時間');
        $sheet->mergeCells('C4:E4');
        $sheet->mergeCells('F4:H4');
        $sheet->mergeCells('I4:K4');
        $sheet->setCellValue('A5', '計ケイ');

        $excelRow = 6;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$excelRow}", $row['name']);
            if (!empty($row['date'])) {
                $this->writeExcelDate($sheet, "B{$excelRow}", $row['date'] . ' 00:00:00');
            }
            if (!empty($row['scheduled_in']) && !empty($row['scheduled_out'])) {
                $this->writeExcelDate($sheet, "C{$excelRow}", $row['scheduled_in']);
                $sheet->setCellValue("D{$excelRow}", '～');
                $this->writeExcelDate($sheet, "E{$excelRow}", $row['scheduled_out']);
            }
            if (!empty($row['punch_in']) || !empty($row['punch_out'])) {
                if (!empty($row['punch_in'])) {
                    $this->writeExcelDate($sheet, "F{$excelRow}", $row['punch_in']);
                }
                $sheet->setCellValue("G{$excelRow}", '～');
                if (!empty($row['punch_out'])) {
                    $this->writeExcelDate($sheet, "H{$excelRow}", $row['punch_out']);
                }
            }
            $workIn = array_key_exists('work_in', $row) ? $row['work_in'] : $row['punch_in'] ?? null;
            $workOut = array_key_exists('work_out', $row) ? $row['work_out'] : $row['punch_out'] ?? null;
            if (!empty($workIn) || !empty($workOut)) {
                if (!empty($workIn)) {
                    $this->writeExcelDate($sheet, "I{$excelRow}", $workIn);
                }
                $sheet->setCellValue("J{$excelRow}", '～');
                if (!empty($workOut)) {
                    $this->writeExcelDate($sheet, "K{$excelRow}", $workOut);
                }
            }
            $sheet->setCellValue("M{$excelRow}", $row['break'] ?? 0);
            foreach (
                [
                    'work_minutes' => 'L',
                    'work_minutes_normal' => 'P',
                    'work_minutes_overtime' => 'Q',
                    'work_minutes_night' => 'R',
                    'work_minutes_night_overtime' => 'S',
                ]
                as $key => $column
            ) {
                if (isset($row['summary'][$key])) {
                    $sheet->setCellValue("{$column}{$excelRow}", $row['summary'][$key]);
                }
            }
            $excelRow++;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function writeExcelDate(Worksheet $sheet, string $cell, string $datetime): void
    {
        $dt = new DateTimeImmutable($datetime);
        $serial = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($dt);
        $sheet->setCellValue($cell, $serial);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DATETIME);
    }
}
