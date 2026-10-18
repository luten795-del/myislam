<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------
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
    return $text !== '' ? $text : 'semester-exam';
}

// ---------------------------------------------------------------------------
// AUTH
// ---------------------------------------------------------------------------
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$conn = db();
if (!$conn instanceof PDO) {
    die('Unable to connect to database.');
}

// Load admin user
$stmt = $conn->prepare("
    SELECT id, full_name, email, profile_image, role, is_active, created_at
    FROM users WHERE id = ? AND is_active = 1 LIMIT 1
");
$stmt->execute([$userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

$allowedRoles = ['owner','admin','sheikh'];
if (!$admin || !in_array(strtolower((string)($admin['role'] ?? '')), $allowedRoles, true)) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Insufficient privileges.');
}

// ---------------------------------------------------------------------------
// LOAD FILTER TARGET: a specific course (from ?course_id=)
// ---------------------------------------------------------------------------
$courseId = (int)($_GET['course_id'] ?? $_POST['course_id'] ?? 0);

// Courses list (for the course picker when none is selected)
$allCourses = $conn->query("
    SELECT id, title, status FROM courses ORDER BY created_at DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// If a course_id is set, load it and its divisions + course_subjects
$course = null;
$divisions = [];
$courseSubjects = [];

if ($courseId > 0) {
    $stmt = $conn->prepare("SELECT * FROM courses WHERE id = ? LIMIT 1");
    $stmt->execute([$courseId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($course) {
        // Divisions in this course
        $stmt = $conn->prepare("
            SELECT id, number, name, start_date, end_date
            FROM course_divisions
            WHERE course_id = ?
            ORDER BY number ASC, id ASC
        ");
        $stmt->execute([$courseId]);
        $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Subjects attached to this course (with the course_subjects.id)
        $stmt = $conn->prepare("
            SELECT cs.id AS course_subject_id,
                   cs.subject_id,
                   s.name AS subject_name,
                   s.icon AS subject_icon
            FROM course_subjects cs
            JOIN subjects s ON s.id = cs.subject_id
            WHERE cs.course_id = ?
            ORDER BY cs.sort_order ASC, s.name ASC
        ");
        $stmt->execute([$courseId]);
        $courseSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ---------------------------------------------------------------------------
// HANDLE POST ACTIONS
// ---------------------------------------------------------------------------
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // ---- DELETE ----
    if ($action === 'delete_exam') {
        $examId = (int)($_POST['exam_id'] ?? 0);

        if ($examId > 0) {
            try {
                // Cascade delete via FK is configured on the migration, but
                // check for existing attempts first so we don't wipe history.
                $stmt = $conn->prepare("
                    SELECT COUNT(*) FROM semester_exam_attempts WHERE exam_id = ?
                ");
                $stmt->execute([$examId]);
                $attempts = (int)$stmt->fetchColumn();

                if ($attempts > 0) {
                    $message = 'Cannot delete: this exam has ' . $attempts . ' attempt(s). Archive it instead.';
                    $messageType = 'danger';
                } else {
                    $stmt = $conn->prepare("DELETE FROM semester_exams WHERE id = ?");
                    $stmt->execute([$examId]);
                    $message = 'Exam deleted.';
                    $messageType = 'success';
                }
            } catch (PDOException $ex) {
                $message = 'Delete failed: ' . $ex->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // ---- SAVE (create or update) ----
    if ($action === 'save_exam') {
        $examId           = (int)($_POST['exam_id'] ?? 0);
        $title            = trim((string)($_POST['title'] ?? ''));
        $description      = trim((string)($_POST['description'] ?? ''));
        $divisionId       = (int)($_POST['division_id'] ?? 0);
        $courseSubjectId  = (int)($_POST['course_subject_id'] ?? 0);
        $examType         = (string)($_POST['exam_type'] ?? 'final');
        $passingScore     = (int)($_POST['passing_score'] ?? 70);
        $timeLimitRaw     = trim((string)($_POST['time_limit_minutes'] ?? ''));
        $timeLimit        = $timeLimitRaw === '' ? null : (int)$timeLimitRaw;
        $maxAttempts      = (int)($_POST['max_attempts'] ?? 1);
        $allowAccess      = isset($_POST['allow_access']) ? 1 : 0;
        $startDateRaw     = trim((string)($_POST['start_date'] ?? ''));
        $endDateRaw       = trim((string)($_POST['end_date'] ?? ''));
        $status           = (string)($_POST['status'] ?? 'draft');

        // ---- VALIDATION ----
        if ($title === '') {
            $message = 'Title is required.'; $messageType = 'danger';
        } elseif ($divisionId <= 0) {
            $message = 'Division is required.'; $messageType = 'danger';
        } elseif ($courseSubjectId <= 0) {
            $message = 'Subject is required.'; $messageType = 'danger';
        } elseif (!in_array($examType, ['final','supplementary'], true)) {
            $message = 'Invalid exam type.'; $messageType = 'danger';
        } elseif (!in_array($status, ['draft','published','archived'], true)) {
            $message = 'Invalid status.'; $messageType = 'danger';
        } elseif ($passingScore < 0 || $passingScore > 100) {
            $message = 'Passing score must be 0–100.'; $messageType = 'danger';
        } elseif ($maxAttempts < 1) {
            $message = 'Max attempts must be at least 1.'; $messageType = 'danger';
        } elseif ($timeLimit !== null && $timeLimit < 1) {
            $message = 'Time limit must be positive or empty.'; $messageType = 'danger';
        } else {
            // Verify division belongs to the current course
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM course_divisions
                WHERE id = ? AND course_id = ?
            ");
            $stmt->execute([$divisionId, $courseId]);
            $okDiv = (int)$stmt->fetchColumn() > 0;

            // Verify course_subject belongs to the same course
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM course_subjects
                WHERE id = ? AND course_id = ?
            ");
            $stmt->execute([$courseSubjectId, $courseId]);
            $okSub = (int)$stmt->fetchColumn() > 0;

            if (!$okDiv || !$okSub) {
                $message = 'Division or subject does not belong to this course.';
                $messageType = 'danger';
            } else {
                $startDate = $startDateRaw === '' ? null : date('Y-m-d H:i:s', strtotime($startDateRaw));
                $endDate   = $endDateRaw   === '' ? null : date('Y-m-d H:i:s', strtotime($endDateRaw));

                if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
                    $message = 'End date must be after start date.';
                    $messageType = 'danger';
                } else {
                    try {
                        if ($examId > 0) {
                            // UPDATE
                            $stmt = $conn->prepare("
                                UPDATE semester_exams
                                SET title = ?, description = ?,
                                    division_id = ?, course_subject_id = ?,
                                    exam_type = ?,
                                    passing_score = ?,
                                    time_limit_minutes = ?,
                                    max_attempts = ?,
                                    allow_access = ?,
                                    start_date = ?, end_date = ?,
                                    status = ?,
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([
                                $title, $description ?: null,
                                $divisionId, $courseSubjectId,
                                $examType,
                                $passingScore,
                                $timeLimit,
                                $maxAttempts,
                                $allowAccess,
                                $startDate, $endDate,
                                $status,
                                $examId,
                            ]);
                            $message = 'Exam updated.';
                        } else {
                            // INSERT
                            $stmt = $conn->prepare("
                                INSERT INTO semester_exams
                                    (title, description,
                                     division_id, course_subject_id,
                                     exam_type,
                                     passing_score,
                                     time_limit_minutes,
                                     max_attempts,
                                     allow_access,
                                     start_date, end_date,
                                     status,
                                     created_by, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                            ");
                            $stmt->execute([
                                $title, $description ?: null,
                                $divisionId, $courseSubjectId,
                                $examType,
                                $passingScore,
                                $timeLimit,
                                $maxAttempts,
                                $allowAccess,
                                $startDate, $endDate,
                                $status,
                                $userId,
                            ]);
                            $examId = (int)$conn->lastInsertId();
                            $message = 'Exam created. Add questions next.';
                        }
                        $messageType = 'success';
                    } catch (PDOException $ex) {
                        $message = 'Save failed: ' . $ex->getMessage();
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// LOAD EXAMS FOR DISPLAY
// ---------------------------------------------------------------------------
$exams = [];
if ($courseId > 0 && $course) {
    $stmt = $conn->prepare("
        SELECT se.*,
               cd.name  AS division_name,
               cd.number AS division_number,
               s.name  AS subject_name,
               s.icon  AS subject_icon,
               (SELECT COUNT(*) FROM semester_exam_questions q WHERE q.exam_id = se.id) AS questions_count,
               (SELECT COUNT(*) FROM semester_exam_attempts a WHERE a.exam_id = se.id) AS attempts_count
        FROM semester_exams se
        JOIN course_divisions cd ON cd.id = se.division_id
        JOIN course_subjects cs  ON cs.id = se.course_subject_id
        JOIN subjects s           ON s.id = cs.subject_id
        WHERE cd.course_id = ?
        ORDER BY cd.number ASC, se.exam_type ASC, se.id DESC
    ");
    $stmt->execute([$courseId]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
// MAIN NAV (matches admin_quizzes.php)
// ---------------------------------------------------------------------------
$mainNavigation = [
    ['name' => 'Dashboard',     'link' => 'dashboard.php',            'active' => false],
    ['name' => 'Subjects',      'link' => 'admin_subjects.php',       'active' => false],
    ['name' => 'Categories',    'link' => 'admin_categories.php',     'active' => false],
    ['name' => 'Contents',      'link' => 'admin_contents.php',       'active' => false],
    ['name' => 'Quizzes',       'link' => 'admin_quizzes.php',        'active' => false],
    ['name' => 'Courses',       'link' => 'admin_courses.php',        'active' => true],
    ['name' => 'Users',         'link' => 'admin_users.php',          'active' => false],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php',  'active' => false],
    ['name' => 'Settings',      'link' => 'admin_settings.php',       'active' => false],
    ['name' => 'Logout',        'link' => '../logout.php',            'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard',    'link' => 'dashboard.php',            'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects',     'link' => 'admin_subjects.php',       'active' => false, 'icon' => '📚'],
    ['name' => 'Categories',   'link' => 'admin_categories.php',     'active' => false, 'icon' => '🗂️'],
    ['name' => 'Contents',     'link' => 'admin_contents.php',       'active' => false, 'icon' => '📘'],
    ['name' => 'Quizzes',      'link' => 'admin_quizzes.php',        'active' => false, 'icon' => '📝'],
    ['name' => 'Courses',      'link' => 'admin_courses.php',        'active' => true,  'icon' => '🎓'],
    ['name' => 'Users',        'link' => 'admin_users.php',          'active' => false, 'icon' => '👥'],
    ['name' => 'Announcements','link' => 'admin_announcements.php',  'active' => false, 'icon' => '📢'],
    ['name' => 'Settings',     'link' => 'admin_settings.php',       'active' => false, 'icon' => '⚙️'],
    ['name' => 'Logout',       'link' => '../logout.php',            'active' => false, 'icon' => '🚪'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Semester Exams — Admin — MyIslam</title>
<style>
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
    a{text-decoration:none;color:inherit} button,input,textarea,select{font:inherit;color:inherit}
    .app-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:var(--header-height);background:var(--header-bg);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;backdrop-filter:blur(10px);gap:18px}
    .header-left,.header-right{display:flex;align-items:center;gap:12px}
    .header-left{flex:1;min-width:0}
    .header-top-nav{display:flex;align-items:center;gap:8px;overflow-x:auto}
    .header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;white-space:nowrap;font-size:13px;font-weight:700;transition:.2s;color:var(--text-secondary)}
    .header-top-link:hover,.header-top-link.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
    .menu-toggle,.icon-btn,.lang-btn,.brand-box{height:44px;min-width:44px;border-radius:var(--radius-sm);background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;transition:.2s;cursor:pointer}
    .menu-toggle:hover,.icon-btn:hover,.lang-btn:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
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
    .section-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px}
    .hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
    .hero-card h1{font-size:32px;margin-bottom:8px;font-weight:700}
    .hero-card p{color:var(--text-secondary);font-size:16px}
    .btn{padding:10px 16px;border-radius:14px;font-weight:600;border:none;cursor:pointer;transition:.2s;font-size:13px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:inherit}
    .btn.primary{background:var(--brand);color:#fff}
    .btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
    .btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
    .btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
    .btn:hover{transform:translateY(-2px)}
    .flash{padding:14px 18px;border-radius:14px;font-weight:600;font-size:14px;border:1px solid transparent}
    .flash.success{background:rgba(34,197,94,.12);color:#15803d;border-color:#86efac}
    .flash.danger{background:rgba(239,68,68,.12);color:#b91c1c;border-color:#fca5a5}
    .exam-list{display:grid;gap:12px}
    .exam-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px;flex-wrap:wrap}
    .exam-item .left{flex:1;min-width:0}
    .exam-item .title{font-size:15px;font-weight:700;margin-bottom:4px}
    .exam-item .meta{font-size:12px;color:var(--muted);line-height:1.7}
    .exam-item .actions{display:flex;gap:8px;flex-wrap:wrap}
    .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
    .badge.final{background:rgba(59,130,246,.12);color:#1d4ed8}
    .badge.supplementary{background:rgba(245,158,11,.15);color:#92400e}
    .badge.draft{background:var(--surface-3);color:var(--muted)}
    .badge.published{background:rgba(34,197,94,.12);color:#15803d}
    .badge.archived{background:rgba(156,163,175,.15);color:#4b5563}
    .badge.locked{background:rgba(239,68,68,.12);color:#b91c1c}
    /* Modal */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px;overflow-y:auto}
    .modal-overlay.show{display:flex}
    .modal{background:var(--surface);border-radius:var(--radius-lg);max-width:680px;width:100%;max-height:92vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid var(--line)}
    .modal-header{padding:20px 24px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
    .modal-header h2{font-size:20px;font-weight:700}
    .modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted);line-height:1}
    .modal-body{padding:22px 24px}
    .form-row{display:grid;gap:6px;margin-bottom:14px}
    .form-row label{font-size:13px;font-weight:700;color:var(--text-secondary)}
    .form-row input[type=text],.form-row input[type=number],.form-row input[type=datetime-local],.form-row select,.form-row textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--surface-2);font-size:14px}
    .form-row textarea{min-height:80px;resize:vertical}
    .form-row input:focus,.form-row select:focus,.form-row textarea:focus{outline:none;border-color:var(--brand);background:var(--surface)}
    .form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .form-check{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;margin-bottom:14px}
    .form-check input[type=checkbox]{width:18px;height:18px;accent-color:var(--brand)}
    .dialog-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid var(--line)}
    .empty{text-align:center;padding:40px 20px;color:var(--muted)}
    @media (max-width:850px){
        .header-top-nav{display:none}
        .form-grid-2{grid-template-columns:1fr}
    }
    @media (max-width:640px){
        :root{--header-height:64px;--footer-height:80px}
        .main-content{padding:calc(var(--header-height) + 16px) 12px calc(var(--footer-height) + 80px)}
        .section-card{padding:16px}
        .hero-card h1{font-size:24px}
    }
</style>
</head>
<body>

<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="brand-box">🛠️ Admin</div>
        <nav class="header-top-nav" aria-label="Admin navigation">
            <?php foreach ($mainNavigation as $nav): ?>
                <a href="<?php echo e($nav['link']); ?>" class="header-top-link <?php echo $nav['active'] ? 'active' : ''; ?>">
                    <?php echo e($nav['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle" type="button">EN</button>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Profile">
                <?php if (!empty($admin['profile_image'])): ?>
                    <img src="<?php echo e($admin['profile_image']); ?>" alt="">
                <?php else: ?>
                    <?php echo e(initials((string)$admin['full_name'])); ?>
                <?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($admin['profile_image'])): ?>
                        <img src="<?php echo e($admin['profile_image']); ?>" alt="">
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
                <a href="admin_settings.php">⚙️ Settings</a>
                <a href="../logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

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
                <h1>🎓 Semester Exams</h1>
                <p>
                    <?php if ($course): ?>
                        Course: <strong><?php echo e($course['title']); ?></strong>
                    <?php else: ?>
                        Choose a course to manage its semester exams.
                    <?php endif; ?>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($course): ?>
                    <a href="admin_courses.php?course_id=<?php echo $courseId; ?>" class="btn secondary">← Back to course</a>
                    <?php if (!empty($divisions) && !empty($courseSubjects)): ?>
                        <button type="button" class="btn primary" onclick="openExamModal()">➕ New Exam</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($message !== ''): ?>
            <div class="flash <?php echo e($messageType); ?>"><?php echo e($message); ?></div>
        <?php endif; ?>

        <?php if (!$courseId || !$course): ?>

            <!-- Course picker -->
            <section class="section-card">
                <h2 style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:14px;">Select a course</h2>
                <?php if (!$allCourses): ?>
                    <div class="empty">No courses yet. <a href="admin_courses.php" style="color:var(--brand);font-weight:700;">Create one →</a></div>
                <?php else: ?>
                    <div class="exam-list">
                        <?php foreach ($allCourses as $c): ?>
                            <a class="exam-item" href="?course_id=<?php echo (int)$c['id']; ?>">
                                <div class="left">
                                    <div class="title"><?php echo e($c['title']); ?></div>
                                    <div class="meta">Status: <?php echo e($c['status']); ?></div>
                                </div>
                                <div class="actions"><span class="btn secondary">Manage →</span></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        <?php else: ?>

            <!-- Prerequisite warning if no divisions or subjects -->
            <?php if (!$divisions): ?>
                <section class="section-card">
                    <div class="empty">
                        ⚠️ This course has no divisions yet. <a href="admin_courses.php?course_id=<?php echo $courseId; ?>" style="color:var(--brand);font-weight:700;">Add a division first.</a>
                    </div>
                </section>
            <?php elseif (!$courseSubjects): ?>
                <section class="section-card">
                    <div class="empty">
                        ⚠️ This course has no subjects attached. <a href="admin_courses.php?course_id=<?php echo $courseId; ?>" style="color:var(--brand);font-weight:700;">Add subjects first.</a>
                    </div>
                </section>
            <?php else: ?>

                <!-- Exam list -->
                <section class="section-card">
                    <h2 style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:14px;">
                        Existing exams (<?php echo count($exams); ?>)
                    </h2>

                    <?php if (!$exams): ?>
                        <div class="empty">
                            No semester exams yet for this course.
                            <br><button class="btn primary" style="margin-top:12px;" onclick="openExamModal()">➕ Create the first one</button>
                        </div>
                    <?php else: ?>
                        <div class="exam-list">
                            <?php foreach ($exams as $ex):
                                $typeLabel = $ex['exam_type'] === 'supplementary' ? 'Supplementary' : 'Final';
                                $statusClass = strtolower($ex['status']);
                            ?>
                                <div class="exam-item">
                                    <div class="left">
                                        <div class="title">
                                            <?php echo e($ex['title']); ?>
                                            <span class="badge <?php echo e($ex['exam_type']); ?>"><?php echo e($typeLabel); ?></span>
                                            <span class="badge <?php echo e($statusClass); ?>"><?php echo e(ucfirst($ex['status'])); ?></span>
                                            <?php if ((int)$ex['allow_access'] === 0): ?>
                                                <span class="badge locked">🔒 Locked</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="meta">
                                            <strong><?php echo e($ex['division_name']); ?></strong>
                                            · <?php echo e(($ex['subject_icon'] ?: '📖') . ' ' . $ex['subject_name']); ?>
                                            · Pass: <?php echo (int)$ex['passing_score']; ?>%
                                            · Attempts: <?php echo (int)$ex['max_attempts']; ?>
                                            · <?php echo (int)$ex['questions_count']; ?> question<?php echo (int)$ex['questions_count'] === 1 ? '' : 's'; ?>
                                            · <?php echo (int)$ex['attempts_count']; ?> student attempt<?php echo (int)$ex['attempts_count'] === 1 ? '' : 's'; ?>
                                            <?php if (!empty($ex['start_date']) && !empty($ex['end_date'])): ?>
                                                <br>📅 <?php echo e(date('d M Y H:i', strtotime($ex['start_date']))); ?>
                                                → <?php echo e(date('d M Y H:i', strtotime($ex['end_date']))); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="actions">
                                        <a class="btn warn" href="admin_semester_exam_questions.php?exam_id=<?php echo (int)$ex['id']; ?>">
                                            ❓ Questions
                                        </a>
                                       <button type="button" class="btn secondary js-edit-exam"
        data-exam='<?php echo htmlspecialchars(json_encode([
            "id"                 => (int)$ex['id'],
            "title"              => (string)$ex['title'],
            "description"        => (string)($ex['description'] ?? ''),
            "division_id"        => (int)$ex['division_id'],
            "course_subject_id"  => (int)$ex['course_subject_id'],
            "exam_type"          => (string)$ex['exam_type'],
            "passing_score"      => (int)$ex['passing_score'],
            "time_limit_minutes" => $ex['time_limit_minutes'] !== null ? (int)$ex['time_limit_minutes'] : "",
            "max_attempts"       => (int)$ex['max_attempts'],
            "allow_access"       => (int)$ex['allow_access'],
            "start_date"         => $ex['start_date'] ? date('Y-m-d\TH:i', strtotime($ex['start_date'])) : "",
            "end_date"           => $ex['end_date'] ? date('Y-m-d\TH:i', strtotime($ex['end_date'])) : "",
            "status"             => (string)$ex['status'],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>'>
    ✏️ Edit
</button>
                                        <?php if ((int)$ex['attempts_count'] === 0): ?>
                                            <form method="post" onsubmit="return confirm('Delete this exam? This cannot be undone.');" style="display:inline">
                                                <input type="hidden" name="action" value="delete_exam">
                                                <input type="hidden" name="exam_id" value="<?php echo (int)$ex['id']; ?>">
                                                <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">
                                                <button type="submit" class="btn danger">🗑️ Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            <?php endif; ?>

        <?php endif; ?>

    </div>
</main>

<!-- EXAM MODAL -->
<div class="modal-overlay" id="examModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="examModalTitle">New Semester Exam</h2>
            <button type="button" class="modal-close" onclick="closeExamModal()">×</button>
        </div>
        <div class="modal-body">
            <form method="post" id="examForm">
                <input type="hidden" name="action" value="save_exam">
                <input type="hidden" name="exam_id" id="f_exam_id" value="0">
                <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">

                <div class="form-row">
                    <label for="f_title">Title *</label>
                    <input type="text" id="f_title" name="title" required maxlength="255">
                </div>

                <div class="form-row">
                    <label for="f_description">Description</label>
                    <textarea id="f_description" name="description" maxlength="2000"></textarea>
                </div>

                <div class="form-grid-2">
                    <div class="form-row">
                        <label for="f_division_id">Division *</label>
                        <select id="f_division_id" name="division_id" required>
                            <option value="">— Choose —</option>
                            <?php foreach ($divisions as $d): ?>
                                <option value="<?php echo (int)$d['id']; ?>">
                                    #<?php echo (int)$d['number']; ?> — <?php echo e($d['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="f_course_subject_id">Subject *</label>
                        <select id="f_course_subject_id" name="course_subject_id" required>
                            <option value="">— Choose —</option>
                            <?php foreach ($courseSubjects as $cs): ?>
                                <option value="<?php echo (int)$cs['course_subject_id']; ?>">
                                    <?php echo e(($cs['subject_icon'] ?: '📖') . ' ' . $cs['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row">
                        <label for="f_exam_type">Exam Type *</label>
                        <select id="f_exam_type" name="exam_type" required>
                            <option value="final">🎓 Final Exam</option>
                            <option value="supplementary">📚 Supplementary Exam</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="f_status">Status</label>
                        <select id="f_status" name="status">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row">
                        <label for="f_passing_score">Passing Score (%)</label>
                        <input type="number" id="f_passing_score" name="passing_score" value="70" min="0" max="100">
                    </div>

                    <div class="form-row">
                        <label for="f_max_attempts">Max Attempts</label>
                        <input type="number" id="f_max_attempts" name="max_attempts" value="1" min="1" max="10">
                    </div>
                </div>

                <div class="form-row">
                    <label for="f_time_limit_minutes">Time Limit (minutes) — optional</label>
                    <input type="number" id="f_time_limit_minutes" name="time_limit_minutes" min="1" max="600" placeholder="Leave blank for no limit">
                </div>

                <div class="form-grid-2">
                    <div class="form-row">
                        <label for="f_start_date">Available from</label>
                        <input type="datetime-local" id="f_start_date" name="start_date">
                    </div>
                    <div class="form-row">
                        <label for="f_end_date">Available until</label>
                        <input type="datetime-local" id="f_end_date" name="end_date">
                    </div>
                </div>

                <label class="form-check">
                    <input type="checkbox" id="f_allow_access" name="allow_access" value="1" checked>
                    <span>🔓 Allow access (uncheck to lock the exam from students)</span>
                </label>

                <div class="dialog-actions">
                    <button type="button" class="btn secondary" onclick="closeExamModal()">Cancel</button>
                    <button type="submit" class="btn primary" id="examSaveBtn">Save exam</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const body = document.body;
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const themeToggle = document.getElementById('themeToggle');
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    const profileArea = document.getElementById('profileArea');

    function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); body.style.overflow = 'hidden'; }
    function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); body.style.overflow = ''; }
    menuToggle.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    overlay.addEventListener('click', closeSidebar);

    if (localStorage.getItem('theme') === 'dark') {
        body.classList.add('dark-mode');
        themeToggle.textContent = '☀';
    }
    themeToggle.addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        const dark = body.classList.contains('dark-mode');
        themeToggle.textContent = dark ? '☀' : '🌙';
        localStorage.setItem('theme', dark ? 'dark' : 'light');
    });

    profileToggle.addEventListener('click', e => {
        e.stopPropagation();
        profileMenu.classList.toggle('show');
    });
    document.addEventListener('click', e => {
        if (!profileArea.contains(e.target)) profileMenu.classList.remove('show');
    });
})();

// Modal control
const examModal = document.getElementById('examModal');
function openExamModal(data) {
    const form = document.getElementById('examForm');
    const title = document.getElementById('examModalTitle');

    if (!data) {
        // New
        title.textContent = 'New Semester Exam';
        document.getElementById('f_exam_id').value = 0;
        document.getElementById('f_title').value = '';
        document.getElementById('f_description').value = '';
        document.getElementById('f_division_id').value = '';
        document.getElementById('f_course_subject_id').value = '';
        document.getElementById('f_exam_type').value = 'final';
        document.getElementById('f_status').value = 'draft';
        document.getElementById('f_passing_score').value = 70;
        document.getElementById('f_max_attempts').value = 1;
        document.getElementById('f_time_limit_minutes').value = '';
        document.getElementById('f_start_date').value = '';
        document.getElementById('f_end_date').value = '';
        document.getElementById('f_allow_access').checked = true;
    } else {
        // Edit
        title.textContent = 'Edit Semester Exam';
        document.getElementById('f_exam_id').value = data.id;
        document.getElementById('f_title').value = data.title;
        document.getElementById('f_description').value = data.description;
        document.getElementById('f_division_id').value = data.division_id;
        document.getElementById('f_course_subject_id').value = data.course_subject_id;
        document.getElementById('f_exam_type').value = data.exam_type;
        document.getElementById('f_status').value = data.status;
        document.getElementById('f_passing_score').value = data.passing_score;
        document.getElementById('f_max_attempts').value = data.max_attempts;
        document.getElementById('f_time_limit_minutes').value = data.time_limit_minutes;
        document.getElementById('f_start_date').value = data.start_date;
        document.getElementById('f_end_date').value = data.end_date;
        document.getElementById('f_allow_access').checked = data.allow_access === 1;
    }
    examModal.classList.add('show');
}
function closeExamModal() {
    examModal.classList.remove('show');
}
examModal.addEventListener('click', e => {
    if (e.target === examModal) closeExamModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeExamModal();
});

// Edit buttons — read data-exam JSON safely
document.querySelectorAll('.js-edit-exam').forEach(function (btn) {
    btn.addEventListener('click', function () {
        try {
            var data = JSON.parse(btn.getAttribute('data-exam'));
            openExamModal(data);
        } catch (err) {
            console.error('Failed to parse exam data', err);
        }
    });
});
</script>
</body>
</html>