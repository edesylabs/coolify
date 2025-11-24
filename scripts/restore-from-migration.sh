#!/bin/bash
# Coolify Migration Restore Script
# Run this on your NEW custom Coolify server AFTER installing it

set -e

echo "============================================"
echo "  Coolify Migration Restore Tool"
echo "============================================"
echo ""

# Check if running as root
if [ $EUID != 0 ]; then
    echo "ERROR: Please run this script as root or with sudo"
    exit 1
fi

# Check if custom Coolify is installed
if [ ! -d "/data/coolify/source" ]; then
    echo "ERROR: Coolify not found. Please install custom Coolify first!"
    echo "Run: curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/custom-install.sh | bash"
    exit 1
fi

# Find the backup archive
echo "Looking for backup archive..."
BACKUP_ARCHIVE=$(ls -t /root/coolify-migration-*.tar.gz 2>/dev/null | head -1)

if [ -z "$BACKUP_ARCHIVE" ]; then
    echo "ERROR: No backup archive found in /root/"
    echo ""
    echo "Please transfer the backup from old server:"
    echo "  scp root@OLD_SERVER_IP:/root/coolify-migration-*.tar.gz /root/"
    exit 1
fi

echo "Found backup: $BACKUP_ARCHIVE"
echo "Size: $(du -sh "$BACKUP_ARCHIVE" | awk '{print $1}')"
echo ""

# Confirm restore
read -p "This will REPLACE all data on this Coolify instance. Continue? (yes/no) " -r
echo
if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
    echo "Restore cancelled."
    exit 0
fi

echo ""
echo "Starting restore process..."
echo ""

# Extract archive
echo "[1/12] Extracting backup archive..."
cd /root
tar -xzf "$BACKUP_ARCHIVE"
BACKUP_DIR=$(tar -tzf "$BACKUP_ARCHIVE" | head -1 | cut -f1 -d"/")

if [ ! -d "/root/$BACKUP_DIR" ]; then
    echo "       ✗ Failed to extract archive!"
    exit 1
fi

echo "       ✓ Extracted to: /root/$BACKUP_DIR"

# Show backup metadata
if [ -f "/root/$BACKUP_DIR/metadata.txt" ]; then
    echo ""
    cat "/root/$BACKUP_DIR/metadata.txt"
    echo ""
fi

# Stop Coolify services
echo "[2/12] Stopping Coolify services..."
cd /data/coolify/source
docker compose down
echo "       ✓ Services stopped"

sleep 3

# Backup current .env (with custom settings)
echo "[3/12] Backing up current custom configuration..."
cp /data/coolify/source/.env /data/coolify/source/.env.custom-backup
echo "       ✓ Custom settings backed up"

# Restore SSH keys
echo "[4/12] Restoring SSH keys..."
if [ -d "/root/$BACKUP_DIR/ssh" ]; then
    rm -rf /data/coolify/ssh
    cp -r "/root/$BACKUP_DIR/ssh" /data/coolify/
    chown -R 9999:root /data/coolify/ssh
    chmod -R 700 /data/coolify/ssh
    echo "       ✓ SSH keys restored"
else
    echo "       ! No SSH keys found in backup"
fi

# Restore proxy configurations
echo "[5/12] Restoring proxy configurations..."
if [ -d "/root/$BACKUP_DIR/proxy" ]; then
    rm -rf /data/coolify/proxy
    cp -r "/root/$BACKUP_DIR/proxy" /data/coolify/
    echo "       ✓ Proxy configurations restored"
else
    echo "       ! No proxy configurations in backup"
fi

# Restore SSL certificates
echo "[6/12] Restoring SSL certificates..."
if [ -d "/root/$BACKUP_DIR/ssl" ]; then
    rm -rf /data/coolify/ssl
    cp -r "/root/$BACKUP_DIR/ssl" /data/coolify/
    echo "       ✓ SSL certificates restored"
else
    echo "       ! No SSL certificates in backup"
fi

# Merge environment configurations
echo "[7/12] Merging environment configurations..."

# Variables to preserve from custom installation
CUSTOM_VARS=(
    "REGISTRY_URL"
    "COOLIFY_IMAGE_NAMESPACE"
    "COOLIFY_CDN"
    "COOLIFY_RELEASES_URL"
    "HELPER_IMAGE"
    "REALTIME_IMAGE"
)

# Start with old environment
cp "/root/$BACKUP_DIR/.env.backup" /data/coolify/source/.env

# Preserve custom variables
for var in "${CUSTOM_VARS[@]}"; do
    if grep -q "^${var}=" /data/coolify/source/.env.custom-backup; then
        value=$(grep "^${var}=" /data/coolify/source/.env.custom-backup | head -1)
        # Remove old value if exists
        sed -i "/^${var}=/d" /data/coolify/source/.env
        # Add new value
        echo "$value" >> /data/coolify/source/.env
    fi
done

echo "       ✓ Environment configuration merged"

# Start database only
echo "[8/12] Starting PostgreSQL database..."
docker compose up -d postgres redis

# Wait for database
echo "       Waiting for database to be ready..."
sleep 10

# Check database is ready
for i in {1..30}; do
    if docker exec coolify-db pg_isready -U coolify >/dev/null 2>&1; then
        echo "       ✓ Database is ready"
        break
    fi
    if [ $i -eq 30 ]; then
        echo "       ✗ Database failed to start!"
        exit 1
    fi
    sleep 2
done

# Restore database
echo "[9/12] Restoring database..."
if [ -f "/root/$BACKUP_DIR/coolify-db.sql" ]; then
    # Drop and recreate database
    docker exec coolify-db psql -U coolify -d postgres -c "DROP DATABASE IF EXISTS coolify;" 2>/dev/null || true
    docker exec coolify-db psql -U coolify -d postgres -c "CREATE DATABASE coolify;"

    # Restore data
    cat "/root/$BACKUP_DIR/coolify-db.sql" | docker exec -i coolify-db psql -U coolify -d coolify

    if [ $? -eq 0 ]; then
        echo "       ✓ Database restored successfully"
    else
        echo "       ✗ Database restore failed!"
        exit 1
    fi
else
    echo "       ✗ Database backup file not found!"
    exit 1
fi

# Start all services
echo "[10/12] Starting all Coolify services..."
docker compose up -d

echo "        Waiting for services to start..."
sleep 20

# Run migrations
echo "[11/12] Running database migrations..."
docker exec coolify php artisan migrate --force
echo "       ✓ Migrations completed"

# Clear caches
echo "[12/12] Clearing caches..."
docker exec coolify php artisan cache:clear
docker exec coolify php artisan config:clear
docker exec coolify php artisan route:clear
docker exec coolify php artisan view:clear
echo "       ✓ Caches cleared"

# Fix permissions
chown -R 9999:root /data/coolify
chmod -R 700 /data/coolify

# Verify restoration
echo ""
echo "Verifying restoration..."
echo ""

# Check database
DB_COUNT=$(docker exec coolify php artisan tinker --execute="echo App\Models\Server::count();" 2>/dev/null || echo "0")
echo "  Servers in database: $DB_COUNT"

APP_COUNT=$(docker exec coolify php artisan tinker --execute="echo App\Models\Application::count();" 2>/dev/null || echo "0")
echo "  Applications in database: $APP_COUNT"

echo ""
echo "============================================"
echo "  Migration Restore Completed! ✓"
echo "============================================"
echo ""
echo "Your custom Coolify is now running with migrated data!"
echo ""
echo "Next Steps:"
echo ""
echo "  1. Access Coolify UI:"
echo "     http://$(hostname -I | awk '{print $1}'):8000"
echo ""
echo "  2. Login with your existing credentials"
echo ""
echo "  3. Verify all data:"
echo "     - Check Servers list"
echo "     - Check Applications list"
echo "     - Check Team members"
echo ""
echo "  4. Reconnect servers:"
echo "     - Go to each server"
echo "     - Click 'Validate Connection'"
echo "     - Servers should reconnect automatically"
echo ""
echo "  5. Test deployment:"
echo "     - Deploy an existing application"
echo "     - Verify it works correctly"
echo ""
echo "  6. Update webhooks/DNS if needed"
echo ""
echo "Backup files preserved at:"
echo "  Directory: /root/$BACKUP_DIR"
echo "  Archive: $BACKUP_ARCHIVE"
echo ""
echo "Custom settings backup:"
echo "  /data/coolify/source/.env.custom-backup"
echo ""
echo "For detailed post-migration steps, see:"
echo "  MIGRATION_GUIDE.md"
echo ""
echo "============================================"
