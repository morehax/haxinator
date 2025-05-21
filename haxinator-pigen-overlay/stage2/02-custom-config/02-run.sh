#!/bin/bash -e

# Source common functions
SCRIPT_DIR="$(dirname "$0")"
# shellcheck source=common-functions.sh
source "${SCRIPT_DIR}/common-functions.sh"

# Install required packages
red_echo "==> Installing required"

# Copy files to the root filesystem
cp "${SCRIPT_DIR}/common-functions.sh" "${ROOTFS_DIR}/common-functions.sh"
cp "${SCRIPT_DIR}/02-nginx-install.sh" "${ROOTFS_DIR}/02-nginx-install.sh"
[ -d "${SCRIPT_DIR}/html" ] && cp -r "${SCRIPT_DIR}/html" "${ROOTFS_DIR}/html"

# Copy nginx template
mkdir -p "${ROOTFS_DIR}/root_files/files/nginx"
cp "${SCRIPT_DIR}/root_files/files/nginx/pi.template" "${ROOTFS_DIR}/root_files/files/nginx/pi.template"

# Make the scripts executable
chmod +x "${ROOTFS_DIR}/02-nginx-install.sh"
chmod +x "${ROOTFS_DIR}/common-functions.sh"

# Run the script in the chroot environment
chroot "${ROOTFS_DIR}" /bin/bash /02-nginx-install.sh

# Clean up
rm "${ROOTFS_DIR}/02-nginx-install.sh"
rm "${ROOTFS_DIR}/common-functions.sh"
rm -rf "${ROOTFS_DIR}/html"
