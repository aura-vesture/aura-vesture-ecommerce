<?php
require_once 'connection.php';

session_start();

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    sendResponse(['error' => 'Unauthorized access'], 401);
}

$userId = $_SESSION['user_id'];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        try {
            // Get user profile
            $stmt = $pdo->prepare("
                SELECT id, email, first_name, last_name, role, created_at
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                sendResponse(['error' => 'User not found'], 404);
            }

            // Get user's addresses
            $stmt = $pdo->prepare("
                SELECT id, type, street_address, city, state, postal_code, country
                FROM addresses 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $addresses = $stmt->fetchAll();

            sendResponse([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'role' => $user['role'],
                    'created_at' => $user['created_at'],
                    'addresses' => $addresses
                ]
            ]);
        } catch(PDOException $e) {
            sendResponse(['error' => 'Failed to fetch profile'], 500);
        }
        break;

    case 'PUT':
        $data = getPostData();

        // Validate required fields
        if (!isset($data['first_name']) || !isset($data['last_name'])) {
            sendResponse(['error' => 'Name fields are required'], 400);
        }

        try {
            // Update user profile
            $stmt = $pdo->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $userId
            ]);

            // If address is provided, update or create it
            if (isset($data['address'])) {
                $address = $data['address'];
                
                // Check if address exists
                $stmt = $pdo->prepare("
                    SELECT id FROM addresses 
                    WHERE user_id = ? AND type = ?
                ");
                $stmt->execute([$userId, $address['type']]);
                $existingAddress = $stmt->fetch();

                if ($existingAddress) {
                    // Update existing address
                    $stmt = $pdo->prepare("
                        UPDATE addresses 
                        SET street_address = ?, city = ?, state = ?, 
                            postal_code = ?, country = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $address['street_address'],
                        $address['city'],
                        $address['state'],
                        $address['postal_code'],
                        $address['country'],
                        $existingAddress['id']
                    ]);
                } else {
                    // Create new address
                    $stmt = $pdo->prepare("
                        INSERT INTO addresses (
                            user_id, type, street_address, city, 
                            state, postal_code, country
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $userId,
                        $address['type'],
                        $address['street_address'],
                        $address['city'],
                        $address['state'],
                        $address['postal_code'],
                        $address['country']
                    ]);
                }
            }

            sendResponse([
                'success' => true,
                'message' => 'Profile updated successfully'
            ]);
        } catch(PDOException $e) {
            sendResponse(['error' => 'Failed to update profile'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}
