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

function formatDateValue(?string $date, string $format = 'M d, Y'): string
{
    if (empty($date)) {
        return '';
    }

    $time = strtotime($date);
    return $time ? date($format, $time) : '';
}

function normalizeUploadPath(?string $path): ?string
{
    if (empty($path)) {
        return null;
    }
    // media.file_path is stored without a leading slash ("uploads/media/x.mp4"),
    // unlike every other upload path in this app ("/uploads/audio/...",
    // "/myislam/uploads/subjects/..."). Left as-is, that relative path
    // resolves against whatever folder the current page happens to be in,
    // not the site root - which silently breaks it as soon as this page
    // isn't served from exactly the root. Absolute URLs are left untouched.
    if (preg_match('~^(https?:)?//~i', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

// Pulls a YouTube video id out of an embed/watch/short URL, for building a
// thumbnail preview - returns null for non-YouTube URLs (e.g. Vimeo).
// Matches multimedia.php's extractYoutubeId() exactly.
function extractYoutubeId(?string $url): ?string
{
    if (empty($url)) {
        return null;
    }
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
        return $m[1];
    }
    return null;
}

function formatDurationShort(int $seconds): string
{
    if ($seconds <= 0) {
        return '—';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0 && $minutes > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }
    return $minutes . 'm';
}

function progressScoreColorVar(float $score): string
{
    if ($score >= 80) {
        return 'var(--gold)';
    }
    if ($score >= 50) {
        return 'var(--brand)';
    }
    return 'var(--danger)';
}

/**
 * Assigns standard competition ranks (1, 2, 2, 4 ...) to an array of rows
 * that is already sorted by $pointsKey descending, so tied scores share the
 * same rank instead of being arbitrarily split by row order.
 */
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

    if (array_key_exists($table, $cache)) {
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
    if (array_key_exists($key, $cache)) {
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
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
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
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
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
            ORDER BY created_at DESC, id DESC
            LIMIT 1
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
            LEFT JOIN user_content_progress ucp
                ON ucp.content_id = c.id AND ucp.user_id = ?
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
        return [
            'total_items' => 0,
            'completed_items' => 0,
            'overall_progress' => 0,
            'total_time_spent' => 0,
            'avg_progress' => 0,
        ];
    }
}

function getLeaderboardStatsByMode(PDO $conn, int $userId, string $mode, string $rangeStart, string $rangeEnd): array
{
    $default = [
        'mode' => $mode,
        'user_id' => $userId,
        'total_points' => 0,
        'quizzes_taken' => 0,
        'quizzes_completed' => 0,
        'average_score' => 0,
        'best_score' => 0,
        'total_correct_answers' => 0,
        'total_wrong_answers' => 0,
        'total_time_seconds' => 0,
        'rank_position' => 0,
        'total_participants' => 0,
    ];

    try {
        $modeCondition = '';
        if ($mode === 'overall') {
            // Overall includes all modes
            $modeCondition = '';
        } elseif (in_array($mode, ['lesson', 'competition'], true)) {
            $modeCondition = 'AND q.mode = :mode';
        } else {
            // Invalid mode
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
        if ($modeCondition && $mode !== 'overall') {
            $stmt->bindValue(':mode', $mode);
        }
        $stmt->execute();
        $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($allRows, static function ($a, $b) {
            return ((float)$b['total_points']) <=> ((float)$a['total_points']);
        });
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

        if (!$userStats) {
            return $default;
        }

        $userStats['mode'] = $mode;
        $userStats['rank_position'] = $rank;
        $userStats['total_participants'] = count($allRows);

        return array_merge($default, $userStats);
    } catch (PDOException $e) {
        error_log("Error getting leaderboard stats by mode: " . $e->getMessage());
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
        return [
            'quizzes_completed' => 0,
            'total_points' => 0,
            'average_score' => 0,
            'total_time_seconds' => 0,
            'rank_position' => 0,
            'total_participants' => 0,
        ];
    }
}

function getMonthlyExamStats(PDO $conn, int $userId, string $rangeStart, string $rangeEnd): array
{
    try {
        $stmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT exam_id) AS exams_attempted,
                SUM(score) AS total_points,
                AVG(percentage) AS average_score
            FROM monthly_exam_attempts
            WHERE user_id = ? AND status = 'completed'
              AND completed_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $rangeStart, $rangeEnd]);
        $stats = $stmt->fetch();

        $rankStmt = $conn->prepare("
            SELECT COUNT(*) + 1 AS rank_position
            FROM (
                SELECT user_id, SUM(score) AS total_points
                FROM monthly_exam_attempts
                WHERE status = 'completed'
                  AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
                HAVING SUM(score) > (
                    SELECT COALESCE(SUM(score), 0)
                    FROM monthly_exam_attempts
                    WHERE user_id = ? AND status = 'completed'
                      AND completed_at BETWEEN ? AND ?
                )
            ) AS better
        ");
        $rankStmt->execute([$rangeStart, $rangeEnd, $userId, $rangeStart, $rangeEnd]);
        $rank = $rankStmt->fetch();

        $totalStmt = $conn->prepare("
            SELECT COUNT(DISTINCT user_id) AS total_participants
            FROM monthly_exam_attempts
            WHERE status = 'completed'
              AND completed_at BETWEEN ? AND ?
        ");
        $totalStmt->execute([$rangeStart, $rangeEnd]);
        $total = $totalStmt->fetch();

        return [
            'exams_attempted' => (int)($stats['exams_attempted'] ?? 0),
            'total_points' => (float)($stats['total_points'] ?? 0),
            'average_score' => (float)($stats['average_score'] ?? 0),
            'rank_position' => (int)($rank['rank_position'] ?? 0),
            'total_participants' => (int)($total['total_participants'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log("Error getting monthly exam stats: " . $e->getMessage());
        return [
            'exams_attempted' => 0,
            'total_points' => 0,
            'average_score' => 0,
            'rank_position' => 0,
            'total_participants' => 0,
        ];
    }
}

function getTodayReminder(PDO $conn, int $userId, string $role = 'user'): ?array
{
    try {
        $announcement = getActiveAnnouncement($conn, $role);

        if ($announcement && !empty($announcement['message'])) {
            return [
                'message' => $announcement['title'] . ': ' . $announcement['message'],
                'is_motivational' => false,
            ];
        }

        $motivationalMessages = [
           
        ];

        return [
            'message' => $motivationalMessages[array_rand($motivationalMessages)],
            'is_motivational' => true,
        ];
    } catch (Throwable $e) {
        error_log("Error getting reminder: " . $e->getMessage());
        return ['message' =>  t('motivation') ];
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
            WHERE ucp.user_id = ?
              AND ucp.last_accessed_at IS NOT NULL
            ORDER BY ucp.last_accessed_at DESC
            LIMIT 1
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
        error_log("Error getting resume state: " . $e->getMessage());
        return ['resume_label' => 'Continue your learning journey'];
    }
}

function getUserStreak(PDO $conn, int $userId): int
{
    try {
        // Real consecutive-day streak: walk backward from today (or from
        // yesterday, if today has no activity yet - so the streak doesn't
        // wrongly reset to 0 the moment the clock passes midnight before the
        // user has had a chance to log in) counting each unbroken day of
        // activity. This previously just counted the number of distinct
        // active days in a rolling 30-day window, which is a very different
        // (and much larger) number than an actual streak - e.g. someone
        // active on day 1 and day 30 only would have shown a "2-day streak"
        // instead of the correct 0 or 1.
        $stmt = $conn->prepare("
            SELECT DISTINCT DATE(last_accessed_at) AS activity_date
            FROM user_content_progress
            WHERE user_id = ?
              AND last_accessed_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
        ");
        $stmt->execute([$userId]);
        $activityDates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($activityDates)) {
            return 0;
        }

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
        error_log("Error getting streak: " . $e->getMessage());
        return 0;
    }
}

function getTodayProgress(PDO $conn, int $userId): int
{
    try {
        $today = date('Y-m-d');

        $stmt = $conn->prepare("
            SELECT
                COUNT(CASE WHEN is_completed = 1 THEN 1 END) AS completed,
                COUNT(*) AS total
            FROM user_content_progress
            WHERE user_id = ?
              AND DATE(last_accessed_at) = ?
        ");
        $stmt->execute([$userId, $today]);
        $result = $stmt->fetch();

        if ($result && (int)$result['total'] > 0) {
            $progress = round(((int)$result['completed'] / (int)$result['total']) * 100);
            return min(100, (int)$progress);
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS has_activity
            FROM user_activity_logs
            WHERE user_id = ?
              AND DATE(created_at) = ?
        ");
        $stmt->execute([$userId, $today]);
        $activity = $stmt->fetch();

        if ($activity && (int)$activity['has_activity'] > 0) {
            return 10;
        }

        return 0;
    } catch (PDOException $e) {
        error_log("Error getting today progress: " . $e->getMessage());
        return 0;
    }
}

function getSubjects(PDO $conn, int $userId): array
{
    try {
        // Single query with a LEFT JOIN instead of one extra query per
        // subject (previously N+1: one query to list subjects, then another
        // per subject just to compute its progress).
        $stmt = $conn->prepare("
            SELECT
                s.id, s.name,s.type, s.slug, s.description, s.icon, s.sort_order, s.is_active,
                COUNT(DISTINCT ucp.content_id) AS total,
                COUNT(DISTINCT CASE WHEN ucp.is_completed = 1 THEN ucp.content_id END) AS completed
            FROM subjects s
            LEFT JOIN user_content_progress ucp
                ON ucp.subject_id = s.id AND ucp.user_id = ?
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
        error_log("Error getting subjects: " . $e->getMessage());
        return [];
    }
}

function getRecentActivities(PDO $conn, int $userId, string $rangeStart, string $rangeEnd, int $limit = 5): array
{
    try {
        $limit = max(1, (int)$limit);
        $sql = "
            SELECT action, description, created_at, ip_address
            FROM user_activity_logs
            WHERE user_id = ?
              AND created_at BETWEEN ? AND ?
            ORDER BY created_at DESC
            LIMIT $limit
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$userId, $rangeStart, $rangeEnd]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting activities: " . $e->getMessage());
        return [];
    }
}

function getUpcomingQuizzes(PDO $conn, int $userId, string $rangeEnd, int $limit = 3): array
{
    try {
        $limit = max(1, (int)$limit);
        $sql = "
            SELECT
                q.id,
                q.title,
                q.slug,
                q.difficulty,
                q.time_limit_minutes,
                q.subject_id,
                q.mode,
                q.competition_start_at,
                (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id AND user_id = ?) AS attempts_taken
            FROM quizzes q
            WHERE q.status = 'published'
              AND (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id AND user_id = ? AND status = 'completed') < COALESCE(q.max_attempts, 999999)
              AND (
                    q.mode <> 'competition'
                    OR (q.competition_start_at IS NOT NULL AND q.competition_start_at > ?)
                  )
            ORDER BY q.created_at DESC, q.id DESC
            LIMIT $limit
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$userId, $userId, $rangeEnd]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting upcoming quizzes: " . $e->getMessage());
        return [];
    }
}

function getUpcomingExams(PDO $conn, int $userId, string $rangeEnd, int $limit = 3): array
{
    try {
        $limit = max(1, (int)$limit);
        $sql = "
            SELECT
                me.id,
                me.title,
                me.description,
                me.start_date,
                me.end_date,
                me.time_limit_minutes,
                me.passing_score,
                (SELECT COUNT(*) FROM monthly_exam_attempts WHERE exam_id = me.id AND user_id = ? AND status = 'completed') AS attempts_taken,
                (SELECT COUNT(*) FROM monthly_exam_questions WHERE exam_id = me.id) AS total_questions
            FROM monthly_exams me
            WHERE me.status = 'published'
              AND (me.start_date > ? OR NOW() BETWEEN me.start_date AND me.end_date)
              AND (SELECT COUNT(*) FROM monthly_exam_attempts WHERE exam_id = me.id AND user_id = ? AND status = 'completed') < COALESCE(me.max_attempts, 999999)
            ORDER BY me.start_date ASC, me.id DESC
            LIMIT $limit
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$userId, $rangeEnd, $userId]);
        $exams = $stmt->fetchAll();
        
        $now = new DateTime();
        foreach ($exams as &$exam) {
            $start = new DateTime($exam['start_date']);
            $end   = new DateTime($exam['end_date']);
            if ($now < $start) {
                $exam['status'] = 'upcoming';
            } else {
                $exam['status'] = 'live';
            }
        }
        return $exams;
    } catch (PDOException $e) {
        error_log("Error getting upcoming exams: " . $e->getMessage());
        return [];
    }
}

function getLatestMedia(PDO $conn, int $limit = 4): array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, title, file_path, video_url, media_type, thumbnail_path, created_at
            FROM media
            WHERE status = 'published'
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting latest media: " . $e->getMessage());
        return [];
    }
}

function getMediaViewsCount(PDO $conn, int $userId): int
{
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT media_id)
            FROM media_views
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error getting media views count: " . $e->getMessage());
        return 0;
    }
}

function getLatestContents(PDO $conn, int $limit = 4): array
{
    try {
        // A lesson usually has no image of its own - category.php already
        // falls back to the parent category's image_path in that case
        // ($contentBgImage = $content['image_path'] ?: $categoryImage), so
        // do the same fallback here via a join to categories.
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
        error_log("Error getting latest contents: " . $e->getMessage());
        return [];
    }
}

function getTopRankedParticipants(PDO $conn, string $rangeStart, string $rangeEnd, string $prevRangeStart, string $prevRangeEnd, int $limit = 3, int $excludeUserId = 0): array
{
    try {
        // Get top participants excluding the current user
        $sql = "
            SELECT
                u.id AS user_id,
                u.username,
                u.full_name,
                u.email,
                u.profile_image,
                COALESCE(qa_points.points, 0) + COALESCE(me_points.points, 0) AS total_points
            FROM users u
            LEFT JOIN (
                SELECT user_id, SUM(score) AS points
                FROM quiz_attempts
                WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
            ) qa_points ON qa_points.user_id = u.id
            LEFT JOIN (
                SELECT user_id, SUM(score) AS points
                FROM monthly_exam_attempts
                WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
            ) me_points ON me_points.user_id = u.id
            WHERE u.is_active = 1
              AND u.id != ?
              AND (COALESCE(qa_points.points, 0) + COALESCE(me_points.points, 0)) > 0
            ORDER BY total_points DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(1, $rangeStart);
        $stmt->bindValue(2, $rangeEnd);
        $stmt->bindValue(3, $rangeStart);
        $stmt->bindValue(4, $rangeEnd);
        $stmt->bindValue(5, $excludeUserId, PDO::PARAM_INT);
        $stmt->bindValue(6, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Current user's total points for the same period, so the podium reflects
        // their real position (this previously ran a second, inconsistent rank
        // calculation here that only counted quiz points - it's no longer needed
        // since ranks are recomputed below from the combined, merged total_points)
        $userSql = "
            SELECT
                u.id AS user_id,
                u.username,
                u.full_name,
                u.email,
                u.profile_image,
                COALESCE(qa_points.points, 0) + COALESCE(me_points.points, 0) AS total_points
            FROM users u
            LEFT JOIN (
                SELECT user_id, SUM(score) AS points
                FROM quiz_attempts
                WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
            ) qa_points ON qa_points.user_id = u.id
            LEFT JOIN (
                SELECT user_id, SUM(score) AS points
                FROM monthly_exam_attempts
                WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
            ) me_points ON me_points.user_id = u.id
            WHERE u.id = ?
        ";
        $userStmt = $conn->prepare($userSql);
        $userStmt->execute([$rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $excludeUserId]);
        $userRow = $userStmt->fetch();

        // Add current user if they have points
        if ($userRow && (float)($userRow['total_points'] ?? 0) > 0) {
            $rows[] = $userRow;
        }

        // Sort all candidates by total_points descending and keep the true top 3
        usort($rows, static function ($a, $b) {
            return ((float)$b['total_points']) <=> ((float)$a['total_points']);
        });
        $rows = array_slice($rows, 0, 3);

        // Standard competition ranking: tied scores share the same rank (1, 2, 2, 4)
        assignCompetitionRanks($rows, 'total_points');

        // Format display names
        foreach ($rows as &$row) {
            if (!empty($row['username'])) {
                $row['display_name'] = $row['username'];
            } elseif (!empty($row['full_name'])) {
                $row['display_name'] = $row['full_name'];
            } else {
                $emailParts = explode('@', $row['email'] ?? '');
                $row['display_name'] = !empty($emailParts[0]) ? $emailParts[0] : 'User';
            }
        }
        unset($row);

        // Work out whether each podium member climbed, dropped, or held their
        // position compared to the immediately preceding period of equal length,
        // so the UI can show a rising / falling / flat trend line per card.
        $podiumUserIds = array_map(static function ($row) {
            return (int)$row['user_id'];
        }, $rows);
        $prevRanks = getPreviousPeriodRankMap($conn, $prevRangeStart, $prevRangeEnd, $podiumUserIds);

        foreach ($rows as &$row) {
            $uid = (int)$row['user_id'];
            $prevRank = $prevRanks[$uid] ?? null;
            $row['prev_rank'] = $prevRank;

            if ($prevRank === null) {
                $row['trend'] = 'new';   // wasn't ranked at all in the previous period
            } elseif ($prevRank > $row['rank']) {
                $row['trend'] = 'up';    // lower rank number now = climbed
            } elseif ($prevRank < $row['rank']) {
                $row['trend'] = 'down';
            } else {
                $row['trend'] = 'same';
            }
        }
        unset($row);

        return $rows;
    } catch (PDOException $e) {
        error_log("Error getting top ranked participants: " . $e->getMessage());
        return [];
    }
}

/**
 * Computes each user's rank within a previous comparison period, based on the
 * same combined (quiz + monthly exam) points used for the live podium, so
 * rank movement is measured on an apples-to-apples basis. Only active users
 * with points > 0 are ranked; users with no activity in that period simply
 * won't appear in the returned map (treated as "new" by the caller).
 */
function getPreviousPeriodRankMap(PDO $conn, string $rangeStart, string $rangeEnd, array $userIds): array
{
    if (empty($userIds)) {
        return [];
    }

    try {
        $sql = "
            SELECT
                u.id AS user_id,
                COALESCE(qa_points.points, 0) + COALESCE(me_points.points, 0) AS total_points
            FROM users u
            LEFT JOIN (
                SELECT user_id, SUM(score) AS points
                FROM quiz_attempts
                WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
            ) qa_points ON qa_points.user_id = u.id
            LEFT JOIN (
                SELECT user_id, SUM(score) AS points
                FROM monthly_exam_attempts
                WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
                GROUP BY user_id
            ) me_points ON me_points.user_id = u.id
            WHERE u.is_active = 1
              AND (COALESCE(qa_points.points, 0) + COALESCE(me_points.points, 0)) > 0
            ORDER BY total_points DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$rangeStart, $rangeEnd, $rangeStart, $rangeEnd]);
        $rows = $stmt->fetchAll();

        assignCompetitionRanks($rows, 'total_points');

        $rankByUserId = [];
        foreach ($rows as $row) {
            $rankByUserId[(int)$row['user_id']] = (int)$row['rank'];
        }

        $wantedIds = array_map('intval', $userIds);
        return array_intersect_key($rankByUserId, array_flip($wantedIds));
    } catch (PDOException $e) {
        error_log("Error getting previous period ranks: " . $e->getMessage());
        return [];
    }
}

function getUserProgressHistory(PDO $conn, int $userId, string $rangeStart, string $rangeEnd): array
{
    try {
        // Get daily quiz scores for the period
        $stmt = $conn->prepare("
            SELECT
                DATE(completed_at) AS activity_date,
                AVG(percentage) AS avg_score,
                COUNT(*) AS quiz_count
            FROM quiz_attempts
            WHERE user_id = ?
              AND status = 'completed'
              AND completed_at BETWEEN ? AND ?
            GROUP BY DATE(completed_at)
            ORDER BY activity_date ASC
        ");
        $stmt->execute([$userId, $rangeStart, $rangeEnd]);
        $rows = $stmt->fetchAll();

        // If no quiz data, try to get content progress data
        if (empty($rows)) {
            $stmt = $conn->prepare("
                SELECT                    DATE(last_accessed_at) AS activity_date,
                    AVG(progress_percent) AS avg_score,
                    COUNT(*) AS content_count
                FROM user_content_progress
                WHERE user_id = ?
                  AND last_accessed_at BETWEEN ? AND ?
                  AND progress_percent > 0
                GROUP BY DATE(last_accessed_at)
                ORDER BY activity_date ASC
            ");
            $stmt->execute([$userId, $rangeStart, $rangeEnd]);
            $rows = $stmt->fetchAll();
        }

        $history = [];
        foreach ($rows as $row) {
            if (empty($row['activity_date'])) {
                continue;
            }
            $history[] = [
                'label' => date('M d', strtotime((string)$row['activity_date'])),
                'score' => round((float)($row['avg_score'] ?? 0), 1),
                'count' => (int)($row['quiz_count'] ?? $row['content_count'] ?? 0),
            ];
        }

        // No fabricated placeholder data here: if the user genuinely has no
        // quiz or lesson activity in this period, $history stays empty and the
        // UI shows its real "not enough activity yet" empty state instead of a
        // misleading randomly-generated trend line.
        return $history;
    } catch (PDOException $e) {
        error_log("Error getting user progress history: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// HIJRI CALENDAR
// ============================================================================
//
// The Umm al-Qura calendar is NOT a fixed arithmetic offset from the
// Gregorian calendar - every Hijri month is 29 or 30 days depending on real
// moon-visibility calculations at Makkah, so purely local approximations
// (PHP intl's "islamic-umalqura" ICU data, or the classic tabular/Kuwaiti
// algorithm) can silently drift a day or two behind the real, official date
// depending on how current their internal tables are. That drift is what
// was causing this page to show 14 Safar instead of the correct 16 Safar.
//
// getHijriDateDisplay() now asks a live Umm al-Qura conversion service
// (Aladhan) for the exact date first, and only falls back to the local
// approximations - corrected by HIJRI_LOCAL_FALLBACK_ADJUSTMENT_DAYS below -
// if that request can't be reached (no outbound internet access, timeout,
// etc). Results are cached on disk for a few hours so we don't hit the API
// on every page load.
//
// If the local fallback is ever visibly wrong again, re-check the current
// drift at https://ummalquracalendar.org/ and update the constant below.
const HIJRI_LOCAL_FALLBACK_ADJUSTMENT_DAYS = 2;

function fetchHijriFromAladhan(DateTime $gregorianDate): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

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
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'MyIslam-Dashboard/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0 || $response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        $hijri = $data['data']['hijri'] ?? null;
        if (empty($hijri['day']) || empty($hijri['month']['en']) || empty($hijri['year'])) {
            return null;
        }

        $result = [
            'day' => (string)$hijri['day'],
            'month' => (string)$hijri['month']['en'],
            'year' => (string)$hijri['year'],
        ];

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        @file_put_contents($cacheFile, json_encode($result));

        return $result;
    } catch (Throwable $e) {
        error_log('Aladhan Hijri API error: ' . $e->getMessage());
        return null;
    }
}

function getHijriDateDisplay(): array
{
    $timezone = 'Asia/Riyadh';
    
    try {
        $now = new DateTime('now', new DateTimeZone($timezone));
    } catch (Throwable $e) {
        $now = new DateTime('now');
    }
    
    // Maghrib time is approximately 6:30 PM in Riyadh
    $currentHour = (int)$now->format('H');
    $currentMinute = (int)$now->format('i');
    $isAfterMaghrib = ($currentHour > 18) || ($currentHour == 18 && $currentMinute >= 30);
    
    $islamicDate = clone $now;
    if ($isAfterMaghrib) {
        $islamicDate->modify('+1 day');
    }
    
    // Islamic day names
    $weekdayMap = [
        'Sunday' => 'Yaumul Ahad',
        'Monday' => 'Yaumul Ithnain',
        'Tuesday' => 'Yaumul Thulatha',
        'Wednesday' => 'Yaumul Arba\'a',
        'Thursday' => 'Yaumul Khamis',
        'Friday' => 'Yaumul Jum\'ah',
        'Saturday' => 'Yaumul Sabt'
    ];
    $gregorianWeekday = $islamicDate->format('l');
    $islamicDay = $weekdayMap[$gregorianWeekday] ?? $gregorianWeekday;

    // 1) Preferred: live, authoritative Umm al-Qura conversion (accurate, no drift)
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

    // 2) Fallback: PHP intl's islamic-umalqura data, corrected for the known drift
    $correctedDate = (clone $islamicDate)->modify('+' . HIJRI_LOCAL_FALLBACK_ADJUSTMENT_DAYS . ' days');

    if (class_exists('IntlDateFormatter')) {
        try {
            $fmt = new IntlDateFormatter(
                'en_SA@calendar=islamic-umalqura',
                IntlDateFormatter::FULL,
                IntlDateFormatter::NONE,
                $timezone,
                IntlDateFormatter::TRADITIONAL
            );
            $fmt->setPattern('d MMMM yyyy');
            $formatted = $fmt->format($correctedDate);

            if (!empty($formatted)) {
                $hijriParts = explode(' ', $formatted);
                $hijriDay = $hijriParts[0] ?? '';
                $hijriMonth = $hijriParts[1] ?? '';
                $hijriYear = $hijriParts[2] ?? '';
                
                return [
                    'formatted' => "{$islamicDay}, {$hijriDay} {$hijriMonth} {$hijriYear} AH",
                    'gregorian' => $now->format('F j, Y'),
                    'source' => 'umalqura-corrected',
                    'islamic_day' => $islamicDay,
                    'is_after_maghrib' => $isAfterMaghrib,
                ];
            }
        } catch (Throwable $e) {
            error_log('Hijri date error: ' . $e->getMessage());
        }
    }

    // 3) Last resort: tabular calculation, corrected for the same known drift
    return getHijriDateFallback($correctedDate, $islamicDay, $now, $isAfterMaghrib);
}

function getHijriDateFallback(DateTime $islamicDate, string $islamicDay, DateTime $gregorianNow, bool $isAfterMaghrib): array
{
    $hijriMonths = [
        1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' al-Awwal", 4 => "Rabi' al-Thani",
        5 => 'Jumada al-Awwal', 6 => 'Jumada al-Thani', 7 => 'Rajab', 8 => "Sha'ban",
        9 => 'Ramadan', 10 => 'Shawwal', 11 => "Dhu al-Qi'dah", 12 => 'Dhu al-Hijjah',
    ];

    $y = (int)$islamicDate->format('Y');
    $m = (int)$islamicDate->format('n');
    $d = (int)$islamicDate->format('j');

    $a = intdiv(14 - $m, 12);
    $y2 = $y + 4800 - $a;
    $m2 = $m + 12 * $a - 3;
    $jd = $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4) - intdiv($y2, 100) + intdiv($y2, 400) - 32045;

    $l = $jd - 1948440 + 10632;
    $n = (int) floor(($l - 1) / 10631);
    $l = $l - 10631 * $n + 354;
    $j = (int) floor((10985 - $l) / 5316) * (int) floor((50 * $l) / 17719)
        + (int) floor($l / 5670) * (int) floor((43 * $l) / 15238);
    $l = $l - (int) floor((30 - $j) / 15) * (int) floor((17719 * $j) / 50)
        - (int) floor($j / 16) * (int) floor((15238 * $j) / 43) + 29;
    $hMonth = (int) floor((24 * $l) / 709);
    $hDay = $l - (int) floor((709 * $hMonth) / 24);
    $hYear = 30 * $n + $j - 30;

    $monthName = $hijriMonths[$hMonth] ?? '';

    return [
        'formatted' => trim("{$islamicDay}, {$hDay} {$monthName} {$hYear} AH"),
        'gregorian' => $gregorianNow->format('F j, Y'),
        'source' => 'tabular-corrected',
        'islamic_day' => $islamicDay,
        'is_after_maghrib' => $isAfterMaghrib,
    ];
}

// ============================================================================
// USER AUTHENTICATION VERIFICATION
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

// ============================================================================
// SANITIZE USER DATA
// ============================================================================

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
if (!in_array($filterType, $allowedFilters, true)) {
    $filterType = 'month';
}

$customFrom = isset($_GET['from']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['from']) : '';
$customTo = isset($_GET['to']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['to']) : '';

try {
    $nowDt = new DateTime('now');
} catch (Throwable $e) {
    $nowDt = new DateTime();
}

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
            if ($rangeStartDt > $rangeEndDt) {
                [$rangeStartDt, $rangeEndDt] = [$rangeEndDt, $rangeStartDt];
            }
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

// Comparison period used to detect whether a podium member climbed, dropped,
// or held their rank. For bounded filters (today/week/month/custom) this is
// simply the equal-length period immediately before the current one. "All
// Time" has no natural predecessor, so we compare against the leaderboard as
// it stood 30 days ago instead, to still show meaningful movement.
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
    $leaderboardCompetition = getLeaderboardStatsByMode($conn, $userId, 'competition', $rangeStartSql, $rangeEndSql);
    $selfModeStats = getSelfModeStats($conn, $userId, $rangeStartSql, $rangeEndSql);
    $monthlyExamStats = getMonthlyExamStats($conn, $userId, $rangeStartSql, $rangeEndSql);

    $reminderRow = getTodayReminder($conn, $userId, (string)($user['role'] ?? 'user'));
    $resumeRow = getResumeState($conn, $userId);
    $streakDays = getUserStreak($conn, $userId);
    $todayProgress = getTodayProgress($conn, $userId);
    $dbSubjects = getSubjects($conn, $userId);
    $recentActivities = getRecentActivities($conn, $userId, $rangeStartSql, $rangeEndSql, 3);
    $upcomingQuizzes = getUpcomingQuizzes($conn, $userId, $rangeEndSql, 2);
    $upcomingExams = getUpcomingExams($conn, $userId, $rangeEndSql, 2);
    $latestMedia = getLatestMedia($conn, 6);
    $latestContents = getLatestContents($conn, 6);
    $mediaViewsCount = getMediaViewsCount($conn, $userId);
    $topRankedParticipants = getTopRankedParticipants($conn, $rangeStartSql, $rangeEndSql, $prevRangeStartSql, $prevRangeEndSql, 3, $userId);
    $progressHistory = getUserProgressHistory($conn, $userId, $rangeStartSql, $rangeEndSql);
} catch (Throwable $e) {
    $mediaViewsCount = 0;
    error_log("Error fetching dashboard data: " . $e->getMessage());
    $progressRow = ['total_items' => 0, 'completed_items' => 0, 'overall_progress' => 0, 'total_time_spent' => 0];
    $leaderboardOverall = $leaderboardLesson = $leaderboardCompetition = ['rank_position' => 0, 'total_participants' => 0, 'total_points' => 0, 'quizzes_completed' => 0, 'average_score' => 0, 'total_time_seconds' => 0];
    $selfModeStats = ['rank_position' => 0, 'total_participants' => 0, 'total_points' => 0, 'quizzes_completed' => 0, 'total_time_seconds' => 0];
    $monthlyExamStats = ['rank_position' => 0, 'total_participants' => 0, 'exams_attempted' => 0, 'total_points' => 0, 'average_score' => 0];
    $reminderRow = ['message' => t('no_reminders')];
    $resumeRow = ['resume_label' => t('start_first_lesson')];
    $streakDays = 0;
    $todayProgress = 0;
    $dbSubjects = [];
    $recentActivities = [];
    $upcomingQuizzes = [];
    $upcomingExams = [];
    $latestMedia = [];
    $latestContents = [];
    $topRankedParticipants = [];
    $progressHistory = [];
        $myCourse = null;
}

// ============================================================================
// PROCESS DATA
// ============================================================================

$overallProgress = isset($progressRow['overall_progress']) ? (int)$progressRow['overall_progress'] : 0;
$completedItems = isset($progressRow['completed_items']) ? (int)$progressRow['completed_items'] : 0;
$totalItems = isset($progressRow['total_items']) ? (int)$progressRow['total_items'] : 0;
$totalTimeSpent = isset($progressRow['total_time_spent']) ? (int)$progressRow['total_time_spent'] : 0;

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

$competitionPoints = (float)($leaderboardCompetition['total_points'] ?? 0);
$competitionRank = (int)($leaderboardCompetition['rank_position'] ?? 0);
$competitionParticipants = (int)($leaderboardCompetition['total_participants'] ?? 0);
$competitionCompleted = (int)($leaderboardCompetition['quizzes_completed'] ?? 0);
$competitionTime = (int)($leaderboardCompetition['total_time_seconds'] ?? 0);

$selfPoints = (float)($selfModeStats['total_points'] ?? 0);
$selfRank = (int)($selfModeStats['rank_position'] ?? 0);
$selfParticipants = (int)($selfModeStats['total_participants'] ?? 0);
$selfCompleted = (int)($selfModeStats['quizzes_completed'] ?? 0);
$selfTime = (int)($selfModeStats['total_time_seconds'] ?? 0);

$monthlyRank = (int)($monthlyExamStats['rank_position'] ?? 0);
$monthlyParticipants = (int)($monthlyExamStats['total_participants'] ?? 0);
$monthlyAttempted = (int)($monthlyExamStats['exams_attempted'] ?? 0);
$monthlyPoints = (float)($monthlyExamStats['total_points'] ?? 0);
$monthlyAvgScore = (float)($monthlyExamStats['average_score'] ?? 0);

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
$currentDateTime = date('Y/m/d H:i');
$region = 'Dar es Salaam Region, Tanzania';

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
        $x = $progressChartPadding + ($progressHistoryCount > 1
            ? ($i / ($progressHistoryCount - 1)) * $plotWidth
            : 0);
        $scoreClamped = max(0, min(100, (float)$point['score']));
        $y = $progressChartPadding + (1 - ($scoreClamped / 100)) * $plotHeight;

        $progressChartPoints[] = [
            'x' => round($x, 2),
            'y' => round($y, 2),
            'score' => (float)$point['score'],
            'label' => $point['label'],
        ];
    }
}

$progressChartLabelStep = count($progressChartPoints) > 7 ? 2 : 1;

// ========== GET UNREAD NOTIFICATIONS ==========
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist yet – ignore
}
// ============================================================================
// BUILD SUBJECT TYPES FROM DATABASE
// ============================================================================





// ============================================================================
// BUILD NAVIGATION
// ============================================================================

$mainNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => true],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false],
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
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
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
$stmt = $conn->prepare("SELECT sheikh_name FROM subjects WHERE sheikh_name IS NOT NULL AND sheikh_name <> ''");
$stmt->execute();
$masheikh = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="<?php echo e($_SESSION['language'] ?? 'sw'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="<?php echo e(t('dashboard')); ?> - MyIslam">
    <title><?php echo e(t('dashboard')); ?> - MyIslam</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg: #f5f7f5;
    --surface: #ffffff;
    --surface-2: #f9fafb;
    --surface-3: #eef2f7;
    --line: #e2e5e2;
    --text: #1f2937;
    --text-secondary: #4b5563;
    --muted: #6b7280;

    --brand: #0E4A34;
    --brand-dark: #0A3626;
    --brand-light: #3E7A5E;
    --brand-soft: rgba(14, 74, 52, 0.08);

    --gold: #9C7A2E;
    --gold-soft: rgba(156, 122, 46, 0.10);

    --accent: #3E6E91;
    --accent-soft: rgba(62, 110, 145, 0.10);

    --primary: var(--accent);
    --primary-soft: var(--accent-soft);

    --rank-gold: #C6A64B;
    --rank-gold-soft: rgba(198, 166, 75, 0.14);
    --rank-diamond: #8FD3E8;
    --rank-diamond-soft: rgba(143, 211, 232, 0.16);
    --rank-tanzanite: #6C5CC0;
    --rank-tanzanite-soft: rgba(108, 92, 192, 0.14);

    --white: #ffffff;
    --warning: #b45309;
    --danger: #b91c1c;
    --success: #0E4A34;

    --header-bg: #ffffff;
    --announcement-bg: linear-gradient(135deg, #eef2f7 0%, #f9fafb 100%);
    --header-height: 72px;
    --overlay-dim: rgba(15, 23, 32, 0.45);
    --glass-bg-strong: rgba(255, 255, 255, 0.92);
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
    --radius-sm: 12px;
    --radius-md: 16px;
    --radius-lg: 20px;
}

body {
    font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.5;
    padding-bottom: 24px;
    position: relative;
}

body.dark-mode {
    --bg: #0f1720;
    --surface: #18212b;
    --surface-2: #1d2833;
    --surface-3: #22303c;
    --line: #304150;
    --text: #edf2f7;
    --text-secondary: #cbd5e1;
    --muted: #94a3b8;

    --brand: #4C9172;
    --brand-dark: #6bb590;
    --brand-light: #7fcaa4;
    --brand-soft: rgba(76, 145, 114, 0.16);

    --gold: #C9A85E;
    --gold-soft: rgba(201, 168, 94, 0.16);

    --accent: #6FA0C4;
    --accent-soft: rgba(111, 160, 196, 0.16);

    --primary: var(--accent);
    --primary-soft: var(--accent-soft);

    --rank-gold: #D9BC72;
    --rank-gold-soft: rgba(217, 188, 114, 0.18);
    --rank-diamond: #A9E4F2;
    --rank-diamond-soft: rgba(169, 228, 242, 0.18);
    --rank-tanzanite: #9285DB;
    --rank-tanzanite-soft: rgba(146, 133, 219, 0.18);

    --header-bg: #13202a;
    --danger: #ef4444;
    --overlay-dim: rgba(0, 0, 0, 0.6);
    --glass-bg-strong: rgba(24, 33, 43, 0.92);

    background: var(--bg);
}

body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: -1;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.025' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
    background-repeat: repeat;
}

body.dark-mode::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
}

a { text-decoration: none; color: inherit; }
button { font: inherit; border: none; background: none; cursor: pointer; }

a:focus-visible,
button:focus-visible,
.subject-link:focus-visible {
    outline: 2px solid var(--gold);
    outline-offset: 2px;
    border-radius: 6px;
}

.skip-link {
    position: absolute;
    left: -9999px;
    top: 0;
    background: var(--brand);
    color: var(--white);
    padding: 10px 16px;
    border-radius: 0 0 8px 0;
    z-index: 2000;
    font-weight: 700;
}

.skip-link:focus { left: 0; }

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
.header-left { flex: 1; min-width: 0; }

.header-top-nav {
    display: flex; align-items: center; gap: 8px; flex-wrap: nowrap;
    overflow-x: auto; scrollbar-width: thin; padding-bottom: 2px;
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
.menu-toggle{width: 50px;}

.menu-toggle:hover, .icon-btn:hover, .lang-btn:hover {
    transform: translateY(-2px); box-shadow: var(--shadow-sm);
    border-color: var(--gold); color: var(--gold);
}
.profile-btn:hover {
    transform: translateY(-2px); box-shadow: var(--shadow-sm);
}

.menu-toggle { font-size: 14px; }
.lang-btn { min-width: 30px; padding: 0 8px; font-weight: 600; color: var(--brand); }
.brand-box { font-size: 22px; font-weight: 700; color: var(--brand); background: transparent; border: none; white-space: nowrap; }
.icon-btn{font-size: 18px; color: var(--text); position: relative; }
.profile-area { position: relative; }
.profile-btn {
    width: 44px; height: 44px; border-radius: 50%; overflow: hidden;
    background: var(--surface); border: 2px solid var(--brand);
    cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center;
    color: var(--brand); font-weight: 700;
}

.profile-btn img { width: 100%; height: 100%; object-fit: cover; }

.profile-menu {
    position: absolute; top: 56px; right: 0; width: 280px;
    background: var(--glass-bg-strong);
    backdrop-filter: blur(12px);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg); padding: 16px; display: none;
    box-shadow: var(--shadow-lg); z-index: 1001;
}

.profile-menu.show { display: block; }

.profile-menu-top {
    display: flex; gap: 12px; padding-bottom: 10px; margin-bottom: 10px;
    border-bottom: 1px solid var(--line);
}

.profile-menu-top img, .profile-avatar-fallback {
    width: 56px; height: 56px; border-radius: 50%;
}

.profile-avatar-fallback {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--brand-soft); color: var(--brand); font-weight: 700; font-size: 16px;
}

.profile-menu-top h4 { font-size: 16px; margin-bottom: 4px; font-weight: bold; color: var(--text); }
.profile-menu-top p { font-size: 12px; color: var(--text-secondary); font-weight: 600; }

.profile-menu a {
    display: block; padding: 10px 12px; border-radius: 10px; font-weight: 700;
    font-size: 14px; transition: background 0.2s; margin: 4px 0; color: var(--text);
}

.profile-menu a:hover { background: var(--brand-soft); color: var(--brand); }

.sidebar-overlay {
    display: none; position: fixed; top: var(--header-height); left: 0;
    width: 100%; height: calc(100vh - var(--header-height));
    background: var(--overlay-dim); z-index: 1100; backdrop-filter: blur(2px);
}

.sidebar-overlay.show { display: block; }

.sidebar {
    position: fixed; top: var(--header-height); left: -100%;
    width: min(320px, 85vw); height: calc(100vh - var(--header-height));
    background: var(--glass-bg-strong);
    backdrop-filter: blur(12px);
    border-right: 1px solid var(--line);
    padding: 24px 20px; z-index: 1200; transition: left 0.3s ease; overflow-y: auto;
}

.sidebar.open { left: 0; }

.sidebar-title {
    font-size: 12px; color: var(--muted); text-transform: uppercase;
    margin-bottom: 20px; font-weight: 700; letter-spacing: 1px; padding: 0 8px;
}

.subject-list { display: grid; gap: 8px; }

.subject-link {
    display: flex; align-items: center; gap: 12px; padding: 12px 16px;
    border-radius: var(--radius-md); background: var(--surface-2);
    border: 1px solid var(--line); font-size: 14px; font-weight: 500; transition: all 0.2s;
    color: var(--text);
}

.subject-link:hover { background: var(--brand-soft); border-color: var(--brand); transform: translateX(4px); }
.subject-link.active { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }

.main-content {
    padding: 10px;
    max-width: 1400px; margin: 0 auto;
    width: 100%;
    overflow-x: hidden;
}

.dashboard-stack { display: grid; gap: 10px; }

.section-card {
    background: var(--surface); border: 1px solid var(--line);
    border-radius: var(--radius-lg); padding: clamp(16px, 5vw, 24px);
    transition: all 0.2s;
    width: 100%;
    overflow: hidden;
}

.section-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

.hijri-card {
    background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%);
    border: 1px solid var(--line);
    position: relative;
    overflow: hidden;
    color: var(--white);
    text-align: left;
    padding: 14px 16px;
}
.hijri-card::before {
    content: "";
    position: absolute;
    inset: 0;
    background-image: url('/uploads/subjects-page-hero.png');
    background-size: cover;
    background-position: center;
    z-index: 0;
}
.hijri-card::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(14,74,52,0.55) 0%, rgba(10,54,38,0.45) 100%);
    z-index: 0;
}
.hijri-card .hijri-date-badge,
.hijri-card .filter-select-wrap,
.hijri-card .custom-filter-form {
    position: relative;
    z-index: 1;
}

.hero-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}

.hijri-card .filter-select-label {
    color: var(--white);
    text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
}

.hijri-card .custom-filter-form {
    border-top: 1px solid rgba(255,255,255,0.3);
}

.hijri-card .custom-filter-form label {
    color: var(--white);
    text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
}

@media (max-width: 560px) {
    .hero-row { justify-content: center; text-align: center; }
}

.hijri-date-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.16);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: var(--white);
    font-size: 13px;
    font-weight: 600;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
}

.hijri-date-gregorian {
    font-weight: 500;
    opacity: 0.85;
}

.top-row { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; }
@media (max-width: 768px) { .top-row { grid-template-columns: 1fr; } }

.filter-card h3, .stats-card h3, .reminder-card h3, .resume-card h3, .activities-card h3, .leaderboard-modes-card h3 {
    font-size: 12px; margin-bottom: 10px; color: var(--muted);
    text-transform: uppercase; letter-spacing: 1px; font-weight: 700;
}

.latest-card h3 {
    font-size: 12px; margin-bottom: 10px; color: var(--gold);
    text-transform: uppercase; letter-spacing: 1px; font-weight: 700;
}

.filter-select-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.filter-select-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-secondary);
    white-space: nowrap;
}
.filter-select {
    padding: 9px 12px;
    border-radius: 9px;
    border: 1px solid var(--line);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    min-width: 160px;
}
.filter-select:hover { border-color: var(--brand); }
.filter-select:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

.custom-filter-form {
    display: none;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--line);
}
.custom-filter-form.show { display: flex; }
.custom-filter-form label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
}
.custom-filter-form input[type="date"] {
    padding: 6px 8px;
    border-radius: 8px;
    border: 1px solid var(--line);
    background: var(--surface);
    color: var(--text);
    font-size: 12px;
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

.podium-you {
    box-shadow: 0 0 0 2px var(--brand), var(--shadow-lg);
}

.rank-progress-card { margin-top: 12px; }

.rank-progress-row {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    justify-content: center;
    gap: 16px;
}

.rank-progress-item {
    flex: 1;
    min-width: 0;
    text-align: center;
}


.rank-progress-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--muted);
    margin-bottom: 8px;
}

.rank-progress-value {
    font-size: clamp(26px, 6vw, 40px);
    font-weight: 800;
    color: var(--gold);
    line-height: 1.1;
}

.rank-progress-sub {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;
}

.rank-progress-divider {
    width: 1px;
    align-self: stretch;
    background: var(--line);
    flex-shrink: 0;
}

@media (max-width: 480px) {
    .rank-progress-row { gap: 8px; }
    .rank-progress-label { font-size: 10px; }
}

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

.podium-trend {
    display: flex;
    justify-content: center;
    margin-bottom: 8px;
}
.podium-trend-svg {
    width: 46px;
    height: 20px;
    display: block;
    overflow: visible;
}

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

@media (max-width: 560px) {
    .podium-grid { gap: 6px; }
    .podium-card { padding: 12px 6px 10px; margin-top: 26px; }
    .podium-first { padding-top: 16px; padding-bottom: 14px; margin-top: 0; }
    .podium-second, .podium-third { margin-top: 26px; }
    .podium-avatar { width: 44px; height: 44px; font-size: 13px; margin-bottom: 6px; }
    .podium-first .podium-avatar { width: 54px; height: 54px; }
    .podium-trend { margin-bottom: 4px; }
    .podium-trend-svg { width: 32px; height: 15px; }
    .podium-badge { font-size: 8px; padding: 3px 6px; margin-bottom: 6px; letter-spacing: 0.2px; }
    .podium-name { font-size: 11px; }
    .podium-points { font-size: 10px; }
}

@media (max-width: 380px) {
    .podium-badge span.badge-label { display: none; }
}

.leaderboard-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    margin-bottom: 10px;
    -webkit-overflow-scrolling: touch;
}

.leaderboard-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface);
    min-width: 620px;
}

.leaderboard-table thead th {
    background: var(--gold-soft);
    color: var(--gold);
    font-size: 13px;
    font-weight: 800;
    text-align: center;
    padding: 12px 14px;
    white-space: nowrap;
    border-bottom: 2px solid var(--gold);
    position: sticky;
    top: 0;
}

.leaderboard-table thead th:first-child {
    text-align: left;
    background: var(--surface);
    color: var(--text-secondary);
    border-bottom: 2px solid var(--line);
}

.leaderboard-table tbody th {
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    padding: 10px 14px;
    white-space: nowrap;
    position: sticky;
    left: 0;
    background: inherit;
}

.leaderboard-table tbody td {
    text-align: center;
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    padding: 10px 14px;
    white-space: nowrap;
}

.leaderboard-table tbody tr {
    border-bottom: 1px dashed var(--line);
    background: var(--surface);
}

.leaderboard-table tbody tr:last-child {
    border-bottom: 0;
}

.leaderboard-table tbody tr:nth-child(even) {
    background: var(--bg-alt, rgba(128,128,128,0.06));
}

@media (max-width: 768px) {
    .leaderboard-table { min-width: 560px; }
    .leaderboard-table thead th,
    .leaderboard-table tbody th,
    .leaderboard-table tbody td {
        padding: 8px 10px;
        font-size: 12px;
    }
}

.mode-link-row {
    margin-top: 24px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.mode-link-btn {
    padding: 10px 14px;
    border-radius: 999px;
    background: var(--gold-soft);
    color: var(--gold);
    font-size: 12px;
    font-weight: 700;
    border: 1px solid transparent;
    transition: all 0.2s;
}

.mode-link-btn:hover {
    border-color: var(--gold);
    transform: translateY(-1px);
}

.progress-chart-wrap {
    width: 100%;
    margin-top: 6px;
}

.progress-chart-svg {
    width: 100%;
    height: auto;
    display: block;
    overflow: visible;
}

.progress-chart-labels {
    display: flex;
    justify-content: space-between;
    margin-top: 4px;
    padding: 0 2px;
}

.progress-chart-labels span {
    font-size: 11px;
    color: var(--text-secondary);
    font-weight: 600;
    white-space: nowrap;
}

.progress-chart-axis-label {
    font-size: 10px;
    font-weight: 600;
    fill: var(--muted);
}

.progress-chart-point-label {
    font-size: 10px;
    font-weight: 700;
    fill: var(--text);
}

.progress-chart-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--line);
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
}

.legend-item i {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}

.reminder-card { background: var(--gold-soft); margin-top: 10px; border-color: var(--gold); }
.reminder-card p { font-size: 16px; line-height: 1.6; font-style: italic; color: var(--text); }

.resume-card {
    background: var(--brand-soft);
    border-color: var(--brand); margin-top: 10px;
}

.resume-btn {
    display: inline-block; width: 100%; text-align: center; padding: 14px;
    border-radius: var(--radius-md); background: var(--brand); color: var(--white);
    font-weight: 700; margin-top: 16px; transition: all 0.2s;
}

.resume-btn:hover {
    transform: translateY(-2px); filter: brightness(1.1); box-shadow: var(--shadow-md);
}
#quizzes{margin-bottom: 10px ; }
.quiz-item, .exam-item {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 10px; background: var(--surface-2); border-radius: 10px; border: 1px solid var(--line); margin: 6px;
}

.quiz-info h4, .exam-info h4 { font-size: 14px; margin-bottom: 4px; color: var(--text); }
.quiz-difficulty, .exam-meta { font-size: 11px; color: var(--muted); }

.quiz-badge {
    padding: 4px 12px; border-radius: 10px; font-size: 11px; font-weight: 600;
    background: var(--gold-soft); color: var(--gold); white-space: nowrap;
}

.exam-badge {
    padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 600;
    background: var(--gold-soft); color: var(--gold); white-space: nowrap;
}



.exam-quiz-row{
    display:grid;
    grid-template-columns:repeat(2, 1fr);
    gap:16px;
    width:100%;
}

@media (max-width: 768px) {
    .exam-quiz-row {
        grid-template-columns: 1fr;
    }
}

.latest-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
    margin-top: 8px; margin-bottom: 8px
}

.latest-media-card {
    background: var(--surface-2);
    border-radius: var(--radius-md);
    overflow: hidden;
    border: 1px solid var(--line);
    width: 100%;
}

.latest-media-thumb {
    aspect-ratio: 16/9;
    background: var(--surface-3);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
}
.latest-card{margin-bottom: 10px; margin-top: 10px; width:100%}

.latest-media-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.latest-media-info {
    padding: 10px;
}

.latest-media-title {
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text);
}

.latest-media-type {
    font-size: 11px;
    color: var(--muted);
    text-transform: capitalize;
}

.footer-meta {
    text-align: center; font-size: 12px; padding: 20px; margin-top: 20px;
    border-top: 1px solid var(--line); font-weight: 700;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
}
.footer-meta .brand-text {
    color: var(--brand);
    font-weight: 700;
}
.footer-meta .gold-text {
    color: var(--gold);
    font-weight: 700;
}

@media (max-width: 1200px) {
    .header-top-nav { max-width: 48vw; }
}

@media (max-width: 900px) {
    .header-top-nav { display: none; }
}

@media (max-width: 768px) {
    :root { --header-height: 64px; }
    .main-content { padding: 10px}
    .section-card { padding: 10px; }

    .dashboard-stack { gap: 10px; }

}

@media (max-width: 480px) {
    .latest-grid { grid-template-columns: 1fr; }
}
.notification-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: var(--danger);
    color: var(--white);
    font-size: 10px;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 2px solid var(--surface);
}
.sidebar-toggle-btn {
    position: fixed;
    top: var(--header-height);
    left: 0;
    z-index: 1300;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-left: none;
    border-top: none;
    border-radius: 0 0 8px 0;
    font-size: 16px;
    cursor: pointer;
    color: var(--text);
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
    padding: 0;
    margin: 0;
}

.sidebar-toggle-btn:hover {
    background: var(--brand-soft);
    color: var(--brand);
}
/* ============================================================================
   MASHEIKH SECTION
   Matches the MyIslam dashboard theme (light + dark mode aware)
   ============================================================================ */

.masheikh-section {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: clamp(16px, 5vw, 24px);
    transition: all 0.2s;
    width: 100%;
    overflow: hidden;
    margin-top: 20px;
}

.masheikh-section:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.masheikh-section h2 {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--gold);
    margin: 0 0 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Decorative icon before the heading (emoji-friendly) */
.masheikh-section h2::before {
    content: "🕌";
    font-size: 16px;
    line-height: 1;
}

/* Grid of sheikh cards */
.masheikh-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 14px;
}

/* Individual sheikh card */
.masheikh-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    transition: all 0.2s;
}

.masheikh-card:hover {
    transform: translateY(-2px);
    border-color: var(--brand);
    background: var(--brand-soft);
    box-shadow: var(--shadow-sm);
}

/* Circular avatar with initials or image */
.masheikh-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 15px;
    color: var(--white);
    background: var(--gold);
    border: 2px solid var(--surface);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.masheikh-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Name + optional subtitle */
.masheikh-info {
    min-width: 0;
    flex: 1;
}

.masheikh-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    line-height: 1.3;

    /* Was: white-space: nowrap; overflow: hidden; text-overflow: ellipsis; */
    /* Now: allow wrapping, but break long unbroken words too */
    white-space: normal;
    overflow-wrap: anywhere;   /* modern, reliable */
    word-break: break-word;    /* fallback for older browsers */
    min-width: 0;              /* lets flex child shrink below content size */
}



/* Empty state */
.masheikh-empty {
    text-align: center;
    color: var(--muted);
    font-size: 13px;
    padding: 20px 10px;
    background: var(--surface-2);
    border: 1px dashed var(--line);
    border-radius: var(--radius-md);
}

/* ============================================================================
   RESPONSIVE
   ============================================================================ */

@media (max-width: 560px) {
    .masheikh-section {
        padding: 14px;
    }

    .masheikh-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .masheikh-card {
        padding: 10px;
        gap: 10px;
    }

    .masheikh-avatar {
        width: 38px;
        height: 38px;
        font-size: 13px;
    }

    .masheikh-name {
        font-size: 13px;
    }
}

/* ============================================================================
   RADIO PLAYER WIDGET
   Matches the MyIslam dashboard theme (light + dark mode aware)
   ============================================================================ */

.widget_custom_html {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: clamp(16px, 5vw, 24px);
    transition: all 0.2s;
    width: 100%;
    overflow: hidden;
}

.widget_custom_html:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* Make the whole title block clickable but keep it visually clean */
.widget_custom_html > a {
    display: block;
    color: inherit;
    text-decoration: none;
    margin-bottom: 12px;
}

.widget_custom_html > a:focus-visible {
    outline: 2px solid var(--gold);
    outline-offset: 2px;
    border-radius: 6px;
}

/* Header / title */
.widget_custom_html .box-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.widget_custom_html .widget-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--gold);
    margin: 0;
    line-height: 1.3;
}

/* Inner wrapper */
.widget_custom_html .textwidget {
    width: 100%;
}

.wrap-radio-player {
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    padding: 14px;
}

.wrap-radio-player p {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Small "live" dot before the label */
.wrap-radio-player p::before {
    content: "";
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--danger);
    box-shadow: 0 0 0 3px rgba(185, 28, 28, 0.15);
    animation: radio-pulse 1.6s ease-in-out infinite;
    flex-shrink: 0;
}

@keyframes radio-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%      { opacity: 0.5; transform: scale(0.85); }
}

/* The actual audio element */
.wrap-radio-player audio {
    width: 100%;
    height: 40px;
    border-radius: 999px;
    outline: none;
    background: var(--surface);
    border: 1px solid var(--line);
    accent-color: var(--brand);
}

.wrap-radio-player audio:focus-visible {
    outline: 2px solid var(--gold);
    outline-offset: 2px;
}

/* Native audio controls don't theme perfectly, so tint the surface behind them */
.wrap-radio-player audio::-webkit-media-controls-panel {
    background: var(--surface);
}

.wrap-radio-player audio::-webkit-media-controls-play-button,
.wrap-radio-player audio::-webkit-media-controls-mute-button {
    filter: hue-rotate(120deg) saturate(0.6);
}

/* ============================================================================
   RESPONSIVE
   ============================================================================ */

@media (max-width: 560px) {
    .widget_custom_html {
        padding: 14px;
    }

    .widget_custom_html .widget-title {
        font-size: 11px;
        letter-spacing: 0.6px;
    }

    .wrap-radio-player {
        padding: 10px;
        gap: 8px;
    }

    .wrap-radio-player p {
        font-size: 12px;
    }

    .wrap-radio-player audio {
        height: 36px;
    }
}


.type{
    padding:0px;
}
.type-menu {
    width: 100%;
}

.type-toggle {
    width: 100%;
    height: 50px;

    display: flex;
    justify-content: space-between;
    align-items: center;

    padding: 0 16px;

    background: var(--surface);
    color: var(--brand);

    border: 1px solid var(--line);
    border-radius: 8px;

    cursor: pointer;
    font-size: 22px;
}

.type-grid {
    display:grid ;

    grid-template-columns: 1fr 1fr;
    gap: 0 18px;

    padding-top: 0px;
}


.type-item {
    display: flex;
    align-items: center;
    gap: 10px;

    min-height: 70px;

    padding:10px;

    text-decoration: none;
    color: var(--brand);

    border-bottom: 1px solid var(--line);
}

.type-box {
    width: 48px;
    height: 48px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: var(--surface);

    border: 1px solid var(--line);
    border-radius: 5px;

    font-size: 22px;
}

.type-item span {
    font-size: 18px;
    font-weight: 600;
}

.type {
    display: grid;
    grid-template-columns: 1fr;
    gap: 6px;
}


@media (min-width:600px){
.type{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;

}}

 22px;
}

.type-item span {
    font-size: 18px;
    font-weight: 600;
}

.type {
    display: grid;
    grid-template-columns: 1fr;
    gap: 6px;
}


@media (min-width:600px){
.type{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;

}}

/* ─── Card wrapper ─── */
.course-card-dash {
    border: 1px solid var(--gold);
    border-radius: var(--radius-lg, 20px);
    overflow: hidden;
    padding: 0;
    display: flex;
    flex-direction: column;
    min-height: 200px;
}

/* ─── Top: title bar ─── */
.course-dash-topbar {
    background: var(--surface-2);       /* solid, separate from image */
    border-bottom: 1px solid var(--line);
    padding: 10px 16px;
    text-align: center;
}

.course-dash-heading {
    margin: 0;
    font-size: 13px;
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 800;
}

/* ─── Bottom: image + content + button ─── */
/* ─── Bottom: image + content + button ─── */
.course-dash-main {
    position: relative;
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 20px;                  /* ← gives the image proper height */
    overflow: hidden;
}

/* Image — full view below title */
.course-dash-main::after {
    content: "";
    position: absolute;
    inset: 0;
        background: url('uploads/myislam-academyc.png') center/100% 100% no-repeat;

    z-index: 0;
    pointer-events: none;
}

.course-dash-main {
    position: relative;
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100px;   /* ← add this — gives the image room */
}
/* Content sits above the image */
.course-dash-content {
    z-index: 1;
}

/* Content sits above the image */
.course-dash-main > * {
    position: relative;
    z-index: 1;
}

/* Dark overlay so white text stays readable */
.course-dash-main::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, rgba(0,0,0,0.35), rgba(0,0,0,0.55));
    pointer-events: none;
}

/* Content above the overlay */
.course-dash-content {
    position: relative;
    z-index: 1;
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 14px 20px 16px;
}

/* ─── Text ─── */
.course-card-dash .course-dash-title { font-size: clamp(17px, 3.5vw, 20px); font-weight: 800; color: #fff; text-shadow: 0 1px 4px rgba(0,0,0,0.6); }
.course-card-dash .course-dash-sub { color: rgba(255,255,255,0.9); font-size: 13px; margin-top: 2px; text-shadow: 0 1px 3px rgba(0,0,0,0.5); }
.course-card-dash .course-dash-bar { height: 8px; border-radius: 999px; background: rgba(255,255,255,0.25); overflow: hidden; margin-top: 12px; }
.course-card-dash .course-dash-bar > div { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--brand-light), var(--brand)); }
.course-card-dash .course-dash-meta { display: flex; justify-content: space-between; font-size: 12px; color: rgba(255,255,255,0.9); margin-top: 5px; font-weight: 600; text-shadow: 0 1px 3px rgba(0,0,0,0.5); }
.course-card-dash p { color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.6); }

/* ─── Actions ─── */
.course-card-dash .course-dash-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: auto;
    padding-top: 14px;
}
.course-card-dash .course-dash-actions.center {
    justify-content: center;
}

/* ─── Buttons ─── */
.course-dash-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 9px 18px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    background: var(--brand);
    color: var(--white);
    border: 1px solid var(--brand);
    text-decoration: none;
    transition: all 0.2s ease;
}
.course-dash-btn:hover { filter: brightness(1.1); }

.course-dash-btn.secondary {
    background: rgba(255,255,255,0.9);
    color: var(--text-secondary);
    border-color: transparent;
}

/* ─── Transparent GREEN background button ─── */
.course-dash-btn.ghost {
    background: rgba(20, 53, 32, 0.15);   /* ← transparent green */
    color: var(--white);
    border: 2px solid var(--brand);
    backdrop-filter: blur(6px);
    font-weight: 800;
}
.course-dash-btn.ghost:hover {
    background:var(--brand);                   /* solid green on hover */
    color: #fff;
    border-color: var(--brand);
}

    </style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<a href="#mainContent" class="skip-link"><?php echo e(t('skip_to_main')); ?></a>

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
        <button class="icon-btn" id="themeToggle" aria-label="<?php echo e(t('dark_mode')); ?>">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="<?php echo e(t('notifications_label')); ?>">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="<?php echo e(t('open_profile')); ?>" aria-expanded="false">
                <?php if (!empty($user['picture'])): ?>
                    <img src="<?php echo e($user['picture']); ?>" alt="<?php echo e(t('profile_image')); ?>">
                <?php else: ?>
                    <?php echo e(initials((string)$user['name'])); ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?php echo e($user['picture']); ?>" alt="<?php echo e(t('profile_image')); ?>">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)$user['name'])); ?></div>
                    <?php endif; ?>

                    <div>
                        <h4><?php echo e((string)$user['name']); ?></h4>
                        <p><?php echo e((string)$user['email']); ?></p>
                        <p style="font-size: 10px;"><?php echo t('member_since'); ?> <?php echo e((string)$user['member_since']); ?></p>
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

    <main class="main-content" id="mainContent">
        <div class="dashboard-stack">
            <!-- 1. Hijri Date + Filter (merged into one hero card) -->
             <div class= "type">
<section class="hijri-card section-card">
    <div class="hero-row">
        <div class="hijri-date-badge">
            🕌 <?php echo e($hijriToday['formatted']); ?>
            <span class="hijri-date-gregorian">· <?php echo e($hijriToday['gregorian']); ?></span>
        </div>
        <div class="filter-select-wrap">
            <label for="filterSelect" class="filter-select-label">📅 <?php echo t('dashboard_period'); ?></label>
            <select id="filterSelect" class="filter-select">
                <?php foreach ($filters as $label => $value): ?>
                    <option value="<?php echo e($value); ?>" <?php echo $filterType === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
                <option value="custom" <?php echo $filterType === 'custom' ? 'selected' : ''; ?>> <?php echo t('custom')?></option>
            </select>
        </div>
    </div>
    <form method="get" action="user_dashboard.php" class="custom-filter-form<?php echo $filterType === 'custom' ? ' show' : ''; ?>" id="customFilterForm">
        <input type="hidden" name="filter" value="custom">
        <label><?php echo t('from') ?> <input type="date" name="from" value="<?php echo e($customFrom !== '' ? $customFrom : $rangeStartDt->format('Y-m-d')); ?>"></label>
        <label><?php echo t('to') ?><input type="date" name="to" value="<?php echo e($customTo !== '' ? $customTo : $rangeEndDt->format('Y-m-d')); ?>"></label>
    </form>
</section>


<section class="section-card course-card-dash">

    <!-- Top: title bar -->
    <div class="course-dash-topbar">
        <h3 class="course-dash-heading">MyIslam Academy</h3>
    </div>

    <!-- Bottom: image + content + button -->
    <div class="course-dash-main">
        <div class="course-dash-content">
            <?php if (!empty($myCourse)): ?>
                <div class="course-dash-title"><?php echo e($myCourse['title']); ?></div>
                <div class="course-dash-sub">
                    <?php echo e($myCourse['cohort_name']); ?> ·
                    <?php if ($myCourse['running']): ?>
                        Day <?php echo (int)$myCourse['day_number']; ?> of <?php echo (int)$myCourse['duration_days']; ?>
                    <?php else: ?>
                        Starts in <?php echo (int)$myCourse['starts_in_days']; ?> day<?php echo $myCourse['starts_in_days'] === 1 ? '' : 's'; ?>
                        (<?php echo e(formatDateValue($myCourse['start_date'], 'd M Y')); ?>)
                    <?php endif; ?>
                </div>
                <div class="course-dash-bar"><div style="width: <?php echo (int)$myCourse['progress']['pct']; ?>%"></div></div>
                <div class="course-dash-meta">
                    <span><?php echo (int)$myCourse['progress']['done']; ?> of <?php echo (int)$myCourse['progress']['total']; ?> completed</span>
                    <span><?php echo (int)$myCourse['progress']['pct']; ?>%</span>
                </div>
                <?php if (!empty($myCourse['next_title'])): ?>
                    <p style="margin-top:10px;font-size:14px;">▶ Up next: <strong><?php echo e($myCourse['next_title']); ?></strong></p>
                <?php elseif ($myCourse['running']): ?>
                    <p style="margin-top:10px;font-size:14px;">You're all caught up for today. 🌙</p>
                <?php endif; ?>
                <div class="course-dash-actions">
                    <?php if (!empty($myCourse['next_link'])): ?>
                        <a class="course-dash-btn" href="<?php echo e($myCourse['next_link']); ?>">Continue →</a>
                    <?php endif; ?>
                    <a class="course-dash-btn secondary" href="course.php?cohort_id=<?php echo (int)$myCourse['cohort_id']; ?>">View schedule</a>
                </div>
            <?php else: ?>
                <div class="course-dash-actions center">
                    <a class="course-dash-btn ghost" href="courses.php">ENROLL →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

</section>


<section class="section-card rank-progress-card">

  <div class="type-grid" id="typeGrid">

    <a href="subjects.php" class="type-item">
        <div class="type-box">📚</div>
        <span>Masomo</span>
    </a>

    <a href="timetable.php" class="type-item">
        <div class="type-box">🗓️</div>
        <span>Ratiba</span>
    </a>

    <a href="quizzes.php" class="type-item">
        <div class="type-box">📝</div>
        <span>Mitihani</span>
    </a>

    <a href="leaderboard.php" class="type-item">
        <div class="type-box">🏆</div>
        <span>Matokeo</span>
    </a>



</div>

</section>

         


<!-- 2. Rank & General Progress, aligned horizontally in one row -->
<section class="section-card rank-progress-card">
    <div class="rank-progress-row">
        <div class="rank-progress-item">
            <div class="rank-progress-label">🏅 <?php echo t('dashboard_current_rank'); ?></div>
            <div class="rank-progress-value"><?php echo $overallRank ? '#' . $overallRank : '--'; ?></div>
            <div class="rank-progress-sub"><?php echo t('dashboard_out_of'); ?> <?php echo $overallParticipants ?: '--'; ?></div>
        </div>
        <div class="rank-progress-divider"></div>
        <div class="rank-progress-item">
            <div class="rank-progress-label">📈 <?php echo t('dashboard_overall_progress'); ?></div>
            <div class="rank-progress-value"><?php echo $overallProgress; ?>%</div>
            <div class="rank-progress-sub"><?php echo $completedItems; ?>/<?php echo max(1, $totalItems); ?> <?php echo t('dashboard_completed_text'); ?></div>
        </div>
    </div>
</section>


<!-- 3. Top Ranked Participants Podium -->
<?php if (!empty($topRankedParticipants)): ?>
<section class="section-card">
    <h3><?php echo t('top_ranked') ?> <span class="period-label"><?php echo e($filterLabel); ?></span></h3>
    <div class="podium-grid">
        <?php foreach ($topRankedParticipants as $participant):
            $rank = (int)($participant['rank'] ?? 0);
            $tierClass = $rank === 1 ? 'podium-first' : ($rank === 2 ? 'podium-second' : 'podium-third');
            $tierLabel = $rank === 1 ? '🥇 ': ($rank === 2 ? '💎 ' : '🔷 ');
            $isCurrentUser = (int)($participant['user_id'] ?? 0) === $userId;
            $pName = $isCurrentUser ? t('you') : ($participant['display_name'] ?? t('user'));
            $pPicture = $participant['profile_image'] ?? '';
            $pPoints = (float)($participant['total_points'] ?? 0);

            // Rank-trend sparkline: flat green (held rank), rising gold (climbed
            // or newly entered the podium), or falling red (dropped)
            $trend = $participant['trend'] ?? 'same';
            $trendPresets = [
                'up'   => ['points' => '2,15 17,9 32,3',  'dot_y' => 3,  'color' => 'var(--rank-gold)', 'title' => t('climbed_rankings')],
                'down' => ['points' => '2,3 17,9 32,15',  'dot_y' => 15, 'color' => 'var(--danger)',    'title' => t('dropped_rankings')],
                'same' => ['points' => '2,9 17,9 32,9',   'dot_y' => 9,  'color' => 'var(--success)',   'title' => t('holding_steady')],
                'new'  => ['points' => '2,15 17,9 32,3',  'dot_y' => 3,  'color' => 'var(--rank-gold)', 'title' => t('new_podium')],
            ];
            $tp = $trendPresets[$trend] ?? $trendPresets['same'];
        ?>
            <div class="podium-card <?php echo $tierClass; ?><?php echo $isCurrentUser ? ' podium-you' : ''; ?>">
                <div class="podium-trend" title="<?php echo e($tp['title']); ?>">
                    <svg viewBox="0 0 34 18" class="podium-trend-svg" preserveAspectRatio="none" aria-label="<?php echo e($tp['title']); ?>">
                        <polyline points="<?php echo e($tp['points']); ?>" fill="none" style="stroke: <?php echo $tp['color']; ?>;" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="32" cy="<?php echo (int)$tp['dot_y']; ?>" r="2.5" style="fill: <?php echo $tp['color']; ?>;" />
                    </svg>
                </div>
                <div class="podium-badge">#<?php echo $rank; ?> <span class="badge-label">· <?php echo e($tierLabel); ?></span></div>
                <div class="podium-avatar">
                    <?php if (!empty($pPicture)): ?>
                        <img src="<?php echo e($pPicture); ?>" alt="<?php echo e($pName); ?>">
                    <?php else: ?>
                        <?php echo e(initials($pName)); ?>
                    <?php endif; ?>
                </div>
                <div class="podium-name"><?php echo e($pName); ?></div>
                <div class="podium-points"><?php echo number_format($pPoints, 0); ?> <?php echo e(t('points')); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
                   

<!-- Leaderboard Overview -->
<section class="section-card leaderboard-modes-card">
    <h3>🏆 <?php echo t('dashboard_leaderboard_overview'); ?> <span class="period-label"><?php echo e($filterLabel); ?></span></h3>
    <div class="leaderboard-table-wrap">
        <table class="leaderboard-table">
            <thead>
                <tr>
                    <th scope="col"></th>
                    <th scope="col"><?php echo t('dashboard_overall_mode'); ?></th>
                    <th scope="col"><?php echo t('dashboard_lesson_mode'); ?></th>
                    <th scope="col"><?php echo t('dashboard_competition_mode'); ?></th>
                    <th scope="col"><?php echo t('dashboard_self_mode'); ?></th>
                    <th scope="col"><?php echo t('dashboard_monthly_exam'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row"><?php echo t('dashboard_rank'); ?></th>
                    <td>#<?php echo $overallRank ?: '--'; ?></td>
                    <td>#<?php echo $lessonRank ?: '--'; ?></td>
                    <td>#<?php echo $competitionRank ?: '--'; ?></td>
                    <td>#<?php echo $selfRank ?: '--'; ?></td>
                    <td>#<?php echo $monthlyRank ?: '--'; ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo t('dashboard_participants'); ?></th>
                    <td><?php echo $overallParticipants; ?></td>
                    <td><?php echo $lessonParticipants; ?></td>
                    <td><?php echo $competitionParticipants; ?></td>
                    <td><?php echo $selfParticipants; ?></td>
                    <td><?php echo $monthlyParticipants; ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo t('dashboard_total_points'); ?></th>
                    <td><?php echo number_format($overallPoints, 0); ?></td>
                    <td><?php echo number_format($lessonPoints, 0); ?></td>
                    <td><?php echo number_format($competitionPoints, 0); ?></td>
                    <td><?php echo number_format($selfPoints, 0); ?></td>
                    <td><?php echo number_format($monthlyPoints, 0); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo t('dashboard_completed'); ?></th>
                    <td><?php echo $overallQuizzesCompleted; ?></td>
                    <td><?php echo $lessonCompleted; ?></td>
                    <td><?php echo $competitionCompleted; ?></td>
                    <td><?php echo $selfCompleted; ?></td>
                    <td><?php echo $monthlyAttempted; ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo t('dashboard_average_score'); ?> / <?php echo t('dashboard_time_spent'); ?></th>
                    <td><?php echo number_format($overallAvgScore, 2); ?>%</td>
                    <td><?php echo formatDurationShort($lessonTime); ?></td>
                    <td><?php echo formatDurationShort($competitionTime); ?></td>
                    <td><?php echo formatDurationShort($selfTime); ?></td>
                    <td><?php echo number_format($monthlyAvgScore, 2); ?>%</td>
                </tr>
            </tbody>
        </table>
    </div>
</section>


    </div>
    <!-- Progress Trend Chart -->
<section class="section-card">
    <h3>📈<?php echo t('progress_trend') ?><span class="period-label"><?php echo e($filterLabel); ?></span></h3>
    <?php if (count($progressChartPoints) >= 2):
        $gridSteps = [0, 25, 50, 75, 100];
        $plotHeightPx = $progressChartHeight - ($progressChartPadding * 2);
        $plotLeftPx = $progressChartPadding;
        $plotRightPx = $progressChartWidth - $progressChartPadding;
        $plotBottomPx = $progressChartHeight - $progressChartPadding;

        $areaPoints = $plotLeftPx . ',' . $plotBottomPx;
        foreach ($progressChartPoints as $p) {
            $areaPoints .= ' ' . $p['x'] . ',' . $p['y'];
        }
        $areaPoints .= ' ' . $plotRightPx . ',' . $plotBottomPx;
    ?>
        <div class="progress-chart-wrap">
            <svg viewBox="0 0 <?php echo $progressChartWidth; ?> <?php echo $progressChartHeight; ?>" class="progress-chart-svg" role="img" aria-label="<?php echo e(t('quiz_score_trend')); ?>">
                <!-- Horizontal gridlines + y-axis % labels for easier reading -->
                <?php foreach ($gridSteps as $step):
                    $gy = round($progressChartPadding + (1 - ($step / 100)) * $plotHeightPx, 2);
                ?>
                    <line x1="<?php echo $plotLeftPx; ?>" y1="<?php echo $gy; ?>" x2="<?php echo $plotRightPx; ?>" y2="<?php echo $gy; ?>" stroke="var(--line)" stroke-width="1" stroke-dasharray="<?php echo $step === 0 ? 'none' : '4,4'; ?>" opacity="<?php echo $step === 0 ? '1' : '0.6'; ?>" />
                    <text x="<?php echo $plotLeftPx - 8; ?>" y="<?php echo $gy + 3; ?>" text-anchor="end" class="progress-chart-axis-label"><?php echo $step; ?>%</text>
                <?php endforeach; ?>

                <!-- Shaded area under the trend line -->
                <polygon points="<?php echo e($areaPoints); ?>" fill="var(--brand)" opacity="0.10" />

                <?php for ($i = 0; $i < count($progressChartPoints) - 1; $i++):
                    $p1 = $progressChartPoints[$i];
                    $p2 = $progressChartPoints[$i + 1];
                    $segmentColor = progressScoreColorVar($p2['score']);
                ?>
                    <line x1="<?php echo $p1['x']; ?>" y1="<?php echo $p1['y']; ?>" x2="<?php echo $p2['x']; ?>" y2="<?php echo $p2['y']; ?>" style="stroke: <?php echo $segmentColor; ?>;" stroke-width="4" stroke-linecap="round" />
                <?php endfor; ?>

                <?php foreach ($progressChartPoints as $p):
                    $pointColor = progressScoreColorVar($p['score']);
                ?>
                    <circle cx="<?php echo $p['x']; ?>" cy="<?php echo $p['y']; ?>" r="6" style="fill: <?php echo $pointColor; ?>;" stroke="var(--surface)" stroke-width="2.5">
                        <title><?php echo e($p['label'] . ': ' . number_format($p['score'], 1) . '%'); ?></title>
                    </circle>
                    <text x="<?php echo $p['x']; ?>" y="<?php echo $p['y'] - 12; ?>" text-anchor="middle" class="progress-chart-point-label"><?php echo number_format($p['score'], 0); ?>%</text>
                <?php endforeach; ?>
            </svg>
            <div class="progress-chart-labels">
                <?php foreach ($progressChartPoints as $i => $p): ?>
                    <span><?php echo ($i % $progressChartLabelStep === 0 || $i === count($progressChartPoints) - 1) ? e($p['label']) : ''; ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="progress-chart-legend">
            <span class="legend-item"><i style="background: var(--danger);"></i> <?php echo e(t('poor_range')); ?></span>
            <span class="legend-item"><i style="background: var(--brand);"></i> <?php echo e(t('good_range')); ?></span>
            <span class="legend-item"><i style="background: var(--gold);"></i> <?php echo e(t('better_range')); ?></span>
        </div>
    <?php else: ?>
        <p style="color: var(--muted); text-align: center;"><?php echo t('activity') ?></p>
    <?php endif; ?>
</section>

<!-- New Lessons & Latest Media -->
<div style="display: flex; flex-direction: row; gap: 10px; align-items: stretch;">
    <div class="section-card latest-card">
       <h3>📖 <?php echo t('dashboard_new_lessons'); ?></h3>
        <?php if (!empty($latestContents)): ?>
            <div class="latest-grid">
                <?php foreach ($latestContents as $content): ?>
                 <a href="<?php
    if (!empty($content['subject_slug'])) {
        echo 'subject.php?slug=' . urlencode($content['subject_slug']);
    } elseif (!empty($content['subject_id'])) {
        echo 'subject.php?id=' . (int)$content['subject_id'];
    } else {
        echo 'content.php?id=' . (int)$content['id'];
    }
?>" class="latest-media-card">
                        <div class="latest-media-thumb">
                            <?php
                            $lessonImage = !empty($content['image_path']) ? $content['image_path'] : ($content['category_image_path'] ?? null);
                            $lessonYtId = !empty($content['video_url']) ? extractYoutubeId($content['video_url']) : null;
                            ?>
                            <?php if (!empty($lessonImage)): ?>
                                <img src="<?= e($lessonImage) ?>" alt="<?= e($content['title']) ?>">
                            <?php elseif (!empty($lessonYtId)): ?>
                                <img src="https://img.youtube.com/vi/<?= e($lessonYtId) ?>/hqdefault.jpg" alt="<?= e($content['title']) ?>">
                            <?php elseif (!empty($content['video_path'])): ?>
                                <?php
                                $lessonVideoExt = strtolower(pathinfo((string)$content['video_path'], PATHINFO_EXTENSION));
                                $lessonVideoMime = 'video/mp4';
                                if ($lessonVideoExt === 'webm') $lessonVideoMime = 'video/webm';
                                elseif ($lessonVideoExt === 'ogg') $lessonVideoMime = 'video/ogg';
                                ?>
                                <video muted playsinline preload="metadata" style="width:100%;height:100%;object-fit:cover;">
                                    <source src="<?= e($content['video_path']) ?>" type="<?= $lessonVideoMime ?>">
                                </video>
                            <?php elseif (!empty($content['video_url'])): ?>
                                <span style="font-size: 32px; opacity: 0.5;">🎬</span>
                            <?php else: ?>
                                <span style="font-size: 32px; opacity: 0.5;">📘</span>
                            <?php endif; ?>
                        </div>
                        <div class="latest-media-info">
                            <div class="latest-media-title"><?= e($content['title']) ?></div>
                            <div class="latest-media-type"><?= e($content['subject_name'] ?? 'Lesson') ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
          <p style="color: var(--muted); text-align: center;"><?php echo t('dashboard_no_lessons'); ?></p>
        <?php endif; ?>
    </div>

    <div class="section-card latest-card">
        <h3>🖼️ <?php echo t('dashboard_latest_media'); ?></h3>
        <?php if (!empty($latestMedia)): ?>
            <div class="latest-grid">
                <?php foreach ($latestMedia as $media): ?>
                    <a href="multimedia.php?view=<?= $media['id'] ?>" class="latest-media-card">
                        <div class="latest-media-thumb">
                            <?php
                            $mediaThumb = normalizeUploadPath($media['thumbnail_path'] ?? null);
                            $mediaYtId = !empty($media['video_url']) ? extractYoutubeId($media['video_url']) : null;
                            $mediaFile = normalizeUploadPath($media['file_path'] ?? null);
                            ?>
                            <?php if ($mediaThumb): ?>
                                <img src="<?= e($mediaThumb) ?>" alt="<?= e($media['title']) ?>">
                            <?php elseif ($media['media_type'] === 'image'): ?>
                                <img src="<?= e($mediaFile) ?>" alt="<?= e($media['title']) ?>">
                            <?php elseif ($mediaYtId): ?>
                                <img src="https://img.youtube.com/vi/<?= e($mediaYtId) ?>/hqdefault.jpg" alt="<?= e($media['title']) ?>">
                            <?php elseif ($media['media_type'] === 'video'): ?>
                                <?php
                                $mediaVideoExt = strtolower(pathinfo((string)$media['file_path'], PATHINFO_EXTENSION));
                                $mediaVideoMime = 'video/mp4';
                                if ($mediaVideoExt === 'webm') $mediaVideoMime = 'video/webm';
                                elseif ($mediaVideoExt === 'ogg') $mediaVideoMime = 'video/ogg';
                                ?>
                                <video muted playsinline preload="metadata" style="width:100%;height:100%;object-fit:cover;">
                                    <source src="<?= e($mediaFile) ?>" type="<?= $mediaVideoMime ?>">
                                </video>
                            <?php else: ?>
                                <span style="font-size: 32px; opacity: 0.5;">🎵</span>
                            <?php endif; ?>
                        </div>
                        <div class="latest-media-info">
                            <div class="latest-media-title"><?= e($media['title']) ?></div>
                           <div class="latest-media-type"><?= e(ucfirst((string)($media['media_type'] ?? t('dashboard_lesson')))) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color: var(--muted); text-align: center;"><?php echo t('dashboard_no_media'); ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Upcoming Quizzes & Upcoming Exams -->
 <div class="exam-quiz-row" >
    <div class="section-card" id ="quizzes">
        <h3>📝 <?php echo t('dashboard_upcoming_quizzes'); ?></h3>
        <?php if (!empty($upcomingQuizzes)): ?>
            <?php foreach ($upcomingQuizzes as $quiz): ?>
                <a href="quizzes.php?id=<?php echo (int)$quiz['id']; ?>" class="quiz-item">
                    <div class="quiz-info">
                        <h4><?php echo e($quiz['title'] ?? 'Untitled Quiz'); ?></h4>
                        <div class="quiz-difficulty">
                         <?php echo t('dashboard_difficulty'); ?>: <?php echo e(ucfirst((string)($quiz['difficulty'] ?? 'medium'))); ?>
                            <?php if (!empty($quiz['mode'])): ?>
                               • <?php echo t('dashboard_mode'); ?>: <?php echo e(ucfirst((string)$quiz['mode'])); ?>
                            <?php endif; ?>
                            <?php if (!empty($quiz['time_limit_minutes'])): ?>
                               • <?php echo (int)$quiz['time_limit_minutes']; ?> <?php echo t('dashboard_mins'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="quiz-badge"><?php echo t('dashboard_take_quiz'); ?> →</div>
                </a>
            <?php endforeach; ?>
            <a href="quizzes.php" style="display: block; text-align: center; margin-top: 12px; color: var(--brand); font-size: 13px; font-weight: 600;"><?php echo t('dashboard_view_all_quizzes'); ?> →</a>
        <?php else: ?>
            <p style="color: var(--muted); text-align: center;"><?php echo t('dashboard_no_quizzes'); ?></p>
        <?php endif; ?>
    </div>

    <div class="section-card">
        <h3>📅 <?php echo t('dashboard_upcoming_exams'); ?></h3>
        <?php if (!empty($upcomingExams)): ?>
            <?php foreach ($upcomingExams as $exam):
                $isLive = ($exam['status'] === 'live');
                $badgeBg = $isLive ? 'var(--brand-soft)' : 'var(--accent-soft)';
                $badgeColor = $isLive ? 'var(--brand)' : 'var(--accent)';
                $badgeText = $isLive ? t('dashboard_live') : t('dashboard_upcoming_badge');
            ?>
                <a href="monthly_exams.php" class="exam-item" style="display: block; text-decoration: none; color: inherit;">
                    <div class="exam-info" style="flex:1;">
                        <h4 style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                            <?php echo e($exam['title'] ?? 'Untitled Exam'); ?>
                            <span style="font-size: 11px; padding: 2px 8px; border-radius: 999px; background: <?php echo $badgeBg; ?>; color: <?php echo $badgeColor; ?>;">
                                <?php echo $badgeText; ?>
                            </span>
                        </h4>
                        <div class="exam-meta" style="font-size: 12px; color: var(--muted);">
                            <?php if (!empty($exam['start_date'])): ?>
                              <span>📅 <?php echo t('dashboard_starts'); ?>: <?php echo e(formatDateValue($exam['start_date'], 'M d, Y H:i')); ?></span>
                                <span> • ⏱️ <?php echo (int)$exam['time_limit_minutes']; ?> <?php echo t('dashboard_mins'); ?></span>
                                <span> • ❓ <?php echo (int)$exam['total_questions']; ?> <?php echo t('dashboard_questions'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="exam-badge" style="background: var(--gold-soft); color: var(--gold);">
                        <?php echo t('dashboard_view_exam'); ?>→
                    </div>
                </a>
            <?php endforeach; ?>
            <a href="monthly_exams.php" style="display: block; text-align: center; margin-top: 16px; color: var(--brand); font-size: 13px; font-weight: 600;"><?php echo t('dashboard_view_all_exams'); ?>→</a>
        <?php else: ?>
            <p style="color: var(--muted); text-align: center;"><?php echo t('no_upcoming') ?></p>
            <a href="monthly_exams.php" style="display: block; text-align: center; margin-top: 12px; color: var(--brand); font-size: 13px;"><?php echo t('dashboard_browse_exams'); ?> → →</a>
        <?php endif; ?>
    </div>
</div>

<!-- Inspiration & Resume -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <div class="section-card reminder-card">
       <h3>🕒 <?php echo t('dashboard_inspiration'); ?></h3>
        <p><?php echo $todayReminder; ?></p>
        <?php if ($isMotivationalReminder): ?>
            <div style="margin-top: 12px; font-size: 12px; color: var(--muted);">✨ <?php echo t('dashboard_stay_motivated'); ?></div>
        <?php endif; ?>
    </div>
    <div class="section-card resume-card">
      <h3>▶ <?php echo t('dashboard_resume_learning'); ?></h3>
        <p dir="rtl"><?php echo $resumeLesson; ?></p>
        <a href="<?php echo e($resumeLink); ?>" class="resume-btn"><?php echo t('dashboard_continue_now'); ?> →</a>
    </div>
</div>

<section class="masheikh-section">
    <h2>Masheikh</h2>

    <?php if (!empty($masheikh)): ?>
        <div class="masheikh-grid">
            <?php foreach ($masheikh as $name): ?>
                <div class="masheikh-card">
                    <div class="masheikh-avatar">
                        <?= e(initials($name)) ?>
                    </div>
                    <div class="masheikh-info">
                        <div class="masheikh-name"><?= e($name) ?></div>
                       
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="masheikh-empty">Hakuna masheikh waliowekwa bado.</div>
    <?php endif; ?>
</section>

<div id="custom_html-12" class="widget_text item-column widget widget_custom_html">
    <a href="https://salafussaalih.net/radio-qur-an-24x7/" target="_blank">
        <header class="box-header">
            <h2 class="widget-title">Radio Qur-aan 24×7</h2>
        </header>
    </a>
    <div class="textwidget custom-html-widget">
        <div class="wrap-radio-player">
            <p>Live Radio Qur-aan</p>
            <audio src="https://stream.zeno.fm/dok66ovac9guv" controls style="width: 100%"></audio>
        </div>
    </div>
</div>
<div id="custom_html-9" class="widget_text item-column widget widget_custom_html">
    <a href="http://salafussaalih.net/radio-mawaidha-24x7/" target="_blank">
        <header class="box-header">
            <h2 class="widget-title">Radio Mawaidha 24×7</h2>
        </header>
    </a>
    <div class="textwidget custom-html-widget">
        <div class="wrap-radio-player">
            <p>Live Radio Mawaidha</p>
            <audio src="https://stream.zeno.fm/ydvkzahuiuatv" controls style="width: 100%"></audio>
        </div>
    </div>
</div>

<!-- Footer -->
<div class="footer-meta">
    <div class="brand-text">MyIslam</div>
    <div class="gold-text">#Manhaj Salaf</div>
</div>
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

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (sidebarOverlay) sidebarOverlay.classList.add('show');
        if (body) body.style.overflow = 'hidden';
        if (menuToggle) menuToggle.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        if (body) body.style.overflow = '';
        if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            if (sidebar && sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
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
                if (profileToggle) profileToggle.setAttribute('aria-expanded', 'false');
            }
        }
    });

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark' && body) {
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

    let currentLang = localStorage.getItem('language') || '<?php echo $_SESSION['language'] ?? "sw"; ?>';
    if (langToggle) {
        langToggle.textContent = currentLang === 'sw' ? 'SW' : 'EN';
        langToggle.addEventListener('click', async () => {
            currentLang = currentLang === 'sw' ? 'en' : 'sw';
            langToggle.textContent = currentLang === 'sw' ? 'SW' : 'EN';
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

    // Period filter dropdown: navigate immediately, unless "Custom" is picked,
    // in which case reveal the from/to date form instead of navigating.
    const filterSelect = document.getElementById('filterSelect');
    const customFilterForm = document.getElementById('customFilterForm');

    if (filterSelect) {
        filterSelect.addEventListener('change', () => {
            const val = filterSelect.value;
            if (val === 'custom') {
                if (customFilterForm) customFilterForm.classList.add('show');
            } else {
                window.location.href = '?filter=' + encodeURIComponent(val);
            }
        });
    }

    if (customFilterForm) {
        const dateInputs = customFilterForm.querySelectorAll('input[type="date"]');
        dateInputs.forEach(input => {
            input.addEventListener('change', function() {
                if (this.value) {
                    customFilterForm.submit();
                }
            });
        });
    }

    console.log('Dashboard initialized');
})();
</script>

</body>
</html>