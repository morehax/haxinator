<?php
/**
 * CSRFProtection - Protection against Cross-Site Request Forgery attacks
 * 
 * This class provides methods for generating and validating CSRF tokens
 * to prevent CSRF attacks.
 */
class CSRFProtection {
    /**
     * Generate a CSRF token
     * 
     * @return string The generated CSRF token
     */
    public static function generateToken() {
        if (empty($_SESSION['csrf_token'])) {
            if (function_exists('random_bytes')) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Get the current CSRF token
     * 
     * @return string The current CSRF token
     */
    public static function getToken() {
        return self::generateToken();
    }
    
    /**
     * Validate a CSRF token
     * 
     * @param string $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateToken($token) {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Generate a hidden input field with the CSRF token
     * 
     * @return string HTML for the hidden input field
     */
    public static function tokenField() {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Validate a request's CSRF token
     * 
     * @return bool True if valid, false otherwise
     */
    public static function validateRequest() {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        return self::validateToken($token);
    }
    
    /**
     * Check if a request has a valid CSRF token, die if not
     */
    public static function enforceCheck() {
        if (!self::validateRequest()) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
} 