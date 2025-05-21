<?php
/**
 * Single-File PHP SSH Tunnel/Proxy Manager
 *
 * - Allows uploading/managing SSH private keys
 * - Manages multiple SSH connections (host, port, username, password or key)
 * - Enables tunnel/port forwarding (local: -L, remote: -R, dynamic: -D)
 * - Tracks active tunnel PIDs, with ability to kill/stop them
 * - Provides a $debugMode toggle for verbose logging
 * - Offers helpful guidance and checks for missing prerequisites
 *
 * Requirements/Assumptions:
 * - Debian 12 (but should work on many Linux distros)
 * - Apache2, with this file placed somewhere under /var/www/html
 * - PHP 7.4+ or 8.x with the "ssh2" extension installed
 * - Runs under www-data user; requires that user to be able to:
 *    * Write to a "keys" directory (0700) for private keys
 *    * Possibly run "ssh" or "autossh" via sudo (NOPASSWD) to manage background tunnels
 *
 * Minimal Environment Setup Instructions (only the essentials):
 * 1) Ensure the ssh2 extension is installed and enabled:
 *      sudo apt-get install libssh2-1-dev libssh2-php
 * 2) Create a directory for SSH keys, owned by www-data, 0700 permissions, e.g.:
 *      sudo mkdir -p /var/www/html/keys
 *      sudo chown www-data:www-data /var/www/html/keys
 *      sudo chmod 700 /var/www/html/keys
 * 3) Optional: Configure sudoers so www-data can start/stop SSH tunnels without a password:
 *      sudo visudo
 *      # Add something like:
 *      www-data ALL=(ALL) NOPASSWD: /usr/bin/ssh, /usr/bin/autossh, /bin/kill, /usr/bin/pgrep
 *
 * Place this file in /var/www/html (or any web-accessible directory),
 * then browse to it. Adjust $debugMode below to true for verbose output.
 *
 * SECURITY WARNING:
 * This example is for demonstration/testing. In a production environment:
 * - Enforce proper access controls so only authorized users can reach this page.
 * - Consider additional security measures for storing SSH connection info.
 *
 * NOTE:
 * This is a single-file illustration, with minimal UI. In real scenarios,
 * you may want a more robust design with better error handling, logging, etc.
 */

// -----------------------------------------------------------------------------
// CONFIGURATION & GLOBAL SETTINGS
// -----------------------------------------------------------------------------

/**
 * Toggle for verbose debugging output to the webpage.
 * Set to true to see detailed logs on each operation.
 */
$debugMode = true;

/**
 * Directory for storing SSH private keys (must be writable by www-data).
 * Make sure it exists and is chmod 700, owned by www-data.
 */
$keysDir = '/var/www/.ssh';

/**
 * JSON file for storing SSH connection configurations.
 * The file should be writable by www-data.
 */
$connectionsFile = __DIR__ . '/connections.json';

/**
 * JSON file for storing active tunnels data (PID, type, ports, etc.).
 * The file should be writable by www-data.
 */
$tunnelsFile = __DIR__ . '/tunnels.json';

/**
 * If true, we attempt to use 'autossh' for the background process.
 * Otherwise, we fallback to normal 'ssh -f -N ...'.
 *
 * If autossh is not installed or not desired, set to false.
 */
$useAutossh = false; // Change to true if you'd like to use autossh

// Check if we're in CLI mode (for testing)
if (!defined('CLI_MODE')) {
    define('CLI_MODE', false);
}

// -----------------------------------------------------------------------------
// HELPER FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Log a debug message if $debugMode is enabled.
 *
 * @param string $msg
 */
function debugLog($msg) {
    global $debugMode;
    if ($debugMode) {
        echo "<pre style='color: #444; background: #f7f7f7; border: 1px solid #ccc; padding: 5px;'>[DEBUG] $msg</pre>";
    }
}

/**
 * Safely load the JSON file data into an associative array.
 * If the file is missing or invalid, return an empty array.
 *
 * @param string $filePath
 * @return array
 */
function loadJsonFile($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    $json = file_get_contents($filePath);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Save an associative array to a JSON file.
 *
 * @param string $filePath
 * @param array  $data
 */
function saveJsonFile($filePath, array $data) {
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Ensure the php_ssh2 extension is loaded.
 * If not, show a helpful message.
 *
 * @return bool
 */
function checkSsh2Extension() {
    if (!extension_loaded('ssh2')) {
        echo "<p style='color:red;'><strong>ERROR:</strong> The 'ssh2' extension is not loaded. 
              Please install/enable it (e.g., <code>sudo apt-get install libssh2-php</code>) 
              and refresh this page.</p>";
        return false;
    }
    return true;
}

/**
 * Check if the keys directory exists and is writable by this script.
 * If not, display a helpful message.
 *
 * @return bool
 */
function checkKeysDirectory() {
    global $keysDir;

    if (!is_dir($keysDir)) {
        echo "<p style='color:red;'><strong>ERROR:</strong> The keys directory <code>$keysDir</code> does not exist.
              Please create it and set correct permissions, for example:
              <pre>sudo mkdir -p $keysDir
sudo chown www-data:www-data $keysDir
sudo chmod 700 $keysDir</pre></p>";
        return false;
    }

    if (!is_writable($keysDir)) {
        echo "<p style='color:red;'><strong>ERROR:</strong> The keys directory <code>$keysDir</code> is not writable
              by the web server (www-data). Please fix permissions, for example:
              <pre>sudo chown www-data:www-data $keysDir
sudo chmod 700 $keysDir</pre></p>";
        return false;
    }

    return true;
}

/**
 * Securely store a password using PHP's password_hash
 * @param string $password
 * @return string Hashed password
 */
function securePassword($password) {
    if (empty($password)) {
        return '';
    }
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verify key file permissions and fix if necessary
 * @param string $keyFile
 * @return array ['success' => bool, 'message' => string]
 */
function verifyKeyFilePermissions($keyFile) {
    if (!file_exists($keyFile)) {
        return ['success' => false, 'message' => 'Key file does not exist'];
    }
    
    $perms = fileperms($keyFile) & 0777;
    $owner = fileowner($keyFile);
    $group = filegroup($keyFile);
    
    $wwwDataUid = posix_getpwnam('www-data')['uid'];
    $wwwDataGid = posix_getgrnam('www-data')['gid'];
    
    $needsUpdate = false;
    
    // Check ownership
    if ($owner !== $wwwDataUid || $group !== $wwwDataGid) {
        if (!chown($keyFile, $wwwDataUid) || !chgrp($keyFile, $wwwDataGid)) {
            return ['success' => false, 'message' => 'Failed to update key file ownership'];
        }
        $needsUpdate = true;
    }
    
    // Check permissions (should be 0600)
    if ($perms !== 0600) {
        if (!chmod($keyFile, 0600)) {
            return ['success' => false, 'message' => 'Failed to update key file permissions'];
        }
        $needsUpdate = true;
    }
    
    return [
        'success' => true,
        'message' => $needsUpdate ? 'Key file permissions fixed' : 'Key file permissions OK'
    ];
}

/**
 * Enhanced key upload with better security checks
 */
function handleKeyUpload() {
    global $keysDir;

    if (!isset($_FILES['ssh_key']) || $_FILES['ssh_key']['error'] !== UPLOAD_ERR_OK) {
        echo "<p style='color:red;'><strong>ERROR:</strong> No valid key file uploaded.</p>";
        return;
    }

    $originalName = $_FILES['ssh_key']['name'];
    $targetPath = $keysDir . '/' . basename($originalName);

    // Enhanced key validation
    $fileContents = file_get_contents($_FILES['ssh_key']['tmp_name']);
    $isPrivateKey = false;
    
    // Check various private key formats
    $keyPatterns = [
        'OPENSSH' => '/BEGIN OPENSSH PRIVATE KEY/',
        'RSA' => '/BEGIN RSA PRIVATE KEY/',
        'DSA' => '/BEGIN DSA PRIVATE KEY/',
        'EC' => '/BEGIN EC PRIVATE KEY/',
        'ENCRYPTED' => '/ENCRYPTED/'
    ];
    
    foreach ($keyPatterns as $type => $pattern) {
        if (preg_match($pattern, $fileContents)) {
            $isPrivateKey = true;
            if ($type === 'ENCRYPTED') {
                echo "<p style='color:orange;'><strong>WARNING:</strong> This appears to be an encrypted key. Make sure you have the passphrase.</p>";
            }
            break;
        }
    }

    if (!$isPrivateKey) {
        echo "<p style='color:red;'><strong>ERROR:</strong> This does not appear to be a valid private key.</p>";
        return;
    }

    // Check file size (shouldn't be too large for a key file)
    if ($_FILES['ssh_key']['size'] > 10240) { // 10KB max
        echo "<p style='color:red;'><strong>ERROR:</strong> Key file is too large. SSH private keys should be under 10KB.</p>";
        return;
    }

    if (!move_uploaded_file($_FILES['ssh_key']['tmp_name'], $targetPath)) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Failed to save the uploaded key file.</p>";
        return;
    }

    // Verify and fix permissions
    $permCheck = verifyKeyFilePermissions($targetPath);
    if (!$permCheck['success']) {
        unlink($targetPath);
        echo "<p style='color:red;'><strong>ERROR:</strong> " . htmlspecialchars($permCheck['message']) . "</p>";
        return;
    }

    echo "<p style='color:green;'>Private key <code>" . htmlspecialchars($originalName) . "</code> uploaded successfully and secured.</p>";
    if ($permCheck['message'] !== 'Key file permissions OK') {
        echo "<p style='color:orange;'><strong>NOTE:</strong> " . htmlspecialchars($permCheck['message']) . "</p>";
    }
}

/**
 * Handle user action to delete a private key from $keysDir.
 */
function handleKeyDelete() {
    global $keysDir;

    if (!isset($_GET['delete_key'])) {
        return;
    }

    $keyName = basename($_GET['delete_key']); // protect against path traversal
    $fullPath = $keysDir . '/' . $keyName;

    if (!file_exists($fullPath)) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Key file not found: <code>$keyName</code>.</p>";
        return;
    }

    if (!unlink($fullPath)) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Failed to delete key file: <code>$keyName</code>.</p>";
        return;
    }

    echo "<p style='color:green;'>Key file <code>$keyName</code> deleted successfully.</p>";
}

// -----------------------------------------------------------------------------
// CONNECTION MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Enhanced connection add/update with better security
 */
function handleConnectionAdd() {
    global $connectionsFile;

    if (!isset($_POST['connection_name'])) {
        return;
    }

    $name     = trim($_POST['connection_name']);
    $host     = trim($_POST['host']);
    $port     = trim($_POST['port']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $key      = trim($_POST['key']);

    // If a .pub file somehow made it through (old data), convert to the matching private key
    if ($key && substr($key, -4) === '.pub') {
        $key = substr($key, 0, -4);
    }

    // Validate input
    if ($name === '' || $host === '' || $port === '' || $username === '') {
        echo "<p style='color:red;'><strong>ERROR:</strong> Missing required fields (name, host, port, username).</p>";
        return;
    }

    // Validate port
    $portCheck = validatePort($port);
    if (!$portCheck['valid']) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Invalid port: " . htmlspecialchars($portCheck['message']) . "</p>";
        return;
    }

    // Validate hostname
    $hostCheck = validateHost($host);
    if (!$hostCheck['valid']) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Invalid host: " . htmlspecialchars($hostCheck['message']) . "</p>";
        return;
    }

    $connections = loadJsonFile($connectionsFile);

    // If using a key, verify it exists and has correct permissions
    if ($key) {
        $keyPath = $GLOBALS['keysDir'] . '/' . $key;
        $keyCheck = verifyKeyFilePermissions($keyPath);
        if (!$keyCheck['success']) {
            echo "<p style='color:red;'><strong>ERROR:</strong> Key file issue: " . htmlspecialchars($keyCheck['message']) . "</p>";
            return;
        }
    }

    // Store connection with hashed password if provided
    $connections[$name] = [
        'host'     => $host,
        'port'     => $port,
        'username' => $username,
        'password' => $password ? securePassword($password) : '',
        'key'      => $key,
        'created'  => date('Y-m-d H:i:s'),
        'modified' => date('Y-m-d H:i:s')
    ];

    if (!is_writable(dirname($connectionsFile))) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Cannot write to connections file directory.</p>";
        return;
    }

    if (file_put_contents($connectionsFile, json_encode($connections, JSON_PRETTY_PRINT)) === false) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Failed to save connection data.</p>";
        return;
    }

    echo "<p style='color:green;'>Connection <strong>" . htmlspecialchars($name) . "</strong> saved/updated successfully.</p>";
}

/**
 * Handle deleting an SSH connection from connections.json.
 */
function handleConnectionDelete() {
    global $connectionsFile;

    if (!isset($_GET['delete_connection'])) {
        return;
    }

    $name = $_GET['delete_connection'];
    $connections = loadJsonFile($connectionsFile);

    if (!isset($connections[$name])) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Connection <strong>$name</strong> not found.</p>";
        return;
    }

    unset($connections[$name]);
    saveJsonFile($connectionsFile, $connections);
    echo "<p style='color:green;'>Connection <strong>$name</strong> deleted successfully.</p>";
}

/**
 * Handle testing connectivity (simple connect + auth check).
 * Uses php_ssh2 for demonstration.
 */
function handleConnectionTest() {
    if (!isset($_GET['test_connection'])) {
        return;
    }

    $name = $_GET['test_connection'];
    $connections = loadJsonFile($GLOBALS['connectionsFile']);

    if (!isset($connections[$name])) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Connection <strong>$name</strong> not found.</p>";
        return;
    }

    $conn = $connections[$name];
    $host = $conn['host'];
    $port = $conn['port'];
    $username = $conn['username'];
    $password = $conn['password'];
    $keyFile  = $conn['key'] ? ($GLOBALS['keysDir'] . '/' . $conn['key']) : null;

    debugLog("Testing SSH connection to $host:$port as $username");

    $connection = @ssh2_connect($host, $port);

    if (!$connection) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Could not connect to $host:$port.</p>";
        return;
    }

    // Try key-based auth if key file is provided, else password
    if ($keyFile && file_exists($keyFile) && $conn['key'] !== '') {
        // Attempt public key auth
        if (@ssh2_auth_pubkey_file($connection, $username, $keyFile . '.pub', $keyFile, $password)) {
            echo "<p style='color:green;'>Success: Connected to <strong>$host:$port</strong> with key <strong>{$conn['key']}</strong>.</p>";
        } else {
            // Some private keys do not have .pub - fallback
            $authSuccess = @ssh2_auth_pubkey_file($connection, $username, $keyFile, $keyFile, $password);
            if ($authSuccess) {
                echo "<p style='color:green;'>Success: Connected to <strong>$host:$port</strong> with private key <strong>{$conn['key']}</strong>.</p>";
            } else {
                echo "<p style='color:red;'><strong>ERROR:</strong> Key-based authentication failed.</p>";
            }
        }
    } else {
        // Attempt password auth
        if (@ssh2_auth_password($connection, $username, $password)) {
            echo "<p style='color:green;'>Success: Connected to <strong>$host:$port</strong> with password.</p>";
        } else {
            echo "<p style='color:red;'><strong>ERROR:</strong> Password authentication failed.</p>";
        }
    }
}

// -----------------------------------------------------------------------------
// TUNNEL MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Validate port number
 * @param string $port
 * @return array ['valid' => bool, 'message' => string]
 */
function validatePort($port) {
    if (!is_numeric($port)) {
        return ['valid' => false, 'message' => 'Port must be a number'];
    }
    $port = (int)$port;
    if ($port < 1 || $port > 65535) {
        return ['valid' => false, 'message' => 'Port must be between 1 and 65535'];
    }
    return ['valid' => true, 'message' => ''];
}

/**
 * Validate host name or IP address
 * @param string $host
 * @return array ['valid' => bool, 'message' => string]
 */
function validateHost($host) {
    if (empty($host)) {
        return ['valid' => false, 'message' => 'Host cannot be empty'];
    }
    // Allow localhost
    if ($host === 'localhost' || $host === '127.0.0.1') {
        return ['valid' => true, 'message' => ''];
    }
    // Check if valid IP
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ['valid' => true, 'message' => ''];
    }
    // Check if valid hostname
    if (preg_match('/^[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}$/', $host)) {
        return ['valid' => true, 'message' => ''];
    }
    return ['valid' => false, 'message' => 'Invalid host format'];
}

/**
 * Validate tunnel configuration
 * @param array $config
 * @return array ['valid' => bool, 'messages' => array]
 */
function validateTunnelConfig($config) {
    $messages = [];
    
    // Validate listen port
    $portCheck = validatePort($config['listenPort']);
    if (!$portCheck['valid']) {
        $messages[] = "Listen port: " . $portCheck['message'];
    }
    
    // For L/R tunnels, validate remote host and port
    if ($config['type'] !== 'D') {
        $hostCheck = validateHost($config['remoteHost']);
        if (!$hostCheck['valid']) {
            $messages[] = "Remote host: " . $hostCheck['message'];
        }
        
        $portCheck = validatePort($config['remotePort']);
        if (!$portCheck['valid']) {
            $messages[] = "Remote port: " . $portCheck['message'];
        }
    }
    
    return ['valid' => empty($messages), 'messages' => $messages];
}

/**
 * Generate an SSH command for background tunnels and run it via shell_exec.
 * Uses key-based authentication and -N flag to prevent shell access requirement.
 *
 * @param string $connectionName
 * @param string $tunnelType   L/R/D
 * @param string $listenPort
 * @param string $remoteHost
 * @param string $remotePort
 * @return array               ['success' => bool, 'message' => string, 'pid' => int|null]
 */
function startTunnelProcess($connectionName, $tunnelType, $listenPort, $remoteHost, $remotePort) {
    try {
        $connections = loadJsonFile($GLOBALS['connectionsFile']);
        if (!isset($connections[$connectionName])) {
            return ['success' => false, 'message' => 'Connection not found', 'pid' => null];
        }
        
        // Validate configuration
        $config = [
            'type' => $tunnelType,
            'listenPort' => $listenPort,
            'remoteHost' => $remoteHost,
            'remotePort' => $remotePort
        ];
        
        $validation = validateTunnelConfig($config);
        if (!$validation['valid']) {
            return [
                'success' => false, 
                'message' => "Validation failed: " . implode(", ", $validation['messages']),
                'pid' => null
            ];
        }
        
        $conn = $connections[$connectionName];
        $host = escapeshellarg($conn['host']);
        $port = escapeshellarg($conn['port']);
        $username = escapeshellarg($conn['username']);
        $keyFile = $conn['key'] ? ($GLOBALS['keysDir'] . '/' . $conn['key']) : null;

        if (!$keyFile || !file_exists($keyFile)) {
            return ['success' => false, 'message' => 'SSH key not found or not specified', 'pid' => null];
        }

        // Check if port is already in use
        $checkPort = (int)$listenPort;
        $portCheck = shell_exec("lsof -i :$checkPort");
        if (!empty($portCheck)) {
            return ['success' => false, 'message' => "Port $listenPort is already in use", 'pid' => null];
        }

        $sshBin = $GLOBALS['useAutossh'] ? 'autossh' : 'ssh';
        
        // Add connection timeout and server alive interval options
        $sshOpts = "-o ConnectTimeout=10 -o ServerAliveInterval=60 -o ServerAliveCountMax=3";
        
        // Prepare the forward argument based on tunnel type
        switch ($tunnelType) {
            case 'L': // Local forward
                $forwardArg = "-L " . escapeshellarg("$listenPort:$remoteHost:$remotePort");
                break;
            case 'R': // Remote forward
                $forwardArg = "-R " . escapeshellarg("$listenPort:$remoteHost:$remotePort");
                break;
            case 'D': // Dynamic (SOCKS)
                $forwardArg = "-D " . escapeshellarg($listenPort);
                break;
            default:
                return ['success' => false, 'message' => 'Invalid tunnel type', 'pid' => null];
        }

        // Build the complete SSH command with improved options
        $cmd = sprintf(
            'nohup %s -N %s -o StrictHostKeyChecking=no %s -i %s -p %s %s@%s > /dev/null 2>&1 & echo $!',
            $sshBin,
            $sshOpts,
            $forwardArg,
            escapeshellarg($keyFile),
            $port,
            $username,
            $host
        );

        debugLog("Executing tunnel command: $cmd");
        
        // Execute command and capture both output and PID
        $output = [];
        exec($cmd, $output);
        $pid = trim(end($output));
        debugLog("Command output: " . implode("\n", $output));
        debugLog("Got PID: $pid");
        
        if (!preg_match('/^\d+$/', $pid)) {
            debugLog("Invalid PID format: $pid");
            return ['success' => false, 'message' => 'Failed to get valid PID from SSH command', 'pid' => null];
        }
        
        // Initial delay to let the process start
        sleep(1);
        
        // Enhanced process verification with multiple retries
        $maxRetries = 5; // Increased from 3 to 5
        $retryDelay = 1000000; // Increased to 1 second
        $verified = false;
        
        for ($i = 0; $i < $maxRetries; $i++) {
            debugLog("Verification attempt " . ($i + 1) . " of $maxRetries");
            
            // Check if process exists
            $psCheck = shell_exec("ps aux | grep $pid | grep -v grep");
            debugLog("Process check result: " . trim($psCheck));
            
            if (!empty($psCheck)) {
                // Process exists, check if port is listening
                $netstatCheck = shell_exec("netstat -tln | grep :$listenPort");
                debugLog("Port check result: " . trim($netstatCheck));
                
                if (strpos($netstatCheck, ":$listenPort") !== false) {
                    debugLog("Tunnel verified successfully");
                    $verified = true;
                    break;
                } else {
                    debugLog("Port $listenPort not listening yet");
                }
            } else {
                debugLog("Process $pid not found");
            }
            
            usleep($retryDelay);
        }
        
        if ($verified) {
            return ['success' => true, 'message' => 'Tunnel created and verified', 'pid' => (int)$pid];
        }
        
        // If we get here, check one last time if the process is still running
        $psCheck = shell_exec("ps aux | grep $pid | grep -v grep");
        if (!empty($psCheck)) {
            debugLog("Process is still running, considering tunnel established");
            return ['success' => true, 'message' => 'Tunnel process running', 'pid' => (int)$pid];
        }
        
        // Only kill if process exists but verification completely failed
        debugLog("Verification failed, cleaning up");
        shell_exec("kill -9 $pid 2>/dev/null");
        return ['success' => false, 'message' => 'Tunnel process started but failed to establish properly', 'pid' => null];
        
    } catch (Exception $e) {
        debugLog("Error in startTunnelProcess: " . $e->getMessage());
        return ['success' => false, 'message' => 'Internal error: ' . $e->getMessage(), 'pid' => null];
    }
}

/**
 * Handle user request to create a new tunnel.
 */
function handleTunnelCreate() {
    if (!isset($_POST['tunnel_type'])) {
        debugLog("No tunnel_type in POST data");
        return;
    }

    $connectionName = $_POST['connection_name'];
    $tunnelType     = $_POST['tunnel_type']; // L, R, or D
    $listenPort     = $_POST['listen_port'];
    $remoteHost     = $_POST['remote_host'];
    $remotePort     = $_POST['remote_port'];

    debugLog("Attempting to create tunnel with: connection=$connectionName, type=$tunnelType, listen=$listenPort, remote=$remoteHost:$remotePort");

    // Basic validation
    if (!$connectionName || !$tunnelType || !$listenPort) {
        debugLog("Missing required fields");
        echo "<p style='color:red;'><strong>ERROR:</strong> Missing required fields (connection, tunnel type, listen port).</p>";
        return;
    }
    
    // For L or R, we also need remoteHost/remotePort
    if (($tunnelType === 'L' || $tunnelType === 'R') && (!$remoteHost || !$remotePort)) {
        debugLog("Missing remote host/port for L/R tunnel");
        echo "<p style='color:red;'><strong>ERROR:</strong> For Local/Remote forwarding, you must specify Remote Host and Remote Port.</p>";
        return;
    }

    debugLog("Starting tunnel process...");
    $result = startTunnelProcess($connectionName, $tunnelType, $listenPort, $remoteHost, $remotePort);
    debugLog("startTunnelProcess result: " . json_encode($result));
    
    if (!$result['success']) {
        echo "<p style='color:red;'><strong>ERROR:</strong> " . htmlspecialchars($result['message']) . "</p>";
        return;
    }

    // Save the tunnel info to tunnels.json
    debugLog("Loading existing tunnels.json");
    $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);
    $tunnelId = time() . '-' . rand(1000, 9999);
    debugLog("Generated tunnel ID: $tunnelId");
    
    $tunnels[$tunnelId] = [
        'pid'            => $result['pid'],
        'connectionName' => $connectionName,
        'type'          => $tunnelType,
        'listenPort'    => $listenPort,
        'remoteHost'    => $remoteHost,
        'remotePort'    => $remotePort,
        'startedAt'     => date('Y-m-d H:i:s'),
    ];
    
    debugLog("Attempting to save updated tunnels.json");
    saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
    debugLog("Save completed");

    echo "<p style='color:green;'>Tunnel started successfully with PID <strong>{$result['pid']}</strong> and ID <strong>$tunnelId</strong>.</p>";
}

/**
 * Handle request to stop a tunnel given an ID.
 * We'll kill the process, then remove it from tunnels.json
 * 
 * @return array ['success' => bool, 'message' => string]
 */
function handleTunnelStop() {
    if (!isset($_GET['stop_tunnel'])) {
        return ['success' => false, 'message' => 'No tunnel ID specified'];
    }

    $tunnelId = $_GET['stop_tunnel'];
    $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);

    if (!isset($tunnels[$tunnelId])) {
        return ['success' => false, 'message' => "Tunnel with ID $tunnelId not found"];
    }

    $pid = $tunnels[$tunnelId]['pid'];
    debugLog("Stopping tunnel ID $tunnelId, PID $pid");

    // First check if the process is still running
    $psCheck = shell_exec("ps -p $pid");
    if (strpos($psCheck, $pid) === false) {
        // Process is already gone, just clean up the json
        unset($tunnels[$tunnelId]);
        saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
        return ['success' => true, 'message' => "Tunnel $tunnelId was already stopped"];
    }

    // Try to kill the process gracefully first
    shell_exec("kill $pid");
    usleep(100000); // Wait a bit

    // Check if it's still running
    $psCheck = shell_exec("ps -p $pid");
    if (strpos($psCheck, $pid) !== false) {
        // Process is still running, force kill
        shell_exec("kill -9 $pid");
        usleep(100000); // Wait a bit more

        // Final check
        $psCheck = shell_exec("ps -p $pid");
        if (strpos($psCheck, $pid) !== false) {
            return ['success' => false, 'message' => "Failed to stop tunnel process $pid"];
        }
    }

    // Remove from tunnels.json
    unset($tunnels[$tunnelId]);
    saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);

    return ['success' => true, 'message' => "Tunnel $tunnelId (PID $pid) was stopped successfully"];
}

/**
 * Check if a tunnel is healthy by verifying both process and port status
 * @param array $tunnel Tunnel configuration
 * @return array ['healthy' => bool, 'details' => string]
 */
function checkTunnelHealth($tunnel) {
    $pid = $tunnel['pid'];
    $listenPort = $tunnel['listenPort'];
    
    // Check if process exists
    $psCheck = shell_exec("ps -p $pid");
    if (strpos($psCheck, $pid) === false) {
        return ['healthy' => false, 'details' => 'Process not running'];
    }
    
    // Check if port is listening
    $netstatCheck = shell_exec("netstat -tln | grep :$listenPort");
    if (strpos($netstatCheck, ":$listenPort") === false) {
        return ['healthy' => false, 'details' => 'Port not listening'];
    }
    
    // For non-SOCKS tunnels, try to verify the connection
    if ($tunnel['type'] !== 'D') {
        $connections = loadJsonFile($GLOBALS['connectionsFile']);
        $conn = $connections[$tunnel['connectionName']];
        
        $connection = @ssh2_connect($conn['host'], $conn['port']);
        if (!$connection) {
            return ['healthy' => false, 'details' => 'SSH connection lost'];
        }
    }
    
    return ['healthy' => true, 'details' => 'OK'];
}

/**
 * Attempt to restart a failed tunnel
 * @param string $tunnelId
 * @param array $tunnel
 * @return array ['success' => bool, 'message' => string]
 */
function restartTunnel($tunnelId, $tunnel) {
    debugLog("Attempting to restart tunnel $tunnelId");
    
    // First, ensure old process is stopped
    if ($tunnel['pid']) {
        shell_exec("kill -9 {$tunnel['pid']} 2>/dev/null");
        sleep(1); // Wait for port to be freed
    }
    
    // Start new tunnel process
    $result = startTunnelProcess(
        $tunnel['connectionName'],
        $tunnel['type'],
        $tunnel['listenPort'],
        $tunnel['remoteHost'],
        $tunnel['remotePort']
    );
    
    if ($result['success']) {
        // Update tunnels.json with new PID
        $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);
        $tunnels[$tunnelId]['pid'] = $result['pid'];
        $tunnels[$tunnelId]['restartedAt'] = date('Y-m-d H:i:s');
        $tunnels[$tunnelId]['restartCount'] = ($tunnels[$tunnelId]['restartCount'] ?? 0) + 1;
        saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
        
        return ['success' => true, 'message' => "Tunnel restarted successfully with new PID: {$result['pid']}"];
    }
    
    return ['success' => false, 'message' => "Failed to restart tunnel: {$result['message']}"];
}

/**
 * Enhanced tunnel status check with health monitoring and auto-restart
 * @return array Updated tunnel statuses
 */
function getTunnelStatuses() {
    $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);
    foreach ($tunnels as $id => &$data) {
        // Skip if tunnel was manually stopped
        if (empty($data['pid'])) {
            continue;
        }
        
        // Check tunnel health
        $health = checkTunnelHealth($data);
        $data['isRunning'] = $health['healthy'];
        $data['healthDetails'] = $health['details'];
        
        // Auto-restart if unhealthy and auto-restart is enabled
        if (!$health['healthy'] && ($data['autoRestart'] ?? true)) {
            // Don't restart if it's been restarted too many times
            $maxRestarts = 5;
            if (($data['restartCount'] ?? 0) < $maxRestarts) {
                $restart = restartTunnel($id, $data);
                if ($restart['success']) {
                    debugLog("Auto-restarted tunnel $id: {$restart['message']}");
                    // Update health status after restart
                    $health = checkTunnelHealth($data);
                    $data['isRunning'] = $health['healthy'];
                    $data['healthDetails'] = $health['details'];
                } else {
                    debugLog("Failed to auto-restart tunnel $id: {$restart['message']}");
                }
            } else {
                $data['healthDetails'] .= " (Max restart attempts reached)";
            }
        }
        
        // Add netstat information for more detailed status
        if ($data['isRunning']) {
            $netstat = shell_exec("netstat -tln | grep :{$data['listenPort']}");
            $data['netstatInfo'] = trim($netstat);
        }
    }
    
    saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
    return $tunnels;
}

// -----------------------------------------------------------------------------
// MAIN LOGIC
// -----------------------------------------------------------------------------

// 1) Check SSH2 extension
if (!checkSsh2Extension()) {
    // Can't proceed further if extension is missing
    exit;
}

// 2) Check keys directory
if (!checkKeysDirectory()) {
    // Some functions (like key upload) won't work if missing
    // but let's continue to let user see the rest of the page
}

// 3) Handle actions
if (!CLI_MODE) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['upload_key'])) {
            handleKeyUpload();
        } elseif (isset($_POST['save_connection'])) {
            handleConnectionAdd();
        } elseif (isset($_POST['create_tunnel'])) {
            handleTunnelCreate();
        }
    } else {
        // GET actions
        if (isset($_GET['delete_key'])) {
            handleKeyDelete();
        }
        if (isset($_GET['delete_connection'])) {
            handleConnectionDelete();
        }
        if (isset($_GET['test_connection'])) {
            handleConnectionTest();
        }
        if (isset($_GET['stop_tunnel'])) {
            $result = handleTunnelStop();
            if ($result['success']) {
                echo "<p style='color:green;'>" . htmlspecialchars($result['message']) . "</p>";
            } else {
                echo "<p style='color:red;'><strong>ERROR:</strong> " . htmlspecialchars($result['message']) . "</p>";
            }
        }
    }
}

// -----------------------------------------------------------------------------
// PAGE LAYOUT / FORMS
// -----------------------------------------------------------------------------

// Load existing data for display
$connections = loadJsonFile($connectionsFile);
$tunnelStatuses = getTunnelStatuses();
$keys = is_dir($keysDir) ? scandir($keysDir) : [];
// filter out '.', '..', and public key files so only private keys remain
$keys = array_filter($keys, function($f) {
    return $f !== '.' && $f !== '..' && substr($f, -4) !== '.pub';
});

// Only output HTML if not in CLI mode
if (!CLI_MODE) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP SSH Tunnel/Proxy Manager</title>
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        h2 { margin-top: 2em; }
        form {
            border: 1px solid #ccc;
            background: #f2f2f2;
            padding: 1em;
            margin-bottom: 1em;
        }
        label {
            display: inline-block;
            width: 120px;
            margin-right: 10px;
        }
        input[type="text"], input[type="file"], input[type="password"], select {
            width: 200px;
        }
        .small-input {
            width: 60px;
        }
        .delete-link {
            color: red;
            text-decoration: none;
            margin-left: 10px;
        }
        .delete-link:hover {
            text-decoration: underline;
        }
        .test-link {
            color: blue;
            text-decoration: none;
            margin-left: 10px;
        }
        .test-link:hover {
            text-decoration: underline;
        }
        .stop-link {
            color: red;
            text-decoration: none;
            margin-left: 10px;
        }
        .stop-link:hover {
            text-decoration: underline;
        }
        .green { color: green; }
        .red { color: red; }
        .form-group {
            margin-bottom: 1em;
        }
        .help-text {
            color: #666;
            font-size: 0.9em;
            margin-left: 130px;
            margin-top: 0.2em;
        }
        .help-text div {
            display: none;
        }
        .forward-options.hidden {
            display: none;
        }
    </style>
</head>
<body>
<h1>PHP SSH Tunnel/Proxy Manager</h1>

<!-- KEY MANAGEMENT -->
<h2>SSH Key Management</h2>
<form method="post" enctype="multipart/form-data">
    <label for="ssh_key">Upload Key:</label>
    <input type="file" name="ssh_key" id="ssh_key" />
    <button type="submit" name="upload_key">Upload</button>
</form>

<?php if (!empty($keys)): ?>
    <p>Existing Keys:</p>
    <ul>
        <?php foreach ($keys as $keyFile): ?>
            <li>
                <?php echo htmlspecialchars($keyFile); ?>
                <a class="delete-link" href="?delete_key=<?php echo urlencode($keyFile); ?>"
                   onclick="return confirm('Are you sure you want to delete this key?');">
                   [Delete]
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else: ?>
    <p>No keys found. Please upload one.</p>
<?php endif; ?>

<!-- CONNECTION MANAGEMENT -->
<h2>SSH Connection Management</h2>
<form method="post">
    <label for="connection_name">Name:</label>
    <input type="text" name="connection_name" id="connection_name" />
    <br><br>
    <label for="host">Host/IP:</label>
    <input type="text" name="host" id="host" />
    <br><br>
    <label for="port">Port:</label>
    <input type="text" name="port" id="port" value="22" />
    <br><br>
    <label for="username">Username:</label>
    <input type="text" name="username" id="username" />
    <br><br>
    <label for="password">Password:</label>
    <input type="password" name="password" id="password" />
    <br><br>
    <label for="key">Key File:</label>
    <select name="key" id="key">
        <option value="">(None / Use Password)</option>
        <?php foreach ($keys as $keyFile): ?>
            <option value="<?php echo htmlspecialchars($keyFile); ?>">
                <?php echo htmlspecialchars($keyFile); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <br><br>
    <button type="submit" name="save_connection">Save/Update Connection</button>
</form>

<?php if (!empty($connections)): ?>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>Name</th>
            <th>Host</th>
            <th>Port</th>
            <th>Username</th>
            <th>Key</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($connections as $name => $conn): ?>
            <tr>
                <td><?php echo htmlspecialchars($name); ?></td>
                <td><?php echo htmlspecialchars($conn['host']); ?></td>
                <td><?php echo htmlspecialchars($conn['port']); ?></td>
                <td><?php echo htmlspecialchars($conn['username']); ?></td>
                <td><?php echo htmlspecialchars($conn['key']); ?></td>
                <td>
                    <a class="delete-link" href="?delete_connection=<?php echo urlencode($name); ?>"
                       onclick="return confirm('Delete this connection?');">
                       Delete
                    </a>
                    <a class="test-link" href="?test_connection=<?php echo urlencode($name); ?>">
                        Test
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p>No SSH connections configured yet.</p>
<?php endif; ?>

<!-- TUNNEL MANAGEMENT -->
<h2>Tunnel Management</h2>
<form method="post" id="tunnelForm">
    <div class="form-group">
        <label for="connection_name">Connection:</label>
        <select name="connection_name" id="connection_name" required>
            <?php foreach ($connections as $name => $conn): ?>
                <option value="<?php echo htmlspecialchars($name); ?>">
                    <?php echo htmlspecialchars($name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="tunnel_type">Tunnel Type:</label>
        <select name="tunnel_type" id="tunnel_type" required onchange="updateTunnelFields()">
            <option value="L">Local Forward (-L)</option>
            <option value="R">Remote Forward (-R)</option>
            <option value="D">Dynamic/SOCKS (-D)</option>
        </select>
        <div class="help-text">
            <div id="help-L">Local Forward: Forwards a local port to a remote destination through the SSH server</div>
            <div id="help-R">Remote Forward: Forwards a remote port on the SSH server to a local destination</div>
            <div id="help-D">Dynamic/SOCKS: Creates a SOCKS proxy for dynamic port forwarding (useful for proxying multiple applications)</div>
        </div>
    </div>

    <div class="form-group">
        <label for="listen_port">Listen Port:</label>
        <input type="text" name="listen_port" id="listen_port" class="small-input" value="8080" required />
        <div class="help-text" id="listen-port-help"></div>
    </div>

    <div class="form-group forward-options">
        <label for="remote_host">Remote Host:</label>
        <input type="text" name="remote_host" id="remote_host" value="127.0.0.1" />
        <div class="help-text" id="remote-host-help"></div>
    </div>

    <div class="form-group forward-options">
        <label for="remote_port">Remote Port:</label>
        <input type="text" name="remote_port" id="remote_port" class="small-input" value="80" />
        <div class="help-text" id="remote-port-help"></div>
    </div>

    <button type="submit" name="create_tunnel">Create Tunnel</button>
</form>

<script>
function updateTunnelFields() {
    const tunnelType = document.getElementById('tunnel_type').value;
    const forwardOptions = document.querySelectorAll('.forward-options');
    const listenPortHelp = document.getElementById('listen-port-help');
    const remoteHostHelp = document.getElementById('remote-host-help');
    const remotePortHelp = document.getElementById('remote-port-help');
    
    // Hide all help text first
    document.querySelectorAll('.help-text div').forEach(el => el.style.display = 'none');
    document.getElementById('help-' + tunnelType).style.display = 'block';

    if (tunnelType === 'D') {
        // For SOCKS proxy, hide remote host/port
        forwardOptions.forEach(el => el.classList.add('hidden'));
        listenPortHelp.textContent = 'Local port where the SOCKS proxy will listen';
    } else {
        // For L/R tunnels, show and update help text
        forwardOptions.forEach(el => el.classList.remove('hidden'));
        
        if (tunnelType === 'L') {
            listenPortHelp.textContent = 'Local port where the tunnel will listen';
            remoteHostHelp.textContent = 'Remote host to forward to (through the SSH server)';
            remotePortHelp.textContent = 'Remote port to forward to';
        } else { // R
            listenPortHelp.textContent = 'Remote port on the SSH server where the tunnel will listen';
            remoteHostHelp.textContent = 'Local host to forward to';
            remotePortHelp.textContent = 'Local port to forward to';
        }
    }
}

// Call on page load to set initial state
document.addEventListener('DOMContentLoaded', updateTunnelFields);
</script>

<h3>Active Tunnels</h3>
<?php if (!empty($tunnelStatuses)): ?>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>PID</th>
            <th>Connection</th>
            <th>Type</th>
            <th>ListenPort</th>
            <th>RemoteHost</th>
            <th>RemotePort</th>
            <th>StartedAt</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php foreach ($tunnelStatuses as $id => $tunnel): ?>
            <tr>
                <td><?php echo htmlspecialchars($id); ?></td>
                <td><?php echo htmlspecialchars($tunnel['pid']); ?></td>
                <td><?php echo htmlspecialchars($tunnel['connectionName']); ?></td>
                <td><?php echo htmlspecialchars($tunnel['type']); ?></td>
                <td><?php echo htmlspecialchars($tunnel['listenPort']); ?></td>
                <td><?php echo htmlspecialchars($tunnel['remoteHost']); ?></td>
                <td><?php echo htmlspecialchars($tunnel['remotePort']); ?></td>
                <td><?php echo htmlspecialchars($tunnel['startedAt']); ?></td>
                <td>
                    <?php
                        if ($tunnel['isRunning']) {
                            echo "<span class='green'>Running</span>";
                            echo "<br><small>" . htmlspecialchars($tunnel['healthDetails']) . "</small>";
                            if (isset($tunnel['restartCount']) && $tunnel['restartCount'] > 0) {
                                echo "<br><small>Restarts: " . htmlspecialchars($tunnel['restartCount']) . "</small>";
                            }
                        } else {
                            echo "<span class='red'>Not Running</span>";
                            echo "<br><small>" . htmlspecialchars($tunnel['healthDetails']) . "</small>";
                        }
                    ?>
                </td>
                <td>
                    <a class="stop-link" href="?stop_tunnel=<?php echo urlencode($id); ?>"
                       onclick="return confirm('Stop this tunnel?');">
                       Stop
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p>No tunnels active.</p>
<?php endif; ?>

<p><em>Tip:</em> If you're encountering issues starting tunnels, ensure <strong>sudoers</strong> allows www-data to run SSH or autossh without password, or that your private key is properly configured. Also note that password-based tunnels may require additional tools (e.g., sshpass), which is not included in this demonstration.</p>

</body>
</html>
<?php
} // end if (!CLI_MODE)
?>

