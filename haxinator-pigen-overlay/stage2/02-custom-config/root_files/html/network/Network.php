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
        // Validate interface name
        if (!InputValidator::interface($iface)) {
            return [
                'error' => "Invalid interface name",
                'message' => ''
            ];
        }
        
        $connectionName = '';
        $connLines = [];
        SecureCommand::executeWithOutput("nmcli -t -f NAME,DEVICE connection show", $connLines);
        foreach ($connLines as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 2) {
                list($connName, $dev) = $parts;
                if ($dev === $iface) {
                    $connectionName = $connName;
                    break;
                }
            }
        }
        if ($connectionName === '' && in_array($iface, ['eth0', 'usb0'])) {
            $connectionName = $iface;
            $output = [];
            $return_code = null;
            SecureCommand::execute("nmcli connection add type ethernet ifname %s con-name %s autoconnect yes", 
                                 [$iface, $iface], $output, $return_code);
            if ($return_code !== 0) {
                return [
                    'error' => "Failed to create connection profile: " . implode("\n", $output),
                    'message' => ''
                ];
            }
        }
        if ($connectionName === '') {
            return [
                'error'   => "No connection profile found for interface " . strtoupper($iface) . ".",
                'message' => ''
            ];
        }

        if ($mode === 'dhcp') {
            $output1 = [];
            $return_code1 = null;
            SecureCommand::execute("nmcli connection modify %s ipv4.method auto ipv4.addresses \"\" ipv4.gateway \"\" ipv4.dns \"\"", 
                                 [$connectionName], $output1, $return_code1);
            
            $outDown = [];
            $retDown = null;
            SecureCommand::execute("nmcli connection down %s", [$connectionName], $outDown, $retDown);
            
            $outUp = [];
            $retUp = null;
            SecureCommand::execute("nmcli connection up %s", [$connectionName], $outUp, $retUp);
            
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
            $output1 = [];
            $return_code1 = null;
            SecureCommand::execute("nmcli connection modify %s ipv4.method manual ipv4.addresses %s ipv4.gateway %s ipv4.dns %s",
                                 [$connectionName, "$ip/$prefix", $gw, $dns], $output1, $return_code1);

            if ($return_code1 === 0) {
                $outDown = [];
                $retDown = null;
                SecureCommand::execute("nmcli connection down %s", [$connectionName], $outDown, $retDown);
                
                $outUp = [];
                $retUp = null;
                SecureCommand::execute("nmcli connection up %s", [$connectionName], $outUp, $retUp);
                
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
                'error'   => "Failed to configure static IP: " . htmlspecialchars(implode("\n", $output1)),
                'message' => ''
            ];
        }
    }

    public function getConnectionDetails($uuid)
    {
        // Validate UUID
        if (!InputValidator::uuid($uuid)) {
            return ['error' => 'Invalid UUID format'];
        }
        
        $details = [];
        $raw = [];
        $out = [];

        // Get all connection info
        SecureCommand::executeWithOutput("nmcli -t connection show uuid %s", [$uuid], $out);
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
        // Validate UUID
        if (!InputValidator::uuid($uuid)) {
            return [
                'message' => '',
                'error' => 'Invalid UUID format'
            ];
        }
        
        $errors = [];

        // Update general settings
        if (isset($settings['name'])) {
            $output = [];
            $return_code = null;
            SecureCommand::execute("nmcli connection modify uuid %s connection.id %s", 
                                 [$uuid, $settings['name']], $output, $return_code);
            if ($return_code !== 0) {
                $errors[] = implode("\n", $output);
            }
        }

        if (isset($settings['autoconnect'])) {
            $autoconnect = $settings['autoconnect'] ? 'yes' : 'no';
            $output = [];
            $return_code = null;
            SecureCommand::execute("nmcli connection modify uuid %s connection.autoconnect %s", 
                                 [$uuid, $autoconnect], $output, $return_code);
            if ($return_code !== 0) {
                $errors[] = implode("\n", $output);
            }
        }

        if (isset($settings['priority'])) {
            $priority = intval($settings['priority']);
            $output = [];
            $return_code = null;
            SecureCommand::execute("nmcli connection modify uuid %s connection.autoconnect-priority %s", 
                                 [$uuid, $priority], $output, $return_code);
            if ($return_code !== 0) {
                $errors[] = implode("\n", $output);
            }
        }

        // Update IPv4 settings
        if (isset($settings['ipv4_method'])) {
            if ($settings['ipv4_method'] === 'auto') {
                $output = [];
                $return_code = null;
                SecureCommand::execute("nmcli connection modify uuid %s ipv4.method auto ipv4.addresses \"\" ipv4.gateway \"\" ipv4.dns \"\"", 
                                     [$uuid], $output, $return_code);
                if ($return_code !== 0) {
                    $errors[] = implode("\n", $output);
                }
            } else if ($settings['ipv4_method'] === 'manual') {
                $output = [];
                $return_code = null;
                SecureCommand::execute("nmcli connection modify uuid %s ipv4.method manual ipv4.addresses %s ipv4.gateway %s ipv4.dns %s", 
                                     [$uuid, $settings['ipv4_address'], $settings['ipv4_gateway'], $settings['ipv4_dns']], 
                                     $output, $return_code);
                if ($return_code !== 0) {
                    $errors[] = implode("\n", $output);
                }
            }
        }

        // Update WiFi security settings if applicable
        if (isset($settings['wifi_security_type'])) {
            $output = [];
            $return_code = null;
            SecureCommand::execute("nmcli connection modify uuid %s 802-11-wireless-security.key-mgmt %s", 
                                 [$uuid, $settings['wifi_security_type']], $output, $return_code);
            if ($return_code !== 0) {
                $errors[] = implode("\n", $output);
            }

            if (isset($settings['wifi_password'])) {
                $output = [];
                $return_code = null;
                SecureCommand::execute("nmcli connection modify uuid %s 802-11-wireless-security.psk %s", 
                                     [$uuid, $settings['wifi_password']], $output, $return_code);
                if ($return_code !== 0) {
                    $errors[] = implode("\n", $output);
                }
            }
        }

        if (empty($errors)) {
            // Check if connection is active
            $active_output = [];
            $active_return_code = null;
            SecureCommand::execute("nmcli -t -f NAME,UUID connection show --active | grep -F %s", 
                                 [$uuid], $active_output, $active_return_code);
            
            if ($active_return_code === 0) {
                // Restart the connection
                $down_output = [];
                $down_return_code = null;
                SecureCommand::execute("nmcli connection down uuid %s", [$uuid], $down_output, $down_return_code);
                
                $up_output = [];
                $up_return_code = null;
                SecureCommand::execute("nmcli connection up uuid %s", [$uuid], $up_output, $up_return_code);
                
                if ($up_return_code !== 0) {
                    $errors[] = "Connection updated but failed to restart: " . implode("\n", $up_output);
                }
            }
        }

        return [
            'message' => empty($errors) ? "Connection updated successfully." : '',
            'error'   => !empty($errors) ? "Failed to update connection: " . implode("\n", $errors) : ''
        ];
    }
}
