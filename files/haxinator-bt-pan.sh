#!/usr/bin/env bash

set -euo pipefail

sysctl -w net.ipv4.ip_forward=1

# Ensure the bridge exists for PAN clients (systemd-networkd manages IP/DHCP)
if ! ip link show br-bt >/dev/null 2>&1; then
    ip link add br-bt type bridge
fi

ip link set br-bt up || true

# Bring the adapter up, and keep it pairable/discoverable
bluetoothctl --timeout 5 <<'EOF'
power on
discoverable on
pairable on
EOF

if command -v bt-network >/dev/null 2>&1; then
    exec bt-network -s nap br-bt
fi

echo "No bt-network command found." >&2
exit 1
