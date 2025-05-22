<?php
/**
 * ping-stream.php – Server-Sent Events endpoint
 * Streams the output of 5 pings to 8.8.8.8 line-by-line.
 * Requires: /bin/ping (with cap_net_raw or SUID) and, if available,
 *           coreutils’ stdbuf for unbuffered output.
 */

/* ── HTTP & PHP streaming headers ─────────────────────────────── */
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');      // nginx: disable buffering

ini_set('output_buffering', '0');
ini_set('zlib.output_compression', '0');
set_time_limit(0);
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

/* ── Send an “init” comment so the browser knows we’re connected ─ */
echo ": init\n\n"; flush();

/* ── Build the ping command ───────────────────────────────────── */
$pingBin = '/bin/ping';                     // full path avoids $PATH issues
$pingCmd = "$pingBin -n -c 5 8.8.8.8";      // numeric output, 5 probes
$cmd     = (shell_exec('command -v stdbuf') ? "stdbuf -oL -eL " : '') . $pingCmd;

/* ── Launch subprocess with correct pipe directions ───────────── */
$proc = proc_open(
    $cmd,
    [
        0 => ['pipe', 'r'],     // child stdin  (unused)
        1 => ['pipe', 'w'],     // child stdout → PHP reads
        2 => ['pipe', 'w'],     // child stderr → PHP reads
    ],
    $pipes,
    null,
    null,
    ['bypass_shell' => true]
);

if (!$proc) {
    echo "data: ERROR: could not start ping\n\n"; flush();
    exit;
}

/* ── Non-blocking so we can interleave stdout + stderr ────────── */
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

/* ── Main read loop ───────────────────────────────────────────── */
while (true) {
    $out = fgets($pipes[1]);
    $err = fgets($pipes[2]);

    if ($out !== false) {
        echo 'data: ' . rtrim($out) . "\n\n";
        flush();
    }
    if ($err !== false) {
        echo 'data: ' . rtrim($err) . "\n\n";
        flush();
    }

    if (feof($pipes[1]) && feof($pipes[2])) {
        break;                               // process is done
    }
    usleep(20000);                           // 20 ms throttle
}

/* ── Clean-up and final event ─────────────────────────────────── */
foreach ($pipes as $p) fclose($p);
proc_close($proc);

echo "event: done\ndata: finished\n\n";
flush();
