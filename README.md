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
| Backend    | Laravel 12 (PHP 8.4+), PostgreSQL 16, Redis 7 |
| Websockets | Laravel Reverb (Pusher protocol) |
| Auth       | Laravel Sanctum (bearer tokens) + custom lightweight RBAC |
| Queue      | Laravel queue workers (Redis driver; cache and sessions on Redis too) |
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

- PHP 8.4+ with `pdo_pgsql` (`php -m | grep pdo_pgsql`)
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

Drop the embeddable widget ([backend/public/widget.js](backend/public/widget.js) — vanilla
JS, no dependencies) into any page:

```html
<!-- Local development: Reverb is reached directly on 8080, no TLS. -->
<script src="http://localhost:8000/widget.js"
        data-inbox="website"
        data-reverb-key="YOUR_REVERB_APP_KEY"
        data-ws-host="localhost"
        data-ws-port="8080"></script>

<!-- Production behind nginx + TLS: port 443, and data-ws-tls is required. -->
<script src="https://tdechat.example.com/widget.js"
        data-inbox="website"
        data-reverb-key="YOUR_REVERB_APP_KEY"
        data-ws-host="tdechat.example.com"
        data-ws-port="443"
        data-ws-tls="true"></script>
```

> Omitting `data-ws-tls="true"` on an HTTPS site is the easy mistake: the widget tries
> `ws://…:8080`, the connection fails, and it quietly falls back to HTTP polling. Chat still
> works, just with a delay instead of instant delivery — so check the browser console for a
> failed websocket if replies feel sluggish.

It renders a floating chat bubble with a pre-chat form (name + BM/EN language), talks to the
public API, streams bot/agent replies and typing indicators over Reverb (with HTTP polling as
a fallback), offers "Talk to a human" escalation, and resumes the conversation across page
reloads via localStorage. A demo host page ships at `/widget-demo.html`.

**Branding is admin-configurable** (no embed changes): **Admin → Chat settings → Widget
branding** sets the header title/subtitle, launcher and header icons (an emoji *or* an image
URL), the bot name, and the pre-chat labels/placeholders. The widget reads them from
`/public/app-config` (alongside `bubble_color`) and falls back to built-in defaults if the
call fails. Settings are global (one set across all embeds).

**Pre-chat fields are configurable too** (**Admin → Chat settings → Pre-chat form**). The three
first-class fields — **name / email / phone** — each toggle show/required with an editable
label; they stay as real columns, so email/phone feed contact history. Beyond those, admins add
**custom fields** (`text / email / phone / number / select / cascade`, each optional or required).
The widget renders them dynamically and enforces required client-side; the server re-validates
against the config (required, select options) and stores answers **self-describing** in
`conversation.meta.fields` (`[{key,label,value}]`), which the agent details panel lists. Unknown
keys are dropped; the config is global.

A **`cascade`** field is a dependent-dropdown tree (defined as pasted JSON,
`[{key,label,children?}]`, any depth). The widget reveals each next level as the visitor
chooses; a started selection must be finished to a leaf. The answer is stored with its full
`path` (`[{key,label}]` per level) so each level is addressable — e.g. for a CMS/CRM URL below.

**FAQ deflection** (**Admin → Chat settings → FAQ deflection**). The widget *opens* on a
**"Quick answers"** screen — an accordion of the inbox's FAQs (from `/public/inboxes/{slug}/faqs`)
— so visitors can self-serve before any personal data is collected or a conversation is created.
**Bahasa Malaysia / English tabs** switch the questions and answers between languages — along with
the screen's title, prompt, and button (all bilingual and admin-editable). Lists are lazy-loaded
and cached, and picking a tab also preselects that language in the pre-chat form. A **"Chat with a
live agent"** button below the list leads to the pre-chat form, and submitting that starts the
chat. The screen is skipped automatically (opening straight to the pre-chat form) when deflection
is off or the inbox has no FAQs for the default language. The toggle, heading, prompt, and button
label are admin-editable (surfaced via `/public/app-config`); FAQs themselves are managed under
**Admin → FAQs**. An optional **"skip the bot"** toggle (off by default) makes the button escalate
straight to the human queue instead of entering the FAQ bot — the FAQ screen already served the
self-service role.

Prefer driving the API directly? It needs no authentication — the conversation UUID returned
on start is the capability that authorizes all later calls for that conversation:

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

- `WorkItemProviderInterface` — `createWorkItem` / `completeWorkItem` / `cancelWorkItem` / `rerouteWorkItem`
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
**TDEChat never completes a MiCC work item.** On Mitel inboxes the agent resolves the case
in Ignite, and the poller reflects that resolution back — so a visitor ending the chat closes
TDEChat's own record (and fires `conversation.closed`) but leaves the agent's Ignite queue
untouched for them to resolve manually.

**Withdrawing a still-queued item (opt-in).** The one exception is a visitor who leaves
**while still waiting in the queue, before any agent accepts** — that would otherwise strand a
"ghost" item MiCC could route to an agent who then finds the visitor gone. `cancelWorkItem`
can retract it via a PUT action, guarded so it **never** fires once a chat is accepted
(`accepted_at` set). It is **off by default** (`MITEL_CANCEL_QUEUED=false`): the MiCC
cancel/withdraw `ActionType` value is not verified in this codebase (only `Complete=0` /
`Reroute=1` are), so confirm it against your MiCC SDK, set `MITEL_CANCEL_ACTION` to that value,
then enable. When off, the queued item is simply left for MiCC's own no-reply timers.

Live responses use camelCase keys and string state
names — the client accepts both those and the swagger's PascalCase/integers.

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
| `MITEL_AGENT_CHAT_URL` | TargetUri template, `{uuid}` placeholder | `APP_URL/embed/chat/{uuid}` |

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

- **Agent** (`/agent`) — a Slack-style three-pane workspace: icon rail (with availability
  switcher on the avatar menu), a searchable sidebar with Queue/Mine/All conversation
  groups updating in realtime, the chat thread with bubble-style messages (agent messages
  right-aligned in blue), and a details panel with visitor info and a CMS shortcut. FAQ
  quick replies, accept/close/reroute as before. The sidebar **search** is server-side: a
  term of 2+ chars matches the visitor's name/email/phone **or any message body**
  (transcript), across the widest scope the account may see (`GET /agent/conversations?q=`).
- **Admin** (`/admin`) — inbox management (incl. MiCC queue mapping), teams, users & agent
  profiles, the FAQ CMS with a BM/EN language toggle, webhook configuration with a
  delivery log, per-inbox routing rules, and analytics (conversations/day, average handle
  time, FAQ hit rate, top missed queries, **abandonment rate**, and a **per-agent** table of
  handled volume + average resolution time).

Dashboard timestamps render in **Malaysia time (MYT, UTC+8)** regardless of the viewer's
machine clock (a global `DATE_PIPE_DEFAULT_OPTIONS` provider).

### One chat per agent

An agent handles **one conversation at a time**. Accepting (or claiming) a second one while a
chat is still `active` is refused with a 409 — "You are already handling a conversation. Close
it before taking another." Only `active` conversations occupy an agent: being offered several
in the queue is fine, and closed ones never count. There is no per-agent limit to configure;
the rule is fixed.

Two related behaviours keep the queue moving rather than parking work on someone unavailable:
a routing rule targeting a busy agent falls through to the general queue instead of assigning,
and rerouting *to* a busy agent is refused with the same 409.

**Mitel inboxes are exempt.** MiCC does its own routing and has its own agent capacity
settings, so TDEChat never refuses a work item MiCC has already ACD'd — otherwise a chat could
strand in the Ignite embed with no way to open it.

### Idle handling & chat settings

**Admin → Chat settings** (`settings.manage` permission) configures conversation idle behavior
and the chat theme. A once-a-minute sweep (`schedule:work`; run `php artisan chat:idle-sweep`
to trigger manually) watches all `active` conversations. Who is "idle" is decided by the last
real (visitor/agent) message; the auto-sent notices are bot messages and never reset the clock.

- **Customer idle** (waiting on the visitor): auto-sends a 1st then 2nd warning (visitor-visible,
  in their language) at the configured minutes, notifying the assigned agent each time with the
  fixed **warning** sound; at the auto-close threshold the conversation is marked **abandoned**
  and fires `conversation.closed` (`reason: idle`). Defaults: 5 / 8 / 10 minutes.
- **Agent idle** (an unanswered visitor message): notifies the agent — a badge, the selected
  **notification** sound, and a desktop notification — repeating every interval until they
  reply. Default: 2 minutes.

**Mitel inboxes get agent alerts only.** MiCC owns the case lifecycle, so on a Mitel conversation
the sweep notifies the agent (sound + desktop + banner) but never messages the visitor and never
auto-closes — the agent resolves in Ignite. Standalone inboxes get the full flow above. Because
nothing is auto-sent, the customer-idle banner in the embed carries a **Send follow-up** button
that fires the suggested "still there?" text to the visitor (as an agent message) in one click.

Notifications ride the agent's private channel as `agent.notified`. On the dashboard they surface
as a rail badge + sound + desktop notification; in the **Ignite embed** (which has no rail) they
also show as a dismissible banner inside the chat panel, so agents who only ever see the embed
still get idle prompts. **Sound files** live in `frontend/public/sounds/` and are supplied by the
operator — the selectable set is listed in `backend/config/chat.php` (`notification_sounds`),
plus one fixed `warning.mp3`; a missing file simply plays nothing.

The same screen sets the **chat bubble color**, applied to both the agent dashboard (a
`--tde-agent-bubble` CSS var) and the visitor widget (fetched from the public
`GET /api/v1/public/app-config`). All idle behavior can be switched off with the **Enable idle
handling** toggle.

### Embedded chat window (MiCC Ignite)

`/embed/chat/{uuid}` renders **only** the chat window — no icon rail, no conversation list —
and is the default `TargetUri` on Open Media work items, so the Ignite flow reads:

1. The agent is notified of the new livechat in Ignite and accepts it there.
2. MiCC ACDs the work item and opens the embedded Open Media tab at this URL. If the agent
   is not signed in, TDEChat sends them to the login page and back afterwards
   (`/login?returnUrl=…`) — no separate embed login to maintain.
3. They land straight in the conversation.
4. The window **claims** the conversation on arrival
   (`POST /api/v1/agent/conversations/{uuid}/claim`), so no second "Accept" click.

That claim step is necessary rather than cosmetic: when MiCC ACDs an item the poller marks
the conversation `active` but cannot fill in `assigned_agent_id`, because MiCC never reports
which of *its* agents took it. Without an assigned agent the backend rejects replies from
anyone lacking `conversations.assign`, so the embed would otherwise be read-only for ordinary
agents. Claiming is idempotent, so reloading the tab is safe.

If the conversation is already held by someone else (or is closed), nothing is taken over.
What the viewer then sees depends on their permissions, which the existing `conversations.view_all`
rule decides: a supervisor or admin gets the full transcript read-only with "Handled by …" in
place of the composer, while an ordinary agent — who may not read another agent's conversation
— gets a plain "This conversation is being handled by another agent." notice.

Auto-claiming happens **only** on this route — opening a conversation from the normal
workspace never silently reassigns it. The embed **hides the Close button on Mitel inboxes**,
because the agent resolves the case in Ignite — TDEChat must not offer a competing "close"
that would only touch its own record. (On standalone inboxes the embed keeps Close, which
also opens the inbox's CMS URL if one is set.)

An inbox can optionally point at an external CMS/ticketing system: set **CMS URL** in the
inbox dialog (stored as `channel_config.cms_url`). It may be a **template** with placeholders,
resolved per conversation on the server (each value URL-encoded):

| Placeholder | Value |
|---|---|
| `{id}` / `{uuid}` | conversation id / uuid (e.g. `case_id=livechat-{id}`) |
| `{name}` `{email}` `{phone}` | visitor details |
| `{field:KEY}` | a custom field answer (cascade = the joined path) |
| `{field:KEY:N}` | level *N* of a cascade answer (e.g. `category={field:services:1}`) |

e.g. `https://crm.example/create?case_id=livechat-{id}&customer_name={name}&category={field:services:1}&sub_category1={field:services:2}`.
When set:

- On the **dashboard**, closing a conversation opens the URL in a new tab, and the details
  card shows an **Open CMS** link (new tab) — handy for logging the interaction.
- In the **Ignite embed** (where resolution happens in MiCC, not via a TDEChat button), once
  the conversation has ended — resolved or abandoned — the details card shows an **Open CMS**
  button that **navigates the embed tab** to the URL, so the agent lands on the CMS to log the
  case where the chat used to be.

The URL is only exposed on authenticated agent responses, never on the public widget API.

The management REST API behind the admin screens lives under `/api/v1/admin/*`, gated by
the per-area RBAC permissions (`inboxes.manage`, `faqs.manage`, …) and fully specified in
the OpenAPI document below.

## Attachments

Visitors and agents can attach files to a message (with an optional caption) — via the paperclip
button, by **dragging files onto the chat** (a "Drop to upload" hint appears), or by **pasting**
an image (e.g. a screenshot) into the composer. Drag/paste can carry several files, uploaded one
after another with a per-file **"Uploading N of M…"** indicator. Uploads go to
`POST {…}/conversations/{uuid}/attachments` (public for the widget,
`/agent/…` for the dashboard — the latter under `messages.send` + `seat.valid`), and are stored
privately on the `local` disk under `attachments/{conversation-uuid}/`.

Each attachment gets an **unguessable uuid** and is served **inline** from
`GET /api/v1/public/attachments/{uuid}` — the uuid is the download capability, matching the
conversation-uuid trust model, so the same URL renders in the widget and the dashboard via
`<img src>`. Images show inline; other files render as a download chip. Allowed types and the
size cap live in [config/attachments.php](backend/config/attachments.php) (`ATTACHMENTS_MAX_KB`,
default images + PDF, 10 MB). Attachments ride the existing `message.sent` broadcast, so they
appear in real time on both sides.

## Agent tooling: canned responses, internal notes, contacts

**Canned responses** are an admin-managed, org-wide quick-reply library
([config-free](backend/app/Models/CannedResponse.php); permission
`canned_responses.manage`). Admins curate them under **Admin → Canned responses**; agents
insert one into the composer from the `post_add` menu, filtered to the conversation's language
(plus any-language entries).

**Internal notes** are agent-only annotations on a conversation (`sender_type: note`). They are
kept off the public conversation channel — `MessageSent` broadcasts a note **only** on the
private `conversation-notes.{uuid}` channel, and the public transcript filters notes out — so a
visitor can never see one, even over raw websockets. Notes don't count as conversation activity
(no `last_activity` bump, no idle reset). Agents toggle note mode in the composer; notes render
as a distinct amber block, in real time for any agent/supervisor viewing the conversation.

**Contacts** link conversations by the visitor's **email (else phone)**: `start()` finds or
creates a `Contact`, links the conversation, and backfills a missing name. The chat details
panel shows **Previous conversations** for the same contact via
`GET /agent/conversations/{uuid}/history`.

## AI translation (BM ↔ EN)

Optional, env-controlled message translation via an **external SeaLLM/Ollama server**:

| Env var | Meaning | Default |
|---------|---------|---------|
| `TRANSLATION` | Master switch | `false` |
| `TRANSLATION_METHOD` | `manual` (per-message/draft buttons for agents), `auto` (background job translates every visitor/agent message), `none` | `none` |
| `TRANSLATION_API_URL` | Base URL of the translation service, e.g. `http://172.17.0.1:5555` | — |
| `TRANSLATION_TIMEOUT` | Client timeout in seconds (the model can be slow) | `60` |
| `TRANSLATION_CACHE_TTL` | Fast-tier cache lifetime in seconds | `86400` |

Wire contract (no auth; `en`/`ms` only), spoken only by
`app/Services/Translation/HttpTranslator.php`: `POST {url}/translate` with
`{"text","source_language","target_language"}`. The service returns **HTTP 200 even on
failure** — success is the `success` boolean, not the status code — so a `200` with
`{"success":false,"error":…}` is treated as a failure, as are `400`/`500`.

**Caching lives entirely in Laravel** (`CachingTranslator` wraps `HttpTranslator`): a
two-level, text-keyed cache — a fast tier on the default cache store (Redis once configured,
DB otherwise, TTL `TRANSLATION_CACHE_TTL`) over a durable `translation_cache` table, both
keyed by `sha256(source:target:text)`. The AI is only called on a miss, so identical text is
never re-translated across conversations. Per-message results still cache on
`messages.meta.translations` and broadcast as **`message.translated`**; the dashboard shows
them under bubbles (translate buttons in manual mode) and the widget shows agent replies in
the visitor's language. Auto mode skips bot/system messages, never blocks message delivery,
and degrades to a logged no-op when the URL is unset (manual returns a friendly 503).

## Licensing (central License Manager)

Licenses are a **pool synced from the central platform, spent one per agent**. There is no
per-installation license key — the platform id and its access token identify TDEChat.
**Unset env = disabled-open** (no restrictions — for dev/self-hosted):

| Env var | Meaning |
|---------|---------|
| `LICENSE_MANAGER_URL` | e.g. `https://lic-serv.example.com` |
| `LICENSE_PLATFORM_ID` / `LICENSE_PLATFORM_ACCESS_TOKEN` | Identifies this platform to the license server |
| `LICENSE_PLATFORM_SLUG` | Display label only |

The flow: the platform creates licenses for TDEChat (they start `inactive`) → TDEChat syncs
with `php artisan license:sync` or the admin screen's **Sync now** → synced licenses become
`active` on the platform, new ones join the local pool, and every status is refreshed. Sync is
idempotent, runs hourly under `schedule:work`, and calls two endpoints:
`admin/platforms/sync-licenses` to activate, then `admin/platforms/licenses` for the detail
(type, features, `max_users`) the sync response omits.

**One license activates one agent**, so `max_users` on a license record is ignored — it is
boilerplate from the platform's bulk generator. Licenses are claimed automatically when an
agent profile is created or re-activated, and existing agents are auto-assigned (oldest first)
the first time a sync runs, so switching licensing on never strands anyone. Running out
returns a friendly 422 on user creation. Users **without** an agent profile — admins,
supervisors — consume nothing.

A license **stays bound** to its agent when that user is deactivated; only an explicit
**Release** on the admin screen returns it to the pool (removing the agent profile entirely
also frees it). With no usable license at all, the **user-management** module returns a clean
403 with `code: license_invalid` (installation-level), though the license screen itself stays
reachable so an admin can fix it. Because the pool lives in the local database, an unreachable
platform never locks anyone out — statuses simply stop refreshing until the next successful
sync. Feature gates read the union of features across usable licenses: `license()->hasFeature('x')`.

**Strict per-seat enforcement.** Beyond provisioning, the whole `/agent` API is gated by
`seat.valid`: an agent whose **own** assigned license has lapsed (expired or no longer active),
or who holds none while licensing is enforced, is refused with `403 code: seat_expired` —
distinct from the installation-level `license_invalid` so the two never collide. The dashboard
treats `seat_expired` as a **forced logout** (clears the token, shows a notice, returns to
login); `/auth/me` also reports `agent.licensed` so the seat state is visible without an action.
Non-agents (admins/supervisors) spend no seat and are never blocked, and an unconfigured
platform stays disabled-open.

## API documentation

The REST API is specified in OpenAPI 3.1 at
[backend/public/docs/openapi.yaml](backend/public/docs/openapi.yaml) — hand-authored as the
contract, covering the public widget API, auth, the agent inbox API, websocket channels and
all four outbound webhook payloads. Swagger UI is served at **http://localhost:8000/docs**
while `php artisan serve` is running (no composer package needed; the UI is a static page).

## Deployment (Ubuntu server)

Everything runs comfortably on one box: nginx serves the built Angular app and proxies to
PHP-FPM and Reverb, with PostgreSQL alongside. Six processes in total — nginx, php-fpm,
`reverb:start`, `queue:work`, `schedule:work`, and postgres.

### Sizing

| | Development | Production (starting point) |
|---|---|---|
| OS | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS |
| vCPU | 2 | 4 |
| RAM | 4 GB | 8 GB |
| Disk | 20 GB SSD | 80 GB SSD |

A dev box wants 4 GB because the Angular production build peaks around 1.5–2 GB — by far the
largest single consumer. Everything else idles in the hundreds of megabytes, so 2 GB is fine
if you build the frontend elsewhere.

Three things drive the production numbers, and only one of them is RAM:

- **File descriptors cap your websockets, not memory.** Reverb keeps one persistent connection
  per open widget and per agent tab in a single event loop, a few KB each. Ubuntu's default
  1024 open-file limit caps you near a thousand sockets regardless of how much RAM you buy —
  hence `LimitNOFILE` in the unit file below.
- **Keep Postgres for data only.** `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER`
  default to **`redis`**, so cache reads, queue polling and sessions never touch Postgres — cache
  on Redis DB 1, queue and sessions on DB 0, so `cache:clear` can't drop queued jobs. Reverting
  any of them to `database` puts that load straight back on Postgres, which is then the first
  component to feel it.
- **Mitel polling is constant.** With `MITEL_ENABLED=true` the scheduler fires every 15s and
  makes per-item HTTPS calls to MiCC. Cheap on CPU, but it needs `schedule:work` running as a
  service — a crontab entry only fires once a minute and cannot drive a sub-minute cadence.

> These are estimates from the architecture, not load-test results. Expect 4 vCPU / 8 GB to
> handle low hundreds of concurrent chats. Cache and queue already run on Redis (the default);
> the remaining scaling order is: give Postgres its own host (below), then run multiple app
> servers with Reverb scaled over Redis pub/sub.

### Packages

```bash
# The committed composer.lock resolves to Symfony 8 components, which require PHP 8.4.
# Ubuntu 24.04 only ships 8.3, so add the ondrej PPA:
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update

sudo apt install -y nginx postgresql redis-server \
  php8.4-fpm php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath
sudo update-alternatives --set php /usr/bin/php8.4   # make the CLI 8.4
```

> The committed lock resolves to Laravel 12 on Symfony 8, which requires PHP **8.4**. Don't
> downgrade to 8.3 — `composer install` would fail the platform check and any `composer update`
> would deploy untested dependency versions. Install 8.4 so `composer install` matches the lock.

Composer 2 is needed to install backend dependencies. Node 20+ is needed **only** if you build
the frontend on the server — building in CI and shipping the `dist` directory keeps Node off
production entirely.

### Host layout, and why it matters

Serve the Angular app and the API from **one hostname**. The MiCC work-item `TargetUri` is
built from `APP_URL` ([Mitel section](#mitel-micc-open-media)) even though `/embed/chat/{uuid}`
is an Angular route, so a split host silently produces an embed URL that 404s. If you do split
them, set `MITEL_AGENT_CHAT_URL` explicitly to the frontend host.

### Build the frontend

The shipped `src/environments/environment.ts` points at `localhost`, and the `production`
build configuration has **no `fileReplacements`** — so a stock production build embeds
localhost URLs. Add a production environment before building:

```ts
// frontend/src/environments/environment.production.ts
export const environment = {
  apiUrl: 'https://tdechat.example.com',
  reverb: {
    key: '<REVERB_APP_KEY from backend/.env>',
    host: 'tdechat.example.com',
    port: 443,
    useTls: true,
  },
};
```

Then add the replacement to the `production` configuration in `angular.json`:

```json
"fileReplacements": [
  { "replace": "src/environments/environment.ts",
    "with": "src/environments/environment.production.ts" }
]
```

```bash
cd frontend && npm ci && npx ng build --configuration production
# output: dist/frontend/browser  ← this is nginx's root
```

### Backend setup

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
php artisan migrate --force          # never --seed with DevSeeder on production
php artisan config:cache && php artisan route:cache
```

Production `.env` essentials. **`REVERB_HOST` here is the backend's *publish* target, so it
must point at the local Reverb listener — not the public domain.** Laravel POSTs broadcast
events (typing, new messages) to `REVERB_HOST`; sending them out through the public
domain/proxy makes every broadcasting call fail with a 500 and kills realtime. The *browser*
connects to the public host over TLS, but that is configured separately in the frontend build
(`environment.ts`), not here.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tdechat.example.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432                  # a native install uses 5432; only the dev compose uses 5433

BROADCAST_CONNECTION=reverb
REVERB_HOST=127.0.0.1         # local publish target — NOT the public domain
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
```

The browser side lives in `frontend/src/environments/environment.ts` (step above): `host` =
the public domain, `port: 443`, `useTls: true`. Those two configs are independent — the
backend talks to Reverb locally, the browser talks to it through nginx/your proxy over `wss`.

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name tdechat.example.com;

    ssl_certificate     /etc/letsencrypt/live/tdechat.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tdechat.example.com/privkey.pem;

    root /var/www/tdechat/frontend;      # dist/frontend/browser
    index index.html;
    client_max_body_size 8m;
    charset utf-8;                       # serve widget.js et al as UTF-8

    # Laravel owns the API, Swagger UI and the widget assets.
    location ~ ^/(api|docs|widget\.js|widget-demo\.html|storage)(/|$) {
        root /var/www/tdechat/backend/public;
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        root /var/www/tdechat/backend/public;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Reverb. Without the Upgrade headers the websocket never establishes and
    # the widget silently falls back to polling.
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600s;
    }

    # Angular routes (/agent, /admin, /embed/chat/…) are client-side.
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

### systemd units

`/etc/systemd/system/tdechat-reverb.service`:

```ini
[Unit]
Description=TDEChat Reverb websocket server
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/tdechat/backend
ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
Restart=always
RestartSec=5
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

`tdechat-queue.service` is the same with
`ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=3 --max-time=3600`, and
`tdechat-scheduler.service` with `ExecStart=/usr/bin/php artisan schedule:work`. The scheduler
runs the **hourly license sync** and (when `MITEL_ENABLED=true`) the sub-minute MiCC polling,
so keep it running whenever either licensing or Mitel is configured.

```bash
sudo systemctl enable --now tdechat-reverb tdechat-queue tdechat-scheduler
```

Restart the queue worker on every deploy (`systemctl restart tdechat-queue`) so it picks up
new code — long-running workers hold the old classes in memory.

### PostgreSQL

Starting points for an 8 GB box, in `/etc/postgresql/16/main/postgresql.conf`:

```conf
shared_buffers = 2GB              # ~25% of RAM
effective_cache_size = 6GB
work_mem = 16MB
maintenance_work_mem = 512MB
```

Back it up daily and keep the dump off the box:

```bash
pg_dump -Fc tdechat > /var/backups/tdechat-$(date +%F).dump
```

#### High availability & point-in-time recovery

TDEChat **owns every conversation and message in Postgres** — there is no external chat store to
fall back on, and since cache/queue/sessions moved to Redis, Postgres is now the one stateful
component whose loss loses data. A daily `pg_dump` is a last resort (up to 24h of data gone, and
a long restore), not availability. For anything beyond a single dev box, pick one:

**Option A — managed Postgres (recommended).** RDS / Cloud SQL / DigitalOcean Managed Postgres
give you a synchronous standby, automatic failover, and continuous PITR out of the box. Point
`DB_HOST` at the managed endpoint and you're done — no replication to operate yourself.

**Option B — self-managed streaming replication + WAL archiving.** Run a hot standby and archive
WAL so you can both fail over and recover to any point in time.

On the **primary** (`postgresql.conf`), then restart:

```conf
wal_level = replica
max_wal_senders = 10
archive_mode = on
archive_command = 'test ! -f /var/backups/wal/%f && cp %p /var/backups/wal/%f'   # ship WAL off-box
```

Create a replication role and allow the standby in `pg_hba.conf`:

```bash
sudo -u postgres psql -c "CREATE ROLE replicator WITH REPLICATION LOGIN PASSWORD '…';"
# pg_hba.conf:  host  replication  replicator  <standby-ip>/32  scram-sha-256
```

Build the **standby** from a base backup, then start it:

```bash
sudo -u postgres pg_basebackup -h <primary-ip> -U replicator -D /var/lib/postgresql/16/main \
  -Fp -Xs -R -P            # -R writes standby.signal + primary_conninfo
sudo systemctl start postgresql
```

**Failover:** promote the standby, then repoint the app and restart the workers (long-running
`reverb`/`queue`/`scheduler` units cache the old connection):

```bash
sudo -u postgres pg_ctl promote -D /var/lib/postgresql/16/main   # or SELECT pg_promote();
# set DB_HOST=<new-primary> in backend/.env, then:
sudo systemctl restart tdechat-reverb tdechat-queue tdechat-scheduler php8.4-fpm
```

For **hands-off failover** put Patroni + a VIP/HAProxy (or PgBouncer) in front so `DB_HOST` never
changes. Chat traffic is write-heavy and read-your-writes sensitive, so keep a **single primary**
— use the replica for failover and PITR, not read-splitting (reserve any read replica for
analytics-only queries via Laravel's read/write connections).

### Scaling

Cache, queue and sessions **run on Redis by default** (`CACHE_STORE`, `QUEUE_CONNECTION`,
`SESSION_DRIVER` = `redis` in `.env`), so Postgres carries only data. Just make sure
`redis-server` is running and the `queue:work` worker is pointed at Redis (it follows
`QUEUE_CONNECTION`; restart the worker after changing it). Cache lives on Redis DB 1 and queue
on DB 0, so flushing the cache never touches queued jobs.

### Post-deploy checklist

- Change the seeded `admin@tdechat.test` password, or delete the account.
- `php artisan mitel:check` if the Mitel integration is enabled — it validates config, takes a
  token, and lists live queues.
- Load `https://tdechat.example.com/widget-demo.html`, send a message, and confirm an agent
  sees it appear without refreshing (proves nginx is proxying websockets correctly).
