<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/config/database.php';

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

/**
 * Shared page shell (header, sidebar, main-content wrapper, and the JS that
 * drives them) used by every secondary page so they visually match the
 * dashboard. Include this file, then call app_shell_open() right after
 * <body>, render your page content, then call app_shell_close().
 *
 * Expects the including page to already define e() and initials().
 */

/**
 * @param array|null $user          ['name'=>..,'email'=>..,'picture'=>..,'member_since'=>..] or null for a guest visitor
 * @param int    $unreadCount        unread notification count for the bell badge
 * @param array  $mainNavigation     top header pill nav: [['name'=>,'link'=>,'active'=>bool], ...]
 * @param array  $sidebarNavigation  sidebar list: [['name'=>,'link'=>,'active'=>bool,'icon'=>], ...]
 */
function app_shell_open(?array $user, int $unreadCount, array $mainNavigation, array $sidebarNavigation): void
{
    $isGuest = $user === null;
    ?>
<a href="#mainContent" class="skip-link">Skip to main content</a>

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
        <button class="icon-btn" id="themeToggle" aria-label="Toggle dark mode">🌙</button>
        <button class="lang-btn" id="langToggle" type="button">EN</button>
        <?php if (!$isGuest): ?>
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="Notifications">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?></span>
            <?php endif; ?>
        </a>

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if (!empty($user['picture'])): ?>
                    <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials((string)($user['name'] ?? 'User'))); ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)($user['name'] ?? 'User'))); ?></div>
                    <?php endif; ?>

                    <div>
                        <h4><?php echo e((string)($user['name'] ?? 'User')); ?></h4>
                        <p><?php echo e((string)($user['email'] ?? '')); ?></p>
                        <?php if (!empty($user['member_since'])): ?>
                            <p style="font-size: 10px;">Member since <?php echo e((string)$user['member_since']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <a href="profile.php">👤 My Profile</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="logout.php">🚪 Logout</a>
            </div>
        </div>
        <?php else: ?>
        <a href="login.php" class="lang-btn" style="padding: 0 16px; color: var(--brand); font-weight: 700;">Log in</a>
        <?php endif; ?>
    </div>
</header>
<button class="sidebar-toggle-btn" id="menuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">☰</button>
<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">📚 Navigation</div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo !empty($item['active']) ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon'] ?? '•'); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">
        <div class="dashboard-stack">
    <?php
}

function app_shell_close(): void
{
    ?>
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
            if (sidebar && sidebar.classList.contains('open')) closeSidebar();
            else openSidebar();
        });
    }
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (sidebar && sidebar.classList.contains('open')) closeSidebar();
            if (profileMenu && profileMenu.classList.contains('show')) {
                profileMenu.classList.remove('show');
                if (profileToggle) profileToggle.setAttribute('aria-expanded', 'false');
            }
        }
    });

    // Dark mode (persisted in localStorage, same key as the dashboard)
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

    // Language toggle (visual only here; the dashboard's language switch endpoint can be wired in later)
    let currentLang = localStorage.getItem('language') || 'en';
    if (langToggle) {
        langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
        langToggle.addEventListener('click', () => {
            currentLang = currentLang === 'en' ? 'sw' : 'en';
            langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
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
            if (profileArea && !profileArea.contains(e.target)) {
                profileMenu.classList.remove('show');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }
})();
</script>
    <?php
}

/**
 * Builds the shared top header nav + sidebar nav arrays with the given page
 * marked active, so every page stays in sync without copy-pasting the list.
 */
function app_shell_navigation(string $activeLink): array
{
    $items = [
        ['name' => 'Dashboard',            'link' => 'user_dashboard.php',       'icon' => '📊'],
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
    foreach ($items as $item) {
        $item['active'] = ($item['link'] === $activeLink);
        $sidebarNavigation[] = $item;
    }

    // The top header nav shows a shorter, higher-traffic subset (matches the dashboard)
    $mainLinks = ['user_dashboard.php', 'subjects.php', 'quizzes.php', 'monthly_exams.php', 'leaderboard.php', 'multimedia.php', 'notification.php', 'help_center.php'];
    $mainNavigation = [];
    foreach ($sidebarNavigation as $item) {
        if (in_array($item['link'], $mainLinks, true)) {
            $mainNavigation[] = ['name' => $item['name'], 'link' => $item['link'], 'active' => $item['active']];
        }
    }

    return [$mainNavigation, $sidebarNavigation];
}


if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$conn = db();

// Fetch user details for header
$dbUser = null;
try {
    $stmt = $conn->prepare("
        SELECT id, full_name, email, profile_image, role, is_active, is_verified, created_at
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

// Sidebar + header navigation (shared across all pages, this one marked active)
[$mainNavigation, $sidebarNavigation] = app_shell_navigation('multimedia.php');

// Ensure required tables exist
$tableQueries = [
    "CREATE TABLE IF NOT EXISTS `media` (
      `id` int NOT NULL AUTO_INCREMENT,
      `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `description` text COLLATE utf8mb4_unicode_ci,
      `media_type` enum('image','video','audio','document') COLLATE utf8mb4_unicode_ci NOT NULL,
      `file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `video_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `thumbnail_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `file_size` int DEFAULT NULL,
      `duration` int DEFAULT NULL,
      `subject_id` int DEFAULT NULL,
      `uploaded_by` int NOT NULL,
      `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
      `view_count` int NOT NULL DEFAULT '0',
      `download_count` int NOT NULL DEFAULT '0',
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `slug` (`slug`),
      KEY `media_type` (`media_type`),
      KEY `subject_id` (`subject_id`),
      KEY `uploaded_by` (`uploaded_by`),
      KEY `status` (`status`),
      CONSTRAINT `fk_media_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
      CONSTRAINT `fk_media_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `media_categories` (
      `id` int NOT NULL AUTO_INCREMENT,
      `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `description` text COLLATE utf8mb4_unicode_ci,
      `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `sort_order` int NOT NULL DEFAULT '0',
      `is_active` tinyint(1) NOT NULL DEFAULT '1',
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `media_category_relations` (
      `media_id` int NOT NULL,
      `category_id` int NOT NULL,
      PRIMARY KEY (`media_id`,`category_id`),
      KEY `category_id` (`category_id`),
      CONSTRAINT `fk_mcr_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_mcr_category` FOREIGN KEY (`category_id`) REFERENCES `media_categories` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `media_views` (
      `id` bigint NOT NULL AUTO_INCREMENT,
      `media_id` int NOT NULL,
      `user_id` int DEFAULT NULL,
      `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `viewed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `media_id` (`media_id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `fk_media_views_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_media_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `media_downloads` (
      `id` bigint NOT NULL AUTO_INCREMENT,
      `media_id` int NOT NULL,
      `user_id` int NOT NULL,
      `downloaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `media_id` (`media_id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `fk_media_downloads_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_media_downloads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

foreach ($tableQueries as $query) {
    $conn->exec($query);
}

// Defensive migration: if `media` already existed from before this file
// added YouTube/Vimeo support, it may be missing the `video_url` column
// (CREATE TABLE IF NOT EXISTS above is a no-op on an existing table).
try {
    $hasVideoUrlColumn = $conn->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media' AND COLUMN_NAME = 'video_url'
    ")->fetchColumn();
    if (!$hasVideoUrlColumn) {
        $conn->exec("ALTER TABLE `media` ADD COLUMN `video_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `file_path`");
    }
} catch (PDOException $e) {
    // Non-fatal: if this fails the page still works for file-based media.
}

// Seed a starter set of media categories the first time this page runs
// (so the category dropdown isn't empty). Admins can rename, add to, or
// remove these at any time from the Categories tab in the admin panel.
try {
    $categoryCount = (int)$conn->query("SELECT COUNT(*) FROM media_categories")->fetchColumn();
    if ($categoryCount === 0) {
        $defaultCategories = [
            ['Mihadhara', 'mihadhara', '🎤'],
            ['Mawaidha', 'mawaidha', '📖'],
            ['Adhkar', 'adhkar', '📿'],
            ['Khutba', 'khutba', '🕌'],
            ['Nasaha', 'nasaha', '💬'],
        ];
        $insertCat = $conn->prepare("INSERT INTO media_categories (name, slug, icon, sort_order) VALUES (?, ?, ?, ?)");
        foreach ($defaultCategories as $i => $cat) {
            $insertCat->execute([$cat[0], $cat[1], $cat[2], $i]);
        }
    }
} catch (PDOException $e) {
    // Non-fatal: the page still works with an empty/admin-managed category list.
}

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'error' => null];
    
    if ($action === 'increment_view') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        if ($mediaId > 0) {
            try {
                $conn->beginTransaction();
                
                // Check if user already viewed this media in the last 30 minutes (prevents spamming)
                $stmt = $conn->prepare("
                    SELECT id FROM media_views 
                    WHERE media_id = ? AND user_id = ? 
                      AND viewed_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                    LIMIT 1
                ");
                $stmt->execute([$mediaId, $userId]);
                $recentView = $stmt->fetchColumn();
                
                if (!$recentView) {
                    $stmt = $conn->prepare("UPDATE media SET view_count = view_count + 1 WHERE id = ?");
                    $stmt->execute([$mediaId]);
                    $stmt = $conn->prepare("INSERT INTO media_views (media_id, user_id, ip_address) VALUES (?, ?, ?)");
                    $stmt->execute([$mediaId, $userId, $_SERVER['REMOTE_ADDR'] ?? null]);
                }
                $conn->commit();
                $response = ['success' => true];
            } catch (PDOException $e) {
                $conn->rollBack();
                $response['error'] = $e->getMessage();
            }
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'track_download') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        if ($mediaId > 0) {
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare("UPDATE media SET download_count = download_count + 1 WHERE id = ?");
                $stmt->execute([$mediaId]);
                $stmt = $conn->prepare("INSERT INTO media_downloads (media_id, user_id) VALUES (?, ?)");
                $stmt->execute([$mediaId, $userId]);
                $conn->commit();
                $response = ['success' => true];
            } catch (PDOException $e) {
                $conn->rollBack();
                $response['error'] = $e->getMessage();
            }
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch categories for filter pills
$categories = $conn->query("SELECT id, name, icon FROM media_categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll();

$activeType = $_GET['type'] ?? 'all';
$activeCategory = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$searchTerm = trim($_GET['search'] ?? '');

$where = ["m.status = 'published'"];
$params = [];

if ($activeType !== 'all' && in_array($activeType, ['image', 'video', 'audio', 'document'])) {
    $where[] = "m.media_type = ?";
    $params[] = $activeType;
}
if ($activeCategory > 0) {
    $where[] = "EXISTS (SELECT 1 FROM media_category_relations mcr WHERE mcr.media_id = m.id AND mcr.category_id = ?)";
    $params[] = $activeCategory;
}
if ($searchTerm !== '') {
    $where[] = "(m.title LIKE ? OR m.description LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

$whereClause = implode(' AND ', $where);
$sql = "
    SELECT m.*, 
           (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM media_category_relations mcr 
            JOIN media_categories c ON c.id = mcr.category_id WHERE mcr.media_id = m.id) AS categories
    FROM media m
    WHERE $whereClause
    ORDER BY m.created_at DESC
    LIMIT 50
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$mediaItems = $stmt->fetchAll();

// Helper functions
function formatSize($bytes): string {
    if ($bytes === null) return '';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
function formatDuration($seconds): string {
    if (!$seconds) return '';
    $mins = floor($seconds / 60);
    $secs = $seconds % 60;
    return sprintf('%d:%02d', $mins, $secs);
}
function getFileIcon($type): string {
    return [
        'image'    => 'fa-image',
        'video'    => 'fa-video',
        'audio'    => 'fa-headphones',
        'document' => 'fa-file-alt'
    ][$type] ?? 'fa-file';
}
function getTypeLabel($type): string {
    return [
        'image'    => 'Image',
        'video'    => 'Video',
        'audio'    => 'Audio',
        'document' => 'Document'
    ][$type] ?? 'File';
}
function getFallbackColor($type): string {
    return [
        'image'    => '#e0f2fe',
        'video'    => '#fce7f3',
        'audio'    => '#fef3c7',
        'document' => '#e6e6fa'
    ][$type] ?? '#f3f4f6';
}
// Pulls a YouTube video id out of an embed/watch/short URL, for building a
// thumbnail preview - returns null for non-YouTube URLs (e.g. Vimeo).
function extractYoutubeId(?string $url): ?string {
    if (empty($url)) return null;
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
        return $m[1];
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Multimedia Library – MyIslam</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
/* ============================================================================
   MyIslam shared app theme
   Extracted from user_dashboard.php so every page (multimedia, notifications,
   calendar, help center, etc.) shares one visual language: colors, header,
   sidebar, cards, buttons. Edit here once and every page that includes this
   file picks up the change.
   ============================================================================ */

* { box-sizing: border-box; margin: 0; padding: 0; }

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

body {
    font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.5;
    padding-bottom: 24px;
    position: relative;
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
.filter-pill:focus-visible,
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

.main-content {
    padding: 24px 24px 20px;
    max-width: 1400px; margin: 0 auto;
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

.page-header { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; flex-wrap: wrap; justify-content: space-between; }
.page-title { font-size: clamp(22px, 4vw, 28px); font-weight: 700; color: var(--text); letter-spacing: -0.01em; display: flex; align-items: center; gap: 10px; }
.page-subtitle { color: var(--muted); font-size: 14px; margin-top: 4px; }

.period-label {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--muted);
    background: var(--surface-3);
    border-radius: 999px;
    padding: 3px 10px;
    margin-left: 6px;
    vertical-align: middle;
}

.filter-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.filter-pill {
    padding: 8px 14px; border-radius: 9px; background: var(--surface);
    border: 1px solid var(--line); font-size: 13px; font-weight: 600; transition: all 0.2s;
    color: var(--text-secondary); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
}
.filter-pill.active { background: var(--brand); border-color: var(--brand); color: var(--white); }
.filter-pill:hover:not(.active) { background: var(--surface-3); }

/* Generic buttons, reused by pages that need actions (download, share, mark read, etc.) */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 10px 16px; border-radius: var(--radius-sm); font-weight: 700; font-size: 14px;
    border: 1px solid var(--brand); background: var(--brand); color: var(--white);
    cursor: pointer; transition: all 0.2s; box-shadow: var(--shadow-sm);
}
.btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.btn-secondary { background: var(--surface); color: var(--text); border-color: var(--line); }
.btn-secondary:hover { border-color: var(--brand); color: var(--brand); }
.btn-outline { background: transparent; color: var(--brand); border-color: var(--brand); box-shadow: none; }
.btn-danger { background: var(--danger); border-color: var(--danger); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

.empty-state { text-align: center; padding: 48px 20px; color: var(--muted); }
.empty-state .empty-icon { font-size: 44px; margin-bottom: 12px; opacity: 0.6; }
.empty-state h3 { color: var(--text); font-size: 16px; margin-bottom: 6px; }

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
}
    </style>
    <style>
        /* Page-specific styles for the multimedia library (header, sidebar, cards,
           buttons already come from includes/app_theme.css) */

        .page-header i { color: var(--brand); }

        /* Category dropdown - replaces the old row of type/category tabs
           with a single select so the filter list can grow (new categories
           an admin adds - Mihadhara, Mawaidha, Adhkaar, etc.) without ever
           needing more tab rows. */
        .category-select-wrapper { position: relative; max-width: 360px; }
        .category-select {
            width: 100%; padding: 12px 40px 12px 16px; border-radius: var(--radius-sm);
            border: 1px solid var(--line); background: var(--surface); color: var(--text);
            font-size: 14px; font-weight: 600; cursor: pointer; appearance: none;
            transition: all 0.2s;
        }
        .category-select:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px var(--brand-soft); }
        .category-select-wrapper::after {
            content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
            position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
            color: var(--muted); pointer-events: none; font-size: 13px;
        }

        .search-wrapper { position: relative; }
        .search-input {
            width: 100%; padding: 14px 18px 14px 46px; border-radius: var(--radius-sm);
            border: 1px solid var(--line); background: var(--surface); font-size: 15px;
            color: var(--text); transition: all 0.2s;
        }
        .search-input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px var(--brand-soft); }
        .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 16px; }

        /* Media grid */
        .media-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        @media (min-width: 500px) { .media-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 768px) { .media-grid { grid-template-columns: repeat(4, 1fr); gap: 16px; } }
        @media (min-width: 1024px) { .media-grid { grid-template-columns: repeat(5, 1fr); } }

        .media-card {
            background: var(--surface); border-radius: var(--radius-md); overflow: hidden;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            transition: transform 0.2s, box-shadow 0.2s; cursor: pointer; display: flex; flex-direction: column;
        }
        .media-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: var(--brand); }
        .card-thumb { aspect-ratio: 1/1; background: var(--surface-2); display: flex; align-items: center; justify-content: center; color: var(--muted); position: relative; }
        .card-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .fallback-thumb { width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--fallback-bg, var(--surface-2)); color: var(--text); }
        .fallback-thumb i { font-size: 36px; margin-bottom: 8px; opacity: 0.7; }
        .fallback-thumb span { font-size: 12px; font-weight: 500; }
        .media-type-badge {
            position: absolute; top: 6px; left: 6px; background: rgba(15,23,32,0.65); backdrop-filter: blur(4px);
            color: var(--white); padding: 4px 8px; border-radius: 999px; font-size: 10px; font-weight: 600;
            display: flex; align-items: center; gap: 4px; z-index: 2;
        }
        .play-overlay {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.3); opacity: 0; transition: opacity 0.2s; z-index: 1;
        }
        .media-card:hover .play-overlay { opacity: 1; }
        .play-overlay i { font-size: 48px; color: var(--white); text-shadow: 0 2px 4px rgba(0,0,0,0.5); }
        .duration-badge {
            position: absolute; bottom: 6px; right: 6px; background: rgba(15,23,32,0.65); backdrop-filter: blur(4px);
            color: var(--white); padding: 2px 6px; border-radius: 999px; font-size: 10px; font-weight: 500; z-index: 2;
        }
        .card-body { padding: 10px 10px 12px; flex: 1; }
        .card-title { font-weight: 700; font-size: 14px; margin-bottom: 4px; color: var(--text); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .card-meta { font-size: 11px; color: var(--muted); display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap; }
        .card-meta i { margin-right: 2px; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0; background: var(--overlay-dim); backdrop-filter: blur(6px);
            z-index: 9999; align-items: center; justify-content: center; padding: 16px;
        }
        .modal {
            background: var(--surface); border-radius: var(--radius-lg); max-width: 500px; width: 100%;
            max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-lg); border: 1px solid var(--line);
        }
        .modal-header { padding: 16px 16px 8px; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .modal-header h3 { font-size: 18px; font-weight: 800; color: var(--text); }
        .modal-close { background: none; border: none; font-size: 26px; cursor: pointer; color: var(--muted); line-height: 1; }
        .modal-close:hover { color: var(--danger); }
        .modal-body { padding: 0 16px 16px; }
        .modal-media { margin-bottom: 12px; border-radius: var(--radius-md); overflow: hidden; background: #000; }
        .modal-media img { width: 100%; display: block; }
        .modal-media video { width: 100%; max-height: 300px; display: block; }
        .modal-media audio { width: 100%; }
        .modal-meta { font-size: 13px; color: var(--muted); margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 8px 16px; }
        .modal-description { margin-bottom: 20px; line-height: 1.6; color: var(--text-secondary); }
        .modal-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .modal-actions .btn { flex: 1; }
        .document-preview { display: flex; flex-direction: column; align-items: center; padding: 20px; background: var(--surface-2); border-radius: var(--radius-md); text-align: center; }
        .document-preview i { font-size: 48px; color: var(--brand); margin-bottom: 12px; }

        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--brand);
            color: var(--white); padding: 12px 24px; border-radius: 999px; font-weight: 600;
            box-shadow: var(--shadow-lg); z-index: 10000; opacity: 0; transition: opacity 0.3s; pointer-events: none;
        }
        .toast.show { opacity: 1; }

        /* Share panel */
        .share-panel { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--line); }
        .share-title { font-size: 13px; font-weight: 700; margin-bottom: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .share-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .share-btn {
            flex: 1; min-width: 70px; display: flex; flex-direction: column; align-items: center; gap: 4px;
            padding: 10px 5px; background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--radius-sm);
            color: var(--text); font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;
        }
        .share-btn i { font-size: 20px; }
        .share-btn:hover { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); }
        .share-btn.whatsapp:hover { background: #dcf8c6; border-color: #25D366; color: #075E54; }
        .share-btn.copy:hover { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }

        @media (max-width: 768px) {
            .category-select-wrapper { max-width: none; }
        }
    </style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<?php app_shell_open($user, $unreadCount, $mainNavigation, $sidebarNavigation); ?>

<div class="page-header">
    <h1 class="page-title"><i class="fas fa-photo-video"></i> Resource Library</h1>
    <span class="page-subtitle">Browse images, videos, audio, and documents shared by your teachers</span>
</div>
    </div>

<div class="section-card">
    <!-- Category dropdown - replaces the old type-tabs + category-pills rows.
         Groups the fixed media types with the admin-managed categories
         (Mihadhara, Mawaidha, Adhkaar, etc.) into one selectable list. -->
    <div class="category-select-wrapper">
        <select class="category-select" id="categoryFilterSelect">
            <option value="" <?= ($activeType === 'all' && $activeCategory === 0) ? 'selected' : '' ?>>📚 All Media</option>
            <optgroup label="Media Type">
                <option value="type:image" <?= $activeType === 'image' ? 'selected' : '' ?>>🖼️ Images</option>
                <option value="type:video" <?= $activeType === 'video' ? 'selected' : '' ?>>🎬 Videos</option>
                <option value="type:audio" <?= $activeType === 'audio' ? 'selected' : '' ?>>🎧 Audio</option>
                <option value="type:document" <?= $activeType === 'document' ? 'selected' : '' ?>>📄 Documents</option>
            </optgroup>
            <?php if (!empty($categories)): ?>
                <optgroup label="Categories">
                    <?php foreach ($categories as $cat): ?>
                        <option value="cat:<?= (int)$cat['id'] ?>" <?= $activeCategory === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['icon'] ?? '📁') ?> <?= e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select>
    </div>

    <!-- Search -->
    <div class="search-wrapper" style="margin-top: 14px;">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="searchInput" class="search-input" placeholder="Search by title or description..." value="<?= e($searchTerm) ?>">
    </div>
</div>

<div class="section-card">
    <!-- Media Grid -->
    <div class="media-grid" id="mediaGrid">
        <?php if (empty($mediaItems)): ?>
            <div class="empty-state" style="grid-column: 1/-1;">
                <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
                <h3>No resources found</h3>
                <p>Try adjusting your filters or search term.</p>
            </div>
        <?php else: ?>
            <?php foreach ($mediaItems as $media): 
                $fallbackColor = getFallbackColor($media['media_type']);
                $ytId = !empty($media['video_url']) ? extractYoutubeId($media['video_url']) : null;
            ?>
                <div class="media-card" data-id="<?= $media['id'] ?>" data-type="<?= $media['media_type'] ?>" data-title="<?= e($media['title']) ?>" data-description="<?= e($media['description'] ?? '') ?>" data-file="<?= e($media['file_path'] ?? '') ?>" data-video-url="<?= e($media['video_url'] ?? '') ?>" data-size="<?= formatSize($media['file_size']) ?>" data-duration="<?= $media['duration'] ? formatDuration($media['duration']) : '' ?>" data-views="<?= $media['view_count'] ?>" data-downloads="<?= $media['download_count'] ?>">
                    <div class="card-thumb">
                        <?php if ($media['thumbnail_path']): ?>
                            <img src="<?= e($media['thumbnail_path']) ?>" alt="<?= e($media['title']) ?>" loading="lazy">
                        <?php elseif ($media['media_type'] === 'image'): ?>
                            <img src="<?= e($media['file_path']) ?>" alt="<?= e($media['title']) ?>" loading="lazy">
                        <?php elseif ($ytId): ?>
                            <img src="https://img.youtube.com/vi/<?= e($ytId) ?>/hqdefault.jpg" alt="<?= e($media['title']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="fallback-thumb" style="background: <?= $fallbackColor ?>;">
                                <i class="fas <?= getFileIcon($media['media_type']) ?>"></i>
                                <span><?= getTypeLabel($media['media_type']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($media['media_type'] === 'video' || $media['media_type'] === 'audio'): ?>
                            <div class="play-overlay">
                                <i class="fas fa-play-circle"></i>
                            </div>
                        <?php endif; ?>
                        <span class="media-type-badge">
                            <i class="fas <?= getFileIcon($media['media_type']) ?>"></i>
                            <?= getTypeLabel($media['media_type']) ?>
                        </span>
                        <?php if ($media['media_type'] !== 'image' && $media['media_type'] !== 'document' && $media['duration']): ?>
                            <span class="duration-badge"><?= formatDuration($media['duration']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="card-title"><?= e($media['title']) ?></div>
                        <div class="card-meta">
                            <?php if ($media['file_size']): ?>
                                <span><i class="far fa-hdd"></i> <?= formatSize($media['file_size']) ?></span>
                            <?php endif; ?>
                            <span><i class="far fa-eye"></i> <?= $media['view_count'] ?></span>
                            <span><i class="fas fa-download"></i> <?= $media['download_count'] ?></span>
                        </div>
                        <?php if (!empty($media['categories'])): ?>
                            <div class="card-meta"><i class="far fa-folder"></i> <?= e($media['categories']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php app_shell_close(); ?>

<!-- Modal Preview -->
<div class="modal-overlay" id="mediaModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modalTitle"></h3>
            <button class="modal-close" id="closeModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <div class="modal-media" id="modalMedia"></div>
            <div class="modal-meta" id="modalMeta"></div>
            <div class="modal-description" id="modalDescription"></div>
            <div class="modal-actions">
                <button class="btn" id="modalDownloadBtn"><i class="fas fa-download"></i> Download</button>
                <button class="btn btn-secondary" id="modalShareBtn"><i class="fas fa-share-alt"></i> Share</button>
            </div>
            <div class="share-panel" id="sharePanel" style="display: none;">
                <div class="share-title">Share via</div>
                <div class="share-buttons">
                    <button class="share-btn whatsapp" data-share="whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp</button>
                    <button class="share-btn" data-share="status"><i class="fas fa-circle"></i> Status</button>
                    <button class="share-btn" data-share="tiktok"><i class="fab fa-tiktok"></i> TikTok</button>
                    <button class="share-btn copy" data-share="copy"><i class="fas fa-link"></i> Copy Link</button>
                    <button class="share-btn" data-share="native"><i class="fas fa-ellipsis-h"></i> More</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
(function() {
    // Media modal and sharing
    const modal = document.getElementById('mediaModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalMedia = document.getElementById('modalMedia');
    const modalMeta = document.getElementById('modalMeta');
    const modalDescription = document.getElementById('modalDescription');
    const downloadBtn = document.getElementById('modalDownloadBtn');
    const shareBtn = document.getElementById('modalShareBtn');
    const sharePanel = document.getElementById('sharePanel');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const toast = document.getElementById('toast');
    const searchInput = document.getElementById('searchInput');

    let currentMediaId = 0;
    let currentFileUrl = '';
    let currentVideoUrl = '';
    let currentTitle = '';
    let currentDescription = '';
    let currentMediaType = '';
    let viewCounted = false; // prevent double counting from play event

    function getSuggestedDownloadName(fileUrl, fallbackTitle = 'download') {
        const sanitized = (fallbackTitle || 'download').trim();
        const cleanName = sanitized.replace(/[\\/:*?"<>|]+/g, '').replace(/\s+/g, ' ').trim();
        const extension = (fileUrl.match(/\.([a-z0-9]+)(?:\?.*)?$/i) || [])[1];
        if (!cleanName) return extension ? `download.${extension}` : 'download';
        return extension ? `${cleanName}.${extension}` : cleanName;
    }

    function triggerDownload(fileUrl, suggestedName = 'download') {
        if (!fileUrl) return;

        const link = document.createElement('a');
        link.href = fileUrl;
        link.rel = 'noopener';
        link.target = '_self';
        link.download = suggestedName;

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function showToast(msg, isError = false) {
        toast.textContent = msg;
        toast.style.background = isError ? '#b91c1c' : '#0E4A34';
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    async function incrementView(mediaId) {
        try {
            await fetch('multimedia.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=increment_view&media_id=' + mediaId
            });
        } catch (err) {
            console.error('View increment error:', err);
        }
    }

    function toEmbedUrl(url) {
        if (!url) return null;
        const yt = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
        if (yt) return 'https://www.youtube.com/embed/' + yt[1];
        const vimeo = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        if (vimeo) return 'https://player.vimeo.com/video/' + vimeo[1];
        return url; // already an embed URL from another host
    }

    function openModal(card) {
        const id = card.dataset.id;
        const type = card.dataset.type;
        const title = card.dataset.title;
        const desc = card.dataset.description || '';
        const file = card.dataset.file;
        const videoUrl = card.dataset.videoUrl || '';
        const size = card.dataset.size || 'Unknown';
        const duration = card.dataset.duration || '';
        const views = card.dataset.views || '0';
        const downloads = card.dataset.downloads || '0';

        currentMediaId = id;
        currentFileUrl = file;
        currentVideoUrl = videoUrl;
        currentTitle = title;
        currentDescription = desc;
        currentMediaType = type;
        viewCounted = false;

        modalTitle.textContent = title;
        modalDescription.textContent = desc || 'No description available.';

        let metaHtml = `<span><i class="far fa-eye"></i> ${views} views</span>`;
        if (!videoUrl) metaHtml += `<span><i class="fas fa-download"></i> ${downloads} downloads</span>`;
        if (size && !videoUrl) metaHtml += `<span><i class="far fa-hdd"></i> ${size}</span>`;
        if (duration && type !== 'image' && type !== 'document') metaHtml += `<span><i class="far fa-clock"></i> ${duration}</span>`;
        modalMeta.innerHTML = metaHtml;

        // Update the download button's label/behaviour for this item -
        // a YouTube/Vimeo-sourced video has no local file to download,
        // so the button instead opens the video on its original site.
        if (videoUrl) {
            downloadBtn.innerHTML = '<i class="fas fa-external-link-alt"></i> Watch on YouTube';
        } else {
            downloadBtn.innerHTML = '<i class="fas fa-download"></i> Download';
        }

        if (type === 'image') {
            modalMedia.innerHTML = `<img src="${file}" alt="${title}" style="width:100%;">`;
        } else if (type === 'video' && videoUrl) {
            const embed = toEmbedUrl(videoUrl);
            modalMedia.innerHTML = `<div style="position:relative;width:100%;aspect-ratio:16/9;"><iframe src="${embed}" style="position:absolute;inset:0;width:100%;height:100%;border:0;" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
            // Cross-origin embeds can't reliably report a "play" event, so
            // count the view as soon as the video is opened instead.
            incrementView(id);
            viewCounted = true;
        } else if (type === 'video') {
            modalMedia.innerHTML = `<video id="modalVideo" controls style="width:100%;"><source src="${file}" type="video/mp4">Your browser does not support the video tag.</video>`;
            const video = document.getElementById('modalVideo');
            if (video) {
                video.addEventListener('play', () => {
                    if (!viewCounted) {
                        incrementView(currentMediaId);
                        viewCounted = true;
                    }
                }, { once: true });
            }
        } else if (type === 'audio') {
            modalMedia.innerHTML = `
                <div class="document-preview">
                    <i class="fas fa-headphones fa-3x"></i>
                    <p style="margin:12px 0;"><strong>${title}</strong></p>
                    <audio id="modalAudio" controls style="width:100%; margin-top:8px;"><source src="${file}" type="audio/mpeg">Your browser does not support the audio tag.</audio>
                </div>
            `;
            const audio = document.getElementById('modalAudio');
            if (audio) {
                audio.addEventListener('play', () => {
                    if (!viewCounted) {
                        incrementView(currentMediaId);
                        viewCounted = true;
                    }
                }, { once: true });
            }
        } else {
            const ext = file.split('.').pop().toUpperCase();
            modalMedia.innerHTML = `
                <div class="document-preview">
                    <i class="fas fa-file-alt fa-4x"></i>
                    <p style="margin:12px 0;"><strong>${title}</strong></p>
                    <p style="color:var(--muted);">${ext} Document (${size})</p>
                    <p style="color:var(--muted);margin-top:8px;">This file type cannot be previewed. Please download to view.</p>
                </div>
            `;
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        sharePanel.style.display = 'none';

        // Count view on modal open (only if not a video/audio or if we want to count open regardless)
        // For images/documents, count immediately; for video/audio, we'll count on play
        if (type === 'image' || type === 'document') {
            incrementView(id);
            viewCounted = true;
        }
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        modalMedia.innerHTML = '';
        sharePanel.style.display = 'none';
        viewCounted = false;
    }

    function getShareText() {
        const link = currentFileUrl ? `${window.location.origin}${currentFileUrl}` : currentVideoUrl;
        return `${currentTitle}\n\n${currentDescription}\n\nView this resource: ${link}`;
    }

    function getShareUrl() {
        return currentFileUrl ? `${window.location.origin}${currentFileUrl}` : currentVideoUrl;
    }

    async function handleShare(method) {
        const shareText = getShareText();
        const shareUrl = getShareUrl();
        const title = currentTitle;
        const fileUrl = currentFileUrl;

        if (currentMediaType === 'image') {
            try {
                const response = await fetch(fileUrl);
                const blob = await response.blob();
                const file = new File([blob], title + '.jpg', { type: blob.type });
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file], title: title, text: currentDescription || title });
                    showToast('Shared successfully!');
                    return;
                }
            } catch (e) { console.log('Image file sharing not supported'); }
        }

        switch (method) {
            case 'whatsapp':
                window.open(`https://wa.me/?text=${encodeURIComponent(shareText)}`, '_blank');
                break;
            case 'status':
            case 'tiktok':
            case 'native':
                if (navigator.share) {
                    try {
                        await navigator.share({ title: title, text: shareText, url: shareUrl });
                        showToast('Shared successfully!');
                    } catch (e) { if (e.name !== 'AbortError') showToast('Sharing cancelled or failed', true); }
                } else {
                    navigator.clipboard?.writeText(shareText).then(() => showToast('Link copied!')).catch(() => showToast('Unable to copy', true));
                }
                break;
            case 'copy':
                navigator.clipboard?.writeText(shareText).then(() => showToast('Link copied!')).catch(() => {
                    const textarea = document.createElement('textarea');
                    textarea.value = shareText;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    showToast('Link copied!');
                });
                break;
        }
    }

    document.querySelectorAll('.media-card').forEach(card => {
        card.addEventListener('click', (e) => { if (!e.target.closest('a')) openModal(card); });
    });

    closeModalBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    downloadBtn.addEventListener('click', () => {
        if (!currentMediaId) return;
        if (currentVideoUrl) {
            // Nothing to download for a YouTube/Vimeo-sourced video -
            // open it on its original site instead.
            window.open(currentVideoUrl, '_blank', 'noopener');
            return;
        }
        fetch('multimedia.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=track_download&media_id=' + currentMediaId
        }).then(() => showToast('Download started!')).catch(() => {});
        triggerDownload(currentFileUrl, getSuggestedDownloadName(currentFileUrl, currentTitle));
    });

    shareBtn.addEventListener('click', () => {
        sharePanel.style.display = sharePanel.style.display === 'none' ? 'block' : 'none';
    });

    document.querySelectorAll('[data-share]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            handleShare(btn.dataset.share);
            sharePanel.style.display = 'none';
        });
    });

    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            const url = new URL(window.location.href);
            url.searchParams.set('search', searchInput.value);
            window.location.href = url.toString();
        }
    });

    // Category/type dropdown - replaces the old tab + pill rows. Value is
    // "type:video", "cat:5", or "" for All Media; navigates while keeping
    // any active search term.
    const categoryFilterSelect = document.getElementById('categoryFilterSelect');
    if (categoryFilterSelect) {
        categoryFilterSelect.addEventListener('change', () => {
            const url = new URL(window.location.href);
            const value = categoryFilterSelect.value;
            url.searchParams.delete('type');
            url.searchParams.delete('category');
            if (value.startsWith('type:')) {
                url.searchParams.set('type', value.slice(5));
            } else if (value.startsWith('cat:')) {
                url.searchParams.set('category', value.slice(4));
            }
            window.location.href = url.toString();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
    });
})();
</script>
</body>
</html>