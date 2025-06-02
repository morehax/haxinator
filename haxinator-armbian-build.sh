#!/usr/bin/env bash
# Haxinator - Network Security Testing Platform
# v.0.2a  (only the two sed commands have been fixed for macOS + Linux)
# =============================================================================
# Armbian Custom Build Script for Haxinator Project
# =============================================================================
#
# DESCRIPTION:
#   This script automates the creation of custom Armbian images for various
#   single-board computers. It clones the official Armbian build system,
#   applies custom overlays, installs specialized packages, and configures
#   the system for the Haxinator VPN Network Manager system. If Docker
#   is installed, the (armbian) build script diverts to Docker.
#
# SUPPORTED BOARDS:
#   • bananapim4zero  - Banana Pi M4 Zero (WiFi overlay enabled)
#   • orangepizero2w  - Orange Pi Zero 2W (WiFi overlay enabled)  
#   • rpi4b          - Raspberry Pi 5,4B,Zero W2 (and possibly others)
#
# REQUIREMENTS:
#   • For native builds - check utils/install_something_something.sh
#   • For Docker builds - it just does its thing
#
# OUTPUT:
#   • Custom Armbian image file (.img)
#   • Located in: build/output/images/
#   • Ready to flash to SD card/eMMC
#
# NOTES:
#   • Build process takes 30-60+ minutes depending on hardware
#   • Requires stable internet connection throughout build
#   • Each board type has specific hardware optimizations
#   • WiFi overlays are board-specific for proper hardware support
#
# =============================================================================

set -euo pipefail

# -----------------------------------------------------------------------------
# Command line argument parsing
# -----------------------------------------------------------------------------
usage() {
    echo "Usage: $0 <board_type>"
    echo ""
    echo "Supported board types:"
    echo "  bananapim4zero - Banana Pi M4 Zero"
    echo "  orangepizero2w - Orange Pi Zero 2W"
    echo "  rpi4b          - Raspberry Pi 5,4B,Zero W2 (and possibly others)"
    echo ""
    exit 1
}

# Check if argument is provided
if [ $# -eq 0 ]; then
    echo "Error: No board type specified"
    usage
elif [ $# -gt 1 ]; then
    echo "Error: Too many arguments"
    usage
fi

BOARD_TYPE="$1"

# Validate and set board-specific variables
case "$BOARD_TYPE" in
    "bananapim4zero")
        BOARD="bananapim4zero"
        NEEDS_BOOT_CMD_FIX=true
        RPI_CONFIG_NEEDED=false
        ;;
    "orangepizero2w")
        BOARD="orangepizero2w"
        NEEDS_BOOT_CMD_FIX=true
        RPI_CONFIG_NEEDED=false
        ;;
    "rpi4b")
        BOARD="rpi4b"
        NEEDS_BOOT_CMD_FIX=false
        RPI_CONFIG_NEEDED=true
        ;;
    *)
        echo "Error: Unsupported board type '$BOARD_TYPE'"
        usage
        ;;
esac

echo "Building for board: $BOARD"

# -----------------------------------------------------------------------------
# 1. Clone the Armbian build system
# -----------------------------------------------------------------------------
rm -rf build
git clone --depth 1 https://github.com/armbian/build || exit 1
cd build || exit 1

# -----------------------------------------------------------------------------
# 2. Prepare overlay directories and copy resources
# -----------------------------------------------------------------------------
mkdir -p userpatches/overlay/
cp -rf ../haxinator-pigen-overlay/stage2/02-custom-config/root_files/files  userpatches/overlay/
cp -rf ../haxinator-pigen-overlay/stage2/02-custom-config/root_files/html2   userpatches/overlay/html

cp ../haxinator-pigen-overlay/stage2/99-self-tests/00-self-tests.sh userpatches/overlay/
cp ../common-functions.sh userpatches/overlay/


# -----------------------------------------------------------------------------
# 3. Generate the customize-image.sh script that runs inside the rootfs chroot
# -----------------------------------------------------------------------------
cat << EOF > userpatches/customize-image.sh
#!/usr/bin/env bash
# -------------------------------------------------------------------------
#  customize-image.sh
# -------------------------------------------------------------------------
# Runs inside the target filesystem to install packages, copy resources,
# enable services, and perform firstboot provisioning tweaks.
# -------------------------------------------------------------------------
set -euo pipefail

# Board type passed from main script
BOARD="$BOARD"

# --- Logging -----------------------------------------------------------------
echo "Starting customize-image.sh" > /root/customize.log

# Source common functions early so yellow_echo and friends are available
source /tmp/overlay/common-functions.sh

# --- Package installation ----------------------------------------------------
echo "Updating package lists" >> /root/customize.log
apt-get update

# Base utilities + networking
echo "Installing packages: vim htop net-tools wireless-tools locate" >> /root/customize.log
apt-get install -y \\
    vim htop net-tools wireless-tools locate iodine iptables \\
    cryptsetup openssl ca-certificates git apache2 php php-ssh2 php-mbstring \\
    php-curl network-manager-openvpn libapache2-mod-php dnsutils shellinabox \\
    ssl-cert dnsmasq python3-dbus python3-gi python3-dotenv git make g++ bluez bluez-tools python3-dnspython python3-pip

# --- Sudoers tweaks ----------------------------------------------------------
echo "www-data ALL=(ALL) NOPASSWD: /sbin/poweroff, /usr/bin/ssh, /bin/kill, /usr/bin/pgrep, /usr/bin/ssh-keygen, /usr/bin/python3" | sudo tee -a /etc/sudoers
usermod -aG netdev www-data
# install this and see how it goes!
pip3 install pywifi --break-system-packages


# --- dnsmasq & NetworkManager -------------------------------------------------
echo "interface=usb0"                       >> /etc/dnsmasq.conf
echo "dhcp-range=192.168.8.2,192.168.8.100,12h" >> /etc/dnsmasq.conf
sed -i 's/managed=false/managed=true/' /etc/NetworkManager/NetworkManager.conf

systemctl disable dnsmasq
#systemctl disable systemd-resolved
systemctl disable wpa_supplicant

# --- Apache (SSL) ------------------------------------------------------------
a2enmod ssl
a2ensite default-ssl

if [ \$? -eq 0 ]; then
  echo "Package installation successful" >> /root/customize.log
else
  echo "Package installation failed"     >> /root/customize.log
fi

# -------------------------------------------------------------------------
# 4. Overlay resource deployment
# -------------------------------------------------------------------------

cp -rf /tmp/overlay/html/*  /var/www/html/
chown -R www-data:www-data   /var/www/
mkdir -p /var/www/scripts
install -m 755 /tmp/overlay/files/wifi-password-test.py /var/www/scripts

cp -rf /tmp/overlay/files             /root/
install -m 755 /tmp/overlay/files/rc.local /etc/rc.local

# --- Build & install HANS ----------------------------------------------------
git clone https://github.com/friedrich/hans.git
cd hans
make
install -m 755 hans /usr/local/bin/hans
cd ..

# --- NetworkManager helper scripts ------------------------------------------
install -m 755 /tmp/overlay/files/hans2/hans-service.py   /usr/lib/NetworkManager/hans-service.py
install -m 755 /tmp/overlay/files/hans2/iodine-service.py /usr/lib/NetworkManager/iodine-service.py

mkdir -p /usr/lib/NetworkManager/VPN
install -m 644 /tmp/overlay/files/hans2/hans.name   /usr/lib/NetworkManager/VPN/hans.name
install -m 644 /tmp/overlay/files/hans2/iodine.name /usr/lib/NetworkManager/VPN/iodine.name

mkdir -p /etc/dbus-1/system.d
install -m 644 /tmp/overlay/files/hans2/nm-hans-service.conf   /etc/dbus-1/system.d/nm-hans-service.conf
install -m 644 /tmp/overlay/files/hans2/nm-iodine-service.conf /etc/dbus-1/system.d/nm-iodine-service.conf

# This can be cleaned out
mkdir -p /etc/NetworkManager/dispatcher.d
install -m 755 /tmp/overlay/files/hans2/99-clean-vpn-routes /etc/NetworkManager/dispatcher.d/99-clean-vpn-routes

# --- Systemd services --------------------------------------------------------
install -m 644 /tmp/overlay/files/services/bluetooth_pair.service /etc/systemd/system/bluetooth_pair.service
install -m 644 /tmp/overlay/files/services/firstboot.service      /lib/systemd/system/firstboot.service

# needs cleanup
# cp -rf /tmp/overlay/files/services/enable-usb-ether.service /etc/systemd/system/
# chmod 0644 /etc/systemd/system/enable-usb-ether.service
# systemctl enable enable-usb-ether.service  # (left disabled intentionally)

# for ubuntu noble (polkit syntax changed very recently, has a different format for older / debian systems)
mkdir -p /etc/polkit-1/rules.d
install -m 644 /tmp/overlay/files/10-nmcli-webui.rules-ubuntu /etc/polkit-1/rules.d/10-nmcli-webui.rules

mkdir -p /usr/local/bin
install -m 755 /tmp/overlay/files/bluetooth/auto-pair /usr/local/bin/auto-pair
install -m 755 /tmp/overlay/files/firstboot.sh       /usr/local/bin/firstboot.sh

# Testing ExpressVPN config
install -m 644 /tmp/overlay/files/openvpn-udp.ovpn /openvpn-udp.ovpn

# Placeholder for stuff, consider removing
install -m 755 /tmp/overlay/files/update_me.sh     /update_me.sh


# Bluetooth and serial over bluetooth
#install -m 644 /tmp/overlay/files/services/dbus-org.bluez.service /etc/systemd/system/dbus-org.bluez.service
#install -m 644 /tmp/overlay/files/services/rfcomm.service          /etc/systemd/system/rfcomm.service

# Also needs cleanup
# cp -rf /tmp/overlay/files/services/unblock-wifi.service /etc/systemd/system/

# systemctl enable unblock-wifi.service
# systemctl enable bluetooth.service                 || yellow_echo "WARNING: Failed to enable bluetooth"
# systemctl enable bluetooth_pair.service    || yellow_echo "WARNING: Failed to enable bluetooth_pair.service"
# systemctl enable dbus-org.bluez.service

# Serial over USB ethernet
systemctl enable serial-getty@ttyGS0.service || yellow_echo "WARNING: Failed to enable serial-getty@ttyGS0.service"
# Serial over BT
# systemctl enable serial-getty@rfcomm.service

# systemctl enable firstboot || yellow_echo "WARNING: Failed to enable firstboot"
# systemctl enable rfcomm                    || yellow_echo "WARNING: Failed to enable rfcomm"

systemctl enable shellinabox || yellow_echo "WARNING: Failed to enable shellinabox"

systemctl mask wpa_supplicant@wlan0.service || yellow_echo "WARNING: Failed to mask wpa_supplicant@wlan0.service"
systemctl enable NetworkManager            || yellow_echo "WARNING: Failed to enable NetworkManager"
systemctl disable dnsmasq                  || yellow_echo "WARNING: Failed to disable dnsmasq"

apt-get clean

# --- Web directory permissions ----------------------------------------------
if [ -d /var/www/html ]; then
    echo "Setting permissions on /var/www/html" >> /root/customize.log
    chown -R www-data:www-data /var/www/
    chmod -R 755 /var/www/
    echo "Contents of /var/www/html:" >> /root/customize.log
    ls -la /var/www/html >> /root/customize.log
fi

# --- Self-test & provisioning ------------------------------------------------
install -m 755 /tmp/overlay/00-self-tests.sh      /00-self-tests.sh
install -m 755 /tmp/overlay/common-functions.sh  /common-functions.sh

# Board-specific boot configuration
if [ "\$BOARD" = "bananapim4zero" ] || [ "\$BOARD" = "orangepizero2w" ]; then
    echo "Applying board-specific modifications for \$BOARD" >> /root/customize.log
    
    # Add WiFi overlay to armbianEnv.txt
    echo "overlays=bananapi-m4-sdio-wifi-bt" >> /boot/armbianEnv.txt
    echo "Added WiFi overlay to armbianEnv.txt" >> /root/customize.log
    
    # Modify boot.cmd for console and module loading
    sed -i 's/console=ttyS0,115200/console=ttyGS0,115200 modules-load=dwc2,g_cdc cfg80211.ieee80211_regdom=GB/' /boot/boot.cmd
    
    # Rebuild boot.scr from modified boot.cmd
    mkimage -C none -A arm -T script -d /boot/boot.cmd /boot/boot.scr
    echo "boot.cmd modifications completed" >> /root/customize.log
else
    echo "No board-specific modifications needed for \$BOARD" >> /root/customize.log
fi

## This is for all images
install -m 644 /tmp/overlay/files/armbian-preset.txt /root/.not_logged_in_yet

# Clean up default Apache page
rm -rf /var/www/html/index.html

/00-self-tests.sh

# --- Custom script inside chroot --------------------------------------------
echo "Running custom script" >> /root/customize.log
cat << 'SCRIPT' > /tmp/custom-script.sh
#!/usr/bin/env bash
echo "Running custom script in chroot" >> /root/customize.log
mv /var/lib/shellinabox/certificate.pem /var/lib/shellinabox/certificate.pem.bak
cat /etc/ssl/certs/ssl-cert-snakeoil.pem /etc/ssl/private/ssl-cert-snakeoil.key > /var/lib/shellinabox/certificate.pem
chown shellinabox:shellinabox /var/lib/shellinabox/certificate.pem
chmod 600 /var/lib/shellinabox/certificate.pem
SCRIPT
chmod +x /tmp/custom-script.sh
/tmp/custom-script.sh
EOF

chmod +x userpatches/customize-image.sh || exit 1

# -----------------------------------------------------------------------------
# 4. Board-specific configuration changes (outside chroot)
# -----------------------------------------------------------------------------
if [ "$RPI_CONFIG_NEEDED" = true ]; then
    echo "Applying Raspberry Pi specific configuration..."
    # Enable the correct parameters in cmdline and config.txt files for Raspberry Pi
    patch config/sources/families/bcm2711.conf < ../haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/bcm2711.patch 

fi

# -----------------------------------------------------------------------------
# 5. Invoke Armbian compile.sh with desired parameters
# -----------------------------------------------------------------------------
echo "Starting build for board: $BOARD"

./compile.sh build \
  BOARD=$BOARD \
  BRANCH=current \
  RELEASE=noble \
  BUILD_MINIMAL=yes \
  BUILD_DESKTOP=no \
  KERNEL_CONFIGURE=no \
  NETWORKING_STACK="network-manager" \
  KERNEL_GIT=shallow \
  FORCE_RECREATE_ROOTFS=yes || exit 1

echo "Build completed"

