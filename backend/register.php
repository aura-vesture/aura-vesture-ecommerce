<?php
require_once 'connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Method not allowed'], 405);
}

$data = getPostData();

// Validate required fields
if (!isset($data['email']) || !isset($data['password']) || 
    !isset($data['first_name']) || !isset($data['last_name'])) {
    sendResponse(['error' => 'All fields are required'], 400);
}

$email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
if (!$email) {
    sendResponse(['error' => 'Invalid email format'], 400);
}

// Validate password strength
if (strlen($data['password']) < 8) {
    sendResponse(['error' => 'Password must be at least 8 characters long'], 400);
}

try {
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Email already registered'], 409);
    }

    // Hash password
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

    // Insert new user
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, first_name, last_name, role)
        VALUES (?, ?, ?, ?, 'customer')
    ");

    $stmt->execute([
        $email,
        $hashedPassword,
        $data['first_name'],
        $data['last_name']
    ]);

    $userId = $pdo->lastInsertId();

    // If shipping address is provided, save it
    if (isset($data['address'])) {
        $stmt = $pdo->prepare("
            INSERT INTO addresses (
                user_id, 
                type,
                street_address, 
                city, 
                state, 
                postal_code, 
                country
            ) VALUES (?, 'shipping', ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            $data['address']['street'],
            $data['address']['city'],
            $data['address']['state'],
            $data['address']['postal_code'],
            $data['address']['country']
        ]);
    }

    // Start session for the new user
    session_start();
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = 'customer';

    sendResponse([
        'success' => true,
        'user' => [
            'id' => $userId,
            'email' => $email,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'role' => 'customer'
        ]
    ], 201);

} catch(PDOException $e) {
    sendResponse(['error' => 'Registration failed'], 500);
}
