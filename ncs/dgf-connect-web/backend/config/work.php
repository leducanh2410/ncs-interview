<?php

return [
    'staff_no_start' => (int) env('STAFF_NO_START', 37000),
    'time' => [
        'night_shift' => [
            'start_time' => '22:00',
            'end_time' => '05:00',
        ],

        'over_time_after' => 8,
    ],

    'check_in' => [
        'check_in_before_hour' => (int) env('CHECK_IN_BEFORE_HOUR', 1),
        'check_out_after_hour' => (int) env('CHECK_OUT_AFTER_HOUR', 3),
        'max_distance' => (int) env('CHECK_IN_MAX_DISTANCE', 200),
        'anti_spoofing_enabled' => (bool) env('CHECK_IN_ANTI_SPOOFING_ENABLED', true),
        'verification_retention_days' => (int) env('CHECK_IN_VERIFICATION_RETENTION_DAYS', 30),
        'late_early_threshold_minute' => (int) env('CHECK_IN_LATE_EARLY_THRESHOLD_MINUTE', 0),
        'attendance_reason_window_day' => (int) env('CHECK_IN_ATTENDANCE_REASON_WINDOW_DAY', 7),
    ],

    'absent_notice' => [
        'before_hour' => (int) env('ABSENT_NOTICE_BEFORE_HOUR', 12),
    ],

    'system_notification_emails' => array_filter(
        array_map('trim', explode(',', env('SYSTEM_NOTIFICATION_EMAILS', '')))
    ),

    'max_shift_per_day' => (int) env('MAX_SHIFT_PER_DAY', 2),
    'max_hour_per_week' => (int) env('MAX_WORK_HOUR_PER_WEEK', 28),

    'reminder' => [
        'check_in' => [
            'before_minute' => (int) env('CHECK_IN_REMINDER_BEFORE_MINUTE', 15),
        ],
        'check_out' => [
            'before_minute' => (int) env('CHECK_OUT_REMINDER_BEFORE_MINUTE', 15),
        ],
        'shift' => [
            'before_minute' => (int) env('SHIFT_REMINDER_BEFORE_MINUTE', 360),
        ],
        'confirmation' => [
            'before_day' => (int) env('CONFIRM_SHIFT_REMINDER_BEFORE_DAY', 2),
            'final_before_minute' => (int) env('CONFIRM_SHIFT_FINAL_REMINDER_BEFORE_MINUTE', 180),
        ],
        'unconfirmed_alert' => [
            'before_minute' => (int) env('SHIFT_UNCONFIRMED_ALERT_BEFORE_MINUTE', 120),
        ],
        'missing_check_in_alert' => [
            'after_minute' => (int) env('SHIFT_MISSING_CHECK_IN_ALERT_AFTER_MINUTE', 10),
        ],
    ],

    'residence_card_expiry_threshold' => (int) env('RESIDENCE_CARD_EXPIRY_THRESHOLD', 3),

    'student_card_expiry_threshold' => (int) env('STUDENT_CARD_EXPIRY_THRESHOLD', 3),

    'passport_expiry_threshold' => (int) env('PASSPORT_EXPIRY_THRESHOLD', 3),

    'document_expiry_alert' => (int) env('DOCUMENT_EXPIRY_ALERT', 14),
];
