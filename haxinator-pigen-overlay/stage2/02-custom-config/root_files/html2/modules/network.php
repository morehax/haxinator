<?php
/**
 * Network Status & Control Module – Unified Interface View (2025)
 * Provides unified view of active interfaces and connection profiles
 * CombinesNetworkManager connections with live interface status
 * Using same design + AJAX/JSON pattern as other modules.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
//  Module metadata (used by parent framework during discovery)
// ─────────────────────────────────────────────────────────────────────────────
$module = [
    'id'          => 'network',
    'title'       => 'Network',
    'icon'        => 'ethernet',
    'description' => 'Unified network interface and connection management',
    'category'    => 'network'
];

// Early return during discovery
if (!defined('EMBEDDED_MODULE') && !defined('MODULE_POST_HANDLER')) {
    return;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Helpers & environment
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('csrf')) {
    function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
}
if (!function_exists('json_out')) {
    function json_out(array $p,int $c=200):never{
        http_response_code($c);
        header('Content-Type: application/json');
        echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('bad')) {
    function bad(string $m,int $c=400):never{ json_out(['error'=>$m],$c); }
}

// Execute nmcli safely and capture output
function nm_exec(string $template, array $args = [], ?array &$out = null, ?int &$rc = null): bool
{
    $quoted = array_map('escapeshellarg', $args);
    $cmd    = vsprintf($template, $quoted) . ' 2>&1';
    exec($cmd, $out, $rc);
    return $rc === 0;
}

// Get network interface information (enhanced from header_utils.php)
function getNetworkInterfaces(): array {
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
    
    // Get detailed interface information
    exec("ip -br addr show 2>/dev/null", $ip_lines);
    foreach ($ip_lines as $line) {
        $parts = preg_split('/\s+/', trim($line), 4);
        if (count($parts) >= 2) {
            $iface = $parts[0];
            $state = $parts[1];
            
            // Skip loopback
            if ($iface === 'lo') continue;
            
            // Get IPv4 address
            $ipv4 = '';
            if (isset($parts[2]) && preg_match('/(\d+\.\d+\.\d+\.\d+)\/\d+/', $parts[2], $matches)) {
                $ipv4 = $matches[1];
            }
            
            // Determine interface type and icon
            $type = isset($nm_info[$iface]) ? $nm_info[$iface]['type'] : 'unknown';
            $nm_state = isset($nm_info[$iface]) ? $nm_info[$iface]['state'] : 'unmanaged';
            $connection = isset($nm_info[$iface]) ? $nm_info[$iface]['connection'] : '--';
            
            $icon = 'hdd-network';  // default icon
            $friendly_type = 'Unknown';
            
            switch ($type) {
                case 'wifi':
                case '802-11-wireless':
                    $icon = 'wifi';
                    $friendly_type = 'Wi-Fi';
                    break;
                case 'ethernet':
                case '802-3-etherlet':
                    $icon = 'ethernet';
                    $friendly_type = 'Ethernet';
                    break;
                case 'tun':
                    $icon = 'shield-lock';
                    $friendly_type = 'VPN Tunnel';
                    break;
                case 'bridge':
                    $icon = 'diagram-3';
                    $friendly_type = 'Bridge';
                    break;
                case 'loopback':
                    continue 2; // Skip loopback
                default:
                    // Handle by interface name patterns
                    if (strpos($iface, 'tun') === 0 || strpos($iface, 'tap') === 0) {
                        $icon = 'shield-lock';
                        $friendly_type = 'VPN Tunnel';
                    } elseif (strpos($iface, 'br') === 0) {
                        $icon = 'diagram-3';
                        $friendly_type = 'Bridge';
                    } elseif (strpos($iface, 'docker') === 0) {
                        $icon = 'box';
                        $friendly_type = 'Container';
                    } else {
                        $friendly_type = ucfirst($type);
                    }
            }
            
            // Determine connection status
            $is_up = ($state === 'UP' || $nm_state === 'connected');
            $has_ip = !empty($ipv4);
            $connected = $is_up && ($has_ip || $nm_state === 'connected');
            
            // Get additional interface stats
            $stats = getInterfaceStats($iface);
            
            $interfaces[] = [
                'name' => $iface,
                'type' => $type,
                'friendly_type' => $friendly_type,
                'icon' => $icon,
                'ipv4' => $ipv4,
                'connected' => $connected,
                'state' => $nm_state,
                'connection_name' => $connection !== '--' ? $connection : null,
                'stats' => $stats,
                'is_managed' => $nm_state !== 'unmanaged'
            ];
        }
    }
    
    return $interfaces;
}

// Get interface statistics
function getInterfaceStats($iface): array {
    $stats = ['rx_bytes' => 0, 'tx_bytes' => 0, 'rx_packets' => 0, 'tx_packets' => 0];
    
    $stats_file = "/sys/class/net/$iface/statistics/";
    if (is_dir($stats_file)) {
        $stats['rx_bytes'] = (int)@file_get_contents($stats_file . 'rx_bytes');
        $stats['tx_bytes'] = (int)@file_get_contents($stats_file . 'tx_bytes');
        $stats['rx_packets'] = (int)@file_get_contents($stats_file . 'rx_packets');
        $stats['tx_packets'] = (int)@file_get_contents($stats_file . 'tx_packets');
    }
    
    return $stats;
}

// Get all connection profiles
function getConnectionProfiles(): array {
    $profiles = [];
    
    nm_exec('nmcli -t -f NAME,UUID,TYPE,DEVICE,AUTOCONNECT,AUTOCONNECT-PRIORITY connection show', [], $rows, $rc);
    if ($rc !== 0) return $profiles;
    
    foreach ($rows as $ln) {
        if ($ln === '') continue;
        $parts = explode(':', $ln);
        if (count($parts) < 5) continue;
        
        [$name, $uuid, $type, $device, $auto, $priority] = array_pad($parts, 6, '');
        
        // Determine if this is an auto-created connection
        $is_auto_created = in_array($type, ['tun', 'bridge', 'loopback', 'dummy']) || 
                          ($type === 'tun' && $name === $device); // tun0 connection matching tun0 device
        
        // Determine friendly type and icon
        $friendly_type = 'Unknown';
        $icon = 'bookmark';
        
        switch ($type) {
            case '802-11-wireless':
                $friendly_type = 'Wi-Fi Profile';
                $icon = 'wifi';
                break;
            case '802-3-ethernet':
                $friendly_type = 'Ethernet Profile';
                $icon = 'ethernet';
                break;
            case 'vpn':
                $friendly_type = 'VPN Profile';
                $icon = 'shield-lock';
                break;
            case 'bridge':
                $friendly_type = 'Bridge Interface';
                $icon = 'diagram-3';
                break;
            case 'tun':
                $friendly_type = 'Tunnel Interface';
                $icon = 'shield-lock';
                break;
            case 'loopback':
                $friendly_type = 'Loopback Interface';
                $icon = 'arrow-repeat';
                break;
            default:
                $friendly_type = ucfirst(str_replace('-', ' ', $type));
        }
        
        $profiles[] = [
            'name' => $name,
            'uuid' => $uuid,
            'type' => $type,
            'friendly_type' => $friendly_type,
            'icon' => $icon,
            'device' => $device !== '--' ? $device : null,
            'auto' => $auto === 'yes',
            'priority' => (int)($priority ?: 0),
            'is_active' => $device !== '--' && $device !== '',
            'is_auto_created' => $is_auto_created
        ];
    }
    
    return $profiles;
}

// Format bytes for display
function formatBytes($bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

$re_uuid = '/^[0-9a-f\-]{36}$/i';

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX / POST handler (JSON responses)
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_POST['csrf'] ?? '') !== csrf()) bad('Invalid CSRF',403);

    $act = $_POST['action'];

    // ───────────── unified view ─────────────
    if ($act === 'unified_view') {
        $active_interfaces = getNetworkInterfaces();
        $connection_profiles = getConnectionProfiles();
        
        // Show ALL connection profiles in "Available Connection Profiles"
        // No filtering needed - users should see all their saved profiles
        
        json_out([
            'active_interfaces' => $active_interfaces,
            'available_connections' => $connection_profiles  // Show ALL profiles, not filtered
        ]);
    }

    // ───────────── simple connection listing (restored from backup) ─────────────
    if ($act === 'list_conns') {
        nm_exec('nmcli -t -f NAME,UUID,TYPE,DEVICE,AUTOCONNECT connection show', [], $rows, $rc);
        if ($rc !== 0) bad('Failed to retrieve network connections');
        $list = [];
        foreach ($rows as $ln) {
            if ($ln==='') continue;
            [$name,$uuid,$type,$dev,$auto] = explode(':', $ln)+array_fill(0,5,'');
            $list[] = [
                'name'  => $name,
                'uuid'  => $uuid,
                'type'  => $type,
                'device'=> $dev,
                'auto'  => $auto,
            ];
        }
        json_out(['conns'=>$list]);
    }

    // ───────────── interface actions ─────────────
    if ($act === 'disconnect_interface') {
        $iface = $_POST['interface'] ?? '';
        if (!preg_match('/^[\w\-.]{1,15}$/', $iface)) bad('Invalid interface name');
        
        // Try to find and disconnect the connection using this interface
        nm_exec('nmcli device disconnect %s', [$iface], $o, $r);
        if ($r === 0) {
            json_out(['success' => true, 'message' => 'Interface disconnected successfully']);
        } else {
            json_out(['success' => false, 'message' => implode('\n', $o)]);
        }
    }

    // ───────────── connection actions (legacy support) ─────────────
    if (in_array($act, ['up_conn','down_conn','del_conn'], true)) {
        $uuid = $_POST['uuid'] ?? '';
        if (!preg_match($re_uuid, $uuid)) bad('Invalid connection identifier');
        
        $cmdMap = [
            'up_conn'   => 'nmcli connection up uuid %s',
            'down_conn' => 'nmcli connection down uuid %s',
            'del_conn'  => 'nmcli connection delete uuid %s'
        ];
        
        if (nm_exec($cmdMap[$act], [$uuid], $o, $r)) {
            $messages = [
                'up_conn' => 'Connection activated successfully',
                'down_conn' => 'Connection deactivated successfully', 
                'del_conn' => 'Connection deleted successfully'
            ];
            json_out(['success' => true, 'message' => $messages[$act]]);
        } else {
            json_out(['success' => false, 'message' => implode('\n', $o)]);
        }
    }

    // ─────────── get connection details ───────────
    if ($act === 'get_conn_details') {
        $uuid = $_POST['uuid'] ?? '';
        if (!preg_match($re_uuid, $uuid)) bad('Invalid connection identifier');
        
        // Get connection type first
        nm_exec('nmcli -t -f connection.type connection show uuid %s', [$uuid], $typeOut, $typeRc);
        if ($typeRc !== 0) bad('Failed to get connection type');
        
        $connType = 'default';
        foreach ($typeOut as $line) {
            if (str_contains($line, 'connection.type:')) {
                $rawType = trim(explode(':', $line, 2)[1]);
                switch ($rawType) {
                    case '802-11-wireless':
                        $connType = 'wifi';
                        break;
                    case 'vpn':
                        $connType = 'vpn';
                        break;
                    case 'ethernet':
                    case '802-3-ethernet':
                        $connType = 'ethernet';
                        break;
                    default:
                        $connType = 'default';
                }
                break;
            }
        }
        
        // Fetch ALL available fields - no filtering (Option A approach)
        nm_exec('nmcli -t -s connection show uuid %s', [$uuid], $out, $rc);
        if ($rc !== 0) bad('Failed to retrieve connection details');
        
        $details = [];
        foreach ($out as $ln) {
            if (!str_contains($ln, ':')) continue;
            [$k, $v] = explode(':', $ln, 2);
            $key = trim($k);
            $val = trim($v);
            
            // Special handling for vpn.data - break it into more manageable parts
            if ($key === 'vpn.data' && $connType === 'vpn') {
                // Get the VPN service type to determine parsing strategy
                $serviceType = '';
                foreach ($out as $serviceLine) {
                    if (str_contains($serviceLine, 'vpn.service-type:')) {
                        $serviceType = trim(explode(':', $serviceLine, 2)[1]);
                        break;
                    }
                }
                
                // Parse VPN data based on service type
                $vpnPairs = parseVpnData($val, $serviceType);
                
                // Add individual VPN data fields instead of the combined field
                $details = array_merge($details, $vpnPairs);
            } else {
                $details[] = ['key' => $key, 'val' => $val === '--' ? '' : $val];
            }
        }
        
        json_out(['details' => $details, 'type' => $connType]);
    }

    // ─────────── save connection details ───────────
    if ($act === 'save_conn_details') {
        $uuid = $_POST['uuid'] ?? '';
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        if (!preg_match($re_uuid, $uuid)) bad('Invalid connection identifier');
        if (!is_array($rows)) bad('Invalid configuration data provided');
        
        // Group vpn.data.* fields and handle them specially
        $vpnDataParts = [];
        $regularFields = [];
        
        foreach ($rows as $r) {
            if (!isset($r['key'], $r['val'])) continue;
            
            if (str_starts_with($r['key'], 'vpn.data.')) {
                // Extract the sub-key from vpn.data.subkey
                $subKey = substr($r['key'], 9); // Remove 'vpn.data.'
                $vpnDataParts[$subKey] = $r['val'];
            } else {
                $regularFields[] = $r;
            }
        }
        
        // If we have vpn.data parts, reconstruct the full vpn.data field
        if (!empty($vpnDataParts)) {
            $vpnDataString = '';
            foreach ($vpnDataParts as $key => $val) {
                if ($vpnDataString !== '') $vpnDataString .= ', ';
                // Quote values that contain spaces or special characters
                if (preg_match('/[\s,=]/', $val)) {
                    $vpnDataString .= "$key = '$val'";
                } else {
                    $vpnDataString .= "$key = $val";
                }
            }
            // Add the reconstructed vpn.data to regular fields
            $regularFields[] = ['key' => 'vpn.data', 'val' => $vpnDataString];
        }
        
        // Apply all field modifications
        foreach ($regularFields as $r) {
            if (!isset($r['key'], $r['val'])) continue;
            nm_exec('nmcli connection modify uuid %s %s %s', [$uuid, $r['key'], $r['val']], $o, $rc);
            if ($rc !== 0) bad('Error modifying ' . $r['key'] . ': ' . implode('\n', $o));
        }
        
        json_out(['success' => true, 'message' => 'Connection updated successfully']);
    }

    // ─────────── interface statistics ───────────
    if ($act === 'get_interface_stats') {
        $iface = $_POST['interface'] ?? '';
        if (!preg_match('/^[\w\-.]{1,15}$/', $iface)) bad('Invalid interface name');
        
        $stats = getInterfaceStats($iface);
        
        // Get additional information
        $info = [];
        
        // Get interface flags and MTU
        exec("ip link show $iface 2>/dev/null", $link_info);
        if (!empty($link_info[0])) {
            if (preg_match('/mtu (\d+)/', $link_info[0], $matches)) {
                $info['mtu'] = $matches[1];
            }
            if (strpos($link_info[0], 'UP') !== false) {
                $info['link_state'] = 'UP';
            } else {
                $info['link_state'] = 'DOWN';
            }
        }
        
        // Get speed information for ethernet interfaces
        $speed_file = "/sys/class/net/$iface/speed";
        if (file_exists($speed_file)) {
            $speed = trim(@file_get_contents($speed_file));
            if (is_numeric($speed) && $speed > 0) {
                $info['speed'] = $speed . ' Mbps';
            }
        }
        
        json_out([
            'interface' => $iface,
            'stats' => $stats,
            'info' => $info,
            'formatted_stats' => [
                'rx_bytes' => formatBytes($stats['rx_bytes']),
                'tx_bytes' => formatBytes($stats['tx_bytes']),
                'rx_packets' => number_format($stats['rx_packets']),
                'tx_packets' => number_format($stats['tx_packets'])
            ]
        ]);
    }

    bad('Unknown action');
}

// Type-specific VPN data parsing functions
function parseVpnData($vpnData, $serviceType) {
    if ($vpnData === '--' || $vpnData === '') {
        return [];
    }
    
    switch ($serviceType) {
        case 'org.freedesktop.NetworkManager.hans':
            return parseHansVpnData($vpnData);
        case 'org.freedesktop.NetworkManager.iodine':
            return parseIodineVpnData($vpnData);
        case 'org.freedesktop.NetworkManager.openvpn':
            return parseOpenVpnData($vpnData);
        default:
            return parseGenericVpnData($vpnData);
    }
}

function parseHansVpnData($vpnData) {
    $vpnPairs = [];
    
    // HANS specific parsing - handles password-flags specially
    if (str_contains($vpnData, 'password-flags')) {
        // Pattern: "server=X, password=Y, password-flags=Z"
        if (preg_match('/server\s*=\s*([^,]+)/', $vpnData, $serverMatch) &&
            preg_match('/password\s*=\s*([^,]+)(?=,\s*password-flags)/', $vpnData, $passwordMatch) &&
            preg_match('/password-flags\s*=\s*(\d+)/', $vpnData, $flagsMatch)) {
            
            $vpnPairs[] = ['key' => 'vpn.data.server', 'val' => trim($serverMatch[1])];
            $vpnPairs[] = ['key' => 'vpn.data.password', 'val' => trim($passwordMatch[1])];
            $vpnPairs[] = ['key' => 'vpn.data.password-flags', 'val' => trim($flagsMatch[1])];
        } else {
            // Fallback to generic parsing
            return parseGenericVpnData($vpnData);
        }
    } else {
        // Standard HANS parsing without password-flags
        return parseGenericVpnData($vpnData);
    }
    
    return $vpnPairs;
}

function parseIodineVpnData($vpnData) {
    $vpnPairs = [];
    
    // Iodine specific parsing - handles multi-word values like "4 w"
    // Pattern: "key = value, key = value" but values can contain spaces
    
    // First, handle special cases with quotes or multi-word values
    $patterns = [
        '/interval\s*=\s*([^,]+?)(?=,\s*\w+\s*=|$)/' => 'interval',
        '/lazy-mode\s*=\s*([^,]+?)(?=,\s*\w+\s*=|$)/' => 'lazy-mode',
        '/mtu\s*=\s*([^,]+?)(?=,\s*\w+\s*=|$)/' => 'mtu',
        '/nameserver\s*=\s*([^,]+?)(?=,\s*\w+\s*=|$)/' => 'nameserver',
        '/password\s*=\s*([^,]+?)(?=,\s*\w+\s*=|$)/' => 'password',
        '/topdomain\s*=\s*([^,]+?)(?=,\s*\w+\s*=|$)/' => 'topdomain'
    ];
    
    foreach ($patterns as $pattern => $fieldName) {
        if (preg_match($pattern, $vpnData, $matches)) {
            $value = trim($matches[1]);
            $vpnPairs[] = ['key' => "vpn.data.$fieldName", 'val' => $value];
        }
    }
    
    return $vpnPairs;
}

function parseOpenVpnData($vpnData) {
    $vpnPairs = [];
    
    // OpenVPN specific parsing - handles comma-separated key=value pairs with escaped colons
    // Format: "key = value, key = value, key = value..."
    
    if ($vpnData === '--' || $vpnData === '') {
        return [];
    }
    
    // Split on commas but be careful about escaped content
    // Use a more sophisticated approach to handle escaped colons properly
    $rawPairs = preg_split('/,\s*(?=\w+(?:-\w+)*\s*=)/', $vpnData);
    
    foreach ($rawPairs as $pair) {
        $pair = trim($pair);
        if (empty($pair)) continue;
        
        // Match key = value pattern allowing for various key formats and values
        if (preg_match('/^(\w+(?:-\w+)*)\s*=\s*(.*)$/', $pair, $matches)) {
            $key = trim($matches[1]);
            $value = trim($matches[2]);
            
            // Unescape colons in the value
            $value = str_replace('\\:', ':', $value);
            
            // Add to the VPN data array
            $vpnPairs[] = ['key' => "vpn.data.$key", 'val' => $value];
        }
    }
    
    return $vpnPairs;
}

function parseGenericVpnData($vpnData) {
    $vpnPairs = [];
    
    // Generic parsing for unknown VPN types
    $pairs = preg_split('/,\s*(?=\w+\s*=)/', $vpnData);
    foreach ($pairs as $pair) {
        if (preg_match('/^(\w+(?:-\w+)*)\s*=\s*(.*)$/', trim($pair), $matches)) {
            $subKey = trim($matches[1]);
            $subVal = trim($matches[2], " '\"");
            $vpnPairs[] = ['key' => "vpn.data.$subKey", 'val' => $subVal];
        }
    }
    
    return $vpnPairs;
}

// ─────────────────────────────────────────────────────────────────────────────
//  EMBEDDED PAGE (Bootstrap UI)
// ─────────────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$csrf = csrf();
?>

<!-- Enhanced Network Status & Control CSS -->
<style>
.cp-interface-section {
    margin-bottom: 1.5rem;
}

.cp-interface-card {
    border-left: 4px solid var(--cp-border);
    transition: border-color 0.2s ease;
}

.cp-interface-card.cp-active {
    border-left-color: var(--cp-success);
    background: rgb(25 135 84 / 3%);
}

.cp-interface-card.cp-inactive {
    border-left-color: var(--cp-secondary);
}

.cp-connection-relationship {
    font-size: 0.8rem;
    color: var(--cp-text-muted);
    font-style: italic;
    margin-top: 0.2rem;
}

.cp-interface-type-icon {
    width: 1.2rem;
    text-align: center;
    margin-right: 0.5rem;
}

.cp-status-badge {
    font-size: 0.75rem;
    font-weight: 600;
}

.cp-stats-mini {
    font-size: 0.7rem;
    color: var(--cp-text-muted);
    font-family: 'SF Mono', Monaco, monospace;
}

.cp-interface-priority {
    background: rgb(108 117 125 / 10%);
    border: 1px solid var(--cp-border-light);
    border-radius: 4px;
    padding: 0.2rem 0.4rem;
    font-size: 0.75rem;
    font-weight: 500;
}

.cp-section-badge {
    font-size: 0.75rem;
    font-weight: 600;
}

/* Override disconnect button colors for better aesthetics */
.btn-outline-warning[onclick*="disconnectInterface"],
.btn-outline-warning[onclick*="deactivateConnection"] {
    border-color: #dc3545;
    color: #dc3545;
}
.btn-outline-warning[onclick*="disconnectInterface"]:hover,
.btn-outline-warning[onclick*="deactivateConnection"]:hover {
    background-color: #dc3545;
    border-color: #dc3545;
    color: white;
}
</style>

<h3 class="mb-3">Network Status & Control</h3>

<!-- Section 1: Available Connection Profiles -->
<div class="cp-interface-section">
  <div class="card cp-interface-card cp-inactive">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">
        <i class="bi bi-collection me-2"></i>Available Connection Profiles
      </h5>
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-secondary cp-section-badge" id="availableConnectionCount">0</span>
        <button class="btn btn-sm btn-primary" id="refreshBtn">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="cp-table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" id="availableConnectionTable">
          <thead class="table-light">
            <tr>
              <th>Profile Name</th>
              <th class="cp-col-sm">Type</th>
              <th class="cp-col-md">Auto-Connect</th>
              <th class="cp-col-lg">Priority</th>
              <th class="cp-col-lg">Device</th>
              <th style="width:18%">Actions</th>
              <th class="d-table-cell d-sm-none" style="width:1%">
                <i class="bi bi-info-circle" title="Tap row for details"></i>
              </th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Section 2: Active Network Interfaces -->
<div class="cp-interface-section">
  <div class="card cp-interface-card cp-active">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">
        <i class="bi bi-hdd-network me-2"></i>Active Network Interfaces
      </h5>
      <span class="badge bg-success cp-section-badge" id="activeInterfaceCount">0</span>
    </div>
    <div class="card-body p-0">
      <div class="cp-table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" id="activeInterfaceTable">
          <thead class="table-light">
            <tr>
              <th>Interface</th>
              <th class="cp-col-sm">Status</th>
              <th class="cp-col-md">IP Address</th>
              <th class="cp-col-lg">Traffic</th>
              <th class="cp-col-lg">Connection</th>
              <th style="width:18%">Actions</th>
              <th class="d-table-cell d-sm-none" style="width:1%">
                <i class="bi bi-info-circle" title="Tap row for details"></i>
              </th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Section 3: Simple Connection Management (Restored from Backup) -->
<div class="cp-interface-section" style="display: none;" id="simpleConnectionSection">
  <div class="card cp-interface-card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">
        <i class="bi bi-list-ul me-2"></i>Simple Connection Management
      </h5>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="toggleViewBtn">Switch View</button>
        <button class="btn btn-sm btn-primary" id="refreshConnBtn">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="cp-table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" id="connTable">
          <thead class="table-light">
            <tr>
              <th>Name</th>
              <th class="cp-col-sm">UUID</th>
              <th class="cp-col-md">Type</th>
              <th class="cp-col-lg">Device</th>
              <th class="cp-col-md">Auto</th>
              <th style="width:18%">Actions</th>
              <th class="d-table-cell d-sm-none" style="width:1%">
                <i class="bi bi-info-circle" title="Tap row for details"></i>
              </th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="cp-toast-container" id="toastArea"></div>

<script>
const csrf = '<?= htmlspecialchars($csrf) ?>';

function toast(msg, ok = true) {
  const t = document.createElement('div');
  t.className = 'toast align-items-center text-bg-' + (ok ? 'success' : 'danger');
  t.innerHTML = '<div class="d-flex"><div class="toast-body">' + msg + '</div>' +
    '<button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
  document.getElementById('toastArea').append(t);
  new bootstrap.Toast(t, { delay: 4000 }).show();
}

async function api(action, body = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', csrf);
  for (const k in body) fd.append(k, body[k]);
  const r = await fetch('?module=network', { method: 'POST', body: fd });
  return r.json();
}

function formatBytes(bytes) {
  if (bytes >= 1073741824) {
    return (bytes / 1073741824).toFixed(2) + ' GB';
  } else if (bytes >= 1048576) {
    return (bytes / 1048576).toFixed(2) + ' MB';
  } else if (bytes >= 1024) {
    return (bytes / 1024).toFixed(2) + ' KB';
  } else {
    return bytes + ' B';
  }
}

function renderActiveInterfaces(interfaces) {
  const tbody = document.querySelector('#activeInterfaceTable tbody');
  tbody.innerHTML = '';
  
  document.getElementById('activeInterfaceCount').textContent = interfaces.length;
  
  interfaces.forEach((iface, idx) => {
    const statusBadge = iface.connected ? 
      '<span class="badge bg-success cp-status-badge">Connected</span>' : 
      '<span class="badge bg-secondary cp-status-badge">Disconnected</span>';
    
    const trafficInfo = `
      <div class="cp-stats-mini">
        ↓ ${formatBytes(iface.stats.rx_bytes)}<br>
        ↑ ${formatBytes(iface.stats.tx_bytes)}
      </div>
    `;
    
    const connectionInfo = iface.connection_name ? 
      `<div class="fw-semibold">${iface.connection_name}</div><div class="cp-connection-relationship">Profile active</div>` :
      '<span class="text-muted">Direct/Unmanaged</span>';
    
    // Mobile-friendly action buttons
    const mobileActions = `
      <div class="cp-btn-group-mobile">
        <button class='btn btn-sm btn-outline-info w-100' onclick="showInterfaceStats('${iface.name}')">
          <i class='bi bi-graph-up me-1'></i>Details
        </button>
        ${iface.connected ? `<button class='btn btn-sm btn-outline-warning w-100' onclick="disconnectInterface('${iface.name}')">
          <i class='bi bi-stop me-1'></i>Disconnect
        </button>` : ''}
      </div>
      <div class="cp-btn-group-desktop">
        <button class='btn btn-sm btn-outline-info me-1' title='Statistics' onclick="showInterfaceStats('${iface.name}')">
          <i class='bi bi-graph-up'></i>
        </button>
        ${iface.connected ? `<button class='btn btn-sm btn-outline-warning' title='Disconnect' onclick="disconnectInterface('${iface.name}')">
          <i class='bi bi-stop'></i>
        </button>` : ''}
      </div>
    `;

    const tr = document.createElement('tr');
    tr.className = 'cp-table-row';
    tr.dataset.row = idx;
    tr.innerHTML = `
      <td>
        <div class="d-flex align-items-center">
          <i class="bi bi-${iface.icon} cp-interface-type-icon ${iface.connected ? 'text-success' : 'text-secondary'}"></i>
          <div>
            <div class="fw-semibold">${iface.name}</div>
            <div class="cp-connection-relationship">${iface.friendly_type}</div>
          </div>
        </div>
      </td>
      <td class="cp-col-sm">${statusBadge}</td>
      <td class="cp-col-md">
        ${iface.ipv4 ? `<code>${iface.ipv4}</code>` : '<span class="text-muted">--</span>'}
      </td>
      <td class="cp-col-lg">${trafficInfo}</td>
      <td class="cp-col-lg">${connectionInfo}</td>
      <td>${mobileActions}</td>
      <td class="d-table-cell d-sm-none">
        <button class="cp-expand-btn" data-row="${idx}">
          <i class="bi bi-chevron-down"></i>
        </button>
      </td>
    `;
    tbody.append(tr);

    // Add mobile details row
    const detailsRow = document.createElement('tr');
    detailsRow.className = 'cp-mobile-details d-sm-none';
    detailsRow.id = `details-${idx}`;
    detailsRow.style.display = 'none';
    detailsRow.innerHTML = `
      <td colspan="7" class="p-3">
        <div class="cp-details-grid">
          <div class="cp-detail-item">
            <div class="cp-detail-label">Status:</div>
            <div>${statusBadge}</div>
          </div>
          <div class="cp-detail-item">
            <div class="cp-detail-label">Type:</div>
            <div>${iface.friendly_type}</div>
          </div>
          <div class="cp-detail-item">
            <div class="cp-detail-label">IP Address:</div>
            <div>${iface.ipv4 ? `<code class="cp-code-text">${iface.ipv4}</code>` : '<span class="text-muted">--</span>'}</div>
          </div>
          <div class="cp-detail-item">
            <div class="cp-detail-label">Connection:</div>
            <div>${iface.connection_name || '<span class="text-muted">Direct/Unmanaged</span>'}</div>
          </div>
          <div class="cp-detail-item">
            <div class="cp-detail-label">Downloaded:</div>
            <div>${formatBytes(iface.stats.rx_bytes)}</div>
          </div>
          <div class="cp-detail-item">
            <div class="cp-detail-label">Uploaded:</div>
            <div>${formatBytes(iface.stats.tx_bytes)}</div>
          </div>
        </div>
      </td>
    `;
    tbody.append(detailsRow);
  });
}

function renderAvailableConnections(connections) {
  const tbody = document.querySelector('#availableConnectionTable tbody');
  tbody.innerHTML = '';
  
  document.getElementById('availableConnectionCount').textContent = connections.length;
  
  connections.forEach((conn, idx) => {
    const autoConnectBadge = conn.auto ? 
      '<span class="badge bg-success cp-status-badge">Yes</span>' : 
      '<span class="badge bg-secondary cp-status-badge">No</span>';
    
    const priorityDisplay = conn.priority !== 0 ? 
      `<span class="cp-interface-priority">${conn.priority}</span>` : 
      '<span class="text-muted">0</span>';
    
    // Show active status and adjust action buttons accordingly
    const isActive = conn.is_active;
    const isAutoCreated = conn.is_auto_created;
    
    // Status indicators
    let statusIndicator = '';
    if (isActive) {
      statusIndicator = '<span class="badge bg-success cp-status-badge ms-2">Active</span>';
    }
    if (isAutoCreated) {
      statusIndicator += '<span class="badge bg-info cp-status-badge ms-2">Auto-Created</span>';
    }
    
    // Different action buttons for auto-created vs user-managed connections
    let mobileActions = '';
    
    if (isAutoCreated) {
      // Auto-created connections: info only, no management actions
      mobileActions = `
        <div class="cp-btn-group-mobile">
          <button class='btn btn-sm btn-outline-info w-100' onclick="showConnectionInfo('${conn.uuid}')">
            <i class='bi bi-info-circle me-1'></i>Info
          </button>
        </div>
        <div class="cp-btn-group-desktop">
          <button class='btn btn-sm btn-outline-info' title='Information' onclick="showConnectionInfo('${conn.uuid}')">
            <i class='bi bi-info-circle'></i>
          </button>
        </div>
      `;
    } else if (isActive) {
      // Active user connections: disconnect + edit + delete
      mobileActions = `
        <div class="cp-btn-group-mobile">
          <button class='btn btn-sm btn-outline-warning w-100' onclick="deactivateConnection('${conn.uuid}')">
            <i class='bi bi-stop me-1'></i>Disconnect
          </button>
          <button class='btn btn-sm btn-outline-secondary w-100' onclick="openEdit('${conn.uuid}')">
            <i class='bi bi-pencil-square me-1'></i>Edit
          </button>
          <button class='btn btn-sm btn-outline-danger w-100' onclick="deleteConnection('${conn.uuid}')">
            <i class='bi bi-trash me-1'></i>Delete
          </button>
        </div>
        <div class="cp-btn-group-desktop">
          <button class='btn btn-sm btn-outline-warning me-1' title='Disconnect' onclick="deactivateConnection('${conn.uuid}')">
            <i class='bi bi-stop'></i>
          </button>
          <button class='btn btn-sm btn-outline-secondary me-1' title='Edit' onclick="openEdit('${conn.uuid}')">
            <i class='bi bi-pencil-square'></i>
          </button>
          <button class='btn btn-sm btn-outline-danger' title='Delete' onclick="deleteConnection('${conn.uuid}')">
            <i class='bi bi-trash'></i>
          </button>
        </div>
      `;
    } else {
      // Inactive user connections: connect + edit + delete  
      mobileActions = `
        <div class="cp-btn-group-mobile">
          <button class='btn btn-sm btn-outline-success w-100' onclick="activateConnection('${conn.uuid}')">
            <i class='bi bi-play me-1'></i>Connect
          </button>
          <button class='btn btn-sm btn-outline-secondary w-100' onclick="openEdit('${conn.uuid}')">
            <i class='bi bi-pencil-square me-1'></i>Edit
          </button>
          <button class='btn btn-sm btn-outline-danger w-100' onclick="deleteConnection('${conn.uuid}')">
            <i class='bi bi-trash me-1'></i>Delete
          </button>
        </div>
        <div class="cp-btn-group-desktop">
          <button class='btn btn-sm btn-outline-success me-1' title='Connect' onclick="activateConnection('${conn.uuid}')">
            <i class='bi bi-play'></i>
          </button>
          <button class='btn btn-sm btn-outline-secondary me-1' title='Edit' onclick="openEdit('${conn.uuid}')">
            <i class='bi bi-pencil-square'></i>
          </button>
          <button class='btn btn-sm btn-outline-danger' title='Delete' onclick="deleteConnection('${conn.uuid}')">
            <i class='bi bi-trash'></i>
          </button>
        </div>
      `;
    }

    const tr = document.createElement('tr');
    // Different styling for auto-created connections
    tr.className = 'cp-table-row' + 
                   (isActive ? ' table-success' : '') + 
                   (isAutoCreated ? ' table-secondary' : '');
    tr.dataset.row = `avail-${idx}`;
    tr.innerHTML = `
      <td>
        <div class="d-flex align-items-center">
          <i class="bi bi-${conn.icon} cp-interface-type-icon ${isActive ? 'text-success' : (isAutoCreated ? 'text-muted' : 'text-secondary')}"></i>
          <div>
            <div class="fw-semibold ${isAutoCreated ? 'text-muted' : ''}">${conn.name}${statusIndicator}</div>
            <div class="cp-connection-relationship">${conn.friendly_type}</div>
          </div>
        </div>
      </td>
      <td class="cp-col-sm">
        <span class="badge bg-light text-dark">${conn.friendly_type}</span>
      </td>
      <td class="cp-col-md">${isAutoCreated ? '<span class="text-muted">N/A</span>' : autoConnectBadge}</td>
      <td class="cp-col-lg">${isAutoCreated ? '<span class="text-muted">N/A</span>' : priorityDisplay}</td>
      <td class="cp-col-lg">
        ${conn.device ? `<code>${conn.device}</code>` : '<span class="text-muted">Any</span>'}
      </td>
      <td>${mobileActions}</td>
      <td class="d-table-cell d-sm-none">
        <button class="cp-expand-btn" data-row="avail-${idx}">
          <i class="bi bi-chevron-down"></i>
        </button>
      </td>
    `;
    tbody.append(tr);

    // Add mobile details row
    const detailsRow = document.createElement('tr');
    detailsRow.className = 'cp-mobile-details d-sm-none';
    detailsRow.id = `details-avail-${idx}`;
    detailsRow.style.display = 'none';
    detailsRow.innerHTML = `
      <td colspan="7" class="p-3">
        <div class="cp-details-grid">
          <div class="cp-detail-item">
            <div class="cp-detail-label">Status:</div>
            <div>${isActive ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</div>
          </div>
          ${isAutoCreated ? `
            <div class="cp-detail-item">
              <div class="cp-detail-label">Type:</div>
              <div><span class="badge bg-info">Auto-Created Interface</span></div>
            </div>
          ` : `
            <div class="cp-detail-item">
              <div class="cp-detail-label">Type:</div>
              <div>${conn.friendly_type}</div>
            </div>
            <div class="cp-detail-item">
              <div class="cp-detail-label">Auto-Connect:</div>
              <div>${autoConnectBadge}</div>
            </div>
            <div class="cp-detail-item">
              <div class="cp-detail-label">Priority:</div>
              <div>${priorityDisplay}</div>
            </div>
          `}
          <div class="cp-detail-item">
            <div class="cp-detail-label">Device:</div>
            <div>${conn.device || '<span class="text-muted">Any</span>'}</div>
          </div>
          <div class="cp-detail-item cp-detail-full">
            <div class="cp-detail-label">UUID:</div>
            <div><code class="cp-code-text">${conn.uuid}</code></div>
          </div>
        </div>
      </td>
    `;
    tbody.append(detailsRow);
  });
}

async function loadUnifiedView() {
  try {
    const data = await api('unified_view');
    if (data.error) {
      toast(data.error, false);
      return;
    }
    
    renderActiveInterfaces(data.active_interfaces);
    renderAvailableConnections(data.available_connections);
  } catch (e) {
    toast('Failed to load network data: ' + e.message, false);
  }
}

// Action functions
async function disconnectInterface(iface) {
  if (!confirm(`Disconnect interface ${iface}?`)) return;
  
  const result = await api('disconnect_interface', { interface: iface });
  toast(result.message || (result.success ? 'Interface disconnected' : 'Failed to disconnect'), result.success);
  if (result.success) {
    // Refresh page to show updated interface states
    setTimeout(() => window.location.reload(), 1000);
  }
}

async function activateConnection(uuid) {
  const result = await api('up_conn', { uuid: uuid });
  toast(result.message || (result.success ? 'Connection activated' : 'Failed to activate'), result.success);
  if (result.success) {
    // Refresh page to show updated network state
    setTimeout(() => window.location.reload(), 1000);
  }
}

async function deactivateConnection(uuid) {
  if (!confirm('Disconnect this connection?')) return;
  
  const result = await api('down_conn', { uuid: uuid });
  toast(result.message || (result.success ? 'Connection deactivated' : 'Failed to deactivate'), result.success);
  if (result.success) {
    // Refresh page to show updated network state
    setTimeout(() => window.location.reload(), 1000);
  }
}

async function deleteConnection(uuid) {
  if (!confirm('Delete this connection profile?')) return;
  
  const result = await api('del_conn', { uuid: uuid });
  toast(result.message || (result.success ? 'Connection deleted' : 'Failed to delete'), result.success);
  if (result.success) {
    // Refresh page to show updated connection list
    setTimeout(() => window.location.reload(), 1000);
  }
}

async function showInterfaceStats(iface) {
  const result = await api('get_interface_stats', { interface: iface });
  if (result.error) {
    toast(result.error, false);
    return;
  }
  
  // Create modal if it doesn't exist
  let modal = document.getElementById('statsModal');
  if (!modal) {
    document.body.insertAdjacentHTML('beforeend', `
      <div class="modal fade cp-modal-responsive" id="statsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="statsModalTitle">Interface Statistics</h5>
              <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="statsModalBody"></div>
            <div class="modal-footer">
              <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>
    `);
    modal = document.getElementById('statsModal');
  }
  
  const title = document.getElementById('statsModalTitle');
  const body = document.getElementById('statsModalBody');
  
  title.textContent = `Interface Statistics - ${iface}`;
  body.innerHTML = `
    <div class="row g-3">
      <div class="col-md-6">
        <h6>Traffic Statistics</h6>
        <table class="table table-sm">
          <tr><td>Downloaded:</td><td><strong>${result.formatted_stats.rx_bytes}</strong></td></tr>
          <tr><td>Uploaded:</td><td><strong>${result.formatted_stats.tx_bytes}</strong></td></tr>
          <tr><td>Packets Received:</td><td><strong>${result.formatted_stats.rx_packets}</strong></td></tr>
          <tr><td>Packets Sent:</td><td><strong>${result.formatted_stats.tx_packets}</strong></td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <h6>Interface Information</h6>
        <table class="table table-sm">
          <tr><td>Link State:</td><td><strong>${result.info.link_state || 'Unknown'}</strong></td></tr>
          <tr><td>MTU:</td><td><strong>${result.info.mtu || 'Unknown'}</strong></td></tr>
          ${result.info.speed ? `<tr><td>Speed:</td><td><strong>${result.info.speed}</strong></td></tr>` : ''}
        </table>
      </div>
    </div>
  `;
  
  new bootstrap.Modal(modal).show();
}

function openEdit(uuid){
  // Build modal skeleton if not present
  let modal=document.getElementById('editModal');
  if(!modal){
    document.body.insertAdjacentHTML('beforeend',`
    <div class="modal fade cp-modal-responsive" id="editModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="editModalTitle">Edit Connection</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div id="editBody" class="text-center p-4"><div class="spinner-border"></div></div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="saveEditBtn">Save</button></div>
    </div></div></div>`);
    modal=document.getElementById('editModal');
  }
  const mInst=new bootstrap.Modal(modal); mInst.show();
  const bodyDiv=document.getElementById('editBody');
  const titleEl=document.getElementById('editModalTitle');
  bodyDiv.innerHTML='<div class="spinner-border"></div>';

  api('get_conn_details',{uuid}).then(d=>{
    if(d.error){ bodyDiv.innerHTML='<p class="text-danger">'+d.error+'</p>'; return; }
    
    // Update modal title with connection type
    const connType = d.type || 'Unknown';
    titleEl.textContent = `Edit ${connType.charAt(0).toUpperCase() + connType.slice(1)} Connection`;
    
    // Check if this is a HANS ICMP VPN connection
    const isHansVpn = d.type === 'vpn' && d.details.some(row => 
      row.key === 'vpn.service-type' && row.val.includes('hans'));
    
    // Check if this is an Iodine DNS VPN connection
    const isIodineVpn = d.type === 'vpn' && d.details.some(row => 
      row.key === 'vpn.service-type' && row.val.includes('iodine'));
    
    // Check if this is an OpenVPN connection
    const isOpenVpn = d.type === 'vpn' && d.details.some(row => 
      row.key === 'vpn.service-type' && row.val.includes('openvpn'));
    
    // Check if this is a WiFi connection
    const isWifi = d.type === 'wifi' || d.details.some(row => 
      row.key === 'connection.type' && row.val === '802-11-wireless');
    
    // Check if this is an Ethernet connection
    const isEthernet = d.type === 'ethernet' || d.details.some(row => 
      row.key === 'connection.type' && row.val === '802-3-ethernet');
    
    if (isHansVpn) {
      // Render tabbed interface for HANS VPN
      renderHansVpnTabs(d, bodyDiv, uuid);
    } else if (isIodineVpn) {
      // Render tabbed interface for Iodine VPN
      renderIodineVpnTabs(d, bodyDiv, uuid);
    } else if (isOpenVpn) {
      // Render tabbed interface for OpenVPN
      renderOpenVpnTabs(d, bodyDiv, uuid);
    } else if (isWifi) {
      // Render tabbed interface for WiFi connections
      renderWifiTabs(d, bodyDiv, uuid);
    } else if (isEthernet) {
      // Render tabbed interface for Ethernet connections (including USB ethernet)
      renderEthernetTabs(d, bodyDiv, uuid);
    } else {
      // Use existing table interface for all other connection types
      renderTableInterface(d, bodyDiv, uuid);
    }
  });
}

// Static field configuration for HANS ICMP VPN
const hansVpnFields = {
  general: [
    { key: 'connection.id', label: 'Connection Name', type: 'text', required: true },
    { key: 'connection.autoconnect', label: 'Auto Connect', type: 'select', options: ['yes', 'no'] },
    { key: 'connection.autoconnect-priority', label: 'Priority', type: 'number' },
    { key: 'connection.interface-name', label: 'Interface Name', type: 'text' }
  ],
  vpn: [
    { key: 'vpn.data.server', label: 'Server IP/Hostname', type: 'text', required: true },
    { key: 'vpn.data.password', label: 'Password', type: 'password', required: true },
    { key: 'vpn.data.password-flags', label: 'Password Flags', type: 'select', options: ['0', '1', '2'] }
  ],
  network: [
    // Essential IPv4 Configuration
    { key: 'ipv4.method', label: 'IPv4 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local'] },
    { key: 'ipv4.never-default', label: 'Never Default Route', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.addresses', label: 'Static IP Addresses', type: 'text' },
    { key: 'ipv4.gateway', label: 'Gateway', type: 'text' },
    { key: 'ipv4.dns', label: 'DNS Servers', type: 'text' },
    { key: 'ipv4.dns-search', label: 'DNS Search Domains', type: 'text' },
    { key: 'ipv4.ignore-auto-routes', label: 'Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.ignore-auto-dns', label: 'Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.may-fail', label: 'May Fail', type: 'select', options: ['yes', 'no'] },
    
    // Essential IPv6 Configuration  
    { key: 'ipv6.method', label: 'IPv6 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local'] },
    { key: 'ipv6.addresses', label: 'IPv6 Addresses', type: 'text' },
    { key: 'ipv6.gateway', label: 'IPv6 Gateway', type: 'text' },
    { key: 'ipv6.dns', label: 'IPv6 DNS Servers', type: 'text' },
    { key: 'ipv6.dns-search', label: 'IPv6 DNS Search', type: 'text' },
    { key: 'ipv6.never-default', label: 'IPv6 Never Default', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-routes', label: 'IPv6 Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-dns', label: 'IPv6 Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.may-fail', label: 'IPv6 May Fail', type: 'select', options: ['yes', 'no'] }
  ],
  proxy: [
    { key: 'proxy.method', label: 'Proxy Method', type: 'select', options: ['none', 'auto', 'manual'] },
    { key: 'proxy.pac-url', label: 'PAC URL', type: 'text' }
  ]
};

// Static field configuration for Iodine DNS VPN
const iodineVpnFields = {
  general: [
    { key: 'connection.id', label: 'Connection Name', type: 'text', required: true },
    { key: 'connection.autoconnect', label: 'Auto Connect', type: 'select', options: ['yes', 'no'] },
    { key: 'connection.autoconnect-priority', label: 'Priority', type: 'number' },
    { key: 'connection.interface-name', label: 'Interface Name', type: 'text' }
  ],
  vpn: [
    { key: 'vpn.data.nameserver', label: 'Name Server IP/Hostname', type: 'text', required: true },
    { key: 'vpn.data.topdomain', label: 'Top Domain', type: 'text', required: true },
    { key: 'vpn.data.password', label: 'Password', type: 'password', required: true },
    { key: 'vpn.data.interval', label: 'Polling Interval', type: 'text' },
    { key: 'vpn.data.lazy-mode', label: 'Lazy Mode', type: 'select', options: ['true', 'false'] },
    { key: 'vpn.data.mtu', label: 'MTU Size', type: 'number' }
  ],
  network: [
    // Essential IPv4 Configuration
    { key: 'ipv4.method', label: 'IPv4 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local'] },
    { key: 'ipv4.never-default', label: 'Never Default Route', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.addresses', label: 'Static IP Addresses', type: 'text' },
    { key: 'ipv4.gateway', label: 'Gateway', type: 'text' },
    { key: 'ipv4.dns', label: 'DNS Servers', type: 'text' },
    { key: 'ipv4.dns-search', label: 'DNS Search Domains', type: 'text' },
    { key: 'ipv4.ignore-auto-routes', label: 'Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.ignore-auto-dns', label: 'Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.may-fail', label: 'May Fail', type: 'select', options: ['yes', 'no'] },
    
    // Essential IPv6 Configuration  
    { key: 'ipv6.method', label: 'IPv6 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local'] },
    { key: 'ipv6.addresses', label: 'IPv6 Addresses', type: 'text' },
    { key: 'ipv6.gateway', label: 'IPv6 Gateway', type: 'text' },
    { key: 'ipv6.dns', label: 'IPv6 DNS Servers', type: 'text' },
    { key: 'ipv6.dns-search', label: 'IPv6 DNS Search', type: 'text' },
    { key: 'ipv6.never-default', label: 'IPv6 Never Default', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-routes', label: 'IPv6 Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-dns', label: 'IPv6 Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.may-fail', label: 'IPv6 May Fail', type: 'select', options: ['yes', 'no'] }
  ],
  proxy: [
    { key: 'proxy.method', label: 'Proxy Method', type: 'select', options: ['none', 'auto', 'manual'] },
    { key: 'proxy.pac-url', label: 'PAC URL', type: 'text' }
  ]
};

// Static field configuration for OpenVPN
const openVpnFields = {
  general: [
    { key: 'connection.id', label: 'Connection Name', type: 'text', required: true },
    { key: 'connection.autoconnect', label: 'Auto Connect', type: 'select', options: ['yes', 'no'] },
    { key: 'connection.autoconnect-priority', label: 'Priority', type: 'number' },
    { key: 'connection.interface-name', label: 'Interface Name', type: 'text' }
  ],
  openvpn: [
    { key: 'vpn.data.username', label: 'Username', type: 'text', required: true },
    { key: 'vpn.secrets', label: 'Password', type: 'password', required: true },
    { key: 'vpn.data.remote', label: 'Remote Server', type: 'text', required: true },
    { key: 'vpn.data.connection-type', label: 'Connection Type', type: 'select', options: ['password', 'password-tls', 'tls', 'static-key'] },
    { key: 'vpn.data.ca', label: 'CA Certificate Path', type: 'text' },
    { key: 'vpn.data.cert', label: 'Client Certificate Path', type: 'text' },
    { key: 'vpn.data.key', label: 'Private Key Path', type: 'text' },
    { key: 'vpn.data.ta', label: 'TLS Auth Key Path', type: 'text' },
    { key: 'vpn.data.ta-dir', label: 'TLS Auth Direction', type: 'select', options: ['0', '1'] }
  ],
  advanced: [
    { key: 'vpn.data.cipher', label: 'Cipher', type: 'select', options: ['AES-256-GCM', 'AES-256-CBC', 'AES-128-GCM', 'AES-128-CBC', 'CHACHA20-POLY1305'] },
    { key: 'vpn.data.auth', label: 'HMAC Authentication', type: 'select', options: ['SHA1', 'SHA256', 'SHA384', 'SHA512', 'MD5'] },
    { key: 'vpn.data.dev', label: 'Device Type', type: 'select', options: ['tun', 'tap'] },
    { key: 'vpn.data.tunnel-mtu', label: 'Tunnel MTU', type: 'number' },
    { key: 'vpn.data.fragment-size', label: 'Fragment Size', type: 'number' },
    { key: 'vpn.data.mssfix', label: 'MSS Fix', type: 'number' },
    { key: 'vpn.data.comp-lzo', label: 'LZO Compression', type: 'select', options: ['adaptive', 'yes', 'no', 'no-by-default'] },
    { key: 'vpn.data.remote-random', label: 'Randomize Remote Hosts', type: 'select', options: ['yes', 'no'] },
    { key: 'vpn.data.verify-x509-name', label: 'X.509 Name Verification', type: 'text' },
    { key: 'vpn.data.ns-cert-type', label: 'NS Certificate Type', type: 'select', options: ['server', 'client'] }
  ],
  network: [
    // Essential IPv4 Configuration
    { key: 'ipv4.method', label: 'IPv4 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local'] },
    { key: 'ipv4.never-default', label: 'Never Default Route', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.addresses', label: 'Static IP Addresses', type: 'text' },
    { key: 'ipv4.gateway', label: 'Gateway', type: 'text' },
    { key: 'ipv4.dns', label: 'DNS Servers', type: 'text' },
    { key: 'ipv4.dns-search', label: 'DNS Search Domains', type: 'text' },
    { key: 'ipv4.ignore-auto-routes', label: 'Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.ignore-auto-dns', label: 'Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.may-fail', label: 'May Fail', type: 'select', options: ['yes', 'no'] },
    
    // Essential IPv6 Configuration  
    { key: 'ipv6.method', label: 'IPv6 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local'] },
    { key: 'ipv6.addresses', label: 'IPv6 Addresses', type: 'text' },
    { key: 'ipv6.gateway', label: 'IPv6 Gateway', type: 'text' },
    { key: 'ipv6.dns', label: 'IPv6 DNS Servers', type: 'text' },
    { key: 'ipv6.dns-search', label: 'IPv6 DNS Search', type: 'text' },
    { key: 'ipv6.never-default', label: 'IPv6 Never Default', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-routes', label: 'IPv6 Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-dns', label: 'IPv6 Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.may-fail', label: 'IPv6 May Fail', type: 'select', options: ['yes', 'no'] }
  ],
  proxy: [
    { key: 'proxy.method', label: 'Proxy Method', type: 'select', options: ['none', 'auto', 'manual'] },
    { key: 'proxy.pac-url', label: 'PAC URL', type: 'text' }
  ]
};

// Static field configuration for WiFi (802-11-wireless) connections
const wifiFields = {
  general: [
    { key: 'connection.id', label: 'Connection Name', type: 'text', required: true },
    { key: 'connection.autoconnect', label: 'Auto Connect', type: 'select', options: ['yes', 'no'] },
    { key: 'connection.autoconnect-priority', label: 'Priority', type: 'number' },
    { key: 'connection.interface-name', label: 'Interface Name', type: 'text' }
  ],
  wifi: [
    { key: '802-11-wireless.ssid', label: 'SSID (Network Name)', type: 'text', required: true },
    { key: '802-11-wireless.mode', label: 'Mode', type: 'select', options: ['infrastructure', 'ap', 'adhoc', 'monitor'] },
    { key: '802-11-wireless.band', label: 'Band', type: 'select', options: ['a', 'bg'] },
    { key: '802-11-wireless.channel', label: 'Channel', type: 'number' },
    { key: '802-11-wireless.bssid', label: 'BSSID (Access Point MAC)', type: 'text' },
    { key: '802-11-wireless.mac-address', label: 'Device MAC Address', type: 'text' },
    { key: '802-11-wireless.cloned-mac-address', label: 'Cloned MAC Address', type: 'text' },
    { key: '802-11-wireless.hidden', label: 'Hidden Network', type: 'select', options: ['yes', 'no'] },
    { key: '802-11-wireless.mtu', label: 'MTU', type: 'text' }
  ],
  security: [
    { key: '802-11-wireless-security.key-mgmt', label: 'Security Type', type: 'select', options: ['none', 'wpa-psk', 'wpa-eap', 'wep', 'sae'] },
    { key: '802-11-wireless-security.psk', label: 'Password (WPA/WPA2)', type: 'password' },
    { key: '802-11-wireless-security.psk-flags', label: 'Password Flags', type: 'select', options: ['0', '1', '2'] },
    { key: '802-11-wireless-security.auth-alg', label: 'Authentication Algorithm', type: 'select', options: ['open', 'shared', 'leap'] },
    { key: '802-11-wireless-security.proto', label: 'WPA Protocol', type: 'select', options: ['wpa', 'rsn'] },
    { key: '802-11-wireless-security.pairwise', label: 'Pairwise Ciphers', type: 'select', options: ['tkip', 'ccmp'] },
    { key: '802-11-wireless-security.group', label: 'Group Ciphers', type: 'select', options: ['wep40', 'wep104', 'tkip', 'ccmp'] },
    { key: '802-11-wireless-security.pmf', label: 'Protected Management Frames', type: 'select', options: ['0', '1', '2'] },
    { key: '802-11-wireless-security.wep-key0', label: 'WEP Key 0', type: 'password' },
    { key: '802-11-wireless-security.wep-tx-keyidx', label: 'WEP TX Key Index', type: 'select', options: ['0', '1', '2', '3'] }
  ],
  network: [
    // Essential IPv4 Configuration
    { key: 'ipv4.method', label: 'IPv4 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local', 'shared'] },
    { key: 'ipv4.never-default', label: 'Never Default Route', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.addresses', label: 'Static IP Addresses', type: 'text' },
    { key: 'ipv4.gateway', label: 'Gateway', type: 'text' },
    { key: 'ipv4.dns', label: 'DNS Servers', type: 'text' },
    { key: 'ipv4.dns-search', label: 'DNS Search Domains', type: 'text' },
    { key: 'ipv4.ignore-auto-routes', label: 'Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.ignore-auto-dns', label: 'Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.may-fail', label: 'May Fail', type: 'select', options: ['yes', 'no'] },
    
    // Essential IPv6 Configuration  
    { key: 'ipv6.method', label: 'IPv6 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local', 'ignore'] },
    { key: 'ipv6.addresses', label: 'IPv6 Addresses', type: 'text' },
    { key: 'ipv6.gateway', label: 'IPv6 Gateway', type: 'text' },
    { key: 'ipv6.dns', label: 'IPv6 DNS Servers', type: 'text' },
    { key: 'ipv6.dns-search', label: 'IPv6 DNS Search', type: 'text' },
    { key: 'ipv6.never-default', label: 'IPv6 Never Default', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-routes', label: 'IPv6 Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-dns', label: 'IPv6 Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.may-fail', label: 'IPv6 May Fail', type: 'select', options: ['yes', 'no'] }
  ],
  advanced: [
    { key: '802-11-wireless.powersave', label: 'Power Save', type: 'select', options: ['0', '1', '2', '3'] },
    { key: '802-11-wireless.mac-address-randomization', label: 'MAC Randomization', type: 'select', options: ['default', 'never', 'always'] },
    { key: '802-11-wireless.wake-on-wlan', label: 'Wake on WLAN', type: 'text' },
    { key: '802-11-wireless.ap-isolation', label: 'AP Isolation', type: 'select', options: ['-1', '0', '1'] },
    { key: 'proxy.method', label: 'Proxy Method', type: 'select', options: ['none', 'auto', 'manual'] },
    { key: 'proxy.pac-url', label: 'PAC URL', type: 'text' }
  ]
};

// Static field configuration for Ethernet (802-3-ethernet) connections including USB ethernet
const ethernetFields = {
  general: [
    { key: 'connection.id', label: 'Connection Name', type: 'text', required: true },
    { key: 'connection.autoconnect', label: 'Auto Connect', type: 'select', options: ['yes', 'no'] },
    { key: 'connection.autoconnect-priority', label: 'Priority', type: 'number' },
    { key: 'connection.interface-name', label: 'Interface Name', type: 'text' }
  ],
  ethernet: [
    { key: '802-3-ethernet.speed', label: 'Link Speed (Mbps)', type: 'select', options: ['0', '10', '100', '1000', '2500', '5000', '10000'] },
    { key: '802-3-ethernet.duplex', label: 'Duplex Mode', type: 'select', options: ['half', 'full'] },
    { key: '802-3-ethernet.auto-negotiate', label: 'Auto Negotiate', type: 'select', options: ['yes', 'no'] },
    { key: '802-3-ethernet.port', label: 'Port Type', type: 'select', options: ['tp', 'aui', 'bnc', 'mii'] },
    { key: '802-3-ethernet.mac-address', label: 'Device MAC Address', type: 'text' },
    { key: '802-3-ethernet.cloned-mac-address', label: 'Cloned MAC Address', type: 'text' },
    { key: '802-3-ethernet.mtu', label: 'MTU', type: 'text' },
    { key: '802-3-ethernet.wake-on-lan', label: 'Wake on LAN', type: 'select', options: ['default', 'ignore', 'none', 'phy', 'unicast', 'multicast', 'broadcast', 'arp', 'magic'] },
    { key: '802-3-ethernet.wake-on-lan-password', label: 'WoL Password', type: 'password' },
    { key: '802-3-ethernet.accept-all-mac-addresses', label: 'Accept All MAC Addresses', type: 'select', options: ['-1', '0', '1'] }
  ],
  network: [
    // Essential IPv4 Configuration
    { key: 'ipv4.method', label: 'IPv4 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local', 'shared'] },
    { key: 'ipv4.never-default', label: 'Never Default Route', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.addresses', label: 'Static IP Addresses', type: 'text' },
    { key: 'ipv4.gateway', label: 'Gateway', type: 'text' },
    { key: 'ipv4.dns', label: 'DNS Servers', type: 'text' },
    { key: 'ipv4.dns-search', label: 'DNS Search Domains', type: 'text' },
    { key: 'ipv4.ignore-auto-routes', label: 'Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.ignore-auto-dns', label: 'Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.may-fail', label: 'May Fail', type: 'select', options: ['yes', 'no'] },
    
    // Essential IPv6 Configuration  
    { key: 'ipv6.method', label: 'IPv6 Method', type: 'select', options: ['auto', 'manual', 'disabled', 'link-local', 'ignore'] },
    { key: 'ipv6.addresses', label: 'IPv6 Addresses', type: 'text' },
    { key: 'ipv6.gateway', label: 'IPv6 Gateway', type: 'text' },
    { key: 'ipv6.dns', label: 'IPv6 DNS Servers', type: 'text' },
    { key: 'ipv6.dns-search', label: 'IPv6 DNS Search', type: 'text' },
    { key: 'ipv6.never-default', label: 'IPv6 Never Default', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-routes', label: 'IPv6 Ignore Auto Routes', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.ignore-auto-dns', label: 'IPv6 Ignore Auto DNS', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.may-fail', label: 'IPv6 May Fail', type: 'select', options: ['yes', 'no'] }
  ],
  advanced: [
    { key: 'ipv4.dhcp-client-id', label: 'DHCP Client ID', type: 'text' },
    { key: 'ipv4.dhcp-hostname', label: 'DHCP Hostname', type: 'text' },
    { key: 'ipv4.dhcp-send-hostname', label: 'Send Hostname via DHCP', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv4.dhcp-timeout', label: 'DHCP Timeout', type: 'number' },
    { key: 'ipv4.route-metric', label: 'Route Metric', type: 'number' },
    { key: 'ipv6.dhcp-hostname', label: 'IPv6 DHCP Hostname', type: 'text' },
    { key: 'ipv6.dhcp-send-hostname', label: 'Send IPv6 Hostname via DHCP', type: 'select', options: ['yes', 'no'] },
    { key: 'ipv6.dhcp-timeout', label: 'IPv6 DHCP Timeout', type: 'number' },
    { key: 'proxy.method', label: 'Proxy Method', type: 'select', options: ['none', 'auto', 'manual'] },
    { key: 'proxy.pac-url', label: 'PAC URL', type: 'text' }
  ]
};

function renderHansVpnTabs(data, container, uuid) {
  // Create field lookup map
  const fieldMap = new Map();
  data.details.forEach(row => fieldMap.set(row.key, row.val));
  
  // Calculate readonly fields count dynamically
  const editableKeys = new Set([
    ...hansVpnFields.general.map(f => f.key),
    ...hansVpnFields.vpn.map(f => f.key), 
    ...hansVpnFields.network.map(f => f.key),
    ...hansVpnFields.proxy.map(f => f.key)
  ]);
  const readonlyCount = data.details.filter(field => !editableKeys.has(field.key)).length;
  
  // Create tabbed interface
  container.innerHTML = `
    <ul class="nav nav-tabs" id="hansVpnTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" 
                type="button" role="tab" aria-controls="general" aria-selected="true">
          <i class="bi bi-gear me-1"></i>General
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="vpn-tab" data-bs-toggle="tab" data-bs-target="#vpn" 
                type="button" role="tab" aria-controls="vpn" aria-selected="false">
          <i class="bi bi-shield-lock me-1"></i>VPN Settings
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="network-tab" data-bs-toggle="tab" data-bs-target="#network" 
                type="button" role="tab" aria-controls="network" aria-selected="false">
          <i class="bi bi-ethernet me-1"></i>Network
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="proxy-tab" data-bs-toggle="tab" data-bs-target="#proxy" 
                type="button" role="tab" aria-controls="proxy" aria-selected="false">
          <i class="bi bi-globe me-1"></i>Proxy
        </button>
      </li>
    </ul>
    <div class="tab-content mt-3" id="hansVpnTabContent">
      <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
        ${renderFieldGroup(hansVpnFields.general, fieldMap)}
      </div>
      <div class="tab-pane fade" id="vpn" role="tabpanel" aria-labelledby="vpn-tab">
        ${renderFieldGroup(hansVpnFields.vpn, fieldMap)}
      </div>
      <div class="tab-pane fade" id="network" role="tabpanel" aria-labelledby="network-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-network-wired me-2"></i>IPv4 & IPv6 Configuration</h6>
            ${renderFieldGroup(hansVpnFields.network, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="proxy" role="tabpanel" aria-labelledby="proxy-tab">
        ${renderFieldGroup(hansVpnFields.proxy, fieldMap)}
      </div>
    </div>
    
    <!-- Additional Info Section -->
    <div class="mt-4">
      <div class="d-flex align-items-center mb-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" 
                data-bs-target="#additionalInfo" aria-expanded="false" aria-controls="additionalInfo">
          <i class="bi bi-info-circle me-1"></i>Additional Information <span class="badge bg-secondary ms-1">${readonlyCount}</span>
          <i class="bi bi-chevron-down ms-1"></i>
        </button>
      </div>
      <div class="collapse" id="additionalInfo">
        <div class="card card-body">
          <h6 class="text-muted mb-3">Read-only System Information</h6>
          ${renderReadOnlyFields(data.details, editableKeys)}
        </div>
      </div>
    </div>
  `;
  
  // Setup save handler for tabbed interface
  document.getElementById('saveEditBtn').onclick = async () => {
    const inputs = collectHansVpnInputs();
    const d2 = await api('save_conn_details', {uuid, rows: JSON.stringify(inputs)});
    if(d2.error) toast(d2.error, false); 
    else { 
      toast('Connection settings saved successfully'); 
      mInst.hide(); 
      loadUnifiedView(); 
    }
  };
}

function renderFieldGroup(fields, fieldMap) {
  return fields.map(field => {
    const value = fieldMap.get(field.key) || '';
    const escaped = value.replace(/"/g, '&quot;');
    const required = field.required ? 'required' : '';
    
    let input = '';
    switch (field.type) {
      case 'select':
        input = `<select class="form-control form-control-sm" data-field="${field.key}" ${required}>
          ${field.options.map(opt => 
            `<option value="${opt}" ${value === opt ? 'selected' : ''}>${opt}</option>`
          ).join('')}
        </select>`;
        break;
      case 'password':
        input = `<input type="password" class="form-control form-control-sm" 
                       data-field="${field.key}" value="${escaped}" ${required}>`;
        break;
      case 'number':
        input = `<input type="number" class="form-control form-control-sm" 
                       data-field="${field.key}" value="${escaped}" ${required}>`;
        break;
      default:
        input = `<input type="text" class="form-control form-control-sm" 
                       data-field="${field.key}" value="${escaped}" ${required}>`;
    }
    
    return `
      <div class="mb-3">
        <label class="form-label fw-semibold">${field.label}</label>
        ${input}
      </div>
    `;
  }).join('');
}

function renderReadOnlyFields(allFields, editableKeys = null) {
  // If editableKeys not provided, calculate from context (for backward compatibility)
  if (!editableKeys) {
    editableKeys = new Set([
      ...hansVpnFields.general.map(f => f.key),
      ...hansVpnFields.vpn.map(f => f.key), 
      ...hansVpnFields.network.map(f => f.key),
      ...hansVpnFields.proxy.map(f => f.key)
    ]);
  }
  
  // Show all fields that are NOT in editable categories
  const readonlyFields = allFields.filter(field => !editableKeys.has(field.key));
  
  return `
    <div class="row">
      ${readonlyFields.map(field => `
        <div class="col-md-6 mb-2">
          <div class="d-flex">
            <div class="text-muted fw-semibold me-2" style="min-width: 180px;">${field.key}:</div>
            <div><code class="cp-code-text">${field.val || '--'}</code></div>
          </div>
        </div>
      `).join('')}
    </div>
  `;
}

function collectHansVpnInputs() {
  const inputs = [];
  const allFields = [...hansVpnFields.general, ...hansVpnFields.vpn, ...hansVpnFields.network, ...hansVpnFields.proxy];
  
  allFields.forEach(field => {
    const element = document.querySelector(`[data-field="${field.key}"]`);
    if (element) {
      inputs.push({
        key: field.key,
        val: element.value
      });
    }
  });
  
  return inputs;
}

function renderTableInterface(data, container, uuid) {
  // Existing table interface code (unchanged)
  const tbl=document.createElement('table'); 
  tbl.className='table table-sm';
  
  // Group VPN data fields for better organization
  const vpnDataFields = [];
  const wifiSecurityFields = [];
  const wifiBasicFields = [];
  const networkFields = [];
  const otherFields = [];
  
  data.details.forEach(row => {
    if(row.key.startsWith('vpn.data.')) {
      vpnDataFields.push(row);
    } else if(row.key.startsWith('802-11-wireless-security.')) {
      wifiSecurityFields.push(row);
    } else if(row.key.startsWith('802-11-wireless.')) {
      wifiBasicFields.push(row);
    } else if(row.key.startsWith('ipv4.') || row.key.startsWith('ipv6.')) {
      networkFields.push(row);
    } else {
      otherFields.push(row);
    }
  });
  
  // Add basic connection fields first
  otherFields.forEach(row=>{
    const tr=document.createElement('tr');
    tr.innerHTML=`<td class='text-nowrap fw-semibold'>${row.key}</td><td><input type='text' class='form-control form-control-sm' value="${row.val.replace(/"/g,'&quot;')}"></td>`;
    tbl.append(tr);
  });
  
  // Add WiFi basic settings with separator if they exist
  if(wifiBasicFields.length > 0) {
    const separatorTr = document.createElement('tr');
    separatorTr.innerHTML = `<td colspan="2" class="bg-light text-muted fw-semibold p-2 border-top"><i class="bi bi-wifi me-2"></i>WiFi Settings</td>`;
    tbl.append(separatorTr);
    
    wifiBasicFields.forEach(row=>{
      const tr=document.createElement('tr');
      tr.innerHTML=`<td class='text-nowrap fw-semibold ps-3'>${row.key}</td><td><input type='text' class='form-control form-control-sm' value="${row.val.replace(/"/g,'&quot;')}"></td>`;
      tbl.append(tr);
    });
  }
  
  // Add WiFi security settings with separator if they exist  
  if(wifiSecurityFields.length > 0) {
    const separatorTr = document.createElement('tr');
    separatorTr.innerHTML = `<td colspan="2" class="bg-light text-muted fw-semibold p-2 border-top"><i class="bi bi-shield-lock me-2"></i>WiFi Security</td>`;
    tbl.append(separatorTr);
    
    wifiSecurityFields.forEach(row=>{
      const tr=document.createElement('tr');
      tr.innerHTML=`<td class='text-nowrap fw-semibold ps-3'>${row.key}</td><td><input type='text' class='form-control form-control-sm' value="${row.val.replace(/"/g,'&quot;')}"></td>`;
      tbl.append(tr);
    });
  }
  
  // Add network configuration with separator if they exist
  if(networkFields.length > 0) {
    const separatorTr = document.createElement('tr');
    separatorTr.innerHTML = `<td colspan="2" class="bg-light text-muted fw-semibold p-2 border-top"><i class="bi bi-ethernet me-2"></i>Network Configuration</td>`;
    tbl.append(separatorTr);
    
    networkFields.forEach(row=>{
      const tr=document.createElement('tr');
      tr.innerHTML=`<td class='text-nowrap fw-semibold ps-3'>${row.key}</td><td><input type='text' class='form-control form-control-sm' value="${row.val.replace(/"/g,'&quot;')}"></td>`;
      tbl.append(tr);
    });
  }
  
  // Add VPN data fields with a separator if they exist
  if(vpnDataFields.length > 0) {
    const separatorTr = document.createElement('tr');
    separatorTr.innerHTML = `<td colspan="2" class="bg-light text-muted fw-semibold p-2 border-top"><i class="bi bi-shield-lock me-2"></i>VPN Configuration</td>`;
    tbl.append(separatorTr);
    
    vpnDataFields.forEach(row=>{
      const tr=document.createElement('tr');
      const friendlyKey = row.key.replace('vpn.data.', '');
      tr.innerHTML=`<td class='text-nowrap fw-semibold ps-3'>${friendlyKey}</td><td><input type='text' class='form-control form-control-sm' value="${row.val.replace(/"/g,'&quot;')}"></td>`;
      tr.dataset.originalKey = row.key; // Store original key for saving
      tbl.append(tr);
    });
  }
  
  container.innerHTML=''; 
  container.append(tbl);

  // Setup save handler for table interface
  document.getElementById('saveEditBtn').onclick=async()=>{
    const inputs=[...tbl.querySelectorAll('tr')].map(tr=>{
      if(tr.children.length !== 2 || !tr.children[1].firstChild) return null;
      const key = tr.dataset.originalKey || tr.children[0].textContent.trim();
      const val = tr.children[1].firstChild.value;
      return {key, val};
    }).filter(Boolean);
    
    const d2=await api('save_conn_details',{uuid, rows:JSON.stringify(inputs)});
    if(d2.error) toast(d2.error,false); else { toast('Connection settings saved successfully'); mInst.hide(); loadUnifiedView(); }
  };
}

// Event delegation for mobile details expansion
document.addEventListener('click', e => {
  const expandBtn = e.target.closest('.cp-expand-btn');
  if (expandBtn) {
    const rowIdx = expandBtn.dataset.row;
    const detailsRow = document.querySelector(`#details-${rowIdx}`);
    const icon = expandBtn.querySelector('i');
    
    if (detailsRow.style.display === 'none') {
      detailsRow.style.display = 'table-row';
      icon.className = 'bi bi-chevron-up';
    } else {
      detailsRow.style.display = 'none';  
      icon.className = 'bi bi-chevron-down';
    }
  }
});

// Event handlers
document.getElementById('refreshBtn').addEventListener('click', loadUnifiedView);

// Event handlers for restored simple connection management
document.getElementById('refreshConnBtn').addEventListener('click', load);
document.getElementById('toggleViewBtn').addEventListener('click', function() {
  const isUnified = document.getElementById('simpleConnectionSection').style.display === 'none';
  if (isUnified) {
    switchToSimpleView();
  } else {
    switchToUnifiedView();
  }
});

// Initial load
loadUnifiedView();

// Auto-refresh every 30 seconds
setInterval(loadUnifiedView, 30000);

// ═══════════════════════════════════════════════════════════════════════════════
// RESTORED FUNCTIONS FROM BACKUP (Simple Connection Management)
// ═══════════════════════════════════════════════════════════════════════════════

function render(conns) {
  const tb = document.querySelector('#connTable tbody');
  tb.innerHTML = '';
  conns.forEach((c, idx) => {
    // Mobile-friendly action buttons
    const mobileActions = `
      <div class="cp-btn-group-mobile">
        <button class='btn btn-sm btn-outline-secondary w-100' title='Edit' onclick="openEdit('${c.uuid}')">
          <i class='bi bi-pencil-square me-1'></i>Edit
        </button>
        <button class='btn btn-sm btn-outline-success w-100' title='Up' onclick="act('up_conn','${c.uuid}')">
          <i class='bi bi-play me-1'></i>Up
        </button>
        <button class='btn btn-sm btn-outline-secondary w-100' title='Down' onclick="act('down_conn','${c.uuid}')">
          <i class='bi bi-stop me-1'></i>Down
        </button>
        <button class='btn btn-sm btn-outline-danger w-100' title='Delete' onclick="act('del_conn','${c.uuid}',true)">
          <i class='bi bi-trash me-1'></i>Delete
        </button>
      </div>
      <div class="cp-btn-group-desktop">
        <button class='btn btn-sm btn-outline-secondary me-1' title='Edit' onclick="openEdit('${c.uuid}')">
          <i class='bi bi-pencil-square'></i>
        </button>
        <button class='btn btn-sm btn-outline-success me-1' title='Up' onclick="act('up_conn','${c.uuid}')">
          <i class='bi bi-play'></i>
        </button>
        <button class='btn btn-sm btn-outline-secondary me-1' title='Down' onclick="act('down_conn','${c.uuid}')">
          <i class='bi bi-stop'></i>
        </button>
        <button class='btn btn-sm btn-outline-danger' title='Delete' onclick="act('del_conn','${c.uuid}',true)">
          <i class='bi bi-trash'></i>
        </button>
      </div>
    `;

    const tr = document.createElement('tr');
    tr.className = 'cp-table-row';
    tr.dataset.row = idx;
    tr.innerHTML = `<td class="fw-semibold">${c.name || ''}</td><td class="cp-col-sm"><code>${c.uuid}</code></td><td class="cp-col-md">${c.type}</td>` +
                   `<td class="cp-col-lg">${c.device}</td><td class="cp-col-md">${c.auto}</td>` +
                   `<td>${mobileActions}</td>` +
                   `<td class="d-table-cell d-sm-none">
                     <button class="cp-expand-btn" data-row="${idx}">
                       <i class="bi bi-chevron-down"></i>
                     </button>
                   </td>`;
    tb.append(tr);

    // Add mobile details row (hidden by default)
    const detailsRow = document.createElement('tr');
    detailsRow.className = 'cp-mobile-details d-sm-none';
    detailsRow.id = `details-${idx}`;
    detailsRow.style.display = 'none';
    detailsRow.innerHTML = `<td colspan="7" class="p-3">
       <div class="cp-details-grid">
         <div class="cp-detail-item"><div class="cp-detail-label">UUID:</div><div><code class="cp-code-text">${c.uuid}</code></div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Type:</div><div>${c.type}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Device:</div><div>${c.device}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Auto:</div><div>${c.auto}</div></div>
       </div>
     </td>`;
    tb.append(detailsRow);
  });
}

async function load() {
  const d = await api('list_conns');
  if (d.error) toast(d.error, false); else render(d.conns);
}

async function act(a, u, confirmDel = false) {
  if (confirmDel && !confirm('Delete connection?')) return;
  const d = await api(a, { uuid: u });
  if (d.error) {
    toast(d.error, false);
  } else {
    // More descriptive success messages
    const messages = {
      'up_conn': 'Connection activated successfully',
      'down_conn': 'Connection deactivated successfully',
      'del_conn': 'Connection deleted successfully'
    };
    toast(messages[a] || 'Operation completed successfully');
    load();
  }
}

// View switching functionality
function switchToUnifiedView() {
  document.getElementById('simpleConnectionSection').style.display = 'none';
  document.querySelectorAll('.cp-interface-section:not(#simpleConnectionSection)').forEach(el => el.style.display = 'block');
  document.getElementById('toggleViewBtn').textContent = 'Switch to Simple';
  loadUnifiedView();
}

function switchToSimpleView() {
  document.querySelectorAll('.cp-interface-section:not(#simpleConnectionSection)').forEach(el => el.style.display = 'none');
  document.getElementById('simpleConnectionSection').style.display = 'block';
  document.getElementById('toggleViewBtn').textContent = 'Switch to Unified';
  load();
}

function renderIodineVpnTabs(data, container, uuid) {
  // Create field lookup map
  const fieldMap = new Map();
  data.details.forEach(row => fieldMap.set(row.key, row.val));
  
  // Calculate readonly fields count dynamically
  const editableKeys = new Set([
    ...iodineVpnFields.general.map(f => f.key),
    ...iodineVpnFields.vpn.map(f => f.key), 
    ...iodineVpnFields.network.map(f => f.key),
    ...iodineVpnFields.proxy.map(f => f.key)
  ]);
  const readonlyCount = data.details.filter(field => !editableKeys.has(field.key)).length;
  
  // Create tabbed interface
  container.innerHTML = `
    <ul class="nav nav-tabs" id="iodineVpnTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" 
                type="button" role="tab" aria-controls="general" aria-selected="true">
          <i class="bi bi-gear me-1"></i>General
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="vpn-tab" data-bs-toggle="tab" data-bs-target="#vpn" 
                type="button" role="tab" aria-controls="vpn" aria-selected="false">
          <i class="bi bi-shield-lock me-1"></i>DNS Tunnel
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="network-tab" data-bs-toggle="tab" data-bs-target="#network" 
                type="button" role="tab" aria-controls="network" aria-selected="false">
          <i class="bi bi-ethernet me-1"></i>Network
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="proxy-tab" data-bs-toggle="tab" data-bs-target="#proxy" 
                type="button" role="tab" aria-controls="proxy" aria-selected="false">
          <i class="bi bi-globe me-1"></i>Proxy
        </button>
      </li>
    </ul>
    <div class="tab-content mt-3" id="iodineVpnTabContent">
      <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
        ${renderFieldGroup(iodineVpnFields.general, fieldMap)}
      </div>
      <div class="tab-pane fade" id="vpn" role="tabpanel" aria-labelledby="vpn-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-dns me-2"></i>DNS Tunnel Configuration</h6>
            ${renderFieldGroup(iodineVpnFields.vpn, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="network" role="tabpanel" aria-labelledby="network-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-network-wired me-2"></i>IPv4 & IPv6 Configuration</h6>
            ${renderFieldGroup(iodineVpnFields.network, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="proxy" role="tabpanel" aria-labelledby="proxy-tab">
        ${renderFieldGroup(iodineVpnFields.proxy, fieldMap)}
      </div>
    </div>
    
    <!-- Additional Info Section -->
    <div class="mt-4">
      <div class="d-flex align-items-center mb-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" 
                data-bs-target="#additionalInfo" aria-expanded="false" aria-controls="additionalInfo">
          <i class="bi bi-info-circle me-1"></i>Additional Information <span class="badge bg-secondary ms-1">${readonlyCount}</span>
          <i class="bi bi-chevron-down ms-1"></i>
        </button>
      </div>
      <div class="collapse" id="additionalInfo">
        <div class="card card-body">
          <h6 class="text-muted mb-3">Read-only System Information</h6>
          ${renderReadOnlyFields(data.details, editableKeys)}
        </div>
      </div>
    </div>
  `;
  
  // Setup save handler for tabbed interface
  document.getElementById('saveEditBtn').onclick = async () => {
    const inputs = collectIodineVpnInputs();
    const d2 = await api('save_conn_details', {uuid, rows: JSON.stringify(inputs)});
    if(d2.error) toast(d2.error, false); 
    else { 
      toast('Connection settings saved successfully'); 
      mInst.hide(); 
      loadUnifiedView(); 
    }
  };
}

function collectIodineVpnInputs() {
  const inputs = [];
  const allFields = [...iodineVpnFields.general, ...iodineVpnFields.vpn, ...iodineVpnFields.network, ...iodineVpnFields.proxy];
  
  allFields.forEach(field => {
    const element = document.querySelector(`[data-field="${field.key}"]`);
    if (element) {
      inputs.push({
        key: field.key,
        val: element.value
      });
    }
  });
  
  return inputs;
}

function renderWifiTabs(data, container, uuid) {
  // Create field lookup map
  const fieldMap = new Map();
  data.details.forEach(row => fieldMap.set(row.key, row.val));
  
  // Calculate readonly fields count dynamically
  const editableKeys = new Set([
    ...wifiFields.general.map(f => f.key),
    ...wifiFields.wifi.map(f => f.key), 
    ...wifiFields.security.map(f => f.key),
    ...wifiFields.network.map(f => f.key),
    ...wifiFields.advanced.map(f => f.key)
  ]);
  const readonlyCount = data.details.filter(field => !editableKeys.has(field.key)).length;
  
  // Determine WiFi mode for better tab labeling
  const mode = fieldMap.get('802-11-wireless.mode') || 'infrastructure';
  const modeLabel = mode === 'ap' ? 'Access Point' : mode === 'adhoc' ? 'Ad-Hoc' : 'Station';
  
  // Create tabbed interface
  container.innerHTML = `
    <ul class="nav nav-tabs" id="wifiTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" 
                type="button" role="tab" aria-controls="general" aria-selected="true">
          <i class="bi bi-gear me-1"></i>General
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="wifi-tab" data-bs-toggle="tab" data-bs-target="#wifi" 
                type="button" role="tab" aria-controls="wifi" aria-selected="false">
          <i class="bi bi-wifi me-1"></i>WiFi Settings
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" 
                type="button" role="tab" aria-controls="security" aria-selected="false">
          <i class="bi bi-shield-lock me-1"></i>Security
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="network-tab" data-bs-toggle="tab" data-bs-target="#network" 
                type="button" role="tab" aria-controls="network" aria-selected="false">
          <i class="bi bi-ethernet me-1"></i>Network
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="advanced-tab" data-bs-toggle="tab" data-bs-target="#advanced" 
                type="button" role="tab" aria-controls="advanced" aria-selected="false">
          <i class="bi bi-sliders me-1"></i>Advanced
        </button>
      </li>
    </ul>
    <div class="tab-content mt-3" id="wifiTabContent">
      <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
        ${renderFieldGroup(wifiFields.general, fieldMap)}
      </div>
      <div class="tab-pane fade" id="wifi" role="tabpanel" aria-labelledby="wifi-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-broadcast me-2"></i>Wireless Configuration (${modeLabel})</h6>
            ${renderFieldGroup(wifiFields.wifi, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-key me-2"></i>Authentication & Encryption</h6>
            ${renderFieldGroup(wifiFields.security, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="network" role="tabpanel" aria-labelledby="network-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-network-wired me-2"></i>IPv4 & IPv6 Configuration</h6>
            ${renderFieldGroup(wifiFields.network, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="advanced" role="tabpanel" aria-labelledby="advanced-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-cpu me-2"></i>Power Management & Advanced Settings</h6>
            ${renderFieldGroup(wifiFields.advanced, fieldMap)}
          </div>
        </div>
      </div>
    </div>
    
    <!-- Additional Info Section -->
    <div class="mt-4">
      <div class="d-flex align-items-center mb-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" 
                data-bs-target="#additionalInfo" aria-expanded="false" aria-controls="additionalInfo">
          <i class="bi bi-info-circle me-1"></i>Additional Information <span class="badge bg-secondary ms-1">${readonlyCount}</span>
          <i class="bi bi-chevron-down ms-1"></i>
        </button>
      </div>
      <div class="collapse" id="additionalInfo">
        <div class="card card-body">
          <h6 class="text-muted mb-3">Read-only System Information</h6>
          ${renderReadOnlyFields(data.details, editableKeys)}
        </div>
      </div>
    </div>
  `;
  
  // Setup save handler for tabbed interface
  document.getElementById('saveEditBtn').onclick = async () => {
    const inputs = collectWifiInputs();
    const d2 = await api('save_conn_details', {uuid, rows: JSON.stringify(inputs)});
    if(d2.error) toast(d2.error, false); 
    else { 
      toast('WiFi connection settings saved successfully'); 
      mInst.hide(); 
      loadUnifiedView(); 
    }
  };
}

function collectWifiInputs() {
  const inputs = [];
  const allFields = [...wifiFields.general, ...wifiFields.wifi, ...wifiFields.security, ...wifiFields.network, ...wifiFields.advanced];
  
  allFields.forEach(field => {
    const element = document.querySelector(`[data-field="${field.key}"]`);
    if (element) {
      inputs.push({
        key: field.key,
        val: element.value
      });
    }
  });
  
  return inputs;
}

function renderEthernetTabs(data, container, uuid) {
  // Create field lookup map
  const fieldMap = new Map();
  data.details.forEach(row => fieldMap.set(row.key, row.val));
  
  // Calculate readonly fields count dynamically
  const editableKeys = new Set([
    ...ethernetFields.general.map(f => f.key),
    ...ethernetFields.ethernet.map(f => f.key), 
    ...ethernetFields.network.map(f => f.key),
    ...ethernetFields.advanced.map(f => f.key)
  ]);
  const readonlyCount = data.details.filter(field => !editableKeys.has(field.key)).length;
  
  // Determine interface type for better labeling
  const interfaceName = fieldMap.get('connection.interface-name') || '';
  const isUSB = interfaceName.startsWith('usb') || interfaceName.includes('usb');
  const connectionLabel = isUSB ? 'USB Ethernet' : 'Ethernet';
  
  // Create tabbed interface
  container.innerHTML = `
    <ul class="nav nav-tabs" id="ethernetTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" 
                type="button" role="tab" aria-controls="general" aria-selected="true">
          <i class="bi bi-gear me-1"></i>General
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="ethernet-tab" data-bs-toggle="tab" data-bs-target="#ethernet" 
                type="button" role="tab" aria-controls="ethernet" aria-selected="false">
          <i class="bi bi-ethernet me-1"></i>Hardware
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="network-tab" data-bs-toggle="tab" data-bs-target="#network" 
                type="button" role="tab" aria-controls="network" aria-selected="false">
          <i class="bi bi-hdd-network me-1"></i>Network
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="advanced-tab" data-bs-toggle="tab" data-bs-target="#advanced" 
                type="button" role="tab" aria-controls="advanced" aria-selected="false">
          <i class="bi bi-sliders me-1"></i>Advanced
        </button>
      </li>
    </ul>
    <div class="tab-content mt-3" id="ethernetTabContent">
      <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
        ${renderFieldGroup(ethernetFields.general, fieldMap)}
      </div>
      <div class="tab-pane fade" id="ethernet" role="tabpanel" aria-labelledby="ethernet-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-plug me-2"></i>${connectionLabel} Hardware Configuration</h6>
            ${renderFieldGroup(ethernetFields.ethernet, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="network" role="tabpanel" aria-labelledby="network-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-network-wired me-2"></i>IPv4 & IPv6 Configuration</h6>
            ${renderFieldGroup(ethernetFields.network, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="advanced" role="tabpanel" aria-labelledby="advanced-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-router me-2"></i>DHCP, Routing & Advanced Settings</h6>
            ${renderFieldGroup(ethernetFields.advanced, fieldMap)}
          </div>
        </div>
      </div>
    </div>
    
    <!-- Additional Info Section -->
    <div class="mt-4">
      <div class="d-flex align-items-center mb-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" 
                data-bs-target="#additionalInfo" aria-expanded="false" aria-controls="additionalInfo">
          <i class="bi bi-info-circle me-1"></i>Additional Information <span class="badge bg-secondary ms-1">${readonlyCount}</span>
          <i class="bi bi-chevron-down ms-1"></i>
        </button>
      </div>
      <div class="collapse" id="additionalInfo">
        <div class="card card-body">
          <h6 class="text-muted mb-3">Read-only System Information</h6>
          ${renderReadOnlyFields(data.details, editableKeys)}
        </div>
      </div>
    </div>
  `;
  
  // Setup save handler for tabbed interface
  document.getElementById('saveEditBtn').onclick = async () => {
    const inputs = collectEthernetInputs();
    const d2 = await api('save_conn_details', {uuid, rows: JSON.stringify(inputs)});
    if(d2.error) toast(d2.error, false); 
    else { 
      toast(`${connectionLabel} connection settings saved successfully`); 
      mInst.hide(); 
      loadUnifiedView(); 
    }
  };
}

function collectEthernetInputs() {
  const inputs = [];
  const allFields = [...ethernetFields.general, ...ethernetFields.ethernet, ...ethernetFields.network, ...ethernetFields.advanced];
  
  allFields.forEach(field => {
    const element = document.querySelector(`[data-field="${field.key}"]`);
    if (element) {
      inputs.push({
        key: field.key,
        val: element.value
      });
    }
  });
  
  return inputs;
}

function renderOpenVpnTabs(data, container, uuid) {
  // Create field lookup map
  const fieldMap = new Map();
  data.details.forEach(row => fieldMap.set(row.key, row.val));
  
  // Calculate readonly fields count dynamically
  const editableKeys = new Set([
    ...openVpnFields.general.map(f => f.key),
    ...openVpnFields.openvpn.map(f => f.key),
    ...openVpnFields.advanced.map(f => f.key), 
    ...openVpnFields.network.map(f => f.key),
    ...openVpnFields.proxy.map(f => f.key)
  ]);
  const readonlyCount = data.details.filter(field => !editableKeys.has(field.key)).length;
  
  // Create tabbed interface
  container.innerHTML = `
    <ul class="nav nav-tabs" id="openVpnTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" 
                type="button" role="tab" aria-controls="general" aria-selected="true">
          <i class="bi bi-gear me-1"></i>General
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="openvpn-tab" data-bs-toggle="tab" data-bs-target="#openvpn" 
                type="button" role="tab" aria-controls="openvpn" aria-selected="false">
          <i class="bi bi-shield-lock me-1"></i>OpenVPN
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="advanced-tab" data-bs-toggle="tab" data-bs-target="#advanced" 
                type="button" role="tab" aria-controls="advanced" aria-selected="false">
          <i class="bi bi-sliders me-1"></i>Advanced
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="network-tab" data-bs-toggle="tab" data-bs-target="#network" 
                type="button" role="tab" aria-controls="network" aria-selected="false">
          <i class="bi bi-ethernet me-1"></i>Network
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="proxy-tab" data-bs-toggle="tab" data-bs-target="#proxy" 
                type="button" role="tab" aria-controls="proxy" aria-selected="false">
          <i class="bi bi-globe me-1"></i>Proxy
        </button>
      </li>
    </ul>
    <div class="tab-content mt-3" id="openVpnTabContent">
      <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
        ${renderFieldGroup(openVpnFields.general, fieldMap)}
      </div>
      <div class="tab-pane fade" id="openvpn" role="tabpanel" aria-labelledby="openvpn-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-key me-2"></i>Authentication & Connection</h6>
            ${renderFieldGroup(openVpnFields.openvpn, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="advanced" role="tabpanel" aria-labelledby="advanced-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-cpu me-2"></i>Protocol & Encryption Settings</h6>
            ${renderFieldGroup(openVpnFields.advanced, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="network" role="tabpanel" aria-labelledby="network-tab">
        <div class="row">
          <div class="col-12">
            <h6 class="text-muted mb-3"><i class="bi bi-network-wired me-2"></i>IPv4 & IPv6 Configuration</h6>
            ${renderFieldGroup(openVpnFields.network, fieldMap)}
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="proxy" role="tabpanel" aria-labelledby="proxy-tab">
        ${renderFieldGroup(openVpnFields.proxy, fieldMap)}
      </div>
    </div>
    
    <!-- Additional Info Section -->
    <div class="mt-4">
      <div class="d-flex align-items-center mb-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" 
                data-bs-target="#additionalInfo" aria-expanded="false" aria-controls="additionalInfo">
          <i class="bi bi-info-circle me-1"></i>Additional Information <span class="badge bg-secondary ms-1">${readonlyCount}</span>
          <i class="bi bi-chevron-down ms-1"></i>
        </button>
      </div>
      <div class="collapse" id="additionalInfo">
        <div class="card card-body">
          <h6 class="text-muted mb-3">Read-only System Information</h6>
          ${renderReadOnlyFields(data.details, editableKeys)}
        </div>
      </div>
    </div>
  `;
  
  // Setup save handler for tabbed interface
  document.getElementById('saveEditBtn').onclick = async () => {
    const inputs = collectOpenVpnInputs();
    const d2 = await api('save_conn_details', {uuid, rows: JSON.stringify(inputs)});
    if(d2.error) toast(d2.error, false); 
    else { 
      toast('OpenVPN connection settings saved successfully'); 
      mInst.hide(); 
      loadUnifiedView(); 
    }
  };
}

function collectOpenVpnInputs() {
  const inputs = [];
  const allFields = [...openVpnFields.general, ...openVpnFields.openvpn, ...openVpnFields.advanced, ...openVpnFields.network, ...openVpnFields.proxy];
  
  allFields.forEach(field => {
    const element = document.querySelector(`[data-field="${field.key}"]`);
    if (element) {
      inputs.push({
        key: field.key,
        val: element.value
      });
    }
  });
  
  return inputs;
}
</script>