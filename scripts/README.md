# Coolify Custom Deployment Scripts

This directory contains scripts for deploying and managing your custom Coolify fork.

## 🚀 One Command: Install or Update (`deploy.sh`)

Run as root on any server. It detects whether Coolify is installed and does the right thing, with no prompts:

```bash
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/deploy.sh | bash
```

- **Not installed** → runs `install.sh`
- **Installed** → backs up the Coolify database to `/data/coolify/backups/deploy/`, points `.env` at the edesylabs images, then runs `upgrade.sh`
- **Always** → waits for `coolify` to be healthy and checks it runs the expected image with PHP ≥ 8.4.27

| Option | Example |
|---|---|
| Specific version | `curl -fsSL …/scripts/deploy.sh \| bash -s -- 4.1.0-beta.4` |
| Preview only | `curl -fsSL …/scripts/deploy.sh \| DRY_RUN=true bash` |
| Skip DB backup | `curl -fsSL …/scripts/deploy.sh \| SKIP_BACKUP=true bash` |

## 🎯 Interactive Manager (Migration / Rollback)

### **`coolify-custom.sh`** - All-in-One Deployment Manager

**This is the ONLY script you need!**

Handles everything:
- ✅ Fresh installation
- ✅ In-place upgrade (official → custom)
- ✅ Update images (custom → latest custom)
- ✅ Rollback (custom → official)
- ✅ Pre-flight safety checks
- ✅ Backup creation
- ✅ Automatic error recovery

**Usage:**
```bash
# Download
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh

# Run
chmod +x coolify.sh
sudo ./coolify.sh
```

The script will:
1. Auto-detect your scenario
2. Show an interactive menu
3. Guide you through the process
4. Handle all safety checks automatically

---

## 📦 Migration Scripts (For Moving to New Server)

These are **only needed** if you're migrating to a completely different server (not upgrading in-place).

### **`backup-for-migration.sh`** - Create Migration Backup

Creates a complete backup for migrating to a new server.

**When to use:** You want to move Coolify from Server A to Server B

**Usage on OLD server:**
```bash
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/backup-for-migration.sh -o backup.sh
chmod +x backup.sh
./backup.sh
```

Creates: `/root/coolify-migration-TIMESTAMP.tar.gz`

---

### **`restore-from-migration.sh`** - Restore on New Server

Restores backup on a new server.

**When to use:** After installing custom Coolify on new server, restore old data

**Usage on NEW server:**
```bash
# 1. First install custom Coolify
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh  # Select "Install Custom Coolify"

# 2. Transfer backup from old server
scp root@OLD_SERVER:/root/coolify-migration-*.tar.gz /root/

# 3. Restore
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/restore-from-migration.sh -o restore.sh
chmod +x restore.sh
./restore.sh
```

---

## 🗂️ Script Overview

| Script | Purpose | When to Use |
|--------|---------|-------------|
| **coolify-custom.sh** | ⭐ Main script - does everything | **Use this for 99% of cases** |
| backup-for-migration.sh | Create migration backup | Moving to NEW server |
| restore-from-migration.sh | Restore on new server | Moving to NEW server |

---

## 📋 Quick Decision Guide

### **Want to upgrade existing Coolify on same server?**
→ Use `coolify-custom.sh` (option 1: Upgrade)

### **Want to install fresh custom Coolify?**
→ Use `coolify-custom.sh` (option 1: Install)

### **Want to rollback to official Coolify?**
→ Use `coolify-custom.sh` (option: Rollback)

### **Want to move Coolify to different server?**
→ Use `backup-for-migration.sh` + `restore-from-migration.sh`

### **Want to check if upgrade is safe?**
→ Use `coolify-custom.sh` (option 2: Pre-Flight Check)

### **Want to update to latest custom images?**
→ Use `coolify-custom.sh` (option: Update Images)

---

## 🚀 Most Common Usage

**99% of users will do this:**

```bash
# On your Coolify server
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh
```

Then select from the menu! That's it! 🎉

---

## 📚 Documentation

For detailed guides, see:
- [QUICK_START.md](../QUICK_START.md) - First-time setup
- [REMOTE_UPGRADE_INSTRUCTIONS.md](../REMOTE_UPGRADE_INSTRUCTIONS.md) - How to use on remote servers
- [MIGRATION_GUIDE.md](../MIGRATION_GUIDE.md) - Moving to new server
- [SAFETY_AND_RECOVERY.md](../SAFETY_AND_RECOVERY.md) - Safety features and recovery
- [README_DEPLOYMENT.md](../README_DEPLOYMENT.md) - Complete deployment reference

---

## 🔧 Development

### Adding New Features to Main Script

The `coolify-custom.sh` script is modular and easy to extend:

- Add new functions for new operations
- Add menu options in `show_menu()` function
- Add handlers in `handle_choice()` function
- All safety features are built-in

### Testing Scripts Locally

```bash
# Always test on a non-production server first!
./coolify-custom.sh
```

---

## 📝 Change Log

### v2.0 - Unified Script
- ✅ Created single `coolify-custom.sh` with all features
- ✅ Removed redundant individual scripts
- ✅ Added auto-detection and interactive menu
- ✅ Kept migration scripts for server-to-server moves

### v1.0 - Initial Release
- Individual scripts for each operation
- (Deprecated - use v2.0)

---

**Keep it simple. Use `coolify-custom.sh` for everything!** 🚀
