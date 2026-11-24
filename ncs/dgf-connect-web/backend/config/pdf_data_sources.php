<?php

return [
    'sources' => [
        // User model fields
        [
            'key' => 'user.email',
            'name' => 'Email',
            'placeholder' => 'yamada.taro@example.com',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user.name',
            'name' => 'Full Name',
            'placeholder' => '山田 太郎',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user.name_kana',
            'name' => 'Full Name (Kana)',
            'placeholder' => 'ヤマダ タロウ',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user.staff_no',
            'name' => 'Staff Number',
            'placeholder' => '37001',
            'type' => 'model_field',
            'group' => 'User',
        ],

        // UserInfo model fields
        [
            'key' => 'user_info.birthday',
            'name' => 'Birthday',
            'placeholder' => '1990-01-15',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.phone',
            'name' => 'Phone',
            'placeholder' => '090-1234-5678',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.emergency_contact_phone',
            'name' => 'Emergency Contact Phone',
            'placeholder' => '03-1234-5678',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.address',
            'name' => 'Address',
            'placeholder' => '東京都渋谷区道玄坂 1-2-3',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user.full_address',
            'name' => 'Full Address',
            'placeholder' => '150-0001 東京都渋谷区道玄坂 1-2-3',
            'type' => 'custom_function',
            'group' => 'User',
        ],
        [
            'key' => 'user.full_address_without_postcode',
            'name' => 'Full Address (Without Postcode)',
            'placeholder' => '東京都渋谷区道玄坂 1-2-3',
            'type' => 'custom_function',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.postal_code',
            'name' => 'Postal Code',
            'placeholder' => '150-0001',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.nationality',
            'name' => 'Nationality',
            'placeholder' => 'Vietnam',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.passport_number',
            'name' => 'Passport Number',
            'placeholder' => 'AB1234567',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.passport_expiry_date',
            'name' => 'Passport Expiry Date',
            'placeholder' => '2030-12-31',
            'type' => 'model_field',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.student_card_expiry_date',
            'name' => 'Student Card Expiry Date',
            'placeholder' => '2027-03-31',
            'type' => 'model_field',
            'group' => 'User',
        ],

        // Custom function sources
        [
            'key' => 'user.latest_residence_number',
            'name' => 'Latest Residence Number',
            'placeholder' => '123456789012',
            'type' => 'custom_function',
            'group' => 'User',
        ],
        [
            'key' => 'user.postal_code_first_3',
            'name' => 'Postal Code (First 3)',
            'placeholder' => '150',
            'type' => 'custom_function',
            'group' => 'User',
        ],
        [
            'key' => 'user.postal_code_last_4',
            'name' => 'Postal Code (Last 4)',
            'placeholder' => '0001',
            'type' => 'custom_function',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.birth_year',
            'name' => 'Birth Year',
            'placeholder' => '1990',
            'type' => 'custom_function',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.birth_month',
            'name' => 'Birth Month',
            'placeholder' => '01',
            'type' => 'custom_function',
            'group' => 'User',
        ],
        [
            'key' => 'user_info.birth_date',
            'name' => 'Birth Date',
            'placeholder' => '15',
            'type' => 'custom_function',
            'group' => 'User',
        ],

        // Fixed value placeholder (user must provide their own value)
        [
            'key' => 'fixed_value',
            'name' => 'Fixed value',
            'placeholder' => 'Enter your value',
            'type' => 'fixed',
            'group' => 'Fixed',
            'value' => '',
        ],

        // Japanese era (based on user birthday)
        [
            'key' => 'is_era_meiji',
            'name' => 'Era: Meiji (明治)',
            'placeholder' => 'false',
            'type' => 'custom_function',
            'group' => 'Japanese Era',
            'is_reversible' => true,
        ],
        [
            'key' => 'is_era_taisho',
            'name' => 'Era: Taisho (大正)',
            'placeholder' => 'false',
            'type' => 'custom_function',
            'group' => 'Japanese Era',
            'is_reversible' => true,
        ],
        [
            'key' => 'is_era_showa',
            'name' => 'Era: Showa (昭和)',
            'placeholder' => 'false',
            'type' => 'custom_function',
            'group' => 'Japanese Era',
            'is_reversible' => true,
        ],
        [
            'key' => 'is_era_heisei',
            'name' => 'Era: Heisei (平成)',
            'placeholder' => 'false',
            'type' => 'custom_function',
            'group' => 'Japanese Era',
            'is_reversible' => true,
        ],
        [
            'key' => 'is_era_reiwa',
            'name' => 'Era: Reiwa (令和)',
            'placeholder' => 'true',
            'type' => 'custom_function',
            'group' => 'Japanese Era',
            'is_reversible' => true,
        ],

        // Custom function sources
        [
            'key' => 'is_household_head',
            'name' => 'Is Household Head',
            'placeholder' => 'true',
            'type' => 'custom_function',
            'group' => 'Custom',
            'is_reversible' => true,
        ],
        [
            'key' => 'current_date_ymd',
            'name' => 'Current Date (Y/M/D)',
            'placeholder' => '2026/03/21',
            'type' => 'custom_function',
            'group' => 'Custom',
        ],
    ],
];
