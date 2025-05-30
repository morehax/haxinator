<?php
/**
 * Control Panel - Main Index (2025)
 * Modular architecture with auto-discovery
 */

declare(strict_types=1);
session_start();
header('X-Content-Type-Options: nosniff');

// ─────────────────────── Authentication ───────────────────────
require_once __DIR__ . '/auth/Auth.php';
$auth = new Auth('admin', 'changeme'); // TODO: move creds to config/env later
if (!$auth->isLoggedIn()) {
    echo Auth::renderLoginPage($auth->getLoginError());
    exit;
}

// CSRF helper
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }

// Module discovery
function discoverModules(): array {
    $modules = [];
    $moduleDir = __DIR__ . '/modules';
    
    if (!is_dir($moduleDir)) return $modules;
    
    // Define preferred modules with their metadata
    $defaultModules = [
        'wifi' => ['title' => 'Wi-Fi', 'icon' => 'wifi'],
        'network' => ['title' => 'Network', 'icon' => 'diagram-3'], 
        'ssh_tunnel' => ['title' => 'SSH', 'icon' => 'key'],
        'terminal' => ['title' => 'Terminal', 'icon' => 'terminal'],
        'configure' => ['title' => 'Configure', 'icon' => 'gear']
    ];
    
    foreach (glob($moduleDir . '/*.php') as $file) {
        $moduleId = basename($file, '.php');
        
        // Use default metadata or discover from file
        $meta = $defaultModules[$moduleId] ?? [
            'title' => ucfirst(str_replace(['_', '-'], ' ', $moduleId)),
            'icon' => 'gear'
        ];
        
        $modules[$moduleId] = ['file' => $file, 'meta' => $meta];
    }
    
    // Sort modules according to preferred order
    $sortedModules = [];
    foreach (array_keys($defaultModules) as $moduleId) {
        if (isset($modules[$moduleId])) {
            $sortedModules[$moduleId] = $modules[$moduleId];
        }
    }
    
    // Add any additional modules not in the preferred order
    foreach ($modules as $moduleId => $module) {
        if (!isset($sortedModules[$moduleId])) {
            $sortedModules[$moduleId] = $module;
        }
    }
    
    return $sortedModules;
}

// Discover all modules
$modules = discoverModules();
$currentModule = $_GET['module'] ?? 'wifi';

// Validate current module
if (!isset($modules[$currentModule])) {
    http_response_code(404);
    die('Module not found');
}

// Handle POST requests to modules FIRST, before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($modules[$currentModule])) {
    // Set embedded mode and include the module to handle the POST
    define('MODULE_POST_HANDLER', true);
    define('MODULE_ID', $currentModule);
    include $modules[$currentModule]['file'];
    // If we get here, the module didn't exit with JSON, so it didn't handle the request
    // This is an error condition - modules should handle their POST requests
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Module did not handle POST request']);
    exit;
}

// Now safe to include header utilities and collect data
require_once __DIR__ . '/includes/header_utils.php';

// Get header data for display
$headerData = HeaderUtils::getHeaderStatus();

$pageTitle = $modules[$currentModule]['meta']['title'] ?? 'Control Panel';
$csrf = csrf();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle) ?> - Control Panel</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/control-panel.css" rel="stylesheet">
</head>
<body>
    <!-- New Header System -->
    <div class="cp-topbar">
        <div class="cp-topbar-content">
            <div class="cp-topbar-left">
                <h1 class="cp-topbar-title">Haxinator 2000</h1>
            </div>
            <div class="cp-topbar-center">
                <i class="bi bi-hdd-network"></i>
                <span><?= htmlspecialchars($headerData['hostname']) ?></span>
            </div>
            <div class="cp-topbar-right">
                <div class="cp-status-group">
                    <span class="cp-status-indicator" title="SSH Tunnels">
                        <i class="bi bi-<?= $headerData['status']['proxy'] ? 'funnel' : 'router' ?>" 
                           style="color:<?= $headerData['status']['proxy'] ? '#22c55e' : '#f59e42' ?>;"></i>
                        <span class="cp-status-label">proxy</span>
                    </span>
                    <span class="cp-status-indicator" title="Ping to 8.8.8.8">
                        <i class="bi bi-door-<?= $headerData['status']['ping'] ? 'open' : 'closed' ?>" 
                           style="color:<?= $headerData['status']['ping'] ? '#22c55e' : '#f4427d' ?>;"></i>
                        <span class="cp-status-label">ping</span>
                    </span>
                    <span class="cp-status-indicator" title="Internet Connection">
                        <i class="bi bi-globe2" 
                           style="color:<?= $headerData['status']['internet'] ? '#22c55e' : '#f59e42' ?>;"></i>
                        <span class="cp-status-label">web</span>
                    </span>
                    <span class="cp-status-indicator" title="DNS Resolution">
                        <i class="bi bi-<?= $headerData['status']['dns'] ? 'plugin' : 'plug' ?>" 
                           style="color:<?= $headerData['status']['dns'] ? '#22c55e' : '#f4427d' ?>;"></i>
                        <span class="cp-status-label">dns</span>
                    </span>
                </div>
                <div class="cp-action-buttons">
                    <button onclick="shutdownSystem()" class="btn btn-outline-danger btn-sm" title="Shutdown System">
                        <i class="bi bi-power"></i>
                        <span class="d-none d-lg-inline ms-1">Shutdown</span>
                    </button>
                    <button onclick="logoutUser()" class="btn btn-outline-secondary btn-sm" title="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="d-none d-lg-inline ms-1">Logout</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="cp-interface-bar">
        <div class="cp-interface-content">
            <div class="cp-interface-status">
                <?php foreach ($headerData['interfaces'] as $iface): ?>
                    <div class="cp-interface-item <?= $iface['connected'] ? 'cp-connected' : 'cp-disconnected' ?>">
                        <i class="bi <?= htmlspecialchars($iface['icon']) ?>"></i>
                        <span class="cp-interface-name"><?= htmlspecialchars($iface['name']) ?></span>
                        <?php if ($iface['ipv4']): ?>
                            <span class="cp-interface-ip"><?= htmlspecialchars($iface['ipv4']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row">
            <div class="col">
                <!-- Navigation Tabs -->
                <ul class="nav nav-tabs cp-nav mb-0">
                    <?php foreach ($modules as $moduleId => $moduleInfo): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $currentModule === $moduleId ? 'active' : '' ?>" 
                               href="?module=<?= urlencode($moduleId) ?>">
                                <i class="bi bi-<?= htmlspecialchars($moduleInfo['meta']['icon'] ?? 'gear') ?>"></i>
                                <?= htmlspecialchars($moduleInfo['meta']['title'] ?? $moduleId) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Content Area -->
                <div class="cp-content">
                    <?php if (isset($modules[$currentModule])): ?>
                        <div class="module-content">
                            <?php
                            // Load the module
                            define('EMBEDDED_MODULE', true);
                            define('MODULE_ID', $currentModule);
                            
                            try {
                                include $modules[$currentModule]['file'];
                            } catch (Exception $e) {
                                echo '<div class="cp-module-error">';
                                echo '<i class="bi bi-exclamation-triangle text-warning fs-1 d-block mb-3"></i>';
                                echo '<h5>Module Error</h5>';
                                echo '<p class="mb-0">Failed to load module: ' . htmlspecialchars($e->getMessage()) . '</p>';
                                echo '</div>';
                            }
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="cp-module-error">
                            <i class="bi bi-box text-muted fs-1 d-block mb-3"></i>
                            <h5>No Modules Found</h5>
                            <p class="mb-0">No modules are available in the modules directory.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/control-panel.js"></script>
    <script>
        // Header functionality
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        
        function shutdownSystem() {
            if (!confirm('Are you sure you want to shutdown the system?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('csrf', csrfToken);
            
            fetch('/api/shutdown.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                } else {
                    alert('Failed to shutdown system: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to shutdown system');
            });
        }
        
        function logoutUser() {
            if (confirm('Are you sure you want to log out?')) {
                const url=new URL(window.location.href);
                url.search='logout'; // Clears other params, guarantees ?logout
                window.location.href=url.toString();
            }
        }
    </script>
</body>
</html> 