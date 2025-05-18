<?php
/**
 * SecureCommand - A secure layer for executing shell commands
 * 
 * This class provides methods for safely executing shell commands with
 * proper input sanitization to prevent command injection vulnerabilities.
 */
class SecureCommand {
    /**
     * Execute a shell command with proper escaping of arguments
     * 
     * @param string $command Command template with placeholders
     * @param array $args Arguments to be escaped and inserted into the command
     * @param array &$output Optional output array to store command output
     * @param int &$return_code Optional return code from command execution
     * @return string The last line of command output
     */
    public static function execute($command, $args = [], &$output = null, &$return_code = null) {
        // Validate command is not empty
        if (empty($command)) {
            throw new Exception("Empty command provided");
        }
        
        // Escape all arguments
        $escaped_args = array_map('escapeshellarg', $args);
        
        // Format the command with escaped arguments
        $safe_command = vsprintf($command, $escaped_args);
        
        // Log the command execution (for debugging and auditing)
        error_log("Executing command: " . $safe_command);
        
        // Execute the command
        $result = exec($safe_command, $output, $return_code);
        
        return $result;
    }
    
    /**
     * Execute a command and capture all output
     * 
     * @param string $command Command template with placeholders
     * @param array $args Arguments to be escaped and inserted into the command
     * @param int &$return_code Optional return code from command execution
     * @return array Command output lines as array
     */
    public static function executeWithOutput($command, $args = [], &$return_code = null) {
        $output = [];
        self::execute($command, $args, $output, $return_code);
        return $output;
    }
    
    /**
     * Execute a NetworkManager command with proper escaping
     * 
     * @param string $command NetworkManager command template
     * @param array $args Arguments for the command
     * @param array &$output Optional output array to store command output
     * @param int &$return_code Optional return code from command execution
     * @return mixed The command output
     */
    public static function nmcli($command, $args = [], &$output = null, &$return_code = null) {
        return self::execute("nmcli " . $command, $args, $output, $return_code);
    }

    /**
     * Restart a NetworkManager connection safely
     * 
     * @param string $uuid Connection UUID to restart
     * @return bool True if successful, false otherwise
     */
    public static function restartConnection($uuid) {
        $uuid_arg = escapeshellarg($uuid);
        $down_result = self::execute("nmcli connection down %s", [$uuid], $down_output, $down_code);
        
        // Sleep to ensure connection has time to go down
        sleep(2);
        
        $up_result = self::execute("nmcli connection up %s", [$uuid], $up_output, $up_code);
        
        return ($down_code === 0 && $up_code === 0);
    }
} 