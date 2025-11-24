# Migrating from Official Coolify to Your Custom Fork

This guide will help you migrate your existing Coolify instance (with all servers, applications, and data) to your custom forked version **without downtime** for your running applications.

## Migration Overview

### What Will Be Migrated:
- ✅ PostgreSQL database (all servers, apps, services, configurations)
- ✅ SSH keys and server connections
- ✅ Environment variables and secrets
- ✅ Application configurations
- ✅ Proxy configurations (Nginx/Traefik)
- ✅ SSL certificates
- ✅ Team and user data

### What Won't Be Affected:
- ✅ Your running applications on connected servers (zero downtime)
- ✅ Your deployed databases
- ✅ Your existing domains and SSL certificates

### Architecture:
```
OLD SETUP:
Server A (Old Coolify) → Controls → Server B, C, D (Your Apps)

NEW SETUP:
Server E (New Custom Coolify) → Controls → Server B, C, D (Your Apps)
```

---

## Prerequisites

- [ ] New server ready for custom Coolify (minimum 2GB RAM, 20GB disk)
- [ ] SSH access to both old and new Coolify servers as root
- [ ] Custom Docker images built and accessible (see QUICK_START.md)
- [ ] Backup space available (estimate: ~500MB-2GB depending on data)

---

## Phase 1: Prepare for Migration (No Downtime)

### Step 1.1: Backup Your Current Coolify

On your **OLD Coolify server** (Server A):

```bash
#!/bin/bash
# Run this on OLD Coolify server

echo "Creating Coolify migration backup..."

# Create backup directory with timestamp
BACKUP_DIR="/root/coolify-migration-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

echo "Backup directory: $BACKUP_DIR"

# 1. Backup PostgreSQL database
echo "Backing up database..."
docker exec coolify-db pg_dump -U coolify coolify > "$BACKUP_DIR/coolify-db.sql"

# 2. Backup environment file
echo "Backing up environment configuration..."
cp /data/coolify/source/.env "$BACKUP_DIR/.env.backup"

# 3. Backup SSH keys
echo "Backing up SSH keys..."
cp -r /data/coolify/ssh "$BACKUP_DIR/ssh"

# 4. Backup docker-compose files (if customized)
if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
    cp /data/coolify/source/docker-compose.custom.yml "$BACKUP_DIR/"
fi

# 5. Backup proxy configurations
echo "Backing up proxy configurations..."
cp -r /data/coolify/proxy "$BACKUP_DIR/proxy" 2>/dev/null || true

# 6. Backup SSL certificates
echo "Backing up SSL certificates..."
cp -r /data/coolify/ssl "$BACKUP_DIR/ssl" 2>/dev/null || true

# 7. Create metadata file
echo "Creating metadata file..."
cat > "$BACKUP_DIR/metadata.txt" << EOF
Coolify Backup Metadata
=======================
Backup Date: $(date)
Coolify Version: $(docker exec coolify php artisan --version 2>/dev/null || echo "Unknown")
Server IP: $(hostname -I | awk '{print $1}')
Database Size: $(du -sh "$BACKUP_DIR/coolify-db.sql" | awk '{print $1}')
EOF

# 8. Create archive
echo "Creating compressed archive..."
cd /root
tar -czf "coolify-migration-$(date +%Y%m%d-%H%M%S).tar.gz" "$(basename $BACKUP_DIR)"

echo ""
echo "============================================"
echo "Backup completed successfully!"
echo "============================================"
echo "Backup location: $BACKUP_DIR"
echo "Archive: /root/coolify-migration-*.tar.gz"
echo ""
echo "To transfer to new server, run:"
echo "scp /root/coolify-migration-*.tar.gz root@NEW_SERVER_IP:/root/"
echo ""
```

Save this as `/root/backup-coolify.sh` and run:
```bash
chmod +x /root/backup-coolify.sh
./backup-coolify.sh
```

### Step 1.2: Document Current Configuration

On **OLD Coolify server**, capture current state:

```bash
# Get list of connected servers
docker exec coolify php artisan tinker --execute="echo json_encode(App\Models\Server::all(['id', 'name', 'ip', 'user'])->toArray(), JSON_PRETTY_PRINT);" > /root/servers-list.json

# Get list of applications
docker exec coolify php artisan tinker --execute="echo json_encode(App\Models\Application::all(['id', 'name', 'fqdn', 'git_repository'])->toArray(), JSON_PRETTY_PRINT);" > /root/applications-list.json

# Current Coolify version
docker exec coolify php artisan --version > /root/coolify-version.txt

echo "Current configuration saved to /root/*-list.json"
```

---

## Phase 2: Install Custom Coolify (No Downtime)

### Step 2.1: Install Your Custom Coolify on NEW Server

On your **NEW Coolify server** (Server E):

```bash
# Install your custom Coolify
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh
```

Select "Install Custom Coolify" from the menu. Wait for installation to complete. **Don't configure it yet.**

### Step 2.2: Stop New Coolify (Temporarily)

On **NEW server**:

```bash
cd /data/coolify/source
docker compose down
```

---

## Phase 3: Transfer and Restore Data

### Step 3.1: Transfer Backup to New Server

On **OLD server**:

```bash
# Transfer backup archive
scp /root/coolify-migration-*.tar.gz root@NEW_SERVER_IP:/root/

# Transfer configuration lists
scp /root/*-list.json /root/coolify-version.txt root@NEW_SERVER_IP:/root/
```

### Step 3.2: Restore Data on New Server

On **NEW server**, create and run this restoration script:

```bash
#!/bin/bash
# Run this on NEW Coolify server

echo "Starting Coolify migration restore..."

# Find the backup archive
BACKUP_ARCHIVE=$(ls -t /root/coolify-migration-*.tar.gz | head -1)

if [ -z "$BACKUP_ARCHIVE" ]; then
    echo "ERROR: No backup archive found!"
    exit 1
fi

echo "Found backup: $BACKUP_ARCHIVE"

# Extract archive
cd /root
tar -xzf "$BACKUP_ARCHIVE"
BACKUP_DIR=$(tar -tzf "$BACKUP_ARCHIVE" | head -1 | cut -f1 -d"/")

echo "Extracted to: /root/$BACKUP_DIR"

# Stop Coolify if running
cd /data/coolify/source
docker compose down

echo "Waiting for containers to stop..."
sleep 5

# 1. Restore SSH keys
echo "Restoring SSH keys..."
rm -rf /data/coolify/ssh
cp -r "/root/$BACKUP_DIR/ssh" /data/coolify/
chown -R 9999:root /data/coolify/ssh
chmod -R 700 /data/coolify/ssh

# 2. Restore proxy configurations
echo "Restoring proxy configurations..."
if [ -d "/root/$BACKUP_DIR/proxy" ]; then
    cp -r "/root/$BACKUP_DIR/proxy" /data/coolify/
fi

# 3. Restore SSL certificates
echo "Restoring SSL certificates..."
if [ -d "/root/$BACKUP_DIR/ssl" ]; then
    cp -r "/root/$BACKUP_DIR/ssl" /data/coolify/
fi

# 4. Merge environment files
echo "Restoring environment configuration..."

# Backup the new .env (has custom settings)
cp /data/coolify/source/.env /data/coolify/source/.env.new

# Copy old .env
cp "/root/$BACKUP_DIR/.env.backup" /data/coolify/source/.env.old

# Merge: Keep custom settings from new .env, everything else from old
# Custom settings to preserve
CUSTOM_VARS="REGISTRY_URL COOLIFY_IMAGE_NAMESPACE COOLIFY_CDN COOLIFY_RELEASES_URL HELPER_IMAGE REALTIME_IMAGE"

# Start with old .env
cp /data/coolify/source/.env.old /data/coolify/source/.env

# Override with custom variables from new .env
for var in $CUSTOM_VARS; do
    if grep -q "^${var}=" /data/coolify/source/.env.new; then
        value=$(grep "^${var}=" /data/coolify/source/.env.new | cut -d'=' -f2-)
        if grep -q "^${var}=" /data/coolify/source/.env; then
            # Replace existing
            sed -i "s|^${var}=.*|${var}=${value}|" /data/coolify/source/.env
        else
            # Add new
            echo "${var}=${value}" >> /data/coolify/source/.env
        fi
    fi
done

# 5. Start Coolify with database
echo "Starting Coolify (database only)..."
docker compose up -d postgres redis

echo "Waiting for database to be ready..."
sleep 15

# 6. Restore database
echo "Restoring database..."
cat "/root/$BACKUP_DIR/coolify-db.sql" | docker exec -i coolify-db psql -U coolify -d coolify

if [ $? -eq 0 ]; then
    echo "Database restored successfully!"
else
    echo "ERROR: Database restore failed!"
    exit 1
fi

# 7. Start all Coolify services
echo "Starting all Coolify services..."
docker compose up -d

echo "Waiting for Coolify to start..."
sleep 20

# 8. Run migrations (in case of version differences)
echo "Running database migrations..."
docker exec coolify php artisan migrate --force

# 9. Clear caches
echo "Clearing caches..."
docker exec coolify php artisan cache:clear
docker exec coolify php artisan config:clear
docker exec coolify php artisan route:clear

# 10. Set permissions
echo "Setting permissions..."
chown -R 9999:root /data/coolify
chmod -R 700 /data/coolify

echo ""
echo "============================================"
echo "Migration restore completed!"
echo "============================================"
echo ""
echo "Next steps:"
echo "1. Access new Coolify at http://$(hostname -I | awk '{print $1}'):8000"
echo "2. Login with your existing credentials"
echo "3. Verify all servers and applications are visible"
echo "4. Update DNS/Load balancer to point to new server"
echo ""
```

Save this as `/root/restore-coolify.sh` and run:
```bash
chmod +x /root/restore-coolify.sh
./restore-coolify.sh
```

---

## Phase 4: Verification and Testing

### Step 4.1: Verify Migration

On **NEW Coolify server**:

```bash
# Check if Coolify is running
docker ps | grep coolify

# Check logs for errors
docker logs coolify --tail 100

# Verify database connection
docker exec coolify php artisan tinker --execute="echo 'DB Connection: ' . (DB::connection()->getPdo() ? 'OK' : 'FAILED');"

# List servers
docker exec coolify php artisan tinker --execute="echo 'Servers: ' . App\Models\Server::count();"

# List applications
docker exec coolify php artisan tinker --execute="echo 'Applications: ' . App\Models\Application::count();"
```

### Step 4.2: Access New Coolify UI

1. Open browser: `http://NEW_SERVER_IP:8000`
2. Login with your **existing credentials** from old Coolify
3. Verify:
   - [ ] All servers are listed
   - [ ] All applications are visible
   - [ ] Team members are present
   - [ ] Environment variables are preserved

### Step 4.3: Reconnect Servers

Your connected servers (Server B, C, D) are still running applications, but they're still "listening" to the old Coolify. We need to update them:

**Option A: Automatic Reconnection (Recommended)**

In the **NEW Coolify UI**:

1. Go to **Servers**
2. For each server, click **"Validate Connection"**
3. Coolify will re-establish SSH connections using the migrated keys
4. Server status should change to "Reachable" and "Usable"

**Option B: Manual Reconnection (If Option A fails)**

On **each connected server** (Server B, C, D):

```bash
# Remove old Coolify's SSH key from authorized_keys
nano ~/.ssh/authorized_keys
# Delete the line with "coolify" comment from OLD server

# Add new Coolify's SSH key
# Get the public key from NEW Coolify server:
# cat /data/coolify/ssh/keys/id.root@host.docker.internal

# Then add it to connected servers:
echo "YOUR_NEW_COOLIFY_PUBLIC_KEY" >> ~/.ssh/authorized_keys
```

Then validate in NEW Coolify UI.

---

## Phase 5: Update and Cutover

### Step 5.1: Test Critical Operations

In **NEW Coolify UI**, test:

1. **Deploy an application**
   - Find an existing app
   - Click "Deploy"
   - Verify deployment succeeds

2. **Check server resources**
   - Go to a server
   - Verify metrics are loading

3. **Test proxy**
   - Access one of your deployed applications
   - Verify it's accessible via domain

### Step 5.2: Update DNS/Load Balancer (If Applicable)

If you're using a domain for Coolify access:

```bash
# Update your DNS A record:
# coolify.yourdomain.com → NEW_SERVER_IP

# Or update load balancer to point to new server
```

### Step 5.3: Keep Old Coolify as Backup (Recommended)

**Don't delete the old Coolify immediately!**

Keep it running for 1-2 weeks as backup:

```bash
# On OLD server - just stop it
cd /data/coolify/source
docker compose down
```

Your applications will continue running on connected servers without any issues.

---

## Phase 6: Post-Migration Tasks

### Step 6.1: Update Server Webhooks (If Using)

If you have GitHub/GitLab webhooks for auto-deployment:

1. Go to your Git repository settings
2. Find Coolify webhooks pointing to OLD server
3. Update webhook URLs to NEW server IP
4. Test webhook delivery

### Step 6.2: Update Notification Settings

In NEW Coolify UI:
- Settings → Notifications
- Re-configure email, Discord, Telegram, etc.
- Test notifications

### Step 6.3: Verify Scheduled Tasks

Check that scheduled tasks are running:

```bash
# On NEW Coolify server
docker exec coolify php artisan schedule:list
docker logs coolify | grep -i "schedule"
```

### Step 6.4: Update Documentation

Update your internal documentation with:
- New Coolify server IP
- New access URLs
- Updated backup procedures

---

## Troubleshooting

### Problem: "Database connection failed" after restore

**Solution:**
```bash
# Check database is running
docker ps | grep postgres

# Check database logs
docker logs coolify-db

# Verify credentials in .env
grep DB_ /data/coolify/source/.env

# Restart Coolify
cd /data/coolify/source
docker compose restart coolify
```

### Problem: Servers show as "Unreachable"

**Solution:**
```bash
# Check SSH keys were restored
ls -la /data/coolify/ssh/keys/

# Test SSH connection manually from NEW Coolify server
ssh -i /data/coolify/ssh/keys/id.root@host.docker.internal root@SERVER_IP

# If fails, regenerate SSH key in Coolify UI and add to servers
```

### Problem: Applications don't appear in UI

**Solution:**
```bash
# Verify database was restored correctly
docker exec coolify-db psql -U coolify -d coolify -c "SELECT COUNT(*) FROM applications;"

# Check for migration errors
docker exec coolify php artisan migrate:status

# Clear all caches
docker exec coolify php artisan cache:clear
docker exec coolify php artisan config:clear
```

### Problem: Custom images not pulling

**Solution:**
```bash
# Verify .env has custom settings
grep -E "REGISTRY_URL|COOLIFY_IMAGE" /data/coolify/source/.env

# Should show:
# REGISTRY_URL=ghcr.io
# COOLIFY_IMAGE_NAMESPACE=edesylabs

# Test image pull
docker pull ghcr.io/edesylabs/coolify:latest

# If fails, images might not be public - check GitHub Packages settings
```

---

## Rollback Plan

If something goes wrong and you need to rollback:

### Quick Rollback:

1. **On NEW server**: Stop Coolify
   ```bash
   cd /data/coolify/source && docker compose down
   ```

2. **On OLD server**: Start Coolify
   ```bash
   cd /data/coolify/source && docker compose up -d
   ```

3. **Update DNS back** to old server IP (if changed)

Your applications continue running throughout this process!

### Full Rollback with Data Loss Prevention:

Before migration, take a snapshot of:
- Old Coolify server
- Connected application servers

Use these snapshots if complete rollback is needed.

---

## Migration Checklist

Use this checklist to track your progress:

### Pre-Migration
- [ ] Backup old Coolify completely
- [ ] Document current configuration
- [ ] Install custom Coolify on new server
- [ ] Verify custom Docker images are accessible

### Migration
- [ ] Transfer backup to new server
- [ ] Restore database
- [ ] Restore SSH keys
- [ ] Restore configurations
- [ ] Merge environment files
- [ ] Start new Coolify

### Validation
- [ ] Login to new Coolify works
- [ ] All servers are listed
- [ ] All applications are visible
- [ ] Server connections validated
- [ ] Test deployment works
- [ ] Applications accessible via domains

### Cutover
- [ ] Update DNS/webhooks (if needed)
- [ ] Update notification settings
- [ ] Verify scheduled tasks
- [ ] Keep old Coolify as backup for 1-2 weeks
- [ ] Update documentation

### Cleanup (After 1-2 weeks)
- [ ] Verify new Coolify running smoothly
- [ ] Backup new Coolify
- [ ] Decommission old Coolify server
- [ ] Cancel old server billing (if applicable)

---

## Expected Timeline

- **Backup**: 10-15 minutes
- **Transfer**: 5-10 minutes (depending on data size)
- **Restore**: 15-20 minutes
- **Validation**: 30-45 minutes
- **Total**: ~1-2 hours (with zero downtime for apps)

---

## Important Notes

1. **Zero Downtime**: Your applications on connected servers continue running during the entire migration. Only Coolify control plane is being moved.

2. **SSH Keys**: The restored SSH keys allow new Coolify to connect to existing servers immediately.

3. **Database Compatibility**: Since you're migrating from the same Coolify version (v4.0.0-beta.444), database schema is compatible.

4. **Custom vs Official**: After migration, your new Coolify uses custom images but all functionality remains the same.

5. **Gradual Cutover**: You can run both old and new Coolify in parallel for testing before full cutover.

---

## Need Help?

If you encounter issues:

1. Check `/data/coolify/source/installation-*.log` on new server
2. Check `docker logs coolify` for runtime errors
3. Compare configurations between old and new: `diff /root/coolify-migration-*/env.backup /data/coolify/source/.env`
4. Refer to DEPLOYMENT_GUIDE.md for custom Coolify specifics

---

Your migration is now complete! Your custom Coolify fork is managing all your existing infrastructure. 🎉
