#!/bin/bash
## Coolify (edesylabs fork) — one command for fresh install OR update.
##
## Usage (as root on the server):
##   curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/deploy.sh | bash
##   curl -fsSL .../scripts/deploy.sh | bash -s -- 4.4.7.1     # specific version
##
## Detects whether Coolify is already installed:
##   - not installed -> runs scripts/install.sh
##   - installed     -> safe update:
##       1. pre-flight: database running, enough disk space, no deployments in progress,
##          no other upgrade running, target image pullable (pulled up front)
##       2. snapshot: database dump + .env + compose files + current image
##       3. runs scripts/upgrade.sh
##       4. verifies: container healthy, expected image, PHP version, all migrations applied
##       5. on any failure in 3-4: automatic rollback (restores config + database,
##          restarts the previous image)
##   - a previous run did not finish (crash, lost SSH session, failed rollback)
##                   -> repairs it first: restores that run's snapshot if the database is
##                      incomplete, then continues with the update. Just run the same command again.
##
## Environment variables:
##   COOLIFY_CDN              Where scripts and compose files are fetched from (default: this repo's main branch)
##   COOLIFY_REGISTRY         Image registry (default: ghcr.io)
##   COOLIFY_IMAGE_NAMESPACE  Image namespace (default: edesylabs)
##   MIN_PHP_VERSION          Minimum PHP version the deployed image must report (default: 8.4.27)
##   SKIP_BACKUP=true         Update without a database snapshot (rollback then cannot restore the database)
##   AUTO_ROLLBACK=false      Leave a failed update in place for debugging instead of rolling back
##   FORCE=true               Update even while deployments are queued or running (they will fail)
##   DRY_RUN=true             Only print what would be done
##   COOLIFY_DATA_DIR         Coolify data directory (default: /data/coolify)

set -e
set -o pipefail

CDN="${COOLIFY_CDN:-https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main}"
REGISTRY_URL="${COOLIFY_REGISTRY:-ghcr.io}"
IMAGE_NAMESPACE="${COOLIFY_IMAGE_NAMESPACE:-edesylabs}"
MIN_PHP_VERSION="${MIN_PHP_VERSION:-8.4.27}"
SKIP_BACKUP="${SKIP_BACKUP:-false}"
AUTO_ROLLBACK="${AUTO_ROLLBACK:-true}"
FORCE="${FORCE:-false}"
DRY_RUN="${DRY_RUN:-false}"
DATA_DIR="${COOLIFY_DATA_DIR:-/data/coolify}"
SOURCE_DIR="$DATA_DIR/source"
ENV_FILE="$SOURCE_DIR/.env"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-300}"

# Set once the snapshot exists; failures after that point trigger a rollback.
SNAPSHOT_DIR=""
PREVIOUS_IMAGE=""
UPGRADE_LOG=""
ROLLBACK_ARMED=false
ROLLING_BACK=false

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
    if [ "$ROLLBACK_ARMED" = "true" ] && [ "$ROLLING_BACK" = "false" ]; then
        if [ "$AUTO_ROLLBACK" = "true" ]; then
            rollback
        else
            warn "AUTO_ROLLBACK=false: leaving the failed update in place. Snapshot: $SNAPSHOT_DIR"
        fi
    fi
    exit 1
}

env_value() {
    local key="$1" default="$2" value=""
    [ -f "$ENV_FILE" ] && value=$(grep -m1 "^${key}=" "$ENV_FILE" | cut -d '=' -f2- || true)
    echo "${value:-$default}"
}

db_user() { env_value DB_USERNAME coolify; }
db_name() { env_value DB_DATABASE coolify; }

detect_mode() {
    if [ -f "$ENV_FILE" ] && docker volume ls --format '{{.Name}}' 2>/dev/null | grep -q 'coolify-db'; then
        echo "update"
    else
        echo "install"
    fi
}

current_image() {
    docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null || echo "none"
}

resolve_version() {
    if [ -n "$1" ]; then
        local requested
        requested=$(echo "$1" | tr '[:upper:]' '[:lower:]')
        echo "${requested#v}"
        return
    fi
    curl -fsSL "$CDN/versions.json" | grep -m1 '"version"' | sed -E 's/.*"version"[^"]*"([^"]+)".*/\1/'
}

db_query() {
    docker exec coolify-db psql -U "$(db_user)" -d "$(db_name)" -tAc "$1"
}

preflight_update() {
    info "Running pre-flight checks"

    [ "$(docker inspect coolify-db --format '{{.State.Running}}' 2>/dev/null)" = "true" ] ||
        fail "coolify-db is not running. Start Coolify first (cd $SOURCE_DIR && docker compose up -d)."
    ok "Database container is running"

    local status_file="$SOURCE_DIR/.upgrade-status"
    if [ -f "$status_file" ] && [ -n "$(find "$status_file" -mmin -15 2>/dev/null)" ] && ! grep -qE '^(error|done|6)\|' "$status_file"; then
        fail "Another upgrade looks active ($(cut -d'|' -f2 "$status_file")). Wait for it or remove $status_file."
    fi

    local active
    active=$(db_query "SELECT count(*) FROM application_deployment_queues WHERE status IN ('queued', 'in_progress');" 2>/dev/null || echo "unknown")
    if [ "$active" != "0" ]; then
        if [ "$FORCE" = "true" ]; then
            warn "$active deployment(s) queued or running; continuing because FORCE=true (they will fail)"
        else
            fail "$active deployment(s) are queued or running. Wait for them to finish, or re-run with FORCE=true."
        fi
    else
        ok "No deployments in progress"
    fi

    if [ "$SKIP_BACKUP" != "true" ]; then
        local db_bytes free_kb
        db_bytes=$(db_query "SELECT pg_database_size(current_database());" 2>/dev/null || echo 0)
        free_kb=$(df -Pk "$DATA_DIR" | awk 'NR==2 {print $4}')
        # A compressed dump is far smaller than the live database; require the full size plus 1 GiB headroom.
        if [ -n "$free_kb" ] && [ $((free_kb * 1024)) -lt $((db_bytes + 1073741824)) ]; then
            fail "Not enough free disk space under $DATA_DIR for a database snapshot ($((free_kb / 1024)) MiB free)."
        fi
        ok "Enough disk space for a snapshot"
    fi

    local target="$REGISTRY_URL/$IMAGE_NAMESPACE/coolify:$VERSION"
    info "Pulling $target"
    docker pull -q "$target" >/dev/null || fail "Cannot pull $target. Is the version published?"
    ok "Target image available"
}

snapshot() {
    SNAPSHOT_DIR="$DATA_DIR/backups/deploy/$(date +%Y%m%d-%H%M%S)"
    mkdir -p "$SNAPSHOT_DIR"
    PREVIOUS_IMAGE=$(current_image)
    echo "$PREVIOUS_IMAGE" >"$SNAPSHOT_DIR/previous-image"

    info "Saving configuration to $SNAPSHOT_DIR"
    for file in .env docker-compose.yml docker-compose.prod.yml docker-compose.custom.yml; do
        [ -f "$SOURCE_DIR/$file" ] && cp -p "$SOURCE_DIR/$file" "$SNAPSHOT_DIR/$file"
    done

    if [ "$SKIP_BACKUP" = "true" ]; then
        warn "Skipping database snapshot (SKIP_BACKUP=true); a rollback cannot restore the database"
    else
        info "Dumping database"
        if ! docker exec coolify-db pg_dump -U "$(db_user)" "$(db_name)" | gzip >"$SNAPSHOT_DIR/coolify-db.sql.gz" ||
            ! gzip -t "$SNAPSHOT_DIR/coolify-db.sql.gz" || [ ! -s "$SNAPSHOT_DIR/coolify-db.sql.gz" ]; then
            rm -f "$SNAPSHOT_DIR/coolify-db.sql.gz"
            fail "Database snapshot failed. Fix coolify-db or re-run with SKIP_BACKUP=true."
        fi
        ok "Database snapshot saved ($(du -h "$SNAPSHOT_DIR/coolify-db.sql.gz" | cut -f1))"
    fi

    mark_snapshot in-progress
    ROLLBACK_ARMED=true
}

# Each snapshot records how its run ended: in-progress, done, rolled-back, failed or recovered.
# Snapshots from before this was tracked have no status file.
mark_snapshot() {
    [ -n "$SNAPSHOT_DIR" ] && echo "$1" >"$SNAPSHOT_DIR/status"
    return 0
}

latest_snapshot() {
    ls -1d "$DATA_DIR"/backups/deploy/*/ 2>/dev/null | sort | tail -1 | sed 's:/$::'
}

unfinished_snapshot() {
    local latest
    latest=$(latest_snapshot)
    [ -n "$latest" ] || return 0
    case "$(cat "$latest/status" 2>/dev/null || echo unknown)" in
        done | rolled-back | recovered) ;;
        *) echo "$latest" ;;
    esac
}

# A plain pg_dump creates the constraints after loading all data, so a restore that was cut
# short is missing them. The last constraint in the dump existing means the restore completed.
database_complete() {
    local dump="$1/coolify-db.sql.gz" last=""
    [ -f "$dump" ] && last=$(gunzip -c "$dump" | sed -nE 's/^    ADD CONSTRAINT "?([^" ]+)"? .*/\1/p' | tail -1)
    if [ -n "$last" ]; then
        [ "$(db_query "SELECT count(*) FROM pg_constraint WHERE conname = '$last';" 2>/dev/null)" = "1" ]
    else
        # Nothing to compare against: at least require the foreign keys a finished restore has
        [ "$(db_query "SELECT count(*) > 0 FROM pg_constraint WHERE contype = 'f' AND connamespace = 'public'::regnamespace;" 2>/dev/null)" = "t" ]
    fi
}

wait_for_healthy() {
    local waited=0 status=""
    while [ "$waited" -lt "$HEALTH_TIMEOUT" ]; do
        status=$(docker inspect coolify --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' 2>/dev/null || echo "missing")
        [ "$status" = "healthy" ] && return 0
        sleep 5
        waited=$((waited + 5))
    done
    echo "$status"
    return 1
}

# upgrade.sh stops and recreates the containers in a detached background job and returns
# before that job is done. Until its log says step 6/6, the old containers may still be up
# and anything we do to them (verify, rollback) races the restart.
restart_in_progress() {
    [ -n "$UPGRADE_LOG" ] &&
        grep -q 'Container restart sequence started in background' "$UPGRADE_LOG" &&
        ! grep -q 'Step 6/6: Upgrade complete' "$UPGRADE_LOG"
}

wait_for_restart() {
    local waited=0
    while restart_in_progress; do
        [ "$waited" -ge "$HEALTH_TIMEOUT" ] && return 1
        sleep 5
        waited=$((waited + 5))
    done
}

wait_for_database() {
    local waited=0
    until docker exec coolify-db pg_isready -U "$(db_user)" -q >/dev/null 2>&1; do
        [ "$waited" -ge 60 ] && return 1
        sleep 2
        waited=$((waited + 2))
    done
}

# compose_up <image tag> [service...]
compose_up() {
    local image_tag="$1"
    shift
    local files="-f $SOURCE_DIR/docker-compose.yml -f $SOURCE_DIR/docker-compose.prod.yml"
    [ -f "$SOURCE_DIR/docker-compose.custom.yml" ] && files="$files -f $SOURCE_DIR/docker-compose.custom.yml"
    local args="--project-directory $SOURCE_DIR --env-file $ENV_FILE $files up -d --remove-orphans --force-recreate $*"
    if docker compose version >/dev/null 2>&1; then
        # shellcheck disable=SC2086
        LATEST_IMAGE="$image_tag" docker compose $args
    else
        # No compose plugin on the host: run it from the helper image, like upgrade.sh does
        local helper
        helper="$(env_value HELPER_IMAGE "$REGISTRY_URL/coollabsio/coolify-helper"):latest"
        docker run --rm -v "$SOURCE_DIR:$SOURCE_DIR" -v /var/run/docker.sock:/var/run/docker.sock \
            -e LATEST_IMAGE="$image_tag" "$helper" sh -c "docker compose $args"
    fi
}

restore_database() {
    local dump="$SNAPSHOT_DIR/coolify-db.sql.gz" user database
    user=$(db_user)
    database=$(db_name)
    info "Restoring database from $dump"
    docker exec coolify-db psql -U "$user" -d postgres -v ON_ERROR_STOP=1 -q \
        -c "DROP DATABASE IF EXISTS \"$database\" WITH (FORCE);" \
        -c "CREATE DATABASE \"$database\" OWNER \"$user\";" &&
        gunzip -c "$dump" | docker exec -i coolify-db psql -U "$user" -d "$database" -v ON_ERROR_STOP=1 -q >/dev/null
}

# Puts back the configuration, database and image saved in $SNAPSHOT_DIR.
restore_snapshot() {
    local failed=false
    for file in .env docker-compose.yml docker-compose.prod.yml docker-compose.custom.yml; do
        [ -f "$SNAPSHOT_DIR/$file" ] && cp -p "$SNAPSHOT_DIR/$file" "$SOURCE_DIR/$file"
    done
    ok "Configuration restored"

    docker stop coolify >/dev/null 2>&1 || true

    if [ -f "$SNAPSHOT_DIR/coolify-db.sql.gz" ]; then
        # The upgrade removes and recreates coolify-db; make sure it is up before restoring into it
        if [ "$(docker inspect coolify-db --format '{{.State.Running}}' 2>/dev/null)" != "true" ]; then
            compose_up "${PREVIOUS_IMAGE##*:}" postgres redis >/dev/null 2>&1 || true
        fi
        if wait_for_database && restore_database; then
            ok "Database restored"
        else
            failed=true
            warn "Database restore failed. Restore manually: gunzip -c $SNAPSHOT_DIR/coolify-db.sql.gz | docker exec -i coolify-db psql -U $(db_user) -d $(db_name)"
        fi
    else
        warn "No database snapshot; the database keeps any migrations the new version applied"
    fi

    if [ -z "$PREVIOUS_IMAGE" ] || [ "$PREVIOUS_IMAGE" = "none" ]; then
        warn "Previous image unknown; start Coolify manually: cd $SOURCE_DIR && docker compose up -d"
        failed=true
    else
        info "Starting previous version (${PREVIOUS_IMAGE##*:})"
        if compose_up "${PREVIOUS_IMAGE##*:}" && wait_for_healthy >/dev/null; then
            ok "Previous version is running and healthy"
        else
            failed=true
            warn "Previous version did not become healthy. Check: docker logs coolify"
        fi
    fi

    [ "$failed" = "false" ]
}

rollback() {
    ROLLING_BACK=true
    echo ""
    warn "Update failed — rolling back to $PREVIOUS_IMAGE"

    if restart_in_progress; then
        info "upgrade.sh is still restarting the containers; waiting for it to finish first"
        if ! wait_for_restart; then
            mark_snapshot failed
            echo -e "${RED}✗${NC} upgrade.sh is still running after ${HEALTH_TIMEOUT}s (log: $UPGRADE_LOG)." >&2
            echo -e "${RED}✗${NC} Not rolling back while it runs. Run this command again later to repair. Snapshot: $SNAPSHOT_DIR" >&2
            return
        fi
    fi

    echo ""
    if restore_snapshot; then
        mark_snapshot rolled-back
        echo -e "${YELLOW}⚠${NC} Rolled back to $PREVIOUS_IMAGE. Snapshot kept at $SNAPSHOT_DIR" >&2
    else
        mark_snapshot failed
        echo -e "${RED}✗${NC} Rollback incomplete. Run this command again to repair. Snapshot kept at $SNAPSHOT_DIR" >&2
    fi
}

# Repairs the server when an earlier run stopped half-way, so the same command can simply be re-run.
recover_unfinished_deploy() {
    local unfinished
    unfinished=$(unfinished_snapshot)
    [ -n "$unfinished" ] || return 0

    warn "The previous deploy ($unfinished) did not finish; checking the installation"
    SNAPSHOT_DIR="$unfinished"
    PREVIOUS_IMAGE=$(cat "$unfinished/previous-image" 2>/dev/null || echo none)

    UPGRADE_LOG=$(find "$SOURCE_DIR" -maxdepth 1 -name 'upgrade-*.log' -newer "$unfinished/previous-image" -mmin -30 2>/dev/null | sort | tail -1)
    if restart_in_progress; then
        info "Waiting for a running upgrade.sh restart to finish"
        wait_for_restart || fail "upgrade.sh is still restarting the containers (log: $UPGRADE_LOG). Run this command again in a few minutes."
    fi

    # Judge the database itself, not a stopped container
    if [ "$(docker inspect coolify-db --format '{{.State.Running}}' 2>/dev/null)" != "true" ]; then
        local tag
        tag=$(current_image)
        compose_up "$([ "$tag" = none ] && echo latest || echo "${tag##*:}")" postgres redis >/dev/null 2>&1 || true
    fi
    wait_for_database || true

    if database_complete "$unfinished"; then
        ok "Database is intact; no restore needed"
    else
        [ -f "$unfinished/coolify-db.sql.gz" ] || fail "The database is incomplete and $unfinished has no database dump to restore."
        warn "The database is incomplete; restoring the snapshot taken before that deploy"
        restore_snapshot || fail "Automatic repair failed. Snapshot kept at $unfinished"
        ok "Restored $PREVIOUS_IMAGE with its database"
    fi
    mark_snapshot recovered

    SNAPSHOT_DIR=""
    PREVIOUS_IMAGE=""
    UPGRADE_LOG=""
}

run_install() {
    info "Running fresh install of $VERSION"
    curl -fsSL "$CDN/scripts/install.sh" | COOLIFY_CDN="$CDN" REGISTRY_URL="$REGISTRY_URL" COOLIFY_IMAGE_NAMESPACE="$IMAGE_NAMESPACE" bash -s -- "$VERSION" ||
        fail "Install failed. See the installer output above."
}

run_update() {
    recover_unfinished_deploy
    preflight_update
    snapshot

    info "Updating to $VERSION ($REGISTRY_URL/$IMAGE_NAMESPACE)"
    curl -fsSL "$CDN/scripts/upgrade.sh" -o "$SOURCE_DIR/upgrade.sh" || fail "Could not download upgrade.sh from $CDN"
    # upgrade.sh also points .env at the registry/namespace/CDN given here
    local upgrade_ok=true
    COOLIFY_CDN="$CDN" bash "$SOURCE_DIR/upgrade.sh" "$VERSION" "$REGISTRY_URL" "$IMAGE_NAMESPACE" || upgrade_ok=false
    # This run's log is the one created after the snapshot
    UPGRADE_LOG=$(find "$SOURCE_DIR" -maxdepth 1 -name 'upgrade-*.log' -newer "$SNAPSHOT_DIR/previous-image" 2>/dev/null | sort | tail -1)
    if [ "$upgrade_ok" = "false" ]; then
        local log="$UPGRADE_LOG"
        [ -n "$log" ] && tail -n 30 "$log" >&2
        fail "upgrade.sh failed. Full log: ${log:-$SOURCE_DIR/upgrade-*.log}"
    fi

    info "Waiting for upgrade.sh to restart the containers (up to ${HEALTH_TIMEOUT}s)"
    wait_for_restart || fail "upgrade.sh did not finish restarting the containers within ${HEALTH_TIMEOUT}s. Log: $UPGRADE_LOG"
    ok "Containers restarted"
}

verify_deployment() {
    info "Waiting for coolify to become healthy (up to ${HEALTH_TIMEOUT}s)"
    local status
    status=$(wait_for_healthy) || fail "coolify is '$status' after ${HEALTH_TIMEOUT}s. Check: docker logs coolify"
    ok "coolify is healthy"

    local image
    image=$(current_image)
    [ "$image" = "$REGISTRY_URL/$IMAGE_NAMESPACE/coolify:$VERSION" ] || fail "coolify is running '$image', expected '$REGISTRY_URL/$IMAGE_NAMESPACE/coolify:$VERSION'"
    ok "Running $image"

    local php_version
    php_version=$(docker exec coolify php -r 'echo PHP_VERSION;')
    docker exec coolify php -r "exit(version_compare(PHP_VERSION, '$MIN_PHP_VERSION', '<') ? 1 : 0);" ||
        fail "coolify reports PHP $php_version, below the required $MIN_PHP_VERSION"
    ok "PHP $php_version (>= $MIN_PHP_VERSION)"
}

verify_migrations() {
    # The container's startup migration exits 0 even when a migration fails, so run it
    # again explicitly (a no-op when everything is applied) to surface any error.
    info "Verifying database migrations"
    docker exec coolify php artisan migrate --force --isolated --no-interaction ||
        fail "Database migrations failed (see output above)"
    docker exec coolify php artisan migrate:status --pending=1 --no-interaction >/dev/null ||
        fail "Database migrations are still pending after the update"
    ok "All database migrations applied"
}

if [ "$DRY_RUN" != "true" ] && [ "$EUID" -ne 0 ]; then
    fail "Please run as root (or with sudo)."
fi

VERSION=$(resolve_version "${1:-}")
[ -n "$VERSION" ] || fail "Could not determine the version to deploy from $CDN/versions.json"

MODE=$(detect_mode)
echo ""
echo "Coolify deploy — $REGISTRY_URL/$IMAGE_NAMESPACE"
echo "  Target version : $VERSION"
echo "  Current image  : $(current_image)"
echo "  Action         : $MODE"
[ "$MODE" = "update" ] && [ -n "$(unfinished_snapshot)" ] && echo "  Repair first   : $(unfinished_snapshot) did not finish"
echo ""

if [ "$DRY_RUN" = "true" ]; then
    ok "Dry run: no changes made"
    exit 0
fi

if [ "$MODE" = "install" ]; then
    run_install
else
    run_update
fi

verify_deployment
verify_migrations
echo ""
mark_snapshot done
ok "Coolify $VERSION deployed successfully"
[ -n "$SNAPSHOT_DIR" ] && info "Pre-update snapshot kept at $SNAPSHOT_DIR"
