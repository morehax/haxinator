<?php
function ping_google() {
    $output = [];
    $retval = -1;
    exec('ping -c 1 -W 1 8.8.8.8 2>/dev/null', $output, $retval);
    $success = ($retval === 0);
    return $success;
}
$ping_ok = ping_google();

echo "Ping function returned: " . var_export($ping_ok, true) . "\n";
echo "Type of \$ping_ok: " . gettype($ping_ok) . "\n";
echo "\nVisual test:\n";
if ($ping_ok): 
    echo "SUCCESS: Would show green door\n";
else:
    echo "FAILED: Would show red door\n";
endif;
?> 