<?php

namespace App\Support\BankAccount;

class BankAccountConfirmRules
{
    public const KANA_PATTERN = '/^[ァ-ヶー　 ]+$/u';

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(bool $isYucho): array
    {
        $rules = [
            'ocr_result_id' => ['nullable', 'integer'],
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'branch_name' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'regex:/^\d{3}$/'],
            'account_number' => ['required', 'string', 'regex:/^\d{1,7}$/'],
            'account_holder_kana' => ['required', 'string', 'max:60', 'regex:' . self::KANA_PATTERN],
            'account_holder_latin' => ['nullable', 'string', 'max:100'],
            'is_yucho' => ['required', 'boolean'],
            'yucho_symbol' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'yucho_number' => ['nullable', 'string', 'regex:/^\d{1,8}$/'],
        ];

        if ($isYucho) {
            $rules['yucho_symbol'] = ['required', 'string', 'regex:/^\d{5}$/'];
            $rules['yucho_number'] = ['required', 'string', 'regex:/^\d{1,8}$/'];
        } else {
            $rules['branch_name'][] = 'required_without:branch_code';
            $rules['branch_code'][] = 'required_without:branch_name';
        }

        return $rules;
    }
}
