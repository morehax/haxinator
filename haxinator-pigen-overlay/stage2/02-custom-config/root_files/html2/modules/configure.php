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
</script> 