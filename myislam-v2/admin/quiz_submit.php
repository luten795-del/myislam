<?php
declare(strict_types=1);
session_start();

// Set timezone to East Africa Time (Tanzania)
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function quizLog(string $message): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    @file_put_contents(
        $logDir . '/quiz_debug.log',
        date('[Y-m-d H:i:s] ') . $message . PHP_EOL,
        FILE_APPEND
    );
}

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Resolve which course enrollment this attempt belongs to.
 *
 * Priority:
 *   1. If the quiz is scheduled in a course_division, find the user's
 *      enrollment for that division's course.
 *   2. Else, if the quiz itself has quizzes.course_id set, find the user's
 *      active/completed enrollment in that course.
 *   3. Else, no scope (NULL) — attempt is course-agnostic (e.g. content-scoped
 *      legacy lesson quizzes).
 *
 * Returns ['course_id' => int|null, 'enrollment_id' => int|null, 'division_id' => int|null]
 */
function resolveQuizAttemptScope(PDO $conn, int $userId, int $quizId, array $quiz): array
{
    $courseId   = isset($quiz['course_id']) ? (int)$quiz['course_id'] : 0;
    $divisionId = isset($quiz['division_id']) ? (int)$quiz['division_id'] : 0;

    // If the quiz isn't linked directly, look at course_schedule to find the division
    if ($divisionId <= 0) {
        try {
            $stmt = $conn->prepare("
                SELECT division_id, course_id
                FROM course_schedule
                WHERE item_type = 'quiz' AND item_id = ?
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([$quizId]);
            $schedRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($schedRow) {
                $divisionId = (int)($schedRow['division_id'] ?? 0);
                if ($courseId <= 0 && !empty($schedRow['course_id'])) {
                    $courseId = (int)$schedRow['course_id'];
                }
            }
        } catch (PDOException $e) {
            quizLog("scope lookup: course_schedule error: " . $e->getMessage());
        }
    }

    // If we have a division, resolve the enrollment through it
    if ($divisionId > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT e.id AS enrollment_id, e.course_id
                FROM course_enrollments e
                JOIN course_divisions cd ON cd.course_id = e.course_id
                WHERE e.user_id = ?
                  AND cd.id = ?
                  AND e.status IN ('active','completed')
                ORDER BY e.started_at DESC, e.id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $divisionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'course_id'     => (int)$row['course_id'],
                    'enrollment_id' => (int)$row['enrollment_id'],
                    'division_id'   => $divisionId,
                ];
            }
        } catch (PDOException $e) {
            quizLog("scope lookup: enrollment (division) error: " . $e->getMessage());
        }
    }

    // Fall back to course_id-only resolution
    if ($courseId > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT id AS enrollment_id, course_id
                FROM course_enrollments
                WHERE user_id = ?
                  AND course_id = ?
                  AND status IN ('active','completed')
                ORDER BY started_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $courseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'course_id'     => (int)$row['course_id'],
                    'enrollment_id' => (int)$row['enrollment_id'],
                    'division_id'   => $divisionId > 0 ? $divisionId : null,
                ];
            }
        } catch (PDOException $e) {
            quizLog("scope lookup: enrollment (course) error: " . $e->getMessage());
        }
    }

    // Unscoped — content quiz or user not enrolled in a matching course
    return [
        'course_id'     => $courseId > 0 ? $courseId : null,
        'enrollment_id' => null,
        'division_id'   => $divisionId > 0 ? $divisionId : null,
    ];
}

function ensureQuizAttemptsTable(PDO $conn): void
{
    $stmt = $conn->query("SHOW TABLES LIKE 'quiz_attempts'");
    $tableExists = $stmt->rowCount() > 0;

    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `quiz_attempts` (
                `id` int NOT NULL AUTO_INCREMENT,
                `user_id` int NOT NULL,
                `course_id` int UNSIGNED DEFAULT NULL,
                `enrollment_id` int UNSIGNED DEFAULT NULL,
                `quiz_id` int NOT NULL,
                `attempt_number` int NOT NULL DEFAULT '1',
                `total_questions` int NOT NULL DEFAULT '0',
                `correct_answers` int NOT NULL DEFAULT '0',
                `score` int NOT NULL DEFAULT '0',
                `total_points` int NOT NULL DEFAULT '0',
                `percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
                `passed` tinyint(1) NOT NULL DEFAULT '0',
                `status` enum('started','completed','abandoned') NOT NULL DEFAULT 'started',
                `started_at` datetime DEFAULT NULL,
                `completed_at` datetime DEFAULT NULL,
                `answers_json` longtext,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_quiz_attempt_user_quiz_attemptno` (`user_id`, `quiz_id`, `attempt_number`),
                KEY `idx_course` (`course_id`),
                KEY `idx_enrollment` (`enrollment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        quizLog("Created quiz_attempts table with AUTO_INCREMENT + course_id/enrollment_id");
        return;
    }

    // --- Fix AUTO_INCREMENT on id ---
    $stmt = $conn->query("SHOW COLUMNS FROM `quiz_attempts` WHERE Field = 'id'");
    $colInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($colInfo && strpos($colInfo['Extra'] ?? '', 'auto_increment') === false) {
        try {
            $conn->exec("ALTER TABLE `quiz_attempts` MODIFY `id` int NOT NULL AUTO_INCREMENT");
            quizLog("Fixed quiz_attempts.id to have AUTO_INCREMENT");
        } catch (PDOException $e) {
            quizLog("Simple modify failed: " . $e->getMessage());
            try {
                $stmt = $conn->query("SELECT id, COUNT(*) as cnt FROM quiz_attempts GROUP BY id HAVING COUNT(*) > 1");
                $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($duplicates)) {
                    quizLog("Found duplicate IDs, cleaning up...");
                    $conn->exec("CREATE TABLE IF NOT EXISTS quiz_attempts_backup LIKE quiz_attempts");
                    $conn->exec("INSERT INTO quiz_attempts_backup SELECT * FROM quiz_attempts");
                    $conn->exec("TRUNCATE TABLE quiz_attempts");
                    $conn->exec("
                        INSERT INTO quiz_attempts (
                            user_id, course_id, enrollment_id, quiz_id, attempt_number,
                            total_questions, correct_answers, score, total_points, percentage,
                            passed, status, started_at, completed_at, answers_json,
                            created_at, updated_at
                        )
                        SELECT
                            user_id, course_id, enrollment_id, quiz_id, attempt_number,
                            total_questions, correct_answers, score, total_points, percentage,
                            passed, status, started_at, completed_at, answers_json,
                            created_at, updated_at
                        FROM quiz_attempts_backup
                    ");
                    $conn->exec("DROP TABLE quiz_attempts_backup");
                    quizLog("Re-inserted data with new auto-generated IDs");
                }

                $conn->exec("ALTER TABLE `quiz_attempts` MODIFY `id` int NOT NULL AUTO_INCREMENT");
                quizLog("Fixed quiz_attempts.id using multi-step approach");
            } catch (PDOException $e2) {
                quizLog("Multi-step fix failed: " . $e2->getMessage());
                try {
                    $conn->exec("ALTER TABLE `quiz_attempts` ADD COLUMN temp_id INT AUTO_INCREMENT UNIQUE FIRST");
                    $conn->exec("ALTER TABLE `quiz_attempts` DROP PRIMARY KEY");
                    $conn->exec("ALTER TABLE `quiz_attempts` DROP COLUMN id");
                    $conn->exec("ALTER TABLE `quiz_attempts` CHANGE temp_id id INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
                    quizLog("Fixed using column swap method");
                } catch (PDOException $e3) {
                    quizLog("All fix attempts failed: " . $e3->getMessage());
                }
            }
        }
    }

    // --- Ensure all required columns exist ---
    $columns = [];
    $stmt = $conn->query("SHOW COLUMNS FROM `quiz_attempts`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $columns[$col['Field']] = true;
    }

    $required = [
        'course_id'       => "ALTER TABLE `quiz_attempts` ADD COLUMN `course_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`",
        'enrollment_id'   => "ALTER TABLE `quiz_attempts` ADD COLUMN `enrollment_id` INT UNSIGNED DEFAULT NULL AFTER `course_id`",
        'attempt_number'  => "ALTER TABLE `quiz_attempts` ADD COLUMN `attempt_number` int NOT NULL DEFAULT '1' AFTER `quiz_id`",
        'total_questions' => "ALTER TABLE `quiz_attempts` ADD COLUMN `total_questions` int NOT NULL DEFAULT '0' AFTER `attempt_number`",
        'correct_answers' => "ALTER TABLE `quiz_attempts` ADD COLUMN `correct_answers` int NOT NULL DEFAULT '0' AFTER `total_questions`",
        'score'           => "ALTER TABLE `quiz_attempts` ADD COLUMN `score` int NOT NULL DEFAULT '0' AFTER `correct_answers`",
        'total_points'    => "ALTER TABLE `quiz_attempts` ADD COLUMN `total_points` int NOT NULL DEFAULT '0' AFTER `score`",
        'percentage'      => "ALTER TABLE `quiz_attempts` ADD COLUMN `percentage` decimal(5,2) NOT NULL DEFAULT '0.00' AFTER `total_points`",
        'passed'          => "ALTER TABLE `quiz_attempts` ADD COLUMN `passed` tinyint(1) NOT NULL DEFAULT '0' AFTER `percentage`",
        'status'          => "ALTER TABLE `quiz_attempts` ADD COLUMN `status` enum('started','completed','abandoned') NOT NULL DEFAULT 'started' AFTER `passed`",
        'started_at'      => "ALTER TABLE `quiz_attempts` ADD COLUMN `started_at` datetime DEFAULT NULL AFTER `status`",
        'completed_at'    => "ALTER TABLE `quiz_attempts` ADD COLUMN `completed_at` datetime DEFAULT NULL AFTER `started_at`",
        'answers_json'    => "ALTER TABLE `quiz_attempts` ADD COLUMN `answers_json` longtext AFTER `completed_at`",
        'created_at'      => "ALTER TABLE `quiz_attempts` ADD COLUMN `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP AFTER `answers_json`",
        'updated_at'      => "ALTER TABLE `quiz_attempts` ADD COLUMN `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    ];

    foreach ($required as $column => $sql) {
        if (!isset($columns[$column])) {
            try {
                $conn->exec($sql);
                quizLog("Added missing quiz_attempts column: {$column}");
            } catch (PDOException $e) {
                quizLog("Failed to add column {$column}: " . $e->getMessage());
            }
        }
    }

    // --- Ensure indexes exist ---
    try {
        $idxStmt = $conn->query("SHOW INDEX FROM `quiz_attempts`");
        $indexes = [];
        foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            $indexes[$idx['Key_name']] = true;
        }
        if (!isset($indexes['idx_course'])) {
            $conn->exec("ALTER TABLE `quiz_attempts` ADD INDEX `idx_course` (`course_id`)");
            quizLog("Added idx_course to quiz_attempts");
        }
        if (!isset($indexes['idx_enrollment'])) {
            $conn->exec("ALTER TABLE `quiz_attempts` ADD INDEX `idx_enrollment` (`enrollment_id`)");
            quizLog("Added idx_enrollment to quiz_attempts");
        }
    } catch (PDOException $e) {
        quizLog("Index ensure error: " . $e->getMessage());
    }
}

function buildAttemptPayload(array $attemptRow): array
{
    $results = [];
    if (!empty($attemptRow['answers_json'])) {
        $decoded = json_decode((string)$attemptRow['answers_json'], true);
        if (is_array($decoded)) {
            $results = $decoded;
        }
    }

    return [
        'attempt_id'      => (int)($attemptRow['id'] ?? 0),
        'score'           => (int)($attemptRow['score'] ?? 0),
        'total'           => (int)($attemptRow['total_points'] ?? 0),
        'percentage'      => (float)($attemptRow['percentage'] ?? 0),
        'passed'          => ((int)($attemptRow['passed'] ?? 0) === 1),
        'correct_count'   => (int)($attemptRow['correct_answers'] ?? 0),
        'total_questions' => (int)($attemptRow['total_questions'] ?? 0),
        'results'         => $results,
        'attempted'       => true,
        'locked'          => true,
    ];
}

function isCompetitionAttemptLocked(PDO $conn, int $userId, int $quizId, array $quiz, ?int $courseId): bool
{
    $start = $quiz['competition_start_at'] ?? null;
    $end = $quiz['competition_end_at'] ?? null;

    // Build scope filter — if a course_id is known, only count attempts in
    // that course. Otherwise fall back to unscoped (legacy behavior).
    $scopeSql = '';
    $scopeParams = [];
    if ($courseId !== null && $courseId > 0) {
        $scopeSql = " AND course_id = ? ";
        $scopeParams[] = $courseId;
    }

    if ($start && $end) {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM quiz_attempts
            WHERE user_id = ?
              AND quiz_id = ?
              AND status = 'completed'
              AND completed_at BETWEEN ? AND ?
              $scopeSql
        ");
        $startUtc = (new DateTime($start, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $endUtc   = (new DateTime($end, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt->execute(array_merge([$userId, $quizId, $startUtc, $endUtc], $scopeParams));
        return ((int)$stmt->fetchColumn()) >= 1;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM quiz_attempts
        WHERE user_id = ?
          AND quiz_id = ?
          AND status = 'completed'
          $scopeSql
    ");
    $stmt->execute(array_merge([$userId, $quizId], $scopeParams));
    return ((int)$stmt->fetchColumn()) >= 1;
}

function updateQuizLeaderboardStats(PDO $conn, int $userId, string $mode): void
{
    if (!in_array($mode, ['lesson', 'competition'], true)) {
        return;
    }

    if ($mode === 'lesson') {
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS quizzes_taken,
                SUM(CASE WHEN qa.status = 'completed' THEN 1 ELSE 0 END) AS quizzes_completed,
                COALESCE(SUM(qa.score), 0) AS total_points,
                COALESCE(SUM(qa.correct_answers), 0) AS total_correct,
                COALESCE(SUM(qa.total_questions - qa.correct_answers), 0) AS total_wrong,
                COALESCE(AVG(qa.percentage), 0) AS average_score,
                COALESCE(MAX(qa.percentage), 0) AS best_score,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, qa.started_at, qa.completed_at)), 0) AS total_time_seconds
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            WHERE qa.user_id = ?
              AND qa.status IN ('completed', 'abandoned')
              AND q.mode IN ('lesson', 'self')
              AND q.course_id IS NULL
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $conn->prepare("
            SELECT
                SUM(t.quizzes_taken)      AS quizzes_taken,
                SUM(t.quizzes_completed)  AS quizzes_completed,
                SUM(t.total_points)       AS total_points,
                SUM(t.total_correct)      AS total_correct,
                SUM(t.total_wrong)        AS total_wrong,
                CASE WHEN SUM(t.quizzes_taken) > 0
                     THEN SUM(t.total_points) / SUM(t.quizzes_taken)
                     ELSE 0 END           AS average_score,
                MAX(t.best_score)         AS best_score,
                SUM(t.total_time_seconds) AS total_time_seconds
            FROM (
                SELECT
                    COUNT(*) AS quizzes_taken,
                    SUM(CASE WHEN qa.status = 'completed' THEN 1 ELSE 0 END) AS quizzes_completed,
                    COALESCE(SUM(qa.score), 0) AS total_points,
                    COALESCE(SUM(qa.correct_answers), 0) AS total_correct,
                    COALESCE(SUM(qa.total_questions - qa.correct_answers), 0) AS total_wrong,
                    COALESCE(MAX(qa.percentage), 0) AS best_score,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, qa.started_at, qa.completed_at)), 0) AS total_time_seconds
                FROM quiz_attempts qa
                JOIN quizzes q ON q.id = qa.quiz_id
                WHERE qa.user_id = ?
                  AND qa.status IN ('completed', 'abandoned')
                  AND q.mode = 'competition'
                  AND q.course_id IS NOT NULL

                UNION ALL

                SELECT
                    COUNT(*) AS quizzes_taken,
                    SUM(CASE WHEN mea.status = 'completed' THEN 1 ELSE 0 END) AS quizzes_completed,
                    COALESCE(SUM(mea.score), 0) AS total_points,
                    COALESCE(SUM(mea.correct_answers), 0) AS total_correct,
                    COALESCE(SUM(mea.total_questions - mea.correct_answers), 0) AS total_wrong,
                    COALESCE(MAX(mea.percentage), 0) AS best_score,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, mea.started_at, mea.completed_at)), 0) AS total_time_seconds
                FROM monthly_exam_attempts mea
                JOIN monthly_exams me ON me.id = mea.exam_id
                WHERE mea.user_id = ?
                  AND mea.status IN ('completed', 'abandoned')
                  AND me.course_id IS NOT NULL

                UNION ALL

                SELECT
                    COUNT(*) AS quizzes_taken,
                    SUM(CASE WHEN sea.status = 'completed' THEN 1 ELSE 0 END) AS quizzes_completed,
                    COALESCE(SUM(sea.score), 0) AS total_points,
                    COALESCE(SUM(sea.correct_answers), 0) AS total_correct,
                    COALESCE(SUM(sea.total_questions - sea.correct_answers), 0) AS total_wrong,
                    COALESCE(MAX(sea.percentage), 0) AS best_score,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, sea.started_at, sea.completed_at)), 0) AS total_time_seconds
                FROM semester_exam_attempts sea
                WHERE sea.user_id = ?
                  AND sea.status IN ('completed', 'abandoned')
            ) t
        ");
        $stmt->execute([$userId, $userId, $userId]);
    }

    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stats || (int)($stats['quizzes_taken'] ?? 0) === 0) {
        return;
    }

    $nextLbIdStmt = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 FROM leaderboard_stats");
    $nextLbId = (int)$nextLbIdStmt->fetchColumn();

    $upsert = $conn->prepare("
        INSERT INTO leaderboard_stats (
            id, user_id, mode, total_points, quizzes_taken, quizzes_completed,
            total_correct_answers, total_wrong_answers, average_score, best_score,
            total_time_seconds, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            total_points = VALUES(total_points),
            quizzes_taken = VALUES(quizzes_taken),
            quizzes_completed = VALUES(quizzes_completed),
            total_correct_answers = VALUES(total_correct_answers),
            total_wrong_answers = VALUES(total_wrong_answers),
            average_score = VALUES(average_score),
            best_score = VALUES(best_score),
            total_time_seconds = VALUES(total_time_seconds),
            updated_at = NOW()
    ");
    $upsert->execute([
        $nextLbId,
        $userId,
        $mode,
        $stats['total_points'] ?? 0,
        $stats['quizzes_taken'] ?? 0,
        $stats['quizzes_completed'] ?? 0,
        $stats['total_correct'] ?? 0,
        $stats['total_wrong'] ?? 0,
        $stats['average_score'] ?? 0,
        $stats['best_score'] ?? 0,
        $stats['total_time_seconds'] ?? 0
    ]);

    $conn->prepare("
        UPDATE leaderboard_stats ls
        JOIN (
            SELECT id,
                   RANK() OVER (ORDER BY total_points DESC, best_score DESC, quizzes_completed DESC, total_time_seconds ASC) AS rnk
            FROM leaderboard_stats
            WHERE mode = ?
        ) ranked ON ls.id = ranked.id
        SET ls.rank_position = ranked.rnk
        WHERE ls.mode = ?
    ")->execute([$mode, $mode]);
}

function evaluateExplanationAnswer(
    string $studentAnswer,
    string $expectedAnswer,
    int $maxPoints,
    array $keywords = [],
    float $keywordThreshold = 0.6,
    float $similarityThreshold = 70.0
): array {
    $normalize = function(string $text): string {
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    };

    $student = $normalize($studentAnswer);
    $expected = $normalize($expectedAnswer);

    if (empty($student)) {
        return [
            'is_correct'      => false,
            'points_earned'   => 0,
            'feedback'        => 'No answer provided.',
            'score_breakdown' => ['keywords_matched' => 0, 'similarity' => 0]
        ];
    }

    if (empty($expected) && empty($keywords)) {
        similar_text($student, $expected, $similarity);
        $isCorrect = $similarity >= $similarityThreshold;
        $points = $isCorrect ? $maxPoints : 0;
        return [
            'is_correct'      => $isCorrect,
            'points_earned'   => $points,
            'feedback'        => $isCorrect ? 'Answer accepted (similarity).' : 'Answer does not match expected response.',
            'score_breakdown' => ['similarity' => round($similarity, 1)]
        ];
    }

    $keywordScore = 0;
    $totalWeight = 0;
    $matchedKeywords = [];

    if (empty($keywords) && !empty($expected)) {
        $words = preg_split('/[,\s]+/', $expected);
        foreach (array_unique($words) as $word) {
            if (strlen($word) > 2) {
                $keywords[] = ['word' => $word, 'weight' => 1];
            }
        }
    }

    foreach ($keywords as $kw) {
        $word = is_array($kw) ? ($kw['word'] ?? $kw) : $kw;
        $weight = isset($kw['weight']) ? (float)$kw['weight'] : 1.0;
        $totalWeight += $weight;

        if (strpos($word, ' ') !== false) {
            if (strpos($student, $word) !== false) {
                $keywordScore += $weight;
                $matchedKeywords[] = $word;
            }
        } else {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $student)) {
                $keywordScore += $weight;
                $matchedKeywords[] = $word;
            }
        }
    }

    $keywordPercent = $totalWeight > 0 ? ($keywordScore / $totalWeight) : 0;

    if ($keywordPercent >= $keywordThreshold) {
        return [
            'is_correct'      => true,
            'points_earned'   => $maxPoints,
            'feedback'        => sprintf('Correct. Matched %d%% of required keywords (%s).', round($keywordPercent * 100), implode(', ', $matchedKeywords)),
            'score_breakdown' => [
                'keywords_matched' => round($keywordPercent * 100, 1),
                'matched_list'     => $matchedKeywords
            ]
        ];
    }

    similar_text($student, $expected, $similarity);
    if ($similarity >= $similarityThreshold) {
        return [
            'is_correct'      => true,
            'points_earned'   => $maxPoints,
            'feedback'        => sprintf('Answer accepted (similarity %.1f%%).', $similarity),
            'score_breakdown' => [
                'keywords_matched' => round($keywordPercent * 100, 1),
                'similarity'       => round($similarity, 1)
            ]
        ];
    }

    $feedback = sprintf(
        'Incorrect. Expected keywords: %s. Your answer similarity: %.1f%%.',
        implode(', ', array_column($keywords, 'word')),
        $similarity
    );
    return [
        'is_correct'      => false,
        'points_earned'   => 0,
        'feedback'        => $feedback,
        'score_breakdown' => [
            'keywords_matched' => round($keywordPercent * 100, 1),
            'similarity'       => round($similarity, 1)
        ]
    ];
}

// -----------------------------------------------------------------------------
// Authentication
// -----------------------------------------------------------------------------
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    respond(['success' => false, 'message' => 'Not authenticated'], 401);
}
$userId = (int)$_SESSION['user_id'];

// -----------------------------------------------------------------------------
// Request
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    respond(['success' => false, 'message' => 'Invalid JSON'], 400);
}

$quizId = isset($input['quiz_id']) ? (int)$input['quiz_id'] : 0;
$attemptId = isset($input['attempt_id']) ? (int)$input['attempt_id'] : 0;
$answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];
$requestedMode = strtolower(trim((string)($input['mode'] ?? 'lesson')));
$autoSubmit = isset($input['auto_submit']) && $input['auto_submit'] === true;

if ($quizId <= 0) {
    respond(['success' => false, 'message' => 'Quiz ID required'], 400);
}

quizLog("Submission started - User: {$userId}, Quiz: {$quizId}, Mode: {$requestedMode}, Attempt: {$attemptId}, Auto: " . ($autoSubmit ? 'Yes' : 'No'));

// -----------------------------------------------------------------------------
// Database
// -----------------------------------------------------------------------------
try {
    $conn = db();
    if (!$conn) {
        throw new RuntimeException('Database connection failed');
    }
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureQuizAttemptsTable($conn);
} catch (Throwable $e) {
    quizLog("DB connection/bootstrap error: " . $e->getMessage());
    respond(['success' => false, 'message' => $e->getMessage()], 500);
}

// -----------------------------------------------------------------------------
// Quiz
// -----------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT q.*
    FROM quizzes q
    WHERE q.id = ? AND q.status = 'published'
    LIMIT 1
");
$stmt->execute([$quizId]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    quizLog("Quiz not found or not published: {$quizId}");
    respond(['success' => false, 'message' => 'Quiz not found or not published'], 404);
}

$quizMode = strtolower(trim((string)($quiz['mode'] ?? 'lesson')));
if (!in_array($quizMode, ['lesson', 'competition', 'self'], true)) {
    $quizMode = 'lesson';
}

// ---- Resolve attempt scope (course / enrollment) ----
$scope = resolveQuizAttemptScope($conn, $userId, $quizId, $quiz);
$attemptCourseId     = $scope['course_id'];
$attemptEnrollmentId = $scope['enrollment_id'];

quizLog("Attempt scope resolved: course_id=" . ($attemptCourseId ?? 'NULL') . ", enrollment_id=" . ($attemptEnrollmentId ?? 'NULL') . ", division_id=" . ($scope['division_id'] ?? 'NULL'));

// -----------------------------------------------------------------------------
// Admin access lock
// -----------------------------------------------------------------------------
if (array_key_exists('allow_access', $quiz) && (int)$quiz['allow_access'] === 0) {
    quizLog("Blocked quiz submission - access locked by admin for quiz {$quizId}");
    respond([
        'success' => false,
        'message' => 'This quiz is currently unavailable. Please check back later.'
    ], 403);
}

// -----------------------------------------------------------------------------
// Lesson-mode completion lock
// -----------------------------------------------------------------------------
if ($quizMode === 'lesson' && !empty($quiz['content_id'])) {
    $lockStmt = $conn->prepare("
        SELECT allow_auto_completion
        FROM contents
        WHERE id = ?
        LIMIT 1
    ");
    $lockStmt->execute([(int)$quiz['content_id']]);
    $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC);

    $allowAutoCompletion = !$lockRow || !array_key_exists('allow_auto_completion', $lockRow) || (int)$lockRow['allow_auto_completion'] === 1;

    if (!$allowAutoCompletion) {
        quizLog("Blocked lesson quiz submission - completion locked for content {$quiz['content_id']}");
        respond([
            'success' => false,
            'message' => 'Quiz submission is currently disabled for this lesson by the admin. Please check back later.'
        ], 403);
    }
}

// -----------------------------------------------------------------------------
// Existing completed attempt rules — scoped by course when possible
// -----------------------------------------------------------------------------
$existingAttempt = null;
try {
    if ($attemptCourseId !== null && $attemptCourseId > 0) {
        $stmt = $conn->prepare("
            SELECT *
            FROM quiz_attempts
            WHERE user_id = ? AND quiz_id = ? AND course_id = ? AND status = 'completed'
            ORDER BY completed_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $quizId, $attemptCourseId]);
    } else {
        $stmt = $conn->prepare("
            SELECT *
            FROM quiz_attempts
            WHERE user_id = ? AND quiz_id = ? AND status = 'completed'
            ORDER BY completed_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $quizId]);
    }
    $existingAttempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    quizLog("Existing attempt lookup error: " . $e->getMessage());
}

if ($quizMode === 'competition' && isCompetitionAttemptLocked($conn, $userId, $quizId, $quiz, $attemptCourseId)) {
    respond([
        'success' => false,
        'attempted' => true,
        'message' => 'You have already used your competition attempt for this quiz window.',
        'attempt_data' => $existingAttempt ? buildAttemptPayload($existingAttempt) : null,
    ], 409);
}

if ($existingAttempt && $quizMode === 'lesson') {
    quizLog("User already completed this lesson quiz (course-scoped)");
    respond([
        'success' => false,
        'attempted' => true,
        'message' => 'You have already taken this quiz. Review only is available now.',
        'attempt_data' => buildAttemptPayload($existingAttempt),
    ], 409);
}

if ($existingAttempt && $quizMode === 'self') {
    $maxAttempts = (int)($quiz['max_attempts'] ?? 0);
    $unlimited = (bool)($quiz['unlimited_attempts'] ?? false);

    if (!$unlimited && $maxAttempts > 0) {
        if ($attemptCourseId !== null && $attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM quiz_attempts
                WHERE user_id = ? AND quiz_id = ? AND course_id = ? AND status = 'completed'
            ");
            $stmt->execute([$userId, $quizId, $attemptCourseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM quiz_attempts
                WHERE user_id = ? AND quiz_id = ? AND status = 'completed'
            ");
            $stmt->execute([$userId, $quizId]);
        }
        $completedAttempts = (int)$stmt->fetchColumn();

        if ($completedAttempts >= $maxAttempts) {
            respond([
                'success' => false,
                'attempted' => true,
                'message' => 'You have reached the maximum number of attempts for this quiz.',
                'attempt_data' => buildAttemptPayload($existingAttempt),
            ], 409);
        }
    }
}

// -----------------------------------------------------------------------------
// Fetch questions and options
// -----------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT
        q.id,
        q.question_text,
        q.question_type,
        q.points,
        q.explanation,
        q.sort_order,
        q.expected_answer,
        q.keywords_json
    FROM quiz_questions q
    WHERE q.quiz_id = ?
    ORDER BY q.sort_order ASC, q.id ASC
");
$stmt->execute([$quizId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($questions)) {
    quizLog("No questions found for quiz {$quizId}");
    respond(['success' => false, 'message' => 'No questions found for this quiz'], 404);
}

foreach ($questions as &$question) {
    $questionId = (int)$question['id'];
    $questionType = $question['question_type'] ?? 'multiple_choice';

    if ($questionType !== 'explanation') {
        $optStmt = $conn->prepare("
            SELECT id, option_text, image_path, is_correct, sort_order
            FROM quiz_options
            WHERE question_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $optStmt->execute([$questionId]);
        $options = $optStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $correctOption = null;
        $optionMap = [];

        foreach ($options as $opt) {
            $optId = (int)$opt['id'];
            $optionMap[$optId] = $opt;
            if ((int)($opt['is_correct'] ?? 0) === 1) {
                if ($correctOption !== null) {
                    respond(['success' => false, 'message' => "Question {$questionId} has more than one correct option configured."], 500);
                }
                $correctOption = $opt;
            }
        }

        if (!$correctOption) {
            respond(['success' => false, 'message' => "Question {$questionId} has no correct option configured."], 500);
        }

        $question['options'] = $options;
        $question['option_map'] = $optionMap;
        $question['correct_option'] = $correctOption;
    } else {
        $question['expected_answer'] = $question['expected_answer'] ?? '';
        $question['keywords'] = [];
        if (!empty($question['keywords_json'])) {
            $decoded = json_decode($question['keywords_json'], true);
            if (is_array($decoded)) {
                $question['keywords'] = $decoded;
            }
        }
    }
}
unset($question);

// -----------------------------------------------------------------------------
// Validate answers
// -----------------------------------------------------------------------------
foreach ($questions as $question) {
    $qId = (int)$question['id'];
    $questionType = $question['question_type'] ?? 'multiple_choice';
    $submittedValue = $answers[$qId] ?? null;

    if ($questionType !== 'explanation') {
        $available = array_keys($question['option_map']);
        quizLog("Q{$qId} | submitted: " . ($submittedValue ?? 'MISSING') . " | available: " . implode(',', $available));

        if (!$autoSubmit) {
            if ($submittedValue === null || $submittedValue === '') {
                respond(['success' => false, 'message' => 'Please answer all questions before submitting.'], 422);
            }
            if (!isset($question['option_map'][$submittedValue])) {
                respond(['success' => false, 'message' => 'Invalid answer submitted for one or more questions.'], 422);
            }
        }
    } else {
        quizLog("Q{$qId} (explanation) | submitted: " . ($submittedValue ? substr($submittedValue, 0, 50) . '...' : 'EMPTY'));
        if (!$autoSubmit && ($submittedValue === null || trim($submittedValue) === '')) {
            respond(['success' => false, 'message' => 'Please answer all questions before submitting.'], 422);
        }
    }
}

// -----------------------------------------------------------------------------
// Grade answers
// -----------------------------------------------------------------------------
$totalPoints = 0;
$earnedPoints = 0;
$correctCount = 0;
$totalQuestions = count($questions);
$results = [];

foreach ($questions as $question) {
    $qId = (int)$question['id'];
    $points = max(1, (int)($question['points'] ?? 1));
    $totalPoints += $points;
    $questionType = $question['question_type'] ?? 'multiple_choice';

    if ($questionType !== 'explanation') {
        $submittedValue = $answers[$qId] ?? null;
        $submittedOption = null;
        $isCorrect = false;
        $userAnswerId = null;
        $userAnswerText = null;

        $correctOption = $question['correct_option'];
        $correctAnswerId = (int)$correctOption['id'];
        $correctAnswerText = (string)$correctOption['option_text'];

        if ($submittedValue !== null && $submittedValue !== '' && isset($question['option_map'][$submittedValue])) {
            $submittedOption = $question['option_map'][$submittedValue];
            $userAnswerId = (int)$submittedOption['id'];
            $userAnswerText = (string)$submittedOption['option_text'];

            $isCorrect = ($userAnswerId === $correctAnswerId);
            if ($isCorrect) {
                $earnedPoints += $points;
                $correctCount++;
            }
        }

        $results[] = [
            'question_id'         => $qId,
            'question_text'       => $question['question_text'],
            'user_answer'         => $userAnswerId,
            'user_answer_text'    => $userAnswerText,
            'correct_answer'      => $correctAnswerId,
            'correct_answer_text' => $correctAnswerText,
            'is_correct'          => $isCorrect,
            'points_earned'       => $isCorrect ? $points : 0,
            'explanation'         => $question['explanation'] ?? null,
        ];
    } else {
        $studentAnswer = (string)($answers[$qId] ?? '');
        $expectedAnswer = $question['expected_answer'] ?? '';
        $keywords = $question['keywords'] ?? [];

        $result = evaluateExplanationAnswer(
            $studentAnswer,
            $expectedAnswer,
            $points,
            $keywords,
            0.6,
            70.0
        );

        $isCorrect = $result['is_correct'];
        $pointsEarned = $result['points_earned'];
        $feedback = $result['feedback'];

        if ($isCorrect) {
            $earnedPoints += $pointsEarned;
            $correctCount++;
        }

        $results[] = [
            'question_id'         => $qId,
            'question_text'       => $question['question_text'],
            'user_answer'         => null,
            'user_answer_text'    => $studentAnswer,
            'correct_answer'      => null,
            'correct_answer_text' => $expectedAnswer,
            'is_correct'          => $isCorrect,
            'points_earned'       => $pointsEarned,
            'explanation'         => $question['explanation'] ?? null,
            'feedback'            => $feedback,
        ];
    }
}

$percentage = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100, 2) : 0.0;
$passed = $percentage >= ((int)($quiz['passing_score'] ?? 70));

quizLog("Grading: {$earnedPoints}/{$totalPoints} ({$percentage}%) - Passed: " . ($passed ? 'Yes' : 'No'));

// -----------------------------------------------------------------------------
// Special handling: Lesson mode failure – do NOT save attempt
// -----------------------------------------------------------------------------
if ($quizMode === 'lesson' && !$passed) {
    quizLog("Lesson quiz failed – attempt not saved.");
    respond([
        'success' => false,
        'passed'  => false,
        'message' => 'You did not achieve the passing score. Please review the lesson and try again.',
    ]);
}

// -----------------------------------------------------------------------------
// Save attempt
// -----------------------------------------------------------------------------
try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(attempt_number), 0) + 1
        FROM quiz_attempts
        WHERE user_id = ? AND quiz_id = ?
    ");
    $stmt->execute([$userId, $quizId]);
    $attemptNumber = (int)$stmt->fetchColumn();

    $savedAttemptId = 0;

    if ($attemptId > 0) {
        $stmt = $conn->prepare("
            SELECT id, attempt_number
            FROM quiz_attempts
            WHERE id = ? AND user_id = ? AND quiz_id = ? AND status = 'started'
            FOR UPDATE
        ");
        $stmt->execute([$attemptId, $userId, $quizId]);
        $openAttempt = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($openAttempt) {
            $stmt = $conn->prepare("
                UPDATE quiz_attempts
                SET
                    course_id = COALESCE(?, course_id),
                    enrollment_id = COALESCE(?, enrollment_id),
                    total_questions = ?,
                    correct_answers = ?,
                    score = ?,
                    total_points = ?,
                    percentage = ?,
                    passed = ?,
                    status = 'completed',
                    completed_at = NOW(),
                    answers_json = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $attemptCourseId,
                $attemptEnrollmentId,
                $totalQuestions,
                $correctCount,
                $earnedPoints,
                $totalPoints,
                $percentage,
                $passed ? 1 : 0,
                json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $attemptId
            ]);
            $savedAttemptId = $attemptId;
        }
    }

    if ($savedAttemptId <= 0) {
        $nextIdStmt = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 FROM quiz_attempts FOR UPDATE");
        $nextId = (int)$nextIdStmt->fetchColumn();

        $stmt = $conn->prepare("
            INSERT INTO quiz_attempts
            (
                id,
                user_id,
                course_id,
                enrollment_id,
                quiz_id,
                attempt_number,
                total_questions,
                correct_answers,
                score,
                total_points,
                percentage,
                passed,
                status,
                started_at,
                completed_at,
                answers_json
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW(), ?)
        ");
        $stmt->execute([
            $nextId,
            $userId,
            $attemptCourseId,
            $attemptEnrollmentId,
            $quizId,
            $attemptNumber,
            $totalQuestions,
            $correctCount,
            $earnedPoints,
            $totalPoints,
            $percentage,
            $passed ? 1 : 0,
            json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
        $savedAttemptId = $nextId > 0 ? $nextId : (int)$conn->lastInsertId();
    }

    $conn->commit();
    quizLog("Attempt saved successfully. ID: {$savedAttemptId}, attempt_number: {$attemptNumber}, course_id=" . ($attemptCourseId ?? 'NULL') . ", enrollment_id=" . ($attemptEnrollmentId ?? 'NULL'));

    // ========== ACADEMY GRADE BOOK SYNC ==========
    if (!empty($quiz['course_id']) && !empty($quiz['division_id']) && !empty($quiz['subject_id'])) {
        try {
            require_once __DIR__ . '/../includes/course_grades.php';

            if (function_exists('academyRecordScheduled')) {
                $academyResult = academyRecordScheduled(
                    $conn,
                    $userId,
                    'quiz',
                    (int)$quizId,
                    (float)$earnedPoints,
                    (float)$totalPoints
                );

                if ($academyResult === null) {
                    quizLog("Academy grade sync SKIPPED: quiz {$quizId} not linked to a course schedule.");
                } elseif (empty($academyResult['ok'])) {
                    quizLog("Academy grade sync REJECTED: " . ($academyResult['reason'] ?? 'unknown'));
                } else {
                    quizLog("Academy grade sync OK: " . json_encode($academyResult));
                }
            } else {
                quizLog("Academy grade sync SKIPPED: academyRecordScheduled() not defined in ../includes/course_grades.php");
            }
        } catch (Throwable $e) {
            quizLog("Academy grade sync FAILED: " . $e->getMessage());
        }
    }
    // ========== /ACADEMY GRADE BOOK SYNC ==========

    // ========== Leaderboard & milestone notifications ==========
    $leaderboardMode = $quizMode === 'self' ? 'lesson' : $quizMode;

    $oldRankStmt = $conn->prepare("SELECT rank_position FROM leaderboard_stats WHERE user_id = ? AND mode = ?");
    $oldRankStmt->execute([$userId, $leaderboardMode]);
    $oldRank = $oldRankStmt->fetchColumn();
    $oldRank = $oldRank !== false ? (int)$oldRank : null;

    updateQuizLeaderboardStats($conn, $userId, $leaderboardMode);

    $newRankStmt = $conn->prepare("SELECT rank_position FROM leaderboard_stats WHERE user_id = ? AND mode = ?");
    $newRankStmt->execute([$userId, $leaderboardMode]);
    $newRank = (int)$newRankStmt->fetchColumn();

    if ($newRank > 0) {
        notifyLeaderboardAchievement($conn, $userId, $leaderboardMode, $newRank, $oldRank);
        $_SESSION['last_rank_' . $leaderboardMode] = $newRank;
    }

    $totalCompletedStmt = $conn->prepare("
        SELECT SUM(quizzes_completed)
        FROM leaderboard_stats
        WHERE user_id = ?
    ");
    $totalCompletedStmt->execute([$userId]);
    $totalQuizzesCompleted = (int)$totalCompletedStmt->fetchColumn();
    notifyProgressMilestone($conn, $userId, $totalQuizzesCompleted, 'quiz');

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $errorMsg = $e->getMessage();
    quizLog("Attempt save error: {$errorMsg}");

    if (strpos($errorMsg, 'Duplicate entry') !== false && strpos($errorMsg, 'uq_quiz_attempt_user_quiz_attemptno') !== false) {
        respond([
            'success' => false,
            'message' => 'This attempt has already been submitted. Please refresh the page.'
        ], 409);
    }

    respond([
        'success' => false,
        'message' => 'Grading successful but attempt could not be saved. DB Error: ' . $errorMsg
    ], 500);
}

// -----------------------------------------------------------------------------
// Return success
// -----------------------------------------------------------------------------
respond([
    'success'         => true,
    'attempted'       => true,
    'locked'          => ($quizMode !== 'self'),
    'passed'          => $passed,
    'score'           => $earnedPoints,
    'total'           => $totalPoints,
    'percentage'      => $percentage,
    'correct_count'   => $correctCount,
    'total_questions' => $totalQuestions,
    'attempt_number'  => $attemptNumber,
    'results'         => $results
]);