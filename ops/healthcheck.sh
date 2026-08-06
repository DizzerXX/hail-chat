#!/usr/bin/env bash
#
# TDEChat health watchdog. Alerts when a service is down or the queue is failing
# jobs. Run every few minutes from tdechat-healthcheck.timer. systemd already
# restarts services (Restart=always); this catches the cases restarts don't fix
# (crash-loops, a wedged worker, piling failed jobs) and tells a human.
#
# Config via environment (all optional):
#   TDECHAT_DIR             app root (default /var/www/tdechat)
#   TDECHAT_SERVICES        space-separated units to check
#                           (default: php-fpm + the three tdechat-* workers)
#   TDECHAT_ALERT_WEBHOOK   POST {"text": "..."} here on problems (Slack/Teams/etc.)
#   TDECHAT_ALERT_EMAIL     mail the alert here (needs a working `mail`)
#
set -uo pipefail

APP_DIR="${TDECHAT_DIR:-/var/www/tdechat}"
SERVICES="${TDECHAT_SERVICES:-php8.4-fpm tdechat-reverb tdechat-queue tdechat-scheduler}"
ENV_FILE="$APP_DIR/backend/.env"
env_get() { grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

problems=()

# 1) Services up?
for s in $SERVICES; do
  systemctl is-active --quiet "$s" || problems+=("service DOWN: $s")
done

# 2) Database reachable? + failed queue jobs. Read the table directly (no
#    artisan, so no root-owned log files land in storage/logs). The DB is often
#    remote, so an explicit reachability check is worth having.
DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"; DB_NAME="$(env_get DB_DATABASE)"
DB_USER="$(env_get DB_USERNAME)"; DB_PASS="$(env_get DB_PASSWORD)"
if [ -n "$DB_HOST" ] && command -v psql >/dev/null; then
  if ! PGPASSWORD="$DB_PASS" psql -tAq -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USER" -d "$DB_NAME" -c 'SELECT 1' >/dev/null 2>&1; then
    problems+=("database unreachable: $DB_HOST")
  else
    failed="$(PGPASSWORD="$DB_PASS" psql -tAq -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USER" -d "$DB_NAME" \
      -c 'SELECT count(*) FROM failed_jobs;' 2>/dev/null | tr -dc '0-9')"
    [ -n "${failed:-}" ] && [ "$failed" -gt 0 ] && problems+=("failed queue jobs: $failed")
  fi
fi

# 3) Redis reachable — only when the app actually uses it (cache/queue/session
#    on redis). Boxes on file/database drivers skip this, so no false alarms.
if printf '%s\n' "$(env_get CACHE_STORE)" "$(env_get QUEUE_CONNECTION)" "$(env_get SESSION_DRIVER)" | grep -qx 'redis'; then
  R_HOST="$(env_get REDIS_HOST)"; R_PORT="$(env_get REDIS_PORT)"
  if command -v redis-cli >/dev/null; then
    [ "$(redis-cli -h "${R_HOST:-127.0.0.1}" -p "${R_PORT:-6379}" ping 2>/dev/null)" = "PONG" ] \
      || problems+=("redis down: ${R_HOST:-127.0.0.1}:${R_PORT:-6379}")
  fi
fi

if [ ${#problems[@]} -eq 0 ]; then
  echo "healthcheck ok"
  exit 0
fi

MSG="TDEChat health on $(hostname): $(IFS='; '; echo "${problems[*]}")"
echo "$MSG" >&2

if [ -n "${TDECHAT_ALERT_WEBHOOK:-}" ]; then
  curl -fsS -m 10 -X POST -H 'Content-Type: application/json' \
    -d "$(printf '{"text":"%s"}' "$MSG")" "$TDECHAT_ALERT_WEBHOOK" >/dev/null 2>&1 || true
fi
if [ -n "${TDECHAT_ALERT_EMAIL:-}" ] && command -v mail >/dev/null; then
  echo "$MSG" | mail -s "TDEChat alert: $(hostname)" "$TDECHAT_ALERT_EMAIL" || true
fi

exit 1
