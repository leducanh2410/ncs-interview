<?php

return [
    'start_date' => env('SALARY_START_DATE', '2025-08-01'),
    'salary_per_hour' => (int) env('SALARY_PER_HOUR', 1000),
    'transfer_fee' => (int) env('TRANSFER_FEE', 330),
    'request' => [
        'max_transfer_amount' => (int) env('SALARY_REQUEST_MAX_TRANSFER_AMOUNT', 100000),
    ],
];
