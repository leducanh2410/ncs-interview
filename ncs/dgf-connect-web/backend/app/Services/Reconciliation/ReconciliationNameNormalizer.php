<?php

namespace App\Services\Reconciliation;

use Normalizer;

class ReconciliationNameNormalizer
{
    public static function normalize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        $value = is_string($normalized) ? $normalized : $value;
        $value = mb_convert_kana($value, 'C');
        $collapsed = preg_replace('/[\s\x{3000}]+/u', ' ', $value) ?? $value;

        return mb_strtoupper(mb_trim($collapsed));
    }

    public static function staffNo(?string $value): string
    {
        $compact = self::compact($value);
        if ($compact !== '' && ctype_digit($compact)) {
            return mb_ltrim($compact, '0') ?: '0';
        }

        return $compact;
    }

    public static function compact(?string $value): string
    {
        return str_replace(' ', '', self::normalize($value));
    }

    /**
     * @return list<string>
     */
    public static function tokens(?string $value): array
    {
        $normalized = self::normalize($value);
        if ($normalized === '') {
            return [];
        }

        return preg_split('/ /', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
