#!/bin/bash

#last provisioning and cleanups
# Enable usb gadget at boot

sed -i 's/console=serial0,115200 console=tty1/console=tty1 console=ttyGS0,115200/' /boot/firmware/cmdline.txt
sed -i 's/$/ modules-load=dwc2,g_cdc cfg80211.ieee80211_regdom=GB/' /boot/firmware/cmdline.txt
echo "dtoverlay=dwc2,dr_mode=peripheral" >> /boot/firmware/config.txt
echo "Reboot and bask in the joy of usb0"

# Clean up web root

rm -rf /var/www/html/index.html

# reboot
reboot
