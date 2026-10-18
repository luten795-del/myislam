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

define('SEM_UPLOAD_DIR', __DIR__ . '/../uploads/semester_questions/');
define('SEM_UPLOAD_URL', '../uploads/semester_questions/');
define('SEM_MAX_FILE_SIZE', 5 * 1024 * 1024);
define('SEM_ALLOWED_EXT', ['jpg','jpeg','png','gif','webp']);

if (!is_dir(SEM_UPLOAD_DIR)) {
    @mkdir(SEM_UPLOAD_DIR, 0755, true);
}

function semHandleImageUpload(string $field): array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'Upload error code ' . $f['error']];
    }
    if (($f['size'] ?? 0) > SEM_MAX_FILE_SIZE) {
        return ['ok' => false, 'path' => null, 'error' => 'File exceeds 5 MB'];
    }
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, SEM_ALLOWED_EXT, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Invalid file type'];
    }
    $newName = uniqid('sq_', true) . '.' . $ext;
    $full = SEM_UPLOAD_DIR . $newName;
    if (!move_uploaded_file((string)$f['tmp_name'], $full)) {
        return ['ok' => false, 'path' => null, 'error' => 'Failed to save file'];
    }
    return ['ok' => true, 'path' => 'uploads/semester_questions/' . $newName, 'error' => null];
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
    exit('Access denied.');
}

// ---------------------------------------------------------------------------
// LOAD EXAM CONTEXT
// ---------------------------------------------------------------------------
$examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
if ($examId <= 0) {
    die('Invalid exam id.');
}

$stmt = $conn->prepare("
    SELECT se.*,
           cd.name AS division_name,
           cd.course_id AS course_id,
           c.title AS course_title,
           cs.subject_id AS subject_id,
           s.name AS subject_name,
           s.icon AS subject_icon
    FROM semester_exams se
    JOIN course_divisions cd ON cd.id = se.division_id
    JOIN courses c           ON c.id  = cd.course_id
    LEFT JOIN course_subjects cs ON cs.id = se.course_subject_id
    LEFT JOIN subjects s         ON s.id  = cs.subject_id
    WHERE se.id = ?
    LIMIT 1
");
$stmt->execute([$examId]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$exam) {
    die('Exam not found.');
}
$courseId = (int)$exam['course_id'];

// ---------------------------------------------------------------------------
// HELPERS FOR QUESTION LOAD
// ---------------------------------------------------------------------------
function semLoadOptions(PDO $conn, int $questionId): array {
    $stmt = $conn->prepare("
        SELECT id, option_text, image_path, is_correct, sort_order
        FROM semester_exam_options
        WHERE question_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$questionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function semLoadQuestion(PDO $conn, int $questionId, int $examId): ?array {
    $stmt = $conn->prepare("
        SELECT * FROM semester_exam_questions
        WHERE id = ? AND exam_id = ?
        LIMIT 1
    ");
    $stmt->execute([$questionId, $examId]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$q) return null;
    $q['options'] = semLoadOptions($conn, $questionId);
    return $q;
}

function semDeleteQuestion(PDO $conn, int $questionId, int $examId): bool {
    // Verify ownership
    $stmt = $conn->prepare("SELECT image_path FROM semester_exam_questions WHERE id = ? AND exam_id = ?");
    $stmt->execute([$questionId, $examId]);
    $qImg = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT image_path FROM semester_exam_options WHERE question_id = ?");
    $stmt->execute([$questionId]);
    $oImgs = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    // Options cascade via FK, but delete explicitly for safety
    $conn->prepare("DELETE FROM semester_exam_options WHERE question_id = ?")->execute([$questionId]);
    $conn->prepare("DELETE FROM semester_exam_questions WHERE id = ? AND exam_id = ?")->execute([$questionId, $examId]);

    foreach (array_filter(array_merge([$qImg ?: null], $oImgs)) as $p) {
        $full = __DIR__ . '/../' . ltrim((string)$p, '/');
        if (file_exists($full)) @unlink($full);
    }
    return true;
}

// ---------------------------------------------------------------------------
// POST ACTIONS
// ---------------------------------------------------------------------------
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // ---- DELETE ----
    if ($action === 'delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        if ($qid > 0) {
            try {
                semDeleteQuestion($conn, $qid, $examId);
                $message = 'Question deleted.';
                $messageType = 'success';
            } catch (PDOException $ex) {
                $message = 'Delete failed: ' . $ex->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // ---- SAVE (create or update) ----
    if ($action === 'save_question') {
        $qid          = (int)($_POST['question_id'] ?? 0);
        $text         = trim((string)($_POST['question_text'] ?? ''));
        $qtype        = (string)($_POST['question_type'] ?? 'multiple_choice');
        $points       = max(1, (int)($_POST['points'] ?? 1));
        $negPoints    = max(0, (int)($_POST['negative_points'] ?? 0));
        $difficulty   = (string)($_POST['difficulty'] ?? 'medium');
        $explanation  = trim((string)($_POST['explanation'] ?? ''));
        $expected     = trim((string)($_POST['expected_answer'] ?? ''));
        $keywordsRaw  = trim((string)($_POST['keywords'] ?? ''));
        $correctMc    = (string)($_POST['correct_option_mc'] ?? '');
        $correctTf    = strtolower(trim((string)($_POST['correct_option_tf'] ?? 'true')));
        $rawOptions   = $_POST['options'] ?? [];

        if ($text === '') {
            $message = 'Question text is required.'; $messageType = 'danger';
        } elseif (!in_array($qtype, ['multiple_choice','true_false','explanation'], true)) {
            $message = 'Invalid question type.'; $messageType = 'danger';
        } elseif (!in_array($difficulty, ['easy','medium','hard'], true)) {
            $message = 'Invalid difficulty.'; $messageType = 'danger';
        } else {
            // Validate options
            $normalizedOptions = [];
            if ($qtype === 'multiple_choice') {
                foreach (['A','B','C','D'] as $k) {
                    $normalizedOptions[$k] = trim((string)($rawOptions[$k] ?? ''));
                }
                $nonEmpty = array_filter($normalizedOptions, fn($v) => $v !== '');
                if (count($nonEmpty) < 2) {
                    $message = 'Provide at least two non-empty options.'; $messageType = 'danger';
                } elseif (!in_array($correctMc, ['A','B','C','D'], true) || ($normalizedOptions[$correctMc] ?? '') === '') {
                    $message = 'Select a valid correct option (must not be empty).'; $messageType = 'danger';
                }
            }
            if ($qtype === 'true_false' && !in_array($correctTf, ['true','false'], true)) {
                $correctTf = 'true';
            }

            // Keywords JSON
            $keywordsJson = null;
            if ($qtype === 'explanation' && $keywordsRaw !== '') {
                $arr = [];
                foreach (explode(',', $keywordsRaw) as $w) {
                    $w = trim($w);
                    if ($w !== '') $arr[] = ['word' => $w, 'weight' => 1];
                }
                $keywordsJson = json_encode($arr);
            }

            if ($message === '') {
                try {
                    $upload = semHandleImageUpload('image_file');
                    if (!$upload['ok']) {
                        throw new RuntimeException('Image upload failed: ' . ($upload['error'] ?? 'unknown'));
                    }
                    $newImagePath = $upload['path'] ?? null;
                    $clearImage   = isset($_POST['clear_image']) && $_POST['clear_image'] === '1';

                    $old = $qid > 0 ? semLoadQuestion($conn, $qid, $examId) : null;

                    $conn->beginTransaction();

                    if ($qid > 0 && $old) {
                        $sql = "UPDATE semester_exam_questions SET
                                    question_text = ?,
                                    question_type = ?,
                                    points = ?,
                                    negative_points = ?,
                                    difficulty = ?,
                                    explanation = ?,
                                    expected_answer = ?,
                                    keywords_json = ?,
                                    sort_order = ?
                                ";
                        $params = [
                            $text, $qtype, $points, $negPoints,
                            $difficulty,
                            $explanation !== '' ? $explanation : null,
                            $expected !== '' ? $expected : null,
                            $keywordsJson,
                            (int)($_POST['sort_order'] ?? 0),
                        ];
                        if ($newImagePath !== null) {
                            $sql .= ", image_path = ?";
                            $params[] = $newImagePath;
                        } elseif ($clearImage) {
                            $sql .= ", image_path = NULL";
                        }
                        $sql .= " WHERE id = ? AND exam_id = ?";
                        $params[] = $qid;
                        $params[] = $examId;

                        $conn->prepare($sql)->execute($params);

                        // Replace options
                        $conn->prepare("DELETE FROM semester_exam_options WHERE question_id = ?")->execute([$qid]);
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO semester_exam_questions
                                (exam_id, question_text, question_type, points, negative_points,
                                 difficulty, explanation, expected_answer, keywords_json,
                                 image_path, sort_order)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $examId, $text, $qtype, $points, $negPoints,
                            $difficulty,
                            $explanation !== '' ? $explanation : null,
                            $expected !== '' ? $expected : null,
                            $keywordsJson,
                            $newImagePath,
                            (int)($_POST['sort_order'] ?? 0),
                        ]);
                        $qid = (int)$conn->lastInsertId();
                    }

                    // Insert options
                    if ($qtype === 'true_false') {
                        $ins = $conn->prepare("
                            INSERT INTO semester_exam_options
                                (question_id, option_text, image_path, is_correct, sort_order)
                            VALUES (?, ?, NULL, ?, ?)
                        ");
                        $ins->execute([$qid, 'True',  $correctTf === 'true'  ? 1 : 0, 1]);
                        $ins->execute([$qid, 'False', $correctTf === 'false' ? 1 : 0, 2]);
                    } elseif ($qtype === 'multiple_choice') {
                        $ins = $conn->prepare("
                            INSERT INTO semester_exam_options
                                (question_id, option_text, image_path, is_correct, sort_order)
                            VALUES (?, ?, NULL, ?, ?)
                        ");
                        $sort = 1;
                        foreach (['A','B','C','D'] as $k) {
                            $v = trim((string)($normalizedOptions[$k] ?? ''));
                            if ($v === '') continue;
                            $ins->execute([$qid, $v, ($k === $correctMc) ? 1 : 0, $sort]);
                            $sort++;
                        }
                    }

                    // Cleanup old image if replaced or cleared
                    if ($old && !empty($old['image_path'])) {
                        if ($newImagePath !== null || $clearImage) {
                            $full = __DIR__ . '/../' . ltrim((string)$old['image_path'], '/');
                            if (file_exists($full)) @unlink($full);
                        }
                    }

                    $conn->commit();
                    $message = $old ? 'Question updated.' : 'Question created.';
                    $messageType = 'success';
                } catch (Throwable $ex) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $message = $ex->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// LOAD FOR DISPLAY
// ---------------------------------------------------------------------------
$editingQuestion = null;
if (isset($_GET['edit'])) {
    $editingQuestion = semLoadQuestion($conn, (int)$_GET['edit'], $examId);
}

$stmt = $conn->prepare("
    SELECT * FROM semester_exam_questions
    WHERE exam_id = ?
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute([$examId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Attach options
foreach ($questions as &$q) {
    $q['options'] = semLoadOptions($conn, (int)$q['id']);
}
unset($q);

// Pre-fill editor values
$optionsMap = ['A'=>'','B'=>'','C'=>'','D'=>''];
$correctKey = 'A';
if ($editingQuestion && $editingQuestion['question_type'] === 'multiple_choice') {
    $keys = array_keys($optionsMap);
    foreach ($editingQuestion['options'] as $idx => $opt) {
        if (!isset($keys[$idx])) break;
        $optionsMap[$keys[$idx]] = (string)$opt['option_text'];
        if ((int)$opt['is_correct'] === 1) $correctKey = $keys[$idx];
    }
}
if ($editingQuestion && $editingQuestion['question_type'] === 'true_false') {
    foreach ($editingQuestion['options'] as $opt) {
        if ((int)$opt['is_correct'] === 1) {
            $correctKey = strtolower((string)$opt['option_text']) === 'false' ? 'false' : 'true';
        }
    }
}

$keywordsDisplay = '';
if ($editingQuestion && !empty($editingQuestion['keywords_json'])) {
    $decoded = json_decode((string)$editingQuestion['keywords_json'], true);
    if (is_array($decoded)) {
        $words = array_map(fn($kw) => is_array($kw) ? ($kw['word'] ?? '') : (string)$kw, $decoded);
        $keywordsDisplay = implode(', ', array_filter($words));
    }
}

// ---------------------------------------------------------------------------
// NAV
// ---------------------------------------------------------------------------
$mainNavigation = [
    ['name'=>'Dashboard','link'=>'dashboard.php','active'=>false],
    ['name'=>'Subjects','link'=>'admin_subjects.php','active'=>false],
    ['name'=>'Categories','link'=>'admin_categories.php','active'=>false],
    ['name'=>'Contents','link'=>'admin_contents.php','active'=>false],
    ['name'=>'Quizzes','link'=>'admin_quizzes.php','active'=>false],
    ['name'=>'Courses','link'=>'admin_courses.php','active'=>true],
    ['name'=>'Users','link'=>'admin_users.php','active'=>false],
    ['name'=>'Announcements','link'=>'admin_announcements.php','active'=>false],
    ['name'=>'Settings','link'=>'admin_settings.php','active'=>false],
    ['name'=>'Logout','link'=>'../logout.php','active'=>false],
];

$sidebarNavigation = [
    ['name'=>'Dashboard','link'=>'dashboard.php','active'=>false,'icon'=>'📊'],
    ['name'=>'Subjects','link'=>'admin_subjects.php','active'=>false,'icon'=>'📚'],
    ['name'=>'Categories','link'=>'admin_categories.php','active'=>false,'icon'=>'🗂️'],
    ['name'=>'Contents','link'=>'admin_contents.php','active'=>false,'icon'=>'📘'],
    ['name'=>'Quizzes','link'=>'admin_quizzes.php','active'=>false,'icon'=>'📝'],
    ['name'=>'Courses','link'=>'admin_courses.php','active'=>true,'icon'=>'🎓'],
    ['name'=>'Users','link'=>'admin_users.php','active'=>false,'icon'=>'👥'],
    ['name'=>'Announcements','link'=>'admin_announcements.php','active'=>false,'icon'=>'📢'],
    ['name'=>'Settings','link'=>'admin_settings.php','active'=>false,'icon'=>'⚙️'],
    ['name'=>'Logout','link'=>'../logout.php','active'=>false,'icon'=>'🚪'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Exam Questions — <?php echo e($exam['title']); ?></title>
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    :root{
        --bg:#f5f7f5;--surface:#ffffff;--surface-2:#f9fafb;--surface-3:#eef2f7;--line:#e5e7eb;
        --text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;--brand:#166534;--brand-soft:#e7f3eb;
        --warning:#f59e0b;--danger:#ef4444;--header-bg:#e1f0e7;
        --header-height:72px;
        --shadow-sm:0 1px 2px 0 rgb(0 0 0 / 0.05);--shadow-md:0 4px 6px -1px rgb(0 0 0 / 0.1);--shadow-lg:0 10px 15px -3px rgb(0 0 0 / 0.1);
        --radius-sm:12px;--radius-md:16px;--radius-lg:20px;
    }
    body.dark-mode{
        --bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;--line:#304150;
        --text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;--brand:#7dd3a7;--brand-soft:rgba(125,211,167,0.12);
        --header-bg:#13202a;
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
    .sidebar-overlay{display:none;position:fixed;top:var(--header-height);left:0;width:100%;height:calc(100vh - var(--header-height));background:rgba(0,0,0,.5);z-index:1100}
    .sidebar-overlay.show{display:block}
    .sidebar{position:fixed;top:var(--header-height);left:-100%;width:320px;height:calc(100vh - var(--header-height));background:var(--surface);border-right:1px solid var(--line);padding:24px 20px;z-index:1200;transition:left .3s ease;overflow-y:auto}
    .sidebar.open{left:0}
    .sidebar-title{font-size:12px;color:var(--muted);text-transform:uppercase;margin-bottom:20px;font-weight:700;letter-spacing:1px;padding:0 8px}
    .subject-list{display:grid;gap:8px}
    .subject-link{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--radius-md);background:var(--surface-2);border:1px solid transparent;font-size:14px;font-weight:500;transition:all .2s}
    .subject-link:hover{background:var(--brand-soft);border-color:var(--brand);transform:translateX(4px)}
    .subject-link.active{background:var(--brand-soft);color:var(--brand);border-color:var(--brand)}
    .main-content{padding:calc(var(--header-height) + 24px) 24px 40px;max-width:1500px;margin:0 auto}
    .page-stack{display:grid;gap:24px}
    .section-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px}
    .hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
    .hero-card h1{font-size:26px;font-weight:700;margin-bottom:6px}
    .hero-card p{color:var(--text-secondary);font-size:14px}
    .btn{padding:10px 16px;border-radius:14px;font-weight:600;border:none;cursor:pointer;transition:.2s;font-size:13px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:inherit}
    .btn.primary{background:var(--brand);color:#fff}
    .btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
    .btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
    .btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
    .btn:hover{transform:translateY(-2px)}
    .flash{padding:14px 18px;border-radius:14px;font-weight:600;font-size:14px;border:1px solid transparent}
    .flash.success{background:rgba(34,197,94,.12);color:#15803d;border-color:#86efac}
    .flash.danger{background:rgba(239,68,68,.12);color:#b91c1c;border-color:#fca5a5}
    .form-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:22px}
    .form-fields{display:grid;gap:14px}
    .form-row{display:grid;gap:6px}
    .form-row label{font-size:13px;font-weight:700;color:var(--text-secondary)}
    .form-row small{font-size:11px;color:var(--muted)}
    .form-row input[type=text],.form-row input[type=number],.form-row select,.form-row textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--surface-2);font-size:14px}
    .form-row textarea{min-height:90px;resize:vertical}
    .form-row input:focus,.form-row select:focus,.form-row textarea:focus{outline:none;border-color:var(--brand);background:var(--surface)}
    .inline-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .option-grid{display:grid;gap:8px}
    .btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
    .question-item{padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:14px;margin-bottom:10px}
    .question-top{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:8px}
    .question-title{font-size:15px;font-weight:700;line-height:1.4}
    .question-meta{font-size:11.5px;color:var(--muted);line-height:1.7}
    .option-list{display:grid;gap:5px;margin-top:10px}
    .opt-row{padding:8px 12px;background:var(--surface);border:1px solid var(--line);border-radius:8px;font-size:13px}
    .opt-row.correct{border-color:#86efac;background:rgba(34,197,94,.08);font-weight:600}
    .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
    .badge.explanation{background:rgba(236,72,153,.12);color:#be185d}
    .badge.mc{background:rgba(59,130,246,.12);color:#1d4ed8}
    .badge.tf{background:rgba(156,163,175,.15);color:#374151}
    .empty{text-align:center;padding:30px 12px;color:var(--muted)}
    @media (max-width:850px){.header-top-nav{display:none}.form-grid{grid-template-columns:1fr}}
    @media (max-width:640px){:root{--header-height:64px}.main-content{padding:calc(var(--header-height) + 14px) 12px 40px}.inline-row{grid-template-columns:1fr}}
</style>
</head>
<body>

<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="brand-box">🛠️ Admin</div>
        <nav class="header-top-nav">
            <?php foreach ($mainNavigation as $nav): ?>
                <a href="<?php echo e($nav['link']); ?>" class="header-top-link <?php echo $nav['active'] ? 'active' : ''; ?>">
                    <?php echo e($nav['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle" type="button">🌙</button>
        <button class="lang-btn" id="langToggle" type="button">EN</button>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button">
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
                        <h4><?php echo e($admin['full_name']); ?></h4>
                        <p><?php echo e($admin['email']); ?></p>
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
                <h1>❓ <?php echo e($exam['title']); ?></h1>
                <p>
                    <?php echo e($exam['course_title']); ?>
                    · <?php echo e($exam['division_name']); ?>
                    <?php if (!empty($exam['subject_name'])): ?>
                        · <?php echo e(($exam['subject_icon'] ?: '📖') . ' ' . $exam['subject_name']); ?>
                    <?php endif; ?>
                    · <?php echo $exam['exam_type'] === 'supplementary' ? '📚 Supplementary' : '🎓 Final'; ?>
                    · <?php echo count($questions); ?> question<?php echo count($questions) === 1 ? '' : 's'; ?>
                </p>
            </div>
            <a class="btn secondary" href="admin_semester_exams.php?course_id=<?php echo $courseId; ?>">← Back to exams</a>
        </section>

        <?php if ($message !== ''): ?>
            <div class="flash <?php echo e($messageType); ?>"><?php echo e($message); ?></div>
        <?php endif; ?>

        <div class="form-grid">

            <!-- ============================================================ -->
            <!-- EDITOR                                                        -->
            <!-- ============================================================ -->
            <section class="section-card">
                <h2 style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:14px;">
                    <?php echo $editingQuestion ? 'Edit Question' : 'Add Question'; ?>
                </h2>

                <form method="post" enctype="multipart/form-data" class="form-fields">
                    <input type="hidden" name="action" value="save_question">
                    <input type="hidden" name="exam_id" value="<?php echo $examId; ?>">
                    <input type="hidden" name="question_id" value="<?php echo (int)($editingQuestion['id'] ?? 0); ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo SEM_MAX_FILE_SIZE; ?>">

                    <div class="form-row">
                        <label for="question_text">Question Text *</label>
                        <textarea id="question_text" name="question_text" required><?php echo e($editingQuestion['question_text'] ?? ''); ?></textarea>
                    </div>

                    <div class="inline-row">
                        <div class="form-row">
                            <label for="question_type">Type</label>
                            <select id="question_type" name="question_type">
                                <?php $qt = $editingQuestion['question_type'] ?? 'multiple_choice'; ?>
                                <option value="multiple_choice" <?php echo $qt==='multiple_choice'?'selected':''; ?>>Multiple Choice</option>
                                <option value="true_false"      <?php echo $qt==='true_false'?'selected':''; ?>>True / False</option>
                                <option value="explanation"     <?php echo $qt==='explanation'?'selected':''; ?>>Explanation (Open)</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="difficulty">Difficulty</label>
                            <select id="difficulty" name="difficulty">
                                <?php $df = $editingQuestion['difficulty'] ?? 'medium'; ?>
                                <option value="easy"   <?php echo $df==='easy'?'selected':''; ?>>Easy</option>
                                <option value="medium" <?php echo $df==='medium'?'selected':''; ?>>Medium</option>
                                <option value="hard"   <?php echo $df==='hard'?'selected':''; ?>>Hard</option>
                            </select>
                        </div>
                    </div>

                    <div class="inline-row">
                        <div class="form-row">
                            <label for="points">Points</label>
                            <input type="number" id="points" name="points" min="1" value="<?php echo (int)($editingQuestion['points'] ?? 1); ?>">
                        </div>
                        <div class="form-row">
                            <label for="negative_points">Negative Points</label>
                            <input type="number" id="negative_points" name="negative_points" min="0" value="<?php echo (int)($editingQuestion['negative_points'] ?? 0); ?>">
                        </div>
                    </div>

                    <div class="inline-row">
                        <div class="form-row">
                            <label for="sort_order">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" min="0" value="<?php echo (int)($editingQuestion['sort_order'] ?? 0); ?>">
                        </div>
                        <div class="form-row">
                            <label for="image_file">Image (optional)</label>
                            <input type="file" id="image_file" name="image_file" accept="image/*">
                        </div>
                    </div>

                    <?php if (!empty($editingQuestion['image_path'])): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:8px;border:1px solid var(--line);border-radius:10px;background:var(--surface-2);">
                            <img src="<?php echo e('../' . ltrim((string)$editingQuestion['image_path'], '/')); ?>" alt="" style="max-height:80px;border-radius:6px;">
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;">
                                <input type="checkbox" name="clear_image" value="1"> Remove image
                            </label>
                        </div>
                    <?php endif; ?>

                    <!-- MC OPTIONS -->
                    <div id="mcOptions" class="form-row">
                        <label>Options (A–D)</label>
                        <div class="option-grid">
                            <?php foreach (['A','B','C','D'] as $k): ?>
                                <input type="text" name="options[<?php echo $k; ?>]" placeholder="Option <?php echo $k; ?>" value="<?php echo e($optionsMap[$k]); ?>">
                            <?php endforeach; ?>
                        </div>
                        <label style="margin-top:10px;">Correct option</label>
                        <select name="correct_option_mc">
                            <?php foreach (['A','B','C','D'] as $k): ?>
                                <option value="<?php echo $k; ?>" <?php echo $correctKey === $k ? 'selected' : ''; ?>>
                                    Option <?php echo $k; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- TF OPTIONS -->
                    <div id="tfOptions" class="form-row" style="display:none;">
                        <label>Correct answer</label>
                        <select name="correct_option_tf">
                            <option value="true"  <?php echo $correctKey==='true'?'selected':'';  ?>>True</option>
                            <option value="false" <?php echo $correctKey==='false'?'selected':''; ?>>False</option>
                        </select>
                    </div>

                    <!-- EXPLANATION -->
                    <div id="explOptions" class="form-row" style="display:none;">
                        <label for="expected_answer">Expected Answer (model)</label>
                        <textarea id="expected_answer" name="expected_answer" style="min-height:70px;"><?php echo e($editingQuestion['expected_answer'] ?? ''); ?></textarea>

                        <label for="keywords" style="margin-top:8px;">Keywords (comma separated)</label>
                        <input type="text" id="keywords" name="keywords" placeholder="e.g. tawheed, shirk, ibadah" value="<?php echo e($keywordsDisplay); ?>">

                        <label for="explanation" style="margin-top:8px;">Explanation / feedback</label>
                        <textarea id="explanation" name="explanation" style="min-height:60px;"><?php echo e($editingQuestion['explanation'] ?? ''); ?></textarea>
                    </div>

                    <div class="btn-row">
                        <button type="submit" class="btn primary">
                            <?php echo $editingQuestion ? 'Update question' : 'Save question'; ?>
                        </button>
                        <?php if ($editingQuestion): ?>
                            <a class="btn secondary" href="admin_semester_exam_questions.php?exam_id=<?php echo $examId; ?>">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <!-- ============================================================ -->
            <!-- QUESTION LIST                                                 -->
            <!-- ============================================================ -->
            <section class="section-card">
                <h2 style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:14px;">
                    Existing questions (<?php echo count($questions); ?>)
                </h2>

                <?php if (!$questions): ?>
                    <div class="empty">No questions yet. Add the first one using the form.</div>
                <?php else: ?>
                    <?php foreach ($questions as $idx => $q):
                        $qtype = (string)$q['question_type'];
                        $badgeClass = $qtype === 'explanation' ? 'explanation' : ($qtype === 'true_false' ? 'tf' : 'mc');
                        $badgeLabel = $qtype === 'explanation' ? 'Explanation' : ($qtype === 'true_false' ? 'True/False' : 'Multiple Choice');
                    ?>
                        <div class="question-item">
                            <div class="question-top">
                                <div style="flex:1;min-width:0;">
                                    <div class="question-title">
                                        Q<?php echo $idx + 1; ?>. <?php echo e($q['question_text']); ?>
                                        <span class="badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
                                    </div>
                                    <div class="question-meta">
                                        Points: <?php echo (int)$q['points']; ?>
                                        · Negative: <?php echo (int)$q['negative_points']; ?>
                                        · Difficulty: <?php echo e(ucfirst((string)$q['difficulty'])); ?>
                                        · Sort: <?php echo (int)$q['sort_order']; ?>
                                    </div>
                                </div>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <a class="btn secondary" href="?exam_id=<?php echo $examId; ?>&edit=<?php echo (int)$q['id']; ?>">✏️ Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this question?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_question">
                                        <input type="hidden" name="exam_id" value="<?php echo $examId; ?>">
                                        <input type="hidden" name="question_id" value="<?php echo (int)$q['id']; ?>">
                                        <button type="submit" class="btn danger">🗑️</button>
                                    </form>
                                </div>
                            </div>

                            <?php if ($qtype !== 'explanation' && !empty($q['options'])): ?>
                                <div class="option-list">
                                    <?php foreach ($q['options'] as $opt): ?>
                                        <div class="opt-row <?php echo ((int)$opt['is_correct'] === 1) ? 'correct' : ''; ?>">
                                            <?php echo e($opt['option_text']); ?>
                                            <?php if ((int)$opt['is_correct'] === 1): ?>
                                                <strong> — Correct</strong>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($qtype === 'explanation'): ?>
                                <div class="question-meta" style="margin-top:10px;">
                                    <strong>Expected:</strong>
                                    <?php echo e((string)($q['expected_answer'] ?? '(none)')); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

        </div>
    </div>
</main>

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

    function openSidebar(){sidebar.classList.add('open');overlay.classList.add('show');body.style.overflow='hidden';}
    function closeSidebar(){sidebar.classList.remove('open');overlay.classList.remove('show');body.style.overflow='';}
    menuToggle.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    overlay.addEventListener('click', closeSidebar);

    if (localStorage.getItem('theme') === 'dark') {
        body.classList.add('dark-mode'); themeToggle.textContent = '☀';
    }
    themeToggle.addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        const dark = body.classList.contains('dark-mode');
        themeToggle.textContent = dark ? '☀' : '🌙';
        localStorage.setItem('theme', dark ? 'dark' : 'light');
    });

    profileToggle.addEventListener('click', e => { e.stopPropagation(); profileMenu.classList.toggle('show'); });
    document.addEventListener('click', e => { if (!profileArea.contains(e.target)) profileMenu.classList.remove('show'); });

    // Type-switching UI
    const typeSel = document.getElementById('question_type');
    const mc = document.getElementById('mcOptions');
    const tf = document.getElementById('tfOptions');
    const ex = document.getElementById('explOptions');
    function syncTypeUI() {
        const t = typeSel.value;
        mc.style.display = t === 'multiple_choice' ? '' : 'none';
        tf.style.display = t === 'true_false' ? '' : 'none';
        ex.style.display = t === 'explanation' ? '' : 'none';
    }
    typeSel.addEventListener('change', syncTypeUI);
    syncTypeUI();
})();
</script>
</body>
</html>