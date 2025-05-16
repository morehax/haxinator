#!/bin/bash -e

# Source common functions
SCRIPT_DIR="$(dirname "$0")"
source "${SCRIPT_DIR}/common-functions.sh"

# Copy the scripts to the chroot environment
cp "${SCRIPT_DIR}/common-functions.sh" "${ROOTFS_DIR}/common-functions.sh"
cp "${SCRIPT_DIR}/03-packages-install.sh" "${ROOTFS_DIR}/03-packages-install.sh"
chmod +x "${ROOTFS_DIR}/03-packages-install.sh"

# Run the script in the chroot environment
chroot "${ROOTFS_DIR}" /bin/bash /03-packages-install.sh

# Clean up
rm "${ROOTFS_DIR}/03-packages-install.sh"
rm "${ROOTFS_DIR}/common-functions.sh"
