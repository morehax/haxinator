#!/bin/bash
# =============================================================================
# Haxinator USB0 Network Configuration
# =============================================================================
# Configures the usb0 interface with a static IP and enables connection sharing.
# This script is triggered by systemd when the usb0 device appears.
# =============================================================================

set -e

# Enable IP forwarding for NAT
sysctl -w net.ipv4.ip_forward=1

# Create or update the NetworkManager connection
if ! nmcli con show usb0 &>/dev/null; then
    nmcli con add type ethernet ifname usb0 con-name usb0 ip4 192.168.8.1/24
    nmcli con mod usb0 ipv4.method shared
fi

nmcli con up usb0

echo "USB0 network configured: 192.168.8.1/24 with connection sharing"
