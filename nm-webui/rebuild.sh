#!/bin/bash

# Script to build and deploy nm-webui
# Run as root from /opt/nm-webui

set -e  # Exit on error

# Build the binary
go build -o nm-webui ./cmd/nm-webui

# Stop the service if running (ignores if not active)
systemctl stop nm-webui || true

# Copy the binary
cp nm-webui /usr/local/bin/nm-webui

# Set execute permissions
chmod +x /usr/local/bin/nm-webui

# Start the service
systemctl start nm-webui

echo "Deployment complete."
