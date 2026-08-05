<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System settings defaults
    |--------------------------------------------------------------------------
    |
    | Seed values for the single SystemSettings row (admins with system.manage
    | edit them at runtime on the System Settings screen). app_name defaults to
    | APP_NAME so a fresh install already shows the configured name.
    |
    */

    'defaults' => [
        'app_name' => env('APP_NAME', 'TDEChat'),
        'favicon' => null,
        'logo' => null,
        'retention_enabled' => false,
        'retention_days' => 180,
    ],

    // Hard floor for data retention — the sweep refuses anything below this, so
    // an accidental tiny value can't wipe recent conversations.
    'retention_min_days' => (int) env('RETENTION_MIN_DAYS', 7),

];
