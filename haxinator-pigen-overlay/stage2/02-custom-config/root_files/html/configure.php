<?php
// Include security framework
require_once __DIR__ . '/security/bootstrap.php';

session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: /index.php');
    exit;
}

// Include data class for interface status
require_once __DIR__ . '/data/Data.php';
require_once __DIR__ . '/data/Util.php';
$data = new Data();

// Get network status using the Util class
$public_ip = Util::getPublicIp();
$hostname = gethostname();
$dns_ok = Util::dnsResolvesGoogle();
$ping_ok = Util::pingGoogle();

// Function to get NetworkManager connections with full details
function getNetworkConnections() {
    $connections = [];
    $output = [];
    
    // Use secure command execution
    $output = SecureCommand::executeWithOutput('nmcli -t -f NAME,UUID,TYPE,DEVICE con show');
    
    foreach ($output as $line) {
        $parts = explode(':', $line);
        
        if (count($parts) === 4) {
            list($name, $uuid, $type, $device) = $parts;
            
            // Skip loopback and VPN connections
            if ($type === 'loopback' || $type === 'vpn') {
                continue;
            }
            
            // Get MAC randomization status
            $macDetails = [];
            $macOutput = [];
            
            // Always get the configuration
            if ($type === '802-11-wireless') {
                $macOutput = SecureCommand::executeWithOutput('nmcli -g 802-11-wireless.mac-address-randomization connection show %s', [$uuid]);
                $macDetails['type'] = 'wifi';
                $macDetails['randomization'] = !empty($macOutput[0]) ? $macOutput[0] : 'default';
            } elseif ($type === '802-3-ethernet') {
                $macOutput = SecureCommand::executeWithOutput('nmcli -g 802-3-ethernet.cloned-mac-address connection show %s', [$uuid]);
                $macDetails['type'] = 'ethernet';
                $macDetails['randomization'] = !empty($macOutput[0]) && $macOutput[0] === 'random' ? 'always' : 'default';
            }
            
            // Only try to get MAC hardware details if the device is connected/active
            if ($device !== '--' && !empty($device)) {
                // Get current MAC from ip link
                $currentMacOutput = SecureCommand::executeWithOutput("ip link show %s | grep 'link/ether' | cut -d' ' -f6", [$device]);
                $macDetails['current_mac'] = !empty($currentMacOutput[0]) ? $currentMacOutput[0] : '';
                
                // Get permanent MAC from sysfs
                $permMacOutput = SecureCommand::executeWithOutput("cat /sys/class/net/%s/address 2>/dev/null || echo ''", [$device]);
                $macDetails['permanent_mac'] = !empty($permMacOutput[0]) ? $permMacOutput[0] : $macDetails['current_mac'];
            } else {
                // For inactive connections, try to get the MAC from sysfs if device exists
                if ($type === '802-3-ethernet') {
                    $fallbackMac = SecureCommand::executeWithOutput("cat /sys/class/net/eth0/address 2>/dev/null || echo '00:00:00:00:00:00'");
                    $macDetails['current_mac'] = !empty($fallbackMac[0]) ? $fallbackMac[0] : '00:00:00:00:00:00';
                    $macDetails['permanent_mac'] = $macDetails['current_mac'];
                }
            }
            
            // Get basic connection details
            $details = [];
            $detailOutput = SecureCommand::executeWithOutput("nmcli -f ipv4.addresses,802-11-wireless.ssid,connection.timestamp con show %s | grep -v '^$'", [$uuid]);
            
            foreach ($detailOutput as $detail) {
                if (strpos($detail, 'ipv4.addresses:') !== false) {
                    $details['ip'] = trim(explode(':', $detail, 2)[1]);
                }
                if (strpos($detail, '802-11-wireless.ssid:') !== false) {
                    $details['ssid'] = trim(explode(':', $detail, 2)[1]);
                }
                if (strpos($detail, 'connection.timestamp:') !== false) {
                    $timestamp = trim(explode(':', $detail, 2)[1]);
                    $details['last_connected'] = $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : 'Never';
                }
            }

            // If no IP from NetworkManager, try ip addr command
            if (empty($details['ip']) && !empty($device) && $device !== '--') {
                $ipOutput = SecureCommand::executeWithOutput("ip addr show %s | grep 'inet ' | awk '{print \$2}'", [$device]);
                if (!empty($ipOutput[0])) {
                    $details['ip'] = $ipOutput[0];
                }
            }

            // Get full connection details
            $fullDetails = [];
            $fullOutput = SecureCommand::executeWithOutput("nmcli -f all con show %s", [$uuid]);
            
            $currentSection = '';
            foreach ($fullOutput as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                if (strpos($line, ':') !== false) {
                    list($key, $value) = array_map('trim', explode(':', $line, 2));
                    
                    $section = strpos($key, '.') !== false ? substr($key, 0, strpos($key, '.')) : 'general';
                    $paramName = strpos($key, '.') !== false ? substr($key, strpos($key, '.') + 1) : $key;
                    
                    if (!isset($fullDetails[$section])) {
                        $fullDetails[$section] = [];
                    }
                    
                    if ($value !== '--' && $value !== '' && !preg_match('/\(default\)$/', $value)) {
                        $fullDetails[$section][$paramName] = $value;
                    }
                }
            }
            
            $connections[$uuid] = [
                'name' => $name,
                'uuid' => $uuid,
                'type' => $type,
                'device' => $device,
                'details' => $details,
                'full_details' => $fullDetails,
                'mac_details' => $macDetails,
                'status' => $device !== '--' ? 'active' : 'inactive'
            ];
        }
    }
    
    ksort($connections);
    return $connections;
}

// Handle MAC randomization toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_mac') {
    // Validate CSRF token
    CSRFProtection::enforceCheck();
    
    $uuid = $_POST['uuid'] ?? '';
    $type = $_POST['type'] ?? '';
    $current = $_POST['current'] ?? 'default';
    
    // Validate input
    if (!InputValidator::uuid($uuid)) {
        $_SESSION['error'] = "Invalid UUID format";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if (!in_array($type, ['wifi', 'ethernet'])) {
        $_SESSION['error'] = "Invalid connection type";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if (!in_array($current, ['default', 'always'])) {
        $_SESSION['error'] = "Invalid randomization setting";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($uuid && $type) {
        if ($type === 'wifi') {
            if ($current === 'default') {
                SecureCommand::nmcli("connection modify %s 802-11-wireless.mac-address-randomization always 802-11-wireless.cloned-mac-address random", [$uuid]);
            } else {
                SecureCommand::nmcli("connection modify %s 802-11-wireless.mac-address-randomization default 802-11-wireless.cloned-mac-address ''", [$uuid]);
            }
        } elseif ($type === 'ethernet') {
            $newValue = $current === 'default' ? 'random' : '--';
            SecureCommand::nmcli("connection modify %s 802-3-ethernet.cloned-mac-address %s", [$uuid, $newValue]);
        }
        
        // Restart connection to apply changes
        SecureCommand::restartConnection($uuid);
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Handle MAC address changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_mac') {
    // Validate CSRF token
    CSRFProtection::enforceCheck();
    
    $uuid = $_POST['uuid'] ?? '';
    $type = $_POST['type'] ?? '';
    $mode = $_POST['mac_mode'] ?? '';
    $customMac = $_POST['custom_mac'] ?? '';
    
    // Validate input
    if (!InputValidator::uuid($uuid)) {
        $_SESSION['error'] = "Invalid UUID format";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if (!in_array($type, ['wifi', 'ethernet'])) {
        $_SESSION['error'] = "Invalid connection type";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if (!in_array($mode, ['permanent', 'random', 'custom'])) {
        $_SESSION['error'] = "Invalid MAC address mode";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($uuid && $type) {
        $error = '';
        
        if ($mode === 'custom' && !InputValidator::mac($customMac)) {
            $error = 'Invalid MAC address format or value';
        } else {
            // Process based on mode
            switch($mode) {
                case 'permanent':
                    if ($type === 'wifi') {
                        SecureCommand::nmcli("connection modify %s 802-11-wireless.mac-address-randomization default 802-11-wireless.cloned-mac-address ''", [$uuid]);
                    } else {
                        SecureCommand::nmcli("connection modify %s 802-3-ethernet.cloned-mac-address ''", [$uuid]);
                    }
                    break;
                    
                case 'random':
                    if ($type === 'wifi') {
                        SecureCommand::nmcli("connection modify %s 802-11-wireless.mac-address-randomization always 802-11-wireless.cloned-mac-address random", [$uuid]);
                    } else {
                        SecureCommand::nmcli("connection modify %s 802-3-ethernet.cloned-mac-address random", [$uuid]);
                    }
                    break;
                    
                case 'custom':
                    $mac = strtoupper($customMac);
                    if ($type === 'wifi') {
                        SecureCommand::nmcli("connection modify %s 802-11-wireless.mac-address-randomization default 802-11-wireless.cloned-mac-address %s", [$uuid, $mac]);
                    } else {
                        SecureCommand::nmcli("connection modify %s 802-3-ethernet.cloned-mac-address %s", [$uuid, $mac]);
                    }
                    break;
            }
            
            // Restart connection to apply changes
            SecureCommand::restartConnection($uuid);
        }
        
        if ($error) {
            $_SESSION['error'] = $error;
        } else {
            $_SESSION['message'] = 'MAC address settings updated successfully';
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get network connections
$connections = getNetworkConnections();

// Handle system configuration logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    CSRFProtection::enforceCheck();
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'toggle_readonly':
                $message = 'System mode toggled';
                break;
            case 'load_secrets':
                $message = 'Secrets loaded';
                break;
        }
    }
}

// Set up message handling
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';

// Clear message from session
unset($_SESSION['message']);
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Haxinator 2000 - Configuration</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet" />
    <link href="/css/theme.css" rel="stylesheet" />
    <link href="/css/configure.css" rel="stylesheet" />
    <link rel="stylesheet" href="/css/bootstrap-icons/bootstrap-icons.min.css">
</head>
<body>
    <div class="mac-spinner" id="macSpinner">
        <div class="mac-spinner-content">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mac-spinner-text">Changing MAC address settings...</div>
        </div>
    </div>

    <div class="topbar sticky-top">
      <div class="topbar-left">
        <div class="topbar-logo">X</div>
        <h1 class="topbar-title">Haxinator 2000</h1>
      </div>
      <div class="topbar-center">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-hdd-network"></i>
          <span><?= htmlspecialchars($hostname) ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <div class="status-group">
          <?php if ($ping_ok): ?>
            <span title="Ping to 8.8.8.8 successful" class="status-indicator">
              <i class="bi bi-door-open" style="color:#22c55e;"></i>
              <span class="status-label">ping</span>
            </span>
          <?php else: ?>
            <span title="Ping to 8.8.8.8 failed" class="status-indicator">
              <i class="bi bi-door-closed" style="color:#f4427d;"></i>
              <span class="status-label">ping</span>
            </span>
          <?php endif; ?>
          <?php if ($public_ip): ?>
            <span title="Internet Connected" class="status-indicator">
              <i class="bi bi-globe2" style="color:#22c55e;"></i>
              <span class="status-label">web</span>
            </span>
          <?php else: ?>
            <span title="No Internet" class="status-indicator">
              <i class="bi bi-globe2" style="color:#f59e42;"></i>
              <span class="status-label">web</span>
            </span>
          <?php endif; ?>
          <?php if ($dns_ok): ?>
            <span title="DNS resolves google.com" class="status-indicator">
              <i class="bi bi-plugin" style="color:#22c55e;"></i>
              <span class="status-label">dns</span>
            </span>
          <?php else: ?>
            <span title="DNS does not resolve google.com" class="status-indicator">
              <i class="bi bi-plug" style="color:#f4427d;"></i>
              <span class="status-label">dns</span>
            </span>
          <?php endif; ?>
        </div>
        <a href="https://<?php echo $_SERVER['SERVER_ADDR']; ?>:4200" target="_blank" class="btn btn-outline-secondary btn-sm ms-3 btn-icon"><i class="bi bi-terminal"></i> <span class="d-none d-md-inline">Terminal</span></a>
        <a href="/index.php" class="btn btn-outline-secondary btn-sm ms-2 btn-icon"><i class="bi bi-house"></i> <span class="d-none d-md-inline">Dashboard</span></a>
        <button onclick="shutdownSystem()" class="btn btn-outline-danger btn-sm ms-2 btn-icon"><i class="bi bi-power"></i> <span class="d-none d-md-inline">Shutdown</span></button>
        <form method="get" action="/index.php" class="d-inline ms-2">
          <button type="submit" name="logout" class="btn btn-outline-secondary btn-sm btn-icon"><i class="bi bi-box-arrow-right"></i> <span class="d-none d-md-inline">Logout</span></button>
        </form>
      </div>
    </div>

    <div class="interface-bar">
      <div class="interface-status">
        <?php 
        try {
          $interfaces = $data->getTopBarInterfaces();
          // Add public IP as external interface if online
          if ($public_ip) {
            $interfaces[] = [
              'name' => 'ext',
              'connected' => true,
              'icon' => 'bi-globe',
              'ipv4' => $public_ip
            ];
          }
          foreach ($interfaces as $iface): ?>
            <div class="interface-item <?= $iface['connected'] ? 'connected' : 'disconnected' ?>">
              <i class="bi <?= htmlspecialchars($iface['icon']) ?>"></i>
              <span class="interface-name"><?= htmlspecialchars($iface['name']) ?></span>
              <?php if ($iface['ipv4']): ?>
                <span class="interface-ip"><?= htmlspecialchars($iface['ipv4']) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach;
        } catch (Exception $e) {
          error_log("Error in interface status: " . $e->getMessage());
        }
        ?>
      </div>
    </div>

    <div class="nm-main-container mx-auto px-3 px-md-4" style="max-width:1100px; margin-bottom: 40px;">
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="mac-tab" data-bs-toggle="tab" data-bs-target="#mac" type="button" role="tab">
                    <i class="bi bi-ethernet me-2"></i>MAC Randomization
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload" type="button" role="tab">
                    <i class="bi bi-upload me-2"></i>Upload Configs
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="system-tab" data-bs-toggle="tab" data-bs-target="#system" type="button" role="tab">
                    <i class="bi bi-gear me-2"></i>System Configuration
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- MAC Randomization Tab -->
            <div class="tab-pane fade show active" id="mac" role="tabpanel">
                <div class="config-card">
                    <div class="config-section">
                        <div class="config-title">Network Interfaces</div>
                        <div class="config-description">Manage network interfaces and MAC address settings.</div>
                        
                        <?php
                        // Filter to only show network interfaces that support MAC configuration
                        $networkInterfaces = [];
                        
                        foreach ($connections as $uuid => $conn) {
                            // Only include WiFi, Ethernet, and USB interfaces
                            if ($conn['type'] === '802-11-wireless' || 
                                $conn['type'] === '802-3-ethernet' || 
                                (strpos($conn['device'], 'usb') === 0 && $conn['device'] === $conn['name'])) {
                                $networkInterfaces[$uuid] = $conn;
                            }
                        }
                        ?>
                        
                        <?php if (!empty($networkInterfaces)): ?>
                        <div class="network-connections">
                            <?php foreach ($networkInterfaces as $conn): ?>
                                <div class="connection-box">
                                    <i class="bi bi-info-circle info-icon" data-bs-toggle="modal" data-bs-target="#connectionModal<?= htmlspecialchars($conn['uuid']) ?>" title="View connection details"></i>
                                    <div class="connection-header">
                                        <i class="bi bi-<?php
                                            switch($conn['type']) {
                                                case '802-11-wireless':
                                                    echo 'wifi';
                                                    break;
                                                case '802-3-ethernet':
                                                    echo 'ethernet';
                                                    break;
                                                default:
                                                    echo 'usb';
                                            }
                                        ?>"></i>
                                        <div class="connection-name-container">
                                            <span class="connection-name"><?= htmlspecialchars($conn['name']) ?></span>
                                            <span class="connection-uuid"><?= htmlspecialchars($conn['uuid']) ?></span>
                                        </div>
                                        <span class="connection-status <?= $conn['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                            <?= ucfirst($conn['status']) ?>
                                        </span>
                                    </div>

                                    <div class="connection-details">
                                        <?php if ($conn['type'] === '802-11-wireless'): ?>
                                        <div>
                                            <span class="detail-label">SSID:</span>
                                            <span><?= htmlspecialchars($conn['details']['ssid']) ?></span>
                                        </div>
                                        <?php else: ?>
                                        <div>
                                            <span class="detail-label">Network:</span>
                                            <span><?= $conn['type'] === '802-3-ethernet' ? 'Ethernet' : 'USB Network' ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <span class="detail-label">IP:</span>
                                            <span><?= !empty($conn['details']['ip']) ? htmlspecialchars($conn['details']['ip']) : '--' ?></span>
                                        </div>
                                        <div>
                                            <span class="detail-label">Device:</span>
                                            <span><?= $conn['device'] === '--' ? 'Not connected' : htmlspecialchars($conn['device']) ?></span>
                                        </div>
                                        <div>
                                            <span class="detail-label">Last Used:</span>
                                            <span><?= htmlspecialchars($conn['details']['last_connected']) ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($conn['mac_details'])): ?>
                                    <div class="mac-randomization">
                                        <div class="mac-status">
                                            <div class="mac-addresses">
                                                <div class="mac-line">
                                                    <i class="bi bi-fingerprint"></i>
                                                    <span class="mac-value"><?= htmlspecialchars($conn['mac_details']['permanent_mac']) ?></span>
                                                    <?php if ($conn['mac_details']['randomization'] !== 'always'): ?>
                                                    <button type="button" class="btn btn-link btn-sm p-0 mac-edit" data-bs-toggle="modal" data-bs-target="#macModal<?= htmlspecialchars($conn['uuid']) ?>">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mac-line">
                                                    <i class="bi bi-incognito"></i>
                                                    <span class="mac-value <?= $conn['mac_details']['randomization'] === 'default' ? 'text-muted' : '' ?>">
                                                        <?php if ($conn['mac_details']['randomization'] === 'always'): ?>
                                                            <?= htmlspecialchars($conn['mac_details']['current_mac']) ?>
                                                        <?php else: ?>
                                                            <em>No randomization</em>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <form method="post" class="d-inline mac-toggle-form">
                                                <input type="hidden" name="action" value="toggle_mac">
                                                <input type="hidden" name="uuid" value="<?= htmlspecialchars($conn['uuid']) ?>">
                                                <input type="hidden" name="type" value="<?= htmlspecialchars($conn['mac_details']['type']) ?>">
                                                <input type="hidden" name="current" value="<?= htmlspecialchars($conn['mac_details']['randomization']) ?>">
                                                <?= CSRFProtection::tokenField() ?>
                                                <label class="mac-toggle" title="Toggle MAC Address Randomization">
                                                    <input type="checkbox" <?= $conn['mac_details']['randomization'] === 'always' ? 'checked' : '' ?>>
                                                    <span class="mac-toggle-slider"></span>
                                                </label>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            No network interfaces found that support MAC address configuration.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upload Configs Tab -->
            <div class="tab-pane fade" id="upload" role="tabpanel">
                <div class="config-card">
                    <!-- Password File Upload Section -->
                    <div class="config-section">
                        <div class="config-title">Password File</div>
                        <div class="config-description">Upload custom password lists for network operations.</div>
                        <div class="upload-zone" id="passwordUpload" data-type="passwords">
                            <div class="upload-content">
                                <i class="bi bi-file-earmark-text mb-2" style="font-size: 2rem;"></i>
                                <div class="upload-text">Drop your password file here or click to browse</div>
                                <div class="upload-info">(Supported formats: .txt, .lst, .dict, max 5MB)</div>
                            </div>
                            <div class="upload-progress" style="display: none;">
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="upload-status mt-2"></div>
                            </div>
                            <input type="file" class="file-input" accept=".txt,.lst,.dict" style="display: none;">
                        </div>
                    </div>

                    <!-- Environment Secrets Upload Section -->
                    <div class="config-section" id="envSecrets">
                        <div class="config-title d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                Environment Secrets
                                <i class="bi bi-info-circle-fill ms-2" style="color: #dc3545; opacity: 0.7; cursor: pointer; font-size: 0.9em;" data-bs-toggle="modal" data-bs-target="#envSecretsInfoModal"></i>
                                <?php if (is_readable('/var/www/env-secrets')): ?>
                                    <span class="badge bg-success ms-2" style="font-size: 0.7em;">File Present</span>
                                    <button type="button" class="btn btn-link btn-sm p-0 ms-2" data-bs-toggle="modal" data-bs-target="#viewSecretsModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-2" style="font-size: 0.7em;">No File</span>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="applyConfigBtn">
                                <i class="bi bi-play-fill"></i> Apply Configuration
                            </button>
                        </div>
                        <div class="config-description">Upload environment secrets file for network connection configuration.</div>
                        <div class="upload-zone" id="envSecretsUpload" data-type="env-secrets">
                            <div class="upload-content">
                                <i class="bi bi-key mb-2" style="font-size: 2rem;"></i>
                                <div class="upload-text">Drop your env-secrets file here or click to browse</div>
                                <div class="upload-info">(File must be named "env-secrets" or "env-secrets.txt", max 1MB)</div>
                            </div>
                            <div class="upload-progress" style="display: none;">
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="upload-status mt-2"></div>
                            </div>
                            <input type="file" class="file-input" accept=".txt,text/plain" style="display: none;">
                        </div>
                    </div>

                    <!-- VPN Profile Upload Section -->
                    <div class="config-section">
                        <div class="config-title">VPN Configuration</div>
                        <div class="config-description">Upload VPN profiles for secure connections.</div>
                        <div class="upload-zone" id="vpnUpload" data-type="vpn">
                            <div class="upload-content">
                                <i class="bi bi-shield-lock mb-2" style="font-size: 2rem;"></i>
                                <div class="upload-text">Drop your VPN profile here or click to browse</div>
                                <div class="upload-info">(Supported formats: .ovpn, .conf, max 1MB)</div>
                            </div>
                            <div class="upload-progress" style="display: none;">
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="upload-status mt-2"></div>
                            </div>
                            <input type="file" class="file-input" accept=".ovpn,.conf" style="display: none;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Configuration Tab -->
            <div class="tab-pane fade" id="system" role="tabpanel">
                <div class="config-card">
                    <div class="config-section">
                        <div class="config-title">System Configuration</div>
                        <div class="config-description">Configure core system settings and security options.</div>
                        <div class="d-flex gap-3">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="toggle_readonly">
                                <?= CSRFProtection::tokenField() ?>
                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="bi bi-toggle-on me-1"></i> Toggle Read-Only Mode
                                </button>
                            </form>
                            <a href="#envSecrets" class="btn btn-outline-primary" data-bs-toggle="tab" data-bs-target="#upload">
                                <i class="bi bi-key me-1"></i> Load Secrets
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Keep all modals outside the tab content -->
    <!-- Environment Secrets Info Modal -->
    <div class="modal fade" id="envSecretsInfoModal" tabindex="-1" aria-labelledby="envSecretsInfoModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="envSecretsInfoModalLabel">Environment Secrets File Format</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6 class="mb-3">File Requirements:</h6>
                    <ul class="list-unstyled mb-4">
                        <li><i class="bi bi-dot me-2"></i>File must be named "env-secrets" or "env-secrets.txt"</li>
                        <li><i class="bi bi-dot me-2"></i>Each line must be in KEY=VALUE format</li>
                    </ul>
                    
                    <h6 class="mb-3">Required Parameter Groups:</h6>
                    <div class="mb-3">
                        <strong class="d-block mb-2">OpenVPN Configuration:</strong>
                        <ul class="list-unstyled ms-3 small">
                            <li><i class="bi bi-chevron-right me-2"></i>VPN_USER</li>
                            <li><i class="bi bi-chevron-right me-2"></i>VPN_PASS</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <strong class="d-block mb-2">Iodine Configuration:</strong>
                        <ul class="list-unstyled ms-3 small">
                            <li><i class="bi bi-chevron-right me-2"></i>IODINE_TOPDOMAIN</li>
                            <li><i class="bi bi-chevron-right me-2"></i>IODINE_NAMESERVER</li>
                            <li><i class="bi bi-chevron-right me-2"></i>IODINE_PASS</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <strong class="d-block mb-2">Hans Configuration:</strong>
                        <ul class="list-unstyled ms-3 small">
                            <li><i class="bi bi-chevron-right me-2"></i>HANS_SERVER</li>
                            <li><i class="bi bi-chevron-right me-2"></i>HANS_PASSWORD</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <strong class="d-block mb-2">WiFi Configuration:</strong>
                        <ul class="list-unstyled ms-3 small">
                            <li><i class="bi bi-chevron-right me-2"></i>WIFI_SSID</li>
                            <li><i class="bi bi-chevron-right me-2"></i>WIFI_PASSWORD</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        You only need to include the parameter groups that you plan to use. However, if you include any parameter from a group, you must include all required parameters for that group.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Modal -->
    <div class="modal fade" id="resultsModal" tabindex="-1" aria-labelledby="resultsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resultsModalLabel">Configuration Results</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="configResults"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Secrets Modal -->
    <div class="modal fade" id="viewSecretsModal" tabindex="-1" aria-labelledby="viewSecretsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewSecretsModalLabel">Environment Secrets</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (is_readable('/var/www/env-secrets')): ?>
                        <pre class="bg-light p-3 rounded" style="max-height: 400px; overflow-y: auto;"><?php
                            echo htmlspecialchars(file_get_contents('/var/www/env-secrets'));
                        ?></pre>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            No env-secrets file found.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="/js/bootstrap.bundle.min.js"></script>
    <script src="/js/configure.js"></script>
</body>
</html> 
