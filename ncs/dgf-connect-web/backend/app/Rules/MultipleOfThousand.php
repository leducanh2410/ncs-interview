<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class MultipleOfThousand implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = 'The :attribute must be a multiple of 1000.';

        if (!is_numeric($value)) {
            $fail($message);
        }

        if ($value % 1000 !== 0) {
            $fail($message);
        }
    }
}
