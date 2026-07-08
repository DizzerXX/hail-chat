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
| 2 | Standalone chat engine: work-item providers, Reverb events, agent inbox API, FAQ chatbot, webhook dispatcher | 🚧 In progress |
| 3 | Mitel MiCC Open Media adapter (create/complete/reroute work items, state polling, queue directory) | ⏳ Planned |
| 4 | Angular agent + admin dashboards, FAQ CMS, analytics | ⏳ Planned |

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

# 4. Run
php artisan serve            # API on http://localhost:8000
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

Phase 2 ships a public REST API (no authentication, conversation UUID as capability) plus
Reverb websocket channels, which any frontend — including the Phase 4 widget — drives:
start a conversation against an inbox slug, post visitor messages, receive bot/agent replies
in real time. See **API documentation** below once Phase 2 lands.

### External systems (webhooks)

Register webhook endpoints (per inbox or global) subscribed to any of:

| Event | Fired when |
|-------|-----------|
| `conversation.started` | A visitor starts a conversation |
| `conversation.escalated` | The bot hands a conversation to human agents |
| `conversation.closed` | A conversation is closed |
| `faq.missed` | The chatbot found no answer for a visitor query |

Deliveries are queued, signed with `X-TDEChat-Signature` (HMAC-SHA256 of the raw body using
the webhook's secret), retried with backoff, and logged to `webhook_deliveries` for
debugging. Payload shape and signature verification examples ship with the Phase 2 API docs.

### Mitel MiCC (Open Media) — Phase 3

The engine talks to an abstraction, not to Mitel directly:

- `WorkItemProviderInterface` — `createWorkItem` / `completeWorkItem` / `rerouteWorkItem`
- `PollableProviderInterface` — work-item state polling (Offered, Acd, Abandoned, …)
- `QueueDirectoryProviderInterface` — queue listing for admin configuration

Standalone deployments use `StandaloneProvider` (pure database state). Mitel deployments will
use `MitelOpenMediaProvider`, which POSTs conversations to `/api/v1/openmedia` with
`TargetUriEmbedded=true` so the MiCC agent desktop embeds TDEChat's agent chat view, keyed by
the conversation UUID. Credentials and polling cadence will live in `backend/config/mitel.php`
(environment variables, never hardcoded).

## API documentation

The REST API is specified in OpenAPI (`backend/public/docs/openapi.yaml`) with Swagger UI
served at `http://localhost:8000/docs` — both arrive with Phase 2.
