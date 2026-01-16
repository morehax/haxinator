#!/usr/bin/env bash
#
# Haxinator v.0.3 - Network Tunneling Platform
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
    echo ""
    echo "Haxinator Armbian Build"
    echo ""
    echo "Usage: $0 <board>"
    echo ""
    echo "Boards:"
    echo "  bananapim4zero    Banana Pi M4 Zero"
    echo "  orangepizero2w    Orange Pi Zero 2W"
    echo "  rpi4b             Raspberry Pi 4B/5/Zero 2W"
    echo ""
    exit 1
}

# Check if argument is provided
if [ $# -eq 0 ]; then
    echo "Error: No board specified"
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
        echo "Error: Unknown board '$BOARD_TYPE'"
        usage
        ;;
esac

echo "Building for board: $BOARD"

# -----------------------------------------------------------------------------
# 1. Clone the Armbian build system
# -----------------------------------------------------------------------------
rm -rf build
# git clone --depth 1 https://github.com/armbian/build || exit 1
git clone --depth 1 --branch v25.11.1 --single-branch https://github.com/armbian/build
cd build

# -----------------------------------------------------------------------------
# 2. Prepare overlay directories and copy resources
# -----------------------------------------------------------------------------
mkdir -p userpatches/overlay/
cp -rf ../files  userpatches/overlay/

# New GO web UI
cp -rf ../nm-webui userpatches/overlay/

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
echo "Starting customize-image.sh"
echo "nameserver 8.8.8.8" >> /etc/resolv.conf

# --- Package installation ----------------------------------------------------
echo "Updating package lists"
apt-get update

# Base utilities + networking
apt-get install -y \\
    vim htop net-tools wireless-tools locate iodine iptables \\
    cryptsetup openssl ca-certificates git \\
    network-manager-openvpn dnsutils shellinabox \\
    ssl-cert dnsmasq python3-dbus python3-gi git make g++ golang-go nmap ncat

# --- dnsmasq & NetworkManager -------------------------------------------------

echo "interface=usb0" >> /etc/dnsmasq.conf
echo "dhcp-range=192.168.8.2,192.168.8.100,12h" >> /etc/dnsmasq.conf
sed -i 's/managed=false/managed=true/' /etc/NetworkManager/NetworkManager.conf

install -m 755 /tmp/overlay/files/rc.local /etc/rc.local

# New Web UI
cp -rf /tmp/overlay/nm-webui /opt/
cd /opt/nm-webui/
./install.sh
cd ..

# --- Build & install HANS ----------------------------------------------------
cp -rf /tmp/overlay/files/hans /opt
cd /opt/hans
make
install -m 755 hans /usr/local/bin/hans
cd ..

# --- NetworkManager helper scripts ------------------------------------------

install -m 755 /tmp/overlay/files/hans-service.py   /usr/lib/NetworkManager/hans-service.py
install -m 755 /tmp/overlay/files/iodine-service.py /usr/lib/NetworkManager/iodine-service.py

mkdir -p /usr/lib/NetworkManager/VPN
install -m 644 /tmp/overlay/files/hans.name   /usr/lib/NetworkManager/VPN/hans.name
install -m 644 /tmp/overlay/files/iodine.name /usr/lib/NetworkManager/VPN/iodine.name

mkdir -p /etc/dbus-1/system.d
install -m 644 /tmp/overlay/files/nm-hans-service.conf   /etc/dbus-1/system.d/nm-hans-service.conf
install -m 644 /tmp/overlay/files/nm-iodine-service.conf /etc/dbus-1/system.d/nm-iodine-service.conf

# HANS VPN route cleanup
mkdir -p /etc/NetworkManager/dispatcher.d
install -m 755 /tmp/overlay/files/99-clean-vpn-routes /etc/NetworkManager/dispatcher.d/99-clean-vpn-routes

systemctl disable wpa_supplicant
systemctl enable serial-getty@ttyGS0.service
systemctl enable shellinabox
systemctl mask wpa_supplicant@wlan0.service
systemctl enable NetworkManager
systemctl disable dnsmasq

apt-get clean

# Board-specific boot configuration
if [ "\$BOARD" = "bananapim4zero" ] || [ "\$BOARD" = "orangepizero2w" ]; then
    echo "Applying board-specific modifications for \$BOARD"
    
    # Add WiFi overlay to armbianEnv.txt
    echo "overlays=bananapi-m4-sdio-wifi-bt" >> /boot/armbianEnv.txt
    echo "Added WiFi overlay to armbianEnv.txt"
    
    # Modify boot.cmd for console and module loading
    sed -i 's/console=ttyS0,115200/console=ttyGS0,115200 modules-load=dwc2,g_cdc cfg80211.ieee80211_regdom=GB/' /boot/boot.cmd
    
    # Rebuild boot.scr from modified boot.cmd
    mkimage -C none -A arm -T script -d /boot/boot.cmd /boot/boot.scr
    echo "boot.cmd modifications completed"
else
    echo "No board-specific modifications needed for \$BOARD"
fi

## This is for all images
install -m 644 /tmp/overlay/files/armbian-preset.txt /root/.not_logged_in_yet

# --- Shellinabox SSL certificate setup --------------------------------------
echo "Configuring shellinabox"
cat /etc/ssl/certs/ssl-cert-snakeoil.pem /etc/ssl/private/ssl-cert-snakeoil.key > /var/lib/shellinabox/certificate.pem
chown shellinabox:shellinabox /var/lib/shellinabox/certificate.pem
chmod 600 /var/lib/shellinabox/certificate.pem

# Swap shellinabox color scheme defaults
cd /etc/shellinabox/options-enabled/
rm -f 00+Black\ on\ White.css 00_White\ On\ Black.css
ln -s ../options-available/00_White\ On\ Black.css ./00+White\ On\ Black.css
ln -s ../options-available/00+Black\ on\ White.css ./00_Black\ on\ White.css
EOF

chmod +x userpatches/customize-image.sh

# -----------------------------------------------------------------------------
# 4. Board-specific configuration changes (outside chroot)
# -----------------------------------------------------------------------------
if [ "$RPI_CONFIG_NEEDED" = true ]; then
    echo "Applying Raspberry Pi specific configuration..."
    # Enable the correct parameters in cmdline and config.txt files for Raspberry Pi
    patch config/sources/families/bcm2711.conf < ../files/bcm2711.patch 

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
  FORCE_RECREATE_ROOTFS=yes

echo "Build completed"
