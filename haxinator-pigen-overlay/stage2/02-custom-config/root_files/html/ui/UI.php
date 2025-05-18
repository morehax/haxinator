<?php
/**
 * UI rendering functions
 *
 * Moved from /var/www/html/ui.php to /var/www/html/ui/UI.php
 */

require_once __DIR__ . '/../data/Util.php';

// Get status information using the Util class
$public_ip = Util::getPublicIp();
$hostname = gethostname();
$dns_ok = Util::dnsResolvesGoogle();
$ping_ok = Util::pingGoogle();  // Store result

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
            <?php if (class_exists('CSRFProtection')): ?>
              <?= CSRFProtection::tokenField() ?>
            <?php endif; ?>
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

function renderMainPage($message, $error, $wifi_list, $saved_connections, $iface_status, $active_uuids, $unique_wifi_list, $public_ip, $dns_ok, $hostname, $tunnel_status = null)
{
    global $ping_ok;  // Add this line to make $ping_ok available inside the function
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
          background-color: #3a7cbd;
          font-family: 'Segoe UI', Arial, sans-serif;
          margin: 0;
          padding: 0;
          position: relative;
        }
        
        /* Add a pseudo-element for the gradient that covers the entire viewport */
        body::before {
          content: "";
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background: linear-gradient(135deg, #65ddb7 0%, #3a7cbd 100%);
          z-index: -1;
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
        }
        .interface-bar {
          background: #ffffff;
          padding: 0.7rem 1.2rem;
          border-bottom: 1px solid #e0e7ef;
          margin-bottom: 24px;
          display: flex;
          align-items: center;
          justify-content: center;
          flex-wrap: wrap;
          gap: 0.8rem;
          box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        }
        .interface-status {
          display: flex;
          align-items: center;
          gap: 0.8rem;
          flex-wrap: wrap;
          justify-content: center;
        }
        .interface-item {
          display: flex;
          align-items: center;
          gap: 0.4rem;
          font-size: 0.91rem;
          color: #1a365d;
          padding: 0.3rem 0.7rem;
          border-radius: 6px;
          background: #fff;
          border: 1px solid #e0e7ef;
          min-width: 140px;
          height: 36px;
          transition: all 0.2s ease;
          box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        }
        .interface-item:hover {
          border-color: #2563eb;
          box-shadow: 0 2px 4px rgba(37, 99, 235, 0.08);
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
          .interface-bar {
            padding: 0.5rem 1rem;
          }
          .interface-status {
            width: 100%;
            justify-content: center;
          }
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
        .status-group {
          display: flex;
          align-items: center;
          gap: 1.2rem;
        }
        .status-indicator {
          display: flex;
          flex-direction: column;
          align-items: center;
          line-height: 1;
        }
        .status-indicator i {
          font-size: 1.35em;
          margin-bottom: 2px;
          width: 1.4em;  /* Fixed width for all icons */
          height: 1.4em; /* Fixed height for all icons */
          display: flex;
          align-items: center;
          justify-content: center;
        }
        .status-indicator .status-label {
          font-size: 0.65em;
          color: #666;
          margin-top: 1px;
        }
        
        /* SSH Tunnel specific styles */
        .ssh-tunnel-card {
          background: rgba(255, 255, 255, 0.97);
          border-radius: 12px;
          box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1);
        }
        .ssh-tunnel-status {
          margin: 20px 0;
          padding: 10px;
          background-color: #e0e0e0;
          border-radius: 5px;
          transition: background-color 0.3s ease;
        }
        .ssh-tunnel-status.running {
          background-color: #d4edda;
          border-color: #c3e6cb;
          color: #155724;
        }
        .ssh-tunnel-status.running .server-info {
          color: #155724;
        }
        .ssh-tunnel-status .server-info {
          font-weight: 600;
          white-space: nowrap;
        }
        .ssh-tunnel-status.stopped {
          background-color: #e0e0e0;
          border-color: #d9d9d9;
          color: #666666;
        }
        .ssh-debug {
          margin: 20px 0;
          padding: 10px;
        }
        .ssh-config-inputs {
          display: flex;
          gap: 10px;
          align-items: center;
          flex-wrap: wrap;
        }
        .ssh-config-inputs label {
          margin-right: 5px;
          font-weight: bold;
        }
        .ssh-config-inputs input {
          padding: 5px;
          border: 1px solid #ccc;
          border-radius: 4px;
          font-size: 14px;
        }
        .ssh-config-inputs input[type="number"] {
          width: 80px;
        }
        .ssh-debug pre {
          font-size: 0.8rem;
          background: #f8f9fa;
          border: 1px solid #dee2e6;
          border-radius: 3px;
          padding: 0.5rem;
          max-height: 200px;
          overflow-y: auto;
          white-space: pre-wrap;
        }
        
        /* SSH Key Management Styles */
        .ssh-key-management {
          background-color: rgba(248, 249, 250, 0.5);
          border-radius: 5px;
          padding: 15px;
          border: 1px solid #e0e7ef;
        }
        
        .ssh-key-status {
          display: flex;
          align-items: center;
        }
        
        .ssh-key-management h5 {
          color: #1a365d;
          font-size: 1rem;
          margin-bottom: 12px;
        }
        .start-tunnel-btn {
          background: #22c55e;
          color: white;
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
        .start-tunnel-btn:hover {
          background: #15803d;
          color: white;
          box-shadow: 0 2px 8px rgba(34,197,94,0.13);
        }
        .stop-tunnel-btn {
          background: #ef4444;
          color: white;
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
        .stop-tunnel-btn:hover {
          background: #b91c1c;
          color: white;
          box-shadow: 0 2px 8px rgba(239,68,68,0.13);
        }
        .start-tunnel-btn:disabled, .stop-tunnel-btn:disabled {
          background: #d1d5db;
          color: #9ca3af;
          box-shadow: none;
          cursor: not-allowed;
        }
        .tunnel-heading {
          font-size: 1rem;
          margin-bottom: 12px;
        }
      </style>
    </head>
    <body>
    <div class="topbar sticky-top">
      <div class="topbar-left">
        <div class="topbar-logo">X</div>
        <h1 class="topbar-title">Haxinator 2000</h1>
      </div>
      <div class="topbar-center">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-hdd-network"></i>
          <span><?= htmlspecialchars($hostname) ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <div class="status-group">
          <?php if ($ping_ok): ?>
            <span title="Ping to 8.8.8.8 successful" class="status-indicator">
              <i class="bi bi-door-open" style="color:#22c55e;"></i>
              <span class="status-label">ping</span>
            </span>
          <?php else: ?>
            <span title="Ping to 8.8.8.8 failed" class="status-indicator">
              <i class="bi bi-door-closed" style="color:#f4427d;"></i>
              <span class="status-label">ping</span>
            </span>
          <?php endif; ?>
          <?php if ($public_ip): ?>
            <span title="Internet Connected" class="status-indicator">
              <i class="bi bi-globe2" style="color:#22c55e;"></i>
              <span class="status-label">web</span>
            </span>
          <?php else: ?>
            <span title="No Internet" class="status-indicator">
              <i class="bi bi-globe2" style="color:#f59e42;"></i>
              <span class="status-label">web</span>
            </span>
          <?php endif; ?>
          <?php if ($dns_ok): ?>
            <span title="DNS resolves google.com" class="status-indicator">
              <i class="bi bi-plugin" style="color:#22c55e;"></i>
              <span class="status-label">dns</span>
            </span>
          <?php else: ?>
            <span title="DNS does not resolve google.com" class="status-indicator">
              <i class="bi bi-plug" style="color:#f4427d;"></i>
              <span class="status-label">dns</span>
            </span>
          <?php endif; ?>
        </div>
        <a href="https://<?php echo $_SERVER['SERVER_ADDR']; ?>:4200" target="_blank" class="btn btn-outline-secondary btn-sm ms-3 btn-icon"><i class="bi bi-terminal"></i> <span class="d-none d-md-inline">Terminal</span></a>
        <a href="/configure.php" class="btn btn-outline-secondary btn-sm ms-2 btn-icon"><i class="bi bi-gear"></i> <span class="d-none d-md-inline">Configure</span></a>
        <button onclick="shutdownSystem()" class="btn btn-outline-danger btn-sm ms-2 btn-icon"><i class="bi bi-power"></i> <span class="d-none d-md-inline">Shutdown</span></button>
        <form method="get" class="d-inline ms-2">
          <button type="submit" name="logout" class="btn btn-outline-secondary btn-sm btn-icon"><i class="bi bi-box-arrow-right"></i> <span class="d-none d-md-inline">Logout</span></button>
        </form>
      </div>
    </div>

    <div class="interface-bar">
      <div class="interface-status">
        <?php 
        try {
          $interfaces = $data->getTopBarInterfaces();
          // Add public IP as external interface if online
          if ($public_ip) {
            $interfaces[] = [
              'name' => 'ext',
              'connected' => true,
              'icon' => 'bi-globe',
              'ipv4' => $public_ip
            ];
          }
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

    <div class="nm-main-container mx-auto px-3 px-md-4" style="max-width:1100px; margin-bottom: 40px;">
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
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="tunnel-tab" data-bs-toggle="tab" data-bs-target="#tunnelTab" type="button" role="tab" aria-controls="tunnelTab" aria-selected="false"><i class="bi bi-shield-lock" style="font-size:0.91em;"></i> SSH Tunnel</button>
        </li>
      </ul>

      <div class="tab-content" id="managerTabsContent">
        <div class="tab-pane fade show active" id="wifiTab" role="tabpanel" aria-labelledby="wifi-tab">
          <div class="card nm-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span>Wi-Fi Networks</span>
              <form method="post" class="m-0">
                <?= CSRFProtection::tokenField() ?>
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
                      <th class="action-cell">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($wifi_list)): ?>
                    <tr><td colspan="4" class="text-muted">No networks found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($wifi_list as $net):
                      // $ssid_display is not used, so removing
                      $bssid_display = '<div class="bssid-sub">' . htmlspecialchars($net['bssid']) . '</div>';
                      $signalPercent = intval($net['signal']);
                      $signalColor = $signalPercent >= 75 ? 'bg-success' :
                                     ($signalPercent >= 50 ? 'bg-warning' : 'bg-danger');
                    ?>
                      <tr <?= $net['in_use'] ? 'class="table-success"' : '' ?>>
                        <td class="ssid-cell">
                          <span class="text-title"><?= htmlspecialchars($net['ssid']) ?></span>
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
                              <?= CSRFProtection::tokenField() ?>
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
                        <td class="name-cell">
                          <span class="text-title"><?= htmlspecialchars($conn['name']) ?></span>
                          <div class="uuid-sub"><?= htmlspecialchars($conn['uuid']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($conn['type']) ?></td>
                        <td><?= $conn['device'] ? htmlspecialchars($conn['device']) : '-' ?></td>
                        <td class="nm-priority-cell">
                          <form method="post" class="priority-form" data-uuid="<?= htmlspecialchars($conn['uuid']) ?>">
                            <input type="hidden" name="update_priority" value="1">
                            <input type="hidden" name="connection_uuid" value="<?= htmlspecialchars($conn['uuid']) ?>">
                            <?= CSRFProtection::tokenField() ?>
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
                                <?= CSRFProtection::tokenField() ?>
                                <button type="submit" class="btn btn-warning nm-btn nm-btn-sm" title="Disconnect">
                                  <i class="bi bi-plug-fill" style="transform: rotate(180deg);"></i>
                                </button>
                              </form>
                            <?php else: ?>
                              <form method="post" class="m-0" style="display:inline;">
                                <input type="hidden" name="activate_connection" value="<?= htmlspecialchars($conn['uuid']) ?>">
                                <?= CSRFProtection::tokenField() ?>
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
                              <?= CSRFProtection::tokenField() ?>
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
                <?= CSRFProtection::tokenField() ?>
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
                      <td class="name-cell text-title">
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

        <!-- SSH Tunnel Tab -->
        <div class="tab-pane fade" id="tunnelTab" role="tabpanel" aria-labelledby="tunnel-tab">
          <div class="card nm-card mb-4">
            <div class="card-header">SSH Tunnel Management</div>
            <div class="card-body p-3">
              <!-- SSH Tunnel Status -->
              <div class="ssh-tunnel-status d-flex justify-content-between align-items-center <?= isset($tunnel_status) && $tunnel_status['status'] == 'Running' ? 'running' : 'stopped' ?>">
                <div>
                  <strong>Status:</strong> <span id="tunnel-status"><?= isset($tunnel_status) ? htmlspecialchars($tunnel_status['status']) : 'Unknown' ?></span>
                  <?php if (isset($tunnel_status) && $tunnel_status['status'] == 'Running' && isset($tunnel_status['server']) && !empty($tunnel_status['server'])): ?>
                    <span class="ms-2">→</span> <span class="server-info"><?= htmlspecialchars($tunnel_status['server']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                  <span class="badge <?= isset($tunnel_status) && $tunnel_status['port_listening'] ? 'bg-success' : 'bg-secondary' ?>">
                    SOCKS Proxy: 0.0.0.0:8080
                    <?php if (isset($tunnel_status) && $tunnel_status['port_listening']): ?>
                      <i class="bi bi-check-circle-fill ms-1"></i>
                    <?php else: ?>
                      <i class="bi bi-x-circle-fill ms-1"></i>
                    <?php endif; ?>
                  </span>
                </div>
              </div>
              
              <!-- SSH Key Management Section -->
              <div class="ssh-key-management mb-4 mt-3">
                <h5 class="mb-2">SSH Key Management</h5>
                <div class="d-flex gap-2 align-items-center">
                  <?php if (isset($tunnel_status['public_key']) && $tunnel_status['public_key']['status']): ?>
                    <div class="ssh-key-status">
                      <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> SSH keys present</span>
                    </div>
                    <a href="?download_ssh_key=1" class="btn btn-outline-primary btn-sm">
                      <i class="bi bi-download me-1"></i> Download Public Key
                    </a>
                  <?php else: ?>
                    <div class="ssh-key-status">
                      <span class="badge bg-warning"><i class="bi bi-exclamation-triangle me-1"></i> No SSH keys found</span>
                    </div>
                  <?php endif; ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="tunnel_action" value="regenerate_ssh_keys">
                    <?= CSRFProtection::tokenField() ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm" <?= isset($tunnel_status) && $tunnel_status['status'] == 'Running' ? 'disabled' : '' ?>>
                      <i class="bi bi-key me-1"></i> Regenerate SSH Keys
                    </button>
                  </form>
                </div>
                <div class="mt-2 small text-muted">
                  SSH keys are used to establish secure connections to remote servers. Regenerating keys will invalidate any existing connections.
                </div>
              </div>

              <!-- SSH Tunnel Configuration Form -->
              <form method="post" id="tunnel-form">
                <div class="ssh-config">
                  <h5 class="mt-3 mb-2">SSH Server Configuration</h5>
                  <div class="ssh-config-inputs mb-3">
                    <input type="hidden" name="tunnel_action" id="tunnel_action" value="">
                    <?= CSRFProtection::tokenField() ?>
                    <div class="me-2">
                      <label for="ssh_username">Username:</label>
                      <input type="text" id="ssh_username" name="ssh_username" class="form-control" 
                             value="<?= isset($_SESSION['ssh_tunnel']['username']) ? htmlspecialchars($_SESSION['ssh_tunnel']['username']) : (isset($_POST['ssh_username']) ? htmlspecialchars($_POST['ssh_username']) : '') ?>" 
                             placeholder="e.g., root">
                    </div>
                    <div class="me-2">
                      <label for="ssh_ip">IP Address:</label>
                      <input type="text" id="ssh_ip" name="ssh_ip" class="form-control" 
                             value="<?= isset($_SESSION['ssh_tunnel']['ip']) ? htmlspecialchars($_SESSION['ssh_tunnel']['ip']) : (isset($_POST['ssh_ip']) ? htmlspecialchars($_POST['ssh_ip']) : '') ?>" 
                             placeholder="e.g., 143.198.29.141">
                    </div>
                    <div class="me-2">
                      <label for="ssh_port">Port:</label>
                      <input type="number" id="ssh_port" name="ssh_port" class="form-control" 
                             value="<?= isset($_SESSION['ssh_tunnel']['port']) ? htmlspecialchars($_SESSION['ssh_tunnel']['port']) : (isset($_POST['ssh_port']) ? htmlspecialchars($_POST['ssh_port']) : '22') ?>" 
                             placeholder="22" min="1" max="65535">
                    </div>
                  </div>
                  <div class="controls d-flex gap-2 mb-3">
                    <button type="button" id="start-tunnel-btn" class="btn start-tunnel-btn" 
                            <?= isset($tunnel_status) && $tunnel_status['status'] == 'Running' ? 'disabled' : '' ?>>
                      <i class="bi bi-play-circle me-1"></i> Start Tunnel
                    </button>
                    <button type="button" id="stop-tunnel-btn" class="btn stop-tunnel-btn" 
                            <?= isset($tunnel_status) && $tunnel_status['status'] != 'Running' ? 'disabled' : '' ?>>
                      <i class="bi bi-stop-circle me-1"></i> Stop Tunnel
                    </button>
                  </div>
                </div>
              </form>
              
              <!-- Debug Information -->
              <div class="ssh-debug">
                <h5 class="mb-2">Debug Information</h5>
                <pre id="ssh-debug-output"><?= isset($tunnel_status) && isset($tunnel_status['debug']) ? htmlspecialchars($tunnel_status['debug']) : 'Configure SSH settings to see debug information.' ?></pre>
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
              <?= CSRFProtection::tokenField() ?>
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
              <?= CSRFProtection::tokenField() ?>
              <button type="submit" class="btn btn-primary">Connect</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Keep all modals outside the tab content -->
    <!-- Tunnel Connection Modal -->
    <div class="modal fade" id="tunnelConnectModal" tabindex="-1" aria-labelledby="tunnelConnectModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-body text-center p-4">
            <div class="spinner-border text-primary mb-3" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mb-0">Establishing SSH tunnel connection...</p>
            <small class="text-muted">This may take a few moments</small>
          </div>
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
        .then(resp => {
          if (!resp.ok) {
            if (resp.status === 403) {
              throw new Error('CSRF token validation failed (403 Forbidden)');
            }
            throw new Error('Server error: ' + resp.status);
          }
          return resp.text();
        })
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
            feedback.innerHTML = '<div class="alert alert-danger">Unknown error. Server response: ' + html.substring(0, 100) + '...</div>';
          }
        })
        .catch((error) => {
          feedback.innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
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

      // SSH Tunnel functionality
      const startTunnelBtn = document.getElementById('start-tunnel-btn');
      const stopTunnelBtn = document.getElementById('stop-tunnel-btn');
      const tunnelForm = document.getElementById('tunnel-form');
      const tunnelAction = document.getElementById('tunnel_action');
      const tunnelStatusBar = document.querySelector('.ssh-tunnel-status');
      const tunnelStatusText = document.getElementById('tunnel-status');
      let tunnelConnectModal;

      if (startTunnelBtn && stopTunnelBtn && tunnelForm) {
        // Initialize the modal
        tunnelConnectModal = new bootstrap.Modal(document.getElementById('tunnelConnectModal'), {
          backdrop: 'static',
          keyboard: false
        });

        startTunnelBtn.addEventListener('click', function() {
          tunnelAction.value = 'start_tunnel';
          tunnelConnectModal.show();
          tunnelForm.submit();
        });

        stopTunnelBtn.addEventListener('click', function() {
          tunnelAction.value = 'stop_tunnel';
          tunnelForm.submit();
        });
        
        // Update status bar based on status text
        if (tunnelStatusText && tunnelStatusBar) {
          const status = tunnelStatusText.textContent.trim();
          if (status === 'Running') {
            tunnelStatusBar.classList.add('running');
            tunnelStatusBar.classList.remove('stopped');
            // Hide the modal if it's showing and tunnel is running
            if (tunnelConnectModal) {
              tunnelConnectModal.hide();
            }
          } else {
            tunnelStatusBar.classList.add('stopped');
            tunnelStatusBar.classList.remove('running');
          }
        }
      }
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
