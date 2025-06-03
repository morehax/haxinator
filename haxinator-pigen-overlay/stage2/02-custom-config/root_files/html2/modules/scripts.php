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
//  Script catalogue and handlers
// ─────────────────────────────────────────────────────────────────────────────
$scripts = [
    'wifi_password_test' => [
        'label' => 'WiFi Password Test',
        'description' => 'Generate command to test WiFi passwords (copy/paste to terminal)',
        'mode' => 'command_only', // Special mode - generates command instead of streaming
        'params'=> [
            'ssid'      => ['label'=>'SSID','type'=>'text','required'=>true],
            'interfaces'=> ['label'=>'Interfaces','type'=>'interfaces'],
            'timeout'   => ['label'=>'Timeout (s)','type'=>'number','min'=>1,'max'=>30,'default'=>4],
            'backend'   => ['label'=>'Backend','type'=>'select','options'=>['pywifi','wpa_cli'],'default'=>'pywifi'],
            'adaptive'  => ['label'=>'Adaptive Timing','type'=>'checkbox','default'=>true]
        ],
        'handler' => 'execute_wifi_test'
    ],
    'nmap_scan' => [
        'label' => 'Nmap Port Scan',
        'description' => 'Scan target IP for open ports using Nmap',
        'params' => [
            'target'    => ['label'=>'Target IP/Host','type'=>'text','required'=>true],
            'ports'     => ['label'=>'Ports','type'=>'text','default'=>'1-1000','required'=>true],
            'scan_type' => ['label'=>'Scan Type','type'=>'select','options'=>['TCP Connect (-sT) [Recommended]','TCP SYN (-sS) [Requires Root]','UDP (-sU) [Requires Root]','TCP SYN + UDP (-sS -sU) [Requires Root]'],'default'=>'TCP Connect (-sT) [Recommended]'],
            'timing'    => ['label'=>'Timing','type'=>'select','options'=>['T0 (Paranoid)','T1 (Sneaky)','T2 (Polite)','T3 (Normal)','T4 (Aggressive)','T5 (Insane)'],'default'=>'T3 (Normal)'],
            'port_filter' => ['label'=>'Port Filter','type'=>'select','options'=>['All Ports','Open Ports Only (--open)','Top 100 Ports (--top-ports 100)','Top 1000 Ports (--top-ports 1000)'],'default'=>'All Ports'],
            'show_reason' => ['label'=>'Show port state reason (--reason)','type'=>'checkbox','default'=>false],
            'aggressive' => ['label'=>'Aggressive scan -A (OS, version, scripts, traceroute)','type'=>'checkbox','default'=>false],
            'script_scan' => ['label'=>'Script scan -sC (default scripts only)','type'=>'checkbox','default'=>false],
            'traceroute' => ['label'=>'Traceroute (--traceroute)','type'=>'checkbox','default'=>false],
            'fragment' => ['label'=>'Fragment packets -f (firewall evasion)','type'=>'checkbox','default'=>false],
            'timeouts' => ['label'=>'Host Timeout (seconds)','type'=>'number','min'=>1,'max'=>300,'default'=>30],
            'max_retries' => ['label'=>'Max Retries','type'=>'number','min'=>0,'max'=>10,'default'=>3],
            'source_port' => ['label'=>'Source Port (optional)','type'=>'number','min'=>1,'max'=>65535,'default'=>''],
            'os_detect' => ['label'=>'OS Detection','type'=>'checkbox','default'=>false],
            'service_detect' => ['label'=>'Service Detection','type'=>'checkbox','default'=>false]
        ],
        'handler' => 'execute_nmap'
    ],
    'check_egress' => [
        'label' => 'Network Egress Test',
        'description' => 'Test outbound network connectivity (DNS, ICMP, HTTP, TCP, UDP)',
        'params' => [],
        'handler' => 'execute_egress_check'
    ]
];

// ─────────────────────────────────────────────────────────────────────────────
//  Script execution handlers
// ─────────────────────────────────────────────────────────────────────────────

function execute_wifi_test($params, $spec) {
    $ssid = trim($params['ssid'] ?? '');
    $ifaces = $params['interfaces'] ?? '';
    $timeout = (int)($params['timeout'] ?? 4);
    $backend = $params['backend'] ?? 'pywifi';
    $adaptive = ($params['adaptive'] ?? 'true') === 'true';

    if ($ssid === '') bad('SSID required');
    if ($timeout < 1 || $timeout > 30) bad('Timeout out of range');

    // Parse interfaces as comma-separated list
    $ifaceArr = array_filter(array_map('trim', explode(',', $ifaces)), static fn($v)=>$v!=='');
    if (!$ifaceArr) bad('At least one interface required');

    // Build command with sudo FIRST, then stdbuf for line buffering
    $cmd = ['sudo', '-n'];
    $stdbuf = trim(shell_exec('command -v stdbuf'));
    if ($stdbuf !== '') {
        $cmd[] = $stdbuf;
        $cmd[] = '-oL';
        $cmd[] = '-eL';
    }

    $cmd[] = 'python3';
    $cmd[] = '-u';
    $cmd[] = '/var/www/scripts/wifi-password-test.py';
    
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

    return $cmd;
}

function execute_nmap($params, $spec) {
    $target = trim($params['target'] ?? '');
    $ports = trim($params['ports'] ?? '1-1000');
    $scan_type = $params['scan_type'] ?? 'TCP SYN (-sS)';
    $timing = $params['timing'] ?? 'T3 (Normal)';
    $os_detect = ($params['os_detect'] ?? 'false') === 'true';
    $service_detect = ($params['service_detect'] ?? 'false') === 'true';

    if ($target === '') bad('Target IP/Host required');
    if ($ports === '') bad('Ports required');

    // Basic target validation
    if (!filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && 
        !preg_match('/^[a-zA-Z0-9.-]+$/', $target)) {
        bad('Invalid target format');
    }

    // Basic port validation - allow ranges, comma-separated, individual ports
    if (!preg_match('/^[\d,\-\s]+$/', $ports)) {
        bad('Invalid port format');
    }

    // Build nmap command
    $cmd = ['sudo', '-n', 'nmap'];

    // Add scan type
    switch ($scan_type) {
        case 'TCP SYN (-sS) [Requires Root]':
        case 'TCP SYN (-sS)':
            // SYN scans require root, so we'll use TCP connect instead
            $cmd[] = '-sT';
            echo "data: [INFO] Using TCP Connect scan instead of SYN (no root required)\n\n"; flush();
            break;
        case 'TCP Connect (-sT) [Recommended]':
        case 'TCP Connect (-sT)':
            $cmd[] = '-sT';
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

    return $cmd;
}

function execute_egress_check($params, $spec) {
    // No parameters needed for egress check - it runs as-is
    $cmd = ['python3', '-u', '/var/www/scripts/check-egress.py'];
    return $cmd;
}

// ─────────────────────────────────────────────────────────────────────────────
//  POST handler for validation only (streaming happens via api/*.php endpoints)
// ─────────────────────────────────────────────────────────────────────────────
$ACTION = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ACTION === 'validate_script') {
    if (($_POST['csrf'] ?? '') !== csrf()) bad('Invalid CSRF token', 403);

    $scriptId = $_POST['script_id'] ?? '';
    if (!isset($scripts[$scriptId])) bad('Unknown script');
    
    // Basic validation passed
    json_out(['success' => true, 'message' => 'Validation passed']);
}

// WiFi scanning for script configuration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ACTION === 'scan_wifi') {
    if (($_POST['csrf'] ?? '') !== csrf()) bad('Invalid CSRF token', 403);
    
    $iface = $_POST['iface'] ?? '';
    if ($iface && !preg_match('/^[\w\-.]{1,15}$/', $iface)) bad('Bad interface');
    
    if (!$iface) {
        $iface = trim(shell_exec("nmcli -t -f DEVICE,TYPE device status | grep ':wifi' | cut -d: -f1 | head -n1") ?: '');
        if ($iface === '') bad('No Wi-Fi devices');
    }
    
    // Check if we should force a rescan (default: yes for manual refresh)
    $rescan = ($_POST['rescan'] ?? 'yes') === 'yes' ? 'yes' : 'no';
    
    $fields = 'IN-USE,SSID,BSSID,CHAN,FREQ,RATE,SIGNAL,SECURITY,DEVICE,MODE';
    $raw = shell_exec(sprintf('nmcli -t --escape yes -f %s dev wifi list ifname %s --rescan %s 2>&1', 
        escapeshellarg($fields), escapeshellarg($iface), $rescan)) ?? '';
    
    $nets = [];
    foreach (explode("\n", trim($raw)) as $l) {
        if (!$l) continue;
        $c = preg_split('/(?<!\\\\):/', $l);
        $c = array_pad($c, 10, '');
        $c = array_map(fn($v) => str_replace(['\\:', '\\\\'], [':', '\\'], $v), $c);
        [$in, $ssid, $bssid, $ch, $fq, $rate, $sig, $sec, $dev, $mode] = $c;
        
        // Skip networks without SSID or hidden networks
        if (!$ssid || $ssid === '--') continue;
        
        $fqN = (int)filter_var($fq, FILTER_SANITIZE_NUMBER_INT);
        $nets[] = [
            'in_use' => $in === '*',
            'ssid' => $ssid,
            'bssid' => $bssid,
            'chan' => (int)$ch,
            'band' => $fqN >= 5950 ? '6 GHz' : ($fqN > 4900 ? '5 GHz' : '2.4 GHz'),
            'rate' => $rate,
            'signal' => (int)$sig,
            'security' => $sec ?: 'OPEN',
            'device' => $dev,
            'mode' => $mode
        ];
    }
    
    // Sort by signal strength (strongest first)
    usort($nets, fn($a, $b) => $b['signal'] <=> $a['signal']);
    
    json_out(['networks' => $nets, 'iface' => $iface]);
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
<style>
.spin {
  animation: spin 1s linear infinite;
}
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
<h3 class="mb-3"><i class="bi bi-file-earmark-code me-2"></i>Scripts</h3>

<!-- Script Selection -->
<div class="row g-3 mb-4 align-items-end">
  <div class="col-12 col-md-6">
    <label class="form-label mb-1 d-block">Select Script</label>
    <select id="scriptSelect" class="form-select">
      <option value="">-- Choose a script --</option>
      <?php foreach ($scripts as $id => $script): ?>
        <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($script['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <div id="scriptDescription" class="form-text"></div>
  </div>
  <div class="col-auto" id="busy" style="display:none">
    <div class="spinner-border spinner-border-sm text-secondary"></div>
  </div>
</div>

<!-- Dynamic Parameter Form -->
<form id="runForm" style="display: none;">
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0" id="configTitle"><i class="bi bi-gear me-2"></i>Configuration</h5>
    </div>
    <div class="card-body">
      <div id="parameterContainer">
        <!-- Parameters will be dynamically generated here -->
      </div>
      
      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary" id="runBtn">
          <i class="bi bi-play-fill me-1"></i>Run Script
        </button>
        <button type="button" class="btn btn-secondary" id="stopBtn" disabled>
          <i class="bi bi-stop-fill me-1"></i>Stop
        </button>
      </div>
    </div>
  </div>
</form>

<!-- Console Output -->
<div class="card" id="consoleCard" style="display: none;">
  <div class="card-header">
    <h5 class="mb-0"><i class="bi bi-terminal me-2"></i>Console Output</h5>
  </div>
  <div class="card-body">
    <pre id="console" class="bg-dark text-white p-3 rounded" style="height:400px; overflow:auto; font-size: 0.9rem; font-family: 'Consolas', 'Monaco', 'Courier New', monospace;">Console ready - select and run a script to see output here...</pre>
  </div>
</div>

<script>
(() => {
  console.log('Scripts module JavaScript loaded!');
  
  const csrf = '<?= htmlspecialchars($csrf) ?>';
  const scripts = <?= json_encode($scripts) ?>;
  const wifiInterfaces = <?= json_encode($wifiIfs) ?>;
  
  const scriptSelect = document.getElementById('scriptSelect');
  const scriptDescription = document.getElementById('scriptDescription');
  const runForm = document.getElementById('runForm');
  const parameterContainer = document.getElementById('parameterContainer');
  const runBtn = document.getElementById('runBtn');
  const stopBtn = document.getElementById('stopBtn');
  const consoleEl = document.getElementById('console');
  const consoleCard = document.getElementById('consoleCard');
  const configTitle = document.getElementById('configTitle');
  
  let abortController = null;

  function appendOut(txt) {
    consoleEl.textContent += txt;
    consoleEl.scrollTop = consoleEl.scrollHeight;
  }

  // Generate form fields based on script parameters with improved UI
  function generateParameterForm(scriptId) {
    parameterContainer.innerHTML = '';
    
    if (!scripts[scriptId]) return;
    
    const params = scripts[scriptId].params || {};
    
    if (scriptId === 'wifi_password_test') {
      generateWifiForm(params);
    } else if (scriptId === 'nmap_scan') {
      generateNmapForm(params);
    } else {
      generateGenericForm(params);
    }
  }
  
  function generateWifiForm(params) {
    configTitle.innerHTML = '<i class="bi bi-wifi me-2"></i>WiFi Password Test Configuration';
    
    // Essential Settings
    const essentialHtml = `
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label">Scanning Interface</label>
          <div class="input-group">
            <select id="scanInterface" class="form-select">
              ${wifiInterfaces.map(iface => `<option value="${iface}">${iface}</option>`).join('')}
            </select>
            <button type="button" class="btn btn-outline-secondary" id="refreshWifi" title="Refresh Networks">
              <i class="bi bi-arrow-clockwise"></i>
            </button>
          </div>
          <div class="form-text">Interface used for network discovery</div>
        </div>
        <div class="col-md-8">
          <label class="form-label">Target Network</label>
          <select id="wifiNetworks" class="form-select">
            <option value="">-- Scanning for networks... --</option>
          </select>
          <div class="form-text">Select from discovered networks or enter manually below</div>
          <div class="mt-2">
            <input type="text" name="ssid" id="ssid" class="form-control" placeholder="Or enter SSID manually">
            <div class="form-text">Manual entry for hidden networks or if scan fails</div>
          </div>
        </div>
      </div>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label">Backend</label>
          <select name="backend" id="backend" class="form-select">
            <option value="pywifi">PyWiFi (Recommended)</option>
            <option value="wpa_cli">WPA CLI</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Timeout (seconds)</label>
          <input type="number" name="timeout" id="timeout" class="form-control" value="4" min="1" max="30">
          <div class="form-text">Time to wait per password attempt</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Testing Interfaces</label>
          <select name="interfaces" id="interfaces" class="form-select" multiple required>
            ${wifiInterfaces.map(iface => `<option value="${iface}">${iface}</option>`).join('')}
          </select>
          <div class="form-text">Hold Ctrl/Cmd to select multiple</div>
        </div>
      </div>
    `;
    
    // Advanced Settings (collapsible)
    const advancedHtml = `
      <div class="card bg-light">
        <div class="card-header">
          <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex justify-content-between align-items-center" 
                  type="button" data-bs-toggle="collapse" data-bs-target="#wifiAdvanced">
            <span><i class="bi bi-sliders me-2"></i>Advanced Options</span>
            <i class="bi bi-chevron-down" id="wifiChevron"></i>
          </button>
        </div>
        <div class="collapse" id="wifiAdvanced">
          <div class="card-body">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="adaptive" id="adaptive" checked>
              <label class="form-check-label" for="adaptive">
                <strong>Adaptive Timing</strong>
                <div class="text-muted small">Automatically adjust timing based on network response</div>
              </label>
            </div>
          </div>
        </div>
      </div>
    `;
    
    parameterContainer.innerHTML = essentialHtml + advancedHtml;
    
    // Add chevron rotation
    const collapseEl = document.getElementById('wifiAdvanced');
    const chevron = document.getElementById('wifiChevron');
    collapseEl.addEventListener('show.bs.collapse', () => chevron.classList.replace('bi-chevron-down', 'bi-chevron-up'));
    collapseEl.addEventListener('hide.bs.collapse', () => chevron.classList.replace('bi-chevron-up', 'bi-chevron-down'));
    
    // Add WiFi scanning functionality
    setupWifiScanning();
  }
  
  function generateNmapForm(params) {
    configTitle.innerHTML = '<i class="bi bi-shield-check me-2"></i>Nmap Scan Configuration';
    
    // Essential Settings
    const essentialHtml = `
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label">Target IP/Host</label>
          <input type="text" name="target" id="target" class="form-control" required placeholder="192.168.1.1 or example.com">
          <div class="form-text">The IP address or hostname to scan</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Port Range</label>
          <input type="text" name="ports" id="ports" class="form-control" value="1-1000" required placeholder="1-1000, 80,443,22">
        </div>
        <div class="col-md-3">
          <label class="form-label">Timing</label>
          <select name="timing" id="timing" class="form-select">
            <option value="T0 (Paranoid)">T0 (Paranoid)</option>
            <option value="T1 (Sneaky)">T1 (Sneaky)</option>
            <option value="T2 (Polite)">T2 (Polite)</option>
            <option value="T3 (Normal)" selected>T3 (Normal)</option>
            <option value="T4 (Aggressive)">T4 (Aggressive)</option>
            <option value="T5 (Insane)">T5 (Insane)</option>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label">Scan Type</label>
          <select name="scan_type" id="scan_type" class="form-select">
            <option value="TCP Connect (-sT) [Recommended]" selected>TCP Connect (Recommended)</option>
            <option value="TCP SYN (-sS) [Requires Root]">TCP SYN (Requires Root)</option>
            <option value="UDP (-sU) [Requires Root]">UDP (Requires Root)</option>
            <option value="TCP SYN + UDP (-sS -sU) [Requires Root]">TCP SYN + UDP (Requires Root)</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Port Filter</label>
          <select name="port_filter" id="port_filter" class="form-select">
            <option value="All Ports">All Ports</option>
            <option value="Open Ports Only (--open)">Open Ports Only</option>
            <option value="Top 100 Ports (--top-ports 100)">Top 100 Ports</option>
            <option value="Top 1000 Ports (--top-ports 1000)">Top 1000 Ports</option>
          </select>
        </div>
      </div>
    `;
    
    // Advanced Settings (collapsible)
    const advancedHtml = `
      <div class="card bg-light">
        <div class="card-header">
          <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex justify-content-between align-items-center" 
                  type="button" data-bs-toggle="collapse" data-bs-target="#nmapAdvanced">
            <span><i class="bi bi-sliders me-2"></i>Advanced Options</span>
            <i class="bi bi-chevron-down" id="nmapChevron"></i>
          </button>
        </div>
        <div class="collapse" id="nmapAdvanced">
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <h6 class="text-muted mb-3">Detection Features</h6>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="os_detect" id="os_detect">
                  <label class="form-check-label" for="os_detect">
                    <strong>OS Detection</strong>
                    <div class="text-muted small">Identify target operating system</div>
                  </label>
                </div>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="service_detect" id="service_detect">
                  <label class="form-check-label" for="service_detect">
                    <strong>Service Detection</strong>
                    <div class="text-muted small">Detect service versions on open ports</div>
                  </label>
                </div>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="script_scan" id="script_scan">
                  <label class="form-check-label" for="script_scan">
                    <strong>Script Scan</strong>
                    <div class="text-muted small">Run default NSE scripts</div>
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="aggressive" id="aggressive">
                  <label class="form-check-label" for="aggressive">
                    <strong>Aggressive Scan</strong>
                    <div class="text-muted small">OS, version, scripts, and traceroute</div>
                  </label>
                </div>
              </div>
              <div class="col-md-6">
                <h6 class="text-muted mb-3">Additional Options</h6>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="show_reason" id="show_reason">
                  <label class="form-check-label" for="show_reason">
                    <strong>Show Port State Reason</strong>
                    <div class="text-muted small">Display why ports are in their state</div>
                  </label>
                </div>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="traceroute" id="traceroute">
                  <label class="form-check-label" for="traceroute">
                    <strong>Traceroute</strong>
                    <div class="text-muted small">Trace path to target</div>
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="fragment" id="fragment">
                  <label class="form-check-label" for="fragment">
                    <strong>Fragment Packets</strong>
                    <div class="text-muted small">May help evade firewalls</div>
                  </label>
                </div>
              </div>
            </div>
            <hr>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Host Timeout (seconds)</label>
                <input type="number" name="timeouts" id="timeouts" class="form-control" value="30" min="1" max="300">
              </div>
              <div class="col-md-4">
                <label class="form-label">Max Retries</label>
                <input type="number" name="max_retries" id="max_retries" class="form-control" value="3" min="0" max="10">
              </div>
              <div class="col-md-4">
                <label class="form-label">Source Port (optional)</label>
                <input type="number" name="source_port" id="source_port" class="form-control" min="1" max="65535" placeholder="Auto">
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
    
    parameterContainer.innerHTML = essentialHtml + advancedHtml;
    
    // Add chevron rotation
    const collapseEl = document.getElementById('nmapAdvanced');
    const chevron = document.getElementById('nmapChevron');
    collapseEl.addEventListener('show.bs.collapse', () => chevron.classList.replace('bi-chevron-down', 'bi-chevron-up'));
    collapseEl.addEventListener('hide.bs.collapse', () => chevron.classList.replace('bi-chevron-up', 'bi-chevron-down'));
  }
  
  function generateGenericForm(params) {
    configTitle.innerHTML = '<i class="bi bi-gear me-2"></i>Script Configuration';
    
    let html = '<div class="row g-3">';
    
    Object.entries(params).forEach(([paramName, paramDef]) => {
      const colClass = getColumnClass(paramDef.type);
      
      html += `<div class="${colClass}">`;
      html += `<label class="form-label">${paramDef.label || paramName}</label>`;
      
      switch (paramDef.type) {
        case 'text':
          html += `<input type="text" name="${paramName}" id="${paramName}" class="form-control" ${paramDef.required ? 'required' : ''}>`;
          break;
        case 'number':
          html += `<input type="number" name="${paramName}" id="${paramName}" class="form-control" value="${paramDef.default || ''}" ${paramDef.min !== undefined ? `min="${paramDef.min}"` : ''} ${paramDef.max !== undefined ? `max="${paramDef.max}"` : ''}>`;
          break;
        case 'select':
          html += `<select name="${paramName}" id="${paramName}" class="form-select">`;
          (paramDef.options || []).forEach(option => {
            html += `<option value="${option}" ${option === paramDef.default ? 'selected' : ''}>${option}</option>`;
          });
          html += `</select>`;
          break;
        case 'checkbox':
          html += `<div class="form-check">`;
          html += `<input class="form-check-input" type="checkbox" name="${paramName}" id="${paramName}" ${paramDef.default === true ? 'checked' : ''}>`;
          html += `<label class="form-check-label" for="${paramName}">${paramDef.label || paramName}</label>`;
          html += `</div>`;
          break;
      }
      
      html += `</div>`;
    });
    
    html += '</div>';
    parameterContainer.innerHTML = html;
  }
  
  function getColumnClass(type) {
    switch (type) {
      case 'checkbox': return 'col-12 col-md-4';
      case 'number': return 'col-6 col-md-3';
      default: return 'col-12 col-md-6';
    }
  }

  // Handle script selection
  scriptSelect.addEventListener('change', (e) => {
    const scriptId = e.target.value;
    
    if (!scriptId) {
      runForm.style.display = 'none';
      consoleCard.style.display = 'none';
      scriptDescription.textContent = '';
      return;
    }
    
    const script = scripts[scriptId];
    scriptDescription.textContent = script.description || '';
    generateParameterForm(scriptId);
    runForm.style.display = 'block';
  });

  // Handle stop button
  stopBtn.addEventListener('click', () => {
    if (abortController) {
      abortController.abort();
      appendOut('\n[!] Aborted by user.\n');
      stopBtn.disabled = true;
      runBtn.disabled = false;
    }
  });

  // Handle form submission with EventSource
  runForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    console.log('Form submitted!');
    
    if (abortController) {
      console.log('Already running, aborting');
      return;
    }
    
    const scriptId = scriptSelect.value;
    console.log('Script ID:', scriptId);
    if (!scriptId) return;
    
    // Handle command_only mode (WiFi script)
    if (scripts[scriptId].mode === 'command_only') {
      handleCommandOnlyMode(scriptId);
      return;
    }
    
    // Validate interfaces for WiFi test (legacy - shouldn't reach here anymore)
    if (scriptId === 'wifi_password_test') {
      const ifSel = document.querySelector('[name="interfaces"]');
      console.log('Interface selector:', ifSel);
      const chosen = Array.from(ifSel.options).filter(o => o.selected);
      console.log('Chosen interfaces:', chosen);
      if (!chosen.length) {
        alert('Pick at least one interface');
        return;
      }
    }
    
    console.log('Setting up console...');
    consoleCard.style.display = 'block';
    consoleEl.textContent = '[DEBUG] Starting script execution...\n';
    runBtn.disabled = true;
    stopBtn.disabled = false;
    
    // Get form values
    const formData = new FormData(runForm);
    console.log('Form data:', Array.from(formData.entries()));
    
    let url;
    
    // Route to appropriate stream endpoint based on script type
    if (scriptId === 'nmap_scan') {
      // Nmap script - use nmap-stream.php in api directory
      url = new URL('/api/nmap-stream.php', window.location.origin);
      
      const target = formData.get('target') || '';
      const ports = formData.get('ports') || '1-1000';
      const scan_type = formData.get('scan_type') || 'TCP SYN (-sS)';
      const timing = formData.get('timing') || 'T3 (Normal)';
      const os_detect = document.querySelector('[name="os_detect"]').checked ? '1' : '0';
      const service_detect = document.querySelector('[name="service_detect"]').checked ? '1' : '0';
      
      console.log('Nmap Parameters:', {target, ports, scan_type, timing, os_detect, service_detect});
      
      // Add parameters for nmap-stream.php
      url.searchParams.set('target', target);
      url.searchParams.set('ports', ports);
      url.searchParams.set('scan_type', scan_type);
      url.searchParams.set('timing', timing);
      url.searchParams.set('os_detect', os_detect);
      url.searchParams.set('service_detect', service_detect);
      
    } else if (scriptId === 'check_egress') {
      // Egress check script - use egress-stream.php in api directory
      url = new URL('/api/egress-stream.php', window.location.origin);
      console.log('Egress Check: No parameters needed');
      
    } else {
      appendOut('\n[!] Unknown script type: ' + scriptId + '\n');
      runBtn.disabled = false;
      stopBtn.disabled = true;
      return;
    }
    
    console.log('Final URL:', url.toString());
    appendOut('[DEBUG] Connecting to: ' + url.toString() + '\n');
    
    try {
      // Create EventSource for Server-Sent Events
      console.log('Creating EventSource...');
      const eventSource = new EventSource(url.toString());
      abortController = new AbortController();
      
      eventSource.onopen = function(event) {
        console.log('EventSource opened:', event);
        appendOut('[DEBUG] Connection opened\n');
      };
      
      eventSource.onmessage = function(event) {
        console.log('EventSource message:', event.data);
        if (event.data && event.data !== 'finished') {
          appendOut(event.data + '\n');
        }
      };
      
      eventSource.addEventListener('done', function(event) {
        console.log('EventSource done event:', event);
        appendOut('\n[✓] Script completed\n');
        eventSource.close();
        abortController = null;
        runBtn.disabled = false;
        stopBtn.disabled = true;
      });
      
      eventSource.onerror = function(event) {
        console.log('EventSource error:', event);
        appendOut('\n[!] Connection error or script finished\n');
        eventSource.close();
        abortController = null;
        runBtn.disabled = false;
        stopBtn.disabled = true;
      };
      
      // Handle abort
      abortController.signal.addEventListener('abort', () => {
        console.log('Aborting EventSource');
        eventSource.close();
        appendOut('\n[!] Aborted by user\n');
      });
      
    } catch (error) {
      console.error('EventSource error:', error);
      appendOut(`\n[!] Error: ${error.message}\n`);
      abortController = null;
      runBtn.disabled = false;
      stopBtn.disabled = true;
    }
  });
  
  // Handle command_only mode (for WiFi script)
  function handleCommandOnlyMode(scriptId) {
    console.log('Handling command-only mode for:', scriptId);
    
    const formData = new FormData(runForm);
    
    if (scriptId === 'wifi_password_test') {
      // Validate interfaces
      const ifSel = document.querySelector('[name="interfaces"]');
      const chosen = Array.from(ifSel.selectedOptions).map(o => o.value);
      if (!chosen.length) {
        alert('Pick at least one interface');
        return;
      }
      
      // Build WiFi command
      const ssid = formData.get('ssid') || '';
      const timeout = formData.get('timeout') || '4';
      const backend = formData.get('backend') || 'pywifi';
      const adaptive = document.querySelector('[name="adaptive"]').checked;
      
      let cmd = `sudo python3 -u /var/www/scripts/wifi-password-test.py`;
      chosen.forEach(iface => cmd += ` -i ${iface}`);
      cmd += ` -s "${ssid}"`;
      cmd += ` -w /var/www/passwords.txt`;
      cmd += ` --timeout ${timeout}`;
      cmd += ` --backend ${backend}`;
      if (!adaptive) cmd += ` --no-adaptive`;
      
      // Display command in console with consistent styling
      consoleCard.style.display = 'block';
      consoleEl.innerHTML = `<div style="background: rgba(13, 110, 253, 0.1); padding: 15px; border-radius: 5px; border-left: 4px solid #0d6efd; margin-bottom: 10px;">
<div style="color: #0d6efd; margin-bottom: 10px; font-weight: bold;"><i class="bi bi-info-circle"></i> WiFi Command Generation</div>
<div style="color: #6c757d; margin-bottom: 15px; line-height: 1.4;">
Due to system limitations, WiFi scripts cannot be run directly through the web interface.<br>
Copy and paste the command below into a terminal with root access:
</div>
<div style="background: #000; padding: 10px; border-radius: 3px; font-family: 'Consolas', 'Monaco', 'Courier New', monospace; color: #00ff00; border: 1px solid #333; margin: 10px 0; overflow-x: auto;">
${cmd}
</div>
<div style="color: #6c757d; font-size: 0.9em; margin-top: 10px;">
<strong>Instructions:</strong><br>
1. Open a terminal as root (or use sudo)<br>
2. Copy the command above<br>
3. Paste and run it in the terminal<br>
4. Monitor the real-time output for password testing results
</div>
</div>`;
    }
  }
  
  // WiFi scanning functionality
  function setupWifiScanning() {
    const wifiNetworks = document.getElementById('wifiNetworks');
    const refreshBtn = document.getElementById('refreshWifi');
    const ssidInput = document.getElementById('ssid');
    const scanInterface = document.getElementById('scanInterface');
    
    // Load networks initially with first interface
    scanWifiNetworks(false);
    
    // Handle scanning interface change
    scanInterface.addEventListener('change', () => {
      wifiNetworks.innerHTML = '<option value="">-- Scanning with new interface... --</option>';
      scanWifiNetworks(false); // Auto-scan when interface changes
    });
    
    // Handle refresh button
    refreshBtn.addEventListener('click', () => {
      refreshBtn.disabled = true;
      refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
      scanWifiNetworks(true); // Force rescan when manually refreshing
    });
    
    // Handle network selection
    wifiNetworks.addEventListener('change', (e) => {
      if (e.target.value) {
        ssidInput.value = e.target.value;
        ssidInput.setAttribute('readonly', true);
        ssidInput.classList.add('bg-light');
      } else {
        ssidInput.value = '';
        ssidInput.removeAttribute('readonly');
        ssidInput.classList.remove('bg-light');
      }
    });
    
    // Handle manual SSID input
    ssidInput.addEventListener('input', (e) => {
      if (e.target.value) {
        wifiNetworks.value = '';
      }
    });
  }
  
  async function scanWifiNetworks(forceRescan = false) {
    const wifiNetworks = document.getElementById('wifiNetworks');
    const refreshBtn = document.getElementById('refreshWifi');
    const scanInterface = document.getElementById('scanInterface');
    
    try {
      const selectedInterface = scanInterface.value;
      
      const formData = new FormData();
      formData.append('action', 'scan_wifi');
      formData.append('csrf', csrf);
      formData.append('iface', selectedInterface);
      formData.append('rescan', forceRescan ? 'yes' : 'no');
      
      const response = await fetch('?module=scripts', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.error) {
        throw new Error(result.error);
      }
      
      // Clear and populate networks dropdown
      wifiNetworks.innerHTML = '<option value="">-- Select discovered network --</option>';
      
      if (result.networks && result.networks.length > 0) {
        result.networks.forEach(network => {
          const option = document.createElement('option');
          option.value = network.ssid;
          
          // Create descriptive text with signal strength and security
          const signalBars = getSignalBars(network.signal);
          const securityText = network.security === 'OPEN' ? 'Open' : network.security;
          
          option.textContent = `${network.ssid} (${signalBars} ${network.signal}dBm, ${securityText}, ${network.band})`;
          
          // Add visual styling based on signal strength
          if (network.signal > -50) {
            option.style.color = '#28a745'; // Strong signal - green
          } else if (network.signal > -70) {
            option.style.color = '#ffc107'; // Medium signal - yellow
          } else {
            option.style.color = '#dc3545'; // Weak signal - red
          }
          
          wifiNetworks.appendChild(option);
        });
        
        // Add interface info to help text
        const interfaceNote = document.createElement('option');
        interfaceNote.value = '';
        interfaceNote.textContent = `--- Scanned with ${selectedInterface} (${result.networks.length} networks found) ---`;
        interfaceNote.disabled = true;
        interfaceNote.style.fontStyle = 'italic';
        interfaceNote.style.color = '#6c757d';
        wifiNetworks.appendChild(interfaceNote);
        
      } else {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = `-- No networks found on ${selectedInterface} --`;
        option.disabled = true;
        wifiNetworks.appendChild(option);
      }
      
    } catch (error) {
      console.error('WiFi scan error:', error);
      wifiNetworks.innerHTML = '<option value="">-- Scan failed --</option>';
      
      // Show error in a user-friendly way
      const errorOption = document.createElement('option');
      errorOption.value = '';
      errorOption.textContent = `Error: ${error.message}`;
      errorOption.disabled = true;
      errorOption.style.color = '#dc3545';
      wifiNetworks.appendChild(errorOption);
    } finally {
      // Reset refresh button
      if (refreshBtn) {
        refreshBtn.disabled = false;
        refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
      }
    }
  }
  
  function getSignalBars(signal) {
    if (signal > -50) return '█████'; // Excellent
    if (signal > -60) return '████▁'; // Good  
    if (signal > -70) return '███▁▁'; // Fair
    if (signal > -80) return '██▁▁▁'; // Weak
    return '█▁▁▁▁'; // Very weak
  }
})();
</script> 