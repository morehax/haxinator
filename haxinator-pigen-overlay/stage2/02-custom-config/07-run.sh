#!/bin/bash -e
set -euo pipefail
SCRIPT_DIR="$(dirname "$0")"

# Source common functions
# shellcheck source=common-functions.sh
source "${SCRIPT_DIR}/common-functions.sh"

green_echo "==> Copying files to chroot environment..."

# Copy the root_files directory to the chroot environment
if [[ ! -d "${SCRIPT_DIR}/root_files" ]]; then
    red_echo "ERROR: root_files directory not found in ${SCRIPT_DIR}"
    ls -la "${SCRIPT_DIR}"
    exit 1
fi

# Create the root_files directory in chroot
mkdir -p "${ROOTFS_DIR}/root_files"

# Copy the contents of root_files to the chroot environment
cp -r "${SCRIPT_DIR}/root_files" "${ROOTFS_DIR}/"

# ls -la "${ROOTFS_DIR}/"

if [[ -f "${SCRIPT_DIR}/root_files/files/env-secrets.template" && ! -f "${SCRIPT_DIR}/root_files/files/env-secrets" ]]; then
    cp "${SCRIPT_DIR}/root_files/files/env-secrets.template" "${ROOTFS_DIR}/root_files/files/env-secrets"
fi


# Copy the scripts
cp "${SCRIPT_DIR}/common-functions.sh" "${ROOTFS_DIR}/common-functions.sh"
cp "${SCRIPT_DIR}/07-root-files-copy.sh" "${ROOTFS_DIR}/07-root-files-copy.sh"

# Make the scripts executable
chmod +x "${ROOTFS_DIR}/07-root-files-copy.sh"
chmod +x "${ROOTFS_DIR}/common-functions.sh"

# Run the copy script in the chroot environment
green_echo "==> Running file copy script in chroot..."
chroot "${ROOTFS_DIR}" /bin/bash /07-root-files-copy.sh

# Clean up
green_echo "==> Cleaning up..."
rm "${ROOTFS_DIR}/07-root-files-copy.sh"
rm -rf "${ROOTFS_DIR}/root_files"
rm "${ROOTFS_DIR}/common-functions.sh"

green_echo "==> File copy operations completed"
