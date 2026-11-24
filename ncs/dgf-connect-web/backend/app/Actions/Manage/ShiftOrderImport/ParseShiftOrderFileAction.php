<?php

namespace App\Actions\Manage\ShiftOrderImport;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ParseShiftOrderFileAction
{
    public const COL_TOTAL_KEY = 1;

    public const COL_WORKPLACE_ID = 2;

    public const COL_LABEL = 3;

    public const COL_FIRST_DAY = 4;

    public const COL_LAST_DAY = 34;

    private const TIME_SEPARATORS = ['〜', '～', '~'];

    /**
     * @param Collection<int, Collection<int, mixed>> $rows
     * @return array{sheet_name: string|null, month: string|null, blocks: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     */
    public function __invoke(Collection $rows, ?string $sheetName = null): array
    {
        $errors = [];
        $blocks = [];
        $rowCount = $rows->count();
        $index = 0;

        while ($index < $rowCount) {
            $dayColumns = $this->readDayColumns($rows->get($index));
            if ($dayColumns === [] || !$this->isWorkplaceId($this->cell($rows->get($index), self::COL_WORKPLACE_ID))) {
                $index++;

                continue;
            }

            [$block, $blockErrors, $index] = $this->readBlock($rows, $index, $dayColumns);
            $blocks[] = $block;
            $errors = array_merge($errors, $blockErrors);
        }

        if ($blocks === []) {
            $errors[] = ['code' => 'no_workplace_block'];
        }

        return [
            'sheet_name' => $sheetName,
            'month' => $this->resolveMonth($rows, $sheetName),
            'blocks' => $blocks,
            'errors' => $errors,
        ];
    }

    /**
     * @param Collection<int, Collection<int, mixed>> $rows
     * @param array<int, int> $dayColumns
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: int}
     */
    private function readBlock(Collection $rows, int $headerIndex, array $dayColumns): array
    {
        $headerRow = $rows->get($headerIndex);
        $workplaceId = (int) $this->cell($headerRow, self::COL_WORKPLACE_ID);
        $workplaceName = (string) $this->cell($headerRow, self::COL_LABEL);

        $errors = [];
        $groups = [];
        $pendingPositions = [];
        $index = $headerIndex + 1;
        $rowCount = $rows->count();

        while ($index < $rowCount) {
            $row = $rows->get($index);

            if ($this->cell($row, self::COL_TOTAL_KEY) !== null) {
                break;
            }

            if ($this->isWorkplaceId($this->cell($row, self::COL_WORKPLACE_ID)) && $this->readDayColumns($row) !== []) {
                break;
            }

            $label = $this->cell($row, self::COL_LABEL);
            if ($label === null) {
                $index++;

                continue;
            }

            $label = mb_trim((string) $label);
            $sheetRow = $index + 1;

            if ($this->isTimeLabel($label)) {
                $time = $this->parseTimeLabel($label);
                if ($time === null) {
                    $errors[] = [
                        'code' => 'invalid_shift_time',
                        'workplace_name' => $workplaceName,
                        'row' => $sheetRow,
                        'cell' => $this->cellRef(self::COL_LABEL, $sheetRow),
                        'value' => $label,
                    ];
                    $pendingPositions = [];
                    $index++;

                    continue;
                }

                [$counts, $countErrors, $provided] = $this->readCounts($row, $dayColumns, $sheetRow, $workplaceName);
                $errors = array_merge($errors, $countErrors);

                $groups[] = [
                    'positions' => $pendingPositions,
                    'shift' => [
                        'label' => $this->normalizeTimeLabel($label),
                        'start_time' => $time['start'],
                        'end_time' => $time['end'],
                        'row' => $sheetRow,
                        'counts' => $counts,
                        'provided' => $provided,
                    ],
                ];
                $pendingPositions = [];
                $index++;

                continue;
            }

            [$counts, $countErrors, $provided] = $this->readCounts($row, $dayColumns, $sheetRow, $workplaceName);
            if ($provided === []) {
                $index++;

                continue;
            }

            $errors = array_merge($errors, $countErrors);
            $pendingPositions[] = [
                'name' => $label,
                'row' => $sheetRow,
                'counts' => $counts,
                'provided' => $provided,
            ];
            $index++;
        }

        if ($pendingPositions !== []) {
            $errors[] = [
                'code' => 'positions_without_shift',
                'workplace_name' => $workplaceName,
                'row' => $pendingPositions[0]['row'],
                'cell' => $this->cellRef(self::COL_LABEL, $pendingPositions[0]['row']),
            ];
        }

        $errors = array_merge($errors, $this->findDuplicateShifts($groups, $workplaceName));

        $block = [
            'workplace_id' => $workplaceId,
            'workplace_name' => $workplaceName,
            'row' => $headerIndex + 1,
            'day_columns' => $dayColumns,
            'groups' => $groups,
        ];

        return [$block, $errors, $index];
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function findDuplicateShifts(array $groups, string $workplaceName): array
    {
        $seen = [];
        $errors = [];
        foreach ($groups as $group) {
            $key = $group['shift']['start_time'] . '-' . $group['shift']['end_time'];
            if (isset($seen[$key])) {
                $errors[] = [
                    'code' => 'duplicate_shift',
                    'workplace_name' => $workplaceName,
                    'row' => $group['shift']['row'],
                    'cell' => $this->cellRef(self::COL_LABEL, $group['shift']['row']),
                    'value' => $group['shift']['label'],
                ];

                continue;
            }
            $seen[$key] = true;
        }

        return $errors;
    }

    /**
     * @param Collection<int, mixed>|null $row
     * @param array<int, int> $dayColumns
     * @return array{0: array<int, int>, 1: array<int, array<string, mixed>>, 2: array<int, bool>}
     */
    private function readCounts(?Collection $row, array $dayColumns, int $sheetRow, string $workplaceName): array
    {
        $counts = [];
        $errors = [];
        $provided = [];

        foreach (array_keys($dayColumns) as $column) {
            $value = $this->cell($row, $column);
            if ($value === null) {
                $counts[$column] = 0;

                continue;
            }

            $provided[$column] = true;
            if (!is_numeric($value)) {
                $errors[] = [
                    'code' => 'invalid_number',
                    'workplace_name' => $workplaceName,
                    'row' => $sheetRow,
                    'cell' => $this->cellRef($column, $sheetRow),
                    'value' => (string) $value,
                ];
                $counts[$column] = 0;

                continue;
            }

            $count = (int) round((float) $value);
            if ($count < 0) {
                $errors[] = [
                    'code' => 'negative_total',
                    'workplace_name' => $workplaceName,
                    'row' => $sheetRow,
                    'cell' => $this->cellRef($column, $sheetRow),
                    'value' => (string) $count,
                ];
                $counts[$column] = 0;

                continue;
            }

            $counts[$column] = $count;
        }

        return [$counts, $errors, $provided];
    }

    /**
     * @param Collection<int, mixed>|null $row
     * @return array<int, int>
     */
    private function readDayColumns(?Collection $row): array
    {
        if ($row === null) {
            return [];
        }

        $dayColumns = [];
        for ($column = self::COL_FIRST_DAY; $column <= self::COL_LAST_DAY; $column++) {
            $day = $this->parseDayLabel($this->cell($row, $column));
            if ($day === null) {
                continue;
            }
            $dayColumns[$column] = $day;
        }

        return $dayColumns;
    }

    private function parseDayLabel(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $day = (int) $value;

            return $day >= 1 && $day <= 31 ? $day : null;
        }

        if (!preg_match('/^(\d{1,2})\s*日?$/u', mb_trim((string) $value), $matches)) {
            return null;
        }

        $day = (int) $matches[1];

        return $day >= 1 && $day <= 31 ? $day : null;
    }

    private function isWorkplaceId(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        $value = is_string($value) ? mb_trim($value) : $value;

        return is_numeric($value) && (int) $value > 0;
    }

    private function isTimeLabel(string $label): bool
    {
        foreach (self::TIME_SEPARATORS as $separator) {
            if (str_contains($label, $separator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{start: string, end: string}|null
     */
    private function parseTimeLabel(string $label): ?array
    {
        $normalized = str_replace(self::TIME_SEPARATORS, '~', $this->stripSpaces($label));
        $parts = explode('~', $normalized);
        if (count($parts) !== 2) {
            return null;
        }

        $start = $this->parseTime($parts[0]);
        $end = $this->parseTime($parts[1]);
        if ($start === null || $end === null) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    private function parseTime(string $value): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 47 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour % 24, $minute);
    }

    private function normalizeTimeLabel(string $label): string
    {
        return str_replace(self::TIME_SEPARATORS, '〜', $this->stripSpaces($label));
    }

    private function stripSpaces(string $value): string
    {
        return preg_replace('/[\s\x{00A0}\x{3000}]+/u', '', $value) ?? $value;
    }

    /**
     * @param Collection<int, Collection<int, mixed>> $rows
     */
    private function resolveMonth(Collection $rows, ?string $sheetName): ?string
    {
        $value = $this->cell($rows->get(0), self::COL_LABEL);
        if (is_numeric($value) && (float) $value > 1) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y-m');
        }

        if ($sheetName !== null && preg_match('/(\d{4})\s*[.\-\/年]\s*(\d{1,2})/u', $sheetName, $matches)) {
            $month = (int) $matches[2];
            if ($month >= 1 && $month <= 12) {
                return sprintf('%04d-%02d', (int) $matches[1], $month);
            }
        }

        return null;
    }

    /**
     * @param Collection<int, mixed>|null $row
     */
    private function cell(?Collection $row, int $column): mixed
    {
        if ($row === null) {
            return null;
        }

        $value = $row->get($column);
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = mb_trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    private function cellRef(int $column, int $sheetRow): string
    {
        return Coordinate::stringFromColumnIndex($column + 1) . $sheetRow;
    }
}
