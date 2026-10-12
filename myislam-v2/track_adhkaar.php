<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=UTF-8');

function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    respond(['success' => false, 'message' => 'Authentication required'], 401);
}

$userId = (int)$_SESSION['user_id'];
$input = getJsonInput();

$adhkaarId  = isset($input['adhkaar_id']) ? (int)$input['adhkaar_id'] : 0;
$categoryId = isset($input['category_id']) ? (int)$input['category_id'] : null;
$action     = isset($input['action']) ? trim((string)$input['action']) : '';

$allowedActions = ['read', 'listen', 'watch', 'complete'];
if ($adhkaarId <= 0 || !in_array($action, $allowedActions, true)) {
    respond(['success' => false, 'message' => 'Invalid tracking request.'], 400);
}

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Dar_es_Salaam');

try {
    $conn = db();

    // Confirm the item exists and pick up its category server-side too,
    // rather than trusting the client's category_id outright.
    $stmt = $conn->prepare("SELECT id, category_id FROM adhkaar WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$adhkaarId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        respond(['success' => false, 'message' => 'Adhkar item not found.'], 404);
    }
    $resolvedCategoryId = $item['category_id'] ?? $categoryId;

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    $readIncrement = $action === 'read' ? 1 : 0;
    $completeIncrement = $action === 'complete' ? 1 : 0;
    $listenedFlag = $action === 'listen' ? 1 : 0;
    $watchedFlag = $action === 'watch' ? 1 : 0;

    // Upsert: one row per user+item+day. On repeat activity the same day,
    // counters accumulate and the listen/watch flags only ever turn on
    // (GREATEST keeps a 1 from earlier today rather than overwriting it
    // back to 0 on an unrelated action).
    $stmt = $conn->prepare("
        INSERT INTO user_adhkaar_progress
            (user_id, adhkaar_id, category_id, practice_date, times_completed, read_count, listened, watched, first_activity_at, last_activity_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            times_completed = times_completed + VALUES(times_completed),
            read_count = read_count + VALUES(read_count),
            listened = GREATEST(listened, VALUES(listened)),
            watched = GREATEST(watched, VALUES(watched)),
            last_activity_at = VALUES(last_activity_at)
    ");
    $stmt->execute([
        $userId, $adhkaarId, $resolvedCategoryId, $today,
        $completeIncrement, $readIncrement, $listenedFlag, $watchedFlag,
        $now, $now
    ]);

    respond(['success' => true]);
} catch (Throwable $e) {
    error_log('track_adhkaar.php error: ' . $e->getMessage());
    // Tracking is best-effort - never let it surface as a hard failure to
    // the reading experience the user is actually there for.
    respond(['success' => false, 'message' => 'Could not record activity.'], 500);
}