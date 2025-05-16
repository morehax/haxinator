---
title: Network Scanning
parent: Features
nav_order: 6
description: "Network discovery and scanning tools in Haxinator 2000"
---

# Network Scanning Tools

Haxinator 2000 includes powerful network scanning tools for discovering devices, ports, and services on networks you're connected to.

## Available Scanning Tools

The Haxinator includes several built-in network scanning tools accessible through the web interface:

- **Network Discovery**: Find active hosts on the current network
- **Port Scanning**: Detect open ports on target devices
- **Service Identification**: Identify running services and versions
- **WiFi Network Scanning**: Discover nearby wireless networks

## Using the Network Scanner

### Web Interface Access

1. Connect to the Haxinator web interface at http://haxinator.local or http://192.168.8.1
2. Navigate to the "Tools" → "Network Scanner" section
3. Select the scan type and parameters
4. Click "Start Scan"

### Network Discovery Scan

To discover devices on your network:

1. Select "Discovery Scan" from the scan type dropdown
2. Choose the network interface to scan (e.g., wlan0, eth0)
3. Set the target network (defaults to the current subnet)
4. Click "Start Scan"

The scan displays:
- IP addresses of discovered devices
- MAC addresses (when available)
- Hostnames (via DNS or NetBIOS)
- Estimated device types

### Port Scanning

To scan for open ports on a target device:

1. Select "Port Scan" from the scan type dropdown
2. Enter the target IP address
3. Select scan options:
   - Quick scan (common ports)
   - Full scan (all ports)
   - Custom port range
4. Click "Start Scan"

Results show:
- Open ports
- Services detected on each port
- Service versions (when available)

### WiFi Network Analysis

To scan for wireless networks:

1. Select "WiFi Scan" from the scan type dropdown
2. Click "Start Scan"

The scan shows:
- SSIDs (network names)
- Signal strength
- Security types (WPA, WPA2, etc.)
- Channel information
- BSSID (MAC address) of access points

## Command-Line Access

Haxinator's scanning tools can also be accessed via SSH:

### Network Discovery

```bash
# Discover all devices on the network
sudo nmap -sn 192.168.8.0/24

# Quick discovery using netdiscover
sudo netdiscover -r 192.168.8.0/24 -P
```

### Port Scanning

```bash
# Quick port scan of a single host
sudo nmap -F 192.168.8.100

# Detailed scan with service detection
sudo nmap -sV -p 1-65535 192.168.8.100

# Scan multiple hosts
sudo nmap -p 22,80,443 192.168.8.1-10
```

### WiFi Scanning

```bash
# Scan for WiFi networks
sudo iwlist wlan0 scan | grep -E "ESSID|Quality|Encryption"

# Use the built-in script
sudo /var/www/html/wifi-scan.sh
```

## Advanced Usage

### Custom Network Scan Profiles

You can save custom scan profiles in the web interface:

1. Configure your scan parameters
2. Click "Save Profile"
3. Give your profile a name
4. Access saved profiles from the dropdown menu

### Scheduling Regular Scans

Set up periodic network scans:

1. Go to "Tools" → "Scheduled Tasks"
2. Click "Add Scheduled Scan"
3. Select scan type and frequency
4. Choose notification options
5. Click "Save"

Results from scheduled scans are saved in the dashboard history.

## Security and Privacy Considerations

When using the network scanning tools, keep in mind:

- Only scan networks you own or have permission to scan
- Active scanning may be detected by security systems
- Some organizations prohibit network scanning
- Excessive scanning can trigger security alerts

## Troubleshooting Scan Issues

If you encounter problems with scanning:

- **Scan Fails to Start**: Check if your current network interface is active
- **Empty Results**: Verify you have proper network connectivity
- **Slow Performance**: Try reducing the scan scope or using "quick scan" mode
- **Access Denied**: Ensure you're logged in with sufficient privileges

## Integrating with Tunnels

Haxinator allows scanning through its various tunnels:

1. Establish a tunnel connection (ICMP, DNS, or OpenVPN)
2. In the scanner interface, select the tunnel interface (e.g., tun0)
3. Enter the target network on the remote side of the tunnel
4. Run the scan normally

This enables discovery and analysis of remote networks through your secured tunnels. 