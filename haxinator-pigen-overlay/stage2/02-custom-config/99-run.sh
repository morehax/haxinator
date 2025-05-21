#!/bin/bash -e
set -euo pipefail

SCRIPT_DIR="$(dirname "$0")"
# shellcheck source=common-functions.sh
source "${SCRIPT_DIR}/common-functions.sh"

green_echo "==> Removing apt proxy file from final image..."

# If APT_PROXY was set earlier, a 01proxy file was created in /etc/apt/apt.conf.d
# We remove it here so the final image doesn't contain your local proxy
rm -f "${ROOTFS_DIR}/etc/apt/apt.conf.d/01proxy" || true
