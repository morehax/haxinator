#!/bin/bash
# Setup Armbian build environment
git clone --depth 1 https://github.com/armbian/build || exit 1
cd build || exit 1

# Create directory for web files (add your files here)
mkdir -p userpatches/web-files

# Create userpatches directory and customize image
mkdir -p userpatches
cat << 'EOF' > userpatches/customize-image.sh
#!/bin/bash
# Log start of customization
echo "Starting customize-image.sh" > /root/customize.log

# Add packages
echo "Updating package lists" >> /root/customize.log
apt-get update 
echo "Installing packages: vim htop net-tools wireless-tools mlocate" >> /root/customize.log
apt-get install -y vim htop net-tools wireless-tools mlocate 
if [ $? -eq 0 ]; then
  echo "Package installation successful" >> /root/customize.log
else
  echo "Package installation failed" >> /root/customize.log
fi

# Enable WiFi overlay in armbianEnv.txt
echo "Adding WiFi overlay" >> /root/customize.log
echo "overlays=bananapi-m4-sdio-wifi-bt" >> /boot/armbianEnv.txt
# List available overlays for verification
echo "Listing overlays" >> /root/customize.log
ls /boot/dtb/overlays/ >> /root/overlay-list.txt 

# Copy files to web root
echo "Copying web files" >> /root/customize.log
mkdir -p /var/www/html
if [ -d /armbian/userpatches/web-files ]; then
  cp -r /armbian/userpatches/web-files/* /var/www/html/ 
  echo "Web files copied" >> /root/customize.log
else
  echo "No web files found" >> /root/customize.log
fi

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
  BOARD=bananapim4zero \
  BRANCH=current \
  RELEASE=bullseye \
  BUILD_MINIMAL=yes \
  BUILD_DESKTOP=no \
  KERNEL_CONFIGURE=no \
  FORCE_RECREATE_ROOTFS=yes || exit 1
