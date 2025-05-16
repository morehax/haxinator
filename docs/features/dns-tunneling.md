---
title: DNS Tunneling
parent: Features
nav_order: 2
description: "Using Iodine for DNS tunneling with Haxinator 2000"
---

# DNS Tunneling with Iodine

DNS tunneling is a powerful technique that encapsulates data within DNS queries and responses, making it possible to bypass captive portals, restrictive firewalls, and other network limitations by utilizing DNS, which is rarely blocked.

## How DNS Tunneling Works

When you use Iodine with Haxinator 2000:

1. Your data is encoded and embedded inside DNS queries
2. These queries are sent to a DNS server that you control
3. The DNS server extracts the data and forwards it to the internet
4. Responses are encoded as DNS records and sent back to you

This technique exploits the fact that DNS is a critical service that's nearly always allowed, even in highly restricted networks.

## Setting Up DNS Tunneling

### Prerequisites

- A registered domain name you control
- A publicly accessible server with a static IP
- Authority to configure DNS for your domain
- Network access that allows DNS queries

### Server-Side Configuration

Before using Haxinator's DNS tunneling, you need to set up a server:

1. Set up an NS record for a subdomain (e.g., `t.yourdomain.com`) pointing to your server
2. Install Iodine on your server:
   ```bash
   apt-get install iodine
   ```
3. Run Iodine server:
   ```bash
   iodined -f -c -P your_password 10.0.0.1 t.yourdomain.com
   ```
   This creates a tunnel interface with IP 10.0.0.1, where your server will be accessible.

### Haxinator Configuration

To enable DNS tunneling on Haxinator 2000:

1. Edit your secrets file (`~/.haxinator-secrets`) and set:
   ```
   IODINE_TOPDOMAIN=t.yourdomain.com
   IODINE_NAMESERVER=ns-server-ip
   IODINE_PASS=your_password
   IODINE_MTU=1400
   IODINE_LAZY=true
   IODINE_INTERVAL=4
   ```

2. Rebuild the image, or update the configuration directly on your device.

During first boot, Haxinator will create a NetworkManager connection profile named `iodine-vpn` with these settings. The connection will be available but not automatically activated.

### Checking Configuration

Verify that the Iodine configuration was properly imported:

```bash
# Check if the connection exists
nmcli connection show | grep iodine-vpn

# View detailed connection configuration
nmcli connection show iodine-vpn
```

## Managing DNS Tunnel via Web Interface

The Haxinator web interface provides easy management of your DNS tunnel:

1. Navigate to "Tunneling" → "DNS Tunnel (Iodine)"
2. You'll see the current status of your DNS tunnel
3. Use the toggle switch to connect or disconnect
4. View real-time connection statistics and status

## Command Line Management

You can also control the DNS tunnel via the command line:

```bash
# Start the DNS tunnel
sudo nmcli connection up iodine-vpn

# Check if the tunnel is active
nmcli connection show --active | grep iodine-vpn

# View the assigned tunnel IP
ip addr show iodine0

# Stop the DNS tunnel
sudo nmcli connection down iodine-vpn
```

## Advanced Configuration Options

The default settings are suitable for most uses, but you can adjust these parameters for your specific needs:

| Setting | Description | Default | Notes |
|---------|-------------|---------|-------|
| MTU | Maximum transmission unit | 1400 | Lower if experiencing fragmentation issues |
| Lazy Mode | Less aggressive polling | true | Reduces server load but may increase latency |
| Interval | Seconds between polls | 4 | Higher values reduce traffic but increase latency |

To modify these settings on a running system:

```bash
# Update MTU
sudo nmcli connection modify iodine-vpn vpn.data "mtu=1200"

# Disable lazy mode
sudo nmcli connection modify iodine-vpn vpn.data "lazy-mode=false"

# Change polling interval
sudo nmcli connection modify iodine-vpn vpn.data "interval=6"
```

After making changes, restart the connection:

```bash
sudo nmcli connection down iodine-vpn
sudo nmcli connection up iodine-vpn
```

## Troubleshooting

### Connection Issues

If you're having trouble establishing the DNS tunnel:

1. Verify your configuration settings:
   ```bash
   grep IODINE /root/.env
   ```

2. Check if NetworkManager can see the connection:
   ```bash
   nmcli connection show | grep iodine
   ```

3. Look for errors in the logs:
   ```bash
   journalctl -u NetworkManager | grep -i iodine
   ```

4. Test DNS resolution to your server:
   ```bash
   dig NS yourdomain.com
   ```

### Testing Tunnel Connectivity

Once connected, verify the tunnel is working:

```bash
# Check if the tunnel interface exists
ip addr show iodine0

# Test connectivity to the server
ping -c 3 10.0.0.1

# Try accessing a website through the tunnel
curl --interface iodine0 https://example.com
```

### Common Problems and Solutions

- **Tunnel Fails to Establish**: 
  - Verify that your NS records are correctly configured
  - Check that UDP port 53 is open on your server
  - Try using a different nameserver in your configuration

- **Connection Drops Frequently**:
  - Increase the polling interval 
  - Check for network restrictions or timeouts
  - Consider adjusting the MTU size

- **Slow Performance**:
  - DNS tunneling is inherently slower than direct connections
  - Text-based protocols work best (SSH, HTTP text)
  - Large downloads and streaming are not recommended

## Security Considerations

Iodine provides tunneling but not encryption. For sensitive data:

1. Use additional encryption within the tunnel (SSH, HTTPS)
2. Be aware that DNS queries might be logged by intermediate DNS servers
3. Consider combining with other security measures

## Technical Details

Haxinator implements DNS tunneling using:

- NetworkManager's native VPN plugin system
- The `iodine-vpn` connection type in NetworkManager
- Connection settings stored in `/root/.env`

The tunnel interface is named `iodine0` and operates at layer 3 (IP level).

## Using with Other Tunnels

You can use DNS tunneling alongside other tunneling methods for redundancy:

1. Enable DNS tunneling via the web interface
2. Connect to another tunnel (e.g., OpenVPN or ICMP)
3. Use specific routing rules to direct traffic as needed

This provides fallback options if one tunneling method becomes blocked or unavailable.

## Performance Considerations

DNS tunneling is inherently slower than direct connections:

- Typical throughput: 3-10 KB/s
- Latency: 200-800ms
- Overhead: ~30% for encoding/decoding

Best used for text-based protocols (SSH, messaging) rather than streaming or large downloads.

## Example Commands

For advanced users, Haxinator provides CLI access to the Iodine service:

```bash
# Start DNS tunnel manually
sudo systemctl start iodine-client

# Check tunnel status
sudo systemctl status iodine-client

# View connection logs
journalctl -u iodine-client

# Test connection through the tunnel
ping -c 3 10.0.0.1

# Stop tunnel
sudo systemctl stop iodine-client
```

## Use Cases

DNS tunneling is particularly useful for:

- Bypassing captive portals at airports, hotels, etc.
- Connecting from networks where VPNs are blocked
- Creating a backup channel when primary connections fail
- Accessing internet in highly restrictive networks

## Additional Resources

- [Iodine Project Website](https://code.kryo.se/iodine/)
- [DNS Tunneling In-Depth](https://blogs.cisco.com/security/data-exfiltration-using-dns-tunneling-techniques) 