<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$materialId = $data['material_id'] ?? 0;
$userId = (int)$_SESSION['user_id'];

if ($materialId <= 0) {
    echo json_encode(['error' => 'Invalid material ID']);
    exit;
}

$conn = db();

try {
    // Update view count
    $conn->prepare("UPDATE category_materials SET view_count = view_count + 1 WHERE id = ?")->execute([$materialId]);
    
    // Track user view
    $stmt = $conn->prepare("
        INSERT INTO user_material_progress (user_id, material_id, last_viewed_at, view_count) 
        VALUES (?, ?, NOW(), 1) 
        ON DUPLICATE KEY UPDATE 
        view_count = view_count + 1, 
        last_viewed_at = NOW()
    ");
    $stmt->execute([$userId, $materialId]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>