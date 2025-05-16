#!/usr/bin/env bash
#===============================================================================
# Build script for Haxinator 2000 Raspberry Pi image
#===============================================================================

set -euo pipefail
IFS=$'\n\t'

#===============================================================================
# Source common functions
#===============================================================================
SCRIPT_DIR="$(dirname "$0")"
source "${SCRIPT_DIR}/common-functions.sh"

#===============================================================================
# Check if running as root
#===============================================================================
if [[ "${EUID}" -ne 0 ]]; then
    red_echo "ERROR: Please run as root"
    exit 1
fi

# Get the original user's home directory
ORIGINAL_USER=$(logname || echo "${SUDO_USER:-${USER}}")
ORIGINAL_HOME=$(eval echo "~$ORIGINAL_USER")

#===============================================================================
# Main build process
#===============================================================================
green_echo "==> Starting build process..."

# Clone git repositories
green_echo "==> Cloning git repositories..."
if ! ./02-clone-git.sh; then
    red_echo "ERROR: Failed to clone git repositories"
    exit 1
fi

# Overlay files
green_echo "==> Overlaying files..."
if ! ./03-overlay.sh; then
    red_echo "ERROR: Failed to overlay files"
    exit 1
fi

# Build image
green_echo "==> Building image..."
cd pi-gen || exit 1
if ! ./build.sh; then
    red_echo "ERROR: Failed to build image"
    exit 1
fi
cd ..

green_echo "==> Build process completed successfully"

# This is for running local builds
#SUDO_USER=$(logname)
#echo "Running chown to set ownership to $SUDO_USER..."

# For local builds witn non root users running sudo.
# REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
# REPO_USER="$(stat -c '%U' "$REPO_DIR")"
# green_echo "==> Returning ownership of $REPO_DIR to $REPO_USER..."
# chown -R "$REPO_USER:$REPO_USER" "$REPO_DIR"

# green_echo "==> Mounting SD card image..."
# if ! ./img-mount.sh; then
#     red_echo "ERROR: Failed to mount SD card image"
#     exit 1
# fi

# green_echo "==> Unmounting SD card image..."
# if ! ./img-unmount.sh; then
#     red_echo "ERROR: Failed to unmount SD card image"
#     exit 1
# fi

