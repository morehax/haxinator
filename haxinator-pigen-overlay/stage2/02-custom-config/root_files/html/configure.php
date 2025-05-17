<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: /index.php');
    exit;
}

// Function to get NetworkManager connections with full details
function getNetworkConnections() {
    $connections = [];
    $output = [];
    
    exec('nmcli -t -f NAME,UUID,TYPE,DEVICE con show', $output);
    
    foreach ($output as $line) {
        $parts = explode(':', $line);
        
        if (count($parts) === 4) {
            list($name, $uuid, $type, $device) = $parts;
            
            // Skip loopback
            if ($type === 'loopback') {
                continue;
            }
            
            // Get MAC randomization status
            $macDetails = [];
            $macOutput = [];
            
            // Always get the configuration
            if ($type === '802-11-wireless') {
                exec("nmcli -g 802-11-wireless.mac-address-randomization connection show '$uuid'", $macOutput);
                $macDetails['type'] = 'wifi';
                $macDetails['randomization'] = !empty($macOutput[0]) ? $macOutput[0] : 'default';
            } elseif ($type === '802-3-ethernet') {
                exec("nmcli -g 802-3-ethernet.cloned-mac-address connection show '$uuid'", $macOutput);
                $macDetails['type'] = 'ethernet';
                $macDetails['randomization'] = !empty($macOutput[0]) && $macOutput[0] === 'random' ? 'always' : 'default';
            }
            
            // Only try to get MAC hardware details if the device is connected/active
            if ($device !== '--' && !empty($device)) {
                // Get current MAC from ip link
                $currentMacOutput = [];
                exec("ip link show $device | grep 'link/ether' | cut -d' ' -f6", $currentMacOutput);
                $macDetails['current_mac'] = !empty($currentMacOutput[0]) ? $currentMacOutput[0] : '';
                
                // Get permanent MAC from sysfs
                $permMacOutput = [];
                exec("cat /sys/class/net/$device/address 2>/dev/null || echo ''", $permMacOutput);
                $macDetails['permanent_mac'] = !empty($permMacOutput[0]) ? $permMacOutput[0] : $macDetails['current_mac'];
            } else {
                // For inactive connections, try to get the MAC from sysfs if device exists
                if ($type === '802-3-ethernet') {
                    $fallbackMac = [];
                    exec("cat /sys/class/net/eth0/address 2>/dev/null || echo '00:00:00:00:00:00'", $fallbackMac);
                    $macDetails['current_mac'] = !empty($fallbackMac[0]) ? $fallbackMac[0] : '00:00:00:00:00:00';
                    $macDetails['permanent_mac'] = $macDetails['current_mac'];
                }
            }
            
            // Get basic connection details
            $details = [];
            $detailOutput = [];
            exec("nmcli -f ipv4.addresses,802-11-wireless.ssid,connection.timestamp con show '$uuid' | grep -v '^$'", $detailOutput);
            
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
                $ipOutput = [];
                exec("ip addr show $device | grep 'inet ' | awk '{print \$2}'", $ipOutput);
                if (!empty($ipOutput[0])) {
                    $details['ip'] = $ipOutput[0];
                }
            }

            // Get full connection details
            $fullDetails = [];
            $fullOutput = [];
            exec("nmcli -f all con show '$uuid'", $fullOutput);
            
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
    $uuid = $_POST['uuid'] ?? '';
    $type = $_POST['type'] ?? '';
    $current = $_POST['current'] ?? 'default';
    
    if ($uuid && $type) {
        if ($type === 'wifi') {
            if ($current === 'default') {
                exec("nmcli connection modify '$uuid' 802-11-wireless.mac-address-randomization always 802-11-wireless.cloned-mac-address random");
            } else {
                exec("nmcli connection modify '$uuid' 802-11-wireless.mac-address-randomization default 802-11-wireless.cloned-mac-address ''");
            }
            exec("nmcli connection down '$uuid' && sleep 2 && nmcli connection up '$uuid'");
        } elseif ($type === 'ethernet') {
            $newValue = $current === 'default' ? 'random' : '--';
            exec("nmcli connection modify '$uuid' 802-3-ethernet.cloned-mac-address '$newValue'");
            exec("nmcli connection down '$uuid' && sleep 2 && nmcli connection up '$uuid'");
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Add MAC validation function
function isValidMac($mac) {
    // Check basic format (XX:XX:XX:XX:XX:XX or XX-XX-XX-XX-XX-XX)
    if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
        return false;
    }
    
    // Convert to standardized format
    $mac = str_replace('-', ':', strtoupper($mac));
    
    // Check first byte for unicast (least significant bit must be 0)
    // This is important because NetworkManager won't accept multicast addresses
    $firstByte = hexdec(substr($mac, 0, 2));
    if ($firstByte & 0x01) {
        return false; // Reject multicast addresses
    }
    
    // Check for invalid addresses
    $invalidMacs = [
        '00:00:00:00:00:00', // Null MAC
        'FF:FF:FF:FF:FF:FF'  // Broadcast MAC
    ];
    
    return !in_array($mac, $invalidMacs);
}

// Handle MAC address changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_mac') {
    $uuid = $_POST['uuid'] ?? '';
    $type = $_POST['type'] ?? '';
    $mode = $_POST['mac_mode'] ?? '';
    $customMac = $_POST['custom_mac'] ?? '';
    
    if ($uuid && $type) {
        $error = '';
        
        if ($mode === 'custom' && !isValidMac($customMac)) {
            $error = 'Invalid MAC address format or value';
        } else {
            // Process based on mode
            switch($mode) {
                case 'permanent':
                    if ($type === 'wifi') {
                        exec("nmcli connection modify '$uuid' 802-11-wireless.mac-address-randomization default 802-11-wireless.cloned-mac-address ''");
                    } else {
                        exec("nmcli connection modify '$uuid' 802-3-ethernet.cloned-mac-address ''");
                    }
                    break;
                    
                case 'random':
                    if ($type === 'wifi') {
                        exec("nmcli connection modify '$uuid' 802-11-wireless.mac-address-randomization always 802-11-wireless.cloned-mac-address random");
                    } else {
                        exec("nmcli connection modify '$uuid' 802-3-ethernet.cloned-mac-address random");
                    }
                    break;
                    
                case 'custom':
                    $mac = strtoupper($customMac);
                    if ($type === 'wifi') {
                        exec("nmcli connection modify '$uuid' 802-11-wireless.mac-address-randomization default 802-11-wireless.cloned-mac-address '$mac'");
                    } else {
                        exec("nmcli connection modify '$uuid' 802-3-ethernet.cloned-mac-address '$mac'");
                    }
                    break;
            }
            
            // Restart connection to apply changes
            exec("nmcli connection down '$uuid' && sleep 2 && nmcli connection up '$uuid'");
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

// Placeholder for actual configuration logic
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Haxinator 2000 - Configuration</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet" />
    <link href="/css/theme.css" rel="stylesheet" />
    <link rel="stylesheet" href="/css/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #65ddb7 0%, #3a7cbd 100%);
            font-family: 'Segoe UI', Arial, sans-serif;
            min-height: 100vh;
        }
        .config-card {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1);
        }
        .config-section {
            border-bottom: 1px solid #e0e7ef;
            padding: 1.5rem;
        }
        .config-section:last-child {
            border-bottom: none;
        }
        .config-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 1rem;
        }
        .config-description {
            color: #64748b;
            font-size: 0.92rem;
            margin-bottom: 1rem;
        }
        .upload-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .upload-zone:hover {
            border-color: #2563eb;
            background: #f1f5f9;
        }
        .upload-zone.drag-over {
            border-color: #2563eb;
            background: #eff6ff;
        }
        .upload-info {
            color: #64748b;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }
        .upload-progress {
            margin-top: 1rem;
        }
        .upload-status {
            font-size: 0.9rem;
        }
        .upload-error {
            color: #dc2626;
        }
        .upload-success {
            color: #16a34a;
        }
        .connection-box {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            padding: 1rem;
            margin: 0;
            width: calc(50% - 0.5rem);
            flex: 0 0 calc(50% - 0.5rem);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.1);
            transition: all 0.2s;
            cursor: default;
            position: relative;
            height: fit-content;
            min-height: 200px;
            display: flex;
            flex-direction: column;
        }
        
        .connection-box:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }
        
        .connection-header {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            padding-right: 2rem;
        }
        
        .connection-header i {
            font-size: 1.2rem;
            margin-right: 0.75rem;
            opacity: 0.8;
        }
        
        .connection-name {
            font-weight: 600;
            color: #1a365d;
            flex-grow: 1;
        }
        
        .connection-status {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            margin-left: 0.5rem;
        }
        
        .status-active {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .connection-details {
            font-size: 0.9rem;
            color: #4a5568;
            margin-top: 0.5rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        
        .connection-details div {
            margin: 0.25rem 0;
            display: flex;
            align-items: baseline;
        }
        
        .detail-label {
            color: #64748b;
            width: 100px;
            flex-shrink: 0;
        }
        
        .network-connections {
            display: flex;
            flex-wrap: wrap;
            margin-top: 1rem;
            gap: 1rem;
            justify-content: space-between;
            align-items: stretch;
            width: 100%;
        }
        
        .connection-box {
            cursor: pointer;
        }
        
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .details-section {
            margin-bottom: 1.5rem;
        }
        
        .details-section-title {
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .details-row {
            display: flex;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        
        .details-label {
            width: 180px;
            flex-shrink: 0;
            color: #64748b;
            font-family: monospace;
        }
        
        .details-value {
            flex-grow: 1;
            color: #1f2937;
            font-family: monospace;
            word-break: break-word;
        }
        
        .details-value.secret {
            color: #dc2626;
            font-style: italic;
        }
        
        .connection-uuid {
            font-size: 0.75rem;
            color: #94a3b8;
            font-family: monospace;
            margin-top: 0.25rem;
            display: block;
        }
        
        .connection-name-container {
            flex-grow: 1;
        }
        
        .connection-status-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-icon {
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            opacity: 0.6;
        }
        
        .connection-box:hover .info-icon {
            opacity: 1;
        }
        
        .info-icon:hover {
            color: #2563eb;
            transform: scale(1.1);
        }
        
        .mac-randomization {
            margin-top: auto;
            padding-top: 0.75rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        
        .mac-status {
            display: flex;
            align-items: flex-start;
            flex-grow: 1;
            margin-right: 1rem;
        }
        
        .mac-status i {
            margin-top: 0.3rem;
        }
        
        .mac-toggle {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }
        
        .mac-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .mac-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 24px;
        }
        
        .mac-toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        .mac-toggle input:checked + .mac-toggle-slider {
            background-color: #2563eb;
        }
        
        .mac-toggle input:checked + .mac-toggle-slider:before {
            transform: translateX(24px);
        }
        
        .mac-addresses {
            font-family: monospace;
            font-size: 0.8rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        
        .mac-line {
            white-space: nowrap;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            height: 24px;
        }
        
        .mac-value {
            color: #2563eb;
            letter-spacing: 0.5px;
        }
        
        .mac-value.text-muted {
            color: #94a3b8;
            font-style: italic;
        }
        
        .mac-line i {
            font-size: 1rem;
            color: #64748b;
        }
        
        .mac-line i.bi-fingerprint {
            color: #4a5568;
        }
        
        .mac-line i.bi-incognito {
            color: #3b82f6;
        }
        
        .connection-box {
            max-width: 450px;
        }
        
        .mac-spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        
        .mac-spinner-content {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            max-width: 300px;
        }
        
        .mac-spinner-text {
            margin-top: 15px;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
            color: #3b82f6;
        }

        @media (max-width: 768px) {
            .connection-box {
                width: 100%;
                flex: 0 0 100%;
                margin-bottom: 1rem;
            }

            .connection-box:last-child {
                margin-bottom: 0;
            }

            .network-connections {
                gap: 0;
            }
        }
        
        .mac-edit {
            opacity: 0.6;
            transition: opacity 0.2s;
        }
        
        .mac-edit:hover {
            opacity: 1;
        }
        
        .custom-mac-input {
            margin-left: 2rem;
        }
    </style>
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

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0 text-white">Haxinator Configuration</h2>
            <a href="/index.php" class="btn btn-outline-light"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="config-card">
            <!-- System Configuration -->
            <div class="config-section">
                <div class="config-title">System Configuration</div>
                <div class="config-description">Configure core system settings and security options.</div>
                <div class="d-flex gap-3">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="toggle_readonly">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-toggle-on me-1"></i> Toggle Read-Only Mode
                        </button>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="load_secrets">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-key me-1"></i> Load Secrets
                        </button>
                    </form>
                </div>
            </div>

            <!-- Network Configuration -->
            <div class="config-section">
                <div class="config-title">Network Configuration</div>
                <div class="config-description">Manage network interfaces and security settings.</div>
                
                <div class="network-connections">
                    <?php foreach ($connections as $conn): ?>
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
                                            echo 'hdd-network';
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
                                <?php if (!empty($conn['details']['ssid'])): ?>
                                <div>
                                    <span class="detail-label">SSID:</span>
                                    <span><?= htmlspecialchars($conn['details']['ssid']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($conn['details']['ip'])): ?>
                                <div>
                                    <span class="detail-label">IP:</span>
                                    <span><?= htmlspecialchars($conn['details']['ip']) ?></span>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <span class="detail-label">Device:</span>
                                    <span><?= $conn['device'] === '--' ? 'Not connected' : htmlspecialchars($conn['device']) ?></span>
                                </div>
                                <div>
                                    <span class="detail-label">Last Used:</span>
                                    <span><?= htmlspecialchars($conn['details']['last_connected']) ?></span>
                                </div>
                                
                                <?php if (!empty($conn['mac_details'])): ?>
                                <div class="mac-randomization">
                                    <div class="mac-status">
                                        <div class="mac-addresses">
                                            <div class="mac-line">
                                                <i class="bi bi-fingerprint"></i>
                                                <span class="mac-value"><?= htmlspecialchars($conn['mac_details']['permanent_mac']) ?></span>
                                                <?php if ($conn['mac_details']['randomization'] !== 'always'): ?>
                                                <button type="button" class="btn btn-link btn-sm p-0 ms-2 mac-edit" data-bs-toggle="modal" data-bs-target="#macModal<?= htmlspecialchars($conn['uuid']) ?>">
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
                                    </div>
                                    <form method="post" class="d-inline mac-toggle-form">
                                        <input type="hidden" name="action" value="toggle_mac">
                                        <input type="hidden" name="uuid" value="<?= htmlspecialchars($conn['uuid']) ?>">
                                        <input type="hidden" name="type" value="<?= htmlspecialchars($conn['mac_details']['type']) ?>">
                                        <input type="hidden" name="current" value="<?= htmlspecialchars($conn['mac_details']['randomization']) ?>">
                                        <label class="mac-toggle" title="Toggle MAC Address Randomization">
                                            <input type="checkbox" <?= $conn['mac_details']['randomization'] === 'always' ? 'checked' : '' ?>>
                                            <span class="mac-toggle-slider"></span>
                                        </label>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Modal for this connection -->
                        <div class="modal fade" id="connectionModal<?= htmlspecialchars($conn['uuid']) ?>" tabindex="-1" aria-labelledby="connectionModalLabel<?= htmlspecialchars($conn['uuid']) ?>" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="connectionModalLabel<?= htmlspecialchars($conn['uuid']) ?>">
                                            <i class="bi bi-<?php
                                                switch($conn['type']) {
                                                    case '802-11-wireless':
                                                        echo 'wifi';
                                                        break;
                                                    case '802-3-ethernet':
                                                        echo 'ethernet';
                                                        break;
                                                    default:
                                                        echo 'hdd-network';
                                                }
                                            ?>"></i>
                                            <?= htmlspecialchars($conn['name']) ?>
                                            <span class="badge <?= $conn['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?> ms-2">
                                                <?= ucfirst($conn['status']) ?>
                                            </span>
                                            <div class="connection-uuid mt-1"><?= htmlspecialchars($conn['uuid']) ?></div>
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <?php foreach ($conn['full_details'] as $section => $params): ?>
                                            <div class="details-section">
                                                <div class="details-section-title">
                                                    <?= htmlspecialchars(ucwords(str_replace('-', ' ', $section))) ?>
                                                </div>
                                                <?php foreach ($params as $param => $value): ?>
                                                    <div class="details-row">
                                                        <div class="details-label"><?= htmlspecialchars($param) ?></div>
                                                        <div class="details-value <?= strpos($param, 'psk') !== false || strpos($param, 'password') !== false || strpos($param, 'key') !== false ? 'secret' : '' ?>">
                                                            <?= htmlspecialchars($value) ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- MAC Edit Modal -->
                        <div class="modal fade" id="macModal<?= htmlspecialchars($conn['uuid']) ?>" tabindex="-1" aria-labelledby="macModalLabel<?= htmlspecialchars($conn['uuid']) ?>" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="macModalLabel<?= htmlspecialchars($conn['uuid']) ?>">
                                            Configure MAC Address - <?= htmlspecialchars($conn['name']) ?>
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form id="macForm<?= htmlspecialchars($conn['uuid']) ?>" method="post">
                                            <input type="hidden" name="action" value="set_mac">
                                            <input type="hidden" name="uuid" value="<?= htmlspecialchars($conn['uuid']) ?>">
                                            <input type="hidden" name="type" value="<?= htmlspecialchars($conn['mac_details']['type']) ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">MAC Address Configuration</label>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="radio" name="mac_mode" id="macPermanent<?= htmlspecialchars($conn['uuid']) ?>" value="permanent" <?= $conn['mac_details']['randomization'] === 'default' && empty($conn['mac_details']['cloned_mac']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="macPermanent<?= htmlspecialchars($conn['uuid']) ?>">
                                                        Use Permanent MAC (<?= htmlspecialchars($conn['mac_details']['permanent_mac']) ?>)
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="radio" name="mac_mode" id="macRandom<?= htmlspecialchars($conn['uuid']) ?>" value="random" <?= $conn['mac_details']['randomization'] === 'always' ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="macRandom<?= htmlspecialchars($conn['uuid']) ?>">
                                                        Use Random MAC
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="mac_mode" id="macCustom<?= htmlspecialchars($conn['uuid']) ?>" value="custom">
                                                    <label class="form-check-label" for="macCustom<?= htmlspecialchars($conn['uuid']) ?>">
                                                        Use Custom MAC
                                                    </label>
                                                </div>
                                                <div class="mt-2 custom-mac-input" style="display: none;">
                                                    <input type="text" class="form-control" name="custom_mac" id="customMac<?= htmlspecialchars($conn['uuid']) ?>" 
                                                           pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$"
                                                           placeholder="XX:XX:XX:XX:XX:XX">
                                                    <div class="form-text">Enter MAC address in format: XX:XX:XX:XX:XX:XX</div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" form="macForm<?= htmlspecialchars($conn['uuid']) ?>" class="btn btn-primary">Apply Changes</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

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

    <script src="/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize MAC form submission handlers
        document.querySelectorAll('.mac-toggle-form').forEach(function(form) {
            const toggle = form.querySelector('input[type="checkbox"]');
            
            // Handle checkbox change
            toggle.addEventListener('change', function() {
                showMacSpinner();
                form.submit();
            });
            
            // Also handle direct clicks on the toggle slider
            form.querySelector('.mac-toggle-slider').addEventListener('click', function(e) {
                // Prevent immediate propagation to avoid double submission
                e.preventDefault();
                e.stopPropagation();
                // Toggle the checkbox state manually
                toggle.checked = !toggle.checked;
                // Show spinner and submit
                showMacSpinner();
                form.submit();
            });
        });
        
        function showMacSpinner() {
            document.getElementById('macSpinner').style.display = 'flex';
        }
        
        function initializeUploadZone(zone) {
            const input = zone.querySelector('.file-input');
            const content = zone.querySelector('.upload-content');
            const progress = zone.querySelector('.upload-progress');
            const progressBar = progress.querySelector('.progress-bar');
            const status = progress.querySelector('.upload-status');
            const type = zone.dataset.type;

            // Click to select file
            zone.addEventListener('click', (e) => {
                if (e.target !== input) {
                    input.click();
                }
            });

            // Drag and drop handlers
            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.add('drag-over');
            });

            zone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('drag-over');
            });

            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('drag-over');
                handleFiles(e.dataTransfer.files);
            });

            // File input change handler
            input.addEventListener('change', () => {
                handleFiles(input.files);
            });

            function handleFiles(files) {
                if (files.length === 0) return;
                
                const file = files[0];
                uploadFile(file);
            }

            function uploadFile(file) {
                const formData = new FormData();
                formData.append('file', file);
                formData.append('type', type);

                // Show progress UI
                content.style.display = 'none';
                progress.style.display = 'block';
                progressBar.style.width = '0%';
                status.textContent = 'Uploading...';
                status.className = 'upload-status mt-2';

                fetch('/api/upload.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json().then(data => ({
                    ok: response.ok,
                    data: data
                })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        throw new Error(data.error || 'Upload failed');
                    }
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    progressBar.style.width = '100%';
                    status.textContent = 'Upload successful!';
                    status.classList.add('upload-success');
                    setTimeout(() => {
                        content.style.display = 'block';
                        progress.style.display = 'none';
                        input.value = ''; // Reset input
                    }, 2000);
                })
                .catch(error => {
                    progressBar.style.width = '100%';
                    progressBar.classList.add('bg-danger');
                    status.textContent = 'Error: ' + error.message;
                    status.classList.add('upload-error');
                    setTimeout(() => {
                        content.style.display = 'block';
                        progress.style.display = 'none';
                        progressBar.classList.remove('bg-danger');
                        input.value = ''; // Reset input
                    }, 3000);
                });
            }
        }

        // Initialize all upload zones
        document.querySelectorAll('.upload-zone').forEach(initializeUploadZone);

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Make connection boxes clickable
        document.querySelectorAll('.connection-box').forEach(function(box) {
            box.addEventListener('click', function() {
                // The data-bs-toggle and data-bs-target attributes handle the modal
            });
        });

        // Handle MAC address custom input visibility
        document.querySelectorAll('input[name^="mac_mode"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                const customInput = this.closest('.modal-body').querySelector('.custom-mac-input');
                customInput.style.display = this.value === 'custom' ? 'block' : 'none';
            });
        });
        
        // MAC address validation function
        function isValidMac(mac) {
            // Basic format check (XX:XX:XX:XX:XX:XX or XX-XX-XX-XX-XX-XX)
            if (!/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/.test(mac)) {
                return false;
            }
            
            // Convert to standardized format for checking
            mac = mac.toUpperCase().replace(/-/g, ':');
            
            // Check first byte for unicast (least significant bit must be 0)
            // This is important because the system won't accept multicast addresses
            const firstByte = parseInt(mac.slice(0, 2), 16);
            if (firstByte & 0x01) {
                return false; // Reject multicast addresses (NetworkManager won't accept them)
            }
            
            // Check for invalid addresses
            const invalidMacs = [
                '00:00:00:00:00:00',
                'FF:FF:FF:FF:FF:FF'
            ];
            
            return !invalidMacs.includes(mac);
        }
        
        // Enhance MAC input validation
        document.querySelectorAll('input[name="custom_mac"]').forEach(function(input) {
            const form = input.closest('form');
            const submitBtn = form.querySelector('button[type="submit"]');
            
            input.addEventListener('input', function() {
                // Remove any non-hex characters first
                let value = this.value.replace(/[^0-9a-fA-F:-]/g, '');
                
                // Handle direct paste of complete MAC addresses
                if (value.length === 17 && /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/.test(value)) {
                    this.value = value.toUpperCase();
                } else {
                    // Handle manual typing with auto-formatting
                    value = value.replace(/[:-]/g, ''); // Remove any colons or hyphens
                    let formatted = '';
                    for (let i = 0; i < value.length && i < 12; i++) {
                        if (i > 0 && i % 2 === 0) formatted += ':';
                        formatted += value[i];
                    }
                    this.value = formatted.toUpperCase();
                }
                
                // Validate the input
                if (this.value.length === 17) { // Full MAC length with separators
                    if (!isValidMac(this.value)) {
                        this.classList.add('is-invalid');
                        this.classList.remove('is-valid');
                        if (!this.nextElementSibling?.classList.contains('invalid-feedback')) {
                            const feedback = document.createElement('div');
                            feedback.className = 'invalid-feedback';
                            feedback.textContent = 'Invalid MAC address format or value';
                            this.parentNode.insertBefore(feedback, this.nextSibling);
                        }
                        submitBtn.disabled = true;
                    } else {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                        const feedback = this.nextElementSibling;
                        if (feedback?.classList.contains('invalid-feedback')) {
                            feedback.remove();
                        }
                        submitBtn.disabled = false;
                    }
                } else {
                    // Incomplete MAC address
                    this.classList.remove('is-valid', 'is-invalid');
                    const feedback = this.nextElementSibling;
                    if (feedback?.classList.contains('invalid-feedback')) {
                        feedback.remove();
                    }
                    submitBtn.disabled = this.value.length === 17 ? false : true;
                }
            });
            
            // Validate on form submit
            form.addEventListener('submit', function(e) {
                const macMode = this.querySelector('input[name="mac_mode"]:checked').value;
                if (macMode === 'custom') {
                    if (!isValidMac(input.value)) {
                        e.preventDefault();
                        input.classList.add('is-invalid');
                        if (!input.nextElementSibling?.classList.contains('invalid-feedback')) {
                            const feedback = document.createElement('div');
                            feedback.className = 'invalid-feedback';
                            feedback.textContent = 'Invalid MAC address format or value';
                            input.parentNode.insertBefore(feedback, input.nextSibling);
                        }
                    }
                }
            });
        });
    });
    </script>
</body>
</html> 
