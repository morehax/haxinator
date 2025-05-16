#!/bin/bash -e
CONFIG_FILE="${ROOTFS_DIR}/boot/firmware/config.txt"
CMDLINE_FILE="${ROOTFS_DIR}/boot/firmware/cmdline.txt"

USB_CMDLINE_FILE="${ROOTFS_DIR}/boot/firmware/cmdline_usb.txt"
SERIAL_CMDLINE_FILE="${ROOTFS_DIR}/boot/firmware/cmdline_serial.txt"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"

[ -f "$CONFIG_FILE" ] && cp "$CONFIG_FILE" "${CONFIG_FILE}.bak.${TIMESTAMP}"
[ -f "$CMDLINE_FILE" ] && cp "$CMDLINE_FILE" "${CMDLINE_FILE}.bak.${TIMESTAMP}"
if [ -f "$CONFIG_FILE" ]; then
    sed -i '/dtoverlay=dwc2,dr_mode=host/d' "$CONFIG_FILE"
    grep -q '^\[all\]' "$CONFIG_FILE" || echo -e "\n[all]" >> "$CONFIG_FILE"
    if grep -q '^dtoverlay=dwc2,dr_mode=peripheral' "$CONFIG_FILE"; then
        sed -i 's/^dtoverlay=dwc2,dr_mode=peripheral.*/dtoverlay=dwc2,dr_mode=peripheral/' "$CONFIG_FILE"
    else
        sed -i '/^\[all\]/a dtoverlay=dwc2,dr_mode=peripheral' "$CONFIG_FILE"
    fi
else
    echo "Error: $CONFIG_FILE not found"
    exit 1
fi
if [ -f "$CMDLINE_FILE" ]; then
    # Make a backup if not already done
    [ -f "${CMDLINE_FILE}.bak.${TIMESTAMP}" ] || cp "$CMDLINE_FILE" "${CMDLINE_FILE}.bak.${TIMESTAMP}"
    
    # Read the current cmdline
    CURRENT_CMDLINE=$(cat "$CMDLINE_FILE")
    
    # Remove console=serial0,115200 entry which conflicts with USB gadget console
    # More robust removal handling any format (with or without trailing space)
    NEW_CMDLINE=$(echo "$CURRENT_CMDLINE" | sed -E 's/console=serial0,115200( |$)//')
    
    # Ensure we have the right modules loaded - prefer g_cdc over g_serial
    if echo "$NEW_CMDLINE" | grep -q "modules-load=dwc2,g_cdc"; then
        # Already has g_cdc, leave it as is
        :
    elif echo "$NEW_CMDLINE" | grep -q "modules-load=dwc2,g_serial"; then
        # Replace g_serial with g_cdc
        NEW_CMDLINE=$(echo "$NEW_CMDLINE" | sed 's/modules-load=dwc2,g_serial/modules-load=dwc2,g_cdc/')
    else
        # Add modules load parameter with g_cdc
        NEW_CMDLINE="$NEW_CMDLINE modules-load=dwc2,g_cdc console=ttyGS0,115200"
    fi
    
    # Add firstboot init if not present
    if ! echo "$NEW_CMDLINE" | grep -q "init=/usr/lib/raspberrypi-sys-mods/firstboot"; then
        NEW_CMDLINE="$NEW_CMDLINE quiet init=/usr/lib/raspberrypi-sys-mods/firstboot"
    fi
    
    # Only write if changed
    if [ "$NEW_CMDLINE" != "$CURRENT_CMDLINE" ]; then
        echo "Updating cmdline.txt:"
        echo "OLD: $CURRENT_CMDLINE"
        echo "NEW: $NEW_CMDLINE"
        echo "$NEW_CMDLINE" > "$CMDLINE_FILE"
    else
        echo "cmdline.txt already has the correct settings"
    fi
else
    echo "Error: $CMDLINE_FILE not found"
    exit 1
fi

# Add apt-cacher proxy

if [ -n "${APT_PROXY:-}" ]; then
    echo "Acquire::http::Proxy \"${APT_PROXY}\";" > "${ROOTFS_DIR}/etc/apt/apt.conf.d/01proxy"
    echo "Set up apt proxy: ${APT_PROXY}"
fi
