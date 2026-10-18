<?php
declare(strict_types=1);
session_start();

// ============================================================================
// DATABASE + HELPERS
// ============================================================================

$databaseConfigFile = __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

if (!file_exists($databaseConfigFile)) {
    if (!is_dir(__DIR__ . '/config')) {
        mkdir(__DIR__ . '/config', 0777, true);
    }
    $configContent = <<<'PHP'
<?php
declare(strict_types=1);
function db(): ?PDO {
    static $connection = null;
    if ($connection === null) {
        try {
            $dsn = "mysql:host=localhost;port=3307;dbname=myislam;charset=utf8mb4";
            $connection = new PDO($dsn, 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    return $connection;
}
PHP;
    file_put_contents($databaseConfigFile, $configContent);
}
require_once $databaseConfigFile;

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function normalizeUploadPath(?string $path): ?string {
    if (empty($path)) return null;
    if (preg_match('~^(https?:)?//~i', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

// ============================================================================
// AUTHENTICATION
// ============================================================================

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    $conn = db();
    if (!$conn) throw new Exception("Database connection failed");
} catch (Throwable $e) {
    error_log("Database error: " . $e->getMessage());
    die("System error. Unable to connect to database. Please try again later.");
}

// ============================================================================
// USER
// ============================================================================

$dbUser = null;
try {
    $stmt = $conn->prepare("
        SELECT id, username, full_name, email, profile_image, role, is_active, created_at
        FROM users WHERE id = ? AND is_active = 1 LIMIT 1
    ");
    $stmt->execute([$userId]);
    $dbUser = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    error_log("library.php user error: " . $e->getMessage());
}

if (!$dbUser) {
    session_destroy();
    header('Location: login.php?error=account_disabled');
    exit;
}

$user = [
    'name'    => $dbUser['username'] ?? $dbUser['full_name'] ?? 'User',
    'email'   => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role'    => $dbUser['role'] ?? 'user',
];

// ============================================================================
// SEARCH / FILTER / CATEGORY
// ============================================================================

$search       = trim((string)($_GET['q'] ?? ''));
$categorySlug = trim((string)($_GET['category'] ?? ''));

$books    = [];
$categories = [];

// ============================================================================
// LOAD BOOKS
// ============================================================================

$tableReady = false;
try {
    $chk = $conn->query("SHOW TABLES LIKE 'library_books'");
    $tableReady = (bool)$chk->fetchColumn();
} catch (PDOException $e) {
    $tableReady = false;
}

if ($tableReady) {
    try {
        // Categories
        $catStmt = $conn->query("
            SELECT DISTINCT category
            FROM library_books
            WHERE status = 'published' AND category IS NOT NULL AND category <> ''
            ORDER BY category ASC
        ");
        foreach ($catStmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $categories[] = (string)$c;
        }

        // Books
        $where = ["status = 'published'"];
        $params = [];

        if ($search !== '') {
            $where[] = "(title LIKE ? OR author LIKE ? OR description LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($categorySlug !== '') {
            $where[] = "category = ?";
            $params[] = $categorySlug;
        }

        $sql = "
            SELECT id, title, author, description, cover_image, file_path, external_url,
                   category, language, pages, published_year, created_at
            FROM library_books
            WHERE " . implode(' AND ', $where) . "
            ORDER BY title ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("library.php books error: " . $e->getMessage());
    }
}

// Fallback sample books if table missing or empty
if (!$books) {
    $books = [
        ['id' => 1, 'title' => 'The Sealed Nectar', 'author' => 'Safi-ur-Rahman al-Mubarakpuri', 'description' => 'Award-winning biography of the Prophet Muhammad ﷺ.', 'cover_image' => null, 'file_path' => null, 'external_url' => null, 'category' => 'Seerah', 'language' => 'English', 'pages' => 600, 'published_year' => 1976, 'created_at' => null],
        ['id' => 2, 'title' => 'Riyad as-Salihin', 'author' => 'Imam an-Nawawi', 'description' => 'The Gardens of the Righteous — a timeless collection of hadith.', 'cover_image' => null, 'file_path' => null, 'external_url' => null, 'category' => 'Hadith', 'language' => 'English', 'pages' => 800, 'published_year' => 1250, 'created_at' => null],
        ['id' => 3, 'title' => 'Tafsir Ibn Kathir (Abridged)', 'author' => 'Ibn Kathir', 'description' => 'Classic commentary on the Qur\'an, abridged in 10 volumes.', 'cover_image' => null, 'file_path' => null, 'external_url' => null, 'category' => 'Tafsir', 'language' => 'English', 'pages' => 4000, 'published_year' => 1370, 'created_at' => null],
        ['id' => 4, 'title' => 'The Fundamentals of Tawheed', 'author' => 'Dr. Abu Ameenah Bilal Philips', 'description' => 'Islamic Monotheism — a clear explanation of the oneness of Allah.', 'cover_image' => null, 'file_path' => null, 'external_url' => null, 'category' => 'Aqeedah', 'language' => 'English', 'pages' => 220, 'published_year' => 1994, 'created_at' => null],
        ['id' => 5, 'title' => 'Fiqh us-Sunnah', 'author' => 'Sayyid Sabiq', 'description' => 'Comprehensive guide to Islamic jurisprudence.', 'cover_image' => null, 'file_path' => null, 'external_url' => null, 'category' => 'Fiqh', 'language' => 'English', 'pages' => 1500, 'published_year' => 1969, 'created_at' => null],
        ['id' => 6, 'title' => 'Fortress of the Muslim', 'author' => 'Sa\'id bin Ali bin Wahf Al-Qahtani', 'description' => 'Hisn al-Muslim — authentic duas for every occasion.', 'cover_image' => null, 'file_path' => null, 'external_url' => null, 'category' => 'Dua', 'language' => 'English', 'pages' => 150, 'published_year' => 1999, 'created_at' => null],
    ];
    if ($categorySlug !== '') {
        $books = array_values(array_filter($books, fn($b) => ($b['category'] ?? '') === $categorySlug));
    }
    if ($search !== '') {
        $books = array_values(array_filter($books, function($b) use ($search) {
            $hay = strtolower(($b['title'] ?? '') . ' ' . ($b['author'] ?? '') . ' ' . ($b['description'] ?? ''));
            return str_contains($hay, strtolower($search));
        }));
    }
}

// Cover tile gradients (used when no cover image)
$tileGradients = [
    'linear-gradient(135deg,#0E4A34,#3E7A5E)',
    'linear-gradient(135deg,#1a9aaf,#0e6d80)',
    'linear-gradient(135deg,#9C7A2E,#c9a85e)',
    'linear-gradient(135deg,#3E6E91,#6FA0C4)',
    'linear-gradient(135deg,#6C5CC0,#9285DB)',
    'linear-gradient(135deg,#b45309,#f59e0b)',
];
$tileIcons = ['📖', '🕌', '📚', '🌙', '✨', '📘'];

// Unread notifications
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// Navigation
$mainNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false],
    ['name' => 'Courses', 'link' => 'courses.php', 'active' => false],
    ['name' => 'Library', 'link' => 'library.php', 'active' => true],
];

$sidebarNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => t('adhkaar'), 'link' => 'adhkaar.php', 'active' => false, 'icon' => '📿'],
    ['name' => t('questions_answers'), 'link' => 'QA.php', 'active' => false, 'icon' => '❓'],
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => 'Courses', 'link' => 'courses.php', 'active' => false, 'icon' => '🎓'],
    ['name' => 'Library', 'link' => 'library.php', 'active' => true, 'icon' => '📖'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo e($_SESSION['language'] ?? 'sw'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="description" content="MyIslam Academy Library — Books & Resources">
<title>Library - MyIslam</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f5f7f5;--surface:#fff;--surface-2:#f9fafb;--surface-3:#eef2f7;
  --line:#e2e5e2;--text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;
  --brand:#0E4A34;--brand-dark:#0A3626;--brand-light:#3E7A5E;--brand-soft:rgba(14,74,52,.08);
  --gold:#9C7A2E;--gold-soft:rgba(156,122,46,.10);
  --accent:#3E6E91;--accent-soft:rgba(62,110,145,.10);
  --danger:#b91c1c;--danger-soft:rgba(185,28,28,.08);
  --header-height:72px;
  --radius-md:16px;--radius-lg:20px;
  --shadow-sm:0 1px 2px 0 rgb(0 0 0 / .05);
  --shadow-md:0 4px 6px -1px rgb(0 0 0 / .08);
  --shadow-lg:0 10px 15px -3px rgb(0 0 0 / .1);
}
body.dark-mode{
  --bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;
  --line:#304150;--text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;
  --brand:#4C9172;--brand-dark:#6bb590;--brand-light:#7fcaa4;--brand-soft:rgba(76,145,114,.16);
  --gold:#C9A85E;--gold-soft:rgba(201,168,94,.16);
  --accent:#6FA0C4;--accent-soft:rgba(111,160,196,.16);
  --danger:#ef4444;--danger-soft:rgba(239,68,68,.12);
  --header-bg:#13202a;
}
body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;padding-bottom:24px}
a{text-decoration:none;color:inherit}
button{font:inherit;border:none;background:none;cursor:pointer}
body::before{content:"";position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:-1;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Ctext x='20' y='150' font-family='Amiri' font-size='120' fill='%230E4A34' fill-opacity='0.025'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");background-repeat:repeat}
body.dark-mode::before{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Ctext x='20' y='150' font-family='Amiri' font-size='120' fill='%234C9172' fill-opacity='0.05'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E")}

/* Header */
.app-header{position:sticky;top:0;z-index:1000;height:var(--header-height);background:var(--header-bg,#fff);border-bottom:1px solid var(--line);box-shadow:var(--shadow-sm);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;gap:18px}
.header-left,.header-right{display:flex;align-items:center;gap:12px}
.header-left{flex:1;min-width:0}
.header-top-nav{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:thin;padding-bottom:2px}
.header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-size:13px;font-weight:700;color:var(--text-secondary);transition:all .2s}
.header-top-link:hover,.header-top-link.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
.icon-btn,.lang-btn,.brand-box{height:40px;min-width:30px;border-radius:10px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;transition:all .2s}
.icon-btn{font-size:18px;color:var(--text);position:relative}
.lang-btn{min-width:30px;padding:0 8px;font-weight:600;color:var(--brand)}
.brand-box{font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none;white-space:nowrap}
.profile-area{position:relative}
.profile-btn{width:44px;height:44px;border-radius:50%;overflow:hidden;background:var(--surface);border:2px solid var(--brand);padding:0;display:flex;align-items:center;justify-content:center;color:var(--brand);font-weight:700}
.profile-btn img{width:100%;height:100%;object-fit:cover}
.profile-menu{position:absolute;top:56px;right:0;width:280px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:16px;display:none;box-shadow:var(--shadow-lg);z-index:1001}
.profile-menu.show{display:block}
.profile-menu-top{display:flex;gap:12px;padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid var(--line)}
.profile-menu-top img,.profile-avatar-fallback{width:56px;height:56px;border-radius:50%}
.profile-avatar-fallback{display:inline-flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:700;font-size:16px}
.profile-menu a{display:block;padding:10px 12px;border-radius:10px;font-weight:700;font-size:14px;margin:4px 0}
.profile-menu a:hover{background:var(--brand-soft);color:var(--brand)}
.notification-badge{position:absolute;top:-4px;right:-4px;background:var(--danger);color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--surface)}

/* Sidebar */
.sidebar-overlay{display:none;position:fixed;top:var(--header-height);left:0;width:100%;height:calc(100vh - var(--header-height));background:rgba(15,23,32,.45);z-index:1100}
.sidebar-overlay.show{display:block}
.sidebar{position:fixed;top:var(--header-height);left:-100%;width:min(320px,85vw);height:calc(100vh - var(--header-height));background:var(--surface);border-right:1px solid var(--line);padding:24px 20px;z-index:1200;transition:left .3s ease;overflow-y:auto}
.sidebar.open{left:0}
.sidebar-title{font-size:12px;color:var(--muted);text-transform:uppercase;margin-bottom:20px;font-weight:700;letter-spacing:1px;padding:0 8px}
.subject-list{display:grid;gap:8px}
.subject-link{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--radius-md);background:var(--surface-2);border:1px solid var(--line);font-size:14px;font-weight:500;color:var(--text);transition:all .2s}
.subject-link:hover{background:var(--brand-soft);border-color:var(--brand);transform:translateX(4px)}
.subject-link.active{background:var(--brand-soft);color:var(--brand);border-color:var(--brand)}
.sidebar-toggle-btn{position:fixed;top:var(--header-height);left:0;z-index:1300;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;background:var(--surface);border:1px solid var(--line);border-left:none;border-top:none;border-radius:0 0 8px 0;font-size:16px;color:var(--text);box-shadow:var(--shadow-sm);padding:0}
.sidebar-toggle-btn:hover{background:var(--brand-soft);color:var(--brand)}

/* Main */
.main-content{padding:16px;max-width:1400px;margin:0 auto;width:100%}

/* Library */
.lib{max-width:1320px;margin:0 auto}
.lib-hero{
    position:relative;
    border-radius:8px;
    overflow:hidden;
    min-height:170px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:28px 20px;
    color:#fff;
    margin-bottom:16px;
    background:linear-gradient(rgba(10,40,30,.40),rgba(10,40,30,.62)),url('/uploads/library-bg.jpg') center/cover no-repeat,#0E4A34;
}
.lib-hero::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,0) 40%,rgba(0,0,0,.25) 100%);pointer-events:none}
.lib-hero > *{position:relative;z-index:1}
.lib-hero .lib-tag{
    display:inline-block;
    padding:4px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.3);
    font-size:11px;
    font-weight:800;
    letter-spacing:1.2px;
    text-transform:uppercase;
    color:#D9BC72;
    margin-bottom:10px;
    text-shadow:0 1px 3px rgba(0,0,0,.4);
}
.lib-hero h1{
    margin:0 0 8px;
    font-size:28px;
    font-weight:900;
    color:#fff;
    letter-spacing:.4px;
    text-shadow:0 2px 6px rgba(0,0,0,.55);
}
.lib-hero p{
    margin:0;
    font-size:13px;
    max-width:560px;
    color:rgba(255,255,255,.92);
    text-shadow:0 1px 3px rgba(0,0,0,.4);
}

/* Search + filter bar */
.lib-toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:16px;
    padding:10px 12px;
    background:var(--surface);
    border:1px solid var(--line);
    border-radius:8px;
}
.lib-search{
    flex:1;
    min-width:200px;
    display:flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    background:var(--surface-2);
    border:1px solid var(--line);
    border-radius:8px;
    transition:border-color .15s;
}
.lib-search:focus-within{border-color:var(--brand)}
.lib-search span{font-size:16px;color:var(--muted)}
.lib-search input{
    flex:1;
    border:none;
    background:transparent;
    outline:none;
    font-size:13px;
    color:var(--text);
    min-width:0;
}
.lib-cat-select{
    padding:8px 12px;
    border:1px solid var(--line);
    border-radius:8px;
    background:var(--surface-2);
    color:var(--text);
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    min-width:160px;
}
.lib-cat-select:hover{border-color:var(--brand)}

.lib-chip-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.lib-chip{
    display:inline-block;
    padding:6px 12px;
    border-radius:999px;
    background:var(--surface);
    border:1px solid var(--line);
    color:var(--text-secondary);
    font-size:12px;
    font-weight:700;
    text-decoration:none;
    transition:all .15s;
    white-space:nowrap;
}
.lib-chip:hover{border-color:var(--brand);color:var(--brand)}
.lib-chip.active{background:var(--brand);border-color:var(--brand);color:#fff}

/* Grid */
.lib-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
    gap:14px;
}

/* Book card */
.lib-book{
    background:var(--surface);
    border:1px solid var(--line);
    border-radius:10px;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    transition:all .18s;
    text-decoration:none;
    color:inherit;
}
.lib-book:hover{
    border-color:var(--brand);
    transform:translateY(-2px);
    box-shadow:0 6px 16px -4px rgba(0,0,0,.12);
}
.lib-cover{
    position:relative;
    aspect-ratio:3/4;
    background:var(--surface-3);
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:52px;
}
.lib-cover img{
    width:100%;
    height:100%;
    object-fit:cover;
}
.lib-cover .lib-cover-icon{
    filter:drop-shadow(0 2px 6px rgba(0,0,0,.25));
}
.lib-cover-badge{
    position:absolute;
    top:8px;
    right:8px;
    padding:3px 9px;
    border-radius:999px;
    font-size:10px;
    font-weight:800;
    letter-spacing:.3px;
    text-transform:uppercase;
    background:rgba(217,188,114,.95);
    color:#1a1a1a;
    z-index:1;
}
.lib-cover-badge.new{background:rgba(127,202,164,.95);color:#0A3626}

.lib-info{
    padding:12px 14px 14px;
    flex:1;
    display:flex;
    flex-direction:column;
    gap:4px;
}
.lib-title{
    font-size:14px;
    font-weight:800;
    color:var(--text);
    line-height:1.3;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
}
.lib-author{
    font-size:11.5px;
    color:var(--muted);
    font-weight:600;
    line-height:1.3;
    display:-webkit-box;
    -webkit-line-clamp:1;
    -webkit-box-orient:vertical;
    overflow:hidden;
}
.lib-desc{
    font-size:11.5px;
    color:var(--text-secondary);
    line-height:1.5;
    margin-top:4px;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
    min-height:34px;
}
.lib-meta{
    display:flex;
    flex-wrap:wrap;
    gap:4px 10px;
    font-size:10.5px;
    color:var(--muted);
    font-weight:600;
    margin-top:auto;
    padding-top:6px;
    border-top:1px dashed var(--line);
}
.lib-meta span{white-space:nowrap}
.lib-read{
    display:block;
    margin-top:10px;
    padding:8px 12px;
    text-align:center;
    background:var(--brand);
    color:#fff;
    font-size:12px;
    font-weight:800;
    border-radius:8px;
    transition:filter .15s;
}
.lib-read:hover{filter:brightness(1.08)}

.lib-empty{
    padding:40px 20px;
    text-align:center;
    background:var(--surface);
    border:1px dashed var(--line);
    border-radius:10px;
    color:var(--muted);
    font-size:13px;
    line-height:1.6;
}
.lib-empty .lib-empty-icon{font-size:42px;display:block;margin-bottom:10px;opacity:.55}

.lib-count{
    font-size:12px;
    font-weight:700;
    color:var(--muted);
    margin-bottom:12px;
}
.lib-count strong{color:var(--text)}

/* Footer */
.lib-footer{text-align:center;font-size:12px;padding:16px;margin-top:20px;border:1px solid var(--line);border-radius:8px;background:var(--surface);font-weight:700}
.lib-footer .brand-text{color:var(--brand)}
.lib-footer .gold-text{color:var(--gold)}

@media (max-width:768px){
    :root{--header-height:64px}
    .main-content{padding:10px}
    .app-header{padding:10px 12px}
    .header-top-nav{display:none}
    .lib-hero{min-height:140px;padding:20px 14px}
    .lib-hero h1{font-size:22px}
    .lib-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
    .lib-cover{font-size:42px}
    .lib-title{font-size:12.5px}
    .lib-author{font-size:10.5px}
    .lib-desc{font-size:10.5px}
    .lib-cat-select{min-width:130px;flex:1}
}
@media (max-width:480px){
    .lib-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .lib-info{padding:10px 10px 12px}
    .lib-read{padding:7px 8px;font-size:11px}
    .lib-meta{font-size:9.5px;gap:3px 6px}
}
</style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<a href="#mainContent" class="skip-link" style="position:absolute;left:-9999px;top:0;background:var(--brand);color:#fff;padding:10px 16px;border-radius:0 0 8px 0;z-index:2000;font-weight:700">Skip to main content</a>

<header class="app-header">
  <div class="header-left">
    <a href="user_dashboard.php" class="brand-box" style="display:flex;align-items:center;gap:4px">
      <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px">
      <span style="font-size:22px;font-weight:1000;color:var(--brand)">MyIslam</span>
    </a>
    <nav class="header-top-nav" aria-label="Main navigation">
      <?php foreach ($mainNavigation as $navItem): ?>
        <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>"><?php echo e($navItem['name']); ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="header-right">
    <button class="icon-btn" id="themeToggle" aria-label="Toggle dark mode">🌙</button>
    <button class="lang-btn" id="langToggle">EN</button>
    <a href="notification.php" class="icon-btn" style="position:relative" aria-label="Notifications">
      🔔
      <?php if ($unreadCount > 0): ?><span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span><?php endif; ?>
    </a>
    <div class="profile-area" id="profileArea">
      <button class="profile-btn" id="profileToggle" type="button" aria-expanded="false">
        <?php if (!empty($user['picture'])): ?><img src="<?php echo e($user['picture']); ?>" alt="">
        <?php else: ?><?php echo e(initials((string)$user['name'])); ?><?php endif; ?>
      </button>
      <div class="profile-menu" id="profileMenu">
        <div class="profile-menu-top">
          <?php if (!empty($user['picture'])): ?><img src="<?php echo e($user['picture']); ?>" alt="">
          <?php else: ?><div class="profile-avatar-fallback"><?php echo e(initials((string)$user['name'])); ?></div><?php endif; ?>
          <div>
            <h4><?php echo e((string)$user['name']); ?></h4>
            <p><?php echo e((string)$user['email']); ?></p>
          </div>
        </div>
        <a href="profile.php">👤 Profile</a>
        <a href="settings.php">⚙️ Settings</a>
        <a href="logout.php">🚪 Logout</a>
      </div>
    </div>
  </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" aria-expanded="false" aria-controls="sidebar">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-title">📚 Navigation</div>
  <nav class="subject-list">
    <?php foreach ($sidebarNavigation as $item): ?>
      <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
        <span><?php echo e($item['icon']); ?></span><span><?php echo e($item['name']); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<main class="main-content" id="mainContent">
<div class="lib">

  <!-- Hero with library background image -->
  <section class="lib-hero">
    <span class="lib-tag">📖 MyIslam Library</span>
    <h1>Books &amp; Resources</h1>
    <p>Tafsir, hadith, fiqh, seerah — a curated collection of authentic Islamic literature, free to read.</p>
  </section>

  <!-- Search + filter -->
  <form method="get" action="library.php" class="lib-toolbar">
    <label class="lib-search">
      <span>🔍</span>
      <input type="search" name="q" value="<?php echo e($search); ?>" placeholder="Search title, author or description…" aria-label="Search books">
    </label>
    <select name="category" class="lib-cat-select" onchange="this.form.submit()" aria-label="Filter by category">
      <option value="">All categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?php echo e($cat); ?>" <?php echo $categorySlug === $cat ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="lib-chip active" style="cursor:pointer;border:0;">Search</button>
    <?php if ($search !== '' || $categorySlug !== ''): ?>
      <a href="library.php" class="lib-chip">Clear</a>
    <?php endif; ?>
  </form>

  <!-- Category chips -->
  <?php if (!empty($categories)): ?>
    <div class="lib-chip-row">
      <a href="library.php<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>"
         class="lib-chip <?php echo $categorySlug === '' ? 'active' : ''; ?>">All</a>
      <?php foreach ($categories as $cat):
        $url = 'library.php?category=' . urlencode($cat) . ($search !== '' ? '&q=' . urlencode($search) : '');
      ?>
        <a href="<?php echo e($url); ?>"
           class="lib-chip <?php echo $categorySlug === $cat ? 'active' : ''; ?>"><?php echo e($cat); ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Result count -->
  <?php if (!empty($books)): ?>
    <p class="lib-count">
      <strong><?php echo count($books); ?></strong> book<?php echo count($books) === 1 ? '' : 's'; ?>
      <?php if ($search !== ''): ?>matching "<strong><?php echo e($search); ?></strong>"<?php endif; ?>
      <?php if ($categorySlug !== ''): ?>in <strong><?php echo e($categorySlug); ?></strong><?php endif; ?>
    </p>
  <?php endif; ?>

  <!-- Grid -->
  <?php if (empty($books)): ?>
    <div class="lib-empty">
      <span class="lib-empty-icon">📚</span>
      <?php if ($search !== '' || $categorySlug !== ''): ?>
        No books match your search.<br>
        <a href="library.php" style="color:var(--brand);font-weight:700;">Clear filters →</a>
      <?php else: ?>
        The library is being prepared.<br>
        Books and resources will appear here soon.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="lib-grid">
      <?php foreach ($books as $i => $book):
          $cover = normalizeUploadPath($book['cover_image'] ?? null);
          $title = (string)($book['title'] ?? 'Untitled');
          $author = trim((string)($book['author'] ?? ''));
          $desc = trim((string)($book['description'] ?? ''));
          $cat = trim((string)($book['category'] ?? ''));
          $lang = trim((string)($book['language'] ?? ''));
          $pages = (int)($book['pages'] ?? 0);
          $year = (int)($book['published_year'] ?? 0);
          $readUrl = '#';

          if (!empty($book['external_url'])) {
              $readUrl = (string)$book['external_url'];
          } elseif (!empty($book['file_path'])) {
              $readUrl = normalizeUploadPath((string)$book['file_path']) ?? '#';
          }

          $grad = $tileGradients[$i % count($tileGradients)];
          $icon = $tileIcons[$i % count($tileIcons)];
      ?>
        <a class="lib-book" href="<?php echo e($readUrl); ?>">
          <div class="lib-cover" style="background: <?php echo $cover ? 'var(--surface-3)' : $grad; ?>;">
            <?php if ($cover): ?>
              <img src="<?php echo e($cover); ?>" alt="<?php echo e($title); ?>">
            <?php else: ?>
              <span class="lib-cover-icon"><?php echo e($icon); ?></span>
            <?php endif; ?>
            <?php if ($year > 0): ?>
              <span class="lib-cover-badge"><?php echo $year; ?></span>
            <?php endif; ?>
          </div>
          <div class="lib-info">
            <div class="lib-title"><?php echo e($title); ?></div>
            <?php if ($author !== ''): ?>
              <div class="lib-author"><?php echo e($author); ?></div>
            <?php endif; ?>
            <?php if ($desc !== ''): ?>
              <div class="lib-desc"><?php echo e($desc); ?></div>
            <?php endif; ?>
            <div class="lib-meta">
              <?php if ($cat !== ''): ?><span>📂 <?php echo e($cat); ?></span><?php endif; ?>
              <?php if ($lang !== ''): ?><span>🌐 <?php echo e($lang); ?></span><?php endif; ?>
              <?php if ($pages > 0): ?><span>📄 <?php echo $pages; ?> pp</span><?php endif; ?>
            </div>
            <?php if ($readUrl !== '#'): ?>
              <span class="lib-read">📖 Read now →</span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="lib-footer">
    <div class="brand-text">MyIslam</div>
    <div class="gold-text">#Manhaj Salaf</div>
  </div>
</div>
</main>

<script>
(function(){
  const body = document.body;
  const menuToggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  const themeToggle = document.getElementById('themeToggle');
  const langToggle = document.getElementById('langToggle');
  const profileToggle = document.getElementById('profileToggle');
  const profileMenu = document.getElementById('profileMenu');
  const profileArea = document.getElementById('profileArea');

  function openSidebar(){sidebar.classList.add('open');sidebarOverlay.classList.add('show');body.style.overflow='hidden';menuToggle.setAttribute('aria-expanded','true')}
  function closeSidebar(){sidebar.classList.remove('open');sidebarOverlay.classList.remove('show');body.style.overflow='';menuToggle.setAttribute('aria-expanded','false')}

  if(menuToggle)menuToggle.addEventListener('click',()=>sidebar.classList.contains('open')?closeSidebar():openSidebar());
  if(sidebarOverlay)sidebarOverlay.addEventListener('click',closeSidebar);

  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
      if(sidebar.classList.contains('open'))closeSidebar();
      if(profileMenu.classList.contains('show')){profileMenu.classList.remove('show');profileToggle.setAttribute('aria-expanded','false')}
    }
  });

  if(localStorage.getItem('theme')==='dark'){body.classList.add('dark-mode');themeToggle.textContent='☀'}
  themeToggle.addEventListener('click',()=>{
    body.classList.toggle('dark-mode');
    const dark=body.classList.contains('dark-mode');
    themeToggle.textContent=dark?'☀':'🌙';
    localStorage.setItem('theme',dark?'dark':'light');
  });

  let currentLang=localStorage.getItem('language')||'<?php echo $_SESSION['language'] ?? "sw"; ?>';
  langToggle.textContent=currentLang==='sw'?'SW':'EN';
  langToggle.addEventListener('click',async()=>{
    currentLang=currentLang==='sw'?'en':'sw';
    langToggle.textContent=currentLang==='sw'?'SW':'EN';
    localStorage.setItem('language',currentLang);
    try{await fetch('ajax/switch_language.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({language:currentLang})});location.reload();}catch(e){}
  });

  if(profileToggle&&profileMenu){
    profileToggle.addEventListener('click',function(e){
      e.stopPropagation();
      const open=profileMenu.classList.contains('show');
      profileMenu.classList.toggle('show',!open);
      profileToggle.setAttribute('aria-expanded',String(!open));
    });
    document.addEventListener('click',function(e){
      if(!profileArea.contains(e.target)){profileMenu.classList.remove('show');profileToggle.setAttribute('aria-expanded','false')}
    });
  }
})();
</script>
</body>
</html>