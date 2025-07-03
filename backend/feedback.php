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
        if (!isset($data['ticket_id']) || !isset($data['rating'])) {
            sendResponse(['error' => 'Missing required fields'], 400);
        }

        // Validate rating
        $rating = intval($data['rating']);
        if ($rating < 1 || $rating > 5) {
            sendResponse(['error' => 'Invalid rating value'], 400);
        }

        try {
            // Start transaction
            $pdo->beginTransaction();

            // Get ticket details
            $stmt = $pdo->prepare("
                SELECT user_id, status 
                FROM support_tickets 
                WHERE id = ?
            ");
            $stmt->execute([$data['ticket_id']]);
            $ticket = $stmt->fetch();

            if (!$ticket) {
                throw new Exception('Ticket not found');
            }

            // Insert feedback
            $stmt = $pdo->prepare("
                INSERT INTO ticket_feedback (
                    ticket_id,
                    user_id,
                    rating,
                    satisfaction_response_time,
                    satisfaction_communication,
                    satisfaction_solution_quality,
                    satisfaction_professionalism,
                    satisfaction_overall_experience,
                    comments,
                    follow_up_requested
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['ticket_id'],
                $ticket['user_id'],
                $rating,
                $data['satisfaction']['response_time'] ?? false,
                $data['satisfaction']['communication'] ?? false,
                $data['satisfaction']['solution_quality'] ?? false,
                $data['satisfaction']['professionalism'] ?? false,
                $data['satisfaction']['overall_experience'] ?? false,
                $data['comments'] ?? null,
                $data['follow_up'] ?? false
            ]);

            // Update ticket feedback status
            $stmt = $pdo->prepare("
                UPDATE support_tickets 
                SET feedback_received = 1,
                    feedback_date = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$data['ticket_id']]);

            // Log the feedback
            $logger->log($ticket['user_id'], 'FEEDBACK_SUBMITTED', "Feedback submitted for ticket #{$data['ticket_id']}", [
                'rating' => $rating,
                'ticket_id' => $data['ticket_id']
            ]);

            // If follow-up is requested, create a follow-up task
            if ($data['follow_up']) {
                $stmt = $pdo->prepare("
                    INSERT INTO support_tasks (
                        ticket_id,
                        type,
                        priority,
                        description,
                        due_date
                    ) VALUES (?, 'feedback_follow_up', 'high', ?, DATE_ADD(NOW(), INTERVAL 1 DAY))
                ");
                
                $stmt->execute([
                    $data['ticket_id'],
                    "Follow up requested regarding feedback for ticket #{$data['ticket_id']}"
                ]);
            }

            // If rating is low (1 or 2), create high-priority review task
            if ($rating <= 2) {
                $stmt = $pdo->prepare("
                    INSERT INTO support_tasks (
                        ticket_id,
                        type,
                        priority,
                        description,
                        due_date
                    ) VALUES (?, 'low_rating_review', 'urgent', ?, NOW())
                ");
                
                $stmt->execute([
                    $data['ticket_id'],
                    "Urgent review needed - Low rating ({$rating}/5) received for ticket #{$data['ticket_id']}"
                ]);

                // Send notification to support managers
                sendEmail(
                    'support-managers@auravesture.com',
                    'Low Rating Alert',
                    'low-rating-alert',
                    [
                        'ticket_id' => $data['ticket_id'],
                        'rating' => $rating,
                        'comments' => $data['comments'] ?? 'No comments provided',
                        'satisfaction' => $data['satisfaction']
                    ]
                );
            }

            // Send thank you email to customer
            sendEmail(
                $_SESSION['email'],
                'Thank You for Your Feedback',
                'feedback-thank-you',
                [
                    'ticket_id' => $data['ticket_id'],
                    'rating' => $rating,
                    'follow_up' => $data['follow_up']
                ]
            );

            $pdo->commit();

            sendResponse([
                'success' => true,
                'message' => 'Feedback submitted successfully'
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            
            $logger->logError('Feedback submission failed', [
                'error' => $e->getMessage(),
                'ticket_id' => $data['ticket_id']
            ]);
            
            sendResponse(['error' => 'Failed to submit feedback'], 500);
        }
        break;

    case 'GET':
        // Require authentication
        $userId = requireAuth();

        try {
            // Get feedback statistics for user's tickets
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_feedback,
                    AVG(rating) as average_rating,
                    COUNT(CASE WHEN rating >= 4 THEN 1 END) as positive_feedback,
                    COUNT(CASE WHEN rating <= 2 THEN 1 END) as negative_feedback
                FROM ticket_feedback
                WHERE user_id = ?
            ");
            
            $stmt->execute([$userId]);
            $stats = $stmt->fetch();

            // Get recent feedback
            $stmt = $pdo->prepare("
                SELECT tf.*, st.subject
                FROM ticket_feedback tf
                JOIN support_tickets st ON tf.ticket_id = st.id
                WHERE tf.user_id = ?
                ORDER BY tf.created_at DESC
                LIMIT 5
            ");
            
            $stmt->execute([$userId]);
            $recentFeedback = $stmt->fetchAll();

            sendResponse([
                'success' => true,
                'statistics' => $stats,
                'recent_feedback' => $recentFeedback
            ]);

        } catch (Exception $e) {
            $logger->logError('Failed to fetch feedback data', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            
            sendResponse(['error' => 'Failed to fetch feedback data'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}
