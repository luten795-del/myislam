<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/admin_auth.php';

function deleteLocalMediaFile(?string $path): void {
    if (empty($path)) return;
    if (strpos($path, 'uploads/adhkaar/') !== 0) return;
    $full = __DIR__ . '/../' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = $_GET['token'] ?? '';

if ($id <= 0) {
    header('Location: admin_adhkaar.php?error=' . urlencode('Invalid adhkar.'));
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
    header('Location: admin_adhkaar.php?error=' . urlencode('Security check failed - please try the delete link again.'));
    exit;
}

$conn = db();

try {
    $stmt = $conn->prepare('SELECT audio_path, video_path FROM adhkaar WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        header('Location: admin_adhkaar.php?error=' . urlencode('That adhkar no longer exists.'));
        exit;
    }

    $stmt = $conn->prepare('DELETE FROM adhkaar WHERE id = ?');
    $stmt->execute([$id]);

    // Clean up any locally-uploaded audio/video so files don't pile up on disk
    deleteLocalMediaFile($row['audio_path'] ?? null);
    deleteLocalMediaFile($row['video_path'] ?? null);

    header('Location: admin_adhkaar.php?deleted=1');
    exit;
} catch (Throwable $e) {
    error_log('delete_adhkar: ' . $e->getMessage());
    header('Location: admin_adhkaar.php?error=' . urlencode('Database error while deleting.'));
    exit;
}