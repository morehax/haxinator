#!/bin/bash
set -e

# Image name and tag
IMAGE_NAME="haxinator-builder"
IMAGE_TAG="latest"

# Directory for output
OUTPUT_DIR="$(pwd)/output"

# Create output directory if it doesn't exist
mkdir -p "$OUTPUT_DIR"

# Display start message
echo "==> Starting Haxinator build with Docker..."

# Always rebuild the Docker image
echo "==> Building Docker image..."
docker build -t "$IMAGE_NAME:$IMAGE_TAG" .

# Run the container
echo "==> Running build process in Docker container..."
echo "==> Output will be stored in: $OUTPUT_DIR"

# Pass APT_PROXY if it exists in environment
if [ -n "$APT_PROXY" ]; then
    echo "==> Using APT_PROXY: $APT_PROXY"
    PROXY_ARG="--build-arg APT_PROXY=$APT_PROXY"
    
    # Rebuild with proxy
    echo "==> Rebuilding with APT_PROXY..."
    docker build $PROXY_ARG -t "$IMAGE_NAME:$IMAGE_TAG" .
fi

# Get container ID - add --privileged flag to allow mounting filesystems 
CONTAINER_ID=$(docker create --privileged ${APT_PROXY:+-e APT_PROXY="$APT_PROXY"} "$IMAGE_NAME:$IMAGE_TAG")

# Start the container
docker start -a $CONTAINER_ID

# Copy the result from the container
echo "==> Copying build artifacts from container..."
docker cp $CONTAINER_ID:/haxinator/pi-gen/deploy/. "$OUTPUT_DIR"

# Remove the container
docker rm $CONTAINER_ID

echo "==> Build complete! Check $OUTPUT_DIR for the generated image." 