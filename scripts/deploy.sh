#!/bin/bash
## Coolify (edesylabs fork) — one command for fresh install OR update.
##
## Usage (as root on the server):
##   curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/deploy.sh | bash
##   curl -fsSL .../scripts/deploy.sh | bash -s -- 4.1.0-beta.4     # specific version
##
## Detects whether Coolify is already installed:
##   - not installed -> runs scripts/install.sh
##   - installed     -> backs up the Coolify database, then runs scripts/upgrade.sh
## and finally verifies the coolify container is healthy, on the expected image and PHP version.
##
## Environment variables:
##   COOLIFY_CDN              Where scripts and compose files are fetched from (default: this repo's main branch)
##   COOLIFY_REGISTRY         Image registry (default: ghcr.io)
##   COOLIFY_IMAGE_NAMESPACE  Image namespace (default: edesylabs)
##   MIN_PHP_VERSION          Minimum PHP version the deployed image must report (default: 8.4.27)
##   SKIP_BACKUP=true         Update without taking a database backup first
##   DRY_RUN=true             Only print what would be done
##   COOLIFY_DATA_DIR         Coolify data directory, used for detection (default: /data/coolify)

set -e
set -o pipefail

CDN="${COOLIFY_CDN:-https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main}"
REGISTRY_URL="${COOLIFY_REGISTRY:-ghcr.io}"
IMAGE_NAMESPACE="${COOLIFY_IMAGE_NAMESPACE:-edesylabs}"
MIN_PHP_VERSION="${MIN_PHP_VERSION:-8.4.27}"
SKIP_BACKUP="${SKIP_BACKUP:-false}"
DRY_RUN="${DRY_RUN:-false}"
DATA_DIR="${COOLIFY_DATA_DIR:-/data/coolify}"
ENV_FILE="$DATA_DIR/source/.env"
HEALTH_TIMEOUT=180

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

set_env_var() {
    local key="$1"
    local value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
    fi
}

backup_database() {
    local backup_dir="$DATA_DIR/backups/deploy"
    local backup_file="$backup_dir/coolify-db-$(date +%Y%m%d-%H%M%S).sql.gz"
    mkdir -p "$backup_dir"
    info "Backing up Coolify database to $backup_file"
    if ! docker exec coolify-db pg_dump -U coolify coolify | gzip >"$backup_file" || [ ! -s "$backup_file" ]; then
        rm -f "$backup_file"
        fail "Database backup failed. Fix coolify-db or re-run with SKIP_BACKUP=true to update without a backup."
    fi
    ok "Database backed up ($(du -h "$backup_file" | cut -f1))"
}

run_install() {
    info "Running fresh install of $VERSION"
    curl -fsSL "$CDN/scripts/install.sh" | COOLIFY_CDN="$CDN" REGISTRY_URL="$REGISTRY_URL" COOLIFY_IMAGE_NAMESPACE="$IMAGE_NAMESPACE" bash -s -- "$VERSION" ||
        fail "Install failed. See the installer output above."
}

run_update() {
    if [ "$SKIP_BACKUP" = "true" ]; then
        warn "Skipping database backup (SKIP_BACKUP=true)"
    else
        backup_database
    fi

    info "Pointing configuration at $REGISTRY_URL/$IMAGE_NAMESPACE"
    set_env_var "REGISTRY_URL" "$REGISTRY_URL"
    set_env_var "COOLIFY_IMAGE_NAMESPACE" "$IMAGE_NAMESPACE"
    set_env_var "COOLIFY_CDN" "$CDN"
    set_env_var "HELPER_IMAGE" "$REGISTRY_URL/$IMAGE_NAMESPACE/coolify-helper"
    set_env_var "REALTIME_IMAGE" "$REGISTRY_URL/$IMAGE_NAMESPACE/coolify-realtime"

    info "Updating to $VERSION"
    curl -fsSL "$CDN/scripts/upgrade.sh" -o "$DATA_DIR/source/upgrade.sh"
    if ! bash "$DATA_DIR/source/upgrade.sh" "$VERSION" "$REGISTRY_URL" "$IMAGE_NAMESPACE"; then
        local log
        log=$(ls -t "$DATA_DIR"/source/upgrade-*.log 2>/dev/null | head -1 || true)
        [ -n "$log" ] && tail -n 30 "$log" >&2
        fail "Update failed. Full log: ${log:-$DATA_DIR/source/upgrade-*.log}"
    fi
}

verify_deployment() {
    info "Waiting for coolify to become healthy (up to ${HEALTH_TIMEOUT}s)"
    local waited=0 status=""
    while [ "$waited" -lt "$HEALTH_TIMEOUT" ]; do
        status=$(docker inspect coolify --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' 2>/dev/null || echo "missing")
        [ "$status" = "healthy" ] && break
        sleep 5
        waited=$((waited + 5))
    done
    [ "$status" = "healthy" ] || fail "coolify is '$status' after ${HEALTH_TIMEOUT}s. Check: docker logs coolify"
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
echo ""
ok "Coolify $VERSION deployed successfully"
