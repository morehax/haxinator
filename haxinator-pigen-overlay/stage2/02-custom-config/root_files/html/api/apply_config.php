<?php
// Include security framework
require_once __DIR__ . '/../security/bootstrap.php';

// Strict error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Authentication check
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

header('Content-Type: application/json');

try {
    $results = [
        'success' => true,
        'connections' => [],
        'warnings' => []
    ];

    // Check if env-secrets exists and is readable
    if (!is_readable('/var/www/env-secrets')) {
        throw new Exception('Cannot read env-secrets file. Please upload it first.');
    }

    // Parse env-secrets file
    $env = parse_env_file('/var/www/env-secrets');
    
    // Process OpenVPN configuration
    if (isset($env['VPN_USER']) && isset($env['VPN_PASS'])) {
        if (!is_readable('/var/www/openvpn-udp.ovpn')) {
            $results['warnings'][] = 'OpenVPN credentials found but no configuration file present. Please upload the VPN configuration file.';
        } else {
            // Delete existing connection if present
            exec('nmcli connection delete openvpn-udp 2>/dev/null');
            
            // Import and configure VPN
            $output = [];
            $retval = 0;
            
            exec('nmcli connection import type openvpn file /var/www/openvpn-udp.ovpn', $output, $retval);
            if ($retval !== 0) {
                throw new Exception('Failed to import OpenVPN configuration');
            }
            
            exec('nmcli connection modify openvpn-udp +vpn.data username="' . escapeshellarg($env['VPN_USER']) . '" +vpn.data password-flags=0', $output, $retval);
            if ($retval !== 0) {
                throw new Exception('Failed to set OpenVPN username');
            }
            
            exec('nmcli connection modify openvpn-udp vpn.secrets password="' . escapeshellarg($env['VPN_PASS']) . '"', $output, $retval);
            if ($retval !== 0) {
                throw new Exception('Failed to set OpenVPN password');
            }
            
            $results['connections'][] = 'OpenVPN connection configured successfully';
        }
    }
    
    // Process Iodine configuration
    if (isset($env['IODINE_TOPDOMAIN']) && isset($env['IODINE_NAMESERVER']) && isset($env['IODINE_PASS'])) {
        // Set defaults for optional parameters
        $mtu = $env['IODINE_MTU'] ?? '1400';
        $lazy = $env['IODINE_LAZY'] ?? 'true';
        $interval = $env['IODINE_INTERVAL'] ?? '4';
        
        // Delete existing connection if present
        exec('nmcli connection delete iodine-vpn 2>/dev/null');
        
        // Create new connection
        $output = [];
        $retval = 0;
        
        // Log command output for debugging
        $command = 'nmcli connection add type vpn ifname iodine0 con-name iodine-vpn vpn-type iodine';
        exec($command . ' 2>&1', $output, $retval);
        error_log("Iodine command: $command");
        error_log("Iodine command output: " . implode("\n", $output));
        error_log("Iodine command return value: $retval");
        
        if ($retval !== 0) {
            throw new Exception('Failed to create Iodine connection: ' . implode("\n", $output));
        }
        
        // For Iodine
        $vpnData = sprintf('topdomain = %s, nameserver = %s, password = %s, mtu = %s, lazy-mode = %s, interval = %s',
            $env['IODINE_TOPDOMAIN'],
            $env['IODINE_NAMESERVER'],
            $env['IODINE_PASS'],
            $mtu,
            $lazy,
            $interval
        );
        
        exec('nmcli connection modify iodine-vpn vpn.data "' . $vpnData . '"', $output, $retval);
        if ($retval !== 0) {
            throw new Exception('Failed to configure Iodine connection');
        }
        
        exec('nmcli connection modify iodine-vpn vpn.secrets password="' . $env['IODINE_PASS'] . '"', $output, $retval);
        if ($retval !== 0) {
            throw new Exception('Failed to set Iodine password');
        }
        
        $results['connections'][] = 'Iodine DNS tunnel configured successfully';
    }
    
    // Process Hans configuration
    if (isset($env['HANS_SERVER']) && isset($env['HANS_PASSWORD'])) {
        // Delete existing connection if present
        exec('nmcli connection delete hans-icmp-vpn 2>/dev/null');
        
        // Create new connection
        $output = [];
        $retval = 0;
        
        exec('nmcli connection add type vpn con-name hans-icmp-vpn ifname tun0 vpn-type org.freedesktop.NetworkManager.hans', $output, $retval);
        if ($retval !== 0) {
            throw new Exception('Failed to create Hans connection');
        }
        
        // For Hans
        exec('nmcli connection modify hans-icmp-vpn vpn.data "server = ' . $env['HANS_SERVER'] . ', password = ' . $env['HANS_PASSWORD'] . ', password-flags = 1"', $output, $retval);
        if ($retval !== 0) {
            throw new Exception('Failed to configure Hans connection');
        }
        
        exec('nmcli connection modify hans-icmp-vpn ipv4.method auto ipv4.never-default true', $output, $retval);
        if ($retval !== 0) {
            throw new Exception('Failed to configure Hans IPv4 settings');
        }
        
        exec('nmcli connection modify hans-icmp-vpn ipv6.method auto ipv6.addr-gen-mode default', $output, $retval);
        if ($retval !== 0) {
            throw new Exception('Failed to configure Hans IPv6 settings');
        }
        
        $results['connections'][] = 'Hans ICMP VPN configured successfully';
    }
    
    // Process WiFi AP configuration
    if (isset($env['WIFI_SSID']) && isset($env['WIFI_PASSWORD'])) {
        // Delete existing connection if present
        exec('nmcli connection delete pi_hotspot 2>/dev/null');
        
        // Create new connection
        $output = [];
        $retval = 0;
        
        exec('nmcli con add type wifi ifname wlan0 con-name pi_hotspot autoconnect yes ssid "' . $env['WIFI_SSID'] . '"', $output, $retval);
        if ($retval !== 0) {
            throw new Exception('Failed to create WiFi AP connection');
        }
        
        $wifiCmd = 'nmcli con mod pi_hotspot ' .
            '802-11-wireless.mode ap ' .
            '802-11-wireless.band bg ' .
            'wifi-sec.key-mgmt wpa-psk ' .
            'wifi-sec.psk "' . $env['WIFI_PASSWORD'] . '" ' .
            'ipv4.addresses 192.168.4.1/24 ' .
            'ipv4.method shared ' .
            'ipv4.never-default yes ' .
            'ipv6.method ignore';
        
        exec($wifiCmd, $output, $retval);
        if ($retval !== 0) {
            throw new Exception('Failed to configure WiFi AP settings');
        }
        
        $results['connections'][] = 'WiFi Access Point configured successfully';
    }
    
    if (empty($results['connections'])) {
        $results['warnings'][] = 'No network configurations were found in the env-secrets file';
    }
    
    echo json_encode($results);

} catch (Exception $e) {
    error_log("Config application error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Parse env-secrets file into an associative array
 */
function parse_env_file($filepath) {
    $env = [];
    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if (empty($line) || $line[0] === '#') {
            continue;
        }
        
        // Check format KEY=VALUE
        if (!preg_match('/^[A-Z_]+=.+$/', $line)) {
            throw new Exception('Invalid format in env-secrets file. Each line must be KEY=VALUE');
        }
        
        list($key, $value) = explode('=', $line, 2);
        // Strip quotes from the value
        $value = trim($value, '"\'');
        $env[$key] = $value;
    }
    
    return $env;
} 