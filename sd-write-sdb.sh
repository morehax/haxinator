#!/bin/bash
set -euo pipefail

# Configuration
IMAGE="/root/pro/pi-gen/work/custompi/export-image/2025-05-05-custompi-lite.img"
DEVICE="/dev/sdb"

# Check root privileges
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

# Check required commands
REQUIRED_CMDS=(dd lsblk umount sync eject)
for cmd in "${REQUIRED_CMDS[@]}"; do
    command -v "$cmd" >/dev/null || { echo "$cmd missing"; exit 1; }
done

# Check if image file exists
[[ -f "$IMAGE" ]] || { echo "Image not found: $IMAGE"; exit 1; }

# Check if device exists
[[ -b "$DEVICE" ]] || { echo "Device not found: $DEVICE"; exit 1; }

# Debug: Show lsblk output
echo "Listing block devices:"
lsblk
echo

# Verify device is a disk
if [[ $(lsblk -dno TYPE "$DEVICE") != "disk" ]]; then
    echo "$DEVICE is not a disk device"
    exit 1
fi

# Unmount all partitions
echo "Unmounting $DEVICE..."
umount ${DEVICE}* 2>/dev/null || true

# Write image
echo "Writing $IMAGE to $DEVICE..."
dd if="$IMAGE" of="$DEVICE" bs=4M status=progress

# Sync
echo "Syncing..."
sync

# Eject
echo "Ejecting $DEVICE..."
eject "$DEVICE"

echo "Image successfully written to $DEVICE. SD card is ready."
exit 0
