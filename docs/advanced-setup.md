---
title: Advanced Setup Guide
nav_order: 3
description: "Advanced configuration and build options for Haxinator 2000"
---

# Advanced Setup Guide

This guide covers advanced topics including building your own images, configuring tunneling features, and customizing the system. If you're new to Haxinator, start with the [Quick Start Guide](quickstart.md) first.

## Building Your Own Image

Building your own image gives you complete control over the system and is recommended for advanced users or developers.

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

### Required Dependencies

The `install_ubuntu_depends.sh` script installs these essential packages:
```bash
sudo apt-get install coreutils quilt parted qemu-user-static debootstrap zerofree zip \
dosfstools libarchive-tools libcap2-bin grep rsync xz-utils file git curl bc \
gpg pigz xxd arch-test bmap-tools
```

> **Note:** Ensure your system has at least 4GB RAM and 20GB free disk space for building.
{: .info }

### Build Process

1. Clone the repository:
   ```bash
   git clone https://github.com/morehax/haxinator
   cd haxinator
   ```

2. Create your secrets file:
   ```bash
   cp haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/env-secrets.template ~/.haxinator-secrets
   ```

3. Configure advanced features in `~/.haxinator-secrets`:
   ```bash
   # Tunneling Configuration
   IODINE_TOPDOMAIN=
   IODINE_NAMESERVER=
   IODINE_PASS=
   IODINE_MTU=1400
   IODINE_LAZY=true
   IODINE_INTERVAL=4

   HANS_SERVER=
   HANS_PASSWORD=

   # Bluetooth Auto-pairing
   BLUETOOTH_MAC=XX:XX:XX:XX:XX:XX

   # Basic Settings (as in quickstart)
   WIFI_SSID="Haxinator 2000"
   WIFI_PASSWORD="ChangeMe"
   VPN_USER=
   VPN_PASS=
   ```

4. Run the build:
   ```bash
   sudo ./01-build-script.sh
   ```

## Advanced Security Configuration

### SSH Key Management

1. Generate a new SSH key pair:
   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/haxinator
   ```

2. Replace the default key on your device:
   ```bash
   ssh-copy-id -i ~/.ssh/haxinator.pub hax@haxinator.local
   ```

3. Disable password authentication:
   ```bash
   sudo nano /etc/ssh/sshd_config
   # Set: PasswordAuthentication no
   sudo systemctl restart sshd
   ```

### SSL Certificate Management

1. Generate a new self-signed certificate:
   ```bash
   sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
   -keyout /etc/ssl/private/haxinator.key \
   -out /etc/ssl/certs/haxinator.crt
   ```

2. Or install a Let's Encrypt certificate if you have a domain:
   ```bash
   sudo apt install certbot
   sudo certbot certonly --standalone -d your.domain.com
   ```

## Tunneling Features

### DNS Tunneling (Iodine)

Iodine allows you to tunnel IPv4 data through a DNS server. This requires:
- A registered domain
- Control over the domain's DNS records
- A server running the Iodine server component

See [Server Requirements](server-requirements.md) for detailed setup instructions.

### ICMP Tunneling (Hans)

Hans creates a VPN-like connection using ICMP echo requests. Requirements:
- A publicly accessible server
- Ability to send/receive ICMP packets
- Root access on the server

Configuration steps are detailed in [Server Requirements](server-requirements.md).

## Development and Customization

### Custom Overlays

The `haxinator-pigen-overlay` directory contains all custom configurations:

```
haxinator-pigen-overlay/
├── stage2/
│   ├── 01-sys-tweaks/
│   ├── 02-custom-config/
│   └── 03-net-config/
└── stage3/
    └── 01-apps/
```

To add custom features:
1. Create a new directory under the appropriate stage
2. Add your scripts and files
3. Update the stage configuration

### Build Process Details

The build process follows these stages:

1. **Stage 1**: Base system setup
   - Raspberry Pi OS minimal installation
   - Essential system packages
   - Boot configuration

2. **Stage 2**: Haxinator customization
   - System tweaks and optimizations
   - Custom configurations
   - Network setup

3. **Stage 3**: Application layer
   - Web interface installation
   - Service configuration
   - Final optimizations

### Testing and Debugging

1. Mount a built image:
   ```bash
   sudo ./img-mount.sh
   ```

2. Inspect or modify files:
   ```bash
   sudo chroot /mnt/root /bin/bash
   ```

3. Unmount safely:
   ```bash
   sudo ./img-unmount.sh
   ```

## Performance Tuning

### Memory Management

1. Adjust swap settings:
   ```bash
   sudo nano /etc/dphys-swapfile
   # CONF_SWAPSIZE=100
   sudo systemctl restart dphys-swapfile
   ```

2. Optimize for your use case:
   ```bash
   # For tunneling focus
   vm.swappiness=10
   net.core.rmem_max=26214400
   ```

### Network Optimization

1. TCP optimizations:
   ```bash
   # /etc/sysctl.conf
   net.ipv4.tcp_fin_timeout = 10
   net.ipv4.tcp_keepalive_time = 600
   net.ipv4.tcp_max_syn_backlog = 8192
   ```

2. DNS settings for better tunnel performance:
   ```bash
   # /etc/systemd/resolved.conf
   [Resolve]
   DNS=1.1.1.1
   FallbackDNS=8.8.8.8
   DNSSEC=no
   ```

## Troubleshooting

### Common Build Issues

1. **Out of Space**
   ```
   no space left on device
   ```
   - Clean old builds: `./docker-build.sh --clean`
   - Ensure sufficient disk space

2. **Cross-compilation Errors**
   ```
   qemu: uncaught target signal 11
   ```
   - Update QEMU: `sudo apt install --only-upgrade qemu-user-static`
   - Check CPU compatibility

### Runtime Issues

1. **Tunneling Problems**
   - Check server connectivity
   - Verify firewall rules
   - Monitor logs: `journalctl -u iodine -u hans`

2. **Web Interface Issues**
   - Clear browser cache
   - Check SSL certificate
   - Verify PHP logs