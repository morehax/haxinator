<?php
/**
 * Utility functions for the Haxinator 2000
 */

class Util {
    /**
     * Get public IP address from ifconfig.me
     * 
     * @return string|bool IP address or false if not available
     */
    public static function getPublicIp() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://ifconfig.me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'curl');
        $ip = trim(curl_exec($ch));
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (filter_var($ip, FILTER_VALIDATE_IP) && $httpcode === 200) {
            return $ip;
        }
        return false;
    }

    /**
     * Check if DNS can resolve google.com
     * 
     * @return bool True if DNS works
     */
    public static function dnsResolvesGoogle() {
        $output = null;
        $retval = null;
        exec('dig +short google.com', $output, $retval);
        foreach ($output as $line) {
            if (filter_var($line, FILTER_VALIDATE_IP)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if we can ping Google's DNS server
     * 
     * @return bool True if ping works
     */
    public static function pingGoogle() {
        $output = [];
        $retval = -1;
        exec('ping -c 1 -W 1 8.8.8.8 2>/dev/null', $output, $retval);
        if ($retval === 0) {
            return true;
        }
        return false;
    }
} 