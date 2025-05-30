<?php
/**
 * scripts-stream.php – Server-Sent Events runner for curated scripts
 * Currently supports only the Wi-Fi Password Test script.
 * Query params:
 *   ssid=<ssid>
 *   interfaces=<comma list>
 *   timeout=<int>
 *   backend=pywifi|wpa_cli
 *   adaptive=1|0
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
$ssid      = trim($_GET['ssid'] ?? '');
$ifacesRaw = trim($_GET['interfaces'] ?? '');
$timeout   = (int)($_GET['timeout'] ?? 4);
$backend   = $_GET['backend'] ?? 'pywifi';
$adaptive  = ($_GET['adaptive'] ?? '1') === '1';

if ($ssid === '' || $ifacesRaw === '') {
    echo "data: ERROR: missing ssid/interfaces\n\n"; flush();
    exit;
}
$ifaceArr = array_filter(array_map('trim', explode(',', $ifacesRaw)), fn($v)=>$v!=='');
if (!$ifaceArr) {
    echo "data: ERROR: bad interface list\n\n"; flush();
    exit;
}
if ($timeout < 1 || $timeout > 30) $timeout = 4;

// ───────────────────────── build command ─────────────────────────────
$scriptPath = '/var/www/scripts/wifi-password-test.py';
$cmd = ['sudo','-n','python3','-u',$scriptPath];
foreach ($ifaceArr as $ifc) { $cmd[]='-i'; $cmd[]=$ifc; }
$cmd[]='-s'; $cmd[]=$ssid;
$cmd[]='-w'; $cmd[]='/var/www/passwords.txt';
$cmd[]='--timeout'; $cmd[]=(string)$timeout;
$cmd[]='--backend'; $cmd[]=$backend;
if (!$adaptive) $cmd[]='--no-adaptive';

$descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$proc = proc_open($cmd,$descriptors,$pipes,null,null,['bypass_shell'=>true]);
if (!is_resource($proc)) {
    echo "data: ERROR: failed to spawn\n\n"; flush(); exit;
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$pid = proc_get_status($proc)['pid'] ?? 0;
echo "data: [PID $pid] " . implode(' ', array_map('escapeshellarg',$cmd)) . "\n\n"; flush();

while (true) {
    if (connection_aborted()) {
        proc_terminate($proc, 15); sleep(1); proc_terminate($proc, 9); break;
    }
    $out = fgets($pipes[1]);
    $err = fgets($pipes[2]);
    if ($out !== false) { echo 'data: '.rtrim($out)."\n\n"; flush(); }
    if ($err !== false) { echo 'data: '.rtrim($err)."\n\n"; flush(); }
    if (feof($pipes[1]) && feof($pipes[2])) break;
    usleep(30000);
}
foreach ($pipes as $p) fclose($p);
proc_close($proc);

echo "event: done\ndata: finished\n\n"; flush(); 