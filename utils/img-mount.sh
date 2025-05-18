#!/bin/bash

#===============================================================================
# Raspberry Pi OS Image Mount Script v0.1
# This version includes proper loop device setup and partition mounting
#===============================================================================

# Enable safer scripting
set -euo pipefail
IFS=$'\n\t'

# Source shared color/checkmark functions
SCRIPT_DIR="$(dirname "$(realpath "$0")")"
source "$SCRIPT_DIR/common-functions.sh"

#------------------------------------------------------------------------------
# 5. Parameterize: allow user to pass a specific .img file as argument
#    or default to the "most recent" image if none is provided.
#------------------------------------------------------------------------------
IMAGE_PATH_ARG=${1:-}
if [ -n "$IMAGE_PATH_ARG" ]; then
    IMAGE_PATH="$IMAGE_PATH_ARG"
else
    IMAGE_PATH=$(find ./pi-gen/work/haxinator/export-image -name "*.img" -type f -printf '%T@ %p\n' \
                 | sort -n | tail -1 | cut -f2- -d" ")
fi

if [ -z "$IMAGE_PATH" ]; then
    red_echo "Error: No image file found in ./pi-gen/deploy"
    exit 1
fi

green_echo "Using image: $IMAGE_PATH"
BOOT_MOUNT="/mnt/rpi-boot"
ROOT_MOUNT="/mnt/rpi"

#------------------------------------------------------------------------------
# Clean up any existing mounts/devices referencing this image
#------------------------------------------------------------------------------
green_echo "Cleaning up any existing mounts..."
sudo umount "$BOOT_MOUNT" 2>/dev/null || true
sudo umount "$ROOT_MOUNT" 2>/dev/null || true

# Detach loop devices linked to this image
for loopdev in $(sudo losetup -a | grep "$(realpath "$IMAGE_PATH")" | cut -d: -f1); do
    sudo losetup -d "$loopdev" 2>/dev/null || true
done

# Create (or confirm) mount points
sudo mkdir -p "$BOOT_MOUNT" "$ROOT_MOUNT"

#------------------------------------------------------------------------------
# 2. Dynamically pick an unused loop device for our image (-f --show)
#------------------------------------------------------------------------------
LOOPDEV=$(sudo losetup -f --show -P "$IMAGE_PATH")
green_echo "Loop device created: $LOOPDEV"

#------------------------------------------------------------------------------
# 4. Check partition layout dynamically (instead of hardcoding /dev/loop0p1/p2)
#------------------------------------------------------------------------------
partitions=( $(ls "${LOOPDEV}"p* 2>/dev/null) )
if [[ ${#partitions[@]} -lt 2 ]]; then
    red_echo "Error: Could not detect at least two partitions on $LOOPDEV"
    # Clean up to avoid leaking the loopdev
    sudo losetup -d "$LOOPDEV"
    exit 1
fi

BOOT_PART="${partitions[0]}"
ROOT_PART="${partitions[1]}"

#------------------------------------------------------------------------------
# 3. Add checks after mounting
#------------------------------------------------------------------------------
green_echo "Mounting $BOOT_PART -> $BOOT_MOUNT"
sudo mount "$BOOT_PART" "$BOOT_MOUNT" || {
    red_echo "Failed to mount $BOOT_PART"
    sudo losetup -d "$LOOPDEV"
    exit 1
}

green_echo "Mounting $ROOT_PART -> $ROOT_MOUNT"
sudo mount "$ROOT_PART" "$ROOT_MOUNT" || {
    red_echo "Failed to mount $ROOT_PART"
    sudo umount "$BOOT_MOUNT" 2>/dev/null || true
    sudo losetup -d "$LOOPDEV"
    exit 1
}

green_echo "Mounting complete. Image is mounted at $BOOT_MOUNT and $ROOT_MOUNT."
