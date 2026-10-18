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

function make_slug(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim((string)$text, '-');
    return $text !== '' ? $text : 'monthly-exam';
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
            SELECT id, name, slug, icon, is_active
            FROM subjects
            ORDER BY name ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getSubjects: " . $e->getMessage());
        return [];
    }
}

function getMonthlyExams(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT
                me.*,
                s.name AS subject_name,
                s.icon AS subject_icon,
                u.full_name AS creator_name,
                (SELECT COUNT(*) FROM monthly_exam_questions WHERE exam_id = me.id) AS total_questions,
                (SELECT COUNT(*) FROM monthly_exam_attempts WHERE exam_id = me.id) AS total_attempts
            FROM monthly_exams me
            LEFT JOIN subjects s ON s.id = me.subject_id
            LEFT JOIN users u ON u.id = me.created_by
            ORDER BY me.created_at DESC, me.id DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getMonthlyExams: " . $e->getMessage());
        return [];
    }
}

function getMonthlyExamById(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("SELECT * FROM monthly_exams WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getMonthlyExamById: " . $e->getMessage());
        return null;
    }
}

function slugExists(PDO $conn, string $slug, ?int $excludeId = null): bool {
    try {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT id FROM monthly_exams WHERE slug = ? AND id != ? LIMIT 1");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM monthly_exams WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
        }
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        error_log("slugExists: " . $e->getMessage());
        return false;
    }
}

function uniqueSlug(PDO $conn, string $baseSlug, ?int $excludeId = null): string {
    $slug = $baseSlug !== '' ? $baseSlug : 'monthly-exam';
    $counter = 1;
    while (slugExists($conn, $slug, $excludeId)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
    return $slug;
}

// Ensure required tables exist
function ensureMonthlyExamTables(PDO $conn): void {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `monthly_exams` (
            `id` int NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `slug` varchar(255) NOT NULL,
            `description` text,
            `subject_id` int DEFAULT NULL,
            `month_year` char(7) NOT NULL,
            `start_date` datetime NOT NULL,
            `end_date` datetime NOT NULL,
            `time_limit_minutes` int DEFAULT NULL,
            `passing_score` int NOT NULL DEFAULT '70',
            `max_attempts` int NOT NULL DEFAULT '1',
            `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
            `created_by` int NOT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`),
            KEY `subject_id` (`subject_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS `monthly_exam_questions` (
            `id` int NOT NULL AUTO_INCREMENT,
            `exam_id` int NOT NULL,
            `question_text` text NOT NULL,
            `question_type` enum('multiple_choice','true_false','explanation') NOT NULL DEFAULT 'multiple_choice',
            `points` int NOT NULL DEFAULT '1',
            `explanation` text,
            `image_path` varchar(500) DEFAULT NULL,
            `sort_order` int NOT NULL DEFAULT '0',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `exam_id` (`exam_id`),
            KEY `sort_order` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS `monthly_exam_options` (
            `id` int NOT NULL AUTO_INCREMENT,
            `question_id` int NOT NULL,
            `option_text` varchar(500) NOT NULL,
            `image_path` varchar(500) DEFAULT NULL,
            `is_correct` tinyint(1) NOT NULL DEFAULT '0',
            `sort_order` int NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `question_id` (`question_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS `monthly_exam_attempts` (
            `id` int NOT NULL AUTO_INCREMENT,
            `user_id` int NOT NULL,
            `exam_id` int NOT NULL,
            `attempt_number` int NOT NULL DEFAULT '1',
            `total_questions` int NOT NULL DEFAULT '0',
            `correct_answers` int NOT NULL DEFAULT '0',
            `score` int NOT NULL DEFAULT '0',
            `total_points` int NOT NULL DEFAULT '0',
            `percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
            `passed` tinyint(1) NOT NULL DEFAULT '0',
            `status` enum('started','completed','abandoned') NOT NULL DEFAULT 'started',
            `started_at` datetime DEFAULT NULL,
            `completed_at` datetime DEFAULT NULL,
            `answers_json` longtext,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_exam_attempt_user_exam_attemptno` (`user_id`,`exam_id`,`attempt_number`),
            KEY `user_id` (`user_id`),
            KEY `exam_id` (`exam_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

$admin = getAdminUser($conn, $userId);
if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Admins only.');
}

ensureMonthlyExamTables($conn);

$message = '';
$messageType = 'success';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// AJAX endpoint for fetching exam data
if (isset($_GET['ajax']) && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $examData = getMonthlyExamById($conn, $editId);
    if ($examData) {
        header('Content-Type: application/json');
        echo json_encode($examData);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Exam not found']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_exam') {
        $examId = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $courseId    = isset($_POST['course_id'])    && $_POST['course_id']    !== '' ? (int)$_POST['course_id']    : null;
$divisionId  = isset($_POST['division_id'])  && $_POST['division_id']  !== '' ? (int)$_POST['division_id']  : null;
$weekNumber  = isset($_POST['week_number'])  && $_POST['week_number']  !== '' ? (int)$_POST['week_number']  : null;

// Validate: if course_id is set but division_id isn't, clear both
// (an exam with a course but no division is meaningless for grading)
if ($courseId !== null && $divisionId === null) {
    $courseId = null;
}
// If division is set but course isn't, clear division too
if ($divisionId !== null && $courseId === null) {
    $divisionId = null;
}
        $subjectId = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? (int)$_POST['subject_id'] : null;
        $monthYear = trim((string)($_POST['month_year'] ?? ''));
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));
        $timeLimit = isset($_POST['time_limit_minutes']) && $_POST['time_limit_minutes'] !== '' ? (int)$_POST['time_limit_minutes'] : null;
        $passingScore = isset($_POST['passing_score']) ? (int)$_POST['passing_score'] : 70;
        $maxAttempts = isset($_POST['max_attempts']) ? (int)$_POST['max_attempts'] : 1;
        $status = trim((string)($_POST['status'] ?? 'draft'));
        $allowAccess = isset($_POST['allow_access']) ? 1 : 0;

        if (!in_array($status, ['draft', 'published', 'archived'])) $status = 'draft';
        if ($passingScore < 0 || $passingScore > 100) $passingScore = 70;
        if ($maxAttempts < 1) $maxAttempts = 1;

        $startDb = $startDate ? date('Y-m-d H:i:s', strtotime($startDate)) : null;
        $endDb = $endDate ? date('Y-m-d H:i:s', strtotime($endDate)) : null;

        if ($title === '') {
            $message = 'Exam title is required.';
            $messageType = 'danger';
        } elseif ($monthYear === '' || !preg_match('/^\d{4}-\d{2}$/', $monthYear)) {
            $message = 'Valid month (YYYY-MM) is required.';
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
                    // Get previous status to determine if we should notify
                    $prevStmt = $conn->prepare("SELECT status FROM monthly_exams WHERE id = ?");
                    $prevStmt->execute([$examId]);
                    $prevStatus = $prevStmt->fetchColumn();
                    $wasPublished = ($prevStatus === 'published');

                    $stmt = $conn->prepare("
                        UPDATE monthly_exams
                        SET title = ?, slug = ?, description = ?, subject_id = ?, month_year = ?,
                            start_date = ?, end_date = ?, time_limit_minutes = ?, passing_score = ?,
                            max_attempts = ?, status = ?, allow_access = ?,
                            course_id = ?, division_id = ?, week_number = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $title, $slug, $description, $subjectId, $monthYear,
                        $startDb, $endDb, $timeLimit, $passingScore,
                        $maxAttempts, $status, $allowAccess,
                        $courseId, $divisionId, $weekNumber,
                        $examId
                    ]);
                    
                    // ========== ADDED: Notify users if exam is published (newly or updated to published) ==========
                    if ($status === 'published' && !$wasPublished) {
                        $notifyTitle = "Monthly Exam Available: " . $title;
                        $notifyMessage = "The {$monthYear} exam is now open. Complete it before " . date('M d, Y', strtotime($endDb)) . ".";
                        $notifyLink = "/exam.php?id=" . $examId; // Adjust to actual exam page URL
                        createNotificationForAll($conn, 'exam', $notifyTitle, $notifyMessage, $notifyLink, 'user');
                    }
                    // ===========================================================================
                    
                    $message = 'Monthly exam updated successfully.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO monthly_exams
                        (title, slug, description, subject_id, month_year, start_date, end_date, time_limit_minutes, passing_score, max_attempts, status, allow_access, created_by, course_id, division_id, week_number)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $title, $slug, $description, $subjectId, $monthYear,
                        $startDb, $endDb, $timeLimit, $passingScore,
                        $maxAttempts, $status, $allowAccess, $userId,
                        $courseId, $divisionId, $weekNumber
                    ]);
                    $newExamId = (int)$conn->lastInsertId();
                    
                    // ========== ADDED: Notify users if new exam is published ==========
                    if ($status === 'published') {
                        $notifyTitle = "New Monthly Exam: " . $title;
                        $notifyMessage = "The {$monthYear} exam is now open. Complete it before " . date('M d, Y', strtotime($endDb)) . ".";
                        $notifyLink = "/exam.php?id=" . $newExamId;
                        createNotificationForAll($conn, 'exam', $notifyTitle, $notifyMessage, $notifyLink, 'user');
                    }
                    // =====================================================================
                    
                    $message = 'Monthly exam created successfully.';
                    $redirectUrl = 'admin_monthly_exam_questions.php?exam_id=' . $newExamId;
                }
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("save_exam: " . $e->getMessage());
                $message = 'Unable to save exam: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $messageType === 'success',
                'message' => $message,
                'redirect' => $redirectUrl ?? null
            ]);
            exit;
        }
    }

    if ($action === 'toggle_status') {
        $examId = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        if ($examId > 0) {
            try {
                // Get current status and title
                $stmt = $conn->prepare("SELECT status, title, month_year, end_date FROM monthly_exams WHERE id = ?");
                $stmt->execute([$examId]);
                $current = $stmt->fetch();
                $wasPublished = ($current['status'] === 'published');
                $title = $current['title'] ?? '';
                $monthYear = $current['month_year'] ?? '';
                $endDate = $current['end_date'] ?? '';

                $stmt = $conn->prepare("UPDATE monthly_exams SET status = IF(status='published','draft','published') WHERE id = ?");
                $stmt->execute([$examId]);
                
                // ========== ADDED: Notify users if toggled to published ==========
                if (!$wasPublished) { // Now published
                    $notifyTitle = "Monthly Exam Now Available: " . $title;
                    $notifyMessage = "The {$monthYear} exam is now open. Complete it before " . date('M d, Y', strtotime($endDate)) . ".";
                    $notifyLink = "/exam.php?id=" . $examId;
                    createNotificationForAll($conn, 'exam', $notifyTitle, $notifyMessage, $notifyLink, 'user');
                }
                // =====================================================================
                
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

    if ($action === 'toggle_access') {
        $examId = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        if ($examId > 0) {
            try {
                $stmt = $conn->prepare("UPDATE monthly_exams SET allow_access = IF(allow_access = 1, 0, 1) WHERE id = ?");
                $stmt->execute([$examId]);

                $stmt = $conn->prepare("SELECT allow_access, title FROM monthly_exams WHERE id = ?");
                $stmt->execute([$examId]);
                $row = $stmt->fetch();
                $newValue = (int)($row['allow_access'] ?? 0);

                $message = 'Access for "' . ($row['title'] ?? 'this exam') . '" is now ' . ($newValue === 1 ? 'enabled - users can access it' : 'locked - users cannot access it') . '.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("toggle_access: " . $e->getMessage());
                $message = 'Unable to update access setting.';
                $messageType = 'danger';
                $newValue = null;
            }
        } else {
            $message = 'Invalid exam.';
            $messageType = 'danger';
            $newValue = null;
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $messageType === 'success',
                'message' => $message,
                'new_value' => $newValue
            ]);
            exit;
        }
    }

    if ($action === 'delete_exam') {
        $examId = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        if ($examId > 0) {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM monthly_exam_attempts WHERE exam_id = ?");
                $stmt->execute([$examId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $message = 'Cannot delete exam with attempts. Archive it instead.';
                    $messageType = 'danger';
                } else {
                    $stmt = $conn->prepare("DELETE FROM monthly_exams WHERE id = ?");
                    $stmt->execute([$examId]);
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

$subjects = getSubjects($conn);
$exams = getMonthlyExams($conn);
$totalExams = count($exams);
$publishedExams = count(array_filter($exams, fn($e) => $e['status'] === 'published'));
$activeExams = count(array_filter($exams, fn($e) => strtotime($e['end_date']) >= time() && strtotime($e['start_date']) <= time()));
$draftExams = count(array_filter($exams, fn($e) => $e['status'] === 'draft'));
// ========== NEW: Load courses + their divisions for the academy link ==========
$allCoursesList = [];
try {
    $stmt = $conn->query("
        SELECT c.id, c.title
        FROM courses c
        WHERE c.status IN ('published','draft')
        ORDER BY c.created_at DESC, c.id DESC
    ");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($courses) {
        $divStmt = $conn->prepare("
            SELECT id, number, name
            FROM course_divisions
            WHERE course_id = ?
            ORDER BY number ASC, id ASC
        ");
        foreach ($courses as &$cRow) {
            $divStmt->execute([(int)$cRow['id']]);
            $cRow['divisions'] = $divStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        unset($cRow);
    }
    $allCoursesList = $courses;
} catch (PDOException $e) {
    error_log('load courses for monthly exam form: ' . $e->getMessage());
}
// ========== /NEW ==========

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Monthly Exams', 'link' => 'admin_monthly_exams.php', 'active' => true],
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
    ['name' => 'Monthly Exams', 'link' => 'admin_monthly_exams.php', 'active' => true, 'icon' => '📅'],
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
<title>Admin Monthly Exams - MyIslam</title>
<style>
/* ... (CSS unchanged) ... */
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
.filters-row{display:grid;grid-template-columns:1fr 200px 150px;gap:12px;margin-bottom:16px}
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
.form-fields{display:grid;gap:14px}
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
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
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
                <?php if (!empty($admin['profile_image'])): ?><img src="<?php echo e($admin['profile_image']); ?>" alt="Profile">
                <?php else: ?><?php echo e(initials((string)$admin['full_name'])); ?><?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($admin['profile_image'])): ?><img src="<?php echo e($admin['profile_image']); ?>" alt="Profile">
                    <?php else: ?><div class="profile-avatar-fallback"><?php echo e(initials((string)$admin['full_name'])); ?></div><?php endif; ?>
                    <div><h4><?php echo e($admin['full_name']); ?></h4><p><?php echo e($admin['email']); ?></p></div>
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
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>"><span><?php echo e($item['icon']); ?></span><span><?php echo e($item['name']); ?></span></a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-stack">
            <section class="hero-card section-card">
                <div><h1>📅 Monthly Examinations</h1><p>Schedule and manage end-of-month exams for users.</p></div>
                <button class="btn primary" id="createExamBtn">➕ Create Monthly Exam</button>
            </section>

            <div class="stats-grid">
                <div class="section-card stat-card"><div class="stat-value"><?php echo $totalExams; ?></div><div class="stat-label">Total Exams</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $publishedExams; ?></div><div class="stat-label">Published</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $activeExams; ?></div><div class="stat-label">Currently Active</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $draftExams; ?></div><div class="stat-label">Drafts</div></div>
            </div>

            <section class="section-card">
                <div class="filters-row">
                    <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="examSearchInput" class="search-input" placeholder="Search by title, subject, month..."></div>
                    <select id="subjectFilter" class="form-select"><option value="">All Subjects</option><?php foreach($subjects as $s): ?><option value="<?php echo strtolower(e($s['name'])); ?>"><?php echo e($s['name']); ?></option><?php endforeach; ?></select>
                    <select id="statusFilter" class="form-select"><option value="">All Status</option><option value="published">Published</option><option value="draft">Draft</option><option value="archived">Archived</option></select>
                </div>

                <?php if(!empty($exams)): ?>
                <div class="exam-list" id="examList">
                    <?php foreach($exams as $exam): $searchBlob = strtolower($exam['title'].' '.($exam['subject_name']??'').' '.$exam['month_year']); ?>
                    <div class="exam-item exam-search-item" data-search="<?php echo e($searchBlob); ?>" data-subject="<?php echo strtolower(e($exam['subject_name']??'')); ?>" data-status="<?php echo e($exam['status']); ?>" data-id="<?php echo $exam['id']; ?>">
                        <div class="exam-left">
                            <div class="exam-icon">📅</div>
                            <div>
                                <div class="exam-title"><?php echo e($exam['title']); ?></div>
                                <div class="exam-meta">
                                    Subject: <?php echo e($exam['subject_name']??'Any'); ?> | Month: <?php echo e($exam['month_year']); ?><br>
                                    <?php echo e(date('M d, Y', strtotime($exam['start_date']))); ?> – <?php echo e(date('M d, Y', strtotime($exam['end_date']))); ?><br>
                                    Questions: <?php echo $exam['total_questions']; ?> | Attempts: <?php echo $exam['total_attempts']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="exam-actions">
                            <span class="badge <?php echo $exam['status']==='published'?'success':($exam['status']==='draft'?'warn':'neutral'); ?>"><?php echo ucfirst($exam['status']); ?></span>
                            <?php $examAccessLocked = isset($exam['allow_access']) && (int)$exam['allow_access'] === 0; ?>
                            <?php if ($examAccessLocked): ?>
                                <span class="badge danger" title="Users cannot access this exam">🔒 Access Locked</span>
                            <?php endif; ?>
                            <button class="btn secondary edit-exam-btn" data-id="<?php echo $exam['id']; ?>">✏️ Edit</button>
                            <a href="admin_monthly_exam_questions.php?exam_id=<?php echo $exam['id']; ?>" class="btn warn">❓ Questions</a>
                            <button class="btn secondary toggle-status-btn" data-id="<?php echo $exam['id']; ?>"><?php echo $exam['status']==='published'?'Unpublish':'Publish'; ?></button>
                            <button class="btn secondary toggle-access-btn" data-id="<?php echo $exam['id']; ?>"><?php echo $examAccessLocked?'🔓 Unlock Access':'🔒 Lock Access'; ?></button>
                            <?php if($exam['total_attempts']==0): ?><button class="btn danger delete-exam-btn" data-id="<?php echo $exam['id']; ?>">🗑️ Delete</button><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="emptySearchState" class="empty-state" style="display:none;">No matching exams found.</div>
                <?php else: ?>
                <div class="empty-state">No monthly exams yet. Click "Create Monthly Exam" to add one.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    
</div>

<!-- Modal -->
<div class="modal-overlay" id="examModal">
    <div class="modal">
        <div class="modal-header"><h2 id="modalTitle">Create Monthly Exam</h2><button class="modal-close" id="closeModalBtn">&times;</button></div>
        <div class="modal-body">
            <form id="examForm">
                <input type="hidden" name="action" value="save_exam"><input type="hidden" name="exam_id" id="exam_id" value="0">
                <div class="form-row"><label>Title *</label><input type="text" name="title" id="title" class="form-input" required></div>
                <div class="form-row"><label>Slug</label><input type="text" name="slug" id="slug" class="form-input" placeholder="auto-generated"></div>
                <div class="form-row"><label>Description</label><textarea name="description" id="description" class="form-textarea"></textarea></div>
                <div class="form-row"><label>Subject</label><select name="subject_id" id="subject_id" class="form-select"><option value="">All Subjects (Optional)</option><?php foreach($subjects as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo e($s['name']); ?></option><?php endforeach; ?></select></div>
                    <!-- ========== NEW: Academy link (optional) ========== -->
<div class="form-row"><label>Course <small style="font-weight:400;color:var(--muted);">(optional — set to make it an academy monthly exam)</small></label>
    <select name="course_id" id="exam_course_id" class="form-select">
        <option value="">— None (non-academy) —</option>
        <?php foreach ($allCoursesList as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" data-divisions='<?php echo e(json_encode($c['divisions'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)); ?>'>
                <?php echo e($c['title']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-row"><label>Division</label>
    <select name="division_id" id="exam_division_id" class="form-select">
        <option value="">— Select course first —</option>
    </select>
</div>

<div class="form-row"><label>Week #</label>
    <input type="number" name="week_number" id="exam_week_number" class="form-input" min="1" placeholder="e.g. 3">
</div>
<!-- ========== /NEW ========== -->
                <div class="inline-row">
                    <div class="form-row"><label>Month (YYYY-MM) *</label><input type="month" name="month_year" id="month_year" class="form-input" required></div>
                    <div class="form-row"><label>Time Limit (mins)</label><input type="number" name="time_limit_minutes" id="time_limit_minutes" class="form-input"></div>
                </div>
                <div class="inline-row">
                    <div class="form-row"><label>Start Date *</label><input type="datetime-local" name="start_date" id="start_date" class="form-input" required></div>
                    <div class="form-row"><label>End Date *</label><input type="datetime-local" name="end_date" id="end_date" class="form-input" required></div>
                </div>
                <div class="inline-row">
                    <div class="form-row"><label>Passing Score (%)</label><input type="number" name="passing_score" id="passing_score" class="form-input" value="70" min="0" max="100"></div>
                    <div class="form-row"><label>Max Attempts</label><input type="number" name="max_attempts" id="max_attempts" class="form-input" value="1" min="1"></div>
                </div>
                <div class="form-row"><label>Status</label><select name="status" id="status" class="form-select"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></div>
                <div class="form-row"><label style="display:flex;align-items:center;gap:10px;cursor:pointer;"><input type="checkbox" name="allow_access" id="allow_access" value="1" checked> 🔓 Allow Access (uncheck to lock this exam from all users)</label></div>
                <div class="action-row"><button type="submit" class="btn primary">Save Exam</button><button type="button" class="btn secondary" id="cancelModalBtn">Cancel</button></div>
            </form>
        </div>
    </div>
</div>

<script>
function showToast(m,t='success'){const c=document.getElementById('toastContainer');const d=document.createElement('div');d.className=`toast ${t}`;d.innerHTML=`<span>${t==='success'?'✅':'❌'}</span><span>${m}</span><span class="close-toast" onclick="this.parentElement.remove()">&times;</span>`;c.appendChild(d);setTimeout(()=>d.remove(),5000);}
const modal=document.getElementById('examModal'),createBtn=document.getElementById('createExamBtn'),closeBtn=document.getElementById('closeModalBtn'),cancelBtn=document.getElementById('cancelModalBtn'),form=document.getElementById('examForm'),modalTitle=document.getElementById('modalTitle');
function openModal(data=null){
    if(data){modalTitle.textContent='Edit Monthly Exam';document.getElementById('exam_id').value=data.id||0;document.getElementById('title').value=data.title||'';document.getElementById('slug').value=data.slug||'';document.getElementById('description').value=data.description||'';document.getElementById('subject_id').value=data.subject_id||'';document.getElementById('month_year').value=data.month_year||'';document.getElementById('time_limit_minutes').value=data.time_limit_minutes||'';document.getElementById('start_date').value=data.start_date?data.start_date.replace(' ','T').slice(0,16):'';document.getElementById('end_date').value=data.end_date?data.end_date.replace(' ','T').slice(0,16):'';document.getElementById('passing_score').value=data.passing_score||70;document.getElementById('max_attempts').value=data.max_attempts||1;document.getElementById('status').value=data.status||'draft';document.getElementById('allow_access').checked=(data.allow_access==null||data.allow_access==1);
        // NEW: pre-fill academy fields
        const cs=document.getElementById('exam_course_id');
        if(cs){cs.value=data.course_id||'';cs.dispatchEvent(new Event('change'));}
        setTimeout(()=>{const ds=document.getElementById('exam_division_id');if(ds)ds.value=data.division_id||'';const wk=document.getElementById('exam_week_number');if(wk)wk.value=data.week_number||'';},0);
    }
    else{modalTitle.textContent='Create Monthly Exam';form.reset();document.getElementById('exam_id').value='0';
        // NEW: reset academy fields
        const cs=document.getElementById('exam_course_id');if(cs){cs.value='';cs.dispatchEvent(new Event('change'));}
        setTimeout(()=>{const ds=document.getElementById('exam_division_id');if(ds)ds.value='';const wk=document.getElementById('exam_week_number');if(wk)wk.value='';},0);
    }
    modal.style.display='flex';document.body.style.overflow='hidden';
}
function closeModal(){modal.style.display='none';document.body.style.overflow='';}
createBtn.addEventListener('click',()=>openModal());closeBtn.addEventListener('click',closeModal);cancelBtn.addEventListener('click',closeModal);modal.addEventListener('click',e=>{if(e.target===modal)closeModal();});
document.getElementById('title').addEventListener('input',function(){const s=document.getElementById('slug');if(!s.value||s.value===s.dataset.old){s.value=this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');s.dataset.old=this.value;}});
form.addEventListener('submit',async e=>{e.preventDefault();const d=new FormData(form);try{const r=await fetch('admin_monthly_exams.php',{method:'POST',body:d,headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json();showToast(j.message,j.success?'success':'danger');if(j.success){closeModal();if(j.redirect)window.location.href=j.redirect;else setTimeout(()=>location.reload(),1000);}}catch{showToast('Error','danger');}});
document.querySelectorAll('.edit-exam-btn').forEach(b=>b.addEventListener('click',async()=>{const id=b.dataset.id;try{const r=await fetch(`admin_monthly_exams.php?edit=${id}&ajax=1`);const d=await r.json();openModal(d);}catch{showToast('Failed to load','danger');}}));
document.querySelectorAll('.toggle-status-btn').forEach(b=>b.addEventListener('click',async()=>{const id=b.dataset.id,f=new FormData();f.append('action','toggle_status');f.append('exam_id',id);try{const r=await fetch('admin_monthly_exams.php',{method:'POST',body:f,headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json();showToast(j.message,j.success?'success':'danger');if(j.success)setTimeout(()=>location.reload(),1000);}catch{showToast('Error','danger');}}));
document.querySelectorAll('.toggle-access-btn').forEach(b=>b.addEventListener('click',async()=>{const id=b.dataset.id,f=new FormData();f.append('action','toggle_access');f.append('exam_id',id);try{const r=await fetch('admin_monthly_exams.php',{method:'POST',body:f,headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json();showToast(j.message,j.success?'success':'danger');if(j.success)setTimeout(()=>location.reload(),1000);}catch{showToast('Error','danger');}}));
document.querySelectorAll('.delete-exam-btn').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Delete?'))return;const id=b.dataset.id,f=new FormData();f.append('action','delete_exam');f.append('exam_id',id);try{const r=await fetch('admin_monthly_exams.php',{method:'POST',body:f,headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json();showToast(j.message,j.success?'success':'danger');if(j.success)setTimeout(()=>location.reload(),1000);}catch{showToast('Error','danger');}}));
// Filters
const search=document.getElementById('examSearchInput'),subjFilter=document.getElementById('subjectFilter'),statFilter=document.getElementById('statusFilter'),items=document.querySelectorAll('.exam-search-item'),empty=document.getElementById('emptySearchState');
function applyFilters(){const q=(search?.value||'').trim().toLowerCase(),s=(subjFilter?.value||'').trim().toLowerCase(),st=(statFilter?.value||'').trim().toLowerCase();let v=0;items.forEach(i=>{const hay=i.dataset.search||'',isub=i.dataset.subject||'',ist=i.dataset.status||'';const show=(!q||hay.includes(q))&&(!s||isub===s)&&(!st||ist===st);i.style.display=show?'':'none';if(show)v++;});if(empty)empty.style.display=v===0?'block':'none';}
search?.addEventListener('input',applyFilters);subjFilter?.addEventListener('change',applyFilters);statFilter?.addEventListener('change',applyFilters);
// Sidebar & theme
(function(){const b=document.body,mt=document.getElementById('menuToggle'),sb=document.getElementById('sidebar'),so=document.getElementById('sidebarOverlay'),tt=document.getElementById('themeToggle'),lt=document.getElementById('langToggle'),pt=document.getElementById('profileToggle'),pm=document.getElementById('profileMenu'),pa=document.getElementById('profileArea');
function open(){sb?.classList.add('open');so?.classList.add('show');b.style.overflow='hidden';}function close(){sb?.classList.remove('open');so?.classList.remove('show');b.style.overflow='';}
mt?.addEventListener('click',()=>sb.classList.contains('open')?close():open());so?.addEventListener('click',close);
const st=localStorage.getItem('theme');if(st==='dark'){b.classList.add('dark-mode');if(tt)tt.textContent='☀';}
tt?.addEventListener('click',()=>{b.classList.toggle('dark-mode');const d=b.classList.contains('dark-mode');tt.textContent=d?'☀':'🌙';localStorage.setItem('theme',d?'dark':'light');});
let cl=localStorage.getItem('language')||'EN';if(lt){lt.textContent=cl;lt.addEventListener('click',()=>{cl=cl==='EN'?'SW':'EN';lt.textContent=cl;localStorage.setItem('language',cl);});}
pt?.addEventListener('click',e=>{e.stopPropagation();pm?.classList.toggle('show');});document.addEventListener('click',e=>{if(!pa?.contains(e.target))pm?.classList.remove('show');});})();

</script>
<script>
(function () {
    const courseSel   = document.getElementById('exam_course_id');
    const divisionSel = document.getElementById('exam_division_id');
    const weekInput   = document.getElementById('exam_week_number');

    if (!courseSel || !divisionSel) return;

    function refreshDivisions() {
        const opt = courseSel.options[courseSel.selectedIndex];
        const divisionsJson = opt?.dataset?.divisions || '[]';
        let divisions = [];
        try { divisions = JSON.parse(divisionsJson); } catch (e) {}

        divisionSel.innerHTML = '';
        if (!divisions.length) {
            divisionSel.innerHTML = '<option value="">— Select course first —</option>';
            return;
        }
        divisionSel.innerHTML = '<option value="">— Choose division —</option>';
        divisions.forEach(d => {
            const o = document.createElement('option');
            o.value = d.id;
            o.textContent = '#' + d.number + ' — ' + d.name;
            divisionSel.appendChild(o);
        });
    }

    courseSel.addEventListener('change', refreshDivisions);
    refreshDivisions(); // initial
})();
</script>
</body>
</html>