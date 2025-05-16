#!/bin/bash -e
set -euo pipefail
IFS=$'\n\t'

# Colour / checkmark helpers
source "/common-functions.sh"

verify_image() {
    echo "Verifying image contents..."

    # Boot partition
    [ -f /boot/firmware/cmdline.txt ] && echo "$(green_check) cmdline.txt exists" || echo "$(red_x) cmdline.txt missing"
    [ -f /boot/firmware/config.txt  ] && echo "$(green_check) config.txt exists"  || echo "$(red_x) config.txt missing"
    
    # Boot - cmdline.txt content checks
    if [ -f /boot/firmware/cmdline.txt ]; then
        CMDLINE=$(cat /boot/firmware/cmdline.txt)
        
        # Check serial console is removed - more robust check
        if echo "$CMDLINE" | grep -q "console=serial0,115200"; then
            echo "$(red_x) cmdline.txt still contains console=serial0,115200 (should be removed)"
        else
            echo "$(green_check) cmdline.txt correctly has console=serial0,115200 removed"
        fi
        
        # Check g_cdc is present
        if echo "$CMDLINE" | grep -q "modules-load=dwc2,g_cdc"; then
            echo "$(green_check) cmdline.txt has g_cdc USB module enabled"
        else
            echo "$(red_x) cmdline.txt missing g_cdc USB module"
        fi
    fi
    
    # Boot - config.txt last line check
    if [ -f /boot/firmware/config.txt ]; then
        # Check if the last line contains the peripheral mode
        LAST_LINE=$(tail -n 1 /boot/firmware/config.txt)
        if [ "$LAST_LINE" = "dtoverlay=dwc2,dr_mode=peripheral" ]; then
            echo "$(green_check) config.txt correctly ends with dtoverlay=dwc2,dr_mode=peripheral"
        else
            echo "$(red_x) config.txt does not end with dtoverlay=dwc2,dr_mode=peripheral"
            echo "    Last line is: $LAST_LINE"
        fi
    fi

    # Root – nginx
    [ -d /etc/nginx ] && echo "$(green_check) nginx directory exists" || echo "$(red_x) nginx directory missing"
    [ -f /etc/nginx/nginx.conf ] && echo "$(green_check) nginx.conf exists" || echo "$(red_x) nginx.conf missing"
    [ -f /etc/nginx/sites-available/pi ] && echo "$(green_check) sites-available/pi exists" || echo "$(red_x) sites-available/pi missing"
    [ -L /etc/nginx/sites-enabled/pi ] && echo "$(green_check) sites-enabled/pi symlink exists" || echo "$(red_x) sites-enabled/pi symlink missing"

    # Root – SSL
    [ -f /etc/ssl/local/pi.crt ] && echo "$(green_check) SSL certificate exists" || echo "$(red_x) SSL certificate missing"
    [ -f /etc/ssl/local/pi.key ] && echo "$(green_check) SSL key exists"         || echo "$(red_x) SSL key missing"

    # Root – PHP-FPM
    PHP_VER=$(find /etc/php -maxdepth 1 -mindepth 1 -type d -printf '%f\n' | sort -V | tail -n1 || true)
    if [ -n "$PHP_VER" ]; then
        [ -f /etc/php/$PHP_VER/fpm/pool.d/www.conf ] && echo "$(green_check) PHP-FPM pool config exists" || echo "$(red_x) PHP-FPM pool config missing"
        [ -f /etc/php/$PHP_VER/fpm/php.ini ] && echo "$(green_check) php.ini exists" || echo "$(red_x) php.ini missing"
        grep -q "pm = ondemand" /etc/php/$PHP_VER/fpm/pool.d/www.conf && echo "$(green_check) PHP-FPM ondemand" || echo "$(red_x) PHP-FPM not ondemand"
        grep -q "memory_limit = 64M" /etc/php/$PHP_VER/fpm/php.ini && echo "$(green_check) PHP memory limit 64M" || echo "$(red_x) PHP memory limit incorrect"
    else
        echo "$(red_x) PHP not installed"
    fi

    # Root – services
    [ -L /etc/systemd/system/multi-user.target.wants/nginx.service ] && echo "$(green_check) nginx.service enabled" || echo "$(red_x) nginx.service not enabled"
    [ -L /etc/systemd/system/multi-user.target.wants/php${PHP_VER}-fpm.service ] && echo "$(green_check) php${PHP_VER}-fpm.service enabled" || echo "$(red_x) php${PHP_VER}-fpm.service not enabled"
    [ -f /etc/systemd/system/bluetooth_pair.service ] && echo "$(green_check) bluetooth_pair.service exists" || echo "$(red_x) bluetooth_pair.service missing"

    # Root – NetworkManager / dnsmasq
    [ -f /etc/NetworkManager/NetworkManager.conf ] && echo "$(green_check) NetworkManager.conf exists" || echo "$(red_x) NetworkManager.conf missing"
    grep -q "managed=true" /etc/NetworkManager/NetworkManager.conf && echo "$(green_check) NetworkManager managed" || echo "$(red_x) NetworkManager managed=false"
    [ -f /etc/dnsmasq.conf ] && echo "$(green_check) dnsmasq.conf exists" || echo "$(red_x) dnsmasq.conf missing"
    grep -q "interface=usb0" /etc/dnsmasq.conf && echo "$(green_check) dnsmasq interface usb0" || echo "$(red_x) dnsmasq usb0 missing"
    grep -q "dhcp-range=192.168.8.2,192.168.8.100,12h" /etc/dnsmasq.conf && echo "$(green_check) dnsmasq DHCP range" || echo "$(red_x) dnsmasq DHCP range missing"

    # Root – locale
    [ -f /etc/locale.gen ] && grep -q "en_US.UTF-8 UTF-8" /etc/locale.gen && echo "$(green_check) locale.gen OK" || echo "$(red_x) locale.gen incorrect"
    [ -f /etc/default/locale ] && grep -q "LANG=en_US.UTF-8" /etc/default/locale && echo "$(green_check) default locale OK" || echo "$(red_x) default locale incorrect"

    # Root – secrets
    if [ -f /root/.env ]; then
        [ "$(stat -c %a /root/.env)" = "600" ] && echo "$(green_check) /root/.env perms 600" || echo "$(red_x) /root/.env perms wrong"
        # Check Bluetooth MAC format
        if grep -q "BLUETOOTH_MAC=XX:XX:XX:XX:XX:XX" /root/.env; then
            echo "$(green_check) Secrets are anonymous"
        else
            echo "$(green_check) Secrets are populated"
        fi
    else
        echo "$(red_x) /root/.env missing"
    fi

    # Root – web files
    [ -d /var/www/html ] && echo "$(green_check) /var/www/html exists" || echo "$(red_x) /var/www/html missing"
    [ -f /var/www/html/wifi-scan.sh ] && echo "$(green_check) wifi-scan.sh exists" || echo "$(red_x) wifi-scan.sh missing"
    [ -f /var/www/html/passwords.txt ] && echo "$(green_check) passwords.txt exists" || echo "$(red_x) passwords.txt missing"
    [ -f /var/www/html/check.py ] && echo "$(green_check) check.py exists" || echo "$(red_x) check.py missing"
    grep -q "Haxinator" /var/www/html/index.php && echo "$(green_check) index.php contains Haxinator" || echo "$(red_x) index.php missing Haxinator"

    # Root – hans
    [ -f /usr/local/bin/hans ] && echo "$(green_check) hans binary exists" || echo "$(red_x) hans binary missing"
    [ -f /usr/lib/NetworkManager/hans-service.py ] && echo "$(green_check) hans service exists" || echo "$(red_x) hans service missing"
    [ -f /usr/lib/NetworkManager/VPN/hans.name ] && echo "$(green_check) hans name file exists" || echo "$(red_x) hans name file missing"
    [ -f /etc/dbus-1/system.d/nm-hans-service.conf ] && echo "$(green_check) hans D-Bus config exists" || echo "$(red_x) hans D-Bus config missing"

    # Root – 01proxy must be gone
    if [ -f /etc/apt/apt.conf.d/01proxy ]; then
        echo "$(red_x) /etc/apt/apt.conf.d/01proxy present (should be removed!)"
        return 1
    else
        echo "$(green_check) No apt proxy file (as expected)"
    fi
}

verify_image
