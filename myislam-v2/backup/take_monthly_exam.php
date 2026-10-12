<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$conn = db();

$examId = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$exam = $conn->prepare("SELECT * FROM monthly_exams WHERE id = ? AND status = 'published'");
$exam->execute([$examId]);
$exam = $exam->fetch();
if (!$exam) die('Exam not available.');

// Determine exam window status
$now = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));
$startDate = $exam['start_date'];
$endDate = $exam['end_date'];
$startDt = new DateTime($startDate, new DateTimeZone('Africa/Dar_es_Salaam'));
$endDt = new DateTime($endDate, new DateTimeZone('Africa/Dar_es_Salaam'));

$examActive = ($now >= $startDt && $now <= $endDt);
$examUpcoming = ($now < $startDt);
$examEnded = ($now > $endDt);

$windowLabel = date('M d, Y H:i', strtotime($startDate)) . ' - ' . date('M d, Y H:i', strtotime($endDate));

// If exam is upcoming, show countdown UI (unchanged)
if ($examUpcoming) {
    $backUrl = 'monthly_exams.php';
    $title = htmlspecialchars($exam['title'] ?? 'Monthly Exam');
    $description = htmlspecialchars($exam['description'] ?? '');
    $startTimestamp = $startDt->getTimestamp();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Exam Upcoming - MyIslam</title>
        <style>
            /* ... (same as before, omitted for brevity) ... */
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:system-ui,sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
            .card{max-width:600px;width:100%;background:white;border-radius:24px;padding:32px;text-align:center;box-shadow:0 10px 25px -5px rgba(0,0,0,0.1)}
            .status-icon{font-size:64px;margin-bottom:16px}
            h1{font-size:28px;margin-bottom:8px;color:#1f2937}
            .badge{display:inline-block;padding:6px 16px;border-radius:999px;font-weight:700;font-size:14px;margin-bottom:20px;background:#fef3c7;color:#92400e}
            .description{color:#4b5563;margin-bottom:24px;line-height:1.6}
            .window-box{background:#f3f4f6;border-radius:16px;padding:20px;margin-bottom:24px}
            .window-label{font-size:14px;text-transform:uppercase;color:#6b7280;margin-bottom:8px}
            .window-dates{font-size:18px;font-weight:700;color:#166534;margin-bottom:16px}
            .countdown-timer{font-size:32px;font-weight:800;color:#166534;margin:12px 0}
            .btn{display:inline-block;background:#166534;color:white;font-weight:700;padding:14px 28px;border-radius:999px;text-decoration:none}
            .btn:hover{background:#14532d}
        </style>
    </head>
    <body>
        <div class="card">
            <div class="status-icon">⏳</div>
            <h1><?= $title ?></h1>
            <span class="badge">Upcoming</span>
            <?php if ($description): ?>
                <p class="description"><?= $description ?></p>
            <?php endif; ?>
            <div class="window-box">
                <div class="window-label">Exam Window</div>
                <div class="window-dates"><?= htmlspecialchars($windowLabel) ?></div>
                <div class="countdown-timer" id="countdown">--:--:--</div>
                <p>Starts in</p>
            </div>
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn">← Back to Exams</a>
        </div>
        <script>
            const startTime = <?= $startTimestamp * 1000 ?>;
            const countdownEl = document.getElementById('countdown');
            function updateCountdown() {
                const diff = startTime - Date.now();
                if (diff <= 0) {
                    countdownEl.textContent = 'Starting soon!';
                    setTimeout(() => location.reload(), 2000);
                    return;
                }
                const days = Math.floor(diff / 86400000);
                const hours = Math.floor((diff % 86400000) / 3600000);
                const minutes = Math.floor((diff % 3600000) / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                let timeStr = days > 0 ? days + 'd ' : '';
                timeStr += String(hours).padStart(2,'0') + ':' + String(minutes).padStart(2,'0') + ':' + String(seconds).padStart(2,'0');
                countdownEl.textContent = timeStr;
            }
            updateCountdown();
            setInterval(updateCountdown, 1000);
        </script>
    </body>
    </html>
    <?php
    exit;
}

// If exam ended, redirect to result page (unchanged)
if ($examEnded) {
    $attemptCheck = $conn->prepare("SELECT id FROM monthly_exam_attempts WHERE user_id = ? AND exam_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1");
    $attemptCheck->execute([$userId, $examId]);
    $attemptId = $attemptCheck->fetchColumn();
    if ($attemptId) {
        header("Location: monthly_exam_result.php?attempt_id=" . $attemptId);
    } else {
        header("Location: monthly_exam_result.php?exam_id=" . $examId . "&ended=1");
    }
    exit;
}

// For active exams, check attempt limits
$completedAttempts = $conn->prepare("SELECT COUNT(*) FROM monthly_exam_attempts WHERE user_id = ? AND exam_id = ? AND status = 'completed'");
$completedAttempts->execute([$userId, $examId]);
$completedAttempts = (int)$completedAttempts->fetchColumn();

$terminatedAttempt = $conn->prepare("SELECT id FROM monthly_exam_attempts WHERE user_id = ? AND exam_id = ? AND status = 'terminated' LIMIT 1");
$terminatedAttempt->execute([$userId, $examId]);
$hasTerminated = (bool)$terminatedAttempt->fetchColumn();

if ($hasTerminated) {
    die('Your exam attempt was terminated due to violations. You cannot retake this exam.');
}

if ($completedAttempts >= $exam['max_attempts']) {
    die('You have reached maximum attempts for this exam.');
}

// Fetch questions – now includes keywords_json
$questions = $conn->prepare("
    SELECT 
        q.id, q.question_text, q.question_type, q.points, q.image_path, q.sort_order,
        e.expected_answer, e.explanation, e.keywords_json
    FROM monthly_exam_questions q
    LEFT JOIN monthly_exam_question_explanations e 
        ON q.id = e.question_id AND q.question_type = 'explanation'
    WHERE q.exam_id = ? 
    ORDER BY q.sort_order
");
$questions->execute([$examId]);
$questions = $questions->fetchAll();
if (empty($questions)) die('No questions.');

// Helper to get next attempt number
function getNextAttemptNumber(PDO $conn, int $userId, int $examId): int {
    $stmt = $conn->prepare("SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM monthly_exam_attempts WHERE user_id = ? AND exam_id = ?");
    $stmt->execute([$userId, $examId]);
    return (int)$stmt->fetchColumn();
}

// ========== ENHANCED EXPLANATION GRADING (same as quiz system) ==========
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
// ============================================================================

// Helper to update leaderboard stats for exam mode (same as before)
function updateExamLeaderboardStats(PDO $conn, int $userId): void {
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS exams_taken,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS exams_completed,
            SUM(score) AS total_points,
            SUM(correct_answers) AS total_correct,
            SUM(total_questions - correct_answers) AS total_wrong,
            AVG(percentage) AS average_score,
            MAX(percentage) AS best_score,
            SUM(TIMESTAMPDIFF(SECOND, started_at, completed_at)) AS total_time_seconds
        FROM monthly_exam_attempts
        WHERE user_id = ? AND status IN ('completed', 'abandoned')
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stats || $stats['exams_taken'] == 0) {
        return;
    }

    $upsert = $conn->prepare("
        INSERT INTO leaderboard_stats (
            user_id, mode, total_points, quizzes_taken, quizzes_completed,
            total_correct_answers, total_wrong_answers, average_score, best_score,
            total_time_seconds, updated_at
        ) VALUES (?, 'exam', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
        $userId,
        $stats['total_points'] ?? 0,
        $stats['exams_taken'] ?? 0,
        $stats['exams_completed'] ?? 0,
        $stats['total_correct'] ?? 0,
        $stats['total_wrong'] ?? 0,
        $stats['average_score'] ?? 0,
        $stats['best_score'] ?? 0,
        $stats['total_time_seconds'] ?? 0
    ]);

    $conn->exec("
        UPDATE leaderboard_stats ls
        JOIN (
            SELECT id,
                   RANK() OVER (ORDER BY total_points DESC, best_score DESC, quizzes_completed DESC, total_time_seconds ASC) AS rnk
            FROM leaderboard_stats
            WHERE mode = 'exam'
        ) ranked ON ls.id = ranked.id
        SET ls.rank_position = ranked.rnk
        WHERE ls.mode = 'exam'
    ");
}

function gradeAndFinalizeAttempt(PDO $conn, int $userId, int $examId, array $exam, array $questions, array $answers, int $startedAttemptId): void {
    $correctCount = 0;
    $totalPoints = 0;
    $earnedPoints = 0;
    $answersJson = [];

    foreach ($questions as $q) {
        $qid = $q['id'];
        $userAns = $answers[$qid] ?? null;
        $isCorrect = false;
        $points = (int)$q['points'];
        $totalPoints += $points;
        $feedback = '';

        if ($q['question_type'] === 'multiple_choice') {
            $opt = $conn->prepare("SELECT is_correct FROM monthly_exam_question_options WHERE id = ? AND question_id = ?");
            $opt->execute([$userAns, $qid]);
            $isCorrect = (bool)$opt->fetchColumn();
            $pointsEarned = $isCorrect ? $points : 0;
            $feedback = $isCorrect ? 'Correct!' : 'Incorrect.';
        } elseif ($q['question_type'] === 'true_false') {
            $corr = $conn->prepare("SELECT is_correct FROM monthly_exam_question_options WHERE question_id = ? AND option_text = ?");
            $corr->execute([$qid, $userAns]);
            $isCorrect = (bool)$corr->fetchColumn();
            $pointsEarned = $isCorrect ? $points : 0;
            $feedback = $isCorrect ? 'Correct!' : 'Incorrect.';
        } else {
            $expected = $q['expected_answer'] ?? '';
            $keywordsJson = $q['keywords_json'] ?? null;
            $keywords = $keywordsJson ? json_decode($keywordsJson, true) : [];
            $result = evaluateExplanationAnswer((string)$userAns, $expected, $points, $keywords, 0.6, 70.0);
            $isCorrect = $result['is_correct'];
            $pointsEarned = $result['points_earned'];
            $feedback = $result['feedback'];
        }

        if ($isCorrect) {
            $correctCount++;
            $earnedPoints += $pointsEarned;
        } else {
            $earnedPoints += $pointsEarned;
        }

        $answersJson[] = [
            'question_id'   => $qid,
            'question_text' => $q['question_text'],
            'user_answer'   => $userAns,
            'is_correct'    => $isCorrect,
            'points_earned' => $pointsEarned,
            'explanation'   => $q['explanation'] ?? null,
            'feedback'      => $feedback
        ];
    }

    $percentage = $totalPoints > 0 ? ($earnedPoints / $totalPoints) * 100 : 0;
    $passed = $percentage >= $exam['passing_score'];
    $attemptNumber = getNextAttemptNumber($conn, $userId, $examId);

    try {
        $conn->beginTransaction();

        $conn->prepare("UPDATE monthly_exam_attempts SET status = 'completed', completed_at = NOW() WHERE id = ?")
             ->execute([$startedAttemptId]);

        $stmt = $conn->prepare("
            INSERT INTO monthly_exam_attempts
            (user_id, exam_id, attempt_number, total_questions, correct_answers, score, total_points, percentage, passed, status, started_at, completed_at, answers_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW(), ?)
        ");
        $stmt->execute([
            $userId,
            $examId,
            $attemptNumber,
            count($questions),
            $correctCount,
            $earnedPoints,
            $totalPoints,
            $percentage,
            $passed ? 1 : 0,
            json_encode($answersJson)
        ]);
        $newAttemptId = $conn->lastInsertId();

        $conn->commit();

        // Leaderboard & milestone notifications
        $oldRankStmt = $conn->prepare("SELECT rank_position FROM leaderboard_stats WHERE user_id = ? AND mode = 'exam'");
        $oldRankStmt->execute([$userId]);
        $oldRank = $oldRankStmt->fetchColumn();
        $oldRank = $oldRank !== false ? (int)$oldRank : null;

        updateExamLeaderboardStats($conn, $userId);

        $newRankStmt = $conn->prepare("SELECT rank_position FROM leaderboard_stats WHERE user_id = ? AND mode = 'exam'");
        $newRankStmt->execute([$userId]);
        $newRank = (int)$newRankStmt->fetchColumn();

        if ($newRank > 0) {
            notifyLeaderboardAchievement($conn, $userId, 'exam', $newRank, $oldRank);
            $_SESSION['last_rank_exam'] = $newRank;
        }

        $totalCompletedStmt = $conn->prepare("
            SELECT COUNT(*) FROM monthly_exam_attempts
            WHERE user_id = ? AND status = 'completed'
        ");
        $totalCompletedStmt->execute([$userId]);
        $totalExamsCompleted = (int)$totalCompletedStmt->fetchColumn();
        notifyProgressMilestone($conn, $userId, $totalExamsCompleted, 'exam');

        header("Location: monthly_exam_result.php?attempt_id=" . $newAttemptId . "&time_expired=1");
        exit;
    } catch (PDOException $e) {
        $conn->rollBack();
        die("Database Error: " . $e->getMessage());
    }
}

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'abandon') {
        $stmt = $conn->prepare("UPDATE monthly_exam_attempts SET status = 'abandoned', completed_at = NOW() WHERE user_id = ? AND exam_id = ? AND status = 'started'");
        $stmt->execute([$userId, $examId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'terminate') {
        $answersJson = $_POST['answers_json'] ?? '[]';
        $attemptNumber = getNextAttemptNumber($conn, $userId, $examId);

        try {
            $stmt = $conn->prepare("
                INSERT INTO monthly_exam_attempts
                (user_id, exam_id, attempt_number, status, started_at, completed_at, answers_json, total_questions, correct_answers, score, total_points, percentage, passed)
                VALUES (?, ?, ?, 'terminated', NOW(), NOW(), ?, 0, 0, 0, 0, 0, 0)
            ");
            $stmt->execute([$userId, $examId, $attemptNumber, $answersJson]);
            $attemptId = $conn->lastInsertId();
            echo json_encode(['success' => true, 'attempt_id' => $attemptId]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'submit_exam') {
        $currentCompleted = $conn->prepare("SELECT COUNT(*) FROM monthly_exam_attempts WHERE user_id = ? AND exam_id = ? AND status = 'completed'");
        $currentCompleted->execute([$userId, $examId]);
        if ((int)$currentCompleted->fetchColumn() >= $exam['max_attempts']) {
            die('Maximum attempts reached.');
        }

        $answers = $_POST['answers'] ?? [];
        $correctCount = 0;
        $totalPoints = 0;
        $earnedPoints = 0;
        $answersJson = [];

        foreach ($questions as $q) {
            $qid = $q['id'];
            $userAns = $answers[$qid] ?? null;
            $isCorrect = false;
            $points = (int)$q['points'];
            $totalPoints += $points;
            $feedback = '';

            if ($q['question_type'] === 'multiple_choice') {
                $opt = $conn->prepare("SELECT is_correct FROM monthly_exam_question_options WHERE id = ? AND question_id = ?");
                $opt->execute([$userAns, $qid]);
                $isCorrect = (bool)$opt->fetchColumn();
                $pointsEarned = $isCorrect ? $points : 0;
                $feedback = $isCorrect ? 'Correct!' : 'Incorrect.';
            } elseif ($q['question_type'] === 'true_false') {
                $corr = $conn->prepare("SELECT is_correct FROM monthly_exam_question_options WHERE question_id = ? AND option_text = ?");
                $corr->execute([$qid, $userAns]);
                $isCorrect = (bool)$corr->fetchColumn();
                $pointsEarned = $isCorrect ? $points : 0;
                $feedback = $isCorrect ? 'Correct!' : 'Incorrect.';
            } else {
                $expected = $q['expected_answer'] ?? '';
                $keywordsJson = $q['keywords_json'] ?? null;
                $keywords = $keywordsJson ? json_decode($keywordsJson, true) : [];
                $result = evaluateExplanationAnswer((string)$userAns, $expected, $points, $keywords, 0.6, 70.0);
                $isCorrect = $result['is_correct'];
                $pointsEarned = $result['points_earned'];
                $feedback = $result['feedback'];
            }

            if ($isCorrect) {
                $correctCount++;
                $earnedPoints += $pointsEarned;
            } else {
                $earnedPoints += $pointsEarned;
            }

            $answersJson[] = [
                'question_id'      => $qid,
                'question_text'    => $q['question_text'],
                'user_answer'      => $userAns,
                'is_correct'       => $isCorrect,
                'points_earned'    => $pointsEarned,
                'explanation'      => $q['explanation'] ?? null,
                'feedback'         => $feedback
            ];
        }

        $percentage = $totalPoints > 0 ? ($earnedPoints / $totalPoints) * 100 : 0;
        $passed = $percentage >= $exam['passing_score'];
        $attemptNumber = getNextAttemptNumber($conn, $userId, $examId);

        try {
            $conn->beginTransaction();

            $conn->prepare("UPDATE monthly_exam_attempts SET status = 'abandoned', completed_at = NOW() WHERE user_id = ? AND exam_id = ? AND status = 'started'")
                 ->execute([$userId, $examId]);

            $stmt = $conn->prepare("
                INSERT INTO monthly_exam_attempts
                (user_id, exam_id, attempt_number, total_questions, correct_answers, score, total_points, percentage, passed, status, started_at, completed_at, answers_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW(), ?)
            ");
            $stmt->execute([
                $userId,
                $examId,
                $attemptNumber,
                count($questions),
                $correctCount,
                $earnedPoints,
                $totalPoints,
                $percentage,
                $passed ? 1 : 0,
                json_encode($answersJson)
            ]);
            $attemptId = $conn->lastInsertId();

            $conn->commit();

            // Leaderboard & milestone notifications
            $oldRankStmt = $conn->prepare("SELECT rank_position FROM leaderboard_stats WHERE user_id = ? AND mode = 'exam'");
            $oldRankStmt->execute([$userId]);
            $oldRank = $oldRankStmt->fetchColumn();
            $oldRank = $oldRank !== false ? (int)$oldRank : null;

            updateExamLeaderboardStats($conn, $userId);

            $newRankStmt = $conn->prepare("SELECT rank_position FROM leaderboard_stats WHERE user_id = ? AND mode = 'exam'");
            $newRankStmt->execute([$userId]);
            $newRank = (int)$newRankStmt->fetchColumn();

            if ($newRank > 0) {
                notifyLeaderboardAchievement($conn, $userId, 'exam', $newRank, $oldRank);
                $_SESSION['last_rank_exam'] = $newRank;
            }

            $totalCompletedStmt = $conn->prepare("
                SELECT COUNT(*) FROM monthly_exam_attempts
                WHERE user_id = ? AND status = 'completed'
            ");
            $totalCompletedStmt->execute([$userId]);
            $totalExamsCompleted = (int)$totalCompletedStmt->fetchColumn();
            notifyProgressMilestone($conn, $userId, $totalExamsCompleted, 'exam');

            header("Location: monthly_exam_result.php?attempt_id=" . $attemptId);
            exit;
        } catch (PDOException $e) {
            $conn->rollBack();
            die("Database Error: " . $e->getMessage());
        }
    }
}

// For active exams, start or resume attempt
$timeLimit = (int)($exam['time_limit_minutes'] ?? 0);

$attempt = $conn->prepare("SELECT id, started_at, answers_json FROM monthly_exam_attempts WHERE user_id = ? AND exam_id = ? AND status = 'started' ORDER BY id DESC LIMIT 1");
$attempt->execute([$userId, $examId]);
$attempt = $attempt->fetch();

if ($attempt && $timeLimit > 0) {
    $startedAt = strtotime($attempt['started_at']);
    $elapsed = time() - $startedAt;
    if ($elapsed >= ($timeLimit * 60)) {
        $saved = json_decode($attempt['answers_json'] ?? '[]', true);
        $answers = [];
        foreach ($saved as $ans) {
            $answers[$ans['question_id']] = $ans['user_answer'] ?? '';
        }
        gradeAndFinalizeAttempt($conn, $userId, $examId, $exam, $questions, $answers, $attempt['id']);
    }
}

$savedAnswers = [];
if (!$attempt) {
    $attemptNumber = getNextAttemptNumber($conn, $userId, $examId);
    $conn->prepare("INSERT INTO monthly_exam_attempts (user_id, exam_id, attempt_number, status, started_at) VALUES (?,?,?,'started',NOW())")
         ->execute([$userId, $examId, $attemptNumber]);
    $attemptId = $conn->lastInsertId();
} else {
    $attemptId = $attempt['id'];
    if (!empty($attempt['answers_json'])) {
        $savedAnswers = json_decode($attempt['answers_json'], true) ?: [];
    }
}

// Load options
foreach ($questions as &$q) {
    if ($q['question_type'] === 'multiple_choice' || $q['question_type'] === 'true_false') {
        $stmt = $conn->prepare("
            SELECT id, option_text, image_path 
            FROM monthly_exam_question_options 
            WHERE question_id = ? 
            ORDER BY sort_order
        ");
        $stmt->execute([$q['id']]);
        $q['options'] = $stmt->fetchAll();
    } else {
        $q['options'] = [];
    }
}
unset($q);

$savedAnswerMap = [];
foreach ($savedAnswers as $ans) {
    $savedAnswerMap[$ans['question_id']] = $ans['user_answer'];
}

$activeAttemptKey = 'exam_active_' . $attemptId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MyIslam Monthly Examination</title>
    <style>
        /* (Same as before, include all styles) */
        *{margin:0;padding:0;box-sizing:border-box}
        :root{
            --primary:#166534;--primary-dark:#14532d;--primary-light:#22c55e;--primary-soft:#e7f3eb;
            --secondary:#2563eb;--accent:#f59e0b;--danger:#dc2626;--success:#10b981;
            --text:#1f2937;--text-light:#6b7280;--bg:#f9fafb;--surface:#ffffff;--surface-2:#f3f4f6;
            --border:#e5e7eb;--shadow-sm:0 1px 2px rgba(0,0,0,0.05);--shadow-md:0 6px 16px rgba(0,0,0,0.08);
            --shadow-lg:0 20px 40px rgba(0,0,0,0.12);--radius-sm:10px;--radius-md:14px;--radius-lg:20px;
        }
        body{
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
            padding: 20px;
            display: flex;
            flex-direction: column;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        textarea {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }
        .container{max-width:900px;margin:0 auto;width:100%}
        .exam-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:24px;
            flex-wrap:wrap;
            gap:16px;
            background:var(--surface);
            padding:16px 24px;
            border-radius:var(--radius-lg);
            box-shadow:var(--shadow-sm);
            border:1px solid var(--border);
        }
        .exam-title-section{display:flex;align-items:center;gap:12px}
        .back-link{
            display:flex;
            align-items:center;
            gap:6px;
            text-decoration:none;
            color:var(--text-light);
            font-weight:600;
            font-size:14px;
        }
        .back-link:hover{color:var(--primary)}
        .exam-title{font-size:24px;font-weight:800;color:var(--text)}
        .timer-container{display:flex;align-items:center;gap:12px}
        .timer-label{color:var(--text-light);font-size:14px}
        .timer{
            background:var(--primary);
            color:#fff;
            padding:12px 24px;
            border-radius:999px;
            font-size:28px;
            font-weight:700;
            font-variant-numeric:tabular-nums;
            box-shadow:0 4px 8px rgba(22,101,52,0.2);
            min-width:130px;
            text-align:center;
        }
        .timer.warning{background:var(--accent);box-shadow:0 4px 8px rgba(245,158,11,0.2)}
        .timer.danger{background:var(--danger);box-shadow:0 4px 8px rgba(220,38,38,0.2)}
        .progress-section{
            background:var(--surface);
            padding:12px 24px;
            border-radius:var(--radius-lg);
            margin-bottom:24px;
            border:1px solid var(--border);
        }
        .progress-label{
            display:flex;
            justify-content:space-between;
            font-size:14px;
            color:var(--text-light);
            margin-bottom:8px;
        }
        .progress-bar-bg{
            height:8px;
            background:var(--surface-2);
            border-radius:999px;
            overflow:hidden;
        }
        .progress-bar-fill{
            height:100%;
            background:linear-gradient(90deg,var(--primary),var(--primary-light));
            border-radius:999px;
            transition:width 0.3s;
        }
        .question-card{
            background:var(--surface);
            border-radius:var(--radius-lg);
            padding:24px;
            margin-bottom:20px;
            box-shadow:var(--shadow-sm);
            border:1px solid var(--border);
        }
        .question-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:16px;
        }
        .question-number{font-size:18px;font-weight:800;color:var(--primary)}
        .question-points{
            background:var(--primary-soft);
            color:var(--primary);
            padding:4px 12px;
            border-radius:999px;
            font-size:13px;
            font-weight:700;
        }
        .question-text{font-size:18px;font-weight:600;margin-bottom:16px}
        .question-image{
            max-width:100%;
            max-height:300px;
            border-radius:var(--radius-md);
            margin-bottom:20px;
            border:1px solid var(--border);
        }
        .options{display:grid;gap:12px}
        .option-item{
            display:flex;
            align-items:center;
            padding:14px 18px;
            border:2px solid var(--border);
            border-radius:var(--radius-md);
            cursor:pointer;
            transition:all 0.2s;
            background:var(--surface);
            gap:12px;
        }
        .option-item:hover{border-color:var(--primary);background:var(--primary-soft)}
        .option-item input[type="radio"]{
            width:20px;
            height:20px;
            accent-color:var(--primary);
            cursor:pointer;
            flex-shrink:0;
        }
        .option-content{display:flex;align-items:center;gap:12px;flex:1}
        .option-text{font-size:16px}
        .option-image{
            max-width:80px;
            max-height:60px;
            border-radius:8px;
            border:1px solid var(--border);
            object-fit:cover;
        }
        .option-item:has(input:checked){border-color:var(--primary);background:var(--primary-soft)}
        .submit-section{margin-top:24px;margin-bottom:20px}
        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            padding:16px 32px;
            border-radius:var(--radius-md);
            font-weight:700;
            font-size:18px;
            text-decoration:none;
            transition:all 0.2s;
            cursor:pointer;
            border:none;
            width:100%;
            background:var(--primary);
            color:#fff;
            box-shadow:0 4px 8px rgba(22,101,52,0.2);
        }
        .btn:hover{background:var(--primary-dark);transform:translateY(-2px)}
        .btn:disabled{opacity:0.6;cursor:not-allowed;transform:none}
        .modal-overlay{
            position:fixed;
            top:0;
            left:0;
            right:0;
            bottom:0;
            background:rgba(0,0,0,0.6);
            backdrop-filter:blur(6px);
            display:flex;
            align-items:center;
            justify-content:center;
            z-index:9999;
            padding:20px;
        }
        .modal{
            background:var(--surface);
            border-radius:var(--radius-lg);
            padding:32px;
            max-width:500px;
            width:100%;
            text-align:center;
            box-shadow:var(--shadow-lg);
            border:1px solid var(--border);
        }
        .modal h2{margin-bottom:16px;font-size:24px}
        .modal p{margin-bottom:24px;color:var(--text-light)}
        .modal ul{
            text-align:left;
            margin:20px 0;
            color:var(--text-light);
            padding-left:24px;
        }
        .modal ul li{margin-bottom:10px}
        .modal .btn{width:auto;padding:12px 30px;font-size:16px}
        .strike-indicator{
            display:flex;
            gap:12px;
            justify-content:center;
            margin:24px 0;
        }
        .strike-dot{width:20px;height:20px;border-radius:50%;background:var(--border)}
        .strike-dot.active{background:var(--accent)}
        .strike-dot.danger{background:var(--danger)}
        textarea.form-control{
            width:100%;
            padding:12px;
            border-radius:8px;
            border:1px solid var(--border);
            font-family:inherit;
            resize:vertical;
            font-size:16px;
            -webkit-user-select: text;
            user-select: text;
        }
        .watermark-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            pointer-events: none;
            z-index: 9998;
            opacity: 0.08;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 120px;
            font-weight: 900;
            color: #000;
            transform: rotate(-30deg);
            white-space: nowrap;
            text-transform: uppercase;
        }
        @media (max-width:768px){
            body{padding:12px}
            .exam-header{flex-direction:column;align-items:stretch;padding:16px}
            .exam-title{font-size:20px}
            .timer{font-size:24px;padding:10px 20px}
            .question-card{padding:18px}
            .question-text{font-size:16px}
            .option-item{padding:12px 14px}
            .modal{padding:24px}
        }
    </style>
</head>
<body>
<div class="watermark-overlay" id="watermark"><?= e($userId) ?> - <?= date('Y-m-d') ?></div>

<!-- Instructions Modal -->
<div class="modal-overlay" id="instructionsModal">
    <div class="modal">
        <h2>📋 Exam Instructions</h2>
        <p><strong><?php echo e($exam['title']); ?></strong></p>
        <p><?php echo e($exam['description'] ?? 'Please read carefully.'); ?></p>
        <ul>
            <li><strong>⏱️ Time limit:</strong> <?php echo $timeLimit ? $timeLimit . ' minutes' : 'No time limit'; ?></li>
            <li><strong>🎯 Passing score:</strong> <?php echo $exam['passing_score']; ?>%</li>
            <li><strong>📝 Questions:</strong> <?php echo count($questions); ?> total</li>
            <li><strong>🚫 Do NOT</strong> switch tabs, minimize, leave this window, or attempt to take screenshots.</li>
            <li><strong>📸 Screenshots are strictly prohibited</strong> and will count as a violation.</li>
            <li><strong>⚠️ 3 violations</strong> will terminate your exam.</li>
            <li><strong>✅ Answer all questions</strong> before submitting.</li>
        </ul>
        <button class="btn" id="startExamBtn">I Understand, Start Exam</button>
    </div>
</div>

<!-- Warning Modal -->
<div class="modal-overlay" id="warningModal" style="display: none;">
    <div class="modal">
        <h2 id="warningTitle">⚠️ Warning</h2>
        <p id="warningMessage">You have left the exam window.</p>
        <div class="strike-indicator" id="strikeIndicator">
            <span class="strike-dot" id="strike1"></span>
            <span class="strike-dot" id="strike2"></span>
            <span class="strike-dot" id="strike3"></span>
        </div>
        <p>Violation <strong><span id="strikeCountDisplay">1</span> of 3</strong>.<br>Third violation terminates the exam.</p>
        <p id="countdownWrapper">⏳ Resume within <strong><span id="countdownDisplay">15</span></strong> seconds or get another violation.</p>
        <button class="btn" id="resumeExamBtn" style="background: var(--accent);">Resume Exam</button>
    </div>
</div>

<!-- Time Up Modal -->
<div class="modal-overlay" id="timeUpModal" style="display: none;">
    <div class="modal" style="max-width: 450px;">
        <div style="font-size: 48px; margin-bottom: 16px;">⏰</div>
        <h2 style="color: #1f2937; margin-bottom: 12px;">Time's Up!</h2>
        <p style="margin-bottom: 24px; color: #4b5563;" id="timeUpMessage">
            Your exam time has expired.<br>
            Submitting your answers automatically...
        </p>
        <button class="btn" id="viewResultBtn" style="width: 100%; padding: 14px;" disabled>
            Submitting...
        </button>
    </div>
</div>

<!-- Main Exam Container -->
<div class="container" id="examContainer" style="display: none;">
    <div class="exam-header">
        <div class="exam-title-section">
            <a href="#" class="back-link" id="exitExamLink"><span>←</span> Exit Exam</a>
            <h1 class="exam-title"><?php echo e($exam['title']); ?></h1>
        </div>
        <div class="timer-container">
            <span class="timer-label">Time Remaining</span>
            <div class="timer" id="timer">--:--</div>
        </div>
    </div>

    <div class="progress-section">
        <div class="progress-label">
            <span>Questions Answered</span>
            <span><span id="answeredCount">0</span> / <?php echo count($questions); ?></span>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" id="progressBar" style="width: 0%;"></div>
        </div>
    </div>

    <form method="POST" id="examForm">
        <input type="hidden" name="action" value="submit_exam">
        <?php foreach ($questions as $index => $q): ?>
        <?php $savedValue = $savedAnswerMap[$q['id']] ?? null; ?>
        <div class="question-card">
            <div class="question-header">
                <span class="question-number">Question <?php echo $index + 1; ?> of <?php echo count($questions); ?></span>
                <span class="question-points"><?php echo $q['points']; ?> point<?php echo $q['points'] != 1 ? 's' : ''; ?></span>
            </div>
            <div class="question-text"><?php echo e($q['question_text']); ?></div>
            <?php if (!empty($q['image_path'])): ?>
                <img src="<?php echo e($q['image_path']); ?>" class="question-image" alt="Question image">
            <?php endif; ?>
            <div class="options">
                <?php if ($q['question_type'] === 'multiple_choice'): ?>
                    <?php foreach ($q['options'] as $opt): ?>
                    <label class="option-item">
                        <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="<?php echo $opt['id']; ?>" required
                               <?php echo ($savedValue == $opt['id']) ? 'checked' : ''; ?>>
                        <div class="option-content">
                            <span class="option-text"><?php echo e($opt['option_text']); ?></span>
                            <?php if (!empty($opt['image_path'])): ?>
                                <img src="<?php echo e($opt['image_path']); ?>" class="option-image" alt="Option image">
                            <?php endif; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                <?php elseif ($q['question_type'] === 'true_false'): ?>
                    <label class="option-item">
                        <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="True" required
                               <?php echo ($savedValue === 'True') ? 'checked' : ''; ?>>
                        <span class="option-text">True</span>
                    </label>
                    <label class="option-item">
                        <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="False" required
                               <?php echo ($savedValue === 'False') ? 'checked' : ''; ?>>
                        <span class="option-text">False</span>
                    </label>
                <?php else: ?>
                    <textarea name="answers[<?php echo $q['id']; ?>]" class="form-control" rows="3" placeholder="Type your answer here..." required><?php echo e($savedValue ?? ''); ?></textarea>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="submit-section">
            <button type="submit" class="btn" id="submitBtn">Submit Exam</button>
        </div>
    </form>
</div>

<script>
    const timeLimit = <?php echo $timeLimit * 60; ?>;
    const examId = <?php echo $examId; ?>;
    const attemptId = <?php echo $attemptId; ?>;
    const activeAttemptKey = <?php echo json_encode($activeAttemptKey); ?>;
    const totalQuestions = <?php echo count($questions); ?>;

    let remainingSeconds = timeLimit;
    let timerInterval = null;
    let examActive = false;
    let violations = 0;
    const MAX_VIOLATIONS = 3;
    let warningModalVisible = false;
    let submitting = false;
    let timeExpired = false;
    let terminated = false;
    let warningTimeout = null;
    let warningCountdown = 15;
    let countdownInterval = null;

    const instructionsModal = document.getElementById('instructionsModal');
    const examContainer = document.getElementById('examContainer');
    const startBtn = document.getElementById('startExamBtn');
    const warningModal = document.getElementById('warningModal');
    const warningTitle = document.getElementById('warningTitle');
    const warningMessage = document.getElementById('warningMessage');
    const strikeCountDisplay = document.getElementById('strikeCountDisplay');
    const resumeBtn = document.getElementById('resumeExamBtn');
    const timerEl = document.getElementById('timer');
    const examForm = document.getElementById('examForm');
    const submitBtn = document.getElementById('submitBtn');
    const exitExamLink = document.getElementById('exitExamLink');
    const answeredCountEl = document.getElementById('answeredCount');
    const progressBar = document.getElementById('progressBar');
    const timeUpModal = document.getElementById('timeUpModal');
    const viewResultBtn = document.getElementById('viewResultBtn');

    function clearWarningTimers() {
        if (warningTimeout) {
            clearTimeout(warningTimeout);
            warningTimeout = null;
        }
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }

    function updateWarningCountdown(seconds) {
        const countdownSpan = document.getElementById('countdownDisplay');
        if (countdownSpan) countdownSpan.textContent = seconds;
    }

    function startWarningTimer() {
        clearWarningTimers();
        warningCountdown = 15;
        updateWarningCountdown(warningCountdown);
        countdownInterval = setInterval(() => {
            warningCountdown--;
            updateWarningCountdown(warningCountdown);
            if (warningCountdown <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                if (warningModalVisible) {
                    warningModal.style.display = 'none';
                    warningModalVisible = false;
                    handleViolation('timeout_no_resume');
                }
            }
        }, 1000);
        warningTimeout = setTimeout(() => {
            if (warningModalVisible) {
                warningModal.style.display = 'none';
                warningModalVisible = false;
                handleViolation('timeout_no_resume');
            }
            clearWarningTimers();
        }, 15000);
    }

    function cleanupAfterSubmission() {
        examActive = false;
        warningModalVisible = false;
        clearWarningTimers();
        if (timeUpModal) timeUpModal.style.display = 'none';
        if (warningModal) warningModal.style.display = 'none';
        document.body.style.overflow = '';
        sessionStorage.removeItem(activeAttemptKey);
    }

    function updateProgress() {
        const inputs = document.querySelectorAll('input[type="radio"]:checked, textarea');
        const answered = new Set();
        inputs.forEach(el => {
            if (el.tagName === 'TEXTAREA' && el.value.trim() === '') return;
            let name = el.getAttribute('name');
            if (name && name.startsWith('answers[')) answered.add(name);
        });
        const count = answered.size;
        answeredCountEl.textContent = count;
        progressBar.style.width = (count / totalQuestions * 100) + '%';
    }

    function autoSave() {
        if (!examActive || submitting) return;
        const formData = new FormData(examForm);
        const answers = {};
        for (let [key, value] of formData.entries()) {
            if (key.startsWith('answers[')) {
                const qid = key.match(/\[(.*?)\]/)[1];
                answers[qid] = value;
            }
        }
        localStorage.setItem('exam_answers_' + examId, JSON.stringify(answers));
    }

    async function abandonExam(useBeacon = false) {
        if (useBeacon && navigator.sendBeacon) {
            const formData = new FormData();
            formData.append('action', 'abandon');
            navigator.sendBeacon('take_monthly_exam.php', formData);
        } else {
            try {
                await fetch('take_monthly_exam.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=abandon'
                });
            } catch(e) {}
        }
        sessionStorage.removeItem(activeAttemptKey);
    }

    function performSubmission() {
        if (submitting) return;
        submitting = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
        window.removeEventListener('beforeunload', beforeUnloadHandler);
        localStorage.removeItem('exam_answers_' + examId);
        cleanupAfterSubmission();
        examForm.submit();
    }

    const beforeUnloadHandler = (e) => {
        if (examActive && !submitting) {
            e.preventDefault();
            e.returnValue = '';
            abandonExam(true);
        }
    };

    function handleTimeExpired() {
        if (submitting || timeExpired) return;
        timeExpired = true;
        examActive = false;
        clearWarningTimers();

        if (timeUpModal) {
            const modalMessage = document.getElementById('timeUpMessage');
            const viewBtn = document.getElementById('viewResultBtn');
            if (modalMessage) modalMessage.textContent = 'Time expired. Submitting your answers automatically...';
            if (viewBtn) {
                viewBtn.disabled = true;
                viewBtn.textContent = 'Submitting...';
            }
            timeUpModal.style.display = 'flex';
        }

        performSubmission();
    }

    if (sessionStorage.getItem(activeAttemptKey) === '1') {
        abandonExam().finally(() => {
            sessionStorage.removeItem(activeAttemptKey);
            window.location.reload();
        });
        throw new Error('Stale attempt detected, reloading...');
    }

    document.querySelectorAll('input[type="radio"], textarea').forEach(el => el.addEventListener('change', updateProgress));

    startBtn.addEventListener('click', async () => {
        instructionsModal.style.display = 'none';
        examContainer.style.display = 'block';
        examActive = true;
        sessionStorage.setItem(activeAttemptKey, '1');
        try { await document.documentElement.requestFullscreen(); } catch(e) {}
        if (timeLimit > 0) {
            updateTimer();
            timerInterval = setInterval(updateTimer, 1000);
        } else {
            timerEl.textContent = 'No limit';
        }
        attachAntiCheat();
        updateProgress();
        const saved = localStorage.getItem('exam_answers_' + examId);
        if (saved) {
            try {
                const answers = JSON.parse(saved);
                for (let qid in answers) {
                    const value = answers[qid];
                    const inputs = document.querySelectorAll(`input[name="answers[${qid}]"], textarea[name="answers[${qid}]"]`);
                    inputs.forEach(input => {
                        if (input.type === 'radio') {
                            if (input.value === value) input.checked = true;
                        } else {
                            input.value = value;
                        }
                    });
                }
                updateProgress();
            } catch(e) {}
        }
        setInterval(autoSave, 30000);
        window.addEventListener('beforeunload', beforeUnloadHandler);
        window.addEventListener('pagehide', () => {
            if (examActive) {
                abandonExam(true);
                sessionStorage.removeItem(activeAttemptKey);
            }
        });
    });

    function updateTimer() {
        if (!examActive || submitting) return;
        if (remainingSeconds <= 0) {
            clearInterval(timerInterval);
            timerEl.textContent = '00:00';
            if (!submitting) handleTimeExpired();
            return;
        }
        const mins = Math.floor(remainingSeconds / 60);
        const secs = remainingSeconds % 60;
        timerEl.textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        if (remainingSeconds <= 60) timerEl.classList.add('danger');
        else if (remainingSeconds <= 300) timerEl.classList.add('warning');
        remainingSeconds--;
    }

    function attachAntiCheat() {
        document.addEventListener('contextmenu', (e) => e.preventDefault());
        document.addEventListener('keydown', (e) => {
            if (!examActive || submitting) return;
            if (e.key === 'PrintScreen' || e.keyCode === 44) {
                e.preventDefault();
                handleViolation('screenshot_attempt');
                return false;
            }
            if (e.ctrlKey && (e.key === 'p' || e.key === 'P' || e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                handleViolation('print_attempt');
                return false;
            }
            if (e.key === 'F12' || e.keyCode === 123) {
                e.preventDefault();
                handleViolation('devtools_attempt');
                return false;
            }
        });
        document.addEventListener('visibilitychange', () => {
            if (examActive && document.hidden && !submitting) handleViolation('tab_switch');
        });
        window.addEventListener('blur', () => {
            if (examActive && !submitting) handleViolation('window_blur');
        });
        let lastBlur = 0;
        window.addEventListener('blur', () => { lastBlur = Date.now(); });
        window.addEventListener('focus', () => {
            if (examActive && !submitting && lastBlur > 0) {
                const diff = Date.now() - lastBlur;
                if (diff > 500 && diff < 3000) {
                    handleViolation('suspicious_activity');
                }
            }
        });
    }

    function handleViolation(type) {
        if (!examActive || warningModalVisible || submitting || terminated) return;
        violations++;
        updateStrikeDots();
        let msg = '';
        switch(type) {
            case 'tab_switch': msg = 'Tab switching detected.'; break;
            case 'window_blur': msg = 'Window focus lost.'; break;
            case 'screenshot_attempt': msg = 'Screenshot attempt detected.'; break;
            case 'print_attempt': msg = 'Print attempt blocked.'; break;
            case 'devtools_attempt': msg = 'Developer tools are not allowed.'; break;
            case 'suspicious_activity': msg = 'Suspicious activity detected.'; break;
            case 'timeout_no_resume': msg = 'You did not resume in time.'; break;
            default: msg = 'Rule violation.';
        }

        if (violations >= MAX_VIOLATIONS) {
            examActive = false;
            clearInterval(timerInterval);
            clearWarningTimers();
            if (terminated) return;
            terminated = true;
            sessionStorage.removeItem(activeAttemptKey);
            warningTitle.textContent = '❌ Exam Terminated';
            warningMessage.textContent = 'You have violated exam rules three times. Your attempt is being terminated...';
            resumeBtn.textContent = 'Terminating...';
            resumeBtn.style.background = 'var(--danger)';
            resumeBtn.disabled = true;
            resumeBtn.onclick = null;
            const cd = document.getElementById('countdownWrapper');
            if (cd) cd.style.display = 'none';
            warningModal.style.display = 'flex';
            warningModalVisible = true;
            terminateExam();
            return;
        }

        warningTitle.textContent = `⚠️ Warning (${violations}/${MAX_VIOLATIONS})`;
        warningMessage.textContent = msg;
        strikeCountDisplay.textContent = violations;

        let countdownWrapper = document.getElementById('countdownWrapper');
        if (!countdownWrapper) {
            const modal = document.querySelector('#warningModal .modal');
            const p = document.createElement('p');
            p.id = 'countdownWrapper';
            p.innerHTML = '⏳ Resume within <strong><span id="countdownDisplay">15</span></strong> seconds or get another violation.';
            modal.insertBefore(p, modal.querySelector('.strike-indicator').nextSibling);
        } else {
            countdownWrapper.style.display = 'block';
            document.getElementById('countdownDisplay').textContent = '15';
        }

        resumeBtn.onclick = () => {
            clearWarningTimers();
            warningModal.style.display = 'none';
            warningModalVisible = false;
            if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(()=>{});
        };

        warningModal.style.display = 'flex';
        warningModalVisible = true;
        startWarningTimer();
    }

    function updateStrikeDots() {
        document.getElementById('strike1').classList.toggle('active', violations >= 1);
        document.getElementById('strike2').classList.toggle('active', violations >= 2);
        document.getElementById('strike3').classList.toggle('active', violations >= 3);
        if (violations >= 3) {
            document.querySelectorAll('.strike-dot').forEach(d => d.classList.add('danger'));
        }
    }

    async function terminateExam() {
        if (submitting) return;
        submitting = true;
        const formData = new FormData(examForm);
        const answers = {};
        for (let [key, value] of formData.entries()) {
            if (key.startsWith('answers[')) {
                const qid = key.match(/\[(.*?)\]/)[1];
                answers[qid] = value;
            }
        }
        const answersJson = [];
        <?php foreach ($questions as $q): ?>
        answersJson.push({
            question_id: <?php echo $q['id']; ?>,
            question_text: <?php echo json_encode($q['question_text']); ?>,
            user_answer: answers[<?php echo $q['id']; ?>] || null,
            is_correct: false,
            points_earned: 0,
            explanation: <?php echo json_encode($q['explanation'] ?? null); ?>
        });
        <?php endforeach; ?>

        try {
            const resp = await fetch('take_monthly_exam.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=terminate&answers_json=' + encodeURIComponent(JSON.stringify(answersJson))
            });
            const data = await resp.json();
            if (data.success && data.attempt_id) {
                window.location.href = 'monthly_exam_result.php?attempt_id=' + data.attempt_id + '&terminated=1';
            } else {
                window.location.href = 'monthly_exams.php';
            }
        } catch(e) {
            window.location.href = 'monthly_exams.php';
        }
    }

    examForm.addEventListener('submit', (e) => {
        e.preventDefault();
        if (timeExpired) {
            performSubmission();
            return;
        }
        const radios = document.querySelectorAll('input[type="radio"][required]');
        const textareas = document.querySelectorAll('textarea[required]');
        let allAnswered = true;
        const answeredNames = new Set();
        document.querySelectorAll('input[type="radio"]:checked').forEach(r => answeredNames.add(r.name));
        for (let radio of radios) {
            if (!answeredNames.has(radio.name)) allAnswered = false;
        }
        for (let ta of textareas) {
            if (!ta.value.trim()) allAnswered = false;
        }
        if (!allAnswered) {
            alert('Please answer all questions before submitting.');
            return;
        }
        performSubmission();
    });

    exitExamLink.addEventListener('click', (e) => {
        e.preventDefault();
        if (submitting || terminated || timeExpired) {
            alert('Cannot exit while exam is being processed.');
            return;
        }
        if (confirm('Are you sure you want to exit? This will count as an attempt.')) {
            abandonExam().then(() => window.location.href = 'monthly_exams.php');
        }
    });

    window.addEventListener('popstate', (e) => {
        if (examActive && !submitting && !terminated) {
            if (confirm('Leaving the exam will count as an attempt. Are you sure?')) {
                abandonExam().then(() => window.location.href = 'monthly_exams.php');
            } else {
                history.pushState(null, '', location.href);
            }
        }
    });
    history.pushState(null, '', location.href);
    if (window.history.replaceState) window.history.replaceState(null, null, window.location.href);
    updateProgress();
</script>
</body>
</html>