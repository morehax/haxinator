---
title: ICMP Tunneling
parent: Features
nav_order: 1
description: "Using Hans VPN for ICMP tunneling with Haxinator 2000"
---

# ICMP Tunneling with Hans VPN

ICMP tunneling is a powerful technique to bypass restrictive firewalls by encapsulating your data inside ICMP echo requests and replies (ping packets), which are rarely blocked by network administrators.

## How ICMP Tunneling Works

When you use Hans VPN on Haxinator 2000:

1. Your data is encapsulated into ICMP echo packets (ping packets)
2. These packets travel through the network, often bypassing firewall restrictions
3. At the destination, a Hans server extracts your data from the ICMP packets
4. Regular internet traffic flows through this tunnel

This technique works because many networks allow ICMP traffic for diagnostic purposes, creating a pathway for your data when other protocols like HTTP, SSH, or custom VPN protocols might be blocked.

## Setting Up ICMP Tunneling

### Prerequisites

- A publicly accessible server with Hans VPN installed
- Server IP address
- Shared password

### Server-Side Configuration

1. Install Hans on your server:
   ```bash
   apt-get install hans
   # or build from source:
   # git clone https://github.com/friedrich/hans.git
   # cd hans && make
   ```

2. Start the Hans server:
   ```bash
   sudo hans -s -p your_password -n 10.1.0.0/24
   ```
   This creates a TUN interface with network 10.1.0.0/24, where the server will be accessible at 10.1.0.1.

### Haxinator Configuration

To enable ICMP tunneling on Haxinator 2000:

1. Edit your secrets file (`~/.haxinator-secrets`) and set:
   ```
   HANS_SERVER=your_server_ip
   HANS_PASSWORD=your_password
   ```

2. Rebuild the image, or update the configuration directly on your device.

During first boot, Haxinator will create a NetworkManager connection profile named `hans-icmp-vpn` with these settings. The connection will be available but not automatically activated.

> **Note:** Haxinator uses a custom NetworkManager VPN plugin for Hans that seamlessly integrates with the system's connection management. This enables easy activation through both the web interface and command line.
{: .info }

### Checking Configuration

Verify that the Hans VPN configuration was properly imported:

```bash
# Check if the connection exists
nmcli connection show | grep hans-icmp-vpn

# View detailed connection configuration
nmcli connection show hans-icmp-vpn
```

The output should show a VPN connection of type `org.freedesktop.NetworkManager.hans`.

## Managing ICMP Tunnel via Web Interface

The Haxinator web interface provides easy management of your ICMP tunnel:

![ICMP Tunneling Interface](/assets/images/interface/haxinate-tab.png)
{: .screenshot }

1. Navigate to "Tunneling" → "ICMP Tunnel (Hans)"
2. You'll see the current status of your ICMP tunnel:
   - Connection state (Connected/Disconnected)
   - Server IP address
   - Tunnel IP address (typically in the 10.1.0.0/24 range)
   - Connection uptime
   - Traffic statistics
3. Use the toggle switch to connect or disconnect
4. The "Connection Log" tab shows real-time output from the Hans process

## Command Line Management

You can also control the ICMP tunnel via the command line through NetworkManager:

```bash
# Start the ICMP tunnel
sudo nmcli connection up hans-icmp-vpn

# Check if the tunnel is active
nmcli connection show --active | grep hans-icmp-vpn

# View the assigned tunnel IP
ip addr show tun0

# Monitor tunnel traffic
sudo iftop -i tun0

# Stop the ICMP tunnel
sudo nmcli connection down hans-icmp-vpn
```

## Troubleshooting

### Connection Issues

If you're having trouble establishing the ICMP tunnel:

1. Verify your configuration settings:
   ```bash
   grep HANS /root/.env
   ```

2. Check if the NetworkManager plugin is properly installed:
   ```bash
   ls -l /usr/lib/NetworkManager/hans-service.py
   systemctl status NetworkManager
   ```

3. Look for errors in the logs:
   ```bash
   journalctl -u NetworkManager | grep -i hans
   cat /tmp/hans-plugin.log
   ```

4. Test basic ICMP connectivity to your server:
   ```bash
   ping -c 3 your_server_ip
   ```
   
   If ICMP is blocked, you'll see timeouts, and you may need to switch to DNS tunneling instead.

### Testing Tunnel Connectivity

Once connected, verify the tunnel is working:

```bash
# Check if the tunnel interface exists
ip addr show tun0

# Test connectivity to the server's tunnel IP
ping -c 3 10.1.0.1

# Try accessing a website through the tunnel
curl --interface tun0 https://example.com

# Check the routing table
ip route show
```

### Common Problems and Solutions

- **Tunnel Fails to Establish**: 
  - Verify that ICMP traffic is allowed to your server
  - Check that your server is running the Hans service: `ps aux | grep hans`
  - Try disabling any firewalls temporarily for testing: `sudo ufw disable` (on server)

- **Connection Drops Frequently**:
  - Some network equipment may throttle or block sustained ICMP traffic
  - Try adjusting the keepalive interval with the `-k` flag on the server
  - Configure auto-reconnect in NetworkManager: 
    ```bash
    sudo nmcli connection modify hans-icmp-vpn connection.autoconnect yes
    ```

- **Slow Performance**:
  - ICMP tunneling is inherently slower than direct connections
  - Try adjusting the tunnel MTU: 
    ```bash
    sudo nmcli connection modify hans-icmp-vpn vpn.data "mtu=1400"
    ```
  - Limit the tunnel to text-based applications (SSH, messaging)

- **Route Conflicts**:
  - By default, Haxinator configures Hans with `ipv4.never-default true` to prevent routing conflicts
  - If you want all traffic through the tunnel, modify this setting:
    ```bash
    sudo nmcli connection modify hans-icmp-vpn ipv4.never-default false
    ```

## Optimizing Performance

ICMP tunneling offers moderate performance:

- Typical throughput: 10-30 KB/s
- Latency: 50-200ms (highly dependent on the underlying network)
- Better than DNS tunneling, but slower than regular connections

Best used for:
- Text-based applications (SSH, messaging)
- Basic web browsing
- Command-line utilities

Less suitable for:
- Video streaming
- Large file transfers
- Real-time gaming

## Security Considerations

Hans VPN provides tunneling but not strong encryption. For sensitive data:

1. Use additional encryption within the tunnel (SSH, HTTPS)
2. Be aware that ICMP traffic may be monitored or logged
3. Consider combining with other security measures

> **Warning:** ICMP tunnel traffic is not encrypted by default. Always use secure protocols like SSH or HTTPS for sensitive communications through the tunnel.
{: .warning }

## Technical Details

Haxinator implements ICMP tunneling using:

- A custom NetworkManager VPN plugin (`org.freedesktop.NetworkManager.hans`)
- Integration with the system's connection management via D-Bus
- Hans binary running in client mode with `-f` (foreground) flag
- Connection settings stored in NetworkManager profiles
- A tun0 interface for routing traffic through the tunnel

The NetworkManager plugin launches Hans with appropriate parameters when the connection is activated:

```bash
hans -f -c server_ip -p password
```

The plugin monitors the Hans process and reports connection state back to NetworkManager, making it easy to manage alongside other network connections.

## Using with Other Tunnels

You can use ICMP tunneling alongside other tunneling methods for redundancy:

1. Enable ICMP tunneling via the web interface
2. Connect to another tunnel (e.g., OpenVPN or DNS tunneling)
3. Configure routing to direct specific traffic through specific tunnels

This provides fallback options if one tunneling method becomes blocked or unavailable.

## Command Line Examples

For advanced users, here are some useful command examples:

```bash
# See the full Hans connection config
nmcli --show-secrets connection show hans-icmp-vpn

# Force reconnection of the tunnel
sudo nmcli connection down hans-icmp-vpn && sudo nmcli connection up hans-icmp-vpn

# Check Hans process
ps aux | grep hans

# Test routing through the tunnel
ip route get 8.8.8.8 dev tun0

# Configure tunnel to autoconnect on boot
sudo nmcli connection modify hans-icmp-vpn connection.autoconnect yes
```

## Use Cases

ICMP tunneling is particularly useful for:

- Bypassing captive portals at airports, hotels, or coffee shops
- Connecting from restricted corporate networks
- Accessing the internet when conventional VPN protocols are blocked
- Creating a backup communication channel for critical systems 