<?php
/**
 * Wi-Fi Connect Form Page
 * Displays a form to enter a password (if needed) before connecting to a Wi-Fi network
 *
 * Moved from /var/www/html/wifi_connect.php to /var/www/html/ui/wifi_connect.php
 * Now referencing the refactored config/auth.
 */

session_start();

// Include config and authentication
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth/Auth.php';

// Ensure user is authenticated
$auth = new Auth($config['username'], $config['password']);
if (!$auth->isLoggedIn()) {
    header("Location: /index.php");
    exit;
}

// Ensure SSID and security are provided
if (!isset($_GET['ssid']) || !isset($_GET['security'])) {
    header("Location: /index.php");
    exit;
}

$ssid = $_GET['ssid'];
$security = $_GET['security'];
$needsPassword = stripos($security, 'WPA') !== false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Connect to Wi-Fi</title>
  <link href="/css/bootstrap.min.css" rel="stylesheet" />
  <link href="/css/theme.css" rel="stylesheet" />
</head>
<body class="p-4 bg-light">
  <div class="card card-connect mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Connect to <?= htmlspecialchars($ssid) ?></span>
      <a href="/index.php" class="btn btn-back">Back</a>
    </div>
    <div class="card-body">
      <form method="post" action="/index.php">
        <input type="hidden" name="connect_ssid" value="<?= htmlspecialchars($ssid) ?>">
        <?php if ($needsPassword): ?>
          <div class="mb-3">
            <label for="wifi_password" class="form-label">Wi-Fi Password</label>
            <input type="password" class="form-control" id="wifi_password" name="wifi_password" placeholder="Enter password" required>
          </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-100">Connect</button>
      </form>
    </div>
  </div>
</body>
</html>
