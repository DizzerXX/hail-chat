# TDEChat

Standalone-first livechat and chatbot platform for Telediant, with optional Mitel MiCC
integration via the Open Media REST API (v2.0, `/miccsdk` base path).

TDEChat works fully standalone: visitors chat through an embeddable widget, an FAQ chatbot
answers in Bahasa Malaysia (English fallback), and unanswered conversations are routed to
human agents. When deployed alongside Mitel MiCC, the same conversations can be pushed into
MiCC as Open Media work items so agents receive them through ACD routing — the core engine
does not change, only the work-item provider behind it.

## Stack

| Layer      | Technology |
|------------|------------|
| Backend    | Laravel 11 (PHP 8.3+), PostgreSQL 16, Redis 7 |
| Websockets | Laravel Reverb (Pusher protocol) |
| Auth       | Laravel Sanctum (bearer tokens) + custom lightweight RBAC |
| Queue      | Laravel queue workers (database driver by default; Redis available) |
| Frontend   | Angular 18+ standalone components (Phase 4) |

Repository layout: [backend/](backend/) (Laravel API + engine), `frontend/` (Angular, arrives
in Phase 4), [docker-compose.yml](docker-compose.yml) (Postgres + Redis for local development).

## Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Domain schema, models, factories, Sanctum auth, custom RBAC | ✅ Done |
| 2 | Standalone chat engine: work-item providers, Reverb events, agent inbox API, FAQ chatbot, webhook dispatcher | ✅ Done |
| 3 | Mitel MiCC Open Media adapter (create/complete/reroute work items, state polling, queue directory) | ✅ Done |
| 4 | Angular agent + admin dashboards, FAQ CMS, analytics | ✅ Done |

## Installation

### Prerequisites

- PHP 8.3+ with `pdo_pgsql` (`php -m | grep pdo_pgsql`)
- Composer 2
- Docker (runs PostgreSQL and Redis; nothing else is containerised)
- Node 20+ (only needed from Phase 4 onward)

### Setup

```bash
git clone <repo-url> TDEChat && cd TDEChat

# 1. Infrastructure — Postgres on host port 5433, Redis on 6380
docker compose up -d --wait

# 2. Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate

# 3. Database — migrations + roles/permissions + admin user
php artisan migrate --seed

# 4. (optional) Demo data: 'website' inbox, General team, agent@tdechat.test, BM/EN FAQs
php artisan db:seed --class=DevSeeder

# 5. Run — three processes
php artisan serve            # API on http://localhost:8000
php artisan queue:work       # broadcasts + webhook deliveries
php artisan reverb:start     # websockets on ws://localhost:8080
php artisan schedule:work    # only when MITEL_ENABLED=true (work-item state polling)
```

> **Non-default ports.** The compose file binds Postgres to **5433** and Redis to **6380**
> (instead of 5432/6379) so TDEChat never collides with other local stacks. `.env.example`
> already matches. If you change the compose ports, mirror the change in `backend/.env`
> (`DB_PORT`, `REDIS_PORT`) and `backend/phpunit.xml`.

The seeder creates an admin account **admin@tdechat.test / password** — change it on any
non-local environment.

### Tests & code style

```bash
cd backend
php artisan test        # runs against the tdechat_test database (auto-created by docker/postgres/init.sql)
./vendor/bin/pint       # PSR-12 formatting (pint.json)
```

The test suite uses `RefreshDatabase` against a separate `tdechat_test` database, so it never
wipes your development data.

## Domain model

| Table | Purpose |
|-------|---------|
| `inboxes` | Entry points/channels. `channel_type`: `standalone` or `mitel_openmedia`; per-channel settings in `channel_config` (jsonb) |
| `conversations` | One chat session. Public `uuid` for widget URLs (and the Mitel TargetUri in Phase 3); visitor name/email/phone embedded; `external_work_item_id`/`external_state` reserved for Mitel |
| `messages` | Chat messages. `sender_type`: `visitor`, `agent`, `bot`, `system` |
| `agents`, `teams`, `agent_team` | Agent operational profile (availability, capacity) on top of `users`; team membership |
| `routing_rules` | Per-inbox prioritised rules (`conditions` jsonb) targeting exactly one team **or** agent (DB CHECK enforced) |
| `faqs` | Chatbot knowledge base. One row per language (`ms`/`en`), translations linked by `translation_group` |
| `faq_queries` | Log of every chatbot lookup (hit or miss) — feeds FAQ hit-rate analytics and `faq.missed` webhooks |
| `webhooks`, `webhook_deliveries` | Outbound event subscriptions and a per-dispatch delivery log with retry state |
| `roles`, `permissions`, `permission_role` | Custom RBAC (no external package) |

Conversation lifecycle:

```
pending ──► bot ──► awaiting_acceptance ──► active ──► closed
   │         │              │
   │         └── abandoned ◄┘        "escalated" is an event (bot → awaiting_acceptance),
   └────────────────────────►        not a state
```

Enum-like columns are stored as strings and cast to PHP backed enums in
[backend/app/Enums/](backend/app/Enums/).

## Authentication & RBAC

API authentication uses Sanctum bearer tokens. Authorisation is a lightweight custom RBAC:
each user has one role, roles hold permissions, and routes are guarded by the `permission:`
middleware alias:

```php
Route::post('/faqs', ...)->middleware(['auth:sanctum', 'permission:faqs.manage']);
```

Seeded roles: **admin** (all permissions), **supervisor** (team management, all conversations,
analytics), **agent** (own conversations, accept/close/send, FAQ browsing). The 14 permissions
use dot notation (`conversations.view_own`, `faqs.manage`, …) and are defined in
[RolePermissionSeeder](backend/database/seeders/RolePermissionSeeder.php).

## Integration

### Your website (chat widget)

The public API needs no authentication — the conversation UUID returned on start is the
capability that authorizes all later calls for that conversation:

```bash
# Start a conversation on the 'website' inbox (bot answers if it can)
curl -X POST http://localhost:8000/api/v1/public/inboxes/website/conversations \
  -H 'Content-Type: application/json' \
  -d '{"message": "Saya lupa kata laluan saya", "visitor_name": "Ahmad", "language": "ms"}'

# Keep chatting / poll for replies
curl -X POST http://localhost:8000/api/v1/public/conversations/{uuid}/messages \
  -H 'Content-Type: application/json' -d '{"body": "Terima kasih!"}'
curl http://localhost:8000/api/v1/public/conversations/{uuid}
```

For realtime instead of polling, subscribe over the Pusher protocol (Reverb):

| Channel | Type | Events |
|---------|------|--------|
| `conversation.{uuid}` | public | `conversation.updated`, `message.sent`, `agent.typing` |
| `private-inbox.{inboxId}` | private (agents) | `conversation.created`, `conversation.updated`, `message.sent` |
| `private-agents.{agentId}` | private (agents) | `conversation.updated` on assignment |

Private channels authorize via `POST /api/broadcasting/auth` with the agent's bearer token.

### External systems (webhooks)

Register webhook endpoints (per inbox or global) subscribed to any of:

| Event | Fired when |
|-------|-----------|
| `conversation.started` | A visitor starts a conversation |
| `conversation.escalated` | The bot hands a conversation to human agents |
| `conversation.closed` | A conversation is closed |
| `faq.missed` | The chatbot found no answer for a visitor query |

Deliveries are queued `POST` requests carrying `{event, timestamp, data}` JSON with headers
`X-TDEChat-Event`, `X-TDEChat-Delivery` (delivery id) and `X-TDEChat-Signature`. Non-2xx
responses retry 3 times (30s / 2m / 10m backoff); every attempt is logged to
`webhook_deliveries` (`status`, `attempts`, `last_status_code`, `last_error`) for debugging.

Verify the signature by recomputing the HMAC over the **raw** request body:

```php
$expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $webhookSecret);
abort_unless(hash_equals($expected, $request->header('X-TDEChat-Signature')), 401);
```

Payload schemas for all four events are in the OpenAPI spec's `webhooks:` section.

### Mitel MiCC (Open Media)

The engine talks to an abstraction, not to Mitel directly:

- `WorkItemProviderInterface` — `createWorkItem` / `completeWorkItem` / `rerouteWorkItem`
- `PollableProviderInterface` — work-item state polling (Offered, Acd, Abandoned, …)
- `QueueDirectoryProviderInterface` — MiCC queue listing (cached, for admin configuration)

Standalone inboxes use `StandaloneProvider` (pure database state). Inboxes with
`channel_type = mitel_openmedia` use `MitelOpenMediaProvider`: escalated conversations are
POSTed to `/api/v1/openmedia` with `TargetUriEmbedded=true` so the MiCC agent desktop embeds
TDEChat's agent chat view (keyed by the conversation UUID), and `VariableData` carries the
visitor's Name/Email/Phone for MiCC contact matching. The `Queue` field takes the queue
**name** (live-verified). A scheduler job then syncs state with **per-id polling**
(`GET /api/v1/openmedia/{id}` answers while the item is InQueue/ACD and 404s once it is
Failed/Handled — live MCX servers filter the plain list by device visibility, so it can't
be trusted): live states map `Acd` → active (`WorkItemAccepted`),
`Abandoned`/`ExitedQueue`/`InboxFailed` → abandoned (`WorkItemAbandoned`); on a 404 the
historical OData `$search` recovers the final state (`Ended`/`Acd` → closed +
`conversation.closed` webhook, otherwise abandoned). Freshly created items get a routing
grace (`MITEL_ROUTING_GRACE`, default 60s) because MiCC takes ~10s to route them, during
which they exist nowhere queryable. Every transition broadcasts `conversation.updated`.
Closing while queued/offered credits MiCC with an abandon; closing after ACD marks the
item handled. Live responses use camelCase keys and string state names — the client accepts
both those and the swagger's PascalCase/integers.

Configuration (`backend/config/mitel.php`, all via env — never hardcoded):

| Env var | Meaning | Default |
|---------|---------|---------|
| `MITEL_ENABLED` | Master switch for the polling scheduler | `false` |
| `MITEL_BASE_URL` | MiCC SDK base URL **including** `/miccsdk` | — |
| `MITEL_CLIENT_ID` | OAuth2 client-credentials id (must be registered as a trusted server in the Authorization Server's `clients.config`) | — |
| `MITEL_CLIENT_SECRET` | OAuth2 client-credentials secret | — |
| `MITEL_AUTH_URL` | Token endpoint override | derived: `{scheme}://{host}/AuthorizationServer/Token` |
| `MITEL_TENANT` | Optional tenant sent on work-item creation | — |
| `MITEL_VERIFY_SSL` | Disable for on-prem self-signed certs | `true` |
| `MITEL_POLL_INTERVAL` | Poll cadence in seconds (1/2/5/10/15/20/30/60) | `15` |
| `MITEL_AGENT_CHAT_URL` | TargetUri template, `{uuid}` placeholder | `APP_URL/agent/chat/{uuid}` |

Per-inbox queue mapping lives in the inbox's `channel_config`:

```json
{"mitel": {"queue": "OpenMediaQueue1"}}
```

(Reroute carries no queue target — the `OpenMediaUpdateRequestModel` only has `Action`;
MiCC re-runs its own routing.)

Authentication is OAuth2 **client credentials** against the MiCC Authorization Server
(`POST /AuthorizationServer/Token`, form-encoded). Tokens are cached until shortly before
`expires_in` and transparently re-requested; a 401 from the SDK API invalidates the cached
token and the call retries once with a fresh one.

Polling requires the scheduler: run `php artisan schedule:work` alongside the queue worker.
Before first use against a real MiCC server, run **`php artisan mitel:check`** — it validates
the config, acquires a token, and performs a live queue listing; `php artisan mitel:poll`
runs one poll pass manually.

## Dashboards

The Angular app in [frontend/](frontend/) serves both dashboards:

```bash
cd frontend
npm install
ng serve                     # http://localhost:4200
```

API and Reverb endpoints are configured in
[frontend/src/environments/environment.ts](frontend/src/environments/environment.ts)
(defaults: API http://localhost:8000, websockets ws://localhost:8080 — the Reverb key must
match your backend `.env`). Quality gates: `ng lint`, `ng test` (vitest — no browser
needed), `ng build --configuration production`.

- **Agent** (`/agent`) — availability switcher, Mine/Queue/All conversation lists with
  realtime updates, chat view with typing indicators, FAQ quick replies, accept/close/
  reroute. `/agent/chat/{uuid}` is also the view MiCC embeds via the work-item TargetUri.
- **Admin** (`/admin`) — inbox management (incl. MiCC queue mapping), teams, users & agent
  profiles, the FAQ CMS with a BM/EN language toggle, webhook configuration with a
  delivery log, per-inbox routing rules, and analytics (conversations/day, average handle
  time, FAQ hit rate, top missed queries).

The management REST API behind the admin screens lives under `/api/v1/admin/*`, gated by
the per-area RBAC permissions (`inboxes.manage`, `faqs.manage`, …) and fully specified in
the OpenAPI document below.

## API documentation

The REST API is specified in OpenAPI 3.1 at
[backend/public/docs/openapi.yaml](backend/public/docs/openapi.yaml) — hand-authored as the
contract, covering the public widget API, auth, the agent inbox API, websocket channels and
all four outbound webhook payloads. Swagger UI is served at **http://localhost:8000/docs**
while `php artisan serve` is running (no composer package needed; the UI is a static page).
