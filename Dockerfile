FROM ubuntu:24.04

# Set non-interactive mode for apt
ENV DEBIAN_FRONTEND=noninteractive

# Set USER environment variable needed by the build script
ENV USER=root
ENV SUDO_USER=root

# Support for APT_PROXY environment variable
ARG APT_PROXY=""
RUN if [ -n "$APT_PROXY" ]; then \
    echo "Acquire::http::Proxy \"$APT_PROXY\";" > /etc/apt/apt.conf.d/01proxy; \
    fi

# Update and install dependencies
# hadolint ignore=DL3015,DL3008
RUN apt-get update && apt-get upgrade -y && \
    apt-get install -y coreutils quilt parted qemu-user-static debootstrap zerofree zip \
    dosfstools libarchive-tools libcap2-bin grep rsync xz-utils file git curl bc \
    gpg pigz xxd arch-test bmap-tools kmod locales locales-all udev && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Create larger tmp directory for package cache
RUN mkdir -p /var/cache/apt/archives && chmod 777 /var/cache/apt/archives && \
    mkdir -p /tmp-extra && chmod 777 /tmp-extra && \
    ln -s /tmp-extra /var/cache/apt/archives/partial

# Create working directory
WORKDIR /haxinator

# Copy build scripts
COPY 01-build-script.sh 02-clone-git.sh 03-overlay.sh common-functions.sh ./
COPY haxinator-pigen-overlay ./haxinator-pigen-overlay/

# Make scripts executable
RUN chmod +x 01-build-script.sh 02-clone-git.sh 03-overlay.sh

# Set entrypoint
ENTRYPOINT ["./01-build-script.sh"] 