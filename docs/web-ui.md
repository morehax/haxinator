---
title: Web Interface
nav_order: 5
description: "Using the Haxinator 2000 web interface"
---

# Haxinator Web Interface

Haxinator 2000 provides a user-friendly web interface for controlling and monitoring all aspects of the system. This page explains how to access and use the web UI effectively.

## Accessing the Web Interface

1. Connect to the Haxinator device via WiFi (default SSID: "Haxinator 2000")
2. Open a web browser on your connected device
3. Navigate to [http://haxinator.local](http://haxinator.local) or [http://192.168.8.1](http://192.168.8.1)
4. Log in with the default credentials:
   - Username: `admin`
   - Password: `haxinator`

**Important**: Change the default password immediately after your first login for security.

## Dashboard Overview

The dashboard provides a comprehensive overview of your Haxinator device:

![Dashboard Screenshot](assets/images/dashboard.png)

Key information displayed:
- System status and uptime
- Active connections and tunnels
- Network interface status
- CPU temperature and load
- Available storage

## Main Navigation

The web interface is organized into several main sections:

### 1. Network Management

This section allows you to:
- Connect to WiFi networks
- Configure the WiFi access point
- Manage saved connections
- View network status and statistics

#### Available WiFi Networks

The WiFi scanning interface shows all available networks in your area:

![WiFi Networks Screen](/assets/images/interface/wifi-networks.png)
{: .screenshot }

#### Saved Connections

Manage your previously connected networks and their settings:

![Saved Connections Screen](/assets/images/interface/saved-connections.png)
{: .screenshot }

### 2. Tunneling

Control all tunneling services:
- **ICMP Tunnel (Hans VPN)**: Configure and monitor ICMP tunneling
- **DNS Tunnel (Iodine)**: Set up and manage DNS tunneling
- **OpenVPN**: Manage traditional VPN connections

Each tunnel type has its own configuration panel with:
- Connection settings
- Credentials management
- Advanced parameters
- Status monitoring

### 3. Tools

Access built-in network tools:
- Network scanner
- WiFi analyzer
- Packet capture
- System diagnostics

### 4. Settings

Configure system settings:
- User management (change password, add users)
- System updates
- Backup and restore
- Advanced configuration options

## Common Tasks

### Connecting to a WiFi Network

1. Go to Network → WiFi Client
2. Click "Scan" to view available networks
3. Select your network from the list
4. Enter the network password when prompted
5. Click "Connect"

### Setting Up a Tunnel

1. Navigate to Tunneling → Select your tunnel type
2. Enter the required information:
   - Server IP/hostname
   - Credentials
   - Any specific parameters
3. Click "Save Configuration"
4. Toggle the switch to "Enabled"
5. Monitor the status indicator for connection success

### Changing Interface Settings

1. Go to Network → Interfaces
2. Select the interface you want to configure
3. Choose between DHCP or Static IP
4. For static IP, enter IP address, subnet mask, and gateway
5. Click "Apply Changes"

## Customizing the Dashboard

You can customize your dashboard view:
1. Click the "Customize" button in the top right
2. Drag and drop widgets to rearrange
3. Toggle visibility for each widget
4. Save your configuration

## Mobile View

The web interface is responsive and works well on mobile devices:
- All functions are available on smaller screens
- Navigation collapses to a hamburger menu
- Widgets adjust to fit available space

## Troubleshooting

If you cannot access the web interface:

1. Ensure you're connected to the correct WiFi network
2. Try the IP address (192.168.8.1) if hostname doesn't resolve
3. Check if the device is powered on and booted completely
4. Restart your browser or try a different browser
5. Reset your WiFi connection

If services won't start from the web interface:

1. Check the logs (Settings → Logs)
2. Verify your configuration parameters
3. Ensure prerequisites for the service are met
4. Try restarting the service

## Advanced Features

### API Access

The web interface provides a REST API for automation:
- Authentication required using API keys
- Documentation available at `/api/docs`
- Supports GET, POST, PUT operations

### Dark Mode

Toggle between light and dark themes:
1. Click on your username in the top right
2. Select "Preferences"
3. Toggle "Dark Mode"

### Keyboard Shortcuts

- `Ctrl+S`: Save current configuration
- `Ctrl+R`: Refresh status
- `Ctrl+H`: Return to dashboard
- `?`: Show keyboard shortcut help 