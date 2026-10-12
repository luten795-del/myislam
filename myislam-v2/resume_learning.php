<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn) {
    die('Database connection error');
}

// Fetch the most recently accessed content that is not completed,
// or if all are completed, the last accessed one.
$stmt = $conn->prepare("
    SELECT
        c.slug,
        ucp.current_part
    FROM user_content_progress ucp
    JOIN contents c ON c.id = ucp.content_id
    WHERE ucp.user_id = ?
      AND c.status = 'published'
    ORDER BY ucp.last_accessed_at DESC, ucp.id DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$progress = $stmt->fetch(PDO::FETCH_ASSOC);

if ($progress) {
    $url = 'content.php?slug=' . urlencode($progress['slug']);
    if ($progress['current_part'] > 1) {
        $url .= '&part=' . (int)$progress['current_part'];
    }
    header('Location: ' . $url);
    exit;
}

// Fallback: if no progress found, go to subjects page
header('Location: subjects.php');
exit;