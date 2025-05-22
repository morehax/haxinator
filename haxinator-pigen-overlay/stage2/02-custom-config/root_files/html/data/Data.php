<?php
/**
 * Data collection for Wi-Fi networks, saved connections, and interface status
 *
 * Moved from /var/www/html/data.php to /var/www/html/data/Data.php
 */

class Data
{
    public function getWifiList()
    {
        $wifi_list = [];
        putenv('LANG=en_US.UTF-8');
        setlocale(LC_ALL, 'en_US.UTF-8');

        // ask nmcli to back-slash-escape any colons it finds *inside* fields
        exec("LANG=en_US.UTF-8 nmcli -t --escape yes -f IN-USE,BSSID,SSID,SECURITY,SIGNAL dev wifi list", $lines);

        foreach ($lines as $line) {
            // at minimum: *,BSSID(6 parts),SSID,SECURITY,SIGNAL → 9 fields
            $parts = explode(':', $line);
            if (count($parts) < 9) {
                continue;
            }

            $in_use = ($parts[0] === '*');

            $bssid_raw = implode(':', array_slice($parts, 1, 6));
            $bssid     = str_replace(['\\:', '\\\\'], [':', '\\'], $bssid_raw);

            /* security & signal are always the final two fields.
               Everything in between belongs to the SSID and may itself
               contain "escaped" colons. */
            $signal   = intval(array_pop($parts)); // last field
            $security = array_pop($parts);         // now last field
            $ssid     = implode(':', array_slice($parts, 7)); // the rest

            // Undo nmcli's back-slash escaping
            $ssid     = str_replace(['\\:', '\\\\'], [':', '\\'], $ssid);
            $security = str_replace(['\\:', '\\\\'], [':', '\\'], $security);

            $wifi_list[] = [
                'in_use'   => $in_use,
                'bssid'    => $bssid,
                'ssid'     => $ssid ?: '(hidden)',
                'security' => $security ?: 'OPEN',
                'signal'   => $signal,
            ];
        }
        return $wifi_list;
    }

    public function getTopBarInterfaces()
    {
        $interfaces = [];
        
        // Get interface states from NetworkManager
        exec("nmcli -t -f DEVICE,TYPE,STATE,CONNECTION device", $nm_lines);
        $nm_info = [];
        foreach ($nm_lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 4) {
                $nm_info[$parts[0]] = [
                    'type' => $parts[1],
                    'state' => $parts[2],
                    'connection' => $parts[3]
                ];
            }
        }
        
        // Get IP addresses
        exec("ip -br addr", $ip_lines);
        foreach ($ip_lines as $line) {
            $parts = preg_split('/\s+/', trim($line), 4);
            if (count($parts) >= 2) {
                $iface = $parts[0];
                
                // Skip loopback and p2p interfaces
                if ($iface === 'lo' || strpos($iface, 'p2p') === 0) {
                    continue;
                }
                
                // Get IPv4 address
                $ipv4 = '';
                if (isset($parts[2])) {
                    if (preg_match('/(\d+\.\d+\.\d+\.\d+)\/\d+/', $parts[2], $matches)) {
                        $ipv4 = $matches[1];
                    }
                }
                
                // Determine interface type and icon
                $type = isset($nm_info[$iface]) ? $nm_info[$iface]['type'] : '';
                $icon = 'bi-hdd-network';  // default icon
                
                switch ($type) {
                    case 'wifi':
                        $icon = 'bi-wifi';
                        break;
                    case 'ethernet':
                        $icon = 'bi-ethernet';
                        break;
                    case 'tun':
                    case 'vpn':
                        $icon = 'bi-shield-lock';
                        break;
                    case 'bridge':
                        $icon = 'bi-diagram-3';
                        break;
                }
                
                // Special cases for interface names
                if (strpos($iface, 'tun') === 0) {
                    $icon = 'bi-shield-lock';
                    $type = 'tun';
                } elseif (strpos($iface, 'dns') === 0) {
                    $icon = 'bi-diagram-2';
                    $type = 'dns';
                }
                
                // Get connection state
                $state = isset($nm_info[$iface]) ? $nm_info[$iface]['state'] : 'unknown';
                $connected = ($state === 'connected' || $ipv4 !== '');
                
                $interfaces[] = [
                    'name' => $iface,
                    'type' => $type,
                    'icon' => $icon,
                    'ipv4' => $ipv4,
                    'connected' => $connected,
                    'state' => $state
                ];
            }
        }
        
        return $interfaces;
    }

    public function getSavedConnections()
    {
        $saved_connections = [];
        exec("nmcli -t -f NAME,UUID,TYPE,DEVICE connection show", $lines);
        foreach ($lines as $line) {
            $parts = explode(':', $line, 4);
            if (count($parts) === 4) {
                $saved_connections[] = [
                    'name'   => $parts[0],
                    'uuid'   => $parts[1],
                    'type'   => $parts[2],
                    'device' => $parts[3],
                ];
            }
        }
        return $saved_connections;
    }

    public function getInterfaceStatus($interfaces = null)
    {
        $iface_status = [];

        // Get all devices if no specific interfaces are requested
        if ($interfaces === null) {
            exec("nmcli -t -f DEVICE device status", $devices);
            $interfaces = array_map('trim', $devices);
        }

        foreach ($interfaces as $if) {
            $iface_status[$if] = [
                'state'          => 'down',
                'connection'     => '',
                'connection_type'=> '',
                'ip'             => '',
                'method'         => '',
                'mac'            => ''
            ];

            // Get device state and connection info
            $stateLine = [];
            exec("nmcli -t -f DEVICE,STATE,CONNECTION device status | grep ^$if:", $stateLine);
            if (!empty($stateLine)) {
                $fields = explode(':', $stateLine[0]);
                if (count($fields) >= 3) {
                    $iface_status[$if]['state'] = $fields[1];
                    if ($fields[2] !== "--" && !empty(trim($fields[2]))) {
                        $iface_status[$if]['connection'] = $fields[2];
                        // Get connection type for active connections
                        exec("nmcli -t -f connection.type connection show " . escapeshellarg($fields[2]), $typeLine);
                        if (!empty($typeLine)) {
                            // Remove the 'connection.type:' prefix from the output
                            $type = trim(substr($typeLine[0], strpos($typeLine[0], ':') + 1));
                            $iface_status[$if]['connection_type'] = $type;
                        }
                    }
                }
            }

            // Get IP address
            $ipLine = [];
            exec("nmcli -t -f IP4.ADDRESS dev show $if | grep IP4.ADDRESS", $ipLine);
            if (!empty($ipLine)) {
                $addr = explode(':', $ipLine[0]);
                if (isset($addr[1])) {
                    $iface_status[$if]['ip'] = explode('/', $addr[1])[0];
                }
            }

            // Get connection method (DHCP/Static)
            if ($iface_status[$if]['connection'] !== '') {
                $methLine = [];
                $conn_name_arg = escapeshellarg($iface_status[$if]['connection']);
                exec("nmcli -t -f ipv4.method connection show $conn_name_arg", $methLine);
                if (!empty($methLine)) {
                    $methodVal = trim($methLine[0]);
                    $iface_status[$if]['method'] = (strpos($methodVal, 'manual') !== false) ? 'Static' : 'DHCP';
                }
            }

            // Get MAC address
            $macLine = [];
            exec("nmcli -t -f GENERAL.HWADDR dev show $if | grep GENERAL.HWADDR", $macLine);
            if (!empty($macLine)) {
                $macParts = explode(':', $macLine[0], 2);
                if (isset($macParts[1])) {
                    $iface_status[$if]['mac'] = trim($macParts[1]);
                }
            }
        }
        return $iface_status;
    }

    public function getActiveConnectionUuids()
    {
        $active_uuids = [];
        exec("nmcli -t -f UUID connection show --active", $out);
        foreach ($out as $line) {
            $active_uuids[] = trim($line);
        }
        return $active_uuids;
    }
}
