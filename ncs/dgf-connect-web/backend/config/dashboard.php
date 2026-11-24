<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dashboard Cache TTL
    |--------------------------------------------------------------------------
    |
    | Lifetime (in seconds) of cached dashboard aggregate responses. The
    | dashboard serves read-only historical analytics, so a longer TTL is
    | acceptable; admins can force a refresh via the clear-cache action.
    | Defaults to 4 hours.
    |
    */

    'cache_ttl' => (int) env('DASHBOARD_CACHE_TTL', 4 * 60 * 60),
];
