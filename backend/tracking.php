<?php
require_once 'connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Method not allowed'], 405);
}

$trackingId = $_GET['id'] ?? '';

if (empty($trackingId)) {
    sendResponse(['error' => 'Tracking ID is required'], 400);
}

try {
    // Get order details
    $stmt = $pdo->prepare("
        SELECT o.*, os.status, os.created_at as status_date, os.location, os.description
        FROM orders o
        JOIN order_status os ON o.id = os.order_id
        WHERE o.tracking_number = ?
        ORDER BY os.created_at DESC
    ");
    $stmt->execute([$trackingId]);
    $statuses = $stmt->fetchAll();

    if (empty($statuses)) {
        sendResponse(['error' => 'Order not found'], 404);
    }

    // Calculate progress percentage based on status
    $statusWeights = [
        'Order Placed' => 0,
        'Processing' => 25,
        'Shipped' => 50,
        'Out for Delivery' => 75,
        'Delivered' => 100
    ];

    $currentStatus = $statuses[0]['status'];
    $progress = $statusWeights[$currentStatus] ?? 0;

    // Format timeline data
    $timeline = array_map(function($status) {
        return [
            'status' => $status['status'],
            'date' => date('M d, Y H:i', strtotime($status['status_date'])),
            'location' => $status['location'],
            'description' => $status['description']
        ];
    }, $statuses);

    sendResponse([
        'success' => true,
        'order' => [
            'tracking_number' => $trackingId,
            'progress' => $progress,
            'timeline' => $timeline
        ]
    ]);

} catch(PDOException $e) {
    sendResponse(['error' => 'Failed to fetch tracking information'], 500);
}
