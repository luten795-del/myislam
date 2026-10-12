<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

// Set default language if not set
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

// Auth
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$conn = db();

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

function getCurrentUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("SELECT id, full_name, email, profile_image, role, is_active, created_at FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}

function getUnreadCount(PDO $conn, int $userId): int {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function getCategoryMaterials(PDO $conn, int $categoryId): array {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM category_materials 
            WHERE category_id = ? AND is_active = 1 
            ORDER BY sort_order ASC, created_at DESC
        ");
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getCategoryMaterials: " . $e->getMessage());
        return [];
    }
}

function formatFileSize(int $bytes): string {
    if ($bytes <= 0) return 'Unknown';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

$dbUser = getCurrentUser($conn, $userId);
if (!$dbUser) { session_destroy(); header('Location: login.php?error=account_disabled'); exit; }
$user = [
    'name' => $dbUser['full_name'] ?? 'User',
    'email' => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role' => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime($dbUser['created_at'])) : 'recently',
];

$unreadCount = getUnreadCount($conn, $userId);

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

$catId = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
if ($catId <= 0) { die('Invalid category.'); }

// Fetch category and its subject
$stmt = $conn->prepare("
    SELECT c.*, s.name AS subject_name, s.slug AS subject_slug, s.id AS subject_id
    FROM categories c
    JOIN subjects s ON c.subject_id = s.id
    WHERE c.id = ?
");
$stmt->execute([$catId]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$category) { die('Category not found.'); }

// Fetch contents for this category with progress
$stmt = $conn->prepare("
    SELECT
        c.id, c.title, c.slug, c.short_description, c.sort_order, c.image_path,
        COALESCE(ucp.progress_percent, 0) AS progress_percent,
        COALESCE(ucp.is_started, 0) AS is_started,
        COALESCE(ucp.is_completed, 0) AS is_completed
    FROM contents c
    LEFT JOIN user_content_progress ucp ON ucp.content_id = c.id AND ucp.user_id = ?
    WHERE c.category_id = ? AND c.status = 'published'
    ORDER BY c.sort_order ASC
");
$stmt->execute([$userId, $catId]);
$contents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch materials (books, documents) for this category
$materials = getCategoryMaterials($conn, $catId);

// Sequential locking
$lockedIndices = [];
$prevCompleted = true;
foreach ($contents as $index => $content) {
    $lockedIndices[$index] = !$prevCompleted;
    $prevCompleted = ((int)($content['is_completed'] ?? 0) === 1);
}

$completedCount = 0;
foreach ($contents as $c) if ((int)($c['is_completed'] ?? 0) === 1) $completedCount++;

$totalLessons = count($contents);
$progress = $totalLessons > 0 ? round(($completedCount / $totalLessons) * 100) : 0;

// Get view type from URL (lessons or materials)
$viewType = isset($_GET['view']) && $_GET['view'] === 'materials' ? 'materials' : 'lessons';

// Determine full base URL for absolute paths
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$baseUrl = $protocol . $_SERVER['HTTP_HOST'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo e($category['name']); ?> – MyIslam</title>
    <!-- PDF.js library for better PDF viewing -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <style>
        /* ===== RESET & BASE ===== */
        *{box-sizing:border-box;margin:0;padding:0}
        
        /* ===== CSS VARIABLES ===== */
        :root{
            --bg:#f5f7f5;
            --surface:#ffffff;
            --surface-2:#f9fafb;
            --surface-3:#eef2f7;
            --line:#e2e5e2;
            --text:#1f2937;
            --text-secondary:#4b5563;
            --muted:#6b7280;
            --brand:#0E4A34;
            --brand-dark:#0A3626;
            --brand-light:#3E7A5E;
            --brand-soft:rgba(14, 74, 52, 0.08);
            --gold:#9C7A2E;
            --gold-soft:rgba(156, 122, 46, 0.10);
            --accent:#3E6E91;
            --accent-soft:rgba(62, 110, 145, 0.10);
            --header-bg:#ffffff;
            --header-height:72px;
            --shadow-sm:0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md:0 4px 6px -1px rgb(0 0 0 / 0.08);
            --shadow-lg:0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius-sm:12px;
            --radius-md:16px;
            --radius-lg:20px;
            --danger:#dc2626;
            --success:#0E4A34;
            --warning:#b45309;
            --max-width:1600px;
            --content-width:95%;
            --text-base:16px;
            --text-lg:18px;
            --text-xl:22px;
            --text-2xl:28px;
            --text-sm:14px;
            --text-xs:12px;
        }

        /* ===== DARK MODE ===== */
        body.dark-mode{
            --bg:#0f1720;
            --surface:#18212b;
            --surface-2:#1d2833;
            --surface-3:#22303c;
            --line:#304150;
            --text:#edf2f7;
            --text-secondary:#cbd5e1;
            --muted:#94a3b8;
            --brand:#4C9172;
            --brand-dark:#6bb590;
            --brand-light:#7fcaa4;
            --brand-soft:rgba(76, 145, 114, 0.16);
            --gold:#C9A85E;
            --gold-soft:rgba(201, 168, 94, 0.16);
            --accent:#6FA0C4;
            --accent-soft:rgba(111, 160, 196, 0.16);
            --header-bg:#13202a;
            --danger:#ef4444;
        }

        /* ===== BODY ===== */
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.65;
            font-size: var(--text-base);
            -webkit-text-size-adjust: 100%;
        }

    body.dark-mode {
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

        a{text-decoration:none;color:inherit} button,input,select{font:inherit}

        /* ===== HEADER ===== */
        .app-header {
            position: sticky; top: 0; z-index: 1000;
            height: var(--header-height);
            background: var(--header-bg);
            border-bottom: 1px solid var(--line);
            box-shadow: var(--shadow-sm);
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 24px; backdrop-filter: blur(10px); gap: 18px;
        }

        .header-left, .header-right { display: flex; align-items: center; gap: 12px; }
        .header-left { flex: 1; min-width: 0; }

        .icon-btn, .lang-btn, .brand-box {
            height: 40px; min-width: 30px; border-radius: 10px;
            background: var(--surface); border: 1px solid var(--line);
            display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
        }

        .icon-btn:hover, .lang-btn:hover, .profile-btn:hover {
            transform: translateY(-2px); box-shadow: var(--shadow-sm);
        }

        .icon-btn{font-size: 18px; color: var(--text); position: relative;}
        .lang-btn { min-width: 30px; padding: 0 8px; font-weight: 600; color: var(--brand);}
        .brand-box { font-size: 22px; font-weight: 700; color: var(--brand); background: transparent; border: none; white-space: nowrap; }

        /* ===== PROFILE ===== */
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
            background: var(--danger); color: white;
            font-size: 10px; font-weight: 700; min-width: 18px; height: 18px;
            border-radius: 999px; display: flex; align-items: center; justify-content: center;
            padding: 0 4px; border: 2px solid var(--surface);
        }

        /* ===== SIDEBAR ===== */
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
            width: 36px; height: 36px; background: var(--surface);
            border: 1px solid var(--line); border-left: none; border-top: none;
            border-radius: 0 0 8px 0; font-size: 16px; cursor: pointer;
            color: var(--text); transition: all 0.2s; box-shadow: var(--shadow-sm);
            padding: 0; margin: 0;
        }
        .sidebar-toggle-btn:hover { background: var(--brand-soft); color: var(--brand); }

        /* ===== MAIN CONTENT - WIDER ===== */
        .main-content {
            max-width: var(--max-width);
            width: var(--content-width);
            margin: 0 auto;
            padding: 10px;
        }

        .page-stack{display:grid;gap:10px;}

        /* ===== SECTION CARDS ===== */
        .section-card{
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            transition: all 0.2s;
            box-shadow: var(--shadow-sm);
        }
        .section-card:hover{
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* ===== HERO CARD ===== */
        .hero-card {
            background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%);
            border: 1px solid var(--line);
            padding: 10px;
            display: flex;
            flex-direction: row;
            gap: 10px;
        }

        .hero-top {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            flex-wrap: wrap;
        }

        .hero-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }

        .hero-icon {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            background: var(--surface);
            color: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            border: 1px solid var(--line);
            flex-shrink: 0;
        }

        .hero-content {
            flex: 1;
        }

        .hero-content h1 {
            font-size: var(--text-2xl);
            margin-bottom: 4px;
            font-weight: 800;
            color: var(--text);
        }

        .hero-content p,
        .hero-description {
            color: var(--text-secondary);
            font-size: var(--text-base);
            max-width: 760px;
            line-height: 1.6;
        }

        /* Progress bar */
        .hero-progress {
            padding: 10px;
            border-radius: var(--radius-md);
            background: var(--surface-2);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

   

  

        .hero-progress-left span {
            font-size: 13px;
            color: var(--muted);
            font-weight: 600;
        }




        .hero-progress-right {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
            white-space: nowrap;
        }

        /* Hero with background image */
        .hero-card[style*="background-image"] .hero-icon {
            background: rgba(255,255,255,0.9) !important;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        .hero-card[style*="background-image"] .hero-progress {
            background: rgba(0,0,0,0.7) !important;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .hero-card[style*="background-image"] .hero-progress-left strong {
            color: #7dd3a7 !important;
        }
        .hero-card[style*="background-image"] .hero-progress-left span {
            color: rgba(255,255,255,0.7) !important;
        }
        .hero-card[style*="background-image"] .hero-progress-right {
            color: rgba(255,255,255,0.7) !important;
        }
        .hero-card[style*="background-image"] .hero-progress-bar-wrap {
            background: rgba(255,255,255,0.3) !important;
            border-color: rgba(255,255,255,0.2) !important;
        }
        .hero-card[style*="background-image"] h1,
        .hero-card[style*="background-image"] .hero-description {
            text-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        .hero-card[style*="background-image"] h1 {
            color: white !important;
        }
        .hero-card[style*="background-image"] .hero-description {
            color: rgba(255,255,255,0.9) !important;
        }

        /* ===== STATS GRID ===== */
        .stats-grid{
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            padding: 0;
            border: none;
            background: transparent;
        }
        .stat-card{
            text-align: center;
            padding: 10px 12px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            transition: all 0.2s;
        }
        .stat-card:hover{
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        /* ===== VIEW TOGGLE ===== */
        .view-toggle {
            display: flex;
            gap: 8px;
            background: var(--surface-2);
            border-radius: var(--radius-lg);
            padding: 6px;
            border: 1px solid var(--line);
            width: fit-content;
        }
        .view-toggle-btn {
            padding: 10px 28px;
            border-radius: var(--radius-md);
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            background: transparent;
            color: var(--text-secondary);
            border: none;
            font-size: 14px;
        }
        .view-toggle-btn.active {
            background: var(--brand);
            color: white;
            box-shadow: var(--shadow-sm);
        }
        .view-toggle-btn:hover:not(.active) {
            background: var(--surface-3);
        }
        .type{
            gap:16px;
        }
      @media(min-width:600px){
        .type{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:16px;
        }
      }
        /* ===== LESSON LIST ===== */
        .content-list{display:grid;gap:16px}
        @media(min-width:768px){
            .content-list{
                grid-template-columns:repeat(4,1fr);
            }
        }
        .content-card{
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 20px 24px;
            transition: all 0.2s;
        }
        .content-card:hover{
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--brand);
        }
        .content-card.locked { opacity: 0.7; }
        .content-title{font-size:18px;font-weight:700;margin-bottom:6px;text-align:center;display:inline-block;padding:6px 16px;border-radius:8px;background:rgba(3, 79, 49, 0.82);color:#fff}
        .content-desc{font-size:14px;line-height:1.6;color:var(--text-secondary)}
        .content-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
        .content-meta{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 12px}
        .content-pill{
            padding:4px 12px;
            border-radius:20px;
            font-size:11px;
            font-weight:700;
            background: var(--brand-soft);
            color: var(--brand);
            border: 1px solid transparent;
        }
        .content-pill.neutral{background:var(--surface-3);color:var(--muted)}
        .content-pill.locked-pill { background: #fee2e2; color: #991b1b; border-color: var(--danger); }
        .progress-bar-wrap{
            height:8px;
            border-radius:999px;
            background:var(--surface-3);
            overflow:hidden;
            margin-bottom:14px;
            border: 1px solid var(--line);
        }
        .progress-bar{
            height:100%;
            background:linear-gradient(90deg, var(--brand), var(--brand-light));
            border-radius:999px;
            transition: width 0.3s ease;
        }
        .content-actions{display:flex;gap:10px;flex-wrap:wrap}
        .content-btn{
            flex:1;
            text-align:center;
            padding:10px 16px;
            border-radius:var(--radius-md);
            font-weight:700;
            font-size:13px;
            transition:all 0.2s;
            border: none;
            cursor: pointer;
            min-width: 100px;
        }
        .content-btn.primary{background:var(--brand);color:#fff}
        .content-btn.primary.completed{background:var(--surface-3);color:var(--muted);border:1px solid var(--line)}
        .content-btn.primary.completed:hover{background:var(--surface-2)}
        .content-btn.locked-btn {
            background: var(--surface-3);
            color: var(--muted);
            border: 1px solid var(--danger);
            cursor: not-allowed;
            opacity: 0.7;
        }
        .content-btn.locked-btn:hover { transform: none; background: var(--surface-3); }
        .content-btn.secondary{
            background: var(--surface);
            border: 1px solid var(--line);
            color: var(--text);
        }
        .content-btn:hover{transform:translateY(-2px)}
        .empty-state{text-align:center;padding:50px 20px;color:var(--muted)}
        .empty-state .emoji{font-size:50px;margin-bottom:12px}

        /* Content card with background image */
        .content-card[style*="background-image"] {
            color: white;
        }
        .content-card[style*="background-image"]::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(0,0,0,0.1), rgba(0,0,0,0.2));
            z-index: 1;
            border-radius: inherit;
        }
        .content-card[style*="background-image"] > * {
            position: relative;
            z-index: 2;
        }
        .content-card[style*="background-image"] .content-pill {
            background: rgba(0,0,0,0.6) !important;
            color: white !important;
            border-color: rgba(255,255,255,0.3) !important;
        }
        .content-card[style*="background-image"] .content-pill.locked-pill {
            background: rgba(0,0,0,0.2) !important;
            color: #ffaaaa !important;
        }
        .content-card[style*="background-image"] .progress-bar-wrap {
            background: rgba(255,255,255,0.3) !important;
            border-color: rgba(255,255,255,0.2) !important;
        }
        .content-card[style*="background-image"] .content-btn.primary {
            background: var(--brand);
        }
        .content-card[style*="background-image"] .content-btn.primary.completed {
            background: rgba(0,0,0,0.6);
            color: white;
            border-color: rgba(255,255,255,0.3);
        }
        .content-card[style*="background-image"] .content-btn.secondary {
            background: rgba(0,0,0,0.6);
            border-color: rgba(255,255,255,0.3);
            color: white;
        }
        .content-card[style*="background-image"] .content-btn.locked-btn {
            background: rgba(0,0,0,0.6);
            color: #ffaaaa;
            border-color: rgba(255,255,255,0.3);
        }

        /* ===== MATERIALS GRID ===== */
        .materials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .material-card {
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 20px;
            transition: all 0.2s;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        .material-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--brand);
        }

        .material-card[style*="background-image"] {
            color: white;
        }
        .material-card[style*="background-image"]::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(0,0,0,0.4), rgba(0,0,0,0.6));
            z-index: 1;
            border-radius: inherit;
        }
        .material-card[style*="background-image"] > * {
            position: relative;
            z-index: 2;
        }
        .material-card[style*="background-image"] .material-icon {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(4px);
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px auto;
            font-size: 32px;
        }

        .material-icon {
            font-size: 48px;
            margin-bottom: 12px;
            text-align: center;
        }
        .material-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 8px;
            text-align: center;
        }
        .material-description {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 14px;
            text-align: center;
            line-height: 1.5;
        }
        .material-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--line);
            flex-wrap: wrap;
            gap: 6px;
        }
        .material-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-pdf { background: #fee2e2; color: #dc2626; }
        .badge-word { background: #dbeafe; color: #2563eb; }
        .badge-excel { background: #dcfce7; color: #16a34a; }
        .badge-ppt { background: #fed7aa; color: #ea580c; }
        .badge-image { background: #f3e8ff; color: #9333ea; }
        .badge-other { background: var(--surface-3); color: var(--muted); }
        
        .material-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .material-btn {
            padding: 8px 20px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
        }
        .material-btn.view {
            background: var(--brand);
            color: white;
        }
        .material-btn.download {
            background: var(--surface);
            border: 1px solid var(--line);
            color: var(--text);
        }
        .material-btn:hover {
            transform: translateY(-2px);
        }

        /* ===== MODAL ===== */
        .material-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 2000;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .material-modal-content {
            position: relative;
            max-width: 1200px;
            width: 95%;
            margin: 20px auto;
            background: var(--surface);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .material-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
            gap: 10px;
        }
        .material-modal-title {
            font-size: 18px;
            font-weight: 700;
            word-break: break-word;
        }
        .material-modal-close {
            font-size: 28px;
            cursor: pointer;
            background: none;
            border: none;
            color: var(--text);
            padding: 0 8px;
        }
        .material-viewer {
            min-height: 300px;
            max-height: 70vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .fullscreen-btn {
            background: var(--brand);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 6px 12px;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* ===== PDF VIEWER ===== */
        .pdf-viewer-container {
            width: 100%;
            min-height: 400px;
            height: auto;
            overflow-x: auto;
            overflow-y: auto;
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            background: #525659;
            -webkit-overflow-scrolling: touch;
        }
        .pdf-canvas {
            display: block;
            margin: 0 auto;
            max-width: 100%;
            height: auto;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .pdf-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            padding: 10px;
            background: var(--surface-2);
            border-radius: var(--radius-md);
            flex-wrap: wrap;
        }
        .pdf-controls button {
            padding: 8px 12px;
            background: var(--brand);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            min-width: 70px;
        }
        .pdf-controls button:disabled { opacity: 0.5; cursor: not-allowed; }

        .empty-materials {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1400px) {
            .main-content { width: 100%; padding: 10px ; }
        }

        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .materials-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
        }

        @media (max-width: 900px) {
            .main-content { width: 100%; padding: 10px 10px; }
        }

        @media (max-width: 768px) {
            :root {
                --header-height: 64px;
                --text-base: 15px;
                --text-lg: 16px;
                --text-xl: 18px;
                --text-2xl: 22px;
                --text-sm: 13px;
                --text-xs: 11px;
            }
            .main-content { padding: 12px ; width: 100%; }
            .section-card { padding: 10px; }
            .hero-card { padding: 16px; }
            .hero-content h1 { font-size: var(--text-2xl); }
            .hero-content p { font-size: var(--text-sm); }
            .hero-progress { flex-direction: row; align-items: stretch; gap: 10px; padding: 10px; }
            .hero-progress-left { justify-content: center; }
            .hero-progress-bar-wrap { min-width: 10px; }
            .hero-progress-right { text-align: center; }
           
            .materials-grid { grid-template-columns: 1fr; gap: 14px; }
            .material-card { padding: 14px; }
            .view-toggle { width: 100%; justify-content: center; }
            .view-toggle-btn { padding: 8px 16px; font-size: 13px; flex: 1; text-align: center; }
            .content-card { padding: 14px 16px; }
            .content-title { font-size: 16px; }
            .content-actions { flex-direction: row; }
            .content-btn { min-width: unset; }
            .material-modal-content { width: 98%; margin: 10px auto; padding: 12px; }
            .material-viewer { max-height: 50vh; }
        }

        @media (max-width: 480px) {
            :root {
                --text-base: 14px;
                --text-lg: 15px;
                --text-xl: 17px;
                --text-2xl: 20px;
                --text-sm: 12px;
                --text-xs: 10px;
            }
            .hero-icon { width: 30px; height: 34px; font-size: 22px; }
            .hero-progress-left strong { font-size: 22px; }
            
            .material-icon { font-size: 36px; }
            .material-btn { padding: 6px 14px; font-size: 12px; }
            .content-btn { font-size: 12px; padding: 8px 12px; }
        }

        @media print {
            .header, .sidebar-toggle-btn, .sidebar-overlay, .sidebar,
            .view-toggle, .navigation-buttons, .toast { display: none !important; }
            .section-card { box-shadow: none !important; transform: none !important; }
        }
        

        /* ===== COLLAPSIBLE STATS ===== */
        .stats-block { margin: 0; }
        .stats-block-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            cursor: pointer;
            font: inherit;
            font-weight: 700;
            font-size: 15px;
            color: var(--text);
            text-align: left;
            transition: background 0.2s ease, border-color 0.2s ease;
        }
        .stats-block-toggle:hover {
            background: var(--brand-soft);
            border-color: var(--brand);
            color: var(--brand);
        }
        .stats-block-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--surface-2);
            border: 1px solid var(--line);
            font-weight: 700;
            font-size: 16px;
            line-height: 1;
            color: var(--brand);
            transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease;
        }
        .stats-block-toggle[aria-expanded="true"] .stats-block-icon {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
        }
        .stats-grid[hidden] { display: none; }
        .stats-grid { margin-top: 10px; }
    </style>
    <?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<!-- ===== HEADER ===== -->
<header class="app-header">
    <div class="header-left">
        <a href="category.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
            <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px;">
            <span style="font-size:22px;font-weight:1000;color:var(--brand);">MyIslam</span>
        </a>
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
                    <?php echo e(initials((string)$user['name'])); ?>
                <?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)$user['name'])); ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?php echo e((string)$user['name']); ?></h4>
                        <p><?php echo e((string)$user['email']); ?></p>
                        <p style="font-size: 10px;">Member since <?php echo e((string)$user['member_since']); ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 <?php echo t('profile'); ?></a>
                <a href="settings.php">⚙️ <?php echo t('settings'); ?></a>
                <a href="logout.php">🚪 <?php echo t('logout'); ?></a>
            </div>
        </div>
    </div>
</header>

<!-- ===== SIDEBAR ===== -->
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
        </nav>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">
        <div class="page-stack">
            <a href="subject.php?slug=<?php echo e($category['subject_slug'] ?? ''); ?>" style="display:inline-flex;align-items:center;gap:8px;color:var(--muted);margin-bottom:4px;font-size:14px;font-weight:600;">
                ← Back to <?php echo e($category['subject_name'] ?? 'Subject'); ?>
            </a>

            <!-- ===== HERO CARD ===== -->
            <?php
            $categoryImage = !empty($category['image_path']) ? $category['image_path'] : null;
            $hasBgImage = !empty($categoryImage);
            ?>
            <div class="type">
            <section class="hero-card section-card" style="<?php echo $hasBgImage ? 'background: linear-gradient(135deg, rgba(0,0,0,0.5), rgba(0,0,0,0.7)), url(\'' . e($categoryImage) . '\'); background-size: cover; background-position: center;' : ''; ?>">
                
                <div class="hero-top">
                    <div class="hero-title-wrap">
                        <div class="hero-icon" style="<?php echo $hasBgImage ? 'background: rgba(255,255,255,0.9); backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.3);' : ''; ?>">
                            <?php echo e($category['icon'] ?? '📁'); ?>
                        </div>
                        <div dir="rtl" class="hero-content">
                            <h1 style="<?php echo $hasBgImage ? 'color: white;' : ''; ?>"><?php echo e($category['name'] ?? 'Category'); ?></h1>
                            
                        </div>
                    </div>
                </div>

                <div class="hero-progress" style="<?php echo $hasBgImage ? 'background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.2);' : ''; ?>">
                    <div class="hero-progress-left">
                        <strong style="<?php echo $hasBgImage ? 'color: #7dd3a7;' : ''; ?>"><?php echo $progress; ?>%</strong>
                        <span style="<?php echo $hasBgImage ? 'color: rgba(255,255,255,0.7);' : ''; ?>"></span>
                    </div>
                   
                   
                </div>
            </section>

            <!-- ===== STATS GRID ===== -->
                      <!-- ===== STATS GRID (collapsible) ===== -->
            <div class="stats-block">
                <button type="button"
                        class="stats-block-toggle"
                        id="subjectStatsToggle"
                        aria-expanded="false"
                        aria-controls="subjectStatsGrid">
                    <span>📊 Stats</span>
                    <span class="stats-block-icon" id="subjectStatsIcon">+</span>
                </button>

                <div class="stats-grid" id="subjectStatsGrid" hidden>
                    <div class="stat-card">
                        <div class="stat-icon">📘</div>
                        <div class="stat-value"><?php echo $totalLessons; ?></div>
                        <div class="stat-label">Lessons</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">✅</div>
                        <div class="stat-value"><?php echo $completedCount; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📚</div>
                        <div class="stat-value"><?php echo count($materials); ?></div>
                        <div class="stat-label">Books &amp; Docs</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📊</div>
                        <div class="stat-value"><?php echo $progress; ?>%</div>
                        <div class="stat-label">Progress</div>
                    </div>
                </div>
            </div>
            </div>
            <!-- ===== VIEW TOGGLE ===== -->
            <div class="view-toggle">
                <button class="view-toggle-btn <?php echo $viewType === 'lessons' ? 'active' : ''; ?>" onclick="switchView('lessons')">
                    📖 Lessons (<?php echo $totalLessons; ?>)
                </button>
                <button class="view-toggle-btn <?php echo $viewType === 'materials' ? 'active' : ''; ?>" onclick="switchView('materials')">
                    📚 Books & Documents (<?php echo count($materials); ?>)
                </button>
            </div>

            <!-- ===== LESSONS VIEW ===== -->
            <div id="lessonsView" style="display: <?php echo $viewType === 'lessons' ? 'block' : 'none'; ?>;">
                <section class="section-card">
                    <!-- No manual "download all" button: unlocked lessons in
                         this category auto-save for offline reading on page
                         load (see the script near the end of this file). -->
                    <?php if (!empty($contents)): ?>
                        <div class="content-list" id="contentList">
                            <?php foreach ($contents as $index => $content):
                                $isLocked = $lockedIndices[$index] ?? false;
                                $isCompleted = (int)($content['is_completed'] ?? 0) === 1;
                                $isStarted = !$isLocked && (int)($content['is_started'] ?? 0) === 1 && !$isCompleted;
                                $progressPercent = (int)($content['progress_percent'] ?? 0);
                                $title = $content['title'] ?? 'Untitled Lesson';
                                $desc = trim((string)($content['short_description'] ?? ''));
                                if ($desc === '') $desc = '';
                                $contentLink = $content['slug'] 
                                    ? 'content.php?slug=' . urlencode((string)$content['slug'])
                                    : 'content.php?id=' . (int)$content['id'];
                                
                                // Fall back to the parent category's image when this
                                // content item has no image of its own, so every lesson
                                // card still gets a background instead of a plain one.
                                $contentBgImage = !empty($content['image_path'])
                                    ? $content['image_path']
                                    : $categoryImage;
                                $hasContentBg = !empty($contentBgImage);
                            ?>
                            <article class="content-card <?php echo $isLocked ? 'locked' : ''; ?>" 
                                     style="<?php echo $hasContentBg ? 'background: linear-gradient(135deg, rgba(0,0,0,0.5), rgba(0,0,0,0.7)), url(\'' . e($contentBgImage) . '\'); background-size: cover; background-position: center; color: white;' : ''; ?>">
                                <div class="content-top">
                                    <div style="width:100%;text-align:center;">
                                        <div class="content-title" style="color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.3);">
                                            <?php echo ($index + 1) . '. ' . e($title); ?>
                                        </div>
                                        <div class="content-desc" style="<?php echo $hasContentBg ? 'color: rgba(255,255,255,0.85);' : ''; ?>">
                                            <?php echo e($desc); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="content-meta">
                                    <?php if ($isLocked): ?>
                                        <span class="content-pill locked-pill" style="<?php echo $hasContentBg ? 'background: rgba(0,0,0,0.6); color: #ffaaaa; border-color: rgba(255,255,255,0.3);' : ''; ?>">🔒 Locked</span>
                                    <?php elseif ($isCompleted): ?>
                                        <span class="content-pill" style="<?php echo $hasContentBg ? 'background: rgba(0,0,0,0.6); color: white; border-color: rgba(255,255,255,0.3);' : ''; ?>">✅ Completed</span>
                                    <?php elseif ($isStarted): ?>
                                        <span class="content-pill" style="<?php echo $hasContentBg ? 'background: rgba(0,0,0,0.6); color: white; border-color: rgba(255,255,255,0.3);' : ''; ?>">📖 In Progress</span>
                                    <?php else: ?>
                                        <span class="content-pill neutral" style="<?php echo $hasContentBg ? 'background: rgba(0,0,0,0.6); color: white; border-color: rgba(255,255,255,0.3);' : ''; ?>">🕓 Not Started</span>
                                    <?php endif; ?>
                                    <span class="content-pill neutral" style="<?php echo $hasContentBg ? 'background: rgba(0,0,0,0.6); color: white; border-color: rgba(255,255,255,0.3);' : ''; ?>"><?php echo $progressPercent; ?>%</span>
                                </div>
                                <div class="progress-bar-wrap" style="<?php echo $hasContentBg ? 'background: rgba(255,255,255,0.3); border-color: rgba(255,255,255,0.2);' : ''; ?>">
                                    <div class="progress-bar" style="width: <?php echo $progressPercent; ?>%;"></div>
                                </div>
                                <div class="content-actions">
                                     <a href="subject.php?slug=<?php echo e($category['subject_slug']); ?>" class="content-btn secondary" 
                                       style="<?php echo $hasContentBg ? 'background: rgba(0,0,0,0.6); border-color: rgba(255,255,255,0.3); color: white;' : ''; ?>">
                                        Back
                                    </a>
                                    <?php if ($isLocked): ?>
                                        <span class="content-btn locked-btn" style="<?php echo $hasContentBg ? 'background: rgba(0,0,0,0.6); color: #ffaaaa; border-color: rgba(255,255,255,0.3);' : ''; ?>">🔒 Locked</span>
                                    <?php else: ?>
                                        <a href="<?php echo e($contentLink); ?>" class="content-btn primary <?php echo $isCompleted ? 'completed' : ''; ?>" 
                                           style="<?php echo $hasContentBg && !$isCompleted ? 'background: var(--brand);' : ''; ?> 
                                                  <?php echo $hasContentBg && $isCompleted ? 'background: rgba(0,0,0,0.6); color: white; border-color: rgba(255,255,255,0.3);' : ''; ?>">
                                            <?php echo $isCompleted ? '✅ Completed' : ($isStarted ? 'Continue' : 'Open'); ?>
                                        </a>
                                    <?php endif; ?>
                                   
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="emoji">📭</div>
                            <p>No lessons available in this category yet.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ===== MATERIALS VIEW ===== -->
            <div id="materialsView" style="display: <?php echo $viewType === 'materials' ? 'block' : 'none'; ?>;">
                <section class="section-card">
                    <h3 style="margin-bottom:16px; font-size:18px; font-weight:700;">📚 Books & Documents</h3>
                    
                    <?php if (!empty($materials)): ?>
                        <div class="materials-grid">
                            <?php foreach ($materials as $material): 
                                $fileIcon = '';
                                $badgeClass = '';
                                switch($material['file_type']) {
                                    case 'pdf': $fileIcon = '📄'; $badgeClass = 'badge-pdf'; break;
                                    case 'word': $fileIcon = '📝'; $badgeClass = 'badge-word'; break;
                                    case 'excel': $fileIcon = '📊'; $badgeClass = 'badge-excel'; break;
                                    case 'powerpoint': $fileIcon = '📽️'; $badgeClass = 'badge-ppt'; break;
                                    case 'image': $fileIcon = '🖼️'; $badgeClass = 'badge-image'; break;
                                    default: $fileIcon = '📁'; $badgeClass = 'badge-other';
                                }
                                $fileSize = $material['file_size'] ? formatFileSize((int)$material['file_size']) : 'Unknown';
                                $isExternal = $material['is_external_link'] == 1;
                                $fileUrl = $isExternal ? $material['external_url'] : $baseUrl . '/' . $material['file_path'];
                                $relativeUrl = $material['file_path'];
                                $materialBgImage = !empty($material['thumbnail_path']) ? $material['thumbnail_path'] : null;
                                $hasMaterialBg = !empty($materialBgImage);
                            ?>
                            <div class="material-card" data-material-id="<?php echo $material['id']; ?>" data-file-type="<?php echo $material['file_type']; ?>" data-file-url="<?php echo e($fileUrl); ?>" data-relative-url="<?php echo e($relativeUrl); ?>" data-title="<?php echo e($material['title']); ?>" data-allow-download="<?php echo $material['allow_download']; ?>" 
                                 style="<?php echo $hasMaterialBg ? 'background: linear-gradient(135deg, rgba(0,0,0,0.5), rgba(0,0,0,0.7)), url(\'' . e($materialBgImage) . '\'); background-size: cover; background-position: center; color: white;' : ''; ?>">
                                <div class="material-icon" style="<?php echo $hasMaterialBg ? 'background: rgba(255,255,255,0.9); backdrop-filter: blur(4px); border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;' : ''; ?>"><?php echo $fileIcon; ?></div>
                                <div class="material-title" style="<?php echo $hasMaterialBg ? 'color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.3);' : ''; ?>"><?php echo e($material['title']); ?></div>
                                <?php if (!empty($material['description'])): ?>
                                    <div class="material-description" style="<?php echo $hasMaterialBg ? 'color: rgba(255,255,255,0.85);' : ''; ?>"><?php echo e(substr($material['description'], 0, 100)) . (strlen($material['description']) > 100 ? '...' : ''); ?></div>
                                <?php endif; ?>
                                <div class="material-meta" style="<?php echo $hasMaterialBg ? 'border-top-color: rgba(255,255,255,0.2);' : ''; ?>">
                                    <span class="material-badge <?php echo $badgeClass; ?>" style="<?php echo $hasMaterialBg ? 'background: rgba(0,0,0,0.6); color: white;' : ''; ?>"><?php echo strtoupper($material['file_type']); ?></span>
                                    <span style="<?php echo $hasMaterialBg ? 'color: rgba(255,255,255,0.7);' : ''; ?>">📊 <?php echo $fileSize; ?></span>
                                    <span style="<?php echo $hasMaterialBg ? 'color: rgba(255,255,255,0.7);' : ''; ?>">👁️ <?php echo $material['view_count']; ?></span>
                                </div>
                                <div class="material-actions">
                                    <button class="material-btn view" onclick="event.stopPropagation(); openMaterial(<?php echo $material['id']; ?>, '<?php echo $material['file_type']; ?>', '<?php echo addslashes($fileUrl); ?>', '<?php echo addslashes($relativeUrl); ?>', '<?php echo addslashes($material['title']); ?>', <?php echo $material['allow_download']; ?>)"
                                            style="<?php echo $hasMaterialBg ? 'background: var(--brand);' : ''; ?>">
                                        👁️ View
                                    </button>
                                    <?php if ($material['allow_download'] && !$isExternal && $relativeUrl): ?>
                                        <a href="<?php echo e($relativeUrl); ?>" download class="material-btn download" onclick="event.stopPropagation();"
                                           style="<?php echo $hasMaterialBg ? 'background: rgba(0,0,0,0.6); border-color: rgba(255,255,255,0.3); color: white;' : ''; ?>">
                                            ⬇️ Download
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-materials">
                            <div class="emoji">📚</div>
                            <p>No books or documents available in this category yet.</p>
                            <p style="font-size: 13px; margin-top: 8px;">Check back later for additional learning materials.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
</div>

<!-- ===== MATERIAL MODAL ===== -->
<div id="materialModal" class="material-modal">
    <div class="material-modal-content">
        <div class="material-modal-header">
            <h3 class="material-modal-title" id="modalTitle">Document Viewer</h3>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button class="fullscreen-btn" id="fullscreenBtn" onclick="toggleFullscreen()">⛶ Fullscreen</button>
                <button class="material-modal-close" onclick="closeMaterialModal()">&times;</button>
            </div>
        </div>
        <div class="material-viewer" id="materialViewer">
            <div class="loading-spinner">
                <div class="spinner"></div>
                <p>Loading document...</p>
            </div>
        </div>
        <div style="margin-top: 20px; text-align: center;">
            <button id="downloadBtnModal" class="material-btn download" style="display: none;">⬇️ Download File</button>
        </div>
    </div>
</div>

<!-- ===== SCRIPTS ===== -->
<script>
// Configure PDF.js worker
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

// Global variables
let pdfDoc = null;
let currentPage = 1;
let totalPages = 0;
let pdfRendering = false;
let pdfCanvas = null;
let pdfContext = null;
let currentMaterialUrl = '';
let currentMaterialUrlForDownload = '';
let currentMaterialDownloadTitle = '';
let currentScale = 0;
let devicePixelRatio = window.devicePixelRatio || 1;
let renderingQueue = [];
let isRendering = false;
let currentFileType = '';

// ===== ULTRA RENDER FUNCTION =====
async function renderPdfUltra(pageNumber) {
    if (!pdfDoc) { console.error('No PDF document loaded'); return; }
    if (isRendering) { renderingQueue.push(pageNumber); return; }
    
    isRendering = true;
    pdfRendering = true;
    
    try {
        const page = await pdfDoc.getPage(pageNumber);
        const container = document.querySelector('.pdf-canvas-wrapper');
        const containerWidth = container ? container.clientWidth - 20 : window.innerWidth - 40;
        const viewportScale = page.getViewport({ scale: 1 });
        let scale = (containerWidth / viewportScale.width) * devicePixelRatio;
        scale = Math.max(scale, 2.5);
        scale = Math.min(scale, 5.0);
        const viewport = page.getViewport({ scale: scale });
        
        pdfCanvas.width = viewport.width;
        pdfCanvas.height = viewport.height;
        pdfCanvas.style.width = '100%';
        pdfCanvas.style.height = 'auto';
        pdfCanvas.style.display = 'block';
        pdfCanvas.style.margin = '0 auto';
        
        pdfContext = pdfCanvas.getContext('2d', { alpha: false, desynchronized: true, willReadFrequently: false, antialias: true });
        pdfContext.imageSmoothingEnabled = true;
        pdfContext.imageSmoothingQuality = 'high';
        
        const renderContext = {
            canvasContext: pdfContext,
            viewport: viewport,
            intent: 'print',
            background: '#ffffff',
            enableWebGL: true,
            renderInteractiveForms: true,
            useSystemFonts: true,
            devicePixelRatio: devicePixelRatio,
            textRenderMode: 0,
            imageSmoothingEnabled: true,
            nativeImageDecoderSupport: 'all',
            annotationMode: 2,
            maxImageSize: -1
        };
        
        await page.render(renderContext).promise;
        currentScale = scale;
        pdfRendering = false;
        isRendering = false;
        updatePageInfo();
        
        if (renderingQueue.length > 0) {
            const nextPage = renderingQueue.shift();
            await renderPdfUltra(nextPage);
        }
    } catch (error) {
        console.error('Render error:', error);
        pdfRendering = false;
        isRendering = false;
    }
}

// ===== FULL SCREEN PDF LOADER =====
async function loadPdfFullscreen(pdfUrl) {
    const viewer = document.getElementById('materialViewer');
    viewer.innerHTML = `<div class="fullscreen-loader"><div class="loader-spinner"></div><p>Loading PDF Document...</p></div>`;
    
    try {
        const loadingTask = pdfjsLib.getDocument({
            url: pdfUrl,
            cMapUrl: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/cmaps/',
            cMapPacked: true,
            enableXfa: true,
            useSystemFonts: true,
            standardFontDataUrl: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/standard_fonts/',
            maxImageSize: -1
        });
        
        pdfDoc = await loadingTask.promise;
        totalPages = pdfDoc.numPages;
        currentPage = 1;
        
        viewer.innerHTML = `
            <style>
                .fullscreen-pdf-container {
                    position: fixed;
                    top: 0; left: 0; width: 100vw; height: 100vh;
                    background: #1a1a2e; z-index: 9999;
                    display: flex; flex-direction: column;
                }
                .pdf-pages {
                    flex: 1; overflow: auto; display: flex; flex-direction: column; gap: 20px; align-items: center; padding: 20px; background: #2d2d44;
                }
                .pdf-page-canvas { box-shadow: 0 4px 20px rgba(0,0,0,0.3); background: white; border-radius: 4px; display: block; }
                .bottom-nav-bar {
                    background: #0f0f1a; padding: 12px 20px; display: flex;
                    justify-content: center; align-items: center; gap: 12px;
                    flex-wrap: wrap; border-top: 1px solid #2d2d44;
                }
                .bottom-nav-bar button {
                    padding: 10px 18px; background: #16213e; color: white;
                    border: none; border-radius: 8px; cursor: pointer;
                    font-weight: 600; font-size: 13px; transition: all 0.2s;
                    min-width: 70px;
                }
                .bottom-nav-bar button:hover { background: #e94560; transform: translateY(-2px); }
                .close-fullscreen { background: #e94560 !important; }
                @media (max-width: 768px) {
                    .bottom-nav-bar button { padding: 6px 12px; font-size: 11px; min-width: 50px; }
                    .pdf-pages { padding: 10px; }
                }
            </style>
            <div class="fullscreen-pdf-container">
                <div id="pdfPages" class="pdf-pages">
                    <div class="fullscreen-loader"><div class="loader-spinner"></div><p>Rendering pages...</p></div>
                </div>
                <div class="bottom-nav-bar">
                    <button onclick="downloadCurrentFile()">💾 Download</button>
                    <button class="close-fullscreen" onclick="closeMaterialModal()">✖ Close</button>
                </div>
            </div>
        `;

        const pagesContainer = document.getElementById('pdfPages');
        pagesContainer.innerHTML = '';

        // Render every page as a stacked canvas so the user can scroll and remain crisp on high-DPI displays
        for (let p = 1; p <= totalPages; p++) {
            const page = await pdfDoc.getPage(p);

            // Base viewport at scale=1 to compute sizes
            const baseViewport = page.getViewport({ scale: 1 });
            const maxDisplayWidth = Math.min(window.innerWidth * 0.96, 1100);

            // Fit the page to the available width (CSS pixels)
            const fitScale = Math.max(0.5, Math.min( Math.max(1, (maxDisplayWidth / baseViewport.width)), 2.5 ));

            // Use pdf.js viewport at the CSS scale; we'll account for devicePixelRatio separately for crispness
            const viewport = page.getViewport({ scale: fitScale });

            const outputScale = window.devicePixelRatio || 1;

            const canvas = document.createElement('canvas');
            canvas.className = 'pdf-page-canvas';

            // Set the internal pixel size taking devicePixelRatio into account, but keep the CSS size equal to viewport size
            canvas.width = Math.floor(viewport.width * outputScale);
            canvas.height = Math.floor(viewport.height * outputScale);
            canvas.style.width = Math.floor(viewport.width) + 'px';
            canvas.style.height = Math.floor(viewport.height) + 'px';

            pagesContainer.appendChild(canvas);

            try {
                const ctx = canvas.getContext('2d', { alpha: false });
                // Scale the drawing operations to account for the outputScale so PDF content stays sharp
                ctx.setTransform(outputScale, 0, 0, outputScale, 0, 0);
                ctx.imageSmoothingEnabled = true;
                if (ctx.imageSmoothingQuality !== undefined) ctx.imageSmoothingQuality = 'high';

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;
            } catch (err) {
                console.error('Page render error (page ' + p + '):', err);
            }
        }


        
        const pageInfoSpan = document.getElementById('pageInfo');
        if (pageInfoSpan) {
            pageInfoSpan.insertAdjacentHTML('afterend', `<input type="number" id="pageJumpInput" min="1" max="${totalPages}" placeholder="#" style="width:55px;margin-left:8px;">`);
            document.getElementById('pageJumpInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const pageNum = parseInt(this.value);
                    if (pageNum >= 1 && pageNum <= totalPages && !pdfRendering) {
                        currentPage = pageNum;
                        renderPdfUltra(currentPage);
                        updatePageInfo();
                        this.value = '';
                    }
                }
            });
        }
    } catch (error) {
        console.error('PDF load error:', error);
        viewer.innerHTML = `<div class="fullscreen-loader"><p>⚠️ Failed to load PDF</p><button onclick="window.open('${pdfUrl}','_blank')" style="padding:12px 24px;margin-top:20px;">Open PDF Directly</button></div>`;
    }
}

function updatePageInfo() {
    const pageInfo = document.getElementById('pageInfo');
    if (pageInfo && totalPages) pageInfo.textContent = `Page ${currentPage} / ${totalPages}`;
    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');
    if (prevBtn) prevBtn.disabled = currentPage <= 1;
    if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
}

function changePage(delta) {
    const newPage = currentPage + delta;
    if (newPage >= 1 && newPage <= totalPages && !pdfRendering) {
        currentPage = newPage;
        renderPdfUltra(currentPage);
        updatePageInfo();
    }
}

function goToFirstPage() { if (currentPage !== 1 && !pdfRendering) { currentPage = 1; renderPdfUltra(currentPage); updatePageInfo(); } }
function goToLastPage() { if (currentPage !== totalPages && !pdfRendering) { currentPage = totalPages; renderPdfUltra(currentPage); updatePageInfo(); } }
async function zoomIn() { if (!pdfDoc || pdfRendering) return; let newScale = Math.min(currentScale + 0.3, 6.0); currentScale = newScale; await renderPdfUltra(currentPage); }
async function zoomOut() { if (!pdfDoc || pdfRendering) return; let newScale = Math.max(currentScale - 0.3, 1.5); currentScale = newScale; await renderPdfUltra(currentPage); }
async function resetZoom() { if (!pdfDoc) return; const container = document.querySelector('.pdf-canvas-wrapper'); const containerWidth = container ? container.clientWidth - 40 : window.innerWidth - 80; const page = await pdfDoc.getPage(currentPage); const viewportScale = page.getViewport({ scale: 1 }); let scale = (containerWidth / viewportScale.width) * devicePixelRatio; scale = Math.max(scale, 2.5); scale = Math.min(scale, 5.0); currentScale = scale; await renderPdfUltra(currentPage); }

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

function downloadCurrentFile() {
    if (currentMaterialUrlForDownload) {
        triggerDownload(currentMaterialUrlForDownload, getSuggestedDownloadName(currentMaterialUrlForDownload, currentMaterialDownloadTitle));
    }
}

// ===== MAIN OPEN FUNCTION =====
async function openMaterial(id, fileType, fileUrl, relativeUrl, title, allowDownload) {
    currentMaterialUrlForDownload = fileUrl;
    currentMaterialDownloadTitle = title;
    currentFileType = fileType;
    const modal = document.getElementById('materialModal');
    const viewer = document.getElementById('materialViewer');
    const modalTitle = document.getElementById('modalTitle');
    modalTitle.textContent = title;
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    try { await fetch('ajax/track_material_view.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ material_id: id }) }); } catch(e) {}
    
    if (fileType === 'pdf') {
        await loadPdfFullscreen(fileUrl);
    } else if (fileType === 'image' || fileType === 'jpg' || fileType === 'jpeg' || fileType === 'png' || fileType === 'gif' || fileType === 'webp') {
        viewer.innerHTML = `<div style="text-align:center;"><img src="${fileUrl}" style="max-width:100%;max-height:70vh;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.3);"></div>`;
    } else if (fileType === 'word' || fileType === 'excel' || fileType === 'powerpoint') {
        const encodedUrl = encodeURIComponent(fileUrl);
        viewer.innerHTML = `<iframe src="https://view.officeapps.live.com/op/embed.aspx?src=${encodedUrl}" style="width:100%;height:60vh;border:none;border-radius:8px;"></iframe>`;
    } else {
        viewer.innerHTML = `<div style="text-align:center;padding:40px;"><div style="font-size:64px;margin-bottom:20px;">📁</div><h3>${escapeHtml(title)}</h3><p>File type: ${fileType.toUpperCase()}</p><button onclick="window.open('${fileUrl}','_blank')" style="padding:12px 24px;margin-top:20px;">Open File</button></div>`;
    }
}

function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
function closeMaterialModal() { const modal = document.getElementById('materialModal'); if (modal) modal.style.display = 'none'; document.body.style.overflow = ''; const viewer = document.getElementById('materialViewer'); if (viewer) viewer.innerHTML = ''; pdfDoc = null; pdfCanvas = null; pdfContext = null; pdfRendering = false; isRendering = false; renderingQueue = []; if (document.fullscreenElement) document.exitFullscreen(); }
function toggleFullscreen() { const viewer = document.getElementById('materialViewer'); if (!document.fullscreenElement) { viewer.requestFullscreen().catch(() => {}); } else { document.exitFullscreen(); } }

function switchView(view) {
    const lessonsView = document.getElementById('lessonsView');
    const materialsView = document.getElementById('materialsView');
    const url = new URL(window.location.href);
    if (view === 'lessons') { lessonsView.style.display = 'block'; materialsView.style.display = 'none'; url.searchParams.set('view', 'lessons'); }
    else { lessonsView.style.display = 'none'; materialsView.style.display = 'block'; url.searchParams.set('view', 'materials'); }
    window.history.pushState({}, '', url);
}

// ===== SIDEBAR & THEME =====
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

    function openSidebar() { if (sidebar) sidebar.classList.add('open'); if (sidebarOverlay) sidebarOverlay.classList.add('show'); if (body) body.style.overflow = 'hidden'; }
    function closeSidebar() { if (sidebar) sidebar.classList.remove('open'); if (sidebarOverlay) sidebarOverlay.classList.remove('show'); if (body) body.style.overflow = ''; }
    
    if (menuToggle) menuToggle.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
    
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { if (sidebar && sidebar.classList.contains('open')) closeSidebar(); if (profileMenu && profileMenu.classList.contains('show')) { profileMenu.classList.remove('show'); profileToggle?.setAttribute('aria-expanded', 'false'); } }
    });
    
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') { body.classList.add('dark-mode'); if (themeToggle) themeToggle.textContent = '☀'; }
    if (themeToggle) {
        themeToggle.addEventListener('click', () => { body.classList.toggle('dark-mode'); const isDark = body.classList.contains('dark-mode'); themeToggle.textContent = isDark ? '☀' : '🌙'; localStorage.setItem('theme', isDark ? 'dark' : 'light'); });
    }
    
    let currentLang = localStorage.getItem('language') || '<?php echo $_SESSION['language'] ?? "en"; ?>';
    if (langToggle) {
        langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
        langToggle.addEventListener('click', async () => {
            currentLang = currentLang === 'en' ? 'sw' : 'en';
            langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
            localStorage.setItem('language', currentLang);
            try { await fetch('ajax/switch_language.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ language: currentLang }) }); location.reload(); } catch(e) {}
        });
    }
    
    if (profileToggle && profileMenu) {
        profileToggle.addEventListener('click', function(e) { e.stopPropagation(); profileMenu.classList.toggle('show'); });
        document.addEventListener('click', function(e) { if (!profileArea.contains(e.target)) profileMenu.classList.remove('show'); });
    }
})();

document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('materialModal');
    if (modal && modal.style.display === 'block') {
        if (e.key === 'Escape') closeMaterialModal();
        if (currentFileType === 'pdf') {
            if (e.key === 'ArrowLeft') { changePage(-1); e.preventDefault(); }
            else if (e.key === 'ArrowRight') { changePage(1); e.preventDefault(); }
        }
    }
});
</script>

<!-- PWA: auto-save every unlocked lesson in this category for offline
     reading. No button - runs on page load, downloads sequentially (not
     parallel, so it doesn't hammer the server with a burst of requests for
     a category with many lessons), skips lessons already saved from a
     previous visit, skips locked lessons the user can't open yet, and only
     shows a notification if it actually saved something new. -->
<script>
    (async function () {
        // Only unlocked lessons can actually be opened, so only those are
        // worth downloading.
        const downloadableLessonIds = <?php
            $downloadableIds = [];
            foreach ($contents as $index => $c) {
                if (empty($lockedIndices[$index])) {
                    $downloadableIds[] = (int)$c['id'];
                }
            }
            echo json_encode($downloadableIds);
        ?>;

        if (downloadableLessonIds.length === 0 || typeof OfflineDB === 'undefined') return;

        let succeeded = 0;

        for (const id of downloadableLessonIds) {
            try {
                const alreadyDownloaded = await OfflineDB.isDownloaded(id);
                if (alreadyDownloaded) continue;

                const response = await fetch(`api/get_lesson_content.php?id=${encodeURIComponent(id)}`);
                if (!response.ok) throw new Error(`Server responded ${response.status}`);
                const lessonData = await response.json();
                if (lessonData.error) throw new Error(lessonData.error);
                await OfflineDB.saveLesson(lessonData);
                succeeded++;
            } catch (err) {
                // Silent per-lesson failure - background/automatic action,
                // not something the user is watching or waiting on. It'll
                // just try again on the next visit to this category.
                console.warn(`Auto-save for offline reading failed for lesson ${id}:`, err);
            }
        }

        if (succeeded > 0 && typeof showToast === 'function') {
            showToast(`Saved ${succeeded} lesson(s) for offline reading 📥`, false);
        }
    })();
</script>
<script>
    // Stats toggle — kept in its own <script> tag so a failure anywhere
    // in the PWA auto-save block above can never prevent this from wiring up.
    (function () {
        function initStatsToggle() {
            const toggle = document.getElementById('subjectStatsToggle');
            const grid   = document.getElementById('subjectStatsGrid');
            const icon   = document.getElementById('subjectStatsIcon');
            if (!toggle || !grid || !icon) {
                console.warn('Stats toggle: missing one of #subjectStatsToggle / #subjectStatsGrid / #subjectStatsIcon');
                return;
            }
            toggle.addEventListener('click', function () {
                const isOpen = toggle.getAttribute('aria-expanded') === 'true';
                const nextOpen = !isOpen;
                toggle.setAttribute('aria-expanded', String(nextOpen));
                if (nextOpen) {
                    grid.removeAttribute('hidden');
                    icon.textContent = '−';
                } else {
                    grid.setAttribute('hidden', '');
                    icon.textContent = '+';
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initStatsToggle);
        } else {
            initStatsToggle();
        }
    })();
</script>
</body>
</html>