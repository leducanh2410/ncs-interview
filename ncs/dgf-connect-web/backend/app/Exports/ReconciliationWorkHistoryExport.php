<?php

namespace App\Exports;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDefaultStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReconciliationWorkHistoryExport implements
    FromCollection,
    WithColumnWidths,
    WithDefaultStyles,
    WithHeadings,
    WithStrictNullComparison,
    WithStyles
{
    public const CHECK_IN_ID_HEADING = 'Check_In_ID';

    private readonly WorkHistoryDetailExport $inner;

    private readonly bool $addedCheckInId;

    /** @var list<int|null> */
    private readonly array $sourceCheckInIds;

    public function __construct(array $groupByWorkplace, ?WorkHistoryDetailExport $inner = null)
    {
        $this->inner = $inner ?? new WorkHistoryDetailExport($groupByWorkplace);
        $this->addedCheckInId = !in_array(self::CHECK_IN_ID_HEADING, $this->inner->headings()[0], true);
        $this->sourceCheckInIds = $this->flattenCheckInIds($groupByWorkplace);
    }

    public function collection(): Collection
    {
        $rows = $this->inner->collection();
        if (!$this->addedCheckInId) {
            return $rows;
        }

        $index = 0;

        return $rows->map(function ($row) use (&$index) {
            if (!$this->isDataRow($row)) {
                return $row;
            }
            $row['check_in_id'] = $this->sourceCheckInIds[$index] ?? null;
            $index++;

            return $row;
        });
    }

    public function headings(): array
    {
        $headings = $this->inner->headings()[0];
        if ($this->addedCheckInId) {
            $headings[] = self::CHECK_IN_ID_HEADING;
        }

        return [$headings];
    }

    public function columnWidths(): array
    {
        $widths = $this->inner->columnWidths();
        if ($this->addedCheckInId) {
            $column = Coordinate::stringFromColumnIndex(count($this->headings()[0]));
            $widths[$column] = 12;
        }

        return $widths;
    }

    public function defaultStyles(Style $defaultStyle): array
    {
        return $this->inner->defaultStyles($defaultStyle);
    }

    public function styles(Worksheet $sheet): void
    {
        $this->inner->styles($sheet);
        if (!$this->addedCheckInId) {
            return;
        }

        $column = Coordinate::stringFromColumnIndex(count($this->headings()[0]));
        $sheet->getColumnDimension($column)->setVisible(false);
        $sheet->getStyle($column)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
        $sheet
            ->getStyle("{$column}1")
            ->getFont()
            ->setBold(true);
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $groupByWorkplace
     * @return list<int|null>
     */
    private function flattenCheckInIds(array $groupByWorkplace): array
    {
        $ids = [];
        foreach ($groupByWorkplace as $data) {
            foreach ($data as $item) {
                $ids[] = Arr::get($item, 'check_in_id');
            }
        }

        return $ids;
    }

    private function isDataRow(mixed $row): bool
    {
        return is_array($row) && (isset($row['date']) || isset($row['staff_no']));
    }
}
