#!/usr/bin/env bash
#
# Restore a TDEChat Postgres dump produced by backup-db.sh.
#
#   ops/restore-db.sh <dumpfile> [target_db]
#
# Defaults the target to DB_DATABASE from backend/.env. TEST THIS regularly:
# restore into a throwaway DB (pass a target) and confirm the app boots against
# it — an untested backup is not a backup.
#
set -euo pipefail

DUMP="${1:?usage: restore-db.sh <dumpfile> [target_db]}"
[ -r "$DUMP" ] || { echo "cannot read dump: $DUMP" >&2; exit 1; }

APP_DIR="${TDECHAT_DIR:-/var/www/tdechat}"
ENV_FILE="$APP_DIR/backend/.env"
env_get() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"
DB_USER="$(env_get DB_USERNAME)"; DB_PASS="$(env_get DB_PASSWORD)"
TARGET="${2:-$(env_get DB_DATABASE)}"

echo "About to restore:"
echo "  dump   : $DUMP"
echo "  target : $TARGET on $DB_HOST:${DB_PORT:-5432}"
echo "This OVERWRITES existing data in $TARGET. Ctrl-C within 8s to abort."
sleep 8

# --clean --if-exists drops existing objects first; --no-owner avoids role
# mismatches when restoring onto a different box.
PGPASSWORD="$DB_PASS" pg_restore --clean --if-exists --no-owner --no-privileges \
  -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USER" -d "$TARGET" "$DUMP"

echo "restore complete into $TARGET."
