<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

// Set default language if not set
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }

    return $letters !== '' ? $letters : 'U';
}

function tableExists(PDO $conn, string $table): bool
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);

    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function columnExists(PDO $conn, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);

    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn instanceof PDO) {
    die('Unable to connect to database.');
}

function getCurrentUser(PDO $conn, int $userId): ?array
{
    try {
        $sql = "
            SELECT id, full_name, email, profile_image, role, is_active, created_at
            FROM users
            WHERE id = :user_id AND is_active = 1
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    } catch (PDOException $e) {
        error_log('getCurrentUser error: ' . $e->getMessage());
        return null;
    }
}

function getQuizSummary(PDO $conn, int $userId): array
{
    try {
        $hasMode = columnExists($conn, 'quizzes', 'mode');

        if ($hasMode) {
            $quizSql = "
                SELECT
                    COUNT(*) AS total_quizzes,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_quizzes,
                    SUM(CASE WHEN status = 'published' AND mode = 'lesson' THEN 1 ELSE 0 END) AS lesson_quizzes,
                    SUM(CASE WHEN status = 'published' AND mode = 'competition' THEN 1 ELSE 0 END) AS competition_quizzes,
                    SUM(CASE WHEN status = 'published' AND mode = 'self' THEN 1 ELSE 0 END) AS self_quizzes
                FROM quizzes
            ";
        } else {
            $quizSql = "
                SELECT
                    COUNT(*) AS total_quizzes,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_quizzes,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS lesson_quizzes,
                    0 AS competition_quizzes,
                    0 AS self_quizzes
                FROM quizzes
            ";
        }

        $quizStmt = $conn->query($quizSql);
        $quizStats = $quizStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $hasPercentage = columnExists($conn, 'quiz_attempts', 'percentage');
        $avgScoreExpr = $hasPercentage
            ? "AVG(CASE WHEN status = 'completed' THEN percentage ELSE NULL END)"
            : "AVG(CASE WHEN status = 'completed' AND total_questions > 0 THEN (correct_answers * 100.0 / total_questions) ELSE NULL END)";

        $attemptSql = "
            SELECT
                COUNT(*) AS attempts_taken,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_attempts,
                {$avgScoreExpr} AS average_score_percent
            FROM quiz_attempts
            WHERE user_id = :user_id
        ";
        $attemptStmt = $conn->prepare($attemptSql);
        $attemptStmt->execute(['user_id' => $userId]);
        $attemptStats = $attemptStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_quizzes' => (int)($quizStats['total_quizzes'] ?? 0),
            'published_quizzes' => (int)($quizStats['published_quizzes'] ?? 0),
            'lesson_quizzes' => (int)($quizStats['lesson_quizzes'] ?? 0),
            'competition_quizzes' => (int)($quizStats['competition_quizzes'] ?? 0),
            'self_quizzes' => (int)($quizStats['self_quizzes'] ?? 0),
            'attempts_taken' => (int)($attemptStats['attempts_taken'] ?? 0),
            'completed_attempts' => (int)($attemptStats['completed_attempts'] ?? 0),
            'average_score_percent' => (float)($attemptStats['average_score_percent'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log('getQuizSummary error: ' . $e->getMessage());
        return [
            'total_quizzes' => 0,
            'published_quizzes' => 0,
            'lesson_quizzes' => 0,
            'competition_quizzes' => 0,
            'self_quizzes' => 0,
            'attempts_taken' => 0,
            'completed_attempts' => 0,
            'average_score_percent' => 0,
        ];
    }
}

function getSubjects(PDO $conn): array
{
    try {
        $sql = "
            SELECT id, name, slug, icon
            FROM subjects
            WHERE is_active = 1
            ORDER BY sort_order ASC, name ASC
        ";
        $stmt = $conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('getSubjects error: ' . $e->getMessage());
        return [];
    }
}

function getQuizzes(PDO $conn, int $userId): array
{
    try {
        $hasMode = columnExists($conn, 'quizzes', 'mode');
        $hasContentId = columnExists($conn, 'quizzes', 'content_id');
        $hasPercentage = columnExists($conn, 'quiz_attempts', 'percentage');
        $hasUnlimitedAttempts = columnExists($conn, 'quizzes', 'unlimited_attempts');
        $hasCompDates = columnExists($conn, 'quizzes', 'competition_start_at');
        $hasAllowAccess = columnExists($conn, 'quizzes', 'allow_access');

        $modeSelect = $hasMode ? "q.mode" : "'lesson' AS mode";
        $contentFields = $hasContentId
            ? ", q.content_id, c.slug AS content_slug, c.title AS content_title, c.category_id AS content_category_id, c.sort_order AS content_sort_order, cat.name AS content_category_name"
            : ", NULL AS content_id, NULL AS content_slug, NULL AS content_title, NULL AS content_category_id, NULL AS content_sort_order, NULL AS content_category_name";
        $unlimitedField = $hasUnlimitedAttempts ? "q.unlimited_attempts" : "0 AS unlimited_attempts";
        $compDateFields = $hasCompDates
            ? ", q.competition_start_at, q.competition_end_at"
            : ", NULL AS competition_start_at, NULL AS competition_end_at";
        $allowAccessField = $hasAllowAccess ? "q.allow_access" : "1 AS allow_access";

        $bestScoreExpr = $hasPercentage
            ? "MAX(qa.percentage)"
            : "MAX(CASE WHEN qa.total_questions > 0 THEN (qa.correct_answers * 100.0 / qa.total_questions) ELSE NULL END)";

        $sql = "
            SELECT
                q.id,
                q.subject_id,
                q.title,
                q.slug,
                q.description,
                q.difficulty,
                q.time_limit_minutes,
                q.total_points,
                q.max_attempts,
                {$unlimitedField},
                q.status,
                {$allowAccessField},
                q.created_at,
                q.published_at,
                {$modeSelect},
                s.name AS subject_name,
                s.icon AS subject_icon
                {$contentFields}
                {$compDateFields},
                COUNT(qa.id) AS attempts_taken,
                SUM(CASE WHEN qa.status = 'completed' THEN 1 ELSE 0 END) AS completed_attempts,
                {$bestScoreExpr} AS best_score_percent,
                MAX(qa.completed_at) AS last_attempt_at
            FROM quizzes q
            LEFT JOIN subjects s ON s.id = q.subject_id
            " . ($hasContentId ? "LEFT JOIN contents c ON c.id = q.content_id LEFT JOIN categories cat ON c.category_id = cat.id" : "") . "
            LEFT JOIN quiz_attempts qa
                ON qa.quiz_id = q.id
                AND qa.user_id = :user_id
            WHERE q.status = 'published'
            GROUP BY
                q.id,
                q.subject_id,
                q.title,
                q.slug,
                q.description,
                q.difficulty,
                q.time_limit_minutes,
                q.total_points,
                q.max_attempts,
                " . ($hasUnlimitedAttempts ? "q.unlimited_attempts," : "") . "
                q.status,
                " . ($hasAllowAccess ? "q.allow_access," : "") . "
                q.created_at,
                q.published_at,
                " . ($hasMode ? "q.mode," : "") . "
                s.name,
                s.icon
                " . ($hasContentId ? ", q.content_id, c.slug, c.title, c.category_id, c.sort_order, cat.name" : "") . "
                " . ($hasCompDates ? ", q.competition_start_at, q.competition_end_at" : "") . "
            ORDER BY COALESCE(q.published_at, q.created_at) DESC, q.id DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Determine content unlock status per CATEGORY
        $contentUnlockedMap = [];

        $categoryIds = [];
        foreach ($quizzes as $quiz) {
            if (($quiz['mode'] ?? '') === 'lesson' && !empty($quiz['content_id']) && !empty($quiz['content_category_id'])) {
                $categoryIds[] = (int)$quiz['content_category_id'];
            }
        }
        $categoryIds = array_unique($categoryIds);

        if (!empty($categoryIds)) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $stmt = $conn->prepare("
                SELECT c.id, c.category_id, COALESCE(ucp.is_completed, 0) AS is_completed
                FROM contents c
                LEFT JOIN user_content_progress ucp ON ucp.content_id = c.id AND ucp.user_id = ?
                WHERE c.category_id IN ($placeholders) AND c.status = 'published'
                ORDER BY c.category_id, c.sort_order ASC, c.published_at ASC
            ");
            $params = array_merge([$userId], $categoryIds);
            $stmt->execute($params);
            $allCategoryContents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            foreach ($allCategoryContents as $row) {
                $grouped[$row['category_id']][] = $row;
            }

            foreach ($grouped as $catId => $contents) {
                $prevCompleted = true;
                foreach ($contents as $c) {
                    $cid = (int)$c['id'];
                    $isUnlocked = $prevCompleted;
                    $contentUnlockedMap[$cid] = $isUnlocked;
                    $prevCompleted = ((int)$c['is_completed'] === 1);
                }
            }
        }

        $now = new DateTime();
        foreach ($quizzes as &$quiz) {
            $quizMode = strtolower((string)($quiz['mode'] ?? 'lesson'));
            $quiz['is_competition'] = ($quizMode === 'competition');
            $quiz['is_unjoined_competition'] = false;
            $quiz['competition_status'] = null;
            $quiz['is_locked'] = false;
            $quiz['lock_reason'] = null;

            // Admin access lock - overrides everything else regardless of
            // mode, publish status, or competition dates.
            $quiz['admin_locked'] = isset($quiz['allow_access']) && (int)$quiz['allow_access'] === 0;
            if ($quiz['admin_locked']) {
                $quiz['is_locked'] = true;
                $quiz['lock_reason'] = 'admin';
            }

            if ($quizMode === 'competition') {
                $start = $quiz['competition_start_at'] ?? null;
                $end   = $quiz['competition_end_at'] ?? null;
                if ($start && $end) {
                    $startDt = new DateTime($start);
                    $endDt   = new DateTime($end);
                    if ($now < $startDt) {
                        $quiz['competition_status'] = 'upcoming';
                    } elseif ($now > $endDt) {
                        $quiz['competition_status'] = 'ended';
                    } else {
                        $quiz['competition_status'] = 'live';
                    }
                } else {
                    $quiz['competition_status'] = 'live';
                }
                $attemptsTaken = (int)($quiz['attempts_taken'] ?? 0);
                $quiz['is_unjoined_competition'] = ($attemptsTaken === 0);
            } elseif ($quizMode === 'lesson' && !empty($quiz['content_id']) && !$quiz['is_locked']) {
                $contentId = (int)$quiz['content_id'];
                $quiz['is_locked'] = !($contentUnlockedMap[$contentId] ?? false);
                if ($quiz['is_locked']) {
                    $quiz['lock_reason'] = 'progress';
                }
            }
        }
        unset($quiz);

        usort($quizzes, function ($a, $b) {
            $aUnjoined = $a['is_unjoined_competition'] ?? false;
            $bUnjoined = $b['is_unjoined_competition'] ?? false;
            if ($aUnjoined && !$bUnjoined) return -1;
            if (!$aUnjoined && $bUnjoined) return 1;

            if ($aUnjoined && $bUnjoined) {
                $priority = ['upcoming' => 0, 'live' => 1, 'ended' => 2];
                $aStatus = $a['competition_status'] ?? 'live';
                $bStatus = $b['competition_status'] ?? 'live';
                $aPrio = $priority[$aStatus] ?? 1;
                $bPrio = $priority[$bStatus] ?? 1;
                if ($aPrio !== $bPrio) return $aPrio - $bPrio;
            }

            $aDate = strtotime($a['published_at'] ?? $a['created_at'] ?? 'now');
            $bDate = strtotime($b['published_at'] ?? $b['created_at'] ?? 'now');
            return $bDate - $aDate;
        });

        return $quizzes;
    } catch (PDOException $e) {
        error_log('getQuizzes error: ' . $e->getMessage());
        return [];
    }
}

function getQuizBackgroundImage(string $mode): ?string {
    $basePath = '/uploads/quizzes/';
    $documentRoot = $_SERVER['DOCUMENT_ROOT'];
    
    $modeImages = [
        'competition' => 'competition-bg.jpg',
        'lesson' => 'lesson-bg.jpg',
        'self' => 'self-bg.jpg',
    ];
    
    $filename = $modeImages[$mode] ?? 'default-bg.jpg';
    $fullPath = $documentRoot . $basePath . $filename;
    
    if (file_exists($fullPath)) {
        return $basePath . $filename;
    }
    
    return null;
}

function getHeroBackgroundImage(): ?string {
    $basePath = '/uploads/quizzes/';
    $documentRoot = $_SERVER['DOCUMENT_ROOT'];
    $filename = 'hero-bg.png';
    $fullPath = $documentRoot . $basePath . $filename;
    
    if (file_exists($fullPath)) {
        return $basePath . $filename;
    }
    
    return null;
}

function getTopRankedQuizParticipants(PDO $conn, int $userId, string $rangeStart, string $rangeEnd, int $limit = 3): array
{
    try {
        $sql = "
            SELECT
                u.id AS user_id,
                u.username,
                u.full_name,
                u.email,
                u.profile_image,
                COALESCE(qa_points.points, 0) AS total_points
            FROM users u
            LEFT JOIN (
                SELECT user_id, SUM(score) AS points
                FROM quiz_attempts
                WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
            ) qa_points ON qa_points.user_id = u.id
            WHERE u.is_active = 1
              AND u.id != ?
              AND COALESCE(qa_points.points, 0) > 0
            ORDER BY total_points DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(1, $rangeStart);
        $stmt->bindValue(2, $rangeEnd);
        $stmt->bindValue(3, $userId, PDO::PARAM_INT);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $userSql = "
            SELECT
                u.id AS user_id,
                u.username,
                u.full_name,
                u.email,
                u.profile_image,
                COALESCE(qa_points.points, 0) AS total_points,
                (
                    SELECT COUNT(*) + 1
                    FROM (
                        SELECT user_id, SUM(score) AS pts
                        FROM quiz_attempts
                        WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                        GROUP BY user_id
                    ) qa_all
                    WHERE qa_all.pts > (
                        SELECT COALESCE(SUM(score), 0)
                        FROM quiz_attempts
                        WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?
                    )
                ) AS rank_position
            FROM users u
            LEFT JOIN (
                SELECT user_id, SUM(score) AS points
                FROM quiz_attempts
                WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
            ) qa_points ON qa_points.user_id = u.id
            WHERE u.id = ?
        ";
        $userStmt = $conn->prepare($userSql);
        $userStmt->bindValue(1, $rangeStart);
        $userStmt->bindValue(2, $rangeEnd);
        $userStmt->bindValue(3, $userId);
        $userStmt->bindValue(4, $rangeStart);
        $userStmt->bindValue(5, $rangeEnd);
        $userStmt->bindValue(6, $rangeStart);
        $userStmt->bindValue(7, $rangeEnd);
        $userStmt->bindValue(8, $userId, PDO::PARAM_INT);
        $userStmt->execute();
        $userRow = $userStmt->fetch();

        if ($userRow && (float)($userRow['total_points'] ?? 0) > 0) {
            $userRow['rank'] = (int)($userRow['rank_position'] ?? 0);
            $rows[] = $userRow;
        }

        usort($rows, static function ($a, $b) {
            return ((float)$b['total_points']) <=> ((float)$a['total_points']);
        });

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
            if (!empty($row['username'])) {
                $row['display_name'] = $row['username'];
            } elseif (!empty($row['full_name'])) {
                $row['display_name'] = $row['full_name'];
            } else {
                $emailParts = explode('@', $row['email'] ?? '');
                $row['display_name'] = !empty($emailParts[0]) ? $emailParts[0] : 'User';
            }
        }

        return array_slice($rows, 0, 3);
    } catch (PDOException $e) {
        error_log("Error getting top ranked quiz participants: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

$user = getCurrentUser($conn, $userId);

if (!$user) {
    session_destroy();
    header('Location: login.php?error=account_disabled');
    exit;
}

$summary = getQuizSummary($conn, $userId);
$subjects = getSubjects($conn);
$quizzes = getQuizzes($conn, $userId);

// Get unread notifications
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist yet – ignore
}

// Get top ranked participants for the podium
$rangeStart = date('Y-m-d 00:00:00', strtotime('first day of this month'));
$rangeEnd = date('Y-m-d 23:59:59');
$topParticipants = getTopRankedQuizParticipants($conn, $userId, $rangeStart, $rangeEnd, 3);

$mainNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => true],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => true, 'icon' => '📝'],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Quizzes - MyIslam</title>
<style>
/* ===== RESET & BASE ===== */
*{box-sizing:border-box;margin:0;padding:0}

/* ===== CSS VARIABLES ===== */
:root{
    --bg:#f5f7f5;
    --surface:#ffffff;
    --surface-2:#f9fafb;
    --surface-3:#eef2f7;
    --line:#e2e5e2;
    --text:#1f2937;
    --text-secondary:#4b5563;
    --muted:#6b7280;
    --brand:#0E4A34;
    --brand-dark:#0A3626;
    --brand-light:#3E7A5E;
    --brand-soft:rgba(14, 74, 52, 0.08);
    --gold:#9C7A2E;
    --gold-soft:rgba(156, 122, 46, 0.10);
    --accent:#3E6E91;
    --accent-soft:rgba(62, 110, 145, 0.10);
    --header-bg:#ffffff;
    --header-height:72px;
    --shadow-sm:0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md:0 4px 6px -1px rgb(0 0 0 / 0.08);
    --shadow-lg:0 10px 15px -3px rgb(0 0 0 / 0.1);
    --radius-sm:12px;
    --radius-md:16px;
    --radius-lg:20px;
    --danger:#dc2626;
    --success:#0E4A34;
    --max-width:1600px;
    --content-width:95%;
    --text-base:16px;
    --text-lg:18px;
    --text-xl:22px;
    --text-2xl:28px;
    --text-sm:14px;
    --text-xs:12px;
    --rank-gold:#C6A64B;
    --rank-gold-soft:rgba(198, 166, 75, 0.14);
    --rank-diamond:#8FD3E8;
    --rank-diamond-soft:rgba(143, 211, 232, 0.16);
    --rank-tanzanite:#6C5CC0;
    --rank-tanzanite-soft:rgba(108, 92, 192, 0.14);
}

/* ===== DARK MODE ===== */
body.dark-mode{
    --bg:#0f1720;
    --surface:#18212b;
    --surface-2:#1d2833;
    --surface-3:#22303c;
    --line:#304150;
    --text:#edf2f7;
    --text-secondary:#cbd5e1;
    --muted:#94a3b8;
    --brand:#4C9172;
    --brand-dark:#6bb590;
    --brand-light:#7fcaa4;
    --brand-soft:rgba(76, 145, 114, 0.16);
    --gold:#C9A85E;
    --gold-soft:rgba(201, 168, 94, 0.16);
    --accent:#6FA0C4;
    --accent-soft:rgba(111, 160, 196, 0.16);
    --header-bg:#13202a;
    --danger:#ef4444;
    --rank-gold:#D9BC72;
    --rank-gold-soft:rgba(217, 188, 114, 0.18);
    --rank-diamond:#A9E4F2;
    --rank-diamond-soft:rgba(169, 228, 242, 0.18);
    --rank-tanzanite:#9285DB;
    --rank-tanzanite-soft:rgba(146, 133, 219, 0.18);
}

/* ===== BODY ===== */
body {
    font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.65;
    font-size: var(--text-base);
    -webkit-text-size-adjust: 100%;
}

body.dark-mode {
    background: var(--bg);
}

body::before {
    content: "";
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    pointer-events: none; z-index: -1;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.025' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
    background-repeat: repeat;
}
body.dark-mode::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
}

a{text-decoration:none;color:inherit} button,input,select{font:inherit}

/* ===== HEADER ===== */
.app-header {
    position: sticky; top: 0; z-index: 1000;
    height: var(--header-height);
    background: var(--header-bg);
    border-bottom: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 24px; gap: 18px;
}
.header-left, .header-right { display: flex; align-items: center; gap: 12px; }
.header-right { flex: 0 0 auto; position: relative; z-index: 1002; visibility: visible !important; }
.header-left { flex: 1; min-width: 0; }

.header-top-nav {
    display: flex; align-items: center; gap: 8px; flex-wrap: nowrap;
    overflow-x: auto; min-width: 0; scrollbar-width: thin; padding-bottom: 2px;
}
.header-top-link {
    height: 42px; padding: 0 14px; border-radius: 999px;
    background: var(--surface); border: 1px solid var(--line);
    display: inline-flex; align-items: center; justify-content: center;
    white-space: nowrap; font-size: 13px; font-weight: 700; transition: all 0.2s;
    color: var(--text-secondary);
}
.header-top-link:hover, .header-top-link.active {
    background: var(--brand-soft); border-color: var(--brand); color: var(--brand);
}

.menu-toggle, .icon-btn, .lang-btn, .brand-box {
    height: 40px; min-width: 30px; border-radius: 10px;
    background: var(--surface); border: 1px solid var(--line);
    display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
}
.menu-toggle{width:50px;}
.menu-toggle:hover, .icon-btn:hover, .lang-btn:hover {
    transform: translateY(-2px); box-shadow: var(--shadow-sm);
    border-color: var(--gold); color: var(--gold);
}
.profile-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }

.menu-toggle { font-size: 14px; }
.lang-btn { min-width: 30px; padding: 0 8px; font-weight: 600; color: var(--brand); }
.brand-box { font-size: 22px; font-weight: 700; color: var(--brand); background: transparent; border: none; white-space: nowrap; }
.icon-btn{font-size:18px; color:var(--text); position:relative;}

/* ===== PROFILE ===== */
.profile-area { position: relative; }
.profile-btn {
    width:44px; height:44px; border-radius:50%; overflow:hidden;
    background:var(--surface); border:2px solid var(--brand);
    cursor:pointer; padding:0; display:flex; align-items:center; justify-content:center;
    color:var(--brand); font-weight:700;
}
.profile-btn img { width:100%; height:100%; object-fit:cover; }
.profile-menu {
    position:absolute; top:56px; right:0; width:280px;
    background:var(--glass-bg-strong); backdrop-filter:blur(12px);
    border:1px solid var(--line); border-radius:var(--radius-lg);
    padding:16px; display:none; box-shadow:var(--shadow-lg); z-index:1001;
}
.profile-menu.show { display:block; }
.profile-menu-top {
    display:flex; gap:12px; padding-bottom:10px; margin-bottom:10px;
    border-bottom:1px solid var(--line);
}
.profile-menu-top img, .profile-avatar-fallback {
    width:56px; height:56px; border-radius:50%;
}
.profile-avatar-fallback {
    display:inline-flex; align-items:center; justify-content:center;
    background:var(--brand-soft); color:var(--brand); font-weight:700; font-size:16px;
}
.profile-menu-top h4 { font-size:16px; margin-bottom:4px; font-weight:bold; color:var(--text); }
.profile-menu-top p { font-size:12px; color:var(--text-secondary); font-weight:600; }
.profile-menu a {
    display:block; padding:10px 12px; border-radius:10px; font-weight:700;
    font-size:14px; transition:background 0.2s; margin:4px 0; color:var(--text);
}
.profile-menu a:hover { background:var(--brand-soft); color:var(--brand); }
.notification-badge {
    position:absolute; top:-4px; right:-4px;
    background:var(--danger); color:white;
    font-size:10px; font-weight:700; min-width:18px; height:18px;
    border-radius:999px; display:flex; align-items:center; justify-content:center;
    padding:0 4px; border:2px solid var(--surface);
}

/* ===== SIDEBAR ===== */
.sidebar-overlay {
    display:none; position:fixed; top:var(--header-height); left:0;
    width:100%; height:calc(100vh - var(--header-height));
    background:var(--overlay-dim); z-index:1100; backdrop-filter:blur(2px);
}
.sidebar-overlay.show { display:block; }
.sidebar {
    position:fixed; top:var(--header-height); left:-100%;
    width:min(320px,85vw); height:calc(100vh - var(--header-height));
    background:var(--glass-bg-strong); backdrop-filter:blur(12px);
    border-right:1px solid var(--line); padding:24px 20px;
    z-index:1200; transition:left 0.3s ease; overflow-y:scroll;
    overscroll-behavior:contain; -webkit-overflow-scrolling:touch; scrollbar-gutter:stable;
}
.sidebar.open { left:0; }
.sidebar-title {
    font-size:12px; color:var(--muted); text-transform:uppercase;
    margin-bottom:20px; font-weight:700; letter-spacing:1px; padding:0 8px;
}
.subject-list { display:grid; gap:8px; }
.subject-link {
    display:flex; align-items:center; gap:12px; padding:12px 16px;
    border-radius:var(--radius-md); background:var(--surface-2);
    border:1px solid var(--line); font-size:14px; font-weight:500; transition:all 0.2s;
    color:var(--text);
}
.subject-link:hover { background:var(--brand-soft); border-color:var(--brand); transform:translateX(4px); }
.subject-link.active {
    background: var(--brand);
    color: var(--white);
    border-color: var(--brand-dark);
    box-shadow: inset 4px 0 0 var(--gold), 0 4px 12px rgba(14, 74, 52, 0.18);
    font-weight: 800;
}
.subject-link.active span:first-child {
    filter: saturate(1.25) brightness(1.25);
}
.sidebar-toggle-btn {
    position:fixed; top:var(--header-height); left:0; z-index:1300;
    display:inline-flex; align-items:center; justify-content:center;
    width:36px; height:36px; background:var(--surface);
    border:1px solid var(--line); border-left:none; border-top:none;
    border-radius:0 0 8px 0; font-size:16px; cursor:pointer;
    color:var(--text); transition:all 0.2s; box-shadow:var(--shadow-sm);
    padding:0; margin:0;
}
.sidebar-toggle-btn:hover { background:var(--brand-soft); color:var(--brand); }
#themeToggle, #langToggle, #menuToggle { visibility:visible !important; opacity:1 !important; }
.sidebar-toggle-btn { display:inline-flex !important; visibility:visible !important; opacity:1 !important; }

/* ===== MAIN CONTENT - WIDER ===== */
.main-content {
    max-width: var(--max-width);
    width: var(--content-width);
    margin: 0 auto;
    padding: 24px 16px 20px;
}
.page-stack{display:grid;gap:20px;}

/* ===== SECTION CARDS ===== */
.section-card{
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
}
.section-card:hover{
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* ===== HERO CARD ===== */
.hero-card{
    background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%);
    border: 1px solid var(--line);
    padding: 24px 28px;
    position: relative;
    overflow: hidden;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
}

.hero-card[style*="background-image"]::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(135deg, rgba(0,0,0,0.5), rgba(0,0,0,0.7));
    z-index: 0;
    border-radius: inherit;
}
.hero-card[style*="background-image"] > * {
    position: relative;
    z-index: 1;
}
.hero-card[style*="background-image"] h1,
.hero-card[style*="background-image"] p {
    color: white !important;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}
.hero-card[style*="background-image"] p {
    color: rgba(255,255,255,0.9) !important;
}

.hero-card h1{
    font-size: var(--text-2xl);
    margin-bottom: 8px;
    font-weight: 800;
}
.hero-card p{color:var(--text-secondary); font-size:var(--text-base);}

/* ===== STATS GRID ===== */
.stats-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:16px;
    padding:0;
    border:none;
    background:transparent;
}
.stat-card{
    display:grid;
    grid-template-columns:48px 1fr;
    align-items:center;
    gap:12px;
    text-align:left;
    padding:14px 16px;
    background:var(--surface);
    border:1px solid var(--line);
    border-radius:var(--radius-md);
    transition:all 0.2s;
}
.stat-card:hover{
    transform:translateY(-2px);
    box-shadow:var(--shadow-sm);
}
.stat-icon{
    width:48px;
    height:48px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:24px;
    background:var(--surface-2);
}
.stat-content{min-width:0;}
.stat-value{
    font-size:24px;
    font-weight:800;
    color:var(--gold);
    line-height:1.1;
    margin-bottom:4px;
}
.stat-label{
    font-size:12px;
    color:var(--muted);
    line-height:1.3;
    font-weight:600;
}

/* ===== PODIUM ===== */
.podium-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: start;
    gap: 10px;
    margin-top: 8px;
}
.podium-card {
    min-width: 0;
    text-align: center;
    padding: 20px 14px 16px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--line);
    position: relative;
    transition: transform 0.2s;
    margin-top: 40px;
}
.podium-card:hover { transform: translateY(-4px); }
.podium-first  { order: 2; margin-top: 0; }
.podium-second { order: 3; margin-top: 40px; }
.podium-third  { order: 1; margin-top: 40px; }
.podium-first {
    background: linear-gradient(160deg, var(--rank-gold-soft) 0%, var(--surface) 100%);
    border-color: var(--rank-gold);
    padding-top: 26px;
    padding-bottom: 22px;
    box-shadow: var(--shadow-lg);
}
.podium-first:hover { transform: translateY(-8px); }
.podium-second {
    background: linear-gradient(160deg, var(--rank-diamond-soft) 0%, var(--surface) 100%);
    border-color: var(--rank-diamond);
}
.podium-third {
    background: linear-gradient(160deg, var(--rank-tanzanite-soft) 0%, var(--surface) 100%);
    border-color: var(--rank-tanzanite);
}
.podium-avatar {
    width: 64px; height: 64px; border-radius: 50%;
    margin: 0 auto 10px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 18px; color: var(--white);
    background: linear-gradient(135deg, var(--brand-light) 0%, var(--brand) 100%);
    overflow: hidden;
    border: 3px solid var(--surface);
    box-shadow: var(--shadow-sm);
}
.podium-first .podium-avatar { width: 76px; height: 76px; border-color: var(--rank-gold); }
.podium-second .podium-avatar { border-color: var(--rank-diamond); }
.podium-third .podium-avatar { border-color: var(--rank-tanzanite); }
.podium-avatar img { width: 100%; height: 100%; object-fit: cover; }
.podium-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 800; letter-spacing: 0.5px;
    text-transform: uppercase;
    padding: 4px 12px;
    border-radius: 999px;
    margin-bottom: 10px;
    color: var(--white);
    white-space: nowrap;
}
.podium-first .podium-badge  { background: var(--rank-gold); }
.podium-second .podium-badge { background: var(--rank-diamond); color: #0f1720; }
.podium-third .podium-badge  { background: var(--rank-tanzanite); }
.podium-name {
    font-size: 14px; font-weight: 700; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.podium-points {
    font-size: 13px; color: var(--muted); margin-top: 2px;
}
.podium-you {
    box-shadow: 0 0 0 2px var(--brand), var(--shadow-lg);
}
.period-label {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--muted);
    background: var(--surface-3);
    border-radius: 999px;
    padding: 3px 10px;
    margin-left: 6px;
    vertical-align: middle;
}

/* ===== FILTERS ===== */
.filter-row{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr;
    gap:12px;
}
.search-wrap,.select-wrap{position:relative}
.search-input,.filter-select{
    width:100%;
    height:48px;
    border-radius:var(--radius-md);
    border:1px solid var(--line);
    background:var(--surface-2);
    color:var(--text);
    padding:0 14px;
    outline:none;
    font-size:14px;
    transition:all .2s;
}
.search-input{padding-left:44px}
.search-input:focus,.filter-select:focus{
    border-color:var(--brand);
    background:var(--surface);
    box-shadow:0 0 0 3px rgba(14,74,52,.08);
}
.search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:16px}

/* ===== QUIZ GRID ===== */
.quiz-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:4px;}
.quiz-card{
    position:relative;
    background:var(--surface-2);
    border:1px solid var(--line);
    border-radius:var(--radius-md);
    padding:20px;
    transition:all 0.2s;
    overflow:hidden;
    background-size:cover !important;
    background-position:center !important;
    background-repeat:no-repeat !important;
}
.quiz-card:hover{
    transform:translateY(-4px);
    box-shadow:var(--shadow-md);
    border-color:var(--brand);
}
.quiz-card.quiz-attempted{
    border-color:var(--brand);
}
.quiz-card.quiz-locked {
    opacity:0.85;
    border-color:var(--danger);
}
.quiz-card[style*="background-image"]::before {
    content:'';
    position:absolute;
    top:0; left:0; right:0; bottom:0;
    background:linear-gradient(135deg, rgba(0,0,0,0.3), rgba(0,0,0,0.6));
    border-radius:inherit;
    z-index:0;
    pointer-events:none;
}
.quiz-card[style*="background-image"] > * {
    position:relative;
    z-index:1;
}
.quiz-card[data-mode-type="competition"][style*="background-image"] { border-color: #f59e0b; }
.quiz-card[data-mode-type="lesson"][style*="background-image"] { border-color: #10b981; }
.quiz-card[data-mode-type="self"][style*="background-image"] { border-color: #8b5cf6; }
.quiz-card[style*="background-image"] .quiz-title,
.quiz-card[style*="background-image"] .quiz-desc,
.quiz-card[style*="background-image"] .quiz-meta-row span,
.quiz-card[style*="background-image"] .quiz-meta-row strong {
    color: white !important;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}
.quiz-card[style*="background-image"] .quiz-desc {
    color: rgba(255,255,255,0.85) !important;
}
.quiz-card[style*="background-image"] .quiz-badge {
    background: rgba(0,0,0,0.7) !important;
    backdrop-filter: blur(4px);
    color: white !important;
    border-color: rgba(255,255,255,0.3);
}
.quiz-card[style*="background-image"] .quiz-badge.locked-badge {
    background: rgba(127,29,29,0.9) !important;
    color: #fecaca !important;
}
.quiz-card[style*="background-image"] .quiz-tick {
    background: rgba(0,0,0,0.7) !important;
    backdrop-filter: blur(4px);
}
.quiz-card[style*="background-image"] .quiz-btn.primary { background: var(--brand); }
.quiz-card[style*="background-image"] .quiz-btn.secondary {
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    border-color: rgba(255,255,255,0.3);
    color: white;
}
.quiz-card[style*="background-image"] .quiz-btn.primary.attempted {
    background: rgba(0,0,0,0.6);
    color: white;
    border-color: rgba(255,255,255,0.3);
}

.quiz-tick{
    position:absolute;
    top:12px;
    right:12px;
    z-index:2;
    padding:6px 14px;
    border-radius:10px;
    background:var(--brand);
    color:#fff;
    font-size:11px;
    font-weight:800;
    letter-spacing:.2px;
    box-shadow:var(--shadow-sm);
}
.quiz-top{
    display:flex;
    align-items:flex-start;
    justify-content:flex-start;
    gap:5px;
    margin-bottom:10px;
    flex-wrap:wrap;
}
.quiz-badge{
    padding:4px 8px;
    border-radius:8px;
    font-size:11px;
    font-weight:700;
    background:var(--brand-soft);
    color:var(--brand);
    border:1px solid transparent;
}
.quiz-badge.mode-lesson{background:#d1fae5;color:#065f46}
.quiz-badge.mode-competition{background:#fef3c7;color:#92400e}
.quiz-badge.mode-self{background:#ede9fe;color:#5b21b6}
.quiz-badge.locked-badge{background:#fee2e2;color:#991b1b;border-color:var(--danger)}
body.dark-mode .quiz-badge.locked-badge{background:#7f1d1d;color:#fecaca}
.quiz-title{font-size:24px;font-weight:700;margin-bottom:4px;}
.quiz-desc{font-size:14px;color:var(--muted);line-height:1.6;margin-bottom:4px;min-height:0px;}
.quiz-meta{display:grid;gap:10px;margin-bottom:16px}
.quiz-meta-row{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:13px}
.quiz-meta-row span:first-child{color:var(--muted)}
.quiz-actions{display:flex;gap:10px;flex-direction:row;}
.quiz-btn{
    flex:1;
    text-align:center;
    padding:10px 12px;
    border-radius:10px;
    font-weight:700;
    font-size:13px;
    transition:all .2s;
    border:none;
    cursor:pointer;
}
.quiz-btn.primary{background:var(--brand);color:#fff;}
.quiz-btn.primary.attempted{
    background:var(--surface-3);
    color:var(--muted);
    border:1px solid var(--line);
    cursor:pointer;
}
.quiz-btn.primary.attempted:hover{
    transform:translateY(-2px);
    background:var(--surface-2);
}
.quiz-btn.locked-btn {
    background:var(--surface-3);
    color:var(--muted);
    border:1px solid var(--danger);
    cursor:not-allowed;
    opacity:0.7;
}
.quiz-btn.locked-btn:hover { transform:none; }
.quiz-btn.secondary{
    background:var(--surface);
    border:1px solid var(--line);
    color:var(--text);
}
.quiz-btn:hover{transform:translateY(-2px)}

.empty-state,.search-empty-state{text-align:center;padding:50px 20px;color:var(--muted)}
.empty-state .emoji,.search-empty-state .emoji{font-size:50px;margin-bottom:12px}
.search-empty-state{display:none;}

/* ===== RESPONSIVE ===== */
@media (max-width:1400px){
    .main-content{width:98%;padding:20px 12px;}
}
@media (max-width:1200px){
    .header-top-nav{max-width:48vw;}
    .quiz-grid{grid-template-columns:repeat(2,1fr);}
}
@media (max-width:992px){
    .stats-grid{grid-template-columns:repeat(2,1fr);}
}
@media (max-width:900px){
    .header-top-nav{display:none;}
    .main-content{width:100%;padding:16px 10px;}
}
@media (max-width:768px){
    :root{
        --header-height:64px;
        --text-base:15px;
        --text-lg:16px;
        --text-xl:18px;
        --text-2xl:22px;
        --text-sm:13px;
        --text-xs:11px;
    }
    .main-content{padding:12px 8px 20px;width:100%;}
    .section-card{padding:14px 16px;}
    .hero-card{padding:16px 18px;}
    .hero-card h1{font-size:var(--text-2xl);}
    .stats-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
    .stat-card{grid-template-columns:40px 1fr;padding:12px;}
    .stat-icon{width:40px;height:40px;font-size:20px;border-radius:12px;}
    .stat-value{font-size:20px;}
    .filter-row{grid-template-columns:1fr;gap:10px;}
    .quiz-grid{grid-template-columns:1fr;gap:16px;}
    .quiz-title{padding-right:0px;}
    .quiz-actions{flex-direction:row;}
    .podium-grid{gap:6px;}
    .podium-card{padding:12px 6px 10px;margin-top:26px;}
    .podium-first{padding-top:16px;padding-bottom:14px;margin-top:0;}
    .podium-second,.podium-third{margin-top:26px;}
    .podium-avatar{width:44px;height:44px;font-size:13px;margin-bottom:6px;}
    .podium-first .podium-avatar{width:54px;height:54px;}
    .podium-badge{font-size:8px;padding:3px 6px;margin-bottom:6px;}
    .podium-name{font-size:11px;}
    .podium-points{font-size:10px;}
}
@media (max-width:480px){
    :root{
        --text-base:14px;
        --text-lg:15px;
        --text-xl:17px;
        --text-2xl:20px;
        --text-sm:12px;
        --text-xs:10px;
    }
    .stat-card{grid-template-columns:36px 1fr;padding:10px 12px;}
    .stat-icon{width:36px;height:36px;font-size:18px;}
    .stat-value{font-size:18px;}
    .podium-badge span.badge-label{display:none;}
}
@media print{
    .header,.sidebar-toggle-btn,.sidebar-overlay,.sidebar{display:none !important;}
    .section-card{box-shadow:none !important;transform:none !important;}
}
#quizStatsGrid {
    display: none;
    margin-top: 16px;
}

#quizStatsGrid.show {
    display: grid;
}
.filter-toggle {
    width: 100%;
    min-height: 50px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 0 16px;

    background: var(--surface);
    color: var(--brand);

    border: 1px solid var(--line);
    border-radius: 10px;

    cursor: pointer;
    font-size: 16px;
    font-weight: 700;
}

.filter-content {
    display: none;
    margin-top: 16px;
}

.filter-content.show {
    display: block;
}
</style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<header class="app-header">
    <div class="header-left">
        <a href="user_dashboard.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
            <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px;">
            <span style="font-size:22px;font-weight:1000;color:var(--brand);">MyIslam</span>
        </a>
        <nav class="header-top-nav" aria-label="<?php echo e(t('main_navigation')); ?>">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>">
                    <?php echo e($navItem['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="header-right">
        <button type="button" class="icon-btn" id="themeToggle" aria-label="<?php echo e(t('dark_mode')); ?>" title="<?php echo e(t('dark_mode')); ?>">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="<?php echo e(t('notifications_label')); ?>" title="<?php echo e(t('notifications_label')); ?>">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="<?php echo e(t('open_profile')); ?>" aria-expanded="false">
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="<?php echo e($user['profile_image']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials((string)$user['full_name'])); ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['profile_image'])): ?>
                        <img src="<?php echo e($user['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)$user['full_name'])); ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?php echo e($user['full_name'] ?? 'User'); ?></h4>
                        <p><?php echo e($user['email'] ?? ''); ?></p>
                        <p style="font-size:10px;">Member since <?php echo !empty($user['created_at']) ? e(date('F Y', strtotime($user['created_at']))) : 'recently'; ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 <?php echo t('profile'); ?></a>
                <a href="settings.php">⚙️ <?php echo t('settings'); ?></a>
                <a href="logout.php">🚪 <?php echo t('logout'); ?></a>
            </div>
        </div>
    </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" aria-label="<?php echo e(t('open_navigation')); ?>" aria-expanded="false" aria-controls="sidebar">☰</button>
<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">📚 <?php echo e(t('navigation')); ?></div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon']); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-stack">
            <?php
            $heroBgImage = getHeroBackgroundImage();
            $hasHeroBg = !empty($heroBgImage);
            ?>
            <section class="hero-card section-card"
                <?php if ($hasHeroBg && $heroBgImage): ?>
                style="background-image: linear-gradient(135deg, rgba(0,0,0,0.3), rgba(0,0,0,0.6)), url('<?php echo e($heroBgImage); ?>'); background-size: cover; background-position: center; background-repeat: no-repeat;"
                <?php endif; ?>
            >
                <h1>📝 <?php echo e(t('quizzes')); ?></h1>
            </section>

       <!-- Stats Grid - Open / Close -->
<div class="section-card">

    <button type="button" class="stats-toggle" id="quizStatsToggle">
        <span>📊 <?php echo e(t('quiz_summary')); ?></span>
        <span id="quizStatsIcon">+</span>
    </button>

    <div class="stats-grid" id="quizStatsGrid" aria-label="Quiz summary">

        <div class="stat-card">
            <div class="stat-icon">📖</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo (int)$summary['lesson_quizzes']; ?></div>
                <div class="stat-label"><?php echo e(t('lesson_quizzes')); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">🏆</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo (int)$summary['competition_quizzes']; ?></div>
                <div class="stat-label"><?php echo e(t('competitions')); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">🔍</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo (int)$summary['self_quizzes']; ?></div>
                <div class="stat-label"><?php echo e(t('self_quizzes')); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📝</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo (int)$summary['published_quizzes']; ?></div>
                <div class="stat-label"><?php echo e(t('total_active')); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📌</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo (int)$summary['attempts_taken']; ?></div>
                <div class="stat-label"><?php echo e(t('attempts_taken')); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📈</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo (int)round($summary['average_score_percent']); ?>%</div>
                <div class="stat-label"><?php echo e(t('average_score')); ?></div>
            </div>
        </div>

    </div>
</div>

           

            <!-- Filters -->
            <!-- Filters - Open / Close -->
<section class="section-card">

    <button type="button" class="filter-toggle" id="quizFilterToggle">
        <span>🔎 <?php echo e(t('filters')); ?></span>
        <span id="quizFilterIcon">+</span>
    </button>

    <div class="filter-content" id="quizFilterContent">

        <div class="filter-row">

            <div class="search-wrap">
                <span class="search-icon">🔍</span>

                <input
                    type="text"
                    id="quizSearchInput"
                    class="search-input"
                    placeholder="<?php echo e(t('search_quizzes')); ?>"
                >
            </div>

            <div class="select-wrap">
                <select id="subjectFilter" class="filter-select">
                    <option value="">
                        <?php echo e(t('all_subjects')); ?>
                    </option>

                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?php echo strtolower(e($subject['name'] ?? '')); ?>">
                            <?php echo e($subject['name'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="select-wrap">
                <select id="modeFilter" class="filter-select">
                    <option value="">
                        <?php echo e(t('all_modes')); ?>
                    </option>
                    <option value="lesson">📖 Lesson</option>
                    <option value="competition">🏆 Competition</option>
                    <option value="self">🔍 Self</option>
                </select>
            </div>

            <div class="select-wrap">
                <select id="statusFilter" class="filter-select">
                    <option value="">
                        <?php echo e(t('all_status')); ?>
                    </option>
                    <option value="attempted">✅ Attempted</option>
                    <option value="joined">🏆 Joined</option>
                    <option value="not_attempted">🕓 Not Attempted</option>
                    <option value="locked">🔒 Locked</option>
                </select>
            </div>

        </div>

    </div>

</section>

             <!-- Top Ranked Participants Podium -->
            <?php if (!empty($topParticipants)): ?>
            <section class="section-card">
                <h3>🏆 <?php echo e(t('top_quiz_participants')); ?> <span class="period-label"><?php echo e(t('this_month')); ?></span></h3>
                <div class="podium-grid">
                    <?php foreach ($topParticipants as $participant):
                        $rank = (int)($participant['rank'] ?? 0);
                        $tierClass = $rank === 1 ? 'podium-first' : ($rank === 2 ? 'podium-second' : 'podium-third');
                        $tierLabel = $rank === 1 ? '🥇' : ($rank === 2 ? '💎' : '🔷');
                        $isCurrentUser = (int)($participant['user_id'] ?? 0) === $userId;
                        $pName = $isCurrentUser ? 'You' : ($participant['display_name'] ?? 'User');
                        $pPicture = $participant['profile_image'] ?? '';
                        $pPoints = (float)($participant['total_points'] ?? 0);
                    ?>
                        <div class="podium-card <?php echo $tierClass; ?><?php echo $isCurrentUser ? ' podium-you' : ''; ?>">
                            <div class="podium-badge">#<?php echo $rank; ?> <span class="badge-label">· <?php echo e($tierLabel); ?></span></div>
                            <div class="podium-avatar">
                                <?php if (!empty($pPicture)): ?>
                                    <img src="<?php echo e($pPicture); ?>" alt="<?php echo e($pName); ?>">
                                <?php else: ?>
                                    <?php echo e(initials($pName)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="podium-name"><?php echo e($pName); ?></div>
                            <div class="podium-points"><?php echo number_format($pPoints, 0); ?> pts</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Quiz Grid -->
            <?php if (!empty($quizzes)): ?>
                <section class="quiz-grid" id="quizGrid">
                    <?php foreach ($quizzes as $quiz): ?>
                        <?php
                        $title = $quiz['title'] ?? 'Untitled Quiz';
                        $description = trim((string)($quiz['description'] ?? ''));
                        if ($description === '') {
                            $description = '';
                        }

                        $difficulty = ucfirst((string)($quiz['difficulty'] ?? 'medium'));
                        $subjectName = $quiz['subject_name'] ?? 'General';
                        $attemptsTaken = (int)($quiz['attempts_taken'] ?? 0);
                        $completedAttempts = (int)($quiz['completed_attempts'] ?? 0);
                        $bestScore = $quiz['best_score_percent'] !== null
                            ? (int)round((float)$quiz['best_score_percent'])
                            : null;
                        $timeLimit = (int)($quiz['time_limit_minutes'] ?? 0);
                        $maxAttempts = (int)($quiz['max_attempts'] ?? 0);
                        $unlimitedAttempts = (bool)($quiz['unlimited_attempts'] ?? false);
                        $totalPoints = (int)($quiz['total_points'] ?? 0);
                        $quizMode = strtolower((string)($quiz['mode'] ?? 'lesson'));

                        // Background Image
                        $bgImage = null;
                        $hasBgImage = false;
                        $modeBgImagePath = '/uploads/quizzes/';
                        $documentRoot = $_SERVER['DOCUMENT_ROOT'];
                        
                        switch($quizMode) {
                            case 'competition': $modeBgFile = 'competition-bg.jpg'; break;
                            case 'lesson': $modeBgFile = 'lesson-bg.png'; break;
                            case 'self': $modeBgFile = 'self-bg.png'; break;
                            default: $modeBgFile = 'default-bg.png';
                        }
                        
                        $fullBgPath = $documentRoot . $modeBgImagePath . $modeBgFile;
                        if (file_exists($fullBgPath)) {
                            $bgImage = $modeBgImagePath . $modeBgFile;
                            $hasBgImage = true;
                        }

                        if (!in_array($quizMode, ['lesson', 'competition', 'self'], true)) {
                            $quizMode = 'lesson';
                        }

                        $modeLabel = ucfirst($quizMode);
                        $modeClass = 'mode-' . $quizMode;

                        $contentSlug = $quiz['content_slug'] ?? null;
                        $hasDirectContent = !empty($quiz['content_id']) && !empty($contentSlug);

                        $isLocked = $quiz['is_locked'] ?? false;
                        $isAdminLocked = $quiz['admin_locked'] ?? false;

                        if ($quizMode === 'lesson' && $hasDirectContent) {
                            $primaryLink = 'content.php?slug=' . urlencode($contentSlug);
                        } else {
                            $primaryLink = !empty($quiz['slug'])
                                ? 'admin/quiz_take.php?slug=' . urlencode((string)$quiz['slug'])
                                : 'admin/quiz_take.php?id=' . (int)$quiz['id'];
                        }

                        if ($quizMode === 'self') {
                            $isAttempted = ($completedAttempts > 0);
                        } else {
                            $isAttempted = ($attemptsTaken > 0);
                        }
                        $isCompetition = ($quizMode === 'competition');
                        $competitionStatus = $quiz['competition_status'] ?? null;

                        if ($isAdminLocked) {
                            $primaryText = '🚫 Unavailable';
                            $btnClass = 'quiz-btn locked-btn';
                            $isLink = false;
                        } elseif ($isLocked) {
                            $primaryText = '🔒 Locked';
                            $btnClass = 'quiz-btn locked-btn';
                            $isLink = false;
                        } elseif ($isCompetition) {
                            if ($isAttempted) {
                                $primaryText = '🏆 Joined';
                                $btnClass = 'quiz-btn primary attempted';
                            } else {
                                if ($competitionStatus === 'ended') {
                                    $primaryText = '🏁 Ended';
                                    $btnClass = 'quiz-btn primary attempted';
                                } else {
                                    $primaryText = '🏆 Join Competition';
                                    $btnClass = 'quiz-btn primary';
                                }
                            }
                            $isLink = true;
                        } else {
                            if ($quizMode === 'lesson' && $hasDirectContent) {
                                $primaryText = '📖 Go to Lesson';
                            } elseif ($quizMode === 'self') {
                                $primaryText = $isAttempted ? '✅ Attempted' : '🔍 Start Quiz';
                            } else {
                                $primaryText = '📝 Start Quiz';
                            }
                            $btnClass = $isAttempted ? 'quiz-btn primary attempted' : 'quiz-btn primary';
                            $isLink = true;
                        }

                        if ($isAdminLocked) {
                            $statusValue = 'locked';
                            $statusLabel = 'Unavailable';
                            $tickLabel = '🚫 Unavailable';
                        } elseif ($isLocked) {
                            $statusValue = 'locked';
                            $statusLabel = 'Locked';
                            $tickLabel = '🔒 Locked';
                        } elseif ($isAttempted) {
                            if ($isCompetition) {
                                $statusValue = 'joined';
                                $statusLabel = 'Joined';
                                $tickLabel = '🏆 Joined';
                            } else {
                                $statusValue = 'attempted';
                                $statusLabel = 'Attempted';
                                $tickLabel = '✔ Attempted';
                            }
                        } else {
                            $statusValue = 'not_attempted';
                            $statusLabel = 'Not Attempted';
                            $tickLabel = '';
                        }

                        $leaderboardLink = 'leaderboard.php?mode=' . urlencode($quizMode);
                        $searchBlob = strtolower(
                            $title . ' ' .
                            $description . ' ' .
                            $difficulty . ' ' .
                            $subjectName . ' ' .
                            $quizMode . ' ' .
                            $totalPoints . ' ' .
                            $statusValue . ' ' .
                            $statusLabel
                        );
                        ?>
                        <article
                            class="quiz-card quiz-search-item <?php echo $isAttempted ? 'quiz-attempted' : ''; ?> <?php echo $isLocked ? 'quiz-locked' : ''; ?>"
                            data-search="<?php echo e($searchBlob); ?>"
                            data-subject="<?php echo strtolower(e($subjectName)); ?>"
                            data-mode="<?php echo e($quizMode); ?>"
                            data-status="<?php echo e($statusValue); ?>"
                            data-mode-type="<?php echo e($quizMode); ?>"
                            <?php if ($hasBgImage && $bgImage): ?>
                            style="background-image: linear-gradient(135deg, rgba(0,0,0,0.3), rgba(0,0,0,0.6)), url('<?php echo e($bgImage); ?>'); background-size: cover; background-position: center; background-repeat: no-repeat;"
                            <?php endif; ?>
                        >
                            <?php if ($isAttempted || $isLocked): ?>
                                <div class="quiz-tick" style="<?php echo $isLocked ? 'background: var(--danger);' : ''; ?>"><?php echo e($tickLabel); ?></div>
                            <?php endif; ?>

                            <div class="quiz-top">
                                <div class="quiz-badge"><?php echo e($difficulty); ?></div>
                                <div class="quiz-badge"><?php echo e($quiz['subject_icon'] ?? '📚'); ?> <?php echo e($subjectName); ?></div>
                                <?php if ($quizMode === 'lesson' && !empty($quiz['content_category_name'])): ?>
                                    <div class="quiz-badge">📁 <?php echo e($quiz['content_category_name']); ?></div>
                                <?php endif; ?>

                                <?php if ($isCompetition): ?>
                                    <div class="quiz-badge mode-competition">🏆 Competition</div>
                                    <?php if ($competitionStatus): ?>
                                        <?php
                                        $statusText = '';
                                        if ($competitionStatus === 'upcoming') {
                                            $statusText = '⏳ Upcoming';
                                        } elseif ($competitionStatus === 'live') {
                                            $statusText = '🔴 Live';
                                        } else {
                                            $statusText = '🏁 Ended';
                                        }
                                        ?>
                                        <div class="quiz-badge mode-competition"><?php echo $statusText; ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="quiz-badge <?php echo e($modeClass); ?>"><?php echo e($modeLabel); ?></div>
                                <?php endif; ?>
                                <?php if ($isAdminLocked): ?>
                                    <div class="quiz-badge locked-badge" title="This quiz has been made unavailable by the admin">🚫 Unavailable</div>
                                <?php elseif ($isLocked): ?>
                                    <div class="quiz-badge locked-badge">🔒 Locked</div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; justify-content:center; align-items:center;">
                            <h3 class="quiz-title"><?php echo e($title); ?></h3>
                                </div>
                            <p class="quiz-desc"><?php echo e($description); ?></p>

                            <div class="quiz-meta">
                                <div class="quiz-meta-row">
                                    <span>Mode</span>
                                    <strong><?php echo e($modeLabel); ?></strong>
                                </div>
                                <div class="quiz-meta-row">
                                    <span>Status</span>
                                    <strong><?php echo e($statusLabel); ?></strong>
                                </div>
                                <div class="quiz-meta-row">
                                    <span>Time Limit</span>
                                    <strong><?php echo $timeLimit > 0 ? $timeLimit . ' mins' : 'No limit'; ?></strong>
                                </div>
                                <div class="quiz-meta-row">
                                    <span>Total Points</span>
                                    <strong><?php echo number_format($totalPoints); ?></strong>
                                </div>
                                <div class="quiz-meta-row">
                                    <span>Attempts</span>
                                    <strong>
                                        <?php
                                        if ($unlimitedAttempts) {
                                            echo $attemptsTaken . ' / ∞';
                                        } elseif ($maxAttempts > 0) {
                                            echo $attemptsTaken . ' / ' . $maxAttempts;
                                        } else {
                                            echo $attemptsTaken;
                                        }
                                        ?>
                                    </strong>
                                </div>
                                <div class="quiz-meta-row">
                                    <span>Best Score</span>
                                    <strong><?php echo $bestScore !== null ? $bestScore . '%' : '--'; ?></strong>
                                </div>
                                <?php if ($isCompetition && !empty($quiz['competition_start_at']) && !empty($quiz['competition_end_at'])): ?>
                                    <div class="quiz-meta-row">
                                        <span>📅 Window</span>
                                        <strong><?php echo date('M d, Y H:i', strtotime($quiz['competition_start_at'])); ?> – <?php echo date('M d, Y H:i', strtotime($quiz['competition_end_at'])); ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="quiz-actions">
                                <?php if ($isLink): ?>
                                    <a href="<?php echo e($primaryLink); ?>" class="<?php echo e($btnClass); ?>">
                                        <?php echo e($primaryText); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="<?php echo e($btnClass); ?>">
                                        <?php echo e($primaryText); ?>
                                    </span>
                                <?php endif; ?>
                                <a href="<?php echo e($leaderboardLink); ?>" class="quiz-btn secondary">🏅 Leaderboard</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="search-empty-state" id="quizEmptyState">
                    <div class="emoji">🔎</div>
                    <h3 style="margin-bottom:8px;"><?php echo e(t('no_matching_quiz')); ?></h3>
                    <p>Try another keyword, subject, mode, or status.</p>
                </section>
            <?php else: ?>
                <section class="section-card empty-state">
                    <div class="emoji">📝</div>
                    <h3 style="margin-bottom:8px;"><?php echo e(t('no_quizzes_available')); ?></h3>
                    <p>There are no published quizzes right now. Check back later!</p>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function() {
    const body = document.body;
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const themeToggle = document.getElementById('themeToggle');
    const langToggle = document.getElementById('langToggle');
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    const profileArea = document.getElementById('profileArea');
    const quizSearchInput = document.getElementById('quizSearchInput');
    const subjectFilter = document.getElementById('subjectFilter');
    const modeFilter = document.getElementById('modeFilter');
    const statusFilter = document.getElementById('statusFilter');
    const quizItems = document.querySelectorAll('.quiz-search-item');
    const quizEmptyState = document.getElementById('quizEmptyState');
    const quizGrid = document.getElementById('quizGrid');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (sidebarOverlay) sidebarOverlay.classList.add('show');
        body.style.overflow = 'hidden';
        menuToggle.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        body.style.overflow = '';
        menuToggle.setAttribute('aria-expanded', 'false');
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (sidebar && sidebar.classList.contains('open')) {
                closeSidebar();
            }
            if (profileMenu && profileMenu.classList.contains('show')) {
                profileMenu.classList.remove('show');
                profileToggle?.setAttribute('aria-expanded', 'false');
            }
        }
    });

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        body.classList.add('dark-mode');
        if (themeToggle) themeToggle.textContent = '☀';
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            themeToggle.textContent = isDark ? '☀' : '🌙';
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }

    let currentLang = localStorage.getItem('language') || '<?php echo $_SESSION['language'] ?? "en"; ?>';
    if (langToggle) {
        langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
        langToggle.addEventListener('click', async () => {
            currentLang = currentLang === 'en' ? 'sw' : 'en';
            langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
            localStorage.setItem('language', currentLang);
            
            try {
                await fetch('ajax/switch_language.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ language: currentLang })
                });
                location.reload();
            } catch (e) {
                console.error('Language switch error:', e);
            }
        });
    }

    if (profileToggle && profileMenu) {
        profileToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = profileMenu.classList.contains('show');
            profileMenu.classList.toggle('show', !isOpen);
            profileToggle.setAttribute('aria-expanded', String(!isOpen));
        });

        document.addEventListener('click', function(e) {
            if (!profileArea.contains(e.target)) {
                profileMenu.classList.remove('show');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function applyQuizFilters() {
        const query = (quizSearchInput?.value || '').trim().toLowerCase();
        const subjectValue = (subjectFilter?.value || '').trim().toLowerCase();
        const modeValue = (modeFilter?.value || '').trim().toLowerCase();
        const statusValue = (statusFilter?.value || '').trim().toLowerCase();
        let visible = 0;

        quizItems.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            const itemSubject = item.getAttribute('data-subject') || '';
            const itemMode = item.getAttribute('data-mode') || '';
            const itemStatus = item.getAttribute('data-status') || '';

            const matchesSearch = query === '' || haystack.includes(query);
            const matchesSubject = subjectValue === '' || itemSubject === subjectValue;
            const matchesMode = modeValue === '' || itemMode === modeValue;
            const matchesStatus = statusValue === '' || itemStatus === statusValue;

            const show = matchesSearch && matchesSubject && matchesMode && matchesStatus;

            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (quizGrid) {
            quizGrid.style.display = visible === 0 ? 'none' : 'grid';
        }
        if (quizEmptyState) {
            quizEmptyState.style.display = visible === 0 ? 'block' : 'none';
        }
    }

    quizSearchInput?.addEventListener('input', applyQuizFilters);
    subjectFilter?.addEventListener('change', applyQuizFilters);
    modeFilter?.addEventListener('change', applyQuizFilters);
    statusFilter?.addEventListener('change', applyQuizFilters);

    document.querySelectorAll('img').forEach(img => {
        img.addEventListener('error', function() {
            this.style.display = 'none';
        });
    });
})();
const quizStatsToggle = document.getElementById('quizStatsToggle');
const quizStatsGrid = document.getElementById('quizStatsGrid');
const quizStatsIcon = document.getElementById('quizStatsIcon');

quizStatsToggle.addEventListener('click', function () {

    quizStatsGrid.classList.toggle('show');

    if (quizStatsGrid.classList.contains('show')) {
        quizStatsIcon.textContent = '−';
    } else {
        quizStatsIcon.textContent = '+';
    }

});
const quizFilterToggle = document.getElementById('quizFilterToggle');
const quizFilterContent = document.getElementById('quizFilterContent');
const quizFilterIcon = document.getElementById('quizFilterIcon');

quizFilterToggle.addEventListener('click', function () {

    quizFilterContent.classList.toggle('show');

    if (quizFilterContent.classList.contains('show')) {
        quizFilterIcon.textContent = '−';
    } else {
        quizFilterIcon.textContent = '+';
    }

});
</script>

</body>
</html>