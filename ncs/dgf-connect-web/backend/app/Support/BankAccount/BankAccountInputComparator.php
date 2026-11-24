<?php

namespace App\Support\BankAccount;

class BankAccountInputComparator
{
    private const NUMERIC_FIELDS = [
        'bank_code' => 4,
        'branch_code' => 3,
        'account_number' => 7,
        'yucho_symbol' => 5,
        'yucho_number' => 8,
    ];

    private const NAME_FIELDS = ['bank_name', 'branch_name'];

    private const KANA_FIELDS = ['account_holder_kana' => 'holder_name_kana'];

    /**
     * @param array<string, mixed> $ocrFields
     * @param array<string, mixed> $confirmed
     * @return array<int, array{field: string, ocr_value: string|null, input_value: string|null}>
     */
    public function compare(array $ocrFields, array $confirmed): array
    {
        $mismatches = [];

        foreach (self::NUMERIC_FIELDS as $field => $length) {
            $ocr = BankAccountValueNormalizer::digits($ocrFields[$field] ?? null, $length);
            if ($ocr === null) {
                continue;
            }
            $input = BankAccountValueNormalizer::digits($confirmed[$field] ?? null, $length);
            if ($input !== $ocr) {
                $mismatches[] = $this->entry($field, $ocrFields[$field] ?? null, $confirmed[$field] ?? null);
            }
        }

        foreach (self::NAME_FIELDS as $field) {
            $ocr = BankAccountValueNormalizer::institutionNameKey($ocrFields[$field] ?? null);
            if ($ocr === null) {
                continue;
            }
            $input = BankAccountValueNormalizer::institutionNameKey($confirmed[$field] ?? null);
            if ($input !== $ocr) {
                $mismatches[] = $this->entry($field, $ocrFields[$field] ?? null, $confirmed[$field] ?? null);
            }
        }

        foreach (self::KANA_FIELDS as $inputField => $ocrField) {
            $ocr = BankAccountValueNormalizer::kanaComparisonKey($ocrFields[$ocrField] ?? null);
            if ($ocr === null) {
                continue;
            }
            $input = BankAccountValueNormalizer::kanaComparisonKey($confirmed[$inputField] ?? null);
            if ($input !== $ocr) {
                $mismatches[] = $this->entry(
                    $inputField,
                    $ocrFields[$ocrField] ?? null,
                    $confirmed[$inputField] ?? null
                );
            }
        }

        return $mismatches;
    }

    /**
     * @return array{field: string, ocr_value: string|null, input_value: string|null}
     */
    private function entry(string $field, mixed $ocrValue, mixed $inputValue): array
    {
        return [
            'field' => $field,
            'ocr_value' => is_scalar($ocrValue) ? (string) $ocrValue : null,
            'input_value' => is_scalar($inputValue) ? (string) $inputValue : null,
        ];
    }
}
