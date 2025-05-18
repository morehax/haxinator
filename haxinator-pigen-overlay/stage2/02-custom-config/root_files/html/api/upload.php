<?php
// Include security framework
require_once __DIR__ . '/../security/bootstrap.php';

// Start a PHP session
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

// Check if it's a POST request with a file
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Define allowed upload types and their configurations
$allowed_types = [
    'passwords' => [
        'dir' => '/var/www/html/wordlists',
        'allowed_types' => ['text/plain'],
        'max_size' => 5 * 1024 * 1024, // 5MB
        'extensions' => ['txt', 'lst', 'dict'],
        'min_size' => 1, // Prevent empty files
    ],
    'env-secrets' => [
        'dir' => '/var/www',  // Not used since we save directly
        'allowed_types' => ['text/plain', 'application/octet-stream'],
        'max_size' => 1 * 1024 * 1024, // 1MB
        'extensions' => ['txt', ''],  // Allow both .txt and no extension
        'min_size' => 1, // Prevent empty files
    ],
    'vpn' => [
        'dir' => '/var/www',  // Not used since we save directly
        'allowed_types' => ['text/plain', 'application/octet-stream'],
        'max_size' => 1 * 1024 * 1024, // 1MB
        'extensions' => ['ovpn', 'conf'],
        'min_size' => 1, // Prevent empty files
    ],
];

// Get upload type from POST or query string
$type = $_POST['type'] ?? '';
if (empty($type) || !array_key_exists($type, $allowed_types)) {
    die(json_encode(['error' => 'Invalid upload type']));
}

// Get file from request
if (empty($_FILES['file'])) {
    die(json_encode(['error' => 'No file submitted']));
}

$file = $_FILES['file'];
$typeConfig = $allowed_types[$type];

// Basic validation
try {
    // Check if file uploaded successfully
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
        ];
        
        $errorMessage = $errorMessages[$file['error']] ?? 'Unknown upload error';
        throw new Exception($errorMessage);
    }
    
    // Check file size against configured limits
    if ($file['size'] > $typeConfig['max_size']) {
        throw new Exception('File too large. Maximum allowed size is ' . round($typeConfig['max_size'] / (1024*1024), 1) . 'MB');
    }
    
    if ($file['size'] < $typeConfig['min_size']) {
        throw new Exception('File is empty or too small');
    }
    
    // Additional validation for env-secrets
    if ($type === 'env-secrets') {
        // Verify the file is named either "env-secrets" or "env-secrets.txt"
        $basename = pathinfo($file['name'], PATHINFO_FILENAME);
        if ($basename !== 'env-secrets') {
            throw new Exception('File must be named "env-secrets" or "env-secrets.txt"');
        }
    }
    
    // Validate file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!empty($typeConfig['extensions']) && !in_array($extension, $typeConfig['extensions']) && !in_array('', $typeConfig['extensions'])) {
        throw new Exception('Invalid file extension. Allowed extensions: ' . implode(', ', $typeConfig['extensions']));
    }
    
    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    
    // More lenient MIME checking due to inconsistencies
    $isTextFile = false;
    if (strpos($mimeType, 'text/') === 0) {
        $isTextFile = true;
    } elseif ($mimeType === 'application/octet-stream') {
        // For .ovpn and other text files sometimes detected as octet-stream
        $content = file_get_contents($file['tmp_name']);
        if (mb_detect_encoding($content, 'ASCII, UTF-8', true)) {
            $isTextFile = true;
        }
    }
    
    // For env-secrets and VPN configs, ensure they're text files
    if (($type === 'env-secrets' || $type === 'vpn') && !$isTextFile) {
        throw new Exception('Invalid file type. Only text files are allowed.');
    }
    
    // For password lists, be more strict with MIME types
    if ($type === 'passwords' && !in_array($mimeType, $typeConfig['allowed_types'])) {
        throw new Exception('Invalid file type. Allowed types: ' . implode(', ', $typeConfig['allowed_types']));
    }
    
    // Process file based on type
    switch ($type) {
        case 'passwords':
            // Save to wordlists directory
            $destination = $typeConfig['dir'] . '/' . basename($file['name']);
            if (!is_dir($typeConfig['dir'])) {
                mkdir($typeConfig['dir'], 0755, true);
            }
            
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception('Failed to save file');
            }
            
            // Set permissions
            chmod($destination, 0644);
            chown($destination, 'www-data');
            break;
            
        case 'env-secrets':
            // Save to /var/www with specific name
            $destination = '/var/www/env-secrets';
            
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception('Failed to save file');
            }
            
            // Set restrictive permissions - only readable by www-data
            chmod($destination, 0600);
            chown($destination, 'www-data');
            break;
            
        case 'vpn':
            // Save to /var/www with fixed name for OpenVPN
            $destination = '/var/www/openvpn-udp.ovpn';
            
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception('Failed to save file');
            }
            
            // Set restrictive permissions - only readable by www-data
            chmod($destination, 0600);
            chown($destination, 'www-data');
            break;
            
        default:
            throw new Exception('Unsupported file type');
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'file' => basename($destination),
        'type' => $type
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
} 