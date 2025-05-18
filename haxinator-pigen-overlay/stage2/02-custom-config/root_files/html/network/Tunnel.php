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
        
        // First, simply check if port 8080 is listening, which is the most important indicator
        $output = [];
        $return_var = 0;
        exec("netstat -tuln | grep ':8080' 2>&1", $output, $return_var);
        if ($return_var === 0 && !empty($output)) {
            $this->logMessage('DEBUG', "Port 8080 is listening - tunnel is active");
            
            // Port is listening, now verify the process exists
            exec("ps -p $pid 2>&1", $output, $return_var);
            if ($return_var === 0) {
                // Process exists, success!
                return true;
            }
            
            // If the original PID doesn't exist, try to find the SSH tunnel process
            $output = [];
            exec("ps aux | grep 'ssh.*-D.*:8080' | grep -v grep", $output);
            if (!empty($output)) {
                // Found SSH tunnel process, let's update the PID file
                $this->logMessage('DEBUG', "Found SSH tunnel but PID mismatch. Updating PID file.");
                
                // Extract the PID of the SSH or sudo process
                if (preg_match('/^\S+\s+(\d+)/', $output[0], $matches)) {
                    $new_pid = $matches[1];
                    file_put_contents($this->pid_file, $new_pid);
                    $this->logMessage('DEBUG', "Updated PID file with $new_pid");
                    return true;
                }
            }
        }
        
        $this->logMessage('DEBUG', "Port 8080 is not listening or tunnel process not found");
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
        
        // Check SSH key existence
        $key_exists = file_exists($this->ssh_key);
        $debug_info[] = "SSH key ({$this->ssh_key}) exists: " . ($key_exists ? 'Yes' : 'No');
        $this->logMessage('DEBUG', $debug_info[count($debug_info)-1]);
        
        // Check SSH key permissions
        if ($key_exists) {
            $perms = substr(sprintf('%o', fileperms($this->ssh_key)), -4);
            $debug_info[] = "SSH key permissions: $perms";
            $this->logMessage('DEBUG', $debug_info[count($debug_info)-1]);
        }
        
        // Check known_hosts existence
        $known_hosts_exists = file_exists($this->known_hosts);
        $debug_info[] = "Known hosts ({$this->known_hosts}) exists: " . ($known_hosts_exists ? 'Yes' : 'No');
        $this->logMessage('DEBUG', $debug_info[count($debug_info)-1]);
        
        // Log HOME environment
        $home = getenv('HOME');
        $debug_info[] = "HOME environment: " . ($home ?: 'not set');
        $this->logMessage('DEBUG', $debug_info[count($debug_info)-1]);
        
        // Test SSH connectivity with verbose output
        $output = [];
        $return_var = 0;
        exec("sudo -H ssh -v -i {$this->ssh_key} -o BatchMode=yes -o ConnectTimeout=5 -o UserKnownHostsFile={$this->known_hosts} $remote_host true 2>&1", $output, $return_var);
        $output_str = implode("\n", $output);
        $debug_info[] = "SSH connectivity test return: $return_var";
        $debug_info[] = "SSH connectivity test output: $output_str";
        $this->logMessage('DEBUG', "SSH connectivity test: return=$return_var, output=$output_str");
        
        return ['return' => $return_var, 'output' => implode("\n", $debug_info)];
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
        
        // Check sudo permissions
        $output = [];
        $return_var = 0;
        exec("sudo -H -l -U www-data 2>&1", $output, $return_var);
        $sudo_output = implode("\n", $output);
        $this->logMessage('DEBUG', "sudo permissions check: return=$return_var, output=$sudo_output");
        
        // Start SSH tunnel
        $command = "sudo -H ssh -i {$this->ssh_key} -o UserKnownHostsFile={$this->known_hosts} -N -D {$this->local_bind} $remote_host >/dev/null 2>&1 & echo $!";
        $this->logMessage('DEBUG', "Executing command: $command");
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);
        $output_str = implode("\n", $output);
        $this->logMessage('DEBUG', "Command output: $output_str, return: $return_var");
        
        if ($return_var !== 0 || empty($output)) {
            $this->logMessage('ERROR', "Failed to start tunnel: exec returned $return_var, output=$output_str");
            return "Failed to start tunnel: exec error.";
        }
        
        $pid = trim($output[0]);
        file_put_contents($this->pid_file, $pid);
        $this->logMessage('DEBUG', "Wrote PID $pid to {$this->pid_file}");
        
        // Verify tunnel started
        sleep(5);
        if ($this->isTunnelRunning()) {
            $this->logMessage('INFO', "Tunnel started successfully with PID $pid");
            return "Tunnel started successfully.";
        } else {
            $this->logMessage('ERROR', "Tunnel failed to start, PID $pid not running");
            // Only unlink PID file if process is confirmed dead
            exec("ps -p $pid >/dev/null 2>&1", $output, $return_var);
            if ($return_var !== 0) {
                unlink($this->pid_file);
                $this->logMessage('DEBUG', "Removed PID file {$this->pid_file} as process is not running");
            }
            return "Tunnel failed to start: process not running.";
        }
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
        
        $pid = trim(file_get_contents($this->pid_file));
        $this->logMessage('DEBUG', "Stopping process with PID $pid");
        $output = [];
        $return_var = 0;
        exec("sudo -H kill $pid 2>&1", $output, $return_var);
        $output_str = implode("\n", $output);
        $this->logMessage('DEBUG', "kill command output: $output_str, return: $return_var");
        
        if ($return_var === 0) {
            unlink($this->pid_file);
            $this->logMessage('INFO', "Tunnel stopped successfully, PID $pid");
            return "Tunnel stopped successfully.";
        } else {
            $this->logMessage('ERROR', "Failed to stop tunnel, PID $pid, error=$output_str");
            return "Failed to stop tunnel.";
        }
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
        
        if (!empty($username) && !empty($ip)) {
            $remote_host = $this->getRemoteHost($username, $ip, $port);
            $debug = $this->debugSshConfig($remote_host)['output'] . "\n\nRecent Logs:\n" . $this->getRecentLogs();
        }
        
        return [
            'status' => $status,
            'server' => !empty($username) && !empty($ip) ? "$username@$ip" . ($port != '22' ? ":$port" : "") : '',
            'debug' => $debug
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

        // Ensure .ssh directory exists
        $ssh_dir = dirname($this->ssh_key);
        if (!is_dir($ssh_dir)) {
            $this->logMessage('DEBUG', "Creating SSH directory: $ssh_dir");
            $mkdir_output = [];
            $mkdir_return = 0;
            exec("mkdir -p " . escapeshellarg($ssh_dir) . " 2>&1", $mkdir_output, $mkdir_return);
            if ($mkdir_return !== 0) {
                $error = "Failed to create SSH directory: " . implode("\n", $mkdir_output);
                $this->logMessage('ERROR', $error);
                return ['status' => false, 'message' => $error];
            }
            
            // Set directory permissions
            $chmod_output = [];
            $chmod_return = 0;
            exec("chmod 700 " . escapeshellarg($ssh_dir) . " 2>&1", $chmod_output, $chmod_return);
            if ($chmod_return !== 0) {
                $error = "Failed to set SSH directory permissions: " . implode("\n", $chmod_output);
                $this->logMessage('ERROR', $error);
                return ['status' => false, 'message' => $error];
            }
            
            // Set ownership
            $chown_output = [];
            $chown_return = 0;
            exec("chown www-data:www-data " . escapeshellarg($ssh_dir) . " 2>&1", $chown_output, $chown_return);
            if ($chown_return !== 0) {
                $error = "Failed to set SSH directory ownership: " . implode("\n", $chown_output);
                $this->logMessage('ERROR', $error);
                return ['status' => false, 'message' => $error];
            }
        }

        // Check directory permissions and ownership
        $dir_perms = substr(sprintf('%o', fileperms($ssh_dir)), -4);
        $dir_owner = posix_getpwuid(fileowner($ssh_dir))['name'];
        $dir_group = posix_getgrgid(filegroup($ssh_dir))['name'];
        $this->logMessage('DEBUG', "SSH directory permissions: $dir_perms, owner: $dir_owner, group: $dir_group");

        // Generate new SSH key pair with no passphrase
        $output = [];
        $return_var = 0;
        $command = "sudo -u www-data ssh-keygen -t rsa -b 4096 -f " . escapeshellarg($this->ssh_key) . " -N '' -C 'haxinator@" . gethostname() . "' 2>&1";
        
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
        
        // Verify the keys were created and check their permissions
        if (!file_exists($this->ssh_key) || !file_exists($this->ssh_key . '.pub')) {
            $error = "SSH keys were not created despite successful command execution";
            $this->logMessage('ERROR', $error);
            return ['status' => false, 'message' => $error];
        }
        
        // Fix permissions
        chmod($this->ssh_key, 0600);
        chmod($this->ssh_key . '.pub', 0644);
        
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