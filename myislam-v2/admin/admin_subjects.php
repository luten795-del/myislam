<?php
declare(strict_types=1);
ini_set('display_errors', '1');
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notifications.php';

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

function make_slug(string $text): string {
    $text = trim($text);
    if ($text === '') return 'subject';
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text, '-');
    return $text !== '' ? $text : 'subject';
}

// Check login
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: /login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn) {
    die('Unable to connect to database.');
}

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

function getSubjects(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT
                s.id,
                s.name,
                s.type,
                s.slug,
                s.description,
                s.icon,
                s.sort_order,
                s.is_active,
                s.sheikh_name,
                s.markaz,
                COUNT(DISTINCT c.id) AS total_categories,
                COUNT(DISTINCT ct.id) AS total_contents
            FROM subjects s
            LEFT JOIN categories c ON c.subject_id = s.id
            LEFT JOIN contents ct ON ct.subject_id = s.id
            GROUP BY s.id, s.name,s.type, s.slug, s.description, s.icon, s.sort_order, s.is_active, s.sheikh_name, s.markaz
            ORDER BY s.sort_order ASC, s.name ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getSubjects: " . $e->getMessage());
        return [];
    }
}

function getSubjectById(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("
        SELECT
            id,
            name,
            type,
            slug,
            description,
            icon,
            image_path,
            sort_order,
            is_active,
            sheikh_name,
            markaz
        FROM subjects
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getSubjectById: " . $e->getMessage());
        return null;
    }
}

function slugExists(PDO $conn, string $slug, ?int $excludeId = null): bool {
    try {
        if ($excludeId && $excludeId > 0) {
            $stmt = $conn->prepare("SELECT id FROM subjects WHERE slug = ? AND id != ? LIMIT 1");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM subjects WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
        }
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        error_log("slugExists: " . $e->getMessage());
        return false;
    }
}

function uniqueSlug(PDO $conn, string $baseSlug, ?int $excludeId = null): string {
    $slug = $baseSlug !== '' ? $baseSlug : 'subject';
    $originalSlug = $slug;
    $counter = 1;
    
    while (slugExists($conn, $slug, $excludeId)) {
        $slug = $originalSlug . '-' . $counter;
        $counter++;
        
        // Safety break
        if ($counter > 100) {
            $slug = $originalSlug . '-' . time();
            break;
        }
    }
    
    return $slug;
}

$admin = getAdminUser($conn, $userId);
if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Admins only.');
}

$message = '';
$messageType = 'success';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ========== AJAX ENDPOINT FOR EDIT ==========
if (isset($_GET['ajax']) && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editSubject = getSubjectById($conn, $editId);
    if ($editSubject) {
        header('Content-Type: application/json');
        echo json_encode($editSubject);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Subject not found']);
        exit;
    }
}
// ===========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_subject') {
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        $name = trim((string)($_POST['name'] ?? ''));
        $type = trim((string)($_POST['type'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $icon = trim((string)($_POST['icon'] ?? '📚'));
        $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $sheikhName = trim((string)($_POST['sheikh_name'] ?? ''));
        $markaz = trim((string)($_POST['markaz'] ?? ''));
        
        // Generate base slug
        $baseSlug = make_slug($slugInput !== '' ? $slugInput : $name);
        
        // Validate name
        if ($name === '') {
            $message = 'Subject name is required.';
            $messageType = 'danger';
        } else {
            // Get unique slug
            $slug = uniqueSlug($conn, $baseSlug, $subjectId > 0 ? $subjectId : null);
            
            // Handle image upload
            $imagePath = $_POST['existing_image'] ?? null;
            
            if (isset($_FILES['subject_image']) && $_FILES['subject_image']['error'] === UPLOAD_ERR_OK && $_FILES['subject_image']['size'] > 0) {
                $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/subjects/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                $ext = strtolower(pathinfo($_FILES['subject_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $filename = 'subject_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($_FILES['subject_image']['tmp_name'], $uploadDir . $filename)) {
                        $imagePath = '/uploads/subjects/' . $filename;
                    }
                }
            }
            
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                if ($subjectId > 0) {
                    // UPDATE
                   $stmt = $conn->prepare("
                UPDATE subjects
                SET
                    name = ?,
                    type = ?,
                    slug = ?,
                    description = ?,
                    icon = ?,
                    image_path = ?,
                    sort_order = ?,
                    is_active = ?,
                    sheikh_name = ?,
                    markaz = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $name,
                $type ?: null,
                $slug,
                $description,
                $icon,
                $imagePath,
                $sortOrder,
                $isActive,
                $sheikhName ?: null,
                $markaz ?: null,
                $subjectId
            ]);
                    $message = 'Subject updated successfully.';
                } else {
                    // INSERT
                  $stmt = $conn->prepare("
                    INSERT INTO subjects
                    (name, type, slug, description, icon, image_path, sort_order, is_active, sheikh_name, markaz)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $name,
                    $type ?: null,
                    $slug,
                    $description,
                    $icon,
                    $imagePath,
                    $sortOrder,
                    $isActive,
                    $sheikhName ?: null,
                    $markaz ?: null
                ]);
                    $message = 'Subject created successfully.';
                }
                
                // Commit transaction
                $conn->commit();
                $messageType = 'success';
                
            } catch (PDOException $e) {
                // Rollback on error
                $conn->rollBack();
                error_log("save_subject error: " . $e->getMessage());
                $message = 'Database error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }

    if ($action === 'toggle_subject') {
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;

        if ($subjectId > 0) {
            try {
                $stmt = $conn->prepare("UPDATE subjects SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$subjectId]);
                $message = 'Subject status updated.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("toggle_subject: " . $e->getMessage());
                $message = 'Unable to update subject status.';
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }

    if ($action === 'delete_subject') {
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        if ($subjectId > 0) {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM categories WHERE subject_id = ?");
                $stmt->execute([$subjectId]);
                $categoryCount = (int)$stmt->fetchColumn();

                $stmt = $conn->prepare("SELECT COUNT(*) FROM contents WHERE subject_id = ?");
                $stmt->execute([$subjectId]);
                $contentCount = (int)$stmt->fetchColumn();

                if ($categoryCount > 0 || $contentCount > 0) {
                    $message = 'Cannot delete subject with linked categories or contents.';
                    $messageType = 'danger';
                } else {
                    $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
                    $stmt->execute([$subjectId]);
                    $message = 'Subject deleted.';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Unable to delete subject.';
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

$subjects = getSubjects($conn);
$totalSubjects = count($subjects);
$activeSubjects = count(array_filter($subjects, fn($s) => (int)($s['is_active'] ?? 0) === 1));
$totalCategories = array_sum(array_map(fn($s) => (int)($s['total_categories'] ?? 0), $subjects));
$totalContents = array_sum(array_map(fn($s) => (int)($s['total_contents'] ?? 0), $subjects));

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => true],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => true, 'icon' => '📚'],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false, 'icon' => '🗂️'],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false, 'icon' => '📘'],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false, 'icon' => '👥'],
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
<title>Admin Subjects - MyIslam</title>
<style>
/* Keep all your existing CSS here - unchanged */
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
a{text-decoration:none;color:inherit} button,input,textarea{font:inherit}
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
.main-content{padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px);max-width:1400px;margin:0 auto}
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
.subjects-list{display:grid;gap:12px}
.subject-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px}
.subject-left{display:flex;align-items:flex-start;gap:12px}
.subject-icon{width:46px;height:46px;border-radius:14px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.subject-name{font-size:16px;font-weight:800;margin-bottom:4px}
.subject-meta{font-size:12px;color:var(--muted);line-height:1.6}
.subject-actions{display:flex;gap:10px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;background:var(--brand-soft);color:var(--brand)}
.badge.danger{background:rgba(239,68,68,.12);color:var(--danger)}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.btn{padding:10px 16px;border-radius:14px;font-weight:600;border:none;cursor:pointer;transition:.2s;font-size:13px;display:inline-flex;align-items:center;gap:6px}
.btn.primary{background:var(--brand);color:#fff}
.btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
.btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
.btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.btn:hover{transform:translateY(-2px)}


/* Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center}
.modal{background:var(--surface);border-radius:var(--radius-lg);max-width:500px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid var(--line)}
.modal-header{padding:24px 24px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.modal-header h2{font-size:20px;font-weight:700}
.modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted)}
.modal-body{padding:24px}
.form-fields{display:grid;gap:14px}
.form-row{display:grid;gap:8px}
.form-row label{font-size:14px;font-weight:700}
.form-input,.form-textarea{width:100%;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:12px 14px;outline:none}
.form-input:focus,.form-textarea:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.form-textarea{min-height:80px;resize:vertical}
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
@media (max-width:900px){.header-top-nav{display:none}}
@media (max-width:768px){
:root{--header-height:64px;--footer-height:80px}
.main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}
.section-card{padding:16px}.hero-card h1{font-size:24px}.stats-grid,.footer-grid,.inline-row{grid-template-columns:1fr;gap:12px}.subject-item{flex-direction:column}.footer-action{min-height:64px}
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
                    <h1>📚 Manage Subjects</h1>
                    <p>Create, edit, search, and activate or deactivate learning subjects.</p>
                </div>
                <button class="btn primary" id="createSubjectBtn">➕ Create Subject</button>
            </section>

            <div class="stats-grid">
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $totalSubjects; ?></div>
                    <div class="stat-label">Total Subjects</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $activeSubjects; ?></div>
                    <div class="stat-label">Active Subjects</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $totalCategories; ?></div>
                    <div class="stat-label">Linked Categories</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $totalContents; ?></div>
                    <div class="stat-label">Linked Contents</div>
                </div>
            </div>

            <section class="section-card">
                <div class="filters-row">
                    <div class="search-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="subjectSearchInput" class="search-input" placeholder="Search by name, slug, description...">
                    </div>
                </div>

                <?php if (!empty($subjects)): ?>
                    <div class="subjects-list" id="subjectsList">
                        <?php foreach ($subjects as $subject): ?>
                            <?php
                            $searchBlob = strtolower(
                                ($subject['name'] ?? '') . ' ' .
                                ($subject['type'] ?? '') . ' ' .
                                ($subject['slug'] ?? '') . ' ' .
                                ($subject['description'] ?? '') . ' ' .
                                ($subject['icon'] ?? '') . ' ' .
                                ($subject['sheikh_name'] ?? '') . ' ' .
                                ($subject['markaz'] ?? '')
                            );
                            ?>
                            <div class="subject-item subject-search-item" data-search="<?php echo e($searchBlob); ?>" data-subject-id="<?php echo (int)$subject['id']; ?>">
                                <div class="subject-left">
                                    <div class="subject-icon"><?php echo e($subject['icon'] ?? '📚'); ?></div>
                                    <div>
                                        <div class="subject-name"><?php echo e($subject['name'] ?? 'Untitled'); ?></div>
                                        <div class="subject-meta">
                                            Type: <?php echo !empty($subject['type']) ? e($subject['type']) : '<em>None</em>'; ?><br>
                                            Slug: <?php echo e($subject['slug'] ?? '--'); ?><br>
                                            Categories: <?php echo (int)($subject['total_categories'] ?? 0); ?> |
                                            Contents: <?php echo (int)($subject['total_contents'] ?? 0); ?><br>
                                            Sort Order: <?php echo (int)($subject['sort_order'] ?? 0); ?><br>
                                            Sheikh: <?php echo !empty($subject['sheikh_name']) ? e($subject['sheikh_name']) : '<em>None</em>'; ?>
                                            <?php if (!empty($subject['markaz'])): ?>
                                                · Markaz: <?php echo e($subject['markaz']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="subject-actions">
                                    <span class="badge <?php echo (int)($subject['is_active'] ?? 0) === 1 ? '' : 'danger'; ?>">
                                        <?php echo (int)($subject['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <button class="btn secondary edit-subject-btn" data-subject-id="<?php echo (int)$subject['id']; ?>">✏️ Edit</button>
                                    <button class="btn warn toggle-subject-btn" data-subject-id="<?php echo (int)$subject['id']; ?>">
                                        <?php echo (int)($subject['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                    <?php if ((int)($subject['total_categories'] ?? 0) === 0 && (int)($subject['total_contents'] ?? 0) === 0): ?>
                                    <button class="btn danger delete-subject-btn" data-subject-id="<?php echo (int)$subject['id']; ?>">🗑️ Delete</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="emptySearchState" class="empty-state" style="display:none;">No matching subjects found.</div>
                <?php else: ?>
                    <div class="empty-state">No subjects found. Click "Create Subject" to add one.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>

   
<!-- Subject Modal -->
<div class="modal-overlay" id="subjectModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Create New Subject</h2>
            <button class="modal-close" id="closeModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="subjectForm">
                <input type="hidden" name="action" value="save_subject">
                <input type="hidden" name="subject_id" id="subject_id" value="0">

                <div class="form-row">
                    <label for="name">Subject Name *</label>
                    <input type="text" id="name" name="name" class="form-input" required placeholder="e.g. Fiqh">
                    <label for="type">Subject Type</label>

                <select id="type" name="type" class="form-input">

                    <option value="">Select subject type</option>

                    <option value="Qur-aan">Qur-aan</option>
                    <option value="Hadith">Hadith</option>
                                        <option value="Seerah">Seerah</option>
                    <option value="Lugha">Lugha</option>

                    <option value="Sunnah">Sunnah</option>
                    <option value="Tawhiyd">Tawhiyd</option>
                    <option value="Aqiydah">Aqiydah</option>
                    <option value="Manhaj">Manhaj</option>
                    <option value="Shirk & Kufru">Shirk & Kufru</option>
                    <option value="Bid-'ah (Uzushi)">Bid-'ah (Uzushi)</option>
                    <option value="Salaf Wa Ummah">Salaf Wa Ummah</option>
                    <option value="Firaq-Makundi">Firaq-Makundi</option>

                </select>
                </div>

                <div class="form-row">
                    <label for="slug">Slug</label>
                    <input type="text" id="slug" name="slug" class="form-input" placeholder="auto-generated from name">
                </div>

                <div class="inline-row">
                    <div class="form-row">
                        <label for="icon">Icon</label>
                        <input type="text" id="icon" name="icon" class="form-input" value="📚" placeholder="📚">
                    </div>
                    <div class="form-row">
                        <label for="sort_order">Sort Order</label>
                        <input type="number" id="sort_order" name="sort_order" class="form-input" value="0" min="0">
                    </div>
                </div>

                <div class="form-row">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-textarea" placeholder="Brief description"></textarea>
                </div>

                <div class="inline-row">
                    <div class="form-row">
                        <label for="sheikh_name">Sheikh Name</label>
                        <input type="text" id="sheikh_name" name="sheikh_name" class="form-input" placeholder="e.g. Sheikh Mohammed">
                    </div>
                    <div class="form-row">
                        <label for="markaz">Markaz</label>
                        <input type="text" id="markaz" name="markaz" class="form-input" placeholder="e.g. Markaz As-Sunnah">
                    </div>
                </div>

                <div class="form-row">
                    <label>Subject Background Image</label>
                    <div id="imagePreviewContainer" style="display:none;margin-bottom:8px;">
                        <img id="imagePreview" src="" style="max-width:200px;max-height:120px;border-radius:8px;border:1px solid var(--line);">
                    </div>
                    <input type="file" id="subject_image" name="subject_image" accept="image/*" style="padding:8px;" onchange="previewImage(this)">
                    <input type="hidden" id="existing_image" name="existing_image" value="">
                    <small style="color:var(--muted);">Recommended: 800x400px</small>
                </div>

                <label class="check-row">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    <span>Active Subject</span>
                </label>

                <div class="action-row">
                    <button type="submit" class="btn primary">Save Subject</button>
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
const modal = document.getElementById('subjectModal');
const createBtn = document.getElementById('createSubjectBtn');
const closeModalBtn = document.getElementById('closeModalBtn');
const cancelModalBtn = document.getElementById('cancelModalBtn');
const subjectForm = document.getElementById('subjectForm');
const modalTitle = document.getElementById('modalTitle');

function openModal(data = null) {
    if (data) {
        modalTitle.textContent = 'Edit Subject';
        document.getElementById('subject_id').value = data.id || 0;
        document.getElementById('name').value = data.name || '';
        document.getElementById('type').value = data.type || '';
        document.getElementById('slug').value = data.slug || '';
        document.getElementById('icon').value = data.icon || '📚';
        document.getElementById('sort_order').value = data.sort_order || 0;
        document.getElementById('description').value = data.description || '';
        document.getElementById('is_active').checked = data.is_active == 1;
        document.getElementById('sheikh_name').value = data.sheikh_name || '';
        document.getElementById('markaz').value = data.markaz || '';
        document.getElementById('existing_image').value = data.image_path || '';
        const preview = document.getElementById('imagePreview');
        const previewContainer = document.getElementById('imagePreviewContainer');
        if (data.image_path) {
            preview.src = data.image_path;
            previewContainer.style.display = 'block';
        } else {
            previewContainer.style.display = 'none';
        }
    } else {
        modalTitle.textContent = 'Create New Subject';
        subjectForm.reset();
        document.getElementById('subject_id').value = '0';
        document.getElementById('icon').value = '📚';
        document.getElementById('is_active').checked = true;
        document.getElementById('sheikh_name').value = '';
        document.getElementById('markaz').value = '';
        document.getElementById('imagePreviewContainer').style.display = 'none';
        document.getElementById('existing_image').value = '';
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const container = document.getElementById('imagePreviewContainer');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            container.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

createBtn.addEventListener('click', () => openModal());
closeModalBtn.addEventListener('click', closeModal);
cancelModalBtn.addEventListener('click', closeModal);
modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

// Auto slug
document.getElementById('name').addEventListener('input', function() {
    const slugInput = document.getElementById('slug');
    if (!slugInput.value || slugInput.value === makeSlug(slugInput.dataset.old || '')) {
        slugInput.value = makeSlug(this.value);
        slugInput.dataset.old = this.value;
    }
});
function makeSlug(t) { return t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }

// Form submit AJAX
subjectForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(subjectForm);
    try {
        const response = await fetch('admin_subjects.php', {
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const result = await response.json();
        showToast(result.message, result.success ? 'success' : 'danger');
        if (result.success) {
            closeModal();
            setTimeout(() => location.reload(), 1500);
        }
    } catch (err) {
        showToast('An error occurred: ' + err.message, 'danger');
    }
});

// Edit buttons
document.querySelectorAll('.edit-subject-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.subjectId;
        try {
            const response = await fetch(`admin_subjects.php?ajax=1&edit=${id}`);
            if (!response.ok) throw new Error('Failed to load');
            const data = await response.json();
            if (data.error) {
                showToast(data.error, 'danger');
            } else {
                openModal(data);
            }
        } catch (err) {
            showToast('Failed to load subject', 'danger');
        }
    });
});

// Toggle status
document.querySelectorAll('.toggle-subject-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.subjectId;
        const formData = new FormData();
        formData.append('action', 'toggle_subject');
        formData.append('subject_id', id);
        try {
            const response = await fetch('admin_subjects.php', {
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

// Delete subject
document.querySelectorAll('.delete-subject-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!confirm('Delete this subject?')) return;
        const id = btn.dataset.subjectId;
        const formData = new FormData();
        formData.append('action', 'delete_subject');
        formData.append('subject_id', id);
        try {
            const response = await fetch('admin_subjects.php', {
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
const searchInput = document.getElementById('subjectSearchInput');
const items = document.querySelectorAll('.subject-search-item');
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
</script>
</body>
</html>