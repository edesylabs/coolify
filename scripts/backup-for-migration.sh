#!/bin/bash
# Coolify Migration Backup Script
# Run this on your OLD/EXISTING Coolify server

set -e

echo "============================================"
echo "  Coolify Migration Backup Tool"
echo "============================================"
echo ""

# Check if running as root
if [ $EUID != 0 ]; then
    echo "ERROR: Please run this script as root or with sudo"
    exit 1
fi

# Check if Coolify is installed
if [ ! -d "/data/coolify" ]; then
    echo "ERROR: Coolify installation not found at /data/coolify"
    exit 1
fi

# Check if Coolify is running
if ! docker ps | grep -q coolify; then
    echo "WARNING: Coolify containers don't seem to be running"
    read -p "Do you want to continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 0
    fi
fi

# Create backup directory with timestamp
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR="/root/coolify-migration-${TIMESTAMP}"
mkdir -p "$BACKUP_DIR"

echo "Creating backup..."
echo "Backup directory: $BACKUP_DIR"
echo ""

# 1. Backup PostgreSQL database
echo "[1/8] Backing up PostgreSQL database..."
if docker ps | grep -q coolify-db; then
    docker exec coolify-db pg_dump -U coolify coolify > "$BACKUP_DIR/coolify-db.sql"
    echo "      ✓ Database backed up ($(du -sh "$BACKUP_DIR/coolify-db.sql" | awk '{print $1}'))"
else
    echo "      ✗ Database container not running!"
    exit 1
fi

# 2. Backup environment file
echo "[2/8] Backing up environment configuration..."
if [ -f /data/coolify/source/.env ]; then
    cp /data/coolify/source/.env "$BACKUP_DIR/.env.backup"
    echo "      ✓ Environment file backed up"
else
    echo "      ✗ Environment file not found!"
    exit 1
fi

# 3. Backup SSH keys
echo "[3/8] Backing up SSH keys..."
if [ -d /data/coolify/ssh ]; then
    cp -r /data/coolify/ssh "$BACKUP_DIR/ssh"
    echo "      ✓ SSH keys backed up"
else
    echo "      ! SSH directory not found (might be okay for some setups)"
fi

# 4. Backup docker-compose files
echo "[4/8] Backing up docker-compose files..."
cp /data/coolify/source/docker-compose.yml "$BACKUP_DIR/" 2>/dev/null || echo "      ! docker-compose.yml not found"
cp /data/coolify/source/docker-compose.prod.yml "$BACKUP_DIR/" 2>/dev/null || echo "      ! docker-compose.prod.yml not found"
if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
    cp /data/coolify/source/docker-compose.custom.yml "$BACKUP_DIR/"
    echo "      ✓ Custom docker-compose.yml backed up"
fi

# 5. Backup proxy configurations
echo "[5/8] Backing up proxy configurations..."
if [ -d /data/coolify/proxy ]; then
    cp -r /data/coolify/proxy "$BACKUP_DIR/proxy"
    echo "      ✓ Proxy configurations backed up"
else
    echo "      ! Proxy directory not found"
fi

# 6. Backup SSL certificates
echo "[6/8] Backing up SSL certificates..."
if [ -d /data/coolify/ssl ]; then
    cp -r /data/coolify/ssl "$BACKUP_DIR/ssl"
    echo "      ✓ SSL certificates backed up"
else
    echo "      ! SSL directory not found"
fi

# 7. Export current state information
echo "[7/8] Exporting current state information..."
docker exec coolify php artisan tinker --execute="echo json_encode(App\Models\Server::all(['id', 'name', 'ip', 'user'])->toArray(), JSON_PRETTY_PRINT);" > "$BACKUP_DIR/servers-list.json" 2>/dev/null || echo "      ! Could not export servers list"
docker exec coolify php artisan tinker --execute="echo json_encode(App\Models\Application::all(['id', 'name', 'fqdn', 'git_repository'])->toArray(), JSON_PRETTY_PRINT);" > "$BACKUP_DIR/applications-list.json" 2>/dev/null || echo "      ! Could not export applications list"
docker exec coolify php artisan --version > "$BACKUP_DIR/coolify-version.txt" 2>/dev/null || echo "Unknown" > "$BACKUP_DIR/coolify-version.txt"

# 8. Create metadata file
echo "[8/8] Creating metadata file..."
cat > "$BACKUP_DIR/metadata.txt" << EOF
Coolify Migration Backup
========================

Backup Information:
------------------
Backup Date: $(date)
Backup Directory: $BACKUP_DIR
Server Hostname: $(hostname)
Server IP: $(hostname -I | awk '{print $1}')

Coolify Information:
-------------------
Version: $(cat "$BACKUP_DIR/coolify-version.txt")
Installation Path: /data/coolify
Database Size: $(du -sh "$BACKUP_DIR/coolify-db.sql" | awk '{print $1}')

Statistics:
----------
Servers: $(cat "$BACKUP_DIR/servers-list.json" 2>/dev/null | grep -c '"id"' || echo "Unknown")
Applications: $(cat "$BACKUP_DIR/applications-list.json" 2>/dev/null | grep -c '"id"' || echo "Unknown")

Backed Up Components:
--------------------
✓ PostgreSQL Database
✓ Environment Configuration
✓ SSH Keys
✓ Docker Compose Files
✓ Proxy Configurations
✓ SSL Certificates
✓ Server/Application Lists

EOF

# Create compressed archive
echo ""
echo "Creating compressed archive..."
cd /root
ARCHIVE_NAME="coolify-migration-${TIMESTAMP}.tar.gz"
tar -czf "$ARCHIVE_NAME" "$(basename $BACKUP_DIR)"

ARCHIVE_SIZE=$(du -sh "/root/$ARCHIVE_NAME" | awk '{print $1}')

echo ""
echo "============================================"
echo "  Backup Completed Successfully! ✓"
echo "============================================"
echo ""
echo "Backup Details:"
echo "  Directory: $BACKUP_DIR"
echo "  Archive:   /root/$ARCHIVE_NAME"
echo "  Size:      $ARCHIVE_SIZE"
echo ""
echo "Next Steps:"
echo "  1. Review backup contents:"
echo "     ls -lah $BACKUP_DIR"
echo ""
echo "  2. Transfer to new server:"
echo "     scp /root/$ARCHIVE_NAME root@NEW_SERVER_IP:/root/"
echo ""
echo "  3. On new server, run the restore script:"
echo "     ./restore-from-migration.sh"
echo ""
echo "  4. Keep this backup safe until migration is verified!"
echo ""
echo "Migration guide: MIGRATION_GUIDE.md"
echo "============================================"
