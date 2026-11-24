<?php

namespace App\Support\BankAccount;

use Normalizer;

class BankAccountValueNormalizer
{
    private const NAME_SUFFIXES = ['銀行', '信用金庫', '信用組合', '支店', '出張所', '本店', '営業部'];

    public static function digits(mixed $value, int $length): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/u', '', mb_convert_kana((string) $value, 'n'));
        if ($digits === null || $digits === '' || mb_strlen($digits) > $length) {
            return null;
        }

        return mb_str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    public static function kana(mixed $value): ?string
    {
        $value = self::string($value);
        if ($value === null) {
            return null;
        }

        $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        $value = mb_convert_kana($value, 'KVCs');
        $value = preg_replace('/\s+/u', ' ', mb_trim($value));

        return $value === '' ? null : $value;
    }

    public static function kanaComparisonKey(?string $value): ?string
    {
        $value = self::kana($value);
        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', '・', '･', '．', '.', 'ー', '－', '-'], '', $value);
        $value = str_replace(['ヴァ', 'ヴィ', 'ヴェ', 'ヴォ', 'ヴ'], ['バ', 'ビ', 'ベ', 'ボ', 'ブ'], $value);

        return $value === '' ? null : $value;
    }

    public static function latinComparisonKey(?string $value): ?string
    {
        $value = self::string($value);
        if ($value === null) {
            return null;
        }

        $value = mb_convert_kana($value, 'as');
        $value = mb_strtoupper(preg_replace('/[\s\-\.\']+/u', '', $value) ?? '');

        return $value === '' ? null : $value;
    }

    public static function institutionNameKey(?string $value): ?string
    {
        $value = self::string($value);
        if ($value === null) {
            return null;
        }

        $value = mb_convert_kana($value, 'asKVC');
        $value = preg_replace('/\s+/u', '', $value) ?? '';
        $value = str_replace(self::NAME_SUFFIXES, '', $value);

        return $value === '' ? null : mb_strtoupper($value);
    }

    public static function string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
