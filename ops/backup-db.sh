#!/usr/bin/env bash
#
# TDEChat Postgres backup. Dumps the app database (creds read from backend/.env),
# verifies the dump is readable, prunes old copies, and optionally ships one
# off-box. Intended to run daily from tdechat-backup.timer.
#
# Config via environment (all optional):
#   TDECHAT_DIR             app root (default /var/www/tdechat)
#   TDECHAT_BACKUP_DIR      where dumps go (default /var/backups/tdechat)
#   TDECHAT_BACKUP_KEEP_DAYS retention (default 14)
#   TDECHAT_BACKUP_RSYNC    optional rsync/scp target for an off-box copy
#
set -euo pipefail

APP_DIR="${TDECHAT_DIR:-/var/www/tdechat}"
BACKUP_DIR="${TDECHAT_BACKUP_DIR:-/var/backups/tdechat}"
KEEP_DAYS="${TDECHAT_BACKUP_KEEP_DAYS:-14}"
ENV_FILE="$APP_DIR/backend/.env"

[ -r "$ENV_FILE" ] || { echo "cannot read $ENV_FILE" >&2; exit 1; }
env_get() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"; DB_NAME="$(env_get DB_DATABASE)"
DB_USER="$(env_get DB_USERNAME)"; DB_PASS="$(env_get DB_PASSWORD)"
: "${DB_HOST:?DB_HOST missing}" "${DB_NAME:?DB_DATABASE missing}" "${DB_USER:?DB_USERNAME missing}"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
STAMP="$(date +%F_%H%M%S)"
FILE="$BACKUP_DIR/tdechat_${STAMP}.dump"

# -Fc = custom compressed format (supports parallel/selective restore).
PGPASSWORD="$DB_PASS" pg_dump -Fc -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USER" "$DB_NAME" > "$FILE"

# Integrity check — a dump we can't list is not a backup.
PGPASSWORD="$DB_PASS" pg_restore -l "$FILE" >/dev/null

# Prune old local copies.
find "$BACKUP_DIR" -name 'tdechat_*.dump' -mtime +"$KEEP_DAYS" -delete

# Optional off-box copy (the single most important part — a backup on the same
# box you're protecting is only half a backup).
if [ -n "${TDECHAT_BACKUP_RSYNC:-}" ]; then
  rsync -a "$FILE" "$TDECHAT_BACKUP_RSYNC"/ || echo "WARNING: off-box rsync failed" >&2
fi

echo "backup ok: $FILE ($(du -h "$FILE" | cut -f1))"
