#!/bin/bash

# Strict mode
set -euo pipefail
IFS=$'\n\t'

# Script configuration
IMAGE_NAME="haxinator-builder"
IMAGE_TAG="latest"
OUTPUT_DIR="$(pwd)/output"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_NAME=$(basename "$0")

readonly IMAGE_NAME
readonly IMAGE_TAG
readonly OUTPUT_DIR
# shellcheck disable=SC2034
readonly SCRIPT_DIR
readonly SCRIPT_NAME



# Logging functions
log() { echo "==> $*" >&2; }
error() { echo "ERROR: $*" >&2; }
warn() { echo "WARNING: $*" >&2; }
die() { error "$*"; exit 1; }

# Verify docker is available
check_requirements() {
    if ! command -v docker >/dev/null 2>&1; then
        die "Docker is not installed or not in PATH"
    fi
    
    if ! docker info >/dev/null 2>&1; then
        die "Docker daemon is not running or current user lacks permissions"
    fi
}

# Cleanup function
cleanup() {
    local exit_code=$?
    log "Performing cleanup..."
    
    # Remove any existing container with our image
    if [ -n "${CONTAINER_ID:-}" ]; then
        log "Removing container..."
        docker rm -f "$CONTAINER_ID" 2>/dev/null || true
    fi
    
    # Remove our image
    log "Removing build image..."
    docker rmi "$IMAGE_NAME:$IMAGE_TAG" 2>/dev/null || true
    
    # Clean up any dangling images and build cache
    log "Cleaning up Docker system..."
    docker system prune -f >/dev/null 2>&1 || true
    
    log "Cleanup complete"
    exit $exit_code
}

# Handle script interruption
handle_error() {
    local line_no=$1
    local error_code=$2
    error "Error in ${SCRIPT_NAME} line ${line_no} (exit code: ${error_code})"
}

main() {
    # Set up error handling
    trap 'handle_error ${LINENO} $?' ERR
    trap cleanup EXIT
    
    # Verify requirements
    check_requirements

    # Create output directory if it doesn't exist
    mkdir -p "$OUTPUT_DIR"

    # Initial cleanup of any previous builds
    log "Cleaning up previous builds..."
    IMAGE_LIST="$(docker ps -a -q --filter ancestor="$IMAGE_NAME:$IMAGE_TAG")"
    docker rm -f "$IMAGE_LIST" 2>/dev/null || true
    docker rmi "$IMAGE_NAME:$IMAGE_TAG" 2>/dev/null || true

    # Display start message
    log "Starting Haxinator build with Docker..."

    # Always rebuild the Docker image
    log "Building Docker image..."
    if ! docker build -t "$IMAGE_NAME:$IMAGE_TAG" .; then
        die "Docker build failed"
    fi

    # Run the container
    log "Running build process in Docker container..."
    log "Output will be stored in: $OUTPUT_DIR"

    # Pass APT_PROXY if it exists in environment
    if [ -n "${APT_PROXY:-}" ]; then
        log "Using APT_PROXY: $APT_PROXY"
        PROXY_ARG="--build-arg APT_PROXY=$APT_PROXY"
        
        # Rebuild with proxy
        log "Rebuilding with APT_PROXY..."
        # shellcheck disable=SC2086
        if ! docker build $PROXY_ARG -t "$IMAGE_NAME:$IMAGE_TAG" .; then
            die "Docker build with proxy failed"
        fi
    fi

    # Get container ID - add --privileged flag to allow mounting filesystems 
    CONTAINER_ID=$(docker create --privileged ${APT_PROXY:+-e APT_PROXY="$APT_PROXY"} "$IMAGE_NAME:$IMAGE_TAG") || \
        die "Failed to create container"

    # Start the container
    if ! docker start -a "$CONTAINER_ID"; then
        die "Container failed to start or run"
    fi

    # Copy the result from the container
    log "Copying build artifacts from container..."
    if ! docker cp "$CONTAINER_ID":/haxinator/pi-gen/deploy/. "$OUTPUT_DIR"; then
        die "Failed to copy build artifacts"
    fi

    log "Build complete! Check $OUTPUT_DIR for the generated image."
}

# Execute main function
main "$@" 