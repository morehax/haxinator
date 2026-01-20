#!/bin/bash
# =============================================================================
# Haxinator Cross-Platform USB Ethernet Gadget
# =============================================================================
# Uses CDC NCM (Network Control Model) which has native driver support on:
#   - Windows 10/11 (native inbox driver)
#   - macOS (native driver)
#   - Linux (cdc_ncm driver)
#
# NCM is the modern replacement for ECM with better performance and
# cross-platform compatibility.
# =============================================================================

set -e

CONFIGFS=/sys/kernel/config/usb_gadget
GADGET=g1

# Fixed MAC addresses for consistent behavior
HOST_MAC="48:6f:73:74:50:43"   # "HostPC" in ASCII
DEV_MAC="42:61:64:55:53:42"    # "BadUSB" in ASCII

# Load required modules
modprobe libcomposite 2>/dev/null || true

# Check if configfs is available
if [ ! -d "$CONFIGFS" ]; then
    echo "ERROR: $CONFIGFS does not exist. Is configfs mounted?"
    exit 1
fi

# Exit if gadget already configured
if [ -d "$CONFIGFS/$GADGET" ]; then
    echo "USB gadget already configured at $CONFIGFS/$GADGET"
    exit 0
fi

echo "Setting up Haxinator USB Gadget (NCM)..."

# Create gadget directory
mkdir -p "$CONFIGFS/$GADGET"
cd "$CONFIGFS/$GADGET"

# =============================================================================
# Device Identification
# =============================================================================
echo 0x1d6b > idVendor   # Linux Foundation
echo 0x0137 > idProduct  # NCM Gadget
echo 0x0100 > bcdDevice  # Device version 1.0.0
echo 0x0200 > bcdUSB     # USB 2.0

# =============================================================================
# Device Strings (English - 0x409)
# =============================================================================
mkdir -p strings/0x409
echo "haxinator0001" > strings/0x409/serialnumber
echo "Haxinator" > strings/0x409/manufacturer
echo "Haxinator USB Network" > strings/0x409/product

# =============================================================================
# Create NCM Function
# =============================================================================
mkdir -p functions/ncm.usb0
echo "$HOST_MAC" > functions/ncm.usb0/host_addr
echo "$DEV_MAC" > functions/ncm.usb0/dev_addr

# =============================================================================
# Create ACM Function (Serial Console)
# =============================================================================
mkdir -p functions/acm.usb0

# =============================================================================
# Configuration
# =============================================================================
mkdir -p configs/c.1/strings/0x409
echo "NCM Network + Serial" > configs/c.1/strings/0x409/configuration
echo 250 > configs/c.1/MaxPower

# Link functions to configuration
ln -s functions/ncm.usb0 configs/c.1/
ln -s functions/acm.usb0 configs/c.1/

# =============================================================================
# Activate the Gadget
# =============================================================================
UDC=$(ls /sys/class/udc 2>/dev/null | head -n1)
if [ -z "$UDC" ]; then
    echo "ERROR: No USB Device Controller found"
    exit 1
fi

echo "$UDC" > UDC

echo ""
echo "USB Gadget configured successfully!"
echo "  - Network: usb0 (CDC NCM - works on Windows/macOS/Linux)"
echo "  - Serial: ttyGS0"
echo "  - Bound to UDC: $UDC"
