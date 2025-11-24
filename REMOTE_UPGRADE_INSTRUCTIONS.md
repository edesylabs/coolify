# How to Upgrade Coolify on Remote Server

Your server only has Coolify installed via the installation script - it doesn't have your repository or custom scripts. Here's how to run the deployment.

## Method 1: Direct Download from GitHub (Recommended)

### Step 1: SSH into your Coolify server

```bash
ssh root@your-coolify-server-ip
```

### Step 2: Download the unified script

```bash
# Download the all-in-one script directly from your GitHub repository
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh

# Make it executable
chmod +x coolify.sh
```

### Step 3: Run the script

```bash
# Run with sudo
sudo ./coolify.sh
```

The script will:
- Auto-detect your scenario (fresh install, needs upgrade, or already custom)
- Show you an interactive menu with relevant options
- Guide you through the process with all safety checks built-in

---

## Method 2: One-Liner (Quick Method)

If you trust the script and want to run it immediately:

```bash
# SSH into server, download and run in one command
ssh root@your-server-ip

# Download and run unified script
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh && chmod +x coolify.sh && sudo ./coolify.sh
```

**Note:** The script auto-detects your scenario and shows an interactive menu. All safety checks are built-in.

---

## Method 3: Download and Review First (Safest)

If you want to review the script before running:

```bash
# 1. SSH into server
ssh root@your-server-ip

# 2. Download the script
wget https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh

# 3. Review the script
cat coolify-custom.sh
# or
less coolify-custom.sh

# 4. Make executable
chmod +x coolify-custom.sh

# 5. Run when ready
sudo ./coolify-custom.sh
```

---

## Method 4: Using SCP (Copy from Local Machine)

If you have the repository cloned locally:

```bash
# From your local machine where you have the repo
cd /Users/suvojit/edesy-labs/coolify

# Copy script to server
scp scripts/coolify-custom.sh root@your-server-ip:/root/coolify.sh

# SSH into server
ssh root@your-server-ip

# Make executable and run
chmod +x /root/coolify.sh
sudo ./coolify.sh
```

---

## What Happens When You Run It

```bash
root@coolify-server:~# sudo ./coolify.sh

==============================================
  Coolify Custom Deployment Manager
==============================================

Detecting current scenario...

✓ Coolify installation found
✓ Currently running: Official Coolify
  Version: 4.0.0-beta.444

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

**After selecting option 1 (Upgrade):**

```bash
==============================================
  Pre-Flight Safety Checks
==============================================

✓ Running as root
✓ Coolify installation found
✓ Coolify containers running
✓ Database is accessible
✓ Sufficient disk space: 50GB
✓ Docker version: 24.0.5
✓ Internet connectivity
✓ Custom images accessible

✓ Pre-flight checks passed!

This will upgrade your Coolify to use custom images from:
  ghcr.io/edesylabs

Safety guarantees:
  ✓ Automatic backup before changes
  ✓ Automatic rollback if anything fails
  ✓ Zero data loss

Do you want to proceed? (yes/no):
```

---

## Complete Step-by-Step Commands

Here's everything in one copy-paste block:

### For Standard Deployment:

```bash
# 1. SSH into your Coolify server
ssh root@your-coolify-server-ip

# 2. Download the unified script
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh

# 3. Make it executable
chmod +x coolify.sh

# 4. Run the script
sudo ./coolify.sh
```

### For Quick One-Liner:

```bash
# SSH and run in one go
ssh root@your-coolify-server-ip "curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh && chmod +x coolify.sh && sudo ./coolify.sh"
```

---

## Important Notes

### 1. **GitHub Repository Must Be Public (for scripts)**

For the `curl` commands to work, make sure:
- Your repository `edesylabs/coolify` is public
- Or the `scripts/` folder is accessible publicly
- Or you have a personal access token configured

**To check if your script is accessible:**
```bash
# Test from your local machine
curl -I https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh

# Should return: HTTP/2 200
# If returns 404, the file isn't accessible
```

### 2. **Alternative: Use GitHub Release Assets**

If you don't want to make the repository public, you can create a GitHub release:

```bash
# 1. Create a release on GitHub with the scripts attached

# 2. Download from release URL
curl -fsSL https://github.com/edesylabs/coolify/releases/download/v1.0.0/coolify-custom.sh -o coolify.sh
```

### 3. **Alternative: Host Scripts on Your Own Server**

Upload scripts to your own server:

```bash
# Download from your own server
curl -fsSL https://your-domain.com/scripts/coolify-custom.sh -o coolify.sh
```

---

## Troubleshooting

### Problem: "curl: command not found"

```bash
# Install curl first
apt-get update && apt-get install -y curl

# Or use wget instead
wget https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -O coolify.sh
```

### Problem: "404 Not Found" when downloading

**Causes:**
- Repository is private
- Script not pushed to GitHub yet
- Wrong branch name (should be `main` not `master`)

**Solutions:**

1. **Check if repository is public:**
   - Go to https://github.com/edesylabs/coolify/settings
   - Scroll to "Danger Zone"
   - Make repository public

2. **Check if files are pushed:**
   ```bash
   git add scripts/coolify-custom.sh
   git commit -m "Add unified deployment script"
   git push origin main
   ```

3. **Use correct branch name:**
   - If your default branch is `master`, change URL to:
   ```bash
   https://raw.githubusercontent.com/edesylabs/coolify/master/scripts/coolify-custom.sh
   ```

### Problem: "Permission denied"

```bash
# Make sure you're running as root
sudo su -

# Or prefix commands with sudo
sudo ./coolify.sh
```

---

## Verification

After downloading, verify the script is correct:

```bash
# Check file exists and has content
ls -lh coolify.sh

# Should show something like:
# -rwxr-xr-x 1 root root 25K Nov 24 10:30 coolify.sh

# Check first few lines
head -20 coolify.sh

# Should show the unified script header
```

---

## What Gets Downloaded

When you download the unified script, you get:
- ✅ Scenario auto-detection
- ✅ Interactive menu system
- ✅ Pre-flight validation (built-in)
- ✅ Fresh installation capability
- ✅ Upgrade process
- ✅ Rollback capability
- ✅ Automatic backup
- ✅ Automatic rollback on error
- ✅ Update images functionality
- ✅ All safety features in one script

**You don't need** to download the entire repository - just the one script!

---

## Summary Commands

**Recommended Approach:**
```bash
# On your Coolify server
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh
```

**That's it!** The script handles everything else with an interactive menu. 🚀
