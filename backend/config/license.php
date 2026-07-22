<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central License Manager
    |--------------------------------------------------------------------------
    |
    | TDEChat pulls its license pool from the platform with
    | POST /api/v1/admin/platforms/sync-licenses and spends one license per
    | agent. There is no per-installation license key: the platform id and its
    | access token identify us. When url, platform id or token are empty the
    | license system is disabled-open — middleware passes, agents are
    | unlimited, and the admin screen reports "not configured".
    |
    */

    'url' => rtrim((string) env('LICENSE_MANAGER_URL', ''), '/'),

    'platform_slug' => env('LICENSE_PLATFORM_SLUG', ''),

    'platform_id' => env('LICENSE_PLATFORM_ID'),

    'platform_access_token' => env('LICENSE_PLATFORM_ACCESS_TOKEN', ''),

    'timeout' => (int) env('LICENSE_TIMEOUT', 30),

];
