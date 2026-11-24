<?php

return [
    'ocr_max_runs' => env('BANK_ACCOUNT_OCR_MAX_RUNS', 3),
    'max_images' => env('BANK_ACCOUNT_MAX_IMAGES', 2),
    'max_image_size_kb' => env('BANK_ACCOUNT_MAX_IMAGE_SIZE_KB', 8192),
    'name_check_model' => env('BANK_ACCOUNT_NAME_CHECK_MODEL'),
    'name_check_timeout' => env('BANK_ACCOUNT_NAME_CHECK_TIMEOUT', 20),
];
