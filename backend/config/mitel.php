<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mitel MiCC Open Media integration
    |--------------------------------------------------------------------------
    |
    | Used by inboxes with channel_type "mitel_openmedia". The base URL must
    | include the MiCC SDK base path (e.g. https://micc.example.com/miccsdk).
    | Authentication is a Bearer token. Per-inbox queue mapping lives in the
    | inbox's channel_config: {"mitel": {"queue": "...", "team_queues": {...}}}.
    |
    */

    'enabled' => (bool) env('MITEL_ENABLED', false),

    'base_url' => env('MITEL_BASE_URL', ''),

    'token' => env('MITEL_TOKEN', ''),

    'tenant' => env('MITEL_TENANT'),

    'verify_ssl' => (bool) env('MITEL_VERIFY_SSL', true),

    'timeout' => (int) env('MITEL_TIMEOUT', 10),

    // Seconds between work-item state polls. Allowed: 1, 2, 5, 10, 15, 20,
    // 30, 60 (Laravel scheduler cadences); anything else falls back to 60.
    'polling_interval' => (int) env('MITEL_POLL_INTERVAL', 15),

    'queues_cache_ttl' => (int) env('MITEL_QUEUES_CACHE_TTL', 60),

    // TargetUri template for the embedded agent chat view ({uuid} is replaced
    // with the conversation uuid). Defaults to APP_URL/agent/chat/{uuid}.
    'agent_chat_url' => env('MITEL_AGENT_CHAT_URL'),

];
