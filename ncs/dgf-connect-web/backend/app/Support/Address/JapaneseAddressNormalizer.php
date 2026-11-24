<?php

namespace App\Support\Address;

use Normalizer;

/**
 * Canonical form used for matching addresses against Japan Post data. Applied
 * identically to the imported rows and to user input, so both sides agree on
 * digit width, spacing and the few kana variants that differ between sources.
 */
class JapaneseAddressNormalizer
{
    private const KANA_VARIANTS = [
        'ヶ' => 'ケ',
        'ヵ' => 'カ',
        'ｹ' => 'ケ',
    ];

    public static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // NFKC folds full-width digits/latin to ASCII and half-width kana to full-width
        $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        $value = preg_replace('/[\s　]+/u', '', $value) ?? $value;

        return strtr($value, self::KANA_VARIANTS);
    }

    /**
     * Japan Post omits the 大字/字 prefix from town names, while registered
     * addresses (and therefore residence cards) sometimes keep it.
     */
    public static function stripAzaPrefix(string $town): string
    {
        return preg_replace('/^(大字|字)/u', '', $town) ?? $town;
    }

    /**
     * @return ?string seven digits, or null when the input is not a postal code
     */
    public static function normalizePostalCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/u', '', self::normalize($value)) ?? '';

        return mb_strlen($digits) === 7 ? $digits : null;
    }
}
