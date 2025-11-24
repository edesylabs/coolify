#!/bin/bash
# Pre-Flight Safety Check for Coolify Migration/Upgrade
# Run this BEFORE upgrading to verify everything is ready

set -e

echo "=============================================="
echo "  Coolify Pre-Flight Safety Check"
echo "=============================================="
echo ""
echo "This script validates your environment before"
echo "upgrading to custom Coolify."
echo ""

ERRORS=0
WARNINGS=0
CHECKS_PASSED=0

# Color codes for output
RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

check_passed() {
    echo -e "${GREEN}✓${NC} $1"
    ((CHECKS_PASSED++))
}

check_failed() {
    echo -e "${RED}✗${NC} $1"
    ((ERRORS++))
}

check_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
    ((WARNINGS++))
}

echo "Running safety checks..."
echo ""

# Check 1: Running as root
echo -n "Checking root privileges... "
if [ $EUID = 0 ]; then
    check_passed "Running as root"
else
    check_failed "Not running as root. Please run with sudo."
fi

# Check 2: Coolify installation exists
echo -n "Checking Coolify installation... "
if [ -d /data/coolify/source ] && [ -f /data/coolify/source/.env ]; then
    check_passed "Coolify installation found"
else
    check_failed "Coolify not found at /data/coolify"
fi

# Check 3: Coolify is running
echo -n "Checking Coolify status... "
if docker ps | grep -q coolify; then
    check_passed "Coolify containers are running"

    # Get current version
    COOLIFY_VERSION=$(docker exec coolify php artisan --version 2>/dev/null | grep -oP 'Laravel Framework \K[\d.]+' || echo "Unknown")
    echo "  Current version: $COOLIFY_VERSION"
else
    check_warning "Coolify containers not running"
fi

# Check 4: Database accessibility
echo -n "Checking database... "
if docker ps | grep -q coolify-db; then
    if docker exec coolify-db pg_isready -U coolify >/dev/null 2>&1; then
        check_passed "Database is accessible"

        # Check database size
        DB_SIZE=$(docker exec coolify-db psql -U coolify -d coolify -t -c "SELECT pg_size_pretty(pg_database_size('coolify'));" 2>/dev/null | xargs || echo "Unknown")
        echo "  Database size: $DB_SIZE"

        # Count records
        SERVER_COUNT=$(docker exec coolify php artisan tinker --execute="echo App\Models\Server::count();" 2>/dev/null || echo "0")
        APP_COUNT=$(docker exec coolify php artisan tinker --execute="echo App\Models\Application::count();" 2>/dev/null || echo "0")
        echo "  Servers: $SERVER_COUNT, Applications: $APP_COUNT"
    else
        check_failed "Database is not responding"
    fi
else
    check_failed "Database container not running"
fi

# Check 5: Disk space
echo -n "Checking disk space... "
AVAILABLE_SPACE=$(df /data | awk 'NR==2 {print $4}')
AVAILABLE_GB=$((AVAILABLE_SPACE / 1024 / 1024))

if [ $AVAILABLE_GB -gt 5 ]; then
    check_passed "Sufficient disk space: ${AVAILABLE_GB}GB available"
elif [ $AVAILABLE_GB -gt 2 ]; then
    check_warning "Low disk space: ${AVAILABLE_GB}GB available (minimum 5GB recommended)"
else
    check_failed "Insufficient disk space: ${AVAILABLE_GB}GB available (minimum 2GB required)"
fi

# Check 6: Docker version
echo -n "Checking Docker version... "
if command -v docker >/dev/null 2>&1; then
    DOCKER_VERSION=$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo "Unknown")
    DOCKER_MAJOR=$(echo $DOCKER_VERSION | cut -d. -f1)

    if [ "$DOCKER_MAJOR" -ge 20 ]; then
        check_passed "Docker version: $DOCKER_VERSION"
    else
        check_warning "Docker version $DOCKER_VERSION (20.0+ recommended)"
    fi
else
    check_failed "Docker not found"
fi

# Check 7: Docker Compose version
echo -n "Checking Docker Compose... "
if docker compose version >/dev/null 2>&1; then
    COMPOSE_VERSION=$(docker compose version --short 2>/dev/null || echo "Unknown")
    check_passed "Docker Compose available: $COMPOSE_VERSION"
else
    check_failed "Docker Compose not available"
fi

# Check 8: Network connectivity
echo -n "Checking internet connectivity... "
if curl -s --max-time 5 https://github.com >/dev/null 2>&1; then
    check_passed "Internet connection working"
else
    check_warning "Cannot reach GitHub (may affect image pull)"
fi

# Check 9: Custom images accessibility
echo -n "Checking custom Docker images... "
COOLIFY_ORG="${COOLIFY_ORG:-edesylabs}"
COOLIFY_REGISTRY="${COOLIFY_REGISTRY:-ghcr.io}"

if docker pull "$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify:latest" >/dev/null 2>&1; then
    check_passed "Custom images are accessible"

    # Get image size
    IMAGE_SIZE=$(docker images "$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify" --format "{{.Size}}" | head -1)
    echo "  Image size: $IMAGE_SIZE"
else
    check_failed "Cannot pull custom images from $COOLIFY_REGISTRY/$COOLIFY_ORG"
    echo "  Make sure images are built and public!"
    echo "  See QUICK_START.md for building images"
fi

# Check 10: Backup directory space
echo -n "Checking backup directory... "
BACKUP_SPACE=$(df /root | awk 'NR==2 {print $4}')
BACKUP_GB=$((BACKUP_SPACE / 1024 / 1024))

if [ $BACKUP_GB -gt 2 ]; then
    check_passed "Backup space available: ${BACKUP_GB}GB"
else
    check_warning "Limited backup space: ${BACKUP_GB}GB (2GB+ recommended)"
fi

# Check 11: Existing backups
echo -n "Checking for existing backups... "
BACKUP_COUNT=$(ls -1 /root/coolify-*backup* /root/coolify-*migration* 2>/dev/null | wc -l || echo 0)
if [ $BACKUP_COUNT -gt 0 ]; then
    check_passed "Found $BACKUP_COUNT existing backup(s)"
    echo "  Latest: $(ls -1t /root/coolify-*backup* /root/coolify-*migration* 2>/dev/null | head -1)"
else
    check_warning "No existing backups found"
fi

# Check 12: Required commands
echo -n "Checking required commands... "
MISSING_CMDS=""
for cmd in curl wget tar gzip docker; do
    if ! command -v $cmd >/dev/null 2>&1; then
        MISSING_CMDS="$MISSING_CMDS $cmd"
    fi
done

if [ -z "$MISSING_CMDS" ]; then
    check_passed "All required commands available"
else
    check_failed "Missing commands:$MISSING_CMDS"
fi

# Check 13: Port availability (if installing new)
if [ ! -f /data/coolify/source/.env ]; then
    echo -n "Checking port 8000... "
    if ! netstat -tuln 2>/dev/null | grep -q ":8000 "; then
        check_passed "Port 8000 is available"
    else
        check_warning "Port 8000 is in use"
    fi
fi

# Check 14: SSH keys
echo -n "Checking SSH keys... "
if [ -d /data/coolify/ssh/keys ]; then
    KEY_COUNT=$(find /data/coolify/ssh/keys -type f | wc -l)
    if [ $KEY_COUNT -gt 0 ]; then
        check_passed "Found $KEY_COUNT SSH key(s)"
    else
        check_warning "No SSH keys found"
    fi
else
    check_warning "SSH keys directory not found"
fi

# Check 15: Environment file validity
echo -n "Checking environment configuration... "
if [ -f /data/coolify/source/.env ]; then
    if grep -q "APP_KEY=" /data/coolify/source/.env && \
       grep -q "DB_PASSWORD=" /data/coolify/source/.env; then
        check_passed "Environment file is valid"
    else
        check_warning "Environment file may be incomplete"
    fi
fi

# Check 16: Can create backup
echo -n "Testing backup capability... "
TEST_BACKUP="/tmp/coolify-test-backup-$$"
if docker exec coolify-db pg_dump -U coolify coolify > "$TEST_BACKUP" 2>/dev/null; then
    BACKUP_SIZE=$(du -h "$TEST_BACKUP" | cut -f1)
    check_passed "Can create database backup ($BACKUP_SIZE)"
    rm -f "$TEST_BACKUP"
else
    check_failed "Cannot create database backup"
fi

# Check 17: Previous upgrade attempts
echo -n "Checking for previous upgrade attempts... "
PREV_BACKUPS=$(ls -1d /root/coolify-pre-upgrade-* 2>/dev/null | wc -l || echo 0)
if [ $PREV_BACKUPS -gt 0 ]; then
    check_warning "Found $PREV_BACKUPS previous upgrade attempt(s)"
    echo "  Latest: $(ls -1td /root/coolify-pre-upgrade-* 2>/dev/null | head -1)"
    echo "  You can rollback using: ./rollback-to-official.sh"
else
    check_passed "No previous upgrade attempts found"
fi

# Summary
echo ""
echo "=============================================="
echo "  Pre-Flight Check Summary"
echo "=============================================="
echo ""
echo -e "${GREEN}Checks Passed: $CHECKS_PASSED${NC}"
echo -e "${YELLOW}Warnings: $WARNINGS${NC}"
echo -e "${RED}Errors: $ERRORS${NC}"
echo ""

# Risk assessment
if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "${GREEN}✓ System Status: EXCELLENT${NC}"
    echo "  All checks passed! Safe to proceed with upgrade."
    RISK="LOW"
elif [ $ERRORS -eq 0 ] && [ $WARNINGS -le 2 ]; then
    echo -e "${YELLOW}⚠ System Status: GOOD${NC}"
    echo "  Minor warnings found. Review warnings before proceeding."
    RISK="LOW-MEDIUM"
elif [ $ERRORS -eq 0 ]; then
    echo -e "${YELLOW}⚠ System Status: FAIR${NC}"
    echo "  Several warnings found. Consider addressing them first."
    RISK="MEDIUM"
elif [ $ERRORS -le 2 ]; then
    echo -e "${RED}✗ System Status: POOR${NC}"
    echo "  Critical errors found! Address errors before proceeding."
    RISK="HIGH"
else
    echo -e "${RED}✗ System Status: CRITICAL${NC}"
    echo "  Multiple critical errors! DO NOT proceed with upgrade."
    RISK="CRITICAL"
fi

echo ""
echo "Risk Assessment: $RISK"
echo ""

# Recommendations
if [ $ERRORS -gt 0 ]; then
    echo "⚠ RECOMMENDATION: Fix all errors before proceeding"
    echo ""
    echo "Critical issues must be resolved:"
    echo "  - Fix any failed checks marked with ✗"
    echo "  - Ensure Coolify is running properly"
    echo "  - Verify custom images are accessible"
    echo ""
    EXIT_CODE=1
else
    echo "✓ RECOMMENDATION: Safe to proceed"
    echo ""
    echo "Your next steps:"
    echo ""
    echo "  For in-place upgrade (same server):"
    echo "    ./upgrade-to-custom.sh"
    echo ""
    echo "  For new server migration:"
    echo "    ./backup-for-migration.sh"
    echo ""
    echo "  To review plans:"
    echo "    cat MIGRATION_COMPARISON.md"
    echo ""
    EXIT_CODE=0
fi

echo "=============================================="

# Save report
REPORT_FILE="/root/coolify-preflight-$(date +%Y%m%d-%H%M%S).txt"
{
    echo "Coolify Pre-Flight Check Report"
    echo "Generated: $(date)"
    echo ""
    echo "Checks Passed: $CHECKS_PASSED"
    echo "Warnings: $WARNINGS"
    echo "Errors: $ERRORS"
    echo "Risk Level: $RISK"
} > "$REPORT_FILE"

echo ""
echo "Report saved to: $REPORT_FILE"

exit $EXIT_CODE
