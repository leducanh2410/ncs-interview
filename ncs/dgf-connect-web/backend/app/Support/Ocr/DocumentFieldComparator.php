<?php

namespace App\Support\Ocr;

use App\Services\External\OpenRouterOcrService;

/**
 * Finds fields that two document images of the same person disagree on.
 *
 * Every OCR group extracts a few identity fields on purpose so they can be held against
 * each other: a residence card whose scanned copy names someone else, or a student card
 * belonging to a different person, is otherwise invisible until an admin spots it by eye.
 */
class DocumentFieldComparator
{
    /**
     * Fields each group contributes to the comparison. A group that does not print a field
     * (a student card rarely shows nationality) simply does not contribute it.
     *
     * The card number is only shared by the two readings of the residence card, which is
     * exactly what splitting them apart is for: it is the one field that says whether the
     * scan is a copy of the card in hand, and unlike a name it needs no normalization.
     *
     * @var array<string, string[]>
     */
    private const COMPARABLE_FIELDS = [
        OpenRouterOcrService::GROUP_RESIDENCE_CARD => ['name', 'birthday', 'nationality', 'residence_number'],
        OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN => ['name', 'birthday', 'nationality', 'residence_number'],
        OpenRouterOcrService::GROUP_PASSPORT => ['name', 'birthday'],
        OpenRouterOcrService::GROUP_STUDENT_CARD => ['name'],
    ];

    /**
     * Compare the fields of several completed OCR runs.
     *
     * @param array<string, array<string, mixed>> $fieldsByGroup group => normalized OCR fields
     * @return array<int, array{field: string, values: array<int, array{group: string, value: string}>}>
     *         one entry per disagreeing field, empty when everything lines up
     */
    public function __invoke(array $fieldsByGroup): array
    {
        $mismatches = [];

        foreach ($this->comparedFields() as $field) {
            $seen = [];
            foreach ($fieldsByGroup as $group => $fields) {
                if (!in_array($field, self::COMPARABLE_FIELDS[$group] ?? [], true)) {
                    continue;
                }

                $value = $fields[$field] ?? null;
                if (!is_string($value) || $value === '') {
                    // A field the OCR could not read says nothing about agreement
                    continue;
                }

                $seen[] = ['group' => $group, 'value' => $value];
            }

            if (count($seen) < 2) {
                continue;
            }

            $keys = array_map(fn(array $entry) => FieldComparisonKey::for($field, $entry['value']), $seen);
            if (count(array_unique($keys)) > 1) {
                $mismatches[] = ['field' => $field, 'values' => $seen];
            }
        }

        return $mismatches;
    }

    /**
     * @return string[]
     */
    private function comparedFields(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::COMPARABLE_FIELDS))));
    }
}
