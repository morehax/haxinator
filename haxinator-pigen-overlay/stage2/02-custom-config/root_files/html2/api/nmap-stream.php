<?php
/**
 * nmap-stream.php – Server-Sent Events runner for nmap port scanning
 * Query params:
 *   target=<ip/host>
 *   ports=<port range>
 *   scan_type=<scan type>
 *   timing=<timing template>
 *   os_detect=1|0
 *   service_detect=1|0
 */

declare(strict_types=1);

session_start();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
set_time_limit(0);
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

echo ": init\n\n"; flush();

// ───────────────────────── validate input ────────────────────────────
$target = trim($_GET['target'] ?? '');
$ports = trim($_GET['ports'] ?? '1-1000');
$scan_type = $_GET['scan_type'] ?? 'TCP SYN (-sS)';
$timing = $_GET['timing'] ?? 'T3 (Normal)';
$os_detect = ($_GET['os_detect'] ?? '0') === '1';
$service_detect = ($_GET['service_detect'] ?? '0') === '1';

if ($target === '') {
    echo "data: ERROR: missing target\n\n"; flush();
    exit;
}

if ($ports === '') {
    echo "data: ERROR: missing ports\n\n"; flush();
    exit;
}

// Basic target validation
if (!filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && 
    !preg_match('/^[a-zA-Z0-9.-]+$/', $target)) {
    echo "data: ERROR: invalid target format\n\n"; flush();
    exit;
}

// Basic port validation
if (!preg_match('/^[\d,\-\s]+$/', $ports)) {
    echo "data: ERROR: invalid port format\n\n"; flush();
    exit;
}

// ───────────────────────── build command ─────────────────────────────
$cmd = ['nmap']; // Remove sudo by default

// Add scan type
switch ($scan_type) {
    case 'TCP Connect (-sT) [Recommended]':
    case 'TCP Connect (-sT)':
        $cmd[] = '-sT';
        break;
    case 'TCP SYN (-sS) [Requires Root]':
    case 'TCP SYN (-sS)':
        // SYN scans require root, so we'll use TCP connect instead
        $cmd[] = '-sT';
        echo "data: [INFO] Using TCP Connect scan instead of SYN (no root required)\n\n"; flush();
        break;
    case 'UDP (-sU) [Requires Root]':
    case 'UDP (-sU)':
        // UDP scans typically require root, so we'll skip or warn
        echo "data: [WARNING] UDP scans may require root privileges\n\n"; flush();
        $cmd[] = '-sU';
        break;
    case 'TCP SYN + UDP (-sS -sU) [Requires Root]':
    case 'TCP SYN + UDP (-sS -sU)':
        // Use TCP connect instead of SYN
        $cmd[] = '-sT';
        echo "data: [INFO] Using TCP Connect instead of SYN+UDP (no root required)\n\n"; flush();
        break;
}

// Add timing
$timing_flag = substr($timing, 0, 2); // Extract T0, T1, etc.
$cmd[] = '-' . $timing_flag;

// Add port specification
$cmd[] = '-p';
$cmd[] = $ports;

// Add optional flags
if ($os_detect) {
    $cmd[] = '-O';
}
if ($service_detect) {
    $cmd[] = '-sV';
}

// Add target
$cmd[] = $target;

// Add verbose output and no ping (to avoid issues with hosts that don't respond to ping)
$cmd[] = '-v';
$cmd[] = '-Pn';

$descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$env = [];
$proc = proc_open($cmd,$descriptors,$pipes,null,$env,['bypass_shell'=>true]);
if (!is_resource($proc)) {
    echo "data: ERROR: failed to spawn nmap\n\n"; flush(); exit;
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$loopCount = 0;
while (true) {
    $loopCount++;
    
    if (connection_aborted()) {
        proc_terminate($proc, 15); sleep(1); proc_terminate($proc, 9); break;
    }
    
    // Read multiple lines if available (more aggressive reading)
    $hasOutput = false;
    while (($out = fgets($pipes[1])) !== false) {
        echo 'data: '.rtrim($out)."\n\n"; flush(); 
        $hasOutput = true;
    }
    while (($err = fgets($pipes[2])) !== false) {
        echo 'data: '.rtrim($err)."\n\n"; flush(); 
        $hasOutput = true;
    }
    
    $status = proc_get_status($proc);
    if (!$status['running']) {
        // Try to read any remaining output after process ends
        while (($out = fgets($pipes[1])) !== false) {
            echo 'data: '.rtrim($out)."\n\n"; flush(); 
        }
        while (($err = fgets($pipes[2])) !== false) {
            echo 'data: '.rtrim($err)."\n\n"; flush(); 
        }
        break;
    }
    
    if (feof($pipes[1]) && feof($pipes[2])) {
        break;
    }
    
    // Shorter delay for faster scripts, longer delay if no output
    usleep($hasOutput ? 5000 : 30000); // 5ms if output, 30ms if no output
}
foreach ($pipes as $p) fclose($p);
proc_close($proc);

echo "event: done\ndata: finished\n\n"; flush(); 