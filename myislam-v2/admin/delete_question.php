<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/admin_auth.php';

header('Content-Type: application/json');

$conn = db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        // Delete options first
        $stmt = $conn->prepare("DELETE FROM quiz_options WHERE question_id = ?");
        $stmt->execute([$id]);
        
        // Delete question
        $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>