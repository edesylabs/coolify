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

## Quick Upgrade (2 Commands)

```bash
# 1. Download and run unified script
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh

# 2. Verify
docker ps | grep coolify
```

That's it! The script will auto-detect your setup and guide you through the upgrade with all safety features built-in.

---

## Using the Unified Script

The unified `coolify-custom.sh` script handles all the steps automatically with an interactive menu:

### What the Script Does

1. **Auto-detects** your current scenario:
   - No Coolify installed → offers fresh installation
   - Official Coolify installed → offers upgrade
   - Custom Coolify already running → offers update/rollback options

2. **Interactive Menu** shows relevant options:
   - Upgrade to Custom Coolify (In-Place)
   - Pre-Flight Check Only
   - Update Images
   - Rollback to Official
   - Exit

3. **Safety Features** built-in:
   - Pre-flight validation (17 checks)
   - Automatic backup before changes
   - Step-by-step verification
   - Automatic rollback on error
   - Data integrity protection

### Running the Script

```bash
# Download
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh

# Run
sudo ./coolify.sh
```

### What You'll See

```bash
==============================================
  Coolify Custom Deployment Manager
==============================================

Detecting current scenario...

✓ Coolify installation found
✓ Currently running: Official Coolify

Scenario: NEEDS_UPGRADE
  Your server has official Coolify installed.
  You can upgrade to custom version.

==============================================
  Available Options
==============================================

1. Upgrade to Custom Coolify (In-Place)
   → Upgrade existing Coolify to custom fork
   → Zero downtime for your applications
   → Automatic backup and safety checks

2. Pre-Flight Check Only
   → Check if upgrade is safe
   → No changes made

3. Exit

Select an option (1-3):
```

### After Selecting Upgrade

The script will:
1. Run pre-flight safety checks
2. Create comprehensive backup
3. Update configuration files
4. Pull custom Docker images
5. Restart with new images
6. Verify everything works
7. Show you the results

If anything fails, it automatically rolls back to your previous state.

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

## Manual Steps (Advanced Users)

If you prefer to perform the upgrade manually without the unified script, here are the key steps:

### 1. Create Backup
```bash
BACKUP_DIR="/root/coolify-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
docker exec coolify-db pg_dump -U coolify coolify > "$BACKUP_DIR/coolify-db.sql"
cp /data/coolify/source/.env "$BACKUP_DIR/.env.backup"
```

### 2. Update Configuration
Edit `/data/coolify/source/.env` and add:
```bash
REGISTRY_URL=ghcr.io
COOLIFY_IMAGE_NAMESPACE=edesylabs
```

### 3. Update Docker Compose
Edit `/data/coolify/source/docker-compose.yml` and change image references from `ghcr.io/coollabsio` to `${REGISTRY_URL:-ghcr.io}/${COOLIFY_IMAGE_NAMESPACE:-edesylabs}`

### 4. Apply Changes
```bash
cd /data/coolify/source
docker compose pull
docker compose down
docker compose up -d
```

### 5. Verify
```bash
docker ps | grep coolify
docker logs coolify --tail 50
```

**Note:** The unified script handles all these steps automatically with error handling and rollback. It's the recommended approach.

---

## Rollback Procedure

### Using the Unified Script (Recommended)

The unified script includes a rollback option:

```bash
sudo ./coolify.sh
# Select "Rollback to Official Coolify"
```

### Manual Rollback

If you need to rollback manually:

```bash
# Stop custom Coolify
cd /data/coolify/source
docker compose down

# Restore original configuration
BACKUP_DIR=$(ls -td /root/coolify-pre-upgrade-* | head -1)
cp "$BACKUP_DIR/.env.backup" .env

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
