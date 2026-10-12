<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';

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
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim((string)$text, '-');
    return $text !== '' ? $text : 'cert-exam';
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();
if (!$conn) {
    die('Unable to connect to database.');
}

// ---------- Auto‑add all required columns ----------
try {
    $conn->exec("ALTER TABLE certification_exams ADD COLUMN IF NOT EXISTS `start_date` datetime DEFAULT NULL");
    $conn->exec("ALTER TABLE certification_exams ADD COLUMN IF NOT EXISTS `end_date` datetime DEFAULT NULL");
    $conn->exec("ALTER TABLE certification_exams ADD COLUMN IF NOT EXISTS `month_year` char(7) DEFAULT NULL");
    $conn->exec("ALTER TABLE certification_exams ADD COLUMN IF NOT EXISTS `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft'");
    $conn->exec("ALTER TABLE certification_exams ADD COLUMN IF NOT EXISTS `max_attempts_per_level` int NOT NULL DEFAULT 3");
    $conn->exec("ALTER TABLE certification_exams ADD COLUMN IF NOT EXISTS `retry_delay_hours` int NOT NULL DEFAULT 24");
    $conn->exec("ALTER TABLE certification_exams ADD COLUMN IF NOT EXISTS `attempt_sequence` int NOT NULL DEFAULT 1");
} catch (Throwable $e) {}

// ---------- Helper functions ----------
function getAdminUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("SELECT id, full_name, email, profile_image, role, is_active, created_at FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) return null;
        if (!in_array(strtolower((string)($user['role'] ?? '')), ['admin','owner','developer'])) return null;
        return $user;
    } catch (PDOException $e) {
        error_log("getAdminUser: " . $e->getMessage());
        return null;
    }
}

function getSubjects(PDO $conn): array {
    try {
        return $conn->query("SELECT id, name, slug, icon, is_active FROM subjects ORDER BY name ASC")->fetchAll();
    } catch (PDOException $e) {
        error_log("getSubjects: " . $e->getMessage());
        return [];
    }
}

function getCertExams(PDO $conn): array {
    try {
        return $conn->query("
            SELECT
                ce.id, ce.title, ce.description, ce.slug, ce.certification_type, ce.subject_id,
                ce.level, ce.attempt_sequence, ce.month_year, ce.start_date, ce.end_date,
                ce.passing_score, ce.time_limit_minutes, ce.status, ce.created_at,
                ce.max_attempts_per_level, ce.retry_delay_hours,
                s.name AS subject_name,
                (SELECT COUNT(*) FROM certification_exam_questions WHERE exam_id = ce.id) AS total_questions,
                (SELECT COUNT(*) FROM certification_exam_attempts WHERE exam_id = ce.id) AS total_attempts
            FROM certification_exams ce
            LEFT JOIN subjects s ON s.id = ce.subject_id
            ORDER BY ce.certification_type, ce.subject_id, ce.level, ce.attempt_sequence
        ")->fetchAll();
    } catch (PDOException $e) {
        error_log("getCertExams: " . $e->getMessage());
        return [];
    }
}

function getCertExamById(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("SELECT * FROM certification_exams WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getCertExamById: " . $e->getMessage());
        return null;
    }
}

function slugExists(PDO $conn, string $slug, ?int $excludeId = null): bool {
    try {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT id FROM certification_exams WHERE slug = ? AND id != ? LIMIT 1");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM certification_exams WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
        }
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

function uniqueSlug(PDO $conn, string $baseSlug, ?int $excludeId = null): string {
    $slug = $baseSlug !== '' ? $baseSlug : 'cert-exam';
    $counter = 1;
    while (slugExists($conn, $slug, $excludeId)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
    return $slug;
}

// ========== AJAX edit endpoint ==========
if (isset($_GET['ajax']) && isset($_GET['edit'])) {
    header('Content-Type: application/json; charset=utf-8');
    $admin = getAdminUser($conn, $userId);
    if (!$admin) {
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }
    $editId = (int)$_GET['edit'];
    $examData = getCertExamById($conn, $editId);
    if ($examData) {
        echo json_encode($examData, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Exam not found']);
    }
    exit;
}

// ========== Main admin check ==========
$admin = getAdminUser($conn, $userId);
if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Admins only.');
}

// ========== POST handlers ==========
$message = '';
$messageType = 'success';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_exam') {
        $examId = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $type = $_POST['certification_type'] ?? 'subject';
        $subjectId = ($type === 'subject') ? (isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? (int)$_POST['subject_id'] : null) : null;
        $level = (int)($_POST['level'] ?? 1);
        $attemptSequence = max(1, (int)($_POST['attempt_sequence'] ?? 1));
        $monthYear = trim((string)($_POST['month_year'] ?? ''));
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));
        $timeLimit = isset($_POST['time_limit_minutes']) && $_POST['time_limit_minutes'] !== '' ? (int)$_POST['time_limit_minutes'] : null;
        $passingScore = isset($_POST['passing_score']) ? (int)$_POST['passing_score'] : 70;
        $status = trim((string)($_POST['status'] ?? 'draft'));

        $maxAttempts = max(1, (int)($_POST['max_attempts_per_level'] ?? 3));
        $retryDelay  = max(0, (int)($_POST['retry_delay_hours'] ?? 24));

        if (!in_array($status, ['draft', 'published', 'archived'])) $status = 'draft';
        if ($passingScore < 0 || $passingScore > 100) $passingScore = 70;
        if ($level < 1 || $level > 4) $level = 1;

        $startDb = $startDate ? date('Y-m-d H:i:s', strtotime($startDate)) : null;
        $endDb = $endDate ? date('Y-m-d H:i:s', strtotime($endDate)) : null;

        if ($title === '') {
            $message = 'Exam title is required.';
            $messageType = 'danger';
        } elseif (!$startDb || !$endDb) {
            $message = 'Start and end dates are required.';
            $messageType = 'danger';
        } elseif (strtotime($endDb) < strtotime($startDb)) {
            $message = 'End date must be after start date.';
            $messageType = 'danger';
        } else {
            $slug = make_slug($slugInput !== '' ? $slugInput : $title);
            $slug = uniqueSlug($conn, $slug, $examId > 0 ? $examId : null);

            try {
                if ($examId > 0) {
                    $stmt = $conn->prepare("UPDATE certification_exams SET title=?, slug=?, description=?, certification_type=?, subject_id=?, level=?, attempt_sequence=?, month_year=?, start_date=?, end_date=?, time_limit_minutes=?, passing_score=?, status=?, max_attempts_per_level=?, retry_delay_hours=? WHERE id=?");
                    $stmt->execute([$title, $slug, $description, $type, $subjectId, $level, $attemptSequence, $monthYear, $startDb, $endDb, $timeLimit, $passingScore, $status, $maxAttempts, $retryDelay, $examId]);
                    $message = 'Certification exam updated successfully.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO certification_exams (title, slug, description, certification_type, subject_id, level, attempt_sequence, month_year, start_date, end_date, time_limit_minutes, passing_score, status, max_attempts_per_level, retry_delay_hours, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$title, $slug, $description, $type, $subjectId, $level, $attemptSequence, $monthYear, $startDb, $endDb, $timeLimit, $passingScore, $status, $maxAttempts, $retryDelay, $userId]);
                    $newExamId = (int)$conn->lastInsertId();
                    $message = 'Certification exam created successfully.';
                    $redirectUrl = 'admin_certification_questions.php?exam_id=' . $newExamId;
                }
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message, 'redirect' => $redirectUrl ?? null]);
            exit;
        }
    }

    if ($action === 'toggle_status') {
        $examId = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        if ($examId > 0) {
            try {
                $conn->prepare("UPDATE certification_exams SET status = IF(status='published','draft','published') WHERE id=?")->execute([$examId]);
                $message = 'Exam status updated.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Unable to update status.';
                $messageType = 'danger';
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }

    if ($action === 'delete_exam') {
        $examId = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        if ($examId > 0) {
            try {
                $attempts = (int)$conn->query("SELECT COUNT(*) FROM certification_exam_attempts WHERE exam_id = $examId")->fetchColumn();
                if ($attempts > 0) {
                    $message = 'Cannot delete exam with attempts. Archive it instead.';
                    $messageType = 'danger';
                } else {
                    $conn->prepare("DELETE FROM certification_exams WHERE id=?")->execute([$examId]);
                    $message = 'Exam deleted.';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Unable to delete exam.';
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

// ========== Fetch data for display ==========
$subjects = getSubjects($conn);
$exams = getCertExams($conn);
$totalExams = count($exams);
$publishedExams = count(array_filter($exams, fn($e) => ($e['status'] ?? '') === 'published'));
$activeExams = count(array_filter($exams, fn($e) => strtotime($e['end_date'] ?? '') >= time() && strtotime($e['start_date'] ?? '') <= time()));
$draftExams = count(array_filter($exams, fn($e) => ($e['status'] ?? '') === 'draft'));

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Monthly Exams', 'link' => 'admin_monthly_exams.php', 'active' => false],
    ['name' => 'Certification Exams', 'link' => 'certification_exams.php', 'active' => true],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false],
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
    ['name' => 'Monthly Exams', 'link' => 'admin_monthly_exams.php', 'active' => false, 'icon' => '📅'],
    ['name' => 'Certification Exams', 'link' => 'certification_exams.php', 'active' => true, 'icon' => '📜'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false, 'icon' => '👥'],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false, 'icon' => '📢'],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false, 'icon' => '⚙️'],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false, 'icon' => '🚪'],
];

$selfScript = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Certification Exams - MyIslam</title>
    <style>
        /* ===== FULL CSS (unchanged from previous) ===== */
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
        .filters-row{display:grid;grid-template-columns:1fr 180px 150px 120px;gap:12px;margin-bottom:16px}
        .search-wrap{position:relative}
        .search-input{width:100%;height:48px;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:0 16px 0 46px;outline:none}
        .search-input:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
        .search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:18px}
        .exam-list{display:grid;gap:12px}
        .exam-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px}
        .exam-left{display:flex;align-items:flex-start;gap:12px;flex:1}
        .exam-icon{width:46px;height:46px;border-radius:14px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
        .exam-title{font-size:16px;font-weight:800;margin-bottom:4px}
        .exam-meta{font-size:12px;color:var(--muted);line-height:1.7}
        .exam-actions{display:flex;gap:10px;flex-wrap:wrap}
        .badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;background:var(--brand-soft);color:var(--brand)}
        .badge.warn{background:rgba(245,158,11,.12);color:var(--warning)}
        .badge.success{background:rgba(16,185,129,.12);color:var(--success)}
        .badge.neutral{background:var(--surface-3);color:var(--muted)}
        .empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
        .btn{padding:10px 16px;border-radius:14px;font-weight:600;border:none;cursor:pointer;transition:.2s;font-size:13px;display:inline-flex;align-items:center;gap:6px}
        .btn.primary{background:var(--brand);color:#fff}
        .btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
        .btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
        .btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
        .btn:hover{transform:translateY(-2px)}

        .modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center}
        .modal{background:var(--surface);border-radius:var(--radius-lg);max-width:600px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid var(--line)}
        .modal-header{padding:24px 24px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
        .modal-header h2{font-size:20px;font-weight:700}
        .modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted)}
        .modal-body{padding:24px}
        .form-row{display:grid;gap:8px}
        .form-row label{font-size:14px;font-weight:700}
        .form-input,.form-textarea,.form-select{width:100%;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:12px 14px;outline:none}
        .form-input:focus,.form-textarea:focus,.form-select:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
        .form-textarea{min-height:100px;resize:vertical}
        .inline-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .action-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
        .toast-container{position:fixed;top:80px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px}
        .toast{padding:16px 20px;border-radius:var(--radius-md);background:var(--success);color:#fff;box-shadow:var(--shadow-lg);font-weight:500;display:flex;align-items:center;gap:10px;animation:slideIn 0.3s ease;max-width:400px}
        .toast.success{background:#10b981}
        .toast.danger{background:#ef4444}
        .toast .close-toast{margin-left:auto;cursor:pointer;font-size:20px;opacity:0.8}
        @keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
        @media (max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        @media (max-width:950px){.header-top-nav{display:none}.filters-row{grid-template-columns:1fr}}
        @media (max-width:768px){
            :root{--header-height:64px;--footer-height:80px}
            .main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}
            .section-card{padding:16px}.hero-card h1{font-size:24px}.stats-grid,.footer-grid,.inline-row{grid-template-columns:1fr;gap:12px}.exam-item{flex-direction:column}
        }
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle">☰</button>
        <div class="brand-box">🛠️ Admin</div>
        <nav class="header-top-nav">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?= e($navItem['link']) ?>" class="header-top-link <?= $navItem['active'] ? 'active' : '' ?>"><?= e($navItem['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle">
                <?php if (!empty($admin['profile_image'])): ?><img src="<?= e($admin['profile_image']) ?>" alt="Profile">
                <?php else: ?><?= e(initials((string)$admin['full_name'])) ?><?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($admin['profile_image'])): ?><img src="<?= e($admin['profile_image']) ?>" alt="Profile">
                    <?php else: ?><div class="profile-avatar-fallback"><?= e(initials((string)$admin['full_name'])) ?></div><?php endif; ?>
                    <div><h4><?= e($admin['full_name']) ?></h4><p><?= e($admin['email']) ?></p></div>
                </div>
                <a href="../profile.php">👤 My Profile</a><a href="admin_settings.php">⚙️ Settings</a><a href="../logout.php">🚪 Logout</a>
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
                <a href="<?= e($item['link']) ?>" class="subject-link <?= $item['active'] ? 'active' : '' ?>"><span><?= e($item['icon']) ?></span><span><?= e($item['name']) ?></span></a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-stack">
            <section class="hero-card section-card">
                <div><h1>📜 Certification Exams</h1><p>Manage certification exams, attempt sequence, windows, and limits.</p></div>
                <button class="btn primary" id="createExamBtn">➕ Create Certification Exam</button>
            </section>

            <div class="stats-grid">
                <div class="section-card stat-card"><div class="stat-value"><?= $totalExams ?></div><div class="stat-label">Total Exams</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?= $publishedExams ?></div><div class="stat-label">Published</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?= $activeExams ?></div><div class="stat-label">Currently Active</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?= $draftExams ?></div><div class="stat-label">Drafts</div></div>
            </div>

            <section class="section-card">
                <div class="filters-row">
                    <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="examSearchInput" class="search-input" placeholder="Search by title, subject, level..."></div>
                    <select id="subjectFilter" class="form-select"><option value="">All Subjects</option><?php foreach($subjects as $s): ?><option value="<?= strtolower(e($s['name'])) ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
                    <select id="statusFilter" class="form-select"><option value="">All Status</option><option value="published">Published</option><option value="draft">Draft</option><option value="archived">Archived</option></select>
                    <select id="levelFilter" class="form-select"><option value="">All Levels</option><option value="1">Level 1</option><option value="2">Level 2</option><option value="3">Level 3</option><option value="4">Level 4</option></select>
                </div>

                <?php if(!empty($exams)): ?>
                <div class="exam-list" id="examList">
                    <?php foreach($exams as $exam): $searchBlob = strtolower($exam['title'].' '.($exam['subject_name']??'').' level '.$exam['level'].' seq '.$exam['attempt_sequence']); ?>
                    <div class="exam-item exam-search-item" data-search="<?= e($searchBlob) ?>" data-subject="<?= strtolower(e($exam['subject_name']??'')) ?>" data-status="<?= e($exam['status']) ?>" data-level="<?= $exam['level'] ?>" data-id="<?= $exam['id'] ?>">
                        <div class="exam-left">
                            <div class="exam-icon">📜</div>
                            <div>
                                <div class="exam-title"><?= e($exam['title']) ?></div>
                                <div class="exam-meta">
                                    <?= e($exam['certification_type'] === 'subject' ? 'Subject' : 'General') ?> |
                                    <?php if ($exam['subject_id']): ?><?= e($exam['subject_name']) ?> |<?php endif; ?>
                                    Level <?= $exam['level'] ?> | Attempt <?= $exam['attempt_sequence'] ?><br>
                                    <?php if ($exam['start_date']): ?>Window: <?= e(date('M d, Y', strtotime($exam['start_date']))) ?> – <?= e(date('M d, Y', strtotime($exam['end_date']))) ?><br><?php endif; ?>
                                    Questions: <?= $exam['total_questions'] ?> | Attempts: <?= $exam['total_attempts'] ?> |
                                    Max Attempts: <?= $exam['max_attempts_per_level'] ?> | Cooldown: <?= $exam['retry_delay_hours'] ?>h
                                </div>
                            </div>
                        </div>
                        <div class="exam-actions">
                            <span class="badge <?= $exam['status']==='published'?'success':($exam['status']==='draft'?'warn':'neutral') ?>"><?= ucfirst($exam['status']) ?></span>
                            <button class="btn secondary edit-exam-btn" data-id="<?= $exam['id'] ?>">✏️ Edit</button>
                            <a href="admin_certification_questions.php?exam_id=<?= $exam['id'] ?>" class="btn warn">❓ Questions</a>
                            <button class="btn secondary toggle-status-btn" data-id="<?= $exam['id'] ?>"><?= $exam['status']==='published'?'Unpublish':'Publish' ?></button>
                            <?php if($exam['total_attempts']==0): ?><button class="btn danger delete-exam-btn" data-id="<?= $exam['id'] ?>">🗑️ Delete</button><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="emptySearchState" class="empty-state" style="display:none;">No matching exams found.</div>
                <?php else: ?>
                <div class="empty-state">No certification exams yet. Click "Create Certification Exam" to add one.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<!-- Modal -->
<div class="modal-overlay" id="examModal">
    <div class="modal">
        <div class="modal-header"><h2 id="modalTitle">Create Certification Exam</h2><button class="modal-close" id="closeModalBtn">&times;</button></div>
        <div class="modal-body">
            <form id="examForm">
                <input type="hidden" name="action" value="save_exam">
                <input type="hidden" name="exam_id" id="exam_id" value="0">
                <div class="form-row"><label>Title *</label><input type="text" name="title" id="title" class="form-input" required></div>
                <div class="form-row"><label>Slug</label><input type="text" name="slug" id="slug" class="form-input" placeholder="auto-generated"></div>
                <div class="form-row"><label>Description</label><textarea name="description" id="description" class="form-textarea"></textarea></div>
                <div class="inline-row">
                    <div class="form-row">
                        <label>Type</label>
                        <select name="certification_type" id="certification_type" class="form-select">
                            <option value="subject">Subject</option>
                            <option value="general">General (All Subjects)</option>
                        </select>
                    </div>
                    <div class="form-row" id="subjectGroup">
                        <label>Subject</label>
                        <select name="subject_id" id="subject_id" class="form-select">
                            <option value="">Select Subject</option>
                            <?php foreach($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="inline-row">
                    <div class="form-row"><label>Level (1-4) *</label><input type="number" name="level" id="level" class="form-input" min="1" max="4" value="1" required></div>
                    <div class="form-row"><label>Attempt Sequence *</label><input type="number" name="attempt_sequence" id="attempt_sequence" class="form-input" min="1" value="1" required></div>
                </div>
                <div class="inline-row">
                    <div class="form-row"><label>Time Limit (mins)</label><input type="number" name="time_limit_minutes" id="time_limit_minutes" class="form-input" value="30"></div>
                    <div class="form-row"><label>Passing Score (%)</label><input type="number" name="passing_score" id="passing_score" class="form-input" value="70" min="0" max="100"></div>
                </div>
                <div class="inline-row">
                    <div class="form-row"><label>Month (YYYY-MM) *</label><input type="month" name="month_year" id="month_year" class="form-input" required></div>
                </div>
                <div class="inline-row">
                    <div class="form-row"><label>Start Date *</label><input type="datetime-local" name="start_date" id="start_date" class="form-input" required></div>
                    <div class="form-row"><label>End Date *</label><input type="datetime-local" name="end_date" id="end_date" class="form-input" required></div>
                </div>
                <div class="inline-row">
                    <div class="form-row">
                        <label>Max Attempts per Level</label>
                        <input type="number" name="max_attempts_per_level" id="max_attempts_per_level" class="form-input" min="1" value="3" required>
                    </div>
                    <div class="form-row">
                        <label>Retry Delay (hours)</label>
                        <input type="number" name="retry_delay_hours" id="retry_delay_hours" class="form-input" min="0" value="24" required>
                    </div>
                </div>
                <div class="form-row"><label>Status</label><select name="status" id="status" class="form-select"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></div>
                <div class="action-row"><button type="submit" class="btn primary">Save Exam</button><button type="button" class="btn secondary" id="cancelModalBtn">Cancel</button></div>
            </form>
        </div>
    </div>
</div>

<script>
function showToast(m, t='success'){
    const c=document.getElementById('toastContainer');
    const d=document.createElement('div');
    d.className=`toast ${t}`;
    d.innerHTML=`<span>${t==='success'?'✅':'❌'}</span><span>${m}</span><span class="close-toast" onclick="this.parentElement.remove()">&times;</span>`;
    c.appendChild(d);
    setTimeout(()=>d.remove(),5000);
}

const modal=document.getElementById('examModal'),
      createBtn=document.getElementById('createExamBtn'),
      closeBtn=document.getElementById('closeModalBtn'),
      cancelBtn=document.getElementById('cancelModalBtn'),
      form=document.getElementById('examForm'),
      modalTitle=document.getElementById('modalTitle');

document.getElementById('certification_type').addEventListener('change', function(){
    document.getElementById('subjectGroup').style.display = this.value === 'subject' ? 'block' : 'none';
});

function openModal(data=null){
    if(data){
        modalTitle.textContent='Edit Certification Exam';
        document.getElementById('exam_id').value = data.id || 0;
        document.getElementById('title').value = data.title || '';
        document.getElementById('slug').value = data.slug || '';
        document.getElementById('description').value = data.description || '';
        document.getElementById('certification_type').value = data.certification_type || 'subject';
        document.getElementById('subject_id').value = data.subject_id || '';
        document.getElementById('level').value = data.level || 1;
        document.getElementById('attempt_sequence').value = data.attempt_sequence || 1;
        document.getElementById('month_year').value = data.month_year || '';
        document.getElementById('time_limit_minutes').value = data.time_limit_minutes || '';
        document.getElementById('start_date').value = data.start_date ? data.start_date.replace(' ', 'T').slice(0,16) : '';
        document.getElementById('end_date').value = data.end_date ? data.end_date.replace(' ', 'T').slice(0,16) : '';
        document.getElementById('passing_score').value = data.passing_score || 70;
        document.getElementById('status').value = data.status || 'draft';
        document.getElementById('max_attempts_per_level').value = data.max_attempts_per_level || 3;
        document.getElementById('retry_delay_hours').value = data.retry_delay_hours || 24;
        document.getElementById('subjectGroup').style.display = (data.certification_type || 'subject') === 'subject' ? 'block' : 'none';
    } else {
        modalTitle.textContent='Create Certification Exam';
        form.reset();
        document.getElementById('exam_id').value = '0';
        document.getElementById('attempt_sequence').value = 1;
        document.getElementById('max_attempts_per_level').value = 3;
        document.getElementById('retry_delay_hours').value = 24;
        document.getElementById('subjectGroup').style.display = 'block';
    }
    modal.style.display='flex';
    document.body.style.overflow='hidden';
}

function closeModal(){
    modal.style.display='none';
    document.body.style.overflow='';
}

createBtn.addEventListener('click', ()=>openModal());
closeBtn.addEventListener('click', closeModal);
cancelBtn.addEventListener('click', closeModal);
modal.addEventListener('click', e=>{ if(e.target===modal) closeModal(); });

document.getElementById('title').addEventListener('input', function(){
    const s=document.getElementById('slug');
    if(!s.value || s.value === s.dataset.old){
        s.value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
        s.dataset.old = this.value;
    }
});

form.addEventListener('submit', async e=>{
    e.preventDefault();
    const d=new FormData(form);
    try{
        const r = await fetch('<?= $selfScript ?>', {
            method:'POST',
            body:d,
            headers:{'X-Requested-With':'XMLHttpRequest'}
        });
        const j = await r.json();
        showToast(j.message, j.success?'success':'danger');
        if(j.success){
            closeModal();
            if(j.redirect) window.location.href = j.redirect;
            else setTimeout(()=>location.reload(), 1000);
        }
    }catch(err){
        showToast('Network error: '+err.message, 'danger');
    }
});

document.querySelectorAll('.edit-exam-btn').forEach(b=>b.addEventListener('click', async ()=>{
    const id = b.dataset.id;
    try{
        const r = await fetch(`<?= $selfScript ?>?edit=${id}&ajax=1`);
        if(!r.ok) throw new Error(`Server returned ${r.status}`);
        const data = await r.json();
        if(data.error) throw new Error(data.error);
        openModal(data);
    }catch(err){
        console.error(err);
        showToast('Failed to load exam data: '+err.message, 'danger');
    }
}));

document.querySelectorAll('.toggle-status-btn').forEach(b=>b.addEventListener('click', async ()=>{
    const id = b.dataset.id;
    const f = new FormData();
    f.append('action','toggle_status');
    f.append('exam_id', id);
    try{
        const r = await fetch('<?= $selfScript ?>', { method:'POST', body:f, headers:{'X-Requested-With':'XMLHttpRequest'} });
        const j = await r.json();
        showToast(j.message, j.success?'success':'danger');
        if(j.success) setTimeout(()=>location.reload(), 1000);
    }catch(err){ showToast('Error: '+err.message, 'danger'); }
}));

document.querySelectorAll('.delete-exam-btn').forEach(b=>b.addEventListener('click', async ()=>{
    if(!confirm('Delete this exam? All questions will be lost!')) return;
    const id = b.dataset.id;
    const f = new FormData();
    f.append('action','delete_exam');
    f.append('exam_id', id);
    try{
        const r = await fetch('<?= $selfScript ?>', { method:'POST', body:f, headers:{'X-Requested-With':'XMLHttpRequest'} });
        const j = await r.json();
        showToast(j.message, j.success?'success':'danger');
        if(j.success) setTimeout(()=>location.reload(), 1000);
    }catch(err){ showToast('Error: '+err.message, 'danger'); }
}));

const search = document.getElementById('examSearchInput'),
      subjFilter = document.getElementById('subjectFilter'),
      statFilter = document.getElementById('statusFilter'),
      levelFilter = document.getElementById('levelFilter'),
      items = document.querySelectorAll('.exam-search-item'),
      empty = document.getElementById('emptySearchState');

function applyFilters(){
    const q = (search?.value || '').trim().toLowerCase(),
          s = (subjFilter?.value || '').trim().toLowerCase(),
          st = (statFilter?.value || '').trim().toLowerCase(),
          lv = (levelFilter?.value || '').trim();
    let visible = 0;
    items.forEach(i => {
        const hay = i.dataset.search || '',
              isub = i.dataset.subject || '',
              ist = i.dataset.status || '',
              ilvl = i.dataset.level || '';
        const show = (!q || hay.includes(q)) && (!s || isub === s) && (!st || ist === st) && (!lv || ilvl === lv);
        i.style.display = show ? '' : 'none';
        if(show) visible++;
    });
    empty.style.display = visible === 0 ? 'block' : 'none';
}
search?.addEventListener('input', applyFilters);
subjFilter?.addEventListener('change', applyFilters);
statFilter?.addEventListener('change', applyFilters);
levelFilter?.addEventListener('change', applyFilters);

(function(){
    const b=document.body, mt=document.getElementById('menuToggle'), sb=document.getElementById('sidebar'),
          so=document.getElementById('sidebarOverlay'), tt=document.getElementById('themeToggle'),
          lt=document.getElementById('langToggle'), pt=document.getElementById('profileToggle'),
          pm=document.getElementById('profileMenu'), pa=document.getElementById('profileArea');
    function open(){sb?.classList.add('open');so?.classList.add('show');b.style.overflow='hidden';}
    function close(){sb?.classList.remove('open');so?.classList.remove('show');b.style.overflow='';}
    mt?.addEventListener('click', ()=>sb.classList.contains('open')?close():open());
    so?.addEventListener('click', close);
    const savedTheme = localStorage.getItem('theme');
    if(savedTheme === 'dark'){ b.classList.add('dark-mode'); if(tt) tt.textContent='☀'; }
    tt?.addEventListener('click', ()=>{
        b.classList.toggle('dark-mode');
        const isDark = b.classList.contains('dark-mode');
        tt.textContent = isDark ? '☀' : '🌙';
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });
    let cl = localStorage.getItem('language') || 'EN';
    if(lt){ lt.textContent=cl; lt.addEventListener('click', ()=>{ cl=cl==='EN'?'SW':'EN'; lt.textContent=cl; localStorage.setItem('language', cl); }); }
    pt?.addEventListener('click', e=>{ e.stopPropagation(); pm?.classList.toggle('show'); });
    document.addEventListener('click', e=>{ if(!pa?.contains(e.target)) pm?.classList.remove('show'); });
})();
</script>
</body>
</html>