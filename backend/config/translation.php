<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI translation (Bahasa Malaysia ↔ English)
    |--------------------------------------------------------------------------
    |
    | The AI itself runs on an external server (see HttpTranslator for the
    | assumed request contract). method: "manual" gives agents on-demand
    | translate actions; "auto" translates every visitor/agent message in the
    | background; "none" disables the feature regardless of TRANSLATION.
    |
    */

    'enabled' => (bool) env('TRANSLATION', false),

    'method' => env('TRANSLATION_METHOD', 'none'),

    'endpoint' => env('TRANSLATION_ENDPOINT', ''),

    'token' => env('TRANSLATION_TOKEN', ''),

    'timeout' => (int) env('TRANSLATION_TIMEOUT', 15),

];
