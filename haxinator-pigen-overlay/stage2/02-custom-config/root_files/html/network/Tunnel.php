<?php
/**
 * SSH Tunnel management class
 * Manages SSH proxy tunnels (ssh -D 0.0.0.0:8080) to user-specified SSH servers
 */

// Include security framework
require_once __DIR__ . '/../security/bootstrap.php';

class Tunnel
{
    private $pid_file = '/var/www/html/data/tunnel.pid';
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
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return "Valid IP address is required.";
        }
        if (!is_numeric($port) || $port < 1 || $port > 65535) {
            return "Port must be a number between 1 and 65535.";
        }
        return "";
    }

    /**
     * Construct remote host string
     * 
     * @param string $username SSH username
     * @param string $ip SSH server IP
     * @param string $port SSH server port
     * @return string Formatted remote host string
     */
    public function getRemoteHost($username, $ip, $port)
    {
        $port = ($port == '22') ? '' : " -p $port";
        return escapeshellarg("$username@$ip") . $port;
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
        if (!file_exists($this->pid_file)) {
            $this->logMessage('DEBUG', "PID file {$this->pid_file} does not exist");
            return false;
        }
        
        $pid = trim(file_get_contents($this->pid_file));
        if (empty($pid)) {
            $this->logMessage('DEBUG', "PID file {$this->pid_file} is empty");
            return false;
        }

        // Check if the process is running using native PHP
        if (!posix_kill($pid, 0)) {
            $this->logMessage('DEBUG', "Process $pid is not running");
            return false;
        }

        // Check if port 8080 is in use using native PHP socket functions
        $socket = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            $this->logMessage('DEBUG', "Port 8080 is listening - tunnel is active");
            return true;
        }

        $this->logMessage('DEBUG', "Port 8080 is not listening");
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
     * Debug SSH configuration
     * 
     * @param string $remote_host Formatted remote host string
     * @return array Debug information
     */
    public function debugSshConfig($remote_host)
    {
        $debug_info = [];
        
        // Check SSH key existence and validate
        $key_exists = file_exists($this->ssh_key);
        $debug_info[] = "SSH key ({$this->ssh_key}) exists: " . ($key_exists ? 'Yes' : 'No');
        $this->logMessage('DEBUG', $debug_info[count($debug_info)-1]);
        
        if ($key_exists) {
            // Check SSH key permissions
            $perms = substr(sprintf('%o', fileperms($this->ssh_key)), -4);
            $debug_info[] = "SSH key permissions: $perms";
            $this->logMessage('DEBUG', $debug_info[count($debug_info)-1]);
            
            // Validate key format
            $key_content = file_get_contents($this->ssh_key);
            if (strpos($key_content, '-----BEGIN') === false || strpos($key_content, '-----END') === false) {
                $debug_info[] = "Warning: SSH key appears to be invalid or corrupted";
                $this->logMessage('WARNING', $debug_info[count($debug_info)-1]);
            }
        }
        
        // Check known_hosts existence and permissions
        $known_hosts_exists = file_exists($this->known_hosts);
        $debug_info[] = "Known hosts ({$this->known_hosts}) exists: " . ($known_hosts_exists ? 'Yes' : 'No');
        $this->logMessage('DEBUG', $debug_info[count($debug_info)-1]);
        
        if ($known_hosts_exists) {
            $known_hosts_perms = substr(sprintf('%o', fileperms($this->known_hosts)), -4);
            $debug_info[] = "Known hosts permissions: $known_hosts_perms";
            $this->logMessage('DEBUG', $debug_info[count($debug_info)-1]);
        }
        
        // Check environment
        $env_info = [
            'HOME' => getenv('HOME') ?: 'not set',
            'USER' => posix_getpwuid(posix_geteuid())['name'],
            'SSH_AUTH_SOCK' => getenv('SSH_AUTH_SOCK') ?: 'not set'
        ];
        foreach ($env_info as $key => $value) {
            $debug_info[] = "$key environment: $value";
            $this->logMessage('DEBUG', "$key = $value");
        }
        
        // Test SSH connectivity using proc_open for better control
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];
        
        $cmd = "ssh -v -i " . escapeshellarg($this->ssh_key) . 
               " -o BatchMode=yes" .
               " -o ConnectTimeout=5" .
               " -o StrictHostKeyChecking=no" .
               " -o UserKnownHostsFile=" . escapeshellarg($this->known_hosts) .
               " " . $remote_host . " true";
        
        $process = proc_open($cmd, $descriptorspec, $pipes);
        $return_var = -1;
        $output_str = '';
        
        if (is_resource($process)) {
            // Close stdin as we don't need it
            fclose($pipes[0]);
            
            // Read stdout and stderr
            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);
            
            $output = ['stdout' => '', 'stderr' => ''];
            $start = microtime(true);
            
            // Read output with timeout
            while (microtime(true) - $start < 10) { // 10 second timeout
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                
                if ($stdout) {
                    $output['stdout'] .= $stdout;
                }
                if ($stderr) {
                    $output['stderr'] .= $stderr;
                }
                
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $return_var = $status['exitcode'];
                    break;
                }
                
                usleep(100000); // 0.1 second
            }
            
            // Clean up
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            // If process is still running after timeout, terminate it
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, SIGTERM);
                $debug_info[] = "Warning: SSH connection test timed out after 10 seconds";
                $this->logMessage('WARNING', "SSH connection test timed out");
            }
            
            proc_close($process);
            
            $output_str = trim($output['stdout'] . "\n" . $output['stderr']);
        }
        
        // Parse SSH output for common issues
        $issues = [];
        if (strpos($output_str, 'Permission denied') !== false) {
            $issues[] = "Permission denied - check key permissions and authorization";
        }
        if (strpos($output_str, 'Connection refused') !== false) {
            $issues[] = "Connection refused - check if SSH service is running on remote host";
        }
        if (strpos($output_str, 'Connection timed out') !== false) {
            $issues[] = "Connection timed out - check network connectivity and firewall rules";
        }
        if (!empty($issues)) {
            $debug_info[] = "Detected issues:\n- " . implode("\n- ", $issues);
        }
        
        $debug_info[] = "SSH connectivity test return: $return_var";
        $debug_info[] = "SSH connectivity test output:\n$output_str";
        $this->logMessage('DEBUG', "SSH connectivity test: return=$return_var, output=$output_str");
        
        return [
            'return' => $return_var,
            'output' => implode("\n", $debug_info),
            'issues' => $issues
        ];
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
        $remote_host = $this->getRemoteHost($username, $ip, $port);
        $this->logMessage('INFO', "Attempting to start tunnel to $remote_host on {$this->local_bind}");
        
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
        
        // Check SSH connectivity
        $connectivity = $this->debugSshConfig($remote_host);
        if ($connectivity['return'] !== 0) {
            $this->logMessage('ERROR', "SSH connectivity test failed: return={$connectivity['return']}, output={$connectivity['output']}");
            return "Cannot start tunnel: SSH server unreachable.";
        }
        
        // Start SSH tunnel using proc_open for better process control
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];
        
        $cmd = "ssh -i " . escapeshellarg($this->ssh_key) . 
               " -o UserKnownHostsFile=" . escapeshellarg($this->known_hosts) .
               " -o StrictHostKeyChecking=no" .
               " -N -D " . escapeshellarg($this->local_bind) . 
               " " . $remote_host;
        $process = proc_open($cmd, $descriptorspec, $pipes);
        
        if (is_resource($process)) {
            // Get process ID
            $status = proc_get_status($process);
            $pid = $status['pid'];
            
            // Make pipes non-blocking
            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);
            
            // Wait briefly to check if process dies immediately
            usleep(500000); // 0.5 seconds
            
            // Check if process is still running
            $status = proc_get_status($process);
            if ($status['running']) {
                file_put_contents($this->pid_file, $pid);
                $this->logMessage('DEBUG', "Wrote PID $pid to {$this->pid_file}");
                
                // Close pipes but keep process running
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                
                // Wait a bit longer to verify tunnel is working
                sleep(2);
                if ($this->isTunnelRunning()) {
                    $this->logMessage('INFO', "Tunnel started successfully with PID $pid");
                    return "Tunnel started successfully.";
                }
            }
            
            // If we get here, something went wrong
            proc_terminate($process);
            proc_close($process);
            
            // Clean up pipes
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        
        $this->logMessage('ERROR', "Failed to start tunnel process");
        return "Failed to start tunnel: process creation failed.";
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
        
        // First try to kill the process in the PID file
        $pid = trim(file_get_contents($this->pid_file));
        $this->logMessage('DEBUG', "Stopping process with PID $pid");

        // Try SIGTERM first (signal 15)
        if (posix_kill($pid, 15)) {
            // Wait briefly to see if the process terminates
            usleep(500000); // 0.5 seconds
        }

        // If SIGTERM didn't work, try SIGKILL (signal 9)
        if (posix_kill($pid, 0)) {
            posix_kill($pid, 9);
            usleep(500000); // Wait another 0.5 seconds
        }

        // Kill any remaining SSH tunnel processes
        $output = [];
        $return_var = 0;
        exec("pkill -f 'ssh.*-D 0.0.0.0:8080'", $output, $return_var);
        
        // Clean up PID file
        if (file_exists($this->pid_file)) {
            unlink($this->pid_file);
        }

        // Verify port 8080 is no longer in use
        $socket = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            $error = "Failed to stop tunnel completely - port 8080 is still in use";
            $this->logMessage('ERROR', $error);
            return $error;
        }

        $this->logMessage('INFO', "Tunnel stopped successfully");
        return "Tunnel stopped successfully.";
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
        
        if (!empty($username) && !empty($ip)) {
            $remote_host = $this->getRemoteHost($username, $ip, $port);
            $debug = $this->debugSshConfig($remote_host)['output'] . "\n\nRecent Logs:\n" . $this->getRecentLogs();
        }
        
        return [
            'status' => $status,
            'server' => !empty($username) && !empty($ip) ? "$username@$ip" . ($port != '22' ? ":$port" : "") : '',
            'debug' => $debug,
            'port_listening' => $port_listening
        ];
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