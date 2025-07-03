<?php
require_once 'config.php';
require_once 'connection.php';
require_once 'utils.php';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        // Request password reset
        $data = getPostData();
        
        if (!isset($data['email'])) {
            sendResponse(['error' => 'Email is required'], 400);
        }

        $email = validateEmail($data['email']);

        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id, first_name, email FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                // For security, don't reveal that the email doesn't exist
                sendResponse([
                    'success' => true,
                    'message' => 'If your email is registered, you will receive password reset instructions.'
                ]);
            }

            // Generate reset token and code
            $resetToken = generateRandomString(32);
            $resetCode = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
            $expiryTime = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            // Store reset token in database
            $stmt = $pdo->prepare("
                INSERT INTO password_resets (
                    user_id, 
                    token, 
                    code,
                    expires_at
                ) VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $user['id'],
                $resetToken,
                $resetCode,
                $expiryTime
            ]);

            // Generate reset link
            $resetLink = APP_URL . '/reset-password?token=' . $resetToken;

            // Send password reset email
            $emailSent = sendEmail(
                $user['email'],
                'Reset Your Password',
                'password-reset',
                [
                    'first_name' => $user['first_name'],
                    'reset_link' => $resetLink,
                    'reset_code' => $resetCode,
                    'email' => $user['email']
                ]
            );

            if (!$emailSent) {
                throw new Exception('Failed to send password reset email');
            }

            sendResponse([
                'success' => true,
                'message' => 'If your email is registered, you will receive password reset instructions.'
            ]);

        } catch (Exception $e) {
            logError('Password reset request failed', [
                'error' => $e->getMessage(),
                'email' => $email
            ]);
            sendResponse(['error' => 'Failed to process password reset request'], 500);
        }
        break;

    case 'PUT':
        // Reset password using token or code
        $data = getPostData();
        
        if ((!isset($data['token']) && !isset($data['code'])) || !isset($data['password'])) {
            sendResponse(['error' => 'Token/code and new password are required'], 400);
        }

        // Validate password strength
        validatePassword($data['password']);

        try {
            // Find valid reset request
            $query = "
                SELECT pr.*, u.email 
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.used = 0 
                AND pr.expires_at > NOW()
            ";
            
            $params = [];
            
            if (isset($data['token'])) {
                $query .= " AND pr.token = ?";
                $params[] = $data['token'];
            } else {
                $query .= " AND pr.code = ?";
                $params[] = strtoupper($data['code']);
            }
            
            $query .= " ORDER BY pr.created_at DESC LIMIT 1";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $reset = $stmt->fetch();

            if (!$reset) {
                sendResponse(['error' => 'Invalid or expired reset token/code'], 400);
            }

            // Update password
            $hashedPassword = hashPassword($data['password']);
            
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password = ?, 
                    updated_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([$hashedPassword, $reset['user_id']]);

            // Mark reset token as used
            $stmt = $pdo->prepare("
                UPDATE password_resets 
                SET used = 1, 
                    used_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([$reset['id']]);

            // Send confirmation email
            sendEmail(
                $reset['email'],
                'Your Password Has Been Reset',
                'password-changed',
                [
                    'email' => $reset['email']
                ]
            );

            sendResponse([
                'success' => true,
                'message' => 'Your password has been successfully reset'
            ]);

        } catch (Exception $e) {
            logError('Password reset failed', [
                'error' => $e->getMessage()
            ]);
            sendResponse(['error' => 'Failed to reset password'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}
