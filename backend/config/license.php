<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central License Manager
    |--------------------------------------------------------------------------
    |
    | License-gates the user-management module. When url or key are empty the
    | license system is disabled-open: middleware passes, seats are
    | unlimited, and the admin screen reports "not configured".
    |
    */

    'url' => rtrim((string) env('LICENSE_MANAGER_URL', ''), '/'),

    'platform_slug' => env('LICENSE_PLATFORM_SLUG', ''),

    'platform_id' => env('LICENSE_PLATFORM_ID'),

    'platform_access_token' => env('LICENSE_PLATFORM_ACCESS_TOKEN', ''),

    'key' => env('LICENSE_KEY', ''),

    // Hours the last-known-good state survives when the license manager is
    // unreachable; past this, the license is treated as invalid.
    'grace_hours' => (int) env('LICENSE_GRACE_HOURS', 72),

    'timeout' => (int) env('LICENSE_TIMEOUT', 30),

];
