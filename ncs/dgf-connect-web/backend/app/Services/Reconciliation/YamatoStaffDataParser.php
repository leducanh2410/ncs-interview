<?php

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationSource;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class YamatoStaffDataParser extends StaffTimesheetParser
{
    private const BREAK_SLOTS = 4;

    public function source(): string
    {
        return ReconciliationSource::YamatoStaffData->value;
    }

    protected function headers(): array
    {
        $headers = [
            'client' => 'クライアント名',
            'office' => '事業所名',
            'staff_no' => '社員番号',
            'name' => '氏名（姓ミドル名）',
            'name_kana' => '氏名_カナ（姓ミドル名）',
            'work_date' => '勤務日',
            'check_in' => '計算元勤務実績開始（修正後）',
            'check_out' => '計算元勤務実績終了（修正後）',
            'break_total' => '休憩時間',
            'work_minutes' => '実働時間',
            'work_minutes_normal' => '所定内時間',
            'work_minutes_overtime' => '残業時間',
            'work_minutes_night' => '深夜時間',
            'work_minutes_night_overtime' => '深夜残業時間',
        ];
        for ($slot = 1; $slot <= self::BREAK_SLOTS; $slot++) {
            $headers["break_{$slot}_start"] = "休憩{$slot}開始";
            $headers["break_{$slot}_end"] = "休憩{$slot}終了";
        }

        return $headers;
    }

    protected function requiredHeaders(): array
    {
        return ['staff_no', 'work_date', 'check_in', 'check_out', 'break_1_start', 'break_1_end'];
    }

    protected function summaryHeaders(): array
    {
        return [
            'work_minutes' => 'work_minutes',
            'work_minutes_normal' => 'work_minutes_normal',
            'work_minutes_overtime' => 'work_minutes_overtime',
            'work_minutes_night' => 'work_minutes_night',
            'work_minutes_night_overtime' => 'work_minutes_night_overtime',
        ];
    }

    protected function externalName(Worksheet $sheet, array $columns, int $row): string
    {
        $kana = $this->collapseSpaces($this->cellString($sheet, $columns['name_kana'] ?? null, $row));
        if ($kana !== '') {
            return $kana;
        }

        return $this->collapseSpaces($this->cellString($sheet, $columns['name'] ?? null, $row));
    }

    protected function breakMinutes(Worksheet $sheet, array $columns, int $row): int
    {
        $total = null;
        for ($slot = 1; $slot <= self::BREAK_SLOTS; $slot++) {
            $start = $this->cellClock($sheet, $columns["break_{$slot}_start"] ?? null, $row);
            $end = $this->cellClock($sheet, $columns["break_{$slot}_end"] ?? null, $row);
            if ($start === null || $end === null) {
                continue;
            }
            $minutes = (int) round(($end - $start) * 1440);
            $total = ($total ?? 0) + ($minutes < 0 ? $minutes + 1440 : $minutes);
        }

        return $total ?? ($this->cellMinutes($sheet, $columns['break_total'] ?? null, $row) ?? 0);
    }

    protected function companyLabel(Worksheet $sheet, array $columns, int $headerRow): ?string
    {
        $label = $this->collapseSpaces(
            $this->cellString($sheet, $columns['client'] ?? null, $headerRow + 1) .
                ' ' .
                $this->cellString($sheet, $columns['office'] ?? null, $headerRow + 1)
        );

        return $label !== '' ? $label : null;
    }
}
