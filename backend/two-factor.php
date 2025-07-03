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

/**
 * Generate TOTP Secret
 */
function generateTOTPSecret() {
    return base32_encode(random_bytes(20));
}

/**
 * Verify TOTP Code
 */
function verifyTOTPCode($secret, $code, $window = 1) {
    require_once 'vendor/autoload.php';
    $totp = new \OTPHP\TOTP($secret);
    return $totp->verify($code, null, $window);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        $data = getPostData();
        $action = $data['action'] ?? '';

        switch ($action) {
            case 'enable':
                try {
                    // Generate new TOTP secret
                    $secret = generateTOTPSecret();
                    
                    // Store secret temporarily
                    $_SESSION['temp_2fa_secret'] = $secret;

                    // Generate QR code URL
                    require_once 'vendor/autoload.php';
                    $totp = new \OTPHP\TOTP(
                        $secret,
                        array(
                            'algorithm' => 'sha1',
                            'digits' => 6,
                            'period' => 30
                        )
                    );
                    
                    $totp->setLabel($_SESSION['email']);
                    $totp->setIssuer(APP_NAME);
                    
                    $qrCodeUrl = $totp->getProvisioningUri();

                    sendResponse([
                        'success' => true,
                        'secret' => $secret,
                        'qr_code_url' => $qrCodeUrl
                    ]);

                } catch (Exception $e) {
                    $logger->logError('2FA setup failed', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId
                    ]);
                    sendResponse(['error' => 'Failed to setup 2FA'], 500);
                }
                break;

            case 'verify':
                if (!isset($data['code'])) {
                    sendResponse(['error' => 'Verification code is required'], 400);
                }

                if (!isset($_SESSION['temp_2fa_secret'])) {
                    sendResponse(['error' => 'No pending 2FA setup found'], 400);
                }

                try {
                    $code = preg_replace('/\s+/', '', $data['code']);
                    $secret = $_SESSION['temp_2fa_secret'];

                    if (!verifyTOTPCode($secret, $code)) {
                        sendResponse(['error' => 'Invalid verification code'], 400);
                    }

                    // Enable 2FA
                    $stmt = $pdo->prepare("
                        UPDATE user_settings 
                        SET two_factor_enabled = 1,
                            two_factor_secret = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$secret, $userId]);

                    // Generate backup codes
                    $backupCodes = [];
                    for ($i = 0; $i < 8; $i++) {
                        $backupCodes[] = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                    }

                    // Store backup codes
                    $stmt = $pdo->prepare("
                        INSERT INTO two_factor_backup_codes (
                            user_id,
                            code
                        ) VALUES (?, ?)
                    ");

                    foreach ($backupCodes as $code) {
                        $stmt->execute([
                            $userId,
                            password_hash($code, PASSWORD_DEFAULT)
                        ]);
                    }

                    // Clear temporary secret
                    unset($_SESSION['temp_2fa_secret']);

                    // Log the action
                    $logger->logSecurity('2FA_ENABLED', [
                        'user_id' => $userId
                    ]);

                    // Send email with backup codes
                    sendEmail(
                        $_SESSION['email'],
                        'Two-Factor Authentication Enabled',
                        'two-factor-backup-codes',
                        [
                            'backup_codes' => $backupCodes
                        ]
                    );

                    sendResponse([
                        'success' => true,
                        'message' => '2FA enabled successfully',
                        'backup_codes' => $backupCodes
                    ]);

                } catch (Exception $e) {
                    $logger->logError('2FA verification failed', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId
                    ]);
                    sendResponse(['error' => 'Failed to verify 2FA code'], 500);
                }
                break;

            case 'disable':
                try {
                    // Verify current password
                    if (!isset($data['password'])) {
                        sendResponse(['error' => 'Current password is required'], 400);
                    }

                    $stmt = $pdo->prepare("
                        SELECT password FROM users WHERE id = ?
                    ");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();

                    if (!verifyPassword($data['password'], $user['password'])) {
                        sendResponse(['error' => 'Invalid password'], 401);
                    }

                    // Disable 2FA
                    $stmt = $pdo->prepare("
                        UPDATE user_settings 
                        SET two_factor_enabled = 0,
                            two_factor_secret = NULL
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$userId]);

                    // Delete backup codes
                    $stmt = $pdo->prepare("
                        DELETE FROM two_factor_backup_codes 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$userId]);

                    // Log the action
                    $logger->logSecurity('2FA_DISABLED', [
                        'user_id' => $userId
                    ]);

                    sendResponse([
                        'success' => true,
                        'message' => '2FA disabled successfully'
                    ]);

                } catch (Exception $e) {
                    $logger->logError('2FA disable failed', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId
                    ]);
                    sendResponse(['error' => 'Failed to disable 2FA'], 500);
                }
                break;

            case 'verify_login':
                if (!isset($data['code'])) {
                    sendResponse(['error' => 'Verification code is required'], 400);
                }

                try {
                    // Get user's 2FA settings
                    $stmt = $pdo->prepare("
                        SELECT two_factor_secret 
                        FROM user_settings 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$userId]);
                    $settings = $stmt->fetch();

                    $code = preg_replace('/\s+/', '', $data['code']);

                    // Check if it's a backup code
                    if (strlen($code) === 8) {
                        $stmt = $pdo->prepare("
                            SELECT id FROM two_factor_backup_codes
                            WHERE user_id = ? AND used = 0
                        ");
                        $stmt->execute([$userId]);
                        $backupCodes = $stmt->fetchAll();

                        $validCode = false;
                        foreach ($backupCodes as $backupCode) {
                            if (password_verify($code, $backupCode['code'])) {
                                // Mark backup code as used
                                $stmt = $pdo->prepare("
                                    UPDATE two_factor_backup_codes
                                    SET used = 1, used_at = NOW()
                                    WHERE id = ?
                                ");
                                $stmt->execute([$backupCode['id']]);
                                $validCode = true;
                                break;
                            }
                        }

                        if (!$validCode) {
                            sendResponse(['error' => 'Invalid backup code'], 400);
                        }
                    } else {
                        // Verify TOTP code
                        if (!verifyTOTPCode($settings['two_factor_secret'], $code)) {
                            sendResponse(['error' => 'Invalid verification code'], 400);
                        }
                    }

                    // Mark session as 2FA verified
                    $_SESSION['2fa_verified'] = true;

                    // Log successful 2FA verification
                    $logger->logSecurity('2FA_VERIFIED', [
                        'user_id' => $userId
                    ]);

                    sendResponse([
                        'success' => true,
                        'message' => '2FA verification successful'
                    ]);

                } catch (Exception $e) {
                    $logger->logError('2FA verification failed', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId
                    ]);
                    sendResponse(['error' => 'Failed to verify 2FA code'], 500);
                }
                break;

            default:
                sendResponse(['error' => 'Invalid action'], 400);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}
