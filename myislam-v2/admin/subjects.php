<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/admin_functions.php';
require_once __DIR__ . '/../includes/auth_functions.php';

requireLogin();
requireAdmin();

$conn = db();
$message = '';
$error = '';

// Handle actions
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Add/Edit Subject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name' => trim($_POST['name']),
        'description' => trim($_POST['description'] ?? ''),
        'icon' => trim($_POST['icon'] ?? ''),
        'sort_order' => (int)($_POST['sort_order'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    if ($action === 'add') {
        $result = createSubject($conn, $data);
        if ($result['success']) {
            $message = $result['message'];
            header('Location: subjects.php?msg=' . urlencode($message));
            exit;
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'edit' && $id > 0) {
        $result = updateSubject($conn, $id, $data);
        if ($result['success']) {
            $message = $result['message'];
            header('Location: subjects.php?msg=' . urlencode($message));
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

// Delete Subject
if ($action === 'delete' && $id > 0) {
    $result = deleteSubject($conn, $id);
    if ($result['success']) {
        $message = $result['message'];
        header('Location: subjects.php?msg=' . urlencode($message));
        exit;
    } else {
        $error = $result['message'];
    }
}

// Get subject for editing
$subject = null;
if ($action === 'edit' && $id > 0) {
    $subject = getSubject($conn, $id);
}

// Get all subjects
$subjects = getAllSubjects($conn);

// Prepare admin navigation
$adminNav = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'subjects.php', 'icon' => '📚', 'active' => true],
    ['name' => 'Categories', 'link' => 'categories.php', 'icon' => '📂'],
    ['name' => 'Content', 'link' => 'content.php', 'icon' => '📄'],
    ['name' => 'Quizzes', 'link' => 'quizzes.php', 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'users.php', 'icon' => '👥'],
    ['name' => 'Settings', 'link' => 'settings.php', 'icon' => '⚙️'],
    ['name' => 'View Site', 'link' => '../user_dashboard.php', 'icon' => '🌐']
];

$userName = $_SESSION['user_name'] ?? 'Admin';
$userEmail = $_SESSION['user_email'] ?? 'admin@myislam.com';
$userPicture = 'https://via.placeholder.com/80x80.png?text=' . urlencode(substr($userName, 0, 1));
$userRole = $_SESSION['user_role'] ?? 'Admin';
$memberSince = date('F Y');

$platformVersion = 'v1.0.0';


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Manage Subjects - MyIslam Admin">
    <title>Manage Subjects - MyIslam Admin</title>
    <style>
        /* ============================================
           Global Variables & Reset (same as other admin pages)
        ============================================ */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #f5f7f5;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --surface-3: #eef2f7;
            --line: #e5e7eb;
            --text: #1f2937;
            --text-secondary: #4b5563;
            --muted: #6b7280;
            --brand: #166534;
            --brand-dark: #14532d;
            --brand-light: #22c55e;
            --brand-soft: #e7f3eb;
            --accent: #3b82f6;
            --warning: #f59e0b;
            --danger: #ef4444;
            --success: #10b981;
            --header-bg: #e1f0e7;
            --header-height: 72px;
            --footer-height: 92px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
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
            --brand: #7dd3a7;
            --brand-dark: #6bb590;
            --brand-light: #9bdebc;
            --brand-soft: rgba(125, 211, 167, 0.12);
            --accent: #60a5fa;
            --header-bg: #13202a;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
        }

        a { text-decoration: none; color: inherit; }
        button { font: inherit; border: none; background: none; cursor: pointer; }

        /* Header */
        .app-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            height: var(--header-height);
            background: var(--header-bg);
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 24px;
            backdrop-filter: blur(10px);
        }

        .header-left, .header-right { display: flex; align-items: center; gap: 12px; }
        
        .menu-toggle, .icon-btn, .lang-btn, .brand-box {
            height: 44px;
            min-width: 44px;
            border-radius: var(--radius-sm);
            background: var(--surface);
            border: 1px solid var(--line);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .menu-toggle:hover, .icon-btn:hover, .lang-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .menu-toggle { font-size: 22px; }
        .lang-btn { min-width: 56px; padding: 0 14px; font-weight: 600; }
        .brand-box { font-size: 22px; font-weight: 700; color: var(--brand); background: transparent; border: none; }

        /* Profile Menu */
        .profile-area { position: relative; }
        .profile-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--surface);
            border: 2px solid var(--brand);
            cursor: pointer;
            padding: 0;
        }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }
        
        .profile-menu {
            position: absolute;
            top: 56px;
            right: 0;
            width: 280px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 16px;
            display: none;
            box-shadow: var(--shadow-lg);
            z-index: 1001;
        }
        
        .profile-area:hover .profile-menu { display: block; }
        
        .profile-menu-top {
            display: flex;
            gap: 12px;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }
        
        .profile-menu-top img { width: 56px; height: 56px; border-radius: 50%; }
        .profile-menu-top h4 { font-size: 16px; margin-bottom: 4px; font-weight: 600; }
        .profile-menu-top p { font-size: 12px; color: var(--muted); }
        .profile-menu a {
            display: block;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: background 0.2s;
            margin: 4px 0;
        }
        .profile-menu a:hover { background: var(--brand-soft); color: var(--brand); }

        /* Sidebar */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: var(--header-height);
            left: 0;
            width: 100%;
            height: calc(100vh - var(--header-height));
            background: rgba(0,0,0,0.5);
            z-index: 1100;
            backdrop-filter: blur(4px);
        }
        .sidebar-overlay.show { display: block; }
        
        .sidebar {
            position: fixed;
            top: var(--header-height);
            left: -100%;
            width: 320px;
            height: calc(100vh - var(--header-height));
            background: var(--surface);
            border-right: 1px solid var(--line);
            padding: 24px 20px;
            z-index: 1200;
            transition: left 0.3s ease;
            overflow-y: auto;
        }
        .sidebar.open { left: 0; }
        
        .sidebar-title {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            padding: 0 8px;
        }
        
        .nav-list { display: grid; gap: 8px; }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            background: var(--surface-2);
            border: 1px solid transparent;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .nav-link:hover {
            background: var(--brand-soft);
            border-color: var(--brand);
            transform: translateX(4px);
        }
        .nav-link.active {
            background: var(--brand-soft);
            color: var(--brand);
            border-color: var(--brand);
        }

        /* Main Content */
        .main-content {
            padding: calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 40px);
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .dashboard-stack { display: grid; gap: 24px; }
        
        .section-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 24px;
            transition: all 0.2s;
        }
        .section-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .card-header h2 {
            font-size: 20px;
            font-weight: 600;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-secondary);
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            background: var(--surface-2);
            color: var(--text);
            font-size: 14px;
            transition: all 0.2s;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(22, 101, 52, 0.1);
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        /* Buttons */
        .btn {
            padding: 8px 20px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }
        .btn-primary {
            background: var(--brand);
            color: white;
        }
        .btn-primary:hover {
            background: var(--brand-dark);
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: var(--surface-2);
            border: 1px solid var(--line);
            color: var(--text);
        }
        .btn-secondary:hover {
            background: var(--surface-3);
        }
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        /* Alert Messages */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-success {
            background: var(--brand-soft);
            color: var(--brand-dark);
            border-left: 4px solid var(--brand);
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }
        
        /* Table */
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--line);
        }
        th {
            background: var(--surface-2);
            font-weight: 600;
            font-size: 14px;
            color: var(--text-secondary);
        }
        td {
            font-size: 14px;
        }
        .status-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .status-active {
            background: var(--brand-soft);
            color: var(--brand-dark);
        }
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .icon-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .icon-btn.edit {
            background: var(--accent);
            color: white;
        }
        .icon-btn.delete {
            background: var(--danger);
            color: white;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        
        
        .footer-meta {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            padding-top: 20px;
            margin-top: 20px;
            border-top: 1px solid var(--line);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            :root { --header-height: 64px; --footer-height: 80px; }
            .main-content { padding: calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 60px); }
            .section-card { padding: 16px; }
            .grid-2 { grid-template-columns: 1fr; }
            .footer-grid { gap: 8px; }
            .footer-action { min-height: 64px; }
            th, td { padding: 8px; }
        }
    </style>
</head>
<body>

<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="brand-box">🕌 MyIslam Admin</div>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <button class="icon-btn" aria-label="Notifications">🔔</button>
        <div class="profile-area">
            <button class="profile-btn">
                <img src="<?php echo $userPicture; ?>" alt="Profile">
            </button>
            <div class="profile-menu">
                <div class="profile-menu-top">
                    <img src="<?php echo $userPicture; ?>" alt="Profile">
                    <div>
                        <h4><?php echo htmlspecialchars($userName); ?></h4>
                        <p><?php echo htmlspecialchars($userEmail); ?></p>
                        <p style="font-size: 10px;"><?php echo htmlspecialchars($userRole); ?> since <?php echo $memberSince; ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 My Profile</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-title">📋 Admin Menu</div>
    <nav class="nav-list">
        <?php foreach ($adminNav as $item): ?>
            <a href="<?php echo $item['link']; ?>" class="nav-link <?php echo isset($item['active']) && $item['active'] ? 'active' : ''; ?>">
                <span><?php echo $item['icon']; ?></span>
                <span><?php echo htmlspecialchars($item['name']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>

<main class="main-content">
    <div class="dashboard-stack">
        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <span>✅</span> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <span>❌</span> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($action === 'add' || ($action === 'edit' && $subject)): ?>
            <!-- Add/Edit Form -->
            <div class="section-card">
                <div class="card-header">
                    <h2><?php echo $action === 'add' ? '➕ Add New Subject' : '✏️ Edit Subject'; ?></h2>
                    <a href="subjects.php" class="btn btn-secondary">← Back</a>
                </div>
                <form method="POST">
                    <div class="form-group">
                        <label>Subject Name *</label>
                        <input type="text" name="name" required value="<?php echo htmlspecialchars($subject['name'] ?? ''); ?>">
                    </div>
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Icon (emoji)</label>
                            <input type="text" name="icon" placeholder="📚" value="<?php echo htmlspecialchars($subject['icon'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" value="<?php echo $subject['sort_order'] ?? 0; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"><?php echo htmlspecialchars($subject['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_active" <?php echo (!isset($subject) || $subject['is_active']) ? 'checked' : ''; ?>>
                            Active
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <?php echo $action === 'add' ? 'Create Subject' : 'Update Subject'; ?>
                    </button>
                    <a href="subjects.php" class="btn btn-secondary" style="margin-left: 0.5rem;">Cancel</a>
                </form>
            </div>
        <?php else: ?>
            <!-- Subjects List -->
            <div class="section-card">
                <div class="card-header">
                    <h2>📚 All Subjects</h2>
                    <a href="subjects.php?action=add" class="btn btn-primary">+ Add New Subject</a>
                </div>
                
                <?php if ($subjects): ?>
                    <div class="table-responsive">
                         <table>
                            <thead>
                                 <tr>
                                    <th>Name</th>
                                    <th>Icon</th>
                                    <th>Categories</th>
                                    <th>Content</th>
                                    <th>Status</th>
                                    <th>Sort</th>
                                    <th>Actions</th>
                                 </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjects as $subject): ?>
                                 <tr>
                                    <td><strong><?php echo htmlspecialchars($subject['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($subject['icon'] ?? '-'); ?></td>
                                    <td><?php echo $subject['category_count']; ?></td>
                                    <td><?php echo $subject['content_count']; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $subject['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $subject['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $subject['sort_order']; ?></td>
                                    <td class="action-buttons">
                                        <a href="subjects.php?action=edit&id=<?php echo $subject['id']; ?>" class="icon-btn edit">Edit</a>
                                        <a href="subjects.php?action=delete&id=<?php echo $subject['id']; ?>" class="icon-btn delete" onclick="return confirm('Delete this subject? All categories and content will be lost!');">Delete</a>
                                    </td>
                                 </tr>
                                <?php endforeach; ?>
                            </tbody>
                         </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No subjects yet. Create your first subject!</p>
                        <p style="margin-top: 1rem;">
                            <a href="subjects.php?action=add" class="btn btn-primary">Create your first subject →</a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Footer Meta -->
        <div class="footer-meta">
            <div>Platform Version: <?php echo htmlspecialchars($platformVersion); ?></div>
            <div>© <?php echo date('Y'); ?> MyIslam. All rights reserved.</div>
        </div>
    </div>
</main>



<script>
    (function() {
        // Sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
        
        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
            });
        }
        
        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });
        
        // Dark Mode
        const themeToggle = document.getElementById('themeToggle');
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-mode');
            themeToggle.textContent = '☀';
        }
        
        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                const isDark = document.body.classList.contains('dark-mode');
                themeToggle.textContent = isDark ? '☀' : '🌙';
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            });
        }
        
        // Language toggle (placeholder)
        const langToggle = document.getElementById('langToggle');
        let currentLang = localStorage.getItem('language') || 'EN';
        if (langToggle) {
            langToggle.textContent = currentLang;
            langToggle.addEventListener('click', () => {
                currentLang = currentLang === 'EN' ? 'SW' : 'EN';
                langToggle.textContent = currentLang;
                localStorage.setItem('language', currentLang);
                console.log('Language changed to:', currentLang);
            });
        }
        
        // Image error fallback
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function() {
                this.src = 'https://via.placeholder.com/80x80.png?text=U';
            });
        });
    })();
</script>

</body>
</html>