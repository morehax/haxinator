# nm-webui

A minimal, low-RAM web interface for managing NetworkManager on Raspberry Pi.

## Features

- **WiFi Management**: Scan, connect, disconnect, forget networks
- **Hotspot**: Create WiFi hotspots with custom settings
- **Connection Sharing**: Share wired/USB connections via WiFi
- **Saved Connections**: Manage all NetworkManager profiles
- **Auto-connect Priority**: Set which networks to prefer
- **Real-time Status**: Live updates via polling
- **Mobile-friendly**: Responsive design for phone/tablet use
- **Low Memory**: Single ~10MB binary, no external dependencies

## Requirements

- Linux with NetworkManager installed
- `nmcli` command available
- Root access (for network configuration)

## Building

### For ARM64 (Raspberry Pi 3/4/5)

```bash
cd /opt/nm-webui
GOOS=linux GOARCH=arm64 go build -o nm-webui ./cmd/nm-webui
```

### For ARM32 (Raspberry Pi Zero/1/2)

```bash
GOOS=linux GOARCH=arm GOARM=6 go build -o nm-webui ./cmd/nm-webui
```

### For x86_64

```bash
GOOS=linux GOARCH=amd64 go build -o nm-webui ./cmd/nm-webui
```

## Installation

1. Build the binary:
   ```bash
   go build -o nm-webui ./cmd/nm-webui
   ```

2. Install:
   ```bash
   sudo cp nm-webui /usr/local/bin/
   sudo chmod +x /usr/local/bin/nm-webui
   ```

3. Create config directory:
   ```bash
   sudo mkdir -p /etc/nm-webui
   ```

4. Install systemd service:
   ```bash
   sudo cp nm-webui.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable nm-webui
   sudo systemctl start nm-webui
   ```

## Configuration

### Command Line Options

| Option | Default | Description |
|--------|---------|-------------|
| `--listen` | `127.0.0.1:8080` | Address to listen on |
| `--auth-file` | (none) | Path to credentials file (user:pass format) |

### Environment Variables

| Variable | Description |
|----------|-------------|
| `NM_WEBUI_USER` | HTTP Basic Auth username |
| `NM_WEBUI_PASS` | HTTP Basic Auth password |
| `VPN_<PROFILE>_USER` | OpenVPN username for profile |
| `VPN_<PROFILE>_PASS` | OpenVPN password for profile |

OpenVPN profiles are uploaded via the Configure tab and stored as separate files.
Each profile name is derived from the uploaded filename (without extension) and
must be alphanumeric with `-` or `_`. Example: `travel-vpn.ovpn` → `travel-vpn`.

If the OpenVPN profile contains `auth-user-pass`, the matching per-profile
credentials are required, e.g. for profile `openvpn`:

```
VPN_OPENVPN_USER=youruser
VPN_OPENVPN_PASS=yourpass
```

### Authentication

Credentials are loaded in this order:

1. Environment variables (`NM_WEBUI_USER`/`NM_WEBUI_PASS`)
2. Auth file (`--auth-file /etc/nm-webui/auth`)
3. Auto-generated (random password printed to stdout/journal)

Auth file format:
```
admin:yourpassword
```

## Usage

1. Start the service:
   ```bash
   sudo systemctl start nm-webui
   ```

2. View logs for the generated password:
   ```bash
   sudo journalctl -u nm-webui -f
   ```

3. Access the web interface at `http://your-pi:8080`

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/status` | System and network status |
| GET | `/api/wifi/scan?dev=wlan0` | Scan WiFi networks |
| POST | `/api/wifi/connect` | Connect to WiFi |
| POST | `/api/wifi/disconnect` | Disconnect from WiFi |
| POST | `/api/wifi/forget` | Forget saved network |
| POST | `/api/wifi/priority` | Set auto-connect priority |
| POST | `/api/wifi/hotspot` | Start/stop hotspot |
| GET | `/api/connections` | List saved connections |
| POST | `/api/connections/activate` | Activate connection |
| POST | `/api/connections/deactivate` | Deactivate connection |
| DELETE | `/api/connections/delete/{uuid}` | Delete connection |
| POST | `/api/connections/share` | Toggle connection sharing |
| GET | `/api/log` | Recent activity log |

## Security

- HTTP Basic Authentication required for all API endpoints
- Binds to localhost by default
- Constant-time password comparison
- Input validation and sanitization

## License

MIT
