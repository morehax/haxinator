---
title: Frequently Asked Questions
nav_order: 6
description: "Common questions and answers about Haxinator 2000"
---

# Frequently Asked Questions

## Getting Started

### Where can I download Haxinator images?

Pre-built images are available from our build server at [build.hax.me/images/](https://build.hax.me/images/). Each image includes:
- SHA-1 checksum for verification
- Changelog of recent updates
- Build date and version information

While you can still build your own image using the provided scripts, downloading a pre-built image is the fastest way to get started.

## General Questions

### What is Haxinator 2000?

Haxinator 2000 is a specialized Raspberry Pi image that transforms your Pi into a powerful network penetration testing and VPN gateway device. It includes tools for creating various types of tunnels (ICMP, DNS, OpenVPN) and provides a user-friendly web interface for configuration.

### What is the current development status?

Haxinator 2000 is currently in early development. While the core functionality is working, you may encounter bugs or incomplete features. We're actively developing and improving the project, and welcome community contributions.

### Are pre-built images available?

Currently, pre-built images are available only by request. We recommend building your own image using the provided scripts, as this ensures you have the latest version with all security updates. If you need a pre-built image, please open an issue on our GitHub repository with the subject "Image Request".

### Which Raspberry Pi models are supported?

Haxinator 2000 is optimized for and tested on:
- Raspberry Pi Zero W2
- Raspberry Pi 5

It may work on other Pi models with WiFi capabilities, but these are the officially supported models.

### Is this legal to use?

Haxinator 2000 is designed for legitimate security testing, educational purposes, and privacy protection. However, you must:
- Only use it on networks you own or have explicit permission to test
- Comply with all local laws and regulations
- Respect network policies and terms of service

Using this tool for unauthorized access to networks is illegal and unethical.

## Installation and Setup

### Why is my build failing?

Common build issues include:
- Insufficient disk space (need at least 8GB free)
- Missing dependencies
- Not running build commands with sudo
- Issues with qemu-user-static for cross-compilation

Check the build logs in `pi-gen/work/` for specific error messages.

### How do I access the web interface?

1. Connect to the "Haxinator 2000" WiFi network (default password: "ChangeMe")
2. Open a web browser and go to http://haxinator.local or http://192.168.8.1
3. Log in with the default credentials (username: `admin`, password: `haxinator`)

### How do I change the default WiFi SSID and password?

Edit the ~/.haxinator-secrets file and update these values:
```
WIFI_SSID="Your-Custom-Name"
WIFI_PASSWORD="YourSecurePassword"
```
Then rebuild the image or apply the changes directly via the web interface.

## Tunneling Features

### How does the ICMP tunnel work?

The ICMP tunnel (via Hans VPN) works by encapsulating your data inside ICMP echo requests and replies (ping packets), which are rarely blocked by firewalls. This allows you to bypass network restrictions, but may have higher latency than regular connections.

### What are the DNS tunneling bandwidth limitations?

DNS tunneling (via Iodine) is typically slower than other methods, with realistic throughput between 3-10 KB/s. It's best used for text-based applications like SSH or basic web browsing, not for streaming or large downloads.

### Can I use multiple tunneling methods simultaneously?

Yes, Haxinator supports running multiple tunneling protocols at the same time. You can prioritize different types of traffic through different tunnels using the web interface.

## Troubleshooting

### My WiFi network isn't showing up in the scan

This can happen for several reasons:
- The network is using a hidden SSID
- The signal is too weak to detect
- There's interference on the wireless channel

Try moving closer to the access point or manually adding the network details.

### The tunnel connection keeps dropping

This could be due to:
- Unstable internet connection
- Network detection and filtering of tunneled traffic
- Timeout settings on firewalls
- Bandwidth limitations

Try adjusting the tunnel parameters (MTU size, keepalive intervals) in the advanced settings.

### I forgot my web interface password

If you've forgotten your web interface login credentials, you'll need to:
1. Connect to the Raspberry Pi via SSH or directly with a keyboard/monitor
2. Use the reset script: `sudo /usr/local/bin/haxinator-reset-password.sh`
3. Follow the prompts to set a new password

## Advanced Usage

### Can I add my own custom tools?

Yes, you can customize the Haxinator image by:
1. Modifying the package list in the pi-gen overlay
2. Adding custom scripts to the build process
3. Mounting the image and manually adding tools

See [Building Custom Images](custom-images.md) for detailed instructions.

### How can I contribute to the project?

We welcome contributions! You can:
- Report bugs by opening issues on GitHub
- Submit pull requests with improvements or new features
- Help improve documentation
- Share your custom configurations and use cases

Please read our [Contributing Guidelines](contributing.md) for more information. 