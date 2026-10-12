<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

// ========== HELPER FUNCTIONS ==========
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

function handleImageUpload(array $file, string $uploadSubdir = 'profiles'): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) return null;

    $uploadDir = __DIR__ . '/uploads/' . $uploadSubdir . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $filename = 'user_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
    $destination = $uploadDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'uploads/' . $uploadSubdir . '/' . $filename;
    }
    return null;
}

// ========== AUTHENTICATION ==========
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();
if (!$conn) die('Database connection failed.');

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ========== FETCH USER DATA ==========
$dbUser = null;
try {
    $stmt = $conn->prepare("
        SELECT id, full_name, username, email, profile_image, role, is_active, is_verified, created_at
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
    'id'       => $dbUser['id'],
    'name'     => $dbUser['full_name'] ?? 'User',
    'username' => $dbUser['username'] ?? '',
    'email'    => $dbUser['email'] ?? '',
    'picture'  => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role'     => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime($dbUser['created_at'])) : 'recently',
];

// ========== GET UNREAD NOTIFICATIONS ==========
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table may not exist
}

// ========== GET USER CERTIFICATES OF ACHIEVEMENT ==========
function getUserAchievementCertificates(PDO $conn, int $userId): array {
    try {
        $stmt = $conn->prepare("
            SELECT
                c.id,
                c.certificate_code,
                c.certification_type,
                c.subject_id,
                c.title,
                c.achievement_summary,
                c.status,
                c.issued_at,
                c.completed_at,
                s.name AS subject_name,
                s.icon AS subject_icon
            FROM certificates c
            LEFT JOIN subjects s ON s.id = c.subject_id
            WHERE c.user_id = ?
            ORDER BY
                CASE WHEN c.status = 'active' THEN 0 ELSE 1 END,
                c.issued_at DESC,
                c.id DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('getUserAchievementCertificates: ' . $e->getMessage());
        return [];
    }
}

$achievementCertificates = getUserAchievementCertificates($conn, $userId);
$totalAchievementCertificates = count($achievementCertificates);
$totalActiveAchievementCertificates = count(array_filter($achievementCertificates, fn($cert) => ($cert['status'] ?? '') === 'active'));
$totalGeneralAchievementCertificates = count(array_filter($achievementCertificates, fn($cert) => ($cert['certification_type'] ?? '') === 'general'));


// ========== HANDLE FORM SUBMISSIONS ==========
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        // ----- UPDATE PROFILE (name, username, picture) -----
        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');

            if ($fullName === '') {
                $message = 'Full name is required.';
                $messageType = 'danger';
            } elseif ($username === '') {
                $message = 'Username is required.';
                $messageType = 'danger';
            } elseif (preg_match('/\s/', $username)) {
                $message = 'Username must not contain spaces.';
                $messageType = 'danger';
            } else {
                // Check if username is already taken by another user
                $usernameCheck = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $usernameCheck->execute([$username, $userId]);
                if ($usernameCheck->fetch()) {
                    $message = 'This username is already taken. Please choose another.';
                    $messageType = 'danger';
                } else {
                    $profileImagePath = $user['picture'];
                    // Handle profile picture upload
                    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                        $uploaded = handleImageUpload($_FILES['profile_image'], 'profiles');
                        if ($uploaded) {
                            $profileImagePath = $uploaded;
                        }
                    }

                    try {
                        $stmt = $conn->prepare("
                            UPDATE users
                            SET full_name = ?, username = ?, profile_image = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$fullName, $username, $profileImagePath ?: null, $userId]);
                        
                        // Update session data
                        $user['name'] = $fullName;
                        $user['username'] = $username;
                        $user['picture'] = $profileImagePath;
                        
                        $message = 'Profile updated successfully.';
                        $messageType = 'success';
                    } catch (PDOException $e) {
                        error_log("Profile update error: " . $e->getMessage());
                        $message = 'Database error. Could not update profile.';
                        $messageType = 'danger';
                    }
                }
            }
        }

        // ----- CHANGE PASSWORD -----
        elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $message = 'All password fields are required.';
                $messageType = 'danger';
            } elseif ($newPassword !== $confirmPassword) {
                $message = 'New password and confirmation do not match.';
                $messageType = 'danger';
            } elseif (strlen($newPassword) < 6) {
                $message = 'Password must be at least 6 characters long.';
                $messageType = 'danger';
            } else {
                // Verify current password
                $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $currentHash = $stmt->fetchColumn();
                
                if (!$currentHash || !password_verify($currentPassword, $currentHash)) {
                    $message = 'Current password is incorrect.';
                    $messageType = 'danger';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$newHash, $userId]);
                    $message = 'Password changed successfully.';
                    $messageType = 'success';
                }
            }
        }
    }

    // If AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
        exit;
    }
}

// ========== BUILD NAVIGATION ==========
$sidebarNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => true, 'icon' => '📊'],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
        ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
        [
    'name' => t('my_certificates'),
    'link' => 'my_certificates.php',
    'active' => false,
    'icon' => '🎓'
],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => 'Help Center', 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Profile - MyIslam</title>
    <style>
        /* ===== DASHBOARD BASE STYLES ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

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
            --accent: #3E6E91;
            --warning: #b45309;
            --danger: #b91c1c;
            --success: #0E4A34;
            --header-bg: #ffffff;
            --header-height: 72px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 20px;
        
    --gold: #9C7A2E;
    --gold-soft: rgba(156, 122, 46, 0.10);
    --accent-soft: rgba(62, 110, 145, 0.10);
    --primary: var(--accent);
    --primary-soft: var(--accent-soft);
    --white: #ffffff;
    --overlay-dim: rgba(15, 23, 32, 0.45);
    --glass-bg-strong: rgba(255, 255, 255, 0.92);
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
            --accent: #6FA0C4;
            --header-bg: #13202a;
        
    --gold: #C9A85E;
    --gold-soft: rgba(201, 168, 94, 0.16);
    --accent-soft: rgba(111, 160, 196, 0.16);
    --primary: var(--accent);
    --primary-soft: var(--accent-soft);
    --danger: #ef4444;
    --overlay-dim: rgba(0, 0, 0, 0.6);
    --glass-bg-strong: rgba(24, 33, 43, 0.92);
}

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            position: relative;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none; z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%23166534' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
        }

        body.dark-mode::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%237dd3a7' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }

        a { text-decoration: none; color: inherit; }
        button { font: inherit; border: none; background: none; cursor: pointer; }

        /* Header */
        .app-header {
            position: sticky; top: 0; z-index: 1000;
            height: var(--header-height); background: var(--header-bg);
            border-bottom: 2px solid var(--brand);
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 16px; backdrop-filter: blur(10px); gap: 8px;
        }

        .header-left, .header-right { display: flex; align-items: center; gap: 8px; }

        .sidebar-toggle-btn {
            position: fixed; top: var(--header-height); left: 0; z-index: 1300;
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; background: var(--surface); border: 1px solid var(--brand);
            border-left: none; border-top: none; border-radius: 0 0 8px 0; font-size: 16px;
            cursor: pointer; color: var(--brand); transition: all 0.2s; box-shadow: var(--shadow-sm);
            padding: 0; margin: 0;
        }
        .sidebar-toggle-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }

        .brand-box {
            height: 40px; border-radius: 10px; background: transparent; display: inline-flex;
            align-items: center; justify-content: center; font-size: 22px; font-weight: 700;
            color: var(--brand); white-space: nowrap;
        }

        .icon-btn, .lang-btn {
            height: 40px; min-width: 30px; border-radius: 10px; background: var(--surface);
            border: 1px solid var(--brand); display: inline-flex; align-items: center;
            justify-content: center; transition: all 0.2s; padding: 0 8px; font-weight: 600;
        }
        .icon-btn:hover, .lang-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }

        .profile-area { position: relative; }
        .profile-btn {
            width: 40px; height: 40px; border-radius: 50%; overflow: hidden;
            background: var(--surface); border: 2px solid var(--brand); cursor: pointer;
            padding: 0; display: flex; align-items: center; justify-content: center;
            color: var(--brand); font-weight: 700;
        }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }

        .profile-menu {
            position: absolute; top: 56px; right: 0; width: 280px;
            background: var(--surface); border: 2px solid var(--brand);
            border-radius: var(--radius-lg); padding: 16px; display: none;
            box-shadow: var(--shadow-lg); z-index: 1001; backdrop-filter: blur(10px);
        }
        .profile-menu.show { display: block; }

        .profile-menu-top {
            display: flex; gap: 12px; padding-bottom: 10px; margin-bottom: 10px;
            border-bottom: 1px solid var(--brand);
        }
        .profile-menu-top img, .profile-avatar-fallback { width: 56px; height: 56px; border-radius: 50%; }
        .profile-avatar-fallback {
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--brand-soft); color: var(--brand); font-weight: 700; font-size: 16px;
        }
        .profile-menu-top h4 { font-size: 16px; margin-bottom: 4px; font-weight: bold; }
        .profile-menu-top p { font-size: 12px; color: var(--text-secondary); font-weight: bold; }

        .profile-menu a {
            display: block; padding: 10px 12px; border-radius: 8px; font-weight: 600;
            font-size: 14px; transition: background 0.2s; margin: 4px 0;
        }
        .profile-menu a:hover { background: var(--brand-soft); color: var(--brand); }

        .notification-badge {
            position: absolute; top: -4px; right: -4px; background: var(--danger);
            color: white; font-size: 10px; font-weight: 700; min-width: 18px;
            height: 18px; border-radius: 999px; display: flex; align-items: center;
            justify-content: center; padding: 0 4px; border: 2px solid var(--surface);
        }

        /* Sidebar */
        .sidebar-overlay {
            display: none; position: fixed; top: var(--header-height); left: 0;
            width: 100%; height: calc(100vh - var(--header-height));
            background: rgba(0,0,0,0.3); z-index: 1100; backdrop-filter: blur(4px);
        }
        .sidebar-overlay.show { display: block; }

        .sidebar {
            position: fixed; top: var(--header-height); left: -100%;
            width: min(320px, 85vw); height: calc(100vh - var(--header-height));
            background: transparent; border-right: 1px solid var(--brand);
            padding: 24px 20px; z-index: 1200; transition: left 0.3s ease; overflow-y: auto;
            box-shadow: var(--shadow-lg);
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
            border: 1px solid var(--brand); font-size: 14px; font-weight: 500;
            transition: all 0.2s; color: var(--text);
        }
        .subject-link:hover { background: var(--brand-soft); border-color: var(--brand); transform: translateX(4px); }
        .subject-link.active { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }

        /* Main Content */
        .main-content {
            max-width: 900px; margin: 0 auto; padding: 32px 24px 24px;
        }

        .profile-header {
            display: flex; align-items: center; gap: 32px; margin-bottom: 32px;
            flex-wrap: wrap; justify-content: center; text-align: center;
        }
        .profile-avatar-large {
            width: 120px; height: 120px; border-radius: 50%; overflow: hidden;
            background: var(--brand-soft); border: 3px solid var(--brand);
            display: flex; align-items: center; justify-content: center;
            color: var(--brand); font-size: 48px; font-weight: 700;
            flex-shrink: 0;
        }
        .profile-avatar-large img { width: 100%; height: 100%; object-fit: cover; }
        .profile-title h1 { font-size: 32px; font-weight: 700; margin-bottom: 4px; }
        .profile-title p { color: var(--muted); font-size: 16px; }

        .section-card {
            background: transparent; border-radius: var(--radius-lg);
            padding: 28px; margin-bottom: 28px;
            box-shadow: var(--shadow-md); border: 1px solid var(--brand);
        }
        .section-title {
            font-size: 20px; font-weight: 700; margin-bottom: 24px;
            display: flex; align-items: center; gap: 10px;
            color: var(--brand);
        }


        /* Certificates of Achievement */
        .certificates-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 18px;
        }

        .cert-summary-item {
            border: 1px solid var(--brand);
            border-radius: 12px;
            background: var(--surface);
            padding: 12px;
            text-align: center;
        }

        .cert-summary-item strong {
            display: block;
            font-size: 22px;
            color: var(--brand);
            line-height: 1;
            margin-bottom: 4px;
        }

        .cert-summary-item span {
            font-size: 11px;
            color: var(--muted);
            font-weight: 800;
            text-transform: uppercase;
        }

        .certificates-grid {
            display: grid;
            gap: 10px;
        }

        .certificate-achievement-card {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--brand);
            border-radius: 14px;
            background: var(--surface);
            padding: 14px;
            display: grid;
            grid-template-columns: 44px minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
        }

        .certificate-achievement-card::before {
            content: "CERT";
            position: absolute;
            right: 12px;
            bottom: -8px;
            color: rgba(22,101,52,.06);
            font-size: 46px;
            font-weight: 1000;
            pointer-events: none;
        }

        .cert-achievement-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--brand-soft);
            border: 1px solid var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand);
            font-size: 22px;
            flex-shrink: 0;
        }

        .cert-achievement-body {
            min-width: 0;
            position: relative;
            z-index: 1;
        }

        .cert-achievement-title {
            font-size: 15px;
            font-weight: 900;
            color: var(--text);
            margin-bottom: 3px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cert-achievement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
        }

        .cert-achievement-pill {
            padding: 3px 7px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--surface-2);
        }

        .cert-achievement-pill.active {
            border-color: var(--success);
            background: #d1fae5;
            color: #065f46;
        }

        .cert-achievement-pill.revoked {
            border-color: var(--danger);
            background: #fee2e2;
            color: #991b1b;
        }

        .cert-achievement-actions {
            display: flex;
            gap: 6px;
            position: relative;
            z-index: 1;
        }

        .cert-mini-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 10px;
            border-radius: 8px;
            border: 1px solid var(--brand);
            background: var(--brand);
            color: #fff;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .cert-mini-link.secondary {
            background: var(--surface);
            color: var(--brand);
        }

        .cert-empty {
            border: 1px dashed var(--brand);
            border-radius: 14px;
            padding: 22px 14px;
            text-align: center;
            color: var(--muted);
            background: var(--surface);
        }

        .cert-empty .emoji {
            font-size: 34px;
            margin-bottom: 8px;
        }


        .form-grid { display: grid; gap: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-weight: 600; font-size: 14px; color: var(--text-secondary); }
        .form-control {
            padding: 12px 16px; border-radius: 10px; border: 1px solid var(--line);
            background: var(--surface-2); color: var(--text); font-size: 15px;
            transition: border 0.2s;
        }
        .form-control:focus { outline: none; border-color: var(--brand); background: var(--surface); }
        .form-control[readonly] { background: var(--surface-3); opacity: 0.7; }

        .image-upload-area {
            display: flex; align-items: center; gap: 20px; flex-wrap: wrap; 
        }
        .image-preview {
            width: 100px; height: 100px; border-radius: 50%; overflow: hidden;
            background: var(--surface-2); border: 2px dashed var(--brand);
            display: flex; align-items: center; justify-content: center;
        }
        .image-preview img { width: 100%; height: 100%; object-fit: cover; }
        .image-preview span { font-size: 32px; color: var(--muted); }
        .upload-controls { display: flex; flex-direction: column; gap: 10px;  }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 15px;
            text-decoration: none; transition: all 0.2s; cursor: pointer;
            border: 1px solid var(--line);
        }
        .btn-primary {
            background: var(--brand); color: white;
            box-shadow: 0 4px 10px rgba(22,101,52,0.2);
        }
        .btn-primary:hover { background: var(--brand-dark); transform: translateY(-2px); }
        .btn-secondary {
            background: var(--surface); color: var(--text);
        }
        .btn-secondary:hover { background: var(--surface-2); }
        .btn-outline {
            background: transparent; border: 1px solid var(--line); color: var(--text);
        }
        .btn-outline:hover { background: var(--surface-2); border-color: var(--brand); }

        .action-buttons { display: flex; gap: 16px; margin-top: 24px; flex-wrap: wrap; }

        .toast-container {
            position: fixed; top: 80px; right: 24px; z-index: 9999;
            display: flex; flex-direction: column; gap: 12px; max-width: 380px;
        }
        .toast {
            background: var(--surface); border-radius: 16px; padding: 16px 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-left: 6px solid;
            display: flex; align-items: center; gap: 14px; animation: slideIn 0.3s;
            border: 1px solid var(--line);
        }
        .toast.success { border-left-color: var(--success); }
        .toast.danger { border-left-color: var(--danger); }
        .toast i { font-size: 22px; }
        .toast.success i { color: var(--success); }
        .toast.danger i { color: var(--danger); }
        .toast-message { flex: 1; font-weight: 500; }
        .toast-close { cursor: pointer; opacity: 0.6; }
        @keyframes slideIn { from { transform: translateX(100%); opacity:0; } to { transform: translateX(0); opacity:1; } }

        @media (max-width: 768px) {
            .main-content { padding: 20px 16px; }
            .profile-header { flex-direction: column; align-items: center; gap: 16px; }
            .section-card { padding: 20px; }
            .action-buttons { flex-direction: column; }
            .btn { width: 100%; }

            .certificates-summary {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 6px;
            }

            .cert-summary-item {
                padding: 9px 6px;
            }

            .cert-summary-item strong {
                font-size: 18px;
            }

            .cert-summary-item span {
                font-size: 9px;
            }

            .certificate-achievement-card {
                grid-template-columns: 38px minmax(0, 1fr);
                gap: 9px;
            }

            .cert-achievement-icon {
                width: 38px;
                height: 38px;
                font-size: 19px;
            }

            .cert-achievement-actions {
                grid-column: 1 / -1;
                width: 100%;
            }

            .cert-mini-link {
                flex: 1;
            }
        }
    </style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

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
                        <p><?= e($user['username']) ?></p>
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
        <?php endforeach; if ($currentSubject !== null) echo '</div>'; ?>
    </nav>
</aside>

<main class="main-content">
    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-avatar-large">
            <?php if (!empty($user['picture'])): ?>
                <img src="<?= e($user['picture']) ?>" alt="Profile">
            <?php else: ?>
                <?= initials($user['name']) ?>
            <?php endif; ?>
        </div>
        <div class="profile-title">
            <h1><?= e($user['name']) ?></h1>
            <p>👤 <?= ucfirst($user['role']) ?> · 🪪 <?= e($user['username']) ?> · 📅 Member since <?= e($user['member_since']) ?></p>
        </div>
    </div>

    <!-- Certificates of Achievement Section -->
    <div class="section-card">
        <h2 class="section-title"><span>🎓</span> Certificates of Achievement</h2>

        <div class="certificates-summary">
            <div class="cert-summary-item">
                <strong><?= (int)$totalAchievementCertificates ?></strong>
                <span>Total</span>
            </div>
            <div class="cert-summary-item">
                <strong><?= (int)$totalActiveAchievementCertificates ?></strong>
                <span>Active</span>
            </div>
            <div class="cert-summary-item">
                <strong><?= (int)$totalGeneralAchievementCertificates ?></strong>
                <span>General</span>
            </div>
        </div>

        <?php if (empty($achievementCertificates)): ?>
            <div class="cert-empty">
                <div class="emoji">🎓</div>
                <h3 style="margin-bottom:6px;">No certificates yet</h3>
                <p style="font-size:14px;">Complete certification exams to earn certificates of achievement.</p>
                <div style="margin-top:12px;">
                    <a href="certification_exams.php" class="btn btn-primary">Go to Certification Exams</a>
                </div>
            </div>
        <?php else: ?>
            <div class="certificates-grid">
                <?php 
$currentSubject = null;
foreach ($achievementCertificates as $cert): 
    $subjectNameHeader = trim((string)($cert['subject_name'] ?? 'General'));
    if ($subjectNameHeader === '') $subjectNameHeader = 'General';
    if ($currentSubject !== $subjectNameHeader):
        if ($currentSubject !== null) echo '</div>'; // close previous group
        $currentSubject = $subjectNameHeader;
        echo '<h3 style="margin:12px 0 6px;font-size:14px;color:var(--brand);">📚 ' . e($currentSubject) . '</h3>';
        echo '<div class="certificates-grid">';
    endif;
?>
                    <?php
                        $certCode = (string)($cert['certificate_code'] ?? '');
                        $certType = (string)($cert['certification_type'] ?? '');
                        $certStatus = (string)($cert['status'] ?? 'active');
                        $isActiveCert = $certStatus === 'active';
                        $certTitle = trim((string)($cert['title'] ?? 'Certificate of Achievement'));
                        $subjectName = trim((string)($cert['subject_name'] ?? ''));
                        $certTrack = $certType === 'general'
                            ? 'General Certificate - All Subjects'
                            : ($subjectName !== '' ? $subjectName . ' Subject Certificate' : 'Subject Certificate');
                        $issuedAt = !empty($cert['issued_at']) ? date('M d, Y', strtotime((string)$cert['issued_at'])) : 'Not issued';
                        $certIcon = $certType === 'general' ? '🌍' : (!empty($cert['subject_icon']) ? (string)$cert['subject_icon'] : '📚');
                    ?>
                    <article class="certificate-achievement-card">
                        <div class="cert-achievement-icon"><?= e($certIcon) ?></div>

                        <div class="cert-achievement-body">
                            <div class="cert-achievement-title"><?= e($certTitle) ?></div>
                            <div class="cert-achievement-meta">
                                <span class="cert-achievement-pill"><?= e($certTrack) ?></span>
                                <span class="cert-achievement-pill <?= $isActiveCert ? 'active' : 'revoked' ?>">
                                    <?= $isActiveCert ? 'Active' : e(ucfirst($certStatus)) ?>
                                </span>
                                <span class="cert-achievement-pill">Issued <?= e($issuedAt) ?></span>
                                <?php if ($certCode !== ''): ?>
                                    <span class="cert-achievement-pill">Code <?= e($certCode) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="cert-achievement-actions">
                            <?php if ($certCode !== ''): ?>
                                <a class="cert-mini-link" href="certificate_view.php?code=<?= urlencode($certCode) ?>">View</a>
                                <a class="cert-mini-link secondary" href="verify_certificate.php?code=<?= urlencode($certCode) ?>">Verify</a>
                            <?php else: ?>
                                <a class="cert-mini-link secondary" href="my_certificates.php">Details</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; if ($currentSubject !== null) echo '</div>'; ?>
            </div>

            <div class="action-buttons" style="margin-top:16px;">
                <a href="my_certificates.php" class="btn btn-secondary">🎓 View All Certificates</a>
                <a href="certification_exams.php" class="btn btn-outline">📜 Certification Path</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Profile Section -->
    <div class="section-card">
        <h2 class="section-title"><span>✏️</span> Edit Profile</h2>
        <form id="profileForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" class="form-control" value="<?= e($user['name']) ?>"  readonly style="flex:1;">
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" value="<?= e($user['username']) ?>"  readonly style="flex:1;">
                </div>
                <div class="form-group">
                    <label>Profile Picture</label>
                    <div class="image-upload-area">
                        <div class="image-preview" id="imagePreview">
                            <?php if (!empty($user['picture'])): ?>
                                <img src="<?= e($user['picture']) ?>" alt="Preview">
                            <?php else: ?>
                                <span><?= initials($user['name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="upload-controls">
                            <input type="file" name="profile_image" id="profileImageInput" accept="image/*" style="display: none;">
                            <button type="button" class="btn btn-outline" id="uploadBtn">📁 Choose Image</button>
                            <button type="button" class="btn btn-outline" id="removeImageBtn">🗑️ Remove</button>
                            <p style="font-size: 12px; color: var(--muted);">JPEG, PNG, GIF, WebP (max 2MB)</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="action-buttons">
                <button type="submit" class="btn btn-primary" id="saveProfileBtn">💾 Save Changes</button>
                <button type="reset" class="btn btn-secondary">Reset</button>
            </div>
        </form>
    </div>

    <!-- Change Password Section -->
    <div class="section-card">
        <h2 class="section-title"><span>🔒</span> Change Password</h2>
        <form id="passwordForm">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password">
                </div>
            </div>
            <div class="action-buttons">
                <button type="submit" class="btn btn-primary" id="changePasswordBtn">🔐 Update Password</button>
            </div>
        </form>
    </div>
</main>

<script>
    // ===== HEADER & SIDEBAR FUNCTIONALITY =====
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
    })();

    // ===== TOAST NOTIFICATIONS =====
    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <i>${type === 'success' ? '✅' : '❌'}</i>
            <span class="toast-message">${message}</span>
            <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
        `;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 6000);
    }

    // ===== PROFILE IMAGE UPLOAD PREVIEW =====
    const profileImageInput = document.getElementById('profileImageInput');
    const uploadBtn = document.getElementById('uploadBtn');
    const removeImageBtn = document.getElementById('removeImageBtn');
    const imagePreview = document.getElementById('imagePreview');
    const originalImageHtml = imagePreview.innerHTML;

    uploadBtn.addEventListener('click', () => profileImageInput.click());

    profileImageInput.addEventListener('change', function(e) {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                imagePreview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
            };
            reader.readAsDataURL(file);
        }
    });

    removeImageBtn.addEventListener('click', function() {
        profileImageInput.value = '';
        imagePreview.innerHTML = originalImageHtml;
    });

    // ===== FORM SUBMISSION (AJAX) =====
    async function submitForm(form, successCallback) {
        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Processing...';

        try {
            const response = await fetch('profile.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            showToast(data.message, data.success ? 'success' : 'danger');
            if (data.success && successCallback) successCallback(data);
        } catch (error) {
            showToast('An error occurred. Please try again.', 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    const profileForm = document.getElementById('profileForm');
    profileForm.addEventListener('submit', (e) => {
        e.preventDefault();
        submitForm(profileForm, (data) => {
            // Update header profile picture if changed
            const newImage = imagePreview.querySelector('img');
            if (newImage) {
                const headerImg = document.querySelector('.profile-btn img');
                if (headerImg) headerImg.src = newImage.src;
                const menuImg = document.querySelector('.profile-menu-top img');
                if (menuImg) menuImg.src = newImage.src;
            }
        });
    });

    const passwordForm = document.getElementById('passwordForm');
    passwordForm.addEventListener('submit', (e) => {
        e.preventDefault();
        submitForm(passwordForm, () => {
            passwordForm.reset();
        });
    });

    // ===== RESET BUTTON =====
    const resetBtn = profileForm.querySelector('button[type="reset"]');
    resetBtn.addEventListener('click', () => {
        imagePreview.innerHTML = originalImageHtml;
        profileImageInput.value = '';
    });
</script>
</body>
</html>