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

// Define CLI_MODE as true to prevent SSH2Tunnel.php from outputting its HTML
define('CLI_MODE', true);
require_once __DIR__ . "/network/SSH2Tunnel.php";
require_once __DIR__ . "/network/SSH2TunnelAdapter.php";

require_once __DIR__ . '/data/Data.php';
require_once __DIR__ . '/data/Util.php';
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
$tunnel = new SSH2TunnelAdapter();
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
            // If we have the additional SSH2 parameters, use them
            if (isset($_POST['tunnel_type']) && isset($_POST['listen_port'])) {
                // Create connection first if needed
                $connection_name = "{$ssh_username}@{$ssh_ip}:{$ssh_port}";
                $tunnel_type = $_POST['tunnel_type'];
                $listen_port = $_POST['listen_port'];
                $remote_host = isset($_POST['remote_host']) ? $_POST['remote_host'] : '';
                $remote_port = isset($_POST['remote_port']) ? $_POST['remote_port'] : '';
                
                // Get connection details
                if (function_exists('loadJsonFile')) {
                    $connections = loadJsonFile(dirname($tunnel->getPidFilePath()) . '/ssh_connections.json');
                    
                    if (!isset($connections[$connection_name])) {
                        $connections[$connection_name] = [
                            'host' => $ssh_ip,
                            'port' => $ssh_port,
                            'username' => $ssh_username,
                            'password' => '',
                            'key' => 'id_rsa',
                            'created' => date('Y-m-d H:i:s'),
                            'modified' => date('Y-m-d H:i:s')
                        ];
                        
                        saveJsonFile(dirname($tunnel->getPidFilePath()) . '/ssh_connections.json', $connections);
                    }
                    
                    // Call SSH2 startTunnelProcess function through adapter
                    $tunnelData = [
                        'connection_name' => $connection_name,
                        'tunnel_type' => $tunnel_type,
                        'listen_port' => $listen_port,
                        'remote_host' => $remote_host,
                        'remote_port' => $remote_port
                    ];
                    
                    // Use startTunnelProcess directly instead of handleTunnelCreate
                    $result = startTunnelProcess(
                        $connection_name,
                        $tunnel_type,
                        $listen_port,
                        $remote_host,
                        $remote_port
                    );
                    
                    if ($result['success']) {
                        // Save the tunnel info to tunnels.json
                        $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);
                        $tunnelId = time() . '-' . rand(1000, 9999);
                        
                        $tunnels[$tunnelId] = [
                            'pid'            => $result['pid'],
                            'connectionName' => $connection_name,
                            'type'           => $tunnel_type,
                            'listenPort'     => $listen_port,
                            'remoteHost'     => $remote_host,
                            'remotePort'     => $remote_port,
                            'startedAt'      => date('Y-m-d H:i:s'),
                        ];
                        
                        saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
                        $message = "Tunnel started successfully with PID {$result['pid']} (ID $tunnelId).";
                    } else {
                        $error = $result['message'];
                    }
                } else {
                    // Fall back to standard tunnel start
                    $result = $tunnel->startTunnel($ssh_username, $ssh_ip, $ssh_port);
                    if (strpos($result, 'successfully') !== false) {
                        $message = $result;
                    } else {
                        $error = $result;
                    }
                }
            } else {
                // Standard tunnel start
                $result = $tunnel->startTunnel($ssh_username, $ssh_ip, $ssh_port);
                if (strpos($result, 'successfully') !== false) {
                    $message = $result;
                } else {
                    $error = $result;
                }
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
            
        case 'stop_specific':
            if (isset($_POST['tunnel_id'])) {
                $result = $tunnel->stopTunnelById($_POST['tunnel_id']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
            } else {
                $error = "No tunnel ID specified";
            }
            break;
            
        case 'regenerate_ssh_keys':
            $result = $tunnel->regenerateSshKeys();
            if ($result['status']) {
                $message = $result['message'];
            } else {
                $error = $result['message'];
            }
            break;
            
        case 'upload_key':
            if (isset($_FILES['ssh_key']) && function_exists('handleKeyUpload')) {
                $result = handleKeyUpload($_FILES['ssh_key']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
            } else {
                $error = "No key file uploaded or function not available";
            }
            break;
            
        case 'delete_key':
            if (isset($_POST['key_name']) && function_exists('handleKeyDelete')) {
                $result = handleKeyDelete($_POST['key_name']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
            } else {
                $error = "Invalid key name or function not available";
            }
            break;
            
        case 'save_connection':
            if (isset($_POST['connection_name']) && isset($_POST['host']) && 
                isset($_POST['port']) && isset($_POST['username']) && 
                function_exists('handleConnectionAdd')) {
                    
                $result = handleConnectionAdd($_POST);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
            } else {
                $error = "Missing connection details or function not available";
            }
            break;
            
        case 'delete_connection':
            if (isset($_POST['connection_name']) && function_exists('handleConnectionDelete')) {
                $result = handleConnectionDelete($_POST['connection_name']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
            } else {
                $error = "Invalid connection name or function not available";
            }
            break;
            
        case 'test_connection':
            if (!empty($ssh_username) && !empty($ssh_ip) && function_exists('handleConnectionTest')) {
                $connection_name = "{$ssh_username}@{$ssh_ip}:{$ssh_port}";
                
                // Create temporary connection for testing
                if (function_exists('loadJsonFile') && function_exists('handleConnectionAdd')) {
                    $connections = loadJsonFile(dirname($tunnel->getPidFilePath()) . '/ssh_connections.json');
                    
                    if (!isset($connections[$connection_name])) {
                        handleConnectionAdd([
                            'connection_name' => $connection_name,
                            'host' => $ssh_ip,
                            'port' => $ssh_port,
                            'username' => $ssh_username,
                            'key' => 'id_rsa',
                            'password' => ''
                        ]);
                    }
                }
                
                $result = handleConnectionTest($connection_name);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
            } else {
                $error = "Missing connection details or function not available";
            }
            break;
    }
}

// Handle SSH public key download
if (isset($_GET['download_ssh_key']) && $_GET['download_ssh_key'] === '1') {
    $key_result = $tunnel->getPublicKey();
    if ($key_result['status'] && !empty($key_result['key'])) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="id_rsa.pub"');
        header('Content-Length: ' . strlen($key_result['key']));
        echo $key_result['key'];
        exit;
    } else {
        $error = $key_result['message'];
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

// Get SSH public key status
$public_key_status = $tunnel->getPublicKey();
$tunnel_status['public_key'] = $public_key_status;

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
$public_ip = Util::getPublicIp();
$dns_ok = Util::dnsResolvesGoogle();
$hostname = gethostname();

// Render main UI
echo renderMainPage($message, $error, $wifi_list, $saved_connections, $iface_status, $active_uuids, $unique_wifi_list, $public_ip, $dns_ok, $hostname, $tunnel_status);
?>
