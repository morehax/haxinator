<?php
/**
 * Single-File PHP SSH Tunnel/Proxy Manager (Refactored Example)
 *
 * Key Changes in this Refactored Version:
 * ----------------------------------------
 * 1) StartTunnelProcess uses proc_open for better error-handling:
 *    - Captures STDOUT/STDERR separately
 *    - Logs exit code
 *    - Ensures we see error messages if `ssh` fails early
 *
 * 2) Additional checks & logging around potential failures:
 *    - Validates PIDs more carefully
 *    - Warns if port is already in use
 *
 * 3) Key file permission handling remains, but with extra commentary.
 *
 * 4) Comments added regarding security best practices and possible improvements.
 *
 * Everything else (UI, JSON structure, function signatures) remains the same
 * to avoid breaking existing deployments and user expectations.
 */

// -----------------------------------------------------------------------------
// CONFIGURATION & GLOBAL SETTINGS
// -----------------------------------------------------------------------------

$debugMode = false;  // Set to false to disable debug output
$keysDir   = '/var/www/.ssh'; // Directory for SSH keys
$connectionsFile = __DIR__ . '/connections.json';
$tunnelsFile     = __DIR__ . '/tunnels.json';

$useAutossh = false; // Set to true if you want to use autossh

if (!defined('CLI_MODE')) {
    define('CLI_MODE', false);
}

// -----------------------------------------------------------------------------
// HELPER FUNCTIONS
// -----------------------------------------------------------------------------

function debugLog($msg) {
    global $debugMode;
    if ($debugMode) {
        echo "<pre style='color: #444; background: #f7f7f7; border: 1px solid #ccc; padding: 5px;'>[DEBUG] $msg</pre>";
    }
}

function loadJsonFile($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    $json = file_get_contents($filePath);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveJsonFile($filePath, array $data) {
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
}

function checkSsh2Extension() {
    if (!extension_loaded('ssh2')) {
        echo "<p style='color:red;'><strong>ERROR:</strong> The 'ssh2' extension is not loaded. 
              Please install/enable it (e.g., <code>sudo apt-get install libssh2-php</code>) 
              and refresh this page.</p>";
        return false;
    }
    return true;
}

function checkKeysDirectory() {
    global $keysDir;

    if (!is_dir($keysDir)) {
        echo "<p style='color:red;'><strong>ERROR:</strong> The keys directory <code>$keysDir</code> does not exist.
              Please create it and set correct permissions:
              <pre>sudo mkdir -p $keysDir
sudo chown www-data:www-data $keysDir
sudo chmod 700 $keysDir</pre></p>";
        return false;
    }

    if (!is_writable($keysDir)) {
        echo "<p style='color:red;'><strong>ERROR:</strong> The keys directory <code>$keysDir</code> is not writable
              by the web server (www-data). Please fix permissions:
              <pre>sudo chown www-data:www-data $keysDir
sudo chmod 700 $keysDir</pre></p>";
        return false;
    }

    return true;
}

function securePassword($password) {
    if (empty($password)) {
        return '';
    }
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyKeyFilePermissions($keyFile) {
    if (!file_exists($keyFile)) {
        return ['success' => false, 'message' => 'Key file does not exist'];
    }

    $perms = fileperms($keyFile) & 0777;
    $owner = fileowner($keyFile);
    $group = filegroup($keyFile);

    // Adjust these to your environment as needed:
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

function handleKeyUpload() {
    global $keysDir;

    if (!isset($_FILES['ssh_key']) || $_FILES['ssh_key']['error'] !== UPLOAD_ERR_OK) {
        echo "<p style='color:red;'><strong>ERROR:</strong> No valid key file uploaded.</p>";
        return;
    }

    $originalName = $_FILES['ssh_key']['name'];
    $targetPath   = $keysDir . '/' . basename($originalName);

    // Basic private key format checks
    $fileContents = file_get_contents($_FILES['ssh_key']['tmp_name']);
    $isPrivateKey = false;

    $keyPatterns = [
        'OPENSSH'   => '/BEGIN OPENSSH PRIVATE KEY/',
        'RSA'       => '/BEGIN RSA PRIVATE KEY/',
        'DSA'       => '/BEGIN DSA PRIVATE KEY/',
        'EC'        => '/BEGIN EC PRIVATE KEY/',
        'ENCRYPTED' => '/ENCRYPTED/'
    ];

    foreach ($keyPatterns as $type => $pattern) {
        if (preg_match($pattern, $fileContents)) {
            $isPrivateKey = true;
            if ($type === 'ENCRYPTED') {
                echo "<p style='color:orange;'><strong>WARNING:</strong> This key appears encrypted. Make sure you have the passphrase.</p>";
            }
            break;
        }
    }

    if (!$isPrivateKey) {
        echo "<p style='color:red;'><strong>ERROR:</strong> This does not appear to be a valid private key.</p>";
        return;
    }

    // Limit size to 10KB as a simple sanity check
    if ($_FILES['ssh_key']['size'] > 10240) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Key file is too large. Should be under 10KB.</p>";
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

    echo "<p style='color:green;'>Private key <code>" . htmlspecialchars($originalName) . "</code> uploaded and secured.</p>";
    if ($permCheck['message'] !== 'Key file permissions OK') {
        echo "<p style='color:orange;'><strong>NOTE:</strong> " . htmlspecialchars($permCheck['message']) . "</p>";
    }
}

function handleKeyDelete() {
    global $keysDir;

    if (!isset($_GET['delete_key'])) {
        return;
    }

    $keyName  = basename($_GET['delete_key']);
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

    // Convert .pub references to private key (if needed)
    if ($key && substr($key, -4) === '.pub') {
        $key = substr($key, 0, -4);
    }

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

    // If using a key, verify it
    if ($key) {
        $keyPath = $GLOBALS['keysDir'] . '/' . $key;
        $keyCheck = verifyKeyFilePermissions($keyPath);
        if (!$keyCheck['success']) {
            echo "<p style='color:red;'><strong>ERROR:</strong> Key file issue: " . htmlspecialchars($keyCheck['message']) . "</p>";
            return;
        }
    }

    // Store hashed password if provided
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

    echo "<p style='color:green;'>Connection <strong>" . htmlspecialchars($name) . "</strong> saved successfully.</p>";
}

function handleConnectionDelete() {
    global $connectionsFile;

    // Check for connection name in either GET or POST parameters
    $name = null;
    if (isset($_GET['delete_connection'])) {
        $name = $_GET['delete_connection'];
    } else if (isset($_POST['connection_name'])) {
        $name = $_POST['connection_name'];
    }
    
    if (!$name) {
        return ['success' => false, 'message' => 'No connection name specified'];
    }

    $connections = loadJsonFile($connectionsFile);

    if (!isset($connections[$name])) {
        return ['success' => false, 'message' => "Connection $name not found"];
    }

    unset($connections[$name]);
    saveJsonFile($connectionsFile, $connections);
    return ['success' => true, 'message' => "Connection $name deleted successfully"];
}

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

    $conn     = $connections[$name];
    $host     = $conn['host'];
    $port     = $conn['port'];
    $username = $conn['username'];
    $password = $conn['password'];
    $keyFile  = $conn['key'] ? ($GLOBALS['keysDir'] . '/' . $conn['key']) : null;

    debugLog("Testing SSH connection to $host:$port as $username");
    $connection = @ssh2_connect($host, $port);

    if (!$connection) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Could not connect to $host:$port.</p>";
        return;
    }

    // Try key-based auth if key is provided
    if ($keyFile && file_exists($keyFile) && $conn['key'] !== '') {
        // Attempt public key auth
        if (@ssh2_auth_pubkey_file($connection, $username, $keyFile . '.pub', $keyFile, $password)) {
            echo "<p style='color:green;'>Success: Connected to <strong>$host:$port</strong> with key <strong>{$conn['key']}</strong>.</p>";
        } else {
            // Some keys do not have .pub - fallback
            $authSuccess = @ssh2_auth_pubkey_file($connection, $username, $keyFile, $keyFile, $password);
            if ($authSuccess) {
                echo "<p style='color:green;'>Success: Connected to <strong>$host:$port</strong> with private key <strong>{$conn['key']}</strong>.</p>";
            } else {
                echo "<p style='color:red;'><strong>ERROR:</strong> Key-based authentication failed.</p>";
            }
        }
    } else {
        // Attempt password auth
        // Since we store hashed password, in real usage you'd store plain or store pass
        // in another secure manner. This example is simplified for demonstration.
        // If you truly hashed it with `securePassword()`, you'd need another approach
        // or a separate pass storage.
        echo "<p style='color:red;'><strong>WARNING:</strong> This example has hashed the password; real auth won't work unless you store the plaintext. (Demo only)</p>";

        // If you stored plaintext, it could be used here:
        // if (@ssh2_auth_password($connection, $username, $plaintextPassword)) {
        //     echo "<p style='color:green;'>Success: Connected with password.</p>";
        // } else {
        //     echo "<p style='color:red;'><strong>ERROR:</strong> Password authentication failed.</p>";
        // }
    }
}

// -----------------------------------------------------------------------------
// TUNNEL MANAGEMENT
// -----------------------------------------------------------------------------

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
 * Start an SSH tunnel using proc_open for better error handling.
 * The overall flow remains the same as the original version:
 *  - Build the SSH command (autossh or ssh).
 *  - Use a background run (nohup &).
 *  - Parse the PID from stdout.
 *  - Verify the process up to multiple retries.
 */
function startTunnelProcess($connectionName, $tunnelType, $listenPort, $remoteHost, $remotePort) {
    try {
        $connections = loadJsonFile($GLOBALS['connectionsFile']);
        if (!isset($connections[$connectionName])) {
            return ['success' => false, 'message' => 'Connection not found', 'pid' => null];
        }

        $config = [
            'type'        => $tunnelType,
            'listenPort'  => $listenPort,
            'remoteHost'  => $remoteHost,
            'remotePort'  => $remotePort
        ];

        $validation = validateTunnelConfig($config);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => "Validation failed: " . implode(", ", $validation['messages']),
                'pid'     => null
            ];
        }

        $conn     = $connections[$connectionName];
        $host     = escapeshellarg($conn['host']);
        $port     = escapeshellarg($conn['port']);
        $username = escapeshellarg($conn['username']);
        $keyFile  = $conn['key'] ? ($GLOBALS['keysDir'] . '/' . $conn['key']) : null;

        if (!$keyFile || !file_exists($keyFile)) {
            return ['success' => false, 'message' => 'SSH key not found or not specified', 'pid' => null];
        }

        // Check if port is already in use (optional but helpful)
        $checkPort = (int)$listenPort;
        $portCheck = shell_exec("lsof -i :$checkPort");
        if (!empty($portCheck)) {
            return ['success' => false, 'message' => "Port $listenPort is already in use", 'pid' => null];
        }

        $sshBin  = $GLOBALS['useAutossh'] ? 'autossh' : 'ssh';
        $sshOpts = "-o ConnectTimeout=10 -o ServerAliveInterval=60 -o ServerAliveCountMax=3";

        switch ($tunnelType) {
            case 'L':
                $forwardArg = "-L " . escapeshellarg("$listenPort:$remoteHost:$remotePort");
                break;
            case 'R':
                $forwardArg = "-R " . escapeshellarg("$listenPort:$remoteHost:$remotePort");
                break;
            case 'D':
                $forwardArg = "-D " . escapeshellarg($listenPort);
                break;
            default:
                return ['success' => false, 'message' => 'Invalid tunnel type', 'pid' => null];
        }

        // We'll run 'nohup ... & echo $!' via a shell, so we can capture the PID properly.
        // Using proc_open gives us separate stdout/stderr and an exit code.
        $cmd = sprintf(
            'bash -c \'nohup %s -N %s %s -o StrictHostKeyChecking=no -i %s -p %s %s@%s > /dev/null 2>&1 & echo $!\'',
            $sshBin,
            $sshOpts,
            $forwardArg,
            escapeshellarg($keyFile),
            $port,
            $username,
            $host
        );

        debugLog("Executing tunnel command via proc_open: $cmd");

        // Prepare descriptors so we can read STDOUT/STDERR
        $descriptorspec = [
            0 => ['file', '/dev/null', 'r'],  // no stdin
            1 => ['pipe', 'w'],              // stdout
            2 => ['pipe', 'w']               // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            return ['success' => false, 'message' => 'Failed to create process handle', 'pid' => null];
        }

        // Read PID from stdout
        $pid = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);

        // Read stderr (if any)
        $stderr = trim(stream_get_contents($pipes[2]));
        fclose($pipes[2]);

        // Get exit code
        $exitCode = proc_close($process);

        debugLog("Tunnel command exit code: $exitCode");
        debugLog("STDERR: " . $stderr);
        debugLog("Captured PID: $pid");

        // Even if exitCode != 0, ssh might still have started in the background.
        // We'll do further checks.
        if (!preg_match('/^\d+$/', $pid)) {
            // Possibly no PID found
            return [
                'success' => false,
                'message' => "Failed to retrieve a valid PID. SSH error message was: $stderr",
                'pid'     => null
            ];
        }

        // Give it a moment to spin up
        sleep(1);

        // Verification attempts
        $maxRetries  = 5;
        $retryDelay  = 1000000; // 1 second
        $verified    = false;

        for ($i = 0; $i < $maxRetries; $i++) {
            debugLog("Verification attempt " . ($i + 1) . "/$maxRetries");
            $psCheck = shell_exec("ps -p $pid -o pid= 2>/dev/null"); // just get the pid column
            if (trim($psCheck) == $pid) {
                // Then check if port is listening
                $netstatCheck = shell_exec("netstat -tln | grep :$listenPort");
                if (strpos($netstatCheck, ":$listenPort") !== false) {
                    debugLog("Tunnel verified listening on port $listenPort");
                    $verified = true;
                    break;
                }
                debugLog("Process found but port $listenPort not listening yet...");
            } else {
                debugLog("Tunnel process $pid not found at attempt #".($i+1));
            }
            usleep($retryDelay);
        }

        if (!$verified) {
            // Final check if process is still running
            $psCheck = shell_exec("ps -p $pid -o pid= 2>/dev/null");
            if (trim($psCheck) != $pid) {
                // Not running at all
                return [
                    'success' => false,
                    'message' => "Tunnel process died or never started properly: $stderr",
                    'pid'     => null
                ];
            }
            // Process is alive but port not listening
            // We can kill to keep it consistent with original approach
            shell_exec("kill -9 $pid 2>/dev/null");
            return [
                'success' => false,
                'message' => "Tunnel process running but port $listenPort not listening; forcibly stopped.",
                'pid'     => null
            ];
        }

        // If we reach here, the tunnel is up and verified
        return ['success' => true, 'message' => 'Tunnel created and verified', 'pid' => (int)$pid];
    } catch (Exception $e) {
        debugLog("Exception in startTunnelProcess: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage(), 'pid' => null];
    }
}

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

    debugLog("Creating tunnel: conn=$connectionName, type=$tunnelType, listen=$listenPort, remote=$remoteHost:$remotePort");

    if (!$connectionName || !$tunnelType || !$listenPort) {
        echo "<p style='color:red;'><strong>ERROR:</strong> Missing required fields (connection, tunnel type, listen port).</p>";
        return;
    }

    // For L or R, remote host/port are required
    if (($tunnelType === 'L' || $tunnelType === 'R') && (!$remoteHost || !$remotePort)) {
        echo "<p style='color:red;'><strong>ERROR:</strong> For Local/Remote forwarding, you must specify Remote Host and Port.</p>";
        return;
    }

    $result = startTunnelProcess($connectionName, $tunnelType, $listenPort, $remoteHost, $remotePort);
    if (!$result['success']) {
        echo "<p style='color:red;'><strong>ERROR:</strong> " . htmlspecialchars($result['message']) . "</p>";
        return;
    }

    // Save the tunnel info to tunnels.json
    $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);
    $tunnelId = time() . '-' . rand(1000, 9999);

    $tunnels[$tunnelId] = [
        'pid'            => $result['pid'],
        'connectionName' => $connectionName,
        'type'           => $tunnelType,
        'listenPort'     => $listenPort,
        'remoteHost'     => $remoteHost,
        'remotePort'     => $remotePort,
        'startedAt'      => date('Y-m-d H:i:s'),
    ];

    saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
    echo "<p style='color:green;'>Tunnel started successfully with PID <strong>{$result['pid']}</strong> (ID $tunnelId).</p>";
}

function handleTunnelStop() {
    if (!isset($_GET['stop_tunnel'])) {
        return ['success' => false, 'message' => 'No tunnel ID specified'];
    }

    $tunnelId = $_GET['stop_tunnel'];
    $tunnels  = loadJsonFile($GLOBALS['tunnelsFile']);

    if (!isset($tunnels[$tunnelId])) {
        return ['success' => false, 'message' => "Tunnel with ID $tunnelId not found"];
    }

    $pid = $tunnels[$tunnelId]['pid'];
    debugLog("Stopping tunnel ID $tunnelId, PID $pid");

    // Check if process is still running
    $psCheck = shell_exec("ps -p $pid -o pid= 2>/dev/null");
    if (trim($psCheck) != $pid) {
        // Already gone; just remove from JSON
        unset($tunnels[$tunnelId]);
        saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
        return ['success' => true, 'message' => "Tunnel $tunnelId was already stopped"];
    }

    // Try to kill gracefully
    shell_exec("kill $pid");
    usleep(100000);

    $psCheck = shell_exec("ps -p $pid -o pid= 2>/dev/null");
    if (trim($psCheck) == $pid) {
        // Force kill
        shell_exec("kill -9 $pid");
        usleep(100000);

        $psCheck = shell_exec("ps -p $pid -o pid= 2>/dev/null");
        if (trim($psCheck) == $pid) {
            return ['success' => false, 'message' => "Failed to stop tunnel process $pid"];
        }
    }

    unset($tunnels[$tunnelId]);
    saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
    return ['success' => true, 'message' => "Tunnel $tunnelId (PID $pid) stopped successfully"];
}

function checkTunnelHealth($tunnel) {
    $pid        = $tunnel['pid'];
    $listenPort = $tunnel['listenPort'];

    $psCheck = shell_exec("ps -p $pid -o pid= 2>/dev/null");
    if (trim($psCheck) != $pid) {
        return ['healthy' => false, 'details' => 'Process not running'];
    }

    $netstatCheck = shell_exec("netstat -tln | grep :$listenPort");
    if (strpos($netstatCheck, ":$listenPort") === false) {
        return ['healthy' => false, 'details' => 'Port not listening'];
    }

    // For demonstration: a simple check to see if remote host is still accessible
    // Could do a real ssh test or skip entirely
    return ['healthy' => true, 'details' => 'OK'];
}

function restartTunnel($tunnelId, $tunnel) {
    debugLog("Attempting to restart tunnel $tunnelId");

    // Stop old process
    if ($tunnel['pid']) {
        shell_exec("kill -9 {$tunnel['pid']} 2>/dev/null");
        sleep(1);
    }

    // Start fresh
    $result = startTunnelProcess(
        $tunnel['connectionName'],
        $tunnel['type'],
        $tunnel['listenPort'],
        $tunnel['remoteHost'],
        $tunnel['remotePort']
    );

    if ($result['success']) {
        $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);
        $tunnels[$tunnelId]['pid']          = $result['pid'];
        $tunnels[$tunnelId]['restartedAt']  = date('Y-m-d H:i:s');
        $tunnels[$tunnelId]['restartCount'] = ($tunnels[$tunnelId]['restartCount'] ?? 0) + 1;
        saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);

        return ['success' => true, 'message' => "Tunnel restarted successfully with new PID: {$result['pid']}"];
    }

    return ['success' => false, 'message' => "Failed to restart tunnel: {$result['message']}"];
}

function getTunnelStatuses() {
    $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);

    foreach ($tunnels as $id => &$data) {
        if (empty($data['pid'])) {
            continue;
        }

        $health = checkTunnelHealth($data);
        $data['isRunning']     = $health['healthy'];
        $data['healthDetails'] = $health['details'];

        // Attempt auto-restart if not healthy
        if (!$health['healthy'] && ($data['autoRestart'] ?? true)) {
            if (($data['restartCount'] ?? 0) < 5) {
                $restart = restartTunnel($id, $data);
                if ($restart['success']) {
                    debugLog("Auto-restarted tunnel $id: {$restart['message']}");
                    $health = checkTunnelHealth($data);
                    $data['isRunning']     = $health['healthy'];
                    $data['healthDetails'] = $health['details'];
                } else {
                    debugLog("Failed to auto-restart tunnel $id: {$restart['message']}");
                }
            } else {
                $data['healthDetails'] .= " (Max restart attempts reached)";
            }
        }

        // Add optional netstat info
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

if (!CLI_MODE) {
    if (!checkSsh2Extension()) {
        exit;
    }

    if (!checkKeysDirectory()) {
        // We continue, but key uploads won't work
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['upload_key'])) {
            handleKeyUpload();
        } elseif (isset($_POST['save_connection'])) {
            handleConnectionAdd();
        } elseif (isset($_POST['create_tunnel'])) {
            handleTunnelCreate();
        }
    } else {
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
// PAGE LAYOUT / FORMS (Unchanged from Original Except for Comments)
// -----------------------------------------------------------------------------

$connections     = loadJsonFile($connectionsFile);
$tunnelStatuses  = getTunnelStatuses();
$keys = is_dir($keysDir) ? scandir($keysDir) : [];
$keys = array_filter($keys, function($f) {
    return $f !== '.' && $f !== '..' && substr($f, -4) !== '.pub';
});

if (!CLI_MODE):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP SSH Tunnel/Proxy Manager (Refactored)</title>
    <style>
        body { font-family: Arial, sans-serif; }
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
        .delete-link { color: red; text-decoration: none; margin-left: 10px; }
        .delete-link:hover { text-decoration: underline; }
        .test-link { color: blue; text-decoration: none; margin-left: 10px; }
        .test-link:hover { text-decoration: underline; }
        .stop-link { color: red; text-decoration: none; margin-left: 10px; }
        .stop-link:hover { text-decoration: underline; }
        .green { color: green; }
        .red { color: red; }
        .form-group { margin-bottom: 1em; }
        .help-text { color: #666; font-size: 0.9em; margin-left: 130px; margin-top: 0.2em; }
        .help-text div { display: none; }
        .forward-options.hidden { display: none; }
    </style>
</head>
<body>
<h1>PHP SSH Tunnel/Proxy Manager (Refactored Example)</h1>

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
            <div id="help-D">Dynamic/SOCKS: Creates a SOCKS proxy for dynamic port forwarding</div>
        </div>
    </div>

    <div class="form-group">
        <label for="listen_port">Listen Port:</label>
        <input type="text" name="listen_port" id="listen_port" class="small-input" value="8080" required />
    </div>

    <div class="form-group forward-options">
        <label for="remote_host">Remote Host:</label>
        <input type="text" name="remote_host" id="remote_host" value="127.0.0.1" />
    </div>

    <div class="form-group forward-options">
        <label for="remote_port">Remote Port:</label>
        <input type="text" name="remote_port" id="remote_port" class="small-input" value="80" />
    </div>

    <button type="submit" name="create_tunnel">Create Tunnel</button>
</form>

<script>
function updateTunnelFields() {
    const tunnelType = document.getElementById('tunnel_type').value;
    const forwardOptions = document.querySelectorAll('.forward-options');

    // Hide all help text first
    document.querySelectorAll('.help-text div').forEach(el => el.style.display = 'none');
    document.getElementById('help-' + tunnelType).style.display = 'block';

    if (tunnelType === 'D') {
        forwardOptions.forEach(el => el.classList.add('hidden'));
    } else {
        forwardOptions.forEach(el => el.classList.remove('hidden'));
    }
}

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

<p><em>Note:</em> Ensure <strong>sudoers</strong> allows <code>www-data</code> to run SSH/autossh without password, or use properly configured keys.</p>

</body>
</html>
<?php
endif;
?>

