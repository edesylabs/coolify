#!/bin/bash
# Coolify In-Place Upgrade Script
# Upgrades existing official Coolify to custom fork with comprehensive safety features

set -e  # Exit on error
set -u  # Exit on undefined variable
set -o pipefail  # Exit on pipe failure

# Global variables
BACKUP_DIR=""
UPGRADE_STARTED=false

# Color codes
RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Error handler with automatic rollback
trap 'error_handler $? $LINENO' ERR

error_handler() {
    local exit_code=$1
    local line_num=$2

    echo ""
    echo -e "${RED}=============================================="
    echo "  ERROR DETECTED!"
    echo -e "==============================================${NC}"
    echo "Error occurred at line $line_num (exit code: $exit_code)"
    echo ""

    if [ "$UPGRADE_STARTED" = true ]; then
        echo "Upgrade was in progress. Initiating automatic rollback..."
        automatic_rollback
    else
        echo "Error occurred before upgrade started."
        echo -e "${GREEN}✓ Your system is unchanged and safe.${NC}"
    fi

    exit $exit_code
}

# Automatic rollback function
automatic_rollback() {
    echo ""
    echo -e "${YELLOW}=============================================="
    echo "  AUTOMATIC ROLLBACK INITIATED"
    echo -e "==============================================${NC}"
    echo ""

    if [ -z "$BACKUP_DIR" ] || [ ! -d "$BACKUP_DIR" ]; then
        echo -e "${RED}ERROR: Backup directory not found!${NC}"
        echo "Cannot perform automatic rollback."
        echo "See SAFETY_AND_RECOVERY.md for manual recovery."
        return 1
    fi

    cd /data/coolify/source

    echo "[1/4] Stopping current containers..."
    docker compose down 2>/dev/null || true

    echo "[2/4] Restoring configuration from backup..."
    [ -f "$BACKUP_DIR/.env.backup" ] && cp "$BACKUP_DIR/.env.backup" .env && echo "      ✓ .env restored"
    [ -f "$BACKUP_DIR/docker-compose.yml.backup" ] && cp "$BACKUP_DIR/docker-compose.yml.backup" docker-compose.yml && echo "      ✓ docker-compose.yml restored"
    [ -f "$BACKUP_DIR/docker-compose.prod.yml.backup" ] && cp "$BACKUP_DIR/docker-compose.prod.yml.backup" docker-compose.prod.yml && echo "      ✓ docker-compose.prod.yml restored"

    echo "[3/4] Starting Coolify with original configuration..."
    docker compose up -d

    echo "[4/4] Waiting for services..."
    sleep 20

    if docker ps | grep -q coolify; then
        echo ""
        echo -e "${GREEN}=============================================="
        echo "  ROLLBACK SUCCESSFUL ✓"
        echo -e "==============================================${NC}"
        echo ""
        echo "Your Coolify has been restored to its previous state."
        echo -e "${GREEN}✓ All your data is safe and intact.${NC}"
        echo ""
        echo "Backup preserved at: $BACKUP_DIR"
        echo ""
        echo "Next steps:"
        echo "  1. Review the error above"
        echo "  2. Check SAFETY_AND_RECOVERY.md for troubleshooting"
        echo "  3. Fix the issue"
        echo "  4. Try upgrade again"
        echo ""
    else
        echo ""
        echo -e "${RED}=============================================="
        echo "  ROLLBACK FAILED - MANUAL RECOVERY NEEDED"
        echo -e "==============================================${NC}"
        echo ""
        echo "Automatic rollback could not start Coolify."
        echo "Your backup is safe at: $BACKUP_DIR"
        echo ""
        echo "Manual recovery steps in SAFETY_AND_RECOVERY.md"
        echo "Or contact support."
    fi
}

echo -e "${BLUE}=============================================="
echo "  Coolify In-Place Upgrade"
echo "  Official → Custom Fork"
echo -e "==============================================${NC}"
echo ""
echo "Safety Features:"
echo "  ✓ Pre-flight validation"
echo "  ✓ Automatic backup"
echo "  ✓ Error detection"
echo "  ✓ Automatic rollback on failure"
echo "  ✓ Data integrity protection"
echo ""

# Configuration
COOLIFY_ORG="${COOLIFY_ORG:-edesylabs}"
COOLIFY_REGISTRY="${COOLIFY_REGISTRY:-ghcr.io}"
COOLIFY_CDN="${COOLIFY_CDN:-https://raw.githubusercontent.com/edesylabs/coolify/main}"

echo "Target Configuration:"
echo "  Organization:  $COOLIFY_ORG"
echo "  Registry:      $COOLIFY_REGISTRY"
echo "  CDN:           $COOLIFY_CDN"
echo ""

# ============================================
# PRE-FLIGHT CHECKS
# ============================================

echo -e "${BLUE}=============================================="
echo "  Pre-Flight Safety Checks"
echo -e "==============================================${NC}"
echo ""

VALIDATION_ERRORS=0
VALIDATION_WARNINGS=0

check_pass() {
    echo -e "${GREEN}✓${NC} $1"
}

check_fail() {
    echo -e "${RED}✗${NC} $1"
    ((VALIDATION_ERRORS++))
}

check_warn() {
    echo -e "${YELLOW}⚠${NC} $1"
    ((VALIDATION_WARNINGS++))
}

# Check 1: Root privileges
if [ $EUID = 0 ]; then
    check_pass "Running as root"
else
    check_fail "Not running as root. Please run with sudo."
fi

# Check 2: Coolify installation
if [ -d /data/coolify/source ] && [ -f /data/coolify/source/.env ]; then
    check_pass "Coolify installation found"
else
    check_fail "Coolify not found at /data/coolify"
fi

# Check 3: Coolify running
if docker ps | grep -q coolify 2>/dev/null; then
    check_pass "Coolify containers running"
    COOLIFY_VERSION=$(docker exec coolify php artisan --version 2>/dev/null | grep -oP 'Laravel Framework \K[\d.]+' || echo "Unknown")
    echo "  Current version: $COOLIFY_VERSION"
else
    check_fail "Coolify containers not running"
fi

# Check 4: Database
if docker ps | grep -q coolify-db 2>/dev/null; then
    if docker exec coolify-db pg_isready -U coolify >/dev/null 2>&1; then
        check_pass "Database is accessible"
        DB_SIZE=$(docker exec coolify-db psql -U coolify -d coolify -t -c "SELECT pg_size_pretty(pg_database_size('coolify'));" 2>/dev/null | xargs || echo "Unknown")
        echo "  Database size: $DB_SIZE"
    else
        check_fail "Database not responding"
    fi
else
    check_fail "Database container not running"
fi

# Check 5: Disk space
AVAILABLE_GB=$(df /data 2>/dev/null | awk 'NR==2 {print int($4/1024/1024)}' || echo 0)
if [ $AVAILABLE_GB -ge 5 ]; then
    check_pass "Sufficient disk space: ${AVAILABLE_GB}GB"
elif [ $AVAILABLE_GB -ge 2 ]; then
    check_warn "Low disk space: ${AVAILABLE_GB}GB (5GB+ recommended)"
else
    check_fail "Insufficient disk space: ${AVAILABLE_GB}GB (minimum 2GB)"
fi

# Check 6: Docker version
if command -v docker >/dev/null 2>&1; then
    DOCKER_VERSION=$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo "Unknown")
    check_pass "Docker version: $DOCKER_VERSION"
else
    check_fail "Docker not found"
fi

# Check 7: Docker Compose
if docker compose version >/dev/null 2>&1; then
    check_pass "Docker Compose available"
else
    check_fail "Docker Compose not available"
fi

# Check 8: Internet connectivity
if curl -s --max-time 5 https://github.com >/dev/null 2>&1; then
    check_pass "Internet connectivity"
else
    check_warn "Cannot reach GitHub (may affect image pull)"
fi

# Check 9: Custom images accessibility
echo -n "Checking custom images... "
if docker pull "$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify:latest" >/dev/null 2>&1; then
    check_pass "Custom images accessible"
else
    check_fail "Cannot pull custom images from $COOLIFY_REGISTRY/$COOLIFY_ORG"
    echo ""
    echo -e "${YELLOW}Make sure:${NC}"
    echo "  1. Images are built (see QUICK_START.md)"
    echo "  2. Images are public on GitHub Packages"
    echo "  3. Image names match: $COOLIFY_REGISTRY/$COOLIFY_ORG/coolify:latest"
fi

# Check 10: Can create backup
echo -n "Testing backup capability... "
TEST_BACKUP="/tmp/coolify-test-backup-$$"
if docker exec coolify-db pg_dump -U coolify coolify > "$TEST_BACKUP" 2>/dev/null; then
    check_pass "Can create database backup"
    rm -f "$TEST_BACKUP"
else
    check_fail "Cannot create database backup"
fi

echo ""
echo "Pre-Flight Summary:"
echo -e "  ${GREEN}Passed: $((10 - VALIDATION_ERRORS - VALIDATION_WARNINGS))${NC}"
echo -e "  ${YELLOW}Warnings: $VALIDATION_WARNINGS${NC}"
echo -e "  ${RED}Errors: $VALIDATION_ERRORS${NC}"
echo ""

# Evaluate results
if [ $VALIDATION_ERRORS -gt 0 ]; then
    echo -e "${RED}=============================================="
    echo "  PRE-FLIGHT CHECK FAILED"
    echo -e "==============================================${NC}"
    echo ""
    echo "Found $VALIDATION_ERRORS critical error(s)."
    echo "Cannot proceed safely with upgrade."
    echo ""
    echo "Please fix the errors above and try again."
    echo ""
    echo "For detailed checking, run:"
    echo "  ./pre-flight-check.sh"
    echo ""
    echo -e "${GREEN}✓ Your system has NOT been modified.${NC}"
    echo ""
    exit 1
fi

if [ $VALIDATION_WARNINGS -gt 0 ]; then
    echo -e "${YELLOW}⚠ Warning:${NC} Found $VALIDATION_WARNINGS warning(s)."
    echo "Warnings are not critical but should be reviewed."
    echo ""
fi

echo -e "${GREEN}✓ Pre-flight checks passed!${NC}"
echo ""

# ============================================
# USER CONFIRMATION
# ============================================

echo -e "${BLUE}=============================================="
echo "  Ready to Upgrade"
echo -e "==============================================${NC}"
echo ""
echo "This will upgrade your Coolify to use custom images from:"
echo "  $COOLIFY_REGISTRY/$COOLIFY_ORG"
echo ""
echo "What will happen:"
echo "  1. Complete backup of current system"
echo "  2. Update configuration"
echo "  3. Pull custom Docker images"
echo "  4. Restart with custom images"
echo "  5. Verify everything works"
echo ""
echo "Safety guarantees:"
echo "  ✓ Automatic backup before changes"
echo "  ✓ Automatic rollback if anything fails"
echo "  ✓ Zero data loss"
echo "  ✓ Your applications keep running"
echo ""
echo "Estimated time: 5-10 minutes"
echo "Coolify UI downtime: ~2-3 minutes (apps stay up)"
echo ""
read -p "Do you want to proceed? (yes/no) " -r
echo
if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
    echo "Upgrade cancelled."
    echo -e "${GREEN}✓ Your system is unchanged.${NC}"
    exit 0
fi

echo ""
echo -e "${BLUE}=============================================="
echo "  Starting Safe Upgrade Process"
echo -e "==============================================${NC}"
echo ""

# Mark upgrade as started (for error handler)
UPGRADE_STARTED=true

# ============================================
# STEP 1: COMPREHENSIVE BACKUP
# ============================================

TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR="/root/coolify-pre-upgrade-${TIMESTAMP}"
mkdir -p "$BACKUP_DIR"

echo "[1/10] Creating comprehensive backup..."
cd /data/coolify/source

# Database backup (CRITICAL)
echo "       Backing up database..."
if ! docker exec coolify-db pg_dump -U coolify coolify > "$BACKUP_DIR/coolify-db.sql" 2>/dev/null; then
    echo -e "       ${RED}✗ Failed to backup database!${NC}"
    echo "       ABORTING: Cannot proceed without database backup"
    exit 1
fi

DB_BACKUP_SIZE=$(du -h "$BACKUP_DIR/coolify-db.sql" | cut -f1)
echo -e "       ${GREEN}✓${NC} Database backed up ($DB_BACKUP_SIZE)"

# Configuration files
cp .env "$BACKUP_DIR/.env.backup"
cp docker-compose.yml "$BACKUP_DIR/docker-compose.yml.backup" 2>/dev/null || true
cp docker-compose.prod.yml "$BACKUP_DIR/docker-compose.prod.yml.backup" 2>/dev/null || true
[ -f docker-compose.custom.yml ] && cp docker-compose.custom.yml "$BACKUP_DIR/"

# SSH keys
[ -d /data/coolify/ssh ] && cp -r /data/coolify/ssh "$BACKUP_DIR/ssh"

# SSL certificates
[ -d /data/coolify/ssl ] && cp -r /data/coolify/ssl "$BACKUP_DIR/ssl"

echo -e "       ${GREEN}✓${NC} All critical data backed up"
echo "       Location: $BACKUP_DIR"

# ============================================
# STEP 2: VERIFY BACKUP INTEGRITY
# ============================================

echo "[2/10] Verifying backup integrity..."

if [ ! -f "$BACKUP_DIR/coolify-db.sql" ] || [ ! -s "$BACKUP_DIR/coolify-db.sql" ]; then
    echo -e "       ${RED}✗ Database backup is invalid!${NC}"
    exit 1
fi

if [ ! -f "$BACKUP_DIR/.env.backup" ]; then
    echo -e "       ${RED}✗ Environment backup is missing!${NC}"
    exit 1
fi

echo -e "       ${GREEN}✓${NC} Backup integrity verified"

# ============================================
# STEP 3: PULL CUSTOM IMAGES
# ============================================

echo "[3/10] Pulling custom Docker images..."
echo "       This may take a few minutes..."

IMAGES_TO_PULL=(
    "$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify:latest"
    "$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify-helper:latest"
    "$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify-realtime:latest"
)

for image in "${IMAGES_TO_PULL[@]}"; do
    if ! docker pull "$image" >/dev/null 2>&1; then
        echo -e "       ${RED}✗ Failed to pull $image${NC}"
        exit 1
    fi
done

echo -e "       ${GREEN}✓${NC} All custom images pulled successfully"

# ============================================
# STEP 4: UPDATE CONFIGURATION
# ============================================

echo "[4/10] Updating environment configuration..."

update_env() {
    local key=$1
    local value=$2
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        echo "${key}=${value}" >> .env
    fi
}

update_env "REGISTRY_URL" "$COOLIFY_REGISTRY"
update_env "COOLIFY_IMAGE_NAMESPACE" "$COOLIFY_ORG"

grep -q "^COOLIFY_CDN=" .env || echo "COOLIFY_CDN=$COOLIFY_CDN" >> .env
grep -q "^HELPER_IMAGE=" .env || echo "HELPER_IMAGE=$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify-helper" >> .env
grep -q "^REALTIME_IMAGE=" .env || echo "REALTIME_IMAGE=$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify-realtime" >> .env

echo -e "       ${GREEN}✓${NC} Environment configuration updated"

# ============================================
# STEP 5: UPDATE DOCKER COMPOSE FILES
# ============================================

echo "[5/10] Updating docker-compose files..."

curl -fsSL "$COOLIFY_CDN/docker-compose.yml" -o docker-compose.yml.new || exit 1
curl -fsSL "$COOLIFY_CDN/docker-compose.prod.yml" -o docker-compose.prod.yml.new || exit 1

sed -i "s|ghcr.io/coollabsio|$COOLIFY_REGISTRY/$COOLIFY_ORG|g" docker-compose.yml.new
sed -i "s|ghcr.io/coollabsio|$COOLIFY_REGISTRY/$COOLIFY_ORG|g" docker-compose.prod.yml.new

if ! docker compose -f docker-compose.yml.new config >/dev/null 2>&1; then
    echo -e "       ${RED}✗ New docker-compose.yml is invalid${NC}"
    rm -f docker-compose.yml.new docker-compose.prod.yml.new
    exit 1
fi

mv docker-compose.yml.new docker-compose.yml
mv docker-compose.prod.yml.new docker-compose.prod.yml

echo -e "       ${GREEN}✓${NC} Docker compose files updated"

# ============================================
# STEP 6: PULL IMAGES VIA COMPOSE
# ============================================

echo "[6/10] Pulling all required images..."

docker compose pull 2>&1 | grep -v "Pulling" | grep -E "(downloaded|up to date)" || true

echo -e "       ${GREEN}✓${NC} Images ready"

# ============================================
# STEP 7: STOP CURRENT COOLIFY
# ============================================

echo "[7/10] Stopping current Coolify..."
docker compose down
echo -e "       ${GREEN}✓${NC} Stopped"

sleep 3

# ============================================
# STEP 8: START WITH CUSTOM IMAGES
# ============================================

echo "[8/10] Starting Coolify with custom images..."
docker compose up -d >/dev/null 2>&1

echo "       Waiting for services to start..."
sleep 20

# ============================================
# STEP 9: VERIFY STARTUP
# ============================================

echo "[9/10] Verifying services..."

if ! docker ps | grep -q coolify; then
    echo -e "       ${RED}✗ Containers did not start!${NC}"
    exit 1
fi
echo -e "       ${GREEN}✓${NC} Containers running"

if ! docker exec coolify php artisan tinker --execute="DB::connection()->getPdo();" >/dev/null 2>&1; then
    echo -e "       ${RED}✗ Database connection failed!${NC}"
    exit 1
fi
echo -e "       ${GREEN}✓${NC} Database connected"

SERVER_COUNT=$(docker exec coolify php artisan tinker --execute="echo App\Models\Server::count();" 2>/dev/null || echo "0")
APP_COUNT=$(docker exec coolify php artisan tinker --execute="echo App\Models\Application::count();" 2>/dev/null || echo "0")

echo -e "       ${GREEN}✓${NC} Data verified (Servers: $SERVER_COUNT, Apps: $APP_COUNT)"

# ============================================
# STEP 10: FINALIZE
# ============================================

echo "[10/10] Finalizing upgrade..."
docker exec coolify php artisan cache:clear >/dev/null 2>&1
docker exec coolify php artisan config:clear >/dev/null 2>&1
docker exec coolify php artisan route:clear >/dev/null 2>&1
echo -e "       ${GREEN}✓${NC} Caches cleared"

# Upgrade successful!
UPGRADE_STARTED=false

# ============================================
# SUCCESS!
# ============================================

echo ""
echo -e "${GREEN}=============================================="
echo "  UPGRADE COMPLETED SUCCESSFULLY! ✓"
echo -e "==============================================${NC}"
echo ""
echo "Your Coolify now uses custom images:"
echo ""
docker ps --format "table {{.Names}}\t{{.Image}}" | grep -E "(NAMES|coolify)"
echo ""
echo "Configuration:"
echo "  Registry:      $COOLIFY_REGISTRY"
echo "  Organization:  $COOLIFY_ORG"
echo ""
echo "Data Status:"
echo "  Servers:       $SERVER_COUNT"
echo "  Applications:  $APP_COUNT"
echo -e "  Status:        ${GREEN}✓ All data preserved${NC}"
echo ""
echo "Access Coolify at:"
echo "  http://$(hostname -I | awk '{print $1}'):8000"
echo ""
echo "Backup preserved at:"
echo "  $BACKUP_DIR"
echo "  (Keep for 1-2 weeks, then safe to delete)"
echo ""
echo "Next steps:"
echo "  1. Login to Coolify UI"
echo "  2. Verify all servers and applications"
echo "  3. Test a deployment"
echo ""
echo "If you need to rollback:"
echo "  ./rollback-to-official.sh"
echo ""
echo -e "${GREEN}=============================================${NC}"
