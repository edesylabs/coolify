# In-Place Upgrade: Official Coolify → Custom Fork

This guide shows you how to upgrade your existing Coolify installation to use your custom fork **on the same server** without data loss or downtime for your applications.

## Overview

**What This Does:**
- Keeps all your data (database, configs, SSH keys)
- Updates Docker images to your custom ones
- Updates scripts to use your repository
- **Zero downtime for deployed applications**
- ~5-10 minutes total time

**Perfect For:**
- Upgrading existing Coolify to custom fork
- Testing custom changes on current setup
- Avoiding server migration

---

## Prerequisites

- [ ] Existing Coolify installation running
- [ ] Custom Docker images built and accessible (`ghcr.io/edesylabs/coolify:latest`)
- [ ] SSH access as root
- [ ] Backup of current setup (just in case)

---

## Quick Upgrade (3 Commands)

```bash
# 1. Backup current setup
cd /data/coolify/source && cp .env .env.backup-$(date +%Y%m%d)

# 2. Download and run upgrade script
wget https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/upgrade-to-custom.sh
chmod +x upgrade-to-custom.sh
./upgrade-to-custom.sh

# 3. Verify
docker ps | grep coolify
```

That's it! Your Coolify now uses your custom fork.

---

## Detailed Step-by-Step Process

### Step 1: Safety Backup (2 minutes)

```bash
# Create safety backup
BACKUP_DIR="/root/coolify-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Backup database
docker exec coolify-db pg_dump -U coolify coolify > "$BACKUP_DIR/coolify-db.sql"

# Backup environment
cp /data/coolify/source/.env "$BACKUP_DIR/.env.backup"

# Backup docker-compose files
cp /data/coolify/source/docker-compose*.yml "$BACKUP_DIR/" 2>/dev/null || true

echo "Backup saved to: $BACKUP_DIR"
```

### Step 2: Update Configuration (1 minute)

Edit `/data/coolify/source/.env`:

```bash
nano /data/coolify/source/.env
```

Add or update these lines:

```bash
# Custom Coolify Configuration
REGISTRY_URL=ghcr.io
COOLIFY_IMAGE_NAMESPACE=edesylabs

# Optional: Explicitly set images
HELPER_IMAGE=ghcr.io/edesylabs/coolify-helper
REALTIME_IMAGE=ghcr.io/edesylabs/coolify-realtime

# Optional: Custom CDN for updates
COOLIFY_CDN=https://raw.githubusercontent.com/edesylabs/coolify/main
COOLIFY_RELEASES_URL=https://raw.githubusercontent.com/edesylabs/coolify/main/versions.json
```

Save and exit (Ctrl+X, Y, Enter).

### Step 3: Update Docker Compose (1 minute)

Edit `/data/coolify/source/docker-compose.yml`:

```bash
nano /data/coolify/source/docker-compose.yml
```

Find the `coolify` service image line and update it:

```yaml
services:
  coolify:
    # Change this:
    # image: ghcr.io/coollabsio/coolify:latest

    # To this:
    image: ${REGISTRY_URL:-ghcr.io}/${COOLIFY_IMAGE_NAMESPACE:-edesylabs}/coolify:${LATEST_IMAGE:-latest}
```

Find helper and realtime services and update similarly:

```yaml
  coolify-helper:
    image: ${HELPER_IMAGE:-ghcr.io/edesylabs/coolify-helper:latest}

  coolify-realtime:
    image: ${REALTIME_IMAGE:-ghcr.io/edesylabs/coolify-realtime:latest}
```

Save and exit.

### Step 4: Pull New Images (2-3 minutes)

```bash
cd /data/coolify/source

# Pull custom images
docker compose pull
```

You should see:
```
Pulling coolify         ... done
Pulling coolify-helper  ... done
Pulling coolify-realtime... done
```

### Step 5: Restart with New Images (2-3 minutes)

```bash
# Stop current Coolify
docker compose down

# Start with new custom images
docker compose up -d

# Wait for startup
sleep 20

# Check status
docker ps | grep coolify
```

### Step 6: Verify Upgrade (1 minute)

```bash
# Check which images are running
docker ps --format "table {{.Names}}\t{{.Image}}"

# Should show:
# coolify              ghcr.io/edesylabs/coolify:latest
# coolify-realtime     ghcr.io/edesylabs/coolify-realtime:latest

# Check logs for errors
docker logs coolify --tail 50

# Test database connection
docker exec coolify php artisan tinker --execute="echo 'Servers: ' . App\Models\Server::count();"
```

### Step 7: Access and Verify UI

1. Open browser: `http://YOUR_SERVER_IP:8000`
2. Login with your existing credentials
3. Verify:
   - [ ] All servers visible and connected
   - [ ] All applications listed
   - [ ] Can navigate all pages
   - [ ] No errors in UI

---

## What Changed vs. What Stayed

### ✅ Kept (No Changes):
- All your data (database intact)
- All server connections
- All applications and deployments
- All configurations and secrets
- SSH keys
- User accounts
- Your login credentials

### 🔄 Changed:
- Docker images (now from `ghcr.io/edesylabs`)
- Update source (now from your GitHub repo)
- Future deployments use custom Coolify

### 🟢 Result:
- Same functionality
- Same data
- Uses your custom code
- **Zero data loss**

---

## Automated Upgrade Script

For convenience, here's a complete automated script:

```bash
#!/bin/bash
# Save as: upgrade-to-custom.sh

set -e

echo "========================================"
echo "  Coolify In-Place Upgrade to Custom"
echo "========================================"
echo ""

# Configuration
COOLIFY_ORG="${COOLIFY_ORG:-edesylabs}"
COOLIFY_REGISTRY="${COOLIFY_REGISTRY:-ghcr.io}"

echo "Configuration:"
echo "  Organization: $COOLIFY_ORG"
echo "  Registry: $COOLIFY_REGISTRY"
echo ""

# Check if running as root
if [ $EUID != 0 ]; then
    echo "ERROR: Please run as root"
    exit 1
fi

# Check Coolify exists
if [ ! -f /data/coolify/source/.env ]; then
    echo "ERROR: Coolify not found at /data/coolify"
    exit 1
fi

# Confirm
read -p "Upgrade current Coolify to custom fork? (yes/no) " -r
if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
    echo "Cancelled."
    exit 0
fi

echo ""
echo "Starting upgrade..."
echo ""

# Backup
echo "[1/6] Creating backup..."
BACKUP_DIR="/root/coolify-pre-upgrade-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
docker exec coolify-db pg_dump -U coolify coolify > "$BACKUP_DIR/coolify-db.sql"
cp /data/coolify/source/.env "$BACKUP_DIR/.env.backup"
echo "      Backup: $BACKUP_DIR"

# Update .env
echo "[2/6] Updating configuration..."
cd /data/coolify/source

# Add custom config if not present
grep -q "REGISTRY_URL=" .env || echo "REGISTRY_URL=$COOLIFY_REGISTRY" >> .env
grep -q "COOLIFY_IMAGE_NAMESPACE=" .env || echo "COOLIFY_IMAGE_NAMESPACE=$COOLIFY_ORG" >> .env

# Update registry URL if it's the default
sed -i "s|^REGISTRY_URL=ghcr.io$|REGISTRY_URL=$COOLIFY_REGISTRY|" .env
sed -i "s|^COOLIFY_IMAGE_NAMESPACE=.*|COOLIFY_IMAGE_NAMESPACE=$COOLIFY_ORG|" .env

echo "      ✓ Configuration updated"

# Update docker-compose
echo "[3/6] Updating docker-compose.yml..."
# Backup original
cp docker-compose.yml docker-compose.yml.backup

# Update coolify image
sed -i 's|ghcr.io/coollabsio/coolify|${REGISTRY_URL:-ghcr.io}/${COOLIFY_IMAGE_NAMESPACE:-edesylabs}/coolify|' docker-compose.yml

echo "      ✓ docker-compose.yml updated"

# Pull new images
echo "[4/6] Pulling custom images..."
docker compose pull

# Restart
echo "[5/6] Restarting Coolify..."
docker compose down
sleep 3
docker compose up -d
sleep 20

# Verify
echo "[6/6] Verifying upgrade..."
if docker ps | grep -q coolify; then
    echo "      ✓ Coolify is running"
else
    echo "      ✗ Coolify failed to start!"
    echo "      Rolling back..."
    cp "$BACKUP_DIR/.env.backup" .env
    cp docker-compose.yml.backup docker-compose.yml
    docker compose up -d
    exit 1
fi

# Check images
echo ""
echo "Running containers:"
docker ps --format "table {{.Names}}\t{{.Image}}" | grep coolify

echo ""
echo "========================================"
echo "  Upgrade Completed Successfully! ✓"
echo "========================================"
echo ""
echo "Your Coolify now uses custom images from:"
echo "  $COOLIFY_REGISTRY/$COOLIFY_ORG"
echo ""
echo "Access Coolify at:"
echo "  http://$(hostname -I | awk '{print $1}'):8000"
echo ""
echo "Backup saved to:"
echo "  $BACKUP_DIR"
echo ""
echo "To rollback if needed:"
echo "  cp $BACKUP_DIR/.env.backup /data/coolify/source/.env"
echo "  cp $BACKUP_DIR/../docker-compose.yml.backup /data/coolify/source/docker-compose.yml"
echo "  cd /data/coolify/source && docker compose up -d"
echo ""
```

---

## Rollback Procedure

If you need to rollback to official Coolify:

```bash
# Stop custom Coolify
cd /data/coolify/source
docker compose down

# Restore original configuration
BACKUP_DIR=$(ls -td /root/coolify-pre-upgrade-* | head -1)
cp "$BACKUP_DIR/.env.backup" .env
cp docker-compose.yml.backup docker-compose.yml

# Pull official images
docker compose pull

# Start with official images
docker compose up -d

echo "Rolled back to official Coolify"
```

---

## Troubleshooting

### Problem: "Image not found" error

**Cause**: Custom images not accessible

**Solution**:
```bash
# Test image pull manually
docker pull ghcr.io/edesylabs/coolify:latest
docker pull ghcr.io/edesylabs/coolify-helper:latest
docker pull ghcr.io/edesylabs/coolify-realtime:latest

# If fails, verify images are public on GitHub Packages
```

### Problem: Coolify won't start after upgrade

**Cause**: Configuration issue or image incompatibility

**Solution**:
```bash
# Check logs
docker logs coolify

# Rollback to official
BACKUP_DIR=$(ls -td /root/coolify-pre-upgrade-* | head -1)
cp "$BACKUP_DIR/.env.backup" /data/coolify/source/.env
cd /data/coolify/source
docker compose pull
docker compose up -d
```

### Problem: "Database connection failed"

**Cause**: Database didn't start properly

**Solution**:
```bash
# Check database
docker ps | grep postgres
docker logs coolify-db

# Restart database
cd /data/coolify/source
docker compose restart postgres
sleep 10
docker compose restart coolify
```

### Problem: UI shows errors or missing data

**Cause**: Database migration needed or cache issue

**Solution**:
```bash
# Run migrations
docker exec coolify php artisan migrate --force

# Clear caches
docker exec coolify php artisan cache:clear
docker exec coolify php artisan config:clear
docker exec coolify php artisan route:clear

# Restart
cd /data/coolify/source
docker compose restart coolify
```

---

## Verification Checklist

After upgrade, verify:

- [ ] Coolify UI accessible at `http://YOUR_IP:8000`
- [ ] Can login with existing credentials
- [ ] All servers are listed
- [ ] All applications are visible
- [ ] Server status shows "Reachable" and "Usable"
- [ ] Can deploy an application successfully
- [ ] Applications accessible via their domains
- [ ] No errors in docker logs: `docker logs coolify`
- [ ] Custom images in use: `docker ps | grep edesylabs`

---

## Comparison: In-Place vs. New Server Migration

| Factor | In-Place Upgrade | New Server Migration |
|--------|-----------------|---------------------|
| **Time** | ~5-10 minutes | ~45-60 minutes |
| **Complexity** | Low | Medium |
| **Data Transfer** | None needed | Need to transfer backup |
| **Rollback** | Very quick | Requires switching back |
| **Risk** | Low (can rollback) | Very low (old server intact) |
| **Testing** | Limited | Can test thoroughly before cutover |
| **Best For** | Single server, quick upgrade | Production, thorough testing needed |

---

## When to Use In-Place Upgrade

✅ **Use In-Place When:**
- You have only one Coolify server
- You want quick upgrade
- You trust your custom images
- You can afford brief Coolify UI downtime (apps stay up)
- You want to keep same IP address

❌ **Use New Server Migration When:**
- You want to test thoroughly first
- You need 100% rollback capability
- You're upgrading production
- You want to run both in parallel

---

## Post-Upgrade: Using Your Custom Fork

After upgrade, your Coolify will:

1. **Use custom images**: All new deployments use your code
2. **Get updates from your fork**: When you push changes, rebuild images
3. **Install custom version on new servers**: Servers added through UI use your fork

To update after making changes to your fork:

```bash
# On your development machine
git push origin main

# GitHub Actions builds new images automatically

# On your Coolify server
cd /data/coolify/source
docker compose pull
docker compose up -d
```

---

## Future Updates

### Manual Updates

```bash
cd /data/coolify/source
docker compose pull  # Pull latest custom images
docker compose up -d # Restart with new images
```

### Automated Updates

Your custom Coolify will auto-update if:
1. `AUTOUPDATE=true` in `.env`
2. `versions.json` in your repo is updated
3. New images are built and tagged

---

## Summary

In-place upgrade is **simple, fast, and safe**:

1. ✅ Keep all your data
2. ✅ Keep same server
3. ✅ Keep same IP address
4. ✅ ~5-10 minutes total
5. ✅ Can rollback quickly
6. ✅ Applications never go down

Just update configs, pull new images, restart. Done! 🎉

---

## Need Help?

- **In-Place Upgrade Issues**: Check troubleshooting section above
- **New Server Migration**: See [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md)
- **Fresh Install**: See [QUICK_START.md](./QUICK_START.md)
- **Custom Deployment**: See [DEPLOYMENT_GUIDE.md](./DEPLOYMENT_GUIDE.md)
