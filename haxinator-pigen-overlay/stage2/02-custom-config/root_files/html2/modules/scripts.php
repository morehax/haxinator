<?php
declare(strict_types=1);

/**
 * Scripts Runner Module
 * Allows running predefined scripts (streaming output) from Control Panel.
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Module metadata – picked up by index.php during discovery
// ─────────────────────────────────────────────────────────────────────────────
$module = [
    'id'          => 'scripts',
    'title'       => 'Scripts',
    'icon'        => 'file-earmark-code',
    'description' => 'Run curated maintenance / diagnostic scripts',
    'category'    => 'tools'
];

// Early-return during metadata discovery.
if (!defined('EMBEDDED_MODULE') && !defined('MODULE_POST_HANDLER')) {
    return;
}

if (!session_id()) session_start();
header('X-Content-Type-Options: nosniff');

// ─────────────────────────────────────────────────────────────────────────────
//  Helper functions (kept identical to other modules for consistency)
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('csrf')) {
    function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
}
if (!function_exists('json_out')) {
    function json_out(array $p,int $c=200): never {
        http_response_code($c);
        header('Content-Type: application/json');
        echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('bad')) {
    function bad(string $m,int $c=400): never { json_out(['error'=>$m], $c); }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Script catalogue (hard-coded / white-listed)
// ─────────────────────────────────────────────────────────────────────────────
$scripts = [
    'wifi_password_test' => [
        'label' => 'Wi-Fi Password Test',
        'exec'  => 'python3',
        'path'  => '/var/www/scripts/wifi-password-test.py',
        'params'=> [  // UI schema
            'ssid'      => ['label'=>'SSID','type'=>'text','required'=>true],
            'interfaces'=> ['label'=>'Interfaces','type'=>'interfaces'], // multi-select list from nmcli
            'timeout'   => ['label'=>'Timeout (s)','type'=>'number','min'=>1,'max'=>30,'default'=>4],
            'backend'   => ['label'=>'Backend','type'=>'select','options'=>['pywifi','wpa_cli'],'default'=>'pywifi'],
            'adaptive'  => ['label'=>'Adaptive Timing','type'=>'checkbox','default'=>true]
        ]
    ]
];

// Convenience alias
$ACTION = $_POST['action'] ?? '';

// ─────────────────────────────────────────────────────────────────────────────
//  POST handler – streaming run
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ACTION === 'run_script') {
    if (($_POST['csrf'] ?? '') !== csrf()) bad('Invalid CSRF token', 403);

    $scriptId = $_POST['script_id'] ?? '';
    if (!isset($scripts[$scriptId])) bad('Unknown script');
    $spec = $scripts[$scriptId];

    // Build command for wifi_password_test (only current implementation)
    if ($scriptId === 'wifi_password_test') {
        $ssid = trim($_POST['ssid'] ?? '');
        $ifaces = $_POST['interfaces'] ?? '';
        $timeout = (int)($_POST['timeout'] ?? 4);
        $backend = $_POST['backend'] ?? 'pywifi';
        $adaptive = ($_POST['adaptive'] ?? 'true') === 'true';

        if ($ssid === '') bad('SSID required');
        if ($timeout < 1 || $timeout > 30) bad('Timeout out of range');

        // Parse interfaces as comma-separated list
        $ifaceArr = array_filter(array_map('trim', explode(',', $ifaces)), static fn($v)=>$v!=='');
        if (!$ifaceArr) bad('At least one interface required');

        // Build argv (sudo -n) plus stdbuf for line buffering if available
        $cmd = ['sudo', '-n'];
        $stdbuf = trim(shell_exec('command -v stdbuf'));
        if ($stdbuf !== '') {
            $cmd[] = $stdbuf;
            $cmd[] = '-oL';
            $cmd[] = '-eL';
        }

        if ($spec['exec'] === 'python3') {
            $cmd[] = $spec['exec'];
            $cmd[] = '-u';
        } else {
            $cmd[] = $spec['exec'];
        }

        $cmd[] = $spec['path'];
        foreach ($ifaceArr as $ifc) {
            // simple iface validation: alnum / – / _
            if (!preg_match('/^[\w.-]{1,15}$/', $ifc)) bad('Bad interface name');
            $cmd[] = '-i';
            $cmd[] = $ifc;
        }
        $cmd[] = '-s';
        $cmd[] = $ssid;
        $cmd[] = '-w';
        $cmd[] = '/var/www/passwords.txt';
        $cmd[] = '--timeout';
        $cmd[] = (string)$timeout;
        $cmd[] = '--backend';
        $cmd[] = $backend;
        if (!$adaptive) {
            $cmd[] = '--no-adaptive';
        }
    } else {
        bad('Script handler missing');
    }

    // Start process with pipes
    $descriptorspec = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w']  // stderr
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes);
    if (!\is_resource($process)) bad('Failed to spawn');

    // Streaming headers
    http_response_code(200);
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    // Disable output buffering in PHP and Apache / FPM
    @apache_setenv('no-gzip', '1');
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'off');
    @ini_set('implicit_flush', '1');
    // Flush any existing output buffers
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);

    // Helper for non-blocking read
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $pid = proc_get_status($process)['pid'] ?? 0;
    // Initial padding to force chunk flush through proxy buffers
    echo str_repeat(' ', 1024) . "\n";
    echo "[PID $pid] Running: " . implode(' ', array_map('escapeshellarg', $cmd)) . "\n\n";
    flush();

    while (true) {
        if (connection_aborted()) {
            // Client gone – terminate child
            proc_terminate($process, 15);
            sleep(1);
            proc_terminate($process, 9);
            break;
        }
        $outLine = fgets($pipes[1]);
        $errLine = fgets($pipes[2]);
        if ($outLine !== false) {
            echo $outLine;
            @ob_flush(); flush();
        }
        if ($errLine !== false) {
            echo $errLine;
            @ob_flush(); flush();
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(50000);
    }

    proc_close($process);
    exit; // Important – stop default page rendering
}

// ─────────────────────────────────────────────────────────────────────────────
//  Embedded UI (HTML + JS)
// ─────────────────────────────────────────────────────────────────────────────

// Discover Wi-Fi interfaces via nmcli (same technique as wifi.php)
$wifiIfs = [];
$raw = shell_exec("nmcli -t -f DEVICE,TYPE device status") ?: '';
foreach (explode("\n", trim($raw)) as $l) {
    [$dev,$typ] = explode(':', $l);
    if (str_ends_with($typ, 'wifi')) $wifiIfs[] = $dev;
}

$csrf = csrf();
?>
<h3 class="mb-3"><i class="bi bi-file-earmark-code me-2"></i>Scripts</h3>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Wi-Fi Password Test</h5>
  </div>
  <div class="card-body">
    <form id="runForm" class="row g-3">
      <div class="col-12 col-md-6">
        <label class="form-label">SSID</label>
        <input type="text" name="ssid" class="form-control" required>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Interfaces</label>
        <select name="interfaces" id="ifaceSelect" class="form-select" multiple required>
          <?php foreach ($wifiIfs as $ifc): ?>
            <option value="<?= htmlspecialchars($ifc) ?>"><?= htmlspecialchars($ifc) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Hold Ctrl / Cmd to pick multiple</div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Timeout (s)</label>
        <input type="number" name="timeout" class="form-control" value="4" min="1" max="30">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Backend</label>
        <select name="backend" class="form-select">
          <option value="pywifi">pywifi</option>
          <option value="wpa_cli">wpa_cli</option>
        </select>
      </div>
      <div class="col-12 col-md-3 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="adaptive" id="adaptiveChk" checked>
          <label class="form-check-label" for="adaptiveChk">Adaptive</label>
        </div>
      </div>
      <div class="col-12 d-flex gap-2 mt-2">
        <button type="submit" class="btn btn-primary" id="runBtn"><i class="bi bi-play me-1"></i>Run</button>
        <button type="button" class="btn btn-secondary" id="stopBtn" disabled><i class="bi bi-stop me-1"></i>Stop</button>
      </div>
    </form>
    <pre id="console" class="bg-dark text-white p-3 mt-4 rounded" style="height:400px; overflow:auto;"></pre>
  </div>
</div>

<script>
(() => {
  const csrf = '<?= htmlspecialchars($csrf) ?>';
  const form = document.getElementById('runForm');
  const runBtn = document.getElementById('runBtn');
  const stopBtn = document.getElementById('stopBtn');
  const consoleEl = document.getElementById('console');
  let es = null;

  function appendOut(txt) {
    consoleEl.textContent += txt;
    consoleEl.scrollTop = consoleEl.scrollHeight;
  }

  stopBtn.addEventListener('click', () => {
    if (es) {
      es.close();
      appendOut('\n[!] Aborted by user.\n');
      stopBtn.disabled = true;
      runBtn.disabled = false;
    }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (es) return;
    consoleEl.textContent = '';
    runBtn.disabled = true; stopBtn.disabled = false;

    const ifSel = document.getElementById('ifaceSelect');
    const chosen = Array.from(ifSel.options).filter(o=>o.selected).map(o=>o.value);
    if (!chosen.length) { alert('Pick at least one interface'); runBtn.disabled=false; stopBtn.disabled=true; return; }

    const params = new URLSearchParams({
      ssid: form.ssid.value,
      interfaces: chosen.join(','),
      timeout: form.timeout.value,
      backend: form.backend.value,
      adaptive: document.getElementById('adaptiveChk').checked ? '1':'0'
    });

    es = new EventSource('scripts-stream.php?' + params.toString());
    es.onmessage = e => { appendOut(e.data + '\n'); };
    es.addEventListener('done', () => { es.close(); es=null; runBtn.disabled=false; stopBtn.disabled=true; });
    es.onerror = () => { appendOut('\n[!] Stream error\n'); es.close(); es=null; runBtn.disabled=false; stopBtn.disabled=true; };
  });
})();
</script> 