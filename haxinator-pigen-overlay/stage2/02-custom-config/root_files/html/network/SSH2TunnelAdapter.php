<?php
/**
 * SSH2 Tunnel Adapter
 * 
 * This adapter bridges the existing Tunnel interface with the new SSH2 implementation
 */

// Include security framework
require_once __DIR__ . '/../security/bootstrap.php';

// Path to the SSH2 implementation
require_once __DIR__ . '/SSH2Tunnel.php';

class SSH2TunnelAdapter 
{
    protected $pid_file = '/var/www/html/data/tunnel.pid';
    private $log_file = '/var/www/html/data/ssh-tunnel.log';
    private $local_bind = '0.0.0.0:8080';
    private $ssh_key = '/var/www/.ssh/id_rsa';
    private $known_hosts = '/var/www/.ssh/known_hosts';

    public function __construct()
    {
        // Ensure the data directory exists
        if (!is_dir(dirname($this->pid_file))) {
            mkdir(dirname($this->pid_file), 0750, true);
        }

        // Initialize SSH2 tunnel variables with absolute paths
        global $keysDir, $connectionsFile, $tunnelsFile;
        $keysDir = '/var/www/.ssh';
        $connectionsFile = '/var/www/html/data/ssh_connections.json';
        $tunnelsFile = '/var/www/html/data/ssh_tunnels.json';
        
        // Make sure the global variables are properly set
        $GLOBALS['keysDir'] = $keysDir;
        $GLOBALS['connectionsFile'] = $connectionsFile;
        $GLOBALS['tunnelsFile'] = $tunnelsFile;
    }

    /**
     * Get the pid file path
     * 
     * @return string Path to the PID file
     */
    public function getPidFilePath()
    {
        return $this->pid_file;
    }

    /**
     * Validate SSH connection inputs
     * 
     * @param string $username SSH username
     * @param string $ip SSH server IP
     * @param string $port SSH server port
     * @return string Empty string if valid, error message otherwise
     */
    public function validateSshInputs($username, $ip, $port)
    {
        if (empty($username)) {
            return "Username is required.";
        }
        
        $hostCheck = validateHost($ip);
        if (!$hostCheck['valid']) {
            return "Valid IP address is required: " . $hostCheck['message'];
        }
        
        $portCheck = validatePort($port);
        if (!$portCheck['valid']) {
            return "Port must be a number between 1 and 65535: " . $portCheck['message'];
        }
        
        return "";
    }

    /**
     * Log message with timestamp and level
     * 
     * @param string $level Log level (INFO, DEBUG, WARNING, ERROR)
     * @param string $message Log message
     * @return void
     */
    public function logMessage($level, $message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid();
        $log_entry = "[$timestamp] [PID:$pid] [$level] $message";
        file_put_contents($this->log_file, "$log_entry\n", FILE_APPEND);
        error_log($log_entry, 3, $this->log_file);
    }

    /**
     * Check if tunnel is running
     * 
     * @return bool True if tunnel is running, false otherwise
     */
    public function isTunnelRunning()
    {
        // Check if tunnels are active in the SSH2 implementation
        $tunnels = getTunnelStatuses();
        
        if (empty($tunnels)) {
            $this->logMessage('DEBUG', "No active tunnels found");
            return false;
        }
        
        foreach ($tunnels as $tunnel) {
            if ($tunnel['isRunning'] && $tunnel['listenPort'] == '8080') {
                $this->logMessage('DEBUG', "Found active tunnel on port 8080 with PID {$tunnel['pid']}");
                
                // Update PID file for compatibility with existing code
                file_put_contents($this->pid_file, $tunnel['pid']);
                
                return true;
            }
        }
        
        $this->logMessage('DEBUG', "No active tunnel found on port 8080");
        return false;
    }

    /**
     * Get tunnel status
     * 
     * @return string Status message (Running/Stopped)
     */
    public function getTunnelStatus()
    {
        $status = $this->isTunnelRunning() ? 'Running' : 'Stopped';
        $this->logMessage('INFO', "Tunnel status checked: $status");
        return $status;
    }

    /**
     * Start the SSH tunnel
     * 
     * @param string $username SSH username
     * @param string $ip SSH server IP
     * @param string $port SSH server port
     * @return string Success/error message
     */
    public function startTunnel($username, $ip, $port)
    {
        $this->logMessage('INFO', "Attempting to start tunnel to $username@$ip:$port on {$this->local_bind}");
        
        // Validate inputs
        $validation_error = $this->validateSshInputs($username, $ip, $port);
        if ($validation_error) {
            $this->logMessage('ERROR', "Validation failed: $validation_error");
            return $validation_error;
        }
        
        if ($this->isTunnelRunning()) {
            $this->logMessage('WARNING', "Tunnel already running");
            return "Tunnel is already running.";
        }
        
        // Check if connection exists in connections.json, create if not
        $connections = loadJsonFile(dirname($this->pid_file) . '/ssh_connections.json');
        $connectionName = "{$username}@{$ip}:{$port}";
        
        if (!isset($connections[$connectionName])) {
            $connections[$connectionName] = [
                'host' => $ip,
                'port' => $port,
                'username' => $username,
                'password' => '',
                'key' => basename($this->ssh_key),
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s')
            ];
            
            saveJsonFile(dirname($this->pid_file) . '/ssh_connections.json', $connections);
        }
        
        // Start tunnel using SSH2 implementation
        $result = startTunnelProcess($connectionName, 'D', '8080', '', '');
        
        if (!$result['success']) {
            $this->logMessage('ERROR', "Failed to start tunnel: {$result['message']}");
            return "Failed to start tunnel: {$result['message']}";
        }
        
        // Save tunnel information
        $tunnels = loadJsonFile(dirname($this->pid_file) . '/ssh_tunnels.json');
        $tunnelId = time() . '-' . rand(1000, 9999);
        
        $tunnels[$tunnelId] = [
            'pid' => $result['pid'],
            'connectionName' => $connectionName,
            'type' => 'D',
            'listenPort' => '8080',
            'remoteHost' => '',
            'remotePort' => '',
            'startedAt' => date('Y-m-d H:i:s'),
        ];
        
        saveJsonFile(dirname($this->pid_file) . '/ssh_tunnels.json', $tunnels);
        
        // Update PID file for compatibility with existing code
        file_put_contents($this->pid_file, $result['pid']);
        
        $this->logMessage('INFO', "Tunnel started successfully with PID {$result['pid']}");
        return "Tunnel started successfully.";
    }

    /**
     * Stop the SSH tunnel
     * 
     * @return string Success/error message
     */
    public function stopTunnel()
    {
        $this->logMessage('INFO', "Attempting to stop tunnel");
        
        if (!$this->isTunnelRunning()) {
            $this->logMessage('WARNING', "Tunnel not running");
            return "Tunnel is not running.";
        }
        
        // Find active tunnel in SSH2 implementation
        $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);
        $stoppedAny = false;
        
        foreach ($tunnels as $tunnelId => $tunnel) {
            if ($tunnel['listenPort'] == '8080' && isset($tunnel['isRunning']) && $tunnel['isRunning']) {
                // Direct kill of the process first
                $pid = $tunnel['pid'];
                if ($pid) {
                    $this->logMessage('INFO', "Directly killing process $pid");
                    shell_exec("kill -9 $pid 2>/dev/null");
                }
                
                // Then let the handleTunnelStop handle cleanup
                $this->logMessage('INFO', "Stopping tunnel with ID $tunnelId");
                
                // Create a custom function to stop the tunnel by ID
                $result = $this->stopTunnelById($tunnelId);
                
                if ($result['success']) {
                    $stoppedAny = true;
                    $this->logMessage('INFO', "Stopped tunnel $tunnelId: {$result['message']}");
                } else {
                    $this->logMessage('ERROR', "Failed to stop tunnel $tunnelId: {$result['message']}");
                }
            }
        }
        
        // Clean up PID file
        if (file_exists($this->pid_file)) {
            unlink($this->pid_file);
        }
        
        if ($stoppedAny) {
            return "Tunnel stopped successfully.";
        } else {
            return "No active tunnels found to stop.";
        }
    }
    
    /**
     * Stop a tunnel by its ID
     * 
     * @param string $tunnelId The ID of the tunnel to stop
     * @return array Success/error message and status
     */
    public function stopTunnelById($tunnelId)
    {
        $this->logMessage('INFO', "Attempting to stop tunnel with ID: $tunnelId");
        
        $tunnels = loadJsonFile($GLOBALS['tunnelsFile']);
        
        if (!isset($tunnels[$tunnelId])) {
            $this->logMessage('ERROR', "Tunnel with ID $tunnelId not found");
            return ['success' => false, 'message' => "Tunnel with ID $tunnelId not found"];
        }
        
        $pid = $tunnels[$tunnelId]['pid'];
        $this->logMessage('DEBUG', "Stopping tunnel ID $tunnelId, PID $pid");
        
        // Check if process is still running
        $psCheck = shell_exec("ps -p $pid -o pid= 2>/dev/null");
        if (trim($psCheck) != $pid) {
            // Already gone; just remove from JSON
            unset($tunnels[$tunnelId]);
            saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
            $this->logMessage('INFO', "Tunnel $tunnelId was already stopped");
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
                $this->logMessage('ERROR', "Failed to stop tunnel process $pid");
                return ['success' => false, 'message' => "Failed to stop tunnel process $pid"];
            }
        }
        
        unset($tunnels[$tunnelId]);
        saveJsonFile($GLOBALS['tunnelsFile'], $tunnels);
        $this->logMessage('INFO', "Tunnel $tunnelId (PID $pid) stopped successfully");
        return ['success' => true, 'message' => "Tunnel $tunnelId (PID $pid) stopped successfully"];
    }

    /**
     * Get the server status details
     * 
     * @param string $username SSH username
     * @param string $ip SSH server IP
     * @param string $port SSH server port
     * @return array Server status details
     */
    public function getServerDetails($username, $ip, $port)
    {
        $status = $this->getTunnelStatus();
        $debug = '';
        
        // Check if port 8080 is listening
        $socket = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 1);
        $port_listening = false;
        if ($socket) {
            fclose($socket);
            $port_listening = true;
        }
        
        $connectionName = '';
        if (!empty($username) && !empty($ip)) {
            $connectionName = "$username@$ip" . ($port != '22' ? ":$port" : "");
            
            // Get debug info from active tunnels
            $tunnels = getTunnelStatuses();
            $debugInfo = [];
            
            // Add environment info
            $debugInfo[] = "Environment Information:";
            $debugInfo[] = "PHP SSH2 Extension: " . (extension_loaded('ssh2') ? "Loaded" : "Not Loaded");
            $debugInfo[] = "SSH Key Directory: " . $this->ssh_key;
            $debugInfo[] = "Key exists: " . (file_exists($this->ssh_key) ? "Yes" : "No");
            
            if (file_exists($this->ssh_key)) {
                $perms = substr(sprintf('%o', fileperms($this->ssh_key)), -4);
                $debugInfo[] = "SSH key permissions: $perms";
            }
            
            $debugInfo[] = "\nActive Tunnels:";
            foreach ($tunnels as $id => $tunnel) {
                $debugInfo[] = "Tunnel ID: $id";
                $debugInfo[] = "  PID: {$tunnel['pid']}";
                $debugInfo[] = "  Connection: {$tunnel['connectionName']}";
                $debugInfo[] = "  Type: {$tunnel['type']}";
                $debugInfo[] = "  Listen Port: {$tunnel['listenPort']}";
                $debugInfo[] = "  Status: " . ($tunnel['isRunning'] ? "Running" : "Not Running");
                $debugInfo[] = "  Details: {$tunnel['healthDetails']}";
                $debugInfo[] = "  Started: {$tunnel['startedAt']}";
                if (isset($tunnel['restartCount'])) {
                    $debugInfo[] = "  Restart Count: {$tunnel['restartCount']}";
                }
                $debugInfo[] = "";
            }
            
            $debugInfo[] = "\nRecent Logs:";
            $debugInfo[] = $this->getRecentLogs();
            
            $debug = implode("\n", $debugInfo);
        }
        
        return [
            'status' => $status,
            'server' => $connectionName,
            'debug' => $debug,
            'port_listening' => $port_listening
        ];
    }

    /**
     * Get recent log entries for display
     * 
     * @param int $lines Number of log lines to return
     * @return string Log entries
     */
    public function getRecentLogs($lines = 20)
    {
        if (!file_exists($this->log_file)) {
            return "Log file does not exist.";
        }
        $logs = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = array_slice($logs, -$lines);
        return implode("\n", $logs);
    }

    /**
     * Regenerate SSH keys for www-data user
     * 
     * @return array Success/error message and status
     */
    public function regenerateSshKeys()
    {
        // Check if tunnel is running
        if ($this->isTunnelRunning()) {
            return [
                'status' => false,
                'message' => 'Cannot regenerate keys while tunnel is running. Please stop the tunnel first.'
            ];
        }

        // Ensure .ssh directory exists with proper permissions
        $ssh_dir = dirname($this->ssh_key);
        if (!is_dir($ssh_dir)) {
            $this->logMessage('DEBUG', "Creating SSH directory: $ssh_dir");
            if (!mkdir($ssh_dir, 0700, true)) {
                $error = "Failed to create SSH directory: " . error_get_last()['message'];
                $this->logMessage('ERROR', $error);
                return ['status' => false, 'message' => $error];
            }
        } else {
            // Ensure correct permissions on existing directory
            if (!chmod($ssh_dir, 0700)) {
                $error = "Failed to set SSH directory permissions";
                $this->logMessage('ERROR', $error);
                return ['status' => false, 'message' => $error];
            }
        }

        // Check directory permissions and ownership
        $dir_perms = substr(sprintf('%o', fileperms($ssh_dir)), -4);
        $dir_owner = posix_getpwuid(fileowner($ssh_dir))['name'];
        $dir_group = posix_getgrgid(filegroup($ssh_dir))['name'];
        $this->logMessage('DEBUG', "SSH directory permissions: $dir_perms, owner: $dir_owner, group: $dir_group");

        // Remove existing keys if they exist
        if (file_exists($this->ssh_key)) {
            unlink($this->ssh_key);
        }
        if (file_exists($this->ssh_key . '.pub')) {
            unlink($this->ssh_key . '.pub');
        }

        // Generate new SSH key pair with no passphrase
        $output = [];
        $return_var = 0;
        $command = "ssh-keygen -t rsa -b 4096 -f " . escapeshellarg($this->ssh_key) . " -N '' -C 'haxinator@" . gethostname() . "' 2>&1";
        
        $this->logMessage('DEBUG', "Executing command: $command");
        exec($command, $output, $return_var);
        $output_str = implode("\n", $output);
        
        if ($return_var !== 0) {
            $error = "Failed to regenerate SSH keys (return code $return_var): $output_str";
            $this->logMessage('ERROR', $error);
            return [
                'status' => false,
                'message' => $error
            ];
        }
        
        // Verify the keys were created
        if (!file_exists($this->ssh_key) || !file_exists($this->ssh_key . '.pub')) {
            $error = "SSH keys were not created despite successful command execution";
            $this->logMessage('ERROR', $error);
            return ['status' => false, 'message' => $error];
        }
        
        // Set proper permissions on the keys
        if (!chmod($this->ssh_key, 0600) || !chmod($this->ssh_key . '.pub', 0644)) {
            $error = "Failed to set proper permissions on SSH keys";
            $this->logMessage('ERROR', $error);
            return ['status' => false, 'message' => $error];
        }
        
        // Verify final permissions
        $key_perms = substr(sprintf('%o', fileperms($this->ssh_key)), -4);
        $pub_perms = substr(sprintf('%o', fileperms($this->ssh_key . '.pub')), -4);
        $this->logMessage('INFO', "SSH keys regenerated successfully. Private key perms: $key_perms, Public key perms: $pub_perms");
        
        return [
            'status' => true,
            'message' => 'SSH keys regenerated successfully'
        ];
    }

    /**
     * Get the public SSH key
     * 
     * @return array Key data and status
     */
    public function getPublicKey()
    {
        $pub_key_path = $this->ssh_key . '.pub';
        
        if (!file_exists($pub_key_path)) {
            return [
                'status' => false,
                'message' => 'No public key found. Please generate SSH keys first.',
                'key' => ''
            ];
        }
        
        $key_content = file_get_contents($pub_key_path);
        
        return [
            'status' => true,
            'message' => 'Public key retrieved successfully',
            'key' => $key_content
        ];
    }
} 