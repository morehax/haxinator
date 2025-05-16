---
title: Bluetooth Serial
parent: Features
nav_order: 5
description: "Automatic Bluetooth serial connection with Haxinator 2000"
---

# Bluetooth Serial with Auto-Pairing

Haxinator 2000 offers seamless Bluetooth serial connectivity with automatic device pairing for easy access to the console and shell.

## How Bluetooth Serial Works

The Bluetooth serial functionality enables:

1. Automatic pairing with a pre-configured device (e.g., your smartphone or laptop)
2. Creation of an RFCOMM serial port channel
3. Terminal access to the Haxinator over Bluetooth
4. Direct command line/shell access without requiring WiFi or wired connections

This provides a backup access method that works even when network connectivity is unavailable.

## Setting Up Bluetooth Auto-Pairing

### Configuration

1. Edit your secrets file (`~/.haxinator-secrets`) and set:
   ```
   BLUETOOTH_MAC=XX:XX:XX:XX:XX:XX
   ```
   Replace `XX:XX:XX:XX:XX:XX` with your device's MAC address.

2. Rebuild the image, or directly update the configuration file on your Haxinator.

3. Restart the bluetooth service:
   ```bash
   sudo systemctl restart bluetooth
   sudo systemctl restart bluetooth_pair
   ```

### Finding Your Device's MAC Address

To find your device's Bluetooth MAC address:

1. On your Haxinator, start Bluetooth scanning:
   ```bash
   sudo bluetoothctl
   [bluetooth]# scan on
   ```

2. Make your device discoverable (turn on Bluetooth and visibility)

3. Note the MAC address shown in the scan output, which looks like `XX:XX:XX:XX:XX:XX`

4. Exit bluetoothctl:
   ```
   [bluetooth]# quit
   ```

## Connecting to Haxinator via Bluetooth

### Android Devices

1. Install a terminal app that supports Bluetooth serial (like "Serial Bluetooth Terminal")
2. Enable Bluetooth on your Android device
3. The Haxinator will automatically pair with your device if the MAC address matches
4. In the terminal app, connect to the Haxinator's serial service
5. You should see a login prompt

### iOS Devices

1. Install a Bluetooth terminal app from the App Store
2. Enable Bluetooth on your iOS device
3. The Haxinator will automatically pair with your device if the MAC address matches
4. Connect to the Haxinator's serial service via the app
5. Log in as you would with SSH

### Laptops/Computers

1. Pair with the Haxinator (it should be broadcasting as "Haxinator Serial")
2. The device will show up as a serial port:
   - Linux: `/dev/rfcomm0` or similar
   - macOS: `/dev/tty.Haxinator-SerialPort` or similar
   - Windows: `COM3` or another COM port
3. Use a terminal program to connect:
   ```bash
   # Linux/macOS example
   screen /dev/rfcomm0 115200
   
   # Or use minicom
   minicom -D /dev/rfcomm0
   ```

## Troubleshooting

### Service Status Check

If you're having trouble with the Bluetooth connection, check the status of relevant services:

```bash
sudo systemctl status bluetooth
sudo systemctl status bluetooth_pair
sudo systemctl status rfcomm
```

### Pairing Issues

If the device doesn't pair automatically:

1. Verify that the MAC address in configuration is correct 
2. Ensure the Bluetooth service is running:
   ```bash
   sudo systemctl restart bluetooth
   sudo hciconfig hci0 up
   ```
3. Check the Bluetooth logs:
   ```bash
   sudo journalctl -u bluetooth
   sudo journalctl -u bluetooth_pair
   ```

### Connection Drops

If your Bluetooth connection keeps dropping:

1. Check if the device is still paired:
   ```bash
   sudo bluetoothctl
   [bluetooth]# paired-devices
   ```

2. Ensure the RFCOMM service is properly running:
   ```bash
   sudo systemctl restart rfcomm
   ```

3. Verify power management isn't interfering:
   ```bash
   sudo btmgmt power on
   ```

### Reset Bluetooth System

As a last resort, you can reset the entire Bluetooth system:

```bash
sudo systemctl stop bluetooth
sudo rm -rf /var/lib/bluetooth/*
sudo systemctl start bluetooth
sudo systemctl restart bluetooth_pair
```

## Technical Details

Haxinator implements Bluetooth serial using:

- `bluetoothd`: Core Bluetooth daemon
- `bluetooth_pair.service`: Custom service for auto-pairing
- `rfcomm.service`: Service that binds the serial channel

The auto-pairing is handled by a dedicated service that monitors for the presence of the configured device and automatically initiates pairing.

The RFCOMM serial service is configured to use channel 1 with a baud rate of 115200.

By default, the system disables Bluetooth power management to maintain stable connections. 