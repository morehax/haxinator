#!/usr/bin/env bash
# change_wifi.sh  —  Switch Wi-Fi credentials on a NetworkManager-based Pi
# Usage: sudo ./change_wifi.sh <SSID> <PSK> [interface]
# Example: sudo ./change_wifi.sh "MyHotspot" "Sup3rSecret!" wlan0

set -euo pipefail

# ─────────────────────── Arguments & sanity checks ────────────────────────
if [[ $# -lt 2 || $# -gt 3 ]]; then
  echo "Usage: sudo $0 <SSID> <PSK> [interface]" >&2
  exit 1
fi

SSID="$1"
PSK="$2"
IFACE="${3:-wlan0}"       # default to wlan0 if not supplied

[[ $EUID -eq 0 ]] || { echo "Run this script as root (sudo)." >&2; exit 1; }
command -v nmcli >/dev/null || { echo "nmcli not found — install NetworkManager." >&2; exit 1; }

echo "=== Switching Wi-Fi on $IFACE to SSID: '$SSID' ==="

# ───────────────────── Disconnect current Wi-Fi (if any) ──────────────────
nmcli device disconnect "$IFACE" 2>/dev/null || true

# ───────────────────── Remove duplicate connection profiles ───────────────
if nmcli -t -f NAME,TYPE connection show | grep -q "^${SSID}:802-11-wireless$"; then
  echo "Deleting old saved profile for '$SSID'..."
  nmcli connection delete "$SSID"
fi

# ───────────────────── Create & activate new profile ──────────────────────
echo "Connecting…"
nmcli device wifi connect "$SSID" password "$PSK" ifname "$IFACE" >/dev/null

# ───────────────────── Poll until DHCP lease is obtained ──────────────────
for i in {1..10}; do
  sleep 1
  ACTIVE_SSID=$(nmcli -t -f ACTIVE,SSID dev wifi | awk -F: '$1=="yes"{print $2}')
  if [[ "$ACTIVE_SSID" == "$SSID" ]]; then
    IP=$(nmcli -t -f IP4.ADDRESS dev show "$IFACE" | cut -d: -f2 | head -n1)
    echo "✅  Connected to '$SSID'  →  IP $IP"
    exit 0
  fi
done

echo "❌  Failed to connect to '$SSID' within timeout." >&2
exit 1

