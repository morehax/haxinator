#!/bin/bash
# =============================================================================
# Haxinator USB Gadget Removal Script
# =============================================================================

CONFIGFS=/sys/kernel/config/usb_gadget
GADGET=g1
DEVDIR="$CONFIGFS/$GADGET"

# Exit if gadget doesn't exist
if [ ! -d "$DEVDIR" ]; then
    echo "USB gadget not configured, nothing to remove"
    exit 0
fi

echo "Removing Haxinator USB Gadget..."

cd "$DEVDIR"

# Disable the gadget by unbinding from UDC
echo "" > UDC 2>/dev/null || true

# Remove function links from configurations
echo "Removing function links..."
for link in configs/*/*.usb*; do
    [ -L "$link" ] && rm -f "$link"
done

# Remove configuration strings
echo "Removing configuration strings..."
for dir in configs/*/strings/*; do
    [ -d "$dir" ] && rmdir "$dir" 2>/dev/null || true
done

# Remove configurations
echo "Removing configurations..."
for conf in configs/*; do
    [ -d "$conf" ] && rmdir "$conf" 2>/dev/null || true
done

# Remove functions
echo "Removing functions..."
for func in functions/*; do
    [ -d "$func" ] && rmdir "$func" 2>/dev/null || true
done

# Remove device strings
echo "Removing device strings..."
for str in strings/*; do
    [ -d "$str" ] && rmdir "$str" 2>/dev/null || true
done

# Remove gadget directory
cd "$CONFIGFS"
rmdir "$GADGET" 2>/dev/null || true

echo "USB Gadget removed successfully"
