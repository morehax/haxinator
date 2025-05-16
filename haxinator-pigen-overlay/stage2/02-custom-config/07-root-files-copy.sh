#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { red_echo "Run as root"; exit 1; }

# Source common functions
source "/common-functions.sh"

green_echo "==> Starting file copy operations..."

# Debug: Show current directory structure
green_echo "==> Current directory structure:"
pwd
ls -la

# Debug: Show root_files directory structure
green_echo "==> root_files directory structure:"
ls -la root_files/
ls -la root_files/files/

# Verify root_files directory exists
if [[ ! -d root_files ]]; then
    red_echo "ERROR: root_files directory not found!"
    ls -la .
    exit 1
fi

# Function to safely copy files with error checking
safe_copy() {
    local src="$1"
    local dest="$2"
    local perms="${3:-}"  # Make permissions optional with default empty value
    
    green_echo "==> Copying $src to $dest"
    if [[ ! -e "$src" ]]; then
        red_echo "ERROR: Source file $src does not exist!"
        ls -la "$(dirname "$src")"
        return 1
    fi
    
    if ! cp -rf "$src" "$dest"; then
        red_echo "ERROR: Failed to copy $src to $dest"
        return 1
    fi
    
    if [[ -n "$perms" ]]; then
        if ! chmod "$perms" "$dest"; then
            red_echo "ERROR: Failed to set permissions $perms on $dest"
            return 1
        fi
    fi
    
    green_echo "==> Successfully copied $src to $dest"
    return 0
}

# Copy all files with error checking
green_echo "==> Copying files to /root/ ..."

# Copy env-secrets first and verify

if [[ ! -f /root_files/files/env-secrets ]]; then
    if [[ -f /root_files/files/env-secrets.template ]]; then
        cp /root_files/files/env-secrets.template /root/.env
        chmod 600 /root/.env
    else
        echo "ERROR: Neither env-secrets nor env-secrets.template found!"
        exit 1
    fi
else
    cp /root_files/files/env-secrets /root/.env
    chmod 600 /root/.env

fi

# Copy nginx template
green_echo "==> Copying nginx template..."
if ! safe_copy "root_files/files/nginx/pi.template" "/etc/nginx/sites-available/pi.template"; then
    red_echo "ERROR: Failed to copy nginx template!"
    exit 1
fi

# Verify .env file exists and has correct permissions
if [[ ! -f "/root/.env" ]]; then
    red_echo "ERROR: /root/.env file not found after copy!"
    exit 1
fi

if [[ "$(stat -c %a /root/.env)" != "600" ]]; then
    red_echo "ERROR: /root/.env has incorrect permissions: $(stat -c %a /root/.env)"
    exit 1
fi

# Copy other files with error checking
safe_copy "root_files/files/rc.local" "/etc/" || yellow_echo "WARNING: Failed to copy rc.local"
safe_copy "root_files/files/motd" "/etc/" || yellow_echo "WARNING: Failed to copy motd"
safe_copy "root_files/files/iodine-client" "/etc/default/iodine-client" || yellow_echo "WARNING: Failed to copy iodine-client"

# Hans and Iodine Service Scripts
safe_copy "root_files/files/hans2/hans-service.py" "/usr/lib/NetworkManager/hans-service.py" "755" || yellow_echo "WARNING: Failed to copy hans-service.py"
safe_copy "root_files/files/hans2/iodine-service.py" "/usr/lib/NetworkManager/iodine-service.py" "755" || yellow_echo "WARNING: Failed to copy iodine-service.py"

safe_copy "root_files/files/hans2/hans.name" "/usr/lib/NetworkManager/VPN/hans.name" || yellow_echo "WARNING: Failed to copy hans.name"
safe_copy "root_files/files/hans2/iodine.name" "/usr/lib/NetworkManager/VPN/iodine.name" || yellow_echo "WARNING: Failed to copy iodine.name"

safe_copy "root_files/files/hans2/nm-hans-service.conf" "/etc/dbus-1/system.d/" || yellow_echo "WARNING: Failed to copy nm-hans-service.conf"
safe_copy "root_files/files/hans2/nm-iodine-service.conf" "/etc/dbus-1/system.d/" || yellow_echo "WARNING: Failed to copy nm-iodine-service.conf"

safe_copy "root_files/files/hans2/99-clean-vpn-routes" "/etc/NetworkManager/dispatcher.d/99-clean-vpn-routes" "755" || yellow_echo "WARNING: Failed to copy 99-clean-vpn-routes"


# BT Auto Pair
safe_copy "root_files/files/bluetooth/auto-pair" "/usr/local/bin/" "755" || yellow_echo "WARNING: Failed to copy auto-pair"
safe_copy "root_files/files/services/bluetooth_pair.service" "/etc/systemd/system/" || yellow_echo "WARNING: Failed to copy bluetooth_pair.service"

# Openvpn files
safe_copy "root_files/files/openvpn-udp.ovpn" "/" || yellow_echo "WARNING: Failed to copy openvpn-udp.ovpn"
safe_copy "root_files/files/update_me.sh" "/update_me.sh" "755" || yellow_echo "WARNING: Failed to copy update_me.sh"

# Copy all scripts to /usr/local/bin
green_echo "==> Copying scripts to /usr/local/bin"
for script in root_files/*.sh; do
    if [[ -f "$script" ]]; then
        safe_copy "$script" "/usr/local/bin/" "755" || yellow_echo "WARNING: Failed to copy $script"
    fi
done

# Copy firstboot and service files
safe_copy "root_files/files/firstboot.sh" "/usr/local/bin/" "755" || yellow_echo "WARNING: Failed to copy firstboot.sh"
safe_copy "root_files/files/services/firstboot.service" "/lib/systemd/system/" || yellow_echo "WARNING: Failed to copy firstboot.service"

# Copy polkit file
safe_copy "root_files/files/10-nmcli-webui.pkla" "/etc/polkit-1/localauthority/50-local.d/" || yellow_echo "WARNING: Failed to copy 10-nmcli-webui.pkla"

# Copy html files
green_echo "==> Copying HTML files"
if [[ -d "root_files/html" ]]; then
    if ! cp -rf root_files/html/* /var/www/html/; then
        red_echo "ERROR: Failed to copy HTML files"
        exit 1
    fi
else
    yellow_echo "WARNING: root_files/html directory not found"
fi

# Copy service files
safe_copy "root_files/files/services/dbus-org.bluez.service" "/etc/systemd/system/" || yellow_echo "WARNING: Failed to copy dbus-org.bluez.service"
safe_copy "root_files/files/services/rfcomm.service" "/etc/systemd/system/" || yellow_echo "WARNING: Failed to copy rfcomm.service"
safe_copy "root_files/files/services/unblock-wifi.service" "/etc/systemd/system/" || yellow_echo "WARNING: Failed to copy unblock-wifi.service"

# Enable services
green_echo "==> Enabling services..."
systemctl enable unblock-wifi.service || yellow_echo "WARNING: Failed to enable unblock-wifi.service"
systemctl enable bluetooth_pair.service || yellow_echo "WARNING: Failed to enable bluetooth_pair.service"
systemctl enable serial-getty@ttyGS0.service || yellow_echo "WARNING: Failed to enable serial-getty@ttyGS0.service"
systemctl enable firstboot || yellow_echo "WARNING: Failed to enable firstboot"
systemctl enable rfcomm || yellow_echo "WARNING: Failed to enable rfcomm"
systemctl enable bluetooth || yellow_echo "WARNING: Failed to enable bluetooth"
systemctl enable shellinabox || yellow_echo "WARNING: Failed to enable shellinabox"

# Configure network services
green_echo "==> Configuring network services..."
systemctl mask wpa_supplicant@wlan0.service || yellow_echo "WARNING: Failed to mask wpa_supplicant@wlan0.service"
systemctl enable NetworkManager || yellow_echo "WARNING: Failed to enable NetworkManager"
systemctl disable dnsmasq || yellow_echo "WARNING: Failed to disable dnsmasq"

# Configure dnsmasq and NetworkManager
green_echo "==> Configuring dnsmasq and NetworkManager..."
echo "interface=usb0" >> /etc/dnsmasq.conf
echo "dhcp-range=192.168.8.2,192.168.8.100,12h" >> /etc/dnsmasq.conf
sed -i 's/managed=false/managed=true/' /etc/NetworkManager/NetworkManager.conf

green_echo "==> File copy operations completed"
exit 0
