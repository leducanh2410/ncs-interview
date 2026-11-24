<?php

namespace App\Services\Reconciliation;

use DateTime;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Calculation\Exception as CalculationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

abstract class StaffTimesheetParser implements DetectsReconciliationFormat, ReconciliationSourceParser
{
    private const HEADER_SCAN_ROWS = 10;

    private const HEADER_SCAN_COLUMNS = 80;

    private const ABSOLUTE_SERIAL_THRESHOLD = 1000;

    private const MAX_CLOCK_DAYS = 2;

    private const MAX_DURATION_DAYS = 1;

    public function parse(string $absolutePath): array
    {
        $spreadsheet = $this->load($absolutePath);

        try {
            [$sheet, $headerRow, $columns] = $this->pickSheet($spreadsheet);
            $companyLabel = $this->companyLabel($sheet, $columns, $headerRow);
        } catch (CalculationException) {
            throw new ReconciliationFormatException('unreadable_workbook');
        }

        $punchRows = [];
        $skipped = 0;
        $highestRow = (int) $sheet->getHighestDataRow();

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            try {
                $staffNo = $this->cellStaffNo($sheet, $columns['staff_no'], $row);
                $name = $this->externalName($sheet, $columns, $row);
                $workDate = $this->cellDate($sheet, $columns['work_date'], $row);
                $checkInClock = $this->cellClock($sheet, $columns['check_in'], $row);
                $checkOutClock = $this->cellClock($sheet, $columns['check_out'], $row);
            } catch (CalculationException $exception) {
                throw new ReconciliationFormatException('invalid_formula', [
                    'row' => $row,
                    'message' => $exception->getMessage(),
                ]);
            }

            $hasTimes = $checkInClock !== null && $checkOutClock !== null;
            $hasIdentity = $staffNo !== null || $name !== '';

            if ($workDate === null) {
                if ($hasTimes && $hasIdentity) {
                    throw new ReconciliationFormatException('invalid_work_date', ['row' => $row]);
                }

                continue;
            }

            if (!$hasIdentity) {
                if ($hasTimes) {
                    throw new ReconciliationFormatException('missing_name', ['row' => $row]);
                }
                $skipped++;

                continue;
            }

            if (!$hasTimes) {
                $skipped++;

                continue;
            }

            $checkIn = $this->resolveClock($workDate, $checkInClock);
            $checkOut = $this->resolveClock($workDate, $checkOutClock);
            if ($checkOut->lessThan($checkIn) && $checkOutClock < self::ABSOLUTE_SERIAL_THRESHOLD) {
                $checkOut = $checkOut->addDay();
            }

            try {
                $breakMinutes = $this->breakMinutes($sheet, $columns, $row);
                $summary = [];
                foreach (ReconciliationFieldCompare::SUMMARY_FIELDS as $field) {
                    $header = array_search($field, $this->summaryHeaders(), true);
                    $summary[$field] =
                        $header !== false ? $this->cellMinutes($sheet, $columns[$header] ?? null, $row) : null;
                }
            } catch (CalculationException $exception) {
                throw new ReconciliationFormatException('invalid_formula', [
                    'row' => $row,
                    'message' => $exception->getMessage(),
                ]);
            }

            $punchRows[] = new CanonicalRow(
                externalName: $name !== '' ? $name : (string) $staffNo,
                workDate: $workDate,
                checkIn: $checkIn,
                checkOut: $checkOut,
                breakMinutes: $breakMinutes,
                raw: [
                    'staff_no' => $staffNo,
                    'work_time_range' => $checkIn->format('H:i') . ' ~ ' . $checkOut->format('H:i'),
                    'summary' => $summary,
                    'source_row' => $row,
                    'sheet' => $sheet->getTitle(),
                ],
                staffNo: $staffNo
            );
        }

        return [
            'company_label' => $companyLabel,
            'punch_rows' => $punchRows,
            'skipped_non_punch_count' => $skipped,
        ];
    }

    public function matchesFormat(string $absolutePath): bool
    {
        try {
            $spreadsheet = $this->load($absolutePath, new HeaderRowsReadFilter(self::HEADER_SCAN_ROWS));
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                if ($this->locateHeaders($sheet) !== null) {
                    return true;
                }
            }
        } catch (ReconciliationFormatException | CalculationException) {
            return false;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    /**
     * @return list<string>
     */
    abstract protected function requiredHeaders(): array;

    /**
     * @return array<string, string>
     */
    abstract protected function summaryHeaders(): array;

    /**
     * @param array<string, int> $columns
     */
    abstract protected function externalName(Worksheet $sheet, array $columns, int $row): string;

    /**
     * @param array<string, int> $columns
     */
    abstract protected function breakMinutes(Worksheet $sheet, array $columns, int $row): int;

    /**
     * @param array<string, int> $columns
     */
    abstract protected function companyLabel(Worksheet $sheet, array $columns, int $headerRow): ?string;

    /**
     * @return list<string>
     */
    protected function preferredSheetKeywords(): array
    {
        return [];
    }

    protected function cellValue(Worksheet $sheet, ?int $col, int $row): mixed
    {
        if ($col === null) {
            return null;
        }
        $cell = $sheet->getCell([$col, $row]);
        if ($cell->isFormula()) {
            $cached = $cell->getOldCalculatedValue();
            $value = $cached ?? $cell->getCalculatedValue();
        } else {
            $value = $cell->getValue();
        }
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_string($value)) {
            $value = mb_trim($value);
            if ($value === '' || array_key_exists($value, DataType::getErrorCodes())) {
                return null;
            }
        }

        return $value;
    }

    protected function cellString(Worksheet $sheet, ?int $col, int $row): string
    {
        $value = $this->cellValue($sheet, $col, $row);
        if ($value === null) {
            return '';
        }
        if (is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }

        return is_string($value) ? $value : (string) $value;
    }

    protected function collapseSpaces(string $value): string
    {
        return mb_trim(preg_replace('/[\s\x{3000}]+/u', ' ', $value) ?? $value);
    }

    protected function cellClock(Worksheet $sheet, ?int $col, int $row): ?float
    {
        $value = $this->cellValue($sheet, $col, $row);
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            $clock = (float) ExcelDate::PHPToExcel($value);
        } elseif (is_numeric($value)) {
            $clock = (float) $value;
        } elseif (is_string($value) && preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m)) {
            $clock = ((int) $m[1] * 60 + (int) $m[2]) / 1440;
        } else {
            return null;
        }

        if ($clock < 0 || ($clock >= self::MAX_CLOCK_DAYS && $clock < self::ABSOLUTE_SERIAL_THRESHOLD)) {
            $this->rejectTime($col, $row);
        }

        return $clock;
    }

    protected function cellMinutes(Worksheet $sheet, ?int $col, int $row): ?int
    {
        $clock = $this->cellClock($sheet, $col, $row);
        if ($clock === null) {
            return null;
        }
        if ($clock >= self::MAX_DURATION_DAYS) {
            $this->rejectTime($col, $row);
        }

        return (int) round($clock * 1440);
    }

    private function rejectTime(?int $col, int $row): never
    {
        throw new ReconciliationFormatException('invalid_time', [
            'row' => $row,
            'column' => $col !== null ? Coordinate::stringFromColumnIndex($col) : null,
        ]);
    }

    private function load(string $absolutePath, ?IReadFilter $filter = null): Spreadsheet
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            if ($filter !== null) {
                $reader->setReadFilter($filter);
            }

            return $reader->load($absolutePath);
        } catch (Throwable) {
            throw new ReconciliationFormatException('unreadable_workbook');
        }
    }

    /**
     * @return array{0: Worksheet, 1: int, 2: array<string, int>}
     */
    private function pickSheet(Spreadsheet $spreadsheet): array
    {
        $candidates = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            try {
                $located = $this->locateHeaders($sheet);
            } catch (CalculationException) {
                continue;
            }
            if ($located !== null) {
                $candidates[] = [$sheet, ...$located];
            }
        }

        if ($candidates === []) {
            throw new ReconciliationFormatException('header_mismatch', [
                'expected' => array_values(array_intersect_key($this->headers(), array_flip($this->requiredHeaders()))),
            ]);
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $preferred = array_values(
            array_filter($candidates, function (array $candidate) {
                foreach ($this->preferredSheetKeywords() as $keyword) {
                    if (str_contains($candidate[0]->getTitle(), $keyword)) {
                        return true;
                    }
                }

                return false;
            })
        );
        if (count($preferred) === 1) {
            return $preferred[0];
        }

        throw new ReconciliationFormatException('multiple_sheets', [
            'sheets' => array_map(fn(array $candidate) => $candidate[0]->getTitle(), $candidates),
        ]);
    }

    /**
     * @return array{0: int, 1: array<string, int>}|null
     */
    private function locateHeaders(Worksheet $sheet): ?array
    {
        $lookup = array_flip(array_map(fn(string $header) => $this->compactHeader($header), $this->headers()));
        $highestRow = min((int) $sheet->getHighestDataRow(), self::HEADER_SCAN_ROWS);
        $highestCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::HEADER_SCAN_COLUMNS);

        for ($row = 1; $row <= $highestRow; $row++) {
            $found = [];
            for ($col = 1; $col <= $highestCol; $col++) {
                $key = $lookup[$this->compactHeader($this->cellString($sheet, $col, $row))] ?? null;
                if ($key !== null) {
                    $found[$key] ??= $col;
                }
            }
            if (array_diff($this->requiredHeaders(), array_keys($found)) === []) {
                return [$row, $found];
            }
        }

        return null;
    }

    private function compactHeader(string $text): string
    {
        return preg_replace('/[\s\x{3000}]+/u', '', $text) ?? $text;
    }

    private function resolveClock(string $workDate, float $clock): Carbon
    {
        if ($clock >= self::ABSOLUTE_SERIAL_THRESHOLD) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject($clock))->second(0);
        }

        return Carbon::parse($workDate)->addMinutes((int) round($clock * 1440));
    }

    private function cellStaffNo(Worksheet $sheet, int $col, int $row): ?string
    {
        $value = $this->collapseSpaces($this->cellString($sheet, $col, $row));

        return $value !== '' ? $value : null;
    }

    private function cellDate(Worksheet $sheet, int $col, int $row): ?string
    {
        $value = $this->cellValue($sheet, $col, $row);
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(DateTime::createFromInterface($value))->toDateString();
        }
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial < self::ABSOLUTE_SERIAL_THRESHOLD) {
                return null;
            }

            return Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->toDateString();
        }
        if (is_string($value) && preg_match('/^(\d{4})[\/\-.年](\d{1,2})[\/\-.月](\d{1,2})日?$/u', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }
}
