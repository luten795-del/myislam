<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

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
    return $letters ?: 'U';
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn) {
    die('Unable to connect to database.');
}

function getCurrentUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, profile_image, role, is_active, created_at
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getCurrentUser: " . $e->getMessage());
        return null;
    }
}

function ensureUserSettingsTable(PDO $conn): void {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS user_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                preferred_language VARCHAR(10) DEFAULT 'EN',
                theme_preference VARCHAR(20) DEFAULT 'light',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
    } catch (PDOException $e) {
        error_log("ensureUserSettingsTable: " . $e->getMessage());
    }
}

function getUserSettings(PDO $conn, int $userId): array {
    ensureUserSettingsTable($conn);

    try {
        $stmt = $conn->prepare("
            SELECT preferred_language, theme_preference
            FROM user_settings
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row) {
            return $row;
        }

        $insert = $conn->prepare("
            INSERT INTO user_settings (user_id, preferred_language, theme_preference)
            VALUES (?, 'EN', 'light')
        ");
        $insert->execute([$userId]);

        return ['preferred_language' => 'EN', 'theme_preference' => 'light'];
    } catch (PDOException $e) {
        error_log("getUserSettings: " . $e->getMessage());
        return ['preferred_language' => 'EN', 'theme_preference' => 'light'];
    }
}

function saveUserSettings(PDO $conn, int $userId, string $language, string $theme): bool {
    ensureUserSettingsTable($conn);
    try {
        $stmt = $conn->prepare("
            INSERT INTO user_settings (user_id, preferred_language, theme_preference)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                preferred_language = VALUES(preferred_language),
                theme_preference = VALUES(theme_preference)
        ");
        return $stmt->execute([$userId, $language, $theme]);
    } catch (PDOException $e) {
        error_log("saveUserSettings: " . $e->getMessage());
        return false;
    }
}

$user = getCurrentUser($conn, $userId);
if (!$user) {
    session_destroy();
    header('Location: login.php?error=account_disabled');
    exit;
}
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table may not exist
}
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $preferredLanguage = strtoupper(trim((string)($_POST['preferred_language'] ?? 'EN')));
    if (!in_array($preferredLanguage, ['EN', 'SW'], true)) {
        $preferredLanguage = 'EN';
    }

    $themePreference = strtolower(trim((string)($_POST['theme_preference'] ?? 'light')));
    if (!in_array($themePreference, ['light', 'dark', 'system'], true)) {
        $themePreference = 'light';
    }

    if (saveUserSettings($conn, $userId, $preferredLanguage, $themePreference)) {
        $message = 'Settings saved successfully.';
        $messageType = 'success';
    } else {
        $message = 'Unable to save settings right now.';
        $messageType = 'danger';
    }
}

$settings = getUserSettings($conn, $userId);

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Quizzes', 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Monthly Exams', 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
    ['name' => 'Leaderboard', 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => 'Multimedia', 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => 'Notifications', 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => 'Help Center', 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
    ['name' => 'Profile', 'link' => 'profile.php', 'active' => false, 'icon' => '👤'],
    ['name' => 'Settings', 'link' => 'settings.php', 'active' => true, 'icon' => '⚙️'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e(t('settings')) ?> - MyIslam</title>
    <style>
        /* ===== DASHBOARD BASE STYLES ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #f5f7f5;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --surface-3: #eef2f7;
            --line: #e5e7eb;
            --text: #1f2937;
            --text-secondary: #4b5563;
            --muted: #6b7280;
            --brand: #166534;
            --brand-dark: #14532d;
            --brand-soft: #e7f3eb;
            --danger: #ef4444;
            --success: #10b981;
            --header-bg: #e1f0e7;
            --header-height: 72px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
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
            --brand: #7dd3a7;
            --brand-dark: #6bb590;
            --brand-soft: rgba(125, 211, 167, 0.12);
            --header-bg: #13202a;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            position: relative;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none; z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%23166534' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
        }

        body.dark-mode::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%237dd3a7' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }

        a { text-decoration: none; color: inherit; }
        button { font: inherit; border: none; background: none; cursor: pointer; }

        /* Header */
        .app-header {
            position: sticky; top: 0; z-index: 1000;
            height: var(--header-height); background: var(--header-bg);
            border-bottom: 2px solid var(--brand);
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 16px; backdrop-filter: blur(10px); gap: 8px;
        }

        .header-left, .header-right { display: flex; align-items: center; gap: 8px; }

        .menu-toggle {
            height: 40px; width: 40px; border-radius: 10px; background: var(--surface);
            border: 1px solid var(--brand); display: inline-flex; align-items: center;
            justify-content: center; font-size: 14px;
        }
        .menu-toggle:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }

        .brand-box {
            height: 40px; border-radius: 10px; background: transparent; display: inline-flex;
            align-items: center; justify-content: center; font-size: 22px; font-weight: 700;
            color: var(--brand); white-space: nowrap;
        }

        .icon-btn, .lang-btn {
            height: 40px; min-width: 30px; border-radius: 10px; background: var(--surface);
            border: 1px solid var(--brand); display: inline-flex; align-items: center;
            justify-content: center; transition: all 0.2s; padding: 0 8px; font-weight: 600;
        }
        .icon-btn:hover, .lang-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }
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
        .profile-area { position: relative; }
        .profile-btn {
            width: 40px; height: 40px; border-radius: 50%; overflow: hidden;
            background: var(--surface); border: 2px solid var(--brand); cursor: pointer;
            padding: 0; display: flex; align-items: center; justify-content: center;
            color: var(--brand); font-weight: 700;
        }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }

        .profile-menu {
            position: absolute; top: 56px; right: 0; width: 280px;
            background: var(--surface); border: 2px solid var(--brand);
            border-radius: var(--radius-lg); padding: 16px; display: none;
            box-shadow: var(--shadow-lg); z-index: 1001; backdrop-filter: blur(10px);
        }
        .profile-menu.show { display: block; }

        .profile-menu-top {
            display: flex; gap: 12px; padding-bottom: 10px; margin-bottom: 10px;
            border-bottom: 1px solid var(--brand);
        }
        .profile-menu-top img, .profile-avatar-fallback { width: 56px; height: 56px; border-radius: 50%; }
        .profile-avatar-fallback {
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--brand-soft); color: var(--brand); font-weight: 700; font-size: 16px;
        }
        .profile-menu-top h4 { font-size: 16px; margin-bottom: 4px; font-weight: bold; }
        .profile-menu-top p { font-size: 12px; color: var(--text-secondary); font-weight: bold; }

        .profile-menu a {
            display: block; padding: 10px 12px; border-radius: 8px; font-weight: 600;
            font-size: 14px; transition: background 0.2s; margin: 4px 0;
        }
        .profile-menu a:hover { background: var(--brand-soft); color: var(--brand); }

        /* Sidebar */
        .sidebar-overlay {
            display: none; position: fixed; top: var(--header-height); left: 0;
            width: 100%; height: calc(100vh - var(--header-height));
            background: rgba(0,0,0,0.3); z-index: 1100; backdrop-filter: blur(4px);
        }
        .sidebar-overlay.show { display: block; }

        .sidebar {
            position: fixed; top: var(--header-height); left: -100%;
            width: min(320px, 85vw); height: calc(100vh - var(--header-height));
            background: transparent; border-right: 1px solid var(--brand);
            padding: 24px 20px; z-index: 1200; transition: left 0.3s ease; overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }
        .sidebar.open { left: 0; }

        .sidebar-title {
            font-size: 12px; color: var(--muted); text-transform: uppercase;
            margin-bottom: 20px; font-weight: 700; letter-spacing: 1px; padding: 0 8px;
        }
        .subject-list { display: grid; gap: 8px; }

        .subject-link {
            display: flex; align-items: center; gap: 12px; padding: 12px 16px;
            border-radius: var(--radius-md); background: rgba(255,255,255,0.5);
            border: 1px solid var(--brand); font-size: 14px; font-weight: 500;
            transition: all 0.2s; color: var(--text);
        }
        .subject-link:hover { background: var(--brand-soft); border-color: var(--brand); transform: translateX(4px); }
        .subject-link.active { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }

        /* Main Content */
        .main-content {
            max-width: 700px; margin: 0 auto; padding: 32px 24px 24px;
        }

        .section-card {
            background: var(--surface); border-radius: var(--radius-lg);
            padding: 28px; box-shadow: var(--shadow-md); border: 1px solid var(--brand);
        }

        .settings-title {
            font-size: 28px; font-weight: 700; margin-bottom: 8px;
            display: flex; align-items: center; gap: 10px;
        }

        .settings-subtitle {
            color: var(--muted); margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 20px; 
        }

        .form-group label {
            display: block; margin-bottom: 8px; font-weight: 600;
            font-size: 14px; color: var(--text-secondary);
        }

        .form-select {
            width: 100%; padding: 14px 16px; border-radius: 10px;
            border: 1px solid green; background: var(--surface-2);
            color: var(--text); font-size: 15px; outline: none;
            transition: border 0.2s;
        }
        .form-select:focus { border-color: var(--brand); background: var(--surface); }

        .notice {
            padding: 14px 16px; border-radius: 14px; margin-bottom: 20px;
            font-size: 14px;
        }
        .notice.success {
            background: #d1fae5; color: #065f46;
            border-left: 4px solid var(--success);
        }
        .notice.danger {
            background: #fee2e2; color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        .btn-primary {
            padding: 14px 28px; background: var(--brand); color: white;
            border: 1px solid green; border-radius: 10px; font-weight: 700; font-size: 15px;
            cursor: pointer; transition: all 0.2s;
            box-shadow: 0 4px 10px rgba(22,101,52,0.2);
        }
        .btn-primary:hover { background: var(--brand-dark); transform: translateY(-2px); }

        @media (max-width: 768px) {
            .main-content { padding: 20px 16px; }
            .section-card { padding: 20px; }
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="brand-box">MyIslam</div>
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
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="<?= e($user['profile_image']) ?>" alt="Profile">
                <?php else: ?>
                    <?= initials($user['full_name'] ?? 'User') ?>
                <?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['profile_image'])): ?>
                        <img src="<?= e($user['profile_image']) ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?= initials($user['full_name'] ?? 'User') ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?= e($user['full_name'] ?? 'User') ?></h4>
                        <p><?= e($user['email'] ?? '') ?></p>
                        <p style="font-size: 10px;">Member since <?= !empty($user['created_at']) ? e(date('F Y', strtotime($user['created_at']))) : 'recently' ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 My Profile</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<!-- Sidebar -->
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

<main class="main-content">
    <div class="section-card">
        <h1 class="settings-title">⚙️ <?= e(t('settings')) ?></h1>
        <p class="settings-subtitle"><?= e(t('customise_experience')) ?></p>

        <?php if ($message): ?>
            <div class="notice <?= $messageType ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="preferred_language"><?= e(t('language')) ?></label>
                <select id="preferred_language" name="preferred_language" class="form-select">
                    <option value="EN" <?= ($settings['preferred_language'] ?? 'EN') === 'EN' ? 'selected' : '' ?>><?= e(t('english')) ?></option>
                    <option value="SW" <?= ($settings['preferred_language'] ?? 'EN') === 'SW' ? 'selected' : '' ?>><?= e(t('swahili')) ?></option>
                </select>
            </div>

            <div class="form-group">
                <label for="theme_preference"><?= e(t('theme')) ?></label>
                <select id="theme_preference" name="theme_preference" class="form-select">
                    <option value="light" <?= ($settings['theme_preference'] ?? 'light') === 'light' ? 'selected' : '' ?>><?= e(t('light')) ?></option>
                    <option value="dark" <?= ($settings['theme_preference'] ?? 'light') === 'dark' ? 'selected' : '' ?>><?= e(t('dark')) ?></option>
                    <option value="system" <?= ($settings['theme_preference'] ?? 'light') === 'system' ? 'selected' : '' ?>><?= e(t('system_default')) ?></option>
                </select>
            </div>

            <button type="submit" class="btn-primary">💾 <?= e(t('save_settings')) ?></button>
        </form>
    </div>
</main>

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
        const themeSelect = document.getElementById('theme_preference');

        // Sidebar
        function openSidebar() {
            if (sidebar) sidebar.classList.add('open');
            if (sidebarOverlay) sidebarOverlay.classList.add('show');
            body.style.overflow = 'hidden';
        }
        function closeSidebar() {
            if (sidebar) sidebar.classList.remove('open');
            if (sidebarOverlay) sidebarOverlay.classList.remove('show');
            body.style.overflow = '';
        }
        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
            });
        }
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (sidebar && sidebar.classList.contains('open')) closeSidebar();
                if (profileMenu && profileMenu.classList.contains('show')) {
                    profileMenu.classList.remove('show');
                    profileToggle?.setAttribute('aria-expanded', 'false');
                }
            }
        });

        // Theme
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            if (themeToggle) themeToggle.textContent = '☀';
        }
        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                body.classList.toggle('dark-mode');
                const isDark = body.classList.contains('dark-mode');
                themeToggle.textContent = isDark ? '☀' : '🌙';
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                if (themeSelect) {
                    themeSelect.value = isDark ? 'dark' : 'light';
                }
            });
        }

        // Theme dropdown sync
        if (themeSelect) {
            themeSelect.addEventListener('change', function() {
                if (this.value === 'dark') {
                    body.classList.add('dark-mode');
                    localStorage.setItem('theme', 'dark');
                    if (themeToggle) themeToggle.textContent = '☀';
                } else if (this.value === 'light') {
                    body.classList.remove('dark-mode');
                    localStorage.setItem('theme', 'light');
                    if (themeToggle) themeToggle.textContent = '🌙';
                } else if (this.value === 'system') {
                    // System preference – use matchMedia
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    body.classList.toggle('dark-mode', prefersDark);
                    localStorage.setItem('theme', 'system');
                    if (themeToggle) themeToggle.textContent = prefersDark ? '☀' : '🌙';
                }
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
                document.getElementById('preferred_language').value = currentLang;
            });
        }

        // Profile dropdown
        if (profileToggle && profileMenu) {
            profileToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                profileMenu.classList.toggle('show');
                profileToggle.setAttribute('aria-expanded', profileMenu.classList.contains('show'));
            });
            document.addEventListener('click', function(e) {
                if (!profileArea.contains(e.target)) {
                    profileMenu.classList.remove('show');
                    profileToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    })();
</script>
</body>
</html>