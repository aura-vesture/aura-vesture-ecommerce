<?php
require_once 'config.php';
require_once 'connection.php';

class ActivityLogger {
    private $pdo;
    private static $instance = null;

    private function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public static function getInstance($pdo = null) {
        if (self::$instance === null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Log user activity
     * 
     * @param int|null $userId User ID (null for guest actions)
     * @param string $action Action performed (login, logout, order_placed, etc.)
     * @param string $description Detailed description of the action
     * @param array $context Additional context data
     * @return bool
     */
    public function log($userId, $action, $description, $context = []) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO activity_logs (
                    user_id, 
                    action, 
                    description, 
                    ip_address,
                    user_agent
                ) VALUES (?, ?, ?, ?, ?)
            ");

            return $stmt->execute([
                $userId,
                $action,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log security events
     * 
     * @param string $event Security event type
     * @param array $data Event details
     * @return bool
     */
    public function logSecurity($event, $data = []) {
        $userId = $_SESSION['user_id'] ?? null;
        $description = json_encode($data);
        
        return $this->log($userId, "SECURITY_" . $event, $description);
    }

    /**
     * Log authentication attempts
     * 
     * @param string $email Email used in attempt
     * @param bool $success Whether the attempt was successful
     * @param string $method Authentication method used
     * @return bool
     */
    public function logAuth($email, $success, $method = 'password') {
        $action = $success ? 'AUTH_SUCCESS' : 'AUTH_FAILURE';
        $description = "Authentication attempt using $method";
        
        return $this->log(
            null, 
            $action,
            $description,
            ['email' => $email, 'method' => $method]
        );
    }

    /**
     * Log order events
     * 
     * @param int $orderId Order ID
     * @param string $status New status
     * @param string $description Status change description
     * @return bool
     */
    public function logOrder($orderId, $status, $description) {
        $userId = $_SESSION['user_id'] ?? null;
        
        return $this->log(
            $userId,
            "ORDER_STATUS_$status",
            $description,
            ['order_id' => $orderId]
        );
    }

    /**
     * Log product changes
     * 
     * @param int $productId Product ID
     * @param string $action Action performed
     * @param array $changes Changes made
     * @return bool
     */
    public function logProduct($productId, $action, $changes = []) {
        $userId = $_SESSION['user_id'] ?? null;
        $description = "Product #$productId: $action";
        
        if (!empty($changes)) {
            $description .= " - Changes: " . json_encode($changes);
        }
        
        return $this->log(
            $userId,
            "PRODUCT_$action",
            $description,
            ['product_id' => $productId]
        );
    }

    /**
     * Get activity logs for a user
     * 
     * @param int $userId User ID
     * @param int $limit Number of logs to retrieve
     * @param int $offset Offset for pagination
     * @return array
     */
    public function getUserActivity($userId, $limit = 10, $offset = 0) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM activity_logs 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute([$userId, $limit, $offset]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Failed to retrieve user activity: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get security events
     * 
     * @param string $event Event type (optional)
     * @param int $limit Number of logs to retrieve
     * @param int $offset Offset for pagination
     * @return array
     */
    public function getSecurityLogs($event = null, $limit = 10, $offset = 0) {
        try {
            $query = "
                SELECT * FROM activity_logs 
                WHERE action LIKE 'SECURITY_%'
            ";
            
            $params = [];
            
            if ($event) {
                $query .= " AND action = ?";
                $params[] = "SECURITY_" . $event;
            }
            
            $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Failed to retrieve security logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean old logs
     * 
     * @param int $days Number of days to keep
     * @return bool
     */
    public function cleanOldLogs($days = 90) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM activity_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            return $stmt->execute([$days]);
        } catch (Exception $e) {
            error_log("Failed to clean old logs: " . $e->getMessage());
            return false;
        }
    }
}

// Initialize logger
$logger = ActivityLogger::getInstance($pdo);

// Example usage:
// $logger->log($userId, 'USER_LOGIN', 'User logged in successfully');
// $logger->logSecurity('SUSPICIOUS_LOGIN', ['ip' => $ip, 'attempts' => $attempts]);
// $logger->logAuth($email, true, 'password');
// $logger->logOrder($orderId, 'SHIPPED', 'Order has been shipped');
// $logger->logProduct($productId, 'UPDATE', ['price' => ['old' => 10, 'new' => 15]]);
