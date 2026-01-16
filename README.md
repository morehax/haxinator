# Haxinator 2000 🚀

Your Swiss Army Knife for Internet Tethering, VPN and network management, Tunneling, and otherwise getting online.

## Features

- **ICMP Tunneling**: Bypass firewalls using Hans VPN
- **DNS Tunneling**: Sneak through DNS with Iodine
- **OpenVPN Support**: Traditional VPN when you need it
- **WiFi & AP**: Connect to networks or create your own
- **Bluetooth Serial**: Easy serial access with auto-pairing

## Typical Use Cases

You're at your hotel, just got in the room. You start connecting your devices to the hotel wifi network, but qiuckly get a message that there is a 2 device limit. You have 14 devices. Fear not, Haxinator to the rescue. By adding another wifi dongle to the haxinator you can connect to the hotel network with one wifi interface, and set up a wifi hotspot on the other interface. NetworkManager will handle all the routing and allow all your 14 devices to connect to your local hotspot, and then apear to the hotel network as a single "device". Victory!

You're at the airport, and you need to get internet access for something urgent. There's a captive portal network which allows DNS or ICMP outbound traffic. Nuf said.

## Quick Start

1. Clone this repository
2. Create your secrets file:
   ```bash
   cp haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/env-secrets.template ~/.haxinator-secrets
   ```
3. Edit `~/.haxinator-secrets` with your configuration
4. Run the build script:
   ```bash
   sudo ./01-build-script.sh
   ```

## Working with Images

### Mounting Images
After building, you can mount the image for inspection or modification:
```bash
sudo ./img-mount.sh
```
This script will:
- Find the most recent .img file in `./pi-gen/deploy`
- Mount both boot and root partitions
- Clean up any existing mounts automatically
- Display mount points for easy access

### Unmounting Images
When you're done working with the image:
```bash
sudo ./img-unmount.sh
```
This script will:
- Verify all configurations are present
- Safely unmount all partitions
- Detach loop devices
- Clean up mount points

### Mount Points
- Boot partition: `/mnt/boot`
- Root partition: `/mnt/root`

### Verification
The unmount script performs several checks:
- Network configurations
- Service files
- SSL certificates
- PHP settings
- Bluetooth configuration
- Web server setup

## Configuration Guide

The Haxinator 2000 uses a secrets file to configure its various services. Create this file by copying the template:

```bash
cp haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/env-secrets.template ~/.haxinator-secrets
```

### Service Requirements

#### Bluetooth Auto-Pairing
```bash
BLUETOOTH_MAC=XX:XX:XX:XX:XX:XX
```
- Set this to your device's MAC address for automatic discovery and pairing
- Enables easy serial access over Bluetooth
- Leave as XX:XX:XX:XX:XX:XX to disable auto-pairing

#### OpenVPN Connection
```bash
VPN_USER=your_username
VPN_PASS=your_password
```
- Both values required for OpenVPN setup
- Leave empty to disable OpenVPN

#### Iodine DNS Tunnel
```bash
IODINE_TOPDOMAIN=your_domain
IODINE_NAMESERVER=your_nameserver_ip
IODINE_PASS=your_password
IODINE_MTU=1400
IODINE_LAZY=true
IODINE_INTERVAL=4
```
- Required values: `IODINE_TOPDOMAIN`, `IODINE_NAMESERVER`, `IODINE_PASS`
- Optional values have defaults shown above
- Leave required values empty to disable Iodine

#### Hans VPN (ICMP Tunnel)
```bash
HANS_SERVER=your_server_ip
HANS_PASSWORD=your_password
```
- Both values required for Hans VPN setup
- Leave empty to disable Hans VPN

#### WiFi Access Point
```bash
WIFI_SSID="Haxinator 2000"
WIFI_PASSWORD="ChangeMe"
```
- Both values required for AP setup
- Default values shown above
- Leave empty to disable AP mode

### How It Works

1. The build process reads your `~/.haxinator-secrets` file
2. Creates necessary configuration files in the image
3. On first boot, only services with complete configuration are enabled
4. Services with missing or template values are automatically disabled

### Security Notes

- Keep your `~/.haxinator-secrets` file secure (permissions 600)
- Never commit this file to version control
- The template file is safe to commit as it contains no real values

## Troubleshooting

### Common Issues

1. **Service Not Starting**
   - Check if all required values are set in `~/.haxinator-secrets`
   - Verify the service is enabled in the image

2. **Bluetooth Not Pairing**
   - Ensure `BLUETOOTH_MAC` is set to your device's address
   - Check if Bluetooth service is enabled

3. **VPN Connections Failing**
   - Verify all required credentials are set
   - Check network connectivity to VPN servers

## Contributing

Found a bug? Want to add a feature? Pull requests are welcome!

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Disclaimer

**Haxinator 2000 was created as a personal pet project to manage network connections while traveling.** It should be treated as a hobbyist tool rather than production-grade software. While it works for my use cases, your mileage may vary.

This tool is for educational and authorized testing purposes only. Always respect network policies and obtain proper authorization before testing.

## Support

Having issues? Check the troubleshooting guide or open an issue on GitHub.
