<?php

return [
    'turnstile' => [
        'site_key' => env('CAPTCHA_TURNSTILE_SITE_KEY'),
        'secret_key' => env('CAPTCHA_TURNSTILE_SECRET_KEY'),
    ],
];
