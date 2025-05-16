#!/bin/bash
set -euo pipefail

# Configuration
DEVICE="/dev/sdb"
MOUNT_POINT="/mnt/chroot"

# Check root privileges
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

# Check required commands
REQUIRED_CMDS=(mount umount chroot lsblk)
for cmd in "${REQUIRED_CMDS[@]}"; do
    command -v "$cmd" >/dev/null || { echo "$cmd missing"; exit 1; }
done

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

# Verify partitions exist
[[ -b "${DEVICE}1" ]] || { echo "Boot partition ${DEVICE}1 not found"; exit 1; }
[[ -b "${DEVICE}2" ]] || { echo "Root partition ${DEVICE}2 not found"; exit 1; }

# Create mount point
mkdir -p "$MOUNT_POINT"

# Unmount any existing mounts to avoid conflicts
echo "Unmounting any existing mounts on $DEVICE..."
umount ${DEVICE}* 2>/dev/null || true
umount "$MOUNT_POINT" 2>/dev/null || true

# Mount root filesystem (/dev/sdb2)
echo "Mounting root filesystem (${DEVICE}2)..."
mount "${DEVICE}2" "$MOUNT_POINT" || { echo "Failed to mount ${DEVICE}2"; exit 1; }

# Mount boot filesystem (/dev/sdb1)
echo "Mounting boot filesystem (${DEVICE}1)..."
mkdir -p "$MOUNT_POINT/boot"
mount "${DEVICE}1" "$MOUNT_POINT/boot" || { echo "Failed to mount ${DEVICE}1"; umount "$MOUNT_POINT"; exit 1; }

# Bind mount system directories
echo "Setting up bind mounts..."
mount --bind /dev "$MOUNT_POINT/dev"
mount --bind /proc "$MOUNT_POINT/proc"
mount --bind /sys "$MOUNT_POINT/sys"
mount --bind /dev/pts "$MOUNT_POINT/dev/pts"

# Copy resolv.conf for network access
echo "Copying /etc/resolv.conf for network..."
cp /etc/resolv.conf "$MOUNT_POINT/etc/resolv.conf"

# Enter chroot
echo "Entering chroot. Type 'exit' to leave."
chroot "$MOUNT_POINT" /bin/bash

# Cleanup
echo "Cleaning up..."
# Unmount system directories
umount "$MOUNT_POINT/dev/pts" 2>/dev/null || true
umount "$MOUNT_POINT/dev" 2>/dev/null || true
umount "$MOUNT_POINT/proc" 2>/dev/null || true
umount "$MOUNT_POINT/sys" 2>/dev/null || true
# Unmount boot and root
umount "$MOUNT_POINT/boot" 2>/dev/null || true
umount "$MOUNT_POINT" 2>/dev/null || true

# Verify no mounts remain
if mount | grep -q "$MOUNT_POINT"; then
    echo "Warning: Some mounts could not be cleaned up. Check with 'mount' and unmount manually."
else
    echo "Cleanup complete."
fi

exit 0
