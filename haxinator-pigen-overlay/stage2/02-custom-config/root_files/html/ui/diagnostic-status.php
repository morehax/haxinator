<?php
// Diagnostic script for network status checks
header('Content-Type: text/plain; charset=utf-8');

echo "=== CURL ifconfig.me ===\n";

// Test curl to ifconfig.me
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://ifconfig.me');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_USERAGENT, 'curl');
$ip = trim(curl_exec($ch));
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "IP: $ip\n";
echo "cURL errno: $curl_errno\n";
echo "cURL error: $curl_error\n";
echo "HTTP code: $httpcode\n\n";

echo "=== exec('dig +short google.com') ===\n";
$output = null;
$retval = null;
exec('dig +short google.com', $output, $retval);
echo "Return value: $retval\n";
echo "Output lines:\n";
foreach ($output as $line) {
    echo $line . "\n";
}

echo "\n=== PHP Info (disabled functions) ===\n";
echo ini_get('disable_functions') . "\n"; 