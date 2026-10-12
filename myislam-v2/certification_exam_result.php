<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/includes/translations.php';
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

function getCertificationExam(PDO $conn, int $examId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM certification_exams WHERE id = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    return $exam ?: null;
}

function getCertificationExamQuestionsWithOptions(PDO $conn, int $examId): array
{
    $stmt = $conn->prepare("
        SELECT * FROM certification_exam_questions
        WHERE exam_id = ?
        ORDER BY sort_order, id
    ");
    $stmt->execute([$examId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $optStmt = $conn->prepare("
        SELECT id, question_id, option_text, image_path, is_correct
        FROM certification_exam_question_options
        WHERE question_id = ?
        ORDER BY sort_order
    ");

    foreach ($questions as &$q) {
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

$attemptId = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
$examId = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

$attempt = null;
if ($attemptId > 0) {
    $stmt = $conn->prepare("SELECT * FROM certification_exam_attempts WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$attemptId, $userId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$attempt) exit('Certification exam result not found.');
    $examId = (int)$attempt['exam_id'];
} elseif ($examId > 0) {
    $stmt = $conn->prepare("
        SELECT *
        FROM certification_exam_attempts
        WHERE exam_id = ? AND user_id = ? AND status IN ('completed','abandoned')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$examId, $userId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    exit('Invalid request.');
}

$exam = getCertificationExam($conn, $examId);
if (!$exam) {
    exit('Certification exam not found or not published.');
}

// Admin access lock - blocks starting/viewing a locked exam entirely,
// EXCEPT a user's own already-existing attempt (in progress or completed)
// stays viewable/resumable - their earned progress isn't taken away just
// because the exam is later locked for new attempts. Defaults to allowed
// if the column isn't present yet (migration not run).
$examAdminLocked = isset($exam['allow_access']) && (int)$exam['allow_access'] === 0;
if ($examAdminLocked && !$attempt) {
    exit('This certification exam is currently unavailable. Please check back later.');
}

$quizId = $examId;
$quiz = [
    'title' => $exam['title'] ?? 'Certification Exam',
    'description' => $exam['description'] ?? '',
    'passing_score' => $exam['passing_score'] ?? 70,
    'time_limit_minutes' => $exam['time_limit_minutes'] ?? 0,
    'mode' => 'certification',
];
$quizMode = 'certification';

$questions = getCertificationExamQuestionsWithOptions($conn, $examId);
$gradableQuestions = array_filter($questions, fn($q) => ($q['question_type'] ?? '') !== 'explanation');

// ----- Exam window detection -----
$startDt = null;
$endDt   = null;
$nowDt   = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));
$competitionActive = false;
$competitionEnded  = false;
$endDateIso        = null;

if (!empty($exam['start_date']) && !empty($exam['end_date'])) {
    $startDt = new DateTime($exam['start_date'], new DateTimeZone('Africa/Dar_es_Salaam'));
    $endDt   = new DateTime($exam['end_date'],   new DateTimeZone('Africa/Dar_es_Salaam'));
    $competitionActive = ($nowDt >= $startDt && $nowDt <= $endDt);
    $competitionEnded  = ($nowDt > $endDt);
    $endDateIso = $endDt->format('c');
}

// ----- Lock reason & payload -----
$takeLocked = true;
$lockReason = '';

if ($attempt) {
    $existingAttemptPayload = buildReviewFromAttempt($attempt, $questions);

    if ($competitionActive) {
        $lockReason = 'Your attempt has been recorded. Results will be available after the exam window closes on '
                      . date('M d, Y H:i', strtotime($exam['end_date']));
    } else {
        if ($attempt['passed'] == 1) {
            $lockReason = 'Congratulations! You passed this exam.';
        } else {
            $lockReason = 'Exam attempt completed. Results are now available.';
        }
    }
} else {
    $existingAttemptPayload = [
        'passed'         => false,
        'percentage'     => 0,
        'score'          => 0,
        'total'          => array_sum(array_map(fn($q) => (int)($q['points'] ?? 0), $gradableQuestions)),
        'correct_count'  => 0,
        'total_questions' => count($gradableQuestions),
        'results'        => generateReviewFromQuestions($questions),
        'attempted'      => false,
        'locked'         => true,
        'has_user_answers' => false
    ];

    if ($competitionActive) {
        $lockReason = 'This exam is still in progress. Results will appear after the window closes.';
    } elseif ($competitionEnded) {
        $lockReason = 'This exam has ended. You did not participate. Below are the correct answers for review.';
    } else {
        $lockReason = 'You have not attempted this exam yet. Below are the correct answers for review.';
    }
}

$backUrl = $baseUrl . 'certification_exams.php';
$modeLabel = 'Certification';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= quizE($quiz['title'] ?? 'Certification Exam Result') ?> - MyIslam</title>
    <style>
        /* ===== DASHBOARD BASE STYLES ===== */
        *{margin:0;padding:0;box-sizing:border-box}
        :root{
            --bg:#f5f7f5;--surface:#ffffff;--surface-2:#f9fafb;--surface-3:#eef2f7;--line:#e2e5e2;
            --text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;
            --brand:#0E4A34;--brand-dark:#0A3626;--brand-light:#3E7A5E;--brand-soft:rgba(14, 74, 52, 0.08);
            --gold:#9C7A2E;--gold-soft:rgba(156, 122, 46, 0.10);
            --accent:#3E6E91;--accent-soft:rgba(62, 110, 145, 0.10);
            --primary:var(--accent);--primary-soft:var(--accent-soft);
            --header-bg:#ffffff;--header-height:72px;--shadow-sm:0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md:0 4px 6px -1px rgb(0 0 0 / 0.08);--shadow-lg:0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius-sm:12px;--radius-md:16px;--radius-lg:20px;--danger:#b91c1c;--success:#0E4A34;--warning:#b45309;
            --white:#ffffff;--overlay-dim:rgba(15, 23, 32, 0.45);--glass-bg-strong:rgba(255, 255, 255, 0.92);
        }
        body.dark-mode{
            --bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;--line:#304150;
            --text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;
            --brand:#4C9172;--brand-dark:#6bb590;--brand-light:#7fcaa4;--brand-soft:rgba(76, 145, 114, 0.16);
            --gold:#C9A85E;--gold-soft:rgba(201, 168, 94, 0.16);
            --accent:#6FA0C4;--accent-soft:rgba(111, 160, 196, 0.16);
            --primary:var(--accent);--primary-soft:var(--accent-soft);
            --header-bg:#13202a;--danger:#ef4444;--overlay-dim:rgba(0, 0, 0, 0.6);--glass-bg-strong:rgba(24, 33, 43, 0.92);
        }
        html { overflow-x: hidden; width: 100%; }
        body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;position:relative;overflow-x:hidden;width:100%}
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
        img { max-width: 100%; height: auto; }
        .card-body, .quiz-header, .quiz-result, .review-item, .info-box, .message-banner, .locked-banner {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
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
        .info-box { margin-top: 16px; padding: 16px; border-radius: 10px; border-left: 4px solid; border-top: 1px solid var(--line); border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .info-box.certification { background: var(--gold-soft); border-color: var(--gold); }
        .attempt-info { font-size: 14px; color: var(--text-secondary); margin-top: 12px; }
        .quiz-header { background: linear-gradient(135deg, var(--accent) 0%, var(--brand) 100%); color: white; padding: 1.5rem 2rem; }
        .quiz-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
        .quiz-result { padding: 2rem; background: var(--brand-soft); border: 1px solid var(--line); border-radius: 10px; margin: 24px; }
        .result-pass { color: var(--success); font-weight: 700; font-size: 1.1rem; }
        .result-fail { color: var(--danger); font-weight: 700; font-size: 1.1rem; }
        .locked-banner { margin-top: 1rem; padding: 0.9rem 1rem; border-radius: var(--radius-md); background: var(--brand-soft); border: 1px solid var(--line); color: var(--brand); font-weight: 600; }
        .message-banner { margin-top: 1rem; padding: 0.9rem 1rem; border-radius: var(--radius-md); background: var(--gold-soft); color: var(--gold); font-weight: 600; border: 1px solid var(--line); }
        .btn { background: var(--brand); color: white; border: none; padding: 14px 22px; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: background 0.2s, transform 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn:hover { background: var(--brand-dark); transform: translateY(-1px); }

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
            background: var(--gold-soft) !important;
            border-color: var(--gold) !important;
        }
        .certificate-earned-banner h3 { color: var(--gold) !important; }

        /* Pending results styles (same as monthly exam) */
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

        @media (max-width: 1200px) {
            .header-top-nav { max-width: 40vw; }
        }

        @media (max-width: 900px) {
            .header-top-nav { display: none; }
        }

        @media (max-width:768px){
            :root { --header-height: 60px; }
            .app-header { padding: 8px 12px; gap: 8px; }
            .header-right { gap: 6px; }
            .icon-btn, .lang-btn, .menu-toggle { height: 36px; min-width: 36px; padding: 0 8px; font-size: 15px; }
            .profile-btn { width: 36px; height: 36px; }
            .lang-btn { min-width: 0; padding: 0 6px; font-size: 12px; }
            .wrapper { padding: 10px; }
            .card-body, .quiz-header, .quiz-result { padding: 16px; }
            h1 { font-size: 22px; word-break: break-word; }
            .quiz-title { font-size: 1.2rem; word-break: break-word; }
            .meta { font-size: 13px; gap: 0.5rem 0.75rem; }
            .profile-menu { width: min(280px, calc(100vw - 24px)); right: -4px; }
        }

        @media (max-width: 400px) {
            .brand-box span { display: none; }
            .app-header { padding: 8px; }
        }
    </style>
</head>
<body>
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
                <a href="<?= quizE($navItem['link']) ?>" class="header-top-link <?= !empty($navItem['active']) ? 'active' : '' ?>">
                    <?= quizE($navItem['name']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <a href="notification.php" class="icon-btn" style="position:relative;">
            🔔 <?= $unreadCount > 0 ? "<span class='notification-badge'>".($unreadCount > 99 ? '99+' : $unreadCount)."</span>" : '' ?>
        </a>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" aria-expanded="false">
                <?= !empty($user['picture']) ? "<img src='".quizE($user['picture'])."' alt='Profile'>" : initials($user['name']) ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if(!empty($user['picture'])): ?><img src="<?= quizE($user['picture']) ?>" alt="Profile"><?php else: ?><div class="profile-avatar-fallback"><?= initials($user['name']) ?></div><?php endif; ?>
                    <div>
                        <h4><?= quizE($user['name']) ?></h4>
                        <p><?= quizE($user['email']) ?></p>
                        <p style="font-size:10px;">Member since <?= quizE($user['member_since']) ?></p>
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
            <?php foreach($sidebarNavigation as $item): ?>
                <a href="<?= quizE($item['link']) ?>" class="subject-link <?= $item['active'] ? 'active' : '' ?>">
                    <span><?= quizE($item['icon']) ?></span> <span><?= quizE($item['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

<!-- Main Content -->
<div class="wrapper" id="quizWrapper">
    <div class="card">
        <div class="card-body">
            <span class="mode-pill certification">Certification Exam</span>
            <h1><?= quizE($quiz['title'] ?? 'Untitled Exam') ?></h1>
            <div class="meta">
                <span>Level: <?= $exam['level'] ?></span>
                <span>Type: <?= quizE($exam['certification_type']) ?></span>
                <?php if($exam['subject_id']): ?><span>Subject: <?= quizE($exam['subject_name'] ?? '') ?></span><?php endif; ?>
                <span>Passing Score: <?= (int)$quiz['passing_score'] ?>%</span>
            </div>
            <p class="quiz-description"><?= nl2br(quizE($quiz['description'] ?? '')) ?></p>
            <div class="info-box certification">
                <strong>📜 Certification Exam – Level <?= $exam['level'] ?></strong><br>
                <span>Pass this exam to advance. Level 4 grants a certificate.</span>
            </div>
            <?php if($lockReason): ?>
                <div class="message-banner"><?= quizE($lockReason) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="quiz-section" id="quizSection">
        <div class="quiz-header">
            <div class="quiz-title">📝 <?= quizE($quiz['title'] ?? 'Exam Result') ?></div>
            <p>Review your performance and correct answers below.</p>
        </div>
        <div id="quizResult" class="quiz-result" style="display:block;"></div>
    </div>
</div>
</div>

<script>
window.quizTakeData = {
    quizId: <?= (int)$quizId ?>,
    attemptId: <?= (int)($attempt['id'] ?? 0) ?>,
    mode: 'certification',
    passingScore: <?= (int)$quiz['passing_score'] ?>,
    competitionActive: <?= $competitionActive ? 'true' : 'false' ?>,
    competitionEnded: <?= $competitionEnded ? 'true' : 'false' ?>,
    competitionEnd: <?= json_encode($endDateIso) ?>,
    existingAttempt: <?= json_encode($existingAttemptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    questions: <?= json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    baseUrl: <?= json_encode($baseUrl) ?>
};

// ===== HEADER & SIDEBAR =====
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

    function openSidebar() { sidebar.classList.add('open'); sidebarOverlay.classList.add('show'); body.style.overflow = 'hidden'; }
    function closeSidebar() { sidebar.classList.remove('open'); sidebarOverlay.classList.remove('show'); body.style.overflow = ''; }
    menuToggle?.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    sidebarOverlay?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeSidebar(); profileMenu?.classList.remove('show'); } });

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') { body.classList.add('dark-mode'); themeToggle.textContent = '☀'; }
    themeToggle?.addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        const isDark = body.classList.contains('dark-mode');
        themeToggle.textContent = isDark ? '☀' : '🌙';
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });

    let currentLang = localStorage.getItem('language') || 'EN';
    if (langToggle) { langToggle.textContent = currentLang; langToggle.addEventListener('click', () => { currentLang = currentLang === 'EN' ? 'SW' : 'EN'; langToggle.textContent = currentLang; localStorage.setItem('language', currentLang); }); }

    profileToggle?.addEventListener('click', function(e) { e.stopPropagation(); profileMenu.classList.toggle('show'); });
    document.addEventListener('click', (e) => { if (!profileArea.contains(e.target)) profileMenu.classList.remove('show'); });
})();

// ===== RESULT DISPLAY (cleaned up, no duplicates) =====
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
                <p>Results will be available after the exam window ends.</p>
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
                <a href="<?= quizE($backUrl) ?>" class="btn" style="margin-top: 1rem;">Back to Certification Exams</a>
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

    // If the exam window is still active, show pending results and exit
    if (window.quizTakeData.mode === 'certification' &&
        window.quizTakeData.competitionActive &&
        !window.quizTakeData.competitionEnded) {
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
                ${passed ? '🎉 Congratulations! You passed this exam.' : '❌ Exam completed. Review your result and revise below.'}
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

    // Certificate link if passed and certificate code exists
    <?php if ($attempt && $attempt['passed'] == 1 && (int)$exam['level'] === 4): ?>
    <?php
        $certCode = '';
        $stmtCert = $conn->prepare("SELECT certificate_code FROM certificates WHERE user_id = ? AND certification_type = ? AND (subject_id = ? OR (subject_id IS NULL AND ? IS NULL)) ORDER BY completed_at DESC LIMIT 1");
        $stmtCert->execute([$userId, $exam['certification_type'], $exam['subject_id'], $exam['subject_id']]);
        $cert = $stmtCert->fetch();
        if ($cert) $certCode = $cert['certificate_code'];
    ?>
    <?php if ($certCode): ?>
    const certDiv = document.createElement('div');
    certDiv.className = 'locked-banner certificate-earned-banner';
    certDiv.style.textAlign = 'center';
    certDiv.innerHTML = `
        <h3 style="margin-bottom:8px;">🎓 Certificate Earned!</h3>
        <a href="generate_certificate.php?code=<?= $certCode ?>" class="btn" style="margin-top:10px;">📜 Download Certificate</a>
    `;
    resultBox.insertAdjacentElement('afterend', certDiv);
    <?php endif; ?>
    <?php endif; ?>
}

// Render the existing attempt data immediately
if (window.quizTakeData.existingAttempt) {
    renderAttemptResult(window.quizTakeData.existingAttempt, window.quizTakeData.questions);
}
</script>
</body>
</html>