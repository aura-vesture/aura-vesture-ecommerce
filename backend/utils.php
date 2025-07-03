<?php
require_once 'config.php';

/**
 * Email Utilities
 */
function sendEmail($to, $subject, $template, $data = []) {
    try {
        // Basic email headers
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
            'X-Mailer: PHP/' . phpversion()
        ];

        // Load email template
        $templatePath = __DIR__ . '/email-templates/' . $template . '.html';
        if (!file_exists($templatePath)) {
            throw new Exception('Email template not found');
        }

        $content = file_get_contents($templatePath);

        // Replace placeholders with actual data
        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        // Replace common placeholders
        $content = str_replace([
            '{{app_name}}',
            '{{app_url}}',
            '{{current_year}}'
        ], [
            APP_NAME,
            APP_URL,
            date('Y')
        ], $content);

        // Send email
        return mail($to, $subject, $content, implode("\r\n", $headers));
    } catch (Exception $e) {
        logError('Email sending failed', [
            'error' => $e->getMessage(),
            'to' => $to,
            'subject' => $subject
        ]);
        return false;
    }
}

/**
 * Image Processing Utilities
 */
function resizeImage($sourcePath, $targetPath, $maxWidth = 800, $maxHeight = 800) {
    list($width, $height, $type) = getimagesize($sourcePath);
    
    // Calculate new dimensions
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = round($width * $ratio);
    $newHeight = round($height * $ratio);

    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Handle transparency for PNG images
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // Load source image
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            $source = imagecreatefromwebp($sourcePath);
            break;
        default:
            throw new Exception('Unsupported image type');
    }

    // Resize image
    imagecopyresampled($newImage, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save image
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($newImage, $targetPath, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($newImage, $targetPath, 8);
            break;
        case IMAGETYPE_WEBP:
            imagewebp($newImage, $targetPath, 85);
            break;
    }

    // Clean up
    imagedestroy($source);
    imagedestroy($newImage);

    return true;
}

/**
 * String Utilities
 */
function slugify($text) {
    // Replace non letter or digits by -
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    // Transliterate
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    // Remove unwanted characters
    $text = preg_replace('~[^-\w]+~', '', $text);
    // Trim
    $text = trim($text, '-');
    // Remove duplicate -
    $text = preg_replace('~-+~', '-', $text);
    // Lowercase
    $text = strtolower($text);
    
    return $text;
}

/**
 * Array Utilities
 */
function arrayToObject($array) {
    return json_decode(json_encode($array));
}

function objectToArray($object) {
    return json_decode(json_encode($object), true);
}

/**
 * Date Utilities
 */
function formatDate($date, $format = 'Y-m-d H:i:s') {
    return date($format, strtotime($date));
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = round($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = round($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = round($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

/**
 * Security Utilities
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Validation Utilities
 */
function isValidJson($string) {
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

function validatePhone($phone) {
    return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
}

function validateUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * File System Utilities
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function getFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
}

/**
 * Debug Utilities
 */
function dd($data) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
    die();
}

function debugLog($message, $context = []) {
    if (LOG_ERRORS) {
        error_log(
            date('Y-m-d H:i:s') . ' DEBUG: ' . 
            $message . ' ' . 
            json_encode($context) . "\n",
            3,
            ERROR_LOG_FILE
        );
    }
}
