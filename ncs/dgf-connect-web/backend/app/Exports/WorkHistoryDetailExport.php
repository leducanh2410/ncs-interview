<?php

namespace App\Exports;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDefaultStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WorkHistoryDetailExport implements
    FromCollection,
    WithColumnWidths,
    WithDefaultStyles,
    WithHeadings,
    WithStrictNullComparison,
    WithStyles
{
    use RegistersEventListeners;

    public const DATE_FORMAT = 'yyyy/mm/dd';

    private readonly Collection $data;

    private array $mergeRows;

    private readonly int $total;

    public function __construct(array $groupByWorkplace)
    {
        $this->data = collect();
        $this->mergeRows = [];
        $rowIndex = 1;
        foreach ($groupByWorkplace as $workplaceId => $data) {
            $workplaceName = Arr::get($data, '0.workplace_name');
            $salaryPerHour = Arr::get($data, '0.workplace_salary_per_hour');
            $workplaceLabel = $salaryPerHour ? "{$workplaceName} - {$salaryPerHour}" : $workplaceName;

            $this->data->push([$workplaceLabel]);
            $this->mergeRows[] = ++$rowIndex;
            foreach ($data as $item) {
                $rowIndex++;
                $date = Arr::get($item, 'date');
                $excelDate = $date ? ExcelDate::PHPToExcel(Carbon::parse($date)->startOfDay()) : null;
                $this->data->push([
                    'workplace_name' => $workplaceName,
                    'date' => $excelDate,
                    'shift_time' => Arr::get($item, 'shift_time'),
                    'staff_no' => Arr::get($item, 'staff_no'),
                    'name' => Arr::get($item, 'name'),
                    'name_kana' => Arr::get($item, 'name_kana'),
                    'check_in_time' => Arr::get($item, 'check_in_time'),
                    'check_out_time' => Arr::get($item, 'check_out_time'),
                    'check_in_time_rounded' => Arr::get($item, 'check_in_time_rounded'),
                    'check_out_time_rounded' => Arr::get($item, 'check_out_time_rounded'),
                    'break_time' => Arr::get($item, 'break_time') / 1440,
                    'work_minutes' => Arr::get($item, 'work_minutes') / 1440,
                    'work_minutes_normal' => Arr::get($item, 'work_minutes_normal') / 1440,
                    'work_minutes_night' => Arr::get($item, 'work_minutes_night') / 1440,
                    'work_minutes_overtime' => Arr::get($item, 'work_minutes_overtime') / 1440,
                    'work_minutes_night_overtime' => Arr::get($item, 'work_minutes_night_overtime') / 1440,
                    'check_in_id' => Arr::get($item, 'check_in_id'),
                    'attendance_status' => Arr::get($item, 'attendance_status'),
                    'attendance_reason' => Arr::get($item, 'attendance_reason'),
                ]);
            }
            $this->data->push(['']);
            $this->data->push(['']);
            $rowIndex += 2;
        }
        $this->total = $this->data->count();
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            [
                'Xưởng',
                'Ngày làm',
                'Giờ làm',
                'Mã nhân viên',
                'Tên nhân viên',
                'Tên nhân viên (Kana)',
                'Giờ vào làm',
                'Giờ tan làm',
                'Giờ vào làm (tính lương)',
                'Giờ tan làm (tính lương)',
                'Giờ nghỉ',
                'Tổng giờ làm',
                'Số giờ thường',
                'Số giờ làm đêm',
                'Số giờ làm thêm',
                'Số giờ làm thêm ban đêm',
                // Hidden technical column (see styles(), column Q is set not-visible): carries
                // each row's check_in id so a re-uploaded, hand-edited copy of this file can be
                // matched back to the exact Check_In it came from. Never meant to be seen or
                // edited by the admin - see .agents/specs/20260825-bulk-work-history-edit-import/.
                'Check_In_ID',
                'Trạng thái',
                'Lý do',
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 32,
            'B' => 12,
            'C' => 13,
            'D' => 14,
            'E' => 24,
            'F' => 24,
            'G' => 12,
            'H' => 12,
            'I' => 14,
            'J' => 14,
            'K' => 10,
            'L' => 12,
            'M' => 12,
            'N' => 14,
            'O' => 14,
            'P' => 16,
            'Q' => 12,
            'R' => 24,
            'S' => 40,
        ];
    }

    public function defaultStyles(Style $defaultStyle): array
    {
        return [
            'alignment' => [
                'wrapText' => false,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    /**
     * @throws Exception
     */
    public function styles(Worksheet $sheet): void
    {
        $sheet->getStyle('A1:S1')->getFont()->setBold(true);
        $sheet
            ->getStyle('A1:S1')
            ->getAlignment()
            ->applyFromArray([
                'wrapText' => true,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ]);
        $sheet->getStyle('A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('B')->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        $sheet->getStyle('D')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('G:P')->getNumberFormat()->setFormatCode('[H]:mm');
        $sheet->getStyle('Q')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
        $sheet->getStyle('R:S')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        // Hidden by default - this column exists only so a re-uploaded copy of this file can be
        // matched back to the exact Check_In it came from; it is not meant for the admin to see
        // or edit while hand-correcting attendance values.
        $sheet->getColumnDimension('Q')->setVisible(false);

        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->getRowDimension(1)->setRowHeight(32);

        foreach ($this->mergeRows as $row) {
            $sheet
                ->getStyle("A{$row}")
                ->getFont()
                ->setBold(true);
        }

        $lastCell = $sheet->getHighestRowAndColumn();

        $tableLastRow = $this->total + 1;
        $sheet->getStyle("A1:{$lastCell['column']}{$tableLastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
    }
}
