<?php
/**
 * Main entry point for Haxinator 2000 Web UI
 * Orchestrates authentication, data collection, and UI rendering
 *
 * Still located at /var/www/html/index.php
 * but referencing refactored classes and modules in subdirectories.
 */

mb_internal_encoding('UTF-8');
// Include security framework instead of using basic session_start()
require_once __DIR__ . '/security/bootstrap.php';

// Include configuration and modules (updated paths)
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/network/Network.php';
require_once __DIR__ . '/network/Tunnel.php';
require_once __DIR__ . '/data/Data.php';
require_once __DIR__ . '/ui/UI.php';

// Handle authentication
$auth = new Auth($config['username'], $config['password']);
if (!$auth->isLoggedIn()) {
    echo renderLoginPage($auth->getLoginError());
    exit;
}

// Handle network actions
$network = new Network();
$message = '';
$error = '';

// Create tunnel instance to handle SSH tunnel operations
$tunnel = new Tunnel();
$tunnel_status = null;

// Handle SSH tunnel operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tunnel_action'])) {
    $ssh_username = isset($_POST['ssh_username']) ? trim($_POST['ssh_username']) : '';
    $ssh_ip = isset($_POST['ssh_ip']) ? trim($_POST['ssh_ip']) : '';
    $ssh_port = isset($_POST['ssh_port']) ? trim($_POST['ssh_port']) : '22';
    
    // Store SSH server details in session when provided
    if (!empty($ssh_username) && !empty($ssh_ip)) {
        $_SESSION['ssh_tunnel'] = [
            'username' => $ssh_username,
            'ip' => $ssh_ip,
            'port' => $ssh_port
        ];
    }
    
    switch ($_POST['tunnel_action']) {
        case 'start_tunnel':
            $result = $tunnel->startTunnel($ssh_username, $ssh_ip, $ssh_port);
            if (strpos($result, 'successfully') !== false) {
                $message = $result;
            } else {
                $error = $result;
            }
            break;
        case 'stop_tunnel':
            $result = $tunnel->stopTunnel();
            if (strpos($result, 'successfully') !== false) {
                $message = $result;
            } else {
                $error = $result;
            }
            break;
    }
}

// Retrieve stored SSH server details from session
$ssh_username = '';
$ssh_ip = '';
$ssh_port = '22';

if (isset($_POST['ssh_username']) && !empty($_POST['ssh_username'])) {
    // Use form values if present
    $ssh_username = trim($_POST['ssh_username']);
    $ssh_ip = isset($_POST['ssh_ip']) ? trim($_POST['ssh_ip']) : '';
    $ssh_port = isset($_POST['ssh_port']) ? trim($_POST['ssh_port']) : '22';
} elseif (isset($_SESSION['ssh_tunnel'])) {
    // Use session values otherwise
    $ssh_username = $_SESSION['ssh_tunnel']['username'];
    $ssh_ip = $_SESSION['ssh_tunnel']['ip'];
    $ssh_port = $_SESSION['ssh_tunnel']['port'];
}

// Get tunnel status with server details
$tunnel_status = $tunnel->getServerDetails($ssh_username, $ssh_ip, $ssh_port);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_connection'])) {
        $result = $network->deleteConnection($_POST['delete_connection']);
        $message = $result['message'];
        $error = $result['error'];
    } elseif (isset($_POST['activate_connection'])) {
        $result = $network->activateConnection($_POST['activate_connection']);
        $message = $result['message'];
        $error = $result['error'];
    } elseif (isset($_POST['disconnect_connection'])) {
        $result = $network->disconnectConnection($_POST['disconnect_connection']);
        $message = $result['message'];
        $error = $result['error'];
    } elseif (isset($_POST['connect_ssid'])) {
        $result = $network->connectWifi($_POST['connect_ssid'], $_POST['wifi_password'] ?? '');
        $message = $result['message'];
        $error = $result['error'];
    } elseif (isset($_POST['disconnect_wifi'])) {
        $result = $network->disconnectWifi($_POST['disconnect_wifi']);
        $message = $result['message'];
        $error = $result['error'];
    } elseif (isset($_POST['scan_refresh'])) {
        $network->rescanWifi();
    } elseif (isset($_POST['iface']) && isset($_POST['ip_mode'])) {
        $result = $network->configureInterface($_POST['iface'], $_POST['ip_mode'], $_POST);
        $message = $result['message'];
        $error = $result['error'];
    } elseif (isset($_POST['update_connection'])) {
        // Handle connection update
        $settings = [
            'name' => $_POST['name'] ?? null,
            'autoconnect' => isset($_POST['autoconnect']),
            'priority' => $_POST['priority'] ?? null,
            'ipv4_method' => $_POST['ipv4_method'] ?? null,
            'ipv4_address' => $_POST['ipv4_address'] ?? null,
            'ipv4_gateway' => $_POST['ipv4_gateway'] ?? null,
            'ipv4_dns' => $_POST['ipv4_dns'] ?? null,
            'wifi_security_type' => $_POST['wifi_security_type'] ?? null,
            'wifi_password' => $_POST['wifi_password'] ?? null
        ];
        $result = $network->updateConnection($_POST['connection_uuid'], $settings);
        $message = $result['message'];
        $error = $result['error'];
    } elseif (isset($_POST['update_priority'])) {
        // Handle priority update
        $settings = ['priority' => intval($_POST['priority'])];
        $result = $network->updateConnection($_POST['connection_uuid'], $settings);
        $message = $result['message'];
        $error = $result['error'];
    }
}

// Collect data
$data = new Data();
$wifi_list = $data->getWifiList();
// Filter for unique SSIDs for Haxinate Wifi tab dropdown
$unique_wifi_list = [];
$seen_ssids = [];
foreach ($wifi_list as $net) {
    if (!empty($net['ssid']) && $net['ssid'] !== '(hidden)' && !in_array($net['ssid'], $seen_ssids, true)) {
        $unique_wifi_list[] = $net;
        $seen_ssids[] = $net['ssid'];
    }
}
$saved_connections = $data->getSavedConnections();
$iface_status = $data->getInterfaceStatus();
$active_uuids = $data->getActiveConnectionUuids();
$public_ip = get_public_ip();
$dns_ok = dns_resolves_google();
$hostname = gethostname();

// Render main UI
echo renderMainPage($message, $error, $wifi_list, $saved_connections, $iface_status, $active_uuids, $unique_wifi_list, $public_ip, $dns_ok, $hostname, $tunnel_status);
?>
