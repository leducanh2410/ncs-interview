<?php

namespace App\Actions\Admin\PdfExport\Excel;

use App\Models\Traits\NormalizesDiacritics;
use App\Models\User;
use App\ValueObjects\PdfExport\ExcelUserRow;
use Illuminate\Support\Collection;

class ResolveExcelUsersAction
{
    use NormalizesDiacritics;

    public const MATCHED_BY_EXACT = 'exact';

    public const MATCHED_BY_NUMERIC = 'numeric';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_AMBIGUOUS = 'ambiguous';

    public const REASON_MISSING_STAFF_NO = 'missing_staff_no';

    private const LOOKUP_CHUNK_SIZE = 500;

    private const USER_COLUMNS = [
        'id',
        'staff_no',
        'first_name',
        'last_name',
        'first_name_kana',
        'last_name_kana',
        'short_name',
        'is_disabled',
    ];

    /**
     * @param array<int, ExcelUserRow> $rows
     * @return array{
     *     matched: array<int, array<string, mixed>>,
     *     unmatched: array<int, array<string, mixed>>,
     *     duplicates: array<int, array<string, mixed>>,
     *     user_ids: array<int, int>,
     *     summary: array<string, int>
     * }
     */
    public function __invoke(array $rows): array
    {
        $exactUsers = $this->findUsersByExactStaffNo($rows);
        $numericUsers = $this->findUsersByNumericStaffNo($rows, $exactUsers);

        $matched = [];
        $unmatched = [];
        $duplicates = [];
        $firstRowByUserId = [];

        foreach ($rows as $row) {
            if ($row->staffNo === '') {
                $unmatched[] = $this->unmatchedRow($row, self::REASON_MISSING_STAFF_NO);

                continue;
            }

            $user = $exactUsers->get($this->staffNoKey($row->staffNo));
            $matchedBy = self::MATCHED_BY_EXACT;

            if (!$user && $row->isNumericStaffNo()) {
                $candidates = $numericUsers->get((int) $row->staffNo, collect());

                if ($candidates->count() > 1) {
                    $unmatched[] = $this->unmatchedRow($row, self::REASON_AMBIGUOUS, $candidates);

                    continue;
                }

                $user = $candidates->first();
                $matchedBy = self::MATCHED_BY_NUMERIC;
            }

            if (!$user) {
                $unmatched[] = $this->unmatchedRow($row, self::REASON_NOT_FOUND);

                continue;
            }

            if (isset($firstRowByUserId[$user->id])) {
                $duplicates[] = [
                    ...$row->toArray(),
                    'first_row_number' => $firstRowByUserId[$user->id],
                    'user' => $this->userPayload($user),
                ];

                continue;
            }

            $firstRowByUserId[$user->id] = $row->rowNumber;

            $matched[] = [
                ...$row->toArray(),
                'user' => $this->userPayload($user),
                'matched_by' => $matchedBy,
                'is_disabled' => (bool) $user->is_disabled,
                'name_mismatch' => $row->name !== null && !$this->nameMatchesUser($row->name, $user),
            ];
        }

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'duplicates' => $duplicates,
            'user_ids' => array_keys($firstRowByUserId),
            'summary' => [
                'total_rows' => count($rows),
                'matched_count' => count($matched),
                'unmatched_count' => count($unmatched),
                'duplicate_count' => count($duplicates),
                'disabled_count' => count(array_filter($matched, fn(array $item) => $item['is_disabled'])),
                'name_mismatch_count' => count(array_filter($matched, fn(array $item) => $item['name_mismatch'])),
                'numeric_match_count' => count(
                    array_filter($matched, fn(array $item) => $item['matched_by'] === self::MATCHED_BY_NUMERIC)
                ),
            ],
        ];
    }

    /**
     * @param array<int, ExcelUserRow> $rows
     * @return Collection<string, User>
     */
    private function findUsersByExactStaffNo(array $rows): Collection
    {
        $staffNos = collect($rows)
            ->map(fn(ExcelUserRow $row) => $row->staffNo)
            ->filter(fn(string $staffNo) => $staffNo !== '')
            ->unique()
            ->values();

        $users = collect();

        foreach ($staffNos->chunk(self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $users = $users->merge(User::query()->whereIn('staff_no', $chunk->all())->get(self::USER_COLUMNS));
        }

        return $users->keyBy(fn(User $user) => $this->staffNoKey((string) $user->staff_no));
    }

    /**
     * @param array<int, ExcelUserRow> $rows
     * @param Collection<string, User> $exactUsers
     * @return Collection<int, Collection<int, User>>
     */
    private function findUsersByNumericStaffNo(array $rows, Collection $exactUsers): Collection
    {
        $numericValues = collect($rows)
            ->filter(
                fn(ExcelUserRow $row) => $row->staffNo !== '' && !$exactUsers->has($this->staffNoKey($row->staffNo))
            )
            ->filter(fn(ExcelUserRow $row) => $row->isNumericStaffNo())
            ->map(fn(ExcelUserRow $row) => (int) $row->staffNo)
            ->unique()
            ->values();

        if ($numericValues->isEmpty()) {
            return collect();
        }

        $users = collect();

        foreach ($numericValues->chunk(self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, $chunk->count(), '?'));

            $users = $users->merge(
                User::query()
                    ->whereRaw("staff_no REGEXP '^[0-9]+$'")
                    ->whereRaw("CAST(staff_no AS UNSIGNED) IN ({$placeholders})", $chunk->values()->all())
                    ->get(self::USER_COLUMNS)
            );
        }

        return $users->groupBy(fn(User $user) => (int) $user->staff_no);
    }

    /**
     * @param Collection<int, User>|null $candidates
     * @return array<string, mixed>
     */
    private function unmatchedRow(ExcelUserRow $row, string $reason, ?Collection $candidates = null): array
    {
        return [
            ...$row->toArray(),
            'reason' => $reason,
            'candidates' => $candidates?->map(fn(User $user) => $this->userPayload($user))->values()->all() ?? [],
        ];
    }

    /**
     * @return array{id: int, name: string, staff_no: string|null, is_disabled: bool}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'staff_no' => $user->staff_no,
            'is_disabled' => (bool) $user->is_disabled,
        ];
    }

    private function staffNoKey(string $staffNo): string
    {
        return mb_strtoupper($staffNo);
    }

    private function nameMatchesUser(string $name, User $user): bool
    {
        $needle = $this->normalizeForComparison($name);

        if ($needle === '') {
            return true;
        }

        $candidates = [
            $user->last_name . $user->first_name,
            $user->first_name . $user->last_name,
            $user->last_name_kana . $user->first_name_kana,
            $user->first_name_kana . $user->last_name_kana,
            $user->short_name,
        ];

        foreach ($candidates as $candidate) {
            if ($this->normalizeForComparison((string) $candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function normalizeForComparison(string $value): string
    {
        $value = mb_convert_kana($value, 'asKVC');
        $value = $this->removeDiacritics($value) ?? $value;
        $value = preg_replace('/[\s\x{3000}・･.,、。\-―‐]+/u', '', $value) ?? $value;

        return mb_strtoupper($value);
    }
}
