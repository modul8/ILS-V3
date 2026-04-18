#!/usr/bin/env bash
set -euo pipefail

# ILS V3 deploy helper
# Usage:
#   ./deploy.sh
# Optional env vars:
#   SRC_WEB_DIR, DEST_DIR, BACKUP_DIR, APP_OWNER, DB_MIGRATE, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, SCHEMA_FILE

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="$(dirname "$SCRIPT_DIR")"

SRC_WEB_DIR="${SRC_WEB_DIR:-$SCRIPT_DIR/web/}"
DEST_DIR="${DEST_DIR:-$BASE_DIR/app/}"
BACKUP_DIR="${BACKUP_DIR:-$BASE_DIR/backups/}"
APP_OWNER="${APP_OWNER:-}"

DB_MIGRATE="${DB_MIGRATE:-0}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-}"
DB_USER="${DB_USER:-}"
DB_PASS="${DB_PASS:-}"
SCHEMA_FILE="${SCHEMA_FILE:-$SCRIPT_DIR/web/schema.sql}"

timestamp="$(date +%F_%H%M%S)"

log() { echo "[deploy] $*"; }
warn() { echo "[deploy][warn] $*" >&2; }
fail() { echo "[deploy][error] $*" >&2; exit 1; }

command -v rsync >/dev/null 2>&1 || fail "rsync is required"

[ -d "$SRC_WEB_DIR" ] || fail "Source web dir not found: $SRC_WEB_DIR"
mkdir -p "$DEST_DIR"
mkdir -p "$BACKUP_DIR"

log "Creating app backup"
if [ -d "$DEST_DIR" ]; then
  cp -a "$DEST_DIR" "${BACKUP_DIR}/ils_v3_app_${timestamp}" || warn "app backup copy failed"
fi

log "Syncing application files"
rsync -av --delete \
  --exclude 'config.php' \
  --exclude 'uploads/' \
  "$SRC_WEB_DIR" \
  "$DEST_DIR"

log "Ensuring persistent directories/files"
mkdir -p "$DEST_DIR/uploads"
if [ ! -f "$DEST_DIR/config.php" ] && [ -f "$DEST_DIR/config.sample.php" ]; then
  cp "$DEST_DIR/config.sample.php" "$DEST_DIR/config.php"
  warn "Created config.php from sample. Edit it before production use."
fi

if [ -n "$APP_OWNER" ]; then
  log "Setting ownership: $APP_OWNER"
  chown -R "$APP_OWNER" "$DEST_DIR" || warn "chown failed"
fi

log "Setting permissions"
chmod -R u+rwX,go+rX "$DEST_DIR" || warn "chmod app dir failed"
chmod -R u+rwX,go+rwx "$DEST_DIR/uploads" || warn "chmod uploads failed"

if [ "$DB_MIGRATE" = "1" ]; then
  [ -n "$DB_NAME" ] || fail "DB_NAME required when DB_MIGRATE=1"
  [ -n "$DB_USER" ] || fail "DB_USER required when DB_MIGRATE=1"
  [ -f "$SCHEMA_FILE" ] || fail "Schema file not found: $SCHEMA_FILE"
  command -v mysql >/dev/null 2>&1 || fail "mysql client required when DB_MIGRATE=1"

  log "Backing up database"
  if command -v mysqldump >/dev/null 2>&1; then
    if [ -n "$DB_PASS" ]; then
      mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "${BACKUP_DIR}/ils_v3_db_${timestamp}.sql" || warn "DB backup failed"
    else
      mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" > "${BACKUP_DIR}/ils_v3_db_${timestamp}.sql" || warn "DB backup failed"
    fi
  else
    warn "mysqldump not found; skipping DB backup"
  fi

  log "Applying schema migration"
  if [ -n "$DB_PASS" ]; then
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SCHEMA_FILE"
  else
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" < "$SCHEMA_FILE"
  fi
fi

log "Deploy complete"
log "Next checks:"
log "  1) Login at /login.php"
log "  2) Verify role permissions (admin vs user)"
log "  3) Verify notes + photo upload + map pin links"
