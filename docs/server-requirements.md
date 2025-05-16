---
title: Server Requirements
nav_order: 3
description: "Setting up required server infrastructure for Haxinator tunneling features"
---

# Server Requirements

> **Note:** This guide is only relevant if you plan to use ICMP (Hans) or DNS (Iodine) tunneling features. If you're only using OpenVPN with an existing VPN provider or just using the WiFi/Bluetooth features, you can skip this section.
{: .info }

Haxinator 2000 is a client device that requires certain server-side infrastructure to enable its advanced tunneling features. This guide explains what servers you need to set up and how to configure them.

## Overview

| Feature | Server Requirement | When Needed |
|---------|-------------------|-------------|
| ICMP Tunneling | Hans VPN Server | Only if you want to tunnel traffic through ICMP (ping) packets |
| DNS Tunneling | Iodine Server + NS Records | Only if you want to tunnel traffic through DNS queries |
| OpenVPN | OpenVPN Server | Optional - can use existing VPN providers |

## Hans ICMP Server Setup

Hans requires a server with:
- Public IP address
- Ability to send/receive ICMP packets
- Root/sudo access

### Installation

```bash
# On Ubuntu/Debian
sudo apt-get update
sudo apt-get install build-essential git
git clone https://github.com/friedrich/hans.git
cd hans
make
sudo make install
```

### Running the Server

```bash
# Basic server setup
sudo hans -s 192.168.1.0/24 -p YOUR_PASSWORD

# With specific interface
sudo hans -s 192.168.1.0/24 -p YOUR_PASSWORD -i eth0

# As a service (recommended)
sudo tee /etc/systemd/system/hans.service <<EOF
[Unit]
Description=Hans ICMP Tunnel
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/sbin/hans -s 192.168.1.0/24 -p YOUR_PASSWORD
Restart=always

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl enable hans
sudo systemctl start hans
```

## Iodine DNS Server Setup

Iodine requires:
- A server with public IP
- A domain name
- DNS records configuration access
- Root/sudo access

### Domain Configuration

1. Register a domain or use a subdomain
2. Add NS record:
   ```
   tunnel  IN   NS   ns.yourdomain.com.
   ns      IN   A    YOUR_SERVER_IP
   ```

### Installation

```bash
# On Ubuntu/Debian
sudo apt-get update
sudo apt-get install iodine

# On CentOS/RHEL
sudo yum install iodine
```

### Running the Server

```bash
# Basic setup
sudo iodined -f -c -P YOUR_PASSWORD 192.168.1.0/24 tunnel.yourdomain.com

# As a service (recommended)
sudo tee /etc/systemd/system/iodine.service <<EOF
[Unit]
Description=Iodine DNS Tunnel
After=network.target

[Service]
Type=simple
ExecStart=/usr/sbin/iodined -f -c -P YOUR_PASSWORD 192.168.1.0/24 tunnel.yourdomain.com
Restart=always

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl enable iodine
sudo systemctl start iodine
```

## OpenVPN Server

For OpenVPN, you can either:
- Set up your own OpenVPN server
- Use a commercial VPN service
- Use existing corporate VPN infrastructure

### Setting Up Your Own Server

We recommend following the official [OpenVPN Access Server](https://openvpn.net/access-server/) setup guide or using [PiVPN](https://pivpn.io/) for a simpler setup.

## Security Considerations

### Firewall Rules

Ensure your firewall allows:
- ICMP traffic for Hans
- UDP port 53 for Iodine
- UDP port 1194 for OpenVPN (default)

### Server Hardening

1. Keep systems updated
2. Use strong passwords
3. Enable fail2ban
4. Use SSH key authentication
5. Regular security audits

### Monitoring

Monitor your servers for:
- Unusual traffic patterns
- High bandwidth usage
- Failed login attempts
- System resource usage

## Troubleshooting

### Hans Server

Common issues:
- ICMP blocked by firewall
- MTU size mismatches
- Root privileges missing

### Iodine Server

Check:
- DNS record configuration
- Domain propagation
- UDP port 53 accessibility
- Firewall rules

### OpenVPN Server

Verify:
- Certificate configuration
- Network routing
- Port forwarding
- Client configuration match 