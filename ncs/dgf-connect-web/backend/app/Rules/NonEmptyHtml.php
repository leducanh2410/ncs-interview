<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NonEmptyHtml implements ValidationRule
{
    public static function isBlank(?string $html): bool
    {
        if ($html === null) {
            return true;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', '', $text) ?? '';

        return $text === '';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || self::isBlank($value)) {
            $fail('validation.required')->translate();
        }
    }
}
