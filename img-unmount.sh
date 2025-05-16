#!/bin/bash

#===============================================================================
# Raspberry Pi OS Image Unmount Script v0.1
# This version includes comprehensive verification checks before unmounting
#===============================================================================

# Enable safer scripting
set -euo pipefail
IFS=$'\n\t'

# 1. Source the unified color/checkmark functions instead of defining them here
SCRIPT_DIR="$(dirname "$(realpath "$0")")"
source "$SCRIPT_DIR/common-functions.sh"

# 5. Parameterize: allow user to pass a specific .img file as argument
IMAGE_PATH_ARG=${1:-}
if [ -n "$IMAGE_PATH_ARG" ]; then
    IMAGE_PATH="$IMAGE_PATH_ARG"
else
    IMAGE_PATH=$(find ./pi-gen/work/haxinator/export-image -name "*.img" -type f -printf '%T@ %p\n' \
                 | sort -n | tail -1 | cut -f2- -d" ")
fi

if [ -z "$IMAGE_PATH" ]; then
    red_echo "Error: No image file found in ./pi-gen/deploy"
    exit 1
fi

echo "Using image: $IMAGE_PATH"
BOOT_MOUNT="/mnt/rpi-boot"
ROOT_MOUNT="/mnt/rpi"

# Function to verify files and configurations
verify_image() {
    echo "Verifying image contents..."

    # Check boot partition files
    if [ -d "$BOOT_MOUNT" ]; then
        echo "Checking boot partition..."
        [ -f "$BOOT_MOUNT/cmdline.txt" ] && echo "$(green_check) cmdline.txt exists" || echo "$(red_x) cmdline.txt missing"
        [ -f "$BOOT_MOUNT/config.txt" ] && echo "$(green_check) config.txt exists" || echo "$(red_x) config.txt missing"
    else
        echo "$(red_x) Boot partition not mounted"
    fi

    # Check root partition files and configurations
    if [ -d "$ROOT_MOUNT" ]; then
        echo "Checking root partition..."
        # Nginx
        [ -d "$ROOT_MOUNT/etc/nginx" ] && echo "$(green_check) nginx directory exists" || echo "$(red_x) nginx directory missing"
        [ -f "$ROOT_MOUNT/etc/nginx/nginx.conf" ] && echo "$(green_check) nginx.conf exists" || echo "$(red_x) nginx.conf missing"
        [ -f "$ROOT_MOUNT/etc/nginx/sites-available/pi" ] && echo "$(green_check) sites-available/pi exists" || echo "$(red_x) sites-available/pi missing"
        [ -L "$ROOT_MOUNT/etc/nginx/sites-enabled/pi" ] && echo "$(green_check) sites-enabled/pi symlink exists" || echo "$(red_x) sites-enabled/pi symlink missing"
        # SSL
        [ -f "$ROOT_MOUNT/etc/ssl/local/pi.crt" ] && echo "$(green_check) SSL certificate exists" || echo "$(red_x) SSL certificate missing"
        [ -f "$ROOT_MOUNT/etc/ssl/local/pi.key" ] && echo "$(green_check) SSL key exists" || echo "$(red_x) SSL key missing"
        # PHP-FPM
        PHP_VER=$(find "$ROOT_MOUNT/etc/php" -maxdepth 1 -mindepth 1 -type d -printf '%f\n' | sort -V | tail -n1)
        if [ -n "$PHP_VER" ]; then
            echo "$(green_check) PHP version $PHP_VER is installed"
            [ -f "$ROOT_MOUNT/etc/php/$PHP_VER/fpm/pool.d/www.conf" ] && echo "$(green_check) PHP-FPM pool config exists" || echo "$(red_x) PHP-FPM pool config missing"
            [ -f "$ROOT_MOUNT/etc/php/$PHP_VER/fpm/php.ini" ] && echo "$(green_check) PHP config exists" || echo "$(red_x) PHP config missing"
            grep -q "pm = ondemand" "$ROOT_MOUNT/etc/php/$PHP_VER/fpm/pool.d/www.conf" && echo "$(green_check) PHP-FPM is ondemand" || echo "$(red_x) PHP-FPM not ondemand"
            grep -q "memory_limit = 64M" "$ROOT_MOUNT/etc/php/$PHP_VER/fpm/php.ini" && echo "$(green_check) PHP memory limit is 64M" || echo "$(red_x) PHP memory limit not set to 64M"
        else
            echo "$(red_x) PHP not installed"
        fi
        # Systemd services
        [ -L "$ROOT_MOUNT/etc/systemd/system/multi-user.target.wants/nginx.service" ] && echo "$(green_check) nginx.service enabled" || echo "$(red_x) nginx.service not enabled"
        [ -L "$ROOT_MOUNT/etc/systemd/system/multi-user.target.wants/php${PHP_VER}-fpm.service" ] && echo "$(green_check) php${PHP_VER}-fpm.service enabled" || echo "$(red_x) php${PHP_VER}-fpm.service not enabled"
        [ -f "$ROOT_MOUNT/etc/systemd/system/bluetooth_pair.service" ] && echo "$(green_check) bluetooth_pair.service exists" || echo "$(red_x) bluetooth_pair.service missing"
        # NetworkManager / dnsmasq
        [ -f "$ROOT_MOUNT/etc/NetworkManager/NetworkManager.conf" ] && echo "$(green_check) NetworkManager config exists" || echo "$(red_x) NetworkManager config missing"
        grep -q "managed=true" "$ROOT_MOUNT/etc/NetworkManager/NetworkManager.conf" && echo "$(green_check) NetworkManager is managed" || echo "$(red_x) NetworkManager not set to managed"
        [ -f "$ROOT_MOUNT/etc/dnsmasq.conf" ] && echo "$(green_check) dnsmasq config exists" || echo "$(red_x) dnsmasq config missing"
        grep -q "interface=usb0" "$ROOT_MOUNT/etc/dnsmasq.conf" && echo "$(green_check) dnsmasq configured for usb0" || echo "$(red_x) dnsmasq not configured for usb0"
        grep -q "dhcp-range=192.168.8.2,192.168.8.100,12h" "$ROOT_MOUNT/etc/dnsmasq.conf" && echo "$(green_check) dnsmasq DHCP range configured" || echo "$(red_x) dnsmasq DHCP range not configured"
        # Locale
        [ -f "$ROOT_MOUNT/etc/locale.gen" ] && echo "$(green_check) locale.gen exists" || echo "$(red_x) locale.gen missing"
        grep -q "en_US.UTF-8 UTF-8" "$ROOT_MOUNT/etc/locale.gen" && echo "$(green_check) en_US.UTF-8 locale configured" || echo "$(red_x) en_US.UTF-8 locale not configured"
        [ -f "$ROOT_MOUNT/etc/default/locale" ] && echo "$(green_check) default locale file exists" || echo "$(red_x) default locale file missing"
        grep -q "LANG=en_US.UTF-8" "$ROOT_MOUNT/etc/default/locale" && echo "$(green_check) default locale is en_US.UTF-8" || echo "$(red_x) default locale not set to en_US.UTF-8"
        # .env secrets
        [ -f "$ROOT_MOUNT/root/.env" ] && echo "$(green_check) /root/.env exists" || echo "$(red_x) /root/.env missing"
        perms=$(stat -c "%a" "$ROOT_MOUNT/root/.env" 2>/dev/null || echo "")
        [ "$perms" = "600" ] && echo "$(green_check) /root/.env permissions 600" || echo "$(red_x) /root/.env permissions not 600"
        # HTML
        [ -d "$ROOT_MOUNT/var/www/html" ] && echo "$(green_check) /var/www/html exists" || echo "$(red_x) /var/www/html missing"
        [ -f "$ROOT_MOUNT/var/www/html/wifi-scan.sh" ] && echo "$(green_check) wifi-scan.sh exists" || echo "$(red_x) wifi-scan.sh missing"
        [ -f "$ROOT_MOUNT/var/www/html/passwords.txt" ] && echo "$(green_check) passwords.txt exists" || echo "$(red_x) passwords.txt missing"
        [ -f "$ROOT_MOUNT/var/www/html/check.py" ] && echo "$(green_check) check.py exists" || echo "$(red_x) check.py missing"
        grep -q "Haxinator" "$ROOT_MOUNT/var/www/html/index.php" && echo "$(green_check) index.php contains Haxinator" || echo "$(red_x) index.php missing Haxinator"

        # Hans
        [ -f "$ROOT_MOUNT/usr/local/bin/hans" ] && echo "$(green_check) hans binary exists" || echo "$(red_x) hans binary missing"
        [ -f "$ROOT_MOUNT/usr/lib/NetworkManager/hans-service.py" ] && echo "$(green_check) hans service script exists" || echo "$(red_x) hans service script missing"
        [ -f "$ROOT_MOUNT/usr/lib/NetworkManager/VPN/hans.name" ] && echo "$(green_check) hans VPN name file exists" || echo "$(red_x) hans VPN name file missing"
        [ -f "$ROOT_MOUNT/etc/dbus-1/system.d/nm-hans-service.conf" ] && echo "$(green_check) hans D-Bus config exists" || echo "$(red_x) hans D-Bus config missing"


# --------------------------------------------------------------------------
# Check if 01proxy file is still present (it should NOT be in the final image)
# --------------------------------------------------------------------------
APT_PROXY_FILE="$ROOT_MOUNT/etc/apt/apt.conf.d/01proxy"
if [ -f "$APT_PROXY_FILE" ]; then
    echo "$(red_x) $APT_PROXY_FILE is present (it should have been removed!)"
else
    echo "$(green_check) No apt proxy file found in final image (as expected)"
fi



    else
        echo "$(red_x) Root partition not mounted"
    fi
}

# Run verification before unmounting
verify_image

# Unmount partitions
sudo umount "$ROOT_MOUNT" 2>/dev/null || { echo "Error: Failed to unmount $ROOT_MOUNT"; exit 1; }
echo "Root partition unmounted from $ROOT_MOUNT"

sudo umount "$BOOT_MOUNT" 2>/dev/null || { echo "Error: Failed to unmount $BOOT_MOUNT"; exit 1; }
echo "Boot partition unmounted from $BOOT_MOUNT"

# Detach loop devices referencing this image
for loopdev in $(sudo losetup -a | grep "$(realpath "$IMAGE_PATH")" | cut -d: -f1); do
    sudo losetup -d "$loopdev" || { echo "Error: Failed to detach $loopdev"; exit 1; }
done
echo "All loop devices detached"

# Remove mount points
sudo rmdir "$ROOT_MOUNT" 2>/dev/null || echo "Note: $ROOT_MOUNT not removed (may not be empty or already removed)"
sudo rmdir "$BOOT_MOUNT" 2>/dev/null || echo "Note: $BOOT_MOUNT not removed (may not be empty or already removed)"

echo "Cleanup successful."
