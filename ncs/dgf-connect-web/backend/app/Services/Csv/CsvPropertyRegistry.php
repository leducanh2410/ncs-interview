<?php

namespace App\Services\Csv;

use App\Enums\Gender;
use App\Enums\JapaneseLevel;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;

class CsvPropertyRegistry
{
    /** @var array<string, CsvPropertyDefinition>|null */
    private ?array $cache = null;

    /**
     * @return array<string, CsvPropertyDefinition>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $definitions = [
            new CsvPropertyDefinition(
                'first_name',
                '名',
                CsvPropertyDefinition::SOURCE_USER,
                'first_name',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'last_name',
                '姓',
                CsvPropertyDefinition::SOURCE_USER,
                'last_name',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'first_name_kana',
                '名（カナ）',
                CsvPropertyDefinition::SOURCE_USER,
                'first_name_kana',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'last_name_kana',
                '姓（カナ）',
                CsvPropertyDefinition::SOURCE_USER,
                'last_name_kana',
                CsvPropertyDefinition::TYPE_STRING
            ),
            // Sri Lankan employees have their long "house name" trimmed to a
            // display-friendly short_name (see User::getShortNameAttribute());
            // exposed here as an explicit column since first_name/last_name
            // above are always the raw, untrimmed values.
            new CsvPropertyDefinition(
                'short_name',
                '表示名',
                CsvPropertyDefinition::SOURCE_USER,
                'short_name',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'birthday',
                '誕生日',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'birthday',
                CsvPropertyDefinition::TYPE_DATE
            ),
            new CsvPropertyDefinition(
                'gender',
                '性別',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'gender',
                Gender::class
            ),
            new CsvPropertyDefinition(
                'nationality',
                '国籍',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'nationality',
                Nationality::class
            ),
            new CsvPropertyDefinition(
                'phone',
                '電話',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'phone',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'emergency_contact_phone',
                '緊急連絡先',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'emergency_contact_phone',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'email',
                'メール',
                CsvPropertyDefinition::SOURCE_USER,
                'email',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'postal_code',
                '郵便番号',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'postal_code',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'prefecture',
                '都道府県',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'prefecture',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'address',
                '住所',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'address',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'registered_location',
                '登録所在地',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'registered_location',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'staff_no',
                '従業員番号',
                CsvPropertyDefinition::SOURCE_USER,
                'staff_no',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'japanese_level',
                '日本語レベル',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'japanese_level',
                JapaneseLevel::class
            ),
            new CsvPropertyDefinition(
                'residence_number',
                '在留カード番号',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'residence_number',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'residence_expiry_date',
                '在留期限',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'residence_expiry_date',
                CsvPropertyDefinition::TYPE_DATE
            ),
            new CsvPropertyDefinition(
                'residence_status',
                '在留資格',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'residence_status',
                ResidenceStatus::class
            ),
            new CsvPropertyDefinition(
                'passport_number',
                'パスポート番号',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'passport_number',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'passport_expiry_date',
                'パスポート有効期限',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'passport_expiry_date',
                CsvPropertyDefinition::TYPE_DATE
            ),
            new CsvPropertyDefinition(
                'student_card_expiry_date',
                '学生証の有効期限',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'student_card_expiry_date',
                CsvPropertyDefinition::TYPE_DATE
            ),
            new CsvPropertyDefinition(
                'is_household_head',
                '世帯主',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'is_household_head',
                CsvPropertyDefinition::TYPE_BOOLEAN
            ),
            new CsvPropertyDefinition(
                'facebook_link',
                'Facebook link',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'facebook_link',
                CsvPropertyDefinition::TYPE_STRING
            ),
            new CsvPropertyDefinition(
                'line_link',
                'LINE link',
                CsvPropertyDefinition::SOURCE_USER_INFO,
                'line_link',
                CsvPropertyDefinition::TYPE_STRING
            ),
        ];

        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }

        return $this->cache = $byKey;
    }

    public function get(string $key): ?CsvPropertyDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string> unknown keys
     */
    public function validateKeys(array $keys): array
    {
        $known = $this->all();

        return array_values(array_filter($keys, fn(string $key) => !isset($known[$key])));
    }

    /**
     * @return array<int, string>
     */
    public function defaultOrderedKeys(): array
    {
        return array_keys($this->all());
    }
}
