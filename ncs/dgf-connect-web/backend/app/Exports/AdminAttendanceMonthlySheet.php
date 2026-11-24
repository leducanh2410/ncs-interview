<?php

namespace App\Exports;

use App\Models\AdminAttendanceSession;
use App\Services\Admin\AdminAttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AdminAttendanceMonthlySheet extends DefaultValueBinder implements
    FromArray,
    WithColumnWidths,
    WithCustomValueBinder,
    WithEvents,
    WithStrictNullComparison,
    WithTitle
{
    public const MAX_TITLE_LENGTH = 31;

    private const HEADER_ROW = 3;

    private const FIRST_DATA_ROW = 4;

    private const MINUTES_PER_DAY = 1440;

    private const DURATION_FORMAT = '[H]:mm';

    private readonly array $labels;

    private readonly Carbon $now;

    private array $rows = [];

    /** @var array<int, array{0: int, 1: int}> inclusive first/last data row of each work date */
    private array $dayGroups = [];

    private int $lastDataRow;

    private int $monthlyTotalRow;

    /**
     * @param Collection<int, AdminAttendanceSession> $sessions the admin's sessions for the month,
     *                                                          ordered by work date then check-in
     */
    public function __construct(
        private readonly string $adminName,
        private readonly Carbon $month,
        Collection $sessions,
        private readonly ?string $sheetTitle = null
    ) {
        $this->labels = [
            'sheet_title' => __('attendance.export.sheet_title'),
            'full_name' => __('attendance.export.full_name'),
            'work_date' => __('attendance.export.work_date'),
            'workplace' => __('attendance.export.workplace'),
            'check_in' => __('attendance.export.check_in'),
            'check_out' => __('attendance.export.check_out'),
            'work_hours' => __('attendance.export.work_hours'),
            'note' => __('attendance.export.note'),
            'daily_total' => __('attendance.export.daily_total'),
            'monthly_total' => __('attendance.export.monthly_total'),
            'missing_checkout' => __('attendance.export.missing_checkout'),
            'passed_deadline' => __('attendance.export.passed_deadline'),
        ];
        $this->now = Carbon::now();

        $this->build($sessions);
    }

    public function title(): string
    {
        return self::sanitizeTitle($this->sheetTitle ?? $this->adminName);
    }

    /**
     * Sheet names cannot contain \ / ? * [ ] : and are capped at 31 characters. Public so the
     * workbook can compute a tab name — and disambiguate duplicates — before building the sheet.
     */
    public static function sanitizeTitle(string $name): string
    {
        $title = mb_trim(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $name));

        return mb_substr($title === '' ? '-' : $title, 0, self::MAX_TITLE_LENGTH);
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnWidths(): array
    {
        return [
            // Wide enough for "Dữ liệu chấm công" on one line; only "ADMIN" wraps below it.
            'A' => 22,
            'B' => 34,
            'C' => 12,
            'D' => 12,
            'E' => 22,
            'F' => 28,
            'G' => 14,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:G' . $this->monthlyTotalRow)->applyFromArray([
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);

                $this->styleTitleBlock($sheet);
                $this->styleTable($sheet);
                $this->styleMonthlyTotal($sheet);

                $sheet->freezePane('A' . self::FIRST_DATA_ROW);
                $this->setUpPrinting($sheet);
            },
        ];
    }

    private function styleTitleBlock(Worksheet $sheet): void
    {
        $sheet->getRowDimension(2)->setRowHeight(32);

        // The title shares its row with the name field (per the form), so it has to wrap inside
        // column A instead of spilling into B and being clipped by it.
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getStyle('A2')->getAlignment()->setWrapText(true);
        $sheet->getStyle('B2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('G2')->getFont()->setBold(true);
        $sheet->getStyle('G2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function styleTable(Worksheet $sheet): void
    {
        $headerRow = self::HEADER_ROW;
        $sheet->getRowDimension($headerRow)->setRowHeight(32);
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        if ($this->lastDataRow < self::FIRST_DATA_ROW) {
            return;
        }

        $lastRow = $this->lastDataRow;
        $sheet->getStyle("A{$headerRow}:G{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $column = fn(string $letter) => "{$letter}" . self::FIRST_DATA_ROW . ":{$letter}{$lastRow}";
        $sheet->getStyle($column('A'))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($column('B'))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet
            ->getStyle('C' . self::FIRST_DATA_ROW . ":E{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($column('E'))->getNumberFormat()->setFormatCode(self::DURATION_FORMAT);
        $sheet->getStyle($column('F'))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle($column('G'))->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getStyle($column('G'))->getNumberFormat()->setFormatCode(self::DURATION_FORMAT);

        foreach ($this->dayGroups as [$start, $end]) {
            if ($end > $start) {
                $sheet->mergeCells("G{$start}:G{$end}");
            }
        }
    }

    private function styleMonthlyTotal(Worksheet $sheet): void
    {
        $row = $this->monthlyTotalRow;
        $sheet->getStyle("F{$row}:G{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet
            ->getStyle("F{$row}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet
            ->getStyle("G{$row}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet
            ->getStyle("G{$row}")
            ->getNumberFormat()
            ->setFormatCode(self::DURATION_FORMAT);
    }

    private function setUpPrinting(Worksheet $sheet): void
    {
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        // A month can run past one page, so the header row has to reappear on each of them.
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
    }

    private function build(Collection $sessions): void
    {
        $this->rows[] = self::emptyRow();
        $this->rows[] = [
            $this->labels['sheet_title'],
            // Label and name live in one cell so a long name simply overflows to the right
            // instead of being clipped by a merge.
            $this->labels['full_name'] . ' ' . $this->adminName,
            null,
            null,
            null,
            null,
            $this->month->format(__('attendance.export.month_format')),
        ];
        $this->rows[] = [
            $this->labels['work_date'],
            $this->labels['workplace'],
            $this->labels['check_in'],
            $this->labels['check_out'],
            $this->labels['work_hours'],
            $this->labels['note'],
            $this->labels['daily_total'],
        ];

        $dateFormat = __('lang.date_format');
        $monthlyMinutes = 0;
        $row = self::HEADER_ROW;

        foreach ($sessions->groupBy(fn($session) => $session->work_date->format('Y-m-d')) as $daySessions) {
            $firstRowOfDay = $row + 1;
            $dayMinutes = 0;

            foreach ($daySessions as $session) {
                $row++;
                $minutes = (int) ($session->work_minutes ?? 0);
                $dayMinutes += $minutes;

                $this->rows[] = [
                    $session->work_date->format($dateFormat),
                    $session->workplace?->name,
                    $session->first_check_in_at ? Carbon::parse($session->first_check_in_at)->format('H:i') : null,
                    $session->last_check_out_at ? Carbon::parse($session->last_check_out_at)->format('H:i') : null,
                    $minutes / self::MINUTES_PER_DAY,
                    $this->noteFor($session),
                    // The day total lives on the day's first row; the rest of the day's rows are
                    // merged into it below, so they must stay empty.
                    null,
                ];
            }

            $this->rows[$firstRowOfDay - 1][6] = $dayMinutes / self::MINUTES_PER_DAY;
            $this->dayGroups[] = [$firstRowOfDay, $row];
            $monthlyMinutes += $dayMinutes;
        }

        $this->lastDataRow = $row;

        $this->rows[] = self::emptyRow();
        $this->rows[] = [
            null,
            null,
            null,
            null,
            null,
            $this->labels['monthly_total'],
            $monthlyMinutes / self::MINUTES_PER_DAY,
        ];
        $this->monthlyTotalRow = $row + 2;
    }

    /**
     * A spacer row has to be a full row of nulls rather than `[]`: the writer flattens the row
     * list, so a genuinely empty row is dropped and every row below it shifts up — out of sync
     * with the merges and styles, which address fixed sheet coordinates.
     */
    private static function emptyRow(): array
    {
        return array_fill(0, 7, null);
    }

    private function noteFor(AdminAttendanceSession $session): string
    {
        if ($session->invalidated_reason !== null) {
            return $this->labels['passed_deadline'];
        }

        $openCheckInAt = $session->openCheckInAt();
        if ($openCheckInAt !== null && !$this->now->lt(AdminAttendanceService::checkoutDeadlineFor($openCheckInAt))) {
            return $this->labels['passed_deadline'];
        }

        return $session->has_missing_checkout ? $this->labels['missing_checkout'] : '';
    }
}
