---
title: Building Custom Images
nav_order: 3
description: "Create customized Haxinator images with pi-gen"
---

# Building Custom Images

Haxinator 2000 is built using pi-gen, the same tool used to create the official Raspberry Pi OS images. This page will guide you through customizing and building your own Haxinator images.

## Understanding pi-gen

Pi-gen is a set of scripts that builds Raspberry Pi OS images in stages. Each stage builds upon the previous one:

- **Stage 0**: Bootstrap - Creates a basic filesystem
- **Stage 1**: Core System - Makes the system bootable
- **Stage 2**: Lite System - Creates a minimal usable system

Haxinator 2000 uses these stages and adds custom configurations and packages to create a specialized penetration testing distribution.

## Prerequisites

To build custom images, you'll need:

- A Debian-based system (Debian Buster, Ubuntu 18.04+, or Raspberry Pi OS)
- At least 8GB of free disk space
- Required dependencies installed:

```bash
sudo apt-get update
sudo apt-get install -y coreutils quilt parted qemu-user-static debootstrap zerofree zip \
                        dosfstools bsdtar libcap2-bin grep rsync xz-utils file git curl bc
```

## Building a Standard Haxinator Image

1. Clone the Haxinator repository:
   ```bash
   git clone https://github.com/morehax/haxinator
   cd haxinator
   ```

2. Create your secrets file:
   ```bash
   cp haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/env-secrets.template ~/.haxinator-secrets
   ```

3. Customize your build by editing `~/.haxinator-secrets`

4. Run the build script:
   ```bash
   sudo ./01-build-script.sh
   ```

5. Wait for the build to complete (can take 30+ minutes depending on your system)

6. The finished image will be in the `pi-gen/deploy/` directory

## Customizing Your Build

### Adding Custom Packages

To add additional packages to your Haxinator build:

1. Create a copy of the overlay directory:
   ```bash
   cp -r haxinator-pigen-overlay haxinator-pigen-overlay-custom
   ```

2. Edit the package list in `haxinator-pigen-overlay-custom/stage2/02-custom-config/00-packages`:
   ```bash
   nano haxinator-pigen-overlay-custom/stage2/02-custom-config/00-packages
   ```

3. Add your desired packages to the list

4. Update your build script to use your custom overlay:
   ```bash
   nano 03-overlay.sh
   ```
   Change the source path to your custom overlay directory

### Adding Custom Scripts

To add custom scripts that run during the first boot:

1. Create a new script in the appropriate directory:
   ```bash
   nano haxinator-pigen-overlay-custom/stage2/02-custom-config/root_files/etc/rc.local
   ```

2. Make sure the script is executable:
   ```bash
   chmod +x haxinator-pigen-overlay-custom/stage2/02-custom-config/root_files/etc/rc.local
   ```

### Modifying Web UI

To customize the web interface:

1. Copy and modify the UI files:
   ```bash
   cp -r haxinator-pigen-overlay/stage2/02-custom-config/root_files/html haxinator-pigen-overlay/stage2/02-custom-config/root_files/html-custom
   ```

2. Edit the files as needed
   
3. Update the overlay scripts to copy your custom UI files

## Advanced Customization

For more advanced customization, you can:

1. Create additional stage directories
2. Add custom scripts following the pi-gen naming conventions
3. Modify existing configurations

For example, to add a custom script that runs during the build process:

```bash
mkdir -p haxinator-pigen-overlay-custom/stage2/99-custom
cat > haxinator-pigen-overlay-custom/stage2/99-custom/00-run.sh << 'EOF'
#!/bin/bash -e
echo "Running custom script!"
# Add your custom commands here
EOF
chmod +x haxinator-pigen-overlay-custom/stage2/99-custom/00-run.sh
```

## Troubleshooting

If you encounter issues during the build process:

- **Build fails with permission errors**: Make sure you're running with sudo
- **Build fails with "Can't chroot"**: Ensure qemu-user-static is properly installed
- **Out of disk space**: pi-gen requires significant disk space; ensure you have at least 8GB free

For more detailed troubleshooting, check the pi-gen output logs in the `work/` directory.

## Additional Resources

- [Official pi-gen documentation](https://github.com/RPi-Distro/pi-gen)
- [Raspberry Pi OS documentation](https://www.raspberrypi.org/documentation/) 