# Custom Coolify Deployment - Quick Reference

**One-stop guide for deploying your custom Coolify fork.**

---

## 🚀 Quick Start (3 Steps)

### Step 1: Build Your Docker Images (One-Time)

See [QUICK_START.md](./QUICK_START.md) for building and publishing images to GitHub Container Registry.

**Quick version:**
1. Create `.github/workflows/build-images.yml` (template in QUICK_START.md)
2. Push to GitHub - images build automatically
3. Make images public on GitHub Packages

---

### Step 2: Deploy Using Unified Script

**On your server (via SSH):**

```bash
# Download and run the all-in-one script
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh
```

**That's it!** The script automatically:
- ✅ Auto-detects your scenario (fresh install, upgrade, already custom)
- ✅ Shows interactive menu with relevant options
- ✅ Pre-flight safety checks
- ✅ Backs up your data
- ✅ Upgrades/installs custom images
- ✅ Rolls back on any error

---

### Step 3: Verify

```bash
# Check running containers
docker ps | grep coolify

# Should show your custom images:
# ghcr.io/edesylabs/coolify:latest
```

**Access Coolify UI:** `http://your-server-ip:8000`

---

## 📚 Complete Documentation

### For Different Scenarios:

| Scenario | Guide |
|----------|-------|
| **Upgrade existing server** | [REMOTE_UPGRADE_INSTRUCTIONS.md](./REMOTE_UPGRADE_INSTRUCTIONS.md) ⭐ |
| **New server installation** | [QUICK_START.md](./QUICK_START.md) |
| **Migrate to new server** | [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md) |
| **Compare options** | [MIGRATION_COMPARISON.md](./MIGRATION_COMPARISON.md) |
| **Safety & recovery** | [SAFETY_AND_RECOVERY.md](./SAFETY_AND_RECOVERY.md) |
| **Full deployment guide** | [DEPLOYMENT_GUIDE.md](./DEPLOYMENT_GUIDE.md) |
| **In-place upgrade details** | [IN_PLACE_UPGRADE_GUIDE.md](./IN_PLACE_UPGRADE_GUIDE.md) |

---

## 🛠️ Available Scripts

### Main Unified Script (Use This!)

```bash
# All-in-one script for install, upgrade, rollback, and more
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh
```

**What it does:**
- Auto-detects your scenario (fresh install, needs upgrade, already custom)
- Shows interactive menu with relevant options
- Pre-flight checks, backups, upgrades, rollbacks - all included!

### For New Server Migration Only:

```bash
# On OLD server - create backup
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/backup-for-migration.sh -o backup.sh
chmod +x backup.sh
./backup.sh

# On NEW server - install custom Coolify
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh  # Select "Install Custom Coolify"

# On NEW server - restore backup
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/restore-from-migration.sh -o restore.sh
chmod +x restore.sh
./restore.sh
```

---

## ⚡ Command Cheat Sheet

### Most Common Use Case: Upgrade Existing Server

```bash
# SSH into your Coolify server
ssh root@your-server-ip

# Download and run unified script
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/coolify-custom.sh -o coolify.sh && chmod +x coolify.sh && sudo ./coolify.sh
```

### Check Status After Upgrade

```bash
# Verify custom images running
docker ps --format "table {{.Names}}\t{{.Image}}" | grep coolify

# Check logs
docker logs coolify --tail 50

# Check data integrity
docker exec coolify php artisan tinker --execute="echo 'Servers: ' . App\Models\Server::count() . ', Apps: ' . App\Models\Application::count();"
```

---

## 🔧 Configuration

### Environment Variables

On your server, edit `/data/coolify/source/.env`:

```bash
# Custom deployment settings
REGISTRY_URL=ghcr.io
COOLIFY_IMAGE_NAMESPACE=edesylabs

# Optional: Explicit image references
HELPER_IMAGE=ghcr.io/edesylabs/coolify-helper
REALTIME_IMAGE=ghcr.io/edesylabs/coolify-realtime

# Optional: Custom CDN for updates
COOLIFY_CDN=https://raw.githubusercontent.com/edesylabs/coolify/main
```

---

## 🆘 Troubleshooting

### Issue: "404 Not Found" when downloading scripts

**Cause:** Repository is private or scripts not pushed

**Solution:**
```bash
# Make repository public or use SCP
scp scripts/coolify-custom.sh root@your-server:/root/coolify.sh
```

### Issue: "Cannot pull custom images"

**Cause:** Images not built or not public

**Solution:**
1. Check images exist: `docker pull ghcr.io/edesylabs/coolify:latest`
2. Make images public on GitHub Packages
3. See QUICK_START.md for building images

### Issue: Upgrade failed

**Result:** Script automatically rolls back

**Action:** Check error message, fix issue, try again

---

## 📞 Support

- **Documentation Issues:** See specific guide above
- **Script Issues:** Check [SAFETY_AND_RECOVERY.md](./SAFETY_AND_RECOVERY.md)
- **Recovery:** All backups saved to `/root/coolify-pre-upgrade-*/`

---

## ✅ Checklist

### Before Upgrade:
- [ ] Custom Docker images built and public
- [ ] Test image pull: `docker pull ghcr.io/edesylabs/coolify:latest`
- [ ] Repository public or scripts accessible
- [ ] Have SSH access to server

### After Upgrade:
- [ ] Login to Coolify UI works
- [ ] All servers visible
- [ ] All applications visible
- [ ] Test deployment succeeds
- [ ] Keep backup for 1-2 weeks

---

## 🎯 Next Steps After Successful Upgrade

1. **Verify Everything Works**
   - Login to UI
   - Check all pages
   - Test a deployment

2. **Update Webhooks** (if using Git auto-deploy)
   - Update webhook URLs if server IP changed
   - Test webhook delivery

3. **Monitor for 24 Hours**
   - Watch for errors
   - Check logs periodically
   - Keep backup until verified

4. **Update Your Workflow**
   - When you push code changes
   - GitHub Actions builds new images
   - Run upgrade script again to update

---

**You're all set! Your custom Coolify is ready to use.** 🎉

For detailed information, see the specific guides linked above.
