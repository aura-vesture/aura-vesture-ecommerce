<?php
function requireAuth() {
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        sendResponse(['error' => 'Unauthorized access'], 401);
    }
    
    return $_SESSION['user_id'];
}

function requireAdmin() {
    $userId = requireAuth();
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        sendResponse(['error' => 'Admin access required'], 403);
    }
    
    return $userId;
}

function validateRequestMethod($methods) {
    if (!in_array($_SERVER['REQUEST_METHOD'], (array)$methods)) {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function validateRequiredFields($data, $fields) {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendResponse(['error' => "Field '$field' is required"], 400);
        }
    }
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'Invalid email format'], 400);
    }
    return $email;
}

function validatePassword($password, $minLength = 8) {
    if (strlen($password) < $minLength) {
        sendResponse(['error' => "Password must be at least $minLength characters long"], 400);
    }
    return $password;
}

function generateTrackingNumber() {
    return 'TRK' . time() . rand(1000, 9999);
}

function logError($error, $context = []) {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $error,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'request_uri' => $_SERVER['REQUEST_URI']
    ];
    
    error_log(json_encode($logEntry) . "\n", 3, __DIR__ . '/logs/error.log');
}

function validateOrderItems($items) {
    if (!is_array($items) || empty($items)) {
        sendResponse(['error' => 'Order must contain at least one item'], 400);
    }

    foreach ($items as $item) {
        if (!isset($item['product_id']) || !isset($item['quantity'])) {
            sendResponse(['error' => 'Invalid order item format'], 400);
        }

        if (!is_numeric($item['quantity']) || $item['quantity'] <= 0) {
            sendResponse(['error' => 'Invalid quantity'], 400);
        }
    }

    return $items;
}

function ensureDirectoryExists($path) {
    if (!file_exists($path)) {
        mkdir($path, 0755, true);
    }
}

// Ensure logs directory exists
ensureDirectoryExists(__DIR__ . '/logs');
