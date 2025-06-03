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
                case '802-3-ethernet':
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
        
        // Get basic connection details
        nm_exec('nmcli -t -s connection show uuid %s', [$uuid], $out, $rc);
        if ($rc !== 0) bad('Failed to retrieve connection details');
        
        $details = [];
        foreach ($out as $ln) {
            if (!str_contains($ln, ':')) continue;
            [$k, $v] = explode(':', $ln, 2);
            $key = trim($k);
            $val = trim($v);
            
            // Filter to show only relevant fields
            if (preg_match('/^(connection\.|ipv4\.|ipv6\.|802-11-wireless\.|vpn\.)/', $key)) {
                $details[] = ['key' => $key, 'val' => $val];
            }
        }
        
        json_out(['details' => $details, 'type' => $connType]);
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
          <button class='btn btn-sm btn-outline-secondary w-100' onclick="editConnection('${conn.uuid}')">
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
          <button class='btn btn-sm btn-outline-secondary me-1' title='Edit' onclick="editConnection('${conn.uuid}')">
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
          <button class='btn btn-sm btn-outline-secondary w-100' onclick="editConnection('${conn.uuid}')">
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
          <button class='btn btn-sm btn-outline-secondary me-1' title='Edit' onclick="editConnection('${conn.uuid}')">
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

function editConnection(uuid) {
  // Use existing edit modal pattern (simplified for now)
  toast('Connection editing - feature coming soon!', true);
}

async function showConnectionInfo(uuid) {
  const result = await api('get_conn_details', { uuid: uuid });
  if (result.error) {
    toast(result.error, false);
    return;
  }
  
  // Create modal if it doesn't exist
  let modal = document.getElementById('infoModal');
  if (!modal) {
    document.body.insertAdjacentHTML('beforeend', `
      <div class="modal fade cp-modal-responsive" id="infoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="infoModalTitle">Connection Information</h5>
              <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="infoModalBody"></div>
            <div class="modal-footer">
              <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>
    `);
    modal = document.getElementById('infoModal');
  }
  
  const title = document.getElementById('infoModalTitle');
  const body = document.getElementById('infoModalBody');
  
  const connType = result.type || 'Unknown';
  title.textContent = `Connection Information - ${connType.charAt(0).toUpperCase() + connType.slice(1)}`;
  
  // Display read-only connection details
  let content = `
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      This is an auto-created interface connection managed by the system.
    </div>
    <table class="table table-sm">
  `;
  
  result.details.forEach(detail => {
    content += `
      <tr>
        <td class="text-nowrap fw-semibold" style="width: 30%;">${detail.key}</td>
        <td><code class="cp-code-text">${detail.val || '--'}</code></td>
      </tr>
    `;
  });
  
  content += '</table>';
  body.innerHTML = content;
  
  new bootstrap.Modal(modal).show();
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

// Initial load
loadUnifiedView();

// Auto-refresh every 30 seconds
setInterval(loadUnifiedView, 30000);
</script>