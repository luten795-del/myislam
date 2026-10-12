<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['content_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $conn = db();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $contentId = (int)$input['content_id'];
    $subjectId = isset($input['subject_id']) ? (int)$input['subject_id'] : null;
    $completed = isset($input['completed']) && $input['completed'] === true;
    
    $stmt = $conn->prepare("
        INSERT INTO user_content_progress (
            user_id, content_id, subject_id, progress_percent, 
            is_started, is_completed, started_at, 
            completed_at, last_accessed_at
        ) VALUES (?, ?, ?, 100, 1, ?, NOW(), ?, NOW())
        ON DUPLICATE KEY UPDATE
            progress_percent = 100,
            is_completed = ?,
            completed_at = CASE WHEN is_completed = 0 AND ? = 1 THEN NOW() ELSE completed_at END,
            last_accessed_at = NOW(),
            updated_at = NOW()
    ");
    
    $stmt->execute([
        $userId,
        $contentId,
        $subjectId,
        $completed ? 1 : 0,
        $completed ? date('Y-m-d H:i:s') : null,
        $completed ? 1 : 0,
        $completed ? 1 : 0
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => $completed ? 'Content marked as complete' : 'Progress updated'
    ]);
    
} catch (Exception $e) {
    error_log('Update progress error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}