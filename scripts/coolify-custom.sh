#!/bin/bash
# Coolify Custom Deployment - All-in-One Script
# Handles: Fresh Install | In-Place Upgrade | Migration | Rollback | Pre-Flight Check

set -eE
set -u
set -o pipefail

# Enable command tracing for debugging (comment out in production)
# set -x

# ============================================
# CONFIGURATION
# ============================================

COOLIFY_ORG="${COOLIFY_ORG:-edesylabs}"
COOLIFY_REGISTRY="${COOLIFY_REGISTRY:-ghcr.io}"
COOLIFY_CDN="${COOLIFY_CDN:-https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main}"

# Colors
RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Global state
BACKUP_DIR=""
OPERATION_STARTED=false
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ============================================
# ERROR HANDLING
# ============================================

# Last command tracking for better error messages
LAST_COMMAND=""
CURRENT_STEP=""
trap 'LAST_COMMAND=$BASH_COMMAND' DEBUG
trap 'error_handler $? $LINENO "$LAST_COMMAND"' ERR
trap 'exit_handler $?' EXIT

exit_handler() {
    local exit_code=$1

    # Only show this for non-zero exits that weren't already handled by error_handler
    if [ $exit_code -ne 0 ] && [ -z "${ERROR_HANDLED:-}" ]; then
        echo ""
        echo -e "${RED}Script terminated unexpectedly!${NC}"
        if [ -n "$CURRENT_STEP" ]; then
            echo "Was executing: $CURRENT_STEP"
        fi
        echo "Last command: $LAST_COMMAND"
        echo "Exit code: $exit_code"
    fi
}

error_handler() {
    local exit_code=$1
    local line_num=$2
    local last_cmd="${3:-unknown command}"

    # Mark error as handled to prevent duplicate messages
    ERROR_HANDLED=true

    echo ""
    echo -e "${RED}=============================================="
    echo "  ERROR!"
    echo -e "==============================================${NC}"
    if [ -n "$CURRENT_STEP" ]; then
        echo "Failed step: $CURRENT_STEP"
    fi
    echo "Exit code: $exit_code"
    echo "Line number: $line_num"
    echo "Failed command: $last_cmd"
    echo ""

    # Show helpful context based on the command that failed
    if [[ "$last_cmd" == *"curl"* ]]; then
        echo -e "${YELLOW}Network error: Failed to download file${NC}"
        echo "Check your internet connection and CDN URL"
    elif [[ "$last_cmd" == *"docker"* ]]; then
        echo -e "${YELLOW}Docker error: Command failed${NC}"
        echo "Check Docker logs: docker logs coolify"
    fi
    echo ""

    if [ "$OPERATION_STARTED" = true ] && [ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ]; then
        echo "Attempting automatic rollback..."
        automatic_rollback
    else
        echo -e "${GREEN}✓ No changes were made to your system.${NC}"
    fi

    exit $exit_code
}

automatic_rollback() {
    echo ""
    echo -e "${YELLOW}Automatic Rollback Initiated...${NC}"

    cd /data/coolify/source 2>/dev/null || return 1

    docker compose down 2>/dev/null || true

    [ -f "$BACKUP_DIR/.env.backup" ] && cp "$BACKUP_DIR/.env.backup" .env
    [ -f "$BACKUP_DIR/docker-compose.yml.backup" ] && cp "$BACKUP_DIR/docker-compose.yml.backup" docker-compose.yml
    [ -f "$BACKUP_DIR/docker-compose.prod.yml.backup" ] && cp "$BACKUP_DIR/docker-compose.prod.yml.backup" docker-compose.prod.yml

    docker compose up -d
    sleep 15

    if docker ps --format '{{.Names}}' | grep -q '^coolify$'; then
        echo -e "${GREEN}✓ Rollback successful. System restored.${NC}"
    else
        echo -e "${RED}✗ Rollback failed. Manual recovery needed.${NC}"
        echo "Backup location: $BACKUP_DIR"
    fi
}

# ============================================
# UTILITY FUNCTIONS
# ============================================

print_header() {
    echo -e "${BLUE}=============================================="
    echo "  $1"
    echo -e "==============================================${NC}"
}

print_step() {
    CURRENT_STEP="[$1] $2"
    echo -e "${CYAN}$CURRENT_STEP${NC}"
}

check_pass() {
    echo -e "${GREEN}✓${NC} $1"
}

check_fail() {
    echo -e "${RED}✗${NC} $1"
    return 1
}

check_warn() {
    echo -e "${YELLOW}⚠${NC} $1"
}

# ============================================
# DETECTION FUNCTIONS
# ============================================

detect_scenario() {
    if [ ! -d /data/coolify ]; then
        echo "fresh_install"
    elif [ -d /data/coolify/source ] && [ -f /data/coolify/source/.env ]; then
        # Check if already using custom images
        if grep -q "COOLIFY_IMAGE_NAMESPACE=$COOLIFY_ORG" /data/coolify/source/.env 2>/dev/null; then
            echo "already_custom"
        else
            echo "upgrade"
        fi
    else
        echo "unknown"
    fi
}

# ============================================
# PRE-FLIGHT CHECKS
# ============================================

run_preflight_checks() {
    local check_type=$1  # "upgrade" or "install"

    print_header "Pre-Flight Safety Checks"
    echo ""

    local errors=0
    local warnings=0

    # Root check
    if [ $EUID = 0 ]; then
        check_pass "Running as root"
    else
        check_fail "Not running as root" || ((errors++))
    fi

    # Disk space
    local available_gb=$(df /data 2>/dev/null | awk 'NR==2 {print int($4/1024/1024)}' || echo 0)
    if [ $available_gb -ge 5 ]; then
        check_pass "Disk space: ${available_gb}GB"
    elif [ $available_gb -ge 2 ]; then
        check_warn "Low disk space: ${available_gb}GB"
        ((warnings++))
    else
        check_fail "Insufficient disk space: ${available_gb}GB" || ((errors++))
    fi

    # Docker
    if command -v docker >/dev/null 2>&1; then
        local docker_version=$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo "Unknown")
        check_pass "Docker: $docker_version"
    else
        check_fail "Docker not found" || ((errors++))
    fi

    # Docker Compose
    if docker compose version >/dev/null 2>&1; then
        check_pass "Docker Compose available"
    else
        check_fail "Docker Compose not available" || ((errors++))
    fi

    # Internet
    if curl -s --max-time 5 https://github.com >/dev/null 2>&1; then
        check_pass "Internet connectivity"
    else
        check_warn "Limited internet connectivity"
        ((warnings++))
    fi

    if [ "$check_type" = "upgrade" ]; then
        # Coolify running - check for main container specifically
        if docker ps --format '{{.Names}}' | grep -q '^coolify$' 2>/dev/null; then
            check_pass "Coolify running"
        else
            # Check if container exists but is stopped
            if docker ps -a --format '{{.Names}}' | grep -q '^coolify$' 2>/dev/null; then
                check_fail "Coolify container exists but is not running" || ((errors++))
                echo -e "  ${YELLOW}Try: cd /data/coolify/source && docker compose up -d${NC}"
            else
                check_fail "Coolify container not found" || ((errors++))
                echo -e "  ${YELLOW}Expected container name: coolify${NC}"
                # Show what coolify containers are actually running for diagnostics
                local running_coolify=$(docker ps --format '{{.Names}}' | grep coolify | head -3 | tr '\n' ' ')
                if [ -n "$running_coolify" ]; then
                    echo -e "  ${YELLOW}Found running: $running_coolify${NC}"
                fi
            fi
        fi

        # Database
        if docker exec coolify-db pg_isready -U coolify >/dev/null 2>&1; then
            check_pass "Database accessible"
        else
            check_fail "Database not accessible" || ((errors++))
        fi

        # Backup capability
        if docker exec coolify-db pg_dump -U coolify coolify > /tmp/test-$$ 2>/dev/null; then
            check_pass "Can create backups"
            rm -f /tmp/test-$$
        else
            check_fail "Cannot create backups" || ((errors++))
        fi
    fi

    # Custom images
    if docker pull "$COOLIFY_REGISTRY/$COOLIFY_ORG/coolify:latest" >/dev/null 2>&1; then
        check_pass "Custom images accessible"
    else
        check_fail "Custom images not accessible" || ((errors++))
        echo ""
        echo -e "${YELLOW}Make sure:${NC}"
        echo "  1. Images built: see QUICK_START.md"
        echo "  2. Images public on GitHub Packages"
        echo "  3. Image: $COOLIFY_REGISTRY/$COOLIFY_ORG/coolify:latest"
    fi

    echo ""
    echo "Summary: Passed: $((8 - errors - warnings)) | Warnings: $warnings | Errors: $errors"
    echo ""

    if [ $errors -gt 0 ]; then
        echo -e "${RED}Pre-flight check failed. Cannot proceed safely.${NC}"
        return 1
    fi

    if [ $warnings -gt 0 ]; then
        echo -e "${YELLOW}Warnings detected. Review above before proceeding.${NC}"
    else
        echo -e "${GREEN}✓ All checks passed!${NC}"
    fi

    return 0
}

# ============================================
# BACKUP FUNCTION
# ============================================

create_backup() {
    local timestamp=$(date +%Y%m%d-%H%M%S)
    BACKUP_DIR="/root/coolify-backup-${timestamp}"
    mkdir -p "$BACKUP_DIR"

    print_step "1" "Creating comprehensive backup..."

    cd /data/coolify/source

    # Database
    if ! docker exec coolify-db pg_dump -U coolify coolify > "$BACKUP_DIR/coolify-db.sql" 2>/dev/null; then
        echo -e "${RED}✗ Database backup failed!${NC}"
        return 1
    fi
    local db_size=$(du -h "$BACKUP_DIR/coolify-db.sql" | cut -f1)
    echo "  Database: $db_size"

    # Configuration
    cp .env "$BACKUP_DIR/.env.backup"
    cp docker-compose.yml "$BACKUP_DIR/docker-compose.yml.backup" 2>/dev/null || true
    cp docker-compose.prod.yml "$BACKUP_DIR/docker-compose.prod.yml.backup" 2>/dev/null || true
    [ -f docker-compose.custom.yml ] && cp docker-compose.custom.yml "$BACKUP_DIR/"

    # SSH keys and SSL
    [ -d /data/coolify/ssh ] && cp -r /data/coolify/ssh "$BACKUP_DIR/"
    [ -d /data/coolify/ssl ] && cp -r /data/coolify/ssl "$BACKUP_DIR/"

    echo -e "  ${GREEN}✓${NC} Backup created: $BACKUP_DIR"
    return 0
}

# ============================================
# UPGRADE FUNCTION
# ============================================

perform_upgrade() {
    print_header "Upgrading to Custom Coolify"
    echo ""

    OPERATION_STARTED=true

    # Step 1: Backup
    create_backup || return 1

    # Step 2: Verify backup
    print_step "2" "Verifying backup integrity..."
    if [ ! -f "$BACKUP_DIR/coolify-db.sql" ] || [ ! -s "$BACKUP_DIR/coolify-db.sql" ]; then
        echo -e "${RED}✗ Backup verification failed${NC}"
        return 1
    fi
    echo -e "  ${GREEN}✓${NC} Backup verified"

    # Step 3: Pull images
    print_step "3" "Pulling custom images..."
    for img in "coolify" "coolify-helper" "coolify-realtime"; do
        docker pull "$COOLIFY_REGISTRY/$COOLIFY_ORG/$img:latest" >/dev/null 2>&1 || return 1
    done
    echo -e "  ${GREEN}✓${NC} Images pulled"

    # Step 4: Update configuration
    print_step "4" "Updating configuration..."
    cd /data/coolify/source

    update_env() {
        local key=$1 value=$2
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

    echo -e "  ${GREEN}✓${NC} Configuration updated"

    # Step 5: Update compose files
    print_step "5" "Updating docker-compose files..."

    if ! curl -fsSL "$COOLIFY_CDN/docker-compose.yml" -o docker-compose.yml.new; then
        echo -e "${RED}✗ Failed to download docker-compose.yml from $COOLIFY_CDN${NC}"
        return 1
    fi

    if ! curl -fsSL "$COOLIFY_CDN/docker-compose.prod.yml" -o docker-compose.prod.yml.new; then
        echo -e "${RED}✗ Failed to download docker-compose.prod.yml from $COOLIFY_CDN${NC}"
        return 1
    fi

    sed -i "s|ghcr.io/coollabsio|$COOLIFY_REGISTRY/$COOLIFY_ORG|g" docker-compose.yml.new
    sed -i "s|ghcr.io/coollabsio|$COOLIFY_REGISTRY/$COOLIFY_ORG|g" docker-compose.prod.yml.new

    # Verify files are not empty
    if [ ! -s docker-compose.yml.new ] || [ ! -s docker-compose.prod.yml.new ]; then
        echo -e "${RED}✗ Downloaded files are empty${NC}"
        return 1
    fi

    mv docker-compose.yml.new docker-compose.yml
    mv docker-compose.prod.yml.new docker-compose.prod.yml
    echo -e "  ${GREEN}✓${NC} Compose files updated"

    # Step 6: Restart
    print_step "6" "Restarting with custom images..."
    docker compose down
    sleep 3
    docker compose up -d >/dev/null 2>&1
    sleep 20

    # Step 7: Verify
    print_step "7" "Verifying deployment..."
    if ! docker ps --format '{{.Names}}' | grep -q '^coolify$'; then
        echo -e "${RED}✗ Main Coolify container failed to start${NC}"
        echo "Running containers:"
        docker ps --format '{{.Names}}' | grep coolify || echo "  None found"
        return 1
    fi

    if ! docker exec coolify php artisan tinker --execute="DB::connection()->getPdo();" >/dev/null 2>&1; then
        echo -e "${RED}✗ Database connection failed${NC}"
        return 1
    fi

    local servers=$(docker exec coolify php artisan tinker --execute="echo App\Models\Server::count();" 2>/dev/null || echo "0")
    local apps=$(docker exec coolify php artisan tinker --execute="echo App\Models\Application::count();" 2>/dev/null || echo "0")

    echo -e "  ${GREEN}✓${NC} Verified (Servers: $servers, Apps: $apps)"

    # Step 8: Cleanup
    print_step "8" "Finalizing..."
    docker exec coolify php artisan cache:clear >/dev/null 2>&1
    docker exec coolify php artisan config:clear >/dev/null 2>&1
    echo -e "  ${GREEN}✓${NC} Complete"

    OPERATION_STARTED=false
    return 0
}

# ============================================
# FRESH INSTALL FUNCTION
# ============================================

perform_fresh_install() {
    print_header "Installing Custom Coolify"
    echo ""

    echo "This will download and run the custom installation script."
    echo ""
    read -p "Continue? (yes/no) " -r
    if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
        echo "Installation cancelled."
        return 1
    fi

    # Download and run installation script
    export REGISTRY_URL="$COOLIFY_REGISTRY"
    export COOLIFY_CDN

    curl -fsSL "$COOLIFY_CDN/scripts/install.sh" | bash

    # Add custom settings
    if [ -f /data/coolify/source/.env ]; then
        echo ""
        echo "Adding custom configuration..."
        echo "REGISTRY_URL=$COOLIFY_REGISTRY" >> /data/coolify/source/.env
        echo "COOLIFY_IMAGE_NAMESPACE=$COOLIFY_ORG" >> /data/coolify/source/.env
        echo "COOLIFY_CDN=$COOLIFY_CDN" >> /data/coolify/source/.env
    fi
}

# ============================================
# UPDATE CUSTOM IMAGES FUNCTION
# ============================================

update_custom_images() {
    print_header "Updating Custom Images"
    echo ""

    cd /data/coolify/source || {
        echo -e "${RED}✗ Coolify source directory not found${NC}"
        return 1
    }

    # Step 1: Download latest compose files
    print_step "1" "Downloading latest docker-compose files..."

    if ! curl -fsSL "$COOLIFY_CDN/docker-compose.yml" -o docker-compose.yml.new; then
        echo -e "${RED}✗ Failed to download docker-compose.yml${NC}"
        return 1
    fi

    if ! curl -fsSL "$COOLIFY_CDN/docker-compose.prod.yml" -o docker-compose.prod.yml.new; then
        echo -e "${RED}✗ Failed to download docker-compose.prod.yml${NC}"
        return 1
    fi

    # Verify files are not empty
    if [ ! -s docker-compose.yml.new ] || [ ! -s docker-compose.prod.yml.new ]; then
        echo -e "${RED}✗ Downloaded files are empty${NC}"
        rm -f docker-compose.yml.new docker-compose.prod.yml.new
        return 1
    fi

    mv docker-compose.yml.new docker-compose.yml
    mv docker-compose.prod.yml.new docker-compose.prod.yml
    echo -e "  ${GREEN}✓${NC} Compose files updated"

    # Step 2: Update .env with required variables
    print_step "2" "Updating environment configuration..."

    update_env() {
        local key=$1 value=$2
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

    echo -e "  ${GREEN}✓${NC} Environment updated"

    # Step 3: Pull latest images
    print_step "3" "Pulling latest images..."
    if ! docker compose pull; then
        echo -e "${RED}✗ Failed to pull images${NC}"
        return 1
    fi
    echo -e "  ${GREEN}✓${NC} Images pulled"

    # Step 4: Restart services
    print_step "4" "Restarting services..."
    docker compose down
    docker compose up -d
    sleep 10

    # Step 4: Verify
    if docker ps --format '{{.Names}}' | grep -q '^coolify$'; then
        echo -e "  ${GREEN}✓${NC} Services restarted successfully"
        echo ""
        echo -e "${GREEN}✓ Update complete!${NC}"
    else
        echo -e "  ${RED}✗${NC} Services failed to start"
        echo "Check logs: docker logs coolify"
        return 1
    fi
}

# ============================================
# ROLLBACK FUNCTION
# ============================================

perform_rollback() {
    print_header "Rollback to Official Coolify"
    echo ""

    local backup=$(ls -td /root/coolify-backup-* 2>/dev/null | head -1)

    if [ -z "$backup" ]; then
        echo "No backup found. Manual configuration needed."
        echo ""
        read -p "Continue with manual rollback? (yes/no) " -r
        if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
            return 1
        fi
    else
        echo "Found backup: $backup"
        echo ""
        read -p "Restore from this backup? (yes/no) " -r
        if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
            return 1
        fi
        BACKUP_DIR="$backup"
    fi

    cd /data/coolify/source
    docker compose down

    if [ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ]; then
        [ -f "$BACKUP_DIR/.env.backup" ] && cp "$BACKUP_DIR/.env.backup" .env
        [ -f "$BACKUP_DIR/docker-compose.yml.backup" ] && cp "$BACKUP_DIR/docker-compose.yml.backup" docker-compose.yml
        [ -f "$BACKUP_DIR/docker-compose.prod.yml.backup" ] && cp "$BACKUP_DIR/docker-compose.prod.yml.backup" docker-compose.prod.yml
    else
        # Manual rollback
        curl -fsSL https://cdn.coollabs.io/coolify/docker-compose.yml -o docker-compose.yml
        curl -fsSL https://cdn.coollabs.io/coolify/docker-compose.prod.yml -o docker-compose.prod.yml

        sed -i 's|COOLIFY_IMAGE_NAMESPACE=.*|COOLIFY_IMAGE_NAMESPACE=coollabsio|' .env
        sed -i 's|REGISTRY_URL=.*|REGISTRY_URL=ghcr.io|' .env
    fi

    docker compose pull
    docker compose up -d
    sleep 20

    if docker ps --format '{{.Names}}' | grep -q '^coolify$'; then
        echo -e "${GREEN}✓ Rollback successful${NC}"
    else
        echo -e "${RED}✗ Rollback failed - Coolify container not running${NC}"
        return 1
    fi
}

# ============================================
# MAIN MENU
# ============================================

show_menu() {
    local scenario=$1

    clear
    echo -e "${BLUE}"
    cat << "EOF"
   ____            _ _  __         ____          _
  / ___|___   ___ | (_)/ _|_   _  / ___|   _ ___| |_ ___  _ __ ___
 | |   / _ \ / _ \| | | |_| | | || |  | | | / __| __/ _ \| '_ ` _ \
 | |__| (_) | (_) | | |  _| |_| || |__| |_| \__ \ || (_) | | | | | |
  \____\___/ \___/|_|_|_|  \__, (_)____\__,_|___/\__\___/|_| |_| |_|
                           |___/
EOF
    echo -e "${NC}"
    echo -e "${CYAN}Custom Deployment Manager${NC}"
    echo -e "Organization: ${GREEN}$COOLIFY_ORG${NC}"
    echo -e "Registry: ${GREEN}$COOLIFY_REGISTRY${NC}"
    echo ""

    case $scenario in
        "fresh_install")
            echo -e "${YELLOW}Scenario: Fresh Installation${NC}"
            echo ""
            echo "1) Install Custom Coolify"
            echo "2) Run Pre-Flight Check Only"
            echo "3) Exit"
            ;;
        "upgrade")
            echo -e "${YELLOW}Scenario: Upgrade to Custom${NC}"
            echo ""
            echo "1) Upgrade to Custom Coolify"
            echo "2) Run Pre-Flight Check Only"
            echo "3) Create Backup Only"
            echo "4) Exit"
            ;;
        "already_custom")
            echo -e "${GREEN}Scenario: Already Using Custom${NC}"
            echo ""
            echo "1) Update Custom Images (Pull Latest)"
            echo "2) Rollback to Official Coolify"
            echo "3) Run Pre-Flight Check"
            echo "4) Create Backup"
            echo "5) Exit"
            ;;
        *)
            echo -e "${RED}Scenario: Unknown${NC}"
            echo ""
            echo "1) Try Fresh Install"
            echo "2) Exit"
            ;;
    esac

    echo ""
    read -p "Select option: " choice
    echo ""

    handle_choice "$scenario" "$choice"
}

handle_choice() {
    local scenario=$1
    local choice=$2

    case $scenario in
        "fresh_install")
            case $choice in
                1)
                    run_preflight_checks "install" && perform_fresh_install
                    ;;
                2)
                    run_preflight_checks "install"
                    ;;
                3)
                    exit 0
                    ;;
                *)
                    echo "Invalid option"
                    sleep 2
                    return
                    ;;
            esac
            ;;
        "upgrade")
            case $choice in
                1)
                    run_preflight_checks "upgrade" && perform_upgrade
                    ;;
                2)
                    run_preflight_checks "upgrade"
                    ;;
                3)
                    create_backup
                    ;;
                4)
                    exit 0
                    ;;
                *)
                    echo "Invalid option"
                    sleep 2
                    return
                    ;;
            esac
            ;;
        "already_custom")
            case $choice in
                1)
                    update_custom_images
                    ;;
                2)
                    perform_rollback
                    ;;
                3)
                    run_preflight_checks "upgrade"
                    ;;
                4)
                    create_backup
                    ;;
                5)
                    exit 0
                    ;;
                *)
                    echo "Invalid option"
                    sleep 2
                    return
                    ;;
            esac
            ;;
        *)
            case $choice in
                1)
                    run_preflight_checks "install" && perform_fresh_install
                    ;;
                2)
                    exit 0
                    ;;
                *)
                    echo "Invalid option"
                    sleep 2
                    return
                    ;;
            esac
            ;;
    esac

    echo ""
    read -p "Press Enter to continue..."
}

# ============================================
# MAIN
# ============================================

main() {
    # Check root
    if [ $EUID != 0 ]; then
        echo -e "${RED}Error: Must run as root${NC}"
        echo "Try: sudo $0"
        exit 1
    fi

    # Detect scenario
    local scenario=$(detect_scenario)

    # Show menu
    while true; do
        show_menu "$scenario"

        # Re-detect in case scenario changed
        scenario=$(detect_scenario)
    done
}

# Run main
main
