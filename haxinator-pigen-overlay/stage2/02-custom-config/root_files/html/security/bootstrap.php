<?php
/**
 * Security Bootstrap - Load all security components
 * 
 * This file loads all security components and should be included
 * at the beginning of each file that requires security features.
 */

// Check if session is already started, if not start it
if (session_status() === PHP_SESSION_NONE) {
    // Set secure session parameters
    ini_set('session.cookie_httponly', 1);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Define path to security directory
$securityDir = __DIR__;

// Include security components
require_once $securityDir . '/SecureCommand.php';
require_once $securityDir . '/InputValidator.php';
require_once $securityDir . '/CSRFProtection.php';

// Set default content security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Optional: Set strict Content-Security-Policy
// Uncomment for production use after testing
// header("Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; frame-ancestors 'none';");

// Function to check if a request needs CSRF validation
function requiresCSRFValidation($method) {
    return in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH']);
}

// Automatically validate CSRF tokens for unsafe methods
if (requiresCSRFValidation($_SERVER['REQUEST_METHOD'])) {
    // Skip validation for specific paths or conditions
    $skip_paths = [
        '/api/upload.php',  // Uses session auth instead
        '/api/run-script.php'  // Uses custom auth
    ];
    
    $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    // Skip validation for login form
    $is_login_request = isset($_POST['login_username']) && isset($_POST['login_password']);
    
    if (!in_array($current_path, $skip_paths) && !$is_login_request) {
        // Check CSRF token
        if (!CSRFProtection::validateRequest()) {
            // Only enforce for non-API paths or when explicitly requested
            if (strpos($current_path, '/api/') !== 0 || isset($_GET['enforce_csrf'])) {
                http_response_code(403);
                die('CSRF token validation failed');
            }
        }
    }
} 