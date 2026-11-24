<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class CustomTime implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = 'The :attribute field must match the format H:i.';

        $parts = explode(':', $value);
        if (count($parts) !== 2) {
            $fail($message);
        }

        if (!is_numeric($parts[0]) || !is_numeric($parts[1])) {
            $fail($message);
        }

        $hour = (int) $parts[0];
        $minute = (int) $parts[1];
        if ($hour < 0 || $hour > 47 || $minute < 0 || $minute > 59) {
            $fail($message);
        }
    }
}
