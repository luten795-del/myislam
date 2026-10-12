<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/config/database.php';

if (!function_exists('quizE')) {
    function quizE($str): string {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

function requireUserAuthForExamResult(): int
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    return (int)$_SESSION['user_id'];
}

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
        return null;
    }
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

function getUnreadCount(PDO $conn, int $userId): int
{
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function getMonthlyExam(PDO $conn, int $examId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM monthly_exams WHERE id = ? LIMIT 1");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    return $exam ?: null;
}

function getMonthlyExamQuestionsWithOptions(PDO $conn, int $examId): array
{
    $stmt = $conn->prepare("
        SELECT
            q.id, q.exam_id, q.question_text, q.question_type, q.points, q.image_path, q.sort_order,
            e.expected_answer, e.explanation, e.keywords_json
        FROM monthly_exam_questions q
        LEFT JOIN monthly_exam_question_explanations e
            ON q.id = e.question_id AND q.question_type = 'explanation'
        WHERE q.exam_id = ?
        ORDER BY q.sort_order, q.id
    ");
    $stmt->execute([$examId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $optStmt = $conn->prepare("
        SELECT id, question_id, option_text, image_path, is_correct
        FROM monthly_exam_question_options
        WHERE question_id = ?
        ORDER BY id ASC
    ");

    foreach ($questions as &$q) {
        $q['negative_points'] = 0;
        $q['difficulty'] = null;
        $q['options'] = [];
        if (($q['question_type'] ?? '') !== 'explanation') {
            $optStmt->execute([(int)$q['id']]);
            $q['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
    unset($q);
    return $questions;
}

function generateReviewFromQuestions(array $questions): array
{
    $review = [];
    foreach ($questions as $q) {
        $correctOption = null;
        $correctText = '';
        if (($q['question_type'] ?? '') === 'explanation') {
            $correctText = $q['expected_answer'] ?? '';
        } else {
            foreach (($q['options'] ?? []) as $opt) {
                if (!empty($opt['is_correct'])) {
                    $correctOption = $opt['id'];
                    $correctText = $opt['option_text'] ?? '';
                    break;
                }
            }
        }
        $review[] = [
            'question_id' => (int)$q['id'],
            'question_text' => $q['question_text'] ?? '',
            'user_answer' => null,
            'user_answer_text' => null,
            'correct_answer' => $correctOption,
            'correct_answer_text' => $correctText,
            'is_correct' => false,
            'points_earned' => 0,
            'explanation' => $q['explanation'] ?? '',
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
        'percentage'       => (float)round((float)($attempt['percentage'] ?? 0)),
        'passed'           => ((int)($attempt['passed'] ?? 0) === 1),
        'correct_count'    => (int)($attempt['correct_answers'] ?? 0),
        'total_questions'  => (int)($attempt['total_questions'] ?? count(array_filter($questions, fn($q) => ($q['question_type'] ?? '') !== 'explanation'))),
        'results'          => $results,
        'attempted'        => true,
        'locked'           => true,
        'status'           => $attempt['status'] ?? 'completed',
        'has_user_answers' => $hasUserAnswers,
    ];
}

$userId = requireUserAuthForExamResult();
$conn = db();
if (!$conn instanceof PDO) {
    exit('Database connection failed.');
}

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseUrl = str_replace('/admin', '', $baseUrl);
$baseUrl = rtrim($baseUrl, '/') . '/';

$dbUser = getCurrentUser($conn, $userId);
if (!$dbUser) {
    session_destroy();
    header('Location: login.php?error=account_disabled');
    exit;
}
$user = [
    'name' => $dbUser['full_name'] ?? 'User',
    'email' => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role' => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime($dbUser['created_at'])) : 'recently',
];
$unreadCount = getUnreadCount($conn, $userId);

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'user_dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'subjects.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'quizzes.php', 'active' => false],
    ['name' => 'Monthly Exams', 'link' => 'monthly_exams.php', 'active' => true],
    ['name' => 'Leaderboard', 'link' => 'leaderboard.php', 'active' => false],
    ['name' => 'Multimedia', 'link' => 'multimedia.php', 'active' => false],
    ['name' => 'Notifications', 'link' => 'notification.php', 'active' => false],
    ['name' => 'Help Center', 'link' => 'help_center.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Quizzes', 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Monthly Exams', 'link' => 'monthly_exams.php', 'active' => true, 'icon' => '📅'],
        ['name' => 'Certification Exams', 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
        [
    'name' => 'My Certificates',
    'link' => 'my_certificates.php',
    'active' => false,
    'icon' => '🎓'
],
    ['name' => 'Leaderboard', 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => 'Multimedia', 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => 'Notifications', 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => 'Help Center', 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];

$attemptId = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
$examId = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

$attempt = null;
if ($attemptId > 0) {
    $stmt = $conn->prepare("SELECT * FROM monthly_exam_attempts WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$attemptId, $userId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$attempt) exit('Monthly exam result not found.');
    $examId = (int)$attempt['exam_id'];
} elseif ($examId > 0) {
    $stmt = $conn->prepare("
        SELECT *
        FROM monthly_exam_attempts
        WHERE exam_id = ? AND user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$examId, $userId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$exam = getMonthlyExam($conn, $examId);
if (!$exam) {
    exit('Monthly exam not found.');
}

$quizId = $examId;
$quiz = [
    'title' => $exam['title'] ?? 'Monthly Exam',
    'description' => $exam['description'] ?? '',
    'passing_score' => $exam['passing_score'] ?? 70,
    'time_limit_minutes' => $exam['time_limit_minutes'] ?? 0,
    'hints_enabled' => false,
    'mode' => 'competition',
];
$quizMode = 'competition';
$isCompetition = true;
$isSelf = false;
$isLesson = false;

$startDt = new DateTime((string)$exam['start_date'], new DateTimeZone('Africa/Dar_es_Salaam'));
$endDt   = new DateTime((string)$exam['end_date'], new DateTimeZone('Africa/Dar_es_Salaam'));
$now = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));

$competitionActive = $now >= $startDt && $now <= $endDt;
$competitionUpcoming = $now < $startDt;
$competitionEnded = $now > $endDt;
$windowLabel = date('M d, Y H:i', strtotime((string)$exam['start_date'])) . ' - ' . date('M d, Y H:i', strtotime((string)$exam['end_date']));
$endDateIso = $endDt->format('c');

$questions = getMonthlyExamQuestionsWithOptions($conn, $examId);
$gradableQuestions = array_filter($questions, fn($q) => ($q['question_type'] ?? '') !== 'explanation');

$existingCompletedAttempt = $attempt;
$takeLocked = true;
$lockReason = '';

if ($attempt) {
    $existingAttemptPayload = buildReviewFromAttempt($attempt, $questions);
    if ($competitionEnded) {
        $lockReason = 'This monthly exam has ended. Here are your results.';
    } else {
        $lockReason = 'Your attempt has been recorded. Results will appear after the monthly exam window ends.';
    }
} else {
    $existingAttemptPayload = [
        'passed' => false,
        'percentage' => 0,
        'score' => 0,
        'total' => array_sum(array_map(fn($q) => (int)($q['points'] ?? 0), $gradableQuestions)),
        'correct_count' => 0,
        'total_questions' => count($gradableQuestions),
        'results' => generateReviewFromQuestions($questions),
        'attempted' => false,
        'locked' => true,
        'has_user_answers' => false
    ];
    $lockReason = $competitionEnded
        ? 'This monthly exam has ended. You did not participate. Below are the correct answers for review.'
        : 'This monthly exam is still in progress. Results are not available yet.';
}

$attemptCount = 0;
$maxAttempts = 1;
$unlimited = false;
$weeklyAttempts = $attempt ? 1 : 0;
$attempt = null;
$expiresAt = null;
$antiCheatEnabled = false;
$timeLimitMinutes = (int)($exam['time_limit_minutes'] ?? 0);
$backUrl = $baseUrl . 'monthly_exams.php';
$modeLabel = 'Monthly Exam';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo quizE((string)($quiz['title'] ?? 'Take Monthly Exam')); ?> - MyIslam</title>
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

    background: var(--bg);
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
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%23166534' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
        }

        body.dark-mode::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%237dd3a7' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }

        a { text-decoration: none; color: inherit; }
        button { font: inherit; border: none; background: none; cursor: pointer; }

        a:focus-visible,
        button:focus-visible {
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

        /* Header */
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

        .profile-area { position: relative; }
        .profile-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--surface);
            border: 2px solid var(--brand);
            cursor: pointer;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand);
            font-weight: 700;
        }

        .profile-btn img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

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

        /* Main content area (matches dashboard's .main-content / .dashboard-stack) */
        .main-content {
            padding: 24px 24px 20px;
            max-width: 1000px; margin: 0 auto;
            width: 100%;
            overflow-x: hidden;
        }

        .dashboard-stack { display: grid; gap: 20px; }

        .wrapper {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0;
            display: grid;
            gap: 20px;
        }

        /* Exam specific cards, styled like dashboard's .section-card */
        .section-card, .card, .quiz-section {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: clamp(16px, 5vw, 24px);
            transition: all 0.2s;
            width: 100%;
            overflow: hidden;
        }

        .section-card:hover, .card:hover, .quiz-section:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .card, .quiz-section { padding: 0; }

        .card-body { padding: clamp(16px, 5vw, 24px); text-align: center; }
        .card-body p, .card-body .info-box, .card-body .message-banner { text-align: left; }

        .mode-pill {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid var(--line);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 10px;
        }

        .mode-pill.lesson { background: #d1fae5; color: #065f46; }
        .mode-pill.competition { background: #fef3c7; color: #92400e; }
        .mode-pill.self { background: #ede9fe; color: #5b21b6; }
        body.dark-mode .mode-pill.lesson { background: rgba(16,185,129,0.18); color: #6ee7b7; }
        body.dark-mode .mode-pill.competition { background: rgba(245,158,11,0.18); color: #fcd34d; }
        body.dark-mode .mode-pill.self { background: rgba(139,92,246,0.2); color: #c4b5fd; }

        h1 {
            margin-bottom: 12px;
            color: var(--brand);
            font-size: clamp(20px, 5vw, 28px);
            word-break: break-word;
        }

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
            text-align: center;
        }

        .attempt-info {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 12px;
            text-align: center;
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

        .info-box.competition { background: #fffbeb; border-color: #f59e0b; }
        .info-box.ended { background: #fef2f2; border-color: #dc2626; }
        .info-box.self { background: #f5f3ff; border-color: #8b5cf6; }
        .info-box.lesson { background: #ecfdf5; border-color: #10b981; }
        body.dark-mode .info-box.competition { background: rgba(245,158,11,0.12); color: var(--text); }
        body.dark-mode .info-box.ended { background: rgba(220,38,38,0.14); color: var(--text); }
        body.dark-mode .info-box.self { background: rgba(139,92,246,0.14); color: var(--text); }
        body.dark-mode .info-box.lesson { background: rgba(16,185,129,0.12); color: var(--text); }

        .quiz-header {
            background: linear-gradient(135deg, var(--accent) 0%, var(--brand) 100%);
            color: white;
            padding: 1.5rem 2rem;
            text-align: center;
            border: 1px solid var(--line);
        }

        .quiz-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .quiz-question {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--line);
            max-width: 100%;
            overflow-wrap: anywhere;
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
            font-size: clamp(0.95rem, 3.5vw, 1.1rem);
            overflow-wrap: anywhere;
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
            max-width: 100%;
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
            overflow-wrap: anywhere;
        }

        .option:hover {
            background: var(--brand-soft);
            border-color: var(--brand);
        }

        .option input { accent-color: var(--brand); cursor: pointer; flex-shrink: 0; }

        img.qimg {
            display: block;
            max-width: 100%;
            width: auto;
            height: auto;
            max-height: 320px;
            border-radius: 10px;
            border: 1px solid var(--line);
            margin: 12px auto;
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
            max-width: 100%;
            overflow-wrap: anywhere;
        }

        .result-banner {
            text-align: center;
            font-weight: 700;
            font-size: clamp(1rem, 4vw, 1.15rem);
            padding: 1rem;
        }

        .result-pass { color: #059669; }
        .result-fail { color: #dc2626; }
        body.dark-mode .result-pass { color: #34d399; }
        body.dark-mode .result-fail { color: #f87171; }

        /* ===== Score summary: glowing green for pass, cool red for fail ===== */
        @keyframes glowPass {
            0%, 100% { box-shadow: 0 0 16px rgba(16,185,129,0.28), inset 0 0 0 1px rgba(16,185,129,0.25); }
            50%      { box-shadow: 0 0 30px rgba(16,185,129,0.5), inset 0 0 0 1px rgba(16,185,129,0.35); }
        }
        @keyframes glowFail {
            0%, 100% { box-shadow: 0 0 16px rgba(59,130,246,0.18), 0 0 12px rgba(239,68,68,0.22), inset 0 0 0 1px rgba(239,68,68,0.22); }
            50%      { box-shadow: 0 0 26px rgba(59,130,246,0.3), 0 0 22px rgba(239,68,68,0.38), inset 0 0 0 1px rgba(239,68,68,0.32); }
        }

        .score-summary {
            margin: 6px;
            padding: clamp(16px, 5vw, 24px);
            border-radius: var(--radius-lg);
            text-align: center;
            border: 1px solid var(--line);
        }

        .score-summary.pass {
            background: linear-gradient(135deg, rgba(16,185,129,0.16) 0%, rgba(16,185,129,0.04) 100%);
            border-color: rgba(16,185,129,0.4);
            animation: glowPass 2.6s ease-in-out infinite;
        }

        .score-summary.fail {
            background: linear-gradient(135deg, rgba(59,130,246,0.10) 0%, rgba(239,68,68,0.07) 100%);
            border-color: rgba(239,68,68,0.4);
            animation: glowFail 2.6s ease-in-out infinite;
        }

        body.dark-mode .score-summary.pass {
            background: linear-gradient(135deg, rgba(16,185,129,0.22) 0%, rgba(16,185,129,0.06) 100%);
        }
        body.dark-mode .score-summary.fail {
            background: linear-gradient(135deg, rgba(59,130,246,0.16) 0%, rgba(239,68,68,0.12) 100%);
        }

        .score-summary .score-value {
            font-size: clamp(1.5rem, 6vw, 2.1rem);
            font-weight: 800;
            margin-bottom: 6px;
        }

        .score-summary.pass .score-value { color: #059669; }
        .score-summary.fail .score-value { color: #dc2626; }
        body.dark-mode .score-summary.pass .score-value { color: #34d399; }
        body.dark-mode .score-summary.fail .score-value { color: #f87171; }

        .score-summary .score-sub {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 4px;
        }

        /* ===== Review question/answer cards — clear green success styling in both themes ===== */
        .review-list { display: grid; gap: 1rem; }

        .review-item {
            padding: 1rem;
            margin: 6px;
            border-radius: var(--radius-md);
            border: 1px solid var(--line);
            border-left: 4px solid var(--line);
            max-width: 100%;
            overflow-wrap: anywhere;
        }

        .review-item.correct {
            background: rgba(16,185,129,0.09);
            border-color: rgba(16,185,129,0.35);
            border-left-color: #10b981;
        }

        .review-item.incorrect {
            background: rgba(239,68,68,0.07);
            border-color: rgba(239,68,68,0.3);
            border-left-color: #ef4444;
        }

        body.dark-mode .review-item.correct {
            background: rgba(16,185,129,0.16);
            border-color: rgba(16,185,129,0.4);
        }

        body.dark-mode .review-item.incorrect {
            background: rgba(239,68,68,0.15);
            border-color: rgba(239,68,68,0.4);
        }

        .review-item .review-q-text {
            font-weight: 700;
            color: var(--text);
        }

        .review-item .review-label { color: var(--muted); }

        .review-item .review-correct-text {
            color: #059669;
            font-weight: 700;
        }
        body.dark-mode .review-item .review-correct-text { color: #34d399; }

        .review-item.incorrect .review-user-text { color: #dc2626; font-weight: 600; }
        body.dark-mode .review-item.incorrect .review-user-text { color: #f87171; }
        .review-item.correct .review-user-text { color: #059669; font-weight: 600; }
        body.dark-mode .review-item.correct .review-user-text { color: #34d399; }

        .locked-banner {
            margin-top: 1rem;
            margin: 6px;
            padding: 0.9rem 1rem;
            border-radius: var(--radius-md);
            background:var(--brand-soft);
            border: 1px solid var(--line);
            border-radius: 10px;
            color: #1d4ed8;
            font-weight: 600;
        }

        .message-banner {
            margin-top: 1rem;
            padding: 0.9rem 1rem;
            border-radius: var(--radius-md);
            background: #fef3c7;
            color: #92400e;
            font-weight: 600;
            border: 1px solid var(--line);        
        }

        .pending-results {
            text-align: center;
            padding: 2rem;
            background: #f0fdf4;
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
            border: 1px solid var(--line);
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
            background: rgba(0,0,0,0.6);
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

        html { overflow-x: hidden; width: 100%; }
        body { overflow-x: hidden; width: 100%; }
        .main-content, .wrapper { max-width: 100%; }
        img { max-width: 100%; height: auto; }

        @media (max-width: 768px) {
            body { padding: 0; }
            .main-content { padding: 16px 10px 20px; }
            .wrapper { padding: 0; gap: 14px; }
            .card-body, .quiz-header, .quiz-question, .quiz-actions, .quiz-result { padding: 14px; }
            .question-header { flex-direction: column; }
            .toast { left: 1rem; right: 1rem; bottom: 1rem; text-align: center; }
            .confirmation-actions { flex-direction: column; }
            .app-header { padding: 10px 14px; }
            .quiz-result { padding: 0px; }
            .score-summary { padding: 16px; margin: 4px; }
            .review-item { margin: 4px 0; padding: 14px; }
            .meta { gap: 0.5rem 0.75rem; font-size: 13px; }
        }

        @media (max-width: 420px) {
            .header-top-nav { display: none; }
            .quiz-question, .quiz-header, .quiz-actions { padding: 12px; }
            .option { padding: 0.7rem 0.85rem; }
        }
    </style>
</head>
<body>

<a href="#mainContent" class="skip-link">Skip to main content</a>

<header class="app-header">
    <div class="header-left">
        <a href="user_dashboard.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
            <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px;">
            <span style="font-size:22px;font-weight:1000;color:var(--brand);">MyIslam</span>
        </a>

        <nav class="header-top-nav" aria-label="Main navigation">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?php echo quizE($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>">
                    <?php echo quizE($navItem['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="Notifications">
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
            <a href="<?php echo quizE($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                <span><?php echo quizE($item['icon']); ?></span>
                <span><?php echo quizE($item['name']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>

<main class="main-content" id="mainContent">
<div class="wrapper">
    <div class="card section-card">
        <div class="card-body">
            <span class="mode-pill <?php echo quizE($quizMode); ?>">
                <?php echo quizE($modeLabel); ?> Mode
            </span>

            <h1><?php echo quizE((string)($quiz['title'] ?? 'Untitled Exam')); ?></h1>

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
                <p><?php echo nl2br(quizE((string)$quiz['description'])); ?></p>
            <?php endif; ?>

            <?php if ($isCompetition): ?>
                <div class="info-box competition <?php echo $competitionEnded ? 'ended' : ''; ?>">
                    <strong>🏆 Monthly Competition</strong><br>
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
                    <span>This exam is for personal review and revision.</span>
                </div>
            <?php else: ?>
                <div class="info-box lesson">
                    <strong>📖 Lesson Exam</strong><br>
                    <span>Complete this exam to test your understanding of the lesson material.</span>
                </div>
            <?php endif; ?>

            <div class="attempt-info">
                <?php if ($isCompetition): ?>
                    Window attempt: <?php echo $weeklyAttempts; ?> / 1
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

    <div class="quiz-section section-card" id="quizSection">
        <div class="quiz-header">
            <div class="quiz-title">📝 <?php echo quizE((string)($quiz['title'] ?? 'Exam')); ?></div>
            <p>
                <?php if ($isCompetition): ?>
                    <?php echo $competitionEnded ? 'Review mode – monthly exam has ended.' : 'Monthly exam mode. Submit once within the active window.'; ?>
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
                    <div class="message-banner" style="margin:1rem ;">⚠️ This attempt was terminated due to rule violations.</div>
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
                        <button type="button" class="submit-btn" id="submitQuizBtn">Submit Exam</button>
                    </div>
                </form>

                <div id="quizResult" style="display:none;"></div>
            <?php endif; ?>
        </div>
    </div>
</div>
</main>
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
    submitEndpoint: <?php echo json_encode('take_monthly_exam.php'); ?>,
    locked: <?php echo $takeLocked ? 'true' : 'false'; ?>,
    expiresAt: <?php echo json_encode($expiresAt); ?>,
    antiCheatEnabled: <?php echo $antiCheatEnabled ? 'true' : 'false'; ?>,
    timeLimitMinutes: <?php echo $timeLimitMinutes; ?>,
    competitionActive: <?php echo $competitionActive ? 'true' : 'false'; ?>,
    competitionEnded: <?php echo $competitionEnded ? 'true' : 'false'; ?>,
    competitionEnd: <?php echo json_encode($endDateIso); ?>,
    baseUrl: <?php echo json_encode($baseUrl); ?>
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

// ===== RESULT DISPLAY FUNCTIONALITY (No active exam logic) =====
const toast = document.getElementById('toast');

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
                <p>Results will be available after the monthly exam window ends.</p>
    `;
    
    if (endTime && endTime > Date.now()) {
        html += `
                <div class="countdown-timer" id="resultsCountdown"></div>
                <p>Check back after the monthly exam window closes to see your detailed score and review.</p>
        `;
    } else {
        html += `
                <p>The monthly exam has ended. <a href="javascript:location.reload()">Refresh the page</a> to see your results.</p>
        `;
    }
    
    html += `
                <a href="<?php echo quizE($backUrl); ?>" class="btn" style="margin-top: 1rem;">Back to Monthly Exams</a>
            </div>
        </div>
    `;
    
    resultBox.innerHTML = html;
    resultBox.style.display = 'block';
    
    const quizForm = document.getElementById('quizForm');
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
            <div class="result-banner ${passed ? 'result-pass' : 'result-fail'}">
                ${passed ? '🎉 Congratulations! You passed this exam.' : '❌ Exam completed. Review your result and revise below.'}
            </div>
    `;

    if (hasUserAnswers || (score > 0 || total > 0)) {
        html += `
            <div class="score-summary ${passed ? 'pass' : 'fail'}">
                <div class="score-value">${score}/${total} <span style="font-size:0.7em;">(${percentage}%)</span></div>
                <p class="score-sub">Correct answers: ${correctCount} of ${totalQuestions} questions</p>
                <p class="score-sub">Passing score: ${window.quizTakeData.passingScore}%</p>
            </div>
        `;
    }

    html += `
            <div class="locked-banner">🔒 This exam submission is now locked. Review only is available.</div>
            <h3 style="margin:1.5rem 0 1rem; text-align:center;">📋 Review Questions and Correct Answers</h3>
            <div class="review-list">
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
            imageHtml = `<div style="margin: 10px 0;"><img src="${escapeHtml(imgSrc)}" class="qimg"></div>`;
        }

        const userAnswerRow = hasUserAnswers
            ? `<p style="margin:.5rem 0;"><span class="review-label">Your answer:</span> <span class="review-user-text">${userAnswer}</span> ${isCorrect ? '✅' : '❌'}</p>`
            : '';

        html += `
            <div class="review-item ${isCorrect ? 'correct' : 'incorrect'}">
                <p class="review-q-text">${idx + 1}. ${questionText}</p>
                ${imageHtml}
                ${userAnswerRow}
                <p style="margin:.5rem 0;"><span class="review-label">Correct answer:</span> <span class="review-correct-text">${correctAnswer}</span></p>
                ${explanation ? `<p style="margin:.5rem 0; font-size:.9rem; color:var(--muted);">💡 ${explanation}</p>` : ''}
            </div>
        `;
    });

    html += '</div></div>';
    resultBox.innerHTML = html;
    resultBox.style.display = 'block';

    const quizForm = document.getElementById('quizForm');
    if (quizForm) quizForm.style.display = 'none';
}

// Render existing attempt immediately
if (window.quizTakeData.existingAttempt) {
    renderAttemptResult(window.quizTakeData.existingAttempt, window.quizTakeData.questions);
}
</script>
</body>
</html>