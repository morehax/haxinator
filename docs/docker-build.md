---
title: Docker Build System
nav_order: 4
description: "Building Haxinator images using Docker"
---

# Docker Build System

This guide explains how to build Haxinator images using our Docker-based build system. The Docker build system ensures consistent and reproducible builds across different environments.

## Prerequisites

- Docker installed and running
- At least 8GB RAM available
- 20GB free disk space
- Git installed
- Ubuntu 24.04.2 LTS (recommended) or compatible system

## Build Environment Setup

### Local Build Environment

1. Install Docker:
   ```bash
   curl -fsSL https://get.docker.com | sh
   sudo usermod -aG docker $USER
   # Log out and back in for group changes to take effect
   ```

2. Clone the repository:
   ```bash
   git clone https://github.com/yourusername/haxinator
   cd haxinator
   ```

### Remote Docker Server

If using a remote Docker server:

1. Configure Docker context:
   ```bash
   docker context create remote --docker "host=tcp://172.16.188.130:2375"
   docker context use remote
   ```

2. Verify connection:
   ```bash
   docker info
   ```

## APT Proxy Configuration

For faster builds, you can use an APT proxy:

1. Set the proxy environment variable:
   ```bash
   export APT_PROXY="http://your-proxy:3142"
   ```

2. Or add to your `~/.haxinator-secrets`:
   ```bash
   APT_PROXY="http://your-proxy:3142"
   ```

## Build Process

### 1. Basic Build

The simplest way to build:

```bash
./docker-build.sh
```

This will:
- Build the Docker image if needed
- Mount necessary volumes
- Execute the build process
- Output the image to `./pi-gen/deploy/`

### 2. Advanced Build Options

```bash
# Build with specific options
./docker-build.sh --apt-proxy="http://your-proxy:3142" --remote

# Clean build (removes previous build artifacts)
./docker-build.sh --clean

# Build with custom configuration
./docker-build.sh --config=/path/to/config
```

### 3. Build Stages

The build process consists of several stages:

1. **Base System Setup**
   - Creates basic Raspberry Pi OS image
   - Configures essential packages

2. **Haxinator Configuration**
   - Applies our custom overlay
   - Configures services and tools
   - Sets up web interface

3. **Image Generation**
   - Creates final image file
   - Compresses and checksums

## Build Artifacts

After a successful build:

- Image file: `./pi-gen/deploy/image_yyyy-mm-dd-haxinator.img`
- SHA256 checksum: `./pi-gen/deploy/image_yyyy-mm-dd-haxinator.img.sha256`
- Build log: `./pi-gen/deploy/build.log`

## Troubleshooting

### Common Issues

1. **Out of Space**
   ```
   no space left on device
   ```
   - Ensure at least 20GB free space
   - Clean old builds: `./docker-build.sh --clean`

2. **Network Issues**
   ```
   Could not resolve 'archive.raspberrypi.org'
   ```
   - Check internet connectivity
   - Verify APT proxy if configured

3. **Permission Issues**
   ```
   permission denied
   ```
   - Ensure user is in docker group
   - Check file permissions in mount points

### Build Logs

Access build logs:
```bash
# View last build log
cat ./pi-gen/deploy/build.log

# Follow build in progress
tail -f ./pi-gen/deploy/build.log
```

## Security Considerations

1. **Docker Socket**
   - Don't expose Docker socket to network without TLS
   - Use Docker context for remote connections

2. **APT Proxy**
   - Use HTTPS proxy when possible
   - Restrict proxy access to trusted networks

3. **Build Artifacts**
   - Verify checksums after download
   - Don't share unencrypted images publicly

## Performance Tips

1. **Use APT Proxy**
   - Significantly reduces download time
   - Saves bandwidth on repeated builds

2. **Clean Builds**
   - Run `--clean` periodically
   - Prevents issues from stale cache

3. **Resource Allocation**
   - Allocate sufficient RAM to Docker
   - Use SSD for build directory if possible 