<?php
require_once 'connection.php';

session_start();

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    sendResponse(['error' => 'Unauthorized access'], 401);
}

$userId = $_SESSION['user_id'];
$isAdmin = $_SESSION['role'] === 'admin';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        try {
            // Check if specific order ID is requested
            $orderId = $_GET['id'] ?? null;

            if ($orderId) {
                // Get specific order details with items
                $stmt = $pdo->prepare("
                    SELECT o.*, u.email, u.first_name, u.last_name
                    FROM orders o
                    JOIN users u ON o.user_id = u.id
                    WHERE o.id = ? " . (!$isAdmin ? "AND o.user_id = ?" : "") . "
                    LIMIT 1
                ");

                $params = [$orderId];
                if (!$isAdmin) {
                    $params[] = $userId;
                }

                $stmt->execute($params);
                $order = $stmt->fetch();

                if (!$order) {
                    sendResponse(['error' => 'Order not found'], 404);
                }

                // Get order items
                $stmt = $pdo->prepare("
                    SELECT oi.*, p.name as product_name, p.image_url
                    FROM order_items oi
                    JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id = ?
                ");
                $stmt->execute([$orderId]);
                $items = $stmt->fetchAll();

                // Get order status history
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM order_status
                    WHERE order_id = ?
                    ORDER BY created_at DESC
                ");
                $stmt->execute([$orderId]);
                $statusHistory = $stmt->fetchAll();

                $order['items'] = $items;
                $order['status_history'] = $statusHistory;

                sendResponse([
                    'success' => true,
                    'order' => $order
                ]);
            } else {
                // Get list of orders
                $query = "
                    SELECT o.*, 
                           COUNT(oi.id) as item_count,
                           MAX(os.status) as current_status,
                           MAX(os.created_at) as status_date
                    FROM orders o
                    LEFT JOIN order_items oi ON o.id = oi.order_id
                    LEFT JOIN order_status os ON o.id = os.order_id
                ";

                if (!$isAdmin) {
                    $query .= " WHERE o.user_id = ?";
                }

                $query .= " GROUP BY o.id ORDER BY o.created_at DESC";

                $stmt = $pdo->prepare($query);
                
                if (!$isAdmin) {
                    $stmt->execute([$userId]);
                } else {
                    $stmt->execute();
                }

                $orders = $stmt->fetchAll();

                sendResponse([
                    'success' => true,
                    'orders' => $orders
                ]);
            }
        } catch(PDOException $e) {
            sendResponse(['error' => 'Failed to fetch orders'], 500);
        }
        break;

    case 'POST':
        // Create new order
        $data = getPostData();

        if (!isset($data['items']) || empty($data['items'])) {
            sendResponse(['error' => 'Order items are required'], 400);
        }

        try {
            $pdo->beginTransaction();

            // Create order
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    user_id, 
                    tracking_number,
                    total_amount,
                    status
                ) VALUES (?, ?, ?, 'pending')
            ");

            $trackingNumber = 'TRK' . time() . rand(1000, 9999);
            $totalAmount = 0;

            // Calculate total amount and verify stock
            foreach ($data['items'] as $item) {
                $stmt = $pdo->prepare("
                    SELECT price, stock FROM products WHERE id = ?
                ");
                $stmt->execute([$item['product_id']]);
                $product = $stmt->fetch();

                if (!$product) {
                    $pdo->rollBack();
                    sendResponse(['error' => 'Product not found'], 404);
                }

                if ($product['stock'] < $item['quantity']) {
                    $pdo->rollBack();
                    sendResponse(['error' => 'Insufficient stock'], 400);
                }

                $totalAmount += $product['price'] * $item['quantity'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO orders (user_id, tracking_number, total_amount, status)
                VALUES (?, ?, ?, 'pending')
            ");
            $stmt->execute([$userId, $trackingNumber, $totalAmount]);
            $orderId = $pdo->lastInsertId();

            // Add order items and update stock
            foreach ($data['items'] as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (order_id, product_id, quantity, price)
                    SELECT ?, ?, ?, price FROM products WHERE id = ?
                ");
                $stmt->execute([
                    $orderId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['product_id']
                ]);

                // Update product stock
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET stock = stock - ? 
                    WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }

            // Create initial order status
            $stmt = $pdo->prepare("
                INSERT INTO order_status (order_id, status, description)
                VALUES (?, 'Order Placed', 'Order has been placed successfully')
            ");
            $stmt->execute([$orderId]);

            $pdo->commit();

            sendResponse([
                'success' => true,
                'order' => [
                    'id' => $orderId,
                    'tracking_number' => $trackingNumber,
                    'total_amount' => $totalAmount
                ]
            ], 201);
        } catch(PDOException $e) {
            $pdo->rollBack();
            sendResponse(['error' => 'Failed to create order'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}
