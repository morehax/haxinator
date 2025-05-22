#!/bin/bash
# Setup Armbian build environment

rm -rf build
git clone --depth 1 https://github.com/armbian/build || exit 1
cd build || exit 1

# Create directory for web files using Armbian's recommended structure
mkdir -p userpatches/overlay/html
cp -rf ../html/ userpatches/overlay/
cp -rf ../files/  userpatches/overlay/
cp ../haxinator-pigen-overlay/stage2/99-self-tests/00-self-tests.sh userpatches/overlay/
cp ../common-functions.sh userpatches/overlay/
# Create userpatches directory and customize image
cat << 'EOF' > userpatches/customize-image.sh
#!/bin/bash
# Log start of customization
echo "Starting customize-image.sh" > /root/customize.log

# Add packages
echo "Updating package lists" >> /root/customize.log
apt-get update
echo "Installing packages: vim htop net-tools wireless-tools mlocate" >> /root/customize.log
apt-get install -y vim htop net-tools wireless-tools locate iodine iptables cryptsetup openssl ca-certificates git apache2 php php-ssh2 php-mbstring php-curl network-manager-openvpn libapache2-mod-php
apt-get install -y dnsutils shellinabox ssl-cert dnsmasq python3-dbus python3-gi python3-dotenv git make g++
apt-get install -y bluez

echo "www-data ALL=(ALL) NOPASSWD: /sbin/poweroff, /usr/bin/ssh, /bin/kill, /usr/bin/pgrep, /usr/bin/ssh, /bin/kill, /usr/bin/pgrep, /usr/bin/ssh-keygen" | sudo tee -a /etc/sudoers

echo "interface=usb0" >> /etc/dnsmasq.conf
echo "dhcp-range=192.168.8.2,192.168.8.100,12h" >> /etc/dnsmasq.conf
sed -i 's/managed=false/managed=true/' /etc/NetworkManager/NetworkManager.conf

systemctl disable dnsmasq
a2enmod ssl
a2ensite default-ssl

if [ $? -eq 0 ]; then
  echo "Package installation successful" >> /root/customize.log
else
  echo "Package installation failed" >> /root/customize.log
fi

# Enable WiFi overlay in armbianEnv.txt if the file exists


echo "Adding WiFi overlay" >> /root/customize.log

#echo "dtoverlay=dwc2,dr_mode=peripheral" >> /boot/config.txt
#sed -i 's/$/ modules-load=dwc2,g_cdc console=ttyGS0,115200 cfg80211.ieee80211_regdom=GB/' /boot/cmdline.txt


# echo "overlays=bananapi-m4-sdio-wifi-bt" >> /boot/armbianEnv.txt
# echo "extraargs=net.ifnames=0 modules_load=g_ether" >> /boot/armbianEnv.txt

    # List available overlays for verification if directory exists
    if [ -d /boot/dtb/overlays/ ]; then
        echo "Listing overlays" >> /root/customize.log
        ls /boot/dtb/overlays/ >> /root/customize.log
    else
        echo "Overlay directory not found - this is normal during initial build" >> /root/customize.log
    fi
#fi

# haxinator custoizations
cp -rf /tmp/overlay/html/* /var/www/html/
chown -R www-data:www-data /var/www/

cp -rf /tmp/overlay/files /root/

cp -rf /tmp/overlay/files/rc.local /etc/rc.local
chmod 755 /etc/rc.local


git clone https://github.com/friedrich/hans.git
cd hans
make
mv hans /usr/local/bin
cd ..

cp -rf /tmp/overlay/files/hans2/hans-service.py /usr/lib/NetworkManager/hans-service.py
cp -rf /tmp/overlay/files/hans2/iodine-service.py /usr/lib/NetworkManager/iodine-service.py

chmod 755 /usr/lib/NetworkManager/*.py


cp -rf /tmp/overlay/files/hans2/hans.name /usr/lib/NetworkManager/VPN/hans.name
cp -rf /tmp/overlay/files/hans2/iodine.name /usr/lib/NetworkManager/VPN/iodine.name

cp -rf /tmp/overlay/files/hans2/nm-hans-service.conf /etc/dbus-1/system.d/
cp -rf /tmp/overlay/files/hans2/nm-iodine-service.conf /etc/dbus-1/system.d/
cp -rf /tmp/overlay/files/hans2/99-clean-vpn-routes /etc/NetworkManager/dispatcher.d/99-clean-vpn-routes
cp -rf /tmp/overlay/files/services/bluetooth_pair.service /etc/systemd/system/
cp -rf /tmp/overlay/files/services/firstboot.service /lib/systemd/system/
cp -rf /tmp/overlay/files/10-nmcli-webui.rules-ubuntu /etc/polkit-1/rules.d/10-nmcli-webui.rules
chown root:root /etc/polkit-1/rules.d/10-nmcli-webui.rules
chmod 644 /etc/polkit-1/rules.d/10-nmcli-webui.rules

cp -rf /tmp/overlay/files/bluetooth/auto-pair /usr/local/bin/
cp -rf /tmp/overlay/files/firstboot.sh /usr/local/bin/
chmod 755  /usr/local/bin/*.sh

cp -rf /tmp/overlay/files/openvpn-udp.ovpn /
cp -rf /tmp/overlay/files/update_me.sh /update_me.sh


cp -rf /tmp/overlay/files/services/dbus-org.bluez.service /etc/systemd/system/
cp -rf /tmp/overlay/files/services/rfcomm.service /etc/systemd/system/
#cp -rf /tmp/overlay/files/services/unblock-wifi.service /etc/systemd/system/

#systemctl enable unblock-wifi.service
systemctl enable bluetooth || yellow_echo "WARNING: Failed to enable bluetooth"
systemctl enable bluetooth_pair.service || yellow_echo "WARNING: Failed to enable bluetooth_pair.service"
systemctl enable serial-getty@ttyGS0.service || yellow_echo "WARNING: Failed to enable serial-getty@ttyGS0.service"
#systemctl enable firstboot || yellow_echo "WARNING: Failed to enable firstboot"
systemctl enable rfcomm || yellow_echo "WARNING: Failed to enable rfcomm"
systemctl enable shellinabox || yellow_echo "WARNING: Failed to enable shellinabox"
systemctl disable shellinabox

systemctl mask wpa_supplicant@wlan0.service || yellow_echo "WARNING: Failed to mask wpa_supplicant@wlan0.service"
systemctl enable NetworkManager || yellow_echo "WARNING: Failed to enable NetworkManager"
systemctl disable dnsmasq || yellow_echo "WARNING: Failed to disable dnsmasq"

apt-get clean

# Set proper permissions for web directory
if [ -d /var/www/html ]; then
    echo "Setting permissions on /var/www/html" >> /root/customize.log
    chown -R www-data:www-data /var/www/
    chmod -R 755 /var/www/
    
    # Verify contents
    echo "Contents of /var/www/html:" >> /root/customize.log
    ls -la /var/www/html >> /root/customize.log
fi

cp /tmp/overlay/00-self-tests.sh /
cp /tmp/overlay/common-functions.sh /

chmod 755 /00-self-tests.sh

/00-self-tests.sh

# Run custom script in chroot
echo "Running custom script" >> /root/customize.log
cat << 'SCRIPT' > /tmp/custom-script.sh
#!/bin/bash
echo "Running custom script in chroot" >> /root/customize.log
# Add your script commands here
touch /root/custom-file
SCRIPT
chmod +x /tmp/custom-script.sh
/tmp/custom-script.sh
EOF

chmod +x userpatches/customize-image.sh || exit 1

# Build minimal CLI image for BPI-M4 Zero, forcing fresh rootfs
./compile.sh build \
  BOARD=rpi4b \
  BRANCH=current \
  RELEASE=noble \
  BUILD_MINIMAL=yes \
  BUILD_DESKTOP=no \
  KERNEL_CONFIGURE=no \
  NETWORKING_STACK="network-manager" \
  KERNEL_GIT=shallow \
  FORCE_RECREATE_ROOTFS=yes || exit 1
echo "Build completed"
