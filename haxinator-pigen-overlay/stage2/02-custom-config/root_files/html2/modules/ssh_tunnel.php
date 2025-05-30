<?php
/**
 * ssh_tunnel.php – Simple SSH Proxy/Tunnel Manager module
 * -------------------------------------------------------
 * Drop this file in your modules directory and include it from your
 * dashboard with:  ?module=ssh_tunnel
 *
 * ✔ PHP 8.x, Debian 12, Apache2 + libapache2-mod-php
 * ✔ Requires php-ssh2, openssh-client (ssh), and optional sshpass/autossh
 * ✔ Runs as www-data; assumes sudo-less access to the ssh binary (already
 *   whitelisted in sudoers per project requirements).
 *
 * Quick Demo Scenario (see README at bottom of file for details)
 * ---------------------------------------------------------------------
 * 1. Upload a private key via the "Keys" tab.
 * 2. Click "New Tunnel", pick the uploaded key, enter remote host details
 *    and choose Local (-L) or Remote (-R) forwarding.
 * 3. Start the tunnel.  The PID is tracked in data/tunnels.json and shown
 *    live in the UI.  Stop it any time.
 * ---------------------------------------------------------------------
 * SECURITY NOTES
 *   • Uploaded keys are stored at /var/www/.ssh (0700) with mode 0600.
 *   • All shell arguments are escaped with escapeshellarg().
 *   • No passwords/pass-phrases are persisted – they are used per request
 *     and discarded.
 * ---------------------------------------------------------------------
 * (c) 2025  MoreHax – MIT License
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Module meta (picked up by the parent framework)
// ─────────────────────────────────────────────────────────────────────────────
$module = [
    'id'          => 'ssh_tunnel',
    'title'       => 'SSH',
    'icon'        => 'key',
    'description' => 'Create and manage SSH tunnels / proxies',
    'category'    => 'network'
];

if (!defined('EMBEDDED_MODULE') && !defined('MODULE_POST_HANDLER')) {
    return; // metadata discovery mode
}

// ─────────────────────────────────────────────────────────────────────────────
//  Basic environment checks
// ─────────────────────────────────────────────────────────────────────────────
if (!extension_loaded('ssh2')) {
    echo '<div class="alert alert-danger">php_ssh2 extension missing – install with <code>apt install php-ssh2</code></div>';
    return;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Paths & helper functions
// ─────────────────────────────────────────────────────────────────────────────
$dataDir   = __DIR__ . '/../data';           // persistent data (JSON, logs)
$sshDir    = '/var/www/.ssh';                // key storage – chmod 0700
$logFile   = $dataDir . '/ssh_tunnel.log';
$tunReg    = $dataDir . '/tunnels.json';

@mkdir($dataDir, 0750, true);
@mkdir($sshDir, 0700, true);

if (!function_exists('log_msg')) {
    function log_msg(string $msg): void {
        global $logFile;
        error_log(date('c') . " SSH-TUNNEL « " . $msg . "\n", 3, $logFile);
    }
}

/**
 * Load tunnel registry (PID → meta array)
 */
function loadRegistry(): array {
    global $tunReg;
    if (!is_file($tunReg)) return [];
    return json_decode(file_get_contents($tunReg), true) ?: [];
}

/**
 * Save tunnel registry
 */
function saveRegistry(array $reg): void {
    global $tunReg;
    file_put_contents($tunReg, json_encode($reg, JSON_PRETTY_PRINT));
}

/**
 * Test if a PID is still alive (and owned by www-data)
 */
function pidAlive(int $pid): bool {
    return posix_kill($pid, 0);
}

/**
 * Enumerate private keys in $sshDir (basename only)
 */
function listKeys(): array {
    global $sshDir;
    $files = array_values(array_filter(scandir($sshDir), static function ($f) use ($sshDir) {
        $fullPath = "$sshDir/$f";
        // Skip . and .. directories
        if ($f === '.' || $f === '..') return false;
        // Must be a regular file
        if (!is_file($fullPath)) return false;
        // Skip SSH system files
        if (in_array($f, ['known_hosts', 'config', 'authorized_keys'], true)) return false;
        // Include files with common SSH key extensions OR files that don't end with .pub (but exclude system files)
        return preg_match('/\.(pem|key|ppk)$/i', $f) || (!str_ends_with($f, '.pub') && !preg_match('/^(known_hosts|config|authorized_keys)/i', $f));
    }));
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX handler – returns JSON
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $csrf = $_SESSION['csrf'] ?? '';
    if (($_POST['csrf'] ?? '') !== $csrf) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $action  = $_POST['action'];
    $payload = [];
    $reg     = loadRegistry(); // current tunnel list

    switch ($action) {
        // ─────────────── KEY MANAGEMENT ───────────────
        case 'list_keys':
            $payload = ['keys' => listKeys()];
            break;

        case 'upload_key':
            if (!isset($_FILES['keyfile'])) {
                $payload = ['error' => 'No file'];
                break;
            }
            $file = $_FILES['keyfile'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $payload = ['error' => 'Upload error'];
                break;
            }
            $dest = $sshDir . '/' . basename($file['name']);
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                chmod($dest, 0600);
                $payload = ['success' => true, 'file' => basename($dest)];
                log_msg("Key uploaded: $dest");
            } else {
                $payload = ['error' => 'Move failed'];
            }
            break;

        case 'delete_key':
            $fname = basename($_POST['file'] ?? '');
            if (!$fname || !is_file($sshDir . '/' . $fname)) {
                $payload = ['error' => 'File not found'];
                break;
            }
            unlink($sshDir . '/' . $fname);
            $payload = ['success' => true];
            log_msg("Key deleted: $fname");
            break;

        case 'download_public_key':
            $keyName = basename($_POST['key'] ?? '');
            if (!$keyName) {
                $payload = ['error' => 'Key name required'];
                break;
            }
            
            $publicKeyPath = $sshDir . '/' . $keyName . '.pub';
            if (!is_file($publicKeyPath)) {
                $payload = ['error' => 'Public key file not found'];
                break;
            }
            
            $publicKeyContent = file_get_contents($publicKeyPath);
            if ($publicKeyContent === false) {
                $payload = ['error' => 'Failed to read public key'];
                break;
            }
            
            // Return content for download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $keyName . '.pub"');
            header('Content-Length: ' . strlen($publicKeyContent));
            echo $publicKeyContent;
            exit;

        case 'generate_key':
            $keyName = trim($_POST['key_name'] ?? '');
            $keyType = $_POST['key_type'] ?? 'rsa';
            $keyBits = (int)($_POST['key_bits'] ?? 2048);
            
            // Validate inputs
            if (!$keyName || !preg_match('/^[a-zA-Z0-9_-]+$/', $keyName)) {
                $payload = ['error' => 'Invalid key name. Use only letters, numbers, underscore, and dash.'];
                break;
            }
            if (!in_array($keyType, ['rsa', 'ed25519', 'ecdsa'], true)) {
                $payload = ['error' => 'Invalid key type'];
                break;
            }
            if ($keyType === 'rsa' && ($keyBits < 2048 || $keyBits > 4096)) {
                $payload = ['error' => 'RSA key bits must be between 2048 and 4096'];
                break;
            }
            
            $privateKeyPath = $sshDir . '/' . $keyName;
            $publicKeyPath = $privateKeyPath . '.pub';
            
            // Check if key already exists
            if (is_file($privateKeyPath) || is_file($publicKeyPath)) {
                $payload = ['error' => 'Key with this name already exists'];
                break;
            }
            
            // Build ssh-keygen command
            $cmd = ['ssh-keygen', '-t', $keyType];
            if ($keyType === 'rsa') {
                $cmd[] = '-b';
                $cmd[] = $keyBits;
            }
            $cmd[] = '-f';
            $cmd[] = $privateKeyPath;
            $cmd[] = '-N';
            $cmd[] = ''; // No passphrase
            $cmd[] = '-C';
            $cmd[] = "www-data@" . gethostname() . " " . date('Y-m-d');
            
            // Execute ssh-keygen
            $fullCmd = '';
            foreach ($cmd as $part) $fullCmd .= escapeshellarg($part) . ' ';
            $fullCmd .= '2>&1';
            
            $output = [];
            $exitCode = 0;
            exec($fullCmd, $output, $exitCode);
            
            if ($exitCode !== 0 || !is_file($privateKeyPath) || !is_file($publicKeyPath)) {
                $payload = ['error' => 'Failed to generate key. Exit code: ' . $exitCode . '. Output: ' . implode("\n", $output)];
                // Clean up any partial files
                @unlink($privateKeyPath);
                @unlink($publicKeyPath);
                break;
            }
            
            // Set proper permissions
            chmod($privateKeyPath, 0600);
            chmod($publicKeyPath, 0644);
            
            // Read public key content for display
            $publicKeyContent = trim(file_get_contents($publicKeyPath));
            
            $payload = [
                'success' => true,
                'private_key' => basename($privateKeyPath),
                'public_key' => basename($publicKeyPath),
                'public_key_content' => $publicKeyContent
            ];
            log_msg("Key pair generated: $keyName ($keyType)");
            break;

        // ─────────────── TUNNEL MANAGEMENT ───────────────
        case 'list_tunnels':
            // Update tunnel status based on actual process state
            foreach ($reg as $id => $meta) {
                $pid = $meta['pid'] ?? 0;
                if ($pid && pidAlive($pid)) {
                    // Process is running - ensure status is correct
                    $reg[$id]['status'] = 'running';
                } else {
                    // Process is dead - mark as stopped but keep config
                    $reg[$id]['status'] = 'stopped';
                    $reg[$id]['pid'] = null;
                }
                // Ensure ID is included in response
                $reg[$id]['id'] = $id;
            }
            saveRegistry($reg);
            $payload = ['tunnels' => array_values($reg)];
            break;

        case 'stop_tunnel':
            $id = $_POST['id'] ?? '';
            if (!$id || !isset($reg[$id])) {
                $payload = ['error' => 'Unknown tunnel'];
                break;
            }
            $pid = $reg[$id]['pid'] ?? 0;
            if ($pid) {
                exec('kill ' . escapeshellarg($pid));
            }
            // Keep tunnel config but mark as stopped
            $reg[$id]['status'] = 'stopped';
            $reg[$id]['pid'] = null;
            saveRegistry($reg);
            $payload = ['success' => true];
            log_msg("Tunnel stopped – ID $id (PID $pid)");
            break;

        case 'start_tunnel':
            $id = $_POST['id'] ?? '';
            if (!$id || !isset($reg[$id])) {
                $payload = ['error' => 'Unknown tunnel'];
                break;
            }
            
            $tunnel = $reg[$id];
            if (($tunnel['status'] ?? '') === 'running') {
                $payload = ['error' => 'Tunnel already running'];
                break;
            }
            
            // Extract tunnel configuration
            $host = $tunnel['host'];
            $port = 22; // Default SSH port
            $user = $tunnel['user'];
            $authType = $tunnel['auth'] ?? 'key';
            $keyFile = $tunnel['key'] ?? '';
            $fwdType = $tunnel['fwd'];
            $lport = $tunnel['lport'];
            $rhost = $tunnel['rhost'] ?? '127.0.0.1';
            $rport = $tunnel['rport'] ?? 0;
            
            // Build SSH command (same logic as create_tunnel)
            $flags = $fwdType === 'L'
                ? "-L 0.0.0.0:{$lport}:{$rhost}:{$rport}"
                : ($fwdType === 'R'
                    ? "-R 0.0.0.0:{$rport}:{$rhost}:{$lport}"
                    : "-D 0.0.0.0:{$lport}");

            $sshCmd = ['ssh', '-N', '-T', '-o', 'ExitOnForwardFailure=yes', '-o', 'StrictHostKeyChecking=no'];
            if ($authType === 'key') {
                $path = $sshDir . '/' . basename($keyFile);
                if (!is_file($path)) {
                    $payload = ['error' => 'Key not found'];
                    break;
                }
                $sshCmd[] = '-i';
                $sshCmd[] = $path;
            }

            $sshCmd[] = '-p';
            $sshCmd[] = $port;
            $sshCmd[] = $flags;
            $sshCmd[] = "$user@$host";

            // Background the command & capture PID
            $full = '';
            foreach ($sshCmd as $part) $full .= escapeshellarg($part) . ' ';
            $full .= ' > /dev/null 2>&1 & echo $!';
            $pid  = (int)trim(shell_exec($full));
            if (!$pid) {
                $payload = ['error' => 'Failed to spawn ssh'];
                break;
            }

            // Update tunnel with new PID and running status
            $reg[$id]['pid'] = $pid;
            $reg[$id]['status'] = 'running';
            $reg[$id]['since'] = time();
            saveRegistry($reg);
            log_msg("Tunnel started – ID $id PID $pid → $user@$host ($fwdType)");
            $payload = ['success' => true, 'pid' => $pid];
            break;

        case 'delete_tunnel':
            $id = $_POST['id'] ?? '';
            if (!$id || !isset($reg[$id])) {
                $payload = ['error' => 'Unknown tunnel'];
                break;
            }
            
            // Stop process if running
            $pid = $reg[$id]['pid'] ?? 0;
            if ($pid) {
                exec('kill ' . escapeshellarg($pid));
            }
            
            // Remove from registry completely
            unset($reg[$id]);
            saveRegistry($reg);
            $payload = ['success' => true];
            log_msg("Tunnel deleted – ID $id (PID $pid)");
            break;

        case 'create_tunnel':
            // required: host, user, lport/rport, fwd_type (L|R), etc.
            $host      = $_POST['host']      ?? '';
            $port      = (int)($_POST['port'] ?? 22);
            $user      = $_POST['user']      ?? 'root';
            $authType  = $_POST['auth']      ?? 'key'; // key|password
            $keyFile   = $_POST['key']       ?? '';
            $password  = $_POST['password']  ?? '';
            $fwdType   = $_POST['fwd']       ?? 'L';    // L or R
            $lport     = (int)($_POST['lport'] ?? 0);
            $rhost     = $_POST['rhost']     ?? '127.0.0.1';
            $rport     = (int)($_POST['rport'] ?? 0);

            if (!$host || !$lport || (!$rport && $fwdType !== 'D') || !in_array($fwdType, ['L', 'R', 'D'], true)) {
                $payload = ['error' => 'Missing / invalid params'];
                break;
            }

            // Validate inputs for security
            if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && 
                !filter_var($host, FILTER_VALIDATE_IP)) {
                $payload = ['error' => 'Invalid remote host'];
                break;
            }
            
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $user)) {
                $payload = ['error' => 'Invalid SSH user'];
                break;
            }
            
            if ($fwdType !== 'D' && (!filter_var($rhost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && 
                !filter_var($rhost, FILTER_VALIDATE_IP))) {
                $payload = ['error' => 'Invalid target host'];
                break;
            }

            // Build SSH command
            $flags = $fwdType === 'L'
                ? "-L 0.0.0.0:{$lport}:{$rhost}:{$rport}"
                : ($fwdType === 'R'
                    ? "-R 0.0.0.0:{$rport}:{$rhost}:{$lport}"
                    : "-D 0.0.0.0:{$lport}");

            $sshCmd = ['ssh', '-N', '-T', '-o', 'ExitOnForwardFailure=yes', '-o', 'StrictHostKeyChecking=no'];
            if ($authType === 'key') {
                $path = $sshDir . '/' . basename($keyFile);
                if (!is_file($path)) {
                    $payload = ['error' => 'Key not found'];
                    break;
                }
                $sshCmd[] = '-i';
                $sshCmd[] = $path;
            } elseif ($authType === 'password') {
                // Needs sshpass; fall back to php_ssh2 test only
                if (!trim($password)) {
                    $payload = ['error' => 'Password required'];
                    break;
                }
                $sshCmd = ['sshpass', '-p', $password, 'ssh', '-o', 'StrictHostKeyChecking=no', '-N', '-T', '-o', 'ExitOnForwardFailure=yes'];
            }

            $sshCmd[] = '-p';
            $sshCmd[] = $port;
            $sshCmd[] = $flags;
            $sshCmd[] = "$user@$host";

            // Background the command & capture PID
            $full = '';
            foreach ($sshCmd as $part) $full .= escapeshellarg($part) . ' ';
            $full .= ' > /dev/null 2>&1 & echo $!';
            $pid  = (int)trim(shell_exec($full));
            if (!$pid) {
                $payload = ['error' => 'Failed to spawn ssh'];
                break;
            }

            // Generate unique tunnel ID and record
            $tunnelId = uniqid();
            $reg[$tunnelId] = [
                'pid'    => $pid,
                'status' => 'running',
                'user'   => $user,
                'host'   => $host,
                'fwd'    => $fwdType,
                'lport'  => $lport,
                'rhost'  => $rhost,
                'rport'  => $rport,
                'since'  => time(),
                'auth'   => $authType,
                'key'    => $authType === 'key' ? basename($keyFile) : null
            ];
            saveRegistry($reg);
            log_msg("Tunnel created – ID $tunnelId PID $pid → $user@$host ($fwdType)");
            $payload = ['success' => true, 'pid' => $pid, 'id' => $tunnelId];
            break;

        default:
            $payload = ['error' => 'Unknown action'];
    }

    echo json_encode($payload);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
//  CSRF token (session started earlier by parent app)
// ─────────────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

// ─────────────────────────────────────────────────────────────────────────────
//  FRONT-END  – minimal Bootstrap 5 markup
// ─────────────────────────────────────────────────────────────────────────────
?>

<h3 class="mb-3">SSH Management</h3>

<ul class="nav nav-tabs" id="sshTab" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="tun-tab" data-bs-toggle="tab" data-bs-target="#tunPanel" type="button" role="tab">Tunnels</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="key-tab" data-bs-toggle="tab" data-bs-target="#keyPanel" type="button" role="tab">Keys</button>
  </li>
</ul>

<div class="tab-content py-3">
  <!-- ─────────── Tunnels panel ─────────── -->
  <div class="tab-pane fade show active" id="tunPanel" role="tabpanel">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Active Tunnels</h5>
        <button class="btn btn-success btn-sm" onclick="showNewTunnel()"><i class="bi bi-plus-circle"></i> New Tunnel</button>
      </div>
      <div class="card-body" id="tunList"><p class="text-muted">Loading…</p></div>
    </div>
  </div>

  <!-- ─────────── Keys panel ─────────── -->
  <div class="tab-pane fade" id="keyPanel" role="tabpanel">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Private Keys</h5>
        <div class="btn-group">
          <button class="btn btn-success btn-sm" onclick="showGenerateKey()">
            <i class="bi bi-key"></i> Generate
          </button>
          <label class="btn btn-primary btn-sm mb-0">
            <i class="bi bi-upload"></i> Upload <input type="file" hidden onchange="uploadKey(this.files[0])">
          </label>
        </div>
      </div>
      <div class="card-body" id="keyList"><p class="text-muted">Loading…</p></div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="cp-toast-container" id="toastArea"></div>

<!-- New Tunnel modal -->
<div class="modal fade cp-modal-responsive" id="tunModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Create Tunnel</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="tunForm" class="vstack gap-2">
          <div class="form-floating"><input required name="host"  class="form-control" placeholder="host"><label>Remote Host</label></div>
          <div class="form-floating"><input required name="user"  class="form-control" placeholder="user" value="root"><label>SSH User</label></div>
          <div class="form-floating"><input required name="port"  class="form-control" type="number" min="1" max="65535" value="22"><label>SSH Port</label></div>
          <div class="form-floating"><select name="auth" class="form-select" onchange="switchAuth(this.value)"><option value="key">Key</option><option value="password">Password</option></select><label>Auth Type</label></div>
          <div id="authKeyGrp" class="form-floating"><select name="key" class="form-select"></select><label>Key File</label></div>
          <div id="authPwdGrp" class="form-floating d-none"><input name="password" type="password" class="form-control" placeholder="password"><label>Password</label></div>

          <div class="form-floating"><select name="fwd" class="form-select" onchange="toggleTargetFields(this.value)"><option value="L">Local (-L)</option><option value="R">Remote (-R)</option><option value="D">SOCKS (-D)</option></select><label>Forward Type</label></div>

          <div class="form-floating"><input required name="lport" class="form-control" type="number" min="1" max="65535"><label>Local Port</label></div>
          <div class="form-floating" id="targetHostGrp"><input required name="rhost" class="form-control" value="127.0.0.1"><label>Target Host</label></div>
          <div class="form-floating" id="targetPortGrp"><input required name="rport" class="form-control" type="number" min="1" max="65535"><label>Target Port</label></div>
        </form>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" onclick="submitTunnel()">Create</button></div>
    </div>
  </div>
</div>

<!-- Generate Key modal -->
<div class="modal fade cp-modal-responsive" id="genKeyModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Generate SSH Key Pair</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="genKeyForm" class="vstack gap-3">
          <div class="form-floating">
            <input required name="key_name" class="form-control" placeholder="Key name" pattern="[a-zA-Z0-9_-]+">
            <label>Key Name</label>
            <div class="form-text">Only letters, numbers, underscore, and dash allowed</div>
          </div>
          <div class="form-floating">
            <select name="key_type" class="form-select" onchange="toggleKeyBits(this.value)">
              <option value="rsa">RSA</option>
              <option value="ed25519">Ed25519</option>
              <option value="ecdsa">ECDSA</option>
            </select>
            <label>Key Type</label>
          </div>
          <div id="keyBitsGroup" class="form-floating">
            <select name="key_bits" class="form-select">
              <option value="2048">2048 bits</option>
              <option value="3072">3072 bits</option>
              <option value="4096">4096 bits</option>
            </select>
            <label>Key Size</label>
          </div>
          <div class="alert alert-info mb-0">
            <small><i class="bi bi-info-circle"></i> Keys will be generated without a passphrase and stored securely with proper permissions.</small>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" onclick="submitKeyGeneration()">
          <i class="bi bi-key"></i> Generate Key
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Public Key Display modal -->
<div class="modal fade cp-modal-responsive" id="pubKeyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Key Generated Successfully</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-success">
          <i class="bi bi-check-circle"></i> SSH key pair generated successfully!
        </div>
        <p><strong>Private Key:</strong> <code id="privKeyName"></code></p>
        <p><strong>Public Key:</strong> <code id="pubKeyName"></code></p>
        <div class="mb-3">
          <label class="form-label"><strong>Public Key Content (copy this to remote servers):</strong></label>
          <textarea id="pubKeyContent" class="form-control font-monospace" rows="3" readonly></textarea>
          <button class="btn btn-sm btn-outline-primary mt-2" onclick="copyPublicKey()">
            <i class="bi bi-clipboard"></i> Copy Public Key
          </button>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" data-bs-dismiss="modal">Done</button>
      </div>
    </div>
  </div>
</div>

<script>
const csrf = '<?= htmlspecialchars($csrf) ?>';

async function api(act, body = {}) {
  const fd = new FormData();
  fd.append('action', act);
  fd.append('csrf', csrf);
  for (const k in body) fd.append(k, body[k]);
  const r = await fetch('?module=ssh_tunnel', {method: 'POST', body: fd});
  return r.json();
}
// ───────── KEYS ─────────
async function loadKeys() {
  const res = await api('list_keys');
  const div = document.getElementById('keyList');
  if (res.error) { div.innerHTML = '<p class="text-danger">'+res.error+'</p>'; return; }
  if (!res.keys.length) { div.innerHTML = '<p class="text-muted">No keys uploaded</p>'; return; }
  div.innerHTML = '<ul class="list-group list-group-flush"></ul>';
  const ul = div.firstChild;
  res.keys.forEach(f => {
    const li = document.createElement('li');
    li.className = 'list-group-item d-flex justify-content-between align-items-center';
    li.textContent = f;
    const btnGroup = document.createElement('div');
    btnGroup.className = 'd-flex gap-1';
    
    const downloadBtn = document.createElement('button');
    downloadBtn.className = 'btn btn-sm btn-outline-primary';
    downloadBtn.innerHTML = '<i class="bi bi-download"></i>';
    downloadBtn.title = 'Download public key';
    downloadBtn.onclick = () => downloadPublicKey(f);
    
    const del = document.createElement('button');
    del.className = 'btn btn-sm btn-outline-danger';
    del.innerHTML = '<i class="bi bi-trash"></i>';
    del.title = 'Delete key';
    del.onclick = async () => { if (confirm('Delete '+f+'?')) { await api('delete_key',{file:f}); loadKeys(); } };
    
    btnGroup.append(downloadBtn, del);
    li.append(btnGroup); 
    ul.append(li);
  });
  // populate select in modal
  const sel = document.querySelector('#tunForm select[name="key"]');
  sel.innerHTML = res.keys.map(k=>`<option value="${k}">${k}</option>`).join('');
}
async function uploadKey(file) {
  if (!file) return;
  const fd = new FormData();
  fd.append('action','upload_key');
  fd.append('csrf',csrf);
  fd.append('keyfile',file);
  const res = await fetch('?module=ssh_tunnel', {method:'POST', body:fd}).then(r=>r.json());
  if (res.error) toast('Upload failed: ' + res.error, false); else { toast('Key uploaded successfully'); loadKeys(); }
}

function downloadPublicKey(keyName) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '?module=ssh_tunnel';
  form.style.display = 'none';
  
  const actionInput = document.createElement('input');
  actionInput.name = 'action';
  actionInput.value = 'download_public_key';
  form.appendChild(actionInput);
  
  const keyInput = document.createElement('input');
  keyInput.name = 'key';
  keyInput.value = keyName;
  form.appendChild(keyInput);
  
  const csrfInput = document.createElement('input');
  csrfInput.name = 'csrf';
  csrfInput.value = csrf;
  form.appendChild(csrfInput);
  
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
}

// ───────── KEY GENERATION ─────────
function showGenerateKey() {
  const m = new bootstrap.Modal('#genKeyModal');
  m.show();
}

function toggleKeyBits(keyType) {
  const bitsGroup = document.getElementById('keyBitsGroup');
  // Only RSA keys need bit size selection
  bitsGroup.style.display = keyType === 'rsa' ? 'block' : 'none';
}

async function submitKeyGeneration() {
  const f = document.getElementById('genKeyForm');
  const data = Object.fromEntries(new FormData(f).entries());
  
  // Validate key name
  if (!data.key_name || !/^[a-zA-Z0-9_-]+$/.test(data.key_name)) {
    toast('Please enter a valid key name (letters, numbers, underscore, dash only)', false);
    return;
  }
  
  try {
    const res = await api('generate_key', data);
    if (res.error) {
      toast('Error: ' + res.error, false);
      return;
    }
    
    // Hide generation modal
    bootstrap.Modal.getInstance(document.getElementById('genKeyModal')).hide();
    
    // Show success modal with public key
    document.getElementById('privKeyName').textContent = res.private_key;
    document.getElementById('pubKeyName').textContent = res.public_key;
    document.getElementById('pubKeyContent').value = res.public_key_content;
    
    const pubModal = new bootstrap.Modal('#pubKeyModal');
    pubModal.show();
    
    // Refresh key list
    loadKeys();
    toast('SSH key pair generated successfully');
    
  } catch (error) {
    toast('Error generating key: ' + error.message, false);
  }
}

function copyPublicKey() {
  const textarea = document.getElementById('pubKeyContent');
  textarea.select();
  textarea.setSelectionRange(0, 99999); // For mobile devices
  
  try {
    document.execCommand('copy');
    // Temporary feedback
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
    btn.classList.replace('btn-outline-primary', 'btn-success');
    setTimeout(() => {
      btn.innerHTML = originalText;
      btn.classList.replace('btn-success', 'btn-outline-primary');
    }, 2000);
    toast('Public key copied to clipboard');
  } catch (err) {
    toast('Failed to copy to clipboard. Please select and copy manually.', false);
  }
}

// ───────── TUNNELS ─────────
async function loadTuns() {
  const res = await api('list_tunnels');
  const div = document.getElementById('tunList');
  if (res.error) { 
    div.innerHTML = '<p class="text-danger">'+res.error+'</p>'; 
    toast('Error loading tunnels: ' + res.error, false);
    return; 
  }
  if (!res.tunnels.length) { 
    div.innerHTML = '<p class="text-muted">No tunnels configured</p>'; 
    return; 
  }
  
  div.innerHTML = '<div class="cp-table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Status</th><th>PID</th><th class="cp-col-md">Type</th><th>Mapping</th><th class="cp-col-lg">Host</th><th style="width:20%">Actions</th><th class="d-table-cell d-sm-none" style="width:1%"><i class="bi bi-info-circle" title="Tap for details"></i></th></tr></thead><tbody></tbody></table></div>';
  const tb = div.querySelector('tbody');
  res.tunnels.forEach((t, idx) => {
    const isRunning = t.status === 'running';
    const statusIcon = isRunning ? '<i class="bi bi-play-circle-fill text-success"></i>' : '<i class="bi bi-pause-circle-fill text-muted"></i>';
    const pidDisplay = t.pid || '<span class="text-muted">-</span>';
    
    // Action buttons based on status
    let actionButtons = '';
    if (isRunning) {
      actionButtons = `
        <div class="cp-btn-group-mobile">
          <button class='btn btn-sm btn-outline-warning w-100 mb-1' onclick='stopTun("${t.id || idx}")'><i class="bi bi-pause-circle me-1"></i>Stop</button>
          <button class='btn btn-sm btn-outline-danger w-100' onclick='deleteTun("${t.id || idx}")'><i class="bi bi-trash me-1"></i>Delete</button>
        </div>
        <div class="cp-btn-group-desktop">
          <button class='btn btn-sm btn-outline-warning' onclick='stopTun("${t.id || idx}")' title='Stop tunnel'><i class="bi bi-pause-circle"></i></button>
          <button class='btn btn-sm btn-outline-danger' onclick='deleteTun("${t.id || idx}")' title='Delete tunnel'><i class="bi bi-trash"></i></button>
        </div>
      `;
    } else {
      actionButtons = `
        <div class="cp-btn-group-mobile">
          <button class='btn btn-sm btn-outline-success w-100 mb-1' onclick='startTun("${t.id || idx}")'><i class="bi bi-play-circle me-1"></i>Start</button>
          <button class='btn btn-sm btn-outline-danger w-100' onclick='deleteTun("${t.id || idx}")'><i class="bi bi-trash me-1"></i>Delete</button>
        </div>
        <div class="cp-btn-group-desktop">
          <button class='btn btn-sm btn-outline-success' onclick='startTun("${t.id || idx}")' title='Start tunnel'><i class="bi bi-play-circle"></i></button>
          <button class='btn btn-sm btn-outline-danger' onclick='deleteTun("${t.id || idx}")' title='Delete tunnel'><i class="bi bi-trash"></i></button>
        </div>
      `;
    }
    
    const tr = document.createElement('tr');
    tr.className = `cp-table-row ${isRunning ? '' : 'table-secondary'}`;
    tr.dataset.row = idx;
    tr.innerHTML = `<td>${statusIcon}</td><td class="fw-semibold">${pidDisplay}</td><td class="cp-col-md">${t.fwd}</td><td>${t.lport} ⇄ ${t.rhost}:${t.rport}</td><td class="cp-col-lg">${t.user}@${t.host}</td><td>${actionButtons}</td><td class="d-table-cell d-sm-none"><button class="cp-expand-btn" data-row="${idx}"><i class="bi bi-chevron-down"></i></button></td>`;
    tb.append(tr);
    
    // Add mobile details row (hidden by default)
    const detailsRow = document.createElement('tr');
    detailsRow.className = `cp-mobile-details d-sm-none ${isRunning ? '' : 'table-secondary'}`;
    detailsRow.id = `details-${idx}`;
    detailsRow.style.display = 'none';
    detailsRow.innerHTML = `<td colspan="7" class="p-3">
       <div class="cp-details-grid">
         <div class="cp-detail-item"><div class="cp-detail-label">Status:</div><div>${isRunning ? '<span class="text-success">Running</span>' : '<span class="text-muted">Stopped</span>'}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">PID:</div><div>${pidDisplay}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Type:</div><div>${t.fwd}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Mapping:</div><div>${t.lport} ⇄ ${t.rhost}:${t.rport}</div></div>
         <div class="cp-detail-item cp-detail-full"><div class="cp-detail-label">Host:</div><div>${t.user}@${t.host}</div></div>
         ${t.auth ? `<div class="cp-detail-item"><div class="cp-detail-label">Auth:</div><div>${t.auth}</div></div>` : ''}
         ${t.key ? `<div class="cp-detail-item"><div class="cp-detail-label">Key:</div><div><code class="cp-code-text">${t.key}</code></div></div>` : ''}
       </div>
     </td>`;
    tb.append(detailsRow);
  });
}
async function stopTun(id) {
  if (!confirm('Stop tunnel?')) return;
  const res = await api('stop_tunnel',{id});
  if (res.error) {
    toast('Error stopping tunnel: ' + res.error, false);
  } else {
    toast('Tunnel stopped');
    loadTuns();
  }
}

async function startTun(id) {
  const res = await api('start_tunnel',{id});
  if (res.error) {
    toast('Error starting tunnel: ' + res.error, false);
  } else {
    toast('Tunnel started');
    loadTuns();
  }
}

async function deleteTun(id) {
  if (!confirm('Permanently delete this tunnel configuration?')) return;
  const res = await api('delete_tunnel',{id});
  if (res.error) {
    toast('Error deleting tunnel: ' + res.error, false);
  } else {
    toast('Tunnel deleted');
    loadTuns();
  }
}

function showNewTunnel() {
  loadKeys();
  const m = new bootstrap.Modal('#tunModal');
  m.show();
}
function switchAuth(v) {
  document.getElementById('authKeyGrp').classList.toggle('d-none', v!=='key');
  document.getElementById('authPwdGrp').classList.toggle('d-none', v!=='password');
}
async function submitTunnel() {
  const f = document.getElementById('tunForm');
  const data = Object.fromEntries(new FormData(f).entries());
  data.action = 'create_tunnel'; data.csrf = csrf;
  const res = await api('create_tunnel', data);
  if (res.error) { 
    toast('Error creating tunnel: ' + res.error, false); 
    return; 
  }
  bootstrap.Modal.getInstance(document.getElementById('tunModal')).hide();
  toast('Tunnel created successfully');
  loadTuns();
}

// initial load
loadKeys();
loadTuns();
setInterval(loadTuns, 10000); // refresh every 10 s

// Handle mobile details expansion
document.addEventListener('click',e=>{
  const expandBtn=e.target.closest('.cp-expand-btn');
  if(expandBtn){
    const rowIdx=expandBtn.dataset.row;
    const detailsRow=document.querySelector(`#details-${rowIdx}`);
    const icon=expandBtn.querySelector('i');
    
    if(detailsRow.style.display==='none'){
      detailsRow.style.display='table-row';
      icon.className='bi bi-chevron-up';
    }else{
      detailsRow.style.display='none';
      icon.className='bi bi-chevron-down';
    }
    return;
  }
});

// Toast notification function (matching other modules)
function toast(message, success = true) {
  const toastArea = document.querySelector('#toastArea');
  if (!toastArea) return; // Fallback if not available
  const toastEl = document.createElement('div');
  toastEl.className = `toast align-items-center text-bg-${success ? 'success' : 'danger'}`;
  toastEl.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${message}</div>
      <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  `;
  toastArea.appendChild(toastEl);
  new bootstrap.Toast(toastEl, {delay: 4500}).show();
}

function toggleTargetFields(value) {
    const targetHost = document.getElementById('targetHostGrp');
    const targetPort = document.getElementById('targetPortGrp');
    if (value === 'D') {
        targetHost.style.display = 'none';
        targetPort.style.display = 'none';
    } else {
        targetHost.style.display = '';
        targetPort.style.display = '';
    }
}
</script>

<!--
README – Quick start
====================
1.   Ensure packages are present:
       sudo apt install php-ssh2 openssh-client sshpass # sshpass optional

2.   Place this file where your dashboard loader can serve it (e.g. modules/ssh_tunnel.php).

3.   chmod 0700 /var/www/.ssh  && chown www-data:www-data /var/www/.ssh

4.   Make sure www-data can run /usr/bin/ssh without password (already in your sudoers).

5.   Open the module in your browser:
       https://host/dashboard.php?module=ssh_tunnel

6.   Upload a private key (.pem).  Key is saved 0600 root: www-data.

7.   Click "New Tunnel".  Fill in:
       • Remote host  – test.box
       • SSH user     – ubuntu
       • Local port   – 8080
       • Target port  – 80
       • Forward type – L

     Hit Create.  A background ssh process is spawned:
       ssh -N -T -L 8080:127.0.0.1:80 ubuntu@test.box -p 22

8.   Verify:  curl http://localhost:8080  # should fetch remote site.

9.   Stop tunnel via the red X; process is killed and registry updated.
-->
