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

    /*
    |--------------------------------------------------------------------------
    | OAuth2 client credentials
    |--------------------------------------------------------------------------
    |
    | Tokens come from the MiCC Authorization Server via the client
    | credentials grant. The client_id must be registered as a trusted
    | server in the Authorization Server's clients.config. When auth_url
    | is empty it is derived from base_url's host as
    | {scheme}://{host}/AuthorizationServer/Token (site root).
    |
    */

    'auth_url' => env('MITEL_AUTH_URL'),

    'client_id' => env('MITEL_CLIENT_ID', ''),

    'client_secret' => env('MITEL_CLIENT_SECRET', ''),

    'tenant' => env('MITEL_TENANT'),

    'verify_ssl' => (bool) env('MITEL_VERIFY_SSL', true),

    'timeout' => (int) env('MITEL_TIMEOUT', 10),

    // Seconds between work-item state polls. Allowed: 1, 2, 5, 10, 15, 20,
    // 30, 60 (Laravel scheduler cadences); anything else falls back to 60.
    'polling_interval' => (int) env('MITEL_POLL_INTERVAL', 15),

    'queues_cache_ttl' => (int) env('MITEL_QUEUES_CACHE_TTL', 60),

    // Seconds after work-item creation during which "not found anywhere"
    // is treated as still-routing rather than gone (live MCX takes ~10s
    // to route a new item into its queue).
    'routing_grace_seconds' => (int) env('MITEL_ROUTING_GRACE', 60),

    // TargetUri template for the embedded agent chat view ({uuid} is replaced
    // with the conversation uuid). Defaults to APP_URL/embed/chat/{uuid} — the
    // bare chat window, which also claims the conversation for whoever opens it.
    'agent_chat_url' => env('MITEL_AGENT_CHAT_URL'),

    /*
    |--------------------------------------------------------------------------
    | Withdraw a still-queued work item when the visitor leaves
    |--------------------------------------------------------------------------
    |
    | When a visitor ends the chat (or closes the tab) while still WAITING IN
    | QUEUE — before any agent has accepted — TDEChat asks MiCC to withdraw the
    | work item so it doesn't strand a "ghost" item for an agent to pick up. It
    | NEVER fires once an agent has accepted (that case stays theirs to resolve
    | in Ignite), so this only ever touches items no agent is handling.
    |
    | ON by default, using Complete (ActionType 0) — the confirmed "this work
    | item is finished" action. Complete is safe here precisely because the
    | guard limits it to unaccepted, still-queued items (there's no Ignite
    | session to disturb). If a MiCC deployment needs a different ActionType,
    | override MITEL_CANCEL_ACTION; disable entirely with MITEL_CANCEL_QUEUED=false.
    |
    */
    'cancel_queued_enabled' => (bool) env('MITEL_CANCEL_QUEUED', true),

    'cancel_action' => (int) env('MITEL_CANCEL_ACTION', 0),

];
