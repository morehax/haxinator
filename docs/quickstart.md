---
title: Quick Start Guide
nav_order: 2
description: "Get up and running with Haxinator 2000 quickly"
---

# Quick Start Guide

This guide will help you get Haxinator 2000 up and running on your Raspberry Pi in just a few minutes.

> **Note:** Haxinator 2000 is currently in its early development stages. Pre-built images are available only by request. The recommended approach is to build your own image using the instructions below.
{: .warning }

## Prerequisites

- Raspberry Pi Zero W2 or Raspberry Pi 5
- MicroSD card (8GB or larger)
- Power supply for your Raspberry Pi
- Computer with SD card reader
- [Etcher](https://www.balena.io/etcher/) or similar tool to flash SD cards
- Debian-based system for building the image (can be the Raspberry Pi itself)

## Installation Options

### Option 1: Request a Pre-built Image

As the project is in early development, pre-built images are available by request only:

1. Open an issue on our [GitHub repository](https://github.com/morehax/haxinator/issues) with the subject "Image Request"
2. Specify your intended use case and Raspberry Pi model
3. Once approved, you'll receive download instructions
4. Extract the zip file to obtain the .img file
5. Use Etcher or similar tool to flash the image to your SD card

### Option 2: Build Your Own Image (Recommended)

Building your own image is the recommended approach:

1. Clone the Haxinator repository:
   ```bash
   git clone https://github.com/morehax/haxinator
   cd haxinator
   ```

> **Info:** The secrets file contains configuration options for various Haxinator features:
> - **VPN_USER/VPN_PASS**: OpenVPN credentials. If filled, OpenVPN will be configured at boot.
> - **HANS_SERVER/HANS_PASSWORD**: ICMP tunneling settings. If filled, Hans VPN will be configured.
> - **IODINE_TOPDOMAIN/IODINE_PASS**: DNS tunneling settings. If filled, Iodine tunneling will be configured.
> - **BLUETOOTH_MAC**: Your device's Bluetooth MAC address for auto-pairing. If left blank, auto-pairing will be disabled.
> - **WIFI_SSID/WIFI_PASS**: WiFi network to connect to on boot. If left blank, only the AP mode will be active.
>
> Any blank fields will result in those features being disabled or unconfigured. You can always configure these options later through the web interface.
{: .info }

### Build Environment Requirements

Building Raspberry Pi images requires specific environment considerations as the target architecture (ARM64) may differ from your build machine:

> **Note:** Ubuntu 24.04.2 LTS is the recommended build environment for Haxinator images. Other distributions or versions may require additional configuration.
{: .info }

- **On ARM64 systems** (Apple Silicon Macs or ARM64 Linux):
  - Native building is possible on ARM64 Linux as the architecture matches the target
  - **Apple Silicon Macs cannot build directly on macOS** — you must use Parallels or VMware to run virtualized Ubuntu 24.04.2 LTS
  - Run `sudo ./install_ubuntu_depends.sh` inside your Linux environment to set up all required packages

- **On x86_64 systems** (Intel/AMD PCs):
  - Cross-compilation is handled via QEMU, which is included in our dependencies
  - Ubuntu 24.04.2 LTS x86_64 should work out of the box after running `sudo ./install_ubuntu_depends.sh`
  - The build process automatically configures QEMU for ARM64 emulation

- **Other environments**:
  - Debian-based distributions may work but are untested
  - You'll need `qemu-user-static`, `binfmt-support`, and other packages for cross-architecture builds
  - Performance may vary significantly; build times can be 2-3x longer on x86_64 systems

#### Required Dependencies

The `install_ubuntu_depends.sh` script installs these essential packages:
```bash
sudo apt-get install coreutils quilt parted qemu-user-static debootstrap zerofree zip \
dosfstools libarchive-tools libcap2-bin grep rsync xz-utils file git curl bc \
gpg pigz xxd arch-test bmap-tools
```

If you encounter build issues, ensure your system has sufficient resources (at least 4GB RAM and 20GB free disk space) and all dependencies are correctly installed.

2. Create your secrets file:
   ```bash
   cp haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/env-secrets.template ~/.haxinator-secrets
   ```

3. Edit `~/.haxinator-secrets` with your configuration:
   ```bash
   nano ~/.haxinator-secrets
   ```

4. Run the build script:
   ```bash
   sudo ./01-build-script.sh
   ```

> **Warning:** The build script requires root permissions to function properly. This is necessary for installing dependencies, creating the filesystem structure, and configuring system services.
{: .warning }

5. Flash the resulting image (found in `./pi-gen/deploy/`) to your SD card

## First Boot Process

When you first boot your Haxinator, several important configuration steps take place automatically:

> **Note:** The first boot process takes approximately 2-3 minutes to complete as the system configures services, generates keys, and prepares the environment.
{: .info }

### What Happens During First Boot

During the first boot, the `firstboot.sh` script performs these key operations:

1. Configures the system hostname
2. Sets up boot profiles for different modes (USB serial vs CDC Ethernet)
3. Configures regional settings (WiFi country code, etc.)
4. Processes your secrets file to configure:
   - OpenVPN with your credentials (if provided)
   - Iodine DNS tunneling (if configured)
   - Hans ICMP tunneling (if configured)
   - WiFi Access Point with your SSID/password or defaults
5. Generates self-signed SSL certificates for the web interface
6. Finalizes the setup and triggers a reboot

After the initial configuration completes and the system reboots, your Haxinator is ready to use.

## Pre-configuring via Boot Partition

Haxinator 2000 supports easy pre-configuration by placing files directly on the SD card's boot partition before first boot. This allows you to configure VPN connections, tunneling options, and other settings without needing to access the web interface first.

> **Tip:** The boot partition is a FAT32 filesystem that can be accessed from Windows, macOS, or Linux when the SD card is inserted into your computer after flashing the image.
{: .info }

### Supported Configuration Files

You can place the following files on the boot partition:

1. **env-secrets**: Contains configuration variables for VPN credentials, tunneling settings, WiFi settings, etc.
   - Location: `/boot/firmware/env-secrets`
   - Format: See template below
   - Purpose: Configures all tunneling services and network settings automatically on first boot

2. **openvpn-udp.ovpn**: Your OpenVPN configuration file
   - Location: `/boot/firmware/openvpn-udp.ovpn`
   - Format: Standard OpenVPN client configuration file
   - Purpose: Provides all necessary connection details for OpenVPN

### Example env-secrets File

Create a file named `env-secrets` on the boot partition with the following format:

```bash
# OpenVPN credentials
VPN_USER="your_vpn_username"
VPN_PASS="your_vpn_password"

# Hans ICMP tunneling settings
HANS_SERVER="hans_server_address"
HANS_PASSWORD="hans_tunnel_password"

# Iodine DNS tunneling settings
IODINE_TOPDOMAIN="your.tunnel.domain"
IODINE_NAMESERVER="nameserver_ip"
IODINE_PASS="iodine_password"
IODINE_MTU="1400"
IODINE_LAZY="true"
IODINE_INTERVAL="4"

# WiFi Access Point settings
WIFI_SSID="Your_Custom_AP_Name"
WIFI_PASSWORD="your_secure_password"

# Bluetooth pairing settings
BLUETOOTH_MAC="00:11:22:33:44:55"
```

> **Security Note:** The `env-secrets` file contains sensitive information. After first boot, this file is processed and then secured with proper permissions. However, you may want to remove the SD card and delete the file from the boot partition after first boot for maximum security.
{: .warning }

### Steps to Pre-configure Your Haxinator

1. Flash the Haxinator image to your SD card using Etcher or similar tool
2. After flashing completes, remove and reinsert the SD card into your computer
3. Your computer should detect a partition named "boot" or "firmware"
4. Copy your prepared `env-secrets` file to this partition
5. If you have an OpenVPN configuration, copy your `.ovpn` file to this partition and rename it to `openvpn-udp.ovpn`
6. Safely eject the SD card
7. Insert the SD card into your Raspberry Pi and power it on
8. On first boot, Haxinator will automatically detect and apply these configurations

When the system boots for the first time, it will:
1. Detect and use configuration files from the boot partition
2. Set up all configured services automatically
3. Create the WiFi Access Point with your custom settings
4. Configure all tunneling services based on your provided credentials

### Available Connectivity Methods

Haxinator provides multiple ways to connect to your device:

#### 1. WiFi Access Point (Default)

The easiest connection method for most users:

1. Look for a WiFi network named "Haxinator 2000" (default password: "ChangeMe")
2. Connect to this network from your computer or mobile device
3. Open a web browser and navigate to https://192.168.8.1 or http://haxinator.local
4. You'll receive a **self-signed certificate warning** in your browser - this is expected and can be safely bypassed

> **Note:** HTTP traffic (port 80) is automatically redirected to HTTPS (port 443) for security. When accessing via http://haxinator.local, you'll be redirected to the secure version automatically.
{: .info }

#### 2. USB Connection (Serial or Ethernet)

When connected via USB cable to your computer:

- **USB Ethernet Mode** (Default): The Raspberry Pi appears as a network adapter
  - Your computer receives an IP from the 192.168.8.x range
  - Access the web interface at https://192.168.8.1
  - No additional drivers needed on most operating systems

- **USB Serial Mode**: Access the command line directly
  - Connect using a terminal program (e.g., screen, PuTTY, minicom)
  - On Linux/macOS: `screen /dev/ttyACM0 115200` (device name may vary)
  - On Windows: Use Device Manager to find the COM port, then connect with PuTTY

To switch between USB modes, use the command:
```bash
sudo /usr/local/bin/toggle_usb_serial.sh [serial|usb]
```

#### 3. Bluetooth Serial Connection

> **Note:** Bluetooth serial mode becomes available approximately 2-3 minutes after boot as the pairing service initializes.
{: .info }

If you've configured a Bluetooth MAC address in your secrets file:

1. The device will automatically attempt to pair with your configured Bluetooth device
2. Once paired, a serial connection will be established
3. Use a Bluetooth terminal app on your phone or computer to access the console
4. See the [Bluetooth Serial](features/bluetooth-serial.md) page for detailed instructions

## Initial Configuration

Once connected to the web interface, you'll see the Haxinator login screen:

![Haxinator Login Screen](/assets/images/interface/haxinator-login.png)
{: .screenshot }

1. Log in with the default credentials (username: `admin`, password: `changeme`)
2. Change the default password immediately via the Settings page
3. Configure your preferred VPN and tunneling options
4. Set up network connections as needed

### Web Interface Overview

The Haxinator web interface provides several key sections:

- **Dashboard**: Overview of system status, active connections, and resource usage
- **Network**: 
  - WiFi client connections (connect to external networks)
  - Access Point configuration
  - Network scanning tools
- **Tunneling**:
  - ICMP Tunnel (Hans VPN)
  - DNS Tunnel (Iodine)
  - OpenVPN configuration
- **System**:
  - Device settings
  - Password management
  - System logs
  - Update options

All tunneling services can be controlled independently through their respective interface sections.

## Next Steps

- Review the [Features](features/index.md) documentation to understand all available capabilities
- Check out [Building Custom Images](custom-images.md) if you want to customize your Haxinator
- Refer to the [FAQ](faq.md) for common questions and troubleshooting
