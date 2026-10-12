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

function getSubjectsWithStats(PDO $conn, int $userId, ?string $type = null): array
{
    try {
        $whereType = '';
        $params = [$userId];

        if ($type !== null && trim($type) !== '') {
            $whereType = " AND s.type = ?";
            $params[] = trim($type);
        }

        $stmt = $conn->prepare("
            SELECT
                s.id,
                s.name,
                s.type,
                s.slug,
                s.description,
                s.icon,
                s.image_path,
                s.sort_order,
                s.is_active,
                COUNT(DISTINCT cat.id) AS total_categories,
                COUNT(DISTINCT c.id) AS total_contents,
                COUNT(DISTINCT ucp.content_id) AS touched_contents,
                COUNT(DISTINCT CASE WHEN ucp.is_completed = 1 THEN ucp.content_id END) AS completed_contents,
                MAX(ucp.last_accessed_at) AS last_accessed_at
            FROM subjects s
            LEFT JOIN categories cat
                ON cat.subject_id = s.id
                AND cat.is_active = 1
            LEFT JOIN contents c
                ON c.subject_id = s.id
                AND c.status = 'published'
            LEFT JOIN user_content_progress ucp
                ON ucp.subject_id = s.id
                AND ucp.user_id = ?

            WHERE s.is_active = 1
            $whereType

            GROUP BY
                s.id,
                s.name,
                s.type,
                s.slug,
                s.description,
                s.icon,
                s.image_path,
                s.sort_order,
                s.is_active

            ORDER BY s.sort_order ASC, s.name ASC
        ");

        $stmt->execute($params);
        $subjects = $stmt->fetchAll();

        foreach ($subjects as &$subject) {
            $total = (int)($subject['total_contents'] ?? 0);
            $completed = (int)($subject['completed_contents'] ?? 0);

            $subject['progress'] = $total > 0
                ? (int)round(($completed / $total) * 100)
                : 0;
        }

        unset($subject);

        return $subjects;

    } catch (PDOException $e) {
        error_log('Error getting subjects: ' . $e->getMessage());
        return [];
    }
}

function getOverallSubjectSummary(array $subjects): array
{
    $totalSubjects = count($subjects);
    $totalCategories = 0;
    $totalContents = 0;
    $averageProgress = 0;
    $startedSubjects = 0;
    $completedSubjects = 0;

    foreach ($subjects as $subject) {
        $totalCategories += (int)($subject['total_categories'] ?? 0);
        $totalContents += (int)($subject['total_contents'] ?? 0);
        $averageProgress += (int)($subject['progress'] ?? 0);

        if ((int)($subject['touched_contents'] ?? 0) > 0) {
            $startedSubjects++;
        }
        
        if ((int)($subject['progress'] ?? 0) >= 100) {
            $completedSubjects++;
        }
    }

    if ($totalSubjects > 0) {
        $averageProgress = (int)round($averageProgress / $totalSubjects);
    }

    return [
        'total_subjects' => $totalSubjects,
        'total_categories' => $totalCategories,
        'total_contents' => $totalContents,
        'average_progress' => $averageProgress,
        'started_subjects' => $startedSubjects,
        'completed_subjects' => $completedSubjects,
        'not_started_subjects' => max(0, $totalSubjects - $startedSubjects),
    ];
}

function getGeneralCertificateInfo(PDO $conn, int $userId, int $averageProgress): array
{
    try {
        $stmt = $conn->prepare("
            SELECT certificate_code, status, issued_at
            FROM certificates
            WHERE user_id = ?
              AND certification_type = 'general'
              AND subject_id IS NULL
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

        $earned = (bool)$certificate;
        $progress = max(0, min(100, (int)$averageProgress));
        $remaining = max(0, 100 - $progress);

        return [
            'earned' => $earned,
            'progress' => $earned ? 100 : $progress,
            'remaining' => $earned ? 0 : $remaining,
            'certificate_code' => $certificate['certificate_code'] ?? null,
            'issued_at' => $certificate['issued_at'] ?? null,
        ];
    } catch (Throwable $e) {
        $progress = max(0, min(100, (int)$averageProgress));
        return [
            'earned' => false,
            'progress' => $progress,
            'remaining' => max(0, 100 - $progress),
            'certificate_code' => null,
            'issued_at' => null,
        ];
    }
}

function getSubjectsFullyCompletedCount(array $subjects): int
{
    $count = 0;
    foreach ($subjects as $subject) {
        if ((int)($subject['progress'] ?? 0) >= 100) {
            $count++;
        }
    }
    return $count;
}

// ============================================================================
// LOAD DATA
// ============================================================================

$user = getCurrentUser($conn, $userId);

if (!$user) {
    session_destroy();
    header('Location: login.php?error=account_disabled');
    exit;
}

$selectedType = trim((string)($_GET['type'] ?? ''));

$dbSubjects = getSubjectsWithStats(
    $conn,
    $userId,
    $selectedType !== '' ? $selectedType : null
);
$summary = getOverallSubjectSummary($dbSubjects);
$generalCertificateInfo = getGeneralCertificateInfo($conn, $userId, (int)($summary['average_progress'] ?? 0));
$fullyCompletedSubjects = getSubjectsFullyCompletedCount($dbSubjects);

// User display name: username, fallback to full_name, then email
$userDisplayName = $user['username'] ?? $user['full_name'] ?? 'User';
if (empty($userDisplayName) && !empty($user['email'])) {
    $emailParts = explode('@', $user['email']);
    $userDisplayName = $emailParts[0] ?? 'User';
}

$generalCertProgress = (int)($generalCertificateInfo['progress'] ?? 0);
$generalCertEarned = !empty($generalCertificateInfo['earned']);

if ($generalCertEarned) {
    $generalCertStatusText = 'Earned';
    $generalCertStatusClass = 'earned';
} elseif ($generalCertProgress >= 100) {
    $generalCertStatusText = 'Ready';
    $generalCertStatusClass = 'ready';
} elseif ($generalCertProgress > 0) {
    $generalCertStatusText = 'In Progress';
    $generalCertStatusClass = 'progress';
} else {
    $generalCertStatusText = 'Locked';
    $generalCertStatusClass = 'locked';
}

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
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => true],
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
    ['name' => t('adhkaar'), 'link' => 'adhkaar.php', 'active' => false, 'icon' => '📿'],
    ['name' => t('questions_answers'), 'link' => 'QA.php', 'active' => false, 'icon' => '❓'],
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
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
    <meta name="description" content="MyIslam Subjects">
    <title>Subjects - MyIslam</title>
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
        flex: 0 0 auto;
        position: relative;
        z-index: 1002;
        visibility: visible !important;
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
        min-width: 0;
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
        overflow-y: scroll;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        scrollbar-gutter: stable;
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
        display: inline-flex !important;
        visibility: visible !important;
        opacity: 1 !important;
        z-index: 1300;
    }

    #themeToggle,
    #langToggle,
    #menuToggle {
        visibility: visible !important;
        opacity: 1 !important;
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
    @media(min-width:768px){
        .page-stack{
            grid-template-columns:1fr 1fr;
            margin-bottom:10px;
        }
    }

    /* ===== SECTION CARDS ===== */
    .section-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        padding: clamp(10px, 5vw, 10px);
        transition: all 0.2s;
        width: 100%;
        overflow: hidden;
        gap:10px;
        display:grid;
        grid-template-columns:1fr 1fr;
        margin-bottom:10px;
        margin-top:0px;
    }

    .section-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    /* ===== HERO CARD ===== */
    .hero-card {
        background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%);
        border: 1px solid var(--line);
        position: relative;
        overflow: hidden;
        background-size: cover;
        background-position: center;
        gap:20%;
        background-image: url('/uploads/subjects-page-hero.png');
        margin-bottom:0px;
    }

    

    .hero-card::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(14, 74, 52, 0.4) 0%, rgba(10, 54, 38, 0.3) 100%);
        z-index: 0;
    }

    .hero-card .hero-top,
    .hero-card .hero-title-wrap,
    .hero-card h1,
    .hero-card p,
    .hero-card .hero-progress {
        position: relative;
        z-index: 1;
    }

    .hero-card h1 {
        color: var(--white);
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.6);
    }

    .hero-card p {
        color: rgba(255, 255, 255, 0.92);
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
    }

    .hero-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 5px;
        flex-wrap: wrap;
        height:60px;
    }

    .hero-title-wrap {
        display: flex;
        align-items: flex-start;
        gap: 5px;
    }

    .hero-icon {
       
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 30px;
        border: 1px solid var(--line);
        flex-shrink: 0;
    }

    .hero-progress {
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.3);
        text-align: center;
        color: var(--white);
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
        width:80px;
        height: 60px;
        margin-right:2px;
}

    .hero-progress strong {
        display: block;
        font-size: 26px;
        color: var(--white);
        margin-bottom: 4px;
        line-height: 1.5;
    }

    .hero-progress span {
        font-size: 15px;
        opacity: 1;
        font-weight: 600;
    }

    /* ===== STATS CONTAINER - One Card with Green Transparent Background ===== */
    

    .stats-container:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
    }

    .stat-item {
        text-align: center;
        padding: 18px 14px;
        border-radius: var(--radius-sm);
        background: var(--surface);
        border: 1px solid var(--line);
        transition: all 0.2s;
    }

    .stat-item:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-md);
        border-color: var(--brand);
    }

    .stat-icon {
        font-size: 28px;
        margin-bottom: 10px;
        line-height: 1;
    }

    .stat-value {
        font-size: 28px;
        font-weight: bold;
        color: var(--brand);
        line-height: 1.1;
        margin-bottom: 6px;
    }

    .stat-label {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-secondary);
        line-height: 1.4;
    }

    .stats-toggle {
    width: 100%;
    min-height: 50px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 0 10px;

    background: var(--surface);
    color: var(--brand);

    border: 1px solid var(--line);
    border-radius: 10px;

    cursor: pointer;
    font-size: 16px;
    font-weight: 700;
}

.stats-grid {
    display: none;
    margin-top: 10px;
}

.stats-grid.show {
    display: grid;
}
    /* ===== FILTER ===== */
    .filter-row {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 10px;
        margin-top: 0px;
    }

    .search-wrap {
        position: relative;
    }

    .search-input,
    .filter-select {
        width: 100%;
        height: 52px;
        border-radius: 10px;
        border: 1px solid var(--line);
        background: var(--surface);
        color: var(--text);
        padding: 0 16px;
        outline: none;
        transition: all 0.2s;
    }

    .search-input {
        padding-left: 48px;
    }

    .search-input:focus,
    .filter-select:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 4px var(--brand-soft);
    }

    .search-icon {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        font-size: 18px;
    }

    .search-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
    }

    .search-top h3 {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
    }

    .search-count {
        font-size: 13px;
        color: var(--muted);
        font-weight: 600;
    }

    .filter-toggle {
    width: 100%;
    min-height: 50px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 0 16px;

    background: var(--surface);
    color: var(--brand);

    border: 1px solid var(--line);
    border-radius: 10px;

    cursor: pointer;
    font-size: 16px;
    font-weight: 700;
}

.filter-content {
    display: none;
    margin-top: 16px;
}

.filter-content.show {
    display: block;
}

    /* ===== SUBJECTS GRID ===== */
    .subjects-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }

    /* ===== SUBJECT CARD - Gold Border ===== */
    .subject-card {
        background: var(--surface);
        border: 2px solid var(--gold);
        border-radius: var(--radius-lg);
        padding: 20px;
        transition: all 0.2s;
        position: relative;
        overflow: hidden;
    }

    .subject-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: var(--gold);
    }

    /* Subject card with background image */
    .subject-card[style*="background-image"]::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.5));
        z-index: 1;
        border-radius: inherit;
        pointer-events: none;
    }

    .subject-card[style*="background-image"]>* {
        position: relative;
        z-index: 2;
    }

    /* ===== SUBJECT CARD TOP - Icon left, Title center, Percent right ===== */
    .subject-card-top {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 10px;
    }

    .subject-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        border: 1px solid var(--line);
        background: var(--brand-soft);
        color: var(--brand);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        flex-shrink: 0;
    }

    .subject-title-wrapper {
        flex: 1;
        text-align: center;
    }

    .subject-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
        margin: 0;
        word-break: break-word;
        display: inline-block;
    }

    .subject-progress-badge {
        font-size: 20px;
        font-weight: 700;
        color: var(--brand);
        background: var(--brand-soft);
        border-radius: 10px;
        padding: 6px 10px;
        border: 1px solid var(--line);
        white-space: nowrap;
        flex-shrink: 0;
    }

    .subject-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 8px 0 14px;
    }

    .subject-pill {
        padding: 6px 10px;
        border-radius: 10px;
        border: 1px solid var(--line);
        font-size: 11px;
        font-weight: 700;
        background: var(--surface-2);
        color: var(--text-secondary);
    }

    .subject-pill.completed {
        background: var(--brand-soft);
        color: var(--brand);
        border-color: var(--brand);
    }

    .subject-description {
        font-size: 14px;
        color: var(--muted);
        line-height: 1.6;
        margin-bottom: 12px;
    }

    .subject-meta {
        display: grid;
        gap: 8px;
        margin-bottom: 16px;
    }

    .subject-meta-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        font-size: 13px;
        padding: 4px 0;
        border-bottom: 1px solid var(--line);
    }

    .subject-meta-row:last-child {
        border-bottom: none;
    }

    .subject-meta-row span:first-child {
        color: var(--muted);
        font-weight: 500;
    }

    .subject-meta-row strong {
        color: var(--text);
        font-weight: 700;
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

    .subject-actions {
        display: flex;
        gap: 10px;
    }

    .subject-btn {
        flex: 1;
        text-align: center;
        padding: 12px 14px;
        border-radius: var(--radius-md);
        font-weight: 700;
        font-size: 14px;
        transition: all 0.2s;
        border: 1px solid var(--line);
    }

    .subject-btn-primary {
        background: var(--brand);
        color: #fff;
        border-color: var(--brand);
    }

    .subject-btn-primary.completed-btn {
        background: var(--surface-3);
        color: var(--muted);
        border-color: var(--line);
    }

    .subject-btn-primary:hover:not(.completed-btn) {
        filter: brightness(1.1);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .subject-btn-secondary {
        background: var(--surface-2);
        color: var(--text-secondary);
    }

    .subject-btn-secondary:hover {
        background: var(--brand-soft);
        color: var(--brand);
        border-color: var(--brand);
    }

    /* ===== EMPTY STATES ===== */
    .empty-state,
    .search-empty-state {
        text-align: center;
        padding: 50px 20px;
        color: var(--muted);
    }

    .empty-state .emoji,
    .search-empty-state .emoji {
        font-size: 50px;
        margin-bottom: 12px;
    }

    .search-empty-state {
        display: none;
        border: 1px dashed var(--line);
        border-radius: var(--radius-lg);
        padding: 40px;
    }
    .cert{
        display:flex;
         justify-content:center;
         align-items:center;
    }

    /* ===== CERTIFICATE PREVIEW ===== */
    .certificate-preview-panel {
        position: relative;
        overflow: hidden;
        border: 2px solid var(--gold);
        border-radius: var(--radius-lg);
        padding: 20px;
        background: linear-gradient(135deg, var(--brand-soft), var(--surface) 54%, #fff7db);
        box-shadow: var(--shadow-md);
        min-height: 180px;
        max-width:568px;
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

    /* ===== RESPONSIVE ===== */
    @media (max-width: 1200px) {
        .subjects-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 992px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 900px) {
        .header-top-nav {
            display: none;
        }
        .filter-row {
            grid-template-columns: 1fr;
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
            padding: 16px;
        }
        .subjects-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        .hero-card h1 {
            font-size: 30px;
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
        .subject-card {
            padding: 14px;
        }
        .subject-title {
            font-size: 16px;
        }
        .subject-progress-badge {
            font-size: 16px;
            padding: 4px 8px;
        }
        .cert-preview-percent strong {
            font-size: 22px;
        }
        .cert-preview-badge {
            font-size: 10px;
            padding: 3px 8px;
        }
    }

    .stati{
        display:grid;
        grid-template-columns:1fr 1fr;
    }
</style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<a href="#mainContent" class="skip-link"><?php echo e(t('skip_to_main')); ?></a>

<header class="app-header">
    <div class="header-left">
        <a href="subjects.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
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
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="<?php echo e($user['profile_image']); ?>" alt="<?php echo e(t('profile_image')); ?>">
                <?php else: ?>
                    <?php echo e(initials($userDisplayName)); ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['profile_image'])): ?>
                        <img src="<?php echo e($user['profile_image']); ?>" alt="<?php echo e(t('profile_image')); ?>">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials($userDisplayName)); ?></div>
                    <?php endif; ?>

                    <div>
                        <h4><?php echo e($userDisplayName); ?></h4>
                        <p><?php echo e($user['email'] ?? ''); ?></p>
                        <p style="font-size: 10px;"><?php echo t('member_since'); ?> <?php echo !empty($user['created_at']) ? e(date('F Y', strtotime($user['created_at']))) : 'recently'; ?></p>
                    </div>
                </div>

                <a href="profile.php">👤 <?php echo t('profile'); ?></a>
                <a href="settings.php">⚙️ <?php echo t('settings'); ?></a>
                <a href="logout.php">🚪 <?php echo t('logout'); ?></a>
            </div>
        </div>
    </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" aria-label="<?php echo e(t('open_navigation')); ?>" aria-expanded="false" aria-controls="sidebar">☰</button>

<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">📚 <?php echo e(t('navigation')); ?></div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon']); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">
        <div class="page-stack">
            <!-- Hero Card -->
            <section class="hero-card section-card">
                <div class="hero-top">
                    <div class="hero-title-wrap">
                        <div class="hero-icon">📚</div>
                        <div>
                            <h1><?php echo t('subjects') ?></h1>
                        </div>
                    </div>
                </div>
                <div class="hero-progress">
                                        <span><?php echo t('average') ?></span>

                    <strong><?php echo (int)$summary['average_progress']; ?>%</strong>
                </div>
            </section>



            

            <!-- Filter Section -->
           <!-- Filter Section - Open / Close -->
                    <section class="section-card">
                        <div>

                        <button type="button" class="stats-toggle" id="statsToggle">
                            <span>📊 <?php echo e(t('STATS')); ?></span>
                            <span id="statsIcon">+</span>
                        </button>

                        <div class="stats-grid" id="statsGrid">

                            <div class="stat-item">
                                <div class="stat-icon">📚</div>
                                <div class="stat-value">
                                    <?php echo (int)$summary['total_subjects']; ?>
                                </div>
                                <div class="stat-label">
                                    <?php echo t('total subjects'); ?>
                                </div>
                            </div>

                            <div class="stat-item">
                                <div class="stat-icon">🗂️</div>
                                <div class="stat-value">
                                    <?php echo (int)$summary['total_categories']; ?>
                                </div>
                                <div class="stat-label">
                                    <?php echo t('total categories'); ?>
                                </div>
                            </div>

                            <div class="stat-item">
                                <div class="stat-icon">📘</div>
                                <div class="stat-value">
                                    <?php echo (int)$summary['total_contents']; ?>
                                </div>
                                <div class="stat-label">
                                    <?php echo t('total contents'); ?>
                                </div>
                            </div>

                            <div class="stat-item">
                                <div class="stat-icon">🚀</div>
                                <div class="stat-value">
                                    <?php echo (int)$summary['started_subjects']; ?>
                                </div>
                                <div class="stat-label">
                                    <?php echo t('started subjects'); ?>
                                </div>
                            </div>

                        </div>
                                </div>
                                <div>

                        <button type="button" class="filter-toggle" id="filterToggle">
                            <span>🔎 <?php echo e(t('find')); ?></span>
                            <span id="filterIcon">+</span>
                        </button>

                        <div class="filter-content" id="filterContent">

                            <div class="filter-row">

                                <div class="search-wrap">
                                    <span class="search-icon">🔍</span>

                                    <input
                                        type="text"
                                        id="subjectSearchInput"
                                        class="search-input"
                                        placeholder="<?php echo e(t('search')); ?>"
                                        autocomplete="off"
                                    >
                                </div>

                                <div class="select-wrap">
                                    <select id="statusFilter" class="filter-select">

                                        <option value="">
                                            <?php echo e(t('status1')); ?>
                                        </option>

                                        <option value="started">
                                            🚀 <?php echo e(t('status2')); ?>
                                        </option>

                                        <option value="not_started">
                                            🕓 <?php echo e(t('status3')); ?>
                                        </option>

                                        <option value="completed">
                                            ✅ <?php echo e(t('status4')); ?>
                                        </option>

                                    </select>
                                </div>

                            </div>

                        </div>
                                </div>

                    </section>
       </div>
            <!-- Subjects Grid -->
            <?php if (!empty($dbSubjects)): ?>
                <section class="subjects-grid" id="subjectsGrid">
                    <?php foreach ($dbSubjects as $subject): ?>
                        <?php
                            $subjectName = $subject['name'] ?? 'Untitled Subject';
                            $subjectType = trim((string)($subject['type'] ?? ''));
                            $subjectIcon = $subject['icon'] ?? '📚';
                            $subjectImage = $subject['image_path'] ?? null;
                            $subjectDescription = trim((string)($subject['description'] ?? ''));
                            $subjectDescription = $subjectDescription !== ''
                                ? $subjectDescription
                                : '';
                            $subjectProgress = (int)($subject['progress'] ?? 0);
                            $subjectContents = (int)($subject['total_contents'] ?? 0);
                            $subjectCategories = (int)($subject['total_categories'] ?? 0);
                            $subjectSlug = (string)($subject['slug'] ?? '');
                            $subjectId = (int)($subject['id'] ?? 0);

                            $subjectLink = $subjectSlug !== ''
                                ? 'subject.php?slug=' . urlencode($subjectSlug)
                                : 'subject.php?id=' . $subjectId;

                            $lastAccessed = formatDateValue($subject['last_accessed_at'] ?? null, 'M d, Y');
                            $touched = (int)($subject['touched_contents'] ?? 0) > 0;
                            $completed = (int)($subject['completed_contents'] ?? 0) === $subjectContents && $subjectContents > 0;
                            
                            $statusValue = $completed ? 'completed' : ($touched ? 'started' : 'not_started');
                                    $statusLabel = $completed ? t('status4') : ($touched ? t('status2') : t('status3'));
                            $completedCount = (int)($subject['completed_contents'] ?? 0);

                            $searchBlob = strtolower(
                                $subjectName . ' ' .
                                $subjectType . ' ' .
                                $subjectDescription . ' ' .
                                $subjectIcon . ' ' .
                                $subjectCategories . ' ' .
                                $subjectContents . ' ' .
                                $subjectProgress . ' ' .
                                $completedCount . ' ' .
                                $statusLabel . ' ' .
                                $lastAccessed
                            );
                        ?>
                        <article class="subject-card subject-search-item"
                            data-search="<?php echo e($searchBlob); ?>"
                            data-status="<?php echo e($statusValue); ?>"
                            <?php if ($subjectImage): ?>
                            style="background-image: linear-gradient(135deg, rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.5)), url('<?php echo e($subjectImage); ?>'); background-size: cover; background-position: center; color: white;"
                            <?php endif; ?>>
                            
                            <!-- Subject Card Top: Icon | Title (centered) | Percentage -->
                            <div class="subject-card-top">
                                <div class="subject-icon" style="<?php echo $subjectImage ? 'background: rgba(255,255,255,0.9); backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.3); color: var(--brand);' : ''; ?>">
                                    <?php echo e($subjectIcon); ?>
                                </div>
                                <div class="subject-title-wrapper">
                                    <h3 class="subject-title" style="<?php echo $subjectImage ? 'color: white;  font-size: 1.80rem; text-shadow: 0 1px 2px rgba(0,0,0,0.5);' : ''; ?>">
                                        <?php echo e($subjectName); ?>
                                    </h3>
                                </div>
                                <div class="subject-progress-badge" style="<?php echo $subjectImage ? 'background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.3); color: #7dd3a7;' : ''; ?>">
                                    <?php echo $subjectProgress; ?>%
                                </div>
                            </div>
                            <?php if ($subjectType !== ''): ?>
                                            <div style="
                                                text-align:center;
                                                margin: 4px 0 10px;
                                                font-size: 12px;
                                                font-weight: 700;
                                                color: <?php echo $subjectImage ? 'rgba(255,255,255,0.9)' : 'var(--brand)'; ?>;
                                            ">
                                                TYPE: <?php echo e($subjectType); ?>
                                            </div>
                                        <?php endif; ?>

                            <p  dir="rtl" class="subject-description" style="<?php echo $subjectImage ? 'color: rgba(255,255,255,0.9); font-size: 1.5rem ; text-shadow: 0 1px 2px rgba(0,0,0,0.3);' : ''; ?>">
                                <?php echo e($subjectDescription); ?>
                            </p>

                            <div class="subject-pills" style="justify-content: center; gap: 10px; margin-bottom: 10px;">
                                
                               
                               
                                
                            </div>

                            <div class="subject-meta">
                                <div class="subject-meta-row">
                                    <span style="<?php echo $subjectImage ? 'color: rgba(255,255,255,0.7);' : ''; ?>"><?php echo t('categories') ?></span>
                                    <strong style="<?php echo $subjectImage ? 'color: white;' : ''; ?>"><?php echo $subjectCategories; ?></strong>
                                </div>
                                <div class="subject-meta-row">
                                    <span style="<?php echo $subjectImage ? 'color: rgba(255,255,255,0.7);' : ''; ?>"><?php echo t('contents') ?></span>
                                    <strong style="<?php echo $subjectImage ? 'color: white;' : ''; ?>"><?php echo $subjectContents; ?></strong>
                                </div>
                                <div class="subject-meta-row">
                                    <span style="<?php echo $subjectImage ? 'color: rgba(255,255,255,0.7);' : ''; ?>"><?php echo t('progress') ?></span>
                                    <strong style="<?php echo $subjectImage ? 'color: #7dd3a7;' : ''; ?>"><?php echo $subjectProgress; ?>%</strong>
                                </div>
                                <div class="subject-meta-row">
                                    <span style="<?php echo $subjectImage ? 'color: rgba(255,255,255,0.7);' : ''; ?>"><?php echo t('status') ?></span>
                                    <strong style="<?php echo $subjectImage ? 'color: white;' : ''; ?>"><?php echo e($statusLabel); ?></strong>
                                </div>
                                <div class="subject-meta-row">
                                    <span style="<?php echo $subjectImage ? 'color: rgba(255,255,255,0.7);' : ''; ?>"><?php echo t('last') ?></span>
                                    <strong style="<?php echo $subjectImage ? 'color: white;' : ''; ?>"><?php echo e($lastAccessed !== '' ? $lastAccessed : '—'); ?></strong>
                                </div>
                            </div>

                            <div class="progress-bar-wrap" style="<?php echo $subjectImage ? 'background: rgba(255,255,255,0.3); border-color: rgba(255,255,255,0.2);' : ''; ?>">
                                <div class="progress-bar" style="width: <?php echo max(0, min(100, $subjectProgress)); ?>%;"></div>
                            </div>

                            <div class="subject-actions">
                     
                                <a href="user_dashboard.php" class="subject-btn subject-btn-secondary" style="<?php echo $subjectImage ? 'background: rgba(0,0,0,0.6); border-color: rgba(255,255,255,0.3); color: white;' : ''; ?>">
                                    <?php echo e(t('back')); ?>
                                </a>
                                  <a href="<?php echo e($subjectLink); ?>" class="subject-btn subject-btn-primary <?php echo $completed ? 'completed-btn' : ''; ?>" style="<?php echo $subjectImage && !$completed ? 'background: var(--brand); border-color: var(--brand);' : ''; ?>">
                                    <?php echo $completed ? '✅ ' . e(t('status4')) : '📖 ' . e(t('open')); ?>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="search-empty-state" id="searchEmptyState">
                    <div class="emoji">🔎</div>
                    <h3 style="margin-bottom: 8px;"><?php echo e(t('no_matching_subject')); ?></h3>
                    <p><?php echo e(t('try_subject_filter')); ?></p>
                </section>
            <?php else: ?>
                <section class="section-card empty-state">
                    <div class="emoji">📭</div>
                    <h3 style="margin-bottom: 8px;"><?php echo e(t('no_active_subjects')); ?></h3>
                    <p><?php echo e(t('no_active_subjects_detail')); ?></p>
                </section>
            <?php endif; ?>
      <div class="cert">
            <!-- Certificate Preview Panel -->
            <div class="certificate-preview-panel" id="generalCertificateGoal" aria-label="General certificate progress preview">
                <div class="certificate-preview-bg"></div>
                <div class="certificate-preview-content">
                    <div>
                        <div class="cert-preview-top">
                            <div class="cert-preview-title"><?php echo e(t('certificate_completion')); ?></div>
                            <div class="cert-preview-badge <?= e($generalCertStatusClass); ?>">
                                <?= e($generalCertStatusText); ?>
                            </div>
                        </div>

                        <div class="cert-preview-progress">
                            <div class="cert-preview-percent">
                                <strong><?= (int)$generalCertProgress; ?>%</strong>
                                <span><?php echo e(t('to_certificate')); ?></span>
                            </div>

                            <div class="cert-preview-bar">
                                <div class="cert-preview-fill" style="width: <?= max(0, min(100, (int)$generalCertProgress)); ?>%;"></div>
                            </div>

                            <p class="cert-preview-note">
                                <?php if ($generalCertEarned): ?>
                                    <?php echo e(t('certificate_unlocked')); ?>
                                <?php elseif ($generalCertProgress >= 100): ?>
                                    <?php echo e(t('all_subjects_completed')); ?>
                                <?php elseif ($generalCertProgress > 0): ?>
                                    <?= (int)($generalCertificateInfo['remaining'] ?? 0); ?>% <?php echo e(t('remaining_continue')); ?>
                                <?php else: ?>
                                    <?php echo e(t('start_subjects_certificate')); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="cert-preview-actions">
                        <?php if ($generalCertEarned && !empty($generalCertificateInfo['certificate_code'])): ?>
                            <a class="cert-preview-link" href="certificate_view.php?code=<?= urlencode((string)$generalCertificateInfo['certificate_code']); ?>">
                                🎓 <?php echo e(t('view_certificate')); ?>
                            </a>
                        <?php elseif ($generalCertProgress >= 100): ?>
                            <a class="cert-preview-link" href="certification_exams.php?subject=general">
                                📝 <?php echo e(t('take_exam')); ?>
                            </a>
                        <?php else: ?>
                            <a class="cert-preview-link" href="#subjectsGrid">
                                📚 Continue
                            </a>
                        <?php endif; ?>

                        <a class="cert-preview-link secondary" href="my_certificates.php">
                            <?php echo e(t('my_certificates')); ?>
                        </a>
                    </div>
                </div>
            </div>
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

    const subjectSearchInput = document.getElementById('subjectSearchInput');
    const statusFilter = document.getElementById('statusFilter');
    const subjectItems = document.querySelectorAll('.subject-search-item');
    const visibleSubjectCount = document.getElementById('visibleSubjectCount');
    const searchEmptyState = document.getElementById('searchEmptyState');
    const subjectsGrid = document.getElementById('subjectsGrid');

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

    // Language Toggle
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

    function applyFilters() {
        const query = (subjectSearchInput?.value || '').trim().toLowerCase();
        const statusValue = (statusFilter?.value || '').trim().toLowerCase();
        let visibleCount = 0;

        subjectItems.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            const itemStatus = item.getAttribute('data-status') || '';
            const matchesSearch = query === '' || haystack.includes(query);
            const matchesStatus = statusValue === '' || itemStatus === statusValue;
            const show = matchesSearch && matchesStatus;

            item.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        if (visibleSubjectCount) {
            visibleSubjectCount.textContent = String(visibleCount);
        }

        if (searchEmptyState) {
            searchEmptyState.style.display = visibleCount === 0 ? 'block' : 'none';
        }

        if (subjectsGrid) {
            subjectsGrid.style.display = visibleCount === 0 ? 'none' : 'grid';
        }
    }

    subjectSearchInput?.addEventListener('input', applyFilters);
    statusFilter?.addEventListener('change', applyFilters);

    // Handle image loading errors
    document.querySelectorAll('img').forEach(img => {
        img.addEventListener('error', function() {
            this.style.display = 'none';
        });
    });
})();

const statsToggle = document.getElementById('statsToggle');
const statsGrid = document.getElementById('statsGrid');
const statsIcon = document.getElementById('statsIcon');

statsToggle.addEventListener('click', function () {

    statsGrid.classList.toggle('show');

    if (statsGrid.classList.contains('show')) {
        statsIcon.textContent = '−';
    } else {
        statsIcon.textContent = '+';
    }

});

const filterToggle = document.getElementById('filterToggle');
const filterContent = document.getElementById('filterContent');
const filterIcon = document.getElementById('filterIcon');

filterToggle.addEventListener('click', function () {

    filterContent.classList.toggle('show');

    if (filterContent.classList.contains('show')) {
        filterIcon.textContent = '−';
    } else {
        filterIcon.textContent = '+';
    }

});
</script>

</body>
</html>