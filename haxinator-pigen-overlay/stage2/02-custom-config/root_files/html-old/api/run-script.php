<?php
/**
 * API endpoint for running various network scripts
 * Handles script execution and output capture
 */

// Ensure this script is being called via AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    die('Direct access not permitted');
}

// Get the script name and parameters
$script = $_GET['script'] ?? '';
$output = '';

// Validate and sanitize script name
$allowed_scripts = [
    'check.py' => '/var/www/html/check.py',
    'nmap.sh' => '/var/www/html/nmap.sh',
    'dig' => 'dig'  // Built-in command
];

if (!isset($allowed_scripts[$script])) {
    die('Invalid script specified');
}

$script_path = $allowed_scripts[$script];

// Execute the appropriate script based on the name
switch ($script) {
    case 'check.py':
        // Run check.py with no parameters
        if (!is_executable($script_path)) {
            die('Error: Script is not executable');
        }
        exec($script_path . ' 2>&1', $output, $return_var);
        if ($return_var !== 0) {
            die('Error: Script execution failed with code ' . $return_var);
        }
        break;

    case 'nmap.sh':
        // Get and validate parameters
        $host = $_GET['host'] ?? '';
        $range = $_GET['range'] ?? '';
        
        if (empty($host) || empty($range)) {
            die('Missing required parameters');
        }
        
        // Sanitize parameters
        $host = escapeshellarg($host);
        $range = escapeshellarg($range);
        
        // Run nmap.sh with parameters
        if (!is_executable($script_path)) {
            die('Error: Script is not executable');
        }
        exec($script_path . ' ' . $host . ' ' . $range . ' 2>&1', $output, $return_var);
        if ($return_var !== 0) {
            die('Error: Script execution failed with code ' . $return_var);
        }
        break;

    case 'dig':
        // Get and validate parameters
        $domain = $_GET['domain'] ?? '';
        $type = $_GET['type'] ?? 'A';
        
        if (empty($domain)) {
            die('Missing domain parameter');
        }
        
        // Sanitize parameters
        $domain = escapeshellarg($domain);
        $type = escapeshellarg($type);
        
        // Run dig command
        exec("dig $type $domain +short 2>&1", $output, $return_var);
        if ($return_var !== 0) {
            die('Error: dig command failed with code ' . $return_var);
        }
        break;
}

// Output the results
echo implode("\n", $output); 
