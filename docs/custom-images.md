---
title: Building Custom Images
nav_order: 3
description: "Create customized Haxinator images with pi-gen and Docker"
---

# Building Custom Images

Haxinator 2000 can be built using two methods:
1. Traditional pi-gen build (requires Debian/Ubuntu system)
2. Docker-based build (supports any system with Docker)

## Docker Build Method (Recommended)

The Docker build method is recommended as it:
- Works on any system that supports Docker (Linux, macOS, Windows)
- Provides a consistent build environment
- Requires minimal system dependencies
- Automatically handles cross-architecture compilation

### System Requirements

For Docker-based builds, you'll need:
- **CPU**: 2+ cores recommended
- **RAM**: Minimum 4GB, 8GB recommended
- **Disk Space**: 
  - 20GB free space for build environment
  - Additional 10GB for Docker images and cache
  - SSD recommended for better performance
- **Network**: Good internet connection for package downloads
- **Docker**: Version 20.10.0 or newer

### Docker Prerequisites

#### On Ubuntu/Debian:
```bash
# Update system packages
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y apt-transport-https ca-certificates curl software-properties-common

# Add Docker's official GPG key
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg

# Add Docker repository
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu noble stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Update package list and install Docker
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io

# Add your user to the docker group
sudo usermod -aG docker $USER
newgrp docker

# Verify installation
docker --version
```

#### On macOS:
1. Download and install [Docker Desktop for Mac](https://www.docker.com/products/docker-desktop/)
2. Start Docker Desktop
3. In Docker Desktop Preferences:
   - **CPUs**: Allocate at least 2 cores
   - **Memory**: Set to at least 4GB
   - **Disk**: Ensure at least 30GB is available
4. Verify installation: `docker --version`

#### On Windows:
1. Install [WSL2](https://learn.microsoft.com/en-us/windows/wsl/install)
2. Download and install [Docker Desktop for Windows](https://www.docker.com/products/docker-desktop/)
3. Enable WSL2 backend in Docker Desktop settings
4. In Docker Desktop Settings:
   - **WSL Integration**: Enable for your Linux distribution
   - **Resources**: Allocate similar resources as macOS
5. Verify installation: `docker --version`

### Building with Docker

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

4. Run the Docker build script:
   ```bash
   ./docker-build.sh
   ```

5. The finished image will be in the `output/` directory

### Docker Build Options

The build script supports several options:

#### APT Proxy Configuration
Using an APT proxy can significantly speed up builds by caching package downloads:

1. **Using an existing proxy**:
   ```bash
   APT_PROXY="http://your-proxy:3142" ./docker-build.sh
   ```

2. **Setting up a new proxy** (Ubuntu/Debian):
   ```bash
   # Install apt-cacher-ng
   sudo apt install apt-cacher-ng

   # Start the service
   sudo systemctl start apt-cacher-ng

   # Get your IP address
   IP=$(ip route get 1 | awk '{print $7;exit}')

   # Use the proxy in your build
   APT_PROXY="http://$IP:3142" ./docker-build.sh
   ```

### Docker Troubleshooting

#### Common Issues and Solutions

1. **Permission Issues**:
   - **Error**: "permission denied while trying to connect to the Docker daemon socket"
   - **Solution**: 
     ```bash
     sudo usermod -aG docker $USER
     newgrp docker
     # Or log out and back in
     ```

2. **Disk Space Issues**:
   - **Error**: "no space left on device"
   - **Solutions**:
     ```bash
     # Clean up unused Docker resources
     docker system prune -a --volumes

     # Check Docker disk usage
     docker system df

     # Clean up old images
     docker image prune -a
     ```

3. **Network Issues**:
   - **Error**: "failed to download packages" or "network timeout"
   - **Solutions**:
     - Check internet connection
     - Try using APT proxy
     - Verify DNS resolution:
       ```bash
       docker run --rm ubuntu nslookup debian.org
       ```

4. **Resource Constraints**:
   - **Error**: Build process crashes or hangs
   - **Solution**: Increase Docker resource limits in Docker Desktop settings

5. **Build Failures**:
   - **Error**: Build exits with non-zero status
   - **Solutions**:
     - Check build logs in the output directory
     - Ensure all prerequisites are installed
     - Try cleaning Docker cache:
       ```bash
       docker builder prune -a
       ```

#### Debugging Tips

1. **View build logs**:
   ```bash
   # Check Docker container logs
   docker ps -a  # Get container ID
   docker logs <container-id>
   ```

2. **Interactive debugging**:
   ```bash
   # Start an interactive shell in a new container
   docker run -it haxinator-builder:latest /bin/bash
   ```

3. **Check resource usage**:
   ```bash
   # Monitor Docker resource usage
   docker stats
   ```

## Traditional Pi-gen Build Method

If you prefer building without Docker, you can use the traditional pi-gen method.

### Pi-gen Prerequisites

You'll need:
- A Debian-based system (Debian Buster, Ubuntu 18.04+, or Raspberry Pi OS)
- At least 8GB of free disk space
- Required dependencies:

```bash
sudo apt-get update
sudo apt-get install -y coreutils quilt parted qemu-user-static debootstrap zerofree zip \
                        dosfstools bsdtar libcap2-bin grep rsync xz-utils file git curl bc
```

### Building with Pi-gen

1. Clone the repository:
   ```bash
   git clone https://github.com/morehax/haxinator
   cd haxinator
   ```

2. Create your secrets file:
   ```bash
   cp haxinator-pigen-overlay/stage2/02-custom-config/root_files/files/env-secrets.template ~/.haxinator-secrets
   ```

3. Run the build script:
   ```bash
   sudo ./01-build-script.sh
   ```

4. The finished image will be in `pi-gen/deploy/`

## Customizing Your Build

### Adding Custom Packages

To add additional packages to your build:

1. Create a custom overlay:
   ```bash
   cp -r haxinator-pigen-overlay haxinator-pigen-overlay-custom
   ```

2. Edit the package list:
   ```bash
   nano haxinator-pigen-overlay-custom/stage2/02-custom-config/00-packages
   ```

3. Update your build script to use the custom overlay:
   ```bash
   nano 03-overlay.sh
   ```

### Adding Custom Scripts

To add scripts that run during first boot:

1. Add your script to the overlay:
   ```bash
   nano haxinator-pigen-overlay-custom/stage2/02-custom-config/root_files/etc/rc.local
   ```

2. Make it executable:
   ```bash
   chmod +x haxinator-pigen-overlay-custom/stage2/02-custom-config/root_files/etc/rc.local
   ```

### Modifying Web UI

To customize the web interface:

1. Copy the UI files:
   ```bash
   cp -r haxinator-pigen-overlay/stage2/02-custom-config/root_files/html haxinator-pigen-overlay-custom/stage2/02-custom-config/root_files/html-custom
   ```

2. Make your modifications
3. Update the overlay scripts to use your custom UI

## Build Output

Both build methods produce:
- A compressed image file (~1.1GB)
- Build logs for troubleshooting
- A ready-to-flash Raspberry Pi image

The image will be in:
- Docker build: `output/` directory
- Pi-gen build: `pi-gen/deploy/` directory

## Understanding pi-gen

Pi-gen is a set of scripts that builds Raspberry Pi OS images in stages. Each stage builds upon the previous one:

- **Stage 0**: Bootstrap - Creates a basic filesystem
- **Stage 1**: Core System - Makes the system bootable
- **Stage 2**: Lite System - Creates a minimal usable system

Haxinator 2000 uses these stages and adds custom configurations and packages to create a specialized penetration testing distribution.

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