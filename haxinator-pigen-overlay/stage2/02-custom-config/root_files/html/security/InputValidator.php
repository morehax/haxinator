<?php
/**
 * InputValidator - A validation framework for user inputs
 * 
 * This class provides methods for validating various types of user input
 * to prevent injection attacks and ensure data integrity.
 */
class InputValidator {
    /**
     * Validate a UUID
     * 
     * @param string $uuid UUID to validate
     * @return bool True if valid, false otherwise
     */
    public static function uuid($uuid) {
        return is_string($uuid) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid);
    }
    
    /**
     * Validate a MAC address
     * 
     * @param string $mac MAC address to validate
     * @return bool True if valid, false otherwise
     */
    public static function mac($mac) {
        if (!is_string($mac)) {
            return false;
        }
        
        // Check basic format (XX:XX:XX:XX:XX:XX or XX-XX-XX-XX-XX-XX)
        if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
            return false;
        }
        
        // Convert to standardized format
        $mac = str_replace('-', ':', strtoupper($mac));
        
        // Check first byte for unicast (least significant bit must be 0)
        // NetworkManager won't accept multicast addresses
        $firstByte = hexdec(substr($mac, 0, 2));
        if ($firstByte & 0x01) {
            return false; // Reject multicast addresses
        }
        
        // Check for invalid addresses
        $invalidMacs = [
            '00:00:00:00:00:00', // Null MAC
            'FF:FF:FF:FF:FF:FF'  // Broadcast MAC
        ];
        
        return !in_array($mac, $invalidMacs);
    }
    
    /**
     * Validate an SSID
     * 
     * @param string $ssid SSID to validate
     * @return bool True if valid, false otherwise
     */
    public static function ssid($ssid) {
        return is_string($ssid) && preg_match('/^[a-zA-Z0-9 _\-\.]{1,32}$/', $ssid);
    }
    
    /**
     * Validate an IP address
     * 
     * @param string $ip IP address to validate
     * @return bool True if valid, false otherwise
     */
    public static function ip($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * Validate a network interface name
     * 
     * @param string $iface Interface name to validate
     * @return bool True if valid, false otherwise
     */
    public static function interface($iface) {
        return is_string($iface) && preg_match('/^[a-zA-Z0-9]{1,15}[0-9]*$/', $iface);
    }
    
    /**
     * Validate a password (basic rules)
     * 
     * @param string $password Password to validate
     * @return bool True if valid, false otherwise
     */
    public static function password($password) {
        return is_string($password) && strlen($password) >= 8;
    }
    
    /**
     * Validate a file path
     * 
     * @param string $path File path to validate
     * @param array $allowed_extensions Allowed file extensions
     * @return bool True if valid, false otherwise
     */
    public static function filePath($path, $allowed_extensions = []) {
        if (!is_string($path)) {
            return false;
        }
        
        // Prevent directory traversal
        if (strpos($path, '..') !== false) {
            return false;
        }
        
        // Check extension if provided
        if (!empty($allowed_extensions)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_extensions)) {
                return false;
            }
        }
        
        return true;
    }
} 