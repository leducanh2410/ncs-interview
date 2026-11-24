<?php

namespace App\Support\Papers;

use App\Support\Ocr\FieldComparisonKey;
use App\Support\Residence\ResidenceSlotResolver;

/**
 * Holds an admin's confirmed reading of a residence card against the employee's profile.
 *
 * The asymmetry this class exists to absorb: the admin side came through
 * OcrFieldNormalizer, the profile side never did — it holds whatever an employee typed on
 * the confirm screen or an admin typed when creating the account. Comparing "as stored"
 * would report a lower-cased card number as a mismatch, so every judged field is brought
 * to one shape on both sides first.
 */
class AdminScanFieldComparator
{
    public const VERDICT_NOT_UPLOADED = 'not_uploaded';

    public const VERDICT_MISMATCHED = 'mismatched';

    public const VERDICT_EMPLOYEE_DATA_MISSING = 'employee_data_missing';

    public const VERDICT_MATCHED = 'matched';

    public const VERDICT_NOT_COMPARABLE = 'not_comparable';

    public const FIELD_MATCH = 'match';

    public const FIELD_MISMATCH = 'mismatch';

    public const FIELD_UNKNOWN = 'unknown';

    /** The only fields that can produce a mismatch. */
    public const COMPARED_FIELDS = [
        'name',
        'birthday',
        'gender',
        'nationality',
        'residence_number',
        'residence_expiry_date',
        'residence_status',
    ];

    /**
     * Shown side by side, never judged. Address formatting, kana transliteration, and the
     * wording printed for a residence status all differ legitimately between a stored
     * record and a fresh read.
     */
    public const DISPLAY_ONLY_FIELDS = [
        'last_name_kana',
        'first_name_kana',
        'address',
        'prefecture',
        'residence_status_raw',
    ];

    /**
     * @param array<string, mixed> $employeeFields always present; an absent residence card
     *        blanks its three fields, not the whole side
     * @param array<string, mixed>|null $adminFields null when nobody has scanned this employee
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $cards all three slots
     * @return array{verdict: string, employee_residence_missing: bool, mismatch_count: int,
     *         fields: array<int, array<string, mixed>>}
     */
    public function __invoke(
        array $employeeFields,
        ?array $adminFields,
        bool $residenceMissing = false,
        array $cards = [],
        ?int $currentSlot = null
    ): array {
        $fields = [];
        $mismatchCount = 0;
        $hasMatch = false;

        foreach (self::COMPARED_FIELDS as $field) {
            $employee = $this->stringOrNull($employeeFields[$field] ?? null);
            $admin = $this->stringOrNull($adminFields[$field] ?? null);

            $row = [
                'field' => $field,
                'employee' => $employee,
                'admin' => $admin,
                'compared' => true,
                'verdict' => self::FIELD_UNKNOWN,
            ];

            if ($employee !== null && $admin !== null) {
                $same = $this->keyFor($field, $employee) === $this->keyFor($field, $admin);
                $row['verdict'] = $same ? self::FIELD_MATCH : self::FIELD_MISMATCH;

                if ($same) {
                    $hasMatch = true;
                } else {
                    $mismatchCount++;

                    if ($field === 'residence_number') {
                        // A card the record has superseded and a card the record has never
                        // seen lead a reviewer to opposite conclusions. Say which this is.
                        $row['matches_previous_slot'] = $this->previousSlotFor($admin, $cards, $currentSlot);
                    }
                }
            }

            $fields[] = $row;
        }

        foreach (self::DISPLAY_ONLY_FIELDS as $field) {
            $fields[] = [
                'field' => $field,
                'employee' => $this->stringOrNull($employeeFields[$field] ?? null),
                'admin' => $this->stringOrNull($adminFields[$field] ?? null),
                'compared' => false,
                'verdict' => null,
            ];
        }

        return [
            'verdict' => $this->verdict($adminFields, $mismatchCount, $residenceMissing, $hasMatch),
            'employee_residence_missing' => $residenceMissing,
            'mismatch_count' => $mismatchCount,
            'fields' => $fields,
        ];
    }

    /**
     * The order is the contract, not an implementation detail: more than one of these can
     * be true of the same row, and which badge the employee gets must not depend on
     * whichever branch happened to run first.
     *
     * @param array<string, mixed>|null $adminFields
     */
    private function verdict(?array $adminFields, int $mismatchCount, bool $residenceMissing, bool $hasMatch): string
    {
        if ($adminFields === null) {
            // Nothing to compare against, whatever the profile holds.
            return self::VERDICT_NOT_UPLOADED;
        }

        if ($mismatchCount > 0) {
            // Ahead of employee_data_missing on purpose: a card bearing the wrong name is
            // the more urgent of the two, and the missing card survives in its own flag.
            return self::VERDICT_MISMATCHED;
        }

        if ($residenceMissing) {
            return self::VERDICT_EMPLOYEE_DATA_MISSING;
        }

        return $hasMatch ? self::VERDICT_MATCHED : self::VERDICT_NOT_COMPARABLE;
    }

    /**
     * Both sides are normalized identically here. Only the admin side ever passed through
     * OcrFieldNormalizer, so anything it did has to be done to the profile side too.
     */
    private function keyFor(string $field, string $value): string
    {
        $value = match ($field) {
            'name' => mb_strtoupper($value),
            // The same expression OcrFieldNormalizer applies to the admin side.
            'residence_number' => mb_strtoupper(str_replace([' ', '-'], '', $value)),
            'birthday', 'residence_expiry_date' => mb_substr($value, 0, 10),
            default => $value,
        };

        return FieldComparisonKey::for($field, $value);
    }

    /**
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $cards
     */
    private function previousSlotFor(string $adminNumber, array $cards, ?int $currentSlot): ?int
    {
        $needle = $this->keyFor('residence_number', $adminNumber);

        for ($slot = 1; $slot <= ResidenceSlotResolver::SLOT_COUNT; $slot++) {
            if ($slot === $currentSlot) {
                continue;
            }

            $number = $cards[$slot]['number'] ?? null;
            if (is_string($number) && $number !== '' && $this->keyFor('residence_number', $number) === $needle) {
                return $slot;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_trim((string) $value) === '' ? null : (string) $value;
    }
}
