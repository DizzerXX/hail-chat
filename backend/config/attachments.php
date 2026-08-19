<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Message attachments
    |--------------------------------------------------------------------------
    |
    | Files a visitor or agent can attach to a message. Stored on this disk
    | (private) and served inline via /public/attachments/{uuid} — the uuid is
    | the download capability, matching the conversation-uuid trust model.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', 'local'),

    // Max size per file, in kilobytes (validation `max:` unit).
    'max_kb' => (int) env('ATTACHMENTS_MAX_KB', 10240), // 10 MB

    // Allowed extensions — kept to images + PDF for a small attack surface.
    'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],

    /*
    |--------------------------------------------------------------------------
    | Antivirus scanning (ClamAV / clamd)
    |--------------------------------------------------------------------------
    |
    | When enabled, every uploaded file is streamed to clamd (INSTREAM) and an
    | infected file is rejected before it is stored. Off by default — clamd must
    | be installed on the box first. `on_error` decides what happens when clamd
    | is unreachable: `block` (fail-closed, safest) or `allow` (accept + log).
    |
    */
    'av' => [
        'enabled' => (bool) env('ATTACHMENTS_AV_ENABLED', false),
        // clamd address: tcp://host:port or unix:///var/run/clamav/clamd.ctl
        'socket' => env('ATTACHMENTS_AV_SOCKET', 'tcp://127.0.0.1:3310'),
        'timeout' => (int) env('ATTACHMENTS_AV_TIMEOUT', 30),
        'on_error' => env('ATTACHMENTS_AV_ON_ERROR', 'block'), // block | allow
    ],

];
