#!/bin/bash

# Check if parameter is provided
if [ -z "$1" ]; then
  echo "Usage: $0 {usb|serial}"
  exit 1
fi

# Source and destination paths
SRC="/boot/firmware/cmdline.txt"

SERIAL_DEST="/boot/firmware/cmdline_serial.txt"

USB_DEST="/boot/firmware/cmdline_usb.txt"

case "$1" in
  serial)
    # Copy to cmdline_serial.txt
    cp "$SERIAL_DEST" "$SRC"
    echo "Copied $SERIAL_DEST to $SRC"
    ;;
  usb)
    # Copy to cmdline_usb.txt
    cp "$USB_DEST" "$SRC"
    echo "Copied $USB_DEST to $SRC"
    ;;
  *)
    echo "Invalid parameter. Use 'usb' or 'serial'"
    exit 1
    ;;
esac

