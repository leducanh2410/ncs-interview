<?php

return [
    'default_external_complete_pattern' => env('EXAM_DEFAULT_EXTERNAL_COMPLETE_PATTERN', 'formResponse'),
    'default_external_time_limit' => (int) env('EXAM_DEFAULT_EXTERNAL_TIME_LIMIT', 120),
    'eligibility_shift_lookback_days' => (int) env('EXAM_ELIGIBILITY_SHIFT_LOOKBACK_DAYS', 15),
];
