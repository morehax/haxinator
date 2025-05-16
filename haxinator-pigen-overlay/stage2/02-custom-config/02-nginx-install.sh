#!/usr/bin/env bash
set -euo pipefail

# Source common functions
source "/common-functions.sh"

red_echo "==> Setting up nginx-light + php-fpm + openssl"

# TARGET_HOSTNAME is provided by pi-gen from config file
if [ -z "${TARGET_HOSTNAME:-}" ]; then
    red_echo "Error: TARGET_HOSTNAME not set by pi-gen"
    exit 1
fi
red_echo "==> Using hostname: ${TARGET_HOSTNAME}"

# Get PHP version from the directory name
PHP_VER=$(find /etc/php -maxdepth 1 -mindepth 1 -type d -printf '%f\n' | sort -V | tail -n1)
if [ -z "${PHP_VER}" ]; then
    red_echo "Error: Could not detect PHP version"
    exit 1
fi
red_echo "==> PHP version detected: ${PHP_VER}"

# Set all paths based on PHP version
PHP_POOL="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
PHP_INI="/etc/php/${PHP_VER}/fpm/php.ini"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

# Set SSL paths
SSL_DIR="/etc/ssl/local"
CRT="${SSL_DIR}/pi.crt"
KEY="${SSL_DIR}/pi.key"

# Generate SSL certificate if needed
if [[ ! -f "$CRT" ]]; then
    red_echo "==> Generating self-signed certificate …"
    install -d -m 700 "$SSL_DIR"
    openssl req -x509 -nodes -days 3650 -newkey rsa:4096 \
        -keyout "$KEY" -out "$CRT" \
        -subj "/CN=${TARGET_HOSTNAME}" \
        -addext "subjectAltName=DNS:${TARGET_HOSTNAME},IP:192.168.8.1"
    chmod 600 "$KEY"
fi

# Set all environment variables for template
export SERVER_NAME="_"
export CRT
export KEY
export PHP_SOCK
export TARGET_HOSTNAME

red_echo "==> Writing trim nginx.conf …"
cp /etc/nginx/nginx.conf /etc/nginx/nginx.conf.bak.$(date +%s)
cat >/etc/nginx/nginx.conf <<'NG'
user www-data;
worker_processes 1;
pid /run/nginx.pid;
events { worker_connections 128; }
http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;
    access_log off;
    error_log /var/log/nginx/error.log crit;
    sendfile on;
    keepalive_timeout 15;
    gzip on;
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
NG

red_echo "==> Creating single HTTPS site /var/www/html …"
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

# Copy and process the template
red_echo "==> Copying nginx template..."
if [[ ! -f "root_files/files/nginx/pi.template" ]]; then
    red_echo "ERROR: Template file root_files/files/nginx/pi.template does not exist!"
    exit 1
fi

if ! cp "root_files/files/nginx/pi.template" "/etc/nginx/sites-available/pi.template"; then
    red_echo "ERROR: Failed to copy nginx template!"
    exit 1
fi

# Process the template with environment variables
envsubst '${SERVER_NAME} ${CRT} ${KEY} ${PHP_SOCK} ${TARGET_HOSTNAME}' < /etc/nginx/sites-available/pi.template > /etc/nginx/sites-available/pi
rm /etc/nginx/sites-available/pi.template

ln -sf ../sites-available/pi /etc/nginx/sites-enabled/pi

red_echo "==> Tuning PHP-FPM pool (${PHP_POOL}) …"
sed -i 's/^pm\s*=.*/pm = ondemand/'                     "$PHP_POOL"
sed -i 's/^pm\.max_children\s*=.*/pm.max_children = 3/' "$PHP_POOL"
sed -i 's/^;*pm\.process_idle_timeout\s*=.*/pm.process_idle_timeout = 10s/' "$PHP_POOL"
sed -i 's/^;*pm\.max_requests\s*=.*/pm.max_requests = 200/' "$PHP_POOL"

red_echo "==> Setting memory_limit = 64M …"
sed -i 's/^memory_limit = .*/memory_limit = 64M/' "$PHP_INI"

red_echo "==> Disabling rarely-needed PHP extensions (xml*, opcache, pdo_mysql, mysqli) …"
phpdismod -v "$PHP_VER" -s fpm xmlreader xmlwriter xmlrpc opcache pdo_mysql mysqli >/dev/null || true

red_echo "==> Enabling Nginx & PHP-FPM …"
systemctl enable php${PHP_VER}-fpm
systemctl enable nginx
exit 0
