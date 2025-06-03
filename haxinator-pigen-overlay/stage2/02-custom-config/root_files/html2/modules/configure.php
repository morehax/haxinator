<?php
declare(strict_types=1);

/**
 * Configure Module
 * File upload and configuration management
 */

// Module metadata
$module = [
    'id' => 'configure',
    'title' => 'Configure',
    'icon' => 'gear',
    'description' => 'Upload configuration files and manage system settings',
    'category' => 'system'
];

// If this is being included for metadata discovery, return early
if (!defined('EMBEDDED_MODULE') && !defined('MODULE_POST_HANDLER')) {
    return;
}

if (!session_id()) session_start();
header('X-Content-Type-Options: nosniff');

/* -------- helpers ------------------------------------------------- */
if (!function_exists('csrf')) {
    function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
}
if (!function_exists('json_out')) {
    function json_out(array $p,int $c=200):never{http_response_code($c);header('Content-Type: application/json');echo json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
}
if (!function_exists('bad')) {
    function bad(string $m,int $c=400):never{json_out(['error'=>$m],$c);}
}

/* -------- Network Configuration Functions -------------------- */
if (!function_exists('parseEnvFile')) {
    function parseEnvFile($filepath) {
        $env = [];
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return $env;
        }
        
        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $env;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            if (!preg_match('/^[A-Z_]+=.+$/', $line)) {
                continue;
            }
            
            list($key, $value) = explode('=', $line, 2);
            $env[$key] = trim($value, '"\'');
        }
        
        return $env;
    }
}

if (!function_exists('detectConfigurations')) {
    function detectConfigurations($env) {
        $configs = [];
        
        // OpenVPN Detection
        $openvpnParams = ['VPN_USER', 'VPN_PASS'];
        $openvpnFound = array_filter($openvpnParams, fn($key) => isset($env[$key]));
        if (count($openvpnFound) > 0) {
            $ovpnFile = '/var/www/VPN.ovpn';
            $fileReady = file_exists($ovpnFile) && is_readable($ovpnFile);
            $paramsComplete = count($openvpnFound) === 2;
            
            $configs['openvpn'] = [
                'name' => 'OpenVPN',
                'ready' => $paramsComplete && $fileReady,
                'status' => getConnectionStatus($paramsComplete, $fileReady),
                'found_params' => $openvpnFound,
                'missing_params' => array_diff($openvpnParams, $openvpnFound),
                'file_status' => $fileReady ? 'found' : 'missing',
                'file_name' => 'VPN.ovpn',
                'icon' => 'shield-lock',
                'description' => 'Secure VPN tunnel connection'
            ];
        }
        
        // Iodine Detection
        $iodineRequired = ['IODINE_TOPDOMAIN', 'IODINE_NAMESERVER', 'IODINE_PASS'];
        $iodineOptional = ['IODINE_MTU', 'IODINE_LAZY', 'IODINE_INTERVAL'];
        $iodineFound = array_filter($iodineRequired, fn($key) => isset($env[$key]));
        if (count($iodineFound) > 0) {
            $paramsComplete = count($iodineFound) === 3;
            
            $configs['iodine'] = [
                'name' => 'Iodine DNS Tunnel',
                'ready' => $paramsComplete,
                'status' => $paramsComplete ? 'ready' : 'incomplete',
                'found_params' => $iodineFound,
                'missing_params' => array_diff($iodineRequired, $iodineFound),
                'optional_params' => array_filter($iodineOptional, fn($key) => isset($env[$key])),
                'icon' => 'dns',
                'description' => 'DNS tunneling for restricted networks'
            ];
        }
        
        // Hans Detection
        $hansParams = ['HANS_SERVER', 'HANS_PASSWORD'];
        $hansFound = array_filter($hansParams, fn($key) => isset($env[$key]));
        if (count($hansFound) > 0) {
            $paramsComplete = count($hansFound) === 2;
            
            $configs['hans'] = [
                'name' => 'Hans ICMP VPN',
                'ready' => $paramsComplete,
                'status' => $paramsComplete ? 'ready' : 'incomplete',
                'found_params' => $hansFound,
                'missing_params' => array_diff($hansParams, $hansFound),
                'icon' => 'router',
                'description' => 'ICMP tunnel for covert communication'
            ];
        }
        
        // WiFi AP Detection
        $wifiParams = ['WIFI_SSID', 'WIFI_PASSWORD'];
        $wifiFound = array_filter($wifiParams, fn($key) => isset($env[$key]));
        if (count($wifiFound) > 0) {
            $paramsComplete = count($wifiFound) === 2;
            
            $configs['wifi_ap'] = [
                'name' => 'WiFi Access Point',
                'ready' => $paramsComplete,
                'status' => $paramsComplete ? 'ready' : 'incomplete',
                'found_params' => $wifiFound,
                'missing_params' => array_diff($wifiParams, $wifiFound),
                'icon' => 'wifi',
                'description' => 'Wireless hotspot for device sharing'
            ];
        }
        
        return $configs;
    }
}

if (!function_exists('getConnectionStatus')) {
    function getConnectionStatus($paramsComplete, $fileReady = true) {
        if ($paramsComplete && $fileReady) return 'ready';
        if ($paramsComplete && !$fileReady) return 'missing_file';
        if (!$paramsComplete && $fileReady) return 'incomplete';
        return 'incomplete';
    }
}

if (!function_exists('validateParameters')) {
    function validateParameters($env, $configs) {
        $errors = [];
        
        foreach ($configs as $type => $config) {
            if (!$config['ready']) continue;
            
            switch ($type) {
                case 'openvpn':
                    if (empty($env['VPN_USER'])) {
                        $errors[$type][] = 'VPN_USER is required';
                    }
                    if (strlen($env['VPN_PASS']) < 6) {
                        $errors[$type][] = 'VPN_PASS must be at least 6 characters';
                    }
                    break;
                    
                case 'iodine':
                    if (!filter_var($env['IODINE_TOPDOMAIN'], FILTER_VALIDATE_DOMAIN)) {
                        $errors[$type][] = 'IODINE_TOPDOMAIN must be a valid domain';
                    }
                    if (!filter_var($env['IODINE_NAMESERVER'], FILTER_VALIDATE_IP) && 
                        !filter_var($env['IODINE_NAMESERVER'], FILTER_VALIDATE_DOMAIN)) {
                        $errors[$type][] = 'IODINE_NAMESERVER must be a valid IP or domain';
                    }
                    if (strlen($env['IODINE_PASS']) < 4) {
                        $errors[$type][] = 'IODINE_PASS must be at least 4 characters';
                    }
                    if (isset($env['IODINE_MTU'])) {
                        $mtu = (int)$env['IODINE_MTU'];
                        if ($mtu < 500 || $mtu > 1500) {
                            $errors[$type][] = 'IODINE_MTU must be between 500-1500';
                        }
                    }
                    break;
                    
                case 'hans':
                    if (!filter_var($env['HANS_SERVER'], FILTER_VALIDATE_IP) && 
                        !filter_var($env['HANS_SERVER'], FILTER_VALIDATE_DOMAIN)) {
                        $errors[$type][] = 'HANS_SERVER must be a valid IP or domain';
                    }
                    if (strlen($env['HANS_PASSWORD']) < 4) {
                        $errors[$type][] = 'HANS_PASSWORD must be at least 4 characters';
                    }
                    break;
                    
                case 'wifi_ap':
                    if (strlen($env['WIFI_SSID']) < 1 || strlen($env['WIFI_SSID']) > 32) {
                        $errors[$type][] = 'WIFI_SSID must be 1-32 characters';
                    }
                    if (!preg_match('/^[a-zA-Z0-9_\-\s]+$/', $env['WIFI_SSID'])) {
                        $errors[$type][] = 'WIFI_SSID contains invalid characters';
                    }
                    if (strlen($env['WIFI_PASSWORD']) < 8 || strlen($env['WIFI_PASSWORD']) > 63) {
                        $errors[$type][] = 'WIFI_PASSWORD must be 8-63 characters';
                    }
                    break;
            }
        }
        
        return $errors;
    }
}

if (!function_exists('applyNetworkConfiguration')) {
    function applyNetworkConfiguration($type, $env) {
        $output = [];
        $errors = [];
        
        switch ($type) {
            case 'openvpn':
                exec('nmcli connection delete openvpn-udp 2>/dev/null');
                exec('nmcli connection delete VPN 2>/dev/null');
                
                exec('nmcli connection import type openvpn file /var/www/VPN.ovpn 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to import OpenVPN configuration: ' . implode(' ', $output));
                }
                
                // Rename the imported connection to openvpn-udp for consistency
                exec('nmcli connection modify VPN connection.id openvpn-udp 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to rename OpenVPN connection');
                }
                
                exec('nmcli connection modify openvpn-udp +vpn.data username="' . $env['VPN_USER'] . '" +vpn.data password-flags=0 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to set OpenVPN username');
                }
                
                exec('nmcli connection modify openvpn-udp vpn.secrets "password=' . $env['VPN_PASS'] . '" 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to set OpenVPN password');
                }
                break;
                
            case 'iodine':
                $mtu = $env['IODINE_MTU'] ?? '1400';
                $lazy = $env['IODINE_LAZY'] ?? 'true';
                $interval = $env['IODINE_INTERVAL'] ?? '4';
                
                exec('nmcli connection delete iodine-vpn 2>/dev/null');
                
                exec('nmcli connection add type vpn ifname iodine0 con-name iodine-vpn vpn-type iodine 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to create Iodine connection');
                }
                
                $vpnData = sprintf('topdomain = %s, nameserver = %s, password = %s, mtu = %s, lazy-mode = %s, interval = %s',
                    $env['IODINE_TOPDOMAIN'], $env['IODINE_NAMESERVER'], $env['IODINE_PASS'], $mtu, $lazy, $interval);
                
                exec('nmcli connection modify iodine-vpn vpn.data "' . $vpnData . '" 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to configure Iodine connection');
                }
                
                exec('nmcli connection modify iodine-vpn vpn.secrets "password=' . $env['IODINE_PASS'] . '" 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to set Iodine password');
                }
                break;
                
            case 'hans':
                exec('nmcli connection delete hans-icmp-vpn 2>/dev/null');
                
                exec('nmcli connection add type vpn con-name hans-icmp-vpn ifname tun0 vpn-type org.freedesktop.NetworkManager.hans 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to create Hans connection');
                }
                
                exec('nmcli connection modify hans-icmp-vpn vpn.data "server=' . $env['HANS_SERVER'] . ', password=' . $env['HANS_PASSWORD'] . ', password-flags=1" 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to configure Hans connection');
                }
                break;
                
            case 'wifi_ap':
                exec('nmcli connection delete pi_hotspot 2>/dev/null');
                
                exec('nmcli con add type wifi ifname wlan0 con-name pi_hotspot autoconnect yes ssid "' . $env['WIFI_SSID'] . '" 2>&1', $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to create WiFi AP connection');
                }
                
                $wifiCmd = 'nmcli con mod pi_hotspot ' .
                    '802-11-wireless.mode ap ' .
                    '802-11-wireless.band bg ' .
                    'wifi-sec.key-mgmt wpa-psk ' .
                    'wifi-sec.psk "' . $env['WIFI_PASSWORD'] . '" ' .
                    'ipv4.addresses 192.168.4.1/24 ' .
                    'ipv4.method shared ' .
                    'ipv4.never-default yes ' .
                    'ipv6.method ignore 2>&1';
                
                exec($wifiCmd, $output, $retval);
                if ($retval !== 0) {
                    throw new Exception('Failed to configure WiFi AP settings');
                }
                break;
        }
    }
}

/* -------- AJAX ---------------------------------------------------- */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){
  if(($_POST['csrf']??'')!==csrf()) bad('Invalid CSRF',403);
  $act=$_POST['action'];

  /* ---------- file upload ----------------------------------------- */
  if($act==='upload'){
    $type = $_POST['type'] ?? '';
    
    if(!in_array($type, ['passwords', 'env-secrets', 'vpn'])) {
        bad('Invalid upload type');
    }
    
    if(!isset($_FILES['file'])) {
        bad('No file uploaded');
    }
    
    $file = $_FILES['file'];
    $uploadDir = '/var/www/';
    
    // Define target filenames
    $targetFiles = [
        'passwords' => 'password.txt',
        'env-secrets' => 'env-secrets', 
        'vpn' => 'VPN.ovpn'
    ];
    
    $targetFile = $uploadDir . $targetFiles[$type];
    
    // Check file size limits
    $maxSizes = [
        'passwords' => 5 * 1024 * 1024, // 5MB
        'env-secrets' => 1 * 1024 * 1024, // 1MB
        'vpn' => 1 * 1024 * 1024 // 1MB
    ];
    
    if($file['size'] > $maxSizes[$type]) {
        bad('File too large');
    }
    
    // Check for upload errors
    if($file['error'] !== UPLOAD_ERR_OK) {
        bad('Upload failed');
    }
    
    // Move uploaded file (will overwrite existing)
    if(move_uploaded_file($file['tmp_name'], $targetFile)) {
        // Set appropriate permissions
        chmod($targetFile, 0644);
        json_out(['success' => true, 'message' => 'File uploaded successfully']);
    } else {
        bad('Failed to save file');
    }
  }

  /* ---------- view file ------------------------------------------- */
  if($act==='view'){
    $type = $_POST['type'] ?? '';
    
    if(!in_array($type, ['passwords', 'env-secrets', 'vpn'])) {
        bad('Invalid file type');
    }
    
    $files = [
        'passwords' => '/var/www/password.txt',
        'env-secrets' => '/var/www/env-secrets',
        'vpn' => '/var/www/VPN.ovpn'
    ];
    
    $filepath = $files[$type];
    
    if(!file_exists($filepath)) {
        bad('File does not exist');
    }
    
    if(!is_readable($filepath)) {
        bad('File not readable');
    }
    
    $content = file_get_contents($filepath);
    if($content === false) {
        bad('Failed to read file');
    }
    
    json_out(['success' => true, 'content' => $content, 'size' => filesize($filepath)]);
  }

  /* ---------- apply network configuration ----------------------- */
  if($act==='apply_config'){
    try {
      $envFile = '/var/www/env-secrets';
      if (!is_readable($envFile)) {
          bad('env-secrets file not found or not readable');
      }
      
      $env = parseEnvFile($envFile);
      if (empty($env)) {
          bad('No valid configuration found in env-secrets file');
      }
      
      $configs = detectConfigurations($env);
      if (empty($configs)) {
          bad('No network configurations detected');
      }
      
      // Get selected configurations
      $selectedConfigs = [];
      foreach ($configs as $type => $config) {
          if ($config['ready'] && isset($_POST["enable_$type"])) {
              $selectedConfigs[$type] = $config;
          }
      }
      
      if (empty($selectedConfigs)) {
          bad('No configurations selected for application');
      }
      
      // Validate all selected configurations
      $validationErrors = validateParameters($env, $selectedConfigs);
      if (!empty($validationErrors)) {
          $errorMsg = [];
          foreach ($validationErrors as $type => $errors) {
              $errorMsg[] = $configs[$type]['name'] . ': ' . implode(', ', $errors);
          }
          bad('Validation failed: ' . implode('; ', $errorMsg));
      }
      
      // Apply configurations
      $results = [];
      foreach ($selectedConfigs as $type => $config) {
          try {
              applyNetworkConfiguration($type, $env);
              $results[] = $config['name'] . ' configured successfully';
          } catch (Exception $e) {
              throw new Exception($config['name'] . ' failed: ' . $e->getMessage());
          }
      }
      
      json_out(['success' => true, 'results' => $results]);
      
    } catch (Exception $e) {
        bad($e->getMessage());
    }
  }

  bad('Unknown action');
}

/* -------- file status helper ------------------------------------ */
if (!function_exists('getFileStatus')) {
    function getFileStatus($filename) {
        $filepath = '/var/www/' . $filename;
        return [
            'exists' => file_exists($filepath),
            'size' => file_exists($filepath) ? filesize($filepath) : 0,
            'modified' => file_exists($filepath) ? date('Y-m-d H:i', filemtime($filepath)) : null
        ];
    }
}

$fileStatuses = [
    'passwords' => getFileStatus('password.txt'),
    'env-secrets' => getFileStatus('env-secrets'),
    'vpn' => getFileStatus('VPN.ovpn')
];

// Get detected network configurations if env-secrets file exists
$networkConfigs = [];
$envFile = '/var/www/env-secrets';
if (file_exists($envFile) && is_readable($envFile)) {
    $env = parseEnvFile($envFile);
    if (!empty($env)) {
        $networkConfigs = detectConfigurations($env);
    }
}

$csrf=csrf();
?>
<script>
// Set CSRF token for embedded mode
if (typeof document !== 'undefined' && !document.querySelector('meta[name="csrf-token"]')) {
  const meta = document.createElement('meta');
  meta.name = 'csrf-token';
  meta.content = '<?=htmlspecialchars($csrf)?>';
  document.head.appendChild(meta);
}
</script>

<h3 class="mb-3">Configuration Manager</h3>

<div class="row g-4">
  <!-- Password Files -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>Password Files
          </h5>
          <small class="text-muted">Upload custom password lists for network operations</small>
        </div>
        <div class="d-flex align-items-center gap-2">
          <?php if($fileStatuses['passwords']['exists']): ?>
            <span class="badge bg-success">File Present</span>
            <button class="btn btn-outline-primary btn-sm" onclick="viewFile('passwords')">
              <i class="bi bi-eye"></i> View
            </button>
          <?php else: ?>
            <span class="badge bg-secondary">No File</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <div class="upload-zone" data-type="passwords" id="passwordUpload">
          <div class="upload-content">
            <i class="bi bi-file-earmark-text mb-2" style="font-size: 2rem; color: #6c757d;"></i>
            <div class="upload-text">Drop your password file here or click to browse</div>
            <div class="upload-info">(Supported formats: .txt, .lst, .dict, max 5MB)</div>
          </div>
          <div class="upload-progress" style="display: none;">
            <div class="progress" style="height: 10px;">
              <div class="progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
            <div class="upload-status mt-2"></div>
          </div>
          <input type="file" class="file-input" accept=".txt,.lst,.dict" style="display: none;">
        </div>
      </div>
    </div>
  </div>

  <!-- Environment Secrets -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0">
            <i class="bi bi-key me-2"></i>Environment Secrets
          </h5>
          <small class="text-muted">Configuration file for VPN, DNS tunnel, and WiFi credentials</small>
        </div>
        <div class="d-flex align-items-center gap-2">
          <?php if($fileStatuses['env-secrets']['exists']): ?>
            <span class="badge bg-success">File Present</span>
            <button class="btn btn-outline-primary btn-sm" onclick="viewFile('env-secrets')">
              <i class="bi bi-eye"></i> View
            </button>
          <?php else: ?>
            <span class="badge bg-secondary">No File</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <div class="upload-zone" data-type="env-secrets" id="envSecretsUpload">
          <div class="upload-content">
            <i class="bi bi-key mb-2" style="font-size: 2rem; color: #6c757d;"></i>
            <div class="upload-text">Drop your env-secrets file here or click to browse</div>
            <div class="upload-info">(File must be named "env-secrets" or "env-secrets.txt", max 1MB)</div>
          </div>
          <div class="upload-progress" style="display: none;">
            <div class="progress" style="height: 10px;">
              <div class="progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
            <div class="upload-status mt-2"></div>
          </div>
          <input type="file" class="file-input" accept=".txt,text/plain" style="display: none;">
        </div>
      </div>
    </div>
  </div>

  <!-- Network Configurations -->
  <?php if (!empty($networkConfigs)): ?>
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0">
            <i class="bi bi-diagram-3 me-2"></i>Network Configurations
          </h5>
          <small class="text-muted">Detected network connections from env-secrets file</small>
        </div>
        <div class="d-flex align-items-center gap-2">
          <?php 
          $readyCount = count(array_filter($networkConfigs, fn($c) => $c['ready']));
          $totalCount = count($networkConfigs);
          ?>
          <span class="badge bg-info"><?= $readyCount ?>/<?= $totalCount ?> Ready</span>
          <?php if ($readyCount > 0): ?>
            <button class="btn btn-success btn-sm" onclick="applyNetworkConfigurations()">
              <i class="bi bi-gear"></i> Apply Configurations
            </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3" id="networkConfigGrid">
          <?php foreach ($networkConfigs as $type => $config): ?>
          <div class="col-md-6">
            <div class="card border-<?= $config['status'] === 'ready' ? 'success' : ($config['status'] === 'incomplete' ? 'warning' : 'secondary') ?>">
              <div class="card-header bg-<?= $config['status'] === 'ready' ? 'success' : ($config['status'] === 'incomplete' ? 'warning' : 'secondary') ?> text-<?= $config['status'] === 'warning' ? 'dark' : 'white' ?>">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <h6 class="mb-0">
                      <i class="bi bi-<?= $config['icon'] ?> me-2"></i><?= htmlspecialchars($config['name']) ?>
                    </h6>
                    <small class="<?= $config['status'] === 'warning' ? 'text-dark' : 'text-white' ?> opacity-75">
                      <?= htmlspecialchars($config['description']) ?>
                    </small>
                  </div>
                  <span class="badge bg-light text-<?= $config['status'] === 'ready' ? 'success' : ($config['status'] === 'incomplete' ? 'warning' : 'secondary') ?>">
                    <?= ucfirst($config['status']) ?>
                  </span>
                </div>
              </div>
              <div class="card-body">
                <?php if (!empty($config['found_params'])): ?>
                <div class="mb-2">
                  <small class="text-muted">Found Parameters:</small><br>
                  <?php foreach ($config['found_params'] as $param): ?>
                    <span class="cp-code-text me-1"><?= htmlspecialchars($param) ?></span>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($config['missing_params'])): ?>
                <div class="mb-2">
                  <small class="text-muted">Missing Parameters:</small><br>
                  <?php foreach ($config['missing_params'] as $param): ?>
                    <span class="text-danger small"><?= htmlspecialchars($param) ?></span>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if (isset($config['file_status'])): ?>
                <div class="mb-2">
                  <small class="text-muted">Configuration File:</small><br>
                  <i class="bi bi-<?= $config['file_status'] === 'found' ? 'check-circle text-success' : 'x-circle text-danger' ?>"></i>
                  <span class="small"><?= htmlspecialchars($config['file_name']) ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($config['optional_params'])): ?>
                <div class="mb-2">
                  <small class="text-muted">Optional Parameters:</small><br>
                  <?php foreach ($config['optional_params'] as $param): ?>
                    <span class="cp-code-text me-1 opacity-75"><?= htmlspecialchars($param) ?></span>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($config['ready']): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="enable_<?= $type ?>" name="enable_<?= $type ?>" checked>
                  <label class="form-check-label" for="enable_<?= $type ?>">
                    Apply this configuration
                  </label>
                </div>
                <?php else: ?>
                <div class="alert alert-<?= $config['status'] === 'incomplete' ? 'warning' : 'secondary' ?> py-2 px-3 small mb-0">
                  <?php if ($config['status'] === 'missing_file'): ?>
                    Cannot apply - configuration file missing
                  <?php else: ?>
                    Cannot apply - missing required parameters
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- VPN Configuration -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0">
            <i class="bi bi-shield-lock me-2"></i>VPN Configuration
          </h5>
          <small class="text-muted">Upload VPN profiles for secure connections</small>
        </div>
        <div class="d-flex align-items-center gap-2">
          <?php if($fileStatuses['vpn']['exists']): ?>
            <span class="badge bg-success">File Present</span>
            <button class="btn btn-outline-primary btn-sm" onclick="viewFile('vpn')">
              <i class="bi bi-eye"></i> View
            </button>
          <?php else: ?>
            <span class="badge bg-secondary">No File</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <div class="upload-zone" data-type="vpn" id="vpnUpload">
          <div class="upload-content">
            <i class="bi bi-shield-lock mb-2" style="font-size: 2rem; color: #6c757d;"></i>
            <div class="upload-text">Drop your VPN profile here or click to browse</div>
            <div class="upload-info">(Supported formats: .ovpn, .conf, max 1MB)</div>
          </div>
          <div class="upload-progress" style="display: none;">
            <div class="progress" style="height: 10px;">
              <div class="progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
            <div class="upload-status mt-2"></div>
          </div>
          <input type="file" class="file-input" accept=".ovpn,.conf" style="display: none;">
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="cp-toast-container" id="toastArea"></div>

<!-- File Viewer Modal -->
<div class="modal fade cp-modal-responsive" id="fileViewerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="fileViewerModalLabel">File Contents</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <pre id="fileContent" class="bg-light p-3 rounded" style="max-height: 400px; overflow-y: auto; white-space: pre-wrap;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<style>
.upload-zone {
  border: 2px dashed #ddd;
  border-radius: 8px;
  padding: 2rem;
  text-align: center;
  cursor: pointer;
  transition: all 0.3s ease;
  background: #f8f9fa;
}

.upload-zone:hover {
  border-color: #0d6efd;
  background: #e3f2fd;
}

.upload-zone.drag-over {
  border-color: #0d6efd;
  background: #e3f2fd;
  transform: scale(1.02);
}

.upload-text {
  font-weight: 500;
  color: #495057;
  margin-bottom: 0.5rem;
}

.upload-info {
  font-size: 0.875rem;
  color: #6c757d;
}

.upload-progress {
  text-align: left;
}

.upload-status {
  font-size: 0.875rem;
}

.upload-status.upload-success {
  color: #198754;
}

.upload-status.upload-error {
  color: #dc3545;
}
</style>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

// Toast notification function (same as WiFi module)
function toast(message, success = true) {
  const toastArea = document.querySelector('#toastArea');
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

// Initialize upload zones
function initializeUploadZone(zone) {
  const input = zone.querySelector('.file-input');
  const content = zone.querySelector('.upload-content');
  const progress = zone.querySelector('.upload-progress');
  const progressBar = progress.querySelector('.progress-bar');
  const status = progress.querySelector('.upload-status');
  const type = zone.dataset.type;

  // Click to select file
  zone.addEventListener('click', (e) => {
    if (e.target !== input) {
      input.click();
    }
  });

  // Drag and drop handlers
  zone.addEventListener('dragover', (e) => {
    e.preventDefault();
    e.stopPropagation();
    zone.classList.add('drag-over');
  });

  zone.addEventListener('dragleave', (e) => {
    e.preventDefault();
    e.stopPropagation();
    zone.classList.remove('drag-over');
  });

  zone.addEventListener('drop', (e) => {
    e.preventDefault();
    e.stopPropagation();
    zone.classList.remove('drag-over');
    handleFiles(e.dataTransfer.files);
  });

  // File input change handler
  input.addEventListener('change', () => {
    handleFiles(input.files);
  });

  function handleFiles(files) {
    if (files.length === 0) return;
    
    const file = files[0];
    uploadFile(file);
  }

  function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', type);
    formData.append('action', 'upload');
    formData.append('csrf', csrf);

    // Show progress UI
    content.style.display = 'none';
    progress.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.classList.remove('bg-danger');
    status.textContent = 'Uploading...';
    status.className = 'upload-status mt-2';

    fetch(location.href, {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.error) {
        throw new Error(data.error);
      }
      progressBar.style.width = '100%';
      status.textContent = 'Upload successful!';
      status.classList.add('upload-success');
      toast(data.message || 'File uploaded successfully');
      
      setTimeout(() => {
        content.style.display = 'block';
        progress.style.display = 'none';
        input.value = '';
        // Reload page to update file status
        location.reload();
      }, 2000);
    })
    .catch(error => {
      progressBar.style.width = '100%';
      progressBar.classList.add('bg-danger');
      status.textContent = 'Error: ' + error.message;
      status.classList.add('upload-error');
      toast('Upload failed: ' + error.message, false);
      
      setTimeout(() => {
        content.style.display = 'block';
        progress.style.display = 'none';
        progressBar.classList.remove('bg-danger');
        input.value = '';
      }, 3000);
    });
  }
}

// View file function
async function viewFile(type) {
  try {
    const formData = new FormData();
    formData.append('action', 'view');
    formData.append('type', type);
    formData.append('csrf', csrf);

    const response = await fetch(location.href, {
      method: 'POST',
      body: formData
    });
    
    const data = await response.json();
    
    if (data.error) {
      throw new Error(data.error);
    }
    
    document.getElementById('fileContent').textContent = data.content;
    document.getElementById('fileViewerModalLabel').textContent = `${type} File Contents`;
    new bootstrap.Modal(document.getElementById('fileViewerModal')).show();
    
  } catch (error) {
    toast('Failed to load file: ' + error.message, false);
  }
}

// Initialize all upload zones
document.querySelectorAll('.upload-zone').forEach(initializeUploadZone);

// Apply network configurations function
async function applyNetworkConfigurations() {
  try {
    const formData = new FormData();
    formData.append('action', 'apply_config');
    formData.append('csrf', csrf);
    
    // Add enabled configurations
    const checkboxes = document.querySelectorAll('#networkConfigGrid input[type="checkbox"]:checked');
    checkboxes.forEach(checkbox => {
      formData.append(checkbox.name, 'on');
    });
    
    if (checkboxes.length === 0) {
      toast('No configurations selected', false);
      return;
    }
    
    // Show loading state
    const applyBtn = document.querySelector('button[onclick="applyNetworkConfigurations()"]');
    const originalText = applyBtn.innerHTML;
    applyBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Applying...';
    applyBtn.disabled = true;
    
    const response = await fetch(location.href, {
      method: 'POST',
      body: formData
    });
    
    const data = await response.json();
    
    if (data.error) {
      throw new Error(data.error);
    }
    
    // Show success messages
    if (data.results && data.results.length > 0) {
      data.results.forEach(result => {
        toast(result, true);
      });
    } else {
      toast('Network configurations applied successfully', true);
    }
    
    // Restore button
    applyBtn.innerHTML = originalText;
    applyBtn.disabled = false;
    
  } catch (error) {
    console.error('Configuration error:', error);
    toast('Failed to apply configurations: ' + error.message, false);
    
    // Restore button
    const applyBtn = document.querySelector('button[onclick="applyNetworkConfigurations()"]');
    if (applyBtn) {
      applyBtn.innerHTML = '<i class="bi bi-gear"></i> Apply Configurations';
      applyBtn.disabled = false;
    }
  }
}
</script>
?> 