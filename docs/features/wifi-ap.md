---
title: WiFi & Access Point
parent: Features
nav_order: 4
description: "Managing WiFi connections and creating an Access Point with Haxinator 2000"
---

# WiFi & Access Point Management

Haxinator 2000 provides comprehensive WiFi functionality, allowing you to connect to existing networks as a client and create your own access point for other devices to connect to.

## WiFi Client Mode

In client mode, Haxinator connects to external WiFi networks like any other device.

### Connecting to WiFi Networks

#### Using the Web Interface

1. Navigate to "Network" → "WiFi Client" in the web interface
2. Click "Scan" to see available networks
3. Select your desired network from the list
4. Enter the password when prompted
5. Click "Connect"

The system will attempt to connect and display the status in real-time.

#### Using Command Line

You can also manage WiFi connections via SSH:

```bash
# List available networks
sudo nmcli device wifi list

# Connect to a network
sudo nmcli device wifi connect "SSID" password "your_password"

# Check connection status
nmcli connection show
```

### Managing Saved Networks

Haxinator remembers previously connected networks:

1. View saved networks in "Network" → "Saved Connections"
2. Set priority order by dragging networks (higher = preferred)
3. Edit connection details by clicking the gear icon
4. Delete saved networks by clicking the trash icon

### Advanced WiFi Client Settings

| Setting | Description | Notes |
|---------|-------------|-------|
| Auto-Connect | Connect automatically | Enabled by default |
| Hidden SSID | Connect to hidden networks | Requires manual SSID entry |
| BSSID | Connect to specific access point | Useful for multi-AP networks |
| Band | 2.4GHz or 5GHz preference | "Auto" recommended |
| Power Management | Enable power saving | May affect latency |

## Access Point Mode

Haxinator can create its own WiFi network for other devices to connect to.

### Setting Up the Access Point

#### Basic Configuration

1. Edit your secrets file (`~/.haxinator-secrets`):
   ```
   WIFI_SSID="Haxinator 2000"
   WIFI_PASSWORD="ChangeMe"
   ```

2. Rebuild the image, or configure through the web interface:
   - Go to "Network" → "Access Point"
   - Enter your desired SSID and password
   - Click "Apply"

#### Advanced AP Configuration

In the web interface, you can configure these additional settings:

- **Channel**: Select WiFi channel (1-11 for 2.4GHz, 36-165 for 5GHz)
- **Band**: Choose 2.4GHz, 5GHz, or dual-band (if supported)
- **Mode**: 802.11n, 802.11ac, etc.
- **SSID Visibility**: Hidden or broadcast
- **Maximum Clients**: Limit number of connections
- **Isolation Mode**: Prevent clients from seeing each other

### DHCP and Network Settings

Configure IP addressing for connected clients:

- **IP Range**: Default 192.168.8.0/24
- **Gateway IP**: Default 192.168.8.1 (Haxinator device)
- **DHCP Range**: Default 192.168.8.10 - 192.168.8.200
- **Lease Time**: How long IP addresses are assigned

### Access Control

Control who can connect to your access point:

- **MAC Filtering**: Allow or deny specific devices
- **Time Restrictions**: Limit connection hours
- **Bandwidth Control**: Set upload/download limits

## Dual Mode Operation

Haxinator uniquely allows simultaneous client and AP modes:

### Internet Sharing

1. Connect to an internet source (WiFi, Ethernet, or mobile)
2. Enable AP mode
3. Enable "Share Connection" in the web interface
4. Choose which connection to share

This creates a bridge between networks, allowing all your devices to share a single internet connection.

### Multi-Hop Configurations

For advanced users, create complex network topologies:

1. Connect Haxinator to a WiFi network
2. Create an access point with a different SSID
3. Configure routing between the networks
4. Optionally, route specific traffic through tunnels

## Network Monitoring

The web interface provides real-time monitoring:

- **Connected Clients**: View devices, IP addresses, signal strength
- **Bandwidth Usage**: Per-client and total usage graphs
- **Signal Quality**: WiFi signal strength and channel congestion
- **Connection History**: Log of connections and disconnections

## Troubleshooting

### WiFi Client Issues

- **Can't See Networks**:
  - Check WiFi radio is enabled (`nmcli radio wifi on`)
  - Check for hardware issues (`sudo iw dev`)
  - Try a manual scan (`sudo iwlist wlan0 scan | grep ESSID`)

- **Can't Connect to Network**:
  - Verify password is correct
  - Check encryption type matches (WPA2, WPA3)
  - Try forgetting and reconnecting
  - Check signal strength (`iwconfig wlan0`)

### Access Point Issues

- **AP Won't Start**:
  - Check for hardware compatibility
  - Verify another process isn't using WiFi
  - Check hostapd logs (`journalctl -u hostapd`)

- **Clients Can't Connect**:
  - Verify password is correct
  - Check DHCP service is running (`systemctl status dnsmasq`)
  - Try changing WiFi channel (may be congestion)
  - Check band compatibility (5GHz vs 2.4GHz)

## Command Reference

```bash
# Turn WiFi radio on/off
sudo nmcli radio wifi on
sudo nmcli radio wifi off

# Start/stop access point
sudo systemctl start hostapd
sudo systemctl stop hostapd

# View connected clients
sudo iw dev wlan0 station dump

# Check wireless interfaces
iw dev

# View detailed AP status
sudo hostapd_cli status
```

## Security Considerations

- Change default passwords immediately
- Use WPA2/WPA3 encryption
- Enable client isolation for public hotspots
- Regularly update firmware for security patches
- Consider MAC filtering for additional security 