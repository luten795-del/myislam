<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/certificate_functions.php';
require_once __DIR__ . '/includes/translations.php';

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) $letters .= strtoupper(substr($part, 0, 1));
    return $letters !== '' ? $letters : 'U';
}
function certTrackLabel(array $cert): string {
    if (($cert['certification_type'] ?? '') === 'general') return 'General Certification - All Subjects';
    return !empty($cert['subject_name']) ? $cert['subject_name'] . ' Subject Certification' : 'Subject Certification';
}
function certIcon(array $cert): string { return (($cert['certification_type'] ?? '') === 'general') ? '🌍' : '📚'; }

if (!isLoggedIn()) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$conn = db();

$stmt = $conn->prepare("SELECT id, username, full_name, email, profile_image, role, created_at FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$userId]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dbUser) { session_destroy(); header('Location: login.php?error=account_disabled'); exit; }

$user = [
    'name' => $dbUser['full_name'] ?? $dbUser['username'] ?? 'User',
    'email' => $dbUser['email'] ?? '',
    'picture' => $dbUser['profile_image'] ?? '',
    'role' => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime((string)$dbUser['created_at'])) : 'recently',
];

$certificates = getUserCertificates($conn, $userId);
$activeCount = count(array_filter($certificates, fn($c) => ($c['status'] ?? '') === 'active'));
$generalCount = count(array_filter($certificates, fn($c) => ($c['certification_type'] ?? '') === 'general'));
$subjectCount = count(array_filter($certificates, fn($c) => ($c['certification_type'] ?? '') === 'subject'));

$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// ============================================================================
// NAVIGATION (matches user_dashboard.php / monthly_exams.php)
// ============================================================================

$mainNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => true, 'icon' => '🎓'],
    ['name' => t('calendar'), 'link' => 'calendar.php', 'active' => false, 'icon' => '📅'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="MyIslam My Certificates - View, print, and verify your earned certificates">
    <title>My Certificates - MyIslam</title>
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
.notification-wrap { position: relative; }
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
.profile-menu-top img, .avatar-fallback {
    width: 56px; height: 56px; border-radius: 50%; flex-shrink: 0;
}
.avatar-fallback {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--brand-soft); color: var(--brand); font-weight: 700; font-size: 16px;
}
.profile-menu-top h4 { font-size: 16px; margin-bottom: 4px; font-weight: bold; color: var(--text); }
.profile-menu-top p { font-size: 12px; color: var(--text-secondary); font-weight: 600; overflow-wrap: anywhere; }

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

/* ===== Certificates page (re-themed to the dashboard palette) ===== */
.hero {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(280px, .55fr);
    gap: 20px;
}

.hero-card {
    background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: clamp(20px, 4vw, 28px);
    overflow: hidden;
    position: relative;
    transition: all 0.2s;
}
.hero-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

.hero-card::after {
    content: "CERTIFIED";
    position: absolute;
    right: -42px;
    bottom: 14px;
    transform: rotate(-14deg);
    color: var(--gold-soft);
    font-size: 76px;
    font-weight: 900;
    letter-spacing: .08em;
    pointer-events: none;
}

.eyebrow {
    color: var(--gold);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .12em;
    font-size: 12px;
    margin-bottom: 8px;
    position: relative;
}

.hero h1 {
    font-size: clamp(26px, 5vw, 40px);
    line-height: 1.15;
    margin-bottom: 12px;
    color: var(--text);
    position: relative;
}

.hero p {
    color: var(--text-secondary);
    max-width: 620px;
    position: relative;
}

.hero-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 22px;
    position: relative;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: var(--radius-md);
    padding: 12px 20px;
    font-weight: 700;
    border: 1px solid var(--brand);
    background: var(--brand);
    color: var(--white);
    font-size: 14px;
    transition: all 0.2s;
    cursor: pointer;
}
.btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); background: var(--brand-dark); }

.btn.secondary {
    background: transparent;
    color: var(--brand);
    border: 1px solid var(--brand);
}
.btn.secondary:hover { background: var(--brand-soft); }

.btn.gold {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--white);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.stat-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: 18px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    transition: all 0.2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

.stat-card span {
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.stat-card strong {
    display: block;
    font-size: 28px;
    margin-top: 4px;
    color: var(--brand);
}

.section-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 14px;
}

.section-head h2 { font-size: 22px; color: var(--text); }
.section-head p { color: var(--muted); font-size: 13px; }

.cert-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 18px;
}

.cert-card {
    position: relative;
    overflow: hidden;
    padding: 20px;
    min-height: 245px;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    transition: all 0.2s;
}
.cert-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

.cert-card::before {
    content: "";
    position: absolute;
    inset: 0 0 auto 0;
    height: 6px;
    background: linear-gradient(90deg, var(--brand), var(--gold));
}

.cert-top {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    margin-bottom: 14px;
}

.cert-icon {
    width: 54px; height: 54px; flex-shrink: 0;
    border-radius: var(--radius-sm);
    background: var(--gold-soft);
    border: 1px solid var(--gold);
    display: flex; align-items: center; justify-content: center;
    font-size: 26px;
}

.cert-card h3 { font-size: 18px; margin-bottom: 4px; color: var(--text); }
.cert-track { color: var(--muted); font-size: 13px; font-weight: 700; }

.cert-summary {
    color: var(--text-secondary);
    font-size: 14px;
    margin: 10px 0 14px;
    flex: 1;
}

.cert-meta { display: grid; gap: 8px; margin: 12px 0 16px; }

.meta-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 13px;
    color: var(--muted);
    border-bottom: 1px dashed var(--line);
    padding-bottom: 7px;
}
.meta-row strong { color: var(--text); text-align: right; overflow-wrap: anywhere; }

.status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    align-self: flex-start;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    margin-bottom: 8px;
}
.status-active { background: var(--brand-soft); color: var(--brand); }
.status-revoked { background: var(--danger-soft); color: var(--danger); }

.cert-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.cert-actions .btn { flex: 1; min-width: 120px; padding: 10px 14px; font-size: 13px; }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--surface);
    border-radius: var(--radius-lg);
    border: 1px dashed var(--line);
}
.empty-state .emoji { font-size: 64px; margin-bottom: 16px; }
.empty-state h2 { font-size: 20px; margin-bottom: 8px; color: var(--text); }
.empty-state p { color: var(--muted); margin-bottom: 18px; }

/* Responsive */
@media (max-width: 1200px) {
    .header-top-nav { max-width: 48vw; }
}
@media (max-width: 900px) {
    .header-top-nav { display: none; }
}
@media (max-width: 860px) {
    .hero { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    :root { --header-height: 64px; }
    .main-content { padding: 16px 12px 20px; }
    .section-card { padding: 16px; }
    .dashboard-stack { gap: 20px; }
}
@media (max-width: 640px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .section-head { align-items: flex-start; flex-direction: column; }
    .cert-grid { grid-template-columns: 1fr; }
    .cert-actions { flex-direction: column; }
    .cert-actions .btn { width: 100%; }
}

@media print {
    .app-header, .sidebar, .sidebar-overlay, .sidebar-toggle-btn, .hero-actions, .cert-actions { display: none !important; }
    body { background: #fff; }
    .main-content { max-width: none; padding: 0; }
    .hero, .section-head { display: none; }
    .cert-card { break-inside: avoid; box-shadow: none; }
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
                <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>">
                    <?php echo e($navItem['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <a href="notification.php" class="icon-btn notification-wrap" aria-label="Notifications">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : (int)$unreadCount ?></span>
            <?php endif; ?>
        </a>

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if (!empty($user['picture'])): ?>
                    <img src="<?= e($user['picture']) ?>" alt="Profile">
                <?php else: ?>
                    <?= e(initials($user['name'])) ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?= e($user['picture']) ?>" alt="Profile">
                    <?php else: ?>
                        <div class="avatar-fallback"><?= e(initials($user['name'])) ?></div>
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

<button class="sidebar-toggle-btn" id="menuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">☰</button>

<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">📚 Navigation</div>
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
            <section class="hero">
                <div class="hero-card">
                    <div class="eyebrow">Verified Achievements</div>
                    <h1>My Certificates</h1>
                    <p>View, print, and verify the certificates you have earned after completing certification levels. Each certificate has a unique verification code.</p>
                    <div class="hero-actions">
                        <a href="certification_exams.php" class="btn">Continue Certifications</a>
                        <button class="btn secondary" onclick="window.print()">Print List</button>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <span>Total</span>
                        <strong><?= count($certificates) ?></strong>
                    </div>
                    <div class="stat-card">
                        <span>Subject</span>
                        <strong><?= $subjectCount ?></strong>
                    </div>
                    <div class="stat-card">
                        <span>General</span>
                        <strong><?= $generalCount ?></strong>
                    </div>
                    <div class="stat-card">
                        <span>Active</span>
                        <strong><?= $activeCount ?></strong>
                    </div>
                </div>
            </section>

            <div class="section-head">
                <div>
                    <h2>Earned Certificates</h2>
                    <p><?= $activeCount ?> active certificate<?= $activeCount === 1 ? '' : 's' ?> found.</p>
                </div>
            </div>

            <?php if (empty($certificates)): ?>
                <section class="empty-state">
                    <div class="emoji">📜</div>
                    <h2>No certificates yet</h2>
                    <p>Complete and pass the final certification level to earn a certificate.</p>
                    <a href="certification_exams.php" class="btn">Go to Certification Exams</a>
                </section>
            <?php else: ?>
                <section class="cert-grid">
                    <?php foreach ($certificates as $cert):
                        $isActive = ($cert['status'] ?? '') === 'active';
                        $code = (string)($cert['certificate_code'] ?? '');
                        $issued = !empty($cert['issued_at']) ? date('M d, Y', strtotime((string)$cert['issued_at'])) : 'N/A';
                        $completed = !empty($cert['completed_at']) ? date('M d, Y', strtotime((string)$cert['completed_at'])) : 'N/A';
                        $track = certTrackLabel($cert);
                    ?>
                        <article class="cert-card">
                            <div class="cert-top">
                                <div class="cert-icon"><?= e(certIcon($cert)) ?></div>
                                <div>
                                    <h3><?= e($cert['title'] ?? 'Certificate of Achievement') ?></h3>
                                    <div class="cert-track"><?= e($track) ?></div>
                                </div>
                            </div>
                            <span class="status-pill <?= $isActive ? 'status-active' : 'status-revoked' ?>">
                                <?= $isActive ? '✅ Active' : '⛔ Revoked' ?>
                            </span>
                            <p class="cert-summary"><?= e($cert['achievement_summary'] ?? 'Successfully completed the required certification pathway.') ?></p>
                            <div class="cert-meta">
                                <div class="meta-row">
                                    <span>Certificate Code</span>
                                    <strong><?= e($code) ?></strong>
                                </div>
                                <div class="meta-row">
                                    <span>Completed</span>
                                    <strong><?= e($completed) ?></strong>
                                </div>
                                <div class="meta-row">
                                    <span>Issued</span>
                                    <strong><?= e($issued) ?></strong>
                                </div>
                            </div>
                            <div class="cert-actions">
                                <a class="btn" href="certificate_view.php?code=<?= urlencode($code) ?>">View</a>
                                <a class="btn secondary" href="verify_certificate.php?code=<?= urlencode($code) ?>" target="_blank">Verify</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <div class="footer-meta" style="text-align:center;font-size:12px;padding:20px;margin-top:4px;font-weight:700;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);">
                <div style="color:var(--brand);font-weight:700;">MyIslam</div>
                <div style="color:var(--gold);font-weight:700;">#Manhaj Salaf</div>
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

    console.log('My Certificates initialized');
})();
</script>

</body>
</html>