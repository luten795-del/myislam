<?php
declare(strict_types=1);
session_start();
// Set default language if not set
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}
// ============================================================================
// DATABASE CONFIGURATION
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
            $host = 'localhost';
            $port = '3307';
            $dbname = 'myislam';
            $username = 'root';
            $password = '';

            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $connection = new PDO($dsn, $username, $password, $options);
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

// ============================================================================
// HELPERS
// ============================================================================

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

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

function formatDateValue(?string $date, string $format = 'M d, Y'): string
{
    if (empty($date)) {
        return '';
    }

    $time = strtotime($date);
    return $time ? date($format, $time) : '';
}

// ============================================================================
// AUTH
// ============================================================================

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// ============================================================================
// DATABASE
// ============================================================================

try {
    $conn = db();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
} catch (Throwable $e) {
    error_log('Database error: ' . $e->getMessage());
    die('System error. Unable to connect to database.');
}

// ============================================================================
// DATA FUNCTIONS
// ============================================================================

function getCurrentUser(PDO $conn, int $userId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, username, full_name, email, profile_image, role, is_active, created_at
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log('Error getting user: ' . $e->getMessage());
        return null;
    }
}

function getAllSubjects(PDO $conn): array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, name, slug, icon, sort_order
            FROM subjects
            WHERE is_active = 1
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Error getting all subjects: ' . $e->getMessage());
        return [];
    }
}

function getSubjectBySlugOrId(PDO $conn, ?string $slug, ?int $id): ?array
{
    try {
        if (!empty($slug)) {
            $stmt = $conn->prepare("
                SELECT id, name, slug, description, icon, image_path, sort_order, is_active, sheikh_name, markaz
                FROM subjects
                WHERE slug = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$slug]);
            $subject = $stmt->fetch();
            if ($subject) {
                return $subject;
            }
        }

        if (!empty($id)) {
            $stmt = $conn->prepare("
                SELECT id, name, slug, description, icon, image_path, sort_order, is_active, sheikh_name, markaz
                FROM subjects
                WHERE id = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $subject = $stmt->fetch();
            if ($subject) {
                return $subject;
            }
        }

        return null;
    } catch (PDOException $e) {
        error_log('Error getting subject: ' . $e->getMessage());
        return null;
    }
}

function getSubjectCertificateInfo(PDO $conn, int $userId, int $subjectId, int $subjectProgress): array
{
    try {
        $stmt = $conn->prepare("
            SELECT certificate_code, status, issued_at
            FROM certificates
            WHERE user_id = ?
              AND subject_id = ?
              AND certification_type = 'subject'
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$userId, $subjectId]);
        $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

        $earned = (bool)$certificate;
        $progress = max(0, min(100, $subjectProgress));
        $remaining = max(0, 100 - $progress);

        return [
            'earned' => $earned,
            'progress' => $earned ? 100 : $progress,
            'remaining' => $earned ? 0 : $remaining,
            'certificate_code' => $certificate['certificate_code'] ?? null,
            'issued_at' => $certificate['issued_at'] ?? null,
        ];
    } catch (Throwable $e) {
        return [
            'earned' => false,
            'progress' => max(0, min(100, $subjectProgress)),
            'remaining' => max(0, 100 - $subjectProgress),
            'certificate_code' => null,
            'issued_at' => null,
        ];
    }
}

function getSubjectCategoriesForFilter(PDO $conn, int $subjectId): array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, name, icon, image_path, sort_order, lock_previous
            FROM categories
            WHERE subject_id = ? AND is_active = 1
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute([$subjectId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Error getting categories for filter: ' . $e->getMessage());
        return [];
    }
}

function isCategoryUnlocked(PDO $conn, int $userId, int $categoryId, int $subjectId): bool {
    try {
        $stmt = $conn->prepare("
            SELECT id, lock_previous, sort_order
            FROM categories
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$categoryId]);
        $currentCat = $stmt->fetch();
        
        if (!$currentCat || (int)$currentCat['lock_previous'] !== 1) {
            return true;
        }
        
        $stmt = $conn->prepare("
            SELECT id, name
            FROM categories
            WHERE subject_id = ? AND is_active = 1 
              AND sort_order < ?
            ORDER BY sort_order DESC
            LIMIT 1
        ");
        $stmt->execute([$subjectId, $currentCat['sort_order']]);
        $prevCat = $stmt->fetch();
        
        if (!$prevCat) {
            return true;
        }
        
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN ucp.is_completed = 1 THEN 1 ELSE 0 END) as completed
            FROM contents c
            LEFT JOIN user_content_progress ucp ON ucp.content_id = c.id AND ucp.user_id = ?
            WHERE c.category_id = ? AND c.status = 'published'
        ");
        $stmt->execute([$userId, $prevCat['id']]);
        $result = $stmt->fetch();
        
        $total = (int)($result['total'] ?? 0);
        $completed = (int)($result['completed'] ?? 0);
        
        return ($total === 0 || $completed >= $total);
        
    } catch (PDOException $e) {
        error_log("isCategoryUnlocked error: " . $e->getMessage());
        return true;
    }
}

function getSubjectContents(PDO $conn, int $userId, int $subjectId): array
{
    try {
        $stmt = $conn->prepare("
            SELECT
                c.id,
                c.subject_id,
                c.category_id,
                c.title,
                c.slug,
                c.short_description,
                c.status,
                c.sort_order,
                c.published_at,
                cat.name AS category_name,
                COALESCE(ucp.progress_percent, 0) AS progress_percent,
                COALESCE(ucp.is_started, 0) AS is_started,
                COALESCE(ucp.is_completed, 0) AS is_completed,
                ucp.last_accessed_at
            FROM contents c
            LEFT JOIN categories cat ON cat.id = c.category_id
            LEFT JOIN user_content_progress ucp
                ON ucp.content_id = c.id
                AND ucp.user_id = ?
            WHERE c.subject_id = ?
              AND c.status = 'published'
            ORDER BY c.sort_order ASC, c.published_at DESC, c.title ASC
        ");
        $stmt->execute([$userId, $subjectId]);
        $results = $stmt->fetchAll();
        
        foreach ($results as &$row) {
            $row['progress_percent'] = (int)round((float)($row['progress_percent'] ?? 0));
        }
        
        return $results;
    } catch (PDOException $e) {
        error_log('Error getting subject contents: ' . $e->getMessage());
        return [];
    }
}

function getSubjectStats(PDO $conn, int $userId, int $subjectId): array
{
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM categories
            WHERE subject_id = ? AND is_active = 1
        ");
        $stmt->execute([$subjectId]);
        $totalCategories = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM contents
            WHERE subject_id = ? AND status = 'published'
        ");
        $stmt->execute([$subjectId]);
        $totalContents = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM user_content_progress ucp
            INNER JOIN contents c ON c.id = ucp.content_id
            WHERE ucp.user_id = ? AND c.subject_id = ? AND ucp.is_completed = 1
        ");
        $stmt->execute([$userId, $subjectId]);
        $completedContents = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM user_content_progress ucp
            INNER JOIN contents c ON c.id = ucp.content_id
            WHERE ucp.user_id = ? AND c.subject_id = ?
        ");
        $stmt->execute([$userId, $subjectId]);
        $touchedContents = (int)$stmt->fetchColumn();

        $progress = $totalContents > 0 ? (int)round(($completedContents / $totalContents) * 100) : 0;

        return [
            'total_categories' => $totalCategories,
            'total_contents' => $totalContents,
            'touched_contents' => $touchedContents,
            'completed_contents' => $completedContents,
            'progress' => $progress,
        ];
    } catch (PDOException $e) {
        error_log('Error getting subject stats: ' . $e->getMessage());
        return [
            'total_categories' => 0,
            'total_contents' => 0,
            'touched_contents' => 0,
            'completed_contents' => 0,
            'progress' => 0,
        ];
    }
}

// ============================================================================
// LOAD REQUEST
// ============================================================================

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// ============================================================================
// LOAD USER
// ============================================================================
try {
    $dbUser = getCurrentUser($conn, $userId);

    if (!$dbUser) {
        session_destroy();
        header('Location: login.php?error=account_disabled');
        exit;
    }
} catch (Throwable $e) {
    error_log("Error fetching user: " . $e->getMessage());
    die("Unable to load user data. Please try again later.");
}

// User display name: username, fallback to full_name, then email
$userDisplayName = $dbUser['username'] ?? $dbUser['full_name'] ?? 'User';
if (empty($userDisplayName) && !empty($dbUser['email'])) {
    $emailParts = explode('@', $dbUser['email']);
    $userDisplayName = $emailParts[0] ?? 'User';
}

$user = [
    'name' => $userDisplayName,
    'email' => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role' => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime($dbUser['created_at'])) : 'recently',
];

// ============================================================================
// LOAD SUBJECT DATA
// ============================================================================

$subject = getSubjectBySlugOrId($conn, $slug, $id);
if (!$subject) {
    http_response_code(404);
}

$allSubjects = getAllSubjects($conn);
$subjectStats = $subject ? getSubjectStats($conn, $userId, (int)$subject['id']) : [];
$categoriesForFilter = $subject ? getSubjectCategoriesForFilter($conn, (int)$subject['id']) : [];
$subjectContents = $subject ? getSubjectContents($conn, $userId, (int)$subject['id']) : [];

$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist yet – ignore
}

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
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];

$totalCategories = (int)($subjectStats['total_categories'] ?? 0);
$totalContents = (int)($subjectStats['total_contents'] ?? 0);
$completedContents = (int)($subjectStats['completed_contents'] ?? 0);
$subjectProgress = (int)($subjectStats['progress'] ?? 0);

$certificateInfo = $subject ? getSubjectCertificateInfo($conn, $userId, (int)$subject['id'], $subjectProgress) : [
    'earned' => false,
    'code' => '',
    'view_url' => '',
    'progress' => 0,
    'remaining' => 100,
    'label' => 'Progress to Certificate',
    'message' => 'Complete this subject and pass all certification levels to unlock your certificate.',
];
$certificateProgress = (int)($certificateInfo['progress'] ?? 0);
$certificateRemaining = (int)($certificateInfo['remaining'] ?? 100);
$certificateEarned = !empty($certificateInfo['earned']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="MyIslam Subject Page">
    <title><?php echo $subject ? e($subject['name']) . ' - MyIslam' : 'Subject Not Found - MyIslam'; ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        /* ===== Light Mode Variables ===== */
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
            --overlay-dim: rgba(0, 0, 0, 0.6);
            --glass-bg-strong: rgba(24, 33, 43, 0.92);

            background: var(--bg);
        }

        /* ===== Base Body ===== */
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            padding-bottom: 24px;
            position: relative;
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

        a {
            text-decoration: none;
            color: inherit;
        }

        button,
        input,
        select {
            font: inherit;
        }

        a:focus-visible,
        button:focus-visible,
        .filter-pill:focus-visible,
        .subject-link:focus-visible,
        .cert-preview-link:focus-visible {
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

        .skip-link:focus {
            left: 0;
        }

        /* ===== HEADER ===== */
        .app-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            height: var(--header-height);
            background: var(--header-bg);
            border-bottom: 1px solid var(--line);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 24px;
            gap: 18px;
        }

        .header-left,
        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-left {
            flex: 1;
            min-width: 0;
        }

        .header-top-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            overflow-x: auto;
            scrollbar-width: thin;
            padding-bottom: 2px;
        }

        .header-top-link {
            height: 42px;
            padding: 0 14px;
            border-radius: 999px;
            background: var(--surface);
            border: 1px solid var(--line);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
            color: var(--text-secondary);
        }

        .header-top-link:hover,
        .header-top-link.active {
            background: var(--brand-soft);
            border-color: var(--brand);
            color: var(--brand);
        }

        .menu-toggle,
        .icon-btn,
        .lang-btn,
        .brand-box {
            height: 40px;
            min-width: 30px;
            border-radius: 10px;
            background: var(--surface);
            border: 1px solid var(--line);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .menu-toggle {
            width: 50px;
        }

        .menu-toggle:hover,
        .icon-btn:hover,
        .lang-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--gold);
            color: var(--gold);
        }

        .profile-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .menu-toggle {
            font-size: 14px;
        }

        .lang-btn {
            min-width: 30px;
            padding: 0 8px;
            font-weight: 600;
            color: var(--brand);
        }

        .brand-box {
            font-size: 22px;
            font-weight: 700;
            color: var(--brand);
            background: transparent;
            border: none;
            white-space: nowrap;
        }

        .icon-btn {
            font-size: 18px;
            color: var(--text);
            position: relative;
        }

        /* ===== PROFILE ===== */
        .profile-area {
            position: relative;
        }

        .profile-btn {
            width: 44px;
            height: 44px;
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
            position: absolute;
            top: 56px;
            right: 0;
            width: 280px;
            background: var(--glass-bg-strong);
            backdrop-filter: blur(12px);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 16px;
            display: none;
            box-shadow: var(--shadow-lg);
            z-index: 1001;
        }

        .profile-menu.show {
            display: block;
        }

        .profile-menu-top {
            display: flex;
            gap: 12px;
            padding-bottom: 10px;
            margin-bottom: 10px;
            border-bottom: 1px solid var(--line);
        }

        .profile-menu-top img,
        .profile-avatar-fallback {
            width: 56px;
            height: 56px;
            border-radius: 50%;
        }

        .profile-avatar-fallback {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--brand-soft);
            color: var(--brand);
            font-weight: 700;
            font-size: 16px;
        }

        .profile-menu-top h4 {
            font-size: 16px;
            margin-bottom: 4px;
            font-weight: bold;
            color: var(--text);
        }

        .profile-menu-top p {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .profile-menu a {
            display: block;
            padding: 10px 12px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            transition: background 0.2s;
            margin: 4px 0;
            color: var(--text);
        }

        .profile-menu a:hover {
            background: var(--brand-soft);
            color: var(--brand);
        }

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

        /* ===== SIDEBAR ===== */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: var(--header-height);
            left: 0;
            width: 100%;
            height: calc(100vh - var(--header-height));
            background: var(--overlay-dim);
            z-index: 1100;
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.show {
            display: block;
        }

        .sidebar {
            position: fixed;
            top: var(--header-height);
            left: -100%;
            width: min(320px, 85vw);
            height: calc(100vh - var(--header-height));
            background: var(--glass-bg-strong);
            backdrop-filter: blur(12px);
            border-right: 1px solid var(--line);
            padding: 24px 20px;
            z-index: 1200;
            transition: left 0.3s ease;
            overflow-y: auto;
        }

        .sidebar.open {
            left: 0;
        }

        .sidebar-title {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            padding: 0 8px;
        }

        .subject-list {
            display: grid;
            gap: 8px;
        }

        .subject-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            background: var(--surface-2);
            border: 1px solid var(--line);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            color: var(--text);
        }

        .subject-link:hover {
            background: var(--brand-soft);
            border-color: var(--brand);
            transform: translateX(4px);
        }

        .subject-link.active {
            background: var(--brand-soft);
            color: var(--brand);
            border-color: var(--brand);
        }

        .subject-progress-small {
            margin-left: auto;
            font-size: 12px;
            font-weight: 700;
            color: var(--brand);
            background: var(--brand-soft);
            padding: 2px 8px;
            border-radius: 999px;
        }

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

        /* ===== MAIN CONTENT ===== */
        .main-content {
            padding: 24px 24px 20px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            overflow-x: hidden;
        }

        .page-stack {
            display: grid;
            gap: 10px;
        }

        /* ===== SECTION CARDS ===== */
        @media (min-width: 768px) {
        .section-plan{
            gap:0px;
            display:grid;
            grid-template-columns: repeat(4, 1fr);
        }}
        .section-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding:10px;
            transition: all 0.2s;
            width: 100%;
            overflow: hidden;
        }

        .section-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .hero-card {
    background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%);
    border: none;
    position: relative;
    padding: 10px;
}

.hero-top {
    display: flex;
    flex-direction:row;
    align-items: center;
    justify-content: center;
    gap: 10px;

}



.hero-icon {
    width: 64px;
    height: 64px;
    border-radius: 10px;
    color: var(--brand);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    border: 1px solid green;
    flex-shrink: 0;
}

.hero-card h1 {
    font-size: 32px;
    margin-bottom: 4px;
    font-weight: 700;
}

.hero-card p,
.hero-description {
    color: var(--text-secondary);
    font-size: 16px;
    max-width: 760px;
    margin: 0;
    line-height: 1.6;
}

.hero-progress {
    position: absolute;
    right: 0px;
    min-height:9px;
    bottom: 10px;
    min-width: 9px;
    padding: 10px;
    border-radius: 12px;
    background: var(--surface);
    ;
    text-align: center;
    box-shadow: var(--shadow-sm);
    z-index: 2;
}

.hero-progress strong {
    display: block;
    font-size: 26px;
    color: var(--brand);
    margin-bottom: 4px;
    line-height: 1.1;
}

.hero-progress span {
    font-size: 12px;
    color: var(--muted);
    font-weight: 700;
}

/* mobile fix */
@media (max-width: 768px) {
    .hero-card {
        padding: 10px;
            border: 1px solid var(--line);
    }

    .hero-top {
        flex-direction: row;
        gap: 10px;
    }

    .hero-title-wrap {
        padding-right: 0;
        width: 100%;
    }

    .hero-progress {
        position: static;
        margin-left: auto;
        margin-top: 8px;
    }

    .hero-card h1 {
        font-size: 24px;
    }

}

        /* ===== STATS CONTAINER ===== */
       .stats-container {
    background:var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 10px;
    margin-top:10px;
}

.stats-toggle {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 4px 4px;
    background: transparent;
    border: none;
    cursor: pointer;
    font: inherit;
    font-weight: 700;
    font-size: 16px;
    color: var(--text);
    text-align: left;
}

.stats-toggle:hover {
    color: var(--brand);
}

.stats-toggle-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--surface);
    border: 1px solid var(--line);
    font-weight: 700;
    font-size: 16px;
    line-height: 1;
    transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease;
    color: var(--brand);
}

/* Rotate the icon when open. The grid's open state is signalled by the
   sibling relationship below; a small rotation reads as "expanded". */
.stats-toggle[aria-expanded="true"] .stats-toggle-icon {
    transform: rotate(45deg);
    background: var(--brand);
    color: #fff;
    border-color: var(--brand);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--line);
}

/* `hidden` on the element already hides it in the DOM; this rule just makes
   the "reveal" behave as a fade rather than an instant pop if you later
   switch from `hidden` to a class-based toggle. */
.stats-grid[hidden] {
    display: none;
}

.stat-item {
    text-align: center;
    padding: 4px 6px;
}

.stat-icon {
    font-size: 24px;
    margin-bottom: 6px;
}

.stat-value {
    font-size: 26px;
    font-weight: 800;
    color: var(--brand);
    line-height: 1.1;
}

.stat-label {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;
}
@media(min-width:768px){
    .type{
        display:grid;
        gap:10px;
        grid-template-columns:1fr 1fr;
    }
}
        /* ===== CATEGORY CARDS ===== */
        .category-card {
            background: var(--surface);
            border: 2px solid var(--gold);
            border-radius: var(--radius-lg);
            padding: 20px;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .category-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--gold);
        }

        .category-card.locked {
            opacity: 0.8;
            border-color: var(--muted);
        }

        .category-card[style*="background-image"]::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.6));
            z-index: 1;
            border-radius: inherit;
            pointer-events: none;
        }

        .category-card[style*="background-image"]>* {
            position: relative;
            z-index: 2;
        }

        .content-top {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 4px;
        }

        .content-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .content-desc {
            font-size: 14px;
            color: var(--muted);
            line-height: 1.6;
        }

        .content-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 8px 0 14px;
        }

        .content-pill {
            padding: 6px 10px;
            border-radius: 10px;
            border: 1px solid var(--line);
            font-size: 11px;
            font-weight: 700;
            background: var(--surface-2);
            color: var(--text-secondary);
        }

        .content-pill.neutral {
            background: var(--surface-3);
            color: var(--muted);
        }

        .progress-bar-wrap {
            height: 10px;
            border-radius: 10px;
            background: var(--surface-3);
            overflow: hidden;
            margin-bottom: 16px;
            border: 1px solid var(--line);
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--brand), var(--brand-light));
            border-radius: 999px;
            transition: width 0.3s ease;
        }

        .content-actions {
            display: flex;
            gap: 5px;
        }

        .content-btn {
            flex: 1;
            text-align: center;
            padding: 8px 10px;
            border-radius: var(--radius-md);
            font-weight: 700;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid var(--line);
        }

        .content-btn.primary {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
        }

        .content-btn.primary:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .content-btn.secondary {
            background: var(--surface-2);
            color: var(--text-secondary);
        }

        .content-btn.secondary:hover {
            background: var(--brand-soft);
            color: var(--brand);
            border-color: var(--brand);
        }

        .content-btn.locked-btn {
            background: var(--surface-3);
            color: var(--muted);
            border-color: var(--danger);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .content-btn.locked-btn:hover {
            transform: none;
            background: var(--surface-3);
        }

        .panel-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text);
        }

        /* ===== CERTIFICATE PANEL ===== */
        .certificate-preview-panel {
            position: relative;
            overflow: hidden;
            border: 2px solid var(--gold);
            border-radius: var(--radius-lg);
            padding: 20px;
            background: linear-gradient(135deg, var(--brand-soft), var(--surface) 54%, #fff7db);
            box-shadow: var(--shadow-md);
            min-height: 160px;
            margin-top: 8px;
        }

        .certificate-preview-panel::before {
            content: "CERTIFICATE";
            position: absolute;
            right: -30px;
            top: 30px;
            font-size: 64px;
            font-weight: 1000;
            letter-spacing: .08em;
            color: rgba(14, 74, 52, .08);
            transform: rotate(14deg);
            filter: blur(1.4px);
            pointer-events: none;
        }

        .certificate-preview-bg {
            position: absolute;
            inset: 14px;
            border: 4px double rgba(201, 154, 46, .55);
            border-radius: 20px;
            filter: blur(1.8px);
            opacity: .55;
            pointer-events: none;
        }

        .certificate-preview-content {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: 1fr 200px;
            gap: 18px;
            align-items: center;
        }

        .cert-preview-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }

        .cert-preview-title {
            font-size: 16px;
            font-weight: 900;
            color: var(--brand);
        }

        .cert-preview-badge {
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
            border: 1px solid var(--line);
        }

        .cert-preview-badge.locked {
            background: var(--surface-3);
            color: var(--muted);
        }

        .cert-preview-badge.progress {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .cert-preview-badge.ready {
            background: var(--gold-soft);
            color: var(--gold);
        }

        .cert-preview-badge.earned {
            background: var(--brand-soft);
            color: var(--brand);
            border-color: var(--brand);
        }

        .cert-preview-progress {
            display: grid;
            gap: 6px;
        }

        .cert-preview-percent {
            display: flex;
            align-items: baseline;
            gap: 5px;
        }

        .cert-preview-percent strong {
            font-size: 28px;
            color: var(--brand);
            line-height: 1;
        }

        .cert-preview-percent span {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }

        .cert-preview-bar {
            height: 8px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--surface-3);
            overflow: hidden;
        }

        .cert-preview-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--brand), var(--brand-light));
            transition: width 0.3s ease;
        }

        .cert-preview-note {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
            line-height: 1.4;
        }

        .cert-preview-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 120px;
        }

        .cert-preview-link {
            text-align: center;
            padding: 10px 14px;
            border-radius: 10px;
            background: var(--brand);
            color: #fff;
            border: 1px solid var(--brand);
            font-size: 12px;
            font-weight: 800;
            transition: all 0.2s;
        }

        .cert-preview-link:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            filter: brightness(1.05);
        }

        .cert-preview-link.secondary {
            background: var(--surface);
            color: var(--brand);
            border-color: var(--line);
        }

        .cert-preview-link.secondary:hover {
            background: var(--brand-soft);
            border-color: var(--brand);
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--muted);
        }

        .empty-state .emoji {
            font-size: 50px;
            margin-bottom: 12px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 900px) {
            .header-top-nav {
                display: none;
            }
        }

        @media (max-width: 768px) {
            :root {
                --header-height: 64px;
            }
            .main-content {
                padding: 16px 12px 20px;
            }
            .section-card {
                padding: 10px;
            }
            .hero-card h1 {
                font-size: 22px;
            }
            .hero-content h1 {
                font-size: 22px;
            }
            .certificate-preview-content {
                grid-template-columns: 1fr;
            }
            .cert-preview-actions {
                flex-direction: row;
                min-width: 0;
            }
            .cert-preview-link {
                flex: 1;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .stat-item {
                padding: 14px 10px;
            }
            .stat-value {
                font-size: 22px;
            }
            .stat-icon {
                font-size: 22px;
            }
         

            .hero-top {
                flex-direction: row;
            }
            .hero-progress {
                flex-direction: row;
                align-items: stretch;
                gap: 10px;
                padding: 10px;
            }
            .hero-progress-left {
                justify-content: center;
            }
            .hero-progress-bar-wrap {
                min-width: 20px;
            }
            .hero-progress-right {
                text-align: center;
            }
            .content-top {
                flex-direction: column;
            }
            .content-actions {
                flex-direction: row;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                gap: 10px;
            }
            .stat-item {
                min-height: 100px;
                padding: 10px 8px;
            }
            .stat-value {
                font-size: 20px;
            }
            .stat-label {
                font-size: 10px;
            }
            .stat-icon {
                font-size: 18px;
                margin-bottom: 6px;
            }
            .category-card {
                padding: 14px;
            }
            .content-title {
                font-size: 16px;
            }
            .cert-preview-percent strong {
                font-size: 22px;
            }
            .cert-preview-badge {
                font-size: 10px;
                padding: 3px 8px;
            }
            .hero-progress-left strong {
                font-size: 22px;
            }
            .hero-icon {
                width: 44px;
                height: 44px;
                font-size: 22px;
            }
        }
    </style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<a href="#mainContent" class="skip-link">Skip to main content</a>

<header class="app-header">
    <div class="header-left">
        <a href="subjects.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
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
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="Notifications">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if (!empty($user['picture'])): ?>
                    <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials($userDisplayName)); ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials($userDisplayName)); ?></div>
                    <?php endif; ?>

                    <div>
                        <h4><?php echo e($userDisplayName); ?></h4>
                        <p><?php echo e($user['email'] ?? ''); ?></p>
                        <p style="font-size: 10px;"><?php echo t('dashboard_member_since'); ?> <?php echo e($user['member_since']); ?></p>
                    </div>
                </div>

                <a href="profile.php">👤 <?php echo t('profile'); ?></a>
                <a href="settings.php">⚙️ <?php echo t('settings'); ?></a>
                <a href="logout.php">🚪 <?php echo t('logout'); ?></a>
            </div>
        </div>
    </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" aria-label="Menu">☰</button>

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
                <div class="sidebar-title" style="margin-top:24px;">📖 Masomo</div>
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
                        <?php if ($isCurrent): ?>
                            <span class="subject-progress-small">sasa</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">
        <div class="page-stack">
            <?php if (!$subject): ?>
                <section class="section-card empty-state">
                    <div class="emoji">📭</div>
                    <h2 style="margin-bottom:8px;">Somo halipo</h2>
                    <p style="margin-bottom:18px;">somo halipo.</p>
                    <a href="subjects.php" class="content-btn primary" style="display:inline-block;width:auto;padding:12px 24px;">Back to Subjects</a>
                </section>
            <?php else: ?>
                <!-- Hero Card - Title on right upper, progress wider below -->
               
                <?php
                $subjectImage = !empty($subject['image_path']) ? $subject['image_path'] : null;
                $hasBgImage = !empty($subjectImage);
                ?>
                  <div class="type">
               <section class="hero-card section-card" style="<?php echo $hasBgImage ? 'background: linear-gradient(135deg, rgba(0,0,0,0.5), rgba(0,0,0,0.7)), url(\'' . e($subjectImage) . '\'); background-size: cover; background-position: center;' : ''; ?>">
                    <div class="hero-top">
                     
                            <div class="hero-icon" style="<?php echo $hasBgImage ? 'background: rgba(255,255,255,0.9); backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.3);' : ''; ?>">
                                <?php echo e($subject['icon'] ?? '📚'); ?>
                            </div>
                            <div class="hero-content">
                                <h1 style="<?php echo $hasBgImage ? 'color: white; text-shadow: 0 1px 3px rgba(0,0,0,0.3);' : ''; ?>"><?php echo e($subject['name'] ?? 'Subject'); ?></h1>
                        </div>
                        <div class="hero-progress" style="<?php echo $hasBgImage ? 'background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.3);' : ''; ?>">
                            <strong style="<?php echo $hasBgImage ? 'color: #7dd3a7;' : ''; ?>"><?php echo $subjectProgress; ?>%</strong>
                            <span style="<?php echo $hasBgImage ? 'color: rgba(255,255,255,0.7);' : ''; ?>"></span>
                        </div>
                    </div>
                </section>

              <!-- Stats Container - collapsible card -->

                <div class="stats-container">
                        <button type="button"
                                class="stats-toggle"
                                id="statsToggle"
                                aria-expanded="false"
                                aria-controls="statsGrid">
                            <span>📊 <?php echo t('STATS') ?: 'Stats'; ?></span>
                            <span class="stats-toggle-icon" id="statsIcon">+</span>
                        </button>

                     <div class="stats-grid" id="statsGrid" hidden>
                        <div class="stat-item">
                            <div class="stat-icon">🗂️</div>
                            <div class="stat-value"><?php echo $totalCategories; ?></div>
                            <div class="stat-label"><?php echo t('categories') ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">📘</div>
                            <div class="stat-value"><?php echo $totalContents; ?></div>
                            <div class="stat-label"><?php echo t('contents') ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">✅</div>
                            <div class="stat-value"><?php echo $completedContents; ?></div>
                            <div class="stat-label"><?php echo t('status4') ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">📊</div>
                            <div class="stat-value"><?php echo $subjectProgress; ?>%</div>
                            <div class="stat-label">Progress</div>
                        </div>
                    </div>
                </div>
            </div>

                <?php if (!empty($subject['sheikh_name']) || !empty($subject['markaz'])): ?>
                <!-- Msomeshaji Card - the instructor (and markaz) assigned to this subject -->
                <div class="section-card" style="display:flex;align-items:center;gap:10px;margin-top:10px;">
                    <div>
                        <?php if (!empty($subject['sheikh_name'])): ?>
                            <div style="font-weight:700;font-size:16px;">Msomeshaji <?php echo e($subject['sheikh_name']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($subject['markaz'])): ?>
                            <div style="font-size:13px;color:var(--muted);"><?php echo e($subject['markaz']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

              <!-- Stats Container - collapsible card -->


                <!-- Categories Section -->
                <section >
                    <?php if (empty($categoriesForFilter)): ?>
                        <div class="section-card empty-state">
                            <div class="emoji">📭</div>
                            <p>Hakuna maudhui katika somo hili</p>
                        </div>
                    <?php else: ?>
                    <div class="section-plan">

                        <?php foreach ($categoriesForFilter as $cat):
                            $catId = (int)$cat['id'];
                            $isUnlocked = isCategoryUnlocked($conn, $userId, $catId, (int)$subject['id']);
                            
                            $stmtCat = $conn->prepare("
                                SELECT c.*, 
                                    COUNT(ct.id) AS total_lessons,
                                    SUM(CASE WHEN ucp.is_completed = 1 THEN 1 ELSE 0 END) AS completed_lessons
                                FROM categories c
                                LEFT JOIN contents ct ON ct.category_id = c.id AND ct.status = 'published'
                                LEFT JOIN user_content_progress ucp ON ucp.content_id = ct.id AND ucp.user_id = ?
                                WHERE c.id = ?
                                GROUP BY c.id
                            ");
                            $stmtCat->execute([$userId, $catId]);
                            $catData = $stmtCat->fetch(PDO::FETCH_ASSOC);
                            
                            $totalLessons = (int)($catData['total_lessons'] ?? 0);
                            $completedLessons = (int)($catData['completed_lessons'] ?? 0);
                            $progress = $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0;
                            $categoryImage = !empty($catData['image_path']) ? $catData['image_path'] : null;
                            $categoryIcon = $catData['icon'] ?? '📁';
                        ?>
                        <article class="category-card <?php echo $isUnlocked ? '' : 'locked'; ?>" 
                            style="<?php echo $categoryImage ? 'background-image: linear-gradient(135deg, rgba(0,0,0,0.4), rgba(0,0,0,0.6)), url(\'' . e($categoryImage) . '\'); background-size: cover; background-position: center; color: white;' : ''; ?>">
                            
                            <div class="content-top">
                                <div>
                                    <div class="content-title" style="<?php echo $categoryImage ? 'color: white;' : ''; ?>">
                                        <?php echo e($catData['name'] ?? $cat['name']); ?>
                                        <?php if (!$isUnlocked): ?>
                                            <span style="font-size:14px; margin-left:8px;">🔒</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                </div>
                            </div>
                            
                            
                            
                            <div class="progress-bar-wrap" style="<?php echo $categoryImage ? 'background: rgba(255,255,255,0.3); border-color: rgba(255,255,255,0.2);' : ''; ?>">
                                <div class="progress-bar" style="width: <?php echo $progress; ?>%;"></div>
                            </div>
                            
                            <div class="content-actions">
                                 <a href="subjects.php" class="content-btn secondary" style="<?php echo $categoryImage ? 'background: rgba(0,0,0,0.6); border-color: rgba(255,255,255,0.3); color: white;' : ''; ?>">
                                    Masomo
                                </a>
                                <?php if ($isUnlocked): ?>
                                    <a href="category.php?cat_id=<?php echo $catId; ?>" class="content-btn primary" style="<?php echo $categoryImage ? 'background: var(--brand);' : ''; ?>">
                                        📂 Fungua
                                    </a>
                                <?php else: ?>
                                    <span class="content-btn locked-btn" style="cursor: not-allowed; opacity: 0.7; <?php echo $categoryImage ? 'background: rgba(0,0,0,0.5);' : ''; ?>">
                                        🔒 Complete previous category first
                                    </span>
                                <?php endif; ?>
                               
                            </div>
                        </article>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Certificate Panel -->
                <div class="certificate-preview-panel" aria-label="Certificate progress preview">
                    <div class="certificate-preview-bg"></div>
                    <div class="certificate-preview-content">
                        <div>
                            <div class="cert-preview-top">
                                <div class="cert-preview-title">Certificate Of Completion</div>
                                <div class="cert-preview-badge <?php echo $certificateEarned ? 'earned' : 'locked'; ?>">
                                    <?php echo $certificateEarned ? 'Earned' : 'Locked'; ?>
                                </div>
                            </div>
                            <div class="cert-preview-progress">
                                <div class="cert-preview-percent">
                                    <strong><?php echo $certificateProgress; ?>%</strong>
                                    <span>to certificate</span>
                                </div>
                                <div class="cert-preview-bar">
                                    <div class="cert-preview-fill" style="width: <?php echo max(0, min(100, $certificateProgress)); ?>%;"></div>
                                </div>
                                <p class="cert-preview-note">
                                    <?php if ($certificateEarned): ?>
                                        ✅ Certificate unlocked. You can view and print it now.
                                    <?php else: ?>
                                        📚 <?php echo $certificateRemaining; ?>% remaining. Complete lessons and pass certification levels to earn it.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="cert-preview-actions">
                            <?php if ($certificateEarned && !empty($certificateInfo['certificate_code'])): ?>
                                <a class="cert-preview-link" href="certificate_view.php?code=<?php echo urlencode((string)$certificateInfo['certificate_code']); ?>">
                                    🎓 View Certificate
                                </a>
                            <?php else: ?>
                                <a class="cert-preview-link" href="certification_exams.php?subject=<?php echo (int)$subject['id']; ?>">
                                    📝 Certification Path
                                </a>
                            <?php endif; ?>
                            <a class="cert-preview-link secondary" href="my_certificates.php">
                                My Certificates
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
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

    // Handle image loading errors
    document.querySelectorAll('img').forEach(img => {
        img.addEventListener('error', function() {
            this.style.display = 'none';
        });
    });
})();
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('statsToggle');
    const grid   = document.getElementById('statsGrid');
    const icon   = document.getElementById('statsIcon');
    if (!toggle || !grid || !icon) return;

    toggle.addEventListener('click', function () {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';
        const nextOpen = !isOpen;

        toggle.setAttribute('aria-expanded', String(nextOpen));

        if (nextOpen) {
            grid.removeAttribute('hidden');
            icon.textContent = '−';   // en-dash / minus sign
        } else {
            grid.setAttribute('hidden', '');
            icon.textContent = '+';
        }
    });
});
</script>

</body>
</html>