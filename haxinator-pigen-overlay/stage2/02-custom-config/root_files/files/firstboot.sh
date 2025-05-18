#!/bin/bash

# Set hostname
HOSTNAME="nano"
echo "$HOSTNAME" > /etc/hostname
#sed -i "s/nano/$HOSTNAME/g" /etc/hosts

# Ensure devices are managed
nmcli dev set eth0 managed yes
nmcli dev set wlan0 managed yes

#---------MY STUFF------------------
# Secure nmcli-based setup for
#   1. Personal OpenVPN connection
#   2. Iodine DNS-tunnel profile
#   3. Hans VPN setup
#   4. WiFi AP setup
#
# Reads all user-specific or secret data from /boot/firmware/env-secrets if it exists,
# otherwise falls back to /root/.env
# ------------------------------------------------------------------

# Check for firmware config first, otherwise fall back to root config
if [[ -r "/boot/firmware/env-secrets" ]]; then
    readonly CONF_FILE="/boot/firmware/env-secrets"
else
    readonly CONF_FILE="/root/.env"
fi

# Check for OpenVPN config in firmware and copy if found
if [[ -r "/boot/firmware/openvpn-udp.ovpn" ]]; then
    cp "/boot/firmware/openvpn-udp.ovpn" "/openvpn-udp.ovpn"
fi

readonly OVPN_BUNDLE="/openvpn-udp.ovpn"   # Path where your .ovpn is mounted/copied
readonly OVPN_CONN_NAME="openvpn-udp"
readonly IODINE_CONN_NAME="iodine-vpn"

# ------------------------------------------------------------------
# Helper: die with message
die() { echo "ERROR: $*" >&2; exit 1; }

# ------------------------------------------------------------------
# 1. Load configuration safely
if [[ ! -r $CONF_FILE ]]; then
    die "Cannot read config file $CONF_FILE (does it exist, correct perms 600?)"
fi
# shellcheck source=/root/.env
source "$CONF_FILE"

# ------------------------------------------------------------------
# Helper function to check if all required variables are set
check_required_vars() {
    local missing=()
    for var in "$@"; do
        if [[ -z "${!var}" ]]; then
            missing+=("$var")
        fi
    done
    if [[ ${#missing[@]} -gt 0 ]]; then
        return 1
    fi
    return 0
}

# ------------------------------------------------------------------
# 1. OpenVPN setup (if credentials are present)
if check_required_vars "VPN_USER" "VPN_PASS"; then
    nmcli connection import type openvpn file "$OVPN_BUNDLE"
    nmcli connection modify "$OVPN_CONN_NAME" \
        +vpn.data username="$VPN_USER" +vpn.data password-flags=0
    nmcli connection modify "$OVPN_CONN_NAME" \
        vpn.secrets "password=$VPN_PASS"
    # Clean up the imported .ovpn bundle
    rm -f -- "$OVPN_BUNDLE"
else
    echo "Skipping OpenVPN setup - missing required credentials" | tee -a "$LOG"
fi

# ------------------------------------------------------------------
# 2. Iodine setup (if all required values are present)
if check_required_vars "IODINE_TOPDOMAIN" "IODINE_NAMESERVER" "IODINE_PASS"; then
    # Optional parameters with defaults
    IODINE_MTU="${IODINE_MTU:-1400}"
    IODINE_LAZY="${IODINE_LAZY:-true}"
    IODINE_INTERVAL="${IODINE_INTERVAL:-4}"

    if nmcli -t -f NAME connection show | grep -qx "$IODINE_CONN_NAME"; then
        nmcli connection delete "$IODINE_CONN_NAME"
    fi

    nmcli connection add type vpn ifname iodine0 con-name "$IODINE_CONN_NAME" vpn-type iodine
    nmcli connection modify "$IODINE_CONN_NAME" vpn.data \
        "topdomain=${IODINE_TOPDOMAIN}, nameserver=${IODINE_NAMESERVER}, password=${IODINE_PASS}, mtu=${IODINE_MTU}, lazy-mode=${IODINE_LAZY}, interval=${IODINE_INTERVAL}"
    nmcli connection modify "$IODINE_CONN_NAME" vpn.secrets "password=${IODINE_PASS}"
else
    echo "Skipping Iodine setup - missing required configuration" | tee -a "$LOG"
fi

# ------------------------------------------------------------------
# 3. Hans VPN setup (if credentials are present)
if check_required_vars "HANS_SERVER" "HANS_PASSWORD"; then
    nmcli connection add type vpn con-name hans-icmp-vpn ifname tun0 vpn-type org.freedesktop.NetworkManager.hans
    nmcli connection modify hans-icmp-vpn vpn.data "server=${HANS_SERVER}, password=${HANS_PASSWORD}, password-flags=1"
    nmcli connection modify hans-icmp-vpn ipv4.method auto ipv4.never-default true
    nmcli connection modify hans-icmp-vpn ipv6.method auto ipv6.addr-gen-mode default
else
    echo "Skipping Hans VPN setup - missing required configuration" | tee -a "$LOG"
fi

# ------------------------------------------------------------------
# 4. WiFi AP setup (if credentials are present)
if check_required_vars "WIFI_SSID" "WIFI_PASSWORD"; then
    nmcli con delete pi_hotspot 2>/dev/null || true
    nmcli con add type wifi ifname wlan0 con-name pi_hotspot autoconnect yes ssid "${WIFI_SSID}"
    nmcli con mod pi_hotspot \
        802-11-wireless.mode ap \
        802-11-wireless.band bg \
        wifi-sec.key-mgmt wpa-psk \
        wifi-sec.psk "${WIFI_PASSWORD}" \
        ipv4.addresses 192.168.4.1/24 \
        ipv4.method shared \
        ipv4.never-default yes \
        ipv6.method ignore
else
    echo "Skipping WiFi AP setup - missing required configuration" | tee -a "$LOG"
fi

# Log to tmpfs
LOG="/tmp/firstboot.log"
echo "Starting firstboot: $(date)" | tee -a "$LOG"

echo "Set hostname: $HOSTNAME" | tee -a "$LOG"

# Regenerate SSL certificates
SSL_DIR="/etc/ssl/local"
CRT="${SSL_DIR}/pi.crt"
KEY="${SSL_DIR}/pi.key"
install -d -m 700 "$SSL_DIR"
openssl req -x509 -nodes -days 3650 -newkey rsa:4096 \
    -keyout "$KEY" -out "$CRT" \
    -subj "/CN=${HOSTNAME}.local" \
    -addext "subjectAltName=DNS:${HOSTNAME}.local,IP:192.168.8.1"
chmod 600 "$KEY"
echo "Regenerated SSL certificates" | tee -a "$LOG"

echo "Generating SSH keys for the www-data user." | tee -a "$LOG"
sudo -u www-data ssh-keygen -t rsa -b 4096 -C "www-data@yourserver" -f /var/www/.ssh/id_rsa -N ""

# Clean up and disable firstboot
systemctl disable firstboot
rm /usr/local/bin/firstboot.sh

echo F > /root/FIRST_COMPLETE
reboot
