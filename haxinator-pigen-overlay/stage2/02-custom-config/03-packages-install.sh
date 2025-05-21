#!/usr/bin/env bash
set -euo pipefail
#shellcheck disable=SC2034
SCRIPT_DIR="$(dirname "$0")"

# Source common functions
# shellcheck source=common-functions.sh
source "/common-functions.sh"

red_echo "==> Installing interactive packages"

# Install required packages
DEBIAN_FRONTEND=noninteractive apt-get install -y tshark

# Download and build some tools
mkdir -p /root/tools

cd /root/tools

# Download and Build hans
red_echo "==> Building hans ICMP tunnel..."
git clone https://github.com/friedrich/hans.git
cd hans
make
mv hans /usr/local/bin/
cd ..

# Allow www-data to power off
red_echo "==> Allowing www-data to poweroff in sudoers"

# Add some stuff for the SSH tunnel web interface
# Allowing www-data all sorts of naughty things
echo "www-data ALL=(ALL) NOPASSWD: /sbin/poweroff, /usr/bin/ssh, /bin/kill, /usr/bin/pgrep, /usr/bin/ssh, /bin/kill, /usr/bin/pgrep, /usr/bin/ssh-keygen" | sudo tee -a /etc/sudoers
# And then make the web root haxable. Hopefully i'll remember to secure this one day.
chown -R www-data:www-data /var/www

# Lets stop auto downloading these for a while.

#git clone https://github.com/threat9/routersploit.git
#git clone https://github.com/kimocoder/wifite2.git
#git clone https://github.com/lwfinger/rtw88
#git clone https://github.com/kimocoder/OneShot.git

exit 0
