<?php
declare(strict_types=1);
session_start();

// Set timezone to East Africa Time (Tanzania)
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/includes/quiz_engine.php';

// Fallback for quizE (if not defined in quiz_engine)
if (!function_exists('quizE')) {
    function quizE($str): string {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

$userId = requireUserAuth();
$conn = db();

if (!$conn instanceof PDO) {
    exit('Database connection failed.');
}

// Ensure quiz_attempts carries course_id / enrollment_id for per-course scoping.
ensureQuizAttemptsColumns($conn);

// Detect base URL for images (e.g., /myislam/)
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseUrl = str_replace('/admin', '', $baseUrl);
$baseUrl = rtrim($baseUrl, '/') . '/';

// ========== FETCH USER DETAILS FOR HEADER ==========
function getCurrentUser(PDO $conn, int $userId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, profile_image, role, is_active, is_verified, created_at
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

$dbUser = getCurrentUser($conn, $userId);
if (!$dbUser) {
    session_destroy();
    header('Location: /admin/login.php?error=account_disabled');
    exit;
}

$user = [
    'name' => $dbUser['full_name'] ?? 'User',
    'email' => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role' => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime($dbUser['created_at'])) : 'recently',
];

// Get unread notification count
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table may not exist yet
}
// ===================================================

function resolveQuizId(PDO $conn): int
{
    foreach (['quiz_id', 'id'] as $key) {
        if (isset($_GET[$key]) && is_numeric($_GET[$key])) {
            $quizId = (int)$_GET[$key];
            if ($quizId > 0) {
                return $quizId;
            }
        }
    }

    if (isset($_GET['slug'])) {
        $slug = trim((string)$_GET['slug']);
        if ($slug !== '') {
            try {
                $stmt = $conn->prepare("SELECT id FROM quizzes WHERE slug = ? AND status = 'published' LIMIT 1");
                $stmt->execute([$slug]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['id'])) {
                    return (int)$row['id'];
                }
            } catch (PDOException $e) {
                error_log('resolveQuizId error: ' . $e->getMessage());
            }
        }
    }

    return 0;
}

function quizModeValue(array $quiz): string
{
    $mode = strtolower(trim((string)($quiz['mode'] ?? 'lesson')));
    return in_array($mode, ['lesson', 'competition', 'self'], true) ? $mode : 'lesson';
}



function generateReviewFromQuestions(array $questions): array
{
    $review = [];
    foreach ($questions as $q) {
        $correctOption = null;
        $correctText = '';
        $qtype = $q['question_type'] ?? 'multiple_choice';
        
        if ($qtype === 'explanation') {
            // For explanation questions, use expected_answer if available
            $correctText = $q['expected_answer'] ?? '';
        } elseif (!empty($q['options'])) {
            foreach ($q['options'] as $opt) {
                if (!empty($opt['is_correct'])) {
                    $correctOption = $opt['id'];
                    $correctText = $opt['option_text'] ?? '';
                    break;
                }
            }
        }
        
        $review[] = [
            'question_id'        => $q['id'],
            'question_text'      => $q['question_text'] ?? '',
            'user_answer'        => null,
            'user_answer_text'   => null,
            'correct_answer'     => $correctOption,
            'correct_answer_text'=> $correctText,
            'is_correct'         => false,
            'points_earned'      => 0,
            'explanation'        => $q['explanation'] ?? '',
        ];
    }
    return $review;
}

function buildReviewFromAttempt(array $attempt, array $questions): array
{
    $results = [];
    $hasUserAnswers = false;
    if (!empty($attempt['answers_json'])) {
        $decoded = json_decode((string)$attempt['answers_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            $results = $decoded;
            $hasUserAnswers = true;
        }
    }

    if (!$hasUserAnswers) {
        $results = generateReviewFromQuestions($questions);
    }

    return [
        'attempt_id'       => (int)($attempt['id'] ?? 0),
        'score'            => (int)($attempt['score'] ?? 0),
        'total'            => (int)($attempt['total_points'] ?? 0),
        'percentage'       => (float)($attempt['percentage'] ?? 0),
        'passed'           => ((int)($attempt['passed'] ?? 0) === 1),
        'correct_count'    => (int)($attempt['correct_answers'] ?? 0),
        'total_questions'  => (int)($attempt['total_questions'] ?? count($questions)),
        'results'          => $results,
        'attempted'        => true,
        'locked'           => true,
        'status'           => $attempt['status'] ?? 'completed',
        'has_user_answers' => $hasUserAnswers,
    ];
}

function latestCompletedAttempt(PDO $conn, int $quizId, int $userId, ?int $courseId = null): ?array
{
    try {
        if ($courseId !== null && $courseId > 0) {
            $stmt = $conn->prepare("
                SELECT *
                FROM quiz_attempts
                WHERE user_id = ? AND quiz_id = ? AND status IN ('completed', 'abandoned')
                  AND (course_id = ? OR course_id IS NULL)
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $quizId, $courseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT *
                FROM quiz_attempts
                WHERE user_id = ? AND quiz_id = ? AND status IN ('completed', 'abandoned')
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $quizId]);
        }
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        return $attempt ?: null;
    } catch (Throwable $e) {
        error_log('latestCompletedAttempt error: ' . $e->getMessage());
        return null;
    }
}

function competitionAttemptsUsed(PDO $conn, int $quizId, int $userId, array $quiz, ?int $courseId = null): int
{
    $start = $quiz['competition_start_at'] ?? null;
    $end = $quiz['competition_end_at'] ?? null;

    try {
        if ($start && $end) {
            if ($courseId !== null && $courseId > 0) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM quiz_attempts
                    WHERE user_id = ?
                      AND quiz_id = ?
                      AND status IN ('completed', 'abandoned')
                      AND completed_at BETWEEN ? AND ?
                      AND (course_id = ? OR course_id IS NULL)
                ");
                $stmt->execute([$userId, $quizId, $start, $end, $courseId]);
            } else {
                $stmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM quiz_attempts
                    WHERE user_id = ?
                      AND quiz_id = ?
                      AND status IN ('completed', 'abandoned')
                      AND completed_at BETWEEN ? AND ?
                ");
                $stmt->execute([$userId, $quizId, $start, $end]);
            }
            return (int)$stmt->fetchColumn();
        }

        if (function_exists('getCompetitionAttemptsInWindow')) {
            return (int)getCompetitionAttemptsInWindow($conn, $quizId, $userId, $quiz, null, $courseId);
        }

        if ($courseId !== null && $courseId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM quiz_attempts
                WHERE user_id = ? AND quiz_id = ? AND status IN ('completed', 'abandoned')
                  AND (course_id = ? OR course_id IS NULL)
            ");
            $stmt->execute([$userId, $quizId, $courseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM quiz_attempts
                WHERE user_id = ? AND quiz_id = ? AND status IN ('completed', 'abandoned')
            ");
            $stmt->execute([$userId, $quizId]);
        }
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('competitionAttemptsUsed error: ' . $e->getMessage());
        return 0;
    }
}

// ========== AJAX HANDLERS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'create_competition_attempt') {
        header('Content-Type: application/json');
        try {
            $quizId = (int)($_POST['quiz_id'] ?? 0);
            if ($quizId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid quiz ID']);
                exit;
            }

            $quiz = getQuizForAttempt($conn, $quizId);
            if (!$quiz) {
                echo json_encode(['success' => false, 'message' => 'Quiz not found']);
                exit;
            }

            $attemptId = createQuizAttempt($conn, $quizId, $userId);

            $attempt = getAttemptById($conn, $attemptId, $userId);
            $expiresAt = null;
            $timeLimit = (int)($quiz['time_limit_minutes'] ?? 0);
            if ($attempt && !empty($attempt['started_at']) && $timeLimit > 0) {
                $started = new DateTime($attempt['started_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
                $started->modify('+' . $timeLimit . ' minutes');
                $expiresAt = $started->format('Y-m-d H:i:s');
            }

            echo json_encode([
                'success' => true,
                'attempt_id' => $attemptId,
                'expires_at' => $expiresAt,
                'time_limit_minutes' => $timeLimit,
            ]);
        } catch (Throwable $e) {
            error_log('create_competition_attempt error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($postAction === 'abandon_attempt') {
        header('Content-Type: application/json');
        try {
            $quizId = (int)($_POST['quiz_id'] ?? 0);
            $attemptId = (int)($_POST['attempt_id'] ?? 0);
            if ($quizId > 0 && $attemptId > 0) {
                $stmt = $conn->prepare("
                    UPDATE quiz_attempts
                    SET status = 'abandoned', completed_at = NOW()
                    WHERE id = ? AND user_id = ? AND quiz_id = ? AND status = 'started'
                ");
                $stmt->execute([$attemptId, $userId, $quizId]);
            }
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            error_log('abandon_attempt error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Unknown action — return JSON, never fall through to HTML output.
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $postAction]);
    exit;
}
$quizId = resolveQuizId($conn);
if ($quizId <= 0) {
    exit('Invalid quiz ID.');
}


$quiz = getQuizForAttempt($conn, $quizId);
if (!$quiz) {
    exit('Quiz not found or not published.');
}

// Resolve course scope for this user + quiz (used by every scoped attempt lookup).
$attemptScope        = resolveQuizAttemptScope($conn, $quizId, $userId);
$attemptCourseId     = $attemptScope['course_id'];
$attemptEnrollmentId = $attemptScope['enrollment_id'];

$quizMode = quizModeValue($quiz);
$isCompetition = $quizMode === 'competition';
$isSelf = $quizMode === 'self';
$isLesson = $quizMode === 'lesson';
// Determine competition status using local timezone
// Determine competition status — delegate to the engine so page and engine
// use the exact same window logic.
$competitionActive  = true;
$competitionUpcoming = false;
$competitionEnded   = false;
$window             = null;
$startDate          = null;
$endDate            = null;

if ($isCompetition) {
    $window = getCompetitionWindow($quiz);
    $now = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));

    if ($window) {
        $startDate = $window['start']->format('Y-m-d H:i:s');
        $endDate   = $window['end']->format('Y-m-d H:i:s');
        $competitionActive   = ($now >= $window['start'] && $now <= $window['end']);
        $competitionUpcoming = ($now < $window['start']);
        $competitionEnded    = ($now > $window['end']);
        $windowLabel = $window['label'];
    } else {
        // No window defined → treat as always active
        $competitionActive = true;
        $windowLabel = '';
    }
}

// Fallback label if we never got one
if (!isset($windowLabel) || $windowLabel === '') {
    $windowLabel = $startDate && $endDate
        ? date('M d, Y H:i', strtotime($startDate)) . ' - ' . date('M d, Y H:i', strtotime($endDate))
        : '';
}

// Fetch questions (needed for all paths)
$questions = getQuizQuestionsWithOptions($conn, $quizId);
if (!$questions || !is_array($questions)) {
    $questions = [];
}

$gradableQuestions = array_filter($questions, fn($q) => ($q['question_type'] ?? '') !== 'explanation');

// Handle upcoming competitions with a countdown UI
if ($isCompetition && $competitionUpcoming) {
    $backUrl = '../quizzes.php';
    $title = htmlspecialchars($quiz['title'] ?? 'Competition Quiz');
    $description = htmlspecialchars($quiz['description'] ?? '');
    $startTimestamp = $startDate ? (new DateTime($startDate, new DateTimeZone('Africa/Dar_es_Salaam')))->getTimestamp() : 0;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Competition Upcoming - MyIslam</title>
        <style>
            /* Use same variables and base styles as main quiz page for consistency */
            * { margin: 0; padding: 0; box-sizing: border-box; }
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
                --warning: #b45309;
                --danger: #b91c1c;
                --success: #0E4A34;
                --header-bg: #ffffff;
                --announcement-bg: linear-gradient(135deg, #eef2f7 0%, #f9fafb 100%);
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
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
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
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
                background-repeat: repeat;
            }
            .card {
                max-width: 600px;
                width: 100%;
                background: var(--surface);
                border-radius: var(--radius-lg);
                box-shadow: var(--shadow-lg);
                padding: 32px;
                text-align: center;
                border: 1px solid var(--line);
            }
            .status-icon { font-size: 64px; margin-bottom: 16px; }
            h1 { font-size: 28px; margin-bottom: 8px; color: var(--text); }
            .badge {
                display: inline-block;
                padding: 6px 16px;
                border-radius: 10px;
                border: 1px solid var(--line);        
                font-weight: 700;
                font-size: 14px;
                margin-bottom: 20px;
                background: var(--gold-soft);
                color: var(--gold);
            }
            .description { color: var(--text-secondary); margin-bottom: 24px; line-height: 1.6; }
            .window-box {
                background: var(--surface-2);
                border-radius: var(--radius-md);
                padding: 20px;
                margin-bottom: 24px;
                border: 1px solid var(--line);
            }
            .window-label {
                font-size: 14px;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: var(--muted);
                margin-bottom: 8px;
            }
            .window-dates {
                font-size: 18px;
                font-weight: 700;
                color: var(--brand);
                margin-bottom: 16px;
            }
            .countdown-timer {
                font-size: 32px;
                font-weight: 800;
                color: var(--brand);
                margin: 12px 0;
            }
            .btn {
                display: inline-block;
                background: var(--brand);
                color: white;
                font-weight: 700;
                padding: 14px 28px;
                border-radius: 999px;
                text-decoration: none;
                transition: background 0.2s;
                border: none;
                cursor: pointer;
            }
            .btn:hover { background: var(--brand-dark); }
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
                <div class="window-label">Competition Window</div>
                <div class="window-dates"><?= htmlspecialchars($windowLabel) ?></div>
                <div class="countdown-timer" id="countdown">--:--:--</div>
                <p>Starts in</p>
            </div>
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn">← Back to Quizzes</a>
        </div>
        <script>
            (function() {
                const startTime = <?= $startTimestamp * 1000 ?>; // milliseconds
                const countdownEl = document.getElementById('countdown');
                if (!countdownEl) return;
                function updateCountdown() {
                    const now = Date.now();
                    const diff = startTime - now;
                    if (diff <= 0) {
                        countdownEl.textContent = 'Starting soon!';
                        setTimeout(() => location.reload(), 2000);
                        return;
                    }
                    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((diff % 86400000) / 3600000);
                    const minutes = Math.floor((diff % 3600000) / 60000);
                    const seconds = Math.floor((diff % 60000) / 1000);
                    let timeStr = '';
                    if (days > 0) timeStr += days + 'd ';
                    timeStr += String(hours).padStart(2, '0') + ':' +
                               String(minutes).padStart(2, '0') + ':' +
                               String(seconds).padStart(2, '0');
                    countdownEl.textContent = timeStr;
                }
                updateCountdown();
                setInterval(updateCountdown, 1000);
            })();
        </script>
    </body>
    </html>
    <?php
    exit;
}

// For ended competitions, we show results directly using the same rendering logic as active flow
if ($isCompetition && $competitionEnded) {
$existingCompletedAttempt = latestCompletedAttempt($conn, $quizId, $userId, $attemptCourseId);
    $reviewPayload = null;
    $isParticipant = false;

    if ($existingCompletedAttempt) {
        $reviewPayload = buildReviewFromAttempt($existingCompletedAttempt, $questions);
        $isParticipant = true;
    } else {
        // Non-participant: generate review with correct answers only
        $reviewPayload = [
            'passed' => false,
            'percentage' => 0,
            'score' => 0,
            'total' => array_sum(array_column($gradableQuestions, 'points')),
            'correct_count' => 0,
            'total_questions' => count($gradableQuestions),
            'results' => generateReviewFromQuestions($questions),
            'attempted' => false,
            'locked' => true,
            'has_user_answers' => false
        ];
    }

    // Use the same template as active flow but with locked state and no form
    $takeLocked = true;
    $lockReason = $isParticipant 
        ? 'This competition has ended. Here are your results.' 
        : 'This competition has ended. You did not participate. Below are the correct answers for review.';
    $existingAttemptPayload = $reviewPayload;
    $attempt = null;
    $expiresAt = null;
    $antiCheatEnabled = false;
    $timeLimitMinutes = (int)($quiz['time_limit_minutes'] ?? 0);
    $backUrl = '../quizzes.php';
    $attemptCount = 0;
    $maxAttempts = 0;
    $unlimited = false;
    $weeklyAttempts = 0;
    $modeLabel = 'Competition';
    $competitionActive = false; // ensure active is false
    // Continue to HTML output below
} else {
    // ========== NORMAL QUIZ FLOW (ACTIVE COMPETITION OR NON-COMPETITION) ==========
$attemptCount = getAttemptCount($conn, $quizId, $userId, $attemptCourseId); // counts completed attempts
    $maxAttempts = (int)($quiz['max_attempts'] ?? 3);
    $unlimited = (bool)($quiz['unlimited_attempts'] ?? false);
    $weeklyAttempts = 0;

    if ($isCompetition) {
        // Count ANY attempt within the window (strict)
        $start = $quiz['competition_start_at'] ?? null;
        $end   = $quiz['competition_end_at'] ?? null;
     if ($start && $end) {
    if ($attemptCourseId !== null && $attemptCourseId > 0) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM quiz_attempts
            WHERE user_id = ? AND quiz_id = ?
              AND created_at BETWEEN ? AND ?
              AND (course_id = ? OR course_id IS NULL)
        ");
        $stmt->execute([$userId, $quizId, $start, $end, $attemptCourseId]);
    } else {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM quiz_attempts
            WHERE user_id = ? AND quiz_id = ?
              AND created_at BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $quizId, $start, $end]);
    }
    $weeklyAttempts = (int)$stmt->fetchColumn();
} else {
    $weeklyAttempts = competitionAttemptsUsed($conn, $quizId, $userId, $quiz, $attemptCourseId);
}
    }
$existingCompletedAttempt = latestCompletedAttempt($conn, $quizId, $userId, $attemptCourseId);

    $takeLocked = false;
    $lockReason = '';

    if ($isCompetition) {
        if ($weeklyAttempts >= 1) {
            $takeLocked = true;
            $lockReason = 'You have already attempted this competition.';
        }
    } elseif ($isLesson) {
        if ($existingCompletedAttempt) {
            $takeLocked = true;
            $lockReason = 'You have already completed this quiz. Review only is available now.';
        }
    } elseif (!$unlimited && $maxAttempts > 0 && $attemptCount >= $maxAttempts && $existingCompletedAttempt) {
        $takeLocked = true;
        $lockReason = 'You have reached the maximum number of attempts for this quiz.';
    }

    // For active competitions, we do NOT create the attempt upfront – it will be created after confirmation.
    $attempt = null;
    $expiresAt = null;
    if (!$isCompetition && !$takeLocked) {
$attempt = getOpenAttempt($conn, $quizId, $userId, $attemptCourseId);
        if (!$attempt) {
            $attemptId = createQuizAttempt($conn, $quizId, $userId);
            if ((int)$attemptId > 0) {
                $attempt = getAttemptById($conn, (int)$attemptId, $userId);
            }
        }
        if ($attempt && !empty($attempt['started_at'])) {
            $timeLimitMinutes = (int)($quiz['time_limit_minutes'] ?? 0);
            if ($timeLimitMinutes > 0) {
                $started = new DateTime($attempt['started_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
                $started->modify('+' . $timeLimitMinutes . ' minutes');
                $expiresAt = $started->format('Y-m-d H:i:s');
            }
        }
    }

    $existingAttemptPayload = $existingCompletedAttempt
        ? buildReviewFromAttempt($existingCompletedAttempt, $questions)
        : null;

    $antiCheatEnabled = $isCompetition && !$takeLocked;
    $timeLimitMinutes = (int)($quiz['time_limit_minutes'] ?? 0);
    $backUrl = '../quizzes.php';
    $modeLabels = [
        'lesson'      => 'Lesson',
        'competition' => 'Competition',
        'self'        => 'Self Practice',
    ];
    $modeLabel = $modeLabels[$quizMode] ?? 'Quiz';
}

// Format end date for JavaScript as ISO 8601 string in local time
// Format end date for JavaScript as ISO 8601 string (already EAT)
$endDateIso = null;
if ($isCompetition && isset($window) && $window) {
    $endDateIso = $window['end']->format('c');
}

function initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : 'U';
}

// Build sidebar + header navigation (same full list as the rest of the site) using baseUrl
$sidebarNavItems = [
    ['name' => 'Dashboard',            'link' => 'user_dashboard.php',      'icon' => '📊'],
    ['name' => 'Subjects',              'link' => 'subjects.php',            'icon' => '📚'],
    ['name' => 'Quizzes',                'link' => 'quizzes.php',              'icon' => '📝'],
    ['name' => 'Monthly Exams',         'link' => 'monthly_exams.php',        'icon' => '📅'],
    ['name' => 'Leaderboard',            'link' => 'leaderboard.php',          'icon' => '🏆'],
    ['name' => 'Certification Exams',   'link' => 'certification_exams.php',  'icon' => '🎓'],
    ['name' => 'My Certificates',       'link' => 'my_certificates.php',      'icon' => '🎓'],
    ['name' => 'Calendar',               'link' => 'calendar.php',             'icon' => '📅'],
    ['name' => 'Multimedia',             'link' => 'multimedia.php',           'icon' => '🖼️'],
    ['name' => 'Notifications',         'link' => 'notification.php',         'icon' => '🔔'],
    ['name' => 'Help Center',            'link' => 'help_center.php',          'icon' => '❓'],
];

$sidebarNavigation = [];
foreach ($sidebarNavItems as $item) {
    $item['active'] = ($item['link'] === 'quizzes.php');
    $item['link'] = $baseUrl . $item['link'];
    $sidebarNavigation[] = $item;
}

$mainNavLinks = ['user_dashboard.php', 'subjects.php', 'quizzes.php', 'monthly_exams.php', 'leaderboard.php', 'multimedia.php', 'notification.php', 'help_center.php'];
$mainNavigation = [];
foreach ($sidebarNavigation as $item) {
    if (in_array(str_replace($baseUrl, '', $item['link']), $mainNavLinks, true)) {
        $mainNavigation[] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo quizE((string)($quiz['title'] ?? 'Take Quiz')); ?> - MyIslam</title>
    <style>
        /* ===== DASHBOARD BASE STYLES (adapted) – COMPLETELY UNCHANGED ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

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
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            padding-bottom: 24px;
            position: relative;
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

        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
        }

        body.dark-mode::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }

        a { text-decoration: none; color: inherit; }
        button { font: inherit; border: none; background: none; cursor: pointer; }

        /* ===== Shared header/sidebar shell (matches user_dashboard.php) ===== */
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
        .menu-toggle { width: 50px; }

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
        .icon-btn { font-size: 18px; color: var(--text); position: relative; }
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

        .page-layout { position: relative; }


        /* Main content wrapper */
        .wrapper {
            max-width: 1000px;
            margin: 0 auto;
            padding: 24px 24px 20px;
        }

        /* Quiz specific cards */
        .card, .quiz-section {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            border: 1px solid var(--line);
        }
        .quiz-section {
            margin-top: 20px;
        }

        .card-body { padding: 1.5rem 2rem; margin: 0; text-align: center; }

        .card-body .info-box,
        .card-body .message-banner,
        .card-body .locked-banner,
        .card-body .quiz-description {
            text-align: left;
        }

        .mode-pill {
            display: inline-block;
            padding: 6px 8px;
            border-radius: 10px;
            border: 1px solid var(--line);        
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 10px;
        }

        .mode-pill.lesson { background: var(--brand-soft); color: var(--brand); }
        .mode-pill.competition { background: var(--gold-soft); color: var(--gold); }
        .mode-pill.self { background: var(--rank-tanzanite-soft); color: var(--rank-tanzanite); }

        h1 { margin-bottom: 12px; color: var(--brand); }

        .meta {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 16px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.75rem 1rem;
        }

        .timer {
            font-weight: 700;
            color: var(--brand);
            font-size: 18px;
            margin-top: 18px;
        }

        .info-box {
            margin-top: 16px;
            padding: 16px;
            border-radius: 10px;
            border-left: 4px solid;
            border-bottom: 1px solid var(--line);
            border-top: 1px solid var(--line);
            border-right: 1px solid var(--line);
        }

        .info-box.competition { background: var(--gold-soft); border-color: var(--gold); }
        .info-box.ended { background: rgba(185, 28, 28, 0.08); border-color: var(--danger); }
        .info-box.self { background: var(--rank-tanzanite-soft); border-color: var(--rank-tanzanite); }
        .info-box.lesson { background: var(--brand-soft); border-color: var(--brand); }

        .attempt-info {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 12px;
        }

        .quiz-header {
            background: linear-gradient(135deg, var(--accent) 0%, var(--brand) 100%);
            color: white;
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--line);
        }

        .quiz-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .quiz-question {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--line);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .question-text {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .question-meta {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 12px;
        }

        .options {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 8px;
        }

        .option {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--surface-2);
            transition: all 0.2s;
        }

        .option:hover {
            background: var(--brand-soft);
            border-color: var(--brand);
        }

        .option input { accent-color: var(--brand); cursor: pointer; }

        img.qimg {
            max-width: 100%;
            max-height: 320px;
            border-radius: 10px;
            border: 1px solid var(--line);
            margin: 12px 0;
        }

        .quiz-actions {
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .submit-btn, .btn {
            background: var(--brand);
            color: white;
            border: none;
            padding: 14px 22px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .submit-btn:hover, .btn:hover {
            background: var(--brand-dark);
            transform: translateY(-1px);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .quiz-result {
            padding: 0;
            background: var(--brand-soft);
            border: 1px solid var(--line);
            border-radius: 10px;
            margin: 0;
        }

        .result-pass {
            color: var(--success);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .result-fail {
            color: var(--danger);
            font-weight: 700;
            font-size: 1.1rem;
        }

        /* Score summary box: an animated, gently-scrolling gradient that reads
           instantly as pass (green) or fail (cool red) in both light and dark mode. */
        @keyframes resultGradientScroll {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .score-summary {
            margin-top: 1rem;
            margin: 6px;
            padding: 1.25rem 1rem;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: var(--surface-2);
        }

        .score-summary.passed {
            border-color: var(--brand);
            background: linear-gradient(120deg, var(--brand-soft), rgba(76, 145, 114, 0.22), var(--brand-soft));
            background-size: 220% 220%;
            animation: resultGradientScroll 7s ease infinite;
        }

        .score-summary.failed {
            border-color: var(--danger);
            background: linear-gradient(120deg, rgba(185, 28, 28, 0.07), var(--accent-soft), rgba(185, 28, 28, 0.07));
            background-size: 220% 220%;
            animation: resultGradientScroll 7s ease infinite;
        }

        .score-summary .score-line { font-size: 1.2rem; font-weight: 700; }
        .score-summary.passed .score-line { color: var(--brand); }
        .score-summary.failed .score-line { color: var(--danger); }

        @media (prefers-reduced-motion: reduce) {
            .score-summary.passed, .score-summary.failed { animation: none; }
        }

        /* Review question cards: correct answers get a clear green treatment,
           incorrect answers a clear red treatment, in both themes. */
        .review-item {
            padding: 1rem;
            margin: 6px;
            border-radius: 10px;
            border: 1px solid var(--line);
            border-left: 4px solid var(--line);
        }

        .review-item.correct {
            background: var(--brand-soft);
            border-color: var(--brand);
            border-left-color: var(--brand);
        }

        .review-item.incorrect {
            background: rgba(185, 28, 28, 0.07);
            border-color: var(--danger);
            border-left-color: var(--danger);
        }

        .locked-banner {
            margin-top: 1rem;
            margin: 6px;
            padding: 0.9rem 1rem;
            border-radius: var(--radius-md);
            background:var(--brand-soft);
            border: 1px solid var(--line);
            border-radius: 10px;
            color: var(--brand);
            font-weight: 600;
        }

        .message-banner {
            margin-top: 1rem;
            padding: 0.9rem 1rem;
            border-radius: var(--radius-md);
            background: var(--gold-soft);
            color: var(--gold);
            font-weight: 600;
            border: 1px solid var(--line);        
        }

        .pending-results {
            text-align: center;
            padding: 2rem;
            background: var(--brand-soft);
            border-radius: var(--radius-md);
            margin: 1rem 0;
            border: 1px solid var(--line);
        }

        .pending-results h3 {
            color: var(--brand);
            margin-bottom: 1rem;
        }

        .pending-results .countdown-timer {
            font-size: 2rem;
            font-weight: 700;
            color: var(--brand);
            margin: 1rem 0;
        }

        .confirmation-card {
            background: var(--surface-2);
            border-radius: 10px;
            padding: 24px;
            margin: 20px 0;
            text-align: center;
            border: 1px solid var(--line);
        }

        .confirmation-card h3 {
            color: var(--brand);
            margin-bottom: 16px;
        }

        .confirmation-card p {
            margin-bottom: 20px;
            color: var(--text-secondary);
        }

        .confirmation-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--brand);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            box-shadow: var(--shadow-lg);
            transform: translateX(400px);
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .toast.show { transform: translateX(0); }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--overlay-dim);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .modal {
            background: var(--surface);
            border-radius: 10px;
            padding: 32px;
            max-width: 500px;
            width: 90%;
            text-align: center;
            border: 1px solid var(--line);
        }

        .modal h2 { margin-bottom: 16px; color: var(--text); }
        .modal p { margin-bottom: 24px; color: var(--text-secondary); line-height: 1.6; }
        .modal ul {
            text-align: left;
            margin: 20px 0;
            color: var(--text-secondary);
            padding-left: 24px;
        }
        .modal .btn {
            width: auto;
            padding: 12px 30px;
            margin: 5px;
            border: 1px solid var(--line);
        }
        .modal .btn.warning { background: var(--warning); }
        .modal .btn.danger { background: var(--danger); }

        .strike-indicator {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin: 20px 0;
        }
        .strike-dot {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--surface-3);
        }
        .strike-dot.active { background: var(--warning); }
        .strike-dot.danger { background: var(--danger); }

        .watermark-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            z-index: 9998;
            opacity: 0.05;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 80px;
            font-weight: 900;
            color: #000;
            transform: rotate(-25deg);
            white-space: nowrap;
            text-transform: uppercase;
        }

        @media (max-width: 1200px) {
            .header-top-nav { max-width: 40vw; }
        }

        @media (max-width: 900px) {
            .header-top-nav { display: none; }
        }

        @media (max-width: 768px) {
            :root { --header-height: 64px; }
            body { padding: 0; }
            .wrapper { padding: 10px; }
            .card-body, .quiz-header, .quiz-question, .quiz-actions, .quiz-result { padding: 10px; }
            .question-header { flex-direction: column; }
            .toast { left: 1rem; right: 1rem; bottom: 1rem; text-align: center; }
            .confirmation-actions { flex-direction: column; }
            .app-header { padding: 12px 16px; }
            .quiz-result { padding: 0px; }
        }
    </style>
</head>
<body>

<?php if ($antiCheatEnabled): ?>
<!-- Watermark overlay -->
<div class="watermark-overlay" id="watermark"><?= quizE($userId) ?> - <?= date('Y-m-d') ?></div>

<!-- Instructions Modal -->
<div class="modal-overlay" id="instructionsModal" style="display: none;">
    <div class="modal">
        <h2>🏆 Competition Instructions</h2>
        <p><strong><?php echo quizE($quiz['title']); ?></strong></p>
        <p><?php echo quizE($quiz['description'] ?? 'Read carefully.'); ?></p>
        <ul>
            <li><strong>⏱️ Time limit:</strong> <?php echo $timeLimitMinutes ? $timeLimitMinutes . ' minutes' : 'No time limit'; ?></li>
            <li><strong>🎯 Passing score:</strong> <?php echo (int)($quiz['passing_score'] ?? 70); ?>%</li>
            <li><strong>📝 Questions:</strong> <?php echo count($gradableQuestions); ?> total</li>
            <li><strong>🚫 Do NOT</strong> switch tabs, minimize, leave this window, or attempt screenshots.</li>
            <li><strong>📸 Screenshots are strictly prohibited</strong> and count as violations.</li>
            <li><strong>⚠️ 3 violations</strong> will terminate your attempt.</li>
            <li><strong>⏳ You have 10 seconds to resume</strong> after any warning.</li>
            <li><strong>✅ Answer all questions</strong> before submitting.</li>
        </ul>
        <button class="btn" id="startCompetitionBtn">I Understand, Start Competition</button>
    </div>
</div>

<!-- Warning Modal -->
<div class="modal-overlay" id="warningModal" style="display: none;">
    <div class="modal">
        <h2 id="warningTitle">⚠️ Warning</h2>
        <p id="warningMessage">You have left the quiz window.</p>
        <div class="strike-indicator" id="strikeIndicator">
            <span class="strike-dot" id="strike1"></span>
            <span class="strike-dot" id="strike2"></span>
            <span class="strike-dot" id="strike3"></span>
        </div>
        <p>Violation <strong><span id="strikeCountDisplay">1</span> of 3</strong>.<br>
           Third violation terminates the attempt.</p>
        <p id="countdownWrapper">⏳ Resume within <strong><span id="countdownDisplay">10</span></strong> seconds or get another violation.</p>
        <button class="btn warning" id="resumeCompetitionBtn">Resume</button>
    </div>
</div>
<?php endif; ?>

<!-- Time‑Up Modal -->
<div class="modal-overlay" id="timeUpModal" style="display: none;">
    <div class="modal" style="max-width: 450px;">
        <div style="font-size: 48px; margin-bottom: 16px;">⏰</div>
        <h2 style="color: var(--text); margin-bottom: 12px;">Time's Up!</h2>
        <p style="margin-bottom: 24px; color: var(--text-secondary);" id="timeUpMessage">
            Your quiz time has expired.<br>
            Click the button below to see your results.
        </p>
        <button class="btn" id="viewResultBtn" style="width: 100%; padding: 14px;">
            See Result
        </button>
        <button class="btn" id="cancelTimeUpBtn" style="background: var(--muted); margin-top: 10px; width: 100%;">
            Return to Quizzes
        </button>
    </div>
</div>

<!-- ===== HEADER (consistent with dashboard) ===== -->
<a href="#quizWrapper" class="skip-link">Skip to main content</a>

<header class="app-header">
    <div class="header-left">
        <a href="<?php echo quizE($baseUrl); ?>user_dashboard.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
            <img src="<?php echo quizE($baseUrl); ?>includes/logo.png" alt="MyIslam" style="height:36px;width:36px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px;" onerror="this.style.display='none'">
            <span style="font-size:20px;font-weight:800;color:var(--brand);">MyIslam</span>
        </a>

        <nav class="header-top-nav" aria-label="Main navigation">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?php echo quizE($navItem['link']); ?>" class="header-top-link <?php echo !empty($navItem['active']) ? 'active' : ''; ?>">
                    <?php echo quizE($navItem['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Toggle dark mode">🌙</button>
        <button class="lang-btn" id="langToggle" type="button">EN</button>
        <a href="<?php echo quizE($baseUrl); ?>notification.php" class="icon-btn" style="position: relative;" aria-label="Notifications">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if (!empty($user['picture'])): ?>
                    <img src="<?php echo quizE($user['picture']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo initials($user['name']); ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?php echo quizE($user['picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo initials($user['name']); ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?php echo quizE($user['name']); ?></h4>
                        <p><?php echo quizE($user['email']); ?></p>
                        <p style="font-size: 10px;">Member since <?php echo quizE($user['member_since']); ?></p>
                    </div>
                </div>
                <a href="<?php echo quizE($baseUrl); ?>profile.php">👤 My Profile</a>
                <a href="<?php echo quizE($baseUrl); ?>settings.php">⚙️ Settings</a>
                <a href="<?php echo quizE($baseUrl); ?>logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>
<button class="sidebar-toggle-btn" id="menuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">☰</button>
<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">📚 Navigation</div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo quizE($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo quizE($item['icon']); ?></span>
                    <span><?php echo quizE($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

<div class="wrapper" id="quizWrapper">
    <div class="card">
        <div class="card-body">
            <span class="mode-pill <?php echo quizE($quizMode); ?>">
                <?php echo quizE($modeLabel); ?> Mode
            </span>

            <h1><?php echo quizE((string)($quiz['title'] ?? 'Untitled Quiz')); ?></h1>

            <div class="meta">
                <span>Subject: <?php echo quizE((string)($quiz['subject_name'] ?? 'General')); ?></span>
                <?php if (!empty($quiz['category_name'])): ?>
                    <span>Category: <?php echo quizE((string)$quiz['category_name']); ?></span>
                <?php endif; ?>
                <span>Difficulty: <?php echo quizE(ucfirst((string)($quiz['difficulty'] ?? 'medium'))); ?></span>
                <span>Total Points: <?php echo (int)($quiz['total_points'] ?? 0); ?></span>
                <?php if (isset($quiz['passing_score'])): ?>
                    <span>Passing Score: <?php echo (int)$quiz['passing_score']; ?>%</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($quiz['description'])): ?>
                <p class="quiz-description"><?php echo nl2br(quizE((string)$quiz['description'])); ?></p>
            <?php endif; ?>

            <?php if ($isCompetition): ?>
                <div class="info-box competition <?php echo $competitionEnded ? 'ended' : ''; ?>">
                    <strong>🏆 Weekly Competition</strong><br>
                    <span>Window: <?php echo quizE($windowLabel); ?></span><br>
                    <?php if ($competitionEnded): ?>
                        <span>This competition has ended.</span>
                    <?php else: ?>
                        <span>One attempt is allowed during this period. Your score will appear on the leaderboard after the competition ends.</span>
                    <?php endif; ?>
                    <?php if (!empty($quiz['prize_description'])): ?>
                        <br><span>🏅 Prize: <?php echo quizE((string)$quiz['prize_description']); ?></span>
                    <?php endif; ?>
                </div>
            <?php elseif ($isSelf): ?>
                <div class="info-box self">
                    <strong>🔍 Self-Practice Mode</strong><br>
                    <span>This quiz is for personal review and revision.</span>
                </div>
            <?php else: ?>
                <div class="info-box lesson">
                    <strong>📖 Lesson Quiz</strong><br>
                    <span>Complete this quiz to test your understanding of the lesson material.</span>
                </div>
            <?php endif; ?>

            <div class="attempt-info">
                <?php if ($isCompetition): ?>
                    Weekly attempt: <?php echo $weeklyAttempts; ?> / 1
                <?php else: ?>
                    Attempts used: <?php echo $attemptCount; ?>
                    <?php if (!$unlimited && $maxAttempts > 0): ?> / <?php echo $maxAttempts; ?><?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($expiresAt): ?>
                <p class="timer">⏱️ Time remaining: <span id="timer"></span></p>
            <?php endif; ?>

            <?php if ($takeLocked && $lockReason): ?>
                <div class="message-banner"><?php echo quizE($lockReason); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isCompetition && !$takeLocked && !$competitionEnded): ?>
    <!-- Confirmation card -->
    <div class="confirmation-card" id="confirmationCard">
        <h3>🏁 Ready to Compete?</h3>
        <p>This is your one attempt for this competition. Once you start, the timer will begin and you cannot pause.</p>
        <p><strong>Time Limit:</strong> <?php echo $timeLimitMinutes ? $timeLimitMinutes . ' minutes' : 'No time limit'; ?></p>
        <div class="confirmation-actions">
            <button class="btn" id="confirmJoinBtn">Yes, Start Competition</button>
            <a href="<?php echo quizE($backUrl); ?>" class="btn" style="background: var(--muted);">Cancel</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="quiz-section" id="quizSection" <?php echo ($isCompetition && !$takeLocked && !$competitionEnded) ? 'style="display:none;"' : ''; ?>>
        <div class="quiz-header">
            <div class="quiz-title">📝 <?php echo quizE((string)($quiz['title'] ?? 'Quiz')); ?></div>
            <p>
                <?php if ($isCompetition): ?>
                    <?php echo $competitionEnded ? 'Review mode – competition has ended.' : 'Competition mode. Submit once within the active window.'; ?>
                <?php elseif ($isSelf): ?>
                    Self practice mode. Review your performance and revise with the correct answers.
                <?php else: ?>
                    Lesson mode. Review your understanding and revise from the results.
                <?php endif; ?>
            </p>
        </div>

        <div id="quizContainer">
            <?php if ($existingAttemptPayload && ($takeLocked || $competitionEnded)): ?>
                <?php if ($existingCompletedAttempt && $existingCompletedAttempt['status'] === 'abandoned'): ?>
                    <div class="message-banner" style="margin:1rem 0;">⚠️ This attempt was terminated due to rule violations.</div>
                <?php endif; ?>
                <div id="quizResult" class="quiz-result" style="display:block;"></div>
                
            <?php else: ?>
                <form id="quizForm" novalidate>
                    <input type="hidden" id="quiz_id" value="<?php echo (int)$quizId; ?>">
                    <input type="hidden" id="attempt_id" value="<?php echo (int)($attempt['id'] ?? 0); ?>">

                    <?php foreach ($questions as $index => $question): 
                        $qid = (int)$question['id'];
                        $qtype = $question['question_type'] ?? 'multiple_choice';
                        $options = $question['options'] ?? [];
                    ?>
                    <div class="quiz-question" data-question-id="<?php echo $qid; ?>">
                        <div class="question-header">
                            <div class="question-text">
                                <strong><?php echo ($index + 1) . '. '; ?></strong>
                                <?php echo quizE((string)($question['question_text'] ?? '')); ?>
                            </div>
                        </div>

                        <div class="question-meta">
                            Points: <?php echo (int)($question['points'] ?? 0); ?>
                            <?php if ((int)($question['negative_points'] ?? 0) > 0): ?>
                                | Negative Marking: -<?php echo (int)$question['negative_points']; ?>
                            <?php endif; ?>
                            <?php if (!empty($question['difficulty'])): ?>
                                | Difficulty: <?php echo quizE(ucfirst((string)$question['difficulty'])); ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($question['image_path'])): 
                            $imgSrc = $question['image_path'];
                            if (!preg_match('/^(http|https):\/\//', $imgSrc) && $imgSrc[0] !== '/') {
                                $imgSrc = $baseUrl . ltrim($imgSrc, '/');
                            } elseif ($imgSrc[0] === '/') {
                                $imgSrc = rtrim($baseUrl, '/') . $imgSrc;
                            }
                            $imgSrc = str_replace(' ', '%20', $imgSrc);
                        ?>
                            <img class="qimg" src="<?php echo quizE($imgSrc); ?>" alt="Question image">
                        <?php endif; ?>

                        <?php if ($qtype === 'explanation'): ?>
                            <textarea
                                name="q_<?php echo $qid; ?>"
                                rows="1"
                                style="width:100%; padding:12px; border-radius:12px; border:1px solid var(--line); font-family:inherit; margin-top:10px; resize: vertical; background: var(--surface-2); color: var(--text);"
                                placeholder="Type your explanation here..."
                            ></textarea>
                        <?php elseif (!empty($options)): ?>
                            <div class="options">
                                <?php foreach ($options as $option): ?>
                                    <label class="option">
                                        <input
                                            type="radio"
                                            name="q_<?php echo $qid; ?>"
                                            value="<?php echo (int)$option['id']; ?>"
                                            required
                                        >
                                        <span><?php echo quizE((string)($option['option_text'] ?? '')); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="question-meta" style="color: var(--danger);">⚠️ This question has no options. Please contact support.</p>
                        <?php endif; ?>

                        <?php if (($quiz['hints_enabled'] ?? false) && !empty($question['explanation'])): ?>
                            <details style="margin-top:12px;">
                                <summary style="cursor:pointer; color:var(--brand); font-weight:500;">💡 Hint</summary>
                                <p style="margin-top:8px; padding:10px; background:var(--surface-2); border-radius:8px;">
                                    <?php echo nl2br(quizE((string)$question['explanation'])); ?>
                                </p>
                            </details>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <div class="quiz-actions">
                        <button type="button" class="submit-btn" id="submitQuizBtn">Submit Quiz</button>
                    </div>
                </form>

                <div id="quizResult" style="display:none;"></div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<div id="toast" class="toast"></div>

<script>
// Pass PHP data to JavaScript
window.quizTakeData = {
    quizId: <?php echo (int)$quizId; ?>,
    attemptId: <?php echo (int)($attempt['id'] ?? 0); ?>,
    mode: <?php echo json_encode($quizMode); ?>,
    passingScore: <?php echo (int)($quiz['passing_score'] ?? 70); ?>,
    existingAttempt: <?php echo json_encode($existingAttemptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    questions: <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    submitEndpoint: <?php echo json_encode(dirname($_SERVER['PHP_SELF']) . '/quiz_submit.php'); ?>,
    locked: <?php echo $takeLocked ? 'true' : 'false'; ?>,
    expiresAt: <?php echo json_encode($expiresAt); ?>,
    antiCheatEnabled: <?php echo $antiCheatEnabled ? 'true' : 'false'; ?>,
    timeLimitMinutes: <?php echo $timeLimitMinutes; ?>,
    competitionActive: <?php echo $competitionActive ? 'true' : 'false'; ?>,
    competitionEnded: <?php echo $competitionEnded ? 'true' : 'false'; ?>,
    competitionEnd: <?php echo json_encode($endDateIso); ?>,
    baseUrl: <?php echo json_encode($baseUrl); ?>,
    backUrl: <?php echo json_encode($backUrl); ?>
};

// ===== HEADER & SIDEBAR FUNCTIONALITY =====
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
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        if (body) body.style.overflow = '';
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

    // Theme
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

    // Language
    let currentLang = localStorage.getItem('language') || 'EN';
    if (langToggle) {
        langToggle.textContent = currentLang;
        langToggle.addEventListener('click', () => {
            currentLang = currentLang === 'EN' ? 'SW' : 'EN';
            langToggle.textContent = currentLang;
            localStorage.setItem('language', currentLang);
        });
    }

    // Profile dropdown
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
})();

// ===== QUIZ FUNCTIONALITY =====
const toast = document.getElementById('toast');
const submitQuizBtn = document.getElementById('submitQuizBtn');
const quizForm = document.getElementById('quizForm');
const timeUpModal = document.getElementById('timeUpModal');
const viewResultBtn = document.getElementById('viewResultBtn');
const cancelTimeUpBtn = document.getElementById('cancelTimeUpBtn');
const confirmJoinBtn = document.getElementById('confirmJoinBtn');
const confirmationCard = document.getElementById('confirmationCard');
const quizSection = document.getElementById('quizSection');
const instructionsModal = document.getElementById('instructionsModal');
const startBtn = document.getElementById('startCompetitionBtn');

const ACTIVE_ATTEMPT_KEY = 'quiz_active_' + window.quizTakeData.attemptId;

let examActive = false;
let violations = 0;
const MAX_VIOLATIONS = 3;
let warningModalVisible = false;
let warningTimeout = null;
let warningCountdown = 10;
let countdownInterval = null;

let submitInProgress = false;
let timerInterval = null;

const warningModal = document.getElementById('warningModal');
const warningTitle = document.getElementById('warningTitle');
const warningMessage = document.getElementById('warningMessage');
const strikeCountDisplay = document.getElementById('strikeCountDisplay');
const resumeBtn = document.getElementById('resumeCompetitionBtn');
const strike1 = document.getElementById('strike1');
const strike2 = document.getElementById('strike2');
const strike3 = document.getElementById('strike3');
const countdownDisplay = document.getElementById('countdownDisplay');
const countdownWrapper = document.getElementById('countdownWrapper');

function showToast(message, isError = false) {
    if (!toast) return;
    toast.textContent = message;
    toast.style.background = isError ? 'var(--danger)' : 'var(--brand)';
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 5000);
}

async function fetchWithTimeout(url, options, timeout = 20000) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);
    try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        clearTimeout(timeoutId);
        return response;
    } catch (error) {
        clearTimeout(timeoutId);
        throw error;
    }
}

function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function optionTextForQuestion(question, optionId) {
    if (!question || !Array.isArray(question.options)) return '';
    const match = question.options.find(opt => String(opt.id) === String(optionId));
    return match ? (match.option_text || '') : '';
}

function normalizeResults(results, questions) {
    if (!Array.isArray(results)) return [];
    return results.map((result, idx) => {
        const question = questions.find(q => String(q.id) === String(result.question_id)) || questions[idx] || null;
        const userAnswer = result.user_answer_text
            ?? optionTextForQuestion(question, result.user_answer)
            ?? result.user_answer
            ?? '';
        const correctAnswer = result.correct_answer_text
            ?? optionTextForQuestion(question, result.correct_answer)
            ?? result.correct_answer
            ?? '';

        return {
            ...result,
            question_text: result.question_text || (question ? question.question_text : `Question ${idx + 1}`),
            user_answer_display: userAnswer,
            correct_answer_display: correctAnswer
        };
    });
}

function renderPendingResults() {
    const resultBox = document.getElementById('quizResult');
    if (!resultBox) return;
    
    const endTime = window.quizTakeData.competitionEnd ? new Date(window.quizTakeData.competitionEnd).getTime() : null;
    
    let html = `
        <div class="quiz-result">
            <div class="pending-results">
                <h3>📊 Results Pending</h3>
                <p>Your attempt has been recorded successfully.</p>
                <p>Results will be available after the competition ends.</p>
    `;
    
    if (endTime && endTime > Date.now()) {
        html += `
                <div class="countdown-timer" id="resultsCountdown"></div>
                <p>Check back after the competition window closes to see your detailed score and review.</p>
        `;
    } else {
        html += `
                <p>The competition has ended. <a href="javascript:location.reload()">Refresh the page</a> to see your results.</p>
        `;
    }
    
    html += `
                <a href="${window.quizTakeData.backUrl}" class="btn" style="margin-top: 1rem;">Back to Quizzes</a>
            </div>
        </div>
    `;
    
    resultBox.innerHTML = html;
    resultBox.style.display = 'block';
    
    if (quizForm) quizForm.style.display = 'none';
    
    if (endTime && endTime > Date.now()) {
        const countdownEl = document.getElementById('resultsCountdown');
        if (countdownEl) {
            function updateResultsCountdown() {
                const now = Date.now();
                const diff = endTime - now;
                if (diff <= 0) {
                    countdownEl.textContent = 'Results are now available!';
                    clearInterval(window.resultsCountdownInterval);
                    return;
                }
                const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                const hours = Math.floor((diff % 86400000) / 3600000);
                const minutes = Math.floor((diff % 3600000) / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                let timeStr = '';
                if (days > 0) timeStr += days + 'd ';
                timeStr += String(hours).padStart(2, '0') + ':' +
                           String(minutes).padStart(2, '0') + ':' +
                           String(seconds).padStart(2, '0');
                countdownEl.textContent = timeStr;
            }
            updateResultsCountdown();
            window.resultsCountdownInterval = setInterval(updateResultsCountdown, 1000);
        }
    }
}

function renderAttemptResult(data, questions) {
    const resultBox = document.getElementById('quizResult');
    if (!resultBox) return;

    if (window.quizTakeData.mode === 'competition' && window.quizTakeData.competitionActive) {
        renderPendingResults();
        return;
    }

    const passed = !!data.passed;
    const percentage = Number(data.percentage || 0);
    const score = Number(data.score || 0);
    const total = Number(data.total || 0);
    const correctCount = Number(data.correct_count || 0);
    const totalQuestions = Number(data.total_questions || 0);
    const results = normalizeResults(Array.isArray(data.results) ? data.results : [], questions);
    const hasUserAnswers = data.has_user_answers !== false;

    let html = `
        <div class="quiz-result">
            <div class="${passed ? 'result-pass' : 'result-fail'}">
                ${passed ? '🎉 Congratulations! You passed this quiz.' : '❌ Quiz completed. Review your result and revise below.'}
            </div>
    `;

    if (hasUserAnswers || (score > 0 || total > 0)) {
        html += `
            <div class="score-summary ${passed ? 'passed' : 'failed'}">
                <p class="score-line">Your Score: ${score}/${total} (${percentage}%)</p>
                <p style="color:var(--muted);">Correct answers: ${correctCount} of ${totalQuestions} questions</p>
                <p style="color:var(--muted);">Passing score: ${window.quizTakeData.passingScore}%</p>
            </div>
        `;
    }

    html += `
            <div class="locked-banner">🔒 This quiz submission is now locked. Review only is available.</div>
            <h3 style="margin:1.5rem 0 1rem;">📋 Review Questions and Correct Answers</h3>
            <div style="display:grid; gap:1rem;">
    `;

    results.forEach((result, idx) => {
        const isCorrect = !!result.is_correct;
        const questionText = escapeHtml(result.question_text || `Question ${idx + 1}`);
        const userAnswer = result.user_answer_display ? escapeHtml(result.user_answer_display) : '<em>No answer</em>';
        const correctAnswer = result.correct_answer_display ? escapeHtml(result.correct_answer_display) : '<em>Not available</em>';
        const explanation = result.explanation ? escapeHtml(result.explanation) : '';

        const question = window.quizTakeData.questions.find(q => String(q.id) === String(result.question_id));
        let questionImage = question && question.image_path ? question.image_path : null;
        let imageHtml = '';
        if (questionImage) {
            let imgSrc = questionImage;
            if (!imgSrc.match(/^(http|https):\/\//) && imgSrc[0] !== '/') {
                imgSrc = window.quizTakeData.baseUrl + imgSrc.replace(/^\/+/, '');
            } else if (imgSrc[0] === '/') {
                imgSrc = window.quizTakeData.baseUrl.replace(/\/$/, '') + imgSrc;
            }
            imgSrc = imgSrc.replace(/ /g, '%20');
            imageHtml = `<div style="margin: 10px 0;"><img src="${escapeHtml(imgSrc)}" style="max-width:100%; max-height:150px; border-radius:8px; border:1px solid var(--line);"></div>`;
        }

        const userAnswerRow = hasUserAnswers
            ? `<p style="margin:.5rem 0;"><span style="color:var(--muted);">Your answer:</span> ${userAnswer} ${isCorrect ? '✅' : '❌'}</p>`
            : '';

        html += `
            <div class="review-item ${isCorrect ? 'correct' : 'incorrect'}">
                <p style="font-weight:700;">${idx + 1}. ${questionText}</p>
                ${imageHtml}
                ${userAnswerRow}
                <p style="margin:.5rem 0;"><span style="color:var(--muted);">Correct answer:</span> ${correctAnswer}</p>
                ${explanation ? `<p style="margin:.5rem 0; font-size:.9rem; color:var(--muted);">💡 ${explanation}</p>` : ''}
            </div>
        `;
    });

    html += '</div></div>';
    resultBox.innerHTML = html;
    resultBox.style.display = 'block';

    if (quizForm) quizForm.style.display = 'none';
}

function showCongratsModal(score, total, percentage) {
    if (window.quizTakeData.mode === 'competition' && window.quizTakeData.competitionActive && !window.quizTakeData.competitionEnded) {
        return;
    }
    const modal = document.createElement('div');
    modal.style.cssText = `
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 1rem;
    `;
    modal.innerHTML = `
        <div style="background:var(--surface); padding:2rem; border-radius:20px; max-width:420px; width:100%; text-align:center; box-shadow:0 20px 40px rgba(0,0,0,.2);">
            <div style="font-size:60px; margin-bottom:1rem;">🎉</div>
            <h2 style="color:var(--brand); margin-bottom:1rem;">Congratulations!</h2>
            <p style="font-size:1.2rem; margin-bottom:1rem;">You scored ${percentage}%</p>
            <p style="color:var(--muted); margin-bottom:1.5rem;">Score: ${score}/${total}</p>
            <button class="btn" id="closeCongratsModal">Continue</button>
        </div>
    `;
    document.body.appendChild(modal);

    modal.querySelector('#closeCongratsModal')?.addEventListener('click', () => modal.remove());
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
    });
}

if (window.quizTakeData.existingAttempt) {
    renderAttemptResult(window.quizTakeData.existingAttempt, window.quizTakeData.questions);
}

function collectAnswers() {
    const answers = {};
    if (!quizForm) return answers;
    const inputs = quizForm.querySelectorAll('input, textarea');
    inputs.forEach(input => {
        if (!input.name) return;
        const match = input.name.match(/q_(\d+)/);
        if (!match) return;
        const qid = match[1];
        if (input.type === 'radio') {
            if (input.checked) answers[qid] = input.value;
        } else if (input.tagName === 'TEXTAREA') {
            answers[qid] = input.value.trim();
        }
    });
    return answers;
}

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

function cleanupAfterSubmission() {
    examActive = false;
    warningModalVisible = false;
    clearWarningTimers();
    
    if (timeUpModal) {
        timeUpModal.style.display = 'none';
        document.body.style.overflow = '';
    }
    if (warningModal) {
        warningModal.style.display = 'none';
    }
    
    if (window._preventEscapeHandler) {
        document.removeEventListener('keydown', window._preventEscapeHandler, true);
        delete window._preventEscapeHandler;
    }
    
    sessionStorage.removeItem(ACTIVE_ATTEMPT_KEY);
}

async function performSubmission(answers) {
    if (submitInProgress) return false;
    
    if (window.quizTakeData.antiCheatEnabled) {
        examActive = false;
        clearWarningTimers();
    }
    
    submitInProgress = true;

    if (submitQuizBtn) {
        submitQuizBtn.disabled = true;
        submitQuizBtn.textContent = '⏳ Submitting...';
    }

    const payload = {
        quiz_id: window.quizTakeData.quizId,
        attempt_id: window.quizTakeData.attemptId,
        mode: window.quizTakeData.mode,
        answers: answers,
        auto_submit: true
    };

    try {
        const response = await fetchWithTimeout(window.quizTakeData.submitEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            if (data.attempted && data.attempt_data) {
                renderAttemptResult(data.attempt_data, window.quizTakeData.questions);
                showToast(data.message || 'This quiz has already been submitted.', true);
                return false;
            }
            throw new Error(data.message || 'Failed to submit quiz');
        }

        const attemptData = {
            passed: data.passed,
            percentage: data.percentage,
            score: data.score,
            total: data.total,
            correct_count: data.correct_count || 0,
            total_questions: data.total_questions || window.quizTakeData.questions.filter(q => q.question_type !== 'explanation').length,
            results: data.results || [],
            attempted: true,
            locked: true,
            has_user_answers: true
        };

        renderAttemptResult(attemptData, window.quizTakeData.questions);
        window.quizTakeData.locked = true;

        cleanupAfterSubmission();

        if (data.passed) {
            showCongratsModal(data.score, data.total, data.percentage);
        }
        return true;
    } catch (error) {
        console.error('Submission error:', error);
        showToast(error.message || 'Unable to submit quiz. Please try again.', true);
        
        if (window.quizTakeData.antiCheatEnabled && !window.quizTakeData.locked) {
            examActive = true;
        }
        return false;
    } finally {
        submitInProgress = false;
    }
}

function showTimeUpModal() {
    if (timeUpModal.style.display === 'flex') return;
    
    document.body.style.overflow = 'hidden';
    timeUpModal.style.display = 'flex';
    
    const preventEscape = (e) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
        }
    };
    document.addEventListener('keydown', preventEscape, true);
    window._preventEscapeHandler = preventEscape;
}

function hideTimeUpModal() {
    if (timeUpModal) {
        timeUpModal.style.display = 'none';
        document.body.style.overflow = '';
    }
    if (window._preventEscapeHandler) {
        document.removeEventListener('keydown', window._preventEscapeHandler, true);
        delete window._preventEscapeHandler;
    }
}

async function handleTimeExpired() {
    if (submitInProgress || window.quizTakeData.locked) return;

    // Show modal immediately
    if (timeUpModal) {
        const modalMessage = document.getElementById('timeUpMessage');
        const viewBtn = document.getElementById('viewResultBtn');
        if (modalMessage) modalMessage.textContent = 'Your time has expired. Submitting your answers automatically...';
        if (viewBtn) {
            viewBtn.disabled = true;
            viewBtn.textContent = 'Submitting...';
        }
        timeUpModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    const answers = collectAnswers();
    const success = await performSubmission(answers);

    if (success) {
        hideTimeUpModal();
        showToast('Quiz submitted successfully.', false);
    } else {
        // Auto‑submit failed – allow manual retry
        if (timeUpModal) {
            const modalMessage = document.getElementById('timeUpMessage');
            const viewBtn = document.getElementById('viewResultBtn');
            if (modalMessage) {
                modalMessage.textContent = 'Auto‑submission failed. Please try again manually.';
                modalMessage.style.color = 'var(--danger)';
            }
            if (viewBtn) {
                viewBtn.disabled = false;
                viewBtn.textContent = 'Retry Submission';
            }
        }
        // Keep modal open so user can retry or exit
    }
}

if (viewResultBtn) {
    viewResultBtn.addEventListener('click', async () => {
        const answers = collectAnswers();
        viewResultBtn.disabled = true;
        viewResultBtn.textContent = 'Submitting...';
        const success = await performSubmission(answers);
        if (success) {
            hideTimeUpModal();
            showToast('Quiz submitted successfully.', false);
        } else {
            viewResultBtn.disabled = false;
            viewResultBtn.textContent = 'Retry Submission';
            const modalMessage = document.getElementById('timeUpMessage');
            if (modalMessage) {
                modalMessage.textContent = 'Submission failed. Check your connection and try again.';
                modalMessage.style.color = 'var(--danger)';
            }
        }
    });
}

if (cancelTimeUpBtn) {
    cancelTimeUpBtn.addEventListener('click', () => {
        window.location.href = window.quizTakeData.backUrl;
    });
}

if (submitQuizBtn && quizForm && !window.quizTakeData.locked) {
    submitQuizBtn.addEventListener('click', async () => {
        if (submitInProgress) return;
        const answers = collectAnswers();
        const totalGradable = window.quizTakeData.questions.filter(q => q.question_type !== 'explanation').length;
        if (Object.keys(answers).length < totalGradable) {
            showToast(`Please answer all ${totalGradable} questions before submitting.`, true);
            return;
        }
        await performSubmission(answers);
    });
}

// Timer countdown
if (window.quizTakeData.expiresAt && !window.quizTakeData.locked) {
    const endTime = new Date(String(window.quizTakeData.expiresAt).replace(' ', 'T')).getTime();
    const timerEl = document.getElementById('timer');

    function updateTimerDisplay() {
        const now = Date.now();
        const diff = endTime - now;
        if (diff <= 0) {
            if (timerEl) timerEl.textContent = '00:00';
            if (timerInterval) clearInterval(timerInterval);
            handleTimeExpired();
            return;
        }
        const mins = Math.floor(diff / 60000);
        const secs = Math.floor((diff % 60000) / 1000);
        if (timerEl) timerEl.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }
    updateTimerDisplay();
    timerInterval = setInterval(updateTimerDisplay, 1000);
}

// Competition confirmation flow
if (confirmJoinBtn) {
    confirmJoinBtn.addEventListener('click', async () => {
        confirmJoinBtn.disabled = true;
        confirmJoinBtn.textContent = 'Creating attempt...';
        
        try {
            const formData = new FormData();
            formData.append('action', 'create_competition_attempt');
            formData.append('quiz_id', window.quizTakeData.quizId);
            
            const response = await fetch('quiz_take.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (!data.success) {
                showToast(data.message || 'Failed to start competition', true);
                confirmJoinBtn.disabled = false;
                confirmJoinBtn.textContent = 'Yes, Start Competition';
                return;
            }
            
            window.quizTakeData.attemptId = data.attempt_id;
            document.getElementById('attempt_id').value = data.attempt_id;
            
            confirmationCard.style.display = 'none';
            instructionsModal.style.display = 'flex';
        } catch (e) {
    console.error('START COMPETITION ERROR:', e);
    showToast('Could not start: ' + e.message, true);
    confirmJoinBtn.disabled = false;
    confirmJoinBtn.textContent = 'Yes, Start Competition';
}
    });
}

// Anti-cheat setup
if (startBtn) {
    startBtn.addEventListener('click', async () => {
        instructionsModal.style.display = 'none';
        quizSection.style.display = 'block';
        examActive = true;
        sessionStorage.setItem(ACTIVE_ATTEMPT_KEY, '1');
        try { await document.documentElement.requestFullscreen(); } catch (e) {}
        attachAntiCheat();
        
        if (window.quizTakeData.timeLimitMinutes > 0) {
            const startTime = new Date();
            const endTime = new Date(startTime.getTime() + window.quizTakeData.timeLimitMinutes * 60000);
            window.quizTakeData.expiresAt = endTime.toISOString().slice(0, 19).replace('T', ' ');
            
            const timerEl = document.getElementById('timer');
            if (!timerEl) {
                const timerPara = document.createElement('p');
                timerPara.className = 'timer';
                timerPara.innerHTML = '⏱️ Time remaining: <span id="timer"></span>';
                document.querySelector('.card-body').appendChild(timerPara);
            }
            
            const endTimeMs = endTime.getTime();
            function updateTimer() {
                const diff = endTimeMs - Date.now();
                if (diff <= 0) {
                    if (timerInterval) clearInterval(timerInterval);
                    handleTimeExpired();
                    return;
                }
                const mins = Math.floor(diff / 60000);
                const secs = Math.floor((diff % 60000) / 1000);
                const span = document.getElementById('timer');
                if (span) span.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
            }
            updateTimer();
            timerInterval = setInterval(updateTimer, 1000);
        }
    });
}

function attachAntiCheat() {
    document.addEventListener('contextmenu', e => e.preventDefault());
    document.addEventListener('keydown', e => {
        if (!examActive || warningModalVisible) return;
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

    window.addEventListener('beforeunload', e => {
        if (examActive) {
            e.preventDefault();
            e.returnValue = '';
            beaconAbandon();
        }
    });

    window.addEventListener('pagehide', () => {
        if (examActive) {
            beaconAbandon();
            sessionStorage.removeItem(ACTIVE_ATTEMPT_KEY);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (examActive && document.hidden) {
            handleViolation('tab_switch');
            beaconAbandon();
        }
    });

    window.addEventListener('blur', () => {
        if (examActive) handleViolation('window_blur');
    });

    let lastBlur = 0;
    window.addEventListener('blur', () => { lastBlur = Date.now(); });
    window.addEventListener('focus', () => {
        if (examActive && lastBlur > 0) {
            const diff = Date.now() - lastBlur;
            if (diff > 500 && diff < 3000) handleViolation('suspicious_activity');
        }
    });
}

async function abandonAttempt() {
    const formData = new FormData();
    formData.append('action', 'abandon_attempt');
    formData.append('quiz_id', window.quizTakeData.quizId);
    formData.append('attempt_id', window.quizTakeData.attemptId);
    try {
        await fetch('quiz_take.php', { method: 'POST', body: formData });
    } catch (e) {}
}

function beaconAbandon() {
    const formData = new FormData();
    formData.append('action', 'abandon_attempt');
    formData.append('quiz_id', window.quizTakeData.quizId);
    formData.append('attempt_id', window.quizTakeData.attemptId);
    if (navigator.sendBeacon) {
        navigator.sendBeacon('quiz_take.php', formData);
    } else {
        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'quiz_take.php', false);
            xhr.send(formData);
        } catch (e) {}
    }
}

async function checkStaleActiveAttempt() {
    if (sessionStorage.getItem(ACTIVE_ATTEMPT_KEY) === '1') {
        try {
            await abandonAttempt();
        } catch (e) {}
        sessionStorage.removeItem(ACTIVE_ATTEMPT_KEY);
        window.location.reload();
        return true;
    }
    return false;
}

if (window.quizTakeData.antiCheatEnabled && !window.quizTakeData.locked) {
    checkStaleActiveAttempt().then((stale) => {
        if (stale) throw new Error('Stale attempt');
    });
}

function updateWarningCountdown(seconds) {
    if (countdownDisplay) countdownDisplay.textContent = seconds;
}

function startWarningTimer() {
    clearWarningTimers();
    warningCountdown = 10;
    updateWarningCountdown(warningCountdown);
    countdownInterval = setInterval(() => {
        warningCountdown--;
        updateWarningCountdown(warningCountdown);
        if (warningCountdown <= 0) {
            clearInterval(countdownInterval);
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

function handleViolation(type) {
    if (!examActive || warningModalVisible) return;
    violations++;
    updateStrikeDots();

    let msg = '';
    switch(type) {
        case 'tab_switch': msg = 'Tab switching detected.'; break;
        case 'window_blur': msg = 'Window focus lost.'; break;
        case 'screenshot_attempt': msg = 'Screenshot attempt blocked.'; break;
        case 'print_attempt': msg = 'Print attempt blocked.'; break;
        case 'devtools_attempt': msg = 'Developer tools are not allowed.'; break;
        case 'suspicious_activity': msg = 'Suspicious activity detected.'; break;
        case 'timeout_no_resume': msg = 'You did not resume in time.'; break;
        default: msg = 'Rule violation.';
    }

    if (violations >= MAX_VIOLATIONS) {
        examActive = false;
        clearWarningTimers();
        sessionStorage.removeItem(ACTIVE_ATTEMPT_KEY);
        abandonAttempt();
        warningTitle.textContent = '❌ Competition Terminated';
        warningMessage.textContent = 'You have violated the rules three times. Your attempt has been disqualified.';
        resumeBtn.textContent = 'Return to Quizzes';
        resumeBtn.classList.add('danger');
        resumeBtn.onclick = () => { window.location.href = window.quizTakeData.backUrl; };
        if (countdownWrapper) countdownWrapper.style.display = 'none';
        warningModal.style.display = 'flex';
        warningModalVisible = true;
        return;
    }

    warningTitle.textContent = `⚠️ Warning (${violations}/${MAX_VIOLATIONS})`;
    warningMessage.textContent = msg;
    strikeCountDisplay.textContent = violations;
    if (countdownWrapper) countdownWrapper.style.display = 'block';
    updateWarningCountdown(warningCountdown);

    resumeBtn.textContent = 'Resume';
    resumeBtn.classList.remove('danger');
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
    strike1.classList.toggle('active', violations >= 1);
    strike2.classList.toggle('active', violations >= 2);
    strike3.classList.toggle('active', violations >= 3);
    if (violations >= 3) {
        strike1.classList.add('danger');
        strike2.classList.add('danger');
        strike3.classList.add('danger');
    }
}
</script>
</body>
</html>