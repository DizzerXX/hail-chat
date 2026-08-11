# TDEChat — improvement roadmap

Living checklist of suggested improvements. Tick items as they ship.

**Legend:** `[x]` done · `[~]` built on a release branch (awaiting merge/deploy) · `[ ]` pending

_Current: `main` = v1.0.0 · safety net staged on `release/1.1` → v1.1.0_

## 🔴 Production safety net — v1.1
- [~] DB backups (script + daily timer, integrity-checked, pruned, off-box option)
- [~] Restore script (+ drill steps)
- [~] Health watchdog + alerts (dependency-aware: services, DB, Redis-if-used, failed jobs)
- [~] Log rotation (daily log channel)
- [~] Redis-aware health panel (no false-red when Redis/Mitel unused)
- [ ] Restore drill — run once per box (manual)
- [ ] Install timers + set alert channel / off-box backup target per box (manual)
- [ ] Merge `release/1.1` → `main` + tag `v1.1.0`

## ⚡ Quick wins
- [x] App version badge in the System health panel (v1.0.0)
- [~] Redis-aware health check (folded into v1.1)
- [ ] Deploy safety guard (bail if not on `main` / dirty working tree)

## 🟠 Compliance (gov / PDPA)
- [x] System Settings + data retention (opt-in, min-days floor)
- [x] Per-user audit log + "View" dialog
- [~] Recycle bin — admin delete → soft-delete (restorable), auto-purge after 30 days; retention now **archives** into the bin instead of hard-deleting
- [ ] Retention v1.1: legal-hold exclusion + anonymize-instead-of-delete option
- [ ] Widget consent notice (data-collection disclosure before a chat starts)
- [ ] Audit-log retention + wider coverage (inbox / chat-settings / license changes)

## 🟡 MiCC / core flow
- [x] Claim take-over on transfer + conversation activity log
- [x] Validated live — A→B transfer confirmed working

## 🟢 Security hardening
- [ ] 2FA for admin accounts
- [ ] Token / session expiry (Sanctum tokens don't expire today)
- [ ] Attachment type/size limits (+ AV scan)
- [ ] Widget bot / rate protection (CAPTCHA / throttle on chat-start)

## 🔵 Product / UX
- [~] Queue position — a waiting visitor sees "#N in queue" (standalone counts ahead; Mitel uses MiCC's position)
- [ ] Estimated wait time (our-side estimate) + "no agents online" fallback — the follow-on to queue position
- [ ] Post-chat CSAT survey (highest-value visible win)
- [x] Conversation / transcript search — server-side, matches the message body (v1.3)
- [x] Richer analytics (per-agent volume, resolution time, abandonment rate) (v1.3)
- [x] Timezone (MYT) display — global date-pipe default, UTC+8 (v1.3)

## 🟣 v1.3 polish (shipped on `release/1.3`)
- [x] FAQ "Chat with a live agent" bot-skip — admin toggle, default off
- [x] Per-file upload progress ("Uploading N of M…") on widget + agent
- [ ] Deploy safety guard — dropped for v1.3 (manual deploy; boxes differ)

---

## Delivered earlier this engagement (context)
- [x] Auto-end chat on widget/window close
- [x] Live idle countdown timer in the embed
- [x] Fixes: agent idle-alert sound (autoplay) · "Open CMS" link on live close · idle-settings cache re-read
- [x] Branding (app name, favicon, centered logo) + login rebrand
- [x] Public read-only transcript preview page
- [x] Deployment hardening (permissions model, per-box `environment.production.ts`, deploy scripts)
