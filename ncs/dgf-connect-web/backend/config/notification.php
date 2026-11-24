<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Notification Retention Days
    |--------------------------------------------------------------------------
    |
    | Number of days to keep notifications before deleting them.
    | Notifications will be deleted if they are older than this many days
    | AND have both user_id and processed_at set.
    |
    */

    'retention_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 7),
];
