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

# Copy SSH public key if it exists
if [ -f ../id_ed25519.pub ]; then
    cp ../id_ed25519.pub userpatches/overlay/
    echo "SSH public key found and copied to overlay"
fi

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
# bluez bluez-tools
# --- dnsmasq & NetworkManager -------------------------------------------------

# echo "interface=usb0" >> /etc/dnsmasq.conf
# echo "dhcp-range=192.168.8.2,192.168.8.100,12h" >> /etc/dnsmasq.conf
sed -i 's/managed=false/managed=true/' /etc/NetworkManager/NetworkManager.conf

# --- USB Gadget (Cross-platform: CDC NCM for Windows/macOS/Linux) -------------
install -m 755 /tmp/overlay/files/setup-haxinator-usb-gadget.sh /usr/local/bin/setup-haxinator-usb-gadget.sh
install -m 755 /tmp/overlay/files/remove-haxinator-usb-gadget.sh /usr/local/bin/remove-haxinator-usb-gadget.sh
install -m 644 /tmp/overlay/files/haxinator-usb-gadget.service /etc/systemd/system/haxinator-usb-gadget.service

# --- USB0 Network Configuration ---------------------------
install -m 755 /tmp/overlay/files/setup-usb0-network.sh /usr/local/bin/setup-usb0-network.sh
install -m 644 /tmp/overlay/files/haxinator-usb0-network.service /etc/systemd/system/haxinator-usb0-network.service

# --- Bluetooth PAN (commented out for now) ------------------------------------
# install -m 755 /tmp/overlay/files/haxinator-bt-pan.sh /usr/local/sbin/haxinator-bt-pan.sh
# install -m 644 /tmp/overlay/files/haxinator-bt-agent.service /etc/systemd/system/haxinator-bt-agent.service
# install -m 644 /tmp/overlay/files/haxinator-bt-pan.service /etc/systemd/system/haxinator-bt-pan.service
# install -m 644 /tmp/overlay/files/99-haxinator-bt-pan.conf /etc/sysctl.d/99-haxinator-bt-pan.conf
# install -m 644 /tmp/overlay/files/br-bt.netdev /etc/systemd/network/br-bt.netdev
# install -m 644 /tmp/overlay/files/br-bt.network /etc/systemd/network/br-bt.network
# install -m 644 /tmp/overlay/files/99-unmanaged-br-bt.conf /etc/NetworkManager/conf.d/99-unmanaged-br-bt.conf
#
# mkdir -p /etc/systemd/system-preset
# install -m 644 /tmp/overlay/files/99-haxinator.preset /etc/systemd/system-preset/99-haxinator.preset
#
# mkdir -p /etc/systemd/system/systemd-networkd-wait-online.service.d
# install -m 644 /tmp/overlay/files/systemd-networkd-wait-online.override.conf \\
#   /etc/systemd/system/systemd-networkd-wait-online.service.d/override.conf
#
# mkdir -p /etc/systemd/system/NetworkManager-wait-online.service.d
# install -m 644 /tmp/overlay/files/NetworkManager-wait-online.override.conf \\
#   /etc/systemd/system/NetworkManager-wait-online.service.d/override.conf
#
# sed -i 's/^#*AutoEnable=.*/AutoEnable=true/' /etc/bluetooth/main.conf
# sed -i 's/^#*DiscoverableTimeout=.*/DiscoverableTimeout=0/' /etc/bluetooth/main.conf
# sed -i 's/^#*PairableTimeout=.*/PairableTimeout=0/' /etc/bluetooth/main.conf

# --- New Web UI -------------------------------------------------------------
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
systemctl enable haxinator-usb-gadget.service
systemctl enable haxinator-usb0-network.service
# Bluetooth services (commented out for now)
# systemctl enable bluetooth
# systemctl enable haxinator-bt-agent.service
# systemctl enable haxinator-bt-pan.service
# systemctl enable systemd-networkd
# systemctl disable systemd-networkd-wait-online.service
# systemctl disable NetworkManager-wait-online.service
systemctl disable dnsmasq

apt-get clean

# Board-specific boot configuration
if [ "\$BOARD" = "bananapim4zero" ] || [ "\$BOARD" = "orangepizero2w" ]; then
    echo "Applying board-specific modifications for \$BOARD"
    
    # Add WiFi overlay to armbianEnv.txt
    echo "overlays=bananapi-m4-sdio-wifi-bt" >> /boot/armbianEnv.txt
    echo "Added WiFi overlay to armbianEnv.txt"
    
    # Modify boot.cmd for console and module loading
    # Note: We only load dwc2 here; the USB gadget is configured via systemd service using configfs
    sed -i 's/console=ttyS0,115200/console=ttyGS0,115200 modules-load=dwc2 cfg80211.ieee80211_regdom=GB/' /boot/boot.cmd
    
    # Rebuild boot.scr from modified boot.cmd
    mkimage -C none -A arm -T script -d /boot/boot.cmd /boot/boot.scr
    echo "boot.cmd modifications completed"
else
    echo "No board-specific modifications needed for \$BOARD"
fi

## This is for all images
install -m 644 /tmp/overlay/files/armbian-preset.txt /root/.not_logged_in_yet

# Patch armbian-firstlogin to remove TTY requirement (allows headless first boot)
sed -i 's/&& -n \$(tty)//' /usr/lib/armbian/armbian-firstlogin

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

# --- Install SSH authorized keys ---------------------------------------------
if [ -f /tmp/overlay/id_ed25519.pub ]; then
    echo "Installing SSH authorized keys"
    mkdir -p /root/.ssh
    chmod 700 /root/.ssh
    cat /tmp/overlay/id_ed25519.pub >> /root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys
else
    echo "No SSH public key found, skipping"
fi
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
