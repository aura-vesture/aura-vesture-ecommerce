<?php
require_once 'config.php';
require_once 'connection.php';
require_once 'middleware.php';
require_once 'logger.php';
require_once 'session.php';

// Initialize logger and session manager
$logger = ActivityLogger::getInstance($pdo);
$sessionManager = SessionManager::getInstance($pdo);

// Require authentication for all requests
$userId = requireAuth();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        try {
            // Get security settings
            $stmt = $pdo->prepare("
                SELECT 
                    two_factor_enabled,
                    login_notifications,
                    suspicious_activity_alerts
                FROM user_settings 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $settings = $stmt->fetch();

            if (!$settings) {
                // Create default settings if none exist
                $stmt = $pdo->prepare("
                    INSERT INTO user_settings (
                        user_id,
                        two_factor_enabled,
                        login_notifications,
                        suspicious_activity_alerts
                    ) VALUES (?, 0, 1, 1)
                ");
                $stmt->execute([$userId]);

                $settings = [
                    'two_factor_enabled' => false,
                    'login_notifications' => true,
                    'suspicious_activity_alerts' => true
                ];
            }

            // Get active sessions
            $sessions = $sessionManager->getUserSessions($userId);

            // Get recent security events
            $stmt = $pdo->prepare("
                SELECT action, description, ip_address, created_at
                FROM activity_logs
                WHERE user_id = ?
                AND action LIKE 'SECURITY_%'
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            $securityEvents = $stmt->fetchAll();

            sendResponse([
                'success' => true,
                'security_settings' => $settings,
                'active_sessions' => $sessions,
                'security_events' => $securityEvents
            ]);

        } catch (Exception $e) {
            $logger->logError('Failed to fetch security settings', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            sendResponse(['error' => 'Failed to fetch security settings'], 500);
        }
        break;

    case 'PUT':
        $data = getPostData();
        
        if (!isset($data['settings'])) {
            sendResponse(['error' => 'Settings data is required'], 400);
        }

        try {
            $settings = $data['settings'];
            
            // Validate settings
            $validSettings = [
                'two_factor_enabled',
                'login_notifications',
                'suspicious_activity_alerts'
            ];

            $updateFields = [];
            $updateValues = [];

            foreach ($validSettings as $setting) {
                if (isset($settings[$setting])) {
                    $updateFields[] = "$setting = ?";
                    $updateValues[] = $settings[$setting] ? 1 : 0;
                }
            }

            if (empty($updateFields)) {
                sendResponse(['error' => 'No valid settings to update'], 400);
            }

            $updateValues[] = $userId;

            // Update settings
            $stmt = $pdo->prepare("
                UPDATE user_settings 
                SET " . implode(', ', $updateFields) . "
                WHERE user_id = ?
            ");
            
            $stmt->execute($updateValues);

            // Log the changes
            $logger->logSecurity('SETTINGS_UPDATED', [
                'user_id' => $userId,
                'changes' => $settings
            ]);

            // If 2FA was enabled, generate and store backup codes
            if (isset($settings['two_factor_enabled']) && $settings['two_factor_enabled']) {
                $backupCodes = [];
                for ($i = 0; $i < 8; $i++) {
                    $backupCodes[] = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                }

                // Store backup codes
                $stmt = $pdo->prepare("
                    INSERT INTO two_factor_backup_codes (
                        user_id,
                        code,
                        created_at
                    ) VALUES " . implode(', ', array_fill(0, count($backupCodes), '(?, ?, NOW())'))
                );

                $params = [];
                foreach ($backupCodes as $code) {
                    $params[] = $userId;
                    $params[] = password_hash($code, PASSWORD_DEFAULT);
                }

                $stmt->execute($params);

                // Send email with backup codes
                sendEmail(
                    $_SESSION['email'],
                    '2FA Backup Codes',
                    'two-factor-backup-codes',
                    [
                        'backup_codes' => $backupCodes
                    ]
                );
            }

            sendResponse([
                'success' => true,
                'message' => 'Security settings updated successfully'
            ]);

        } catch (Exception $e) {
            $logger->logError('Failed to update security settings', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            sendResponse(['error' => 'Failed to update security settings'], 500);
        }
        break;

    case 'DELETE':
        $data = getPostData();
        
        if (!isset($data['session_id'])) {
            sendResponse(['error' => 'Session ID is required'], 400);
        }

        try {
            // Verify the session belongs to the user
            $stmt = $pdo->prepare("
                SELECT id FROM user_sessions 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$data['session_id'], $userId]);
            
            if (!$stmt->fetch()) {
                sendResponse(['error' => 'Session not found'], 404);
            }

            // Delete the session
            $stmt = $pdo->prepare("
                DELETE FROM user_sessions 
                WHERE id = ?
            ");
            $stmt->execute([$data['session_id']]);

            $logger->logSecurity('SESSION_TERMINATED', [
                'user_id' => $userId,
                'session_id' => $data['session_id']
            ]);

            sendResponse([
                'success' => true,
                'message' => 'Session terminated successfully'
            ]);

        } catch (Exception $e) {
            $logger->logError('Failed to terminate session', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'session_id' => $data['session_id']
            ]);
            sendResponse(['error' => 'Failed to terminate session'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}
