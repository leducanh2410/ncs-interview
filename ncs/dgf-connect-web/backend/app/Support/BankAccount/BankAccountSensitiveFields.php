<?php

namespace App\Support\BankAccount;

class BankAccountSensitiveFields
{
    public const KEYS = ['account_number', 'yucho_number'];

    public static function mask(mixed $value): ?string
    {
        if (!is_scalar($value) || (string) $value === '') {
            return null;
        }

        $value = (string) $value;

        return str_repeat('*', max(mb_strlen($value) - 3, 0)) . mb_substr($value, -3);
    }

    /**
     * @param array<string, mixed>|null $fields
     * @return array<string, mixed>|null
     */
    public static function maskFields(?array $fields): ?array
    {
        if ($fields === null) {
            return null;
        }

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null) {
                $fields[$key] = self::mask($fields[$key]);
            }
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $mismatches
     * @return array<int, array<string, mixed>>
     */
    public static function maskMismatches(array $mismatches): array
    {
        return array_map(
            fn(array $mismatch) => in_array($mismatch['field'] ?? null, self::KEYS, true)
                ? array_merge($mismatch, [
                    'ocr_value' => self::mask($mismatch['ocr_value'] ?? null),
                    'input_value' => self::mask($mismatch['input_value'] ?? null),
                ])
                : $mismatch,
            $mismatches
        );
    }
}
