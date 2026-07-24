<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI translation (Bahasa Malaysia ↔ English)
    |--------------------------------------------------------------------------
    |
    | The AI itself runs on an external SeaLLM/Ollama server (see HttpTranslator
    | for the wire contract). method: "manual" gives agents on-demand translate
    | actions; "auto" translates every visitor/agent message in the background;
    | "none" disables the feature regardless of TRANSLATION.
    |
    | The service does its own translation only — all caching lives here in
    | Laravel: a fast tier on the default cache store (Redis once configured)
    | over a durable, queryable translation_cache table, both keyed by the
    | text+source+target hash. The AI is only called on a cache miss.
    |
    */

    'enabled' => (bool) env('TRANSLATION', false),

    'method' => env('TRANSLATION_METHOD', 'none'),

    // Base URL of the translation service, e.g. http://172.17.0.1:5555.
    // POST {url}/translate is the only endpoint used on the hot path.
    'url' => rtrim((string) env('TRANSLATION_API_URL', ''), '/'),

    // The contract recommends a 60s client timeout (the model can be slow).
    'timeout' => (int) env('TRANSLATION_TIMEOUT', 60),

    // How long a cached translation stays warm in the fast tier (seconds).
    'cache_ttl' => (int) env('TRANSLATION_CACHE_TTL', 86400),

];
