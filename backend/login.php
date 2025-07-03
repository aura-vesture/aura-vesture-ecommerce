<?php
require_once 'config.php';
require_once 'connection.php';
require_once 'utils.php';
require_once 'logger.php';
require_once 'session.php';

// Initialize logger and session manager
$logger = ActivityLogger::getInstance($pdo);
$sessionManager = SessionManager::getInstance($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Method not allowed'], 405);
}

$data = getPostData();

// Validate required fields
if (!isset($data['email']) || !isset($data['password'])) {
    sendResponse(['error' => 'Email and password are required'], 400);
}

$email = validateEmail($data['email']);
$password = $data['password'];

try {
    // Get user by email
    $stmt = $pdo->prepare("
        SELECT id, email, password, role, first_name, last_name, 
               created_at, updated_at
        FROM users 
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Check if user exists and verify password
    if (!$user || !verifyPassword($password, $user['password'])) {
        // Log failed login attempt
        $logger->logAuth($email, false);
        
        // Check for brute force attempts
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts 
            FROM activity_logs 
            WHERE action = 'AUTH_FAILURE' 
            AND ip_address = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$_SERVER['REMOTE_ADDR']]);
        $attempts = $stmt->fetch()['attempts'];

        if ($attempts >= 5) {
            $logger->logSecurity('BRUTE_FORCE_ATTEMPT', [
                'email' => $email,
                'ip' => $_SERVER['REMOTE_ADDR'],
                'attempts' => $attempts
            ]);
            sendResponse(['error' => 'Too many failed attempts. Please try again later.'], 429);
        }

        sendResponse(['error' => 'Invalid email or password'], 401);
    }

    // Check if user is locked
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as suspicious 
        FROM activity_logs 
        WHERE action = 'SECURITY_SUSPICIOUS_LOGIN' 
        AND user_id = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$user['id']]);
    $suspicious = $stmt->fetch()['suspicious'];

    if ($suspicious > 0) {
        $logger->logSecurity('LOGIN_BLOCKED', [
            'user_id' => $user['id'],
            'reason' => 'Account locked due to suspicious activity'
        ]);
        sendResponse(['error' => 'Account temporarily locked. Please contact support.'], 403);
    }

    // Create new session
    if (!$sessionManager->createSession($user)) {
        throw new Exception('Failed to create session');
    }

    // Log successful login
    $logger->logAuth($email, true);

    // Get user's last login
    $stmt = $pdo->prepare("
        SELECT created_at, ip_address 
        FROM activity_logs 
        WHERE user_id = ? 
        AND action = 'AUTH_SUCCESS' 
        ORDER BY created_at DESC 
        LIMIT 1, 1
    ");
    $stmt->execute([$user['id']]);
    $lastLogin = $stmt->fetch();

    // Check for suspicious login patterns
    if ($lastLogin) {
        $suspicious = false;
        $reasons = [];

        // Different IP address
        if ($lastLogin['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
            $suspicious = true;
            $reasons[] = 'IP address change';
        }

        // Unusual login time (if last login was less than 1 hour ago)
        $lastLoginTime = strtotime($lastLogin['created_at']);
        if (time() - $lastLoginTime < 3600) {
            $suspicious = true;
            $reasons[] = 'Rapid consecutive logins';
        }

        if ($suspicious) {
            $logger->logSecurity('SUSPICIOUS_LOGIN', [
                'user_id' => $user['id'],
                'reasons' => $reasons,
                'last_login' => $lastLogin
            ]);

            // Send security alert email
            sendEmail(
                $user['email'],
                'Security Alert - New Login Detected',
                'security-alert',
                [
                    'first_name' => $user['first_name'],
                    'login_time' => date('Y-m-d H:i:s'),
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]
            );
        }
    }

    // Get user's active sessions
    $activeSessions = $sessionManager->getUserSessions($user['id']);

    // Prepare response data
    $userData = [
        'id' => $user['id'],
        'email' => $user['email'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'role' => $user['role'],
        'created_at' => $user['created_at'],
        'last_login' => $lastLogin ? $lastLogin['created_at'] : null,
        'active_sessions' => count($activeSessions)
    ];

    sendResponse([
        'success' => true,
        'user' => $userData,
        'message' => 'Login successful'
    ]);

} catch(Exception $e) {
    $logger->logSecurity('LOGIN_ERROR', [
        'error' => $e->getMessage(),
        'email' => $email
    ]);
    sendResponse(['error' => 'Login failed'], 500);
}
