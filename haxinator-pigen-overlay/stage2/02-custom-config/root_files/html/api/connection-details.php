<?php
/**
 * Returns JSON details about a particular connection (identified by UUID).
 *
 * Moved from /var/www/html/api/connection-details.php (stays in /api directory),
 * but now references the refactored config, auth, and network class locations.
 */

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../network/Network.php';

// Ensure user is authenticated
$auth = new Auth($config['username'], $config['password']);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get UUID from query string
$uuid = $_GET['uuid'] ?? '';
if (empty($uuid)) {
    http_response_code(400);
    echo json_encode(['error' => 'UUID is required']);
    exit;
}

// Get connection details
$network = new Network();
$details = $network->getConnectionDetails($uuid);

// Return as JSON
header('Content-Type: application/json');
echo json_encode($details, JSON_UNESCAPED_UNICODE);
