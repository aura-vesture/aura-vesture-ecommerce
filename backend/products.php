<?php
require_once 'connection.php';

// Check if user is authenticated and is admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    sendResponse(['error' => 'Unauthorized access'], 401);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Get all products or single product
        $productId = $_GET['id'] ?? null;
        try {
            if ($productId) {
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                
                if (!$product) {
                    sendResponse(['error' => 'Product not found'], 404);
                }
                
                sendResponse(['success' => true, 'product' => $product]);
            } else {
                $stmt = $pdo->query("SELECT * FROM products ORDER BY created_at DESC");
                $products = $stmt->fetchAll();
                sendResponse(['success' => true, 'products' => $products]);
            }
        } catch(PDOException $e) {
            sendResponse(['error' => 'Failed to fetch products'], 500);
        }
        break;

    case 'POST':
        // Create new product
        $data = getPostData();
        
        if (!isset($data['name']) || !isset($data['price']) || !isset($data['stock'])) {
            sendResponse(['error' => 'Missing required fields'], 400);
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO products (name, description, price, stock, category, image_url)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['description'] ?? '',
                $data['price'],
                $data['stock'],
                $data['category'] ?? null,
                $data['image_url'] ?? null
            ]);

            $productId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            sendResponse(['success' => true, 'product' => $product], 201);
        } catch(PDOException $e) {
            sendResponse(['error' => 'Failed to create product'], 500);
        }
        break;

    case 'PUT':
        // Update existing product
        $productId = $_GET['id'] ?? null;
        if (!$productId) {
            sendResponse(['error' => 'Product ID is required'], 400);
        }

        $data = getPostData();
        
        try {
            $fields = [];
            $values = [];
            
            // Build dynamic update query based on provided fields
            foreach (['name', 'description', 'price', 'stock', 'category', 'image_url'] as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                sendResponse(['error' => 'No fields to update'], 400);
            }

            $values[] = $productId;
            
            $stmt = $pdo->prepare("
                UPDATE products 
                SET " . implode(', ', $fields) . "
                WHERE id = ?
            ");
            
            $stmt->execute($values);
            
            if ($stmt->rowCount() === 0) {
                sendResponse(['error' => 'Product not found'], 404);
            }

            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            sendResponse(['success' => true, 'product' => $product]);
        } catch(PDOException $e) {
            sendResponse(['error' => 'Failed to update product'], 500);
        }
        break;

    case 'DELETE':
        // Delete product
        $productId = $_GET['id'] ?? null;
        if (!$productId) {
            sendResponse(['error' => 'Product ID is required'], 400);
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            
            if ($stmt->rowCount() === 0) {
                sendResponse(['error' => 'Product not found'], 404);
            }

            sendResponse(['success' => true, 'message' => 'Product deleted successfully']);
        } catch(PDOException $e) {
            sendResponse(['error' => 'Failed to delete product'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}
