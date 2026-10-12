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

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$conn = db();

// ========== FETCH USER DETAILS FOR HEADER ==========
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

// Sidebar navigation
 $sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'user_dashboard.php', 'active' => true, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Quizzes', 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Monthly Exams', 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
        ['name' => 'Certification Exams', 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
        [
    'name' => 'My Certificates',
    'link' => 'my_certificates.php',
    'active' => false,
    'icon' => '🎓'
],
    ['name' => 'Leaderboard', 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => 'Multimedia', 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => 'Notifications', 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => 'Help Center', 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];
// =================================================

// Ensure tables exist (unchanged)
$tableQueries = [
    "CREATE TABLE IF NOT EXISTS `help_categories` (
      `id` int NOT NULL AUTO_INCREMENT,
      `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '❓',
      `sort_order` int NOT NULL DEFAULT '0',
      `is_active` tinyint(1) NOT NULL DEFAULT '1',
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `help_articles` (
      `id` int NOT NULL AUTO_INCREMENT,
      `category_id` int NOT NULL,
      `question` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `answer` text COLLATE utf8mb4_unicode_ci NOT NULL,
      `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `view_count` int NOT NULL DEFAULT '0',
      `is_featured` tinyint(1) NOT NULL DEFAULT '0',
      `sort_order` int NOT NULL DEFAULT '0',
      `is_active` tinyint(1) NOT NULL DEFAULT '1',
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `slug` (`slug`),
      KEY `category_id` (`category_id`),
      CONSTRAINT `fk_help_articles_category` FOREIGN KEY (`category_id`) REFERENCES `help_categories` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `support_tickets` (
      `id` int NOT NULL AUTO_INCREMENT,
      `user_id` int NOT NULL,
      `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
      `status` enum('open','in_progress','resolved','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
      `priority` enum('low','medium','high') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
      `admin_response` text COLLATE utf8mb4_unicode_ci,
      `responded_by` int DEFAULT NULL,
      `responded_at` datetime DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      KEY `status` (`status`),
      CONSTRAINT `fk_support_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

foreach ($tableQueries as $query) {
    $conn->exec($query);
}

// Handle ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    
    $errors = [];
    if ($subject === '') $errors[] = 'Subject is required.';
    if ($message === '') $errors[] = 'Message is required.';
    
    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO support_tickets (user_id, subject, message, priority) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $subject, $message, $priority]);
        $ticketId = $conn->lastInsertId();
        $success = "Your support ticket (#$ticketId) has been submitted. We'll respond soon.";
    }
}

// Fetch categories with article count
$categories = $conn->query("
    SELECT c.*, COUNT(a.id) as article_count 
    FROM help_categories c 
    LEFT JOIN help_articles a ON a.category_id = c.id AND a.is_active = 1 
    WHERE c.is_active = 1 
    GROUP BY c.id 
    ORDER BY c.sort_order, c.name
")->fetchAll();

// Determine active category and search
$activeCategory = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$searchTerm = trim($_GET['search'] ?? '');

// Build articles query
$where = ["a.is_active = 1"];
$params = [];

if ($activeCategory > 0) {
    $where[] = "a.category_id = ?";
    $params[] = $activeCategory;
}

if ($searchTerm !== '') {
    $where[] = "(a.question LIKE ? OR a.answer LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

$whereClause = implode(' AND ', $where);
$sql = "
    SELECT a.*, c.name as category_name, c.icon as category_icon 
    FROM help_articles a 
    JOIN help_categories c ON a.category_id = c.id 
    WHERE $whereClause 
    ORDER BY a.is_featured DESC, a.sort_order, a.question
    LIMIT 30
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// Featured articles (for sidebar)
$featuredStmt = $conn->prepare("
    SELECT a.*, c.name as category_name 
    FROM help_articles a 
    JOIN help_categories c ON a.category_id = c.id 
    WHERE a.is_featured = 1 AND a.is_active = 1 
    ORDER BY a.sort_order LIMIT 5
");
$featuredStmt->execute();
$featuredArticles = $featuredStmt->fetchAll();

// User's recent tickets
$myTicketsStmt = $conn->prepare("
    SELECT id, subject, status, created_at 
    FROM support_tickets 
    WHERE user_id = ? 
    ORDER BY created_at DESC LIMIT 5
");
$myTicketsStmt->execute([$userId]);
$myTickets = $myTicketsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Help Center – MyIslam</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
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
        body{
            font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);
            min-height:100vh;line-height:1.6;position:relative;
        }
        body::before{
            content:"";position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:-1;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat:repeat;
        }
        body.dark-mode::before{
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }
        a{text-decoration:none;color:inherit}
        button{font:inherit;border:none;background:none;cursor:pointer}

        /* Header */
        .app-header{
            position:sticky;top:0;z-index:1000;height:var(--header-height);background:var(--header-bg);
            border-bottom:1px solid var(--line);box-shadow:var(--shadow-sm);display:flex;align-items:center;justify-content:space-between;
            padding:12px 24px;gap:18px;
        }
        .header-left,.header-right{display:flex;align-items:center;gap:12px}
        .header-left{flex:1;min-width:0}
        .sidebar-toggle-btn{
            position:fixed;top:var(--header-height);left:0;z-index:1300;
            display:inline-flex;align-items:center;justify-content:center;
            width:36px;height:36px;background:var(--surface);border:1px solid var(--line);
            border-left:none;border-top:none;border-radius:0 0 8px 0;font-size:16px;
            cursor:pointer;color:var(--text);transition:all 0.2s;box-shadow:var(--shadow-sm);
            padding:0;margin:0;
        }
        .sidebar-toggle-btn:hover{background:var(--brand-soft);color:var(--brand)}
        .menu-toggle,.icon-btn,.lang-btn,.brand-box{
            height:40px;min-width:30px;border-radius:10px;background:var(--surface);border:1px solid var(--line);
            display:inline-flex;align-items:center;justify-content:center;transition:all 0.2s;
        }
        .menu-toggle:hover,.icon-btn:hover,.lang-btn:hover{
            transform:translateY(-2px);box-shadow:var(--shadow-sm);border-color:var(--gold);color:var(--gold);
        }
        .brand-box{
            font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none;white-space:nowrap;
        }
        .icon-btn{font-size:18px;color:var(--text);position:relative}
        .lang-btn{min-width:30px;padding:0 8px;font-weight:600;color:var(--brand)}
        .profile-area{position:relative}
        .profile-btn{
            width:44px;height:44px;border-radius:50%;overflow:hidden;background:var(--surface);
            border:2px solid var(--brand);cursor:pointer;padding:0;display:flex;align-items:center;
            justify-content:center;color:var(--brand);font-weight:700;
        }
        .profile-btn:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
        .profile-btn img{width:100%;height:100%;object-fit:cover}
        .profile-menu{
            position:absolute;top:56px;right:0;width:280px;background:var(--glass-bg-strong);
            backdrop-filter:blur(12px);border:1px solid var(--line);
            border-radius:var(--radius-lg);padding:16px;display:none;box-shadow:var(--shadow-lg);z-index:1001;
        }
        .profile-menu.show{display:block}
        .profile-menu-top{
            display:flex;gap:12px;padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid var(--line);
        }
        .profile-menu-top img,.profile-avatar-fallback{width:56px;height:56px;border-radius:50%}
        .profile-avatar-fallback{
            display:inline-flex;align-items:center;justify-content:center;background:var(--brand-soft);
            color:var(--brand);font-weight:700;font-size:16px;
        }
        .profile-menu-top h4{font-size:16px;margin-bottom:4px;font-weight:bold;color:var(--text)}
        .profile-menu-top p{font-size:12px;color:var(--text-secondary);font-weight:600}
        .profile-menu a{
            display:block;padding:10px 12px;border-radius:10px;font-weight:700;font-size:14px;
            transition:background 0.2s;margin:4px 0;color:var(--text);
        }
        .profile-menu a:hover{background:var(--brand-soft);color:var(--brand)}
        .notification-badge{
            position:absolute;top:-4px;right:-4px;background:var(--danger);color:white;font-size:10px;
            font-weight:700;min-width:18px;height:18px;border-radius:999px;display:flex;align-items:center;
            justify-content:center;padding:0 4px;border:2px solid var(--surface);
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
        .subject-link.active { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); 
    }

        /* Main content */
        .main-content{padding:24px;max-width:1200px;margin:0 auto;width:100%}
        .container{width:100%}
        .page-header{
            display:flex;align-items:center;gap:16px;margin-bottom:24px;flex-wrap:wrap;
            justify-content: center;
        }
        .back-link{
            display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--text-secondary);
            font-weight:500;font-size:14px;transition:color 0.2s;
        }
        .back-link:hover{color:var(--brand)}
        .page-title{font-size:28px;font-weight:700;color:var(--text);letter-spacing:-0.01em}
        
        /* Layout */
        .help-layout{display:grid;grid-template-columns:1fr;gap:24px;}
        @media (min-width:900px){.help-layout{grid-template-columns:1fr 320px;}}
        
        /* Search */
        .search-wrapper{position:relative;margin-bottom:24px}
        .search-input{
            width:100%;padding:16px 20px 16px 50px;border-radius:10px;border:1px solid var(--line);
            background:var(--surface);font-size:16px;box-shadow:var(--shadow-sm);color:var(--text);
        }
        .search-input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(14,74,52,.08)}
        .search-icon{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:18px}
        
        /* Category Pills */
        .category-row{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
        .category-pill{
            padding:8px 16px;border-radius:10px;font-size:13px;font-weight:500;background:var(--surface-2);
            border:1px solid var(--line);color:var(--text-secondary);cursor:pointer;transition:all 0.2s;
            text-decoration:none;display:inline-flex;align-items:center;gap:6px;
        }
        .category-pill.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand);font-weight:600}
        .category-pill .count{background:var(--surface);padding:2px 8px;border-radius:40px;font-size:11px;margin-left:4px}
        
        /* FAQ Articles */
        .articles-section{margin-top:16px}
        .article-item{
            background:var(--surface);border-radius:var(--radius-md);padding:16px 18px;
            border:1px solid var(--line);margin-bottom:12px;transition:all 0.2s;
        }
        .article-question{
            display:flex;align-items:flex-start;gap:12px;cursor:pointer; border:1px solid var(--line);
        }
        .article-icon{color:var(--brand);font-size:18px;margin-top:2px}
        .article-question h3{font-size:16px;font-weight:600;flex:1}
        .article-meta{font-size:12px;color:var(--text-secondary);margin-top:6px;display:flex;gap:16px}
        .article-answer{
            margin-top:16px;padding-top:16px;border-top:1px dashed var(--line);
            display:none;line-height:1.7;color:var(--text);
        }
        .article-item.expanded .article-answer{display:block}
        
        /* Sidebar Cards */
        .sidebar-card{
            background:transparent;border-radius:10px;padding:20px;
            border:1px solid var(--line);margin-bottom:24px;
        }
        .sidebar-title{font-size:16px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
        .featured-list{list-style:none}
        .featured-list li{margin-bottom:12px}
        .featured-list a{text-decoration:none;color:var(--text);font-weight:500;font-size:14px;display:block}
        .featured-list a:hover{color:var(--brand)}
        .featured-list .cat{font-size:12px;color:var(--text-secondary);margin-top:2px}
        
        .ticket-item{
            padding:12px 0;border-bottom:1px solid var(--line);
            display:flex;justify-content:space-between;align-items:center;
        }
        .ticket-item:last-child{border-bottom:none}
        .ticket-subject{font-weight:500;font-size:14px}
        .ticket-status{font-size:11px;padding:2px 8px;border-radius:40px;background:var(--surface-2)}
        .ticket-status.open{background:#fef3c7;color:#92400e}
        .ticket-status.resolved{background:#d1fae5;color:#065f46}
        
        /* Form */
        .form-group{margin-bottom:12px}
        .form-group label{font-weight:600;font-size:14px;margin-bottom:6px;display:block}
        .form-control{
            width:100%;padding:12px 16px;border-radius:10px;border:1px solid var(--line);
            background:var(--surface-2);font-size:15px;color:var(--text);
        }
        .form-control:focus{outline:none;border-color:var(--brand);background:var(--surface)}
        textarea.form-control{min-height:120px;resize:vertical}
        .btn{
            display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 24px;
            border-radius:10px;font-weight:600;font-size:15px;cursor:pointer;border:1px solid var(--line);
            background:var(--brand);color:#fff;box-shadow:0 4px 10px rgba(14,74,52,.2);
            width:100%;transition:all 0.2s;
        }
        .btn:hover{background:var(--brand-dark);transform:translateY(-2px)}
        .btn-secondary{background:var(--surface-2);color:var(--text);border:1px solid var(--line);box-shadow:none}
        .btn-secondary:hover{background:var(--surface);border-color:var(--brand)}
        
        .alert{
            padding:14px 18px;border-radius:var(--radius-md);margin-bottom:20px;
            background:var(--brand-soft);color:var(--brand-dark);border-left:4px solid var(--brand);
        }
        .alert.success{background:#d1fae5;color:#065f46;border-left-color:var(--success)}
        .alert.error{background:#fee2e2;color:#991b1b;border-left-color:var(--danger)}
        
        .empty-state{text-align:center;padding:40px 20px;color:var(--text-secondary)}
        .empty-state i{font-size:48px;margin-bottom:16px;opacity:0.5}

        /* Contact support list */
        .contact-list{list-style:none}
        .contact-list li{margin-bottom:12px;display:flex;align-items:center;gap:12px}
        .contact-list i{width:20px;color:var(--brand)}
        .contact-list a{color:var(--text);text-decoration:none;font-weight:500}
        .contact-list a:hover{color:var(--brand)}
        
        @media (max-width:768px){
            .main-content{padding:16px}
            .app-header{padding:12px 16px}
            .category-row{flex-wrap:nowrap;overflow-x:auto;padding-bottom:8px}
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="app-header">
    <div class="header-left">
        <a href="subjects.php" class="brand-box" style="display:flex;align-items:center;gap:4px;text-decoration:none;">
            <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px;">
            <span style="font-size:22px;font-weight:700;color:var(--brand);">MyIslam</span>
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
                        <p style="font-size: 10px;">Member since <?= e($user['member_since']) ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 My Profile</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" aria-label="Menu">☰</button>

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
<div class="container">
    <div class="page-header">
      
        <h1 class="page-title"><i class="fas fa-life-ring" style="color:var(--brand);"></i> Help Center</h1>
    </div>
    
    <div class="help-layout">
        <!-- Main Content -->
        <div class="main-content-inner">
            <!-- Search -->
            <div class="search-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search for help articles..." value="<?= e($searchTerm) ?>">
            </div>
            
            <!-- Categories -->
            <div class="category-row">
                <a href="?search=<?= urlencode($searchTerm) ?>" class="category-pill <?= $activeCategory === 0 ? 'active' : '' ?>">
                    <i class="fas fa-th-large"></i> All
                    <span class="count"><?= array_sum(array_column($categories, 'article_count')) ?></span>
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="?category=<?= $cat['id'] ?><?= $searchTerm ? '&search='.urlencode($searchTerm) : '' ?>" class="category-pill <?= $activeCategory === (int)$cat['id'] ? 'active' : '' ?>">
                        <?= e($cat['icon']) ?> <?= e($cat['name']) ?>
                        <span class="count"><?= $cat['article_count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <!-- Articles -->
            <div class="articles-section">
                <?php if (empty($articles)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book-open"></i>
                        <h3>No articles found</h3>
                        <p>Try a different search term or browse categories.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($articles as $article): ?>
                        <div class="article-item" data-article-id="<?= $article['id'] ?>">
                            <div class="article-question" onclick="toggleArticle(this)">
                                <i class="fas fa-question-circle article-icon"></i>
                                <h3><?= e($article['question']) ?></h3>
                                <i class="fas fa-chevron-down" style="color:var(--text-secondary);transition:transform 0.2s;"></i>
                            </div>
                            <div class="article-meta">
                                <span><i class="far fa-folder"></i> <?= e($article['category_name']) ?></span>
                                <span><i class="far fa-eye"></i> <?= $article['view_count'] ?> views</span>
                            </div>
                            <div class="article-answer">
                                <?= nl2br(e($article['answer'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Contact Form -->
            <div class="sidebar-card" style="margin-top:24px;">
                <div class="sidebar-title"><i class="fas fa-envelope"></i> Still need help?</div>
                <?php if (isset($success)): ?>
                    <div class="alert success"><?= e($success) ?></div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert error"><?= implode('<br>', array_map('e', $errors)) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" class="form-control" required value="<?= e($_POST['subject'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" class="form-control">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" class="form-control" required><?= e($_POST['message'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" name="submit_ticket" class="btn"><i class="fas fa-paper-plane"></i> Submit Ticket</button>
                </form>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Featured Articles -->
            <?php if (!empty($featuredArticles)): ?>
                <div class="sidebar-card">
                    <div class="sidebar-title"><i class="fas fa-star" style="color:var(--gold);"></i> Popular Articles</div>
                    <ul class="featured-list">
                        <?php foreach ($featuredArticles as $fa): ?>
                            <li>
                                <a href="#" onclick="scrollToArticle(<?= $fa['id'] ?>); return false;"><?= e($fa['question']) ?></a>
                                <div class="cat"><i class="far fa-folder"></i> <?= e($fa['category_name']) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- My Recent Tickets -->
            <div class="sidebar-card">
                <div class="sidebar-title"><i class="fas fa-ticket-alt"></i> My Support Tickets</div>
                <?php if (empty($myTickets)): ?>
                    <p style="color:var(--text-secondary);">No tickets submitted yet.</p>
                <?php else: ?>
                    <?php foreach ($myTickets as $ticket): ?>
                        <div class="ticket-item">
                            <div>
                                <div class="ticket-subject">#<?= $ticket['id'] ?> - <?= e($ticket['subject']) ?></div>
                                <div style="font-size:12px;color:var(--text-secondary);"><?= date('M d, Y', strtotime($ticket['created_at'])) ?></div>
                            </div>
                            <span class="ticket-status <?= $ticket['status'] ?>"><?= ucfirst($ticket['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <a href="my_tickets.php" style="display:block;margin-top:12px;font-size:14px;color:var(--brand);">View all tickets →</a>
                <?php endif; ?>
            </div>
            
            <!-- Quick Links -->
            <div class="sidebar-card">
                <div class="sidebar-title"><i class="fas fa-link"></i> Quick Links</div>
                <ul class="featured-list">
                    <li><a href="user_dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li><a href="monthly_exams.php"><i class="fas fa-calendar-alt"></i> Monthly Exams</a></li>
                    <li><a href="quizzes.php"><i class="fas fa-pencil-alt"></i> Quizzes</a></li>
                    <li><a href="multimedia.php"><i class="fas fa-photo-video"></i> Multimedia Library</a></li>
                </ul>
            </div>

            <!-- Contact Support -->
            <div class="sidebar-card">
                <div class="sidebar-title"><i class="fas fa-headset"></i> Contact Support</div>
                <ul class="contact-list">
                    <li>
                        <i class="fab fa-whatsapp"></i>
                        <a href="https://wa.me/255655058616" target="_blank">+255 655 058 616</a>
                    </li>
                    <li>
                        <i class="fas fa-phone-alt"></i>
                        <a href="tel:+255697145829">+255 697 145 829</a>
                    </li>
                </ul>
                <p style="font-size:12px; color:var(--text-secondary); margin-top:12px;">Available for direct calls and WhatsApp messages.</p>
            </div>
        </div>
    </div>
</div>
</main>

<script>
(function() {
    // Header & Sidebar functionality
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
    }
    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        if (body) body.style.overflow = '';
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
    let currentLang = localStorage.getItem('language') || 'EN';
    if (langToggle) {
        langToggle.textContent = currentLang;
        langToggle.addEventListener('click', () => {
            currentLang = currentLang === 'EN' ? 'SW' : 'EN';
            langToggle.textContent = currentLang;
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
            if (!profileArea.contains(e.target)) {
                profileMenu.classList.remove('show');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Toggle article expansion
    window.toggleArticle = function(element) {
        const articleItem = element.closest('.article-item');
        articleItem.classList.toggle('expanded');
        const icon = element.querySelector('.fa-chevron-down');
        if (icon) {
            icon.style.transform = articleItem.classList.contains('expanded') ? 'rotate(180deg)' : 'rotate(0deg)';
        }
        const articleId = articleItem.dataset.articleId;
        if (articleId && articleItem.classList.contains('expanded')) {
            fetch('help_center.php?action=view&id=' + articleId, { method: 'POST' }).catch(()=>{});
        }
    };
    
    // Search
    document.getElementById('searchInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            const url = new URL(window.location.href);
            url.searchParams.set('search', e.target.value);
            window.location.href = url.toString();
        }
    });
    
    // Scroll to article (for featured links)
    window.scrollToArticle = function(id) {
        const article = document.querySelector(`.article-item[data-article-id="${id}"]`);
        if (article) {
            article.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (!article.classList.contains('expanded')) {
                toggleArticle(article.querySelector('.article-question'));
            }
        }
    };
})();
</script>
</body>
</html>