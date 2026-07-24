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

];
