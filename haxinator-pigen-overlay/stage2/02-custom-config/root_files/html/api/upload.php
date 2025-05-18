<?php
// Include security framework
require_once __DIR__ . '/../security/bootstrap.php';

// Strict error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Authentication check
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Enable error logging
error_log("Upload request received");

// Configuration with strict types
$config = [
    'passwords' => [
        'dir' => __DIR__ . '/../uploads/passwords',
        'allowed_types' => ['text/plain', 'text/x-generic', 'application/octet-stream'],
        'max_size' => 5 * 1024 * 1024, // 5MB
        'extensions' => ['txt', 'lst', 'dict'],
        'min_size' => 1, // Prevent empty files
    ],
    'vpn' => [
        'dir' => __DIR__ . '/../uploads/vpn',
        'allowed_types' => ['application/x-openvpn-profile', 'application/octet-stream', 'text/plain'],
        'max_size' => 1 * 1024 * 1024, // 1MB
        'extensions' => ['ovpn', 'conf'],
        'min_size' => 1, // Prevent empty files
    ]
];

function sanitizeFilename($filename) {
    // Remove any directory components
    $filename = basename($filename);
    
    // Get extension before sanitizing
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // Validate file extension using InputValidator
    if (!InputValidator::filePath($filename, $config[$type]['extensions'])) {
        throw new Exception('Invalid filename or extension');
    }
    
    // Remove extension for sanitizing
    $name = pathinfo($filename, PATHINFO_FILENAME);
    
    // Replace any non-alphanumeric characters
    $name = preg_replace('/[^a-zA-Z0-9]/', '_', $name);
    
    // Trim any leading/trailing underscores
    $name = trim($name, '_');
    
    // Add timestamp and reconnect extension
    return time() . '-' . substr($name, 0, 50) . '.' . $ext;
}

function verifyUploadDirectory($dir) {
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0750, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }
    
    // Verify directory permissions
    $perms = fileperms($dir);
    if (($perms & 0777) !== 0750) {
        chmod($dir, 0750);
    }
    
    // Create .htaccess to prevent direct access
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
        chmod($htaccess, 0640);
    }
}

try {
    // Rate limiting (simple implementation)
    $upload_count = $_SESSION['upload_count'] ?? 0;
    $upload_time = $_SESSION['upload_time'] ?? 0;
    
    if (time() - $upload_time > 3600) {
        $_SESSION['upload_count'] = 1;
        $_SESSION['upload_time'] = time();
    } elseif ($upload_count > 50) { // Max 50 uploads per hour
        throw new Exception('Upload limit exceeded. Please try again later.');
    } else {
        $_SESSION['upload_count'] = $upload_count + 1;
    }

    if (!isset($_FILES['file']) || !isset($_POST['type'])) {
        error_log("Missing parameters: files=" . print_r($_FILES, true) . ", post=" . print_r($_POST, true));
        throw new Exception('Missing required parameters');
    }

    $type = $_POST['type'];
    error_log("Upload type: " . $type);
    
    if (!isset($config[$type])) {
        throw new Exception('Invalid upload type');
    }

    $file = $_FILES['file'];
    $typeConfig = $config[$type];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds PHP maximum file size',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum file size',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        throw new Exception($errors[$file['error']] ?? 'Unknown upload error');
    }

    error_log("Original filename: " . $file['name']);
    error_log("File size: " . $file['size']);

    // Validate file size
    if ($file['size'] < $typeConfig['min_size']) {
        throw new Exception('File is empty');
    }
    if ($file['size'] > $typeConfig['max_size']) {
        throw new Exception('File too large (max ' . ($typeConfig['max_size'] / 1024 / 1024) . 'MB)');
    }

    // Get file extension and mime type
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    error_log("File extension: " . $extension);
    error_log("Allowed extensions: " . implode(', ', $typeConfig['extensions']));

    if (!in_array($extension, $typeConfig['extensions'], true)) {
        throw new Exception('Invalid file extension. Allowed: ' . implode(', ', $typeConfig['extensions']));
    }

    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new Exception('Failed to initialize file info');
    }
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    error_log("MIME type: " . $mimeType);

    // For password files, accept any text-based mime type
    if ($type === 'passwords') {
        $isTextFile = false;
        // Check if it's a text file by reading the first few bytes
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            throw new Exception('Failed to read file');
        }
        $firstBytes = fread($handle, 512);
        fclose($handle);
        
        // Check if the content appears to be text
        if (mb_detect_encoding($firstBytes, 'UTF-8, ISO-8859-1', true) !== false) {
            $isTextFile = true;
        }

        if (!$isTextFile && !in_array($mimeType, $typeConfig['allowed_types'], true)) {
            throw new Exception('Invalid file type. File must be a text file.');
        }
    } else {
        // For VPN files, strictly check mime type
        if (!in_array($mimeType, $typeConfig['allowed_types'], true)) {
            throw new Exception('Invalid file type. Got: ' . $mimeType . ', Expected: ' . implode(' or ', $typeConfig['allowed_types']));
        }
    }

    // Special handling for password files - save directly to passwords.txt
    if ($type === 'passwords') {
        $destination = '/var/www/html/passwords.txt';
        error_log("Saving password file to: " . $destination);
        
        if (!copy($file['tmp_name'], $destination)) {
            throw new Exception('Failed to save password file');
        }
        
        // Set secure permissions
        chmod($destination, 0644);
    } else {
        // Regular file upload handling for other file types
        // Verify and secure upload directory
        verifyUploadDirectory($typeConfig['dir']);

        // Generate secure filename and move file
        $filename = sanitizeFilename($file['name']);
        $destination = $typeConfig['dir'] . '/' . $filename;

        error_log("Saving to: " . $destination);

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception('Failed to save file');
        }

        // Set secure permissions
        chmod($destination, 0640);
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'filename' => $type === 'passwords' ? 'passwords.txt' : $filename,
        'message' => $type === 'passwords' ? 'Password file updated successfully' : 'File uploaded successfully'
    ]);

} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
} 