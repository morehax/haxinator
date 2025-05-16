#!/usr/bin/env bash
#===============================================================================
# Git repository setup script for Raspberry Pi image build
#===============================================================================

set -euo pipefail
IFS=$'\n\t'

#===============================================================================
# Source common functions
#===============================================================================
SCRIPT_DIR="$(dirname "$0")"
source "${SCRIPT_DIR}/common-functions.sh"

#===============================================================================
# Clean up existing repository
#===============================================================================
yellow_echo "==> Removing existing pi-gen folder"
if [[ -d "pi-gen" ]]; then
    if ! rm -rf pi-gen; then
        red_echo "ERROR: Failed to remove existing pi-gen folder"
        exit 1
    fi
fi

#===============================================================================
# Clone and setup repository
#===============================================================================
green_echo "==> Cloning https://github.com/rpi-distro/pi-gen"
if ! git clone https://github.com/rpi-distro/pi-gen; then
    red_echo "ERROR: Failed to clone pi-gen repository"
    exit 1
fi

# Checkout arm64 branch
cd pi-gen || exit 1
green_echo "==> Checking out arm64 build"
if ! git checkout arm64; then
    red_echo "ERROR: Failed to checkout arm64 branch"
    exit 1
fi
cd ..

#===============================================================================
# Clean up unnecessary files
#===============================================================================
green_echo "==> Removing stage2/01-sys-tweaks/00-packages-nr"
if [[ -f "pi-gen/stage2/01-sys-tweaks/00-packages-nr" ]]; then
    if ! rm pi-gen/stage2/01-sys-tweaks/00-packages-nr; then
        yellow_echo "WARNING: Failed to remove 00-packages-nr file"
    fi
else
    yellow_echo "WARNING: 00-packages-nr file not found"
fi

green_echo "==> Git repository setup completed successfully"
