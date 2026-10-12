<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';

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

function timeAgo(string $datetime): string {
    $time = new DateTime($datetime);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $time->getTimestamp();

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day ago';
    return $time->format('M d, Y');
}

function getTypeIcon(string $type): string {
    $icons = [
        'leaderboard'  => '🏆',
        'content'      => '📄',
        'quiz'         => '📝',
        'exam'         => '📅',
        'lesson'       => '📖',
        'subject'      => '📚',
        'multimedia'   => '🖼️',
        'announcement' => '📢',
        'system'       => 'ℹ️',
        'category'     => '🗂️',
        'certificate'  => '🎓'
    ];
    return $icons[$type] ?? '🔔';
}

function getTypeLabel(string $type): string {
    $labels = [
        'leaderboard'  => 'Leaderboard',
        'content'      => 'Content',
        'quiz'         => 'Quiz',
        'exam'         => 'Exam',
        'lesson'       => 'Lesson',
        'subject'      => 'Subject',
        'multimedia'   => 'Media',
        'announcement' => 'Announcement',
        'system'       => 'System',
        'category'     => 'Category',
        'certificate'  => 'Certificate'
    ];
    return $labels[$type] ?? ucfirst($type);
}

// Fallback function for getActiveAnnouncement (if not defined elsewhere)
if (!function_exists('getActiveAnnouncement')) {
    function getActiveAnnouncement(PDO $conn, string $role = 'user'): ?array {
        try {
            // Check if announcements table exists
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'announcements'
            ");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                return null;
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("
                SELECT id, title, message, audience_role, created_at
                FROM announcements
                WHERE is_published = 1
                  AND (audience_role = 'all' OR audience_role = ?)
                  AND (starts_at IS NULL OR starts_at <= ?)
                  AND (ends_at IS NULL OR ends_at >= ?)
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$role, $now, $now]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log("Error getting announcement: " . $e->getMessage());
            return null;
        }
    }
}


function syncCertificateNotifications(PDO $conn, int $userId): void {
    try {
        $stmt = $conn->prepare("
            SELECT
                c.id,
                c.certificate_code,
                c.certification_type,
                c.subject_id,
                c.title,
                c.issued_at,
                s.name AS subject_name
            FROM certificates c
            LEFT JOIN subjects s ON s.id = c.subject_id
            WHERE c.user_id = ?
              AND c.status = 'active'
        ");
        $stmt->execute([$userId]);
        $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($certificates as $cert) {
            $code = trim((string)($cert['certificate_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $link = 'certificate_view.php?code=' . urlencode($code);

            $check = $conn->prepare("
                SELECT id
                FROM notifications
                WHERE user_id = ?
                  AND type = 'certificate'
                  AND link = ?
                LIMIT 1
            ");
            $check->execute([$userId, $link]);

            if ($check->fetchColumn()) {
                continue;
            }

            $certType = (string)($cert['certification_type'] ?? '');
            $subjectName = trim((string)($cert['subject_name'] ?? ''));

            if ($certType === 'general') {
                $title = '🎓 General Certificate Earned';
                $message = 'Congratulations! You have earned your General Certificate for all subjects. You can now view, verify, and print it.';
            } else {
                $subjectLabel = $subjectName !== '' ? $subjectName : 'this subject';
                $title = '🎓 Subject Certificate Earned';
                $message = 'Congratulations! You have earned your certificate for ' . $subjectLabel . '. You can now view, verify, and print it.';
            }

            createNotification($conn, $userId, 'certificate', $title, $message, $link);
        }
    } catch (Throwable $e) {
        error_log('Certificate notification sync failed: ' . $e->getMessage());
    }
}


// --- Authentication ---
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

// Ensure notifications table exists
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
            `id` bigint NOT NULL AUTO_INCREMENT,
            `user_id` int NOT NULL,
            `type` enum('leaderboard','content','quiz','exam','lesson','subject','multimedia','announcement','system','category','certificate') NOT NULL,
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `link` varchar(500) DEFAULT NULL,
            `is_read` tinyint(1) NOT NULL DEFAULT '0',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `is_read` (`is_read`),
            KEY `type` (`type`),
            CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $conn->exec("
            ALTER TABLE `notifications`
            MODIFY `type` enum(
                'leaderboard',
                'content',
                'quiz',
                'exam',
                'lesson',
                'subject',
                'multimedia',
                'announcement',
                'system',
                'category',
                'certificate'
            ) NOT NULL
        ");
    } catch (PDOException $e) {
        error_log('Notification enum update warning: ' . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log('Table creation error: ' . $e->getMessage());
    die('A system error occurred. Please try again later.');
}

// --- CSRF Protection ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Handle AJAX POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['success' => false];

    try {
        if ($action === 'mark_read' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $response['success'] = $stmt->execute([$id, $userId]);
        } elseif ($action === 'mark_all_read') {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $response['success'] = $stmt->execute([$userId]);
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $response['success'] = $stmt->execute([$id, $userId]);
        }
    } catch (PDOException $e) {
        error_log("Notification AJAX error: " . $e->getMessage());
        $response['success'] = false;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

syncCertificateNotifications($conn, $userId);

// --- Pagination & Filters ---
$page = isset($_GET['page']) && ctype_digit($_GET['page']) ? (int)$_GET['page'] : 1;
$filter = $_GET['filter'] ?? 'all';
$type   = $_GET['type'] ?? 'all';

$notificationsData = getUserNotifications($conn, $userId, $filter, $type, $page, 20);
$notifications = $notificationsData['notifications'];
$total = $notificationsData['total'];
$unreadCount = $notificationsData['unreadCount'];

// Get active announcement
$announcementRow = getActiveAnnouncement($conn, $user['role']);
$announcement = [
    'is_published' => !empty($announcementRow) && !empty($announcementRow['message']),
    'title' => e($announcementRow['title'] ?? 'Announcement'),
    'message' => e($announcementRow['message'] ?? 'No new announcements for today.'),
    'date' => isset($announcementRow['created_at']) ? date('M d, Y', strtotime($announcementRow['created_at'])) : '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Notifications – MyIslam</title>
    <style>
        /* (CSS unchanged from previous update) */
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
    --announcement-bg: linear-gradient(135deg, #eef2f7 0%, #f9fafb 100%);
    --danger: #ef4444;
    --overlay-dim: rgba(0, 0, 0, 0.6);
    --glass-bg-strong: rgba(24, 33, 43, 0.92);

    background: var(--bg);
}
        body{
            font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);
            min-height:100vh;line-height:1.6;position:relative;
        }
        body::before{
            content:"";position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:-1;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%23166534' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat:repeat;
        }
        body.dark-mode::before{
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%237dd3a7' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
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
        .main-content{padding:20px;max-width:900px;margin:0 auto;width:100%}
        .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:16px}
        .page-title{font-size:28px;font-weight:700;color:var(--text)}
        .unread-badge{background:var(--danger);color:#fff;padding:4px 12px;border-radius:10px;font-size:14px;font-weight:600}

        /* Announcement card - fixed light/blue theme regardless of dark mode */
        .announcement-card{
            background:var(--announcement-bg);border-left:4px solid #3E6E91;border-radius:var(--radius-md);
            padding:16px 20px;margin-bottom:24px;position:relative;overflow:hidden;
            border-bottom: 1px solid #e2e5e2;
            border-top: 1px solid #e2e5e2;
            border-right: 1px solid #e2e5e2;
            color:#1f2937;
        }
        .announcement-card::before{
            content:"📢";position:absolute;right:20px;top:20px;font-size:48px;opacity:0.1;
        }
        .announcement-header{
            display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px;
        }
        .announcement-title{font-size:16px;font-weight:700;color:#3E6E91}
        .announcement-date{font-size:11px;color:#6b7280}
        .announcement-message{font-size:15px;line-height:1.6;font-weight:500;position:relative;z-index:1}

        /* Filters */
        .filter-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
        .filter-tab{
            padding:8px 18px;border-radius:10px;font-weight:600;font-size:14px;background:var(--surface);
            border:1px solid var(--line);color:var(--text-secondary);cursor:pointer;transition:all 0.2s;
            text-decoration:none;display:inline-flex;align-items:center;gap:6px;
        }
        .filter-tab.active{background:var(--brand);border-color:var(--brand);color:#fff}
        .filter-tab:hover:not(.active){background:var(--brand-soft);border-color:var(--brand-light)}

        /* Category dropdown (replaces overflowing category-tabs row, matches dashboard .filter-select) */
        .filter-select-wrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px}
        .filter-select-label{font-size:12px;font-weight:700;color:var(--text-secondary);white-space:nowrap}
        .filter-select{
            padding:9px 12px;border-radius:9px;border:1px solid var(--line);background:var(--surface);
            color:var(--text);font-size:13px;font-weight:600;cursor:pointer;min-width:200px;
        }
        .filter-select:hover{border-color:var(--brand)}
        .filter-select:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
        .actions-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px}
        .btn{
            padding:8px 16px;border-radius:10px;font-weight:600;font-size:14px;border:1px solid var(--line);cursor:pointer;
            background:var(--brand-soft);color:var(--brand);transition:all 0.2s;
        }
        .btn:hover{background:var(--brand);color:#fff}

        /* Notification list */
        .notification-list{display:flex;flex-direction:column;gap:12px}
        .notification-item{
            background:var(--surface);border-radius:var(--radius-md);padding:16px 20px;
            box-shadow:var(--shadow-sm);border:1px solid var(--line);display:flex;gap:16px;
            transition:all 0.2s;cursor:pointer;
        }
        .notification-item.unread{background:var(--brand-soft);border-left:4px solid var(--brand)}
        .notification-item:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
        .notification-icon{font-size:24px;flex-shrink:0}
        .notification-content{flex:1}
        .notification-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px}
        .notification-title{font-weight:700;font-size:16px;color:var(--text)}
        .notification-time{font-size:12px;color:var(--text-secondary)}
        .notification-message{color:var(--text-secondary);margin-bottom:8px;font-size:14px}
        .notification-meta{display:flex;gap:16px;font-size:12px;color:var(--text-secondary)}
        .notification-type{display:flex;align-items:center;gap:4px}
        .notification-actions{display:flex;gap:8px;flex-shrink:0;align-items:flex-start}
        .action-btn{
            background:none;border:none;color:var(--text-secondary);font-size:18px;cursor:pointer;
            padding:4px;border-radius:50%;transition:all 0.2s;width:32px;height:32px;display:flex;align-items:center;justify-content:center;
        }
        .action-btn:hover{background:var(--surface-2);color:var(--brand)}
        .empty-state{text-align:center;padding:60px 20px;color:var(--text-secondary)}
        .empty-state i{font-size:48px;margin-bottom:16px;opacity:0.5}
        .pagination{display:flex;justify-content:center;gap:8px;margin-top:32px}
        .pagination a,.pagination span{
            display:inline-block;padding:8px 14px;border-radius:var(--radius-sm);background:var(--surface);
            border:1px solid var(--line);color:var(--text);text-decoration:none;font-weight:500;
        }
        .pagination .current{background:var(--brand);color:#fff;border-color:var(--brand)}

        @media (max-width:768px){
            .main-content{padding:16px}
            .app-header{padding:12px 16px}
            .notification-item{padding:14px}
            .notification-actions{flex-direction:column}
        }
    </style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
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
    <div class="page-header">
        <h1 class="page-title">🔔 Notifications</h1>
        <?php if ($unreadCount > 0): ?>
            <span class="unread-badge"><?= $unreadCount ?> unread</span>
        <?php endif; ?>
    </div>

    <!-- Announcement -->
    <div class="announcement-card">
        <?php if ($announcement['is_published']): ?>
            <div class="announcement-header">
                <span class="announcement-title">📢 <?= $announcement['title'] ?></span>
                <?php if ($announcement['date']): ?>
                    <span class="announcement-date"><?= e($announcement['date']) ?></span>
                <?php endif; ?>
            </div>
            <div class="announcement-message"><?= $announcement['message'] ?></div>
        <?php else: ?>
            <div class="announcement-header">
                <span class="announcement-title">📢 No Announcements</span>
            </div>
            <div class="announcement-message">You're all caught up! Check back later for updates.</div>
        <?php endif; ?>
    </div>

    <!-- Filter: All / Unread / Read -->
    <div class="filter-tabs">
        <a href="?filter=all&type=<?= urlencode($type) ?>&page=1" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">All</a>
        <a href="?filter=unread&type=<?= urlencode($type) ?>&page=1" class="filter-tab <?= $filter === 'unread' ? 'active' : '' ?>">Unread</a>
        <a href="?filter=read&type=<?= urlencode($type) ?>&page=1" class="filter-tab <?= $filter === 'read' ? 'active' : '' ?>">Read</a>
    </div>

    <!-- Category filter (dropdown, to save space) -->
    <div class="filter-select-wrap">
        <label for="categoryTypeSelect" class="filter-select-label">🗂️ Category</label>
        <select id="categoryTypeSelect" class="filter-select" data-base-filter="<?= e($filter) ?>">
            <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
            <option value="quiz" <?= $type === 'quiz' ? 'selected' : '' ?>>📝 Quizzes</option>
            <option value="exam" <?= $type === 'exam' ? 'selected' : '' ?>>📅 Exams</option>
            <option value="lesson" <?= $type === 'lesson' ? 'selected' : '' ?>>📖 Lessons</option>
            <option value="subject" <?= $type === 'subject' ? 'selected' : '' ?>>📚 Subjects</option>
            <option value="category" <?= $type === 'category' ? 'selected' : '' ?>>🗂️ Categories</option>
            <option value="certificate" <?= $type === 'certificate' ? 'selected' : '' ?>>🎓 Certificates</option>
            <option value="multimedia" <?= $type === 'multimedia' ? 'selected' : '' ?>>🖼️ Media</option>
            <option value="leaderboard" <?= $type === 'leaderboard' ? 'selected' : '' ?>>🏆 Leaderboard</option>
            <option value="announcement" <?= $type === 'announcement' ? 'selected' : '' ?>>📢 Announcements</option>
        </select>
    </div>

    <div class="actions-bar">
        <?php if ($unreadCount > 0): ?>
            <button class="btn" id="markAllReadBtn">✓ Mark all as read</button>
        <?php endif; ?>
    </div>

    <div class="notification-list" id="notificationList">
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <i>🔔</i>
                <h3>No notifications</h3>
                <p>You're all caught up! Check back later.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>" data-id="<?= $notif['id'] ?>" data-link="<?= e($notif['link'] ?? '') ?>">
                    <div class="notification-icon"><?= getTypeIcon($notif['type']) ?></div>
                    <div class="notification-content" onclick="handleNotificationClick(this)">
                        <div class="notification-header">
                            <span class="notification-title"><?= e($notif['title']) ?></span>
                            <span class="notification-time"><?= timeAgo($notif['created_at']) ?></span>
                        </div>
                        <div class="notification-message"><?= nl2br(e($notif['message'])) ?></div>
                        <div class="notification-meta">
                            <span class="notification-type"><?= getTypeIcon($notif['type']) ?> <?= getTypeLabel($notif['type']) ?></span>
                        </div>
                    </div>
                    <div class="notification-actions">
                        <?php if (!$notif['is_read']): ?>
                            <button class="action-btn mark-read-btn" title="Mark as read" onclick="event.stopPropagation(); markAsRead(<?= $notif['id'] ?>, this)">✓</button>
                        <?php endif; ?>
                        <button class="action-btn delete-btn" title="Delete" onclick="event.stopPropagation(); deleteNotification(<?= $notif['id'] ?>, this)">🗑️</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($total > 20): ?>
        <div class="pagination">
            <?php
            $totalPages = ceil($total / 20);
            for ($i = 1; $i <= $totalPages; $i++):
                $active = ($i === $page) ? 'current' : '';
                $url = "?filter=" . urlencode($filter) . "&type=" . urlencode($type) . "&page=$i";
            ?>
                <?php if ($i === $page): ?>
                    <span class="<?= $active ?>"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= $url ?>" class="<?= $active ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
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

    // Notification functions (global for inline handlers)
    const csrfToken = "<?= $_SESSION['csrf_token'] ?>";

    async function postJSON(action, data = {}) {
        data.action = action;
        data.csrf_token = csrfToken;
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(data)
        });
        return response.json();
    }

    window.handleNotificationClick = function(contentEl) {
        const item = contentEl.closest('.notification-item');
        const link = item.dataset.link;
        const id = item.dataset.id;

        if (item.classList.contains('unread')) {
            markAsRead(id, item.querySelector('.mark-read-btn'));
        }

        if (link && link !== '') {
            window.location.href = link;
        }
    };

    window.markAsRead = async function(id, btn) {
        const item = btn.closest('.notification-item');
        try {
            const data = await postJSON('mark_read', { id: id });
            if (data.success) {
                item.classList.remove('unread');
                if (btn) btn.remove();
                const unreadBadge = document.querySelector('.unread-badge');
                if (unreadBadge) {
                    let count = parseInt(unreadBadge.textContent);
                    if (!isNaN(count)) {
                        count--;
                        if (count > 0) unreadBadge.textContent = count + ' unread';
                        else unreadBadge.remove();
                    }
                }
                const markAllBtn = document.getElementById('markAllReadBtn');
                if (markAllBtn && document.querySelectorAll('.notification-item.unread').length === 0) {
                    markAllBtn.remove();
                }
            }
        } catch (e) {
            console.error('Failed to mark as read:', e);
        }
    };

    window.deleteNotification = async function(id, btn) {
        if (!confirm('Delete this notification?')) return;
        const item = btn.closest('.notification-item');
        try {
            const data = await postJSON('delete', { id: id });
            if (data.success) {
                item.remove();
                if (document.querySelectorAll('.notification-item').length === 0) {
                    document.getElementById('notificationList').innerHTML = `
                        <div class="empty-state">
                            <i>🔔</i>
                            <h3>No notifications</h3>
                            <p>You're all caught up! Check back later.</p>
                        </div>
                    `;
                }
            }
        } catch (e) {
            console.error('Failed to delete:', e);
        }
    };

    document.getElementById('markAllReadBtn')?.addEventListener('click', async function() {
        if (!confirm('Mark all notifications as read?')) return;
        try {
            const data = await postJSON('mark_all_read');
            if (data.success) {
                location.reload();
            }
        } catch (e) {
            console.error('Failed to mark all read:', e);
        }
    });

    // Category dropdown navigates on change (mirrors dashboard filter-select)
    const categoryTypeSelect = document.getElementById('categoryTypeSelect');
    if (categoryTypeSelect) {
        categoryTypeSelect.addEventListener('change', () => {
            const filter = categoryTypeSelect.getAttribute('data-base-filter') || 'all';
            window.location.href = '?filter=' + encodeURIComponent(filter) + '&type=' + encodeURIComponent(categoryTypeSelect.value) + '&page=1';
        });
    }
})();
</script>
</body>
</html>