<?php

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationSource;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Calculation\Exception as CalculationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class AmazonKadouParser implements DetectsReconciliationFormat, ReconciliationSourceParser
{
    private const HEADER_SCAN_ROWS = 20;

    private const EXPECTED_HEADERS = ['氏名', '作業日', '作業時間', '休憩'];

    private const SUMMARY_HEADERS = [
        '実働時間' => 'work_minutes',
        '所定内労働時間' => 'work_minutes_normal',
        '残業時間' => 'work_minutes_overtime',
        '深夜時間' => 'work_minutes_night',
        '深夜残業時間' => 'work_minutes_night_overtime',
    ];

    public function source(): string
    {
        return ReconciliationSource::AmazonKadou->value;
    }

    public function parse(string $absolutePath): array
    {
        if ($this->probeHeaders($absolutePath) === false) {
            throw new ReconciliationFormatException('header_mismatch', [
                'expected' => self::EXPECTED_HEADERS,
            ]);
        }

        try {
            $spreadsheet = IOFactory::load($absolutePath);
        } catch (Throwable) {
            throw new ReconciliationFormatException('unreadable_workbook');
        }

        $sheet = $this->findSheetWithHeaders($spreadsheet);
        if ($sheet === null) {
            throw new ReconciliationFormatException('header_mismatch', [
                'expected' => self::EXPECTED_HEADERS,
            ]);
        }

        // Reading cells evaluates formulas, so the data sheet's own header band
        // can still raise a calculation error even though findSheetWithHeaders()
        // got through it — report it as a 422 rather than a raw PhpSpreadsheet
        // 500, without a row number since the failure is in the header band.
        try {
            [$headerRow, $columns] = $this->locateHeaders($sheet);
            $companyLabel = $this->readCompanyLabel($sheet);
        } catch (CalculationException) {
            throw new ReconciliationFormatException('unreadable_workbook');
        }

        $punchRows = [];
        $skipped = 0;
        $highestRow = (int) $sheet->getHighestDataRow();

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            // Every cell read below goes through getCalculatedValue(), which —
            // unlike getValue() — evaluates formulas and throws on the ones it
            // cannot resolve (a reference to an external workbook with no cached
            // value, a malformed formula). CreateReconciliationBatchAction only
            // maps ReconciliationFormatException to a 422, so without this the
            // admin would get a 500 carrying a PhpSpreadsheet message instead of
            // RECONCILIATION_INVALID_FORMAT naming the offending row.
            try {
                $name = $this->cellName($sheet, $columns['name'], $row);
                $workDate = $this->cellDate($sheet, $columns['work_date'], $row);
                $workStart = $this->cellDateTime($sheet, $columns['work_time_start'], $row);
                $workEnd = $this->cellDateTime($sheet, $columns['work_time_end'], $row);
            } catch (CalculationException $exception) {
                throw new ReconciliationFormatException('invalid_formula', [
                    'row' => $row,
                    'message' => $exception->getMessage(),
                ]);
            }

            if ($name === '') {
                // A 作業日 plus both 作業時間 ends is a Punch_Log row (glossary) —
                // so a blank 氏名 here is a real Amazon punch that cannot be
                // attributed to anyone, and cellName() has already resolved the
                // ordinary cause (a name merged down its employee's rows).
                // Skipping it would drop it from the batch without even a
                // count, so reject the file the way an unparseable 作業日 does.
                if ($workDate !== null && $workStart !== null && $workEnd !== null) {
                    throw new ReconciliationFormatException('missing_name', ['row' => $row]);
                }
                // Without a 作業日 the row is a total/label row like the ones
                // isSummaryRow() drops (they simply happen to have no name to
                // recognise); with one, it is a non-punch row and must be
                // counted (Requirement 4.2). A fully empty row leaves no trace.
                if ($workDate !== null) {
                    $skipped++;
                }

                continue;
            }

            if ($this->isSummaryRow($name, $workDate)) {
                continue;
            }

            // Punch_Log requires both 作業時間 ends (glossary). A missing start or
            // end is skipped as non-punch, same as a row with neither.
            if ($workStart === null || $workEnd === null) {
                $skipped++;

                continue;
            }

            if ($workDate === null) {
                throw new ReconciliationFormatException('invalid_work_date', ['row' => $row]);
            }

            try {
                $breakMinutes = $this->cellBreakMinutes($sheet, $columns['break'], $row);
                $summary = [];
                foreach (ReconciliationFieldCompare::SUMMARY_FIELDS as $key) {
                    $summary[$key] = $this->cellMinutes($sheet, $columns[$key] ?? null, $row);
                }
            } catch (CalculationException $exception) {
                throw new ReconciliationFormatException('invalid_formula', [
                    'row' => $row,
                    'message' => $exception->getMessage(),
                ]);
            }

            $punchRows[] = new CanonicalRow(
                externalName: $this->collapseSpaces($name),
                workDate: $workDate,
                checkIn: $workStart,
                checkOut: $workEnd,
                breakMinutes: $breakMinutes,
                raw: [
                    'work_time_range' => $this->formatRange($workStart, $workEnd),
                    'summary' => $summary,
                    'source_row' => $row,
                ]
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
        return $this->probeHeaders($absolutePath) === true;
    }

    private function probeHeaders(string $absolutePath): ?bool
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new HeaderRowsReadFilter(self::HEADER_SCAN_ROWS));
            $spreadsheet = $reader->load($absolutePath);
        } catch (Throwable) {
            return null;
        }
        $this->useCachedFormulaResults($spreadsheet);

        return $this->findSheetWithHeaders($spreadsheet) !== null;
    }

    private function useCachedFormulaResults(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                $cached = $cell->isFormula() ? $cell->getOldCalculatedValue() : null;
                if ($cached !== null) {
                    $cell->setValueExplicit(
                        $cached,
                        is_string($cached) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC
                    );
                }
            }
        }
    }

    private function isSummaryRow(string $name, ?string $workDate): bool
    {
        if ($workDate !== null) {
            return false;
        }

        // Punch rows always have 作業日, so containing 計 is enough to catch
        // total-row labels (計 / 合計 / 小計 / 総計 / 総合計) without skipping a
        // real employee such as 計良 太郎.
        return str_contains($name, '計');
    }

    private function findSheetWithHeaders(Spreadsheet $spreadsheet): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // A broken formula in the top rows of some unrelated sheet (a
            // summary tab, a draft left in the workbook) must not disqualify the
            // whole file — skip that sheet and keep looking for the data one.
            try {
                $found = $this->locateHeaders($sheet) !== null;
            } catch (CalculationException) {
                continue;
            }
            if ($found) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: array<string, int>}|null
     */
    private function locateHeaders(Worksheet $sheet): ?array
    {
        $highestRow = min((int) $sheet->getHighestDataRow(), self::HEADER_SCAN_ROWS);
        $highestCol = $sheet->getHighestDataColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestCol);

        for ($row = 1; $row <= $highestRow; $row++) {
            $found = [];
            for ($col = 1; $col <= $highestColIndex; $col++) {
                $text = $this->cellString($sheet, $col, $row);
                if ($text === '') {
                    continue;
                }
                $summaryKey = self::SUMMARY_HEADERS[preg_replace('/[\s\x{3000}]+/u', '', $text) ?? $text] ?? null;
                if ($summaryKey !== null) {
                    $found[$summaryKey] ??= $col;

                    continue;
                }
                if (!isset($found['name']) && str_contains($text, '氏名')) {
                    $found['name'] = $col;
                } elseif (!isset($found['work_date']) && str_contains($text, '作業日')) {
                    $found['work_date'] = $col;
                } elseif (!isset($found['break']) && str_contains($text, '休憩')) {
                    $found['break'] = $col;
                } elseif (!isset($found['work_time_start']) && str_contains($text, '作業時間')) {
                    $found['work_time_start'] = $col;
                    $found['work_time_end'] = $col + 2;
                }
            }
            if (isset($found['name'], $found['work_date'], $found['work_time_start'], $found['break'])) {
                return [$row, $found];
            }
        }

        return null;
    }

    private function readCompanyLabel(Worksheet $sheet): ?string
    {
        for ($row = 1; $row <= 5; $row++) {
            $label = $this->cellString($sheet, 1, $row);
            if (str_contains($label, '会社')) {
                $value = $this->cellString($sheet, 2, $row);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * 氏名 read through vertical merges. When a name is merged across an
     * employee's daily rows, PhpSpreadsheet only holds the value in the
     * range's top-left cell and returns '' for every other row of it — reading
     * the cell alone would make all but the first day of that employee look
     * nameless.
     */
    private function cellName(Worksheet $sheet, ?int $col, int $row): string
    {
        $name = $this->cellString($sheet, $col, $row);
        if ($name !== '' || $col === null) {
            return $name;
        }

        $cell = $sheet->getCell([$col, $row]);
        if (!$cell->isInMergeRange() || $cell->isMergeRangeValueCell()) {
            return '';
        }

        $anchor = explode(':', (string) $cell->getMergeRange())[0];
        $value = $sheet->getCell($anchor)->getCalculatedValue();

        return is_string($value) ? $value : (string) ($value ?? '');
    }

    private function cellString(Worksheet $sheet, ?int $col, int $row): string
    {
        if ($col === null) {
            return '';
        }
        $value = $sheet->getCell([$col, $row])->getCalculatedValue();
        if ($value === null) {
            return '';
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function cellDateTime(Worksheet $sheet, ?int $col, int $row): ?Carbon
    {
        if ($col === null) {
            return null;
        }
        $cell = $sheet->getCell([$col, $row]);
        // Same as cellString()/cellBreakMinutes(): a 作業日 or 作業時間 written as
        // a formula (`=F6`, a cross-sheet reference — common once the file has
        // been edited by hand) would otherwise read back as the formula text
        // and make the row look empty. ExcelDate::isDateTime() only inspects
        // the cell's number format, so it still works on the calculated value.
        $value = $cell->getCalculatedValue();
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($value));
        }
        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        return null;
    }

    private function cellDate(Worksheet $sheet, int $col, int $row): ?string
    {
        $dateTime = $this->cellDateTime($sheet, $col, $row);

        return $dateTime?->format('Y-m-d');
    }

    private function cellBreakMinutes(Worksheet $sheet, int $col, int $row): int
    {
        return $this->cellMinutes($sheet, $col, $row) ?? 0;
    }

    private function cellMinutes(Worksheet $sheet, ?int $col, int $row): ?int
    {
        if ($col === null) {
            return null;
        }
        $value = $sheet->getCell([$col, $row])->getCalculatedValue();
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        if ((float) $value < 0 || (float) $value >= 1) {
            throw new ReconciliationFormatException('invalid_time', [
                'row' => $row,
                'column' => Coordinate::stringFromColumnIndex($col),
            ]);
        }

        return (int) round(((float) $value) * 1440);
    }

    private function collapseSpaces(string $name): string
    {
        $collapsed = preg_replace('/[\s\x{3000}]+/u', ' ', $name) ?? $name;

        return mb_trim($collapsed);
    }

    private function formatRange(?Carbon $start, ?Carbon $end): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        return $start->format('H:i') . ' ~ ' . $end->format('H:i');
    }
}
