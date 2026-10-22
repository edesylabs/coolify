#!/bin/bash
## Coolify (edesylabs fork) — restore the Coolify database from a deploy.sh snapshot.
##
## Usage (as root on the server):
##   curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/restore.sh | bash
##   curl -fsSL .../scripts/restore.sh | bash -s -- /data/coolify/backups/deploy/<timestamp>   # a specific snapshot
##
## Without an argument it uses the newest snapshot whose dump has user accounts (a dump of an
## emptied database is skipped). It then:
##   1. checks the dump is readable and the encryption key (APP_KEY) still matches
##   2. saves the current database next to the snapshot, so the restore can be undone
##   3. replaces the database with the dump
##   4. clears the cache, starts Coolify and applies the migrations of the running version
##
## Environment variables:
##   YES=true                 Do not ask for confirmation
##   COOLIFY_DATA_DIR         Coolify data directory (default: /data/coolify)
##   HEALTH_TIMEOUT           Seconds to wait for Coolify to become healthy (default: 300)

set -e
set -o pipefail

DATA_DIR="${COOLIFY_DATA_DIR:-/data/coolify}"
SOURCE_DIR="$DATA_DIR/source"
ENV_FILE="$SOURCE_DIR/.env"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-300}"
YES="${YES:-false}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

info() { echo -e "${BLUE}→${NC} $*"; }
ok() { echo -e "${GREEN}✓${NC} $*"; }
warn() { echo -e "${YELLOW}⚠${NC} $*"; }
fail() {
    echo -e "${RED}✗${NC} $*" >&2
    exit 1
}

env_value() {
    local file="$1" key="$2" default="$3" value=""
    [ -f "$file" ] && value=$(grep -m1 "^${key}=" "$file" | cut -d '=' -f2- || true)
    echo "${value:-$default}"
}

DB_USER=$(env_value "$ENV_FILE" DB_USERNAME coolify)
DB_NAME=$(env_value "$ENV_FILE" DB_DATABASE coolify)

# Prints the number of rows in the users table of a plain pg_dump.
dump_users() {
    gunzip -c "$1" 2>/dev/null | awk '$1 == "COPY" && $2 == "public.users" { inside = 1; next }
        inside && $0 == "\\." { print rows + 0; exit }
        inside { rows++ }'
}

pick_snapshot() {
    local dir users
    for dir in $(ls -1d "$DATA_DIR"/backups/deploy/*/ 2>/dev/null | sed 's:/$::' | sort -r); do
        [ -f "$dir/coolify-db.sql.gz" ] || continue
        users=$(dump_users "$dir/coolify-db.sql.gz")
        [ "${users:-0}" -gt 0 ] && echo "$dir" && return
    done
}

wait_for_database() {
    local waited=0
    until docker exec coolify-db pg_isready -U "$DB_USER" -q >/dev/null 2>&1; do
        [ "$waited" -ge 60 ] && return 1
        sleep 2
        waited=$((waited + 2))
    done
}

confirm() {
    [ "$YES" = "true" ] && return 0
    # stdin is the script itself when piped from curl; ask on the terminal
    if ! { exec 3</dev/tty; } 2>/dev/null; then
        fail "No terminal to confirm on. Re-run with YES=true to restore without asking."
    fi
    local answer
    printf "Replace the current Coolify database with this snapshot? [y/N] "
    read -r answer <&3
    exec 3<&-
    [ "$answer" = "y" ] || [ "$answer" = "Y" ] || fail "Cancelled; nothing was changed."
}

[ "$EUID" -eq 0 ] || fail "Please run as root (or with sudo)."
[ -f "$ENV_FILE" ] || fail "$ENV_FILE not found. Is Coolify installed?"

SNAPSHOT="${1:-$(pick_snapshot)}"
SNAPSHOT="${SNAPSHOT%/}"
[ -n "$SNAPSHOT" ] || fail "No snapshot with user accounts found under $DATA_DIR/backups/deploy."
DUMP="$SNAPSHOT/coolify-db.sql.gz"
[ -f "$DUMP" ] || fail "$DUMP not found."

info "Checking $DUMP"
gzip -t "$DUMP" 2>/dev/null || fail "$DUMP is damaged."
SNAPSHOT_USERS=$(dump_users "$DUMP")
[ "${SNAPSHOT_USERS:-0}" -gt 0 ] || fail "$DUMP has no user accounts; it is not a usable backup."

if [ -f "$SNAPSHOT/.env" ] && [ "$(env_value "$SNAPSHOT/.env" APP_KEY "")" != "$(env_value "$ENV_FILE" APP_KEY "")" ]; then
    fail "APP_KEY in $ENV_FILE differs from the snapshot's. Stored keys and secrets would not decrypt; restore $SNAPSHOT/.env first."
fi

echo ""
echo "Coolify database restore"
echo "  Snapshot       : $SNAPSHOT ($(du -h "$DUMP" | cut -f1), taken $(date -r "$DUMP" '+%Y-%m-%d %H:%M'))"
echo "  Users in dump  : $SNAPSHOT_USERS"
echo "  Running image  : $(docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null || echo none)"
echo ""
confirm

if [ "$(docker inspect coolify-db --format '{{.State.Running}}' 2>/dev/null)" != "true" ]; then
    docker start coolify-db >/dev/null 2>&1 || fail "coolify-db is not running and could not be started."
fi
wait_for_database || fail "coolify-db is not accepting connections. Check: docker logs coolify-db"

if [ "$(docker exec coolify-db psql -U "$DB_USER" -d postgres -tAc "SELECT count(*) FROM pg_database WHERE datname = '$DB_NAME';")" = "1" ]; then
    CURRENT_DUMP="$SNAPSHOT/before-restore-$(date +%Y%m%d-%H%M%S).sql.gz"
    info "Saving the current database to $CURRENT_DUMP"
    docker exec coolify-db pg_dump -U "$DB_USER" "$DB_NAME" | gzip >"$CURRENT_DUMP" && gzip -t "$CURRENT_DUMP" ||
        fail "Could not save the current database; nothing was changed."
fi

info "Stopping coolify"
docker stop coolify >/dev/null 2>&1 || true

info "Restoring the database"
docker exec coolify-db psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -q \
    -c "DROP DATABASE IF EXISTS \"$DB_NAME\" WITH (FORCE);" \
    -c "CREATE DATABASE \"$DB_NAME\" OWNER \"$DB_USER\";" ||
    fail "Could not recreate the database."
gunzip -c "$DUMP" | docker exec -i coolify-db psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -q >/dev/null ||
    fail "Restore failed part-way. Run this command again to retry."
RESTORED_USERS=$(docker exec coolify-db psql -U "$DB_USER" -d "$DB_NAME" -tAc "SELECT count(*) FROM users;")
[ "$RESTORED_USERS" = "$SNAPSHOT_USERS" ] || fail "Restored database has $RESTORED_USERS users, expected $SNAPSHOT_USERS."
ok "Database restored ($RESTORED_USERS users)"

# The cache still holds the replaced database's teams and settings
REDIS_PASSWORD=$(env_value "$ENV_FILE" REDIS_PASSWORD "")
docker exec coolify-redis redis-cli --no-auth-warning ${REDIS_PASSWORD:+-a "$REDIS_PASSWORD"} FLUSHALL >/dev/null 2>&1 &&
    ok "Cache cleared" || warn "Could not clear the Redis cache; log out and back in if pages look wrong"

info "Starting coolify"
docker start coolify >/dev/null || fail "Could not start coolify. Check: docker logs coolify"
waited=0
until [ "$(docker inspect coolify --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' 2>/dev/null)" = "healthy" ]; do
    [ "$waited" -ge "$HEALTH_TIMEOUT" ] && fail "coolify did not become healthy within ${HEALTH_TIMEOUT}s. Check: docker logs coolify"
    sleep 5
    waited=$((waited + 5))
done
ok "coolify is healthy"

# The startup migration exits 0 even when a migration fails, so run it explicitly
info "Applying database migrations"
docker exec coolify php artisan migrate --force --isolated --no-interaction ||
    fail "Database migrations failed (see above). The restored data is intact; send this output for help."
docker exec coolify php artisan migrate:status --pending=1 --no-interaction >/dev/null ||
    fail "Database migrations are still pending."
ok "All database migrations applied"

echo ""
ok "Coolify database restored from $SNAPSHOT"
if [ -n "$CURRENT_DUMP" ]; then
    info "The replaced database is kept at $CURRENT_DUMP"
fi
