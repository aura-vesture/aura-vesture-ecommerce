<?php
require_once 'config.php';
require_once 'connection.php';
require_once 'logger.php';

class SessionManager {
    private $pdo;
    private $logger;
    private static $instance = null;

    private function __construct($pdo) {
        $this->pdo = $pdo;
        $this->logger = ActivityLogger::getInstance($pdo);
        
        // Configure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        session_set_cookie_params(SESSION_LIFETIME);
    }

    public static function getInstance($pdo = null) {
        if (self::$instance === null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Start a new session or validate existing session
     * 
     * @return bool
     */
    public function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Validate existing session
        if (isset($_SESSION['user_id'])) {
            return $this->validateSession();
        }

        return true;
    }

    /**
     * Create a new session for user
     * 
     * @param array $user User data
     * @return bool
     */
    public function createSession($user) {
        try {
            // Generate session token
            $token = bin2hex(random_bytes(32));
            
            // Store session in database
            $stmt = $this->pdo->prepare("
                INSERT INTO user_sessions (
                    user_id,
                    token,
                    ip_address,
                    user_agent,
                    last_activity
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $user['id'],
                $token,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);

            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['session_token'] = $token;
            $_SESSION['created_at'] = time();

            // Log the session creation
            $this->logger->logSecurity('SESSION_CREATE', [
                'user_id' => $user['id'],
                'ip' => $_SERVER['REMOTE_ADDR']
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Session creation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate current session
     * 
     * @return bool
     */
    private function validateSession() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return false;
        }

        try {
            // Check if session exists in database
            $stmt = $this->pdo->prepare("
                SELECT * FROM user_sessions 
                WHERE user_id = ? 
                AND token = ? 
                AND last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            
            $stmt->execute([
                $_SESSION['user_id'],
                $_SESSION['session_token'],
                SESSION_LIFETIME
            ]);

            $session = $stmt->fetch();

            if (!$session) {
                $this->destroySession();
                return false;
            }

            // Update last activity
            $stmt = $this->pdo->prepare("
                UPDATE user_sessions 
                SET last_activity = NOW() 
                WHERE token = ?
            ");
            
            $stmt->execute([$_SESSION['session_token']]);

            // Check if session needs renewal
            if (time() - $_SESSION['created_at'] > SESSION_LIFETIME / 2) {
                $this->renewSession();
            }

            return true;
        } catch (Exception $e) {
            error_log("Session validation failed: " . $e->getMessage());
            $this->destroySession();
            return false;
        }
    }

    /**
     * Renew session to prevent session fixation
     * 
     * @return bool
     */
    private function renewSession() {
        try {
            // Generate new session ID and token
            session_regenerate_id(true);
            $newToken = bin2hex(random_bytes(32));

            // Update session in database
            $stmt = $this->pdo->prepare("
                UPDATE user_sessions 
                SET token = ? 
                WHERE token = ?
            ");
            
            $stmt->execute([$newToken, $_SESSION['session_token']]);

            // Update session variables
            $_SESSION['session_token'] = $newToken;
            $_SESSION['created_at'] = time();

            return true;
        } catch (Exception $e) {
            error_log("Session renewal failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Destroy current session
     * 
     * @return bool
     */
    public function destroySession() {
        try {
            if (isset($_SESSION['session_token'])) {
                // Remove session from database
                $stmt = $this->pdo->prepare("
                    DELETE FROM user_sessions 
                    WHERE token = ?
                ");
                
                $stmt->execute([$_SESSION['session_token']]);

                // Log the session destruction
                if (isset($_SESSION['user_id'])) {
                    $this->logger->logSecurity('SESSION_DESTROY', [
                        'user_id' => $_SESSION['user_id'],
                        'ip' => $_SERVER['REMOTE_ADDR']
                    ]);
                }
            }

            // Clear session data
            $_SESSION = array();

            // Destroy session cookie
            if (isset($_COOKIE[session_name()])) {
                setcookie(
                    session_name(),
                    '',
                    time() - 3600,
                    '/',
                    '',
                    true,
                    true
                );
            }

            // Destroy session
            session_destroy();

            return true;
        } catch (Exception $e) {
            error_log("Session destruction failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean expired sessions
     * 
     * @return bool
     */
    public function cleanExpiredSessions() {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM user_sessions 
                WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            
            return $stmt->execute([SESSION_LIFETIME]);
        } catch (Exception $e) {
            error_log("Failed to clean expired sessions: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all active sessions for a user
     * 
     * @param int $userId User ID
     * @return array
     */
    public function getUserSessions($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, ip_address, user_agent, last_activity 
                FROM user_sessions 
                WHERE user_id = ? 
                ORDER BY last_activity DESC
            ");
            
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Failed to get user sessions: " . $e->getMessage());
            return [];
        }
    }
}

// Initialize session manager
$sessionManager = SessionManager::getInstance($pdo);

// Start or validate session
$sessionManager->startSession();
