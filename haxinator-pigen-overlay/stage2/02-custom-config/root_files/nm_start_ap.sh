#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
#  make_ap.sh  –  Turn the Pi into an offline Wi-Fi access point + DHCP
#
#  Usage   : sudo ./make_ap.sh <SSID> <PASSWORD> [wlanX] [subnet/CIDR]
#  Example : sudo ./make_ap.sh "Pi-Setup" "changeme123" wlan0 192.168.50.1/24
#
#  What it does
#    • Deletes any existing hotspot called "pi_hotspot"
#    • Creates / updates a NetworkManager connection in AP mode
#    • Static-IPs the Pi at <subnet/CIDR> (default 192.168.50.1/24)
#    • Enables NM’s built-in dnsmasq → clients get DHCP on that subnet
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

# ─── argument handling ────────────────────────────────────────────────────────
if [[ $# -lt 2 || $# -gt 4 ]]; then
  echo "Usage: sudo $0 <SSID> <PASSWORD> [interface] [subnet/CIDR]" >&2
  exit 1
fi

SSID="$1"
PSK="$2"
IFACE="${3:-wlan0}"
CIDR="${4:-192.168.50.1/24}"   # Pi’s own IP + subnet mask

[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
command -v nmcli >/dev/null || { echo "NetworkManager/nmcli not installed." >&2; exit 1; }

echo "=== Creating offline hotspot '$SSID' on $IFACE ($CIDR) ==="

# ─── remove any pre-existing hotspot connection called pi_hotspot ─────────────
if nmcli -t -f NAME connection show | grep -qx "pi_hotspot"; then
  nmcli connection down    "pi_hotspot" 2>/dev/null || true
  nmcli connection delete  "pi_hotspot"
fi

# ─── create the AP connection ─────────────────────────────────────────────────
nmcli connection add type wifi ifname "$IFACE" con-name "pi_hotspot" \
       autoconnect yes ssid "$SSID" >/dev/null

nmcli connection modify "pi_hotspot" \
       802-11-wireless.mode            ap \
       802-11-wireless.band            bg \
       wifi-sec.key-mgmt               wpa-psk \
       wifi-sec.psk                    "$PSK" \
       ipv4.addresses                  "$CIDR" \
       ipv4.method                     shared \
       ipv4.never-default              yes \
       ipv6.method                     ignore

# ─── bring it up ──────────────────────────────────────────────────────────────
nmcli connection up "pi_hotspot" >/dev/null

# give wpa_supplicant/NM a moment to settle
sleep 2

IP=$(nmcli -t -f IP4.ADDRESS dev show "$IFACE" | cut -d: -f2 | head -n1)
echo -e "\n✅  Hotspot active:"
echo "    SSID      : $SSID"
echo "    Interface : $IFACE"
echo "    Pi IP     : $IP"
echo "    DHCP range: handed out automatically by NetworkManager"

exit 0

