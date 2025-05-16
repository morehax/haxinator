---
title: Introduction
nav_order: 1
description: "Home of the Hax – A Swiss Army Knife for Network Tunneling and Penetration Testing"
permalink: /
---

# Haxinator 2000

Welcome to the documentation for **Haxinator 2000** – your ultimate toolbox for network security testing and circumvention techniques.

{: .fs-6 .fw-300 }

[Get Started](#quick-start){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[View on GitHub](https://github.com/morehax/haxinator){: .btn .fs-5 .mb-4 .mb-md-0 }

---

> **Note:** Haxinator 2000 is currently in its early development stages. Pre-built images are available only by request. Users are encouraged to build their own images using the build scripts provided in this repository.
{: .warning }

> **Disclaimer:** Haxinator 2000 was created as a personal pet project to manage network connections while traveling. It should be treated as such — a hobbyist tool rather than production-grade software. While it works for my use cases, your mileage may vary. Feel free to adapt it to your needs!
{: .info }

## What is Haxinator?

Haxinator 2000 is a specialized open-source Raspberry Pi distribution that transforms your Pi Zero W2 or Pi5 into a powerful VPN gateway with advanced network tunneling capabilities.

## Key Features

- **ICMP Tunneling**: Bypass firewalls using Hans VPN
- **DNS Tunneling**: Sneak through DNS with Iodine  
- **OpenVPN Support**: Traditional VPN when you need it
- **WiFi & AP Functionality**: Connect to networks or create your own
- **Bluetooth Serial**: Easy serial access with automatic pairing
- **Web Interface**: Simple control panel for configuration
- **Easy Pre-configuration**: Place config files on the boot partition for immediate setup on first boot

## Interface Preview

<div class="screenshot-gallery">
  <div class="gallery-item">
    <a href="/assets/images/interface/haxinator-login.png">
      <img src="/assets/images/interface/haxinator-login.png" alt="Login Screen">
    </a>
    <p class="caption">Secure login screen</p>
  </div>
  <div class="gallery-item">
    <a href="/assets/images/interface/wifi-networks.png">
      <img src="/assets/images/interface/wifi-networks.png" alt="WiFi Networks">
    </a>
    <p class="caption">Available WiFi networks</p>
  </div>
  <div class="gallery-item">
    <a href="/assets/images/interface/saved-connections.png">
      <img src="/assets/images/interface/saved-connections.png" alt="Saved Connections">
    </a>
    <p class="caption">Saved network connections</p>
  </div>
  <div class="gallery-item">
    <a href="/assets/images/interface/haxinate-tab.png">
      <img src="/assets/images/interface/haxinate-tab.png" alt="ICMP Tunneling">
    </a>
    <p class="caption">ICMP tunneling controls</p>
  </div>
</div>

## Quick Start

1. Clone the repository and run the build script (pre-built images available by request only)
2. Flash the image to your SD card
3. Boot your Raspberry Pi
4. Connect to the Haxinator WiFi network
5. Visit http://haxinator.local to access the control panel

For more detailed instructions, see our [Quick Start Guide](quickstart.md).

## License

Everything here is MIT-licensed—fork away 🚀

```bash
# example install
git clone https://github.com/morehax/haxinator
cd haxinator
sudo ./01-build-script.sh
```
