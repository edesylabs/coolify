#!/bin/bash
# Custom Coolify Installation Script
# This is a convenience wrapper for installing your forked version

set -e

echo "=========================================="
echo "  Custom Coolify Installation (EdesyLabs)"
echo "=========================================="
echo ""

# Configuration
COOLIFY_ORG="${COOLIFY_ORG:-edesylabs}"
COOLIFY_BRANCH="${COOLIFY_BRANCH:-main}"
COOLIFY_CDN="https://raw.githubusercontent.com/${COOLIFY_ORG}/coolify/${COOLIFY_BRANCH}"
COOLIFY_REGISTRY="${COOLIFY_REGISTRY:-ghcr.io}"

echo "Configuration:"
echo "  Organization: $COOLIFY_ORG"
echo "  Branch: $COOLIFY_BRANCH"
echo "  CDN: $COOLIFY_CDN"
echo "  Registry: $COOLIFY_REGISTRY"
echo ""

# Check if running as root
if [ $EUID != 0 ]; then
    echo "ERROR: Please run this script as root or with sudo"
    exit 1
fi

# Confirm installation
read -p "Do you want to proceed with the installation? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Installation cancelled."
    exit 0
fi

echo ""
echo "Downloading installation script from: $COOLIFY_CDN/scripts/install.sh"
echo ""

# Download and run the installation script
export COOLIFY_CDN
export REGISTRY_URL="$COOLIFY_REGISTRY"

curl -fsSL "$COOLIFY_CDN/scripts/install.sh" | bash

if [ $? -eq 0 ]; then
    echo ""
    echo "=========================================="
    echo "  Installation completed successfully!"
    echo "=========================================="
    echo ""
    echo "Next steps:"
    echo "  1. Access Coolify at http://$(hostname -I | awk '{print $1}'):8000"
    echo "  2. Configure your .env file at /data/coolify/source/.env"
    echo "  3. Add this to your .env for custom settings:"
    echo ""
    echo "     REGISTRY_URL=$COOLIFY_REGISTRY"
    echo "     COOLIFY_IMAGE_NAMESPACE=$COOLIFY_ORG"
    echo "     COOLIFY_CDN=$COOLIFY_CDN"
    echo ""
else
    echo ""
    echo "=========================================="
    echo "  Installation failed!"
    echo "=========================================="
    echo ""
    echo "Check the logs at: /data/coolify/source/installation-*.log"
    exit 1
fi
