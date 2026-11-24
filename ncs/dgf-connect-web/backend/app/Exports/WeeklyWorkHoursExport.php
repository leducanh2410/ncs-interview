<?php

namespace App\Exports;

use App\Enums\WorkLoadStatus;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithDefaultStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WeeklyWorkHoursExport implements
    FromArray,
    ShouldAutoSize,
    WithDefaultStyles,
    WithHeadings,
    WithStrictNullComparison,
    WithStyles,
    WithTitle
{
    public const STATUS_COLORS = [
        'normal' => ['fill' => 'E6FCF5', 'font' => '087F5B'],
        'near' => ['fill' => 'FFF3BF', 'font' => 'E67700'],
        'over' => ['fill' => 'FFE3E3', 'font' => 'C92A2A'],
    ];

    private const FIXED_COLUMNS_BEFORE_WINDOWS = 4;

    private const HEADER_FILL = 'E9ECEF';

    /**
     * @param list<array{start: string, end: string}> $windows
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $windows,
        private readonly array $rows,
        private readonly string $locale
    ) {}

    public function title(): string
    {
        return $this->translate('sheet_title');
    }

    public function headings(): array
    {
        return [
            $this->translate('staff_no'),
            $this->translate('name'),
            $this->translate('residence_status'),
            $this->translate('status'),
            ...array_map(fn(array $window) => $this->formatRange($window['start'], $window['end']), $this->windows),
        ];
    }

    public function array(): array
    {
        return array_map(
            fn(array $row) => [
                $row['user']['staff_no'],
                $row['user']['name'],
                $this->residenceLabel($row['residence_status']),
                $this->translate("status_label.{$row['status']}"),
                ...array_map(fn(array $summary) => $this->formatSummary($summary), $row['weeks']),
            ],
            $this->rows
        );
    }

    public function defaultStyles(Style $defaultStyle): array
    {
        return [
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    public function styles(Worksheet $sheet): void
    {
        $windowCount = count($this->windows);
        $lastColumn = Coordinate::stringFromColumnIndex(self::FIXED_COLUMNS_BEFORE_WINDOWS + $windowCount);
        $lastRow = count($this->rows) + 1;

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
        ]);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->freezePane('C2');

        foreach ($this->rows as $index => $row) {
            $rowNumber = $index + 2;
            $statuses = [$row['status'], ...array_column($row['weeks'], 'status')];
            foreach ($this->groupConsecutive($statuses) as [$status, $firstIndex, $lastIndex]) {
                $this->paintStatus(
                    $sheet,
                    self::FIXED_COLUMNS_BEFORE_WINDOWS + $firstIndex,
                    self::FIXED_COLUMNS_BEFORE_WINDOWS + $lastIndex,
                    $rowNumber,
                    $status
                );
            }
        }
    }

    /**
     * @param list<string> $values
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function groupConsecutive(array $values): array
    {
        $groups = [];
        foreach ($values as $index => $value) {
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last][0] === $value) {
                $groups[$last][2] = $index;
            } else {
                $groups[] = [$value, $index, $index];
            }
        }

        return $groups;
    }

    private function paintStatus(
        Worksheet $sheet,
        int $firstColumnIndex,
        int $lastColumnIndex,
        int $rowNumber,
        string $status
    ): void {
        $colors = self::STATUS_COLORS[$status] ?? self::STATUS_COLORS[WorkLoadStatus::Normal->value];
        $range =
            Coordinate::stringFromColumnIndex($firstColumnIndex) .
            $rowNumber .
            ':' .
            Coordinate::stringFromColumnIndex($lastColumnIndex) .
            $rowNumber;
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colors['fill']]],
            'font' => ['color' => ['argb' => $colors['font']], 'bold' => $status !== WorkLoadStatus::Normal->value],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function formatSummary(array $summary): string
    {
        $unit = $this->translate('hour_unit');
        $limitMarker = $summary['is_custom_limit'] ? '*' : '';

        return $this->formatHours($summary['total_hours']) .
            $unit .
            ' / ' .
            $this->formatHours($summary['max_hour_per_week']) .
            $unit .
            $limitMarker;
    }

    private function residenceLabel(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $key = "weekly_work_hours.residence.{$status}";
        $label = __($key, [], $this->locale);

        return $label === $key ? $status : $label;
    }

    private function formatHours(float|int $hours): string
    {
        return (string) round($hours, 2);
    }

    private function formatRange(string $start, string $end): string
    {
        $format = __('lang.date_format', [], $this->locale);

        return Carbon::parse($start)->format($format) . ' ~ ' . Carbon::parse($end)->format($format);
    }

    private function translate(string $key): string
    {
        return __("weekly_work_hours.{$key}", [], $this->locale);
    }
}
