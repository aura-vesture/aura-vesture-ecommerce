<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'aura_vesture');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8');

// Application Settings
define('APP_NAME', 'Aura Vesture');
define('APP_URL', 'http://localhost:8000');
define('APP_VERSION', '1.0.0');

// Security Settings
define('PASSWORD_MIN_LENGTH', 8);
define('SESSION_LIFETIME', 86400); // 24 hours
define('CSRF_TOKEN_LENGTH', 32);

// File Upload Settings
define('UPLOAD_DIR', __DIR__ . '/../public/uploads');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_FILE_TYPES', [
    'image/jpeg',
    'image/png',
    'image/webp'
]);

// Email Settings
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@auravesture.com');
define('SMTP_PASS', 'your-smtp-password');
define('SMTP_FROM_EMAIL', 'noreply@auravesture.com');
define('SMTP_FROM_NAME', 'Aura Vesture');

// Order Status Definitions
define('ORDER_STATUSES', [
    'pending' => 'Order Placed',
    'processing' => 'Processing',
    'shipped' => 'Shipped',
    'out_for_delivery' => 'Out for Delivery',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled'
]);

// Pagination Settings
define('ITEMS_PER_PAGE', 10);

// API Rate Limiting
define('RATE_LIMIT_REQUESTS', 100); // Number of requests
define('RATE_LIMIT_WINDOW', 3600);  // Time window in seconds (1 hour)

// Error Reporting
define('LOG_ERRORS', true);
define('ERROR_LOG_FILE', __DIR__ . '/logs/error.log');

// Initialize error logging
if (LOG_ERRORS) {
    ini_set('log_errors', 1);
    ini_set('error_log', ERROR_LOG_FILE);
}

// Time zone setting
date_default_timezone_set('UTC');

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
session_set_cookie_params(SESSION_LIFETIME);

// Create required directories
$directories = [
    UPLOAD_DIR,
    dirname(ERROR_LOG_FILE)
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Helper function to get base URL
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    return "{$protocol}://{$host}";
}

// Helper function to generate CSRF token
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

// Helper function to verify CSRF token
function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}

// Helper function for formatting currency
function formatCurrency($amount) {
    return number_format($amount, 2, '.', ',');
}

// Helper function for generating unique IDs
function generateUniqueId($prefix = '') {
    return uniqid($prefix, true);
}
