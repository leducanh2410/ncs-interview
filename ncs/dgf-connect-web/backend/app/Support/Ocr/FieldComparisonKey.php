<?php

namespace App\Support\Ocr;

/**
 * Turns a field value into the key two readings of it are compared by.
 *
 * Shared by DocumentFieldComparator (employee image against employee image) and
 * AdminScanFieldComparator (admin scan against the employee's profile) so the two can
 * never drift into disagreeing about what counts as the same name.
 */
class FieldComparisonKey
{
    /**
     * Names are compared as an unordered set of parts: a residence card prints the family
     * name first, a student card may not, and that ordering difference is not a mismatch.
     * Everything else is compared as given; callers are responsible for handing in values
     * that are already normalized the same way on both sides.
     */
    public static function for(string $field, string $value): string
    {
        if ($field !== 'name') {
            return $value;
        }

        $parts = preg_split('/\s+/u', mb_trim($value)) ?: [];
        sort($parts);

        return implode(' ', $parts);
    }
}
