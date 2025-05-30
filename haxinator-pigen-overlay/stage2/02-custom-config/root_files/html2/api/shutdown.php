<?php
/**
 * System Shutdown API
 * Provides secure system shutdown functionality
 */

declare(strict_types=1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Basic session check (can be enhanced with proper auth later)
if (!isset($_SESSION['csrf'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if ($csrf !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}

header('Content-Type: application/json');

try {
    // Execute shutdown command
    $output = [];
    $return_var = -1;
    exec('sudo /sbin/poweroff 2>&1', $output, $return_var);

    if ($return_var === 0) {
        echo json_encode(['success' => true, 'message' => 'System is shutting down...']);
    } else {
        error_log("Shutdown failed. Output: " . implode("\n", $output));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to shutdown system. Error: ' . implode(", ", $output)]);
    }
} catch (Exception $e) {
    error_log("Shutdown error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error occurred']);
}
?> 