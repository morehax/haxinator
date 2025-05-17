<?php
/**
 * UI rendering functions
 *
 * Moved from /var/www/html/ui.php to /var/www/html/ui/UI.php
 */

// Get public IP from ifconfig.me
function get_public_ip() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://ifconfig.me');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl');
    $ip = trim(curl_exec($ch));
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (filter_var($ip, FILTER_VALIDATE_IP) && $httpcode === 200) {
        return $ip;
    }
    return false;
}
$public_ip = get_public_ip();
$hostname = gethostname();

// DNS resolution check for google.com
function dns_resolves_google() {
    $output = null;
    $retval = null;
    exec('dig +short google.com', $output, $retval);
    foreach ($output as $line) {
        if (filter_var($line, FILTER_VALIDATE_IP)) {
            return true;
        }
    }
    return false;
}
$dns_ok = dns_resolves_google();

function renderLoginPage($login_error)
{
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <title>Haxinator 2000 Login</title>
      <link href="/css/bootstrap.min.css" rel="stylesheet" />
      <link href="/css/theme.css" rel="stylesheet" />
      <style>
        * { margin: 0; padding: 0; }
        body { background: black; }
        canvas#c {
          display: block;
          position: fixed;
          top: 0;
          left: 0;
          width: 100vw;
          height: 100vh;
          z-index: 0;
        }
        .card {
          position: relative;
          z-index: 2;
          background: rgba(255,255,255,0.93);
        }
        .login-container {
          position: relative;
          z-index: 2;
        }
      </style>
    </head>
    <body class="d-flex align-items-center justify-content-center" style="height:100vh;">
      <canvas id="c"></canvas>
      <div class="login-container">
        <div class="card shadow-sm p-4" style="min-width:300px;">
          <h4 class="mb-3">Haxinator 2000 Login</h4>
          <?php if ($login_error): ?>
            <div class="alert alert-danger" role="alert">
              <?= htmlspecialchars($login_error) ?>
            </div>
          <?php endif; ?>
          <form method="post">
            <div class="mb-3">
              <label for="username" class="form-label">Username</label>
              <input type="text" class="form-control" id="username" name="login_username" required />
            </div>
            <div class="mb-3">
              <label for="password" class="form-label">Password</label>
              <input type="password" class="form-control" id="password" name="login_password" required />
            </div>
            <button type="submit" class="btn btn-primary w-100">Login</button>
          </form>
        </div>
      </div>
      <script>
        var c = document.getElementById("c");
        var ctx = c.getContext("2d");
        c.height = window.innerHeight;
        c.width = window.innerWidth;
        var matrix = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ123456789@#$%^&*()*&^%+-/~{[|`]}";
        matrix = matrix.split("");
        var font_size = 10;
        var columns = c.width/font_size;
        var drops = [];
        for(var x = 0; x < columns; x++) drops[x] = 1;
        function draw() {
          ctx.fillStyle = "rgba(0, 0, 0, 0.04)";
          ctx.fillRect(0, 0, c.width, c.height);
          ctx.fillStyle = "#f4427d";
          ctx.font = font_size + "px arial";
          for(var i = 0; i < drops.length; i++) {
            var text = matrix[Math.floor(Math.random()*matrix.length)];
            ctx.fillText(text, i*font_size, drops[i]*font_size);
            if(drops[i]*font_size > c.height && Math.random() > 0.975) drops[i] = 0;
            drops[i]++;
          }
        }
        setInterval(draw, 35);
      </script>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function renderMainPage($message, $error, $wifi_list, $saved_connections, $iface_status, $active_uuids, $unique_wifi_list, $public_ip, $dns_ok, $hostname)
{
    global $data;
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <title>Haxinator 2000</title>
      <link href="/css/bootstrap.min.css" rel="stylesheet" />
      <link href="/css/theme.css" rel="stylesheet" />
      <link rel="stylesheet" href="/css/bootstrap-icons/bootstrap-icons.min.css">
      <style>
        body {
          background: linear-gradient(135deg, #65ddb7 0%, #3a7cbd 100%);
          font-family: 'Segoe UI', Arial, sans-serif;
        }
        .topbar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          padding: 0.7rem 1.2rem;
          background: #f6f8fa;
          border-bottom: 2px solid #e0e7ef;
          min-height: 56px;
        }
        .topbar-left {
          display: flex;
          align-items: center;
          gap: 0.7rem;
          flex-shrink: 0;
        }
        .topbar-logo {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 40px;
          height: 40px;
          background: #2563eb;
          color: #fff;
          font-size: 1.5rem;
          font-weight: 900;
          border-radius: 50%;
          margin-bottom: 0;
          box-shadow: 0 2px 8px rgba(37,99,235,0.10);
          flex-shrink: 0;
        }
        .topbar-title {
          font-size: 1.25rem;
          font-weight: 700;
          color: #1a365d;
          letter-spacing: 1px;
          margin-bottom: 0;
          line-height: 1;
          white-space: nowrap;
        }
        .topbar-center {
          display: flex;
          align-items: center;
          gap: 0.8rem;
          margin: 0 1rem;
          flex-grow: 1;
          justify-content: center;
          flex-wrap: wrap;
        }
        .interface-status {
          display: flex;
          align-items: center;
          gap: 0.8rem;
          background: rgba(255,255,255,0.7);
          padding: 0.4rem 0.8rem;
          border-radius: 6px;
          border: 1px solid #e0e7ef;
          flex-wrap: wrap;
        }
        .interface-item {
          display: flex;
          align-items: center;
          gap: 0.4rem;
          font-size: 0.91rem;
          color: #1a365d;
          padding: 0.15rem 0.4rem;
          border-radius: 4px;
          background: rgba(255,255,255,0.8);
          border: 1px solid #e0e7ef;
          min-width: 140px;
          height: 32px;
        }
        .interface-item i {
          font-size: 0.91em;
          opacity: 0.85;
          flex-shrink: 0;
        }
        .interface-item.connected {
          border-color: #22c55e;
          background: rgba(34,197,94,0.05);
        }
        .interface-item.disconnected {
          border-color: #f59e42;
          background: rgba(245,158,66,0.05);
        }
        .interface-name {
          font-weight: 600;
          margin-right: 0.3rem;
          flex-shrink: 0;
        }
        .interface-ip {
          color: #4a5568;
          font-family: monospace;
          font-size: 0.88em;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        @media (max-width: 1200px) {
          .interface-item { 
            min-width: 120px;
            font-size: 0.85rem;
          }
          .interface-ip { 
            font-size: 0.82em;
          }
        }
        @media (max-width: 992px) {
          .topbar {
            flex-wrap: wrap;
            gap: 0.8rem;
          }
          .topbar-center {
            order: 2;
            width: 100%;
            margin: 0;
          }
          .interface-status {
            width: 100%;
            justify-content: center;
          }
        }
        .nm-header {
          display: inline;
          font-size: 2.1rem;
          font-weight: 800;
          color: #1a365d;
          letter-spacing: 1px;
          margin-bottom: 0;
          text-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .nm-header-logo {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 54px;
          height: 54px;
          background: #2563eb;
          color: #fff;
          font-size: 2.2rem;
          font-weight: 900;
          border-radius: 50%;
          margin-bottom: 0;
          box-shadow: 0 4px 16px rgba(37,99,235,0.10);
        }
        .nm-tagline {
          font-size: 1.05rem;
          color: #b0b8c9;
          font-weight: 500;
          margin-left: 0.7rem;
          vertical-align: middle;
        }
        .nm-subheader {
          color: #4e5d6c;
          font-size: 1.15rem;
          margin-bottom: 1.7rem;
          text-align: center;
        }
        .nm-card {
          border: 1.5px solid #e0e7ef;
          border-radius: var(--border-radius);
          box-shadow: 0 4px 24px rgba(37,99,235,0.08), 0 1.5px 6px rgba(0,0,0,0.04);
          background: rgba(255,255,255,0.97);
          backdrop-filter: blur(4px);
          margin-bottom: 2rem;
        }
        .nav-tabs {
          display: flex;
          flex-wrap: wrap;
          width: 100%;
          border-bottom: 2.5px solid #444;
          position: relative;
          z-index: 1;
          margin-bottom: 1.5rem;
          background: transparent;
          padding: 0;
        }
        .nav-tabs .nav-item {
          flex: 1 1 25%;
          min-width: 0;
          margin: 0;
        }
        .nav-tabs .nav-link {
          width: 100%;
          margin: 0;
          text-align: center;
          border-radius: var(--border-radius) var(--border-radius) 0 0;
          background: none;
          border: none;
          position: relative;
          z-index: 2;
          transition: background 0.13s, color 0.13s;
          padding: 1.1em 0;
          font-weight: 700;
          color: #4e5d6c;
        }
        .nav-tabs .nav-link.active {
          background: rgba(30, 41, 59, 0.65);
          color: #fff;
          border-radius: var(--border-radius) var(--border-radius) 0 0;
          font-weight: 700;
          box-shadow: none;
        }
        .nav-tabs .nav-link:hover:not(.active) {
          background: rgba(30, 41, 59, 0.35);
          color: #fff;
          border-radius: var(--border-radius) var(--border-radius) 0 0;
          box-shadow: none;
        }
        @media (max-width: 899px) {
          .nav-tabs .nav-item { flex: 1 1 50%; }
        }
        @media (max-width: 599px) {
          .nav-tabs .nav-item { flex: 1 1 100%; }
        }
        .btn-primary, .btn-success, .btn-warning {
          border-radius: calc(var(--border-radius) * 0.7);
          font-weight: 500;
          letter-spacing: 0.5px;
        }
        .nm-btn, .btn-primary {
          background: #2563eb;
          color: #fff;
          border: none;
          border-radius: calc(var(--border-radius) * 0.6) !important;
          font-weight: 500;
          letter-spacing: 0.5px;
          min-width: 92px;
          padding: 0.38em 1.1em;
          font-size: 1rem;
          box-shadow: 0 1px 3px rgba(0,0,0,0.04);
          transition: background 0.13s, color 0.13s, box-shadow 0.13s;
        }
        .nm-btn:hover, .btn-primary:hover {
          background: #1a365d;
          color: #fff;
          box-shadow: 0 2px 8px rgba(37,99,235,0.13);
        }
        .nm-btn:active { transform: scale(0.97); }
        .btn-outline-secondary {
          border-radius: 0.5em;
          font-weight: 500;
          letter-spacing: 0.5px;
        }
        .btn-outline-secondary:hover {
          background: #e0e7ef;
          color: #1a365d;
        }
        .nm-main-container {
          max-width: 1100px;
          margin: 0 auto;
          padding: 0 1.2rem;
          margin-top: 24px;
        }
        @media (max-width: 900px) {
          .nm-main-container { max-width: 100%; padding: 0 0.5rem; }
          .nm-header { font-size: 1.5rem; }
          .nm-subheader { font-size: 1rem; }
          .nm-card { margin-bottom: 1.2rem; }
        }
        @media (max-width: 600px) {
          .nm-header { font-size: 1.1rem; }
          .nm-subheader { font-size: 0.95rem; }
          .nm-main-container { padding: 0 0.2rem; }
          .nm-card { border-radius: 0.5rem; }
        }
        @media (max-width: 700px) {
          .topbar { padding: 0 0.7rem; }
          .topbar-title { font-size: 1.05rem; }
          .topbar-logo { width: 32px; height: 32px; font-size: 1.1rem; }
          .topbar-right { font-size: 0.97rem; gap: 0.7rem; }
        }
        .nm-priority-cell {
          max-width: 90px;
          text-align: center;
          vertical-align: middle !important;
        }
        .priority-select {
          border-radius: 0.5em !important;
          width: auto !important;
          min-width: 60px;
          max-width: 100px;
          padding: 0.2em 0.7em;
          box-sizing: border-box;
          background-clip: padding-box;
        }
        .script-output {
          background: #111;
          color: #0f0;
          padding: 1rem;
          height: 23.5rem;
          overflow-y: auto;
          white-space: pre-wrap;
          border-radius: 0.4rem;
        }
        .nm-action-group {
          display: flex;
          align-items: center;
          gap: 0.3em;
          flex-wrap: nowrap;
          justify-content: center;
        }
        .nm-btn, .nm-btn-sm {
          min-width: 60px;
          padding: 0.22em 0.7em;
          font-size: 0.93rem;
          border-radius: calc(var(--border-radius) * 0.6) !important;
          margin-bottom: 0;
        }
        .nm-table {
          background: #fff;
          border-radius: var(--border-radius);
          overflow: hidden;
          box-shadow: 0 1px 6px rgba(0,0,0,0.04);
        }
        .modal-content {
          border-radius: var(--border-radius);
          box-shadow: 0 4px 24px rgba(0,0,0,0.10);
        }
        .form-control, .form-select {
          border-radius: calc(var(--border-radius) * 0.7);
        }
        .nm-signal-bar-row {
          display: flex;
          align-items: center;
          gap: 0.5em;
          min-width: 110px;
        }
        .nm-signal-bar {
          flex: 1 1 60px;
          min-width: 60px;
          max-width: 90px;
        }
        .nm-signal-text {
          font-size: 0.88em;
          min-width: 2.5em;
          text-align: left;
          margin-left: 0.4em;
          line-height: 1;
        }
        .action-cell .btn {
          padding: 0.25em 0.6em;
          min-width: 36px;
          height: 32px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
        }
        .action-cell .btn i {
          font-size: 0.91em;
        }
        .nm-action-group .btn {
          padding: 0.25em 0.6em;
          min-width: 36px;
          height: 32px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
        }
        .nm-action-group .btn i {
          font-size: 0.91em;
        }
        .topbar-right {
          display: flex;
          align-items: center;
          gap: 0.7rem;
        }
        .topbar-right i {
          font-size: 1.15em;
          vertical-align: middle;
        }
        .action-buttons {
          display: flex;
          align-items: center;
          gap: 0.5rem;
        }
      </style>
    </head>
    <body>
    <!-- TEST123: public_ip=<?php var_export($public_ip); ?> dns_ok=<?php var_export($dns_ok); ?> -->
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-logo">H</div>
        <span class="topbar-title">Haxinator 2000</span>
      </div>
      <div class="topbar-center">
        <div class="interface-status">
          <?php 
          try {
            $interfaces = $data->getTopBarInterfaces();
            foreach ($interfaces as $iface): ?>
              <div class="interface-item <?= $iface['connected'] ? 'connected' : 'disconnected' ?>">
                <i class="bi <?= htmlspecialchars($iface['icon']) ?>"></i>
                <span class="interface-name"><?= htmlspecialchars($iface['name']) ?></span>
                <?php if ($iface['ipv4']): ?>
                  <span class="interface-ip"><?= htmlspecialchars($iface['ipv4']) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach;
          } catch (Exception $e) {
            error_log("Error in interface status: " . $e->getMessage());
          }
          ?>
        </div>
      </div>
      <div class="topbar-right">
        <?php if ($public_ip): ?>
          <span title="Internet Connected"><i class="bi bi-wifi" style="color:#22c55e;"></i></span>
        <?php else: ?>
          <span title="No Internet"><i class="bi bi-wifi-off" style="color:#f59e42;"></i></span>
        <?php endif; ?>
        <?php if ($dns_ok): ?>
          <span title="DNS resolves google.com"><i class="bi bi-plugin" style="color:#22c55e;margin-left:0.7em;"></i></span>
        <?php else: ?>
          <span title="DNS does not resolve google.com"><i class="bi bi-plug" style="color:#f4427d;margin-left:0.7em;"></i></span>
        <?php endif; ?>
        <a href="https://<?php echo $_SERVER['SERVER_ADDR']; ?>:4200" target="_blank" class="btn btn-outline-secondary btn-sm ms-3" style="min-width: 40px;"><i class="bi bi-terminal"></i> <span class="d-none d-md-inline">Terminal</span></a>
        <button onclick="shutdownSystem()" class="btn btn-outline-danger btn-sm ms-2" style="min-width: 40px;"><i class="bi bi-power"></i> <span class="d-none d-md-inline">Shutdown</span></button>
        <form method="get" class="d-inline ms-2">
          <button type="submit" name="logout" class="btn btn-outline-secondary btn-sm" style="min-width: 40px;"><i class="bi bi-box-arrow-right"></i> <span class="d-none d-md-inline">Logout</span></button>
        </form>
      </div>
    </div>
    <div class="nm-main-container mx-auto px-3 px-md-4" style="max-width:1100px;">
      <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>

      <ul class="nav nav-tabs mb-3" id="managerTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="wifi-tab" data-bs-toggle="tab" data-bs-target="#wifiTab" type="button" role="tab" aria-controls="wifiTab" aria-selected="true"><i class="bi bi-wifi" style="font-size:0.91em;"></i> Wi-Fi Networks</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="saved-tab" data-bs-toggle="tab" data-bs-target="#savedTab" type="button" role="tab" aria-controls="savedTab" aria-selected="false"><i class="bi bi-bookmark-star" style="font-size:0.91em;"></i> Saved Connections</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="current-tab" data-bs-toggle="tab" data-bs-target="#currentTab" type="button" role="tab" aria-controls="currentTab" aria-selected="false"><i class="bi bi-activity" style="font-size:0.91em;"></i> Network Status</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="haxinate-tab" data-bs-toggle="tab" data-bs-target="#haxinateTab" type="button" role="tab" aria-controls="haxinateTab" aria-selected="false"><i class="bi bi-lightning-charge" style="font-size:0.91em;"></i> Haxinate Wifi</button>
        </li>
      </ul>

      <div class="tab-content" id="managerTabsContent">
        <div class="tab-pane fade show active" id="wifiTab" role="tabpanel" aria-labelledby="wifi-tab">
          <div class="card nm-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span>Wi-Fi Networks</span>
              <form method="post" class="m-0">
                <button type="submit" name="scan_refresh" class="btn btn-refresh">Refresh</button>
              </form>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover table-sm nm-table mb-0">
                  <thead>
                    <tr>
                      <th>SSID</th>
                      <th>Signal</th>
                      <th>Security</th>
                      <th style="width: 200px;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($wifi_list)): ?>
                    <tr><td colspan="4" class="text-muted">No networks found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($wifi_list as $net):
                      $ssid_display = $net['ssid'] !== '(hidden)' ? htmlspecialchars($net['ssid']) : '<em>(Hidden)</em>';
                      $bssid_display = '<div class="bssid-sub" style="font-size:0.92em; color:#b0b8c9; font-family:monospace; font-weight:400; margin-top:0.1em;">' . htmlspecialchars($net['bssid']) . '</div>';
                      $signalPercent = intval($net['signal']);
                      $signalColor = $signalPercent >= 75 ? 'bg-success' :
                                     ($signalPercent >= 50 ? 'bg-warning' : 'bg-danger');
                    ?>
                      <tr <?= $net['in_use'] ? 'class="table-success"' : '' ?>>
                        <td class="ssid-cell">
                          <span style="font-weight:700; font-size:1.08em; color:#1a365d;"><?= htmlspecialchars($net['ssid']) ?></span>
                          <?= $bssid_display ?>
                        </td>
                        <td>
                          <div class="nm-signal-bar-row">
                            <div class="progress nm-signal-bar">
                              <div class="progress-bar <?= $signalColor ?>" role="progressbar" style="width: <?= $signalPercent ?>%;" aria-valuenow="<?= $signalPercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <span class="nm-signal-text"><?= $signalPercent ?>%</span>
                          </div>
                        </td>
                        <td><?= htmlspecialchars($net['security']) ?></td>
                        <td class="action-cell">
                          <?php if ($net['in_use']): ?>
                            <form method="post" class="m-0" style="display:inline;">
                              <input type="hidden" name="disconnect_wifi" value="<?= htmlspecialchars($net['ssid']) ?>">
                              <button type="submit" class="btn btn-danger nm-btn nm-btn-sm" title="Disconnect">
                                <i class="bi bi-plug-fill" style="transform: rotate(180deg);"></i>
                              </button>
                            </form>
                          <?php else: ?>
                            <button type="button" class="btn btn-primary nm-btn btn-connect-wifi" title="Connect"
                                    data-ssid="<?= htmlspecialchars($net['ssid']) ?>"
                                    data-security="<?= htmlspecialchars($net['security']) ?>"
                                    data-bssid="<?= htmlspecialchars($net['bssid']) ?>">
                              <i class="bi bi-plug"></i>
                            </button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="savedTab" role="tabpanel" aria-labelledby="saved-tab">
          <div class="card nm-card mb-4">
            <div class="card-header">Saved Connections</div>
            <div class="card-body p-0">
              <?php if (empty($saved_connections)): ?>
                <div class="p-3 text-muted">No saved connections found.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm table-hover nm-table mb-0">
                    <thead>
                      <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Device</th>
                        <th class="nm-priority-cell">PRTY</th>
                        <th class="text-center">Status</th>
                        <th class="nm-actions-cell">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($saved_connections as $conn):
                        // Skip loopback connections
                        if ($conn['type'] === 'loopback' || $conn['device'] === 'lo') continue;
                        // Check if this connection is currently active using UUID
                        $isActive = in_array($conn['uuid'], $active_uuids);
                    ?>
                      <tr <?= $isActive ? 'class="table-success"' : '' ?>>
                        <td style="position:relative;" class="name-cell">
                          <span style="font-weight:700; font-size:1.08em; color:#1a365d;"><?= htmlspecialchars($conn['name']) ?></span>
                          <div class="uuid-sub" style="font-size:0.80em; color:#b0b8c9; font-family:monospace; font-weight:400; margin-top:0.1em; word-break:break-all;"><?= htmlspecialchars($conn['uuid']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($conn['type']) ?></td>
                        <td><?= $conn['device'] ? htmlspecialchars($conn['device']) : '-' ?></td>
                        <td class="nm-priority-cell">
                          <form method="post" class="priority-form" data-uuid="<?= htmlspecialchars($conn['uuid']) ?>">
                            <input type="hidden" name="update_priority" value="1">
                            <input type="hidden" name="connection_uuid" value="<?= htmlspecialchars($conn['uuid']) ?>">
                            <select name="priority" class="form-select form-select-sm priority-select" style="width: 80px;">
                              <?php for ($i = 0; $i <= 100; $i += 10): ?>
                                <option value="<?= $i ?>" <?= ($conn['priority'] ?? 0) == $i ? 'selected' : '' ?>>
                                  <?= $i ?>
                                </option>
                              <?php endfor; ?>
                            </select>
                          </form>
                        </td>
                        <td class="text-center align-middle">
                          <?php if ($isActive): ?>
                            <span class="badge bg-success nm-status-badge">Connected</span>
                          <?php else: ?>
                            <span class="badge bg-secondary nm-status-badge">Disconnected</span>
                          <?php endif; ?>
                        </td>
                        <td class="nm-actions-cell">
                          <div class="nm-action-group justify-content-center">
                            <?php if ($isActive): ?>
                              <form method="post" class="m-0" style="display:inline;">
                                <input type="hidden" name="disconnect_connection" value="<?= htmlspecialchars($conn['uuid']) ?>">
                                <button type="submit" class="btn btn-warning nm-btn nm-btn-sm" title="Disconnect">
                                  <i class="bi bi-plug-fill" style="transform: rotate(180deg);"></i>
                                </button>
                              </form>
                            <?php else: ?>
                              <form method="post" class="m-0" style="display:inline;">
                                <input type="hidden" name="activate_connection" value="<?= htmlspecialchars($conn['uuid']) ?>">
                                <button type="submit" class="btn btn-success nm-btn nm-btn-sm" title="Connect">
                                  <i class="bi bi-plug"></i>
                                </button>
                              </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-primary nm-btn nm-btn-sm edit-connection"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editConnectionModal"
                                    data-uuid="<?= htmlspecialchars($conn['uuid']) ?>"
                                    data-name="<?= htmlspecialchars($conn['name']) ?>"
                                    data-type="<?= htmlspecialchars($conn['type']) ?>"
                                    title="Edit">
                              <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" class="m-0" style="display:inline;">
                              <input type="hidden" name="delete_connection" value="<?= htmlspecialchars($conn['uuid']) ?>">
                              <button type="submit" class="btn btn-danger nm-btn nm-btn-sm" title="Delete">
                                <i class="bi bi-trash"></i>
                              </button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Edit Connection Modal -->
        <div class="modal fade" id="editConnectionModal" tabindex="-1" aria-labelledby="editConnectionModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="editConnectionModalLabel">Edit Connection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <form id="editConnectionForm" method="post">
                  <input type="hidden" name="update_connection" value="1">
                  <input type="hidden" name="connection_uuid" id="editConnectionUuid">

                  <ul class="nav nav-tabs mb-3" id="editConnectionTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                      <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">General</button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="ipv4-tab" data-bs-toggle="tab" data-bs-target="#ipv4" type="button" role="tab">IPv4</button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">Security</button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="advanced-tab" data-bs-toggle="tab" data-bs-target="#advanced" type="button" role="tab">Advanced</button>
                    </li>
                  </ul>

                  <div class="tab-content" id="editConnectionTabsContent">
                    <!-- General Settings -->
                    <div class="tab-pane fade show active" id="general" role="tabpanel">
                      <div class="mb-3">
                        <label for="connectionName" class="form-label">Connection Name</label>
                        <input type="text" class="form-control" id="connectionName" name="name" required>
                      </div>
                      <div class="mb-3">
                        <div class="form-check">
                          <input type="checkbox" class="form-check-input" id="autoconnect" name="autoconnect" value="1">
                          <label class="form-check-label" for="autoconnect">Connect automatically</label>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="priority" class="form-label">Connection Priority (0-100)</label>
                        <input type="number" class="form-control" id="priority" name="priority" min="0" max="100" step="10">
                      </div>
                    </div>

                    <!-- IPv4 Settings -->
                    <div class="tab-pane fade" id="ipv4" role="tabpanel">
                      <div class="mb-3">
                        <label class="form-label">IPv4 Method</label>
                        <div class="form-check">
                          <input type="radio" class="form-check-input" name="ipv4_method" value="auto" id="ipv4Auto" checked>
                          <label class="form-check-label" for="ipv4Auto">Automatic (DHCP)</label>
                        </div>
                        <div class="form-check">
                          <input type="radio" class="form-check-input" name="ipv4_method" value="manual" id="ipv4Manual">
                          <label class="form-check-label" for="ipv4Manual">Manual</label>
                        </div>
                      </div>

                      <div id="manualIpv4Settings" style="display: none;">
                        <div class="mb-3">
                          <label for="ipv4Address" class="form-label">IPv4 Address</label>
                          <input type="text" class="form-control" id="ipv4Address" name="ipv4_address" placeholder="e.g., 192.168.1.100/24">
                        </div>
                        <div class="mb-3">
                          <label for="ipv4Gateway" class="form-label">Gateway</label>
                          <input type="text" class="form-control" id="ipv4Gateway" name="ipv4_gateway" placeholder="e.g., 192.168.1.1">
                        </div>
                        <div class="mb-3">
                          <label for="ipv4Dns" class="form-label">DNS Servers</label>
                          <input type="text" class="form-control" id="ipv4Dns" name="ipv4_dns" placeholder="e.g., 8.8.8.8,8.8.4.4">
                        </div>
                      </div>
                    </div>

                    <!-- Security Settings (WiFi only) -->
                    <div class="tab-pane fade" id="security" role="tabpanel">
                      <div id="wifiSecuritySettings" style="display: none;">
                        <div class="mb-3">
                          <label for="securityType" class="form-label">Security Type</label>
                          <select class="form-select" id="securityType" name="wifi_security_type">
                            <option value="none">None</option>
                            <option value="wpa-psk">WPA & WPA2 Personal</option>
                            <option value="wpa-eap">WPA & WPA2 Enterprise</option>
                          </select>
                        </div>
                        <div class="mb-3" id="passwordField" style="display: none;">
                          <label for="wifiPassword" class="form-label">Password</label>
                          <input type="password" class="form-control" id="wifiPassword" name="wifi_password">
                        </div>
                      </div>
                      <div id="ethernetSecurityNote" class="text-muted">
                        Security settings are not available for Ethernet connections.
                      </div>
                    </div>

                    <!-- Advanced Settings -->
                    <div class="tab-pane fade" id="advanced" role="tabpanel">
                      <div id="advancedFieldsContainer">
                        <div class="text-muted">Loading advanced fields...</div>
                      </div>
                    </div>
                  </div>
                </form>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="editConnectionForm" class="btn btn-primary">Save Changes</button>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="currentTab" role="tabpanel" aria-labelledby="current-tab">
          <div class="card nm-card mb-4">
            <div class="card-header">Current Network Status</div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm align-middle nm-table mb-0">
                  <thead>
                    <tr>
                      <th>Interface</th>
                      <th>Status</th>
                      <th>IP Address</th>
                      <th>MAC Address</th>
                      <th>Mode</th>
                      <th>Configure</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($iface_status as $if => $st):
                      // Skip loopback interface
                      if ($if === 'lo') continue;
                      $isStatic = ($st['method'] === 'Static');
                      $isConnected = ($st['state'] === 'connected' || !empty($st['connection']));
                  ?>
                    <tr<?= $isConnected ? ' class="table-success"' : '' ?>>
                      <td class="name-cell" style="font-weight:700; font-size:1.08em; color:#1a365d;">
                        <?= htmlspecialchars($if) ?>
                      </td>
                      <td>
                        <?php
                          $stateText = '';
                          if ($st['connection']) {
                              if ($st['connection_type'] === 'vpn') {
                                  $stateText = 'VPN: ' . htmlspecialchars($st['connection']);
                              } else if ($st['connection_type'] === 'tun') {
                                  $stateText = 'Tunnel: ' . htmlspecialchars($st['connection']);
                              } else if ($st['connection_type'] === 'loopback') {
                                  $stateText = 'Loopback';
                              } else if ($if === 'wlan0') {
                                  $stateText = 'Connected to ' . htmlspecialchars($st['connection']);
                              } else {
                                  $stateText = 'Connected';
                              }
                          } else if ($st['state'] === 'disconnected' || $st['state'] === 'unavailable') {
                              $stateText = 'Disconnected';
                          } else {
                              $stateText = ucfirst($st['state']);
                          }
                          ?>
                        <span class="nm-status-badge badge <?= $isConnected ? 'bg-success' : 'bg-secondary' ?>">
                          <?= htmlspecialchars($stateText) ?>
                        </span>
                      </td>
                      <td><?= $st['ip'] ? htmlspecialchars($st['ip']) : '-' ?></td>
                      <td><?= $st['mac'] ? htmlspecialchars($st['mac']) : '-' ?></td>
                      <td><?= $st['method'] ? htmlspecialchars($st['method']) : '-' ?></td>
                      <td>
                        <?php if ($st['connection_type'] !== 'loopback' && $st['connection_type'] !== 'tun'): ?>
                          <button type="button" class="btn btn-primary nm-btn nm-btn-sm" title="Configure"
                                  data-iface="<?= htmlspecialchars($if) ?>"
                                  data-method="<?= htmlspecialchars($st['method']) ?>"
                                  data-ip="<?= htmlspecialchars($st['ip']) ?>"
                                  data-mask="<?= htmlspecialchars($st['mask'] ?? '') ?>"
                                  data-gw="<?= htmlspecialchars($st['gw'] ?? '') ?>"
                                  data-dns="<?= htmlspecialchars($st['dns'] ?? '') ?>">
                            <i class="bi bi-pencil"></i>
                          </button>
                        <?php else: ?>
                          <span class="text-muted">Configuration not available</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="haxinateTab" role="tabpanel" aria-labelledby="haxinate-tab">
          <div class="card nm-card mb-4">
            <div class="card-header">Haxinate: Network Tools</div>
            <div class="card-body p-0">
              <div class="p-3">
                <!-- Script Selector -->
                <div class="mb-4">
                  <label for="script-selector" class="form-label">Select Tool</label>
                  <select id="script-selector" class="form-select" style="max-width:300px;">
                    <option value="wifi-test">WiFi Network Tester</option>
                    <option value="network-scan">Outbound Traffic Tester</option>
                    <option value="port-scan">Port Scanner</option>
                    <option value="dns-lookup">DNS Lookup</option>
                  </select>
                </div>

                <!-- Dynamic Content Area -->
                <div id="script-content">
                  <!-- WiFi Network Tester -->
                  <div class="script-panel" id="wifi-test-panel">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                      <button id="runPing" class="btn nm-btn"><i class="bi bi-play-circle"></i> Run Test</button>
                      <select id="haxinate-ssid" class="form-select" style="width:auto;min-width:220px;">
                        <option value="">Select Wi-Fi Network</option>
                        <?php 
                        if (!empty($unique_wifi_list)): 
                          foreach ($unique_wifi_list as $net):
                            // Skip networks with empty or hidden SSIDs
                            if (empty($net['ssid']) || $net['ssid'] === '(hidden)'): 
                              continue; 
                            endif;
                            $ssid = htmlspecialchars($net['ssid']);
                            $selected = ($net['in_use'] ?? false) ? 'selected' : '';
                        ?>
                          <option value="<?= $ssid ?>" <?= $selected ?>><?= $ssid ?></option>
                        <?php 
                          endforeach; 
                        endif; 
                        ?>
                      </select>
                      <button id="haxinate-refresh" class="btn btn-outline-secondary" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh Networks</button>
                      <span id="ping-status"></span>
                    </div>
                    <pre id="ping-log" class="script-output">Are we ready to Haxinate today?</pre>
                  </div>

                  <!-- Network Scanner -->
                  <div class="script-panel" id="network-scan-panel" style="display:none;">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                      <button id="runNetworkScan" class="btn btn-primary">Start Check</button>
                      <span id="network-scan-status"></span>
                    </div>
                    <pre id="network-scan-log" class="script-output">Ready to scan network...</pre>
                  </div>

                  <!-- Port Scanner -->
                  <div class="script-panel" id="port-scan-panel" style="display:none;">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                      <button id="runPortScan" class="btn btn-primary">Start Scan</button>
                      <input type="text" id="target-host" class="form-control" style="width:auto;min-width:220px;" placeholder="Host IP or domain">
                      <input type="text" id="port-range" class="form-control" style="width:auto;min-width:150px;" placeholder="Port range (e.g., 1-1000)">
                      <span id="port-scan-status"></span>
                    </div>
                    <pre id="port-scan-log" class="script-output">Ready to scan ports...</pre>
                  </div>

                  <!-- DNS Lookup -->
                  <div class="script-panel" id="dns-lookup-panel" style="display:none;">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                      <button id="runDnsLookup" class="btn btn-primary">Lookup</button>
                      <input type="text" id="dns-domain" class="form-control" style="width:auto;min-width:220px;" placeholder="Domain name">
                      <select id="dns-record-type" class="form-select" style="width:auto;min-width:150px;">
                        <option value="A">A Record</option>
                        <option value="AAAA">AAAA Record</option>
                        <option value="MX">MX Record</option>
                        <option value="NS">NS Record</option>
                        <option value="TXT">TXT Record</option>
                      </select>
                      <span id="dns-lookup-status"></span>
                    </div>
                    <pre id="dns-lookup-log" class="script-output">Ready for DNS lookup...</pre>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Interface Configuration Modal -->
    <div class="modal fade" id="ifaceConfigModal" tabindex="-1" aria-labelledby="ifaceConfigModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="ifaceConfigModalLabel">Configure Interface</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="post" id="ifaceConfigForm">
            <div class="modal-body">
              <ul class="nav nav-tabs mb-3" id="ifaceConfigTabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="iface-general-tab" data-bs-toggle="tab" data-bs-target="#ifaceGeneral" type="button" role="tab">General</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="iface-advanced-tab" data-bs-toggle="tab" data-bs-target="#ifaceAdvanced" type="button" role="tab">Advanced</button>
                </li>
              </ul>
              <div class="tab-content" id="ifaceConfigTabsContent">
                <div class="tab-pane fade show active" id="ifaceGeneral" role="tabpanel">
                  <input type="hidden" name="iface" id="ifaceConfigIface">
                  <div class="mb-3">
                    <label class="form-label">IP Assignment</label>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="ip_mode" id="ifaceConfigDhcp" value="dhcp" checked>
                      <label class="form-check-label" for="ifaceConfigDhcp">DHCP (Automatic)</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="ip_mode" id="ifaceConfigStatic" value="static">
                      <label class="form-check-label" for="ifaceConfigStatic">Static (Manual)</label>
                    </div>
                  </div>
                  <div id="ifaceConfigStaticFields" style="display:none;">
                    <div class="mb-3">
                      <label for="ifaceConfigIp" class="form-label">IP Address</label>
                      <input type="text" class="form-control" name="static_ip" id="ifaceConfigIp" placeholder="e.g. 192.168.1.100">
                    </div>
                    <div class="mb-3">
                      <label for="ifaceConfigMask" class="form-label">Subnet Mask</label>
                      <input type="text" class="form-control" name="static_mask" id="ifaceConfigMask" placeholder="e.g. 255.255.255.0">
                    </div>
                    <div class="mb-3">
                      <label for="ifaceConfigGw" class="form-label">Gateway</label>
                      <input type="text" class="form-control" name="static_gw" id="ifaceConfigGw" placeholder="e.g. 192.168.1.1">
                    </div>
                    <div class="mb-3">
                      <label for="ifaceConfigDns" class="form-label">DNS Servers</label>
                      <input type="text" class="form-control" name="static_dns" id="ifaceConfigDns" placeholder="e.g. 8.8.8.8,8.8.4.4">
                    </div>
                  </div>
                </div>
                <div class="tab-pane fade" id="ifaceAdvanced" role="tabpanel">
                  <div class="mb-3">
                    <label for="ifaceConfigMtu" class="form-label">MTU</label>
                    <input type="number" class="form-control" name="mtu" id="ifaceConfigMtu" placeholder="e.g. 1500">
                  </div>
                  <div class="mb-3">
                    <label for="ifaceConfigMacSpoof" class="form-label">MAC Spoofing</label>
                    <input type="text" class="form-control" name="mac_spoof" id="ifaceConfigMacSpoof" placeholder="e.g. 00:11:22:33:44:55">
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Apply</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- WiFi Connect Modal -->
    <div class="modal fade" id="wifiConnectModal" tabindex="-1" aria-labelledby="wifiConnectModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="wifiConnectModalLabel">Connect to Wi-Fi</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form id="wifiConnectForm">
            <div class="modal-body">
              <ul class="nav nav-tabs mb-3" id="wifiConnectTabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="wifi-general-tab" data-bs-toggle="tab" data-bs-target="#wifiGeneral" type="button" role="tab">General</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="wifi-advanced-tab" data-bs-toggle="tab" data-bs-target="#wifiAdvanced" type="button" role="tab">Advanced</button>
                </li>
              </ul>
              <div class="tab-content" id="wifiConnectTabsContent">
                <div class="tab-pane fade show active" id="wifiGeneral" role="tabpanel">
                  <div class="mb-3">
                    <label class="form-label">SSID</label>
                    <input type="text" class="form-control" id="wifiConnectSsid" name="connect_ssid" readonly>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Security</label>
                    <input type="text" class="form-control" id="wifiConnectSecurity" name="security" readonly>
                  </div>
                  <div class="mb-3" id="wifiPasswordField">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control" id="wifiConnectPassword" name="wifi_password">
                  </div>
                </div>
                <div class="tab-pane fade" id="wifiAdvanced" role="tabpanel">
                  <div class="mb-3">
                    <label class="form-label">BSSID</label>
                    <input type="text" class="form-control" id="wifiConnectBssid" name="bssid">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Band</label>
                    <input type="text" class="form-control" id="wifiConnectBand" name="band" placeholder="e.g. a, bg">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Channel</label>
                    <input type="number" class="form-control" id="wifiConnectChannel" name="channel" min="1" max="165">
                  </div>
                </div>
              </div>
              <div id="wifiConnectFeedback" class="mt-2"></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Connect</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script src="/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
      document.querySelectorAll(".ip-mode-toggle").forEach(function (radio) {
        radio.addEventListener("change", function () {
          const form = this.closest("form");
          const showStatic = (this.value === "static");
          const fields = form.querySelector(".static-fields");
          if (fields) {
            fields.classList.toggle("d-none", !showStatic);
          }
        });
      });

      // Handle priority changes
      document.querySelectorAll('.priority-select').forEach(select => {
        select.addEventListener('change', function() {
          this.closest('form').submit();
        });
      });

      // Handle IPv4 method changes
      document.querySelectorAll('input[name="ipv4_method"]').forEach(radio => {
        radio.addEventListener('change', function() {
          document.getElementById('manualIpv4Settings').style.display =
            this.value === 'manual' ? 'block' : 'none';
        });
      });

      // Handle security type changes
      document.getElementById('securityType')?.addEventListener('change', function() {
        document.getElementById('passwordField').style.display =
          this.value === 'wpa-psk' ? 'block' : 'none';
      });

      // Handle edit button clicks
      document.querySelectorAll('.edit-connection').forEach(button => {
        button.addEventListener('click', function() {
          const uuid = this.dataset.uuid;
          const name = this.dataset.name;
          const type = this.dataset.type;

          // Set form values
          document.getElementById('editConnectionUuid').value = uuid;
          document.getElementById('connectionName').value = name;

          // Show/hide security settings based on connection type
          const isWifi = type === '802-11-wireless';
          document.getElementById('wifiSecuritySettings').style.display =
            isWifi ? 'block' : 'none';
          document.getElementById('ethernetSecurityNote').style.display =
            isWifi ? 'none' : 'block';

          // Fetch current connection details
          fetch(`/api/connection-details.php?uuid=${encodeURIComponent(uuid)}`)
            .then(response => response.json())
            .then(data => {
              // Populate form with connection details
              if (data.autoconnect) {
                document.getElementById('autoconnect').checked = data.autoconnect === 'yes';
              }
              if (data.priority) {
                document.getElementById('priority').value = data.priority;
              }
              if (data.ipv4_method) {
                const method = data.ipv4_method === 'auto' ? 'auto' : 'manual';
                document.querySelector(`input[name="ipv4_method"][value="${method}"]`).checked = true;
                if (method === 'manual') {
                  document.getElementById('manualIpv4Settings').style.display = 'block';
                  document.getElementById('ipv4Address').value = data.ipv4_address || '';
                  document.getElementById('ipv4Gateway').value = data.ipv4_gateway || '';
                  document.getElementById('ipv4Dns').value = data.ipv4_dns || '';
                }
              }
              if (isWifi && data.wifi_security_type) {
                document.getElementById('securityType').value = data.wifi_security_type;
                document.getElementById('passwordField').style.display =
                  data.wifi_security_type === 'wpa-psk' ? 'block' : 'none';
                if (data.wifi_password) {
                  document.getElementById('wifiPassword').value = data.wifi_password;
                }
              }

              // Populate Advanced tab with logical grouping
              const raw = data.raw || {};
              const groups = {
                'General': {},
                'IPv4': {},
                'IPv6': {},
                'WiFi': {},
                'Ethernet': {},
                'Proxy': {},
                'Other': {}
              };
              Object.entries(raw).forEach(([key, value]) => {
                if (key.startsWith('connection.')) groups['General'][key] = value;
                else if (key.startsWith('ipv4.')) groups['IPv4'][key] = value;
                else if (key.startsWith('ipv6.')) groups['IPv6'][key] = value;
                else if (key.startsWith('802-11-wireless')) groups['WiFi'][key] = value;
                else if (key.startsWith('802-3-ethernet')) groups['Ethernet'][key] = value;
                else if (key.startsWith('proxy.')) groups['Proxy'][key] = value;
                else groups['Other'][key] = value;
              });
              let advHtml = '';
              Object.entries(groups).forEach(([group, fields]) => {
                if (Object.keys(fields).length === 0) return;
                advHtml += `<div class='mb-3'><h6>${group}</h6><table class='table table-sm table-bordered'><tbody>`;
                Object.entries(fields).forEach(([k, v]) => {
                  advHtml += `<tr><td style='font-family:monospace;'>${k}</td><td>${v}</td></tr>`;
                });
                advHtml += '</tbody></table></div>';
              });
              document.getElementById('advancedFieldsContainer').innerHTML =
                advHtml || '<div class="text-muted">No advanced fields found.</div>';
            });
        });
      });

      // Interface configuration modal logic
      document.querySelectorAll('.configure-btn').forEach(btn => {
        btn.addEventListener('click', function() {
          const iface = this.getAttribute('data-iface');
          const method = this.getAttribute('data-method');
          document.getElementById('ifaceConfigIface').value = iface;
          // Set radio buttons
          document.getElementById('ifaceConfigDhcp').checked = (method !== 'Static');
          document.getElementById('ifaceConfigStatic').checked = (method === 'Static');
          // Show/hide static fields
          document.getElementById('ifaceConfigStaticFields').style.display = (method === 'Static') ? 'block' : 'none';
          // Prefill static fields
          document.getElementById('ifaceConfigIp').value = this.getAttribute('data-ip') || '';
          document.getElementById('ifaceConfigMask').value = this.getAttribute('data-mask') || '';
          document.getElementById('ifaceConfigGw').value = this.getAttribute('data-gw') || '';
          document.getElementById('ifaceConfigDns').value = this.getAttribute('data-dns') || '';
          // Show modal
          var modal = new bootstrap.Modal(document.getElementById('ifaceConfigModal'));
          modal.show();
        });
      });
      document.getElementById('ifaceConfigDhcp').addEventListener('change', function() {
        document.getElementById('ifaceConfigStaticFields').style.display = this.checked ? 'none' : 'block';
      });
      document.getElementById('ifaceConfigStatic').addEventListener('change', function() {
        document.getElementById('ifaceConfigStaticFields').style.display = this.checked ? 'block' : 'none';
      });

      // WiFi Connect Modal logic
      document.querySelectorAll('.btn-connect-wifi').forEach(btn => {
        btn.addEventListener('click', function() {
          document.getElementById('wifiConnectSsid').value = this.getAttribute('data-ssid');
          document.getElementById('wifiConnectSecurity').value = this.getAttribute('data-security');
          document.getElementById('wifiConnectBssid').value = this.getAttribute('data-bssid');
          document.getElementById('wifiConnectBand').value = '';
          document.getElementById('wifiConnectChannel').value = '';
          document.getElementById('wifiConnectPassword').value = '';
          document.getElementById('wifiConnectFeedback').innerHTML = '';
          // Show/hide password field based on security
          const sec = this.getAttribute('data-security').toLowerCase();
          document.getElementById('wifiPasswordField').style.display = (sec.includes('wpa') || sec.includes('wep')) ? 'block' : 'none';
          var modal = new bootstrap.Modal(document.getElementById('wifiConnectModal'));
          modal.show();
        });
      });
      document.getElementById('wifiConnectForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const feedback = document.getElementById('wifiConnectFeedback');
        feedback.innerHTML = '<div class="text-info">Connecting...</div>';
        const formData = new FormData(form);
        fetch('', {
          method: 'POST',
          body: new URLSearchParams(formData),
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(resp => resp.text())
        .then(html => {
          // Try to parse for success/failure
          if (html.includes('alert-success')) {
            feedback.innerHTML = '<div class="alert alert-success">Connected successfully!</div>';
            setTimeout(() => {
              bootstrap.Modal.getInstance(document.getElementById('wifiConnectModal')).hide();
              window.location.reload();
            }, 1200);
          } else if (html.includes('alert-danger')) {
            // Extract error message
            const match = html.match(/<div class="alert alert-danger">([\s\S]*?)<\/div>/);
            feedback.innerHTML = match
              ? `<div class="alert alert-danger">${match[1]}</div>`
              : '<div class="alert alert-danger">Failed to connect.</div>';
          } else {
            feedback.innerHTML = '<div class="alert alert-danger">Unknown error.</div>';
          }
        })
        .catch(() => {
          feedback.innerHTML = '<div class="alert alert-danger">Network error.</div>';
        });
      });

      // Script selector logic
      const scriptSelector = document.getElementById('script-selector');
      const scriptPanels = document.querySelectorAll('.script-panel');
      
      scriptSelector.addEventListener('change', function() {
        // Hide all panels
        scriptPanels.forEach(panel => panel.style.display = 'none');
        // Show selected panel
        document.getElementById(this.value + '-panel').style.display = 'block';
      });

      // Existing WiFi test logic
      let pingEs;
      const pingLog = document.getElementById('ping-log');
      const pingStatus = document.getElementById('ping-status');
      const runPingBtn = document.getElementById('runPing');
      const ssidSelect = document.getElementById('haxinate-ssid');
      const refreshBtn = document.getElementById('haxinate-refresh');
      
      // Add refresh handler
      if (refreshBtn) {
        refreshBtn.onclick = () => {
          // Submit the scan_refresh form
          const form = document.createElement('form');
          form.method = 'POST';
          form.innerHTML = '<input type="hidden" name="scan_refresh" value="1">';
          document.body.appendChild(form);
          form.submit();
        };
      }
      
      if (runPingBtn && ssidSelect) {
        runPingBtn.onclick = () => {
          if (pingEs) pingEs.close();
          pingLog.textContent = '';
          const ssid = ssidSelect.value;
          if (!ssid) {
            pingStatus.textContent = ' ❗ Please select a Wi-Fi network.';
            return;
          }
          pingStatus.textContent = ' ⏳ Running…';
          pingEs = new EventSource('/api/ping-stream.php?ssid=' + encodeURIComponent(ssid));
          pingEs.onmessage = e => {
            pingLog.textContent += e.data + '\n';
            pingLog.scrollTop = pingLog.scrollHeight;
          };
          pingEs.addEventListener('done', () => {
            pingStatus.textContent = ' ✅ Finished';
            pingEs.close();
          });
          pingEs.onerror = () => {
            pingStatus.textContent = ' ❌ Stream error';
            pingEs.close();
          };
        };
      }

      // Network Scanner logic
      const runNetworkScanBtn = document.getElementById('runNetworkScan');
      const networkScanLog = document.getElementById('network-scan-log');
      const networkScanStatus = document.getElementById('network-scan-status');

      if (runNetworkScanBtn) {
        runNetworkScanBtn.onclick = () => {
          networkScanStatus.textContent = ' ⏳ Scanning…';
          networkScanLog.textContent = 'Starting network scan...\n';
          
          // Call check.py
          fetch('/api/run-script.php?script=check.py', {
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
            .then(response => {
              if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
              }
              return response.text();
            })
            .then(data => {
              if (data.includes('Error:')) {
                throw new Error(data);
              }
              networkScanLog.textContent += data + '\n';
              networkScanStatus.textContent = ' ✅ Finished';
            })
            .catch(error => {
              console.error('Network scan error:', error);
              networkScanLog.textContent += 'Error: ' + error.message + '\n';
              networkScanStatus.textContent = ' ❌ Error';
            });
        };
      }

      // Port Scanner logic
      const runPortScanBtn = document.getElementById('runPortScan');
      const targetHostInput = document.getElementById('target-host');
      const portRangeInput = document.getElementById('port-range');
      const portScanLog = document.getElementById('port-scan-log');
      const portScanStatus = document.getElementById('port-scan-status');

      if (runPortScanBtn) {
        runPortScanBtn.onclick = () => {
          const host = targetHostInput.value;
          const range = portRangeInput.value;
          if (!host || !range) {
            portScanStatus.textContent = ' ❗ Please enter host and port range.';
            return;
          }
          portScanStatus.textContent = ' ⏳ Scanning…';
          portScanLog.textContent = 'Starting port scan...\n';
          
          // Call nmap.sh with parameters
          fetch(`/api/run-script.php?script=nmap.sh&host=${encodeURIComponent(host)}&range=${encodeURIComponent(range)}`, {
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
            .then(response => response.text())
            .then(data => {
              portScanLog.textContent += data + '\n';
              portScanStatus.textContent = ' ✅ Finished';
            })
            .catch(error => {
              portScanLog.textContent += 'Error: ' + error.message + '\n';
              portScanStatus.textContent = ' ❌ Error';
            });
        };
      }

      // DNS Lookup logic
      const runDnsLookupBtn = document.getElementById('runDnsLookup');
      const dnsDomainInput = document.getElementById('dns-domain');
      const dnsRecordTypeSelect = document.getElementById('dns-record-type');
      const dnsLookupLog = document.getElementById('dns-lookup-log');
      const dnsLookupStatus = document.getElementById('dns-lookup-status');

      if (runDnsLookupBtn) {
        runDnsLookupBtn.onclick = () => {
          const domain = dnsDomainInput.value;
          const recordType = dnsRecordTypeSelect.value;
          if (!domain) {
            dnsLookupStatus.textContent = ' ❗ Please enter a domain name.';
            return;
          }
          dnsLookupStatus.textContent = ' ⏳ Looking up…';
          dnsLookupLog.textContent = 'Starting DNS lookup...\n';
          
          // Use dig command for DNS lookup
          fetch(`/api/run-script.php?script=dig&domain=${encodeURIComponent(domain)}&type=${encodeURIComponent(recordType)}`, {
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
            .then(response => response.text())
            .then(data => {
              dnsLookupLog.textContent += data + '\n';
              dnsLookupStatus.textContent = ' ✅ Finished';
            })
            .catch(error => {
              dnsLookupLog.textContent += 'Error: ' + error.message + '\n';
              dnsLookupStatus.textContent = ' ❌ Error';
            });
        };
      }

      // --- Remember and restore last active tab using localStorage ---
      const tabKey = 'haxinator-last-tab';
      const tabTriggers = document.querySelectorAll('button[data-bs-toggle="tab"]');
      // Restore last active tab
      try {
        const lastTab = localStorage.getItem(tabKey);
        if (lastTab) {
          const trigger = document.querySelector(`button[data-bs-target='${lastTab}']`);
          if (trigger) {
            const tab = new bootstrap.Tab(trigger);
            tab.show();
          }
        }
      } catch (e) { /* Ignore errors, fallback to default */ }
      // Save tab on change
      tabTriggers.forEach(btn => {
        btn.addEventListener('shown.bs.tab', function (e) {
          try {
            localStorage.setItem(tabKey, this.getAttribute('data-bs-target'));
          } catch (e) { /* Ignore errors */ }
        });
      });
    });

    function shutdownSystem() {
        if (!confirm('Are you sure you want to shutdown the system?')) {
            return;
        }
        
        fetch('/api/shutdown.php', {
            method: 'POST',
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('System is shutting down...');
                setTimeout(() => {
                    window.location.href = '/';
                }, 3000);
            } else {
                alert('Failed to shutdown system: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to shutdown system');
        });
    }
    </script>
    </body>
    </html>
    <?php
    return ob_get_clean();
}
