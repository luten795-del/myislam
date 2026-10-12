<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';

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

function getUsers(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT
                u.id,
                u.full_name,
                u.email,
                u.profile_image,
                u.role,
                u.is_active,
                u.is_verified,
                u.created_at,
                COUNT(DISTINCT qa.id) AS quizzes_taken,
                COUNT(DISTINCT ucp.content_id) AS contents_started
            FROM users u
            LEFT JOIN quiz_attempts qa ON qa.user_id = u.id
            LEFT JOIN user_content_progress ucp ON ucp.user_id = u.id
            GROUP BY
                u.id, u.full_name, u.email, u.profile_image, u.role,
                u.is_active, u.is_verified, u.created_at
            ORDER BY u.created_at DESC, u.id DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getUsers: " . $e->getMessage());
        return [];
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
    $action = $_POST['action'] ?? '';
    $targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    if ($targetUserId > 0 && $targetUserId !== $currentUserId) {
        try {
            if ($action === 'toggle_active') {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                    WHERE id = ?
                ");
                $stmt->execute([$targetUserId]);
                $message = 'User status updated.';
            }

            if ($action === 'toggle_verified') {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET is_verified = CASE WHEN is_verified = 1 THEN 0 ELSE 1 END
                    WHERE id = ?
                ");
                $stmt->execute([$targetUserId]);
                $message = 'User verification updated.';
            }

            if ($action === 'toggle_role') {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET role = CASE WHEN role = 'admin' THEN 'user' ELSE 'admin' END
                    WHERE id = ?
                ");
                $stmt->execute([$targetUserId]);
                $message = 'User role updated.';
            }
        } catch (PDOException $e) {
            error_log("user action: " . $e->getMessage());
            $message = 'Unable to update user.';
            $messageType = 'danger';
        }
    } elseif ($targetUserId === $currentUserId) {
        $message = 'You cannot change your own admin access from this page.';
        $messageType = 'danger';
    }
}

$users = getUsers($conn);

$totalUsers = count($users);
$activeUsers = count(array_filter($users, fn($u) => (int)($u['is_active'] ?? 0) === 1));
$verifiedUsers = count(array_filter($users, fn($u) => (int)($u['is_verified'] ?? 0) === 1));
$adminUsers = count(array_filter($users, fn($u) => strtolower((string)($u['role'] ?? 'user')) === 'admin'));

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => true],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false, 'icon' => '🗂️'],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false, 'icon' => '📘'],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => true, 'icon' => '👥'],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false, 'icon' => '📢'],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false, 'icon' => '⚙️'],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false, 'icon' => '🚪'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Users - MyIslam</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
--bg:#f5f7f5;--surface:#fff;--surface-2:#f9fafb;--surface-3:#eef2f7;--line:#e5e7eb;
--text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;--brand:#166534;--brand-soft:#e7f3eb;
--warning:#f59e0b;--danger:#ef4444;--header-bg:#e1f0e7;--header-height:72px;--footer-height:92px;
--shadow-sm:0 1px 2px 0 rgb(0 0 0 / .05);--shadow-md:0 4px 6px -1px rgb(0 0 0 / .1);--shadow-lg:0 10px 15px -3px rgb(0 0 0 / .1);
--radius-sm:12px;--radius-md:16px;--radius-lg:20px;
}
body.dark-mode{
--bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;--line:#304150;
--text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;--brand:#7dd3a7;--brand-soft:rgba(125,211,167,.12);--header-bg:#13202a;
}
body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5}
a{text-decoration:none;color:inherit} button,input,select{font:inherit}
.app-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:var(--header-height);background:var(--header-bg);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;gap:18px}
.header-left,.header-right{display:flex;align-items:center;gap:12px}.header-left{flex:1;min-width:0}
.header-top-nav{display:flex;align-items:center;gap:8px;overflow-x:auto}
.header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;white-space:nowrap;font-size:13px;font-weight:700}
.header-top-link.active,.header-top-link:hover{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
.menu-toggle,.icon-btn,.lang-btn,.brand-box{height:44px;min-width:44px;border-radius:12px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
.brand-box{font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none}
.profile-area{position:relative}
.profile-btn{width:44px;height:44px;border-radius:50%;overflow:hidden;background:var(--surface);border:2px solid var(--brand);display:flex;align-items:center;justify-content:center;color:var(--brand);font-weight:700}
.profile-btn img{width:100%;height:100%;object-fit:cover}
.profile-menu{position:absolute;top:56px;right:0;width:280px;background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:16px;display:none;box-shadow:var(--shadow-lg);z-index:1001}
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
.main-content{padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px);max-width:1450px;margin:0 auto}
.page-stack{display:grid;gap:24px}
.section-card{background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:24px}
.hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none}
.hero-card h1{font-size:32px;margin-bottom:8px}
.hero-card p{color:var(--text-secondary)}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
.stat-card{text-align:center}.stat-value{font-size:32px;font-weight:800;color:var(--brand)}.stat-label{font-size:13px;color:var(--muted);margin-top:8px}
.notice{padding:14px 16px;border-radius:14px;border:1px solid var(--line);background:var(--surface-2)}
.notice.success{border-color:#86efac;background:rgba(34,197,94,.12)}
.notice.danger{border-color:#fca5a5;background:rgba(239,68,68,.12)}
.filters-row{display:grid;grid-template-columns:1fr 220px 220px;gap:12px}
.search-wrap{position:relative}
.search-input,.form-select{width:100%;height:48px;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:0 14px;outline:none}
.search-input{padding-left:46px}
.search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--muted)}
.users-list{display:grid;gap:12px}
.user-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px}
.user-left{display:flex;gap:12px}
.user-avatar,.user-avatar-fallback{width:46px;height:46px;border-radius:14px;flex-shrink:0}
.user-avatar{object-fit:cover}
.user-avatar-fallback{display:flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:700}
.user-name{font-size:16px;font-weight:800;margin-bottom:4px}
.user-meta{font-size:12px;color:var(--muted);line-height:1.7}
.user-actions{display:flex;gap:10px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;background:var(--brand-soft);color:var(--brand)}
.badge.warn{background:rgba(245,158,11,.12);color:var(--warning)}
.badge.danger{background:rgba(239,68,68,.12);color:var(--danger)}
.btn{padding:10px 14px;border-radius:14px;font-weight:700;border:none;cursor:pointer}
.btn.secondary{background:var(--surface);border:1px solid var(--line);color:var(--text)}
.btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
.btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.btn.primary{background:var(--brand);color:#fff}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}

@media (max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:950px){.header-top-nav{display:none}.filters-row{grid-template-columns:1fr}}
@media (max-width:768px){:root{--header-height:64px;--footer-height:80px}.main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}.stats-grid,.footer-grid{grid-template-columns:1fr;gap:12px}.user-item{flex-direction:column}}
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
                <h1>👥 Manage Users</h1>
                <p>Search users, manage status, verification, and roles.</p>
            </section>

            <div class="stats-grid">
                <div class="section-card stat-card"><div class="stat-value"><?php echo $totalUsers; ?></div><div class="stat-label">Total Users</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $activeUsers; ?></div><div class="stat-label">Active Users</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $verifiedUsers; ?></div><div class="stat-label">Verified Users</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $adminUsers; ?></div><div class="stat-label">Admins</div></div>
            </div>

            <?php if ($message !== ''): ?>
                <div class="notice <?php echo $messageType === 'success' ? 'success' : 'danger'; ?>"><?php echo e($message); ?></div>
            <?php endif; ?>

            <section class="section-card">
                <div class="filters-row">
                    <div class="search-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="userSearchInput" class="search-input" placeholder="Search name, email, role...">
                    </div>
                    <select id="roleFilter" class="form-select">
                        <option value="">All Roles</option>
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                    <select id="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>

                <?php if (!empty($users)): ?>
                    <div class="users-list" id="usersList">
                        <?php foreach ($users as $user): ?>
                            <?php
                            $searchBlob = strtolower(($user['full_name'] ?? '') . ' ' . ($user['email'] ?? '') . ' ' . ($user['role'] ?? ''));
                            $roleValue = strtolower((string)($user['role'] ?? 'user'));
                            $statusValue = (int)($user['is_active'] ?? 0) === 1 ? 'active' : 'disabled';
                            ?>
                            <div class="user-item user-search-item" data-search="<?php echo e($searchBlob); ?>" data-role="<?php echo e($roleValue); ?>" data-status="<?php echo e($statusValue); ?>">
                                <div class="user-left">
                                    <?php if (!empty($user['profile_image'])): ?>
                                        <img src="<?php echo e($user['profile_image']); ?>" alt="Avatar" class="user-avatar">
                                    <?php else: ?>
                                        <div class="user-avatar-fallback"><?php echo e(initials((string)($user['full_name'] ?? 'U'))); ?></div>
                                    <?php endif; ?>

                                    <div>
                                        <div class="user-name"><?php echo e($user['full_name'] ?? 'Unknown User'); ?></div>
                                        <div class="user-meta">
                                            Email: <?php echo e($user['email'] ?? ''); ?><br>
                                            Role: <?php echo e(ucfirst((string)($user['role'] ?? 'user'))); ?><br>
                                            Joined: <?php echo !empty($user['created_at']) ? e(date('M d, Y', strtotime($user['created_at']))) : '--'; ?><br>
                                            Quizzes: <?php echo (int)($user['quizzes_taken'] ?? 0); ?> |
                                            Contents: <?php echo (int)($user['contents_started'] ?? 0); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="user-actions">
                                    <span class="badge <?php echo (int)($user['is_active'] ?? 0) === 1 ? '' : 'danger'; ?>">
                                        <?php echo (int)($user['is_active'] ?? 0) === 1 ? 'Active' : 'Disabled'; ?>
                                    </span>
                                    <span class="badge <?php echo (int)($user['is_verified'] ?? 0) === 1 ? '' : 'warn'; ?>">
                                        <?php echo (int)($user['is_verified'] ?? 0) === 1 ? 'Verified' : 'Unverified'; ?>
                                    </span>

                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                        <button class="btn <?php echo (int)($user['is_active'] ?? 0) === 1 ? 'warn' : 'primary'; ?>">
                                            <?php echo (int)($user['is_active'] ?? 0) === 1 ? 'Disable' : 'Enable'; ?>
                                        </button>
                                    </form>

                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_verified">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                        <button class="btn secondary"><?php echo (int)($user['is_verified'] ?? 0) === 1 ? 'Unverify' : 'Verify'; ?></button>
                                    </form>

                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_role">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                        <button class="btn secondary"><?php echo strtolower((string)($user['role'] ?? 'user')) === 'admin' ? 'Make User' : 'Make Admin'; ?></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="emptySearchState" class="empty-state" style="display:none;">No matching users found.</div>
                <?php else: ?>
                    <div class="empty-state">No users found.</div>
                <?php endif; ?>
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
          profileArea = document.getElementById('profileArea'),
          userSearchInput = document.getElementById('userSearchInput'),
          roleFilter = document.getElementById('roleFilter'),
          statusFilter = document.getElementById('statusFilter'),
          userItems = document.querySelectorAll('.user-search-item'),
          emptySearchState = document.getElementById('emptySearchState'),
          usersList = document.getElementById('usersList');

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

    function applyFilters(){
        const q=(userSearchInput?.value||'').trim().toLowerCase();
        const role=(roleFilter?.value||'').trim().toLowerCase();
        const status=(statusFilter?.value||'').trim().toLowerCase();
        let visible=0;
        userItems.forEach(item=>{
            const hay=item.getAttribute('data-search')||'';
            const itemRole=item.getAttribute('data-role')||'';
            const itemStatus=item.getAttribute('data-status')||'';
            const show=(q===''||hay.includes(q))&&(role===''||itemRole===role)&&(status===''||itemStatus===status);
            item.style.display=show?'':'none';
            if(show) visible++;
        });
        if(usersList) usersList.style.display = visible===0 ? 'none' : 'grid';
        if(emptySearchState) emptySearchState.style.display = visible===0 ? 'block' : 'none';
    }
    userSearchInput?.addEventListener('input',applyFilters);
    roleFilter?.addEventListener('change',applyFilters);
    statusFilter?.addEventListener('change',applyFilters);
})();
</script>
</body>
</html>