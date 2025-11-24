# Quick Start: Deploy Your Custom Coolify

This guide will get your forked Coolify up and running in minutes.

## Prerequisites Checklist

- [ ] GitHub repository forked: `edesylabs/coolify`
- [ ] Server with Ubuntu/Debian (minimum 2GB RAM, 20GB disk)
- [ ] SSH access to the server as root
- [ ] GitHub Container Registry enabled (Settings > Packages)

---

## Step 1: Build Your Docker Images (One-time setup)

### Method A: GitHub Actions (Automatic - Recommended)

1. Create `.github/workflows/build-images.yml` in your repository:

```bash
mkdir -p .github/workflows
cat > .github/workflows/build-images.yml << 'EOF'
name: Build Docker Images

on:
  push:
    branches: [main]
  workflow_dispatch:

env:
  REGISTRY: ghcr.io
  ORG: edesylabs

jobs:
  build:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    strategy:
      matrix:
        include:
          - name: coolify
            dockerfile: ./docker/prod/Dockerfile
            context: .
          - name: coolify-helper
            dockerfile: ./docker/coolify-helper/Dockerfile
            context: ./docker/coolify-helper
          - name: coolify-realtime
            dockerfile: ./docker/coolify-realtime/Dockerfile
            context: ./docker/coolify-realtime

    steps:
      - uses: actions/checkout@v4

      - name: Log in to GHCR
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: ${{ matrix.context }}
          file: ${{ matrix.dockerfile }}
          push: true
          tags: ghcr.io/${{ env.ORG }}/${{ matrix.name }}:latest
EOF
```

2. Commit and push:
```bash
git add .github/workflows/build-images.yml
git commit -m "Add Docker image build workflow"
git push origin main
```

3. Go to GitHub Actions and verify the build completes successfully

4. Make images public:
   - Go to https://github.com/edesylabs?tab=packages
   - For each package (coolify, coolify-helper, coolify-realtime):
     - Click on the package
     - Click "Package settings"
     - Scroll to "Danger Zone"
     - Click "Change visibility" → "Public"

### Method B: Manual Build (For testing)

```bash
# On your development machine
docker login ghcr.io -u YOUR_GITHUB_USERNAME

# Build and push images
docker build -t ghcr.io/edesylabs/coolify:latest -f docker/prod/Dockerfile .
docker push ghcr.io/edesylabs/coolify:latest

docker build -t ghcr.io/edesylabs/coolify-helper:latest docker/coolify-helper/
docker push ghcr.io/edesylabs/coolify-helper:latest

docker build -t ghcr.io/edesylabs/coolify-realtime:latest docker/coolify-realtime/
docker push ghcr.io/edesylabs/coolify-realtime:latest
```

---

## Step 2: Install on Your Server

### Recommended: Using the Unified Script

```bash
# On your server (as root)
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh -o coolify.sh
chmod +x coolify.sh
sudo ./coolify.sh
```

The script will auto-detect that Coolify is not installed and offer to install it.

### Alternative: Manual Download and Review

```bash
# Download the script
wget https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/coolify-custom.sh

# Review it (optional but recommended)
less coolify-custom.sh

# Run it
chmod +x coolify-custom.sh
sudo ./coolify-custom.sh
```

---

## Step 3: Configure Your Installation

After installation, edit the environment file:

```bash
nano /data/coolify/source/.env
```

Add these lines (or verify they exist):

```bash
# Custom deployment settings
REGISTRY_URL=ghcr.io
COOLIFY_IMAGE_NAMESPACE=edesylabs
COOLIFY_CDN=https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main
```

Restart Coolify to apply changes:

```bash
cd /data/coolify/source
docker compose down
docker compose up -d
```

---

## Step 4: Access Your Coolify Instance

1. Get your server's IP address:
```bash
hostname -I | awk '{print $1}'
```

2. Open in browser:
```
http://YOUR_SERVER_IP:8000
```

3. Create your admin account on first access

---

## Step 5: Add Your First Server

In the Coolify UI:

1. Go to **Servers** → **Add New Server**
2. Choose "Add by IP"
3. Enter server details:
   - **Name**: My App Server
   - **IP Address**: Your target server IP
   - **Port**: 22
   - **User**: root
4. Click **Validate Connection**

The server will automatically install using your custom Coolify configuration!

---

## Verification

Verify your custom version is running:

```bash
# Check Docker images
docker ps --format "table {{.Names}}\t{{.Image}}"

# Should show:
# coolify             ghcr.io/edesylabs/coolify:latest
# coolify-realtime    ghcr.io/edesylabs/coolify-realtime:latest
# coolify-db          postgres:...
# coolify-redis       redis:...

# Check configuration
grep -E "(REGISTRY_URL|COOLIFY_)" /data/coolify/source/.env

# Should show your custom settings
```

---

## Troubleshooting

### Problem: Installation fails with "image not found"

**Solution:**
```bash
# Verify images are public and accessible
docker pull ghcr.io/edesylabs/coolify:latest
docker pull ghcr.io/edesylabs/coolify-helper:latest
docker pull ghcr.io/edesylabs/coolify-realtime:latest
```

### Problem: Cannot access Coolify UI

**Solution:**
```bash
# Check if Coolify is running
docker ps | grep coolify

# Check logs
docker logs coolify

# Check firewall
sudo ufw status
sudo ufw allow 8000/tcp
```

### Problem: New servers install official Coolify

**Solution:**
```bash
# Verify .env has correct settings
cat /data/coolify/source/.env | grep REGISTRY_URL

# Should show: REGISTRY_URL=ghcr.io
# If not, add it and restart
```

---

## What's Next?

1. **Customize your fork** - Add your features
2. **Set up CI/CD** - Automate image builds on every commit
3. **Configure backups** - Set up database backups
4. **Add monitoring** - Set up server monitoring
5. **Deploy applications** - Start deploying your apps!

---

## Updating Your Custom Coolify

When you make changes to your fork:

```bash
# 1. Push changes to GitHub
git push origin main

# 2. GitHub Actions builds new images automatically

# 3. On your Coolify server, pull updates
cd /data/coolify/source
./upgrade.sh latest latest ghcr.io

# Or restart with latest images
docker compose pull
docker compose up -d
```

---

## Getting Help

- **Full Documentation**: See [DEPLOYMENT_GUIDE.md](./DEPLOYMENT_GUIDE.md)
- **Installation Logs**: `/data/coolify/source/installation-*.log`
- **Runtime Logs**: `docker logs coolify`
- **Official Coolify Docs**: https://coolify.io/docs

---

## Summary

You now have:
- ✅ Custom Docker images hosted on GitHub Container Registry
- ✅ Modified installation scripts using your repository
- ✅ Coolify running with your custom configuration
- ✅ Ability to add servers that use your custom version
- ✅ Automatic updates from your fork

Your custom Coolify is ready to use! 🎉
