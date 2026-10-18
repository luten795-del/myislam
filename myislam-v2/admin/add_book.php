<?php
declare(strict_types=1);
session_start();

// ============================================================================
// DATABASE + HELPERS
// ============================================================================

$databaseConfigFile = __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/translations.php';

if (!file_exists($databaseConfigFile)) {
    die("Database config not found. Run the main site once first.");
}
require_once $databaseConfigFile;

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function normalizeUploadPath(?string $path): ?string {
    if (empty($path)) return null;
    if (preg_match('~^(https?:)?//~i', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function safeFilename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name);
    $name = preg_replace('/-+/', '-', (string)$name);
    return trim((string)$name, '-');
}

function flash(string $type, string $message): void {
    $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array {
    if (empty($_SESSION['admin_flash'])) return null;
    $f = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
    return $f;
}

function redirectSelf(): void {
    header('Location: add_book.php');
    exit;
}

// ============================================================================
// AUTHENTICATION + ADMIN CHECK
// ============================================================================

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    $conn = db();
    if (!$conn) throw new Exception("Database connection failed");
} catch (Throwable $e) {
    error_log("DB error: " . $e->getMessage());
    die("System error. Unable to connect to database.");
}

$dbUser = null;
try {
    $stmt = $conn->prepare("
        SELECT id, username, full_name, email, profile_image, role, is_active
        FROM users WHERE id = ? AND is_active = 1 LIMIT 1
    ");
    $stmt->execute([$userId]);
    $dbUser = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    error_log("add_book.php user error: " . $e->getMessage());
}

if (!$dbUser) {
    session_destroy();
    header('Location: ../login.php?error=account_disabled');
    exit;
}

$role = (string)($dbUser['role'] ?? 'user');
if (!in_array($role, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    die("Access denied. This page is for administrators only.");
}

$user = [
    'name'    => $dbUser['username'] ?? $dbUser['full_name'] ?? 'Admin',
    'email'   => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role'    => $role,
];

// ============================================================================
// ENSURE TABLE + UPLOAD DIRECTORIES
// ============================================================================

$tableReady = false;
try {
    $chk = $conn->query("SHOW TABLES LIKE 'library_books'");
    $tableReady = (bool)$chk->fetchColumn();

    if (!$tableReady) {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS library_books (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                author VARCHAR(255) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                cover_image VARCHAR(500) DEFAULT NULL,
                file_path VARCHAR(500) DEFAULT NULL,
                external_url VARCHAR(500) DEFAULT NULL,
                category VARCHAR(100) DEFAULT NULL,
                language VARCHAR(50) DEFAULT NULL,
                pages INT UNSIGNED DEFAULT NULL,
                published_year SMALLINT UNSIGNED DEFAULT NULL,
                status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_category (category),
                INDEX idx_status (status),
                INDEX idx_title (title)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $tableReady = true;
    }
} catch (PDOException $e) {
    error_log("add_book.php table setup: " . $e->getMessage());
    die("Unable to prepare library_books table. Check DB permissions.");
}

$uploadsRoot = dirname(__DIR__) . '/uploads';
$coversDir   = $uploadsRoot . '/covers';
$booksDir    = $uploadsRoot . '/books';
ensureDir($uploadsRoot);
ensureDir($coversDir);
ensureDir($booksDir);

// ============================================================================
// HANDLE POST: ADD / EDIT / DELETE
// ============================================================================

$action = (string)($_POST['action'] ?? '');
$editId = (int)($_POST['edit_id'] ?? 0);

// ---- DELETE ----
if ($action === 'delete' && $editId > 0) {
    try {
        // Fetch paths first so we can delete files
        $stmt = $conn->prepare("SELECT cover_image, file_path FROM library_books WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Remove local files if they are within /uploads/
            foreach (['cover_image', 'file_path'] as $field) {
                $p = (string)($row[$field] ?? '');
                if ($p === '') continue;
                $abs = dirname(__DIR__) . '/' . ltrim($p, '/');
                if (str_starts_with($abs, $uploadsRoot) && is_file($abs)) {
                    @unlink($abs);
                }
            }
            $conn->prepare("DELETE FROM library_books WHERE id = ?")->execute([$editId]);
            flash('success', 'Book deleted.');
        } else {
            flash('error', 'Book not found.');
        }
    } catch (PDOException $e) {
        error_log("add_book.php delete: " . $e->getMessage());
        flash('error', 'Could not delete book.');
    }
    redirectSelf();
}

// ---- ADD / EDIT ----
if ($action === 'save') {
    $title          = trim((string)($_POST['title'] ?? ''));
    $author         = trim((string)($_POST['author'] ?? ''));
    $description    = trim((string)($_POST['description'] ?? ''));
    $category       = trim((string)($_POST['category'] ?? ''));
    $language       = trim((string)($_POST['language'] ?? ''));
    $pages          = (int)($_POST['pages'] ?? 0);
    $publishedYear  = (int)($_POST['published_year'] ?? 0);
    $externalUrl    = trim((string)($_POST['external_url'] ?? ''));
    $status         = in_array($_POST['status'] ?? '', ['draft','published','archived'], true)
                        ? (string)$_POST['status'] : 'published';

    $errors = [];
    if ($title === '') $errors[] = 'Title is required.';
    if ($pages < 0) $errors[] = 'Pages cannot be negative.';
    if ($publishedYear < 0 || $publishedYear > 2100) $errors[] = 'Published year looks invalid.';
    if ($externalUrl !== '' && !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'External URL is not valid.';
    }

    // Load existing row for edit mode
    $existing = null;
    if ($editId > 0) {
        $stmt = $conn->prepare("SELECT * FROM library_books WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$existing) $errors[] = 'Book to edit was not found.';
    }

    // ---- Cover upload ----
    $coverImage = $existing['cover_image'] ?? null;
    if (!empty($_FILES['cover_file']['tmp_name']) && is_uploaded_file($_FILES['cover_file']['tmp_name'])) {
        $f = $_FILES['cover_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Cover upload failed (error ' . $f['error'] . ').';
        } else {
            $maxBytes = 4 * 1024 * 1024; // 4 MB
            if ((int)$f['size'] > $maxBytes) {
                $errors[] = 'Cover image must be ≤ 4 MB.';
            }
            $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!isset($allowed[$mime])) {
                $errors[] = 'Cover must be JPG, PNG, WEBP, or GIF.';
            } else {
                $base = safeFilename(pathinfo($f['name'], PATHINFO_FILENAME));
                if ($base === '') $base = 'cover';
                $fname = $base . '-' . date('YmdHis') . '.' . $allowed[$mime];
                $dest = $coversDir . '/' . $fname;
                if (move_uploaded_file($f['tmp_name'], $dest)) {
                    // Delete old cover
                    if (!empty($existing['cover_image'])) {
                        $old = dirname(__DIR__) . '/' . ltrim((string)$existing['cover_image'], '/');
                        if (str_starts_with($old, $uploadsRoot) && is_file($old)) @unlink($old);
                    }
                    $coverImage = 'uploads/covers/' . $fname;
                } else {
                    $errors[] = 'Could not save cover file.';
                }
            }
        }
    } elseif (trim((string)($_POST['cover_image_url'] ?? '')) !== '') {
        $coverImage = trim((string)$_POST['cover_image_url']);
    }

    // ---- Book file upload ----
    $filePath = $existing['file_path'] ?? null;
    if (!empty($_FILES['book_file']['tmp_name']) && is_uploaded_file($_FILES['book_file']['tmp_name'])) {
        $f = $_FILES['book_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Book upload failed (error ' . $f['error'] . ').';
        } else {
            $maxBytes = 50 * 1024 * 1024; // 50 MB
            if ((int)$f['size'] > $maxBytes) {
                $errors[] = 'Book file must be ≤ 50 MB.';
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'epub', 'mobi', 'zip'];
            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Book must be PDF, EPUB, MOBI, or ZIP.';
            } else {
                $base = safeFilename(pathinfo($f['name'], PATHINFO_FILENAME));
                if ($base === '') $base = 'book';
                $fname = $base . '-' . date('YmdHis') . '.' . $ext;
                $dest = $booksDir . '/' . $fname;
                if (move_uploaded_file($f['tmp_name'], $dest)) {
                    // Delete old file
                    if (!empty($existing['file_path'])) {
                        $old = dirname(__DIR__) . '/' . ltrim((string)$existing['file_path'], '/');
                        if (str_starts_with($old, $uploadsRoot) && is_file($old)) @unlink($old);
                    }
                    $filePath = 'uploads/books/' . $fname;
                } else {
                    $errors[] = 'Could not save book file.';
                }
            }
        }
    } elseif (trim((string)($_POST['file_path_url'] ?? '')) !== '') {
        $filePath = trim((string)$_POST['file_path_url']);
    }

    if (!$errors) {
        try {
            if ($editId > 0) {
                $sql = "
                    UPDATE library_books SET
                        title = ?, author = ?, description = ?, cover_image = ?,
                        file_path = ?, external_url = ?, category = ?, language = ?,
                        pages = ?, published_year = ?, status = ?
                    WHERE id = ?
                ";
                $conn->prepare($sql)->execute([
                    $title, $author ?: null, $description ?: null,
                    $coverImage ?: null, $filePath ?: null, $externalUrl ?: null,
                    $category ?: null, $language ?: null,
                    $pages ?: null, $publishedYear ?: null, $status, $editId,
                ]);
                flash('success', 'Book updated.');
            } else {
                $sql = "
                    INSERT INTO library_books
                        (title, author, description, cover_image, file_path,
                         external_url, category, language, pages, published_year, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                $conn->prepare($sql)->execute([
                    $title, $author ?: null, $description ?: null,
                    $coverImage ?: null, $filePath ?: null, $externalUrl ?: null,
                    $category ?: null, $language ?: null,
                    $pages ?: null, $publishedYear ?: null, $status,
                ]);
                flash('success', 'Book added.');
            }
            redirectSelf();
        } catch (PDOException $e) {
            error_log("add_book.php save: " . $e->getMessage());
            $errors[] = 'Database error while saving.';
        }
    }

    // If we reach here, we have errors — fall through to render form with values
    $_SESSION['admin_form_errors'] = $errors;
    $_SESSION['admin_form_values'] = $_POST;
    if ($editId > 0) {
        $_SESSION['admin_edit_id'] = $editId;
    }
    redirectSelf();
}

// ============================================================================
// LOAD STATE
// ============================================================================

$flash = takeFlash();
$formErrors = $_SESSION['admin_form_errors'] ?? [];
$formValues = $_SESSION['admin_form_values'] ?? [];
$editId     = (int)($_SESSION['admin_edit_id'] ?? ($_GET['edit'] ?? 0));
unset($_SESSION['admin_form_errors'], $_SESSION['admin_form_values'], $_SESSION['admin_edit_id']);

// Edit target
$editing = null;
if ($editId > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM library_books WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("add_book.php edit load: " . $e->getMessage());
    }
}

// Books list
$books = [];
try {
    $stmt = $conn->query("
        SELECT id, title, author, category, language, cover_image, status, created_at
        FROM library_books
        ORDER BY created_at DESC, id DESC
        LIMIT 200
    ");
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("add_book.php list: " . $e->getMessage());
}

// Suggested values for editing — fall back to posted values, then editing row
$v = function(string $key, string $default = '') use ($formValues, $editing) {
    if (array_key_exists($key, $formValues)) return (string)$formValues[$key];
    if ($editing && array_key_exists($key, $editing)) return (string)$editing[$key];
    return $default;
};

// Category suggestions from existing books
$categories = [];
try {
    $stmt = $conn->query("
        SELECT DISTINCT category FROM library_books
        WHERE category IS NOT NULL AND category <> ''
        ORDER BY category ASC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $editing ? 'Edit Book' : 'Add Book'; ?> - MyIslam Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f5f7f5;--surface:#fff;--surface-2:#f9fafb;--surface-3:#eef2f7;
  --line:#e2e5e2;--text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;
  --brand:#0E4A34;--brand-dark:#0A3626;--brand-soft:rgba(14,74,52,.08);
  --gold:#9C7A2E;--gold-soft:rgba(156,122,46,.10);
  --danger:#b91c1c;--danger-soft:rgba(185,28,28,.08);
  --success:#15803d;--success-soft:rgba(21,128,61,.10);
}
body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);line-height:1.5;padding:20px}
a{color:var(--brand);text-decoration:none}
a:hover{text-decoration:underline}

.adm-wrap{max-width:1200px;margin:0 auto}
.adm-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.adm-header h1{font-size:22px;font-weight:800;color:var(--brand)}
.adm-header .sub{font-size:13px;color:var(--muted);margin-top:2px}
.adm-back{padding:8px 14px;background:var(--surface);border:1px solid var(--line);border-radius:8px;font-size:13px;font-weight:700;color:var(--text)}

.adm-flash{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px}
.adm-flash.success{background:var(--success-soft);color:var(--success);border:1px solid var(--success)}
.adm-flash.error{background:var(--danger-soft);color:var(--danger);border:1px solid var(--danger)}

.adm-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:20px;align-items:start}
@media (max-width:900px){.adm-grid{grid-template-columns:1fr}}

.adm-card{background:var(--surface);border:1px solid var(--line);border-radius:10px;overflow:hidden}
.adm-card-head{padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface-2);font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;justify-content:space-between;gap:8px}
.adm-card-body{padding:16px}

.adm-field{margin-bottom:14px}
.adm-field label{display:block;font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:6px;letter-spacing:.2px}
.adm-field label .req{color:var(--danger)}
.adm-field input[type="text"],
.adm-field input[type="url"],
.adm-field input[type="number"],
.adm-field select,
.adm-field textarea{
  width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;
  background:var(--surface);color:var(--text);font-size:13px;font-family:inherit;
  transition:border-color .15s;
}
.adm-field input:focus,
.adm-field select:focus,
.adm-field textarea:focus{
  outline:none;border-color:var(--brand);
}
.adm-field textarea{min-height:80px;resize:vertical;line-height:1.5}
.adm-field .hint{font-size:11px;color:var(--muted);margin-top:4px;line-height:1.4}
.adm-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:600px){.adm-row{grid-template-columns:1fr}}
.adm-file{
  display:block;padding:10px 12px;border:1px dashed var(--line);
  border-radius:8px;background:var(--surface-2);font-size:12.5px;color:var(--text-secondary);
  cursor:pointer;transition:border-color .15s;
}
.adm-file:hover{border-color:var(--brand)}
.adm-file input{display:none}
.adm-file .adm-file-label{font-weight:700;color:var(--text)}
.adm-file .adm-file-name{font-size:11.5px;color:var(--muted);margin-top:2px}
.adm-file.has-file{border-style:solid;border-color:var(--success);background:var(--success-soft)}

.adm-actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}
.adm-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:800;border:1px solid var(--line);background:var(--surface);color:var(--text);cursor:pointer;transition:all .15s;text-decoration:none}
.adm-btn:hover{background:var(--surface-2);text-decoration:none}
.adm-btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}
.adm-btn.primary:hover{filter:brightness(1.08);background:var(--brand);color:#fff}
.adm-btn.danger{color:var(--danger);border-color:var(--danger)}
.adm-btn.danger:hover{background:var(--danger-soft)}
.adm-btn.ghost{background:transparent}

.adm-errors{background:var(--danger-soft);border:1px solid var(--danger);border-radius:8px;padding:12px 16px;margin-bottom:16px;color:var(--danger);font-size:13px}
.adm-errors ul{margin:6px 0 0 18px;padding:0}
.adm-errors li{margin:3px 0}

/* List */
.adm-list{display:flex;flex-direction:column;gap:8px}
.adm-item{display:flex;align-items:center;gap:12px;padding:10px;background:var(--surface);border:1px solid var(--line);border-radius:8px}
.adm-item:hover{border-color:var(--brand)}
.adm-item-cover{
  width:44px;height:56px;flex-shrink:0;border-radius:4px;overflow:hidden;
  background:linear-gradient(135deg,var(--brand),var(--brand-dark));
  display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;
}
.adm-item-cover img{width:100%;height:100%;object-fit:cover}
.adm-item-body{flex:1;min-width:0}
.adm-item-title{font-size:13px;font-weight:800;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.adm-item-meta{font-size:11px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.adm-item-actions{display:flex;gap:4px;flex-shrink:0}
.adm-item-actions a,
.adm-item-actions button{
  padding:5px 9px;border-radius:6px;background:var(--surface-2);border:1px solid var(--line);
  font-size:11px;font-weight:700;color:var(--text-secondary);cursor:pointer;text-decoration:none;
}
.adm-item-actions a:hover{background:var(--brand-soft);border-color:var(--brand);color:var(--brand);text-decoration:none}
.adm-item-actions button.del{color:var(--danger);border-color:rgba(185,28,28,.3)}
.adm-item-actions button.del:hover{background:var(--danger-soft);border-color:var(--danger)}

.adm-status{display:inline-block;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px}
.adm-status.published{background:var(--success-soft);color:var(--success)}
.adm-status.draft{background:var(--gold-soft);color:var(--gold)}
.adm-status.archived{background:var(--surface-3);color:var(--muted)}

.adm-empty{padding:24px;text-align:center;color:var(--muted);font-size:13px;background:var(--surface-2);border:1px dashed var(--line);border-radius:8px}
</style>
</head>
<body>
<div class="adm-wrap">

  <div class="adm-header">
    <div>
      <h1><?php echo $editing ? '✏️ Edit Book' : '📚 Add Book'; ?></h1>
      <div class="sub">Library administration · MyIslam Academy</div>
    </div>
    <a class="adm-back" href="../library.php">← View library</a>
  </div>

  <?php if ($flash): ?>
    <div class="adm-flash <?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
  <?php endif; ?>

  <?php if (!empty($formErrors)): ?>
    <div class="adm-errors">
      <strong>Please fix the following:</strong>
      <ul>
        <?php foreach ($formErrors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="adm-grid">

    <!-- LEFT: FORM -->
    <div class="adm-card">
      <div class="adm-card-head">
        <span><?php echo $editing ? 'Edit details' : 'New book details'; ?></span>
        <?php if ($editing): ?>
          <span style="font-size:11px;color:var(--muted);font-weight:600;">#<?php echo (int)$editing['id']; ?></span>
        <?php endif; ?>
      </div>
      <div class="adm-card-body">

        <form method="post" enctype="multipart/form-data" action="add_book.php">
          <input type="hidden" name="action" value="save">
          <?php if ($editing): ?>
            <input type="hidden" name="edit_id" value="<?php echo (int)$editing['id']; ?>">
          <?php endif; ?>

          <div class="adm-field">
            <label>Title <span class="req">*</span></label>
            <input type="text" name="title" value="<?php echo e($v('title')); ?>" maxlength="255" required>
          </div>

          <div class="adm-row">
            <div class="adm-field">
              <label>Author</label>
              <input type="text" name="author" value="<?php echo e($v('author')); ?>" maxlength="255">
            </div>
            <div class="adm-field">
              <label>Category</label>
              <input type="text" name="category" value="<?php echo e($v('category')); ?>" list="adm-cat-list" maxlength="100" placeholder="e.g. Tafsir, Hadith, Fiqh">
              <datalist id="adm-cat-list">
                <?php foreach ($categories as $cat): ?>
                  <option value="<?php echo e((string)$cat); ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
          </div>

          <div class="adm-field">
            <label>Description</label>
            <textarea name="description" maxlength="2000"><?php echo e($v('description')); ?></textarea>
          </div>

          <div class="adm-row">
            <div class="adm-field">
              <label>Language</label>
              <input type="text" name="language" value="<?php echo e($v('language')); ?>" maxlength="50" placeholder="e.g. English, Arabic, Kiswahili">
            </div>
            <div class="adm-field">
              <label>Pages</label>
              <input type="number" name="pages" value="<?php echo e($v('pages')); ?>" min="0" max="100000">
            </div>
          </div>

          <div class="adm-row">
            <div class="adm-field">
              <label>Published year</label>
              <input type="number" name="published_year" value="<?php echo e($v('published_year')); ?>" min="0" max="2100">
            </div>
            <div class="adm-field">
              <label>Status</label>
              <select name="status">
                <?php
                  $statusVal = $v('status', 'published');
                  foreach (['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived'] as $k => $lbl):
                ?>
                  <option value="<?php echo $k; ?>" <?php echo $statusVal === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- COVER -->
          <div class="adm-field">
            <label>Cover image</label>
            <label class="adm-file" id="coverDrop">
              <input type="file" name="cover_file" accept="image/jpeg,image/png,image/webp,image/gif" id="coverInput">
              <span class="adm-file-label">📷 Choose cover (JPG, PNG, WEBP, GIF ≤ 4 MB)</span>
              <span class="adm-file-name" id="coverName">No file selected</span>
            </label>
            <?php if ($editing && !empty($editing['cover_image'])): ?>
              <div class="hint">Current cover: <code><?php echo e((string)$editing['cover_image']); ?></code> — uploading a new file will replace it.</div>
            <?php endif; ?>
            <div class="hint" style="margin-top:6px;">Or paste a URL instead:</div>
            <input type="url" name="cover_image_url" value="<?php echo e($v('cover_image_url')); ?>" placeholder="https://example.com/cover.jpg" style="margin-top:4px;">
          </div>

          <!-- BOOK FILE -->
          <div class="adm-field">
            <label>Book file</label>
            <label class="adm-file" id="bookDrop">
              <input type="file" name="book_file" accept=".pdf,.epub,.mobi,.zip" id="bookInput">
              <span class="adm-file-label">📄 Choose book (PDF, EPUB, MOBI, ZIP ≤ 50 MB)</span>
              <span class="adm-file-name" id="bookName">No file selected</span>
            </label>
            <?php if ($editing && !empty($editing['file_path'])): ?>
              <div class="hint">Current file: <code><?php echo e((string)$editing['file_path']); ?></code> — uploading a new file will replace it.</div>
            <?php endif; ?>
            <div class="hint" style="margin-top:6px;">Or paste a URL / path instead:</div>
            <input type="url" name="file_path_url" value="<?php echo e($v('file_path_url')); ?>" placeholder="https://example.com/book.pdf" style="margin-top:4px;">
          </div>

          <!-- EXTERNAL -->
          <div class="adm-field">
            <label>External URL (optional — read online)</label>
            <input type="url" name="external_url" value="<?php echo e($v('external_url')); ?>" placeholder="https://example.com/read-online">
            <div class="hint">If set, "Read now" opens this URL instead of the uploaded file.</div>
          </div>

          <div class="adm-actions">
            <button type="submit" class="adm-btn primary">
              <?php echo $editing ? '💾 Save changes' : '➕ Add book'; ?>
            </button>
            <?php if ($editing): ?>
              <a class="adm-btn" href="add_book.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($editing): ?>
          <form method="post" action="add_book.php" onsubmit="return confirm('Delete this book permanently? This cannot be undone.');" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="edit_id" value="<?php echo (int)$editing['id']; ?>">
            <button type="submit" class="adm-btn danger">🗑️ Delete book</button>
          </form>
        <?php endif; ?>

      </div>
    </div>

    <!-- RIGHT: LIST -->
    <div class="adm-card">
      <div class="adm-card-head">
        <span>📚 Library (<strong><?php echo count($books); ?></strong>)</span>
        <?php if ($editing): ?>
          <a href="add_book.php" style="font-size:11px;font-weight:700;color:var(--brand);">+ Add new</a>
        <?php endif; ?>
      </div>
      <div class="adm-card-body">
        <?php if (empty($books)): ?>
          <div class="adm-empty">
            No books in the library yet.<br>
            Fill the form to add your first book.
          </div>
        <?php else: ?>
          <div class="adm-list">
            <?php foreach ($books as $b):
                $cover = normalizeUploadPath($b['cover_image'] ?? null);
                $status = (string)($b['status'] ?? 'published');
            ?>
              <div class="adm-item">
                <div class="adm-item-cover">
                  <?php if ($cover): ?>
                    <img src="<?php echo e($cover); ?>" alt="">
                  <?php else: ?>
                    📖
                  <?php endif; ?>
                </div>
                <div class="adm-item-body">
                  <div class="adm-item-title"><?php echo e((string)$b['title']); ?></div>
                  <div class="adm-item-meta">
                    <?php if (!empty($b['author'])): ?><?php echo e((string)$b['author']); ?> · <?php endif; ?>
                    <span class="adm-status <?php echo e($status); ?>"><?php echo e($status); ?></span>
                  </div>
                </div>
                <div class="adm-item-actions">
                  <a href="add_book.php?edit=<?php echo (int)$b['id']; ?>">Edit</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<script>
(function(){
  function wire(inputId, dropId, nameId) {
    const input = document.getElementById(inputId);
    const drop  = document.getElementById(dropId);
    const name  = document.getElementById(nameId);
    if (!input || !drop || !name) return;
    input.addEventListener('change', function(){
      if (input.files && input.files.length > 0) {
        name.textContent = input.files[0].name + ' · ' + Math.round(input.files[0].size / 1024) + ' KB';
        drop.classList.add('has-file');
      } else {
        name.textContent = 'No file selected';
        drop.classList.remove('has-file');
      }
    });
    ['dragover', 'dragenter'].forEach(ev => {
      drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = 'var(--brand)'; });
    });
    ['dragleave', 'drop'].forEach(ev => {
      drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = ''; });
    });
    drop.addEventListener('drop', function(e){
      if (e.dataTransfer.files.length > 0) {
        input.files = e.dataTransfer.files;
        input.dispatchEvent(new Event('change'));
      }
    });
  }
  wire('coverInput', 'coverDrop', 'coverName');
  wire('bookInput', 'bookDrop', 'bookName');
})();
</script>
</body>
</html>