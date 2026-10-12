<?php
declare(strict_types=1);

/**
 * Create a single notification for a specific user.
 *
 * @param PDO    $conn    Database connection
 * @param int    $userId  Recipient user ID
 * @param string $type    One of: leaderboard, content, quiz, exam, lesson, subject, multimedia, announcement, system, category, certificate
 * @param string $title   Notification title
 * @param string $message Notification message body
 * @param string|null $link Optional URL to redirect when clicked
 *
 * @return int|false Returns the new notification ID on success, false on failure
 */
function createNotification(PDO $conn, int $userId, string $type, string $title, string $message, ?string $link = null): int|false
{
    $validTypes = [
        'leaderboard', 'content', 'quiz', 'exam', 'lesson', 'subject',
        'multimedia', 'announcement', 'system', 'category', 'certificate'
    ];
    if (!in_array($type, $validTypes, true)) {
        $type = 'system';
    }

    if ($link !== null && trim($link) === '') {
        $link = null;
    }

    // Verify user exists
    $checkStmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
    $checkStmt->execute([$userId]);
    if ($checkStmt->fetchColumn() === false) {
        error_log("createNotification: User ID $userId does not exist.");
        return false;
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $type, $title, $message, $link]);
        return (int) $conn->lastInsertId();
    } catch (PDOException $e) {
        error_log("Notification creation failed for user $userId: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a notification for all active users (optionally filtered by role(s)).
 *
 * @param PDO                 $conn    Database connection
 * @param string              $type    Notification type
 * @param string              $title   Notification title
 * @param string              $message Notification message
 * @param string|null         $link    Optional link
 * @param string|array|null   $role    Restrict to specific role(s).
 *
 * @return int Number of notifications inserted (0 if failed)
 */
function createNotificationForAll(PDO $conn, string $type, string $title, string $message, ?string $link = null, $role = null): int
{
    $validTypes = [
        'leaderboard', 'content', 'quiz', 'exam', 'lesson', 'subject',
        'multimedia', 'announcement', 'system', 'category', 'certificate'
    ];
    if (!in_array($type, $validTypes, true)) {
        $type = 'system';
    }

    if ($link !== null && trim($link) === '') {
        $link = null;
    }

    $sql = "
        INSERT INTO notifications (user_id, type, title, message, link, created_at)
        SELECT id, ?, ?, ?, ?, NOW()
        FROM users
        WHERE is_active = 1
    ";
    $params = [$type, $title, $message, $link];

    if ($role !== null) {
        if (is_array($role)) {
            $roles = array_filter($role, fn($r) => is_string($r) && trim($r) !== '');
            if (!empty($roles)) {
                $placeholders = implode(',', array_fill(0, count($roles), '?'));
                $sql .= " AND role IN ($placeholders)";
                $params = array_merge($params, $roles);
            }
        } elseif (is_string($role) && trim($role) !== '') {
            $sql .= " AND role = ?";
            $params[] = $role;
        }
    }

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Bulk notification creation failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Fetch paginated notifications for a user.
 *
 * @param PDO   $conn      Database connection
 * @param int   $userId    User ID
 * @param string $filter   'all', 'unread', or 'read'
 * @param string $type     Notification type or 'all'
 * @param int   $page      Page number (1-indexed)
 * @param int   $perPage   Items per page
 *
 * @return array{notifications: array, total: int, unreadCount: int}
 */
function getUserNotifications(PDO $conn, int $userId, string $filter = 'all', string $type = 'all', int $page = 1, int $perPage = 20): array
{
    $offset = ($page - 1) * $perPage;

    $where = ['user_id = ?'];
    $params = [$userId];

    if ($filter === 'unread') {
        $where[] = 'is_read = 0';
    } elseif ($filter === 'read') {
        $where[] = 'is_read = 1';
    }

    $validTypes = [
        'leaderboard', 'content', 'quiz', 'exam', 'lesson', 'subject',
        'multimedia', 'announcement', 'system', 'category', 'certificate'
    ];
    if ($type !== 'all' && in_array($type, $validTypes, true)) {
        $where[] = 'type = ?';
        $params[] = $type;
    }

    $whereClause = implode(' AND ', $where);

    // Total count
    $countSql = "SELECT COUNT(*) FROM notifications WHERE $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch paginated
    $sql = "
        SELECT id, type, title, message, link, is_read, created_at
        FROM notifications
        WHERE $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ";
    $fetchParams = array_merge($params, [$perPage, $offset]);
    $stmt = $conn->prepare($sql);
    $stmt->execute($fetchParams);
    $notifications = $stmt->fetchAll();

    // Unread count for badge
    $unreadStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$userId]);
    $unreadCount = (int) $unreadStmt->fetchColumn();

    return [
        'notifications' => $notifications,
        'total'         => $total,
        'unreadCount'   => $unreadCount,
    ];
}

/**
 * Notify user about a leaderboard achievement (rank improvement or top rank).
 *
 * @param PDO    $conn
 * @param int    $userId
 * @param string $mode    'lesson', 'competition', 'exam', or 'overall'
 * @param int    $newRank
 * @param int|null $oldRank (optional) – if provided, only notify if improved
 * @return void
 */
function notifyLeaderboardAchievement(PDO $conn, int $userId, string $mode, int $newRank, ?int $oldRank = null): void
{
    // Only notify if rank improved (smaller number) or first time ranking
    if ($oldRank !== null && $newRank >= $oldRank) {
        return;
    }

    $title = "🏆 Leaderboard Update!";
    $message = "You are now ranked #{$newRank} on the " . ucfirst($mode) . " leaderboard. Keep it up!";
    $link = "/leaderboard.php?mode=" . urlencode($mode);
    createNotification($conn, $userId, 'leaderboard', $title, $message, $link);
}

/**
 * Notify user about a progress milestone (e.g., completed 10 quizzes).
 *
 * @param PDO    $conn
 * @param int    $userId
 * @param int    $completedCount
 * @param string $type   'quiz', 'lesson', or 'exam'
 * @return void
 */
function notifyProgressMilestone(PDO $conn, int $userId, int $completedCount, string $type = 'quiz'): void
{
    $milestones = [5, 10, 25, 50, 100];
    if (!in_array($completedCount, $milestones, true)) {
        return;
    }

    $title = "🎉 Milestone Achieved!";
    $message = "You have completed {$completedCount} {$type}s. Great progress!";
    $link = $type === 'quiz' ? '/quizzes.php' : ($type === 'lesson' ? '/subjects.php' : '/monthly_exams.php');
    createNotification($conn, $userId, 'leaderboard', $title, $message, $link);
}


/**
 * Notify a user when a certificate is earned.
 */
function notifyCertificateEarned(
    PDO $conn,
    int $userId,
    string $certificateCode,
    string $certificateType = 'subject',
    ?string $subjectName = null
): void {
    $certificateCode = trim($certificateCode);
    if ($certificateCode === '') {
        return;
    }

    $link = '/certificate_view.php?code=' . urlencode($certificateCode);

    if ($certificateType === 'general') {
        $title = '🎓 General Certificate Earned';
        $message = 'Congratulations! You have earned your General Certificate for all subjects. You can now view, verify, and print it.';
    } else {
        $subjectLabel = trim((string)$subjectName) !== '' ? trim((string)$subjectName) : 'this subject';
        $title = '🎓 Subject Certificate Earned';
        $message = 'Congratulations! You have earned your certificate for ' . $subjectLabel . '. You can now view, verify, and print it.';
    }

    createNotification($conn, $userId, 'certificate', $title, $message, $link);
}

/**
 * Notify a user that a certificate path is ready.
 */
function notifyCertificateReady(
    PDO $conn,
    int $userId,
    string $certificateType = 'subject',
    ?int $subjectId = null,
    ?string $subjectName = null
): void {
    if ($certificateType === 'general') {
        $title = '🎓 General Certificate Path Ready';
        $message = 'You have completed the required learning progress. Take the general certification exam to earn your certificate.';
        $link = '/certification_exams.php?subject=general';
    } else {
        $subjectLabel = trim((string)$subjectName) !== '' ? trim((string)$subjectName) : 'this subject';
        $title = '🎓 Subject Certificate Path Ready';
        $message = 'You have completed the required learning progress for ' . $subjectLabel . '. Take the certification exam to earn your certificate.';
        $link = '/certification_exams.php' . ($subjectId ? '?subject=' . (int)$subjectId : '');
    }

    createNotification($conn, $userId, 'certificate', $title, $message, $link);
}

