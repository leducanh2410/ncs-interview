<?php

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationSource;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FactoryTimesheetParser extends StaffTimesheetParser
{
    public function source(): string
    {
        return ReconciliationSource::FactoryTimesheet->value;
    }

    protected function headers(): array
    {
        return [
            'work_date' => '稼働日',
            'staff_no' => 'スタッフNo.',
            'name' => '名前',
            'check_in' => '出勤時間',
            'check_out' => '退勤時間',
            'break' => '休憩時間',
            'work_minutes_normal' => '昼時間',
            'work_minutes_overtime' => '昼残業',
            'work_minutes_night' => '夜勤',
            'work_minutes_night_overtime' => '夜勤残業',
        ];
    }

    protected function requiredHeaders(): array
    {
        return [
            'work_date',
            'staff_no',
            'check_in',
            'check_out',
            'break',
            'work_minutes_normal',
            'work_minutes_overtime',
            'work_minutes_night',
            'work_minutes_night_overtime',
        ];
    }

    protected function summaryHeaders(): array
    {
        return [
            'work_minutes_normal' => 'work_minutes_normal',
            'work_minutes_overtime' => 'work_minutes_overtime',
            'work_minutes_night' => 'work_minutes_night',
            'work_minutes_night_overtime' => 'work_minutes_night_overtime',
        ];
    }

    protected function preferredSheetKeywords(): array
    {
        return ['支払'];
    }

    protected function externalName(Worksheet $sheet, array $columns, int $row): string
    {
        return $this->collapseSpaces($this->cellString($sheet, $columns['name'] ?? null, $row));
    }

    protected function breakMinutes(Worksheet $sheet, array $columns, int $row): int
    {
        return $this->cellMinutes($sheet, $columns['break'], $row) ?? 0;
    }

    protected function companyLabel(Worksheet $sheet, array $columns, int $headerRow): ?string
    {
        return $sheet->getTitle();
    }
}
