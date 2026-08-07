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

    // Days a soft-deleted conversation stays in the recycle bin (restorable)
    // before it is permanently purged. Both manual deletes and retention
    // archiving land here first.
    'recycle_bin_days' => (int) env('RECYCLE_BIN_DAYS', 30),

];
