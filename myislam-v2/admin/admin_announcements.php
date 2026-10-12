<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';
// ========== ADDED: Include notification functions ==========
require_once __DIR__ . '/../includes/notifications.php';
// ===========================================================

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

function getAnnouncements(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT
                id, title, message, audience_role, is_published,
                starts_at, ends_at, created_at
            FROM announcements
            ORDER BY created_at DESC, id DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getAnnouncements: " . $e->getMessage());
        return [];
    }
}

function getAnnouncementById(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT id, title, message, audience_role, is_published, starts_at, ends_at
            FROM announcements
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getAnnouncementById: " . $e->getMessage());
        return null;
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
$editingAnnouncement = null;
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_announcement') {
        $announcementId = isset($_POST['announcement_id']) ? (int)$_POST['announcement_id'] : 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $msg = trim((string)($_POST['message'] ?? ''));
        $audienceRole = trim((string)($_POST['audience_role'] ?? 'all'));
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        $startsAt = trim((string)($_POST['starts_at'] ?? ''));
        $endsAt = trim((string)($_POST['ends_at'] ?? ''));

        if (!in_array($audienceRole, ['all', 'user', 'admin'], true)) {
            $audienceRole = 'all';
        }

        $startsAtDb = $startsAt !== '' ? str_replace('T', ' ', $startsAt) . ':00' : null;
        $endsAtDb = $endsAt !== '' ? str_replace('T', ' ', $endsAt) . ':00' : null;

        if ($title === '' || $msg === '') {
            $message = 'Title and message are required.';
            $messageType = 'danger';
        } else {
            try {
                if ($announcementId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE announcements
                        SET title = ?, message = ?, audience_role = ?, is_published = ?, starts_at = ?, ends_at = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$title, $msg, $audienceRole, $isPublished, $startsAtDb, $endsAtDb, $announcementId]);
                    
                    // ========== ADDED: Notification for updated/published announcement ==========
                    if ($isPublished) {
                        $notifyTitle = "Announcement Updated: " . $title;
                        $notifyMessage = $msg;
                        $notifyLink = null; // No specific link, or you can add one if needed
                        $roleFilter = mapAudienceToRoleFilter($audienceRole);
                        createNotificationForAll($conn, 'announcement', $notifyTitle, $notifyMessage, $notifyLink, $roleFilter);
                    }
                    // =====================================================================
                    
                    $message = 'Announcement updated successfully.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO announcements
                        (title, message, audience_role, is_published, starts_at, ends_at, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$title, $msg, $audienceRole, $isPublished, $startsAtDb, $endsAtDb]);
                    
                    // ========== ADDED: Notification for new published announcement ==========
                    if ($isPublished) {
                        $notifyTitle = "New Announcement: " . $title;
                        $notifyMessage = $msg;
                        $notifyLink = null;
                        $roleFilter = mapAudienceToRoleFilter($audienceRole);
                        createNotificationForAll($conn, 'announcement', $notifyTitle, $notifyMessage, $notifyLink, $roleFilter);
                    }
                    // =====================================================================
                    
                    $message = 'Announcement created successfully.';
                }
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("save_announcement: " . $e->getMessage());
                $message = 'Unable to save announcement.';
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }

    if ($action === 'toggle_publish') {
        $announcementId = isset($_POST['announcement_id']) ? (int)$_POST['announcement_id'] : 0;
        if ($announcementId > 0) {
            try {
                // Get current status to know if we are publishing or unpublishing
                $stmt = $conn->prepare("SELECT is_published, title, message, audience_role FROM announcements WHERE id = ?");
                $stmt->execute([$announcementId]);
                $current = $stmt->fetch();
                
                $newPublished = $current['is_published'] ? 0 : 1;
                
                $stmt = $conn->prepare("
                    UPDATE announcements
                    SET is_published = ?
                    WHERE id = ?
                ");
                $stmt->execute([$newPublished, $announcementId]);
                
                // ========== ADDED: Notification when toggled to published ==========
                if ($newPublished === 1) {
                    $notifyTitle = "Announcement: " . $current['title'];
                    $notifyMessage = $current['message'];
                    $notifyLink = null;
                    $roleFilter = mapAudienceToRoleFilter($current['audience_role']);
                    createNotificationForAll($conn, 'announcement', $notifyTitle, $notifyMessage, $notifyLink, $roleFilter);
                }
                // =====================================================================
                
                $message = 'Announcement publish status updated.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("toggle_publish: " . $e->getMessage());
                $message = 'Unable to update publish status.';
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }

    if ($action === 'delete_announcement') {
        $announcementId = isset($_POST['announcement_id']) ? (int)$_POST['announcement_id'] : 0;
        if ($announcementId > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
                $stmt->execute([$announcementId]);
                $message = 'Announcement deleted.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("delete_announcement: " . $e->getMessage());
                $message = 'Unable to delete announcement.';
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }
}

// ========== ADDED: Helper to map announcement audience_role to notification role filter ==========
function mapAudienceToRoleFilter(string $audience): string|array|null {
    return match ($audience) {
        'all'   => null,              // all active users
        'user'  => 'user',            // only students
        'admin' => ['admin', 'owner', 'sheikh'], // all admin-level roles
        default => null,
    };
}
// =============================================================================================

$announcements = getAnnouncements($conn);
$totalAnnouncements = count($announcements);
$publishedAnnouncements = count(array_filter($announcements, fn($a) => (int)($a['is_published'] ?? 0) === 1));
$scheduledAnnouncements = count(array_filter($announcements, fn($a) => !empty($a['starts_at']) || !empty($a['ends_at'])));
$adminOnlyAnnouncements = count(array_filter($announcements, fn($a) => ($a['audience_role'] ?? '') === 'admin'));

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => true],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false, 'icon' => '🗂️'],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false, 'icon' => '📘'],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false, 'icon' => '👥'],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => true, 'icon' => '📢'],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false, 'icon' => '⚙️'],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false, 'icon' => '🚪'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Announcements - MyIslam</title>
<style>
/* ... CSS unchanged ... */
*{box-sizing:border-box;margin:0;padding:0}
:root{
--bg:#f5f7f5;--surface:#ffffff;--surface-2:#f9fafb;--surface-3:#eef2f7;--line:#e5e7eb;
--text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;--brand:#166534;--brand-soft:#e7f3eb;
--accent:#3b82f6;--warning:#f59e0b;--danger:#ef4444;--success:#10b981;--header-bg:#e1f0e7;
--header-height:72px;--footer-height:92px;
--shadow-sm:0 1px 2px 0 rgb(0 0 0 / 0.05);--shadow-md:0 4px 6px -1px rgb(0 0 0 / 0.1);--shadow-lg:0 10px 15px -3px rgb(0 0 0 / 0.1);
--radius-sm:12px;--radius-md:16px;--radius-lg:20px;
}
body.dark-mode{
--bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;--line:#304150;
--text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;--brand:#7dd3a7;--brand-soft:rgba(125,211,167,0.12);
--accent:#60a5fa;--header-bg:#13202a;
}
body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5}
a{text-decoration:none;color:inherit} button,input,textarea,select{font:inherit}
.app-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:var(--header-height);background:var(--header-bg);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;backdrop-filter:blur(10px);gap:18px}
.header-left,.header-right{display:flex;align-items:center;gap:12px}
.header-left{flex:1;min-width:0}
.header-top-nav{display:flex;align-items:center;gap:8px;overflow-x:auto}
.header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-size:13px;font-weight:700;transition:.2s}
.header-top-link:hover,.header-top-link.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
.menu-toggle,.icon-btn,.lang-btn,.brand-box{height:44px;min-width:44px;border-radius:var(--radius-sm);background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;transition:.2s;cursor:pointer}
.menu-toggle:hover,.icon-btn:hover,.lang-btn:hover,.profile-btn:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
.menu-toggle{font-size:22px}.lang-btn{min-width:56px;padding:0 14px;font-weight:600}
.brand-box{font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none;white-space:nowrap}
.profile-area{position:relative}
.profile-btn{width:44px;height:44px;border-radius:50%;overflow:hidden;background:var(--surface);border:2px solid var(--brand);cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center;color:var(--brand);font-weight:700}
.profile-btn img{width:100%;height:100%;object-fit:cover}
.profile-menu{position:absolute;top:56px;right:0;width:280px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:16px;display:none;box-shadow:var(--shadow-lg);z-index:1001}
.profile-menu.show{display:block}
.profile-menu-top{display:flex;gap:12px;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--line)}
.profile-menu-top img,.profile-avatar-fallback{width:56px;height:56px;border-radius:50%}
.profile-avatar-fallback{display:inline-flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:700;font-size:16px}
.profile-menu-top h4{font-size:16px;margin-bottom:4px;font-weight:600}
.profile-menu-top p{font-size:12px;color:var(--muted)}
.profile-menu a{display:block;padding:10px 12px;border-radius:var(--radius-sm);font-size:14px;transition:background .2s;margin:4px 0}
.profile-menu a:hover{background:var(--brand-soft);color:var(--brand)}
.sidebar-overlay{display:none;position:fixed;top:var(--header-height);left:0;width:100%;height:calc(100vh - var(--header-height));background:rgba(0,0,0,.5);z-index:1100;backdrop-filter:blur(4px)}
.sidebar-overlay.show{display:block}
.sidebar{position:fixed;top:var(--header-height);left:-100%;width:320px;height:calc(100vh - var(--header-height));background:var(--surface);border-right:1px solid var(--line);padding:24px 20px;z-index:1200;transition:left .3s ease;overflow-y:auto}
.sidebar.open{left:0}
.sidebar-title{font-size:12px;color:var(--muted);text-transform:uppercase;margin-bottom:20px;font-weight:700;letter-spacing:1px;padding:0 8px}
.subject-list{display:grid;gap:8px}
.subject-link{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--radius-md);background:var(--surface-2);border:1px solid transparent;font-size:14px;font-weight:500;transition:all .2s}
.subject-link:hover{background:var(--brand-soft);border-color:var(--brand);transform:translateX(4px)}
.subject-link.active{background:var(--brand-soft);color:var(--brand);border-color:var(--brand)}
.main-content{padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px);max-width:1450px;margin:0 auto}
.page-stack{display:grid;gap:24px}
.section-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px;transition:.2s}
.section-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none;display:flex;justify-content:space-between;align-items:center}
.hero-card h1{font-size:32px;margin-bottom:8px;font-weight:700}
.hero-card p{color:var(--text-secondary);font-size:16px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
.stat-card{text-align:center;padding:20px}
.stat-value{font-size:32px;font-weight:800;color:var(--brand);margin-bottom:8px}
.stat-label{font-size:13px;color:var(--muted)}
.filters-row{margin-bottom:16px}
.search-wrap{position:relative}
.search-input{width:100%;height:48px;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:0 16px 0 46px;outline:none}
.search-input:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:18px}
.announcement-list{display:grid;gap:12px}
.announcement-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px}
.announcement-title{font-size:16px;font-weight:800;margin-bottom:4px}
.announcement-meta{font-size:12px;color:var(--muted);line-height:1.7}
.announcement-actions{display:flex;gap:10px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;background:var(--brand-soft);color:var(--brand)}
.badge.warn{background:rgba(245,158,11,.12);color:var(--warning)}
.badge.neutral{background:var(--surface-3);color:var(--muted)}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.btn{padding:10px 16px;border-radius:14px;font-weight:600;border:none;cursor:pointer;transition:.2s;font-size:13px;display:inline-flex;align-items:center;gap:6px}
.btn.primary{background:var(--brand);color:#fff}
.btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
.btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
.btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.btn:hover{transform:translateY(-2px)}


/* Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center}
.modal{background:var(--surface);border-radius:var(--radius-lg);max-width:600px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid var(--line)}
.modal-header{padding:24px 24px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.modal-header h2{font-size:20px;font-weight:700}
.modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted)}
.modal-body{padding:24px}
.form-fields{display:grid;gap:14px}
.form-row{display:grid;gap:8px}
.form-row label{font-size:14px;font-weight:700}
.form-input,.form-textarea,.form-select{width:100%;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:12px 14px;outline:none}
.form-input:focus,.form-textarea:focus,.form-select:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.form-textarea{min-height:140px;resize:vertical}
.inline-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.check-row{display:flex;align-items:center;gap:10px;padding-top:6px}
.check-row input{width:18px;height:18px}
.action-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}

/* Toast */
.toast-container{position:fixed;top:80px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px}
.toast{padding:16px 20px;border-radius:var(--radius-md);background:var(--success);color:#fff;box-shadow:var(--shadow-lg);font-weight:500;display:flex;align-items:center;gap:10px;animation:slideIn 0.3s ease;max-width:400px}
.toast.success{background:#10b981}
.toast.danger{background:#ef4444}
.toast .close-toast{margin-left:auto;cursor:pointer;font-size:20px;opacity:0.8}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}

@media (max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:950px){.header-top-nav{display:none}}
@media (max-width:768px){
:root{--header-height:64px;--footer-height:80px}
.main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}
.section-card{padding:16px}.hero-card h1{font-size:24px}.stats-grid,.footer-grid,.inline-row{grid-template-columns:1fr;gap:12px}.announcement-item{flex-direction:column}
}
</style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="brand-box">🛠️ Admin</div>
        <nav class="header-top-nav" aria-label="Admin navigation">
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
        <button class="icon-btn" aria-label="Notifications">🔔</button>

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
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
                        <h4><?php echo e($admin['full_name'] ?? 'Admin'); ?></h4>
                        <p><?php echo e($admin['email'] ?? ''); ?></p>
                        <p style="font-size:10px;">Role: <?php echo e(ucfirst((string)($admin['role'] ?? 'admin'))); ?></p>
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
                <div>
                    <h1>📢 Manage Announcements</h1>
                    <p>Create, schedule, publish, and target announcements.</p>
                </div>
                <button class="btn primary" id="createAnnouncementBtn">➕ Create Announcement</button>
            </section>

            <div class="stats-grid">
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $totalAnnouncements; ?></div>
                    <div class="stat-label">Total Announcements</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $publishedAnnouncements; ?></div>
                    <div class="stat-label">Published</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $scheduledAnnouncements; ?></div>
                    <div class="stat-label">Scheduled</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $adminOnlyAnnouncements; ?></div>
                    <div class="stat-label">Admin Only</div>
                </div>
            </div>

            <section class="section-card">
                <div class="filters-row">
                    <div class="search-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="announcementSearchInput" class="search-input" placeholder="Search by title, message, audience...">
                    </div>
                </div>

                <?php if (!empty($announcements)): ?>
                    <div class="announcement-list" id="announcementList">
                        <?php foreach ($announcements as $item): ?>
                            <?php
                            $searchBlob = strtolower(
                                ($item['title'] ?? '') . ' ' .
                                ($item['message'] ?? '') . ' ' .
                                ($item['audience_role'] ?? '')
                            );
                            ?>
                            <div class="announcement-item announcement-search-item" data-search="<?php echo e($searchBlob); ?>" data-id="<?php echo (int)$item['id']; ?>">
                                <div>
                                    <div class="announcement-title"><?php echo e($item['title'] ?? 'Untitled'); ?></div>
                                    <div class="announcement-meta">
                                        Audience: <?php echo e(ucfirst((string)($item['audience_role'] ?? 'all'))); ?><br>
                                        Created: <?php echo !empty($item['created_at']) ? e(date('M d, Y H:i', strtotime((string)$item['created_at']))) : '--'; ?><br>
                                        Start: <?php echo !empty($item['starts_at']) ? e(date('M d, Y H:i', strtotime((string)$item['starts_at']))) : 'Immediate'; ?><br>
                                        End: <?php echo !empty($item['ends_at']) ? e(date('M d, Y H:i', strtotime((string)$item['ends_at']))) : 'No end'; ?><br>
                                        <?php echo nl2br(e($item['message'] ?? '')); ?>
                                    </div>
                                </div>

                                <div class="announcement-actions">
                                    <span class="badge neutral"><?php echo e(ucfirst((string)($item['audience_role'] ?? 'all'))); ?></span>
                                    <span class="badge <?php echo (int)($item['is_published'] ?? 0) === 1 ? '' : 'warn'; ?>">
                                        <?php echo (int)($item['is_published'] ?? 0) === 1 ? 'Published' : 'Draft'; ?>
                                    </span>
                                    <button class="btn secondary edit-announcement-btn" data-id="<?php echo (int)$item['id']; ?>">✏️ Edit</button>
                                    <button class="btn <?php echo (int)($item['is_published'] ?? 0) === 1 ? 'warn' : 'primary'; ?> toggle-publish-btn" data-id="<?php echo (int)$item['id']; ?>">
                                        <?php echo (int)($item['is_published'] ?? 0) === 1 ? '📄 Unpublish' : '🚀 Publish'; ?>
                                    </button>
                                    <button class="btn danger delete-announcement-btn" data-id="<?php echo (int)$item['id']; ?>">🗑️ Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="emptySearchState" class="empty-state" style="display:none;">No matching announcements found.</div>
                <?php else: ?>
                    <div class="empty-state">No announcements found. Click "Create Announcement" to add one.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>

   
</div>

<!-- Announcement Modal -->
<div class="modal-overlay" id="announcementModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Create New Announcement</h2>
            <button class="modal-close" id="closeModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="announcementForm">
                <input type="hidden" name="action" value="save_announcement">
                <input type="hidden" name="announcement_id" id="announcement_id" value="0">

                <div class="form-row">
                    <label for="title">Title *</label>
                    <input type="text" id="title" name="title" class="form-input" required>
                </div>

                <div class="form-row">
                    <label for="message">Message *</label>
                    <textarea id="message" name="message" class="form-textarea" required></textarea>
                </div>

                <div class="form-row">
                    <label for="audience_role">Audience</label>
                    <select id="audience_role" name="audience_role" class="form-select">
                        <option value="all">All</option>
                        <option value="user">Users</option>
                        <option value="admin">Admins</option>
                    </select>
                </div>

                <div class="inline-row">
                    <div class="form-row">
                        <label for="starts_at">Starts At</label>
                        <input type="datetime-local" id="starts_at" name="starts_at" class="form-input">
                    </div>
                    <div class="form-row">
                        <label for="ends_at">Ends At</label>
                        <input type="datetime-local" id="ends_at" name="ends_at" class="form-input">
                    </div>
                </div>

                <label class="check-row">
                    <input type="checkbox" name="is_published" id="is_published" checked>
                    <span>Published</span>
                </label>

                <div class="action-row">
                    <button type="submit" class="btn primary">Save Announcement</button>
                    <button type="button" class="btn secondary" id="cancelModalBtn">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toast
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <span>${type === 'success' ? '✅' : '❌'}</span>
        <span>${message}</span>
        <span class="close-toast" onclick="this.parentElement.remove()">&times;</span>
    `;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}

// Modal
const modal = document.getElementById('announcementModal');
const createBtn = document.getElementById('createAnnouncementBtn');
const closeModalBtn = document.getElementById('closeModalBtn');
const cancelModalBtn = document.getElementById('cancelModalBtn');
const form = document.getElementById('announcementForm');
const modalTitle = document.getElementById('modalTitle');

function openModal(data = null) {
    if (data) {
        modalTitle.textContent = 'Edit Announcement';
        document.getElementById('announcement_id').value = data.id || 0;
        document.getElementById('title').value = data.title || '';
        document.getElementById('message').value = data.message || '';
        document.getElementById('audience_role').value = data.audience_role || 'all';
        document.getElementById('starts_at').value = data.starts_at ? data.starts_at.replace(' ', 'T').slice(0,16) : '';
        document.getElementById('ends_at').value = data.ends_at ? data.ends_at.replace(' ', 'T').slice(0,16) : '';
        document.getElementById('is_published').checked = data.is_published == 1;
    } else {
        modalTitle.textContent = 'Create New Announcement';
        form.reset();
        document.getElementById('announcement_id').value = '0';
        document.getElementById('is_published').checked = true;
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

createBtn.addEventListener('click', () => openModal());
closeModalBtn.addEventListener('click', closeModal);
cancelModalBtn.addEventListener('click', closeModal);
modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

// Form submit AJAX
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(form);
    try {
        const response = await fetch('admin_announcements.php', {
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const result = await response.json();
        showToast(result.message, result.success ? 'success' : 'danger');
        if (result.success) {
            closeModal();
            setTimeout(() => location.reload(), 1000);
        }
    } catch (err) {
        showToast('An error occurred', 'danger');
    }
});

// Edit buttons
document.querySelectorAll('.edit-announcement-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        try {
            const response = await fetch(`admin_announcements.php?edit=${id}&ajax=1`);
            const data = await response.json();
            openModal(data);
        } catch (err) {
            showToast('Failed to load announcement', 'danger');
        }
    });
});

// Toggle publish
document.querySelectorAll('.toggle-publish-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        const formData = new FormData();
        formData.append('action', 'toggle_publish');
        formData.append('announcement_id', id);
        try {
            const response = await fetch('admin_announcements.php', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            showToast(result.message, result.success ? 'success' : 'danger');
            if (result.success) setTimeout(() => location.reload(), 1000);
        } catch (err) {
            showToast('An error occurred', 'danger');
        }
    });
});

// Delete announcement
document.querySelectorAll('.delete-announcement-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!confirm('Delete this announcement?')) return;
        const id = btn.dataset.id;
        const formData = new FormData();
        formData.append('action', 'delete_announcement');
        formData.append('announcement_id', id);
        try {
            const response = await fetch('admin_announcements.php', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            showToast(result.message, result.success ? 'success' : 'danger');
            if (result.success) setTimeout(() => location.reload(), 1000);
        } catch (err) {
            showToast('An error occurred', 'danger');
        }
    });
});

// Search filter
const searchInput = document.getElementById('announcementSearchInput');
const items = document.querySelectorAll('.announcement-search-item');
const emptyState = document.getElementById('emptySearchState');

function applySearch() {
    const query = (searchInput?.value || '').trim().toLowerCase();
    let visible = 0;
    items.forEach(item => {
        const haystack = item.dataset.search || '';
        const show = !query || haystack.includes(query);
        item.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    if (emptyState) emptyState.style.display = visible === 0 ? 'block' : 'none';
}
searchInput?.addEventListener('input', applySearch);

// Sidebar & theme
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

    function openSidebar() { sidebar?.classList.add('open'); sidebarOverlay?.classList.add('show'); body.style.overflow = 'hidden'; }
    function closeSidebar() { sidebar?.classList.remove('open'); sidebarOverlay?.classList.remove('show'); body.style.overflow = ''; }
    menuToggle?.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    sidebarOverlay?.addEventListener('click', closeSidebar);

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') { body.classList.add('dark-mode'); if(themeToggle) themeToggle.textContent = '☀'; }
    themeToggle?.addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        const isDark = body.classList.contains('dark-mode');
        themeToggle.textContent = isDark ? '☀' : '🌙';
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });

    let currentLang = localStorage.getItem('language') || 'EN';
    if (langToggle) { langToggle.textContent = currentLang; }
    langToggle?.addEventListener('click', () => {
        currentLang = currentLang === 'EN' ? 'SW' : 'EN';
        langToggle.textContent = currentLang;
        localStorage.setItem('language', currentLang);
    });

    profileToggle?.addEventListener('click', e => { e.stopPropagation(); profileMenu?.classList.toggle('show'); });
    document.addEventListener('click', e => { if (!profileArea?.contains(e.target)) profileMenu?.classList.remove('show'); });
})();

// AJAX endpoint for edit
<?php
if (isset($_GET['ajax']) && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editAnnouncement = getAnnouncementById($conn, $editId);
    if ($editAnnouncement) {
        header('Content-Type: application/json');
        echo json_encode($editAnnouncement);
        exit;
    }
}
?>
</script>
</body>
</html>