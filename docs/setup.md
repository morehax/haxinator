# Haxinator Setup Guide

This guide covers setting up your Haxinator device and configuring a tunneling server for bypassing restrictive networks.

---

## Part 1: Raspberry / Banana / Orange Pi Setup

### 1.1 Requirements

**Hardware:**
- Raspberry Pi 4B, Pi 5, or Pi Zero 2W (also supports Banana Pi M4 Zero, Orange Pi Zero 2W)
- MicroSD card (8GB minimum, 16GB+ recommended)
- **Data-capable USB cable** (not a charge-only cable)
- Computer with USB port

**Software:**
- [Raspberry Pi Imager](https://www.raspberrypi.com/software/)
- Haxinator image file

### 1.2 Flashing the Image

1. Download the latest Haxinator image
2. Insert your MicroSD card into your computer
3. Open Raspberry Pi Imager
4. Click **Choose OS** → **Use custom** → Select the Haxinator `.img` file
5. Click **Choose Storage** → Select your MicroSD card
6. Click **Write** and wait for completion

> **Note:** Do not use Raspberry Pi Imager's customization options - Haxinator has its own first-boot configuration.

### 1.3 First Boot

1. Insert the MicroSD card into your Raspberry Pi
2. Connect the Pi to your computer using a **USB data cable**:
   - **Pi Zero 2W:** Use the USB port labeled "USB" (not "PWR")
   - **Pi 4B/5:** Use a USB-C cable to the power port
3. Wait 60-90 seconds for the Pi to boot
4. A new network interface will appear on your computer (usually named "USB Ethernet" or similar)
5. Your computer will receive an IP address in the `192.168.8.x` range
6. If using a headless setup, SSH to root@192.168.8.1 with password 1234 and follow the prompts.
7. After (auto)setup, you can access via ssh hax@192.168.8.1, with password hax. 

### 1.4 Initial Access

**Web UI:**
- Open your browser and navigate to: `http://192.168.8.1:8080`
- Default credentials are auto-generated on first boot
- Check the system logs for the password: `journalctl -u nm-webui`

**SSH Access:**
```bash
ssh root@192.168.8.1
```
- Default password: `1234`
- You will be prompted to change this on first login

> **Important:** Change both the SSH password and web UI credentials before connecting to any network.

---

## Part 2: Tunneling Server Setup

### 2.1 Overview
The tunneling server is a remote VPS with unrestricted internet access that acts as your exit point. You'll need a cloud server from a provider like DigitalOcean, Linode, Vultr, or any VPS with a public IP address. Your Haxinator connects to this server through the restrictive network, and the server forwards your traffic to the internet.
Haxinator supports three methods to bypass restrictive networks:

| Protocol | Method | Port | Use Case |
|----------|--------|------|----------|
| **Iodine** | DNS tunneling | 53/UDP | Networks that allow DNS queries |
| **HANS** | ICMP tunneling | ICMP | Networks that allow ping |
| **OpenVPN** | VPN tunnel | 1194/UDP or custom | Full encryption, requires open port |

> **Note:** Iodine and HANS require you to set up your own server (covered below). OpenVPN can use a commercial VPN provider or your own server.

**Server Requirements (for Iodine/HANS):**
- Ubuntu 24.04 (or similar Linux distribution)
- Public IP address
- Root access
- For Iodine: A domain name with DNS control

> **Security Note:** Traffic through these tunnels is **NOT encrypted**. Always use a VPN or SSH tunnel on top for sensitive data.

### 2.2 Server Preparation

SSH into your server and install the required packages:

```bash
apt update
apt install -y iodine iptables-persistent netfilter-persistent build-essential git
```

When prompted by `iptables-persistent`, select **Yes** to save current rules.

**Compile and install HANS:**

```bash
cd /tmp
git clone https://github.com/friedrich/hans.git
cd hans
make
cp hans /usr/local/bin/
chmod +x /usr/local/bin/hans
cd ~
rm -rf /tmp/hans
```

**Enable IP forwarding:**

```bash
# Enable immediately
echo 1 > /proc/sys/net/ipv4/ip_forward

# Make persistent across reboots
echo "net.ipv4.ip_forward=1" > /etc/sysctl.d/60-ipv4-forward.conf
sysctl -p /etc/sysctl.d/60-ipv4-forward.conf
```

### 2.3 DNS Configuration for Iodine

Iodine requires a dedicated subdomain with an NS record pointing to your server.

**Example Setup:**

If your domain is `example.com` and your server IP is `203.0.113.50`:

1. Create an **A record** for your server:
   ```
   tunnel.example.com  →  A  →  203.0.113.50
   ```

2. Create an **NS record** for the tunnel subdomain:
   ```
   t.example.com  →  NS  →  tunnel.example.com
   ```

This tells DNS resolvers that `tunnel.example.com` is the authoritative nameserver for anything under `t.example.com`.

> **Note:** DNS propagation can take up to 48 hours. You can verify with: `dig NS t.example.com`

### 2.4 Iodine Server (DNS Tunnel)

Create the configuration file:

```bash
cat > /etc/default/iodine << 'EOF'
START_IODINED="true"
IODINED_ARGS="-c 172.16.42.1 t.example.com"
IODINED_PASSWORD="YourSecurePassword"
EOF
```

> **Replace** `t.example.com` with your tunnel subdomain and use a strong password.

Create the systemd service (if not already present):

```bash
cat > /usr/lib/systemd/system/iodined.service << 'EOF'
[Unit]
Description=Iodine DNS tunnel server
After=local-fs.target network.target
Documentation=man:iodined(8)

[Service]
EnvironmentFile=/etc/default/iodine
ExecStart=/usr/sbin/iodined -f -u iodine -t /run/iodine $IODINED_ARGS -P ${IODINED_PASSWORD}
Restart=on-failure
Type=simple

[Install]
WantedBy=multi-user.target
EOF
```

Enable and start the service:

```bash
systemctl daemon-reload
systemctl enable iodined
systemctl start iodined
systemctl status iodined
```

Verify the tunnel interface:

```bash
ip addr show dns0
# Should show: inet 172.16.42.1/27
```

### 2.5 HANS Server (ICMP Tunnel)

Create the configuration file:

```bash
cat > /etc/default/hans << 'EOF'
START_HANS="true"
HANS_ARGS="-s 172.16.48.1"
HANS_PASSWORD="YourSecurePassword"
EOF
```

Create the systemd service:

```bash
cat > /usr/lib/systemd/system/hans.service << 'EOF'
[Unit]
Description=Hans ICMP tunnel server
After=local-fs.target network.target
Documentation=man:hans(8)

[Service]
EnvironmentFile=/etc/default/hans
ExecStart=/usr/local/bin/hans -f $HANS_ARGS -p ${HANS_PASSWORD}
Restart=on-failure
Type=simple

[Install]
WantedBy=multi-user.target
EOF
```

Enable and start the service:

```bash
systemctl daemon-reload
systemctl enable hans
systemctl start hans
systemctl status hans
```

Verify the tunnel interface:

```bash
ip addr show tun0
# Should show: inet 172.16.48.1/24
```

### 2.6 Firewall & NAT Configuration

These rules enable NAT so tunnel clients can access the internet through your server.

> **Note:** Replace `eth0` with your server's internet-facing interface. Check with: `ip route show default`

```bash
# Allow DNS traffic for iodine
iptables -t filter -A INPUT -p udp --dport 53 -j ACCEPT
iptables -t filter -A INPUT -i dns0 -j ACCEPT
iptables -t filter -A OUTPUT -o dns0 -j ACCEPT

# NAT for iodine tunnel (172.16.42.0/24)
iptables -t nat -A POSTROUTING -s 172.16.42.0/24 ! -d 172.16.42.0/24 -o eth0 -j MASQUERADE

# Forward rules for iodine
iptables -t filter -A FORWARD -i dns0 -o eth0 -j ACCEPT
iptables -t filter -A FORWARD -i eth0 -o dns0 -j ACCEPT

# NAT for hans tunnel (172.16.48.0/24)
iptables -t nat -A POSTROUTING -s 172.16.48.0/24 ! -d 172.16.48.0/24 -o eth0 -j MASQUERADE

# Forward rules for hans
iptables -t filter -A FORWARD -i tun0 -o eth0 -j ACCEPT
iptables -t filter -A FORWARD -i eth0 -o tun0 -j ACCEPT

# Save rules
netfilter-persistent save
```

### 2.7 Verification

Run these commands to verify your setup:

```bash
# Check IP forwarding is enabled
cat /proc/sys/net/ipv4/ip_forward
# Expected: 1

# Check iodine is running
systemctl status iodined
ip addr show dns0

# Check hans is running
systemctl status hans
ip addr show tun0

# Check iptables NAT rules
iptables -t nat -L POSTROUTING -v -n

# Check iodine is listening on port 53
ss -lunp | grep :53
```

### 2.8 Troubleshooting

**Service won't start:**
```bash
journalctl -u iodined -n 50
journalctl -u hans -n 50
```

**No internet through tunnel:**
1. Verify IP forwarding: `cat /proc/sys/net/ipv4/ip_forward`
2. Check NAT rules: `iptables -t nat -L -v -n`
3. Check forward rules: `iptables -t filter -L FORWARD -v -n`
4. Verify client can ping gateway (172.16.42.1 or 172.16.48.1)

**DNS not resolving through iodine:**
- Verify NS record: `dig NS t.example.com`
- Check if port 53 is open: `nc -vz your-server-ip 53`

---

## Part 3: Connecting Haxinator to Your Server

### 3.1 The env-secrets File

Haxinator uses an `env-secrets` file to store tunnel credentials. Create a file with the following format:

```bash
# Iodine DNS tunnel configuration
IODINE_TOPDOMAIN=t.example.com
IODINE_NAMESERVER=203.0.113.50
IODINE_PASS=YourSecurePassword
IODINE_MTU=1400
IODINE_LAZY=true
IODINE_INTERVAL=4

# Hans ICMP tunnel configuration
HANS_SERVER=203.0.113.50
HANS_PASSWORD=YourSecurePassword

# OpenVPN credentials (only needed if .ovpn contains auth-user-pass)
# Format: VPN_<PROFILE>_USER and VPN_<PROFILE>_PASS
# Profile name is uppercased with hyphens/spaces converted to underscores
# Example: travel-vpn.ovpn → VPN_TRAVEL_VPN_USER / VPN_TRAVEL_VPN_PASS
VPN_MYVPN_USER=your_vpn_username
VPN_MYVPN_PASS=your_vpn_password
```

**Configuration options:**

| Variable | Description |
|----------|-------------|
| `IODINE_TOPDOMAIN` | Your tunnel subdomain (e.g., `t.example.com`) |
| `IODINE_NAMESERVER` | Your server's IP address |
| `IODINE_PASS` | Password set in `/etc/default/iodine` |
| `IODINE_MTU` | MTU size (1400 is a safe default) |
| `IODINE_LAZY` | Lazy mode for better performance |
| `IODINE_INTERVAL` | Polling interval in seconds |
| `HANS_SERVER` | Your server's IP address |
| `HANS_PASSWORD` | Password set in `/etc/default/hans` |
| `VPN_<PROFILE>_USER` | OpenVPN username for profile (only if config has `auth-user-pass`) |
| `VPN_<PROFILE>_PASS` | OpenVPN password for profile (only if config has `auth-user-pass`) |

### 3.2 OpenVPN Configuration

OpenVPN provides full encryption and is ideal when you have access to an open port. You can use:
- A commercial VPN provider (they will provide a `.ovpn` file)
- Your own OpenVPN server (see [OpenVPN documentation](https://openvpn.net/community-resources/how-to/))

**Haxinator supports two types of OpenVPN authentication:**

| Type | `.ovpn` contains | `env-secrets` needs |
|------|------------------|---------------------|
| **Certificate-based** | `<ca>`, `<cert>`, `<key>` | Nothing (just upload .ovpn) |
| **Username/Password** | `auth-user-pass` directive | `VPN_USER` and `VPN_PASS` |

**To configure OpenVPN on Haxinator:**

1. Obtain a `.ovpn` configuration file from your VPN provider or server
2. If your config contains `auth-user-pass`, add `VPN_USER` and `VPN_PASS` to your `env-secrets` file
3. Upload the `.ovpn` file (and `env-secrets` if needed) via the **Configure** tab
4. Apply the configuration and activate from the **Connections** tab

> **Note:** Unlike Iodine and HANS, OpenVPN traffic is encrypted. However, it requires an open port on the network you're connecting from.

### 3.3 Uploading Configuration

**Via Web UI (recommended):**

1. Open `http://192.168.8.1:8080`
2. Navigate to the **Configure** tab
3. Drag and drop your `env-secrets` file (or click to browse)
4. For OpenVPN: also upload your `.ovpn` file
5. The system will detect available configurations
6. Select which tunnels to enable and click **Apply Selected**

**Via SSH:**

```bash
scp env-secrets root@192.168.8.1:/etc/haxinator/env-secrets
```

### 3.4 Testing the Connection

1. Connect your Haxinator to a Wi-Fi network (via the **Wi-Fi** tab)
2. Go to the **Connections** tab
3. Activate your desired connection (Iodine, HANS, or OpenVPN)
4. Check the **Status** tab and run diagnostics:
   - Click **Check External IP** - should show your server's IP
   - Click **Run Ping** - should succeed through the tunnel

---

## Security Checklist

Before deploying your Haxinator:

- [ ] Changed default SSH password (`1234`)
- [ ] Set strong passwords for Iodine and HANS (not "Freedom")
- [ ] Updated `env-secrets` with your actual credentials
- [ ] Consider using OpenVPN on top of tunnels for encryption
- [ ] Keep your server and Haxinator updated

---

## Next Steps

- See [Web UI Guide](webui.md) for detailed information on each tab
- Configure OpenVPN for encrypted tunneling
- Set up SSH tunnels for additional security
