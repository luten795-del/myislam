<?php
declare(strict_types=1);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['content_id']) || !isset($input['time_spent'])) {
    http_response_code(400);
    exit;
}

$contentId = (int)$input['content_id'];
$timeSpent = (int)$input['time_spent'];

require_once __DIR__ . '/../config/database.php';

try {
    $conn = db();
    if (!$conn) {
        throw new Exception('Connection failed');
    }
    
    $stmt = $conn->prepare("
        UPDATE user_content_progress 
        SET time_spent_seconds = time_spent_seconds + ?,
            last_accessed_at = NOW(),
            updated_at = NOW()
        WHERE user_id = ? AND content_id = ?
    ");
    $stmt->execute([$timeSpent, $userId, $contentId]);
    
    http_response_code(200);
} catch (Exception $e) {
    error_log('Track time error: ' . $e->getMessage());
    http_response_code(500);
}