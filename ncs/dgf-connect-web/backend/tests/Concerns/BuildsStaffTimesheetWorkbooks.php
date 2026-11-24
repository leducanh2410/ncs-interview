<?php

namespace Tests\Concerns;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait BuildsStaffTimesheetWorkbooks
{
    /**
     * @param list<array{staff_no?: string|int|null, name_kana?: ?string, name?: ?string, date?: ?string, in?: ?string, out?: ?string, breaks?: list<array{0: string, 1: string}>, summary?: array<string, int>}> $rows
     */
    protected function writeYamatoFile(string $path, array $rows, bool $withDescriptionRow = true): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('武蔵野ベース');
        $headerRow = $withDescriptionRow ? 2 : 1;
        if ($withDescriptionRow) {
            $sheet->setCellValue('AB1', 'Giờ vào làm (tính lương)');
        }
        $headers = [
            'A' => 'クライアント名',
            'B' => '事業所名',
            'H' => '社員番号',
            'O' => '氏名（姓ミドル名）',
            'P' => '氏名_カナ（姓ミドル名）',
            'V' => '勤務日',
            'W' => '勤務結果区分',
            'Z' => '打刻開始',
            'AA' => '打刻終了',
            'AB' => '計算元勤務実績開始（修正後）',
            'AC' => '計算元勤務実績終了（修正後）',
            'AD' => '休憩1開始',
            'AE' => '休憩1終了',
            'AF' => '休憩2開始',
            'AG' => '休憩2終了',
            'AL' => '実働時間',
            'AM' => '休憩時間',
            'AP' => '所定内時間',
            'AQ' => '残業時間',
            'AR' => '深夜時間',
            'AS' => '深夜残業時間',
        ];
        foreach ($headers as $column => $header) {
            $sheet->setCellValue("{$column}{$headerRow}", $header);
        }

        $excelRow = $headerRow + 1;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$excelRow}", 'ヤマト運輸株式会社');
            $sheet->setCellValue("B{$excelRow}", '武蔵野ベース');
            if (($row['staff_no'] ?? null) !== null) {
                $sheet->setCellValue("H{$excelRow}", $row['staff_no']);
            }
            $sheet->setCellValue("O{$excelRow}", $row['name'] ?? null);
            $sheet->setCellValue("P{$excelRow}", $row['name_kana'] ?? null);
            if (!empty($row['date'])) {
                $sheet->setCellValue("V{$excelRow}", $this->excelSerial($row['date']));
            }
            $sheet->setCellValue("W{$excelRow}", empty($row['in']) ? '欠勤' : '出勤');
            $this->writeClock($sheet, "AB{$excelRow}", $row['in'] ?? null);
            $this->writeClock($sheet, "AC{$excelRow}", $row['out'] ?? null);
            foreach (array_values($row['breaks'] ?? []) as $index => [$start, $end]) {
                $this->writeClock($sheet, ['AD', 'AF'][$index] . $excelRow, $start);
                $this->writeClock($sheet, ['AE', 'AG'][$index] . $excelRow, $end);
            }
            foreach (
                [
                    'work_minutes' => 'AL',
                    'break_total' => 'AM',
                    'work_minutes_normal' => 'AP',
                    'work_minutes_overtime' => 'AQ',
                    'work_minutes_night' => 'AR',
                    'work_minutes_night_overtime' => 'AS',
                ]
                as $key => $column
            ) {
                if (isset($row['summary'][$key])) {
                    $sheet->setCellValue("{$column}{$excelRow}", $row['summary'][$key] / 1440);
                }
            }
            $excelRow++;
        }

        $this->saveWorkbook($spreadsheet, $path);
    }

    /**
     * @param list<array{staff_no?: string|int|null, name?: ?string, date?: ?string, in?: ?string, out?: ?string, break?: int, summary?: array<string, int>}> $rows
     * @param list<string> $extraSheetTitles
     */
    protected function writeFactoryFile(
        string $path,
        array $rows,
        string $sheetTitle = '支払い8月',
        array $extraSheetTitles = []
    ): void {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        foreach ($extraSheetTitles as $title) {
            $this->fillFactorySheet($spreadsheet->createSheet()->setTitle($title), []);
        }
        $this->fillFactorySheet($spreadsheet->createSheet()->setTitle($sheetTitle), $rows);

        $this->saveWorkbook($spreadsheet, $path);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function fillFactorySheet(Worksheet $sheet, array $rows): void
    {
        $sheet->setCellValue('A1', 46194);
        $sheet->setCellValue('F1', 'Giờ vào làm (tính lương)');
        $headers = [
            'B' => '稼働日',
            'C' => '作業時間',
            'D' => 'スタッフNo.',
            'E' => '名前',
            'F' => '出勤時間',
            'G' => '退勤時間',
            'H' => '休憩時間',
            'I' => '昼時間',
            'J' => '昼残業',
            'K' => '夜勤',
            'L' => "夜勤\n残業",
        ];
        foreach ($headers as $column => $header) {
            $sheet->setCellValue("{$column}2", $header);
        }

        $excelRow = 3;
        foreach ($rows as $row) {
            if (!empty($row['date'])) {
                $sheet->setCellValue("B{$excelRow}", $this->excelSerial($row['date']));
            }
            if (($row['staff_no'] ?? null) !== null) {
                $sheet->setCellValue("D{$excelRow}", $row['staff_no']);
            }
            $sheet->setCellValue("E{$excelRow}", $row['name'] ?? null);
            $this->writeClock($sheet, "F{$excelRow}", $row['in'] ?? null);
            $this->writeClock($sheet, "G{$excelRow}", $row['out'] ?? null);
            $sheet->setCellValue("H{$excelRow}", ($row['break'] ?? 60) / 1440);
            foreach (
                [
                    'work_minutes_normal' => 'I',
                    'work_minutes_overtime' => 'J',
                    'work_minutes_night' => 'K',
                    'work_minutes_night_overtime' => 'L',
                ]
                as $key => $column
            ) {
                if (isset($row['summary'][$key])) {
                    $sheet->setCellValue("{$column}{$excelRow}", $row['summary'][$key] / 1440);
                }
            }
            foreach ($row['raw_cells'] ?? [] as $column => $value) {
                $sheet->setCellValue("{$column}{$excelRow}", $value);
            }
            $excelRow++;
        }
    }

    private function writeClock(Worksheet $sheet, string $cell, ?string $clock): void
    {
        if ($clock === null || $clock === '') {
            return;
        }
        [$hours, $minutes] = array_map('intval', explode(':', $clock));
        $sheet->setCellValue($cell, ($hours * 60 + $minutes) / 1440);
    }

    private function excelSerial(string $date): float
    {
        return (float) ExcelDate::PHPToExcel(new DateTimeImmutable($date . ' 00:00:00'));
    }

    private function saveWorkbook(Spreadsheet $spreadsheet, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }
}
