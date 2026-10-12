<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

function e($v): string {
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

// Fetch user details for header
$dbUser = null;
try {
    $stmt = $conn->prepare("
        SELECT id, username, full_name, email, profile_image, role, is_active, is_verified, created_at
        FROM users
        WHERE id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $dbUser = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Error getting user: " . $e->getMessage());
}
if (!$dbUser) {
    session_destroy();
    header('Location: login.php?error=account_disabled');
    exit;
}
$user = [
    'name' => $dbUser['full_name'] ?? $dbUser['username'] ?? 'User',
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

// ============================================================================
// NAVIGATION (matches user_dashboard.php)
// ============================================================================

$mainNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => true],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => true, 'icon' => '📅'],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('calendar'), 'link' => 'calendar.php', 'active' => false, 'icon' => '📅'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];

// ============================================================================
// FETCH ALL PUBLISHED MONTHLY EXAMS WITH USER ATTEMPT DATA (unchanged logic)
// ============================================================================

$exams = $conn->query("
    SELECT 
        me.*, 
        s.name AS subject_name, 
        s.icon AS subject_icon,
        (SELECT COUNT(*) FROM monthly_exam_attempts WHERE exam_id = me.id AND user_id = $userId) AS attempts_taken,
        (SELECT MAX(percentage) FROM monthly_exam_attempts WHERE exam_id = me.id AND user_id = $userId AND status = 'completed') AS best_score,
        (SELECT id FROM monthly_exam_attempts WHERE exam_id = me.id AND user_id = $userId ORDER BY id DESC LIMIT 1) AS latest_attempt_id,
        (SELECT COUNT(*) FROM monthly_exam_questions WHERE exam_id = me.id) AS total_questions
    FROM monthly_exams me
    LEFT JOIN subjects s ON s.id = me.subject_id
    WHERE me.status = 'published'
    ORDER BY me.start_date DESC
")->fetchAll();

// Process each exam to add computed status
$now = new DateTime();
foreach ($exams as &$exam) {
    $start = new DateTime($exam['start_date']);
    $end   = new DateTime($exam['end_date']);
    if ($now < $start) {
        $exam['status'] = 'upcoming';
    } elseif ($now > $end) {
        $exam['status'] = 'ended';
    } else {
        $exam['status'] = 'live';
    }
    // Convert to timestamp for JS countdown (milliseconds)
    $exam['start_timestamp'] = $start->getTimestamp() * 1000;
    $exam['end_timestamp']   = $end->getTimestamp() * 1000;

    // Admin access lock - overrides everything else regardless of the
    // exam's live/upcoming/ended window. Defaults to allowed if the
    // column isn't present yet (migration not run).
    $exam['admin_locked'] = isset($exam['allow_access']) && (int)$exam['allow_access'] === 0;
}
unset($exam);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="MyIslam Monthly Examinations - Test your knowledge with end-of-month exams">
    <title>Monthly Examinations - MyIslam</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

/* ===== Light Mode Variables (matches user_dashboard.php) ===== */
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

    --white: #ffffff;
    --warning: #b45309;
    --danger: #b91c1c;
    --danger-soft: rgba(185, 28, 28, 0.10);
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

/* ===== Base Body (Light Mode) ===== */
body {
    font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.5;
    padding-bottom: 24px;
    position: relative;
}

/* ===== Dark Mode ===== */
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

    --header-bg: #13202a;
    --danger: #ef4444;
    --danger-soft: rgba(239, 68, 68, 0.14);
    --overlay-dim: rgba(0, 0, 0, 0.6);
    --glass-bg-strong: rgba(24, 33, 43, 0.92);

    background: var(--bg);
}

/* ===== Background Pattern Overlay (subtle, brand-tinted) ===== */
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

/* ===== Header (matches user_dashboard.php) ===== */
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

.icon-btn, .lang-btn, .brand-box {
    height: 40px; min-width: 30px; border-radius: 10px;
    background: var(--surface); border: 1px solid var(--line);
    display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
}

.icon-btn:hover, .lang-btn:hover {
    transform: translateY(-2px); box-shadow: var(--shadow-sm);
    border-color: var(--gold); color: var(--gold);
}
.profile-btn:hover {
    transform: translateY(-2px); box-shadow: var(--shadow-sm);
}

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
    position: absolute; top: -4px; right: -4px;
    background: var(--danger); color: var(--white);
    font-size: 10px; font-weight: 700; min-width: 18px; height: 18px;
    border-radius: 999px; display: flex; align-items: center; justify-content: center;
    padding: 0 4px; border: 2px solid var(--surface);
}

/* ===== Sidebar (matches user_dashboard.php) ===== */
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
    padding: 24px 20px; z-index: 1200; transition: left 0.3s ease; overflow-y: scroll;
    overscroll-behavior: contain; -webkit-overflow-scrolling: touch; scrollbar-gutter: stable;
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
.subject-link.active {
    background: var(--brand); color: var(--white); border-color: var(--brand-dark);
    box-shadow: inset 4px 0 0 var(--gold), 0 4px 12px rgba(14, 74, 52, 0.18);
    font-weight: 800;
}

.sidebar-toggle-btn {
    position: fixed; top: var(--header-height); left: 0; z-index: 1300;
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    background: var(--surface); border: 1px solid var(--line);
    border-left: none; border-top: none; border-radius: 0 0 8px 0;
    font-size: 16px; cursor: pointer; color: var(--text); transition: all 0.2s;
    box-shadow: var(--shadow-sm); padding: 0; margin: 0;
}
.sidebar-toggle-btn:hover { background: var(--brand-soft); color: var(--brand); }

/* ===== Main content (matches user_dashboard.php) ===== */
.main-content {
    padding: 24px 24px 20px;
    max-width: 1200px; margin: 0 auto;
    width: 100%;
    overflow-x: hidden;
}

.dashboard-stack { display: grid; gap: 20px; }

.section-card {
    background: var(--surface); border: 1px solid var(--line);
    border-radius: var(--radius-lg); padding: clamp(16px, 5vw, 24px);
    transition: all 0.2s;
    width: 100%;
    overflow: hidden;
}
.section-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

.page-header-card { text-align: center; }
.page-title {
    font-size: clamp(24px, 5vw, 32px); font-weight: 800; color: var(--brand); margin-bottom: 8px;
}
.page-subtitle { color: var(--muted); font-size: 16px; }

/* ===== Monthly exam cards (re-themed to the dashboard palette) ===== */
.exam-grid { display: grid; gap: 16px; }

.exam-card {
    background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg);
    padding: clamp(16px, 4vw, 24px); box-shadow: var(--shadow-sm); transition: all 0.2s;
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 16px;
}
.exam-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); border-color: var(--brand-light); }
.exam-card.attempted { background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%); }

.exam-info { flex: 1; min-width: 240px; }
.exam-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
.exam-title { font-size: 20px; font-weight: 800; color: var(--text); }

.exam-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 999px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
}
.badge-upcoming { background: var(--gold-soft); color: var(--gold); }
.badge-live { background: var(--brand-soft); color: var(--brand); }
.badge-ended { background: var(--danger-soft); color: var(--danger); }
.badge-completed { background: var(--brand-soft); color: var(--brand); }
.badge-attempted { background: var(--gold-soft); color: var(--gold); }
.badge-new { background: var(--accent-soft); color: var(--accent); }

.exam-description { color: var(--text-secondary); margin-bottom: 16px; font-size: 14px; }

.exam-meta { display: flex; flex-wrap: wrap; gap: 14px 26px; margin-bottom: 16px; }
.meta-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); }
.meta-item strong { color: var(--text); }

.progress-section { margin-top: 8px; }
.progress-label { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); margin-bottom: 6px; }
.progress-bar-bg { height: 8px; background: var(--surface-3); border-radius: 999px; overflow: hidden; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg, var(--brand), var(--brand-light)); border-radius: 999px; transition: width 0.3s; }

.exam-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 12px; }

.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 22px;
    border-radius: var(--radius-md); font-weight: 700; font-size: 14px; text-decoration: none;
    transition: all 0.2s; cursor: pointer; border: none; white-space: nowrap;
}
.btn-primary { background: var(--brand); color: var(--white); box-shadow: 0 4px 8px rgba(14,74,52,0.2); }
.btn-primary:hover { background: var(--brand-dark); transform: translateY(-2px); box-shadow: 0 6px 12px rgba(14,74,52,0.25); }
.btn-outline { background: transparent; color: var(--brand); border: 1px solid var(--brand); }
.btn-outline:hover { background: var(--brand-soft); }
.btn-disabled { background: var(--surface-2); color: var(--muted); opacity: 0.8; cursor: not-allowed; border: 1px solid var(--line); }

.score-badge { text-align: right; }
.best-score { font-size: 24px; font-weight: 800; color: var(--brand); }
.score-label { font-size: 11px; color: var(--muted); }
.action-group { display: flex; gap: 8px; flex-wrap: wrap; }

.countdown { font-size: 13px; color: var(--gold); font-weight: 700; margin-top: 8px; }

.empty-state {
    text-align: center; padding: 60px 20px; background: var(--surface);
    border-radius: var(--radius-lg); border: 1px dashed var(--line);
}
.empty-state .emoji { font-size: 64px; margin-bottom: 16px; }
.empty-state h3 { font-size: 20px; margin-bottom: 8px; color: var(--text); }
.empty-state p { color: var(--muted); }

.footer-meta {
    text-align: center; font-size: 12px; padding: 20px; margin-top: 4px;
    font-weight: 700;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
}
.footer-meta .brand-text { color: var(--brand); font-weight: 700; }
.footer-meta .gold-text { color: var(--gold); font-weight: 700; }

@media (max-width: 1200px) {
    .header-top-nav { max-width: 48vw; }
}
@media (max-width: 900px) {
    .header-top-nav { display: none; }
}
@media (max-width: 768px) {
    :root { --header-height: 64px; }
    .main-content { padding: 16px 12px 20px; }
    .section-card { padding: 16px; }
    .dashboard-stack { gap: 20px; }
    .exam-card { flex-direction: column; }
    .exam-actions { width: 100%; align-items: stretch; }
    .btn { width: 100%; justify-content: center; }
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
                <?php if (!empty($user['picture'])): ?>
                    <img src="<?= e($user['picture']) ?>" alt="Profile">
                <?php else: ?>
                    <?= initials($user['name']) ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?= e($user['picture']) ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?= initials($user['name']) ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?= e($user['name']) ?></h4>
                        <p><?= e($user['email']) ?></p>
                        <p style="font-size: 10px;"><?php echo t('dashboard_member_since'); ?> <?= e($user['member_since']) ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 <?php echo t('profile'); ?></a>
                <a href="settings.php">⚙️ <?php echo t('settings'); ?></a>
                <a href="logout.php">🚪 <?php echo t('logout'); ?></a>
            </div>
        </div>
    </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" aria-label="<?php echo e(t('open_navigation')); ?>" title="<?php echo e(t('open_navigation')); ?>" aria-expanded="false" aria-controls="sidebar">☰</button>

<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">📚 <?php echo e(t('navigation')); ?></div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?= e($item['link']) ?>" class="subject-link <?= $item['active'] ? 'active' : '' ?>">
                    <span><?= e($item['icon']) ?></span>
                    <span><?= e($item['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">
        <div class="dashboard-stack">
            <section class="section-card page-header-card">
                <h1 class="page-title">📅 <?php echo e(t('monthly_examinations')); ?></h1>
                <p class="page-subtitle"><?php echo e(t('monthly_exam_intro')); ?></p>
            </section>

            <?php if (empty($exams)): ?>
                <div class="empty-state">
                    <div class="emoji">📭</div>
                    <h3><?php echo e(t('no_exams_available')); ?></h3>
                    <p><?php echo e(t('no_monthly_exams')); ?><br><?php echo e(t('check_later_materials')); ?></p>
                </div>
            <?php else: ?>
                <div class="exam-grid">
                    <?php foreach ($exams as $exam):
                        $attemptsTaken = (int)$exam['attempts_taken'];
                        $attempted = $attemptsTaken > 0;
                        $maxAttempts = (int)$exam['max_attempts'];
                        $attemptsLeft = max(0, $maxAttempts - $attemptsTaken);
                        $bestScore = $exam['best_score'] ? round((float)$exam['best_score']) : null;
                        $isPassed = $bestScore !== null && $bestScore >= (int)$exam['passing_score'];
                        $totalQuestions = (int)$exam['total_questions'];
                        $latestAttemptId = $exam['latest_attempt_id'] ?? null;
                        $status = $exam['status'];
                        $isLive = ($status === 'live');
                        $isUpcoming = ($status === 'upcoming');
                        $isEnded = ($status === 'ended');
                        $isAdminLocked = $exam['admin_locked'] ?? false;

                        // Determine available actions
                        $canAttempt = $isLive && $attemptsLeft > 0 && !$isAdminLocked;
                        $showRetake = $canAttempt && !$isPassed;
                        $showStart = $canAttempt && !$attempted;
                        // A user's own completed result stays visible even if the
                        // admin later locks the exam - only NEW attempts are blocked.
                        $showViewResult = $attempted && $latestAttemptId;
                        // Browsing correct answers for an exam you never attempted
                        // counts as "accessing" it, so the admin lock applies here too.
                        $showViewAnswers = $isEnded && !$attempted && !$isAdminLocked;
                    ?>
                        <div class="exam-card <?php echo $attempted ? 'attempted' : ''; ?>">
                            <div class="exam-info">
                                <div class="exam-header">
                                    <h2 class="exam-title"><?php echo e($exam['title']); ?></h2>
                                    <?php if ($isAdminLocked): ?>
                                        <span class="exam-badge badge-ended" title="<?php echo e(t('unavailable')); ?>">🚫 <?php echo e(t('unavailable')); ?></span>
                                    <?php elseif ($isUpcoming): ?>
                                        <span class="exam-badge badge-upcoming">⏳ <?php echo e(t('upcoming')); ?></span>
                                    <?php elseif ($isLive): ?>
                                        <span class="exam-badge badge-live">🔴 <?php echo e(t('live')); ?></span>
                                    <?php else: ?>
                                        <span class="exam-badge badge-ended">🏁 <?php echo e(t('ended')); ?></span>
                                    <?php endif; ?>

                                    <?php if ($isPassed): ?>
                                        <span class="exam-badge badge-completed">✅ <?php echo e(t('status4')); ?></span>
                                    <?php elseif ($attempted): ?>
                                        <span class="exam-badge badge-attempted">🔄 <?php echo e(t('attempted')); ?></span>
                                    <?php elseif ($isLive): ?>
                                        <span class="exam-badge badge-new">📝 <?php echo e(t('new')); ?></span>
                                    <?php endif; ?>

                                    <?php if (!empty($exam['subject_icon'])): ?>
                                        <span class="exam-badge badge-new"><?php echo e($exam['subject_icon']); ?> <?php echo e($exam['subject_name'] ?? 'General'); ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($exam['description'])): ?>
                                    <p class="exam-description"><?php echo e($exam['description']); ?></p>
                                <?php endif; ?>

                                <div class="exam-meta">
                                    <div class="meta-item">
                                        <span>📆</span>
                                        <span><strong><?php echo e($exam['month_year']); ?></strong></span>
                                    </div>
                                    <div class="meta-item">
                                        <span>⏱️</span>
                                        <span><strong><?php echo $exam['time_limit_minutes'] ? $exam['time_limit_minutes'] . ' mins' : 'No time limit'; ?></strong></span>
                                    </div>
                                    <div class="meta-item">
                                        <span>🎯</span>
                                        <span><strong>Pass: <?php echo $exam['passing_score']; ?>%</strong></span>
                                    </div>
                                    <div class="meta-item">
                                        <span>❓</span>
                                        <span><strong><?php echo $totalQuestions; ?> questions</strong></span>
                                    </div>
                                    <div class="meta-item">
                                        <span>📅</span>
                                        <span><strong><?php echo date('M d, Y H:i', strtotime($exam['start_date'])); ?> – <?php echo date('M d, Y H:i', strtotime($exam['end_date'])); ?></strong></span>
                                    </div>
                                </div>

                                <?php if ($isUpcoming): ?>
                                    <div class="countdown" id="countdown-<?php echo $exam['id']; ?>" data-start="<?php echo $exam['start_timestamp']; ?>">
                                        Starts in: calculating...
                                    </div>
                                <?php endif; ?>

                                <div class="progress-section">
                                    <div class="progress-label">
                                        <span>Attempts: <?php echo $attemptsTaken; ?> / <?php echo $maxAttempts; ?></span>
                                        <span><?php echo $attemptsLeft; ?> attempt<?php echo $attemptsLeft !== 1 ? 's' : ''; ?> remaining</span>
                                    </div>
                                    <div class="progress-bar-bg">
                                        <div class="progress-bar-fill" style="width: <?php echo min(100, ($attemptsTaken / max(1, $maxAttempts)) * 100); ?>%;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="exam-actions">
                                <?php if ($bestScore !== null): ?>
                                    <div class="score-badge">
                                        <div class="best-score"><?php echo $bestScore; ?>%</div>
                                        <div class="score-label">Best Score</div>
                                    </div>
                                <?php endif; ?>

                                <div class="action-group">
                                    <?php if ($isUpcoming): ?>
                                        <span class="btn btn-disabled">⏳ Not Yet Started</span>
                                    <?php elseif ($isEnded): ?>
                                        <?php if ($showViewResult): ?>
                                            <a href="monthly_exam_result.php?attempt_id=<?php echo $latestAttemptId; ?>" class="btn btn-outline">
                                                📋 View Result
                                            </a>
                                        <?php elseif ($showViewAnswers): ?>
                                            <a href="monthly_exam_result.php?exam_id=<?php echo $exam['id']; ?>&ended=1" class="btn btn-outline">
                                                🔍 View Answers
                                            </a>
                                        <?php elseif ($isAdminLocked): ?>
                                            <span class="btn btn-disabled">🚫 Unavailable</span>
                                        <?php else: ?>
                                            <span class="btn btn-disabled">🏁 Exam Ended</span>
                                        <?php endif; ?>
                                    <?php else: // Live ?>
                                        <?php if ($showRetake): ?>
                                            <a href="take_monthly_exam.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-primary">
                                                🔄 Retake Exam
                                            </a>
                                        <?php elseif ($showStart): ?>
                                            <a href="take_monthly_exam.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-primary">
                                                ▶️ Start Exam
                                            </a>
                                        <?php elseif ($isAdminLocked && !$attempted): ?>
                                            <span class="btn btn-disabled">🚫 Unavailable</span>
                                        <?php elseif (!$canAttempt && !$attempted): ?>
                                            <span class="btn btn-disabled">🚫 Not Available</span>
                                        <?php endif; ?>

                                        <?php if ($showViewResult): ?>
                                            <a href="monthly_exam_result.php?attempt_id=<?php echo $latestAttemptId; ?>" class="btn btn-outline">
                                                📋 View Result
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($attempted && !$canAttempt && !$showRetake && !$showViewResult): ?>
                                            <span class="btn btn-disabled">🚫 Max Attempts</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

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

    // Countdown timers for upcoming exams
    document.querySelectorAll('[id^="countdown-"]').forEach(el => {
        const startTimestamp = parseInt(el.dataset.start);
        if (isNaN(startTimestamp)) return;

        function updateCountdown() {
            const now = Date.now();
            const diff = startTimestamp - now;
            if (diff <= 0) {
                el.textContent = 'Starting soon!';
                return;
            }
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % 86400000) / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);
            let str = '';
            if (days > 0) str += days + 'd ';
            str += String(hours).padStart(2, '0') + 'h ';
            str += String(minutes).padStart(2, '0') + 'm ';
            str += String(seconds).padStart(2, '0') + 's';
            el.textContent = 'Starts in: ' + str;
        }
        updateCountdown();
        setInterval(updateCountdown, 1000);
    });

    console.log('Monthly Exams initialized');
})();
</script>
</body>
</html>