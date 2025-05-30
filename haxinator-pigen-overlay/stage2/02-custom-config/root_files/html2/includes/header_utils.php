<?php
/**
 * Header Status Utilities
 * Functions for checking connectivity status and interface information
 */

declare(strict_types=1);

class HeaderUtils {
    
    /**
     * Get public IP address from ifconfig.me
     */
    public static function getPublicIp(): string|false {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://ifconfig.me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'curl');
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Check if curl_exec failed
        if ($result === false) {
            return false;
        }
        
        $ip = trim($result);
        if (filter_var($ip, FILTER_VALIDATE_IP) && $httpcode === 200) {
            return $ip;
        }
        return false;
    }

    /**
     * Check if DNS can resolve google.com
     */
    public static function dnsResolvesGoogle(): bool {
        $output = [];
        $retval = -1;
        exec('dig +short +time=2 google.com 2>/dev/null', $output, $retval);
        
        foreach ($output as $line) {
            if (filter_var(trim($line), FILTER_VALIDATE_IP)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if we can ping Google's DNS server
     */
    public static function pingGoogle(): bool {
        $output = [];
        $retval = -1;
        exec('ping -c 1 -W 2 8.8.8.8 2>/dev/null', $output, $retval);
        return ($retval === 0);
    }

    /**
     * Check if SSH tunnels are active (proxy status)
     */
    public static function getSshTunnelStatus(): bool {
        $tunnelsFile = __DIR__ . '/../data/tunnels.json';
        
        if (!is_file($tunnelsFile)) {
            return false;
        }
        
        $tunnels = json_decode(file_get_contents($tunnelsFile), true);
        if (!is_array($tunnels) || empty($tunnels)) {
            return false;
        }
        
        // Check if any tunnels have active PIDs
        foreach ($tunnels as $tunnel) {
            if (isset($tunnel['pid']) && posix_kill((int)$tunnel['pid'], 0)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get network interface information for the interface bar
     */
    public static function getNetworkInterfaces(): array {
        $interfaces = [];
        
        // Get interface states from NetworkManager
        $nm_info = [];
        exec("nmcli -t -f DEVICE,TYPE,STATE,CONNECTION device 2>/dev/null", $nm_lines);
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
        exec("ip -br addr 2>/dev/null", $ip_lines);
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

    /**
     * Get all header status data in one call
     */
    public static function getHeaderStatus(): array {
        $public_ip = self::getPublicIp();
        $interfaces = self::getNetworkInterfaces();
        
        // Add public IP as external interface if online
        if ($public_ip) {
            $interfaces[] = [
                'name' => 'ext',
                'type' => 'external',
                'icon' => 'bi-globe',
                'ipv4' => $public_ip,
                'connected' => true,
                'state' => 'connected'
            ];
        }
        
        return [
            'hostname' => gethostname(),
            'status' => [
                'proxy' => self::getSshTunnelStatus(),
                'ping' => self::pingGoogle(),
                'internet' => (bool) $public_ip,
                'dns' => self::dnsResolvesGoogle()
            ],
            'interfaces' => $interfaces,
            'public_ip' => $public_ip
        ];
    }
} 