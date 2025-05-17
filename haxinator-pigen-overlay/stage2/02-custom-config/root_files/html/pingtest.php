<?php
$output = [];
$retval = null;
echo "Starting ping test...\n";
exec('ping -c 1 -W 1 8.8.8.8 2>/dev/null', $output, $retval);
echo "Return value: " . $retval . "\n";
echo "Output: \n" . implode("\n", $output) . "\n";
echo "Ping status: " . ($retval === 0 ? "SUCCESS" : "FAILED") . "\n";
?> 