---
title: Quick Start Guide
nav_order: 2
description: "Get started with Haxinator 2000 in minutes"
---

# Quick Start Guide

Get your Haxinator 2000 up and running quickly with this basic guide. For advanced features and custom builds, see the [Advanced Setup Guide](advanced-setup.md).

## What You'll Need

- Raspberry Pi Zero W2 or Raspberry Pi 5
- MicroSD card (8GB or larger)
- Power supply for your Raspberry Pi
- Computer with SD card reader
- [Etcher](https://www.balena.io/etcher/) for flashing the SD card

## Download and Flash

1. Download the latest image from [build.hax.me/images/](https://build.hax.me/images/)
2. Verify the SHA-1 checksum (shown on download page)
3. Extract the .zip file
4. Flash the .img file to your SD card using Etcher

## Basic Configuration

After flashing, you can optionally configure your device before first boot:

1. Remove and reinsert the SD card in your computer
2. The boot partition will appear as "bootfs"
3. Create a file named `env-secrets` with your basic settings:

```bash
# WiFi Access Point Settings
WIFI_SSID="Haxinator 2000"      # Your preferred AP name
WIFI_PASSWORD="ChangeMe"        # Your preferred AP password

# OpenVPN (Optional - only if using a VPN service)
VPN_USER=""                     # Your VPN username
VPN_PASS=""                     # Your VPN password
```

> **Tip:** Leave any settings blank to disable those features. You can configure them later through the web interface.
{: .info }

## First Boot

1. Insert the SD card into your Raspberry Pi
2. Connect power
3. Wait about 2-3 minutes for initial setup
4. The device will create a WiFi network with your chosen SSID (or "Haxinator 2000" by default)

## Accessing Your Device

### Via Web Interface
1. Connect to the Haxinator WiFi network
2. Open `https://haxinator.local` or `https://192.168.8.1`
3. Login with:
   - Username: `admin`
   - Password: `changeme`

### Via SSH (Optional)
```bash
ssh hax@haxinator.local
# Default password: hax
```

## Important Security Steps

Before using your device, change these default passwords:

1. Web Interface Password:
   - Click Settings in the web interface
   - Use the "Change Password" option

2. SSH Password:
   ```bash
   ssh hax@haxinator.local
   passwd
   ```

## Next Steps

- For advanced features like tunneling, custom builds, or development, see the [Advanced Setup Guide](advanced-setup.md)
- Learn about available features in the [User Manual](usage.md)
- Check [Server Requirements](server-requirements.md) if you plan to use tunneling features 