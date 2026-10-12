<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'A';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters ?: 'A';
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$conn = db();
if (!$conn) {
    die('Unable to connect to database.');
}

$currentUserId = (int)$_SESSION['user_id'];

function getAdminUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, profile_image, role, is_active, created_at
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) return null;
        if (strtolower((string)($user['role'] ?? '')) !== 'admin') return null;
        return $user;
    } catch (PDOException $e) {
        error_log("getAdminUser: " . $e->getMessage());
        return null;
    }
}

function ensureAdminSettingsTable(PDO $conn): void {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS admin_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_name VARCHAR(150) DEFAULT 'MyIslam',
                default_language VARCHAR(10) DEFAULT 'EN',
                default_theme VARCHAR(20) DEFAULT 'light',
                allow_user_registration TINYINT(1) DEFAULT 1,
                enable_announcements TINYINT(1) DEFAULT 1,
                enable_leaderboard TINYINT(1) DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
    } catch (PDOException $e) {
        error_log("ensureAdminSettingsTable: " . $e->getMessage());
    }
}

function getAdminSettings(PDO $conn): array {
    ensureAdminSettingsTable($conn);

    try {
        $stmt = $conn->query("SELECT * FROM admin_settings ORDER BY id ASC LIMIT 1");
        $row = $stmt->fetch();

        if ($row) return $row;

        $conn->exec("
            INSERT INTO admin_settings
            (site_name, default_language, default_theme, allow_user_registration, enable_announcements, enable_leaderboard)
            VALUES ('MyIslam', 'EN', 'light', 1, 1, 1)
        ");

        return [
            'site_name' => 'MyIslam',
            'default_language' => 'EN',
            'default_theme' => 'light',
            'allow_user_registration' => 1,
            'enable_announcements' => 1,
            'enable_leaderboard' => 1,
        ];
    } catch (PDOException $e) {
        error_log("getAdminSettings: " . $e->getMessage());
        return [
            'site_name' => 'MyIslam',
            'default_language' => 'EN',
            'default_theme' => 'light',
            'allow_user_registration' => 1,
            'enable_announcements' => 1,
            'enable_leaderboard' => 1,
        ];
    }
}

function saveAdminSettings(PDO $conn, array $data): bool {
    ensureAdminSettingsTable($conn);

    try {
        $existing = $conn->query("SELECT id FROM admin_settings ORDER BY id ASC LIMIT 1")->fetch();

        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE admin_settings
                SET
                    site_name = ?,
                    default_language = ?,
                    default_theme = ?,
                    allow_user_registration = ?,
                    enable_announcements = ?,
                    enable_leaderboard = ?
                WHERE id = ?
            ");
            return $stmt->execute([
                $data['site_name'],
                $data['default_language'],
                $data['default_theme'],
                $data['allow_user_registration'],
                $data['enable_announcements'],
                $data['enable_leaderboard'],
                (int)$existing['id']
            ]);
        }

        $stmt = $conn->prepare("
            INSERT INTO admin_settings
            (site_name, default_language, default_theme, allow_user_registration, enable_announcements, enable_leaderboard)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $data['site_name'],
            $data['default_language'],
            $data['default_theme'],
            $data['allow_user_registration'],
            $data['enable_announcements'],
            $data['enable_leaderboard'],
        ]);
    } catch (PDOException $e) {
        error_log("saveAdminSettings: " . $e->getMessage());
        return false;
    }
}

$admin = getAdminUser($conn, $currentUserId);
if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Admins only.');
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteName = trim((string)($_POST['site_name'] ?? 'MyIslam'));
    $defaultLanguage = strtoupper(trim((string)($_POST['default_language'] ?? 'EN')));
    $defaultTheme = strtolower(trim((string)($_POST['default_theme'] ?? 'light')));

    if (!in_array($defaultLanguage, ['EN', 'SW'], true)) {
        $defaultLanguage = 'EN';
    }
    if (!in_array($defaultTheme, ['light', 'dark', 'system'], true)) {
        $defaultTheme = 'light';
    }

    $data = [
        'site_name' => $siteName !== '' ? $siteName : 'MyIslam',
        'default_language' => $defaultLanguage,
        'default_theme' => $defaultTheme,
        'allow_user_registration' => isset($_POST['allow_user_registration']) ? 1 : 0,
        'enable_announcements' => isset($_POST['enable_announcements']) ? 1 : 0,
        'enable_leaderboard' => isset($_POST['enable_leaderboard']) ? 1 : 0,
    ];

    if (saveAdminSettings($conn, $data)) {
        $message = 'Admin settings saved successfully.';
    } else {
        $message = 'Unable to save admin settings.';
        $messageType = 'danger';
    }
}

$settings = getAdminSettings($conn);

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => true],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false, 'icon' => '🗂️'],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false, 'icon' => '📘'],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false, 'icon' => '👥'],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false, 'icon' => '📢'],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => true, 'icon' => '⚙️'],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false, 'icon' => '🚪'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Settings - MyIslam</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
--bg:#f5f7f5;--surface:#fff;--surface-2:#f9fafb;--line:#e5e7eb;
--text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;--brand:#166534;--brand-soft:#e7f3eb;
--danger:#ef4444;--header-bg:#e1f0e7;--header-height:72px;--footer-height:92px;
}
body.dark-mode{
--bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--line:#304150;
--text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;--brand:#7dd3a7;--brand-soft:rgba(125,211,167,.12);--header-bg:#13202a;
}
body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5}
a{text-decoration:none;color:inherit} button,input,select{font:inherit}
.app-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:var(--header-height);background:var(--header-bg);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;gap:18px}
.header-left,.header-right{display:flex;align-items:center;gap:12px}.header-left{flex:1;min-width:0}
.header-top-nav{display:flex;gap:8px;overflow-x:auto}
.header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;font-size:13px;font-weight:700;white-space:nowrap}
.header-top-link.active,.header-top-link:hover{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
.menu-toggle,.icon-btn,.lang-btn,.brand-box{height:44px;min-width:44px;border-radius:12px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
.brand-box{font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none}
.profile-area{position:relative}.profile-btn{width:44px;height:44px;border-radius:50%;overflow:hidden;background:var(--surface);border:2px solid var(--brand);display:flex;align-items:center;justify-content:center;color:var(--brand);font-weight:700}
.profile-btn img{width:100%;height:100%;object-fit:cover}
.profile-menu{position:absolute;top:56px;right:0;width:280px;background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:16px;display:none;z-index:1001}
.profile-menu.show{display:block}
.profile-menu-top{display:flex;gap:12px;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--line)}
.profile-menu-top img,.profile-avatar-fallback{width:56px;height:56px;border-radius:50%}
.profile-avatar-fallback{display:flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:700}
.profile-menu a{display:block;padding:10px 12px;border-radius:12px;font-size:14px;margin:4px 0}
.profile-menu a:hover{background:var(--brand-soft);color:var(--brand)}
.sidebar-overlay{display:none;position:fixed;top:var(--header-height);left:0;width:100%;height:calc(100vh - var(--header-height));background:rgba(0,0,0,.5);z-index:1100}
.sidebar-overlay.show{display:block}
.sidebar{position:fixed;top:var(--header-height);left:-100%;width:320px;height:calc(100vh - var(--header-height));background:var(--surface);border-right:1px solid var(--line);padding:24px 20px;z-index:1200;transition:left .3s ease;overflow-y:auto}
.sidebar.open{left:0}
.sidebar-title{font-size:12px;color:var(--muted);text-transform:uppercase;margin-bottom:20px;font-weight:700;letter-spacing:1px;padding:0 8px}
.subject-list{display:grid;gap:8px}
.subject-link{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:16px;background:var(--surface-2);border:1px solid transparent;font-size:14px;font-weight:500}
.subject-link.active,.subject-link:hover{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
.main-content{padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px);max-width:1000px;margin:0 auto}
.page-stack{display:grid;gap:24px}
.section-card{background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:24px}
.hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none}
.hero-card h1{font-size:32px;margin-bottom:8px}
.hero-card p{color:var(--text-secondary)}
.notice{padding:14px 16px;border-radius:14px;border:1px solid var(--line);background:var(--surface-2)}
.notice.success{border-color:#86efac;background:rgba(34,197,94,.12)}
.notice.danger{border-color:#fca5a5;background:rgba(239,68,68,.12)}
.form-grid{display:grid;gap:18px}
.form-row{display:grid;gap:8px}
.form-row label{font-size:14px;font-weight:700}
.form-input,.form-select{height:48px;padding:0 14px;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);outline:none}
.checkbox-row{display:flex;align-items:center;gap:10px;padding:14px;border-radius:14px;background:var(--surface-2);border:1px solid var(--line)}
.btn{padding:12px 16px;border-radius:14px;font-weight:700;border:none;cursor:pointer}
.btn.primary{background:var(--brand);color:#fff}

@media (max-width:950px){.header-top-nav{display:none}}
@media (max-width:768px){:root{--header-height:64px;--footer-height:80px}.main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}.footer-grid{grid-template-columns:1fr;gap:12px}}
</style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>
<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle">☰</button>
        <div class="brand-box">🛠️ Admin</div>
        <nav class="header-top-nav">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>"><?php echo e($navItem['name']); ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle">
                <?php if (!empty($admin['profile_image'])): ?>
                    <img src="<?php echo e($admin['profile_image']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials((string)$admin['full_name'])); ?>
                <?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($admin['profile_image'])): ?>
                        <img src="<?php echo e($admin['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)$admin['full_name'])); ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?php echo e($admin['full_name']); ?></h4>
                        <p><?php echo e($admin['email']); ?></p>
                    </div>
                </div>
                <a href="../profile.php">👤 My Profile</a>
                <a href="admin_settings.php">⚙️ Admin Settings</a>
                <a href="../logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">🛠️ Admin Panel</div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon']); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-stack">
            <section class="hero-card section-card">
                <h1>⚙️ Admin Settings</h1>
                <p>Manage platform-level preferences and defaults.</p>
            </section>

            <?php if ($message !== ''): ?>
                <div class="notice <?php echo $messageType === 'success' ? 'success' : 'danger'; ?>"><?php echo e($message); ?></div>
            <?php endif; ?>

            <section class="section-card">
                <form method="POST" class="form-grid">
                    <div class="form-row">
                        <label for="site_name">Site Name</label>
                        <input type="text" id="site_name" name="site_name" class="form-input" value="<?php echo e($settings['site_name'] ?? 'MyIslam'); ?>">
                    </div>

                    <div class="form-row">
                        <label for="default_language">Default Language</label>
                        <select id="default_language" name="default_language" class="form-select">
                            <option value="EN" <?php echo (($settings['default_language'] ?? 'EN') === 'EN') ? 'selected' : ''; ?>>English</option>
                            <option value="SW" <?php echo (($settings['default_language'] ?? 'EN') === 'SW') ? 'selected' : ''; ?>>Swahili</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="default_theme">Default Theme</label>
                        <select id="default_theme" name="default_theme" class="form-select">
                            <option value="light" <?php echo (($settings['default_theme'] ?? 'light') === 'light') ? 'selected' : ''; ?>>Light</option>
                            <option value="dark" <?php echo (($settings['default_theme'] ?? 'light') === 'dark') ? 'selected' : ''; ?>>Dark</option>
                            <option value="system" <?php echo (($settings['default_theme'] ?? 'light') === 'system') ? 'selected' : ''; ?>>System</option>
                        </select>
                    </div>

                    <label class="checkbox-row">
                        <input type="checkbox" name="allow_user_registration" <?php echo !empty($settings['allow_user_registration']) ? 'checked' : ''; ?>>
                        <span>Allow User Registration</span>
                    </label>

                    <label class="checkbox-row">
                        <input type="checkbox" name="enable_announcements" <?php echo !empty($settings['enable_announcements']) ? 'checked' : ''; ?>>
                        <span>Enable Announcements</span>
                    </label>

                    <label class="checkbox-row">
                        <input type="checkbox" name="enable_leaderboard" <?php echo !empty($settings['enable_leaderboard']) ? 'checked' : ''; ?>>
                        <span>Enable Leaderboard</span>
                    </label>

                    <div>
                        <button class="btn primary" type="submit">Save Settings</button>
                    </div>
                </form>
            </section>
        </div>
    </main>

   
</div>

<script>
(function() {
    const body = document.body, menuToggle = document.getElementById('menuToggle'),
          sidebar = document.getElementById('sidebar'), sidebarOverlay = document.getElementById('sidebarOverlay'),
          themeToggle = document.getElementById('themeToggle'), langToggle = document.getElementById('langToggle'),
          profileToggle = document.getElementById('profileToggle'), profileMenu = document.getElementById('profileMenu'),
          profileArea = document.getElementById('profileArea');

    function openSidebar(){sidebar?.classList.add('open');sidebarOverlay?.classList.add('show');body.style.overflow='hidden';}
    function closeSidebar(){sidebar?.classList.remove('open');sidebarOverlay?.classList.remove('show');body.style.overflow='';}
    menuToggle?.addEventListener('click',()=>sidebar?.classList.contains('open')?closeSidebar():openSidebar());
    sidebarOverlay?.addEventListener('click',closeSidebar);

    const savedTheme = localStorage.getItem('theme');
    if(savedTheme==='dark'){body.classList.add('dark-mode'); if(themeToggle) themeToggle.textContent='☀';}
    themeToggle?.addEventListener('click',()=>{body.classList.toggle('dark-mode');const d=body.classList.contains('dark-mode');themeToggle.textContent=d?'☀':'🌙';localStorage.setItem('theme',d?'dark':'light');});

    let currentLang = localStorage.getItem('language') || 'EN';
    if(langToggle){langToggle.textContent=currentLang;langToggle.addEventListener('click',()=>{currentLang=currentLang==='EN'?'SW':'EN';langToggle.textContent=currentLang;localStorage.setItem('language',currentLang);});}

    profileToggle?.addEventListener('click',(e)=>{e.stopPropagation();const open=profileMenu.classList.contains('show');profileMenu.classList.toggle('show',!open);});
    document.addEventListener('click',(e)=>{if(profileArea && !profileArea.contains(e.target)){profileMenu?.classList.remove('show');}});
})();
</script>
</body>
</html>