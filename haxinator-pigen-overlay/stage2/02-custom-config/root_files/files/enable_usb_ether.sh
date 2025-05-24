#!/bin/bash

sed -i 's/console=serial0,115200 console=tty1/console=tty1 console=ttyGS0,115200/' /boot/firmware/cmdline.txt
sed -i 's/$/ modules-load=dwc2,g_cdc cfg80211.ieee80211_regdom=GB/' /boot/firmware/cmdline.txt
echo "dtoverlay=dwc2,dr_mode=peripheral" >> /boot/firmware/config.txt
echo "Reboot and bask in the joy of usb0"
reboot
