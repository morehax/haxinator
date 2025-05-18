<?php
/**
 * SSE endpoint for scanning a selected Wi-Fi network.
 *
 * Moved from /var/www/html/ping-stream.php to /var/www/html/api/ping-stream.php
 */

// Include security framework
require_once __DIR__ . '/../security/bootstrap.php';

// Ensure user is authenticated
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    die('data: Unauthorized, please login first' . "\n\n" . "event: done\ndata: \n\n");
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Content-Encoding: none');

$ssid = isset($_GET['ssid']) ? preg_replace('/[^a-zA-Z0-9 _\-\.]/', '', $_GET['ssid']) : '';
if (!$ssid) {
    echo "data: No SSID selected\n\n";
    echo "event: done\ndata: \n\n";
    flush(); if (function_exists('ob_flush')) ob_flush();
    exit;
}

$cmd = '/var/www/html/wifi-scan.sh ' . escapeshellarg($ssid);

$proc = popen($cmd . " 2>&1", "r");
if (!$proc) {
    echo "data: Failed to start process\n\n";
    echo "event: done\ndata: \n\n";
    flush(); if (function_exists('ob_flush')) ob_flush();
    exit;
}

while (!feof($proc)) {
    $line = fgets($proc);
    if ($line === false) break;
    echo "data: " . rtrim($line) . "\n\n";
    flush(); if (function_exists('ob_flush')) ob_flush();
    usleep(50000);
}
pclose($proc);

echo "event: done\ndata: \n\n";
flush(); if (function_exists('ob_flush')) ob_flush();
?>
