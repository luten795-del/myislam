<?php
declare(strict_types=1);

/**
 * Page shell for the student Courses pages. It mirrors the header / sidebar /
 * theme / language behaviour of user_dashboard.php so the pages feel identical.
 *
 * IMPORTANT: require this file from the TOP LEVEL of a page (not from inside
 * a function) so translations.php and config/database.php load in global scope.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
date_default_timezone_set('Africa/Dar_es_Salaam');

if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}

require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/course_helpers.php';

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('initials')) {
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
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function courseCsrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function courseCsrfValid(): bool
{
    return hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
}

function courseFlash(string $type, string $message): void
{
    $_SESSION['course_flash'] = [$type, $message];
}

function courseTakeFlash(): ?array
{
    $flash = $_SESSION['course_flash'] ?? null;
    unset($_SESSION['course_flash']);
    return $flash;
}

function courseRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Auth + DB + current user. Redirects to login when needed. */
function courseShellBoot(): array
{
    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
        header('Location: login.php?error=login_required&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
    $userId = (int)$_SESSION['user_id'];

    $conn = db();
    if (!$conn) {
        die('System error. Unable to connect to database. Please try again later.');
    }

    try {
        $stmt = $conn->prepare("SELECT id, username, email, profile_image, role, created_at FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $dbUser = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Course shell user error: ' . $e->getMessage());
        $dbUser = false;
    }
    if (!$dbUser) {
        session_destroy();
        header('Location: login.php?error=account_disabled');
        exit;
    }

    $unread = 0;
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        $unread = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // notifications table may not exist — ignore
    }

    return [
        'conn' => $conn,
        'userId' => $userId,
        'unread' => $unread,
        'user' => [
            'name' => !empty($dbUser['username']) ? (string)$dbUser['username'] : 'User',
            'email' => (string)($dbUser['email'] ?? ''),
            'picture' => !empty($dbUser['profile_image']) ? (string)$dbUser['profile_image'] : '',
            'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime((string)$dbUser['created_at'])) : 'recently',
        ],
    ];
}

/** Prints everything from <!DOCTYPE> up to the opening of the page content. */
function courseShellStart(array $ctx, string $title, string $active = 'courses'): void
{
    $user = $ctx['user'];
    $unread = (int)$ctx['unread'];

    $mainNav = [
        [t('dashboard'), 'user_dashboard.php', false],
        [t('subjects'), 'subjects.php', false],
        [t('quizzes'), 'quizzes.php', false],
        [t('monthly_exams'), 'monthly_exams.php', false],
        ['Courses', 'courses.php', $active === 'courses'],
        ['Schedule', 'schedule.php', $active === 'schedule'],
        [t('leaderboard'), 'leaderboard.php', false],
        [t('multimedia'), 'multimedia.php', false],
        [t('notifications'), 'notification.php', false],
        [t('help_center'), 'help_center.php', false],
    ];
    $sideNav = [
        [t('dashboard'), 'user_dashboard.php', '📊', false],
        [t('subjects'), 'subjects.php', '📚', false],
        [t('quizzes'), 'quizzes.php', '📝', false],
        [t('monthly_exams'), 'monthly_exams.php', '📅', false],
        ['Courses', 'courses.php', '🎓', $active === 'courses'],
        ['Schedule', 'schedule.php', '🗓️', $active === 'schedule'],
        [t('leaderboard'), 'leaderboard.php', '🏆', false],
        [t('certification'), 'certification_exams.php', '🎓', false],
        [t('my_certificates'), 'my_certificates.php', '🎓', false],
        [t('calendar'), 'calendar.php', '📅', false],
        [t('multimedia'), 'multimedia.php', '🖼️', false],
        [t('notifications'), 'notification.php', '🔔', false],
        [t('help_center'), 'help_center.php', '❓', false],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo e($title); ?> - MyIslam</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #f5f7f5; --surface: #ffffff; --surface-2: #f9fafb; --surface-3: #eef2f7;
            --line: #e2e5e2; --text: #1f2937; --text-secondary: #4b5563; --muted: #6b7280;
            --brand: #0E4A34; --brand-dark: #0A3626; --brand-light: #3E7A5E; --brand-soft: rgba(14, 74, 52, 0.08);
            --gold: #9C7A2E; --gold-soft: rgba(156, 122, 46, 0.10);
            --accent: #3E6E91; --accent-soft: rgba(62, 110, 145, 0.10);
            --danger: #b91c1c; --danger-soft: rgba(185, 28, 28, 0.08);
            --white: #ffffff; --header-bg: #ffffff; --header-height: 72px;
            --overlay-dim: rgba(15, 23, 32, 0.45); --glass-bg-strong: rgba(255, 255, 255, 0.92);
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius-sm: 12px; --radius-md: 16px; --radius-lg: 20px;
        }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg); color: var(--text); min-height: 100vh; line-height: 1.5; padding-bottom: 24px;
        }
        body.dark-mode {
            --bg: #0f1720; --surface: #18212b; --surface-2: #1d2833; --surface-3: #22303c;
            --line: #304150; --text: #edf2f7; --text-secondary: #cbd5e1; --muted: #94a3b8;
            --brand: #4C9172; --brand-dark: #6bb590; --brand-light: #7fcaa4; --brand-soft: rgba(76, 145, 114, 0.16);
            --gold: #C9A85E; --gold-soft: rgba(201, 168, 94, 0.16);
            --accent: #6FA0C4; --accent-soft: rgba(111, 160, 196, 0.16);
            --danger: #ef4444; --danger-soft: rgba(239, 68, 68, 0.12);
            --header-bg: #13202a; --overlay-dim: rgba(0, 0, 0, 0.6); --glass-bg-strong: rgba(24, 33, 43, 0.92);
        }
        a { text-decoration: none; color: inherit; }
        button, input, select { font: inherit; color: inherit; }
        button { border: none; background: none; cursor: pointer; }
        a:focus-visible, button:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; border-radius: 6px; }

        .skip-link { position: absolute; left: -9999px; top: 0; background: var(--brand); color: var(--white); padding: 10px 16px; border-radius: 0 0 8px 0; z-index: 2000; font-weight: 700; }
        .skip-link:focus { left: 0; }

        /* ---- Header ---- */
        .app-header {
            position: sticky; top: 0; z-index: 1000; height: var(--header-height);
            background: var(--header-bg); border-bottom: 1px solid var(--line); box-shadow: var(--shadow-sm);
            display: flex; align-items: center; justify-content: space-between; padding: 12px 24px; gap: 18px;
        }
        .header-left, .header-right { display: flex; align-items: center; gap: 12px; }
        .header-left { flex: 1; min-width: 0; }
        .brand-box { display: flex; align-items: center; gap: 4px; white-space: nowrap; color: var(--brand); }
        .brand-box img { height: 50px; width: 50px; border-radius: 50%; border: 2px solid var(--brand); object-fit: cover; padding: 2px; }
        .brand-box span { font-size: 22px; font-weight: 900; }
        .header-top-nav { display: flex; align-items: center; gap: 8px; overflow-x: auto; scrollbar-width: thin; padding-bottom: 2px; }
        .header-top-link {
            height: 42px; padding: 0 14px; border-radius: 999px; background: var(--surface); border: 1px solid var(--line);
            display: inline-flex; align-items: center; white-space: nowrap; font-size: 13px; font-weight: 700;
            transition: all .2s; color: var(--text-secondary);
        }
        .header-top-link:hover, .header-top-link.active { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); }
        .icon-btn, .lang-btn {
            height: 40px; min-width: 30px; border-radius: 10px; background: var(--surface); border: 1px solid var(--line);
            display: inline-flex; align-items: center; justify-content: center; transition: all .2s; position: relative;
        }
        .icon-btn { font-size: 18px; width: 40px; }
        .lang-btn { padding: 0 8px; font-weight: 600; color: var(--brand); }
        .icon-btn:hover, .lang-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); border-color: var(--gold); color: var(--gold); }
        .notification-badge {
            position: absolute; top: -4px; right: -4px; background: var(--danger); color: var(--white);
            font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; border-radius: 999px;
            display: flex; align-items: center; justify-content: center; padding: 0 4px; border: 2px solid var(--surface);
        }
        .profile-area { position: relative; }
        .profile-btn {
            width: 44px; height: 44px; border-radius: 50%; overflow: hidden; background: var(--surface);
            border: 2px solid var(--brand); display: flex; align-items: center; justify-content: center; color: var(--brand); font-weight: 700;
        }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        .profile-menu {
            position: absolute; top: 56px; right: 0; width: 280px; background: var(--glass-bg-strong); backdrop-filter: blur(12px);
            border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 16px; display: none; box-shadow: var(--shadow-lg); z-index: 1001;
        }
        .profile-menu.show { display: block; }
        .profile-menu-top { display: flex; gap: 12px; padding-bottom: 10px; margin-bottom: 10px; border-bottom: 1px solid var(--line); }
        .profile-menu-top img, .profile-avatar-fallback { width: 56px; height: 56px; border-radius: 50%; }
        .profile-avatar-fallback { display: inline-flex; align-items: center; justify-content: center; background: var(--brand-soft); color: var(--brand); font-weight: 700; font-size: 16px; }
        .profile-menu-top h4 { font-size: 16px; margin-bottom: 4px; }
        .profile-menu-top p { font-size: 12px; color: var(--text-secondary); font-weight: 600; }
        .profile-menu a { display: block; padding: 10px 12px; border-radius: 10px; font-weight: 700; font-size: 14px; margin: 4px 0; }
        .profile-menu a:hover { background: var(--brand-soft); color: var(--brand); }

        /* ---- Sidebar ---- */
        .sidebar-toggle-btn {
            position: fixed; top: var(--header-height); left: 0; z-index: 1300; width: 36px; height: 36px;
            background: var(--surface); border: 1px solid var(--line); border-left: none; border-top: none;
            border-radius: 0 0 8px 0; font-size: 16px; color: var(--text); box-shadow: var(--shadow-sm);
        }
        .sidebar-toggle-btn:hover { background: var(--brand-soft); color: var(--brand); }
        .sidebar-overlay { display: none; position: fixed; top: var(--header-height); left: 0; width: 100%; height: calc(100vh - var(--header-height)); background: var(--overlay-dim); z-index: 1100; backdrop-filter: blur(2px); }
        .sidebar-overlay.show { display: block; }
        .sidebar {
            position: fixed; top: var(--header-height); left: -100%; width: min(320px, 85vw); height: calc(100vh - var(--header-height));
            background: var(--glass-bg-strong); backdrop-filter: blur(12px); border-right: 1px solid var(--line);
            padding: 24px 20px; z-index: 1200; transition: left .3s ease; overflow-y: auto;
        }
        .sidebar.open { left: 0; }
        .sidebar-title { font-size: 12px; color: var(--muted); text-transform: uppercase; margin-bottom: 20px; font-weight: 700; letter-spacing: 1px; padding: 0 8px; }
        .subject-list { display: grid; gap: 8px; }
        .subject-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: var(--radius-md); background: var(--surface-2); border: 1px solid var(--line); font-size: 14px; font-weight: 500; transition: all .2s; }
        .subject-link:hover { background: var(--brand-soft); border-color: var(--brand); transform: translateX(4px); }
        .subject-link.active { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }

        /* ---- Page content ---- */
        .main-content { padding: 24px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .stack { display: grid; gap: 20px; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: clamp(16px, 4vw, 24px); }
        .card h2, .section-title {
            font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 800; color: var(--gold); margin-bottom: 14px;
        }
        .page-hero { background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%); }
        .page-hero h1 { font-size: clamp(22px, 4vw, 30px); font-weight: 800; }
        .page-hero p { color: var(--text-secondary); margin-top: 4px; }
        .back-link { display: inline-block; margin-bottom: 10px; font-weight: 700; font-size: 14px; color: var(--brand); }
        .back-link:hover { color: var(--gold); }

        .flash { padding: 12px 16px; border-radius: var(--radius-sm); font-weight: 600; font-size: 14px; border: 1px solid transparent; }
        .flash.success { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }
        .flash.error { background: var(--danger-soft); color: var(--danger); border-color: var(--danger); }
        .flash.info { background: var(--accent-soft); color: var(--accent); border-color: var(--accent); }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 18px; border-radius: 999px;
            font-weight: 700; font-size: 13px; background: var(--surface); border: 1px solid var(--line); color: var(--text-secondary); transition: all .2s;
        }
        .btn:hover { border-color: var(--brand); color: var(--brand); transform: translateY(-1px); }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: var(--white); }
        .btn-primary:hover { color: var(--white); filter: brightness(1.1); }
        .btn-gold { background: var(--gold-soft); color: var(--gold); border-color: transparent; }
        .btn-gold:hover { border-color: var(--gold); color: var(--gold); }
        .btn-danger { color: var(--danger); background: var(--danger-soft); border-color: transparent; }
        .btn-danger:hover { border-color: var(--danger); color: var(--danger); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn[disabled] { opacity: .55; cursor: not-allowed; transform: none; }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge.brand { background: var(--brand-soft); color: var(--brand); }
        .badge.gold { background: var(--gold-soft); color: var(--gold); }
        .badge.accent { background: var(--accent-soft); color: var(--accent); }
        .badge.danger { background: var(--danger-soft); color: var(--danger); }
        .badge.muted { background: var(--surface-3); color: var(--muted); }

        .progress-track { height: 10px; border-radius: 999px; background: var(--surface-3); overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--brand-light), var(--brand)); transition: width .4s ease; }
        .progress-meta { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); margin-top: 6px; font-weight: 600; }

        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 16px; }
        .course-card { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--gold); border-radius: var(--radius-md); padding: 18px; display: flex; flex-direction: column; gap: 10px; }
        .course-card h3 { font-size: 17px; }
        .course-card .meta { display: flex; flex-wrap: wrap; gap: 6px 12px; font-size: 12px; color: var(--muted); font-weight: 600; }
        .course-card p.desc { font-size: 13px; color: var(--text-secondary); }
        .course-card .card-actions { margin-top: auto; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .course-card .card-actions form { display: inline; }
        .empty { text-align: center; color: var(--muted); padding: 24px 12px; }

        .day-group { border: 1px solid var(--line); border-radius: var(--radius-md); overflow: hidden; }
        .day-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 16px; background: var(--surface-2); border-bottom: 1px solid var(--line); font-weight: 800; font-size: 14px; }
        .day-header small { color: var(--muted); font-weight: 600; }
        .item-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--line); }
        .item-row:last-child { border-bottom: none; }
        .item-icon { font-size: 20px; flex-shrink: 0; width: 28px; text-align: center; }
        .item-main { flex: 1; min-width: 0; }
        .item-title { font-weight: 700; font-size: 14px; }
        .item-sub { font-size: 12px; color: var(--muted); }
        .item-row.locked { opacity: .65; }
        .item-row.missed .item-title { text-decoration: line-through; color: var(--muted); }
        .next-card { background: var(--gold-soft); border-color: var(--gold); }
        .next-card .item-title { font-size: 17px; }

        @media (max-width: 900px) { .header-top-nav { display: none; } }
        @media (max-width: 768px) {
            :root { --header-height: 64px; }
            .main-content { padding: 16px 12px 20px; }
            .app-header { padding: 12px; }
            .item-row { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<a href="#mainContent" class="skip-link">Skip to main content</a>

<header class="app-header">
    <div class="header-left">
        <a href="user_dashboard.php" class="brand-box">
            <img src="includes/logo.png" alt="MyIslam">
            <span>MyIslam</span>
        </a>
        <nav class="header-top-nav" aria-label="Main navigation">
            <?php foreach ($mainNav as [$label, $link, $active]): ?>
                <a href="<?php echo e($link); ?>" class="header-top-link <?php echo $active ? 'active' : ''; ?>"><?php echo e((string)$label); ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle" type="button">EN</button>
        <a href="notification.php" class="icon-btn" aria-label="Notifications">
            🔔
            <?php if ($unread > 0): ?>
                <span class="notification-badge"><?php echo $unread > 99 ? '99+' : $unread; ?></span>
            <?php endif; ?>
        </a>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if ($user['picture'] !== ''): ?>
                    <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials($user['name'])); ?>
                <?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if ($user['picture'] !== ''): ?>
                        <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials($user['name'])); ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?php echo e($user['name']); ?></h4>
                        <p><?php echo e($user['email']); ?></p>
                        <p style="font-size:10px;"><?php echo t('dashboard_member_since'); ?> <?php echo e($user['member_since']); ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 <?php echo t('profile'); ?></a>
                <a href="settings.php">⚙️ <?php echo t('settings'); ?></a>
                <a href="logout.php">🚪 <?php echo t('logout'); ?></a>
            </div>
        </div>
    </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-title">📚 Navigation</div>
    <nav class="subject-list">
        <?php foreach ($sideNav as [$label, $link, $icon, $active]): ?>
            <a href="<?php echo e($link); ?>" class="subject-link <?php echo $active ? 'active' : ''; ?>">
                <span><?php echo e($icon); ?></span>
                <span><?php echo e((string)$label); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>

<main class="main-content" id="mainContent">
    <div class="stack">
<?php
}

/** Closes the page content and prints the shared JavaScript. */
function courseShellEnd(): void
{
    ?>
    </div>
</main>

<script>
(function () {
    const body = document.body;
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuToggle = document.getElementById('menuToggle');
    const themeToggle = document.getElementById('themeToggle');
    const langToggle = document.getElementById('langToggle');
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    const profileArea = document.getElementById('profileArea');

    function setSidebar(open) {
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('show', open);
        body.style.overflow = open ? 'hidden' : '';
        menuToggle.setAttribute('aria-expanded', String(open));
    }
    menuToggle.addEventListener('click', () => setSidebar(!sidebar.classList.contains('open')));
    overlay.addEventListener('click', () => setSidebar(false));
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            setSidebar(false);
            profileMenu.classList.remove('show');
            profileToggle.setAttribute('aria-expanded', 'false');
        }
    });

    if (localStorage.getItem('theme') === 'dark') {
        body.classList.add('dark-mode');
        themeToggle.textContent = '☀';
    }
    themeToggle.addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        const dark = body.classList.contains('dark-mode');
        themeToggle.textContent = dark ? '☀' : '🌙';
        localStorage.setItem('theme', dark ? 'dark' : 'light');
    });

    let currentLang = localStorage.getItem('language') || '<?php echo e((string)($_SESSION['language'] ?? 'en')); ?>';
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
        } catch (err) {
            console.error('Language switch error:', err);
        }
    });

    profileToggle.addEventListener('click', e => {
        e.stopPropagation();
        const open = !profileMenu.classList.contains('show');
        profileMenu.classList.toggle('show', open);
        profileToggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', e => {
        if (!profileArea.contains(e.target)) {
            profileMenu.classList.remove('show');
            profileToggle.setAttribute('aria-expanded', 'false');
        }
    });

    // Confirm before forms that ask for it (e.g. leaving a course)
    document.querySelectorAll('form[data-confirm]').forEach(f => {
        f.addEventListener('submit', e => {
            if (!confirm(f.dataset.confirm)) e.preventDefault();
        });
    });
})();
</script>
</body>
</html>
<?php
}