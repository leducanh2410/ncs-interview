<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_SES_REGION', 'ap-northeast-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cross_staff' => [
        'base_url' => env('CROSS_STAFF_BASE_URL'),
        'tenant_cd' => env('CROSS_STAFF_TENANT_CD'),
        'external_cd' => env('CROSS_STAFF_EXTERNAL_CD'),
        'api_key' => env('CROSS_STAFF_API_KEY'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-flash'),
        'translation_model' => env('OPENROUTER_TRANSLATION_MODEL'),
        'fallback_model' => env('OPENROUTER_FALLBACK_MODEL'),
        'rotation_model' => env('OPENROUTER_ROTATION_MODEL', 'google/gemini-2.5-flash-lite'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 55),
    ],

    'face_recognition' => [
        'provider' => env('FACE_RECOGNITION_PROVIDER', 'rekognition'),
    ],

    'runpod_face_recognition' => [
        'endpoint' => env('RUNPOD_FACE_RECOGNITION_ENDPOINT'),
        'api_key' => env('RUNPOD_FACE_RECOGNITION_API_KEY'),
        'timeout' => env('RUNPOD_FACE_RECOGNITION_TIMEOUT', 30),
        'endpoint_id' => env('RUNPOD_FACE_RECOGNITION_ENDPOINT_ID'),
    ],

    'runpod_anti_spoofing' => [
        'endpoint' => env('RUNPOD_ANTI_SPOOFING_ENDPOINT'),
        'api_key' => env('RUNPOD_ANTI_SPOOFING_API_KEY'),
        'timeout' => env('RUNPOD_ANTI_SPOOFING_TIMEOUT', 300),
        'endpoint_id' => env('RUNPOD_ANTI_SPOOFING_ENDPOINT_ID'),
        'max_retries' => env('RUNPOD_ANTI_SPOOFING_MAX_RETRIES', 3),
        'retry_delay' => env('RUNPOD_ANTI_SPOOFING_RETRY_DELAY', 5),
        'poll_interval' => env('RUNPOD_ANTI_SPOOFING_POLL_INTERVAL', 5),
        'max_poll_time' => env('RUNPOD_ANTI_SPOOFING_MAX_POLL_TIME', 600),
    ],

    'aws' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'rekognition' => [
            'collection' => env('AWS_REKOGNITION_COLLECTION', 'dgf-connect'),
            'threshold' => env('AWS_REKOGNITION_THRESHOLD', 80.0),
            'timeout' => env('AWS_REKOGNITION_TIMEOUT', 30),
        ],
    ],
];
