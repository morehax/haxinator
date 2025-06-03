<?php
/**
 * egress-stream.php – Server-Sent Events runner for network egress testing
 * No query parameters needed - the script runs as-is
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

// ───────────────────────── build command ─────────────────────────────
$cmd = ['python3', '-u', '/var/www/scripts/check-egress.py'];

$descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$env = ['PYTHONUNBUFFERED' => '1'];
$proc = proc_open($cmd,$descriptors,$pipes,null,$env,['bypass_shell'=>true]);
if (!is_resource($proc)) {
    echo "data: ERROR: failed to spawn egress check process\n\n"; flush(); exit;
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