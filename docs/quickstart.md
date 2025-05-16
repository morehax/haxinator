---
title: Quick Start Guide
nav_order: 2
description: "Get up and running with Haxinator 2000 quickly"
---

# Quick Start Guide

This guide will help you get Haxinator 2000 up and running on your Raspberry Pi in just a few minutes.

> **Note:** Pre-built images are now available! You can download the latest image from our build server.
{: .info }

## Prerequisites

- Raspberry Pi Zero W2 or Raspberry Pi 5
- MicroSD card (8GB or larger)
- Power supply for your Raspberry Pi
- Computer with SD card reader
- [Etcher](https://www.balena.io/etcher/) or similar tool to flash SD cards
- Debian-based system for building the image (can be the Raspberry Pi itself)

## Installation Options

### Option 1: Download Pre-built Image

The fastest way to get started is to download our pre-built image:

1. Download the latest image from [build.hax.me/images/](https://build.hax.me/images/)
2. Verify the SHA-1 checksum (provided on the download page)
3. Extract the zip file to obtain the .img file
4. Flash the image to your SD card using Etcher or similar tool
5. *(Optional but Recommended)* Configure your settings:
   - After flashing, remove and reinsert the SD card
   - Create an `env-secrets` file with your configuration (see template below)
   - Place it in the boot partition (appears as "bootfs")
   - You can also add your OpenVPN config file here if needed

Here's a template for your `env-secrets` file:

```bash
# Bluetooth MAC address for auto-pair script
BLUETOOTH_MAC=XX:XX:XX:XX:XX:XX

# OpenVPN credentials
VPN_USER=
VPN_PASS=

# Iodine DNS tunnel configuration
IODINE_TOPDOMAIN=
IODINE_NAMESERVER=
IODINE_PASS=
IODINE_MTU=1400
IODINE_LAZY=true
IODINE_INTERVAL=4

# Hans VPN configuration
HANS_SERVER=
HANS_PASSWORD=

# WiFi AP configuration
WIFI_SSID="Haxinator 2000"
WIFI_PASSWORD="ChangeMe"
```

> **Important:** For tunneling features to work, you need to set up the corresponding server infrastructure. See [Server Requirements](server-requirements.md) for detailed setup instructions.
{: .warning }

> **Note:** If you plan to use ICMP (Hans) or DNS (Iodine) tunneling, you'll need to set up your own server infrastructure first. See [Server Requirements](server-requirements.md) for setup instructions. This is not needed if you're only using OpenVPN with an existing provider or just using WiFi/Bluetooth features.
{: .warning }

> **Tip:** Only fill in the settings you plan to use. Empty values will disable those features.
{: .info }

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

## Default Access Methods

Haxinator 2000 provides several ways to access and manage your device:

### Web Interface Access
- URL: `https://haxinator.local` or `https://192.168.8.1`
- Default credentials:
  - Username: `admin`
  - Password: `changeme`

### SSH Access
- Default credentials:
  - Username: `hax`
  - Password: `hax`
- SSH is enabled by default for easy access
- A default SSH key is pre-installed (see Security Considerations below)

### Web Terminal (shellinabox)
- Available through the web interface via the "Terminal" button in the top right
- Uses the same credentials as SSH
- Provides secure HTTPS access on port 4200
- Useful when direct SSH access is not possible

> **Security Warning:** The default SSH key and credentials are publicly known. For production use:
> 1. Change the default password immediately
> 2. Replace the default SSH key with your own
> 3. Consider disabling password authentication and using only key-based authentication
{: .warning }

### Changing Default Credentials

1. Change the web interface password:
   ```bash
   sudo nano /var/www/html/config/config.php
   ```

2. Change the SSH password:
   ```bash
   passwd
   ```

3. Replace the default SSH key:
   ```bash
   # Remove the default key
   rm ~/.ssh/authorized_keys
   # Add your own key
   echo "your-public-key" > ~/.ssh/authorized_keys
   chmod 600 ~/.ssh/authorized_keys
   ```

## Pre-configuring via Boot Partition

Haxinator 2000 supports easy pre-configuration by placing files directly on the SD card's boot partition before first boot. This allows you to configure VPN connections, tunneling options, and other settings without needing to access the web interface first.

> **Tip:** After flashing your SD card, you can add configuration files to the boot partition from any operating system:
> - **Windows**: The partition appears as "bootfs" drive letter
> - **macOS**: Automatically mounts as "bootfs" volume
> - **Linux**: Can be mounted manually or appears as "bootfs"
{: .info }

### Adding Configuration Files

You have two options for adding your configuration:

1. **During Image Build**:
   ```bash
   # Create and edit your secrets before building
   cp haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/env-secrets.template ~/.haxinator-secrets
   nano ~/.haxinator-secrets
   ```

2. **After Flashing** (Recommended):
   1. Flash the Haxinator image to your SD card using Etcher or similar
   2. Remove and reinsert the SD card into your computer
   3. The boot partition will appear as "bootfs"
   4. Copy your configuration files directly to this partition:
      - Copy your `env-secrets` file
      - Copy your OpenVPN config as `openvpn-udp.ovpn`
   5. Safely eject the SD card
   6. Boot your Haxinator - it will automatically detect and use these files

### Supported Configuration Files

The following files are supported in the boot partition:

1. **env-secrets**: Main configuration file
   - Location: `/boot/firmware/env-secrets`
   - Purpose: Configures all services (VPN, tunneling, WiFi, Bluetooth)
   - Template: Copy from `env-secrets.template`
   - Format: Bash environment variables (see below)

2. **openvpn-udp.ovpn**: OpenVPN configuration
   - Location: `/boot/firmware/openvpn-udp.ovpn`
   - Purpose: Provides OpenVPN connection details
   - Format: Standard OpenVPN client configuration
   - Note: Must be named exactly `openvpn-udp.ovpn`

### Configuration Process

1. **During Image Creation**:
   ```bash
   # Copy and edit the template
   cp haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/env-secrets.template ~/.haxinator-secrets
   nano ~/.haxinator-secrets
   ```

2. **After Flashing**:
   ```bash
   # Mount the SD card
   # Copy files to the boot partition
   cp ~/.haxinator-secrets /path/to/sdcard/firmware/env-secrets
   cp your-vpn-config.ovpn /path/to/sdcard/firmware/openvpn-udp.ovpn
   ```

3. **First Boot Process**:
   - System checks `/boot/firmware/env-secrets` first
   - Falls back to `/root/.env` if not found
   - Copies OpenVPN config if present
   - Configures all services with valid credentials
   - Disables services with missing configuration

> **Security Warning:** The `env-secrets` file in the boot partition is world-readable. After first boot:
> 1. The file is processed and stored securely as `/root/.env` with proper permissions (600)
> 2. You should remove your SD card and delete `env-secrets` from the boot partition
> 3. OpenVPN configuration is copied to a secure location
{: .warning }

### Example env-secrets File

```bash
# OpenVPN credentials (requires openvpn-udp.ovpn file)
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

### Service Dependencies

| Service | Required Files | Required Variables |
|---------|---------------|-------------------|
| OpenVPN | `openvpn-udp.ovpn` + `env-secrets` | `VPN_USER`, `VPN_PASS` |
| Hans VPN | `env-secrets` | `HANS_SERVER`, `HANS_PASSWORD` |
| Iodine | `env-secrets` | `IODINE_TOPDOMAIN`, `IODINE_NAMESERVER`, `IODINE_PASS` |
| WiFi AP | `env-secrets` | `WIFI_SSID`, `WIFI_PASSWORD` |
| Bluetooth | `env-secrets` | `BLUETOOTH_MAC` |

### Verifying Configuration

After first boot, you can verify your configuration:

```bash
# Check service status
systemctl status openvpn-client@openvpn-udp
systemctl status hans-icmp-vpn
systemctl status iodine
systemctl status hostapd
systemctl status bluetooth_pair

# Check configuration security
ls -l /root/.env  # Should show: -rw------- 1 root root
```

### Steps to Pre-configure Your Haxinator

1. Flash the Haxinator image to your SD card using Etcher or similar tool
2. After flashing completes, remove and reinsert the SD card into your computer
3. Your computer should detect a partition named "boot" or "firmware"
4. Copy your prepared `env-secrets`