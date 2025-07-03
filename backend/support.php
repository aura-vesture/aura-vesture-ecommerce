<?php
require_once 'config.php';
require_once 'connection.php';
require_once 'middleware.php';
require_once 'logger.php';
require_once 'session.php';

// Initialize logger
$logger = ActivityLogger::getInstance($pdo);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        $data = getPostData();

        // Validate required fields
        $requiredFields = ['name', 'email', 'category', 'subject', 'message', 'urgency'];
        validateRequiredFields($data, $requiredFields);

        // Validate email
        $email = validateEmail($data['email']);

        // Validate category
        $validCategories = ['account_access', '2fa', 'security', 'password', 'other'];
        if (!in_array($data['category'], $validCategories)) {
            sendResponse(['error' => 'Invalid category'], 400);
        }

        // Validate urgency
        $validUrgencies = ['low', 'normal', 'high', 'urgent'];
        if (!in_array($data['urgency'], $validUrgencies)) {
            sendResponse(['error' => 'Invalid urgency level'], 400);
        }

        try {
            // Get user ID if logged in
            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

            // Create support ticket
            $stmt = $pdo->prepare("
                INSERT INTO support_tickets (
                    user_id,
                    name,
                    email,
                    category,
                    subject,
                    message,
                    urgency,
                    status,
                    ip_address,
                    user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)
            ");

            $stmt->execute([
                $userId,
                sanitizeInput($data['name']),
                $email,
                $data['category'],
                sanitizeInput($data['subject']),
                sanitizeInput($data['message']),
                $data['urgency'],
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);

            $ticketId = $pdo->lastInsertId();

            // Log the support request
            $logger->log($userId, 'SUPPORT_REQUEST', "Support ticket #{$ticketId} created", [
                'category' => $data['category'],
                'urgency' => $data['urgency']
            ]);

            // Send confirmation email to user
            sendEmail(
                $email,
                'Support Request Received',
                'support-confirmation',
                [
                    'name' => $data['name'],
                    'ticket_id' => $ticketId,
                    'category' => $data['category'],
                    'subject' => $data['subject'],
                    'urgency' => $data['urgency']
                ]
            );

            // Send notification to support team
            $supportEmail = match ($data['category']) {
                'security' => 'security@auravesture.com',
                '2fa' => 'security@auravesture.com',
                'account_access' => 'accounts@auravesture.com',
                default => 'support@auravesture.com'
            };

            sendEmail(
                $supportEmail,
                "New Support Request #{$ticketId} - {$data['urgency']}",
                'support-notification',
                [
                    'ticket_id' => $ticketId,
                    'name' => $data['name'],
                    'email' => $email,
                    'category' => $data['category'],
                    'subject' => $data['subject'],
                    'message' => $data['message'],
                    'urgency' => $data['urgency'],
                    'user_id' => $userId,
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ]
            );

            // For high-priority security issues, create a security log
            if ($data['category'] === 'security' && in_array($data['urgency'], ['high', 'urgent'])) {
                $logger->logSecurity('HIGH_PRIORITY_SUPPORT', [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'category' => $data['category'],
                    'urgency' => $data['urgency']
                ]);
            }

            sendResponse([
                'success' => true,
                'message' => 'Support request submitted successfully',
                'ticket_id' => $ticketId
            ]);

        } catch (Exception $e) {
            $logger->logError('Support request failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId ?? null,
                'email' => $email
            ]);
            sendResponse(['error' => 'Failed to submit support request'], 500);
        }
        break;

    case 'GET':
        // Require authentication for viewing tickets
        $userId = requireAuth();

        try {
            // Get user's support tickets
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    category,
                    subject,
                    status,
                    urgency,
                    created_at,
                    updated_at
                FROM support_tickets
                WHERE user_id = ?
                ORDER BY 
                    CASE 
                        WHEN status = 'open' THEN 1
                        WHEN status = 'in_progress' THEN 2
                        ELSE 3
                    END,
                    created_at DESC
            ");
            
            $stmt->execute([$userId]);
            $tickets = $stmt->fetchAll();

            sendResponse([
                'success' => true,
                'tickets' => $tickets
            ]);

        } catch (Exception $e) {
            $logger->logError('Failed to fetch support tickets', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            sendResponse(['error' => 'Failed to fetch support tickets'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}
