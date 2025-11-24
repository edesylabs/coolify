# Custom Coolify Deployment Guide

This guide explains how to deploy your forked Coolify version instead of the official one.

## Overview

Your forked Coolify requires customization in three main areas:
1. **Installation/Upgrade Scripts** - Hosted files that servers download
2. **Docker Images** - Custom built images for your fork
3. **Configuration** - Environment variables for customization

## Prerequisites

- GitHub repository: `edesylabs/coolify`
- Docker registry access (GitHub Container Registry recommended)
- Basic understanding of Docker and CI/CD

---

## Part 1: Docker Images Setup

### Option A: Using GitHub Container Registry (Recommended)

1. **Enable GitHub Packages for your repository:**
   - Go to your repository settings
   - Enable GitHub Packages/Container Registry

2. **Create GitHub Actions workflow** (`.github/workflows/build.yml`):

```yaml
name: Build and Push Docker Images

on:
  push:
    branches: [main]
    tags: ['v*']
  workflow_dispatch:

env:
  REGISTRY: ghcr.io
  IMAGE_NAMESPACE: edesylabs

jobs:
  build-main:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - uses: actions/checkout@v4

      - name: Log in to GitHub Container Registry
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push main image
        uses: docker/build-push-action@v5
        with:
          context: .
          file: ./docker/prod/Dockerfile
          push: true
          tags: |
            ghcr.io/edesylabs/coolify:latest
            ghcr.io/edesylabs/coolify:${{ github.sha }}

  build-helper:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - uses: actions/checkout@v4

      - name: Log in to GitHub Container Registry
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push helper image
        uses: docker/build-push-action@v5
        with:
          context: ./docker/coolify-helper
          push: true
          tags: |
            ghcr.io/edesylabs/coolify-helper:latest

  build-realtime:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - uses: actions/checkout@v4

      - name: Log in to GitHub Container Registry
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push realtime image
        uses: docker/build-push-action@v5
        with:
          context: ./docker/coolify-realtime
          push: true
          tags: |
            ghcr.io/edesylabs/coolify-realtime:latest
```

3. **Make images public:**
   - Go to https://github.com/orgs/edesylabs/packages
   - Find each package (coolify, coolify-helper, coolify-realtime)
   - Change visibility to "Public"

### Option B: Using Docker Hub

Replace `ghcr.io` with `docker.io` and use Docker Hub credentials.

---

## Part 2: Script Hosting Setup

### Option A: GitHub Raw URLs (Easiest)

The scripts are now configured to use:
```bash
https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/install.sh
```

**Required files in your repository:**
- `scripts/install.sh` ✓
- `scripts/upgrade.sh` ✓
- `docker-compose.yml`
- `docker-compose.prod.yml`
- `.env.production`

Make sure these files are in your `main` branch.

### Option B: Custom CDN (Advanced)

If you want to use a custom CDN (Cloudflare R2, AWS S3, etc.):

1. Upload the required files to your CDN
2. Set the `COOLIFY_CDN` environment variable when installing

---

## Part 3: Installation on Servers

### Installing Your Custom Coolify

**On your main Coolify server:**

```bash
# Option 1: Using environment variable
export COOLIFY_CDN="https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main"
export COOLIFY_REGISTRY="ghcr.io"
export REGISTRY_URL="ghcr.io"
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/install.sh | bash

# Option 2: Download and run
wget https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/install.sh
chmod +x install.sh
COOLIFY_CDN="https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main" \
REGISTRY_URL="ghcr.io" \
./install.sh
```

### Environment Configuration

Create/edit `/data/coolify/source/.env` with:

```bash
# Registry Configuration
REGISTRY_URL=ghcr.io
COOLIFY_IMAGE_NAMESPACE=edesylabs

# Image Overrides (optional)
HELPER_IMAGE=ghcr.io/edesylabs/coolify-helper
REALTIME_IMAGE=ghcr.io/edesylabs/coolify-realtime

# CDN Configuration (optional)
COOLIFY_CDN=https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main

# Other settings...
APP_ID=<your-app-id>
APP_KEY=<your-app-key>
DB_PASSWORD=<your-db-password>
```

---

## Part 4: Connecting New Servers

When you add new servers through the Coolify UI, they will automatically use your custom installation because:

1. The `InstallDocker` action (app/Actions/Server/InstallDocker.php) uses the configured registry
2. The installation commands reference your custom images via `REGISTRY_URL`

### Manual Server Installation

If you need to manually prepare a server:

```bash
# On the target server
ssh user@target-server

# Install using your custom script
export COOLIFY_CDN="https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main"
export REGISTRY_URL="ghcr.io"
curl -fsSL $COOLIFY_CDN/scripts/install.sh | bash
```

---

## Part 5: Updates and Maintenance

### Auto-updates

Your custom Coolify will check for updates from your configured sources:

1. Edit `/data/coolify/source/.env`:
```bash
AUTOUPDATE=true
COOLIFY_RELEASES_URL=https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/versions.json
```

2. Create `versions.json` in your repository root:
```json
{
  "coolify": {
    "v4": {
      "version": "4.0.0-beta.444"
    }
  },
  "version": "4.0.0-beta.444",
  "helper_version": "1.0.12",
  "realtime_version": "1.0.10"
}
```

### Manual Updates

```bash
# On your Coolify server
cd /data/coolify/source
export COOLIFY_CDN="https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main"
./upgrade.sh latest latest ghcr.io
```

---

## Verification

After installation, verify your custom version:

1. **Check running containers:**
```bash
docker ps | grep coolify
# Should show images from ghcr.io/edesylabs
```

2. **Check environment:**
```bash
cat /data/coolify/source/.env | grep REGISTRY_URL
# Should show: REGISTRY_URL=ghcr.io
```

3. **Check Coolify UI:**
   - Go to Settings > Configuration
   - Verify version and image sources

---

## Troubleshooting

### Images not pulling
- Verify images are public on GitHub Packages
- Check `docker login ghcr.io` works on the server
- Verify image tags exist: `docker pull ghcr.io/edesylabs/coolify:latest`

### Installation fails
- Check CDN URL is accessible: `curl -I <CDN_URL>/scripts/install.sh`
- Verify all required files exist in your repository
- Check installation logs: `/data/coolify/source/installation-*.log`

### Servers not installing correctly
- Check `/data/coolify/source/.env` has correct `REGISTRY_URL`
- Verify `COOLIFY_IMAGE_NAMESPACE` is set
- Check server logs in Coolify UI

---

## Next Steps

1. Set up GitHub Actions to build images automatically
2. Create your `versions.json` for update management
3. Test installation on a fresh server
4. Document any custom features you've added
5. Set up monitoring for your custom Coolify instance

---

## Support

For issues specific to:
- **Official Coolify**: https://github.com/coollabsio/coolify/issues
- **Your fork**: Document your support channels here
