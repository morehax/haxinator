#!/bin/bash
# nm-webui installer script
# Run after copying files to /opt/nm-webui

set -e

echo "=== Building nm-webui ==="
cd /opt/nm-webui
go build -o nm-webui ./cmd/nm-webui

echo "=== Installing binary ==="
cp nm-webui /usr/local/bin/nm-webui
chmod +x /usr/local/bin/nm-webui

echo "=== Installing systemd service ==="
cat > /etc/systemd/system/nm-webui.service << 'EOF'
[Unit]
Description=NetworkManager Web UI
After=network.target NetworkManager.service
Wants=NetworkManager.service

[Service]
Type=simple
ExecStart=/usr/local/bin/nm-webui --listen 192.168.8.1:8080 --no-auth
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
EOF

echo "=== Starting service ==="
systemctl daemon-reload
systemctl enable nm-webui
systemctl restart nm-webui

echo "=== Done! ==="
