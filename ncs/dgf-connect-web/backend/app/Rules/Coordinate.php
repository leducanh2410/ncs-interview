<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class Coordinate implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = 'The :attribute must be a valid coordinate.';

        $coordinates = explode(',', $value);
        if (count($coordinates) !== 2) {
            $fail($message);
        }

        $latitude = mb_trim($coordinates[0]);
        $longitude = mb_trim($coordinates[1]);
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            $fail($message);
        }

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $fail($message);
        }
    }
}
