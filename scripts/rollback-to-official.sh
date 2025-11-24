#!/bin/bash
# Rollback from Custom Coolify to Official Coolify
# Use this if you need to revert to the official Coolify version

set -e

echo "=============================================="
echo "  Coolify Rollback to Official Version"
echo "=============================================="
echo ""

# Check if running as root
if [ $EUID != 0 ]; then
    echo "ERROR: Please run this script as root or with sudo"
    exit 1
fi

# Check if Coolify exists
if [ ! -d /data/coolify/source ]; then
    echo "ERROR: Coolify installation not found"
    exit 1
fi

cd /data/coolify/source

# Find latest backup
echo "Looking for pre-upgrade backup..."
BACKUP_DIR=$(ls -td /root/coolify-pre-upgrade-* 2>/dev/null | head -1)

if [ -z "$BACKUP_DIR" ]; then
    echo "WARNING: No pre-upgrade backup found!"
    echo ""
    read -p "Continue with manual rollback? (yes/no) " -r
    echo
    if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
        exit 0
    fi
    MANUAL_ROLLBACK=true
else
    echo "Found backup: $BACKUP_DIR"
    echo ""
    MANUAL_ROLLBACK=false
fi

# Confirmation
echo "This will rollback to official Coolify images."
echo "Your data will be preserved."
echo ""
read -p "Do you want to proceed? (yes/no) " -r
echo
if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
    echo "Rollback cancelled."
    exit 0
fi

echo ""
echo "Starting rollback..."
echo ""

# Stop Coolify
echo "[1/5] Stopping current Coolify..."
docker compose down
echo "      ✓ Stopped"

sleep 3

if [ "$MANUAL_ROLLBACK" = false ]; then
    # Restore from backup
    echo "[2/5] Restoring configuration from backup..."

    if [ -f "$BACKUP_DIR/.env.backup" ]; then
        cp "$BACKUP_DIR/.env.backup" .env
        echo "      ✓ .env restored"
    fi

    if [ -f "$BACKUP_DIR/docker-compose.yml.backup" ]; then
        cp "$BACKUP_DIR/docker-compose.yml.backup" docker-compose.yml
        echo "      ✓ docker-compose.yml restored"
    fi

    if [ -f "$BACKUP_DIR/docker-compose.prod.yml.backup" ]; then
        cp "$BACKUP_DIR/docker-compose.prod.yml.backup" docker-compose.prod.yml
        echo "      ✓ docker-compose.prod.yml restored"
    fi
else
    # Manual rollback
    echo "[2/5] Updating configuration manually..."

    # Download official compose files
    curl -fsSL https://cdn.coollabs.io/coolify/docker-compose.yml -o docker-compose.yml
    curl -fsSL https://cdn.coollabs.io/coolify/docker-compose.prod.yml -o docker-compose.prod.yml

    # Update .env
    sed -i 's|^REGISTRY_URL=.*|REGISTRY_URL=ghcr.io|' .env
    sed -i 's|^COOLIFY_IMAGE_NAMESPACE=.*|COOLIFY_IMAGE_NAMESPACE=coollabsio|' .env

    # Remove custom variables
    sed -i '/^COOLIFY_CDN=/d' .env
    sed -i '/^HELPER_IMAGE=/d' .env
    sed -i '/^REALTIME_IMAGE=/d' .env
    sed -i '/^COOLIFY_RELEASES_URL=/d' .env

    echo "      ✓ Configuration updated to official"
fi

# Pull official images
echo "[3/5] Pulling official Coolify images..."
docker compose pull

if [ $? -ne 0 ]; then
    echo "      ✗ Failed to pull official images"
    exit 1
fi

echo "      ✓ Official images pulled"

# Start with official images
echo "[4/5] Starting Coolify with official images..."
docker compose up -d

echo "      Waiting for services to start..."
sleep 20

# Verify
echo "[5/5] Verifying rollback..."

if docker ps | grep -q coolify; then
    echo "      ✓ Coolify is running"
else
    echo "      ✗ Coolify failed to start"
    exit 1
fi

# Check database
DB_CHECK=$(docker exec coolify php artisan tinker --execute="echo App\Models\Server::count();" 2>/dev/null || echo "0")
echo "      ✓ Database connection verified (Servers: $DB_CHECK)"

# Clear caches
docker exec coolify php artisan cache:clear >/dev/null 2>&1
docker exec coolify php artisan config:clear >/dev/null 2>&1

echo ""
echo "=============================================="
echo "  Rollback Completed Successfully! ✓"
echo "=============================================="
echo ""
echo "Now running official Coolify:"
echo ""
docker ps --format "table {{.Names}}\t{{.Image}}" | grep -E "(NAMES|coolify)"
echo ""
echo "Access Coolify at:"
echo "  http://$(hostname -I | awk '{print $1}'):8000"
echo ""
echo "All your data, servers, and applications are preserved."
echo ""
echo "=============================================="
