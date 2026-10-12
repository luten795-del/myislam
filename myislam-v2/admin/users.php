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

// Process form submission for add/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'full_name' => trim($_POST['full_name']),
        'email' => trim($_POST['email']),
        'role' => $_POST['role'] ?? 'user',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'is_verified' => isset($_POST['is_verified']) ? 1 : 0,
    ];
    
    if (!empty($_POST['password'])) {
        $data['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
    }
    
    if ($action === 'add') {
        // Validate required fields
        if (empty($data['full_name']) || empty($data['email'])) {
            $error = 'Full name and email are required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            // Check if email exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                $error = 'Email already registered.';
            } else {
                if (empty($data['password'])) {
                    $error = 'Password is required for new users.';
                } else {
                    $result = createUser($conn, $data);
                    if ($result['success']) {
                        $message = $result['message'];
                        header('Location: users.php?msg=' . urlencode($message));
                        exit;
                    } else {
                        $error = $result['message'];
                    }
                }
            }
        }
    } elseif ($action === 'edit' && $id > 0) {
        // Don't require password on edit
        unset($data['password']);
        if (!empty($_POST['password'])) {
            $data['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
        $result = updateUser($conn, $id, $data);
        if ($result['success']) {
            $message = $result['message'];
            header('Location: users.php?msg=' . urlencode($message));
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

// Handle delete
if ($action === 'delete' && $id > 0) {
    // Prevent deleting own account
    if ($id == $_SESSION['user_id']) {
        $error = 'You cannot delete your own account.';
    } else {
        $result = deleteUser($conn, $id);
        if ($result['success']) {
            $message = $result['message'];
            header('Location: users.php?msg=' . urlencode($message));
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

// Handle toggle status (quick activate/deactivate)
if ($action === 'toggle_status' && $id > 0) {
    // Get current status
    $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if ($user) {
        $newStatus = $user['is_active'] ? 0 : 1;
        $result = updateUser($conn, $id, ['is_active' => $newStatus]);
        if ($result['success']) {
            $message = 'User status updated.';
            header('Location: users.php?msg=' . urlencode($message));
            exit;
        } else {
            $error = $result['message'];
        }
    } else {
        $error = 'User not found.';
    }
}

// Get user for editing
$user = null;
if ($action === 'edit' && $id > 0) {
    $user = getUser($conn, $id);
    if (!$user) {
        $error = 'User not found.';
        $action = 'list';
    }
}

// Get users with optional search/filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$roleFilter = isset($_GET['role']) ? $_GET['role'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query
$where = [];
$params = [];
if ($search) {
    $where[] = "(full_name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($roleFilter && in_array($roleFilter, ['admin', 'user'])) {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}
if ($statusFilter === 'active') {
    $where[] = "is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $where[] = "is_active = 0";
}

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Count total
$countSql = "SELECT COUNT(*) as total FROM users $whereClause";
$stmt = $conn->prepare($countSql);
$stmt->execute($params);
$totalUsers = $stmt->fetch()['total'];
$totalPages = ceil($totalUsers / $limit);

// Fetch users (removed last_login column)
$sql = "SELECT id, full_name, email, role, is_active, is_verified, created_at 
        FROM users $whereClause 
        ORDER BY created_at DESC 
        LIMIT $offset, $limit";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get stats for dashboard
$stats = [];
$stmt = $conn->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
$stats['active'] = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'admin'");
$stats['admins'] = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'user'");
$stats['users'] = $stmt->fetch()['total'];

// Admin navigation
$adminNav = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'subjects.php', 'icon' => '📚'],
    ['name' => 'Categories', 'link' => 'categories.php', 'icon' => '📂'],
    ['name' => 'Content', 'link' => 'content.php', 'icon' => '📄'],
    ['name' => 'Quizzes', 'link' => 'quizzes.php', 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'users.php', 'icon' => '👥', 'active' => true],
    ['name' => 'Settings', 'link' => 'settings.php', 'icon' => '⚙️'],
    ['name' => 'View Site', 'link' => '../user_dashboard.php', 'icon' => '🌐']
];

$userName = $_SESSION['user_name'] ?? 'Admin';
$userEmail = $_SESSION['user_email'] ?? 'admin@myislam.com';
$userPicture = 'https://via.placeholder.com/80x80.png?text=' . urlencode(substr($userName, 0, 1));
$userRole = $_SESSION['user_role'] ?? 'Admin';
$memberSince = date('F Y');

$platformVersion = 'v1.0.0';

$footerActions = [
    ['title' => 'Dashboard', 'icon' => '📊', 'link' => 'dashboard.php'],
    ['title' => 'Users', 'icon' => '👥', 'link' => 'users.php'],
    ['title' => 'Content', 'icon' => '📄', 'link' => 'content.php'],
    ['title' => 'Logout', 'icon' => '🚪', 'link' => 'logout.php']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Manage Users - MyIslam Admin">
    <title>Manage Users - MyIslam Admin</title>
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 0;
        }
        
        .stat-card {
            text-align: center;
            padding: 20px;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--brand);
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: var(--muted);
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
        .role-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .role-admin {
            background: #fef3c7;
            color: #92400e;
        }
        .role-user {
            background: #dbeafe;
            color: #1e40af;
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
        .icon-btn.toggle {
            background: var(--warning);
            color: white;
        }
        
        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-bar select,
        .filter-bar input {
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            background: var(--surface);
            min-width: 150px;
        }
        .filter-bar input {
            min-width: 200px;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .page-link {
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            background: var(--surface);
            transition: all 0.2s;
        }
        .page-link.active {
            background: var(--brand);
            color: white;
            border-color: var(--brand);
        }
        .page-link:hover:not(.active) {
            background: var(--surface-2);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: var(--surface);
            border-radius: var(--radius-lg);
            max-width: 600px;
            width: 90%;
            max-height: 90%;
            overflow-y: auto;
            padding: 24px;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .modal-header h3 {
            font-size: 1.25rem;
        }
        .close-modal {
            font-size: 24px;
            cursor: pointer;
            color: var(--muted);
        }
        
        /* Footer Actions */
       
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
<?php include __DIR__ . '/../includes/pwa.php'; ?>
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

        <?php if ($action === 'add' || ($action === 'edit' && $user)): ?>
            <!-- Add/Edit User Form -->
            <div class="section-card">
                <div class="card-header">
                    <h2><?php echo $action === 'add' ? '➕ Add New User' : '✏️ Edit User: ' . htmlspecialchars($user['full_name']); ?></h2>
                    <a href="users.php" class="btn btn-secondary">← Back</a>
                </div>
                <form method="POST">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Password <?php echo $action === 'add' ? '*' : '(leave blank to keep current)'; ?></label>
                        <input type="password" name="password" <?php echo $action === 'add' ? 'required' : ''; ?>>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Role</label>
                            <select name="role">
                                <option value="user" <?php echo ($user && $user['role'] == 'user') ? 'selected' : ''; ?>>User</option>
                                <option value="admin" <?php echo ($user && $user['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="is_active">
                                <option value="1" <?php echo (!isset($user) || $user['is_active']) ? 'selected' : ''; ?>>Active</option>
                                <option value="0" <?php echo (isset($user) && !$user['is_active']) ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_verified" <?php echo ($user && $user['is_verified']) ? 'checked' : ''; ?>>
                            Verified
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?php echo $action === 'add' ? 'Create User' : 'Update User'; ?>
                    </button>
                    <a href="users.php" class="btn btn-secondary" style="margin-left: 0.5rem;">Cancel</a>
                </form>
            </div>
        <?php else: ?>
            <!-- Users List -->
            <div class="section-card">
                <div class="card-header">
                    <h2>👥 All Users</h2>
                    <a href="users.php?action=add" class="btn btn-primary">+ Add New User</a>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid" style="margin-bottom: 24px;">
                    <div class="stat-card" style="background: var(--surface-2); border-radius: var(--radius-md);">
                        <div class="stat-value"><?php echo $totalUsers; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-card" style="background: var(--surface-2); border-radius: var(--radius-md);">
                        <div class="stat-value"><?php echo $stats['active']; ?></div>
                        <div class="stat-label">Active Users</div>
                    </div>
                    <div class="stat-card" style="background: var(--surface-2); border-radius: var(--radius-md);">
                        <div class="stat-value"><?php echo $stats['admins']; ?></div>
                        <div class="stat-label">Admins</div>
                    </div>
                    <div class="stat-card" style="background: var(--surface-2); border-radius: var(--radius-md);">
                        <div class="stat-value"><?php echo $stats['users']; ?></div>
                        <div class="stat-label">Regular Users</div>
                    </div>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                        <input type="text" name="search" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
                        <select name="role">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $roleFilter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="user" <?php echo $roleFilter == 'user' ? 'selected' : ''; ?>>User</option>
                        </select>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="btn btn-secondary">Filter</button>
                        <a href="users.php" class="btn btn-secondary">Reset</a>
                    </form>
                </div>
                
                <?php if ($users): ?>
                    <div class="table-responsive">
                          <table>
                            <thead>
                                  <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Verified</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                  </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                  <tr>
                                    <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['is_verified']): ?>
                                            <span style="color: var(--success);">✓ Verified</span>
                                        <?php else: ?>
                                            <span style="color: var(--muted);">✗ Not verified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td class="action-buttons">
                                        <a href="users.php?action=edit&id=<?php echo $user['id']; ?>" class="icon-btn edit">Edit</a>
                                        <a href="users.php?action=toggle_status&id=<?php echo $user['id']; ?>" class="icon-btn toggle" onclick="return confirm('Toggle user status?');">
                                            <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </a>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <a href="users.php?action=delete&id=<?php echo $user['id']; ?>" class="icon-btn delete" onclick="return confirm('Delete this user? This action cannot be undone.');">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                            </tbody>
                          </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $roleFilter; ?>&status=<?php echo $statusFilter; ?>" 
                               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="empty-state">
                        <p>No users found.</p>
                        <p style="margin-top: 1rem;">
                            <a href="users.php?action=add" class="btn btn-primary">Add your first user →</a>
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