<?php
declare(strict_types=1);
session_start();

// Set default language if not set
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'sw';
}

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================

$databaseConfigFile = __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/includes/course_helpers.php';

if (!file_exists($databaseConfigFile)) {
    if (!is_dir(__DIR__ . '/config')) {
        mkdir(__DIR__ . '/config', 0777, true);
    }

    $configContent = <<<'PHP'
<?php
declare(strict_types=1);

function db(): ?PDO {
    static $connection = null;

    if ($connection === null) {
        try {
            $host = 'localhost';
            $port = '3307';
            $dbname = 'myislam';
            $username = 'root';
            $password = '';

            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }

    return $connection;
}
PHP;

    file_put_contents($databaseConfigFile, $configContent);
}

require_once $databaseConfigFile;

// ============================================================================
// HELPERS
// ============================================================================

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : 'U';
}

function formatDateValue(?string $date, string $format = 'M d, Y'): string
{
    if (empty($date)) return '';
    $time = strtotime($date);
    return $time ? date($format, $time) : '';
}

function normalizeUploadPath(?string $path): ?string
{
    if (empty($path)) return null;
    if (preg_match('~^(https?:)?//~i', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

function extractYoutubeId(?string $url): ?string
{
    if (empty($url)) return null;
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
        return $m[1];
    }
    return null;
}

function formatDurationShort(int $seconds): string
{
    if ($seconds <= 0) return '—';
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0 && $minutes > 0) return $hours . 'h ' . $minutes . 'm';
    if ($hours > 0) return $hours . 'h';
    return $minutes . 'm';
}

function progressScoreColorVar(float $score): string
{
    if ($score >= 80) return 'var(--gold)';
    if ($score >= 50) return 'var(--brand)';
    return 'var(--danger)';
}

function assignCompetitionRanks(array &$rows, string $pointsKey = 'total_points'): void
{
    $rank = 0;
    $seen = 0;
    $prevPoints = null;
    foreach ($rows as &$row) {
        $seen++;
        $points = (float)($row[$pointsKey] ?? 0);
        if ($prevPoints === null || $points < $prevPoints) {
            $rank = $seen;
            $prevPoints = $points;
        }
        $row['rank'] = $rank;
    }
    unset($row);
}

function tableExists(PDO $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

// ============================================================================
// AUTHENTICATION
// ============================================================================

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ============================================================================
// DATABASE CONNECTION
// ============================================================================

try {
    $conn = db();
    if (!$conn) throw new Exception("Database connection failed");
} catch (Throwable $e) {
    error_log("Database error: " . $e->getMessage());
    die("System error. Unable to connect to database. Please try again later.");
}

// ============================================================================
// DATA FUNCTIONS
// ============================================================================

function getCurrentUser(PDO $conn, int $userId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, username, full_name, email, profile_image, role, is_active, is_verified, created_at
            FROM users WHERE id = ? AND is_active = 1 LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("Error getting user: " . $e->getMessage());
        return null;
    }
}

function getActiveAnnouncement(PDO $conn, string $role = 'user'): ?array
{
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            SELECT id, title, message, audience_role, created_at
            FROM announcements
            WHERE is_published = 1
              AND (audience_role = 'all' OR audience_role = ?)
              AND (starts_at IS NULL OR starts_at <= ?)
              AND (ends_at IS NULL OR ends_at >= ?)
            ORDER BY created_at DESC, id DESC LIMIT 1
        ");
        $stmt->execute([$role, $now, $now]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("Error getting announcement: " . $e->getMessage());
        return null;
    }
}

function getOverallProgress(PDO $conn, int $userId): array
{
    try {
        $stmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT c.id) AS total_items,
                COUNT(DISTINCT CASE WHEN ucp.is_completed = 1 THEN c.id END) AS completed_items,
                COALESCE(AVG(ucp.progress_percent), 0) AS avg_progress,
                COALESCE(SUM(ucp.time_spent_seconds), 0) AS total_time_spent
            FROM contents c
            LEFT JOIN user_content_progress ucp ON ucp.content_id = c.id AND ucp.user_id = ?
            WHERE c.status = 'published'
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();

        $totalItems = (int)($result['total_items'] ?? 0);
        $completedItems = (int)($result['completed_items'] ?? 0);
        $result['overall_progress'] = $totalItems > 0
            ? (int) round(($completedItems / $totalItems) * 100)
            : 0;
        return $result;
    } catch (PDOException $e) {
        error_log("Error getting progress: " . $e->getMessage());
        return ['total_items'=>0,'completed_items'=>0,'overall_progress'=>0,'total_time_spent'=>0,'avg_progress'=>0];
    }
}

/**
 * Leaderboard stats — LESSON/SELF ONLY. Competition excluded.
 * The "overall" mode here means "any non-competition quiz".
 */
function getLeaderboardStatsByMode(PDO $conn, int $userId, string $mode, string $rangeStart, string $rangeEnd): array
{
    $default = [
        'mode' => $mode, 'user_id' => $userId,
        'total_points' => 0, 'quizzes_taken' => 0, 'quizzes_completed' => 0,
        'average_score' => 0, 'best_score' => 0,
        'total_correct_answers' => 0, 'total_wrong_answers' => 0,
        'total_time_seconds' => 0, 'rank_position' => 0, 'total_participants' => 0,
    ];

    try {
        // Only lesson or self; "overall" = both
        if ($mode === 'overall') {
            $modeCondition = "AND q.mode IN ('lesson','self')";
        } elseif (in_array($mode, ['lesson', 'self'], true)) {
            $modeCondition = "AND q.mode = :mode";
        } else {
            return $default;
        }

        $sql = "
            SELECT
                qa.user_id,
                SUM(qa.score) AS total_points,
                COUNT(*) AS quizzes_taken,
                SUM(CASE WHEN qa.status = 'completed' THEN 1 ELSE 0 END) AS quizzes_completed,
                AVG(qa.percentage) AS average_score,
                MAX(qa.percentage) AS best_score,
                SUM(qa.correct_answers) AS total_correct_answers,
                SUM(GREATEST(qa.total_questions - qa.correct_answers, 0)) AS total_wrong_answers,
                SUM(TIMESTAMPDIFF(SECOND, qa.started_at, qa.completed_at)) AS total_time_seconds
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            WHERE qa.status = 'completed'
              AND qa.completed_at BETWEEN :range_start AND :range_end
              $modeCondition
            GROUP BY qa.user_id
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':range_start', $rangeStart);
        $stmt->bindValue(':range_end', $rangeEnd);
        if ($mode !== 'overall') {
            $stmt->bindValue(':mode', $mode);
        }
        $stmt->execute();
        $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($allRows, static fn($a, $b) => ((float)$b['total_points']) <=> ((float)$a['total_points']));
        assignCompetitionRanks($allRows, 'total_points');

        $rank = 0;
        $userStats = null;
        foreach ($allRows as $row) {
            if ((int)$row['user_id'] === $userId) {
                $rank = (int)$row['rank'];
                $userStats = $row;
                break;
            }
        }

        $default['total_participants'] = count($allRows);
        if (!$userStats) return $default;

        $userStats['mode'] = $mode;
        $userStats['rank_position'] = $rank;
        $userStats['total_participants'] = count($allRows);
        return array_merge($default, $userStats);
    } catch (PDOException $e) {
        error_log("Error getting leaderboard stats: " . $e->getMessage());
        return $default;
    }
}

function getSelfModeStats(PDO $conn, int $userId, string $rangeStart, string $rangeEnd): array
{
    try {
        $stmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT qa.quiz_id) AS quizzes_completed,
                SUM(qa.score) AS total_points,
                AVG(qa.percentage) AS average_score,
                SUM(TIMESTAMPDIFF(SECOND, qa.started_at, qa.completed_at)) AS total_time_seconds
            FROM quiz_attempts qa
            JOIN quizzes q ON qa.quiz_id = q.id
            WHERE qa.user_id = ? AND qa.status = 'completed' AND q.mode = 'self'
              AND qa.completed_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $rangeStart, $rangeEnd]);
        $stats = $stmt->fetch();

        $rankStmt = $conn->prepare("
            SELECT COUNT(*) + 1 AS rank_position
            FROM (
                SELECT user_id, SUM(score) AS total_points
                FROM quiz_attempts qa
                JOIN quizzes q ON qa.quiz_id = q.id
                WHERE qa.status = 'completed' AND q.mode = 'self'
                  AND qa.completed_at BETWEEN ? AND ?
                GROUP BY user_id
                HAVING SUM(score) > (
                    SELECT COALESCE(SUM(score), 0)
                    FROM quiz_attempts qa2
                    JOIN quizzes q2 ON qa2.quiz_id = q2.id
                    WHERE qa2.user_id = ? AND qa2.status = 'completed' AND q2.mode = 'self'
                      AND qa2.completed_at BETWEEN ? AND ?
                )
            ) AS better
        ");
        $rankStmt->execute([$rangeStart, $rangeEnd, $userId, $rangeStart, $rangeEnd]);
        $rank = $rankStmt->fetch();

        $totalStmt = $conn->prepare("
            SELECT COUNT(DISTINCT user_id) AS total_participants
            FROM quiz_attempts qa
            JOIN quizzes q ON qa.quiz_id = q.id
            WHERE q.mode = 'self' AND qa.status = 'completed'
              AND qa.completed_at BETWEEN ? AND ?
        ");
        $totalStmt->execute([$rangeStart, $rangeEnd]);
        $total = $totalStmt->fetch();

        return [
            'quizzes_completed' => (int)($stats['quizzes_completed'] ?? 0),
            'total_points' => (float)($stats['total_points'] ?? 0),
            'average_score' => (float)($stats['average_score'] ?? 0),
            'total_time_seconds' => (int)($stats['total_time_seconds'] ?? 0),
            'rank_position' => (int)($rank['rank_position'] ?? 0),
            'total_participants' => (int)($total['total_participants'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log("Error getting self mode stats: " . $e->getMessage());
        return ['quizzes_completed'=>0,'total_points'=>0,'average_score'=>0,'total_time_seconds'=>0,'rank_position'=>0,'total_participants'=>0];
    }
}

function getTodayReminder(PDO $conn, int $userId, string $role = 'user'): ?array
{
    try {
        $announcement = getActiveAnnouncement($conn, $role);
        if ($announcement && !empty($announcement['message'])) {
            return ['message' => $announcement['title'] . ': ' . $announcement['message'], 'is_motivational' => false];
        }
        return ['message' => t('motivation'), 'is_motivational' => true];
    } catch (Throwable $e) {
        return ['message' => t('motivation')];
    }
}

function getResumeState(PDO $conn, int $userId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT c.id, c.title, c.slug, s.name AS subject_name, s.slug AS subject_slug, s.id AS subject_id
            FROM user_content_progress ucp
            JOIN contents c ON ucp.content_id = c.id
            LEFT JOIN subjects s ON ucp.subject_id = s.id
            WHERE ucp.user_id = ? AND ucp.last_accessed_at IS NOT NULL
            ORDER BY ucp.last_accessed_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $lastContent = $stmt->fetch();
        if ($lastContent) {
            return [
                'resume_label' => $lastContent['title'] ?? 'Continue learning',
                'resume_type' => 'content',
                'last_content_id' => $lastContent['id'] ?? null,
                'last_content_slug' => $lastContent['slug'] ?? null,
            ];
        }
        return ['resume_label' => 'Start your first lesson today!'];
    } catch (PDOException $e) {
        return ['resume_label' => 'Continue your learning journey'];
    }
}

function getUserStreak(PDO $conn, int $userId): int
{
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT DATE(last_accessed_at) AS activity_date
            FROM user_content_progress
            WHERE user_id = ? AND last_accessed_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
        ");
        $stmt->execute([$userId]);
        $activityDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($activityDates)) return 0;

        $activityDays = array_flip($activityDates);
        $streak = 0;
        $cursor = new DateTime('today');
        if (!isset($activityDays[$cursor->format('Y-m-d')])) {
            $cursor->modify('-1 day');
        }
        while (isset($activityDays[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor->modify('-1 day');
        }
        return $streak;
    } catch (PDOException $e) {
        return 0;
    }
}

function getTodayProgress(PDO $conn, int $userId): int
{
    try {
        $today = date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT COUNT(CASE WHEN is_completed = 1 THEN 1 END) AS completed, COUNT(*) AS total
            FROM user_content_progress
            WHERE user_id = ? AND DATE(last_accessed_at) = ?
        ");
        $stmt->execute([$userId, $today]);
        $result = $stmt->fetch();
        if ($result && (int)$result['total'] > 0) {
            return min(100, (int)round(((int)$result['completed'] / (int)$result['total']) * 100));
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS has_activity FROM user_activity_logs WHERE user_id = ? AND DATE(created_at) = ?");
        $stmt->execute([$userId, $today]);
        $activity = $stmt->fetch();
        if ($activity && (int)$activity['has_activity'] > 0) return 10;
        return 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function getSubjects(PDO $conn, int $userId): array
{
    try {
        $stmt = $conn->prepare("
            SELECT
                s.id, s.name, s.type, s.slug, s.description, s.icon, s.sort_order, s.is_active,
                COUNT(DISTINCT ucp.content_id) AS total,
                COUNT(DISTINCT CASE WHEN ucp.is_completed = 1 THEN ucp.content_id END) AS completed
            FROM subjects s
            LEFT JOIN user_content_progress ucp ON ucp.subject_id = s.id AND ucp.user_id = ?
            WHERE s.is_active = 1
            GROUP BY s.id, s.name, s.type, s.slug, s.description, s.icon, s.sort_order, s.is_active
            ORDER BY s.sort_order ASC, s.name ASC
        ");
        $stmt->execute([$userId]);
        $subjects = $stmt->fetchAll();
        foreach ($subjects as &$subject) {
            $total = (int)($subject['total'] ?? 0);
            $completed = (int)($subject['completed'] ?? 0);
            $subject['progress'] = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
        }
        unset($subject);
        return $subjects;
    } catch (PDOException $e) {
        return [];
    }
}

function getUpcomingQuizzes(PDO $conn, int $userId, string $rangeEnd, int $limit = 3): array
{
    try {
        $limit = max(1, (int)$limit);
        // Lesson + self only — no competition
        $sql = "
            SELECT q.id, q.title, q.slug, q.difficulty, q.time_limit_minutes,
                   q.subject_id, q.mode,
                   (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id AND user_id = ?) AS attempts_taken
            FROM quizzes q
            WHERE q.status = 'published'
              AND q.mode IN ('lesson','self')
              AND (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id AND user_id = ? AND status = 'completed') < COALESCE(q.max_attempts, 999999)
            ORDER BY q.created_at DESC, q.id DESC
            LIMIT $limit
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getLatestMedia(PDO $conn, int $limit = 4): array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, title, file_path, video_url, media_type, thumbnail_path, created_at
            FROM media WHERE status = 'published'
            ORDER BY created_at DESC LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getLatestContents(PDO $conn, int $limit = 4): array
{
    try {
        $stmt = $conn->prepare("
            SELECT c.id, c.title, c.slug, c.image_path, c.video_path, c.video_url,
                   cat.image_path AS category_image_path,
                   s.name AS subject_name, s.slug AS subject_slug, s.id AS subject_id, c.published_at
            FROM contents c
            LEFT JOIN subjects s ON c.subject_id = s.id
            LEFT JOIN categories cat ON c.category_id = cat.id
            WHERE c.status = 'published'
            ORDER BY c.published_at DESC, c.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Top podium — lesson/self quizzes only. No monthly exams, no competition.
 */
function getTopRankedParticipants(
    PDO $conn,
    string $rangeStart,
    string $rangeEnd,
    string $prevRangeStart,
    string $prevRangeEnd,
    int $limit = 3,
    int $excludeUserId = 0
): array {
    try {
        $sql = "
            SELECT
                u.id AS user_id, u.username, u.full_name, u.email, u.profile_image,
                COALESCE(qa_points.points, 0) AS total_points
            FROM users u
            LEFT JOIN (
                SELECT qa.user_id, SUM(qa.score) AS points
                FROM quiz_attempts qa
                JOIN quizzes q ON q.id = qa.quiz_id
                WHERE qa.status = 'completed'
                  AND q.mode IN ('lesson','self')
                  AND qa.completed_at BETWEEN ? AND ?
                GROUP BY qa.user_id
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
        $stmt->bindValue(3, $excludeUserId, PDO::PARAM_INT);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Fetch current user too
        $userSql = "
            SELECT
                u.id AS user_id, u.username, u.full_name, u.email, u.profile_image,
                COALESCE(qa_points.points, 0) AS total_points
            FROM users u
            LEFT JOIN (
                SELECT qa.user_id, SUM(qa.score) AS points
                FROM quiz_attempts qa
                JOIN quizzes q ON q.id = qa.quiz_id
                WHERE qa.status = 'completed'
                  AND q.mode IN ('lesson','self')
                  AND qa.completed_at BETWEEN ? AND ?
                GROUP BY qa.user_id
            ) qa_points ON qa_points.user_id = u.id
            WHERE u.id = ?
        ";
        $userStmt = $conn->prepare($userSql);
        $userStmt->execute([$rangeStart, $rangeEnd, $excludeUserId]);
        $userRow = $userStmt->fetch();
        if ($userRow && (float)($userRow['total_points'] ?? 0) > 0) {
            $rows[] = $userRow;
        }

        usort($rows, static fn($a, $b) => ((float)$b['total_points']) <=> ((float)$a['total_points']));
        $rows = array_slice($rows, 0, 3);
        assignCompetitionRanks($rows, 'total_points');

        foreach ($rows as &$row) {
            if (!empty($row['username'])) $row['display_name'] = $row['username'];
            elseif (!empty($row['full_name'])) $row['display_name'] = $row['full_name'];
            else {
                $emailParts = explode('@', $row['email'] ?? '');
                $row['display_name'] = !empty($emailParts[0]) ? $emailParts[0] : 'User';
            }
        }
        unset($row);

        // Trend: compare with previous period
        $podiumIds = array_map(static fn($r) => (int)$r['user_id'], $rows);
        $prevRanks = getPreviousPeriodRankMap($conn, $prevRangeStart, $prevRangeEnd, $podiumIds);

        foreach ($rows as &$row) {
            $uid = (int)$row['user_id'];
            $prevRank = $prevRanks[$uid] ?? null;
            $row['prev_rank'] = $prevRank;
            if ($prevRank === null) $row['trend'] = 'new';
            elseif ($prevRank > $row['rank']) $row['trend'] = 'up';
            elseif ($prevRank < $row['rank']) $row['trend'] = 'down';
            else $row['trend'] = 'same';
        }
        unset($row);

        return $rows;
    } catch (PDOException $e) {
        error_log("Error getting top ranked participants: " . $e->getMessage());
        return [];
    }
}

function getPreviousPeriodRankMap(PDO $conn, string $rangeStart, string $rangeEnd, array $userIds): array
{
    if (empty($userIds)) return [];
    try {
        $sql = "
            SELECT u.id AS user_id, COALESCE(qa_points.points, 0) AS total_points
            FROM users u
            LEFT JOIN (
                SELECT qa.user_id, SUM(qa.score) AS points
                FROM quiz_attempts qa
                JOIN quizzes q ON q.id = qa.quiz_id
                WHERE qa.status = 'completed'
                  AND q.mode IN ('lesson','self')
                  AND qa.completed_at BETWEEN ? AND ?
                GROUP BY qa.user_id
            ) qa_points ON qa_points.user_id = u.id
            WHERE u.is_active = 1 AND COALESCE(qa_points.points, 0) > 0
            ORDER BY total_points DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$rangeStart, $rangeEnd]);
        $rows = $stmt->fetchAll();

        assignCompetitionRanks($rows, 'total_points');

        $rankByUserId = [];
        foreach ($rows as $row) $rankByUserId[(int)$row['user_id']] = (int)$row['rank'];

        $wanted = array_map('intval', $userIds);
        return array_intersect_key($rankByUserId, array_flip($wanted));
    } catch (PDOException $e) {
        return [];
    }
}

function getUserProgressHistory(PDO $conn, int $userId, string $rangeStart, string $rangeEnd): array
{
    try {
        $stmt = $conn->prepare("
            SELECT DATE(completed_at) AS activity_date, AVG(percentage) AS avg_score, COUNT(*) AS quiz_count
            FROM quiz_attempts
            WHERE user_id = ? AND status = 'completed'
              AND completed_at BETWEEN ? AND ?
            GROUP BY DATE(completed_at)
            ORDER BY activity_date ASC
        ");
        $stmt->execute([$userId, $rangeStart, $rangeEnd]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            $stmt = $conn->prepare("
                SELECT DATE(last_accessed_at) AS activity_date, AVG(progress_percent) AS avg_score, COUNT(*) AS content_count
                FROM user_content_progress
                WHERE user_id = ? AND last_accessed_at BETWEEN ? AND ?
                  AND progress_percent > 0
                GROUP BY DATE(last_accessed_at)
                ORDER BY activity_date ASC
            ");
            $stmt->execute([$userId, $rangeStart, $rangeEnd]);
            $rows = $stmt->fetchAll();
        }

        $history = [];
        foreach ($rows as $row) {
            if (empty($row['activity_date'])) continue;
            $history[] = [
                'label' => date('M d', strtotime((string)$row['activity_date'])),
                'score' => round((float)($row['avg_score'] ?? 0), 1),
                'count' => (int)($row['quiz_count'] ?? $row['content_count'] ?? 0),
            ];
        }
        return $history;
    } catch (PDOException $e) {
        return [];
    }
}

// ============================================================================
// HIJRI DATE
// ============================================================================

const HIJRI_LOCAL_FALLBACK_ADJUSTMENT_DAYS = 2;

function fetchHijriFromAladhan(DateTime $gregorianDate): ?array
{
    if (!function_exists('curl_init')) return null;

    $cacheDir = sys_get_temp_dir() . '/myislam_hijri_cache';
    $dateKey = $gregorianDate->format('Y-m-d');
    $cacheFile = $cacheDir . '/' . $dateKey . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['day']) && !empty($cached['month']) && !empty($cached['year'])) {
            return $cached;
        }
    }

    try {
        $url = 'https://api.aladhan.com/v1/gToH/' . $gregorianDate->format('d-m-Y');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'MyIslam-Dashboard/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0 || $response === false || $httpCode !== 200) return null;

        $data = json_decode($response, true);
        $hijri = $data['data']['hijri'] ?? null;
        if (empty($hijri['day']) || empty($hijri['month']['en']) || empty($hijri['year'])) return null;

        $result = ['day'=>(string)$hijri['day'],'month'=>(string)$hijri['month']['en'],'year'=>(string)$hijri['year']];
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
        @file_put_contents($cacheFile, json_encode($result));
        return $result;
    } catch (Throwable $e) {
        return null;
    }
}

function getHijriDateDisplay(): array
{
    $timezone = 'Asia/Riyadh';
    try { $now = new DateTime('now', new DateTimeZone($timezone)); }
    catch (Throwable $e) { $now = new DateTime('now'); }

    $currentHour = (int)$now->format('H');
    $currentMinute = (int)$now->format('i');
    $isAfterMaghrib = ($currentHour > 18) || ($currentHour == 18 && $currentMinute >= 30);

    $islamicDate = clone $now;
    if ($isAfterMaghrib) $islamicDate->modify('+1 day');

    $weekdayMap = [
        'Sunday'=>'Yaumul Ahad','Monday'=>'Yaumul Ithnain','Tuesday'=>'Yaumul Thulatha',
        'Wednesday'=>"Yaumul Arba'a",'Thursday'=>'Yaumul Khamis','Friday'=>"Yaumul Jum'ah",'Saturday'=>'Yaumul Sabt',
    ];
    $gregorianWeekday = $islamicDate->format('l');
    $islamicDay = $weekdayMap[$gregorianWeekday] ?? $gregorianWeekday;

    $apiResult = fetchHijriFromAladhan($islamicDate);
    if ($apiResult !== null) {
        return [
            'formatted' => "{$islamicDay}, {$apiResult['day']} {$apiResult['month']} {$apiResult['year']} AH",
            'gregorian' => $now->format('F j, Y'),
            'source' => 'aladhan',
            'islamic_day' => $islamicDay,
            'is_after_maghrib' => $isAfterMaghrib,
        ];
    }

    $correctedDate = (clone $islamicDate)->modify('+' . HIJRI_LOCAL_FALLBACK_ADJUSTMENT_DAYS . ' days');
    if (class_exists('IntlDateFormatter')) {
        try {
            $fmt = new IntlDateFormatter(
                'en_SA@calendar=islamic-umalqura',
                IntlDateFormatter::FULL, IntlDateFormatter::NONE,
                $timezone, IntlDateFormatter::TRADITIONAL
            );
            $fmt->setPattern('d MMMM yyyy');
            $formatted = $fmt->format($correctedDate);
            if (!empty($formatted)) {
                $parts = explode(' ', $formatted);
                return [
                    'formatted' => "{$islamicDay}, {$parts[0]} {$parts[1]} {$parts[2]} AH",
                    'gregorian' => $now->format('F j, Y'),
                    'source' => 'umalqura-corrected',
                    'islamic_day' => $islamicDay,
                    'is_after_maghrib' => $isAfterMaghrib,
                ];
            }
        } catch (Throwable $e) {}
    }

    // Tabular fallback
    $y = (int)$correctedDate->format('Y');
    $m = (int)$correctedDate->format('n');
    $d = (int)$correctedDate->format('j');
    $a = intdiv(14 - $m, 12);
    $y2 = $y + 4800 - $a;
    $m2 = $m + 12 * $a - 3;
    $jd = $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4) - intdiv($y2, 100) + intdiv($y2, 400) - 32045;
    $l = $jd - 1948440 + 10632;
    $n = (int) floor(($l - 1) / 10631);
    $l = $l - 10631 * $n + 354;
    $j = (int) floor((10985 - $l) / 5316) * (int) floor((50 * $l) / 17719) + (int) floor($l / 5670) * (int) floor((43 * $l) / 15238);
    $l = $l - (int) floor((30 - $j) / 15) * (int) floor((17719 * $j) / 50) - (int) floor($j / 16) * (int) floor((15238 * $j) / 43) + 29;
    $hMonth = (int) floor((24 * $l) / 709);
    $hDay = $l - (int) floor((709 * $hMonth) / 24);
    $hYear = 30 * $n + $j - 30;

    $hijriMonths = [1=>'Muharram',2=>'Safar',3=>"Rabi' al-Awwal",4=>"Rabi' al-Thani",5=>'Jumada al-Awwal',6=>'Jumada al-Thani',7=>'Rajab',8=>"Sha'ban",9=>'Ramadan',10=>'Shawwal',11=>"Dhu al-Qi'dah",12=>'Dhu al-Hijjah'];
    $monthName = $hijriMonths[$hMonth] ?? '';

    return [
        'formatted' => trim("{$islamicDay}, {$hDay} {$monthName} {$hYear} AH"),
        'gregorian' => $now->format('F j, Y'),
        'source' => 'tabular-corrected',
        'islamic_day' => $islamicDay,
        'is_after_maghrib' => $isAfterMaghrib,
    ];
}

// ============================================================================
// USER VERIFICATION
// ============================================================================

try {
    $dbUser = getCurrentUser($conn, $userId);
    if (!$dbUser) {
        session_destroy();
        header('Location: login.php?error=account_disabled');
        exit;
    }
} catch (Throwable $e) {
    error_log("Error fetching user: " . $e->getMessage());
    die("Unable to load user data. Please try again later.");
}

$user = [
    'name' => $dbUser['username'] ?? $dbUser['full_name'] ?? 'User',
    'email' => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role' => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime($dbUser['created_at'])) : 'recently',
];

// ============================================================================
// PERIOD FILTER
// ============================================================================

$allowedFilters = ['today', 'week', 'month', 'alltime', 'custom'];
$filterType = strtolower((string)($_GET['filter'] ?? 'month'));
if (!in_array($filterType, $allowedFilters, true)) $filterType = 'month';

$customFrom = isset($_GET['from']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['from']) : '';
$customTo = isset($_GET['to']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['to']) : '';

try { $nowDt = new DateTime('now'); } catch (Throwable $e) { $nowDt = new DateTime(); }

switch ($filterType) {
    case 'today':
        $rangeStartDt = (clone $nowDt)->setTime(0, 0, 0);
        $rangeEndDt = (clone $nowDt)->setTime(23, 59, 59);
        $filterLabel = t('today');
        break;
    case 'week':
        $rangeStartDt = (clone $nowDt)->modify('monday this week')->setTime(0, 0, 0);
        $rangeEndDt = (clone $nowDt)->modify('sunday this week')->setTime(23, 59, 59);
        $filterLabel = t('week');
        break;
    case 'alltime':
        $rangeStartDt = new DateTime('1970-01-01 00:00:00');
        $rangeEndDt = new DateTime('2100-01-01 00:00:00');
        $filterLabel = t('all_time');
        break;
    case 'custom':
        try {
            $rangeStartDt = $customFrom !== '' ? new DateTime($customFrom . ' 00:00:00') : (clone $nowDt)->modify('first day of this month')->setTime(0, 0, 0);
            $rangeEndDt = $customTo !== '' ? new DateTime($customTo . ' 23:59:59') : (clone $nowDt)->setTime(23, 59, 59);
            if ($rangeStartDt > $rangeEndDt) [$rangeStartDt, $rangeEndDt] = [$rangeEndDt, $rangeStartDt];
        } catch (Throwable $e) {
            $rangeStartDt = (clone $nowDt)->modify('first day of this month')->setTime(0, 0, 0);
            $rangeEndDt = (clone $nowDt)->setTime(23, 59, 59);
        }
        $filterLabel = $rangeStartDt->format('M j, Y') . ' – ' . $rangeEndDt->format('M j, Y');
        break;
    case 'month':
    default:
        $rangeStartDt = (clone $nowDt)->modify('first day of this month')->setTime(0, 0, 0);
        $rangeEndDt = (clone $nowDt)->modify('last day of this month')->setTime(23, 59, 59);
        $filterLabel = t('month');
        break;
}

$rangeStartSql = $rangeStartDt->format('Y-m-d H:i:s');
$rangeEndSql = $rangeEndDt->format('Y-m-d H:i:s');

// Previous period for podium trend
if ($filterType === 'alltime') {
    $prevRangeEndDt = (clone $rangeEndDt)->modify('-30 days');
    $prevRangeStartDt = clone $rangeStartDt;
} else {
    $periodSeconds = max(1, $rangeEndDt->getTimestamp() - $rangeStartDt->getTimestamp());
    $prevRangeEndDt = (clone $rangeStartDt)->modify('-1 second');
    $prevRangeStartDt = (clone $prevRangeEndDt)->modify('-' . $periodSeconds . ' seconds');
}
$prevRangeStartSql = $prevRangeStartDt->format('Y-m-d H:i:s');
$prevRangeEndSql = $prevRangeEndDt->format('Y-m-d H:i:s');

// ============================================================================
// FETCH DASHBOARD DATA
// ============================================================================

$filters = [
    t('today') => 'today',
    t('week') => 'week',
    t('month') => 'month',
    t('all_time') => 'alltime',
];

try {
    $progressRow = getOverallProgress($conn, $userId);
    $leaderboardOverall = getLeaderboardStatsByMode($conn, $userId, 'overall', $rangeStartSql, $rangeEndSql);
    $leaderboardLesson = getLeaderboardStatsByMode($conn, $userId, 'lesson', $rangeStartSql, $rangeEndSql);
    $selfModeStats = getSelfModeStats($conn, $userId, $rangeStartSql, $rangeEndSql);

    $reminderRow = getTodayReminder($conn, $userId, (string)($user['role'] ?? 'user'));
    $resumeRow = getResumeState($conn, $userId);
    $streakDays = getUserStreak($conn, $userId);
    $todayProgress = getTodayProgress($conn, $userId);
    $dbSubjects = getSubjects($conn, $userId);
    $upcomingQuizzes = getUpcomingQuizzes($conn, $userId, $rangeEndSql, 3);
    $latestMedia = getLatestMedia($conn, 6);
    $latestContents = getLatestContents($conn, 6);
    $topRankedParticipants = getTopRankedParticipants($conn, $rangeStartSql, $rangeEndSql, $prevRangeStartSql, $prevRangeEndSql, 3, $userId);
    $progressHistory = getUserProgressHistory($conn, $userId, $rangeStartSql, $rangeEndSql);
} catch (Throwable $e) {
    error_log("Error fetching dashboard data: " . $e->getMessage());
    $progressRow = ['total_items'=>0,'completed_items'=>0,'overall_progress'=>0,'total_time_spent'=>0];
    $leaderboardOverall = $leaderboardLesson = ['rank_position'=>0,'total_participants'=>0,'total_points'=>0,'quizzes_completed'=>0,'average_score'=>0,'total_time_seconds'=>0];
    $selfModeStats = ['rank_position'=>0,'total_participants'=>0,'total_points'=>0,'quizzes_completed'=>0,'total_time_seconds'=>0];
    $reminderRow = ['message' => t('no_reminders')];
    $resumeRow = ['resume_label' => t('start_first_lesson')];
    $streakDays = 0; $todayProgress = 0; $dbSubjects = []; $upcomingQuizzes = [];
    $latestMedia = []; $latestContents = []; $topRankedParticipants = []; $progressHistory = [];
}

// ============================================================================
// PROCESS DATA
// ============================================================================

$overallProgress = (int)($progressRow['overall_progress'] ?? 0);
$completedItems = (int)($progressRow['completed_items'] ?? 0);
$totalItems = (int)($progressRow['total_items'] ?? 0);
$totalTimeSpent = (int)($progressRow['total_time_spent'] ?? 0);

$overallPoints = (float)($leaderboardOverall['total_points'] ?? 0);
$overallRank = (int)($leaderboardOverall['rank_position'] ?? 0);
$overallParticipants = (int)($leaderboardOverall['total_participants'] ?? 0);
$overallAvgScore = (float)($leaderboardOverall['average_score'] ?? 0);
$overallQuizzesCompleted = (int)($leaderboardOverall['quizzes_completed'] ?? 0);

$lessonPoints = (float)($leaderboardLesson['total_points'] ?? 0);
$lessonRank = (int)($leaderboardLesson['rank_position'] ?? 0);
$lessonParticipants = (int)($leaderboardLesson['total_participants'] ?? 0);
$lessonCompleted = (int)($leaderboardLesson['quizzes_completed'] ?? 0);
$lessonTime = (int)($leaderboardLesson['total_time_seconds'] ?? 0);

$selfPoints = (float)($selfModeStats['total_points'] ?? 0);
$selfRank = (int)($selfModeStats['rank_position'] ?? 0);
$selfParticipants = (int)($selfModeStats['total_participants'] ?? 0);
$selfCompleted = (int)($selfModeStats['quizzes_completed'] ?? 0);
$selfTime = (int)($selfModeStats['total_time_seconds'] ?? 0);

$todayReminder = e($reminderRow['message'] ?? t('no_reminders'));
$isMotivationalReminder = isset($reminderRow['is_motivational']) && $reminderRow['is_motivational'];

$resumeLesson = e($resumeRow['resume_label'] ?? t('start_first_lesson'));
$resumeType = $resumeRow['resume_type'] ?? 'content';

if ($resumeType === 'quiz' && !empty($resumeRow['last_quiz_id'])) {
    $resumeLink = 'quiz.php?id=' . (int)$resumeRow['last_quiz_id'];
} elseif (!empty($resumeRow['last_content_slug'])) {
    $resumeLink = 'content.php?slug=' . urlencode((string)$resumeRow['last_content_slug']);
} elseif (!empty($resumeRow['last_content_id'])) {
    $resumeLink = 'content.php?id=' . (int)$resumeRow['last_content_id'];
} else {
    $resumeLink = 'resume_learning.php';
}

$platformVersion = 'v1.0.0';
$hijriToday = getHijriDateDisplay();

// ============================================================================
// PROGRESS TREND CHART
// ============================================================================

$progressChartWidth = 680;
$progressChartHeight = 220;
$progressChartPadding = 36;
$progressChartPoints = [];

$progressHistoryCount = count($progressHistory);
if ($progressHistoryCount >= 2) {
    $plotWidth = $progressChartWidth - ($progressChartPadding * 2);
    $plotHeight = $progressChartHeight - ($progressChartPadding * 2);

    foreach ($progressHistory as $i => $point) {
        $x = $progressChartPadding + ($progressHistoryCount > 1 ? ($i / ($progressHistoryCount - 1)) * $plotWidth : 0);
        $scoreClamped = max(0, min(100, (float)$point['score']));
        $y = $progressChartPadding + (1 - ($scoreClamped / 100)) * $plotHeight;
        $progressChartPoints[] = [
            'x' => round($x, 2), 'y' => round($y, 2),
            'score' => (float)$point['score'], 'label' => $point['label'],
        ];
    }
}
$progressChartLabelStep = count($progressChartPoints) > 7 ? 2 : 1;

// Unread notifications
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// Masheikh
$stmt = $conn->prepare("SELECT sheikh_name FROM subjects WHERE sheikh_name IS NOT NULL AND sheikh_name <> ''");
$stmt->execute();
$masheikh = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Navigation
$mainNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => true],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false],
    ['name' => 'Courses', 'link' => 'courses.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => true, 'icon' => '📊'],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => t('adhkaar'), 'link' => 'adhkaar.php', 'active' => false, 'icon' => '📿'],
    ['name' => t('questions_answers'), 'link' => 'QA.php', 'active' => false, 'icon' => '❓'],
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => 'Courses', 'link' => 'courses.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo e($_SESSION['language'] ?? 'sw'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="description" content="<?php echo e(t('dashboard')); ?> - MyIslam">
<title><?php echo e(t('dashboard')); ?> - MyIslam</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f5f7f5;--surface:#fff;--surface-2:#f9fafb;--surface-3:#eef2f7;
  --line:#e2e5e2;--text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;
  --brand:#0E4A34;--brand-dark:#0A3626;--brand-light:#3E7A5E;--brand-soft:rgba(14,74,52,.08);
  --gold:#9C7A2E;--gold-soft:rgba(156,122,46,.10);
  --accent:#3E6E91;--accent-soft:rgba(62,110,145,.10);
  --danger:#b91c1c;--danger-soft:rgba(185,28,28,.08);
  --white:#fff;
  --header-height:72px;
  --radius-sm:12px;--radius-md:16px;--radius-lg:20px;
  --shadow-sm:0 1px 2px 0 rgb(0 0 0 / .05);
  --shadow-md:0 4px 6px -1px rgb(0 0 0 / .08);
  --shadow-lg:0 10px 15px -3px rgb(0 0 0 / .1);
}
body.dark-mode{
  --bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;
  --line:#304150;--text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;
  --brand:#4C9172;--brand-dark:#6bb590;--brand-light:#7fcaa4;--brand-soft:rgba(76,145,114,.16);
  --gold:#C9A85E;--gold-soft:rgba(201,168,94,.16);
  --accent:#6FA0C4;--accent-soft:rgba(111,160,196,.16);
  --danger:#ef4444;--danger-soft:rgba(239,68,68,.12);
  --header-bg:#13202a;
}
body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;padding-bottom:24px}
a{text-decoration:none;color:inherit}
button{font:inherit;border:none;background:none;cursor:pointer}
body::before{content:"";position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:-1;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Ctext x='20' y='150' font-family='Amiri' font-size='120' fill='%230E4A34' fill-opacity='0.025'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");background-repeat:repeat}
body.dark-mode::before{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Ctext x='20' y='150' font-family='Amiri' font-size='120' fill='%234C9172' fill-opacity='0.05'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E")}

/* Header */
.app-header{position:sticky;top:0;z-index:1000;height:var(--header-height);background:var(--header-bg,#fff);border-bottom:1px solid var(--line);box-shadow:var(--shadow-sm);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;gap:18px}
.header-left,.header-right{display:flex;align-items:center;gap:12px}
.header-left{flex:1;min-width:0}
.header-top-nav{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:thin;padding-bottom:2px}
.header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-size:13px;font-weight:700;color:var(--text-secondary);transition:all .2s}
.header-top-link:hover,.header-top-link.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
.menu-toggle,.icon-btn,.lang-btn,.brand-box{height:40px;min-width:30px;border-radius:10px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;transition:all .2s}
.menu-toggle{width:50px;font-size:14px}
.icon-btn{font-size:18px;color:var(--text);position:relative}
.lang-btn{min-width:30px;padding:0 8px;font-weight:600;color:var(--brand)}
.brand-box{font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none;white-space:nowrap}
.profile-area{position:relative}
.profile-btn{width:44px;height:44px;border-radius:50%;overflow:hidden;background:var(--surface);border:2px solid var(--brand);padding:0;display:flex;align-items:center;justify-content:center;color:var(--brand);font-weight:700}
.profile-btn img{width:100%;height:100%;object-fit:cover}
.profile-menu{position:absolute;top:56px;right:0;width:280px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:16px;display:none;box-shadow:var(--shadow-lg);z-index:1001}
.profile-menu.show{display:block}
.profile-menu-top{display:flex;gap:12px;padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid var(--line)}
.profile-menu-top img,.profile-avatar-fallback{width:56px;height:56px;border-radius:50%}
.profile-avatar-fallback{display:inline-flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:700;font-size:16px}
.profile-menu a{display:block;padding:10px 12px;border-radius:10px;font-weight:700;font-size:14px;margin:4px 0}
.profile-menu a:hover{background:var(--brand-soft);color:var(--brand)}
.notification-badge{position:absolute;top:-4px;right:-4px;background:var(--danger);color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--surface)}

/* Sidebar */
.sidebar-overlay{display:none;position:fixed;top:var(--header-height);left:0;width:100%;height:calc(100vh - var(--header-height));background:rgba(15,23,32,.45);z-index:1100}
.sidebar-overlay.show{display:block}
.sidebar{position:fixed;top:var(--header-height);left:-100%;width:min(320px,85vw);height:calc(100vh - var(--header-height));background:var(--surface);border-right:1px solid var(--line);padding:24px 20px;z-index:1200;transition:left .3s ease;overflow-y:auto}
.sidebar.open{left:0}
.sidebar-title{font-size:12px;color:var(--muted);text-transform:uppercase;margin-bottom:20px;font-weight:700;letter-spacing:1px;padding:0 8px}
.subject-list{display:grid;gap:8px}
.subject-link{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--radius-md);background:var(--surface-2);border:1px solid var(--line);font-size:14px;font-weight:500;color:var(--text);transition:all .2s}
.subject-link:hover{background:var(--brand-soft);border-color:var(--brand);transform:translateX(4px)}
.subject-link.active{background:var(--brand-soft);color:var(--brand);border-color:var(--brand)}
.sidebar-toggle-btn{position:fixed;top:var(--header-height);left:0;z-index:1300;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;background:var(--surface);border:1px solid var(--line);border-left:none;border-top:none;border-radius:0 0 8px 0;font-size:16px;color:var(--text);box-shadow:var(--shadow-sm);padding:0}
.sidebar-toggle-btn:hover{background:var(--brand-soft);color:var(--brand)}

/* Main layout */
.main-content{padding:16px;max-width:1400px;margin:0 auto;width:100%}

/* Dashboard 3-column */
.zd{z-index:1}
.zd-layout{display:grid;grid-template-columns:260px minmax(0,1fr) 300px;gap:16px;align-items:start;max-width:1320px;margin:0 auto}
.zd-main,.zd-left,.zd-right{min-width:0}
.zd-panel{background:var(--surface);border:1px solid var(--line);border-radius:8px;margin-bottom:14px;overflow:hidden}
.zd-panel-head{display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--surface-2);border-bottom:1px solid var(--line);font-size:12.5px;font-weight:700;color:var(--text)}
.zd-panel-head .ico{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:4px;background:var(--brand);color:#fff;font-size:11px;flex-shrink:0}
.zd-panel-body{padding:12px;font-size:12.5px;color:var(--text)}

/* Hero */
.zd-hero{position:relative;min-height:130px;border-radius:8px;overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:22px 18px;color:#fff;margin-bottom:14px;background:linear-gradient(rgba(10,40,30,.72),rgba(10,40,30,.72)),url('/uploads/subjects-page-hero.png') center/cover no-repeat,#0E4A34}
.zd-hero::before{content:"";position:absolute;inset:0;background:url('/uploads/subjects-page-hero.png') center/cover;opacity:.22}
.zd-hero > *{position:relative;z-index:1}
.zd-hero .hijri{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:10px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:13px;font-weight:600;text-shadow:1px 1px 2px rgba(0,0,0,.5);margin-bottom:10px}
.zd-hero .hijri .greg{opacity:.85;font-weight:500}
.zd-hero h1{margin:0 0 4px;font-size:22px;font-weight:800;text-shadow:1px 2px 4px rgba(0,0,0,.3)}
.zd-hero p{margin:0;font-size:12.5px;opacity:.92;max-width:520px}

/* Filter */
.zd-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;border-radius:8px;background:var(--surface);border:1px solid var(--line);margin-bottom:12px}
.zd-filter label{font-size:12px;font-weight:700;color:var(--muted);white-space:nowrap}
.zd-filter select{padding:8px 10px;border-radius:8px;border:1px solid var(--line);background:var(--surface);color:var(--text);font-size:13px;font-weight:600;cursor:pointer;min-width:150px}
.zd-filter select:hover{border-color:var(--brand)}

/* Academy card — WITH BACKGROUND IMAGE */
.zd-academy-card{position:relative;border-radius:8px;overflow:hidden;padding:20px 16px 18px;margin-bottom:14px;color:#fff;background:linear-gradient(rgba(10,40,30,.40),rgba(10,40,30,.62)),url('/uploads/myislam-academyc.png') center/cover no-repeat,#0E4A34}
.zd-academy-card > *{position:relative;z-index:1}
.zd-academy-title{
    text-align:center;
    font-size:22px;
    font-weight:900;
    color:#D9BC72;
    margin:0 0 16px;
    letter-spacing:.6px;
    text-shadow:0 2px 6px rgba(0,0,0,.55);
}

.zd-academy-courses{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:14px}
.zd-academy-course{display:block;padding:12px 14px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,.22);border-right:4px solid #D9BC72;border-radius:8px;text-decoration:none;color:#fff;transition:all .15s;min-width:0;backdrop-filter:blur(0px)}
.zd-academy-course:hover{background:rgba(255,255,255,.18);border-color:#D9BC72;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.25)}
.zd-academy-course-title{font-size:24px;font-weight:800;color:#fff;line-height:1.25;margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;text-shadow:0 1px 3px rgba(0,0,0,.4)}
.zd-academy-course-meta{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:10.5px;font-weight:700;color:rgba(255,255,255,.88);margin-bottom:6px}
.zd-academy-course-meta span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.zd-academy-course-state{display:inline-block;padding:2px 9px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;background:rgba(217,188,114,.85);color:#1a1a1a}
.zd-academy-course-state.active{background:rgba(127,202,164,.9);color:#0A3626}
.zd-academy-course-state.ended{background:rgba(255,255,255,.22);color:#fff}
.zd-academy-empty{text-align:center;font-size:12.5px;color:rgba(255,255,255,.92);line-height:1.55;margin:8px 0 14px;padding:0 6px;text-shadow:0 1px 3px rgba(0,0,0,.4)}
.zd-academy-cta-wrap{text-align:center}
.zd-academy-cta{display:inline-block;padding:10px 28px;border-radius:9px;background:rgba(255,255,255,.12);border:2px solid #D9BC72;color:#D9BC72;font-weight:800;font-size:13px;text-decoration:none;transition:all .15s}
.zd-academy-cta:hover{background:rgba(217,188,114,.18)}
.zd-academy-cta.primary{background:#D9BC72;border-color:#D9BC72;color:#1a1a1a}
.zd-academy-cta.primary:hover{filter:brightness(1.08);background:#D9BC72;color:#1a1a1a}
.zd-academy-course-state{display:block;width:fit-content;margin:6px auto 0;padding:2px 9px;border-radius:5px;font-size:10px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;background:rgba(217,188,114,.85);color:#1a1a1a}

/* Quick tiles — compact, 2 per row always */
.zd-tiles{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:14px}
.zd-tile{display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--surface);border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--text);font-size:12px;font-weight:700;transition:all .15s;min-width:0;overflow:hidden}
.zd-tile:hover{border-color:var(--brand);background:var(--brand-soft);color:var(--brand)}
.zd-tile .icon{font-size:16px;flex-shrink:0;line-height:1}
.zd-tile span:not(.icon){overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}

/* Rank/progress card */
.zd-rank-row{display:flex;align-items:center;justify-content:center;gap:16px}
.zd-rank-item{flex:1;min-width:0;text-align:center}
.zd-rank-label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:700;color:var(--muted);margin-bottom:6px}
.zd-rank-value{font-size:clamp(22px,5vw,32px);font-weight:800;color:var(--gold);line-height:1.1}
.zd-rank-sub{font-size:11px;color:var(--muted);margin-top:4px}
.zd-rank-divider{width:1px;align-self:stretch;background:var(--line);flex-shrink:0}

/* Podium */
.zd-podium-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:end;gap:6px;margin-top:10px}
.zd-podium-card{min-width:0;text-align:center;padding:10px 4px;border-radius:12px;border:1px solid var(--line);position:relative;margin-top:18px;display:flex;flex-direction:column;align-items:center;overflow:hidden;transition:transform .2s}
.zd-podium-card:hover{transform:translateY(-2px)}
.zd-podium-first{order:2;margin-top:0;padding-top:14px;padding-bottom:14px;background:linear-gradient(160deg,rgba(198,166,75,.14) 0%,var(--surface) 100%);border-color:#C6A64B;box-shadow:0 4px 10px -2px rgba(0,0,0,.10)}
.zd-podium-second{order:3;background:linear-gradient(160deg,rgba(143,211,232,.16) 0%,var(--surface) 100%);border-color:#8FD3E8}
.zd-podium-third{order:1;background:linear-gradient(160deg,rgba(108,92,192,.14) 0%,var(--surface) 100%);border-color:#6C5CC0}
.zd-podium-you{box-shadow:0 0 0 2px var(--brand),0 4px 10px -2px rgba(0,0,0,.10)}
.zd-podium-avatar{width:40px;height:40px;border-radius:50%;margin:0 auto 6px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;background:linear-gradient(135deg,var(--brand) 0%,var(--brand-dark) 100%);overflow:hidden;border:2px solid var(--surface);flex-shrink:0}
.zd-podium-first .zd-podium-avatar{width:48px;height:48px;border-color:#C6A64B;font-size:14px}
.zd-podium-second .zd-podium-avatar{border-color:#8FD3E8}
.zd-podium-third .zd-podium-avatar{border-color:#6C5CC0}
.zd-podium-avatar img{width:100%;height:100%;object-fit:cover}
.zd-podium-badge{display:inline-flex;align-items:center;gap:2px;font-size:9px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;padding:2px 8px;border-radius:999px;margin-bottom:6px;color:#fff;white-space:nowrap;max-width:100%}
.zd-podium-first .zd-podium-badge{background:#C6A64B}
.zd-podium-second .zd-podium-badge{background:#8FD3E8;color:#0f1720}
.zd-podium-third .zd-podium-badge{background:#6C5CC0}
.zd-podium-name{font-size:11px;font-weight:700;color:var(--text);line-height:1.2;max-width:100%;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
.zd-podium-points{font-size:10px;color:var(--muted);margin-top:3px;line-height:1.2;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* Leaderboard table */
.zd-lb-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:10px}
.zd-lb-table{width:100%;border-collapse:collapse;background:var(--surface);min-width:320px}
.zd-lb-table thead th{background:var(--gold-soft);color:var(--gold);font-size:11px;font-weight:800;text-align:center;padding:8px 6px;white-space:nowrap;border-bottom:2px solid var(--gold);letter-spacing:.2px}
.zd-lb-table thead th:first-child{text-align:left;background:var(--surface);color:var(--text-secondary);border-bottom:2px solid var(--line)}
.zd-lb-table tbody th{text-align:left;font-size:11.5px;font-weight:600;color:var(--text-secondary);padding:8px 8px;white-space:nowrap;background:var(--surface)}
.zd-lb-table tbody td{text-align:center;font-size:12px;font-weight:700;color:var(--text);padding:8px 6px;white-space:nowrap;font-variant-numeric:tabular-nums}
.zd-lb-table tbody tr{border-bottom:1px dashed var(--line)}
.zd-lb-table tbody tr:last-child{border-bottom:0}

/* Upcoming quizzes */
.zd-quiz-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px;background:var(--surface-2);border-radius:8px;border:1px solid var(--line);margin-bottom:6px;text-decoration:none;color:inherit}
.zd-quiz-item:hover{border-color:var(--brand);background:var(--brand-soft)}
.zd-quiz-info h4{font-size:12.5px;margin-bottom:2px;color:var(--text);font-weight:700}
.zd-quiz-meta{font-size:10.5px;color:var(--muted)}
.zd-quiz-badge{padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:700;background:var(--gold-soft);color:var(--gold);white-space:nowrap}

/* Latest grids */
.zd-latest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
.zd-latest-card{background:var(--surface-2);border-radius:8px;overflow:hidden;border:1px solid var(--line)}
.zd-latest-thumb{aspect-ratio:16/9;background:var(--surface-3);display:flex;align-items:center;justify-content:center;color:var(--muted)}
.zd-latest-thumb img{width:100%;height:100%;object-fit:cover}
.zd-latest-info{padding:8px}
.zd-latest-title{font-size:11.5px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.zd-latest-type{font-size:10px;color:var(--muted);text-transform:capitalize}

/* Reminder / resume */
.zd-reminder{background:var(--gold-soft);border:1px solid var(--gold);border-radius:8px;padding:12px 14px;margin-bottom:12px}
.zd-reminder h3{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--gold);margin-bottom:6px;font-weight:800}
.zd-reminder p{font-size:13px;font-style:italic;color:var(--text);line-height:1.55}
.zd-resume{background:var(--brand-soft);border:1px solid var(--brand);border-radius:8px;padding:12px 14px;margin-bottom:12px}
.zd-resume h3{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--brand);margin-bottom:6px;font-weight:800}
.zd-resume p{font-size:13px;color:var(--text);margin-bottom:10px;line-height:1.55}
.zd-resume a{display:block;text-align:center;padding:10px 14px;border-radius:8px;background:var(--brand);color:#fff;font-weight:800;font-size:12.5px}
.zd-resume a:hover{filter:brightness(1.08)}

/* Progress chart */
.zd-chart{margin-top:6px}
.zd-chart svg{width:100%;height:auto;display:block;overflow:visible}
.zd-chart-labels{display:flex;justify-content:space-between;margin-top:4px;padding:0 2px}
.zd-chart-labels span{font-size:10px;color:var(--text-secondary);font-weight:600;white-space:nowrap}

/* Masheikh */
.zd-masheikh-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
.zd-masheikh-card{display:flex;align-items:center;gap:10px;padding:10px;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;transition:all .15s}
.zd-masheikh-card:hover{border-color:var(--brand);background:var(--brand-soft)}
.zd-masheikh-avatar{width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;background:var(--gold);border:2px solid var(--surface);overflow:hidden}
.zd-masheikh-name{font-size:12.5px;font-weight:700;color:var(--text);line-height:1.3;overflow-wrap:anywhere}

/* Radio widget */
.zd-radio{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:12px}
.zd-radio h3{font-size:11.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--gold);font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.zd-radio-wrap{background:var(--surface-2);border:1px solid var(--line);border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:8px}
.zd-radio-wrap p{font-size:12px;font-weight:600;color:var(--text-secondary);display:flex;align-items:center;gap:6px}
.zd-radio-wrap p::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--danger);box-shadow:0 0 0 3px rgba(185,28,28,.15);animation:zd-pulse 1.6s ease-in-out infinite;flex-shrink:0}
@keyframes zd-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.85)}}
.zd-radio-wrap audio{width:100%;height:36px;border-radius:999px;outline:none;accent-color:var(--brand)}

/* Quick tiles secondary */
.zd-quick{display:grid;grid-template-columns:repeat(2,1fr);gap:6px}

/* Footer */
.zd-footer{text-align:center;font-size:12px;padding:16px;margin-top:16px;border:1px solid var(--line);border-radius:8px;background:var(--surface);font-weight:700}
.zd-footer .brand-text{color:var(--brand)}
.zd-footer .gold-text{color:var(--gold)}

/* Empty */
.zd-empty{padding:16px;text-align:center;font-size:12px;color:var(--muted);background:var(--surface-2);border:1px dashed var(--line);border-radius:8px;line-height:1.5}

/* Responsive */
@media (max-width:1200px){
  .zd-layout{grid-template-columns:260px minmax(0,1fr)}
  .zd-right{grid-column:1 / -1}
}
@media (max-width:900px){
  .zd-layout{grid-template-columns:minmax(0,1fr)}
  .zd-right{grid-column:auto}
  .zd-main{order:-1}
}
@media (max-width:768px){
  :root{--header-height:64px}
  .main-content{padding:10px}
  .app-header{padding:10px 12px}
  .header-top-nav{display:none}
  .zd-hero{min-height:110px;padding:18px 14px}
  .zd-hero h1{font-size:19px}
}
@media (max-width:560px){
  .zd-academy-courses{grid-template-columns:1fr}
  .zd-academy-title{font-size:20px}
  .zd-masheikh-grid{grid-template-columns:1fr}
  .zd-latest-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<a href="#mainContent" class="skip-link" style="position:absolute;left:-9999px;top:0;background:var(--brand);color:#fff;padding:10px 16px;border-radius:0 0 8px 0;z-index:2000;font-weight:700"><?php echo e(t('skip_to_main')); ?></a>

<header class="app-header">
  <div class="header-left">
    <a href="user_dashboard.php" class="brand-box" style="display:flex;align-items:center;gap:4px">
      <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px">
      <span style="font-size:22px;font-weight:1000;color:var(--brand)">MyIslam</span>
    </a>
    <nav class="header-top-nav" aria-label="<?php echo e(t('main_navigation')); ?>">
      <?php foreach ($mainNavigation as $navItem): ?>
        <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>"><?php echo e($navItem['name']); ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="header-right">
    <button class="icon-btn" id="themeToggle" aria-label="<?php echo e(t('dark_mode')); ?>">🌙</button>
    <button class="lang-btn" id="langToggle">EN</button>
    <a href="notification.php" class="icon-btn" style="position:relative" aria-label="<?php echo e(t('notifications_label')); ?>">
      🔔
      <?php if ($unreadCount > 0): ?><span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span><?php endif; ?>
    </a>
    <div class="profile-area" id="profileArea">
      <button class="profile-btn" id="profileToggle" type="button" aria-expanded="false">
        <?php if (!empty($user['picture'])): ?><img src="<?php echo e($user['picture']); ?>" alt="">
        <?php else: ?><?php echo e(initials((string)$user['name'])); ?><?php endif; ?>
      </button>
      <div class="profile-menu" id="profileMenu">
        <div class="profile-menu-top">
          <?php if (!empty($user['picture'])): ?><img src="<?php echo e($user['picture']); ?>" alt="">
          <?php else: ?><div class="profile-avatar-fallback"><?php echo e(initials((string)$user['name'])); ?></div><?php endif; ?>
          <div>
            <h4><?php echo e((string)$user['name']); ?></h4>
            <p><?php echo e((string)$user['email']); ?></p>
            <p style="font-size:10px"><?php echo t('member_since'); ?> <?php echo e((string)$user['member_since']); ?></p>
          </div>
        </div>
        <a href="profile.php">👤 <?php echo t('profile'); ?></a>
        <a href="settings.php">⚙️ <?php echo t('settings'); ?></a>
        <a href="logout.php">🚪 <?php echo t('logout'); ?></a>
      </div>
    </div>
  </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" aria-expanded="false" aria-controls="sidebar">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-title">📚 <?php echo e(t('navigation')); ?></div>
  <nav class="subject-list">
    <?php foreach ($sidebarNavigation as $item): ?>
      <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
        <span><?php echo e($item['icon']); ?></span><span><?php echo e($item['name']); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<main class="main-content" id="mainContent">
<div class="zd">
  <div class="zd-layout">

    <!-- ============ LEFT COLUMN ============ -->
    <aside class="zd-left">

      <!-- Streak + today -->
      <div class="zd-panel">
        <div class="zd-panel-head"><span class="ico">🔥</span> Today</div>
        <div class="zd-panel-body">
          <div class="zd-rank-row">
            <div class="zd-rank-item">
              <div class="zd-rank-label">Streak</div>
              <div class="zd-rank-value"><?php echo $streakDays; ?></div>
              <div class="zd-rank-sub">day<?php echo $streakDays === 1 ? '' : 's'; ?></div>
            </div>
            <div class="zd-rank-divider"></div>
            <div class="zd-rank-item">
              <div class="zd-rank-label">Today</div>
              <div class="zd-rank-value"><?php echo $todayProgress; ?>%</div>
              <div class="zd-rank-sub">of daily goal</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Overall progress -->
      <div class="zd-panel">
        <div class="zd-panel-head"><span class="ico">📈</span> Overall progress</div>
        <div class="zd-panel-body">
          <div class="zd-rank-row">
            <div class="zd-rank-item">
              <div class="zd-rank-label">📚 Lessons</div>
              <div class="zd-rank-value"><?php echo $overallProgress; ?>%</div>
              <div class="zd-rank-sub"><?php echo $completedItems; ?>/<?php echo max(1, $totalItems); ?> done</div>
            </div>
            <div class="zd-rank-divider"></div>
            <div class="zd-rank-item">
              <div class="zd-rank-label">⏱️ Study time</div>
              <div class="zd-rank-value"><?php echo formatDurationShort($totalTimeSpent); ?></div>
              <div class="zd-rank-sub">total</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Overall rank -->
      <div class="zd-panel">
        <div class="zd-panel-head"><span class="ico">🏅</span> My rank</div>
        <div class="zd-panel-body">
          <div class="zd-rank-row">
            <div class="zd-rank-item">
              <div class="zd-rank-label">Overall</div>
              <div class="zd-rank-value"><?php echo $overallRank ? '#' . $overallRank : '--'; ?></div>
              <div class="zd-rank-sub">of <?php echo $overallParticipants ?: '--'; ?></div>
            </div>
            <div class="zd-rank-divider"></div>
            <div class="zd-rank-item">
              <div class="zd-rank-label">Lesson</div>
              <div class="zd-rank-value"><?php echo $lessonRank ? '#' . $lessonRank : '--'; ?></div>
              <div class="zd-rank-sub">of <?php echo $lessonParticipants ?: '--'; ?></div>
            </div>
          </div>
        </div>
      </div>

    </aside>

    <!-- ============ CENTER COLUMN ============ -->
    <div class="zd-main">

      <!-- Hero with hijri -->
      <section class="zd-hero">
        <div class="hijri">🕌 <?php echo e($hijriToday['formatted']); ?> <span class="greg">· <?php echo e($hijriToday['gregorian']); ?></span></div>
        <h1><?php echo t('welcome'); ?>, <?php echo e((string)$user['name']); ?></h1>
        <p><?php echo t('motivation'); ?></p>
      </section>

      <!-- Filter -->
      <div class="zd-filter">
        <label for="filterSelect">📅 <?php echo t('dashboard_period'); ?></label>
        <select id="filterSelect">
          <?php foreach ($filters as $label => $value): ?>
            <option value="<?php echo e($value); ?>" <?php echo $filterType === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
          <?php endforeach; ?>
          <option value="custom" <?php echo $filterType === 'custom' ? 'selected' : ''; ?>><?php echo t('custom'); ?></option>
        </select>
        <form method="get" action="user_dashboard.php" class="custom-filter-form<?php echo $filterType === 'custom' ? ' show' : ''; ?>" id="customFilterForm" style="display:<?php echo $filterType === 'custom' ? 'flex' : 'none'; ?>;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="filter" value="custom">
          <label style="font-size:11px;color:var(--muted)"><?php echo t('from'); ?>
            <input type="date" name="from" value="<?php echo e($customFrom !== '' ? $customFrom : $rangeStartDt->format('Y-m-d')); ?>" style="padding:6px 8px;border-radius:8px;border:1px solid var(--line);background:var(--surface);color:var(--text);font-size:12px;">
          </label>
          <label style="font-size:11px;color:var(--muted)"><?php echo t('to'); ?>
            <input type="date" name="to" value="<?php echo e($customTo !== '' ? $customTo : $rangeEndDt->format('Y-m-d')); ?>" style="padding:6px 8px;border-radius:8px;border:1px solid var(--line);background:var(--surface);color:var(--text);font-size:12px;">
          </label>
        </form>
      </div>

      <!-- Academy card — with background image -->
      <?php
      // Load up to two most recent active enrollments
      $myCourses = [];
      try {
          $stmt = $conn->prepare("
              SELECT e.id, e.course_id, c.title, c.description, e.started_at,
                     cd.name AS div_name, cd.start_date AS div_start, cd.end_date AS div_end,
                     (SELECT COUNT(*) FROM course_subjects cs WHERE cs.course_id = c.id) AS total_subjects,
                     (SELECT COUNT(*) FROM course_divisions cd2 WHERE cd2.course_id = c.id) AS total_divisions
              FROM course_enrollments e
              JOIN courses c ON c.id = e.course_id
              LEFT JOIN course_divisions cd ON cd.id = e.current_division_id
              WHERE e.user_id = ? AND e.status IN ('active','completed')
              ORDER BY e.started_at DESC
              LIMIT 2
          ");
          $stmt->execute([$userId]);
          $myCourses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (PDOException $e) {
          $myCourses = [];
      }
      $hasEnrollments = !empty($myCourses);
      ?>
      <section class="zd-academy-card">
        <h2 class="zd-academy-title">MyIslam Academy</h2>

        <?php if ($hasEnrollments): ?>
          <div class="zd-academy-courses">
            <?php foreach ($myCourses as $mc):
                $today2 = new DateTimeImmutable('today');
                $divStart = !empty($mc['div_start']) ? new DateTimeImmutable((string)$mc['div_start']) : null;
                $divEnd   = !empty($mc['div_end'])   ? new DateTimeImmutable((string)$mc['div_end'])   : null;

                $state = 'pending';
                $weekInfo = '';
                if ($divStart && $divEnd) {
                    if ($today2 < $divStart) {
                        $state = 'pending';
                        $days = (int)$today2->diff($divStart)->days;
                        $weekInfo = 'Starts in ' . $days . ' day' . ($days === 1 ? '' : 's');
                    } elseif ($today2 > $divEnd) {
                        $state = 'ended';
                        $weekInfo = 'Ended';
                    } else {
                        $state = 'active';
                        $daysIn = (int)$divStart->diff($today2)->days;
                        $week = (int)floor($daysIn / 7) + 1;
                        $totalWeeks = max(1, (int)ceil(($divStart->diff($divEnd)->days + 1) / 7));
                        $weekInfo = 'Week ' . $week . ' / ' . $totalWeeks;
                    }
                }
            ?>
            <a class="zd-academy-course" dir="rtl" href="course.php?course_id=<?php echo (int)$mc['course_id']; ?>">
    <div class="zd-academy-course-title"><?php echo e((string)$mc['title']); ?></div>
    <div class="zd-academy-course-meta">
      <?php if (!empty($mc['div_name'])): ?>
        <span>📚 <?php echo e((string)$mc['div_name']); ?></span>
      <?php endif; ?>
      <span>📖 <?php echo (int)$mc['total_subjects']; ?> subj</span>
      <span>🏫 <?php echo (int)$mc['total_divisions']; ?> div</span>
    </div>
    <?php if ($weekInfo !== ''): ?>
      <div class="zd-academy-course-state <?php echo e($state); ?>">
        <?php echo e($weekInfo); ?>
      </div>
    <?php endif; ?>
</a> 
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="zd-academy-empty">
            You haven't enrolled in any course yet.<br>
            Join the academy to start learning with a structured curriculum.
          </p>
        <?php endif; ?>

        <div class="zd-academy-cta-wrap">
          <?php if ($hasEnrollments): ?>
            <a class="zd-academy-cta" href="courses.php">Academy →</a>
          <?php else: ?>
            <a class="zd-academy-cta primary" href="courses.php">Join the Academy →</a>
          <?php endif; ?>
        </div>
      </section>

      <!-- Quick tiles -->
      <section class="zd-tiles">
        <a href="subjects.php" class="zd-tile"><span class="icon">📚</span> Subjects</a>
        <a href="quizzes.php" class="zd-tile"><span class="icon">📝</span> Quizzes</a>
        <a href="leaderboard.php" class="zd-tile"><span class="icon">🏆</span> Leaderboard</a>
        <a href="multimedia.php" class="zd-tile"><span class="icon">🖼️</span> Multimedia</a>
      </section>

      <!-- Latest lessons -->
      <section class="zd-panel">
        <div class="zd-panel-head"><span class="ico">📖</span> New lessons</div>
        <div class="zd-panel-body">
          <?php if (!empty($latestContents)): ?>
            <div class="zd-latest-grid">
              <?php foreach ($latestContents as $content):
                  $lessonImage = !empty($content['image_path']) ? $content['image_path'] : ($content['category_image_path'] ?? null);
                  $lessonYtId = !empty($content['video_url']) ? extractYoutubeId($content['video_url']) : null;
                  $lessonLink = !empty($content['subject_slug'])
                      ? 'subject.php?slug=' . urlencode((string)$content['subject_slug'])
                      : 'content.php?id=' . (int)$content['id'];
              ?>
                <a href="<?php echo e($lessonLink); ?>" class="zd-latest-card">
                  <div class="zd-latest-thumb">
                    <?php if (!empty($lessonImage)): ?>
                      <img src="<?php echo e($lessonImage); ?>" alt="<?php echo e((string)$content['title']); ?>">
                    <?php elseif (!empty($lessonYtId)): ?>
                      <img src="https://img.youtube.com/vi/<?php echo e($lessonYtId); ?>/hqdefault.jpg" alt="">
                    <?php else: ?>
                      <span style="font-size:28px;opacity:.5">📘</span>
                    <?php endif; ?>
                  </div>
                  <div class="zd-latest-info">
                    <div class="zd-latest-title"><?php echo e((string)$content['title']); ?></div>
                    <div class="zd-latest-type"><?php echo e((string)($content['subject_name'] ?? 'Lesson')); ?></div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="zd-empty"><?php echo t('dashboard_no_lessons'); ?></div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Latest media -->
      <section class="zd-panel">
        <div class="zd-panel-head"><span class="ico">🖼️</span> Latest media</div>
        <div class="zd-panel-body">
          <?php if (!empty($latestMedia)): ?>
            <div class="zd-latest-grid">
              <?php foreach ($latestMedia as $media):
                  $mediaThumb = normalizeUploadPath($media['thumbnail_path'] ?? null);
                  $mediaYtId = !empty($media['video_url']) ? extractYoutubeId($media['video_url']) : null;
                  $mediaFile = normalizeUploadPath($media['file_path'] ?? null);
              ?>
                <a href="multimedia.php?view=<?php echo (int)$media['id']; ?>" class="zd-latest-card">
                  <div class="zd-latest-thumb">
                    <?php if ($mediaThumb): ?>
                      <img src="<?php echo e($mediaThumb); ?>" alt="">
                    <?php elseif ($media['media_type'] === 'image'): ?>
                      <img src="<?php echo e($mediaFile); ?>" alt="">
                    <?php elseif ($mediaYtId): ?>
                      <img src="https://img.youtube.com/vi/<?php echo e($mediaYtId); ?>/hqdefault.jpg" alt="">
                    <?php else: ?>
                      <span style="font-size:28px;opacity:.5">🎵</span>
                    <?php endif; ?>
                  </div>
                  <div class="zd-latest-info">
                    <div class="zd-latest-title"><?php echo e((string)$media['title']); ?></div>
                    <div class="zd-latest-type"><?php echo e(ucfirst((string)($media['media_type'] ?? 'media'))); ?></div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="zd-empty"><?php echo t('dashboard_no_media'); ?></div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Reminder + resume -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" class="zd-reminder-resume">
        <div class="zd-reminder">
          <h3>🕒 <?php echo t('dashboard_inspiration'); ?></h3>
          <p><?php echo $todayReminder; ?></p>
        </div>
        <div class="zd-resume">
          <h3>▶ <?php echo t('dashboard_resume_learning'); ?></h3>
          <p dir="rtl"><?php echo $resumeLesson; ?></p>
          <a href="<?php echo e($resumeLink); ?>"><?php echo t('dashboard_continue_now'); ?> →</a>
        </div>
      </div>

      <!-- Masheikh -->
      <?php if (!empty($masheikh)): ?>
      <section class="zd-panel">
        <div class="zd-panel-head"><span class="ico">🕌</span> Masheikh</div>
        <div class="zd-panel-body">
          <div class="zd-masheikh-grid">
            <?php foreach ($masheikh as $name): ?>
              <div class="zd-masheikh-card">
                <div class="zd-masheikh-avatar"><?php echo e(initials((string)$name)); ?></div>
                <div class="zd-masheikh-name"><?php echo e((string)$name); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
      <?php endif; ?>

    </div>

    <!-- ============ RIGHT COLUMN ============ -->
    <aside class="zd-right">

      <!-- Progress trend -->
      <section class="zd-panel">
        <div class="zd-panel-head"><span class="ico">📊</span> Progress trend</div>
        <div class="zd-panel-body">
          <?php if (count($progressChartPoints) >= 2):
              $gridSteps = [0, 25, 50, 75, 100];
              $plotHeightPx = $progressChartHeight - ($progressChartPadding * 2);
              $plotLeftPx = $progressChartPadding;
              $plotRightPx = $progressChartWidth - $progressChartPadding;
              $plotBottomPx = $progressChartHeight - $progressChartPadding;
              $areaPoints = $plotLeftPx . ',' . $plotBottomPx;
              foreach ($progressChartPoints as $p) $areaPoints .= ' ' . $p['x'] . ',' . $p['y'];
              $areaPoints .= ' ' . $plotRightPx . ',' . $plotBottomPx;
          ?>
            <div class="zd-chart">
              <svg viewBox="0 0 <?php echo $progressChartWidth; ?> <?php echo $progressChartHeight; ?>" role="img" aria-label="Trend">
                <?php foreach ($gridSteps as $step):
                    $gy = round($progressChartPadding + (1 - ($step / 100)) * $plotHeightPx, 2);
                ?>
                  <line x1="<?php echo $plotLeftPx; ?>" y1="<?php echo $gy; ?>" x2="<?php echo $plotRightPx; ?>" y2="<?php echo $gy; ?>" stroke="var(--line)" stroke-width="1" stroke-dasharray="<?php echo $step === 0 ? 'none' : '4,4'; ?>" opacity="<?php echo $step === 0 ? '1' : '0.6'; ?>" />
                <?php endforeach; ?>
                <polygon points="<?php echo e($areaPoints); ?>" fill="var(--brand)" opacity="0.10" />
                <?php for ($i = 0; $i < count($progressChartPoints) - 1; $i++):
                    $p1 = $progressChartPoints[$i];
                    $p2 = $progressChartPoints[$i + 1];
                    $delta = $p2['score'] - $p1['score'];
                    if ($delta > 0.05) $color = 'var(--gold)';
                    elseif ($delta < -0.05) $color = 'var(--danger)';
                    else $color = 'var(--brand)';
                ?>
                  <line x1="<?php echo $p1['x']; ?>" y1="<?php echo $p1['y']; ?>" x2="<?php echo $p2['x']; ?>" y2="<?php echo $p2['y']; ?>" stroke="<?php echo $color; ?>" stroke-width="3" stroke-linecap="round" />
                <?php endfor; ?>
                <?php foreach ($progressChartPoints as $p): ?>
                  <circle cx="<?php echo $p['x']; ?>" cy="<?php echo $p['y']; ?>" r="5" fill="var(--brand)" stroke="var(--surface)" stroke-width="2">
                    <title><?php echo e($p['label'] . ': ' . number_format($p['score'], 1) . '%'); ?></title>
                  </circle>
                <?php endforeach; ?>
              </svg>
              <div class="zd-chart-labels">
                <?php foreach ($progressChartPoints as $i => $p): ?>
                  <span><?php echo ($i % $progressChartLabelStep === 0 || $i === count($progressChartPoints) - 1) ? e($p['label']) : ''; ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="zd-empty"><?php echo t('activity'); ?></div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Leaderboard -->
      <section class="zd-panel">
        <div class="zd-panel-head"><span class="ico">🏆</span> <?php echo t('dashboard_leaderboard_overview'); ?> <span style="font-size:10px;color:var(--muted);margin-left:auto;"><?php echo e($filterLabel); ?></span></div>
        <div class="zd-panel-body">
          <div class="zd-lb-wrap">
            <table class="zd-lb-table">
              <thead>
                <tr>
                  <th></th>
                  <th>Overall</th>
                  <th>Lesson</th>
                  <th>Self</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <th>Rank</th>
                  <td>#<?php echo $overallRank ?: '--'; ?></td>
                  <td>#<?php echo $lessonRank ?: '--'; ?></td>
                  <td>#<?php echo $selfRank ?: '--'; ?></td>
                </tr>
                <tr>
                  <th>Players</th>
                  <td><?php echo $overallParticipants; ?></td>
                  <td><?php echo $lessonParticipants; ?></td>
                  <td><?php echo $selfParticipants; ?></td>
                </tr>
                <tr>
                  <th>Points</th>
                  <td><?php echo number_format($overallPoints, 0); ?></td>
                  <td><?php echo number_format($lessonPoints, 0); ?></td>
                  <td><?php echo number_format($selfPoints, 0); ?></td>
                </tr>
                <tr>
                  <th>Quizzes</th>
                  <td><?php echo $overallQuizzesCompleted; ?></td>
                  <td><?php echo $lessonCompleted; ?></td>
                  <td><?php echo $selfCompleted; ?></td>
                </tr>
                <tr>
                  <th>Avg / Time</th>
                  <td><?php echo number_format($overallAvgScore, 1); ?>%</td>
                  <td><?php echo formatDurationShort($lessonTime); ?></td>
                  <td><?php echo formatDurationShort($selfTime); ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- Top students podium -->
      <?php if (!empty($topRankedParticipants)): ?>
      <section class="zd-panel">
        <div class="zd-panel-head"><span class="ico">🥇</span> Top students <span style="font-size:10px;color:var(--muted);margin-left:auto;"><?php echo e($filterLabel); ?></span></div>
        <div class="zd-panel-body" style="padding:6px 10px 14px;">
          <div class="zd-podium-grid">
            <?php foreach ($topRankedParticipants as $participant):
                $rank = (int)($participant['rank'] ?? 0);
                $tierClass = $rank === 1 ? 'zd-podium-first' : ($rank === 2 ? 'zd-podium-second' : 'zd-podium-third');
                $tierLabel = $rank === 1 ? '🥇' : ($rank === 2 ? '💎' : '🔷');
                $isMe = (int)($participant['user_id'] ?? 0) === $userId;
                $pName = $isMe ? t('you') : ($participant['display_name'] ?? t('user'));
                $pPicture = $participant['profile_image'] ?? '';
                $pPoints = (float)($participant['total_points'] ?? 0);
            ?>
              <div class="zd-podium-card <?php echo $tierClass; ?><?php echo $isMe ? ' zd-podium-you' : ''; ?>">
                <div class="zd-podium-badge">#<?php echo $rank; ?> · <?php echo $tierLabel; ?></div>
                <div class="zd-podium-avatar">
                  <?php if (!empty($pPicture)): ?><img src="<?php echo e($pPicture); ?>" alt="">
                  <?php else: ?><?php echo e(initials($pName)); ?><?php endif; ?>
                </div>
                <div class="zd-podium-name"><?php echo e($pName); ?></div>
                <div class="zd-podium-points"><?php echo number_format($pPoints, 0); ?> <?php echo e(t('points')); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <!-- Upcoming quizzes -->
      <section class="zd-panel">
        <div class="zd-panel-head"><span class="ico">📝</span> <?php echo t('dashboard_upcoming_quizzes'); ?></div>
        <div class="zd-panel-body">
          <?php if (!empty($upcomingQuizzes)): ?>
            <?php foreach ($upcomingQuizzes as $quiz): ?>
              <a href="quizzes.php?id=<?php echo (int)$quiz['id']; ?>" class="zd-quiz-item">
                <div class="zd-quiz-info">
                  <h4><?php echo e($quiz['title'] ?? 'Quiz'); ?></h4>
                  <div class="zd-quiz-meta">
                    <?php echo e(ucfirst((string)($quiz['difficulty'] ?? 'medium'))); ?>
                    <?php if (!empty($quiz['mode'])): ?> · <?php echo e(ucfirst((string)$quiz['mode'])); ?><?php endif; ?>
                    <?php if (!empty($quiz['time_limit_minutes'])): ?> · <?php echo (int)$quiz['time_limit_minutes']; ?> min<?php endif; ?>
                  </div>
                </div>
                <div class="zd-quiz-badge">Take →</div>
              </a>
            <?php endforeach; ?>
            <a href="quizzes.php" style="display:block;text-align:center;margin-top:8px;color:var(--brand);font-size:12px;font-weight:700;"><?php echo t('dashboard_view_all_quizzes'); ?> →</a>
          <?php else: ?>
            <div class="zd-empty"><?php echo t('dashboard_no_quizzes'); ?></div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Radio -->
      <div class="zd-radio">
        <h3>📻 Radio Qur-aan 24×7</h3>
        <div class="zd-radio-wrap">
          <p>Live Radio Qur-aan</p>
          <audio src="https://stream.zeno.fm/dok66ovac9guv" controls></audio>
        </div>
      </div>

      <div class="zd-radio">
        <h3>📻 Radio Mawaidha 24×7</h3>
        <div class="zd-radio-wrap">
          <p>Live Radio Mawaidha</p>
          <audio src="https://stream.zeno.fm/ydvkzahuiuatv" controls></audio>
        </div>
      </div>

    </aside>
  </div>

  <div class="zd-footer">
    <div class="brand-text">MyIslam</div>
    <div class="gold-text">#Manhaj Salaf</div>
  </div>
</div>
</main>

<script>
(function(){
  const body = document.body;
  const menuToggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  const themeToggle = document.getElementById('themeToggle');
  const langToggle = document.getElementById('langToggle');
  const profileToggle = document.getElementById('profileToggle');
  const profileMenu = document.getElementById('profileMenu');
  const profileArea = document.getElementById('profileArea');

  function openSidebar(){sidebar.classList.add('open');sidebarOverlay.classList.add('show');body.style.overflow='hidden';menuToggle.setAttribute('aria-expanded','true')}
  function closeSidebar(){sidebar.classList.remove('open');sidebarOverlay.classList.remove('show');body.style.overflow='';menuToggle.setAttribute('aria-expanded','false')}

  if(menuToggle)menuToggle.addEventListener('click',()=>sidebar.classList.contains('open')?closeSidebar():openSidebar());
  if(sidebarOverlay)sidebarOverlay.addEventListener('click',closeSidebar);

  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
      if(sidebar.classList.contains('open'))closeSidebar();
      if(profileMenu.classList.contains('show')){profileMenu.classList.remove('show');profileToggle.setAttribute('aria-expanded','false')}
    }
  });

  if(localStorage.getItem('theme')==='dark'){body.classList.add('dark-mode');themeToggle.textContent='☀'}
  themeToggle.addEventListener('click',()=>{
    body.classList.toggle('dark-mode');
    const dark=body.classList.contains('dark-mode');
    themeToggle.textContent=dark?'☀':'🌙';
    localStorage.setItem('theme',dark?'dark':'light');
  });

  let currentLang=localStorage.getItem('language')||'<?php echo $_SESSION['language'] ?? "sw"; ?>';
  langToggle.textContent=currentLang==='sw'?'SW':'EN';
  langToggle.addEventListener('click',async()=>{
    currentLang=currentLang==='sw'?'en':'sw';
    langToggle.textContent=currentLang==='sw'?'SW':'EN';
    localStorage.setItem('language',currentLang);
    try{await fetch('ajax/switch_language.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({language:currentLang})});location.reload();}catch(e){}
  });

  if(profileToggle&&profileMenu){
    profileToggle.addEventListener('click',function(e){
      e.stopPropagation();
      const open=profileMenu.classList.contains('show');
      profileMenu.classList.toggle('show',!open);
      profileToggle.setAttribute('aria-expanded',String(!open));
    });
    document.addEventListener('click',function(e){
      if(!profileArea.contains(e.target)){profileMenu.classList.remove('show');profileToggle.setAttribute('aria-expanded','false')}
    });
  }

  const filterSelect=document.getElementById('filterSelect');
  const customFilterForm=document.getElementById('customFilterForm');
  if(filterSelect){
    filterSelect.addEventListener('change',()=>{
      const val=filterSelect.value;
      if(val==='custom'){
        if(customFilterForm)customFilterForm.style.display='flex';
      }else{
        window.location.href='?filter='+encodeURIComponent(val);
      }
    });
  }
  if(customFilterForm){
    customFilterForm.querySelectorAll('input[type="date"]').forEach(input=>{
      input.addEventListener('change',function(){if(this.value)customFilterForm.submit()});
    });
  }
})();
</script>
</body>
</html>