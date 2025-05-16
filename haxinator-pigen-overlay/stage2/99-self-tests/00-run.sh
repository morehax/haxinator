#!/bin/bash -e
set -euo pipefail
SCRIPT_DIR="$(dirname "$0")"
source "${SCRIPT_DIR}/common-functions.sh"

green_echo "==> Self-tests: copy helper & script into chroot…"
cp "${SCRIPT_DIR}/common-functions.sh" "${ROOTFS_DIR}/common-functions.sh"
cp "${SCRIPT_DIR}/00-self-tests.sh"    "${ROOTFS_DIR}/00-self-tests.sh"
chmod +x "${ROOTFS_DIR}/00-self-tests.sh"

green_echo "==> Running self-tests inside chroot…"
chroot "${ROOTFS_DIR}" /bin/bash /00-self-tests.sh

green_echo "==> Cleaning up…"
rm -f "${ROOTFS_DIR}/00-self-tests.sh" "${ROOTFS_DIR}/common-functions.sh"
