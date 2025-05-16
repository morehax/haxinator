#!/usr/bin/env bash
# toggle_hd_rw.sh  –  Switch Raspberry-Pi rootfs between
#                     read-only overlay (RO) and normal read-write (RW)
#
#   sudo toggle_hd_rw.sh status   # show current mode
#   sudo toggle_hd_rw.sh on       # enable overlay  → RO  (needs reboot)
#   sudo toggle_hd_rw.sh off      # disable overlay → RW  (needs reboot)
#
#   Runs only with one explicit argument.
#   A reboot is required after a change.
# -------------------------------------------------------------------------

set -euo pipefail
usage() { echo "Usage: sudo $0 {on|off|status}" >&2; exit 1; }

[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
command -v raspi-config >/dev/null || { echo "raspi-config missing." >&2; exit 1; }

[[ $# -eq 1 ]] || usage
case "$1" in
  on|enable|ro)   ACTION="on"  ;;
  off|disable|rw) ACTION="off" ;;
  status)         ACTION="status" ;;
  *)              usage ;;
esac

# -------- Runtime detection --------------------------------------------------
root_fs_type=$(awk '$2=="/"{print $3; exit}' /proc/mounts)
is_ro() [[ $root_fs_type == overlay ]]
current_state() { is_ro && echo "ro" || echo "rw"; }

# -------- Status only --------------------------------------------------------
if [[ $ACTION == status ]]; then
  echo "Current rootfs mode: $(current_state)"
  exit 0
fi

# -------- Already in requested state? ----------------------------------------
if { [[ $ACTION == on  ]] && is_ro; } || \
   { [[ $ACTION == off ]] && ! is_ro; }; then
  echo "Already in desired state ($(current_state)); nothing to do."
  exit 0
fi

# -------- Toggle via raspi-config -------------------------------------------
if [[ $ACTION == on ]]; then
  echo "► Enabling read-only overlay filesystem…"
  raspi-config nonint enable_overlayfs
else
  echo "► Disabling overlay; returning to normal RW root…"
  raspi-config nonint disable_overlayfs
fi

echo "✔ Change queued. Reboot required to apply it."
read -rp "Reboot now? [y/N] " ans
[[ ${ans,,} == y* ]] && reboot

