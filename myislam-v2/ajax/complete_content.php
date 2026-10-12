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
    respond([
        'success' => false,
        'message' => 'Method not allowed'
    ], 405);
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    respond([
        'success' => false,
        'message' => 'Authentication required'
    ], 401);
}

$input = getJsonInput();

$contentId = isset($input['content_id']) ? (int)$input['content_id'] : 0;
$subjectId = isset($input['subject_id']) ? (int)$input['subject_id'] : null;

if ($contentId <= 0) {
    respond([
        'success' => false,
        'message' => 'Invalid content ID'
    ], 400);
}

require_once __DIR__ . '/../config/database.php';
// ========== ADDED: Include notification functions ==========
require_once __DIR__ . '/../includes/notifications.php';
// ===========================================================

$userId = (int)$_SESSION['user_id'];

try {
    $conn = db();

    if (!$conn instanceof PDO) {
        throw new RuntimeException('Database connection failed');
    }

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // -------------------------------------------------------------------------
    // 1. Validate content
    // -------------------------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT
            c.id,
            c.subject_id,
            c.slug,
            c.status,
            c.allow_auto_completion,
            c.allow_manual_completion
        FROM contents c
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$contentId]);
    $content = $stmt->fetch();

    if (!$content) {
        respond([
            'success' => false,
            'message' => 'Content not found'
        ], 404);
    }

    if (($content['status'] ?? '') !== 'published') {
        respond([
            'success' => false,
            'message' => 'This content is not published'
        ], 403);
    }

    $resolvedSubjectId = $subjectId ?: (isset($content['subject_id']) ? (int)$content['subject_id'] : null);

    // -------------------------------------------------------------------------
    // 2. Count lesson parts for proper progress completion
    // -------------------------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_parts
        FROM content_parts
        WHERE content_id = ?
    ");
    $stmt->execute([$contentId]);
    $partsCount = (int)$stmt->fetchColumn();
    $finalPart = $partsCount > 0 ? $partsCount : 1;

    // -------------------------------------------------------------------------
    // 3. Find lesson quiz related to this content
    // -------------------------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT
            q.id,
            q.subject_id,
            q.content_id,
            q.title,
            q.mode,
            q.status,
            q.passing_score,
            q.max_attempts,
            q.unlimited_attempts
        FROM quizzes q
        WHERE q.content_id = ?
          AND q.mode = 'lesson'
          AND q.status = 'published'
        ORDER BY q.created_at DESC, q.id DESC
        LIMIT 1
    ");
    $stmt->execute([$contentId]);
    $quiz = $stmt->fetch();

    if (!$quiz && !empty($resolvedSubjectId)) {
        $stmt = $conn->prepare("
            SELECT
                q.id,
                q.subject_id,
                q.content_id,
                q.title,
                q.mode,
                q.status,
                q.passing_score,
                q.max_attempts,
                q.unlimited_attempts
            FROM quizzes q
            WHERE q.subject_id = ?
              AND q.mode = 'lesson'
              AND q.status = 'published'
            ORDER BY q.created_at DESC, q.id DESC
            LIMIT 1
        ");
        $stmt->execute([$resolvedSubjectId]);
        $quiz = $stmt->fetch();
    }

    // -------------------------------------------------------------------------
    // 3.5. Enforce admin-controlled completion lock
    //
    // Two independent switches live on `contents`:
    //   allow_auto_completion   - lessons WITH a quiz complete automatically
    //                              once the quiz is passed
    //   allow_manual_completion - lessons WITHOUT a quiz complete via the
    //                              manual "Complete Lesson" button
    // Both default to 1 (enabled) when the columns aren't present yet, so
    // behavior is unchanged until an admin explicitly disables one from
    // admin_contents.php.
    // -------------------------------------------------------------------------
    $allowAutoCompletion = !array_key_exists('allow_auto_completion', $content) || (int)$content['allow_auto_completion'] === 1;
    $allowManualCompletion = !array_key_exists('allow_manual_completion', $content) || (int)$content['allow_manual_completion'] === 1;

    if ($quiz && !$allowAutoCompletion) {
        respond([
            'success' => false,
            'message' => 'Automatic completion via this lesson\'s quiz has been disabled by the admin. Please check back later.'
        ], 403);
    }

    if (!$quiz && !$allowManualCompletion) {
        respond([
            'success' => false,
            'message' => 'Completion has been disabled for this lesson by the admin. Please check back later.'
        ], 403);
    }

    // -------------------------------------------------------------------------
    // 4. If lesson quiz exists, require one completed passed attempt
    // -------------------------------------------------------------------------
    if ($quiz) {
        $quizId = (int)$quiz['id'];

        $stmt = $conn->prepare("
            SELECT
                qa.id,
                qa.attempt_number,
                qa.passed,
                qa.status,
                qa.score,
                qa.total_points,
                qa.percentage,
                qa.completed_at
            FROM quiz_attempts qa
            WHERE qa.user_id = ?
              AND qa.quiz_id = ?
              AND qa.status = 'completed'
            ORDER BY qa.attempt_number DESC, qa.completed_at DESC, qa.id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $quizId]);
        $latestAttempt = $stmt->fetch();

        if (!$latestAttempt) {
            respond([
                'success' => false,
                'message' => 'Complete the quiz before finishing this lesson',
                'requires_quiz' => true,
                'quiz_id' => $quizId
            ], 403);
        }

        if ((int)$latestAttempt['passed'] !== 1) {
            respond([
                'success' => false,
                'message' => 'You must pass the quiz before completing this lesson',
                'requires_quiz' => true,
                'quiz_id' => $quizId,
                'latest_attempt' => [
                    'attempt_id' => (int)$latestAttempt['id'],
                    'attempt_number' => (int)$latestAttempt['attempt_number'],
                    'score' => (int)$latestAttempt['score'],
                    'total_points' => (int)$latestAttempt['total_points'],
                    'percentage' => (float)$latestAttempt['percentage'],
                    'passed' => false
                ]
            ], 403);
        }
    }

    // -------------------------------------------------------------------------
    // 5. Mark lesson complete
    // -------------------------------------------------------------------------
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT
            ucp.id,
            ucp.user_id,
            ucp.content_id,
            ucp.current_part,
            ucp.is_started,
            ucp.is_completed
        FROM user_content_progress ucp
        WHERE ucp.user_id = ?
          AND ucp.content_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$userId, $contentId]);
    $existingProgress = $stmt->fetch();

    if ($existingProgress) {
        $stmt = $conn->prepare("
            UPDATE user_content_progress
            SET
                subject_id = COALESCE(subject_id, ?),
                is_started = 1,
                is_completed = 1,
                current_part = ?,
                progress_percent = 100,
                last_accessed_at = NOW(),
                completed_at = NOW(),
                updated_at = NOW()
            WHERE user_id = ?
              AND content_id = ?
        ");
        $stmt->execute([
            $resolvedSubjectId,
            $finalPart,
            $userId,
            $contentId
        ]);
    } else {
        // user_content_progress.id has NO AUTO_INCREMENT and NO PRIMARY KEY in
        // the current schema, so an INSERT that omits `id` either throws
        // "Field 'id' doesn't have a default value" (this is what was actually
        // producing the "Server error occurred" response) or silently writes
        // id=0. Compute the id ourselves, the same way quiz_submit.php already
        // does for quiz_attempts and content.php now does for its own insert
        // into this same table. We're already inside the transaction started
        // above, and the FOR UPDATE on MAX(id) keeps this safe under
        // concurrent completions.
        $nextIdStmt = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 FROM user_content_progress FOR UPDATE");
        $nextId = (int)$nextIdStmt->fetchColumn();

        $stmt = $conn->prepare("
            INSERT INTO user_content_progress (
                id,
                user_id,
                content_id,
                subject_id,
                is_started,
                is_completed,
                current_part,
                progress_percent,
                started_at,
                last_accessed_at,
                completed_at,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, 1, 1, ?, 100, NOW(), NOW(), NOW(), NOW(), NOW()
            )
        ");
        $stmt->execute([
            $nextId,
            $userId,
            $contentId,
            $resolvedSubjectId,
            $finalPart
        ]);
    }

    $conn->commit();

    // ========== ADDED: Progress milestone notification ==========
    // Count total completed lessons for this user
    $totalStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM user_content_progress
        WHERE user_id = ? AND is_completed = 1
    ");
    $totalStmt->execute([$userId]);
    $completedLessons = (int)$totalStmt->fetchColumn();

    // Send milestone notification if applicable (5, 10, 25, 50, 100)
    notifyProgressMilestone($conn, $userId, $completedLessons, 'lesson');
    // ===========================================================

    respond([
        'success' => true,
        'message' => 'Content marked as complete',
        'content_id' => $contentId,
        'subject_id' => $resolvedSubjectId,
        'final_part' => $finalPart,
        'completed_automatically' => true
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('complete_content.php error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Server error occurred',
        'error' => $e->getMessage()
    ], 500);
}