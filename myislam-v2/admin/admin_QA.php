<?php
declare(strict_types=1);

// A POST that exceeded post_max_size arrives here with an empty $_POST/$_FILES
// even though Content-Length was set -- see admin_adhkaar.php for why this
// guard has to run before session_start()/header().
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    echo '<!DOCTYPE html><html><body style="font-family:system-ui,sans-serif;max-width:520px;margin:80px auto;text-align:center;color:#1f2937;">'
       . '<h2 style="color:#b91c1c;">Upload Too Large</h2>'
       . '<p>The file you tried to upload is bigger than this server currently allows. '
       . 'Try a smaller audio/video file, or ask an administrator to raise the server\'s upload limit.</p>'
       . '<p><a href="javascript:history.back()">&larr; Go back</a></p>'
       . '</body></html>';
    exit;
}

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/admin_auth.php';

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function truncateText(string $text, int $len = 70): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if (mb_strlen($text) <= $len) return $text;
    return mb_substr($text, 0, $len) . '…';
}

$conn = db();
if (!$conn) die('Database connection error.');

$userId = (int)($_SESSION['user_id'] ?? 0);

// helper: upload file (identical convention to admin_adhkaar.php's handleUpload)
function handleUpload(array $file, string $subdir = 'qa', ?string &$error = null): ?string {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['mp3','wav','ogg','m4a','mp4','webm','mov'];
    if (!in_array($ext, $allowed)) { $error = 'Unsupported file type: .' . e($ext); return null; }
    $uploadDir = __DIR__ . '/../uploads/' . $subdir . '/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        $error = 'Upload directory is not writable by the web server (' . e($uploadDir) . '). Check ownership/permissions.';
        error_log('handleUpload: unwritable dir ' . $uploadDir);
        return null;
    }
    $filename = uniqid('qa_', true) . '.' . $ext;
    $dest = $uploadDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/' . $subdir . '/' . $filename;
    }
    $error = 'move_uploaded_file() failed for ' . e($dest) . '. Check server permissions/disk space.';
    error_log('handleUpload: move_uploaded_file failed for ' . $dest);
    return null;
}

// load categories (for the item form's dropdown)
$categories = [];
try {
    $categories = $conn->query("SELECT id, name, slug FROM qa_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_qa categories dropdown: ' . $e->getMessage());
}

$errors = [];
$success = '';
$catErrors = [];
$catSuccess = '';

// ============================================================================
// POST HANDLING
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Save a question/answer item -------------------------------------
    if ($action === 'save') {
        $itemId       = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $categoryId   = (isset($_POST['category_id']) && (int)$_POST['category_id'] > 0) ? (int)$_POST['category_id'] : null;
        $question     = trim($_POST['question'] ?? '');
        $questionSw   = trim($_POST['question_sw'] ?? '');
        $answer       = trim($_POST['answer'] ?? '');
        $answerSw     = trim($_POST['answer_sw'] ?? '');
        $referenceType = ($_POST['reference_type'] ?? 'ulamaa') === 'book' ? 'book' : 'ulamaa';
        $bookTitle    = trim($_POST['book_title'] ?? '');
        $bookAuthor   = trim($_POST['book_author'] ?? '');
        $bookPageRef  = trim($_POST['book_page_reference'] ?? '');
        $scholarName  = trim($_POST['scholar_name'] ?? '');
        $scholarTitle = trim($_POST['scholar_title'] ?? '');
        $sourceReference = trim($_POST['source_reference'] ?? '');
        $sortOrder    = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $isFeatured   = isset($_POST['is_featured']) ? 1 : 0;
        $isActive     = isset($_POST['is_active']) ? 1 : 0;

        if ($question === '') $errors[] = 'Question (English) is required.';
        if ($answer === '') $errors[] = 'Answer (English) is required.';
        if ($referenceType === 'book' && $bookTitle === '') $errors[] = 'Book title is required when Reference Type is "Book".';
        if ($referenceType === 'ulamaa' && $scholarName === '') $errors[] = 'Scholar name is required when Reference Type is "Ulamaa".';

        // Audio/video: upload wins over URL when both given; on update, leaving
        // both blank keeps whatever was already saved (COALESCE below).
        $audioPath = null; $audioUrl = null; $videoPath = null; $videoUrl = null;
        $uploadErr = null;
        if (!empty($_FILES['audio_file']['name'])) {
            $res = handleUpload($_FILES['audio_file'], 'qa', $uploadErr);
            if ($res) $audioPath = $res; elseif ($uploadErr) $errors[] = $uploadErr;
        }
        if (!empty($_POST['audio_url'])) $audioUrl = trim($_POST['audio_url']);
        if (!empty($_FILES['video_file']['name'])) {
            $res = handleUpload($_FILES['video_file'], 'qa', $uploadErr);
            if ($res) $videoPath = $res; elseif ($uploadErr) $errors[] = $uploadErr;
        }
        if (!empty($_POST['video_url'])) $videoUrl = trim($_POST['video_url']);

        if (empty($errors)) {
            try {
                if ($itemId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE qa_items SET
                            category_id = ?, question = ?, question_sw = ?, answer = ?, answer_sw = ?,
                            reference_type = ?, book_title = ?, book_author = ?, book_page_reference = ?,
                            scholar_name = ?, scholar_title = ?, source_reference = ?,
                            sort_order = ?, is_featured = ?, is_active = ?,
                            audio_path = COALESCE(?, audio_path), audio_url = COALESCE(?, audio_url),
                            video_path = COALESCE(?, video_path), video_url = COALESCE(?, video_url),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $categoryId, $question, $questionSw, $answer, $answerSw,
                        $referenceType, $bookTitle, $bookAuthor, $bookPageRef,
                        $scholarName, $scholarTitle, $sourceReference,
                        $sortOrder, $isFeatured, $isActive,
                        $audioPath, $audioUrl, $videoPath, $videoUrl,
                        $itemId,
                    ]);
                    $success = 'Question updated.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO qa_items
                            (category_id, question, question_sw, answer, answer_sw,
                             reference_type, book_title, book_author, book_page_reference,
                             scholar_name, scholar_title, source_reference,
                             sort_order, is_featured, is_active,
                             audio_path, audio_url, video_path, video_url, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $categoryId, $question, $questionSw, $answer, $answerSw,
                        $referenceType, $bookTitle, $bookAuthor, $bookPageRef,
                        $scholarName, $scholarTitle, $sourceReference,
                        $sortOrder, $isFeatured, $isActive,
                        $audioPath, $audioUrl, $videoPath, $videoUrl,
                    ]);
                    $success = 'Question created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // ---- Save a category ---------------------------------------------------
    if ($action === 'save_category') {
        $catId    = isset($_POST['cat_id']) ? (int)$_POST['cat_id'] : 0;
        $name     = trim($_POST['cat_name'] ?? '');
        $name_sw  = trim($_POST['cat_name_sw'] ?? '');
        $slugInput = trim($_POST['cat_slug'] ?? '');
        $icon     = trim($_POST['cat_icon'] ?? '') ?: '❓';
        $description = trim($_POST['cat_description'] ?? '');
        $description_sw = trim($_POST['cat_description_sw'] ?? '');
        $catSortOrder = isset($_POST['cat_sort_order']) ? (int)$_POST['cat_sort_order'] : 0;
        $catIsActive = isset($_POST['cat_is_active']) ? 1 : 0;

        if ($name === '') {
            $catErrors[] = 'Category name is required.';
        }

        $slug = $slugInput !== '' ? $slugInput : strtolower((string)$name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string)$slug, '-');

        if ($slug === '') {
            $catErrors[] = 'Could not derive a valid slug from that name — please set one manually.';
        }

        if (empty($catErrors)) {
            try {
                $dupStmt = $conn->prepare("SELECT id FROM qa_categories WHERE slug = ? AND id != ?");
                $dupStmt->execute([$slug, $catId]);
                if ($dupStmt->fetch()) {
                    $catErrors[] = "The slug \"{$slug}\" is already used by another category. Choose a different name or set a custom slug.";
                }
            } catch (Throwable $e) {
                $catErrors[] = 'Database error checking slug: ' . $e->getMessage();
            }
        }

        if (empty($catErrors)) {
            try {
                if ($catId > 0) {
                    $stmt = $conn->prepare("UPDATE qa_categories SET name = ?, name_sw = ?, slug = ?, icon = ?, description = ?, description_sw = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$name, $name_sw, $slug, $icon, $description, $description_sw, $catSortOrder, $catIsActive, $catId]);
                    $catSuccess = 'Category updated.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO qa_categories (name, name_sw, slug, icon, description, description_sw, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$name, $name_sw, $slug, $icon, $description, $description_sw, $catSortOrder, $catIsActive]);
                    $catSuccess = 'Category created.';
                }
                $categories = $conn->query("SELECT id, name, slug FROM qa_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $catErrors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ---- Simple actions (delete / toggle / duplicate) --------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action ?? '', ['delete', 'toggle_status', 'duplicate', 'toggle_category_status', 'delete_category'], true)) {
    if ($action === 'delete' && isset($_POST['id'])) {
        $delId = (int)$_POST['id'];
        try {
            $stmt = $conn->prepare("SELECT audio_path, video_path FROM qa_items WHERE id = ?");
            $stmt->execute([$delId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                foreach ([$row['audio_path'], $row['video_path']] as $path) {
                    if ($path && is_file(__DIR__ . '/../' . $path)) {
                        @unlink(__DIR__ . '/../' . $path);
                    }
                }
            }
            $conn->prepare("DELETE FROM qa_items WHERE id = ?")->execute([$delId]);
            $success = 'Question deleted.';
        } catch (Throwable $e) {
            $errors[] = 'Could not delete: ' . $e->getMessage();
        }
    }

    if ($action === 'toggle_status' && isset($_POST['id'])) {
        try {
            $conn->prepare("UPDATE qa_items SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            $success = 'Status updated.';
        } catch (Throwable $e) {
            $errors[] = 'Could not update status: ' . $e->getMessage();
        }
    }

    if ($action === 'duplicate' && isset($_POST['id'])) {
        try {
            $stmt = $conn->prepare("SELECT * FROM qa_items WHERE id = ?");
            $stmt->execute([(int)$_POST['id']]);
            if ($src = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ins = $conn->prepare("
                    INSERT INTO qa_items
                        (category_id, question, question_sw, answer, answer_sw,
                         reference_type, book_title, book_author, book_page_reference,
                         scholar_name, scholar_title, source_reference,
                         sort_order, is_featured, is_active,
                         audio_path, audio_url, video_path, video_url, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([
                    $src['category_id'], $src['question'] . ' (Copy)', $src['question_sw'], $src['answer'], $src['answer_sw'],
                    $src['reference_type'], $src['book_title'], $src['book_author'], $src['book_page_reference'],
                    $src['scholar_name'], $src['scholar_title'], $src['source_reference'],
                    $src['sort_order'], $src['is_featured'],
                    $src['audio_path'], $src['audio_url'], $src['video_path'], $src['video_url'],
                ]);
                $success = 'Question duplicated as a draft — edit and publish it when ready.';
            } else {
                $errors[] = 'Original question not found.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not duplicate: ' . $e->getMessage();
        }
    }

    if ($action === 'toggle_category_status' && isset($_POST['id'])) {
        try {
            $conn->prepare("UPDATE qa_categories SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            $catSuccess = 'Category status updated.';
        } catch (Throwable $e) {
            $catErrors[] = 'Could not update status: ' . $e->getMessage();
        }
    }

    if ($action === 'delete_category' && isset($_POST['id'])) {
        $delCatId = (int)$_POST['id'];
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM qa_items WHERE category_id = ?");
            $stmt->execute([$delCatId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $catErrors[] = 'This category still has questions in it. Move or delete them first, then delete the category.';
            } else {
                $conn->prepare("DELETE FROM qa_categories WHERE id = ?")->execute([$delCatId]);
                $catSuccess = 'Category deleted.';
            }
        } catch (Throwable $e) {
            $catErrors[] = 'Could not delete: ' . $e->getMessage();
        }
    }
}

// load item being edited, if requested
$editing = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    try {
        $stmt = $conn->prepare("SELECT * FROM qa_items WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('admin_qa edit fetch: ' . $e->getMessage());
    }
}

// load category being edited, if requested
$editingCategory = null;
if (isset($_GET['edit_category'])) {
    $editCatId = (int)$_GET['edit_category'];
    try {
        $stmt = $conn->prepare("SELECT * FROM qa_categories WHERE id = ? LIMIT 1");
        $stmt->execute([$editCatId]);
        $editingCategory = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('admin_qa edit_category fetch: ' . $e->getMessage());
    }
}

// ============================================================================
// LISTS
// ============================================================================
$filterCategory = isset($_GET['filter_category']) ? (int)$_GET['filter_category'] : 0;
$filterStatus = in_array($_GET['filter_status'] ?? '', ['active', 'inactive'], true) ? $_GET['filter_status'] : '';
$filterRefType = in_array($_GET['filter_reference_type'] ?? '', ['book', 'ulamaa'], true) ? $_GET['filter_reference_type'] : '';

$items = [];
try {
    $where = [];
    $params = [];
    if ($filterCategory > 0) { $where[] = 'q.category_id = ?'; $params[] = $filterCategory; }
    if ($filterStatus === 'active') { $where[] = 'q.is_active = 1'; }
    if ($filterStatus === 'inactive') { $where[] = 'q.is_active = 0'; }
    if ($filterRefType !== '') { $where[] = 'q.reference_type = ?'; $params[] = $filterRefType; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $conn->prepare("
        SELECT q.*, c.name AS category_name
        FROM qa_items q
        LEFT JOIN qa_categories c ON c.id = q.category_id
        {$whereSql}
        ORDER BY q.sort_order ASC, q.id DESC
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_qa list: ' . $e->getMessage());
}

$allCategories = [];
try {
    $allCategories = $conn->query("SELECT * FROM qa_categories ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_qa categories list: ' . $e->getMessage());
}

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Q&A', 'link' => 'admin_qa.php', 'active' => true],
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
    ['name' => 'Q&A', 'link' => 'admin_qa.php', 'active' => true, 'icon' => '❓'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false, 'icon' => '👥'],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false, 'icon' => '📢'],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false, 'icon' => '⚙️'],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false, 'icon' => '🚪'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin - Manage Q&amp;A</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
/* ============================================
   ROOT VARIABLES - copied from admin_adhkaar.php's shared design system
   ============================================ */
* { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg: #f0f4f8;
    --surface: #ffffff;
    --surface-2: #f8fafc;
    --surface-3: #eef2f7;
    --line: #e2e8f0;
    --text: #0f172a;
    --text-secondary: #334155;
    --muted: #64748b;
    --brand: #0f7b4a;
    --brand-light: #16a34a;
    --brand-soft: #e6f7ed;
    --brand-dark: #0a5c34;
    --accent: #3b82f6;
    --warning: #f59e0b;
    --danger: #ef4444;
    --success: #10b981;
    --header-bg: #e8f5ee;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
    --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
    --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.12);
    --shadow-xl: 0 20px 60px rgba(0, 0, 0, 0.15);
    --header-height: 72px;
    --footer-height: 92px;
    --radius-sm: 10px;
    --radius-md: 14px;
    --radius-lg: 20px;
    --radius-xl: 28px;
    --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

body.dark-mode {
    --bg: #0b141e;
    --surface: #15202b;
    --surface-2: #1a2735;
    --surface-3: #1f2f3f;
    --line: #2d4055;
    --text: #e8edf2;
    --text-secondary: #b0c4d9;
    --muted: #7a8fa5;
    --brand: #34d399;
    --brand-light: #6ee7b7;
    --brand-soft: rgba(52, 211, 153, 0.12);
    --brand-dark: #1a7a5a;
    --accent: #60a5fa;
    --header-bg: #0e1a26;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.3);
    --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
    --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.5);
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.6;
    transition: background var(--transition), color var(--transition);
}
a { text-decoration: none; color: inherit; }
button, input, textarea, select { font-family: inherit; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--surface-2); }
::-webkit-scrollbar-thumb { background: var(--brand); border-radius: 999px; }
::-webkit-scrollbar-thumb:hover { background: var(--brand-dark); }

.app-header {
    position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
    height: var(--header-height); background: var(--header-bg);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--line);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 24px; gap: 18px;
    transition: background var(--transition), border-color var(--transition);
}
.header-left, .header-right { display: flex; align-items: center; gap: 12px; }
.header-left { flex: 1; min-width: 0; }
.header-top-nav { display: flex; align-items: center; gap: 6px; overflow-x: auto; padding: 4px 0; scrollbar-width: none; }
.header-top-nav::-webkit-scrollbar { display: none; }
.header-top-link {
    height: 38px; padding: 0 16px; border-radius: 999px; background: transparent;
    border: 1px solid transparent; display: inline-flex; align-items: center; justify-content: center;
    white-space: nowrap; font-size: 13px; font-weight: 600; transition: all var(--transition);
    color: var(--text-secondary);
}
.header-top-link:hover { background: var(--surface); border-color: var(--line); color: var(--text); }
.header-top-link.active { background: var(--surface); border-color: var(--brand); color: var(--brand); }

.menu-toggle, .icon-btn, .lang-btn {
    height: 40px; min-width: 40px; border-radius: var(--radius-sm); background: var(--surface);
    border: 1px solid var(--line); display: inline-flex; align-items: center; justify-content: center;
    transition: all var(--transition); cursor: pointer; color: var(--text-secondary); font-size: 18px;
}
.menu-toggle:hover, .icon-btn:hover, .lang-btn:hover {
    background: var(--brand-soft); border-color: var(--brand); color: var(--brand);
    transform: translateY(-1px); box-shadow: var(--shadow-sm);
}
.lang-btn { min-width: 52px; padding: 0 12px; font-weight: 700; font-size: 13px; }
.brand-box { font-size: 20px; font-weight: 800; color: var(--brand); background: transparent; border: none; white-space: nowrap; letter-spacing: -0.5px; padding: 0 4px; }
.brand-box:hover { color: var(--brand-dark); }

.profile-area { position: relative; }
.profile-btn {
    width: 40px; height: 40px; border-radius: 50%; overflow: hidden; background: var(--surface);
    border: 2px solid var(--brand); cursor: pointer; padding: 0; display: flex; align-items: center;
    justify-content: center; color: var(--brand); font-weight: 700; transition: all var(--transition);
}
.profile-btn:hover { transform: scale(1.05); box-shadow: 0 0 0 4px var(--brand-soft); }
.profile-btn img { width: 100%; height: 100%; object-fit: cover; }
.profile-avatar-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: var(--brand-soft); color: var(--brand); font-weight: 700; font-size: 16px; }
.profile-menu {
    position: absolute; top: 52px; right: 0; width: 280px; background: var(--surface);
    border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 16px; display: none;
    box-shadow: var(--shadow-lg); z-index: 1001; animation: slideDown 0.2s ease;
}
.profile-menu.show { display: block; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-8px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
.profile-menu-top { display: flex; gap: 12px; padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid var(--line); }
.profile-menu-top img, .profile-menu-top .profile-avatar-fallback { width: 48px; height: 48px; border-radius: 50%; flex-shrink: 0; }
.profile-menu-top .profile-avatar-fallback { display: inline-flex; align-items: center; justify-content: center; background: var(--brand-soft); color: var(--brand); font-weight: 700; font-size: 18px; }
.profile-menu-top h4 { font-size: 15px; margin-bottom: 2px; font-weight: 600; }
.profile-menu-top p { font-size: 12px; color: var(--muted); word-break: break-all; }
.profile-menu a { display: block; padding: 10px 12px; border-radius: var(--radius-sm); font-size: 14px; font-weight: 500; transition: all var(--transition); margin: 2px 0; }
.profile-menu a:hover { background: var(--brand-soft); color: var(--brand); }

.sidebar-overlay {
    display: none; position: fixed; top: var(--header-height); left: 0; width: 100%;
    height: calc(100vh - var(--header-height)); background: rgba(0, 0, 0, 0.5); z-index: 1100;
    backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); opacity: 0; transition: opacity 0.3s ease;
}
.sidebar-overlay.show { display: block; opacity: 1; }
.sidebar {
    position: fixed; top: var(--header-height); left: -100%; width: 300px;
    height: calc(100vh - var(--header-height)); background: var(--surface); border-right: 1px solid var(--line);
    padding: 24px 16px; z-index: 1200; transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1); overflow-y: auto;
}
.sidebar.open { left: 0; }
.sidebar-title { font-size: 11px; color: var(--muted); text-transform: uppercase; margin-bottom: 20px; font-weight: 700; letter-spacing: 1.2px; padding: 0 12px; }
.subject-list { display: grid; gap: 4px; }
.subject-link {
    display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: var(--radius-md);
    background: transparent; border: 1px solid transparent; font-size: 14px; font-weight: 500;
    transition: all var(--transition); color: var(--text-secondary);
}
.subject-link:hover { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); transform: translateX(4px); }
.subject-link.active { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }
.subject-link span:first-child { font-size: 18px; width: 24px; text-align: center; }

.main-content { padding: calc(var(--header-height) + 28px) 28px calc(var(--footer-height) + 60px); max-width: 1400px; margin: 0 auto; transition: padding 0.3s ease; }
.page-stack { display: grid; gap: 28px; }
.page-header { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 8px; }
.page-header .back-link {
    display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 999px;
    background: var(--surface); border: 1px solid var(--line); font-size: 13px; font-weight: 500;
    color: var(--text-secondary); transition: all var(--transition);
}
.page-header .back-link:hover { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); }
.page-title { font-size: 28px; font-weight: 800; letter-spacing: -0.5px; display: flex; align-items: center; gap: 10px; }
.page-title i { color: var(--brand); }

.section-card, .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 24px; transition: all var(--transition); }
.section-card:hover, .card:hover { box-shadow: var(--shadow-md); }

.row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.col { display: flex; flex-direction: column; gap: 4px; }
label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
input[type="text"], input[type="number"], input[type="search"], input[type="url"], select, textarea {
    width: 100%; padding: 10px 14px; border-radius: var(--radius-sm); border: 1px solid var(--line);
    background: var(--surface-2); color: var(--text); font-size: 14px; transition: all var(--transition); outline: none;
}
input:focus, select:focus, textarea:focus { border-color: var(--brand); background: var(--surface); box-shadow: 0 0 0 4px var(--brand-soft); }
textarea { resize: vertical; font-family: inherit; }
input[type="file"] { padding: 8px 0; font-size: 13px; }
input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--brand); cursor: pointer; }
select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 40px; cursor: pointer;
}

.btn { padding: 10px 20px; border-radius: var(--radius-sm); font-weight: 600; border: none; cursor: pointer; transition: all var(--transition); font-size: 14px; display: inline-flex; align-items: center; gap: 8px; justify-content: center; }
.btn-primary, .btn.primary { background: var(--brand); color: #fff; }
.btn-primary:hover, .btn.primary:hover { background: var(--brand-dark); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(15, 123, 74, 0.35); }
.btn-secondary, .btn.secondary { background: var(--surface-2); border: 1px solid var(--line); color: var(--text); }
.btn-secondary:hover, .btn.secondary:hover { background: var(--surface); border-color: var(--brand); color: var(--brand); }
.btn-danger, .btn.danger { background: rgba(239, 68, 68, 0.12); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.3); }
.btn-danger:hover, .btn.danger:hover { background: rgba(239, 68, 68, 0.2); transform: translateY(-2px); }
.btn-sm { padding: 6px 14px; font-size: 12px; }

.tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--line); padding-bottom: 0; margin-bottom: 20px; }
.tab { padding: 10px 20px; border: none; background: transparent; color: var(--text-secondary); font-weight: 600; font-size: 14px; cursor: pointer; border-bottom: 3px solid transparent; transition: all var(--transition); border-radius: var(--radius-sm) var(--radius-sm) 0 0; }
.tab:hover { color: var(--text); background: var(--surface-2); }
.tab.active { color: var(--brand); border-bottom-color: var(--brand); background: var(--brand-soft); }
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.link-btn { background: none; border: none; padding: 0; color: var(--brand); font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; }
.link-btn:hover { text-decoration: underline; }

.table-wrapper { overflow-x: auto; margin-top: 8px; }
.table { width: 100%; border-collapse: collapse; font-size: 14px; }
.table th { text-align: left; padding: 12px 14px; background: var(--surface-2); font-weight: 700; color: var(--text-secondary); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--line); }
.table td { padding: 12px 14px; border-bottom: 1px solid var(--line); vertical-align: middle; }
.table tr:hover td { background: var(--surface-2); }
.table .actions { display: flex; gap: 8px; flex-wrap: wrap; }
.table .actions a { padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; transition: all var(--transition); background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--line); }
.table .actions a:hover { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); }

.badge { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; background: var(--brand-soft); color: var(--brand); gap: 4px; }
.badge-muted { background: var(--surface-2); color: var(--muted); }
.badge-warning { background: rgba(245, 158, 11, 0.12); color: var(--warning); }

.action-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.action-bar .search-wrap { position: relative; }
.action-bar .search-wrap input { padding-left: 40px; width: 260px; }
.action-bar .search-wrap::before { content: '🔍'; position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14px; opacity: 0.5; }
.action-bar .btn-group { display: flex; gap: 8px; flex-wrap: wrap; }

.small { font-size: 12px; color: var(--muted); }

@media (max-width: 768px) {
    :root { --header-height: 64px; }
    .app-header { padding: 0 14px; }
    .main-content { padding: calc(var(--header-height) + 16px) 16px 80px; }
    .page-stack { gap: 16px; }
    .header-top-nav { display: none; }
    .brand-box { font-size: 17px; }
    .row { grid-template-columns: 1fr; gap: 12px; }
    .page-title { font-size: 22px; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .action-bar { flex-direction: column; align-items: stretch; }
    .action-bar .search-wrap input { width: 100%; }
    .table { font-size: 13px; }
    .table th, .table td { padding: 8px 10px; }
    .tabs { overflow-x: auto; flex-wrap: nowrap; gap: 0; }
    .tab { padding: 8px 14px; font-size: 13px; white-space: nowrap; }
}
</style>
<?php if (is_file(__DIR__ . '/../includes/pwa.php')) include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>

<header class="app-header">
    <div class="header-left">
        <button id="menuToggle" class="menu-toggle">☰</button>
        <a href="admin_dashboard.php" class="brand-box">MyIslam Admin</a>
        <nav class="header-top-nav">
            <?php foreach ($mainNavigation as $link): ?>
                <a class="header-top-link<?= ($link['active'] ? ' active' : '') ?>" href="<?= e($link['link']) ?>"><?= e($link['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button id="themeToggle" class="icon-btn">🌙</button>
        <?php
        $adminUser = null;
        try {
            $uStmt = $conn->prepare("SELECT id, full_name, email, profile_image FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $adminUser = $uStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* ignore */ }
        ?>
        <div class="profile-area" id="profileArea">
            <button id="profileToggle" class="profile-btn">
                <?php if (!empty($adminUser['profile_image'])): ?>
                    <img src="<?= e($adminUser['profile_image']) ?>" alt="avatar">
                <?php else: ?>
                    <span class="profile-avatar-fallback"><?= strtoupper(substr(e($adminUser['full_name'] ?? 'A'), 0, 1)) ?></span>
                <?php endif; ?>
            </button>
            <div id="profileMenu" class="profile-menu">
                <div class="profile-menu-top">
                    <?php if (!empty($adminUser['profile_image'])): ?><img src="<?= e($adminUser['profile_image']) ?>" alt="avatar"><?php else: ?><div class="profile-avatar-fallback"><?= strtoupper(substr(e($adminUser['full_name'] ?? 'A'), 0, 1)) ?></div><?php endif; ?>
                    <div>
                        <h4><?= e($adminUser['full_name'] ?? ($_SESSION['user_name'] ?? 'Admin')) ?></h4>
                        <p><?= e($adminUser['email'] ?? ($_SESSION['user_email'] ?? '')) ?></p>
                    </div>
                </div>
                <a href="admin_profile.php">Profile</a>
                <a href="admin_settings.php">Settings</a>
                <a href="../logout.php">Logout</a>
            </div>
        </div>
    </div>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-title">🛠️ Admin Panel</div>
    <div class="subject-list">
        <?php foreach ($sidebarNavigation as $item): ?>
            <a class="subject-link<?= ($item['active'] ? ' active' : '') ?>" href="<?= e($item['link']) ?>"><span><?= e($item['icon']) ?></span><span><?= e($item['name']) ?></span></a>
        <?php endforeach; ?>
    </div>
</aside>

<main class="main-content">
<div class="page-stack">

    <div class="page-header">
        <div style="display:flex;align-items:center;gap:14px;">
            <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <h1 class="page-title"><i class="fas fa-question-circle"></i> Manage Q&amp;A</h1>
        </div>
        <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
            <div style="text-align:right">
                <div style="font-weight:700"><?= e($adminUser['full_name'] ?? ($_SESSION['user_name'] ?? 'Admin')) ?></div>
                <div class="small"><?= e($adminUser['email'] ?? ($_SESSION['user_email'] ?? '')) ?></div>
            </div>
            <a href="logout.php" class="btn small">Logout</a>
        </div>
    </div>

<?php
$activeAdminTab = 'items';
if (isset($_GET['edit_category']) || (($_GET['tab'] ?? '') === 'categories')) {
    $activeAdminTab = 'categories';
}
?>
    <div class="tabs" role="tablist">
        <button type="button" class="tab <?= $activeAdminTab === 'items' ? 'active' : '' ?>" data-tab="items">Questions</button>
        <button type="button" class="tab <?= $activeAdminTab === 'categories' ? 'active' : '' ?>" data-tab="categories">Categories</button>
    </div>

    <div id="panel-items" class="tab-panel <?= $activeAdminTab === 'items' ? 'active' : '' ?>">
    <div class="action-bar">
        <div class="btn-group">
            <button class="btn btn-primary" id="addItemBtn"><i class="fas fa-plus"></i> Add New Question</button>
            <a href="admin_qa.php" class="btn btn-secondary"><i class="fas fa-sync-alt"></i> Refresh</a>
        </div>
        <div>
            <input type="search" id="searchItems" class="form-control" placeholder="Search questions..." style="width:260px">
        </div>
    </div>

    <h2 style="margin-top:0;margin-bottom:12px"><?= e($editing ? 'Edit Question' : 'Create Question') ?></h2>

    <div class="card">
        <?php if (!empty($errors)): ?>
            <div style="background:#fee2e2;color:#b91c1c;padding:10px;border-radius:8px;margin-bottom:8px">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#dcfce7;color:#166534;padding:10px;border-radius:8px;margin-bottom:8px"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">

            <div class="row">
                <div class="col">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="0">— Uncategorized —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= isset($editing['category_id']) && (int)$editing['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col">
                    <label>Sort order</label>
                    <input name="sort_order" type="number" value="<?= e($editing['sort_order'] ?? 0) ?>">
                </div>
            </div>

            <div style="margin-top:8px" class="row">
                <div class="col">
                    <label>Question (English) *</label>
                    <textarea name="question" rows="2" required><?= e($editing['question'] ?? '') ?></textarea>
                </div>
                <div class="col">
                    <label>Question (Swahili)</label>
                    <textarea name="question_sw" rows="2"><?= e($editing['question_sw'] ?? '') ?></textarea>
                </div>
            </div>

            <div style="margin-top:8px" class="row">
                <div class="col">
                    <label>Answer (English) *</label>
                    <textarea name="answer" rows="4" required><?= e($editing['answer'] ?? '') ?></textarea>
                </div>
                <div class="col">
                    <label>Answer (Swahili)</label>
                    <textarea name="answer_sw" rows="4"><?= e($editing['answer_sw'] ?? '') ?></textarea>
                </div>
            </div>

            <?php $currentRefType = $editing['reference_type'] ?? 'ulamaa'; ?>
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                <div class="row">
                    <div class="col">
                        <label>Reference Type</label>
                        <select name="reference_type" id="referenceTypeSelect">
                            <option value="ulamaa" <?= $currentRefType === 'ulamaa' ? 'selected' : '' ?>>🎓 Ulamaa (Scholar)</option>
                            <option value="book" <?= $currentRefType === 'book' ? 'selected' : '' ?>>📖 Book</option>
                        </select>
                    </div>
                    <div class="col">
                        <label>Source citation <span class="small">(e.g. "Sahih al-Bukhari 1234", "Majmu al-Fatawa 12/45")</span></label>
                        <input name="source_reference" value="<?= e($editing['source_reference'] ?? '') ?>">
                    </div>
                </div>

                <div id="bookReferenceFields" class="row" style="margin-top:8px">
                    <div class="col">
                        <label>Book title</label>
                        <input name="book_title" value="<?= e($editing['book_title'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label>Author</label>
                        <input name="book_author" value="<?= e($editing['book_author'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label>Page / chapter reference</label>
                        <input name="book_page_reference" placeholder="e.g. Vol 2, p. 145" value="<?= e($editing['book_page_reference'] ?? '') ?>">
                    </div>
                </div>

                <div id="ulamaaReferenceFields" class="row" style="margin-top:8px">
                    <div class="col">
                        <label>Scholar name</label>
                        <input name="scholar_name" value="<?= e($editing['scholar_name'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label>Title / credential</label>
                        <input name="scholar_title" placeholder="e.g. Former Grand Mufti of Saudi Arabia" value="<?= e($editing['scholar_title'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);" class="row">
                <div class="col">
                    <label>Audio file (upload)</label>
                    <input type="file" name="audio_file" accept="audio/*">
                    <div class="small">Or provide external audio URL — leave both blank to keep this question text-only</div>
                    <input name="audio_url" value="<?= e($editing['audio_url'] ?? '') ?>">
                </div>
                <div class="col">
                    <label>Video file (upload)</label>
                    <input type="file" name="video_file" accept="video/*">
                    <div class="small">Or provide external video URL (YouTube link)</div>
                    <input name="video_url" value="<?= e($editing['video_url'] ?? '') ?>">
                </div>
            </div>

            <div style="margin-top:8px;display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                <label style="margin:0;display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" <?= (!$editing || !empty($editing['is_active'])) ? 'checked' : '' ?>> Active</label>
                <label style="margin:0;display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_featured" <?= !empty($editing['is_featured']) ? 'checked' : '' ?>> Featured</label>
                <button class="btn btn-primary" type="submit">Save</button>
                <?php if ($editing): ?><a href="admin_qa.php?tab=items" class="btn btn-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Existing Questions</h3>

        <form method="get" style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="items">
            <select name="filter_category" onchange="this.form.submit()" style="width:auto;">
                <option value="0">All Categories</option>
                <?php foreach ($allCategories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= $filterCategory === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_reference_type" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Reference Types</option>
                <option value="book" <?= $filterRefType === 'book' ? 'selected' : '' ?>>📖 Book</option>
                <option value="ulamaa" <?= $filterRefType === 'ulamaa' ? 'selected' : '' ?>>🎓 Ulamaa</option>
            </select>
            <select name="filter_status" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Statuses</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Published</option>
                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Draft</option>
            </select>
            <?php if ($filterCategory || $filterStatus || $filterRefType): ?>
                <a href="?tab=items" class="btn btn-secondary">Clear filters</a>
            <?php endif; ?>
        </form>

        <div class="table-wrapper">
        <table class="table" id="itemsTable">
            <thead><tr><th>ID</th><th>Question</th><th>Category</th><th>Reference</th><th>Media</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= (int)$it['id'] ?></td>
                        <td>
                            <?= e(truncateText($it['question'], 80)) ?>
                            <?php if (!empty($it['is_featured'])): ?><span class="badge badge-warning">★ Featured</span><?php endif; ?>
                        </td>
                        <td><?= e($it['category_name'] ?? '—') ?></td>
                        <td class="small">
                            <?php if ($it['reference_type'] === 'book'): ?>
                                📖 <?= e($it['book_title'] ?: 'Book') ?>
                            <?php else: ?>
                                🎓 <?= e($it['scholar_name'] ?: 'Ulamaa') ?>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= !empty($it['audio_path']) || !empty($it['audio_url']) ? '🔊' : '' ?>
                            <?= !empty($it['video_path']) || !empty($it['video_url']) ? '🎬' : '' ?>
                            <?= (empty($it['audio_path']) && empty($it['audio_url']) && empty($it['video_path']) && empty($it['video_url'])) ? '—' : '' ?>
                        </td>
                        <td><?= $it['is_active'] ? '<span class="badge">Published</span>' : '<span class="badge badge-muted">Draft</span>' ?></td>
                        <td class="actions">
                            <a href="?tab=items&edit=<?= (int)$it['id'] ?>">Edit</a>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button type="submit" class="link-btn"><?= $it['is_active'] ? 'Unpublish' : 'Publish' ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="duplicate">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button type="submit" class="link-btn">Duplicate</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this question? This cannot be undone.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button type="submit" class="link-btn" style="color:#b91c1c;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                    <tr><td colspan="7" class="small" style="text-align:center;">No questions match these filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    </div> <!-- #panel-items -->

    <div id="panel-categories" class="tab-panel <?= $activeAdminTab === 'categories' ? 'active' : '' ?>">
        <h2 style="margin-top:0;margin-bottom:12px"><?= e($editingCategory ? 'Edit Category' : 'Create Category') ?></h2>

        <div class="card">
            <?php if (!empty($catErrors)): ?>
                <div style="background:#fee2e2;color:#b91c1c;padding:10px;border-radius:8px;margin-bottom:8px">
                    <?php foreach ($catErrors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($catSuccess): ?>
                <div style="background:#dcfce7;color:#166534;padding:10px;border-radius:8px;margin-bottom:8px"><?= e($catSuccess) ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="save_category">
                <?php if ($editingCategory): ?><input type="hidden" name="cat_id" value="<?= (int)$editingCategory['id'] ?>"><?php endif; ?>

                <div class="row">
                    <div class="col">
                        <label>Name (English) *</label>
                        <input type="text" name="cat_name" required value="<?= e($editingCategory['name'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label>Name (Swahili)</label>
                        <input type="text" name="cat_name_sw" value="<?= e($editingCategory['name_sw'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <label>Slug <span class="small">(auto-generated from name if left blank)</span></label>
                        <input type="text" name="cat_slug" placeholder="e.g. aqeedah" value="<?= e($editingCategory['slug'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label>Icon <span class="small">(emoji)</span></label>
                        <input type="text" name="cat_icon" placeholder="❓" value="<?= e($editingCategory['icon'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <label>Sort Order</label>
                        <input type="number" name="cat_sort_order" value="<?= (int)($editingCategory['sort_order'] ?? 0) ?>">
                    </div>
                    <div class="col" style="justify-content:end;">
                        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="cat_is_active" style="width:auto" <?= (!$editingCategory || !empty($editingCategory['is_active'])) ? 'checked' : '' ?>> Active</label>
                    </div>
                </div>
                <div class="row" style="margin-top:8px;">
                    <div class="col">
                        <label>Description (English)</label>
                        <textarea name="cat_description" rows="2"><?= e($editingCategory['description'] ?? '') ?></textarea>
                    </div>
                    <div class="col">
                        <label>Description (Swahili)</label>
                        <textarea name="cat_description_sw" rows="2"><?= e($editingCategory['description_sw'] ?? '') ?></textarea>
                    </div>
                </div>

                <div style="margin-top:12px;display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary"><?= $editingCategory ? 'Update Category' : 'Create Category' ?></button>
                    <?php if ($editingCategory): ?><a href="admin_qa.php?tab=categories" class="btn btn-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>Existing Categories</h3>
            <div class="table-wrapper">
            <table class="table">
                <thead><tr><th>ID</th><th>Name</th><th>Slug</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($allCategories as $cat): ?>
                        <tr>
                            <td><?= (int)$cat['id'] ?></td>
                            <td><?= e($cat['icon']) ?> <?= e($cat['name']) ?></td>
                            <td class="small"><?= e($cat['slug']) ?></td>
                            <td><?= (int)$cat['sort_order'] ?></td>
                            <td><?= $cat['is_active'] ? '<span class="badge">Active</span>' : '<span class="badge badge-muted">Hidden</span>' ?></td>
                            <td class="actions">
                                <a href="?tab=categories&edit_category=<?= (int)$cat['id'] ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_category_status">
                                    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                    <button type="submit" class="link-btn"><?= $cat['is_active'] ? 'Hide' : 'Show' ?></button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this category? Questions inside it must be moved or deleted first.');">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                    <button type="submit" class="link-btn" style="color:#b91c1c;">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allCategories)): ?>
                        <tr><td colspan="6" class="small" style="text-align:center;">No categories yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div> <!-- #panel-categories -->
</div> <!-- .page-stack -->
</main>

<script>
(function() {
    const body = document.body;
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const themeToggle = document.getElementById('themeToggle');
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    const profileArea = document.getElementById('profileArea');

    function openSidebar() { sidebar?.classList.add('open'); sidebarOverlay?.classList.add('show'); body.style.overflow = 'hidden'; }
    function closeSidebar() { sidebar?.classList.remove('open'); sidebarOverlay?.classList.remove('show'); body.style.overflow = ''; }
    menuToggle?.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    sidebarOverlay?.addEventListener('click', closeSidebar);

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') { body.classList.add('dark-mode'); if (themeToggle) themeToggle.textContent = '☀'; }
    themeToggle?.addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        const isDark = body.classList.contains('dark-mode');
        themeToggle.textContent = isDark ? '☀' : '🌙';
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });

    profileToggle?.addEventListener('click', e => { e.stopPropagation(); profileMenu?.classList.toggle('show'); });
    document.addEventListener('click', e => { if (!profileArea?.contains(e.target)) profileMenu?.classList.remove('show'); });

    // Questions / Categories tab switching
    const tabButtons = document.querySelectorAll('.tab[data-tab]');
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;
            tabButtons.forEach(b => b.classList.toggle('active', b === btn));
            document.querySelectorAll('.tab-panel').forEach(panel => {
                panel.classList.toggle('active', panel.id === 'panel-' + target);
            });
            const url = new URL(window.location.href);
            url.searchParams.set('tab', target);
            history.replaceState(null, '', url);
        });
    });

    // Live search filtering on the Existing Questions table
    const searchInput = document.getElementById('searchItems');
    const itemsTable = document.getElementById('itemsTable');
    searchInput?.addEventListener('input', () => {
        const q = searchInput.value.trim().toLowerCase();
        itemsTable?.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });

    // "Add New Question" button: clear any edit state and jump to the form
    document.getElementById('addItemBtn')?.addEventListener('click', () => {
        const url = new URL(window.location.href);
        url.searchParams.delete('edit');
        url.searchParams.set('tab', 'items');
        window.location.href = url.toString();
    });

    // Reference Type: show only the field group that matches book/ulamaa
    const refTypeSelect = document.getElementById('referenceTypeSelect');
    const bookFields = document.getElementById('bookReferenceFields');
    const ulamaaFields = document.getElementById('ulamaaReferenceFields');
    function applyReferenceFieldsVisibility() {
        if (!refTypeSelect) return;
        const isBook = refTypeSelect.value === 'book';
        if (bookFields) bookFields.style.display = isBook ? '' : 'none';
        if (ulamaaFields) ulamaaFields.style.display = isBook ? 'none' : '';
    }
    refTypeSelect?.addEventListener('change', applyReferenceFieldsVisibility);
    applyReferenceFieldsVisibility();
})();
</script>

</body>
</html>