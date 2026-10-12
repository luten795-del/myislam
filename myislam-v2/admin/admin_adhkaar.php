<?php
declare(strict_types=1);

// A POST that exceeded post_max_size arrives here with an empty $_POST/$_FILES
// even though Content-Length was set -- PHP already emitted a warning for it
// before this script ran, which means output has already started. Calling
// session_start()/header() at that point only cascades into more confusing
// "headers already sent" warnings, so detect it and stop cleanly instead.
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

$conn = db();
if (!$conn) die('Database connection error.');

$userId = (int)($_SESSION['user_id'] ?? 0);

// helper: upload file
function handleUpload(array $file, string $subdir = 'adhkaar', ?string &$error = null): ?string {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['mp3','wav','ogg','m4a','mp4','webm','mov','ogg'];
    if (!in_array($ext, $allowed)) { $error = 'Unsupported file type: .' . e($ext); return null; }
    $uploadDir = __DIR__ . '/../uploads/' . $subdir . '/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        $error = 'Upload directory is not writable by the web server (' . e($uploadDir) . '). Check ownership/permissions.';
        error_log('handleUpload: unwritable dir ' . $uploadDir);
        return null;
    }
    $filename = uniqid('adhkar_', true) . '.' . $ext;
    $dest = $uploadDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/' . $subdir . '/' . $filename;
    }
    $error = 'move_uploaded_file() failed for ' . e($dest) . '. Check server permissions/disk space.';
    error_log('handleUpload: move_uploaded_file failed for ' . $dest);
    return null;
}

// load categories
$categories = [];
try {
    $stmt = $conn->query("SELECT id, name, slug FROM adhkaar_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_adhkaar: ' . $e->getMessage());
}

// handle save
$errors = [];
$success = '';
$catErrors = [];
$catSuccess = '';
$hadithErrors = [];
$hadithSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $title_sw = trim($_POST['title_sw'] ?? '');
        $virtue = trim($_POST['virtue'] ?? '');
        $virtue_sw = trim($_POST['virtue_sw'] ?? '');
        $source_reference = trim($_POST['source_reference'] ?? '');
        $sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // ------------------------------------------------------------------
        // Verses: an adhkar item can bundle MULTIPLE Arabic/translation
        // instances (see adhkaar_verses) - e.g. a sleep-adhkaar hadith that
        // groups Ikhlas + Falaq + An-Nas together, each with its own
        // repeat count. Submitted as parallel arrays, one entry per verse
        // block the admin added in the form; empty trailing blocks (from
        // "Add verse" then not filling it in) are dropped.
        // ------------------------------------------------------------------
        $verseArabic   = $_POST['verse_arabic'] ?? [];
        $verseTranslit = $_POST['verse_transliteration'] ?? [];
        $verseTransEn  = $_POST['verse_translation_en'] ?? [];
        $verseTransSw  = $_POST['verse_translation_sw'] ?? [];
        $verseRepeat   = $_POST['verse_repeat_count'] ?? [];
        $verses = [];
        for ($i = 0; $i < count($verseArabic); $i++) {
            $vArabic = trim($verseArabic[$i] ?? '');
            if ($vArabic === '') continue; // skip blank/unused verse blocks
            $verses[] = [
                'arabic_text'     => $vArabic,
                'transliteration' => trim($verseTranslit[$i] ?? ''),
                'translation_en'  => trim($verseTransEn[$i] ?? ''),
                'translation_sw'  => trim($verseTransSw[$i] ?? ''),
                'repeat_count'    => max(1, (int)($verseRepeat[$i] ?? 1)),
            ];
        }

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if (empty($categories)) {
            $errors[] = 'No active categories available. Please create a category first.';
        }
        if (empty($verses)) {
            $errors[] = 'At least one verse (with Arabic text) is required.';
        }

        // handle audio/video uploads
        $audioPath = null; $audioUrl = null; $videoPath = null; $videoUrl = null;
        if (!empty($_FILES['audio_file']['name'])) {
            $uploadErr = null;
            $res = handleUpload($_FILES['audio_file'], 'adhkaar', $uploadErr);
            if ($res) $audioPath = $res; else $errors[] = 'Audio upload failed: ' . ($uploadErr ?? 'unknown error');
        }
        if (!empty($_POST['audio_url'])) {
            $audioUrl = trim($_POST['audio_url']);
        }
        if (!empty($_FILES['video_file']['name'])) {
            $uploadErr = null;
            $res = handleUpload($_FILES['video_file'], 'adhkaar', $uploadErr);
            if ($res) $videoPath = $res; else $errors[] = 'Video upload failed: ' . ($uploadErr ?? 'unknown error');
        }
        if (!empty($_POST['video_url'])) {
            $videoUrl = trim($_POST['video_url']);
        }

        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE adhkaar SET category_id = ?, title = ?, title_sw = ?, virtue = ?, virtue_sw = ?, source_reference = ?, sort_order = ?, is_active = ?, audio_path = COALESCE(?, audio_path), audio_url = COALESCE(?, audio_url), video_path = COALESCE(?, video_path), video_url = COALESCE(?, video_url), updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$category_id, $title, $title_sw, $virtue, $virtue_sw, $source_reference, $sort_order, $is_active, $audioPath, $audioUrl, $videoPath, $videoUrl, $id]);
                    $success = 'Adhkar updated.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO adhkaar (category_id, title, title_sw, virtue, virtue_sw, source_reference, sort_order, is_active, audio_path, audio_url, video_path, video_url, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$category_id, $title, $title_sw, $virtue, $virtue_sw, $source_reference, $sort_order, $is_active, $audioPath, $audioUrl, $videoPath, $videoUrl]);
                    $id = (int)$conn->lastInsertId();
                    $success = 'Adhkar created.';
                }

                // Replace this item's verses wholesale: simpler and more
                // reliable than diffing which verse rows changed, and the
                // form always submits the item's complete, current verse
                // list anyway (not just edits).
                $conn->prepare("DELETE FROM adhkaar_verses WHERE adhkaar_id = ?")->execute([$id]);
                $insVerse = $conn->prepare("INSERT INTO adhkaar_verses (adhkaar_id, arabic_text, transliteration, translation_en, translation_sw, repeat_count, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                foreach ($verses as $vIndex => $v) {
                    $insVerse->execute([$id, $v['arabic_text'], $v['transliteration'], $v['translation_en'], $v['translation_sw'], $v['repeat_count'], $vIndex]);
                }

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'save_category') {
        $catId = isset($_POST['cat_id']) ? (int)$_POST['cat_id'] : 0;
        $name = trim($_POST['cat_name'] ?? '');
        $name_sw = trim($_POST['cat_name_sw'] ?? '');
        $slugInput = trim($_POST['cat_slug'] ?? '');
        $icon = trim($_POST['cat_icon'] ?? '') ?: '📿';
        $categoryType = trim($_POST['cat_type'] ?? 'general');
        if (!in_array($categoryType, ['morning', 'evening', 'night', 'general'], true)) {
            $categoryType = 'general';
        }
        $description = trim($_POST['cat_description'] ?? '');
        $description_sw = trim($_POST['cat_description_sw'] ?? '');
        $catSortOrder = isset($_POST['cat_sort_order']) ? (int)$_POST['cat_sort_order'] : 0;
        $catIsActive = isset($_POST['cat_is_active']) ? 1 : 0;

        if ($name === '') {
            $catErrors[] = 'Category name is required.';
        }

        // Auto-generate a URL-safe slug from the name if one wasn't provided
        $slug = $slugInput !== '' ? $slugInput : strtolower((string)$name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string)$slug, '-');

        if ($slug === '') {
            $catErrors[] = 'Could not derive a valid slug from that name — please set one manually.';
        }

        if (empty($catErrors)) {
            try {
                // Slug must be unique -- the front-end looks categories up by slug
                $dupStmt = $conn->prepare("SELECT id FROM adhkaar_categories WHERE slug = ? AND id != ?");
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
                    $stmt = $conn->prepare("UPDATE adhkaar_categories SET name = ?, name_sw = ?, slug = ?, icon = ?, category_type = ?, description = ?, description_sw = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$name, $name_sw, $slug, $icon, $categoryType, $description, $description_sw, $catSortOrder, $catIsActive, $catId]);
                    $catSuccess = 'Category updated.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO adhkaar_categories (name, name_sw, slug, icon, category_type, description, description_sw, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$name, $name_sw, $slug, $icon, $categoryType, $description, $description_sw, $catSortOrder, $catIsActive]);
                    $catSuccess = 'Category created.';
                }
                // The category dropdown on the Items tab needs to reflect new/renamed categories immediately
                $categories = $conn->query("SELECT id, name, slug FROM adhkaar_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $catErrors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
    if ($action === 'save_hadith') {
        $hadithId = isset($_POST['hadith_id']) ? (int)$_POST['hadith_id'] : 0;
        $hadithType = ($_POST['hadith_type'] ?? 'hadith') === 'ayat' ? 'ayat' : 'hadith';
        $hadithText = trim($_POST['hadith_text'] ?? '');
        $hadithTextSw = trim($_POST['hadith_text_sw'] ?? '');
        $hadithSource = trim($_POST['hadith_source'] ?? '');
        $hadithSortOrder = isset($_POST['hadith_sort_order']) ? (int)$_POST['hadith_sort_order'] : 0;
        $hadithIsActive = isset($_POST['hadith_is_active']) ? 1 : 0;

        if ($hadithText === '') {
            $hadithErrors[] = $hadithType === 'ayat' ? 'Ayat text (Arabic) is required.' : 'Hadith text (Arabic) is required.';
        }

        if (empty($hadithErrors)) {
            try {
                if ($hadithId > 0) {
                    $stmt = $conn->prepare("UPDATE hadiths SET type = ?, text = ?, text_sw = ?, source_reference = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$hadithType, $hadithText, $hadithTextSw, $hadithSource, $hadithSortOrder, $hadithIsActive, $hadithId]);
                    $hadithSuccess = ($hadithType === 'ayat' ? 'Ayat' : 'Hadith') . ' updated.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO hadiths (type, text, text_sw, source_reference, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$hadithType, $hadithText, $hadithTextSw, $hadithSource, $hadithSortOrder, $hadithIsActive]);
                    $hadithSuccess = ($hadithType === 'ayat' ? 'Ayat' : 'Hadith') . ' created.';
                }
            } catch (Throwable $e) {
                // Older installs may not have the `type` column yet (from
                // before Ayat support was added) - retry without it so
                // saving still works; it'll just be stored as a hadith.
                try {
                    if ($hadithId > 0) {
                        $stmt = $conn->prepare("UPDATE hadiths SET text = ?, text_sw = ?, source_reference = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$hadithText, $hadithTextSw, $hadithSource, $hadithSortOrder, $hadithIsActive, $hadithId]);
                        $hadithSuccess = 'Updated (run the ALTER TABLE below to enable the Ayat type).';
                    } else {
                        $stmt = $conn->prepare("INSERT INTO hadiths (text, text_sw, source_reference, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$hadithText, $hadithTextSw, $hadithSource, $hadithSortOrder, $hadithIsActive]);
                        $hadithSuccess = 'Created (run the ALTER TABLE below to enable the Ayat type).';
                    }
                } catch (Throwable $e2) {
                    $hadithErrors[] = 'Database error: ' . $e2->getMessage();
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action ?? '', ['delete', 'toggle_status', 'duplicate', 'toggle_category_status', 'delete_category', 'toggle_hadith_status', 'delete_hadith'], true)) {
    if ($action === 'delete' && isset($_POST['id'])) {
        $delId = (int)$_POST['id'];
        try {
            // Clean up any uploaded audio/video files on disk before removing the row
            $stmt = $conn->prepare("SELECT audio_path, video_path FROM adhkaar WHERE id = ?");
            $stmt->execute([$delId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                foreach ([$row['audio_path'], $row['video_path']] as $path) {
                    if ($path && is_file(__DIR__ . '/../' . $path)) {
                        @unlink(__DIR__ . '/../' . $path);
                    }
                }
            }
            $conn->prepare("DELETE FROM adhkaar WHERE id = ?")->execute([$delId]);
            $success = 'Adhkar deleted.';
        } catch (Throwable $e) {
            $errors[] = 'Could not delete: ' . $e->getMessage();
        }
    }

    if ($action === 'toggle_status' && isset($_POST['id'])) {
        try {
            $conn->prepare("UPDATE adhkaar SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            $success = 'Status updated.';
        } catch (Throwable $e) {
            $errors[] = 'Could not update status: ' . $e->getMessage();
        }
    }

    if ($action === 'duplicate' && isset($_POST['id'])) {
        try {
            $stmt = $conn->prepare("SELECT * FROM adhkaar WHERE id = ?");
            $stmt->execute([(int)$_POST['id']]);
            if ($src = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $conn->beginTransaction();
                $ins = $conn->prepare("INSERT INTO adhkaar (category_id, title, title_sw, virtue, virtue_sw, source_reference, sort_order, is_active, audio_path, audio_url, video_path, video_url, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NOW())");
                $ins->execute([
                    $src['category_id'], $src['title'] . ' (Copy)', $src['title_sw'], $src['virtue'], $src['virtue_sw'],
                    $src['source_reference'], $src['sort_order'],
                    $src['audio_path'], $src['audio_url'], $src['video_path'], $src['video_url'],
                ]);
                $newId = (int)$conn->lastInsertId();

                // Copy the original's verses across too - a duplicate should
                // be a full copy, not just the shell with no content.
                $verseStmt = $conn->prepare("SELECT arabic_text, transliteration, translation_en, translation_sw, repeat_count, sort_order FROM adhkaar_verses WHERE adhkaar_id = ? ORDER BY sort_order ASC, id ASC");
                $verseStmt->execute([(int)$src['id']]);
                $insVerse = $conn->prepare("INSERT INTO adhkaar_verses (adhkaar_id, arabic_text, transliteration, translation_en, translation_sw, repeat_count, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                foreach ($verseStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
                    $insVerse->execute([$newId, $v['arabic_text'], $v['transliteration'], $v['translation_en'], $v['translation_sw'], $v['repeat_count'], $v['sort_order']]);
                }

                $conn->commit();
                $success = 'Adhkar duplicated as a draft — edit and publish it when ready.';
            } else {
                $errors[] = 'Original adhkar not found.';
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Could not duplicate: ' . $e->getMessage();
        }
    }

    if ($action === 'toggle_category_status' && isset($_POST['id'])) {
        try {
            $conn->prepare("UPDATE adhkaar_categories SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            $catSuccess = 'Category status updated.';
        } catch (Throwable $e) {
            $catErrors[] = 'Could not update status: ' . $e->getMessage();
        }
    }

    if ($action === 'delete_category' && isset($_POST['id'])) {
        $delCatId = (int)$_POST['id'];
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM adhkaar WHERE category_id = ?");
            $stmt->execute([$delCatId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $catErrors[] = 'This category still has adhkar items in it. Move or delete them first, then delete the category.';
            } else {
                $conn->prepare("DELETE FROM adhkaar_categories WHERE id = ?")->execute([$delCatId]);
                $catSuccess = 'Category deleted.';
            }
        } catch (Throwable $e) {
            $catErrors[] = 'Could not delete: ' . $e->getMessage();
        }
    }

    if ($action === 'toggle_hadith_status' && isset($_POST['id'])) {
        try {
            $conn->prepare("UPDATE hadiths SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            $hadithSuccess = 'Hadith status updated.';
        } catch (Throwable $e) {
            $hadithErrors[] = 'Could not update status: ' . $e->getMessage();
        }
    }

    if ($action === 'delete_hadith' && isset($_POST['id'])) {
        try {
            $conn->prepare("DELETE FROM hadiths WHERE id = ?")->execute([(int)$_POST['id']]);
            $hadithSuccess = 'Hadith deleted.';
        } catch (Throwable $e) {
            $hadithErrors[] = 'Could not delete: ' . $e->getMessage();
        }
    }
}

// load edit if requested
$editing = null;
$editingVerses = [];
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    try {
        $stmt = $conn->prepare("SELECT * FROM adhkaar WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('admin_adhkaar edit fetch: ' . $e->getMessage());
    }
    if ($editing) {
        try {
            $vStmt = $conn->prepare("SELECT * FROM adhkaar_verses WHERE adhkaar_id = ? ORDER BY sort_order ASC, id ASC");
            $vStmt->execute([$editId]);
            $editingVerses = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('admin_adhkaar editing verses fetch: ' . $e->getMessage());
        }
    }
}
// A brand-new item (or one that somehow has zero verses) starts the form
// with one empty verse block instead of none, so there's always something
// to type into.
if (empty($editingVerses)) {
    $editingVerses = [['arabic_text' => '', 'transliteration' => '', 'translation_en' => '', 'translation_sw' => '', 'repeat_count' => 1]];
}

// load category being edited, if requested
$editingCategory = null;
if (isset($_GET['edit_category'])) {
    $editCatId = (int)$_GET['edit_category'];
    try {
        $stmt = $conn->prepare("SELECT * FROM adhkaar_categories WHERE id = ? LIMIT 1");
        $stmt->execute([$editCatId]);
        $editingCategory = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('admin_adhkaar edit_category fetch: ' . $e->getMessage());
    }
}

// load hadith being edited, if requested
$editingHadith = null;
if (isset($_GET['edit_hadith'])) {
    $editHadithId = (int)$_GET['edit_hadith'];
    try {
        $stmt = $conn->prepare("SELECT * FROM hadiths WHERE id = ? LIMIT 1");
        $stmt->execute([$editHadithId]);
        $editingHadith = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('admin_adhkaar edit_hadith fetch: ' . $e->getMessage());
    }
}

// full hadith list for the admin management table, in the same order the
// front-end's "hadith of the day" rotation uses (sort_order, then id)
$allHadiths = [];
$hadithsTableMissing = false;
try {
    $allHadiths = $conn->query("SELECT * FROM hadiths ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_adhkaar hadiths list: ' . $e->getMessage());
    $hadithsTableMissing = true;
}

// full category list for the admin management table (includes inactive ones,
// unlike $categories above which only has active ones for the item dropdown)
$allCategories = [];
try {
    $stmt = $conn->query("
        SELECT c.*, (SELECT COUNT(*) FROM adhkaar a WHERE a.category_id = c.id) AS item_count
        FROM adhkaar_categories c
        ORDER BY c.sort_order ASC, c.name ASC
    ");
    $allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_adhkaar categories list: ' . $e->getMessage());
}

// stats for the dashboard cards at the top of the Items panel
$adhkarStats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'total_repeats' => 0, 'category_count' => 0];
try {
    $s = $conn->query("SELECT COUNT(*) AS total, SUM(is_active) AS active, SUM(NOT is_active) AS inactive FROM adhkaar")->fetch(PDO::FETCH_ASSOC);
    $adhkarStats['total'] = (int)($s['total'] ?? 0);
    $adhkarStats['active'] = (int)($s['active'] ?? 0);
    $adhkarStats['inactive'] = (int)($s['inactive'] ?? 0);
    // Repeat counts now live per-verse (an item can have several), so the
    // dashboard total is the sum across every verse of every item.
    $adhkarStats['total_repeats'] = (int)$conn->query("SELECT COALESCE(SUM(repeat_count), 0) FROM adhkaar_verses")->fetchColumn();
    $adhkarStats['category_count'] = (int)$conn->query("SELECT COUNT(*) FROM adhkaar_categories")->fetchColumn();
} catch (Throwable $e) {
    error_log('admin_adhkaar stats: ' . $e->getMessage());
}

// ==========================================
// TRACKING DATA (admin visibility into reading/listening/watching activity)
// ==========================================
$trackingSummary = ['today_users' => 0, 'today_completions' => 0, 'week_users' => 0, 'week_completions' => 0];
$trackingByCategory = [];
$recentActivity = [];
$trackingTableMissing = false;
try {
    $s = $conn->query("
        SELECT
            COUNT(DISTINCT CASE WHEN practice_date = CURDATE() THEN user_id END) AS today_users,
            SUM(CASE WHEN practice_date = CURDATE() THEN times_completed ELSE 0 END) AS today_completions,
            COUNT(DISTINCT CASE WHEN practice_date >= CURDATE() - INTERVAL 6 DAY THEN user_id END) AS week_users,
            SUM(CASE WHEN practice_date >= CURDATE() - INTERVAL 6 DAY THEN times_completed ELSE 0 END) AS week_completions
        FROM user_adhkaar_progress
    ")->fetch(PDO::FETCH_ASSOC);
    $trackingSummary['today_users'] = (int)($s['today_users'] ?? 0);
    $trackingSummary['today_completions'] = (int)($s['today_completions'] ?? 0);
    $trackingSummary['week_users'] = (int)($s['week_users'] ?? 0);
    $trackingSummary['week_completions'] = (int)($s['week_completions'] ?? 0);

    $trackingByCategory = $conn->query("
        SELECT
            c.id, c.name, c.icon, c.category_type,
            COUNT(DISTINCT CASE WHEN p.practice_date = CURDATE() THEN p.user_id END) AS today_users,
            COUNT(DISTINCT CASE WHEN p.practice_date >= CURDATE() - INTERVAL 6 DAY THEN p.user_id END) AS week_users,
            COALESCE(SUM(CASE WHEN p.practice_date >= CURDATE() - INTERVAL 6 DAY THEN p.times_completed ELSE 0 END), 0) AS week_completions
        FROM adhkaar_categories c
        LEFT JOIN user_adhkaar_progress p ON p.category_id = c.id
        GROUP BY c.id, c.name, c.icon, c.category_type
        ORDER BY week_completions DESC, c.sort_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $recentActivity = $conn->query("
        SELECT
            p.practice_date, p.times_completed, p.read_count, p.listened, p.watched, p.last_activity_at,
            u.username, u.full_name,
            a.title AS adhkaar_title,
            c.name AS category_name, c.icon AS category_icon
        FROM user_adhkaar_progress p
        JOIN users u ON u.id = p.user_id
        JOIN adhkaar a ON a.id = p.adhkaar_id
        LEFT JOIN adhkaar_categories c ON c.id = p.category_id
        ORDER BY p.last_activity_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_adhkaar tracking: ' . $e->getMessage());
    $trackingTableMissing = true;
}

// list items -- filterable by category and status
$filterCategory = isset($_GET['filter_category']) ? (int)$_GET['filter_category'] : 0;
$filterStatus = in_array($_GET['filter_status'] ?? '', ['active', 'inactive'], true) ? $_GET['filter_status'] : '';

$items = [];
try {
    $where = [];
    $params = [];
    if ($filterCategory > 0) { $where[] = 'a.category_id = ?'; $params[] = $filterCategory; }
    if ($filterStatus === 'active') { $where[] = 'a.is_active = 1'; }
    if ($filterStatus === 'inactive') { $where[] = 'a.is_active = 0'; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $conn->prepare("
        SELECT a.id, a.title, a.is_active, a.sort_order, a.category_id, c.name as category_name,
               COUNT(v.id) AS verse_count, COALESCE(SUM(v.repeat_count), 0) AS total_repeats
        FROM adhkaar a
        LEFT JOIN adhkaar_categories c ON c.id = a.category_id
        LEFT JOIN adhkaar_verses v ON v.adhkaar_id = a.id
        {$whereSql}
        GROUP BY a.id, a.title, a.is_active, a.sort_order, a.category_id, c.name
        ORDER BY COALESCE(a.sort_order,0) ASC, a.id ASC LIMIT 500
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_adhkaar list: ' . $e->getMessage());
}


$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
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
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false, 'icon' => '📚'],
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
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin - Manage Adhkaar</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
/* ============================================
   ROOT VARIABLES
   ============================================ */
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

:root {
    /* Colors */
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

    /* Sizing */
    --header-height: 72px;
    --footer-height: 92px;
    --radius-sm: 10px;
    --radius-md: 14px;
    --radius-lg: 20px;
    --radius-xl: 28px;

    /* Transitions */
    --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ============================================
   DARK MODE
   ============================================ */
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

/* ============================================
   BASE STYLES
   ============================================ */
body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.6;
    transition: background var(--transition), color var(--transition);
}

a {
    text-decoration: none;
    color: inherit;
}

button,
input,
textarea,
select {
    font-family: inherit;
}

/* ============================================
   SCROLLBAR
   ============================================ */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}
::-webkit-scrollbar-track {
    background: var(--surface-2);
}
::-webkit-scrollbar-thumb {
    background: var(--brand);
    border-radius: 999px;
}
::-webkit-scrollbar-thumb:hover {
    background: var(--brand-dark);
}

/* ============================================
   HEADER
   ============================================ */
.app-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    height: var(--header-height);
    background: var(--header-bg);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    gap: 18px;
    transition: background var(--transition), border-color var(--transition);
}

.header-left,
.header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left {
    flex: 1;
    min-width: 0;
}

.header-top-nav {
    display: flex;
    align-items: center;
    gap: 6px;
    overflow-x: auto;
    padding: 4px 0;
    scrollbar-width: none;
}
.header-top-nav::-webkit-scrollbar {
    display: none;
}

.header-top-link {
    height: 38px;
    padding: 0 16px;
    border-radius: 999px;
    background: transparent;
    border: 1px solid transparent;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
    font-size: 13px;
    font-weight: 600;
    transition: all var(--transition);
    color: var(--text-secondary);
}
.header-top-link:hover {
    background: var(--surface);
    border-color: var(--line);
    color: var(--text);
}
.header-top-link.active {
    background: var(--surface);
    border-color: var(--brand);
    color: var(--brand);
}

/* ============================================
   HEADER BUTTONS
   ============================================ */
.menu-toggle,
.icon-btn,
.lang-btn {
    height: 40px;
    min-width: 40px;
    border-radius: var(--radius-sm);
    background: var(--surface);
    border: 1px solid var(--line);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition);
    cursor: pointer;
    color: var(--text-secondary);
    font-size: 18px;
}
.menu-toggle:hover,
.icon-btn:hover,
.lang-btn:hover {
    background: var(--brand-soft);
    border-color: var(--brand);
    color: var(--brand);
    transform: translateY(-1px);
    box-shadow: var(--shadow-sm);
}

.lang-btn {
    min-width: 52px;
    padding: 0 12px;
    font-weight: 700;
    font-size: 13px;
}

.brand-box {
    font-size: 20px;
    font-weight: 800;
    color: var(--brand);
    background: transparent;
    border: none;
    white-space: nowrap;
    letter-spacing: -0.5px;
    padding: 0 4px;
}
.brand-box:hover {
    color: var(--brand-dark);
}

/* ============================================
   PROFILE
   ============================================ */
.profile-area {
    position: relative;
}

.profile-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--surface);
    border: 2px solid var(--brand);
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--brand);
    font-weight: 700;
    transition: all var(--transition);
}
.profile-btn:hover {
    transform: scale(1.05);
    box-shadow: 0 0 0 4px var(--brand-soft);
}
.profile-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-avatar-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--brand-soft);
    color: var(--brand);
    font-weight: 700;
    font-size: 16px;
}

.profile-menu {
    position: absolute;
    top: 52px;
    right: 0;
    width: 280px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 16px;
    display: none;
    box-shadow: var(--shadow-lg);
    z-index: 1001;
    animation: slideDown 0.2s ease;
}
.profile-menu.show {
    display: block;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-8px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.profile-menu-top {
    display: flex;
    gap: 12px;
    padding-bottom: 12px;
    margin-bottom: 12px;
    border-bottom: 1px solid var(--line);
}
.profile-menu-top img,
.profile-menu-top .profile-avatar-fallback {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    flex-shrink: 0;
}
.profile-menu-top .profile-avatar-fallback {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--brand-soft);
    color: var(--brand);
    font-weight: 700;
    font-size: 18px;
}
.profile-menu-top h4 {
    font-size: 15px;
    margin-bottom: 2px;
    font-weight: 600;
}
.profile-menu-top p {
    font-size: 12px;
    color: var(--muted);
    word-break: break-all;
}

.profile-menu a {
    display: block;
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 500;
    transition: all var(--transition);
    margin: 2px 0;
}
.profile-menu a:hover {
    background: var(--brand-soft);
    color: var(--brand);
}

/* ============================================
   SIDEBAR
   ============================================ */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: var(--header-height);
    left: 0;
    width: 100%;
    height: calc(100vh - var(--header-height));
    background: rgba(0, 0, 0, 0.5);
    z-index: 1100;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    opacity: 0;
    transition: opacity 0.3s ease;
}
.sidebar-overlay.show {
    display: block;
    opacity: 1;
}

.sidebar {
    position: fixed;
    top: var(--header-height);
    left: -100%;
    width: 300px;
    height: calc(100vh - var(--header-height));
    background: var(--surface);
    border-right: 1px solid var(--line);
    padding: 24px 16px;
    z-index: 1200;
    transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow-y: auto;
}
.sidebar.open {
    left: 0;
}

.sidebar-title {
    font-size: 11px;
    color: var(--muted);
    text-transform: uppercase;
    margin-bottom: 20px;
    font-weight: 700;
    letter-spacing: 1.2px;
    padding: 0 12px;
}

.subject-list {
    display: grid;
    gap: 4px;
}

.subject-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: var(--radius-md);
    background: transparent;
    border: 1px solid transparent;
    font-size: 14px;
    font-weight: 500;
    transition: all var(--transition);
    color: var(--text-secondary);
}
.subject-link:hover {
    background: var(--brand-soft);
    border-color: var(--brand);
    color: var(--brand);
    transform: translateX(4px);
}
.subject-link.active {
    background: var(--brand-soft);
    color: var(--brand);
    border-color: var(--brand);
}
.subject-link span:first-child {
    font-size: 18px;
    width: 24px;
    text-align: center;
}

/* ============================================
   MAIN CONTENT
   ============================================ */
.main-content {
    padding: calc(var(--header-height) + 28px) 28px calc(var(--footer-height) + 60px);
    max-width: 1400px;
    margin: 0 auto;
    transition: padding 0.3s ease;
}

.page-stack {
    display: grid;
    gap: 28px;
}

/* ============================================
   PAGE HEADER
   ============================================ */
.page-header {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.page-header .back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 999px;
    background: var(--surface);
    border: 1px solid var(--line);
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    transition: all var(--transition);
}
.page-header .back-link:hover {
    background: var(--brand-soft);
    border-color: var(--brand);
    color: var(--brand);
}
.page-title {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.5px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.page-title i {
    color: var(--brand);
}

/* ============================================
   CARDS
   ============================================ */
.section-card,
.card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 24px;
    transition: all var(--transition);
}
.section-card:hover,
.card:hover {
    box-shadow: var(--shadow-md);
}

.hero-card {
    background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%);
    border: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.hero-card h1 {
    font-size: 32px;
    margin-bottom: 6px;
    font-weight: 800;
    letter-spacing: -0.5px;
}
.hero-card p {
    color: var(--text-secondary);
    font-size: 16px;
}

/* ============================================
   STATS GRID
   ============================================ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}
.stat-card {
    text-align: center;
    padding: 24px 16px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    transition: all var(--transition);
}
.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--brand);
}
.stat-value {
    font-size: 34px;
    font-weight: 800;
    color: var(--brand);
    margin-bottom: 4px;
}
.stat-label {
    font-size: 13px;
    color: var(--muted);
    font-weight: 500;
}

/* ============================================
   FORM ELEMENTS
   ============================================ */
.row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.col {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
}

input[type="text"],
input[type="number"],
input[type="search"],
input[type="url"],
select,
textarea {
    width: 100%;
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--line);
    background: var(--surface-2);
    color: var(--text);
    font-size: 14px;
    transition: all var(--transition);
    outline: none;
}
input:focus,
select:focus,
textarea:focus {
    border-color: var(--brand);
    background: var(--surface);
    box-shadow: 0 0 0 4px var(--brand-soft);
}
textarea {
    resize: vertical;
    font-family: inherit;
}
input[type="file"] {
    padding: 8px 0;
    font-size: 13px;
}
input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--brand);
    cursor: pointer;
}
select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 40px;
    cursor: pointer;
}

/* ============================================
   BUTTONS
   ============================================ */
.btn {
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all var(--transition);
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
}
.btn-primary,
.btn.primary {
    background: var(--brand);
    color: #fff;
}
.btn-primary:hover,
.btn.primary:hover {
    background: var(--brand-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(15, 123, 74, 0.35);
}
.btn-secondary,
.btn.secondary {
    background: var(--surface-2);
    border: 1px solid var(--line);
    color: var(--text);
}
.btn-secondary:hover,
.btn.secondary:hover {
    background: var(--surface);
    border-color: var(--brand);
    color: var(--brand);
}
.btn-warning,
.btn.warn {
    background: rgba(245, 158, 11, 0.12);
    color: var(--warning);
    border: 1px solid rgba(245, 158, 11, 0.3);
}
.btn-warning:hover,
.btn.warn:hover {
    background: rgba(245, 158, 11, 0.2);
    transform: translateY(-2px);
}
.btn-danger,
.btn.danger {
    background: rgba(239, 68, 68, 0.12);
    color: var(--danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
}
.btn-danger:hover,
.btn.danger:hover {
    background: rgba(239, 68, 68, 0.2);
    transform: translateY(-2px);
}
.btn-sm {
    padding: 6px 14px;
    font-size: 12px;
}
.btn-outline {
    background: transparent;
    border: 1px solid var(--line);
    color: var(--text-secondary);
}
.btn-outline:hover {
    background: var(--brand-soft);
    border-color: var(--brand);
    color: var(--brand);
}

/* ============================================
   TABS
   ============================================ */
.tabs {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid var(--line);
    padding-bottom: 0;
    margin-bottom: 20px;
}
.tab {
    padding: 10px 20px;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all var(--transition);
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
}
.tab:hover {
    color: var(--text);
    background: var(--surface-2);
}
.tab.active {
    color: var(--brand);
    border-bottom-color: var(--brand);
    background: var(--brand-soft);
}
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.link-btn {
    background: none; border: none; padding: 0; color: var(--brand); font-size: 13px;
    font-weight: 600; cursor: pointer; text-decoration: none;
}
.link-btn:hover { text-decoration: underline; }

/* ============================================
   TABLE
   ============================================ */
.table-wrapper {
    overflow-x: auto;
    margin-top: 8px;
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.table th {
    text-align: left;
    padding: 12px 14px;
    background: var(--surface-2);
    font-weight: 700;
    color: var(--text-secondary);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--line);
}
.table td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}
.table tr:hover td {
    background: var(--surface-2);
}
.table .actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.table .actions a {
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    transition: all var(--transition);
    background: var(--surface-2);
    color: var(--text-secondary);
    border: 1px solid var(--line);
}
.table .actions a:hover {
    background: var(--brand-soft);
    border-color: var(--brand);
    color: var(--brand);
}
.table .actions a:last-child:hover {
    background: rgba(239, 68, 68, 0.12);
    border-color: var(--danger);
    color: var(--danger);
}

/* ============================================
   BADGES
   ============================================ */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: var(--brand-soft);
    color: var(--brand);
    gap: 4px;
}
.badge-success {
    background: rgba(16, 185, 129, 0.12);
    color: var(--success);
}
.badge-warning {
    background: rgba(245, 158, 11, 0.12);
    color: var(--warning);
}
.badge-danger {
    background: rgba(239, 68, 68, 0.12);
    color: var(--danger);
}
.badge-muted {
    background: var(--surface-2);
    color: var(--muted);
}

/* ============================================
   ACTION BAR
   ============================================ */
.action-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}
.action-bar .search-wrap {
    position: relative;
}
.action-bar .search-wrap input {
    padding-left: 40px;
    width: 260px;
}
.action-bar .search-wrap::before {
    content: '🔍';
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    opacity: 0.5;
}
.action-bar .btn-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* ============================================
   TOAST / ALERTS
   ============================================ */
.alert {
    padding: 14px 18px;
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-error {
    background: rgba(239, 68, 68, 0.08);
    color: var(--danger);
    border: 1px solid rgba(239, 68, 68, 0.2);
}
.alert-success {
    background: rgba(16, 185, 129, 0.08);
    color: var(--success);
    border: 1px solid rgba(16, 185, 129, 0.2);
}
.alert-warning {
    background: rgba(245, 158, 11, 0.08);
    color: var(--warning);
    border: 1px solid rgba(245, 158, 11, 0.2);
}
.alert-info {
    background: rgba(59, 130, 246, 0.08);
    color: var(--accent);
    border: 1px solid rgba(59, 130, 246, 0.2);
}

/* ============================================
   EMPTY STATE
   ============================================ */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.4;
}
.empty-state h3 {
    font-size: 20px;
    margin-bottom: 8px;
    color: var(--text-secondary);
}
.empty-state p {
    font-size: 14px;
}

/* ============================================
   TOAST CONTAINER
   ============================================ */
.toast-container {
    position: fixed;
    top: calc(var(--header-height) + 16px);
    right: 16px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 380px;
    width: 100%;
}
.toast {
    padding: 14px 20px;
    border-radius: var(--radius-sm);
    background: var(--surface);
    border: 1px solid var(--line);
    box-shadow: var(--shadow-lg);
    animation: slideInRight 0.3s ease;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
}
@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(40px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}
.toast-success {
    border-left: 4px solid var(--success);
}
.toast-error {
    border-left: 4px solid var(--danger);
}
.toast-warning {
    border-left: 4px solid var(--warning);
}
.toast-info {
    border-left: 4px solid var(--accent);
}

/* ============================================
   RESPONSIVE
   ============================================ */
@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: 64px;
    }

    .app-header {
        padding: 0 14px;
    }
    .main-content {
        padding: calc(var(--header-height) + 16px) 16px 80px;
    }
    .page-stack {
        gap: 16px;
    }

    .header-top-nav {
        display: none;
    }
    .brand-box {
        font-size: 17px;
    }
    .lang-btn {
        min-width: 44px;
        padding: 0 8px;
        font-size: 12px;
    }
    .menu-toggle {
        font-size: 20px;
        min-width: 36px;
        height: 36px;
    }
    .icon-btn {
        min-width: 36px;
        height: 36px;
        font-size: 16px;
    }
    .profile-btn {
        width: 36px;
        height: 36px;
    }

    .row {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .sidebar {
        width: 280px;
        padding: 16px 12px;
    }

    .page-title {
        font-size: 22px;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .page-header .back-link {
        font-size: 12px;
    }

    .hero-card {
        flex-direction: column;
        text-align: center;
    }
    .hero-card h1 {
        font-size: 24px;
    }

    .action-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .action-bar .search-wrap input {
        width: 100%;
    }
    .action-bar .btn-group {
        justify-content: stretch;
    }
    .action-bar .btn-group .btn {
        flex: 1;
        justify-content: center;
    }

    .stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    .stat-value {
        font-size: 26px;
    }

    .section-card,
    .card {
        padding: 16px;
    }

    .table {
        font-size: 13px;
    }
    .table th,
    .table td {
        padding: 8px 10px;
    }
    .table .actions a {
        padding: 2px 10px;
        font-size: 11px;
    }

    .tabs {
        overflow-x: auto;
        flex-wrap: nowrap;
        gap: 0;
    }
    .tab {
        padding: 8px 14px;
        font-size: 13px;
        white-space: nowrap;
    }

    .profile-menu {
        right: -60px;
        width: 240px;
    }
    .toast-container {
        right: 8px;
        left: 8px;
        max-width: 100%;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .page-title {
        font-size: 19px;
    }
    .brand-box {
        font-size: 15px;
    }
    .hero-card h1 {
        font-size: 20px;
    }
    .card {
        padding: 12px;
    }
    .table th,
    .table td {
        padding: 6px 8px;
        font-size: 12px;
    }
}
</style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

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
        <button id="langToggle" class="lang-btn">EN</button>
        <div class="profile-area" id="profileArea">
            <button id="profileToggle" class="profile-btn">
                <?php if (!empty($adminUser['profile_image'])): ?>
                    <img src="<?= e($adminUser['profile_image']) ?>" alt="avatar">
                <?php else: ?>
                    <span class="profile-avatar-fallback"><?= strtoupper(substr(e($adminUser['full_name'] ?? 'A'),0,1)) ?></span>
                <?php endif; ?>
            </button>
            <div id="profileMenu" class="profile-menu">
                <div class="profile-menu-top">
                    <?php if (!empty($adminUser['profile_image'])): ?><img src="<?= e($adminUser['profile_image']) ?>" alt="avatar"><?php else: ?><div class="profile-avatar-fallback"><?= strtoupper(substr(e($adminUser['full_name'] ?? 'A'),0,1)) ?></div><?php endif; ?>
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
            <h1 class="page-title"><i class="fas fa-pray"></i> Manage Adhkaar</h1>
        </div>
        <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
            <?php
            // fetch admin user display name
            $adminUser = null;
            try {
                $uStmt = $conn->prepare("SELECT id, full_name, email, profile_image FROM users WHERE id = ? LIMIT 1");
                $uStmt->execute([$userId]);
                $adminUser = $uStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                // ignore
            }
            ?>
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
} elseif (isset($_GET['edit_hadith']) || (($_GET['tab'] ?? '') === 'hadith')) {
    $activeAdminTab = 'hadith';
} elseif (($_GET['tab'] ?? '') === 'tracking') {
    $activeAdminTab = 'tracking';
}
?>
    <div class="tabs" role="tablist">
        <button type="button" class="tab <?= $activeAdminTab === 'items' ? 'active' : '' ?>" data-tab="items">Items</button>
        <button type="button" class="tab <?= $activeAdminTab === 'categories' ? 'active' : '' ?>" data-tab="categories">Categories</button>
        <button type="button" class="tab <?= $activeAdminTab === 'hadith' ? 'active' : '' ?>" data-tab="hadith">🕌📖 Hadith / Ayat</button>
        <button type="button" class="tab <?= $activeAdminTab === 'tracking' ? 'active' : '' ?>" data-tab="tracking">📊 Tracking</button>
    </div>

    <div id="panel-items" class="tab-panel <?= $activeAdminTab === 'items' ? 'active' : '' ?>">

    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px;">
        <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:var(--brand)"><?= (int)$adhkarStats['total'] ?></div>
            <div class="small">Total Adhkar</div>
        </div>
        <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:#166534"><?= (int)$adhkarStats['active'] ?></div>
            <div class="small">Published</div>
        </div>
        <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:#9ca3af"><?= (int)$adhkarStats['inactive'] ?></div>
            <div class="small">Draft</div>
        </div>
        <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:var(--gold, #b8860b)"><?= (int)$adhkarStats['category_count'] ?></div>
            <div class="small">Categories</div>
        </div>
        <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:var(--brand)"><?= number_format((int)$adhkarStats['total_repeats']) ?></div>
            <div class="small">Total Repeats (all items)</div>
        </div>
    </div>

    <div class="action-bar" style="margin-bottom:18px;">
        <div>
            <button class="btn btn-primary" id="addItemBtn"><i class="fas fa-plus"></i> Add New Adhkar</button>
            <a href="admin_adhkaar.php" class="btn" style="background:transparent;color:var(--text-muted);border:1px solid var(--border)"><i class="fas fa-sync-alt"></i> Refresh</a>
        </div>
        <div>
            <input type="search" id="searchItems" class="form-control" placeholder="Search Adhkar..." style="width:260px">
        </div>
    </div>

    <h2 style="margin-top:0;margin-bottom:12px"><?= e($editing ? 'Edit Adhkar' : 'Create Adhkar') ?></h2>

    <div class="card">
        <h3 style="display:none">Form holder</h3>
        <?php if (!empty($errors)): ?>
            <div style="background:#fee2e2;color:#b91c1c;padding:10px;border-radius:8px;margin-bottom:8px">
                <?php foreach ($errors as $err) echo '<div>' . e($err) . '</div>'; ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#ecfdf5;color:#065f46;padding:10px;border-radius:8px;margin-bottom:8px"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">

            <div class="row">
                <div class="col">
                    <label>Category</label>
                    <select name="category_id">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= isset($editing['category_id']) && (int)$editing['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col">
                    <label>Title (EN)</label>
                    <input name="title" value="<?= e($editing['title'] ?? '') ?>">
                </div>
            </div>

            <div style="margin-top:8px">
                <label>Title (SW)</label>
                <input name="title_sw" value="<?= e($editing['title_sw'] ?? '') ?>">
            </div>

            <div style="margin-top:8px" class="row">
                <div class="col">
                    <label>Virtue / Context (EN) <span class="small">(shown once, above all verses - e.g. "The Prophet ﷺ used to say this before sleeping")</span></label>
                    <textarea name="virtue" rows="2"><?= e($editing['virtue'] ?? '') ?></textarea>
                </div>
                <div class="col">
                    <label>Virtue / Context (SW)</label>
                    <textarea name="virtue_sw" rows="2"><?= e($editing['virtue_sw'] ?? '') ?></textarea>
                </div>
            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:2px solid var(--line);">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:4px;">
                    <label style="font-size:15px;">Verses <span class="small">— one Arabic/translation instance each. Add more for items that bundle several ayat/du'a together (e.g. Ikhlas + Falaq + An-Nas under one heading).</span></label>
                    <button type="button" class="btn btn-secondary btn-sm" id="addVerseBtn">+ Add Verse</button>
                </div>

                <div id="versesContainer">
                    <?php foreach ($editingVerses as $vIndex => $verse): ?>
                        <div class="verse-block" style="margin-top:14px;padding:14px;background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius-md);">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                <strong class="verse-number small">Verse <?= $vIndex + 1 ?></strong>
                                <button type="button" class="link-btn removeVerseBtn" style="color:#b91c1c;">Remove</button>
                            </div>
                            <div>
                                <label>Arabic Text *</label>
                                <textarea name="verse_arabic[]" rows="3" dir="rtl" lang="ar" style="font-family:'Amiri','Traditional Arabic',serif;font-size:17px;"><?= e($verse['arabic_text'] ?? '') ?></textarea>
                            </div>
                            <div style="margin-top:8px" class="row">
                                <div class="col">
                                    <label>Transliteration</label>
                                    <input name="verse_transliteration[]" value="<?= e($verse['transliteration'] ?? '') ?>">
                                </div>
                                <div class="col">
                                    <label>Repeat count</label>
                                    <input name="verse_repeat_count[]" value="<?= e($verse['repeat_count'] ?? 1) ?>" type="number" min="1">
                                </div>
                            </div>
                            <div style="margin-top:8px" class="row">
                                <div class="col">
                                    <label>Translation (EN)</label>
                                    <textarea name="verse_translation_en[]" rows="2"><?= e($verse['translation_en'] ?? '') ?></textarea>
                                </div>
                                <div class="col">
                                    <label>Translation (SW)</label>
                                    <textarea name="verse_translation_sw[]" rows="2"><?= e($verse['translation_sw'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <template id="verseBlockTemplate">
                <div class="verse-block" style="margin-top:14px;padding:14px;background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius-md);">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <strong class="verse-number small">Verse</strong>
                        <button type="button" class="link-btn removeVerseBtn" style="color:#b91c1c;">Remove</button>
                    </div>
                    <div>
                        <label>Arabic Text *</label>
                        <textarea name="verse_arabic[]" rows="3" dir="rtl" lang="ar" style="font-family:'Amiri','Traditional Arabic',serif;font-size:17px;"></textarea>
                    </div>
                    <div style="margin-top:8px" class="row">
                        <div class="col">
                            <label>Transliteration</label>
                            <input name="verse_transliteration[]">
                        </div>
                        <div class="col">
                            <label>Repeat count</label>
                            <input name="verse_repeat_count[]" value="1" type="number" min="1">
                        </div>
                    </div>
                    <div style="margin-top:8px" class="row">
                        <div class="col">
                            <label>Translation (EN)</label>
                            <textarea name="verse_translation_en[]" rows="2"></textarea>
                        </div>
                        <div class="col">
                            <label>Translation (SW)</label>
                            <textarea name="verse_translation_sw[]" rows="2"></textarea>
                        </div>
                    </div>
                </div>
            </template>

            <div style="margin-top:8px" class="row">
                <div class="col">
                    <label>Audio file (upload)</label>
                    <input type="file" name="audio_file" accept="audio/*">
                    <div class="small">Or provide external audio URL</div>
                    <input name="audio_url" value="<?= e($editing['audio_url'] ?? '') ?>">
                </div>
                <div class="col">
                    <label>Video file (upload)</label>
                    <input type="file" name="video_file" accept="video/*">
                    <div class="small">Or provide external video URL (YouTube link)</div>
                    <input name="video_url" value="<?= e($editing['video_url'] ?? '') ?>">
                </div>
            </div>

            <div style="margin-top:8px" class="row">
                <div class="col">
                    <label>Source reference</label>
                    <input name="source_reference" value="<?= e($editing['source_reference'] ?? '') ?>">
                </div>
                <div class="col">
                    <label>Sort order</label>
                    <input name="sort_order" type="number" value="<?= e($editing['sort_order'] ?? 0) ?>">
                </div>
            </div>

            <div style="margin-top:8px;display:flex;gap:12px;align-items:center">
                <label style="margin:0;display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" <?= (!$editing || !empty($editing['is_active'])) ? 'checked' : '' ?>> Active</label>
                <button class="btn" type="submit">Save</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Existing Adhkaar</h3>

        <form method="get" style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="items">
            <select name="filter_category" onchange="this.form.submit()" style="width:auto;">
                <option value="0">All Categories</option>
                <?php foreach ($allCategories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= $filterCategory === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_status" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Statuses</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Published</option>
                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Draft</option>
            </select>
            <?php if ($filterCategory || $filterStatus): ?>
                <a href="?tab=items" class="btn" style="background:transparent;color:var(--text-muted);border:1px solid var(--border)">Clear filters</a>
            <?php endif; ?>
        </form>

        <table class="table" id="itemsTable">
            <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Verses</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= (int)$it['id'] ?></td>
                        <td><?= e($it['title']) ?></td>
                        <td><?= e($it['category_name'] ?? '—') ?></td>
                        <td><?= (int)$it['verse_count'] ?> <span class="small">(<?= (int)$it['total_repeats'] ?>× total)</span></td>
                        <td><?= (int)$it['sort_order'] ?></td>
                        <td><?= $it['is_active'] ? '<span class="badge">Published</span>' : '<span class="small">Draft</span>' ?></td>
                        <td class="actions" style="display:flex;gap:6px;flex-wrap:wrap;">
                            <a href="?tab=items&edit=<?= (int)$it['id'] ?>">Edit</a>
                            <form method="post" style="display:inline;" onsubmit="return true;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button type="submit" class="link-btn"><?= $it['is_active'] ? 'Unpublish' : 'Publish' ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="duplicate">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button type="submit" class="link-btn">Duplicate</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this adhkar? This cannot be undone.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button type="submit" class="link-btn" style="color:#b91c1c;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                    <tr><td colspan="7" class="small" style="text-align:center;">No adhkar match these filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
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
                        <input type="text" name="cat_slug" placeholder="e.g. morning-adhkar" value="<?= e($editingCategory['slug'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label>Icon <span class="small">(emoji)</span></label>
                        <input type="text" name="cat_icon" placeholder="📿" value="<?= e($editingCategory['icon'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <label>Time of Day <span class="small">(drives the reading theme + auto-selection)</span></label>
                        <?php $currentCatType = $editingCategory['category_type'] ?? 'general'; ?>
                        <select name="cat_type">
                            <option value="general" <?= $currentCatType === 'general' ? 'selected' : '' ?>>🕋 General (neutral theme)</option>
                            <option value="morning" <?= $currentCatType === 'morning' ? 'selected' : '' ?>>🌅 Morning (sunrise theme)</option>
                            <option value="evening" <?= $currentCatType === 'evening' ? 'selected' : '' ?>>🌇 Evening (sunset theme)</option>
                            <option value="night" <?= $currentCatType === 'night' ? 'selected' : '' ?>>🌙 Night / Sleep (moon &amp; stars theme)</option>
                        </select>
                    </div>
                    <div class="col">
                        <label>Sort Order</label>
                        <input type="number" name="cat_sort_order" value="<?= (int)($editingCategory['sort_order'] ?? 0) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col" style="justify-content:end;">
                        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="cat_is_active" style="width:auto" <?= (!$editingCategory || !empty($editingCategory['is_active'])) ? 'checked' : '' ?>> Active</label>
                    </div>
                </div>
                <div class="col">
                    <label>Description (English)</label>
                    <textarea name="cat_description" rows="2"><?= e($editingCategory['description'] ?? '') ?></textarea>
                </div>
                <div class="col">
                    <label>Description (Swahili)</label>
                    <textarea name="cat_description_sw" rows="2"><?= e($editingCategory['description_sw'] ?? '') ?></textarea>
                </div>

                <div style="margin-top:12px;display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary"><?= $editingCategory ? 'Update Category' : 'Create Category' ?></button>
                    <?php if ($editingCategory): ?><a href="admin_adhkaar.php?tab=categories" class="btn" style="background:transparent;color:var(--text-muted);border:1px solid var(--border)">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>Existing Categories</h3>
            <table class="table">
                <thead><tr><th>ID</th><th>Icon</th><th>Name</th><th>Slug</th><th>Time</th><th>Items</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php
                    $typeLabels = [
                        'morning' => '🌅 Morning',
                        'evening' => '🌇 Evening',
                        'night'   => '🌙 Night',
                        'general' => '🕋 General',
                    ];
                    ?>
                    <?php foreach ($allCategories as $cat): ?>
                        <tr>
                            <td><?= (int)$cat['id'] ?></td>
                            <td><?= e($cat['icon'] ?: '📿') ?></td>
                            <td><?= e($cat['name']) ?><?php if (!empty($cat['name_sw'])): ?><div class="small"><?= e($cat['name_sw']) ?></div><?php endif; ?></td>
                            <td><code><?= e($cat['slug']) ?></code></td>
                            <td><span class="small"><?= e($typeLabels[$cat['category_type'] ?? 'general'] ?? '🕋 General') ?></span></td>
                            <td><?= (int)$cat['item_count'] ?></td>
                            <td><?= (int)$cat['sort_order'] ?></td>
                            <td><?= $cat['is_active'] ? '<span class="badge">Active</span>' : '<span class="small">Inactive</span>' ?></td>
                            <td class="actions" style="display:flex;gap:6px;flex-wrap:wrap;">
                                <a href="?tab=categories&edit_category=<?= (int)$cat['id'] ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_category_status">
                                    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                    <button type="submit" class="link-btn"><?= $cat['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <?php if ((int)$cat['item_count'] === 0): ?>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this empty category?');">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                    <button type="submit" class="link-btn" style="color:#b91c1c;">Delete</button>
                                </form>
                                <?php else: ?>
                                    <span class="small" title="Move or delete its <?= (int)$cat['item_count'] ?> adhkar first">Delete (has items)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allCategories)): ?>
                        <tr><td colspan="9" class="small" style="text-align:center;">No categories yet — create one above.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- #panel-categories -->

    <div id="panel-hadith" class="tab-panel <?= $activeAdminTab === 'hadith' ? 'active' : '' ?>">
        <h2 style="margin-top:0;margin-bottom:4px"><?= e($editingHadith ? 'Edit Entry' : 'Add Hadith / Ayat') ?></h2>
        <p class="small" style="margin-bottom:12px;">
            One entry — a Hadith or a Qur'an Ayat — is shown on the Adhkaar page each day, right under the page title, Arabic first with its Swahili translation underneath.
            It's the same for every user and rotates automatically through all <strong>active</strong> entries below (in Sort Order, then ID) as the days pass, then wraps back to the first one.
        </p>

        <?php if ($hadithsTableMissing): ?>
            <div class="card" style="border-color:#f59e0b;background:#fffbeb;color:#92400e;">
                <strong>The <code>hadiths</code> table doesn't exist yet.</strong> Ask a developer to run this once:
                <pre style="white-space:pre-wrap;background:#fff;border:1px solid #f3d9a8;border-radius:8px;padding:10px;margin-top:8px;font-size:12px;">CREATE TABLE hadiths (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('hadith','ayat') NOT NULL DEFAULT 'hadith',
    text TEXT NOT NULL,
    text_sw TEXT NULL,
    source_reference VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL
);</pre>
            </div>
        <?php elseif (empty($allHadiths) === false && !array_key_exists('type', $allHadiths[0])): ?>
            <div class="card" style="border-color:#f59e0b;background:#fffbeb;color:#92400e;">
                <strong>Your <code>hadiths</code> table predates Ayat support.</strong> Run this once to add it (existing rows will keep working as hadiths):
                <pre style="white-space:pre-wrap;background:#fff;border:1px solid #f3d9a8;border-radius:8px;padding:10px;margin-top:8px;font-size:12px;">ALTER TABLE hadiths
    ADD COLUMN type ENUM('hadith','ayat') NOT NULL DEFAULT 'hadith' AFTER id;</pre>
            </div>
        <?php endif; ?>

        <div class="card">
            <?php if (!empty($hadithErrors)): ?>
                <div style="background:#fee2e2;color:#b91c1c;padding:10px;border-radius:8px;margin-bottom:8px">
                    <?php foreach ($hadithErrors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($hadithSuccess): ?>
                <div style="background:#dcfce7;color:#166534;padding:10px;border-radius:8px;margin-bottom:8px"><?= e($hadithSuccess) ?></div>
            <?php endif; ?>

            <?php $editingHadithType = $editingHadith['type'] ?? 'hadith'; ?>
            <form method="post">
                <input type="hidden" name="action" value="save_hadith">
                <?php if ($editingHadith): ?><input type="hidden" name="hadith_id" value="<?= (int)$editingHadith['id'] ?>"><?php endif; ?>

                <div class="col">
                    <label>Type</label>
                    <select name="hadith_type">
                        <option value="hadith" <?= $editingHadithType === 'hadith' ? 'selected' : '' ?>>🕌 Hadith</option>
                        <option value="ayat" <?= $editingHadithType === 'ayat' ? 'selected' : '' ?>>📖 Qur'an Ayat</option>
                    </select>
                </div>
                <div class="col">
                    <label>Arabic Text *</label>
                    <textarea name="hadith_text" rows="3" dir="rtl" lang="ar" style="font-family:'Amiri','Traditional Arabic',serif;font-size:18px;" required><?= e($editingHadith['text'] ?? '') ?></textarea>
                </div>
                <div class="col">
                    <label>Swahili Translation</label>
                    <textarea name="hadith_text_sw" rows="3"><?= e($editingHadith['text_sw'] ?? '') ?></textarea>
                </div>
                <div class="row">
                    <div class="col">
                        <label>Source Reference <span class="small">(e.g. Sahih Muslim 2699, or Surah Al-Baqarah 2:255)</span></label>
                        <input type="text" name="hadith_source" value="<?= e($editingHadith['source_reference'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label>Sort Order <span class="small">(controls rotation order)</span></label>
                        <input type="number" name="hadith_sort_order" value="<?= (int)($editingHadith['sort_order'] ?? 0) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col" style="justify-content:end;">
                        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="hadith_is_active" style="width:auto" <?= (!$editingHadith || !empty($editingHadith['is_active'])) ? 'checked' : '' ?>> Active (eligible for the daily rotation)</label>
                    </div>
                </div>

                <div style="margin-top:12px;display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary"><?= $editingHadith ? 'Update Entry' : 'Add Entry' ?></button>
                    <?php if ($editingHadith): ?><a href="admin_adhkaar.php?tab=hadith" class="btn" style="background:transparent;color:var(--text-muted);border:1px solid var(--border)">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>Existing Hadiths &amp; Ayat</h3>
            <?php
            // Which row is "today's" entry, for a visual indicator only -
            // must match the exact same ordering/index logic as adhkaar.php.
            $activeHadithIds = array_values(array_map(
                static fn($h) => (int)$h['id'],
                array_filter($allHadiths, static fn($h) => !empty($h['is_active']))
            ));
            $todaysHadithId = null;
            if (!empty($activeHadithIds)) {
                $todaysHadithId = $activeHadithIds[((int)date('z')) % count($activeHadithIds)];
            }
            ?>
            <table class="table">
                <thead><tr><th>ID</th><th>Type</th><th>Arabic Text</th><th>Swahili</th><th>Source</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($allHadiths as $h): ?>
                        <?php $rowType = $h['type'] ?? 'hadith'; ?>
                        <tr>
                            <td><?= (int)$h['id'] ?><?php if ($todaysHadithId === (int)$h['id']): ?><div class="badge" style="margin-top:4px;">Today</div><?php endif; ?></td>
                            <td class="small"><?= $rowType === 'ayat' ? '📖 Ayat' : '🕌 Hadith' ?></td>
                            <td style="max-width:320px;" dir="rtl" lang="ar"><?= e(mb_strimwidth((string)$h['text'], 0, 140, '…')) ?></td>
                            <td class="small" style="max-width:220px;"><?= e(mb_strimwidth((string)($h['text_sw'] ?? ''), 0, 100, '…')) ?></td>
                            <td class="small"><?= e($h['source_reference'] ?? '') ?></td>
                            <td><?= (int)$h['sort_order'] ?></td>
                            <td><?= $h['is_active'] ? '<span class="badge">Active</span>' : '<span class="small">Inactive</span>' ?></td>
                            <td class="actions" style="display:flex;gap:6px;flex-wrap:wrap;">
                                <a href="?tab=hadith&edit_hadith=<?= (int)$h['id'] ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_hadith_status">
                                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                                    <button type="submit" class="link-btn"><?= $h['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this entry?');">
                                    <input type="hidden" name="action" value="delete_hadith">
                                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                                    <button type="submit" class="link-btn" style="color:#b91c1c;">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allHadiths)): ?>
                        <tr><td colspan="8" class="small" style="text-align:center;">Nothing yet — add a hadith or ayat above.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- #panel-hadith -->

    <div id="panel-tracking" class="tab-panel <?= $activeAdminTab === 'tracking' ? 'active' : '' ?>">
        <?php if ($trackingTableMissing): ?>
            <div class="card">
                <p class="small">Tracking data isn't available yet — run <code>migration_add_adhkaar_tracking.sql</code> to create the <code>user_adhkaar_progress</code> table, then reload this page.</p>
            </div>
        <?php else: ?>
            <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;">
                <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:800;"><?= (int)$trackingSummary['today_users'] ?></div>
                    <div class="small">Active users today</div>
                </div>
                <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:800;"><?= (int)$trackingSummary['today_completions'] ?></div>
                    <div class="small">Adhkar completed today</div>
                </div>
                <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:800;"><?= (int)$trackingSummary['week_users'] ?></div>
                    <div class="small">Active users (7 days)</div>
                </div>
                <div class="stat-box" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:800;"><?= (int)$trackingSummary['week_completions'] ?></div>
                    <div class="small">Completions (7 days)</div>
                </div>
            </div>

            <div class="card">
                <h3>By Category (last 7 days)</h3>
                <table class="table">
                    <thead><tr><th>Category</th><th>Time of Day</th><th>Active Today</th><th>Active This Week</th><th>Completions This Week</th></tr></thead>
                    <tbody>
                        <?php foreach ($trackingByCategory as $row): ?>
                            <tr>
                                <td><?= e($row['icon'] ?: '📿') ?> <?= e($row['name']) ?></td>
                                <td class="small"><?= e($typeLabels[$row['category_type'] ?? 'general'] ?? '🕋 General') ?></td>
                                <td><?= (int)$row['today_users'] ?></td>
                                <td><?= (int)$row['week_users'] ?></td>
                                <td><?= (int)$row['week_completions'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($trackingByCategory)): ?>
                            <tr><td colspan="5" class="small" style="text-align:center;">No categories yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Recent Activity</h3>
                <p class="small" style="margin-bottom:10px;">Most recent 50 user/adhkar activity records — one row per user, per item, per day (repeat taps the same day update the same row rather than adding new ones).</p>
                <table class="table">
                    <thead><tr><th>User</th><th>Adhkar</th><th>Category</th><th>Date</th><th>Read</th><th>Completed</th><th>Listened</th><th>Watched</th><th>Last Activity</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentActivity as $row): ?>
                            <tr>
                                <td><?= e($row['full_name'] ?: $row['username']) ?></td>
                                <td><?= e($row['adhkaar_title']) ?></td>
                                <td class="small"><?= e(($row['category_icon'] ?? '') . ' ' . ($row['category_name'] ?? '—')) ?></td>
                                <td class="small"><?= e($row['practice_date']) ?></td>
                                <td><?= (int)$row['read_count'] ?>×</td>
                                <td><?= (int)$row['times_completed'] ?>×</td>
                                <td><?= $row['listened'] ? '🔊' : '—' ?></td>
                                <td><?= $row['watched'] ? '🎬' : '—' ?></td>
                                <td class="small"><?= e($row['last_activity_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentActivity)): ?>
                            <tr><td colspan="9" class="small" style="text-align:center;">No activity recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div> <!-- #panel-tracking -->
</div> <!-- .page-stack -->
</main>

<script>
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

    // Items / Categories tab switching
    const tabButtons = document.querySelectorAll('.tab[data-tab]');
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;
            tabButtons.forEach(b => b.classList.toggle('active', b === btn));
            document.querySelectorAll('.tab-panel').forEach(panel => {
                panel.classList.toggle('active', panel.id === 'panel-' + target);
            });
            // Keep the URL in sync so a reload / edit link stays on the right tab
            const url = new URL(window.location.href);
            url.searchParams.set('tab', target);
            history.replaceState(null, '', url);
        });
    });

    // Live search filtering on the Existing Adhkaar table (title + category columns)
    const searchInput = document.getElementById('searchItems');
    const itemsTable = document.getElementById('itemsTable');
    searchInput?.addEventListener('input', () => {
        const q = searchInput.value.trim().toLowerCase();
        itemsTable?.querySelectorAll('tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
    });

    // "Add New Adhkar" button: clear any edit state and jump to the form
    const addItemBtn = document.getElementById('addItemBtn');
    addItemBtn?.addEventListener('click', () => {
        const url = new URL(window.location.href);
        url.searchParams.delete('edit');
        url.searchParams.set('tab', 'items');
        window.location.href = url.toString();
    });

    // Verses repeater: "+ Add Verse" clones the hidden template into the
    // container; each block's "Remove" button deletes just that block.
    // At least one block must remain when the form is submitted (enforced
    // server-side too, since a block can still be removed down to zero here).
    const versesContainer = document.getElementById('versesContainer');
    const verseTemplate = document.getElementById('verseBlockTemplate');
    const addVerseBtn = document.getElementById('addVerseBtn');

    function renumberVerses() {
        versesContainer?.querySelectorAll('.verse-block').forEach((block, idx) => {
            const label = block.querySelector('.verse-number');
            if (label) label.textContent = 'Verse ' + (idx + 1);
        });
    }

    addVerseBtn?.addEventListener('click', () => {
        if (!verseTemplate || !versesContainer) return;
        const clone = verseTemplate.content.cloneNode(true);
        versesContainer.appendChild(clone);
        renumberVerses();
    });

    versesContainer?.addEventListener('click', (e) => {
        if (!e.target.classList.contains('removeVerseBtn')) return;
        const block = e.target.closest('.verse-block');
        block?.remove();
        renumberVerses();
    });
})();
</script>

</body>
</html>