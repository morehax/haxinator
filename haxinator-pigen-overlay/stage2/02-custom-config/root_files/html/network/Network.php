<?php
/**
 * Network operations using NetworkManager
 * 
 * Moved from /var/www/html/network.php to /var/www/html/network/Network.php
 */

// Include security framework
require_once __DIR__ . '/../security/bootstrap.php';

class Network
{
    public function deleteConnection($uuid)
    {
        // Validate input
        if (!InputValidator::uuid($uuid)) {
            return [
                'message' => '',
                'error' => "Invalid UUID format"
            ];
        }
        
        $output = [];
        $return_code = null;
        SecureCommand::execute("nmcli connection delete uuid %s", [$uuid], $output, $return_code);
        
        return [
            'message' => $return_code === 0
                ? "Connection <strong>" . htmlspecialchars($uuid) . "</strong> deleted successfully."
                : '',
            'error' => $return_code !== 0
                ? "Failed to delete connection <strong>" . htmlspecialchars($uuid) . "</strong>: " .
                  htmlspecialchars(implode("\n", $output))
                : ''
        ];
    }

    public function activateConnection($uuid)
    {
        // Validate input
        if (!InputValidator::uuid($uuid)) {
            return [
                'message' => '',
                'error' => "Invalid UUID format"
            ];
        }
        
        $output = [];
        $return_code = null;
        SecureCommand::execute("nmcli connection up uuid %s", [$uuid], $output, $return_code);
        
        return [
            'message' => $return_code === 0
                ? "Connection <strong>" . htmlspecialchars($uuid) . "</strong> activated successfully."
                : '',
            'error' => $return_code !== 0
                ? "Failed to activate connection <strong>" . htmlspecialchars($uuid) . "</strong>: " .
                  htmlspecialchars(implode("\n", $output))
                : ''
        ];
    }

    public function disconnectConnection($uuid)
    {
        // Validate input
        if (!InputValidator::uuid($uuid)) {
            return [
                'message' => '',
                'error' => "Invalid UUID format"
            ];
        }
        
        $output = [];
        $return_code = null;
        SecureCommand::execute("nmcli connection down uuid %s", [$uuid], $output, $return_code);
        
        return [
            'message' => $return_code === 0
                ? "Connection <strong>" . htmlspecialchars($uuid) . "</strong> disconnected successfully."
                : '',
            'error' => $return_code !== 0
                ? "Failed to disconnect connection <strong>" . htmlspecialchars($uuid) . "</strong>: " .
                  htmlspecialchars(implode("\n", $output))
                : ''
        ];
    }

    public function connectWifi($ssid, $password)
    {
        // Validate input
        if (!InputValidator::ssid($ssid)) {
            return [
                'message' => '',
                'error' => "Invalid SSID format"
            ];
        }
        
        // Password validation is optional (for open networks)
        if (!empty($password) && strlen($password) < 8) {
            return [
                'message' => '',
                'error' => "Invalid password (must be at least 8 characters)"
            ];
        }
        
        $output = [];
        $return_code = null;
        
        if ($password !== '') {
            SecureCommand::execute("nmcli dev wifi connect %s password %s ifname wlan0", [$ssid, $password], $output, $return_code);
        } else {
            SecureCommand::execute("nmcli dev wifi connect %s ifname wlan0", [$ssid], $output, $return_code);
        }
        
        if ($return_code === 0) {
            return [
                'message' => "Connecting to Wi-Fi network <strong>" . htmlspecialchars($ssid) . "</strong>...",
                'error' => ''
            ];
        } else {
            // Reset WiFi radio and rescan on failure
            SecureCommand::execute("nmcli radio wifi off && nmcli radio wifi on");
            SecureCommand::execute("nmcli dev wifi rescan");
            
            return [
                'error' => "Failed to connect to <strong>" . htmlspecialchars($ssid) . "</strong>: " .
                          htmlspecialchars(implode("\n", $output)),
                'message' => ''
            ];
        }
    }

    public function disconnectWifi($ssid)
    {
        // Validate input
        if (!InputValidator::ssid($ssid)) {
            return [
                'message' => '',
                'error' => "Invalid SSID format"
            ];
        }
        
        // First get the connection UUID for this SSID
        $uuid_output = [];
        $uuid_return_code = null;
        SecureCommand::execute("nmcli -t -f NAME,UUID connection show | grep '^%s:'", [$ssid], $uuid_output, $uuid_return_code);
        
        if ($uuid_return_code === 0 && !empty($uuid_output)) {
            // Extract UUID from the output (format is "name:uuid")
            $parts = explode(':', $uuid_output[0]);
            if (isset($parts[1])) {
                $uuid = trim($parts[1]);
                
                // Validate the extracted UUID
                if (!InputValidator::uuid($uuid)) {
                    return [
                        'message' => '',
                        'error' => "Invalid UUID format from network manager"
                    ];
                }
                
                $output = [];
                $return_code = null;
                SecureCommand::execute("nmcli connection down uuid %s", [$uuid], $output, $return_code);
                
                if ($return_code === 0) {
                    return [
                        'message' => "Disconnected from Wi-Fi network <strong>" . htmlspecialchars($ssid) . "</strong>",
                        'error' => ''
                    ];
                }
            }
        }
        
        // Fallback: try to disconnect the device directly
        $output = [];
        $return_code = null;
        SecureCommand::execute("nmcli device disconnect wlan0", [], $output, $return_code);
        
        return [
            'message' => $return_code === 0 
                ? "Disconnected from Wi-Fi network <strong>" . htmlspecialchars($ssid) . "</strong>"
                : '',
            'error' => $return_code !== 0
                ? "Failed to disconnect from <strong>" . htmlspecialchars($ssid) . "</strong>: " .
                  htmlspecialchars(implode("\n", $output))
                : ''
        ];
    }

    public function rescanWifi()
    {
        SecureCommand::execute("nmcli dev wifi rescan");
    }

    public function configureInterface($iface, $mode, $postData)
    {
        $connectionName = '';
        exec("nmcli -t -f NAME,DEVICE connection show", $connLines);
        foreach ($connLines as $line) {
            list($connName, $dev) = explode(':', $line);
            if ($dev === $iface) {
                $connectionName = $connName;
                break;
            }
        }
        if ($connectionName === '' && in_array($iface, ['eth0', 'usb0'])) {
            $connectionName = $iface;
            exec("nmcli connection add type ethernet ifname $iface con-name $iface autoconnect yes 2>&1");
        }
        if ($connectionName === '') {
            return [
                'error'   => "No connection profile found for interface " . strtoupper($iface) . ".",
                'message' => ''
            ];
        }

        $conn_arg = escapeshellarg($connectionName);
        if ($mode === 'dhcp') {
            exec("nmcli connection modify $conn_arg ipv4.method auto ipv4.addresses \"\" ipv4.gateway \"\" ipv4.dns \"\"");
            exec("nmcli connection down $conn_arg", $outDown, $retDown);
            exec("nmcli connection up $conn_arg", $outUp, $retUp);
            return [
                'message' => ($retDown === 0 && $retUp === 0)
                    ? strtoupper($iface) . " set to DHCP (automatic IP)."
                    : '',
                'error' => ($retDown !== 0 || $retUp !== 0)
                    ? "Failed to set DHCP on " . strtoupper($iface) . ": " .
                      htmlspecialchars(implode("\n", array_merge((array)$outDown, (array)$outUp)))
                    : ''
            ];
        } elseif ($mode === 'static') {
            // ── Grab & trim fields ─────────────────────────────────────────────
            $ip   = trim($postData['static_ip']   ?? '');
            $mask = trim($postData['static_mask'] ?? '');
            $gw   = trim($postData['static_gw']   ?? '');
            $dns  = trim($postData['static_dns']  ?? '');

            // ── Validate input ────────────────────────────────────────────────
            $errors = [];

            // IPv4 only for now
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = "Invalid IP address.";
            }

            // Accept "255.255.255.0"  _or_ CIDR "24"
            $prefix = null;
            if ($mask === '') {
                $errors[] = "Subnet mask required.";
            } elseif (ctype_digit($mask) && $mask >= 0 && $mask <= 32) {
                $prefix = (int)$mask;
            } elseif (filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $long = ip2long($mask);
                $prefix = 32 - (int)log((~$long & 0xFFFFFFFF) + 1, 2);
            } else {
                $errors[] = "Invalid subnet mask.";
            }

            if ($gw !== '' && !filter_var($gw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = "Invalid gateway address.";
            }

            if ($dns !== '') {
                foreach (explode(',', $dns) as $one) {
                    $one = trim($one);
                    if ($one !== '' && !filter_var($one, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $errors[] = "Invalid DNS server: $one";
                        break;
                    }
                }
            }

            // Bail out early on any errors
            if ($errors) {
                return [
                    'error'   => implode(' ', $errors),
                    'message' => ''
                ];
            }

            // ── Apply settings via nmcli ──────────────────────────────────────
            $addr_arg = escapeshellarg("$ip/$prefix");
            $gw_arg   = escapeshellarg($gw);
            $dns_arg  = escapeshellarg($dns);

            $cmdMod = "nmcli connection modify $conn_arg "
                    . "ipv4.method manual ipv4.addresses $addr_arg "
                    . "ipv4.gateway $gw_arg ipv4.dns $dns_arg";
            exec("$cmdMod 2>&1", $out1, $ret1);

            if ($ret1 === 0) {
                exec("nmcli connection down $conn_arg", $outDown, $retDown);
                exec("nmcli connection up   $conn_arg", $outUp, $retUp);
                if ($retDown === 0 && $retUp === 0) {
                    return [
                        'message' => strtoupper($iface) . " configured with static IP $ip.",
                        'error'   => ''
                    ];
                }
                $applyErr = htmlspecialchars(implode("\n", array_merge($outDown, $outUp)));
                return [
                    'error'   => "Failed to apply IP settings: $applyErr",
                    'message' => ''
                ];
            }

            return [
                'error'   => "Failed to configure static IP: " . htmlspecialchars(implode("\n", $out1)),
                'message' => ''
            ];
        }
    }

    public function getConnectionDetails($uuid)
    {
        $escaped = escapeshellarg($uuid);
        $details = [];
        $raw = [];

        // Get all connection info
        exec("nmcli -t connection show uuid $escaped", $out);
        foreach ($out as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                $raw[$key] = $value;
                // Map to user-friendly keys
                switch ($key) {
                    case 'connection.id':
                        $details['name'] = $value;
                        break;
                    case 'connection.autoconnect':
                        $details['autoconnect'] = $value;
                        break;
                    case 'connection.autoconnect-priority':
                        $details['priority'] = $value;
                        break;
                    case 'connection.type':
                        $details['type'] = $value;
                        break;
                    case 'connection.interface-name':
                        $details['interface'] = $value;
                        break;
                    case 'connection.mtu':
                        $details['mtu'] = $value;
                        break;
                    case 'ipv4.method':
                        $details['ipv4_method'] = $value;
                        break;
                    case 'ipv4.addresses':
                        $details['ipv4_address'] = $value;
                        break;
                    case 'ipv4.gateway':
                        $details['ipv4_gateway'] = $value;
                        break;
                    case 'ipv4.dns':
                        $details['ipv4_dns'] = $value;
                        break;
                    case 'ipv4.routes':
                        $details['ipv4_routes'] = $value;
                        break;
                    case 'ipv6.method':
                        $details['ipv6_method'] = $value;
                        break;
                    case 'ipv6.addresses':
                        $details['ipv6_address'] = $value;
                        break;
                    case 'ipv6.gateway':
                        $details['ipv6_gateway'] = $value;
                        break;
                    case 'ipv6.dns':
                        $details['ipv6_dns'] = $value;
                        break;
                    case 'ipv6.routes':
                        $details['ipv6_routes'] = $value;
                        break;
                    case '802-11-wireless-security.key-mgmt':
                        $details['wifi_security_type'] = $value;
                        break;
                    case '802-11-wireless-security.psk':
                        $details['wifi_password'] = $value;
                        break;
                    case '802-11-wireless-security.identity':
                        $details['wifi_identity'] = $value;
                        break;
                    case '802-11-wireless-security.ca-cert':
                        $details['wifi_ca_cert'] = $value;
                        break;
                    case '802-3-ethernet.cloned-mac-address':
                        $details['ethernet_mac_spoof'] = $value;
                        break;
                    case 'proxy.method':
                        $details['proxy_method'] = $value;
                        break;
                    case 'proxy.browser-only':
                        $details['proxy_browser_only'] = $value;
                        break;
                    // Add more mappings as needed
                }
            }
        }
        // Always include all raw fields for advanced tab
        $details['raw'] = $raw;
        return $details;
    }

    public function updateConnection($uuid, $settings)
    {
        $escaped = escapeshellarg($uuid);
        $commands = [];
        $errors = [];

        // Update general settings
        if (isset($settings['name'])) {
            $name = escapeshellarg($settings['name']);
            $commands[] = "nmcli connection modify uuid $escaped connection.id $name";
        }

        if (isset($settings['autoconnect'])) {
            $autoconnect = $settings['autoconnect'] ? 'yes' : 'no';
            $commands[] = "nmcli connection modify uuid $escaped connection.autoconnect $autoconnect";
        }

        if (isset($settings['priority'])) {
            $priority = intval($settings['priority']);
            $commands[] = "nmcli connection modify uuid $escaped connection.autoconnect-priority $priority";
        }

        // Update IPv4 settings
        if (isset($settings['ipv4_method'])) {
            if ($settings['ipv4_method'] === 'auto') {
                $commands[] = "nmcli connection modify uuid $escaped ipv4.method auto ipv4.addresses \"\" ipv4.gateway \"\" ipv4.dns \"\"";
            } else if ($settings['ipv4_method'] === 'manual') {
                $ip = escapeshellarg($settings['ipv4_address']);
                $gw = escapeshellarg($settings['ipv4_gateway']);
                $dns = escapeshellarg($settings['ipv4_dns']);
                $commands[] = "nmcli connection modify uuid $escaped ipv4.method manual ipv4.addresses $ip ipv4.gateway $gw ipv4.dns $dns";
            }
        }

        // Update WiFi security settings if applicable
        if (isset($settings['wifi_security_type'])) {
            $security_type = escapeshellarg($settings['wifi_security_type']);
            $commands[] = "nmcli connection modify uuid $escaped 802-11-wireless-security.key-mgmt $security_type";

            if (isset($settings['wifi_password'])) {
                $password = escapeshellarg($settings['wifi_password']);
                $commands[] = "nmcli connection modify uuid $escaped 802-11-wireless-security.psk $password";
            }
        }

        // Execute all commands
        foreach ($commands as $cmd) {
            exec($cmd . " 2>&1", $out, $ret);
            if ($ret !== 0) {
                $errors[] = implode("\n", $out);
            }
        }

        if (empty($errors)) {
            // Restart the connection if it's active
            exec("nmcli -t -f NAME,DEVICE connection show --active | grep -q \"$escaped\"", $out, $ret);
            if ($ret === 0) {
                exec("nmcli connection down uuid $escaped && nmcli connection up uuid $escaped 2>&1", $out, $ret);
                if ($ret !== 0) {
                    $errors[] = "Connection updated but failed to restart: " . implode("\n", $out);
                }
            }
        }

        return [
            'message' => empty($errors) ? "Connection updated successfully." : '',
            'error'   => !empty($errors) ? "Failed to update connection: " . implode("\n", $errors) : ''
        ];
    }
}
