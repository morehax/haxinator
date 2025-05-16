---
title: Terminal Access
nav_order: 7
parent: Features
description: "Terminal access methods for Haxinator 2000"
---

# Terminal Access

> **Security Warning:** Haxinator ships with default credentials (`hax:hax`) that are publicly known. Change these immediately after first boot to prevent unauthorized access.
{: .warning }

Haxinator 2000 comes with four built-in terminal access methods, each configured and ready to use out of the box.

## Quick Reference

| Method | URL/Port | Protocol | Default Credentials |
|--------|----------|-----------|-------------------|
| Web Terminal | https://192.168.8.1:4200 | HTTPS | `hax`:`hax` |
| SSH | Port 22 | SSH | `hax`:`hax` |
| Bluetooth Serial | RFCOMM Channel 1 | Bluetooth | `hax`:`hax` |
| Serial Console | 115200 baud | USB Serial | None |

## Web Terminal

The web terminal is integrated into Haxinator's web interface, providing instant terminal access without additional software.

### Accessing the Web Terminal

1. Click the "Terminal" button in the top-right corner of the web interface
2. Or navigate directly to `https://192.168.8.1:4200`
3. Accept the self-signed certificate warning
4. Log in with:
   - Username: `hax`
   - Password: `hax`

> **Note:** The web terminal uses the same credentials as SSH access.
{: .info }

### Features
- Accessible through any modern web browser
- Integrated with Haxinator's web interface
- Full terminal functionality including color support
- Automatic HTTPS encryption
- Mobile-friendly interface

## SSH Access

SSH is enabled by default for remote terminal access and file transfers.

### Default Configuration
- Username: `hax`
- Password: `hax`
- Port: 22
- Pre-installed public key (see warning below)

> **Security Warning:** The default SSH key is publicly known. After first boot:
> ```bash
> # Remove the default key and add your own
> rm ~/.ssh/authorized_keys
> echo "your-public-key" > ~/.ssh/authorized_keys
> chmod 600 ~/.ssh/authorized_keys
> 
> # Change the default password
> passwd
> ```
{: .warning }

### Basic Usage
```bash
# Connect to Haxinator
ssh hax@192.168.8.1

# Copy files to Haxinator
scp localfile.txt hax@192.168.8.1:~/
```

## Bluetooth Terminal

Haxinator provides terminal access over Bluetooth, useful when network connectivity isn't available.

### Quick Setup
1. Configure your device's MAC address:
   ```bash
   echo "BLUETOOTH_MAC=XX:XX:XX:XX:XX:XX" >> ~/.haxinator-secrets
   ```
2. Restart Bluetooth services:
   ```bash
   sudo systemctl restart bluetooth bluetooth_pair
   ```

### Connecting
- **Android/iOS**: Use any Bluetooth serial terminal app
- **Linux/macOS**: Connect via `/dev/rfcomm0` or similar
- **Windows**: Use the assigned COM port

For detailed Bluetooth setup and troubleshooting, see [Bluetooth Serial](bluetooth-serial.html).

> **Note:** Bluetooth terminal uses the same credentials as SSH/Web access (`hax:hax`).
{: .info }

## Serial Console

Serial console provides direct terminal access through USB, useful for troubleshooting or when network access isn't available.

### USB Mode Switching

Haxinator's USB port can operate in either network or serial mode:

```bash
# Switch to serial console mode
sudo /usr/local/bin/toggle_usb_serial.sh serial

# Switch back to USB network mode
sudo /usr/local/bin/toggle_usb_serial.sh usb
```

### Connecting to Serial Console

#### On Linux/macOS:
```bash
# Connect using screen
screen /dev/ttyACM0 115200
```

#### On Windows:
1. Open Device Manager to find the COM port
2. Connect using PuTTY:
   - Serial line: COM[X]
   - Speed: 115200

> **Note:** Serial console provides direct system access without authentication - useful for recovery but keep physical security in mind.
{: .info }

## Troubleshooting

### Web Terminal
- If the terminal doesn't load, check shellinabox service:
  ```bash
  sudo systemctl status shellinabox
  ```

### SSH Access
- If connection fails, verify SSH service:
  ```bash
  sudo systemctl status ssh
  ```

### Serial Console
- Ensure USB mode is set correctly
- Try unplugging and reconnecting the USB cable
- Verify your USB cable supports data transfer 

## Security Considerations

### Changing Default Credentials

It's critical to change the default credentials immediately after first boot:

```bash
# Change password for all terminal access methods
passwd

# Remove default SSH key
rm ~/.ssh/authorized_keys
echo "your-public-key" > ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### Access Method Security Levels

| Method | Security Level | Notes |
|--------|---------------|-------|
| SSH | High (with proper setup) | Change default key and password |
| Web Terminal | Medium | Uses HTTPS but with self-signed cert |
| Bluetooth | Medium | Requires physical proximity |
| Serial | Basic | No authentication, physical access required | 