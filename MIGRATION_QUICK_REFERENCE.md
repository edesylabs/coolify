# Migration Quick Reference Card

Quick commands for migrating from official Coolify to your custom fork.

## 📋 Prerequisites

- [ ] Old Coolify server (with data to migrate)
- [ ] New server for custom Coolify
- [ ] Custom Docker images built and public on GitHub Container Registry
- [ ] SSH access to both servers as root

---

## 🔄 3-Step Migration Process

### Step 1: Backup Old Coolify (5-10 min)

**On OLD server:**

```bash
# Download and run backup script
wget https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/backup-for-migration.sh
chmod +x backup-for-migration.sh
./backup-for-migration.sh

# Transfer to new server
scp /root/coolify-migration-*.tar.gz root@NEW_SERVER_IP:/root/
```

---

### Step 2: Install Custom Coolify (10-15 min)

**On NEW server:**

```bash
# Install custom Coolify using unified script
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh  # Select "Install Custom Coolify" from menu
```

---

### Step 3: Restore Data (10-15 min)

**On NEW server:**

```bash
# Download and run restore script
wget https://raw.githubusercontent.com/edesylabs/coolify/main/scripts/restore-from-migration.sh
chmod +x restore-from-migration.sh
./restore-from-migration.sh
```

---

## ✅ Post-Migration Verification

**On NEW server:**

```bash
# Check Coolify is running
docker ps | grep coolify

# Check database connection
docker exec coolify php artisan tinker --execute="echo App\Models\Server::count() . ' servers';"

# Access UI
echo "http://$(hostname -I | awk '{print $1}'):8000"
```

**In Coolify UI:**

1. Login with existing credentials
2. Go to **Servers** → Click each server → **Validate Connection**
3. Go to **Applications** → Pick one → Click **Deploy** (test)
4. Verify application is accessible

---

## 🔍 Verification Checklist

- [ ] All servers show as "Reachable" and "Usable"
- [ ] All applications are listed
- [ ] Team members can login
- [ ] Test deployment succeeds
- [ ] Applications accessible via their domains
- [ ] Server metrics loading correctly

---

## ⚠️ Important Notes

### Zero Downtime
Your applications on connected servers continue running during the entire migration. Only Coolify control plane is being moved.

### What Gets Migrated
✅ All servers, apps, databases, configs
✅ SSH keys and certificates
✅ User accounts and teams
✅ Environment variables
✅ Proxy configurations

### What Changes
🔄 Coolify now uses your custom Docker images
🔄 Updates come from your fork
🔄 Installation uses your scripts

---

## 🔧 Common Issues & Fixes

### Servers show "Unreachable"

```bash
# In Coolify UI: Servers → Click server → Validate Connection
# If that fails, manually test SSH:
ssh -i /data/coolify/ssh/keys/id.root@host.docker.internal root@SERVER_IP
```

### Database connection failed

```bash
docker exec coolify-db pg_isready -U coolify
docker logs coolify-db
docker compose restart coolify
```

### Custom images not found

```bash
# Verify images are public on GitHub
docker pull ghcr.io/edesylabs/coolify:latest

# Check .env has correct settings
grep REGISTRY_URL /data/coolify/source/.env
```

---

## 📞 Rollback (If Needed)

If something goes wrong:

```bash
# On NEW server: Stop
cd /data/coolify/source && docker compose down

# On OLD server: Resume
cd /data/coolify/source && docker compose up -d
```

Applications continue running! Update DNS back if changed.

---

## 📚 Full Documentation

- **Complete Guide**: [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md)
- **Deployment Setup**: [DEPLOYMENT_GUIDE.md](./DEPLOYMENT_GUIDE.md)
- **Quick Start**: [QUICK_START.md](./QUICK_START.md)

---

## ⏱️ Expected Timeline

| Phase | Duration |
|-------|----------|
| Backup old Coolify | 5-10 min |
| Install new Coolify | 10-15 min |
| Restore data | 10-15 min |
| Verification | 15-20 min |
| **Total** | **~45-60 min** |

**Application Downtime**: 0 minutes ✨

---

## 🎯 Success Criteria

Migration is successful when:

✅ New Coolify UI accessible
✅ All historical data visible
✅ Server connections working
✅ Test deployment succeeds
✅ Applications remain accessible

---

## 🚀 Next Steps After Migration

1. ⏳ Keep old Coolify offline for 1-2 weeks (backup)
2. 🔔 Update notification settings
3. 🔗 Update Git webhooks to new server
4. 🌐 Update DNS (if using custom domain)
5. 📖 Update internal documentation
6. 🎉 Enjoy your custom Coolify!

---

**Need help?** See full [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md) for detailed troubleshooting.
