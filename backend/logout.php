<?php
require_once 'config.php';
require_once 'connection.php';
require_once 'logger.php';
require_once 'session.php';

// Initialize logger and session manager
$logger = ActivityLogger::getInstance($pdo);
$sessionManager = SessionManager::getInstance($pdo);

// Start or validate session
$sessionManager->startSession();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    sendResponse(['error' => 'No active session'], 401);
}

try {
    $userId = $_SESSION['user_id'];
    $sessionToken = $_SESSION['session_token'] ?? null;

    // Get session information before destroying it
    if ($sessionToken) {
        $stmt = $pdo->prepare("
            SELECT ip_address, user_agent, created_at 
            FROM user_sessions 
            WHERE token = ?
        ");
        $stmt->execute([$sessionToken]);
        $sessionInfo = $stmt->fetch();
    }

    // Destroy the session
    $success = $sessionManager->destroySession();

    if (!$success) {
        throw new Exception('Failed to destroy session');
    }

    // Log the logout
    $logger->log($userId, 'USER_LOGOUT', 'User logged out successfully', [
        'session_info' => $sessionInfo ?? null
    ]);

    // Clean up expired sessions
    $sessionManager->cleanExpiredSessions();

    sendResponse([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);

} catch (Exception $e) {
    $logger->logSecurity('LOGOUT_ERROR', [
        'error' => $e->getMessage(),
        'user_id' => $userId ?? null
    ]);
    
    sendResponse(['error' => 'Logout failed'], 500);
}

// Function to terminate all sessions for a user
function terminateAllSessions($userId) {
    global $pdo, $logger;

    try {
        // Delete all sessions for the user
        $stmt = $pdo->prepare("
            DELETE FROM user_sessions 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);

        // Log the action
        $logger->logSecurity('ALL_SESSIONS_TERMINATED', [
            'user_id' => $userId,
            'sessions_count' => $stmt->rowCount()
        ]);

        return true;
    } catch (Exception $e) {
        $logger->logSecurity('SESSION_TERMINATION_ERROR', [
            'error' => $e->getMessage(),
            'user_id' => $userId
        ]);
        return false;
    }
}

// Handle POST request with specific action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = getPostData();
    
    // Check if request is to terminate all sessions
    if (isset($data['action']) && $data['action'] === 'terminate_all') {
        if (!isset($_SESSION['user_id'])) {
            sendResponse(['error' => 'Unauthorized'], 401);
        }

        $success = terminateAllSessions($_SESSION['user_id']);
        
        if ($success) {
            // Destroy current session as well
            $sessionManager->destroySession();
            
            sendResponse([
                'success' => true,
                'message' => 'All sessions terminated successfully'
            ]);
        } else {
            sendResponse([
                'error' => 'Failed to terminate all sessions'
            ], 500);
        }
    }
}
