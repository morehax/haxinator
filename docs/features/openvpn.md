---
title: OpenVPN
parent: Features
nav_order: 3
description: "Using OpenVPN with Haxinator 2000"
---

# OpenVPN Integration

OpenVPN is a robust and widely-used VPN solution that provides secure, encrypted connections. Haxinator 2000 integrates OpenVPN client capabilities to provide traditional VPN connectivity when needed.

## How OpenVPN Works

OpenVPN creates an encrypted tunnel for your network traffic:

1. Your data is encrypted on the Haxinator device
2. Encrypted data travels through your internet connection
3. The OpenVPN server decrypts and forwards the traffic to its destination
4. Return traffic follows the reverse path

This provides both security (through encryption) and privacy (by masking your real IP address).

## Setting Up OpenVPN

### Prerequisites

- Access to an OpenVPN server with proper credentials
- OpenVPN configuration files (.ovpn) or server details
- Network connection for initial setup

### Configuration Methods

Haxinator 2000 supports OpenVPN configuration through the secrets file, which is processed during first boot:

1. Edit your secrets file (`~/.haxinator-secrets`) and set:
   ```
   VPN_USER=your_username
   VPN_PASS=your_password
   ```

2. Rebuild the image, or update the configuration directly on your device.

The device comes with a pre-configured OpenVPN profile called `openvpn-udp` that uses the provided credentials. During first boot, NetworkManager imports the OpenVPN configuration from `/openvpn-udp.ovpn` and configures it with your credentials.

### Checking Connection Status

You can verify the OpenVPN configuration was properly imported:

```bash
# List configured connections
nmcli connection show | grep openvpn

# Check the VPN connection details
nmcli connection show openvpn-udp
```

## Managing OpenVPN Through the Web Interface

The Haxinator web interface provides a simple way to manage your VPN connection:

### Connection Controls

1. Navigate to "Tunneling" → "OpenVPN" in the web interface
2. You'll see the status of your OpenVPN connection
3. Use the toggle switch to connect or disconnect
4. View real-time connection details and statistics

### Connection Status Indicators

The web interface shows:
- Connection state (Connected/Disconnected)
- Remote server IP address
- Assigned VPN IP address
- Connection uptime
- Data transferred (sent/received)

## Command Line Management

You can also manage the OpenVPN connection via SSH:

```bash
# Establish the VPN connection
sudo nmcli connection up openvpn-udp

# Check connection status
nmcli connection show --active | grep openvpn

# View detailed status
nmcli connection show openvpn-udp

# Disconnect the VPN
sudo nmcli connection down openvpn-udp
```

## Advanced Configuration

For advanced users who need to modify the OpenVPN setup:

### Using a Different Configuration File

To replace the default OpenVPN configuration:

1. Create a new .ovpn file
2. Copy it to your Haxinator device:
   ```bash
   scp your-config.ovpn muts@haxinator.local:/tmp/
   ```
3. SSH into your Haxinator
4. Import the new configuration:
   ```bash
   sudo nmcli connection delete openvpn-udp
   sudo nmcli connection import type openvpn file /tmp/your-config.ovpn
   ```
5. Update with your credentials:
   ```bash
   sudo nmcli connection modify openvpn-udp +vpn.data username=your_username +vpn.data password-flags=0 vpn.secrets password=your_password
   ```

### Modifying Connection Properties

You can adjust the OpenVPN connection settings:

```bash
# Set to autoconnect on boot
sudo nmcli connection modify openvpn-udp connection.autoconnect yes

# Set connection priority (higher number = higher priority)
sudo nmcli connection modify openvpn-udp connection.autoconnect-priority 100

# Prevent the VPN from becoming the default route
sudo nmcli connection modify openvpn-udp ipv4.never-default true
```

## Troubleshooting

If you encounter issues with OpenVPN:

### Connection Failures

1. Check if your credentials are correct:
   ```bash
   grep -E "VPN_USER|VPN_PASS" /root/.env
   ```

2. Verify the OpenVPN connection exists:
   ```bash
   nmcli connection show | grep openvpn
   ```

3. Review the connection logs:
   ```bash
   journalctl -u NetworkManager | grep -i openvpn
   ```

### Authentication Issues

If you receive authentication errors:

1. Ensure your username and password are correctly set in /root/.env
2. Check if the VPN provider's servers are operational
3. Try manually reinstalling the connection:
   ```bash
   sudo nmcli connection delete openvpn-udp
   
   # Then in firstboot.sh or manually:
   sudo nmcli connection import type openvpn file "/openvpn-udp.ovpn"
   sudo nmcli connection modify openvpn-udp +vpn.data username="$VPN_USER" +vpn.data password-flags=0
   sudo nmcli connection modify openvpn-udp vpn.secrets "password=$VPN_PASS"
   ```

### Getting Detailed Logs

For deeper troubleshooting:

```bash
# Enable verbose logging for NetworkManager
sudo nmcli general logging level DEBUG domains VPN_PLUGIN,VPN_MANAGER

# Attempt to connect
sudo nmcli connection up openvpn-udp

# Check the logs
journalctl -fu NetworkManager
```

## Security Considerations

Your VPN credentials are stored in the `/root/.env` file, which should have restricted permissions (mode 600). This file is read during first boot to configure the OpenVPN connection.

For production deployments, consider these security enhancements:

1. Use certificate-based authentication instead of username/password
2. Implement a more secure credential storage solution
3. Configure the VPN to reconnect automatically after network changes

## Additional Resources

- [OpenVPN Community](https://openvpn.net/community/)
- [Digital Ocean OpenVPN Setup Guide](https://www.digitalocean.com/community/tutorials/how-to-set-up-an-openvpn-server-on-ubuntu-18-04)
- [VPN Provider Comparisons](https://www.privacytools.io/providers/vpn/) 