<?php
declare(strict_types=1);
error_reporting(-1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';

$certificateFunctionsFile = __DIR__ . '/includes/certificate_functions.php';
if (is_file($certificateFunctionsFile)) {
    require_once $certificateFunctionsFile;
}

if (!isLoggedIn()) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$conn = db();

// ---------- Certification attempt schema guard ----------
// Allows the system to record anti-cheat termination separately from normal abandonment.
try {
    $conn->exec("ALTER TABLE certification_exam_attempts MODIFY status ENUM('started','completed','abandoned','terminated') NOT NULL DEFAULT 'started'");
} catch (Throwable $e) {
    error_log('certification_exam_attempts status migration skipped: ' . $e->getMessage());
}

// ---------- Helpers ----------
function e(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
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
    if ($student === '') return ['is_correct' => false, 'points_earned' => 0, 'feedback' => 'No answer provided.'];
    if (empty($keywords) && $expected !== '') {
        $words = preg_split('/[,\s]+/', $expected) ?: [];
        foreach (array_unique($words) as $word) {
            if (mb_strlen($word) > 2) $keywords[] = ['word' => $word, 'weight' => 1];
        }
    }
    $keywordScore = 0.0; $totalWeight = 0.0;
    foreach ($keywords as $kw) {
        $word = is_array($kw) ? (string)($kw['word'] ?? '') : (string)$kw;
        if ($word === '') continue;
        $weight = isset($kw['weight']) ? (float)$kw['weight'] : 1.0;
        $totalWeight += $weight;
        if (strpos($word, ' ') !== false) {
            if (strpos($student, strtolower($word)) !== false) $keywordScore += $weight;
        } else {
            if (preg_match('/\b' . preg_quote(strtolower($word), '/') . '\b/u', $student)) $keywordScore += $weight;
        }
    }
    $keywordPercent = $totalWeight > 0 ? ($keywordScore / $totalWeight) : 0.0;
    similar_text($student, $expected, $similarity);
    if ($keywordPercent >= $keywordThreshold || $similarity >= $similarityThreshold) {
        return ['is_correct' => true, 'points_earned' => $maxPoints, 'feedback' => $keywordPercent >= $keywordThreshold
                ? sprintf('Correct. Matched %d%% of required keywords.', round($keywordPercent * 100))
                : sprintf('Answer accepted (similarity %.1f%%).', $similarity)];
    }
    return ['is_correct' => false, 'points_earned' => 0, 'feedback' => sprintf('Incorrect. Similarity %.1f%%.', $similarity)];
}




if (!function_exists('certificateCodeExists')) {
    function certificateCodeExists(PDO $conn, string $code): bool {
        $stmt = $conn->prepare("SELECT id FROM certificates WHERE certificate_code = ? LIMIT 1");
        $stmt->execute([$code]);
        return (bool)$stmt->fetch();
    }
}
if (!function_exists('generateCertificateCode')) {
    function generateCertificateCode(PDO $conn): string {
        do {
            $code = strtoupper('CERT-' . bin2hex(random_bytes(8)));
        } while (certificateCodeExists($conn, $code));
        return $code;
    }
}
if (!function_exists('hasActiveCertificate')) {
    function hasActiveCertificate(PDO $conn, int $userId, string $type, ?int $subjectId = null): ?string {
        $stmt = $conn->prepare("
            SELECT certificate_code
            FROM certificates
            WHERE user_id = ?
              AND certification_type = ?
              AND (subject_id = ? OR (subject_id IS NULL AND ? IS NULL))
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$userId, $type, $subjectId, $subjectId]);
        $code = $stmt->fetchColumn();
        return $code ? (string)$code : null;
    }
}
if (!function_exists('issueCertificate')) {
    function issueCertificate(PDO $conn, int $userId, string $type, ?int $subjectId = null): string {
        $type = $type === 'general' ? 'general' : 'subject';
        $subjectId = $type === 'general' ? null : $subjectId;

        $existing = hasActiveCertificate($conn, $userId, $type, $subjectId);
        if ($existing) return $existing;

        $code = generateCertificateCode($conn);
        $title = $type === 'general' ? 'General Certificate of Achievement' : 'Subject Certificate of Achievement';
        $summary = $type === 'general'
            ? 'Successfully completed and passed all certification levels across all active subjects.'
            : 'Successfully completed and passed all certification levels for this subject.';

        $stmt = $conn->prepare("
            INSERT INTO certificates
                (user_id, subject_id, certificate_code, certification_type, title, achievement_summary, completed_at, issued_at, status)
            VALUES
                (?, ?, ?, ?, ?, ?, NOW(), NOW(), 'active')
        ");
        $stmt->execute([$userId, $subjectId, $code, $type, $title, $summary]);
        return $code;
    }
}
function getActiveCertificateCodeForExam(PDO $conn, int $userId, array $exam): ?string {
    $type = ((string)($exam['certification_type'] ?? 'subject')) === 'general' ? 'general' : 'subject';
    $subjectId = $type === 'general' ? null : (!empty($exam['subject_id']) ? (int)$exam['subject_id'] : null);
    return hasActiveCertificate($conn, $userId, $type, $subjectId);
}
function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
function examWindowLabel(array $exam): string {
    return date('M d, Y H:i', strtotime((string)$exam['start_date'])) . ' - ' . date('M d, Y H:i', strtotime((string)$exam['end_date']));
}


// ---------- Certification prerequisite & scoped attempt helpers ----------
function hasPassedSubjectLevel(PDO $conn, int $userId, int $subjectId, int $level): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM certification_exam_attempts a
        JOIN certification_exams e ON e.id = a.exam_id
        WHERE a.user_id = ?
          AND a.passed = 1
          AND e.status = 'published'
          AND e.certification_type = 'subject'
          AND e.subject_id = ?
          AND e.level = ?
    ");
    $stmt->execute([$userId, $subjectId, $level]);
    return (int)$stmt->fetchColumn() > 0;
}

function hasPassedGeneralLevel(PDO $conn, int $userId, int $level): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM certification_exam_attempts a
        JOIN certification_exams e ON e.id = a.exam_id
        WHERE a.user_id = ?
          AND a.passed = 1
          AND e.status = 'published'
          AND e.certification_type = 'general'
          AND e.level = ?
    ");
    $stmt->execute([$userId, $level]);
    return (int)$stmt->fetchColumn() > 0;
}

function getFirstUnpassedSubjectNameAtLevel(PDO $conn, int $userId, int $level): ?string {
    $stmt = $conn->query("SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name ASC");
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$subjects) return 'No active subject is available';
    foreach ($subjects as $subject) {
        if (!hasPassedSubjectLevel($conn, $userId, (int)$subject['id'], $level)) {
            return (string)($subject['name'] ?? ('Subject #' . (int)$subject['id']));
        }
    }
    return null;
}

function getCertificationPrerequisiteBlockReason(PDO $conn, int $userId, array $exam): ?string {
    $level = (int)($exam['level'] ?? 1);
    $type = (string)($exam['certification_type'] ?? 'subject');
    $subjectId = isset($exam['subject_id']) ? (int)$exam['subject_id'] : 0;

    if ($type === 'subject') {
        if ($subjectId <= 0) return 'This subject certification exam is missing a valid subject. Please contact the administrator.';
        if ($level > 1 && !hasPassedSubjectLevel($conn, $userId, $subjectId, $level - 1)) {
            return "You cannot take Subject Level {$level} before successfully passing Subject Level " . ($level - 1) . ' for this same subject.';
        }
        return null;
    }

    if ($type === 'general') {
        if ($level > 1 && !hasPassedGeneralLevel($conn, $userId, $level - 1)) {
            return "You cannot take General Level {$level} before successfully passing General Level " . ($level - 1) . '.';
        }
        $missingSubject = getFirstUnpassedSubjectNameAtLevel($conn, $userId, $level);
        if ($missingSubject !== null) {
            return "You cannot take General Level {$level} before successfully passing Level {$level} in all active subjects. Missing: {$missingSubject}.";
        }
        return null;
    }

    return 'Unknown certification type. Please contact the administrator.';
}

function getScopedLevelAttempts(PDO $conn, int $userId, array $exam): array {
    $level = (int)($exam['level'] ?? 1);
    $type = (string)($exam['certification_type'] ?? 'subject');

    if ($type === 'subject') {
        $stmt = $conn->prepare("
            SELECT a.*, e.attempt_sequence AS exam_seq
            FROM certification_exam_attempts a
            JOIN certification_exams e ON a.exam_id = e.id
            WHERE a.user_id = ?
              AND e.level = ?
              AND e.certification_type = 'subject'
              AND e.subject_id = ?
            ORDER BY a.id ASC
        ");
        $stmt->execute([$userId, $level, (int)($exam['subject_id'] ?? 0)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $conn->prepare("
        SELECT a.*, e.attempt_sequence AS exam_seq
        FROM certification_exam_attempts a
        JOIN certification_exams e ON a.exam_id = e.id
        WHERE a.user_id = ?
          AND e.level = ?
          AND e.certification_type = 'general'
        ORDER BY a.id ASC
    ");
    $stmt->execute([$userId, $level]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ---------- Exam ID ----------
$examId = 0;
if (isset($_GET['exam_id'])) $examId = (int)$_GET['exam_id'];
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = json_decode(file_get_contents('php://input'), true);
    if ($json && isset($json['quiz_id'])) $examId = (int)$json['quiz_id'];
    elseif (isset($_POST['exam_id'])) $examId = (int)$_POST['exam_id'];
}
if ($examId <= 0) {
    if (($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) ||
        ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answers']))) {
        header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid exam ID.']); exit;
    }
    die('Invalid exam ID.');
}

// ---------- Fetch exam ----------
$stmt = $conn->prepare("SELECT ce.*, s.name AS subject_name FROM certification_exams ce LEFT JOIN subjects s ON ce.subject_id = s.id WHERE ce.id = ? AND ce.status = 'published'");
$stmt->execute([$examId]); $exam = $stmt->fetch();
if (!$exam) die('Exam not found.');

// ---------- Certification prerequisite check ----------
$prerequisiteBlockReason = getCertificationPrerequisiteBlockReason($conn, $userId, $exam);

// ---------- Progress check ----------
$progress = ($exam['certification_type'] === 'general') ? getOverallProgress($conn, $userId) : getSubjectProgress($conn, $userId, (int)$exam['subject_id']);
$threshold = getProgressThreshold((int)$exam['level']);
if ($progress < $threshold) die('You have not reached the required lesson progress to take this exam.');

// ---------- Window checks ----------
$nowDt = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));
$competitionActive = false; $competitionUpcoming = false; $competitionEnded = false; $windowLabel = ''; $endDateIso = null;
if (!empty($exam['start_date']) && !empty($exam['end_date'])) {
    $startDt = new DateTime($exam['start_date'], new DateTimeZone('Africa/Dar_es_Salaam'));
    $endDt   = new DateTime($exam['end_date'],   new DateTimeZone('Africa/Dar_es_Salaam'));
    $windowLabel = examWindowLabel($exam);
    $competitionActive = ($nowDt >= $startDt && $nowDt <= $endDt);
    $competitionUpcoming = ($nowDt < $startDt);
    $competitionEnded = ($nowDt > $endDt);
    $endDateIso = $endDt->format('c');
} else {
    $competitionActive = true; $competitionUpcoming = false; $competitionEnded = false;
}

// ---------- Fetch questions ----------
$stmt = $conn->prepare("SELECT * FROM certification_exam_questions WHERE exam_id = ? ORDER BY sort_order");
$stmt->execute([$examId]);
$questions = $stmt->fetchAll();
$optStmt = $conn->prepare("SELECT * FROM certification_exam_question_options WHERE question_id = ? ORDER BY sort_order");
foreach ($questions as &$q) { $optStmt->execute([$q['id']]); $q['options'] = $optStmt->fetchAll(); } unset($q);
$gradableQuestions = array_filter($questions, fn($q) => ($q['question_type'] ?? '') !== 'explanation');

// ---------- Upcoming page ----------
if ($competitionUpcoming) {
    $backUrl = 'certification_exams.php'; $title = htmlspecialchars($exam['title'] ?? 'Certification Exam'); $description = htmlspecialchars($exam['description'] ?? ''); $startTimestamp = $startDt->getTimestamp();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Exam Upcoming - MyIslam</title>
        <style>
            /* Use same variables and base styles as the main exam page for consistency */
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
                <div class="window-label">Exam Window</div>
                <div class="window-dates"><?= htmlspecialchars($windowLabel) ?></div>
                <div class="countdown-timer" id="countdown">--:--:--</div>
                <p>Starts in</p>
            </div>
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn">← Back to Certification Exams</a>
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

// =====================  CRITICAL: PREVENT RE‑ATTEMPT OF THE SAME EXAM =====================
$takeLocked = false;
$lockReason = '';
$attempt = null;   // holds a 'started' attempt if resuming
$expiresAt = null;

// 1. Check if this specific exam has EVER been attempted (any status)
$stmt = $conn->prepare("SELECT id, status FROM certification_exam_attempts WHERE user_id = ? AND exam_id = ? LIMIT 1");
$stmt->execute([$userId, $examId]);
$thisExamAttempt = $stmt->fetch(PDO::FETCH_ASSOC);

if ($thisExamAttempt) {
    if ($thisExamAttempt['status'] === 'started') {
        // Resume the existing attempt
        $stmt = $conn->prepare("SELECT * FROM certification_exam_attempts WHERE id = ?");
        $stmt->execute([$thisExamAttempt['id']]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($attempt && !empty($attempt['started_at'])) {
            $timeLimitMinutes = (int)($exam['time_limit_minutes'] ?? 0);
            if ($timeLimitMinutes > 0) {
                $started = new DateTime($attempt['started_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
                $started->modify('+' . $timeLimitMinutes . ' minutes');
                $expiresAt = $started->format('Y-m-d H:i:s');
            }
        }
        // NOT locked – the user can resume
    } else {
        // Any other status (completed, abandoned) → permanent lock
        $takeLocked = true;
        $lockReason = 'You have already attempted this exact exam. You cannot retake it. Please go back to the certification exams page to see your next available exam.';
    }
}

// 2. If prerequisites fail, lock before any start/resume can continue
if (!$takeLocked && $prerequisiteBlockReason !== null) {
    $takeLocked = true;
    $lockReason = $prerequisiteBlockReason;
}

// 3. If not locked yet, perform the usual scoped level‑wide checks
if (!$takeLocked) {
    $level = (int)$exam['level'];
    $examSequence = (int)($exam['attempt_sequence'] ?? 1);
    $maxAttempts = max((int)($exam['max_attempts_per_level'] ?? 3), 1);
    $retryDelayHours = max((int)($exam['retry_delay_hours'] ?? 24), 0);

    $allLevelAttempts = getScopedLevelAttempts($conn, $userId, $exam);
    $levelAttemptCount = count($allLevelAttempts);
    $lastAttempt = ($levelAttemptCount > 0) ? end($allLevelAttempts) : null;

    // Passed?
    $passedThisLevel = false;
    foreach ($allLevelAttempts as $att) { if ($att['passed'] == 1) { $passedThisLevel = true; break; } }
    if ($passedThisLevel) {
        $takeLocked = true;
        $lockReason = '🎉 Congratulations – you have already passed this certification level. No further exams are needed.';
    } elseif ($levelAttemptCount >= $maxAttempts) {
        $takeLocked = true;
        $lockReason = "You have used all {$maxAttempts} attempts allowed for Level {$level}.";
    } elseif (!$competitionActive) {
        $takeLocked = true;
        $lockReason = $competitionEnded ? 'This exam window has ended.' : 'This exam is not yet available.';
    } else {
        // Active attempt in another exam of this level?
        $activeAttempt = null;
        foreach ($allLevelAttempts as $att) { if ($att['status'] === 'started') { $activeAttempt = $att; break; } }
        if ($activeAttempt && (int)$activeAttempt['exam_id'] !== $examId) {
            $takeLocked = true;
            $lockReason = 'You already have an active exam in this level. Please complete or abandon it first.';
        } elseif (!$activeAttempt) {   // no active attempt → check sequence & cooldown
            $highestAttemptedSeq = 0;
            foreach ($allLevelAttempts as $att) { if ((int)$att['exam_seq'] > $highestAttemptedSeq) $highestAttemptedSeq = (int)$att['exam_seq']; }
            $expectedSeq = $highestAttemptedSeq + 1;
            if ($examSequence !== $expectedSeq) {
                $takeLocked = true;
                $lockReason = "You must take the exams in order. Please go back and select the next available exam for Level {$level}.";
            } elseif ($lastAttempt && in_array($lastAttempt['status'], ['completed', 'abandoned', 'terminated'], true) && $lastAttempt['passed'] == 0) {
                $completionTime = !empty($lastAttempt['completed_at']) ? new DateTime($lastAttempt['completed_at'], new DateTimeZone('Africa/Dar_es_Salaam')) : null;
                if (!$completionTime && !empty($lastAttempt['started_at'])) $completionTime = new DateTime($lastAttempt['started_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
                if ($completionTime && $retryDelayHours > 0) {
                    $unlockTime = clone $completionTime; $unlockTime->modify('+' . $retryDelayHours . ' hours');
                    if ($nowDt < $unlockTime) {
                        $takeLocked = true;
                        $remaining = $nowDt->diff($unlockTime); $hoursLeft = $remaining->h + ($remaining->days * 24); $minutesLeft = $remaining->i;
                        $timeStr = "{$hoursLeft}h {$minutesLeft}m";
                        $lockReason = (($lastAttempt['status'] ?? '') === 'terminated')
                            ? "You were terminated due to exam rule violations. Your next attempt will unlock in {$timeStr}. Please come back when the next attempt is open."
                            : "You failed to reach the passing score. Your next attempt will unlock in {$timeStr}. Please go back to the lessons and review before trying again.";
                    }
                }
            }
            // If all checks passed, $takeLocked remains false → the exam can be started
        }
    }
}

// Build review payload if locked (optional, for displaying past results)
$existingAttemptPayload = null;
if ($takeLocked && $thisExamAttempt) {
    $stmt = $conn->prepare("SELECT * FROM certification_exam_attempts WHERE user_id = ? AND exam_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId, $examId]);
    $lastExamAttempt = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lastExamAttempt) {
        $existingAttemptPayload = buildReviewFromAttempt($lastExamAttempt, $questions);
        if (!empty($existingAttemptPayload['passed']) && (int)($exam['level'] ?? 0) === 4) {
            $existingAttemptPayload['certCode'] = getActiveCertificateCodeForExam($conn, $userId, $exam);
        }
    }
}

// ---------- Base quiz data ----------
$quiz = [
    'title'             => $exam['title'] ?? 'Certification Exam',
    'description'       => $exam['description'] ?? '',
    'passing_score'     => $exam['passing_score'] ?? 70,
    'time_limit_minutes' => $exam['time_limit_minutes'] ?? 0,
    'hints_enabled'     => false,
    'mode'              => 'certification',
];
$timeLimitMinutes  = (int)($exam['time_limit_minutes'] ?? 0);
$backUrl           = 'certification_exams.php';
$antiCheatEnabled  = !$takeLocked;

// ------ CSRF token -------
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

// ---------- Handle AJAX POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? null;
    $jsonBody = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) { $jsonBody = readJsonBody(); $postAction = $postAction ?? ($jsonBody['action'] ?? 'submit_exam_json'); }

    if ($postAction === 'start_exam') {
        header('Content-Type: application/json');
        if (($jsonBody['csrf_token'] ?? '') !== $_SESSION['csrf_token']) { echo json_encode(['success' => false, 'message' => 'Invalid security token.']); exit; }
        if ($prerequisiteBlockReason !== null) { echo json_encode(['success' => false, 'message' => $prerequisiteBlockReason]); exit; }

        // Duplicate the entire lock check (defense in depth)
        $stmt = $conn->prepare("SELECT id, status FROM certification_exam_attempts WHERE user_id = ? AND exam_id = ? LIMIT 1");
        $stmt->execute([$userId, $examId]);
        $thisExamAttempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($thisExamAttempt && $thisExamAttempt['status'] !== 'started') {
            echo json_encode(['success' => false, 'message' => 'You have already attempted this exam.']); exit;
        }

        // Level‑wide checks
        $level = (int)$exam['level']; $examSequence = (int)($exam['attempt_sequence'] ?? 1);
        $maxAttempts = max((int)($exam['max_attempts_per_level'] ?? 3), 1); $retryDelayHours = max((int)($exam['retry_delay_hours'] ?? 24), 0);

        $allLevelAttempts = getScopedLevelAttempts($conn, $userId, $exam);
        $levelAttemptCount = count($allLevelAttempts); $lastAttempt = ($levelAttemptCount > 0) ? end($allLevelAttempts) : null;

        foreach ($allLevelAttempts as $att) { if ($att['passed'] == 1) { echo json_encode(['success' => false, 'message' => 'You have already passed this certification level.']); exit; } }
        if ($levelAttemptCount >= $maxAttempts) { echo json_encode(['success' => false, 'message' => 'You have used all allowed attempts for this level.']); exit; }

        $activeAttempt = null;
        foreach ($allLevelAttempts as $att) { if ($att['status'] === 'started') { $activeAttempt = $att; break; } }
        if ($activeAttempt) {
            if ((int)$activeAttempt['exam_id'] !== $examId) { echo json_encode(['success' => false, 'message' => 'You already have an active exam in this level.']); exit; }
            // Resume existing
            echo json_encode(['success' => true, 'attempt_id' => (int)$activeAttempt['id'], 'expires_at' => $expiresAt ?? null, 'time_limit_minutes' => $timeLimitMinutes]); exit;
        }

        $highestAttemptedSeq = 0;
        foreach ($allLevelAttempts as $att) { if ((int)$att['exam_seq'] > $highestAttemptedSeq) $highestAttemptedSeq = (int)$att['exam_seq']; }
        $expectedSeq = $highestAttemptedSeq + 1;
        if ($examSequence !== $expectedSeq) { echo json_encode(['success' => false, 'message' => 'You must take the exams in order.']); exit; }

        if ($lastAttempt && in_array($lastAttempt['status'], ['completed', 'abandoned', 'terminated'], true) && $lastAttempt['passed'] == 0) {
            $completionTime = !empty($lastAttempt['completed_at']) ? new DateTime($lastAttempt['completed_at'], new DateTimeZone('Africa/Dar_es_Salaam')) : null;
            if (!$completionTime && !empty($lastAttempt['started_at'])) $completionTime = new DateTime($lastAttempt['started_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
            if ($completionTime && $retryDelayHours > 0) {
                $unlockTime = clone $completionTime; $unlockTime->modify('+' . $retryDelayHours . ' hours');
                $nowUTC = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));
                if ($nowUTC < $unlockTime) {
                    $remaining = $nowUTC->diff($unlockTime);
                    $hoursLeft = $remaining->h + ($remaining->days * 24);
                    $minutesLeft = $remaining->i;
                    $timeStr = $hoursLeft . 'h ' . $minutesLeft . 'm';
                    $msg = (($lastAttempt['status'] ?? '') === 'terminated')
                        ? 'You were terminated due to exam rule violations. Your next attempt will unlock in ' . $timeStr . '. Please come back when the next attempt is open.'
                        : 'You failed to reach the passing score. Your next attempt will unlock in ' . $timeStr . '. Please review the lessons before trying again.';
                    echo json_encode(['success' => false, 'message' => $msg]); exit;
                }
            }
        }

        // All clear – create attempt
        $stmt = $conn->prepare("INSERT INTO certification_exam_attempts (user_id, exam_id, attempt_number, status, score, total_points, percentage, passed, correct_answers, total_questions, answers_json, started_at) VALUES (?, ?, ?, 'started', 0, 0, 0, 0, 0, ?, NULL, NOW())");
        $nextAttemptNumber = $levelAttemptCount + 1;
        $stmt->execute([$userId, $examId, $nextAttemptNumber, count($questions)]);
        $attemptId = (int)$conn->lastInsertId();
        $expiresAt = null;
        if ($timeLimitMinutes > 0) { $started = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam')); $started->modify('+' . $timeLimitMinutes . ' minutes'); $expiresAt = $started->format('Y-m-d H:i:s'); }
        echo json_encode(['success' => true, 'attempt_id' => $attemptId, 'expires_at' => $expiresAt, 'time_limit_minutes' => $timeLimitMinutes]); exit;
    }

    if ($postAction === 'abandon_attempt' || $postAction === 'terminate_attempt') {
        header('Content-Type: application/json');
        $clientAttemptId = (int)($jsonBody['attempt_id'] ?? $_POST['attempt_id'] ?? 0);
        $status = ($postAction === 'terminate_attempt') ? 'terminated' : 'abandoned';
        if ($clientAttemptId > 0) {
            $stmt = $conn->prepare("UPDATE certification_exam_attempts SET status = ?, completed_at = NOW(), passed = 0 WHERE id = ? AND user_id = ? AND status = 'started'");
            $stmt->execute([$status, $clientAttemptId, $userId]);
        }
        echo json_encode([
            'success' => true,
            'status' => $status,
            'message' => $status === 'terminated'
                ? 'Your exam attempt has been terminated due to rule violations. Your next attempt will unlock after the admin retry delay.'
                : 'Your exam attempt has been abandoned.'
        ]);
        exit;
    }

    if ($postAction === 'submit_exam_json') {
        header('Content-Type: application/json');
        $payload = $jsonBody ?: $_POST; $clientAttemptId = (int)($payload['attempt_id'] ?? 0); $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
        $stmt = $conn->prepare("SELECT * FROM certification_exam_attempts WHERE id = ? AND user_id = ? AND status = 'started'"); $stmt->execute([$clientAttemptId, $userId]); $attemptData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attemptData) { echo json_encode(['success' => false, 'message' => 'Attempt not found or already completed.']); exit; }
        if (!empty($exam['start_date']) && !empty($exam['end_date'])) { $s = new DateTime($exam['start_date'], new DateTimeZone('Africa/Dar_es_Salaam')); $e = new DateTime($exam['end_date'], new DateTimeZone('Africa/Dar_es_Salaam')); $n = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam')); if ($n < $s || $n > $e) { echo json_encode(['success' => false, 'message' => 'This exam window is not active.']); exit; } }

        // Grading (same as always)
        $totalPoints = 0; $earnedPoints = 0; $correctCount = 0; $results = [];
        foreach ($questions as $q) {
            $qid = (int)$q['id']; $userAns = $answers[$qid] ?? null; $isCorrect = false; $pointsEarned = 0; $correctAnswerText = ''; $userAnswerText = ''; $qtype = $q['question_type'] ?? 'multiple_choice';
            if ($qtype === 'explanation') {
                $studentAnswer = trim((string)$userAns);
                if ($studentAnswer === '') { $isCorrect = false; $pointsEarned = 0; $userAnswerText = ''; $correctAnswerText = $q['expected_answer'] ?? ''; }
                else { $expected = $q['expected_answer'] ?? ''; $keywords = !empty($q['keywords_json']) ? json_decode($q['keywords_json'], true) : []; $eval = evaluateExplanationAnswer($studentAnswer, $expected, (int)$q['points'], $keywords); $isCorrect = $eval['is_correct']; $pointsEarned = $eval['points_earned']; $userAnswerText = $studentAnswer; $correctAnswerText = $expected; }
            } else {
                $optStmt = $conn->prepare("SELECT id, option_text FROM certification_exam_question_options WHERE question_id = ? AND is_correct = 1 LIMIT 1"); $optStmt->execute([$qid]); $correctOption = $optStmt->fetch(PDO::FETCH_ASSOC);
                if ($correctOption) { $correctAnswerText = $correctOption['option_text'] ?? ''; $correctId = $correctOption['id']; $isCorrect = ($userAns !== null && (string)$userAns === (string)$correctId); }
                else { $isCorrect = false; $correctAnswerText = ''; }
                $pointsEarned = $isCorrect ? (int)$q['points'] : 0;
                if ($userAns !== null && $userAns !== '') { $optTextStmt = $conn->prepare("SELECT option_text FROM certification_exam_question_options WHERE id = ?"); $optTextStmt->execute([$userAns]); $userAnswerText = (string)$optTextStmt->fetchColumn(); }
                else $userAnswerText = '';
            }
            if ($isCorrect) $correctCount++; $totalPoints += (int)$q['points']; $earnedPoints += $pointsEarned;
            $results[] = ['question_id' => $qid, 'question_text' => $q['question_text'], 'user_answer' => $userAns, 'is_correct' => $isCorrect, 'points_earned' => $pointsEarned, 'user_answer_text' => $userAnswerText, 'correct_answer_text' => $correctAnswerText, 'explanation' => $q['explanation'] ?? ''];
        }
        $percentage = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100, 2) : 0; $passed = $percentage >= (float)$exam['passing_score'];
        $stmt = $conn->prepare("UPDATE certification_exam_attempts SET status = 'completed', score = ?, total_points = ?, percentage = ?, passed = ?, correct_answers = ?, total_questions = ?, answers_json = ?, completed_at = NOW() WHERE id = ? AND user_id = ? AND status = 'started'");
        $stmt->execute([$earnedPoints, $totalPoints, $percentage, $passed ? 1 : 0, $correctCount, count($questions), json_encode($results), $clientAttemptId, $userId]);
        $certCode = '';
        if ($passed && (int)$exam['level'] === 4) {
            $certCode = issueCertificate(
                $conn,
                $userId,
                (string)$exam['certification_type'],
                !empty($exam['subject_id']) ? (int)$exam['subject_id'] : null
            );
        }
        $resultsPending = false; $endDate = $exam['end_date'] ?? null; if ($endDate) { $endDt = new DateTime($endDate, new DateTimeZone('Africa/Dar_es_Salaam')); if ($nowDt < $endDt) $resultsPending = true; }
        $response = ['success' => true, 'passed' => $passed, 'percentage' => $percentage, 'score' => $earnedPoints, 'total' => $totalPoints, 'correct_count' => $correctCount, 'total_questions' => count($gradableQuestions), 'certCode' => $certCode, 'end_date' => $endDate, 'results_pending' => $resultsPending];
        if (!$resultsPending) $response['results'] = $results;
        echo json_encode($response); exit;
    }
}

// ---------- Helper functions for review ----------
function generateReviewFromQuestions(array $questions): array {
    $review = [];
    foreach ($questions as $q) { $correctOption = null; $correctText = ''; if (($q['question_type'] ?? '') === 'explanation') $correctText = $q['expected_answer'] ?? ''; elseif (!empty($q['options'])) { foreach ($q['options'] as $opt) { if (!empty($opt['is_correct'])) { $correctOption = $opt['id']; $correctText = $opt['option_text'] ?? ''; break; } } } $review[] = ['question_id' => (int)$q['id'], 'question_text' => $q['question_text'] ?? '', 'user_answer' => null, 'user_answer_text' => null, 'correct_answer' => $correctOption, 'correct_answer_text' => $correctText, 'is_correct' => false, 'points_earned' => 0, 'explanation' => $q['explanation'] ?? '']; }
    return $review;
}
function buildReviewFromAttempt(array $attempt, array $questions): array {
    $results = []; $hasUserAnswers = false;
    if (!empty($attempt['answers_json'])) { $decoded = json_decode((string)$attempt['answers_json'], true); if (is_array($decoded) && !empty($decoded)) { $results = $decoded; $hasUserAnswers = true; } }
    if (!$hasUserAnswers) $results = generateReviewFromQuestions($questions);
    return ['attempt_id' => (int)($attempt['id'] ?? 0), 'score' => (int)($attempt['score'] ?? 0), 'total' => (int)($attempt['total_points'] ?? 0), 'percentage' => (float)round((float)($attempt['percentage'] ?? 0)), 'passed' => ((int)($attempt['passed'] ?? 0) === 1), 'correct_count' => (int)($attempt['correct_answers'] ?? 0), 'total_questions' => (int)($attempt['total_questions'] ?? count(array_filter($questions, fn($q) => ($q['question_type'] ?? '') !== 'explanation'))), 'results' => $results, 'attempted' => true, 'locked' => true, 'status' => $attempt['status'] ?? 'completed', 'has_user_answers' => $hasUserAnswers];
}

// ---------- Sidebar & user ----------
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist yet – ignore
} catch (Throwable $e) {
    error_log('Error fetching unread notifications count: ' . $e->getMessage());
}
$sidebarNavigation = [
    ['name' => 'Dashboard',            'link' => 'user_dashboard.php',      'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects',              'link' => 'subjects.php',            'active' => false, 'icon' => '📚'],
    ['name' => 'Quizzes',                'link' => 'quizzes.php',              'active' => false, 'icon' => '📝'],
    ['name' => 'Monthly Exams',         'link' => 'monthly_exams.php',        'active' => false, 'icon' => '📅'],
    ['name' => 'Leaderboard',            'link' => 'leaderboard.php',          'active' => false, 'icon' => '🏆'],
    ['name' => 'Certification Exams',   'link' => 'certification_exams.php',  'active' => true,  'icon' => '🎓'],
    ['name' => 'My Certificates',       'link' => 'my_certificates.php',      'active' => false, 'icon' => '🎓'],
    ['name' => 'Calendar',               'link' => 'calendar.php',             'active' => false, 'icon' => '📅'],
    ['name' => 'Multimedia',             'link' => 'multimedia.php',           'active' => false, 'icon' => '🖼️'],
    ['name' => 'Notifications',         'link' => 'notification.php',         'active' => false, 'icon' => '🔔'],
    ['name' => 'Help Center',            'link' => 'help_center.php',          'active' => false, 'icon' => '❓'],
];
$mainNavLinks = ['user_dashboard.php', 'subjects.php', 'quizzes.php', 'monthly_exams.php', 'leaderboard.php', 'multimedia.php', 'notification.php', 'help_center.php'];
$mainNavigation = [];
foreach ($sidebarNavigation as $navItem) {
    if (in_array($navItem['link'], $mainNavLinks, true)) {
        $mainNavigation[] = $navItem;
    }
}
$user = [
    'name'         => $_SESSION['user_name'] ?? 'User',
    'email'        => $_SESSION['user_email'] ?? '',
    'picture'      => $_SESSION['user_picture'] ?? '',
    'role'         => $_SESSION['user_role'] ?? 'user',
    'member_since' => 'recently',
];
?>
<!-- The rest of the HTML/JS is identical to the previous fully working version (with the updated lock logic) -->
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($quiz['title'] ?? 'Take Certification Exam') ?> - MyIslam</title>
<style>
/* ===== DASHBOARD BASE STYLES (matches user_dashboard.php) ===== */
*{margin:0;padding:0;box-sizing:border-box}
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
body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;position:relative}
body::before{content:"";position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:-1;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");background-repeat:repeat}
body.dark-mode::before{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E")}
a{text-decoration:none;color:inherit} button{font:inherit;border:none;background:none;cursor:pointer}

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

/* Main content */
.wrapper { max-width: 1000px; margin: 0 auto; padding: 24px 16px; }
.card, .quiz-section { background: var(--surface); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); overflow: hidden; border: 1px solid var(--line); margin-bottom: 24px; }
.card-body { padding: 1.5rem 2rem; text-align: center; }
.card-body .info-box,
.card-body .message-banner,
.card-body .locked-banner,
.card-body .quiz-description {
    text-align: left;
}
.mode-pill { display: inline-block; padding: 6px 14px; border-radius: 10px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 12px; }
.mode-pill.certification { background: var(--gold-soft); color: var(--gold); }
h1 { margin-bottom: 12px; color: var(--brand); font-size: 28px; }
.meta { color: var(--text-secondary); font-size: 14px; margin-bottom: 16px; display: flex; flex-wrap: wrap; justify-content: center; gap: 0.75rem 1rem; }
.timer { font-weight: 700; color: var(--brand); font-size: 18px; margin-top: 18px; }
.info-box { margin-top: 16px; padding: 16px; border-radius: 10px; border-left: 4px solid; border-top: 1px solid var(--line); border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.info-box.certification { background: var(--gold-soft); border-color: var(--gold); }
.attempt-info { font-size: 14px; color: var(--text-secondary); margin-top: 12px; }
.quiz-header { background: linear-gradient(135deg, var(--accent) 0%, var(--brand) 100%); color: white; padding: 1.5rem 2rem; }
.quiz-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
.quiz-question { padding: 1.5rem 2rem; border-bottom: 1px solid var(--line); }
.question-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 12px; }
.question-text { font-weight: 600; font-size: 1.1rem; }
.question-meta { font-size: 13px; color: var(--muted); margin-bottom: 12px; }
.options { display: flex; flex-direction: column; gap: 0.75rem; margin-top: 8px; }
.option { display: flex; align-items: center; gap: 0.75rem; cursor: pointer; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--line); background: var(--surface-2); transition: all 0.2s; }
.option:hover { background: var(--brand-soft); border-color: var(--brand); }
.option input { accent-color: var(--brand); cursor: pointer; }
img.qimg { max-width: 100%; max-height: 320px; border-radius: 10px; border: 1px solid var(--line); margin: 12px 0; }
.quiz-actions { padding: 1.5rem 2rem; display: flex; justify-content: flex-end; gap: 1rem; }
.submit-btn, .btn { background: var(--brand); color: white; border: none; padding: 14px 22px; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: background 0.2s, transform 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
.submit-btn:hover, .btn:hover { background: var(--brand-dark); transform: translateY(-1px); }
.submit-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.quiz-result { padding: 2rem; background: var(--brand-soft); border: 1px solid var(--line); border-radius: 10px; margin: 24px; }
.result-pass { color: var(--success); font-weight: 700; font-size: 1.1rem; }
.result-fail { color: var(--danger); font-weight: 700; font-size: 1.1rem; }

/* Score summary box: an animated, gently-scrolling gradient that reads
   instantly as pass (green) or fail (cool red) in both light and dark mode. */
@keyframes resultGradientScroll {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}
.score-summary {
    margin: 1rem 0;
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
    margin: 6px 0;
    border-radius: 10px;
    border: 1px solid var(--line);
    border-left: 4px solid var(--line);
}
.review-item.correct { background: var(--brand-soft); border-color: var(--brand); border-left-color: var(--brand); }
.review-item.incorrect { background: rgba(185, 28, 28, 0.07); border-color: var(--danger); border-left-color: var(--danger); }

/* Certificate-earned banner: gold, to match the certification theme */
.certificate-earned-banner {
    text-align: center;
    background: var(--gold-soft);
    color: var(--gold);
    border-color: var(--gold);
}

.pending-results { text-align: center; padding: 2rem; background: var(--brand-soft); border-radius: var(--radius-md); margin: 1rem 0; border: 1px solid var(--line); }
.pending-results h3 { color: var(--brand); margin-bottom: 1rem; }
.pending-results .countdown-timer { font-size: 2rem; font-weight: 700; color: var(--brand); margin: 1rem 0; }
.locked-banner { margin-top: 1rem; padding: 0.9rem 1rem; border-radius: var(--radius-md); background: var(--brand-soft); border: 1px solid var(--line); color: var(--brand); font-weight: 600; }
.message-banner { margin-top: 1rem; padding: 0.9rem 1rem; border-radius: var(--radius-md); background: var(--gold-soft); color: var(--gold); font-weight: 600; border: 1px solid var(--line); }
.confirmation-card { background: var(--surface-2); border-radius: 10px; padding: 24px; margin: 20px 0; text-align: center; border: 1px solid var(--line); }
.confirmation-card h3 { color: var(--brand); margin-bottom: 16px; }
.confirmation-card p { margin-bottom: 20px; color: var(--text-secondary); }
.confirmation-actions { display: flex; gap: 12px; justify-content: center; }
.toast { position: fixed; bottom: 2rem; right: 2rem; background: var(--brand); color: white; padding: 1rem 1.5rem; border-radius: 10px; box-shadow: var(--shadow-lg); transform: translateX(400px); transition: transform 0.3s ease; z-index: 1000; }
.toast.show { transform: translateX(0); }

/* Modals */
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: var(--overlay-dim); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center; z-index: 9999; }
.modal { background: var(--surface); border-radius: 10px; padding: 32px; max-width: 500px; width: 90%; text-align: center; border: 1px solid var(--line); }
.modal h2 { margin-bottom: 16px; color: var(--text); }
.modal p { margin-bottom: 24px; color: var(--text-secondary); line-height: 1.6; }
.modal ul { text-align: left; margin: 20px 0; color: var(--text-secondary); padding-left: 24px; }
.modal .btn { width: auto; padding: 12px 30px; margin: 5px; }
.modal .btn.warning { background: var(--warning); }
.modal .btn.danger { background: var(--danger); }
.strike-indicator { display: flex; gap: 8px; justify-content: center; margin: 20px 0; }
.strike-dot { width: 16px; height: 16px; border-radius: 50%; background: var(--surface-3); }
.strike-dot.active { background: var(--warning); }
.strike-dot.danger { background: var(--danger); }
.watermark-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; pointer-events: none; z-index: 9998; opacity: 0.05; display: flex; align-items: center; justify-content: center; font-size: 80px; font-weight: 900; color: #000; transform: rotate(-25deg); white-space: nowrap; text-transform: uppercase; font-family: monospace; }

@media (max-width: 1200px) {
    .header-top-nav { max-width: 40vw; }
}

@media (max-width: 900px) {
    .header-top-nav { display: none; }
}

@media (max-width:768px){
    :root { --header-height: 64px; }
    .wrapper { padding: 10px; }
    .card-body, .quiz-header, .quiz-question, .quiz-actions, .quiz-result { padding: 16px; }
    .question-header { flex-direction: column; }
    .toast { left: 1rem; right: 1rem; bottom: 1rem; text-align: center; }
    .confirmation-actions { flex-direction: column; }
    .app-header { padding: 12px 16px; }
}
                 .notification-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: var(--danger, #b91c1c);
    color: white;
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
</style>
</head>
<body>
<!-- ... (same modal, header, sidebar, main content structure) ... -->
<!-- The rest of the HTML is unchanged, using the same layout and JS, with the only difference being the updated quizTakeData and the new lock logic already applied in PHP. -->
<!-- The JavaScript block remains identical to the previous version (the enhanced one). -->


<!-- Instructions Modal -->
<div class="modal-overlay" id="instructionsModal" style="display:none;">
    <div class="modal">
        <h2>🏆 Certification Exam Instructions</h2>
        <p><strong><?= e($quiz['title']) ?></strong></p>
        <p><?= e($quiz['description'] ?? 'Read carefully.') ?></p>
        <ul>
            <li><strong>⏱️ Time limit:</strong> <?= $timeLimitMinutes ? $timeLimitMinutes . ' minutes' : 'No time limit' ?></li>
            <li><strong>🎯 Passing score:</strong> <?= (int)$quiz['passing_score'] ?>%</li>
            <li><strong>📝 Questions:</strong> <?= count($gradableQuestions) ?> total</li>
            <li><strong>🚫 Do NOT</strong> switch tabs, minimize, leave this window, or attempt screenshots.</li>
            <li><strong>📸 Screenshots are strictly prohibited</strong> and count as violations.</li>
            <li><strong>⚠️ 3 violations</strong> will terminate your attempt.</li>
            <li><strong>⏳ You have 10 seconds to resume</strong> after any warning.</li>
            <li><strong>✅ Answer all questions</strong> before submitting.</li>
        </ul>
        <button class="btn" id="startCompetitionBtn">I Understand, Start Exam</button>
    </div>
</div>

<!-- Warning Modal -->
<div class="modal-overlay" id="warningModal" style="display:none;">
    <div class="modal">
        <h2 id="warningTitle">⚠️ Warning</h2>
        <p id="warningMessage">You have left the exam window.</p>
        <div class="strike-indicator" id="strikeIndicator">
            <span class="strike-dot" id="strike1"></span>
            <span class="strike-dot" id="strike2"></span>
            <span class="strike-dot" id="strike3"></span>
        </div>
        <p>Violation <strong><span id="strikeCountDisplay">1</span> of 3</strong>.<br>Third violation terminates the attempt.</p>
        <p id="countdownWrapper">⏳ Resume within <strong><span id="countdownDisplay">10</span></strong> seconds or get another violation.</p>
        <button class="btn warning" id="resumeCompetitionBtn">Resume</button>
    </div>
</div>

<!-- Time‑Up Modal -->
<div class="modal-overlay" id="timeUpModal" style="display:none;">
    <div class="modal" style="max-width:450px;">
        <div style="font-size:48px;margin-bottom:16px;">⏰</div>
        <h2 style="color:var(--text);margin-bottom:12px;">Time's Up!</h2>
        <p style="margin-bottom:24px;color:var(--text-secondary);" id="timeUpMessage">
            Your exam time has expired.<br>Click the button below to see your results.
        </p>
        <button class="btn" id="viewResultBtn" style="width:100%;padding:14px;">See Result</button>
        <button class="btn" id="cancelTimeUpBtn" style="background:var(--muted);margin-top:10px;width:100%;">Return to Exams</button>
    </div>
</div>

<!-- Header -->
<a href="#quizWrapper" class="skip-link">Skip to main content</a>
<header class="app-header">
    <div class="header-left">
        <a href="user_dashboard.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
            <img src="includes/logo.png" alt="MyIslam" style="height:36px;width:36px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px;" onerror="this.style.display='none'">
            <span style="font-size:20px;font-weight:800;color:var(--brand);">MyIslam</span>
        </a>

        <nav class="header-top-nav" aria-label="Main navigation">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo !empty($navItem['active']) ? 'active' : ''; ?>">
                    <?php echo e($navItem['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
          <!-- ========== UPDATED: Notification bell with link and badge ========== -->
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="Notifications">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <!-- ================================================================= -->

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if (!empty($user['picture'])): ?>
                    <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials((string)$user['name'])); ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)$user['name'])); ?></div>
                    <?php endif; ?>

                    <div>
                        <h4><?php echo e((string)$user['name']); ?></h4>
                        <p><?php echo e((string)$user['email']); ?></p>
                        <p style="font-size: 10px;">Member since <?php echo e((string)$user['member_since']); ?></p>
                    </div>
                </div>

                <a href="profile.php">👤 My Profile</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="logout.php">🚪 Logout</a>
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
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon']); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>

            <?php if (!empty($allSubjects)): ?>
                <div class="sidebar-title" style="margin-top:24px;">📖 Subjects</div>
                <?php foreach ($allSubjects as $s): ?>
                    <?php
                        $link = !empty($s['slug'])
                            ? 'subject.php?slug=' . urlencode((string)$s['slug'])
                            : 'subject.php?id=' . (int)$s['id'];
                        $isCurrent = $subject && (int)$subject['id'] === (int)$s['id'];
                    ?>
                    <a href="<?php echo e($link); ?>" class="subject-link <?php echo $isCurrent ? 'active' : ''; ?>">
                        <span><?php echo e($s['icon'] ?? '📚'); ?></span>
                        <span><?php echo e($s['name'] ?? 'Untitled'); ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
    </aside>

<!-- Main Content -->
<div class="wrapper" id="quizWrapper">
    <div class="card">
        <div class="card-body">
            <span class="mode-pill certification">Certification Exam</span>
            <h1><?= e($quiz['title'] ?? 'Untitled Exam') ?></h1>
            <div class="meta">
                <span>Level: <?= $exam['level'] ?></span>
                <span>Type: <?= e($exam['certification_type']) ?></span>
                <?php if($exam['subject_id']): ?><span>Subject: <?= e($exam['subject_name'] ?? '') ?></span><?php endif; ?>
                <span>Passing Score: <?= (int)$quiz['passing_score'] ?>%</span>
                <?php if($timeLimitMinutes > 0): ?><span>Time limit: <?= $timeLimitMinutes ?> min</span><?php endif; ?>
            </div>
            <p class="quiz-description"><?= nl2br(e($quiz['description'] ?? '')) ?></p>
            <div class="info-box certification">
                <strong>📜 Certification Exam – Level <?= $exam['level'] ?></strong><br>
                <span>Pass this exam to advance. Level 4 grants a certificate.</span>
            </div>
            <?php if($takeLocked && $lockReason): ?>
                <div class="message-banner"><?= e($lockReason) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if(!$takeLocked && !$competitionEnded): ?>
    <div class="confirmation-card" id="confirmationCard">
        <h3>🏁 Ready to Start Certification Exam?</h3>
        <p>This is your attempt for Level <?= $exam['level'] ?>. Once you start, the timer will begin and you cannot pause.</p>
        <p><strong>Time Limit:</strong> <?= $timeLimitMinutes ? $timeLimitMinutes . ' minutes' : 'No time limit' ?></p>
        <div class="confirmation-actions">
            <button class="btn" id="confirmJoinBtn">Yes, Start Exam</button>
            <a href="<?= e($backUrl) ?>" class="btn" style="background:var(--muted);">Cancel</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="quiz-section" id="quizSection" <?= ($takeLocked || $competitionEnded) ? '' : 'style="display:none;"' ?>>
        <div class="quiz-header">
            <div class="quiz-title">📝 <?= e($quiz['title'] ?? 'Exam') ?></div>
            <p><?= $competitionEnded ? 'Review mode – certification exam has ended.' : 'Certification exam mode. Submit once within the active window.' ?></p>
        </div>
        <div id="quizContainer">
            <?php if($takeLocked): ?>
                <div id="quizResult" class="quiz-result" style="display:block;"></div>
            <?php else: ?>
                <form id="quizForm" novalidate>
                    <input type="hidden" id="quiz_id" value="<?= $examId ?>">
                    <input type="hidden" id="attempt_id" value="<?= (int)($attempt['id'] ?? 0) ?>">
                    <input type="hidden" id="csrf_token" value="<?= e($csrfToken) ?>">
                    <?php foreach($questions as $index => $q):
                        $qid = (int)$q['id'];
                        $qtype = $q['question_type'] ?? 'multiple_choice';
                        $options = $q['options'] ?? [];
                    ?>
                    <div class="quiz-question" data-question-id="<?= $qid ?>">
                        <div class="question-header">
                            <div class="question-text"><strong><?= $index + 1 ?>. </strong><?= e($q['question_text']) ?></div>
                        </div>
                        <div class="question-meta">
                            Points: <?= (int)$q['points'] ?>
                            <?php if(!empty($q['difficulty'])): ?> | Difficulty: <?= e(ucfirst($q['difficulty'])) ?><?php endif; ?>
                        </div>
                        <?php if(!empty($q['image_path'])): ?>
                            <img class="qimg" src="<?= e($q['image_path']) ?>" alt="Question image">
                        <?php endif; ?>
                        <?php if($qtype === 'explanation'): ?>
                            <textarea name="q_<?= $qid ?>" rows="1" style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--line);font-family:inherit;margin-top:10px;resize:vertical;background:var(--surface-2);color:var(--text)" placeholder="Type your explanation here..."></textarea>
                        <?php elseif(!empty($options)): ?>
                            <div class="options">
                                <?php foreach($options as $opt): ?>
                                <label class="option">
                                    <input type="radio" name="q_<?= $qid ?>" value="<?= (int)$opt['id'] ?>" required>
                                    <span><?= e($opt['option_text'] ?? '') ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="question-meta" style="color:var(--danger);">⚠️ No options available.</p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="quiz-actions">
                        <button type="button" class="submit-btn" id="submitQuizBtn">Submit Exam</button>
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
    quizId: <?= $examId ?>,
    attemptId: <?= (int)($attempt['id'] ?? 0) ?>,
    mode: 'certification',
    passingScore: <?= (int)$quiz['passing_score'] ?>,
    existingAttempt: <?= json_encode($existingAttemptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    questions: <?= json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    submitEndpoint: 'take_certification_exam.php',
    locked: <?= $takeLocked ? 'true' : 'false' ?>,
    expiresAt: <?= json_encode($expiresAt) ?>,
    antiCheatEnabled: <?= $antiCheatEnabled ? 'true' : 'false' ?>,
    timeLimitMinutes: <?= $timeLimitMinutes ?>,
    competitionActive: <?= $competitionActive ? 'true' : 'false' ?>,
    competitionEnded: <?= $competitionEnded ? 'true' : 'false' ?>,
    competitionEnd: <?= json_encode($endDateIso) ?>,
    baseUrl: '',
    backUrl: '<?= e($backUrl) ?>',
    csrfToken: '<?= e($csrfToken) ?>'
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
            if (sidebar && sidebar.classList.contains('open')) closeSidebar();
            else openSidebar();
        });
    }
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeSidebar();
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

    let currentLang = localStorage.getItem('language') || 'EN';
    if (langToggle) {
        langToggle.textContent = currentLang;
        langToggle.addEventListener('click', () => {
            currentLang = currentLang === 'EN' ? 'SW' : 'EN';
            langToggle.textContent = currentLang;
            localStorage.setItem('language', currentLang);
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
})();

// ===== EXAM FUNCTIONALITY =====
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

const ACTIVE_ATTEMPT_KEY = 'exam_active_' + window.quizTakeData.attemptId;

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
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
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
        const userAnswer = result.user_answer_text ?? optionTextForQuestion(question, result.user_answer) ?? result.user_answer ?? '';
        const correctAnswer = result.correct_answer_text ?? optionTextForQuestion(question, result.correct_answer) ?? result.correct_answer ?? '';
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
                <p>Results will be available after the certification exam window ends.</p>
    `;
    
    if (endTime && endTime > Date.now()) {
        html += `
                <div class="countdown-timer" id="resultsCountdown"></div>
                <p>Check back after the window closes to see your detailed score and review.</p>
        `;
    } else {
        html += `
                <p>The exam window has ended. <a href="javascript:location.reload()">Refresh the page</a> to see your results.</p>
        `;
    }
    
    html += `
                <a href="${window.quizTakeData.backUrl}" class="btn" style="margin-top: 1rem;">Back to Certification Exams</a>
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

    if (window.quizTakeData.mode === 'certification' && window.quizTakeData.competitionActive) {
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
                ${passed ? '🎉 Congratulations! You passed this exam.' : '❌ You failed to reach the passing score. Review the lessons before your next attempt.'}
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
            <div class="locked-banner">🔒 This exam submission is now locked. Review only is available.</div>
            ${data.certCode ? `
                <div class="locked-banner certificate-earned-banner">
                    <h3 style="margin-bottom:8px;">🎓 Certificate Earned!</h3>
                    <p style="margin-bottom:12px;">You have successfully completed this certification track.</p>
                    <a class="btn" href="certificate_view.php?code=${encodeURIComponent(data.certCode)}" target="_blank" rel="noopener">
                        View Certificate
                    </a>
                </div>
            ` : ''}
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
        let imageHtml = '';
        if (question && question.image_path) {
            let imgSrc = question.image_path;
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
    if (window.quizTakeData.mode === 'certification' && window.quizTakeData.competitionActive && !window.quizTakeData.competitionEnded) {
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
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
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
    if (warningTimeout) { clearTimeout(warningTimeout); warningTimeout = null; }
    if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
}

function cleanupAfterSubmission() {
    examActive = false;
    warningModalVisible = false;
    clearWarningTimers();
    if (timeUpModal) { timeUpModal.style.display = 'none'; document.body.style.overflow = ''; }
    if (warningModal) warningModal.style.display = 'none';
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
        csrf_token: window.quizTakeData.csrfToken
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
            throw new Error(data.message || 'Failed to submit exam');
        }

        // Redirect to result page
        showToast('Exam submitted successfully! Redirecting to results...', false);
        window.quizTakeData.locked = true;
        cleanupAfterSubmission();
        setTimeout(() => {
            window.location.href = 'certification_exam_result.php?attempt_id=' + window.quizTakeData.attemptId;
        }, 1500);
        return true;
    } catch (error) {
        console.error('Submission error:', error);
        showToast(error.message || 'Unable to submit exam. Please try again.', true);
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
    const preventEscape = (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); } };
    document.addEventListener('keydown', preventEscape, true);
    window._preventEscapeHandler = preventEscape;
}

function hideTimeUpModal() {
    if (timeUpModal) { timeUpModal.style.display = 'none'; document.body.style.overflow = ''; }
    if (window._preventEscapeHandler) {
        document.removeEventListener('keydown', window._preventEscapeHandler, true);
        delete window._preventEscapeHandler;
    }
}

async function handleTimeExpired() {
    if (submitInProgress || window.quizTakeData.locked) return;
    if (timeUpModal) {
        document.getElementById('timeUpMessage').textContent = 'Your time has expired. Submitting your answers automatically...';
        if (viewResultBtn) { viewResultBtn.disabled = true; viewResultBtn.textContent = 'Submitting...'; }
        timeUpModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    const answers = collectAnswers();
    const success = await performSubmission(answers);
    if (success) {
        hideTimeUpModal();
        showToast('Exam submitted successfully.', false);
    } else {
        if (timeUpModal) {
            document.getElementById('timeUpMessage').textContent = 'Auto‑submission failed. Please try again manually.';
            document.getElementById('timeUpMessage').style.color = 'var(--danger)';
            if (viewResultBtn) { viewResultBtn.disabled = false; viewResultBtn.textContent = 'Retry Submission'; }
        }
    }
}

if (viewResultBtn) {
    viewResultBtn.addEventListener('click', async () => {
        const answers = collectAnswers();
        viewResultBtn.disabled = true; viewResultBtn.textContent = 'Submitting...';
        const success = await performSubmission(answers);
        if (success) { hideTimeUpModal(); showToast('Exam submitted successfully.', false); }
        else { viewResultBtn.disabled = false; viewResultBtn.textContent = 'Retry Submission'; }
    });
}
if (cancelTimeUpBtn) {
    cancelTimeUpBtn.addEventListener('click', () => { window.location.href = window.quizTakeData.backUrl; });
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

// Confirmation flow –> show instructions
if (confirmJoinBtn) {
    confirmJoinBtn.addEventListener('click', () => {
        confirmationCard.style.display = 'none';
        instructionsModal.style.display = 'flex';
    });
}

// Start exam (called from instructions modal "I Understand, Start Exam" button)
async function startExam() {
    startBtn.disabled = true;
    startBtn.textContent = 'Starting...';
    try {
        const resp = await fetch(window.quizTakeData.submitEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'start_exam',
                quiz_id: window.quizTakeData.quizId,
                csrf_token: window.quizTakeData.csrfToken
            })
        });
        const data = await resp.json();
        if (!data.success) {
            alert(data.message || 'Could not start exam.');
            startBtn.disabled = false;
            startBtn.textContent = 'I Understand, Start Exam';
            return;
        }
        window.quizTakeData.attemptId = data.attempt_id;
        document.getElementById('attempt_id').value = data.attempt_id;
        // Update timer expiry if provided
        if (data.expires_at) {
            window.quizTakeData.expiresAt = data.expires_at;
            // restart countdown
            if (timerInterval) clearInterval(timerInterval);
            const endTime = new Date(data.expires_at.replace(' ', 'T')).getTime();
            const timerEl = document.getElementById('timer');
            if (!timerEl) {
                const timerPara = document.createElement('p');
                timerPara.className = 'timer';
                timerPara.innerHTML = '⏱️ Time remaining: <span id="timer"></span>';
                document.querySelector('.card-body').appendChild(timerPara);
            }
            function updateTimer() {
                const diff = endTime - Date.now();
                if (diff <= 0) { clearInterval(timerInterval); handleTimeExpired(); return; }
                const mins = Math.floor(diff / 60000);
                const secs = Math.floor((diff % 60000) / 1000);
                const span = document.getElementById('timer');
                if (span) span.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
            }
            updateTimer();
            timerInterval = setInterval(updateTimer, 1000);
        }
        instructionsModal.style.display = 'none';
        quizSection.style.display = 'block';
        examActive = true;
        sessionStorage.setItem('exam_active_' + data.attempt_id, '1');
        try { await document.documentElement.requestFullscreen(); } catch (e) {}
        attachAntiCheat();
    } catch (err) {
        alert('Failed to start exam: ' + err.message);
        startBtn.disabled = false;
        startBtn.textContent = 'I Understand, Start Exam';
    }
}

if (startBtn) {
    startBtn.addEventListener('click', startExam);
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
    formData.append('attempt_id', window.quizTakeData.attemptId);
    formData.append('quiz_id', window.quizTakeData.quizId);
    try {
        await fetch(window.quizTakeData.submitEndpoint, { method: 'POST', body: formData });
    } catch (e) {}
}

async function terminateAttempt() {
    const formData = new FormData();
    formData.append('action', 'terminate_attempt');
    formData.append('attempt_id', window.quizTakeData.attemptId);
    formData.append('quiz_id', window.quizTakeData.quizId);
    try {
        await fetch(window.quizTakeData.submitEndpoint, { method: 'POST', body: formData });
    } catch (e) {}
}

function beaconAbandon() {
    const formData = new FormData();
    formData.append('action', 'abandon_attempt');
    formData.append('attempt_id', window.quizTakeData.attemptId);
    formData.append('quiz_id', window.quizTakeData.quizId);
    if (navigator.sendBeacon) {
        navigator.sendBeacon(window.quizTakeData.submitEndpoint, formData);
    } else {
        try { 
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.quizTakeData.submitEndpoint, false);
            xhr.send(formData);
        } catch (e) {}
    }
}

async function checkStaleActiveAttempt() {
    if (sessionStorage.getItem(ACTIVE_ATTEMPT_KEY) === '1') {
        try { await abandonAttempt(); } catch (e) {}
        sessionStorage.removeItem(ACTIVE_ATTEMPT_KEY);
        window.location.reload();
        return true;
    }
    return false;
}

if (window.quizTakeData.antiCheatEnabled && !window.quizTakeData.locked) {
    checkStaleActiveAttempt().then(stale => { if (stale) throw new Error('Stale attempt'); });
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
        terminateAttempt();
        warningTitle.textContent = '❌ Exam Terminated';
        warningMessage.textContent = 'You have violated the rules three times. Your attempt has been disqualified.';
        resumeBtn.textContent = 'Return to Certification Exams';
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