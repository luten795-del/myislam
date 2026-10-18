<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';


function quizE($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function requireUserAuth(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
        header('Location: ../login.php?error=login_required');
        exit;
    }

    return (int)$_SESSION['user_id'];
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

/**
 * Ensure quiz_attempts carries course_id and enrollment_id so attempt lookups
 * can be scoped per course. Runs once per request; ALTERs only if needed.
 */
function ensureQuizAttemptsColumns(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (!tableExists($conn, 'quiz_attempts')) {
        return;
    }

    try {
        if (!columnExists($conn, 'quiz_attempts', 'course_id')) {
            $conn->exec("ALTER TABLE `quiz_attempts`
                         ADD COLUMN `course_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`,
                         ADD INDEX `idx_quiz_attempts_course` (`course_id`)");
            // bust the columnExists cache so later calls see the new column
            $ref = new ReflectionFunction('columnExists');
            // (columnExists uses a static cache; we can't reset it directly,
            //  but the ADD COLUMN above only runs once thanks to $done).
        }
        if (!columnExists($conn, 'quiz_attempts', 'enrollment_id')) {
            $conn->exec("ALTER TABLE `quiz_attempts`
                         ADD COLUMN `enrollment_id` INT UNSIGNED DEFAULT NULL AFTER `course_id`,
                         ADD INDEX `idx_quiz_attempts_enrollment` (`enrollment_id`)");
        }
    } catch (PDOException $e) {
        error_log('ensureQuizAttemptsColumns: ' . $e->getMessage());
    }
}

/**
 * Resolve which (course_id, enrollment_id) this quiz attempt belongs to.
 *
 * Prefers quizzes.course_id when the quiz is course-scoped.
 * Falls back to any active enrollment for the user when quizzes.course_id
 * is NULL (legacy non-course quizzes) so legacy attempts still resolve a scope.
 *
 * Returns ['course_id' => ?int, 'enrollment_id' => ?int].
 */
function resolveQuizAttemptScope(PDO $conn, int $quizId, int $userId): array
{
    $courseId     = 0;
    $enrollmentId = 0;

    // 1. Ask the quiz what course it belongs to.
    try {
        if (columnExists($conn, 'quizzes', 'course_id')) {
            $stmt = $conn->prepare("SELECT course_id FROM quizzes WHERE id = ? LIMIT 1");
            $stmt->execute([$quizId]);
            $courseId = (int)$stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('resolveQuizAttemptScope course lookup: ' . $e->getMessage());
    }

    // 2. Resolve enrollment for that course if we have one.
    if ($courseId > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT id FROM course_enrollments
                WHERE user_id = ? AND course_id = ? AND status IN ('active','completed')
                ORDER BY started_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $courseId]);
            $enrollmentId = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('resolveQuizAttemptScope enrollment lookup: ' . $e->getMessage());
        }
    }

    return [
        'course_id'     => $courseId > 0 ? $courseId : null,
        'enrollment_id' => $enrollmentId > 0 ? $enrollmentId : null,
    ];
}

function pickOptionTable(PDO $conn): string
{
    return 'quiz_options';
}

function getQuizMode(array $quiz): string
{
    $possibleColumns = ['mode', 'quiz_mode', 'lesson_mode', 'status_mode'];

    foreach ($possibleColumns as $column) {
        if (array_key_exists($column, $quiz)) {
            $value = strtolower(trim((string)$quiz[$column]));
            if (in_array($value, ['lesson', 'competition', 'self'], true)) {
                return $value;
            }
        }
    }

    return 'lesson';
}

function getCompetitionWindow(array $quiz): ?array
{
    $tz = new DateTimeZone('Africa/Dar_es_Salaam');

    if (!empty($quiz['competition_start_at']) && !empty($quiz['competition_end_at'])) {
        $start = new DateTime((string)$quiz['competition_start_at'], $tz);
        $end   = new DateTime((string)$quiz['competition_end_at'], $tz);

        return [
            'start'  => $start,
            'end'    => $end,
            'label'  => $start->format('M d, Y H:i') . ' - ' . $end->format('M d, Y H:i'),
            'source' => 'explicit',
        ];
    }

    if (!empty($quiz['published_at']) && strtotime((string)$quiz['published_at']) !== false) {
        $published = new DateTime((string)$quiz['published_at'], $tz);
        $weekStart = (clone $published)->modify('monday this week')->setTime(0, 0, 0);
        $weekEnd   = (clone $published)->modify('sunday this week')->setTime(23, 59, 59);

        return [
            'start'  => $weekStart,
            'end'    => $weekEnd,
            'label'  => $weekStart->format('M d, Y') . ' - ' . $weekEnd->format('M d, Y'),
            'source' => 'published_at',
        ];
    }

    return null;
}

function isCompetitionOpen(array $quiz): bool
{
    if (getQuizMode($quiz) !== 'competition') {
        return true;
    }

    $window = getCompetitionWindow($quiz);
    if (!$window) {
        return true;
    }

    $now = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));
    return $now >= $window['start'] && $now <= $window['end'];
}

/**
 * Count attempts in the competition window.
 * Scoped by course_id when provided, tolerant of legacy NULL rows.
 */
function getCompetitionAttemptsInWindow(
    PDO $conn,
    int $quizId,
    int $userId,
    array $quiz,
    ?int $excludeAttemptId = null,
    ?int $courseId = null
): int {
    $window = getCompetitionWindow($quiz);
    if (!$window) {
        return 0;
    }

    $timeColumn = columnExists($conn, 'quiz_attempts', 'created_at')
        ? 'created_at'
        : (columnExists($conn, 'quiz_attempts', 'started_at') ? 'started_at' : null);

    if ($timeColumn === null) {
        return 0;
    }

    try {
        $sql = "
            SELECT COUNT(*)
            FROM quiz_attempts
            WHERE quiz_id = ?
              AND user_id = ?
              AND {$timeColumn} >= ?
              AND {$timeColumn} <= ?
        ";

        $params = [
            $quizId,
            $userId,
            $window['start']->format('Y-m-d H:i:s'),
            $window['end']->format('Y-m-d H:i:s'),
        ];

        if ($courseId !== null && $courseId > 0) {
            $sql .= " AND (course_id = ? OR course_id IS NULL) ";
            $params[] = $courseId;
        }

        if ($excludeAttemptId !== null && $excludeAttemptId > 0) {
            $sql .= " AND id <> ? ";
            $params[] = $excludeAttemptId;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('getCompetitionAttemptsInWindow error: ' . $e->getMessage());
        return 0;
    }
}

function getQuizForAttempt(PDO $conn, int $quizId): ?array
{
    $select = [
        'q.id',
        'q.title',
        'q.slug',
        'q.description',
        'q.subject_id',
        'q.difficulty',
        'q.time_limit_minutes',
        'q.total_points',
        'q.passing_score',
        'q.status',
        's.name AS subject_name'
    ];

    if (columnExists($conn, 'quizzes', 'course_id')) {
        $select[] = 'q.course_id';
    } else {
        $select[] = 'NULL AS course_id';
    }

    if (columnExists($conn, 'quizzes', 'max_attempts')) {
        $select[] = 'q.max_attempts';
    } else {
        $select[] = '1 AS max_attempts';
    }

    if (columnExists($conn, 'quizzes', 'unlimited_attempts')) {
        $select[] = 'q.unlimited_attempts';
    } else {
        $select[] = '0 AS unlimited_attempts';
    }

    if (columnExists($conn, 'quizzes', 'category_id')) {
        $select[] = 'q.category_id';
    } else {
        $select[] = 'NULL AS category_id';
    }

    if (columnExists($conn, 'quizzes', 'published_at')) {
        $select[] = 'q.published_at';
    } else {
        $select[] = 'NULL AS published_at';
    }

    if (columnExists($conn, 'quizzes', 'competition_start_at')) {
        $select[] = 'q.competition_start_at';
    } else {
        $select[] = 'NULL AS competition_start_at';
    }

    if (columnExists($conn, 'quizzes', 'competition_end_at')) {
        $select[] = 'q.competition_end_at';
    } else {
        $select[] = 'NULL AS competition_end_at';
    }

    if (columnExists($conn, 'quizzes', 'mode')) {
        $select[] = 'q.mode';
    } elseif (columnExists($conn, 'quizzes', 'quiz_mode')) {
        $select[] = 'q.quiz_mode';
    } elseif (columnExists($conn, 'quizzes', 'lesson_mode')) {
        $select[] = 'q.lesson_mode';
    } elseif (columnExists($conn, 'quizzes', 'status_mode')) {
        $select[] = 'q.status_mode';
    } else {
        $select[] = "'lesson' AS mode";
    }

    $joins = [
        'LEFT JOIN subjects s ON s.id = q.subject_id'
    ];

    if (tableExists($conn, 'categories') && columnExists($conn, 'quizzes', 'category_id')) {
        $select[] = 'c.name AS category_name';
        $joins[] = 'LEFT JOIN categories c ON c.id = q.category_id';
    } else {
        $select[] = 'NULL AS category_name';
    }

    $sql = "
        SELECT
            " . implode(",\n            ", $select) . "
        FROM quizzes q
        " . implode("\n        ", $joins) . "
        WHERE q.id = ?
          AND q.status = 'published'
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$quizId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getQuizQuestionsWithOptions(PDO $conn, int $quizId): array
{
    $questionSelect = [
        'id',
        'quiz_id',
        'question_text'
    ];

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'question_type')
        ? 'question_type'
        : "'multiple_choice' AS question_type";

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'points')
        ? 'points'
        : '1 AS points';

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'negative_points')
        ? 'negative_points'
        : '0 AS negative_points';

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'difficulty')
        ? 'difficulty'
        : "'medium' AS difficulty";

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'explanation')
        ? 'explanation'
        : 'NULL AS explanation';

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'image_path')
        ? 'image_path'
        : 'NULL AS image_path';

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'sort_order')
        ? 'sort_order'
        : '0 AS sort_order';

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'expected_answer')
        ? 'expected_answer'
        : 'NULL AS expected_answer';

    $questionSelect[] = columnExists($conn, 'quiz_questions', 'keywords_json')
        ? 'keywords_json'
        : 'NULL AS keywords_json';

    $questionOrder = columnExists($conn, 'quiz_questions', 'sort_order')
        ? 'ORDER BY sort_order ASC, id ASC'
        : 'ORDER BY id ASC';

    $questionSql = "
        SELECT
            " . implode(",\n            ", $questionSelect) . "
        FROM quiz_questions
        WHERE quiz_id = ?
        $questionOrder
    ";

    $stmt = $conn->prepare($questionSql);
    $stmt->execute([$quizId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$questions) {
        return [];
    }

    $optionTable = pickOptionTable($conn);

    $optionSelect = [
        'id',
        'question_id'
    ];

    $optionSelect[] = columnExists($conn, $optionTable, 'option_text')
        ? 'option_text'
        : "'' AS option_text";

    $optionSelect[] = columnExists($conn, $optionTable, 'image_path')
        ? 'image_path'
        : 'NULL AS image_path';

    $optionSelect[] = columnExists($conn, $optionTable, 'is_correct')
        ? 'is_correct'
        : '0 AS is_correct';

    $optionSelect[] = columnExists($conn, $optionTable, 'sort_order')
        ? 'sort_order'
        : '0 AS sort_order';

    $optionOrder = columnExists($conn, $optionTable, 'sort_order')
        ? 'ORDER BY sort_order ASC, id ASC'
        : 'ORDER BY id ASC';

    $optionSql = "
        SELECT
            " . implode(",\n            ", $optionSelect) . "
        FROM {$optionTable}
        WHERE question_id = ?
        $optionOrder
    ";

    $opt = $conn->prepare($optionSql);

    foreach ($questions as &$question) {
        $opt->execute([(int)$question['id']]);
        $options = $opt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $question['options'] = $options;

        $optionMap = [];
        foreach ($options as $optRow) {
            $optionMap[(int)$optRow['id']] = $optRow;
        }
        $question['option_map'] = $optionMap;
    }
    unset($question);

    return $questions;
}

/**
 * Count completed attempts for a quiz.
 * Scoped by course_id when provided, tolerant of legacy NULL rows.
 */
function getAttemptCount(PDO $conn, int $quizId, int $userId, ?int $courseId = null): int
{
    if ($courseId !== null && $courseId > 0) {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM quiz_attempts
            WHERE quiz_id = ? AND user_id = ? AND status = 'completed'
              AND (course_id = ? OR course_id IS NULL)
        ");
        $stmt->execute([$quizId, $userId, $courseId]);
    } else {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM quiz_attempts
            WHERE quiz_id = ? AND user_id = ? AND status = 'completed'
        ");
        $stmt->execute([$quizId, $userId]);
    }
    return (int)$stmt->fetchColumn();
}

/**
 * Most recent open attempt for a quiz.
 * Scoped by course_id when provided, tolerant of legacy NULL rows.
 */
function getOpenAttempt(PDO $conn, int $quizId, int $userId, ?int $courseId = null): ?array
{
    if ($courseId !== null && $courseId > 0) {
        $stmt = $conn->prepare("
            SELECT *
            FROM quiz_attempts
            WHERE quiz_id = ? AND user_id = ? AND status = 'started'
              AND (course_id = ? OR course_id IS NULL)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$quizId, $userId, $courseId]);
    } else {
        $stmt = $conn->prepare("
            SELECT *
            FROM quiz_attempts
            WHERE quiz_id = ? AND user_id = ? AND status = 'started'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$quizId, $userId]);
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create a new attempt row.
 * Populates course_id / enrollment_id resolved from the quiz's course.
 */
function createQuizAttempt(PDO $conn, int $quizId, int $userId): int
{
    ensureQuizAttemptsColumns($conn);

    $quiz = getQuizForAttempt($conn, $quizId);
    if (!$quiz) {
        throw new RuntimeException('Quiz not found or not published.');
    }

    // Resolve scope once for the whole function.
    $scope        = resolveQuizAttemptScope($conn, $quizId, $userId);
    $attemptCourseId     = $scope['course_id'];
    $attemptEnrollmentId = $scope['enrollment_id'];

    $quizMode    = getQuizMode($quiz);
    $maxAttempts = (int)($quiz['max_attempts'] ?? 1);
    $unlimited   = (bool)($quiz['unlimited_attempts'] ?? false);
    $totalPoints = (int)($quiz['total_points'] ?? 0);

    if ($quizMode === 'competition') {
        $window = getCompetitionWindow($quiz);
        $now = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));

        if ($window && ($now < $window['start'] || $now > $window['end'])) {
            throw new RuntimeException(
                'This competition is not active right now. Window: ' . $window['label'] .
                ' | now: ' . $now->format('Y-m-d H:i')
            );
        }

        $used = getCompetitionAttemptsInWindow($conn, $quizId, $userId, $quiz, null, $attemptCourseId);
        if ($used >= 1) {
            throw new RuntimeException('You have already attempted this competition in this window.');
        }
    }

    if ($quizMode === 'lesson') {
        $completedCount = getAttemptCount($conn, $quizId, $userId, $attemptCourseId);
        if ($completedCount >= 1) {
            throw new RuntimeException('You have already completed this lesson quiz.');
        }
    }

    if ($quizMode === 'self' && !$unlimited && $maxAttempts > 0) {
        $completedCount = getAttemptCount($conn, $quizId, $userId, $attemptCourseId);
        if ($completedCount >= $maxAttempts) {
            throw new RuntimeException('You have reached the maximum attempts (' . $maxAttempts . ').');
        }
    }

    $existing = getOpenAttempt($conn, $quizId, $userId, $attemptCourseId);
    if ($existing) {
        return (int)$existing['id'];
    }

    $attemptNumber = 1;
    if ($quizMode === 'self') {
        if ($attemptCourseId !== null && $attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT COALESCE(MAX(attempt_number), 0) + 1
                FROM quiz_attempts
                WHERE user_id = ? AND quiz_id = ? AND (course_id = ? OR course_id IS NULL)
            ");
            $stmt->execute([$userId, $quizId, $attemptCourseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT COALESCE(MAX(attempt_number), 0) + 1
                FROM quiz_attempts
                WHERE user_id = ? AND quiz_id = ?
            ");
            $stmt->execute([$userId, $quizId]);
        }
        $attemptNumber = (int)$stmt->fetchColumn();
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO quiz_attempts
            (user_id, course_id, enrollment_id, quiz_id, attempt_number, total_points, status, started_at)
            VALUES (?, ?, ?, ?, ?, ?, 'started', NOW())
        ");
        $stmt->execute([
            $userId,
            $attemptCourseId,
            $attemptEnrollmentId,
            $quizId,
            $attemptNumber,
            $totalPoints,
        ]);
        return (int)$conn->lastInsertId();
    } catch (PDOException $e) {
        error_log("createQuizAttempt error: " . $e->getMessage());
        throw new RuntimeException('Failed to create attempt: ' . $e->getMessage());
    }
}

function getAttemptById(PDO $conn, int $attemptId, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM quiz_attempts
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$attemptId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function saveAttemptAnswer(PDO $conn, int $attemptId, int $questionId, ?int $selectedOptionId): void
{
    $hasAnsweredAt = columnExists($conn, 'quiz_attempt_answers', 'answered_at');

    if ($hasAnsweredAt) {
        $sql = "
            INSERT INTO quiz_attempt_answers
            (attempt_id, question_id, selected_option_id, is_correct, points_earned, answered_at)
            VALUES (?, ?, ?, 0, 0, NOW())
            ON DUPLICATE KEY UPDATE
                selected_option_id = VALUES(selected_option_id),
                answered_at = NOW()
        ";
    } else {
        $sql = "
            INSERT INTO quiz_attempt_answers
            (attempt_id, question_id, selected_option_id, is_correct, points_earned)
            VALUES (?, ?, ?, 0, 0)
            ON DUPLICATE KEY UPDATE
                selected_option_id = VALUES(selected_option_id)
        ";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([$attemptId, $questionId, $selectedOptionId]);
}

function updateLeaderboardStats(
    PDO $conn,
    int $userId,
    string $mode,
    float $score,
    int $correctAnswers,
    int $wrongAnswers,
    int $durationSeconds
): void {
    if (!tableExists($conn, 'leaderboard_stats')) {
        return;
    }

    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['lesson', 'competition', 'self'], true)) {
        $mode = 'lesson';
    }

    $hasMode = columnExists($conn, 'leaderboard_stats', 'mode');

    if ($hasMode) {
        $sql = "
            INSERT INTO leaderboard_stats (
                user_id, mode, total_points, quizzes_taken, quizzes_completed,
                total_correct_answers, total_wrong_answers, average_score, best_score,
                total_time_seconds, updated_at
            )
            VALUES (?, ?, ?, 1, 1, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_points = total_points + VALUES(total_points),
                quizzes_taken = quizzes_taken + 1,
                quizzes_completed = quizzes_completed + 1,
                total_correct_answers = total_correct_answers + VALUES(total_correct_answers),
                total_wrong_answers = total_wrong_answers + VALUES(total_wrong_answers),
                total_time_seconds = total_time_seconds + VALUES(total_time_seconds),
                best_score = GREATEST(best_score, VALUES(best_score)),
                average_score = ROUND((total_points + VALUES(total_points)) / NULLIF(quizzes_completed + 1, 0), 2),
                updated_at = NOW()
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $userId, $mode, $score, $correctAnswers, $wrongAnswers,
            $score, $score, $durationSeconds
        ]);
    } else {
        $sql = "
            INSERT INTO leaderboard_stats (
                user_id, total_points, quizzes_taken, quizzes_completed,
                total_correct_answers, total_wrong_answers, average_score, best_score,
                total_time_seconds, updated_at
            )
            VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_points = total_points + VALUES(total_points),
                quizzes_taken = quizzes_taken + 1,
                quizzes_completed = quizzes_completed + 1,
                total_correct_answers = total_correct_answers + VALUES(total_correct_answers),
                total_wrong_answers = total_wrong_answers + VALUES(total_wrong_answers),
                total_time_seconds = total_time_seconds + VALUES(total_time_seconds),
                best_score = GREATEST(best_score, VALUES(best_score)),
                average_score = ROUND((total_points + VALUES(total_points)) / NULLIF(quizzes_completed + 1, 0), 2),
                updated_at = NOW()
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $userId, $score, $correctAnswers, $wrongAnswers,
            $score, $score, $durationSeconds
        ]);
    }
}

function syncLeaderboardRankings(PDO $conn, ?string $mode = null): void
{
    if (!tableExists($conn, 'leaderboard_stats') || !columnExists($conn, 'leaderboard_stats', 'rank_position')) {
        return;
    }

    $hasMode = columnExists($conn, 'leaderboard_stats', 'mode');

    if ($hasMode && $mode !== null) {
        $stmt = $conn->prepare("
            SELECT id
            FROM leaderboard_stats
            WHERE mode = ?
            ORDER BY total_points DESC, best_score DESC, quizzes_completed DESC, total_time_seconds ASC, updated_at ASC
        ");
        $stmt->execute([$mode]);
    } else {
        $stmt = $conn->query("
            SELECT id
            FROM leaderboard_stats
            ORDER BY total_points DESC, best_score DESC, quizzes_completed DESC, total_time_seconds ASC, updated_at ASC
        ");
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }

    $rank = 1;
    $upd = $conn->prepare("UPDATE leaderboard_stats SET rank_position = ? WHERE id = ?");

    foreach ($rows as $row) {
        $upd->execute([$rank, (int)$row['id']]);
        $rank++;
    }
}

function gradeAttempt(PDO $conn, int $attemptId, int $userId): ?array
{
    $attempt = getAttemptById($conn, $attemptId, $userId);
    if (!$attempt) {
        return null;
    }

    if (isset($attempt['status']) && $attempt['status'] !== 'started') {
        return null;
    }

    $conn->beginTransaction();

    try {
        $quizId = (int)$attempt['quiz_id'];
        $quiz = getQuizForAttempt($conn, $quizId);

        if (!$quiz) {
            throw new RuntimeException('Quiz not found or not published.');
        }

        $quizMode = getQuizMode($quiz);

        // Attempt's own course scope — used to scope the competition check.
        $attemptCourseId = (int)($attempt['course_id'] ?? 0);

        $competitionAttempts = 0;

        if ($quizMode === 'competition') {
            $window = getCompetitionWindow($quiz);

            if ($window) {
                $attemptStartRaw = $attempt['started_at'] ?? $attempt['created_at'] ?? null;

                if ($attemptStartRaw === null) {
                    throw new RuntimeException('This attempt has no start timestamp and cannot be validated.');
                }

                $attemptStart = new DateTime((string)$attemptStartRaw, new DateTimeZone('Africa/Dar_es_Salaam'));

                if ($attemptStart < $window['start'] || $attemptStart > $window['end']) {
                    throw new RuntimeException(
                        'This attempt was started outside the competition window and cannot be submitted. ' .
                        'Attempt started: ' . $attemptStart->format('Y-m-d H:i') .
                        ' | Window: ' . $window['label']
                    );
                }
            }

            $competitionAttempts = getCompetitionAttemptsInWindow(
                $conn, $quizId, $userId, $quiz, $attemptId,
                $attemptCourseId > 0 ? $attemptCourseId : null
            );
            if ($competitionAttempts >= 1) {
                throw new RuntimeException('You have already submitted an attempt for this competition in this window.');
            }
        }

        $questions = getQuizQuestionsWithOptions($conn, $quizId);
        $optionTable = pickOptionTable($conn);

        $score = 0;
        $correctAnswers = 0;
        $wrongAnswers = 0;
        $totalQuestions = count($questions);

        foreach ($questions as $question) {
            $questionId = (int)$question['id'];
            $points = (int)($question['points'] ?? 1);
            $negativePoints = (int)($question['negative_points'] ?? 0);

            $stmt = $conn->prepare("
                SELECT id, selected_option_id
                FROM quiz_attempt_answers
                WHERE attempt_id = ? AND question_id = ?
                LIMIT 1
            ");
            $stmt->execute([$attemptId, $questionId]);
            $answer = $stmt->fetch(PDO::FETCH_ASSOC);

            $selectedOptionId = $answer['selected_option_id'] ?? null;
            $isCorrect = 0;
            $pointsEarned = 0;

            if ($selectedOptionId !== null) {
                $opt = $conn->prepare("
                    SELECT is_correct
                    FROM {$optionTable}
                    WHERE id = ? AND question_id = ?
                    LIMIT 1
                ");
                $opt->execute([(int)$selectedOptionId, $questionId]);
                $option = $opt->fetch(PDO::FETCH_ASSOC);

                if ($option && (int)$option['is_correct'] === 1) {
                    $isCorrect = 1;
                    $pointsEarned = $points;
                    $correctAnswers++;
                } else {
                    $pointsEarned = -$negativePoints;
                    $wrongAnswers++;
                }
            }

            $score += $pointsEarned;

            if ($answer) {
                $upd = $conn->prepare("
                    UPDATE quiz_attempt_answers
                    SET is_correct = ?, points_earned = ?
                    WHERE id = ?
                ");
                $upd->execute([$isCorrect, $pointsEarned, (int)$answer['id']]);
            } else {
                if (columnExists($conn, 'quiz_attempt_answers', 'answered_at')) {
                    $ins = $conn->prepare("
                        INSERT INTO quiz_attempt_answers
                        (attempt_id, question_id, selected_option_id, is_correct, points_earned, answered_at)
                        VALUES (?, ?, NULL, 0, 0, NOW())
                    ");
                } else {
                    $ins = $conn->prepare("
                        INSERT INTO quiz_attempt_answers
                        (attempt_id, question_id, selected_option_id, is_correct, points_earned)
                        VALUES (?, ?, NULL, 0, 0)
                    ");
                }
                $ins->execute([$attemptId, $questionId]);
            }
        }

        $updateParts = [];
        $updateParams = [];

        if (columnExists($conn, 'quiz_attempts', 'completed_at')) {
            $updateParts[] = "completed_at = NOW()";
        } elseif (columnExists($conn, 'quiz_attempts', 'submitted_at')) {
            $updateParts[] = "submitted_at = NOW()";
        }

        $durationSeconds = 0;

        if (columnExists($conn, 'quiz_attempts', 'duration_seconds') && !empty($attempt['started_at'])) {
            $startedAt = new DateTime((string)$attempt['started_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
            $now = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));
            $durationSeconds = max(0, $now->getTimestamp() - $startedAt->getTimestamp());
            $updateParts[] = "duration_seconds = ?";
            $updateParams[] = $durationSeconds;
        } elseif (columnExists($conn, 'quiz_attempts', 'time_spent_seconds') && !empty($attempt['started_at'])) {
            $startedAt = new DateTime((string)$attempt['started_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
            $now = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));
            $durationSeconds = max(0, $now->getTimestamp() - $startedAt->getTimestamp());
            $updateParts[] = "time_spent_seconds = ?";
            $updateParams[] = $durationSeconds;
        }

        if (columnExists($conn, 'quiz_attempts', 'score')) {
            $updateParts[] = "score = ?";
            $updateParams[] = $score;
        }

        if (columnExists($conn, 'quiz_attempts', 'total_questions')) {
            $updateParts[] = "total_questions = ?";
            $updateParams[] = $totalQuestions;
        }

        if (columnExists($conn, 'quiz_attempts', 'correct_answers')) {
            $updateParts[] = "correct_answers = ?";
            $updateParams[] = $correctAnswers;
        }

        if (columnExists($conn, 'quiz_attempts', 'wrong_answers')) {
            $updateParts[] = "wrong_answers = ?";
            $updateParams[] = $wrongAnswers;
        }

        if (columnExists($conn, 'quiz_attempts', 'status')) {
            $updateParts[] = "status = ?";
            $updateParams[] = 'completed';
        }

        if (columnExists($conn, 'quiz_attempts', 'mode')) {
            $updateParts[] = "mode = ?";
            $updateParams[] = $quizMode;
        }

        if (!empty($updateParts)) {
            $updateParams[] = $attemptId;
            $stmt = $conn->prepare("
                UPDATE quiz_attempts
                SET " . implode(', ', $updateParts) . "
                WHERE id = ?
            ");
            $stmt->execute($updateParams);
        }

        if (tableExists($conn, 'quiz_leaderboard')) {
            $leaderboardColumns = ['quiz_id', 'user_id'];
            $leaderboardValues = ['?', '?'];
            $leaderboardParams = [$quizId, $userId];
            $updates = [];

            if (columnExists($conn, 'quiz_leaderboard', 'best_score')) {
                $leaderboardColumns[] = 'best_score';
                $leaderboardValues[] = '?';
                $leaderboardParams[] = $score;
                $updates[] = 'best_score = GREATEST(best_score, VALUES(best_score))';
            }

            if (columnExists($conn, 'quiz_leaderboard', 'attempts')) {
                $leaderboardColumns[] = 'attempts';
                $leaderboardValues[] = '1';
                $updates[] = 'attempts = attempts + 1';
            }

            if (columnExists($conn, 'quiz_leaderboard', 'last_attempt_at')) {
                $leaderboardColumns[] = 'last_attempt_at';
                $leaderboardValues[] = 'NOW()';
                $updates[] = 'last_attempt_at = NOW()';
            }

            if (columnExists($conn, 'quiz_leaderboard', 'mode')) {
                $leaderboardColumns[] = 'mode';
                $leaderboardValues[] = '?';
                $leaderboardParams[] = $quizMode;
                $updates[] = 'mode = VALUES(mode)';
            }

            if (!empty($updates)) {
                $lb = $conn->prepare("
                    INSERT INTO quiz_leaderboard
                    (" . implode(', ', $leaderboardColumns) . ")
                    VALUES (" . implode(', ', $leaderboardValues) . ")
                    ON DUPLICATE KEY UPDATE
                    " . implode(', ', $updates) . "
                ");
                $lb->execute($leaderboardParams);
            }
        }

        updateLeaderboardStats(
            $conn,
            $userId,
            $quizMode,
            (float)$score,
            $correctAnswers,
            $wrongAnswers,
            (int)$durationSeconds
        );

        syncLeaderboardRankings($conn, $quizMode);

        $conn->commit();

        return [
            'score' => $score,
            'total_questions' => $totalQuestions,
            'correct_answers' => $correctAnswers,
            'wrong_answers' => $wrongAnswers,
            'duration_seconds' => $durationSeconds ?? 0,
            'mode' => $quizMode,
            'competition_attempts_in_window' => $competitionAttempts,
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function getAttemptReview(PDO $conn, int $attemptId, int $userId): ?array
{
    $attempt = getAttemptById($conn, $attemptId, $userId);
    if (!$attempt) {
        return null;
    }

    $quiz = getQuizForAttempt($conn, (int)$attempt['quiz_id']);
    if (!$quiz) {
        return null;
    }

    $questions = getQuizQuestionsWithOptions($conn, (int)$attempt['quiz_id']);

    foreach ($questions as &$question) {
        $stmt = $conn->prepare("
            SELECT selected_option_id, is_correct, points_earned
            FROM quiz_attempt_answers
            WHERE attempt_id = ? AND question_id = ?
            LIMIT 1
        ");
        $stmt->execute([$attemptId, (int)$question['id']]);
        $question['attempt_answer'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    unset($question);

    return [
        'attempt' => $attempt,
        'quiz' => $quiz,
        'questions' => $questions,
        'mode' => getQuizMode($quiz),
        'competition_window' => getCompetitionWindow($quiz),
    ];
}