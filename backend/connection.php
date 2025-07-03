<?php
require_once 'config.php';
require_once 'middleware.php';

// Set headers for CORS and JSON response
header("Access-Control-Allow-Origin: " . APP_URL);
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

} catch(PDOException $e) {
    logError('Database connection failed', [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
    sendResponse(['error' => 'Database connection failed'], 500);
}

// Helper function to send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    
    // Add CSRF token to successful responses
    if ($statusCode < 400 && isset($_SESSION)) {
        $data['csrf_token'] = generateCsrfToken();
    }
    
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit();
}

// Helper function to get POST/PUT data
function getPostData() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate CSRF token for POST/PUT/DELETE requests
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
        $headers = getallheaders();
        $csrfToken = $headers['X-CSRF-Token'] ?? null;
        
        if (!$csrfToken || !verifyCsrfToken($csrfToken)) {
            sendResponse(['error' => 'Invalid CSRF token'], 403);
        }
    }
    
    return $data ? sanitizeInput($data) : [];
}

// Helper function to handle file uploads
function handleFileUpload($file, $allowedTypes = ALLOWED_FILE_TYPES, $maxSize = MAX_FILE_SIZE) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds limit');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type');
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = generateUniqueId() . '.' . $extension;
    $destination = UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception('Failed to move uploaded file');
    }

    return [
        'filename' => $filename,
        'path' => $destination,
        'url' => APP_URL . '/uploads/' . $filename
    ];
}

// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Rate limiting check
if (LOG_ERRORS) {
    $clientIp = $_SERVER['REMOTE_ADDR'];
    $requestCount = isset($_SESSION['rate_limit'][$clientIp]) ? count($_SESSION['rate_limit'][$clientIp]) : 0;
    
    if ($requestCount >= RATE_LIMIT_REQUESTS) {
        $oldestRequest = reset($_SESSION['rate_limit'][$clientIp]);
        if (time() - $oldestRequest < RATE_LIMIT_WINDOW) {
            logError('Rate limit exceeded', ['ip' => $clientIp]);
            sendResponse(['error' => 'Too many requests'], 429);
        }
        
        // Remove old requests
        $_SESSION['rate_limit'][$clientIp] = array_filter(
            $_SESSION['rate_limit'][$clientIp],
            fn($timestamp) => time() - $timestamp < RATE_LIMIT_WINDOW
        );
    }
    
    // Add current request
    $_SESSION['rate_limit'][$clientIp][] = time();
}
