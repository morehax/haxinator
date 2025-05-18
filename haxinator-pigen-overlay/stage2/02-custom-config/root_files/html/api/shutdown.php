<?php
// Include security framework
require_once __DIR__ . '/../security/bootstrap.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    die('Unauthorized');
}

// Check CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check is already handled by bootstrap.php
}

// Execute shutdown command
exec('sudo /sbin/poweroff 2>&1', $output, $return_var);

if ($return_var === 0) {
    echo json_encode(['success' => true, 'message' => 'System is shutting down...']);
} else {
    error_log("Shutdown failed. Output: " . implode("\n", $output));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to shutdown system. Error: ' . implode(", ", $output)]);
}
?> 