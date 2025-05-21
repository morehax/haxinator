#!/bin/bash

# Port scanner script
# Uses nmap to scan ports on a specified host

# Check if nmap is installed
if ! command -v nmap &> /dev/null; then
    echo "Error: nmap is not installed"
    exit 1
fi

# Get parameters
HOST=$1
RANGE=$2

# Validate parameters
if [ -z "$HOST" ] || [ -z "$RANGE" ]; then
    echo "Usage: $0 <host> <port-range>"
    echo "Example: $0 192.168.1.1 80-443"
    exit 1
fi

echo "Scanning $HOST for ports $RANGE..."
echo "----------------------------------------"

# Run nmap scan
# shellcheck disable=SC2086
nmap -p $RANGE $HOST

echo "----------------------------------------"
echo "Scan complete" 