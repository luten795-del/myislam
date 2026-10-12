<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');
require_once __DIR__ . '/../config/database.php';

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ============================================================================
// AUTH (admin only — same rule as admin_multmedia.php)
// ============================================================================

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();
if (!$conn) {
    die('Database connection error.');
}

try {
    $stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $adminRow = $stmt->fetch();
} catch (PDOException $e) {
    $adminRow = null;
}
if (!$adminRow || strtolower((string)($adminRow['role'] ?? '')) !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// TABLES (created on first run; identical to courses_schema.sql)
// ============================================================================

$schemaQueries = [
    "CREATE TABLE IF NOT EXISTS `courses` (
      `id` int NOT NULL AUTO_INCREMENT,
      `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `description` text COLLATE utf8mb4_unicode_ci,
      `duration_days` int NOT NULL DEFAULT 30,
      `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
      `created_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `slug` (`slug`),
      KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `course_items` (
      `id` int NOT NULL AUTO_INCREMENT,
      `course_id` int NOT NULL,
      `item_type` enum('content','quiz','exam') COLLATE utf8mb4_unicode_ci NOT NULL,
      `item_id` int NOT NULL,
      `unlock_day` int NOT NULL DEFAULT 1,
      `due_day` int DEFAULT NULL,
      `sort_order` int NOT NULL DEFAULT 0,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_course_item` (`course_id`,`item_type`,`item_id`),
      KEY `course_day` (`course_id`,`unlock_day`),
      CONSTRAINT `fk_course_items_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `course_cohorts` (
      `id` int NOT NULL AUTO_INCREMENT,
      `course_id` int NOT NULL,
      `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `start_date` date NOT NULL,
      `enrollment_deadline` date NOT NULL,
      `capacity` int DEFAULT NULL,
      `status` enum('draft','published','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `course_id` (`course_id`),
      KEY `start_date` (`start_date`),
      CONSTRAINT `fk_course_cohorts_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `course_enrollments` (
      `id` int NOT NULL AUTO_INCREMENT,
      `cohort_id` int NOT NULL,
      `user_id` int NOT NULL,
      `status` enum('active','completed','dropped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
      `enrolled_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `completed_at` timestamp NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_cohort_user` (`cohort_id`,`user_id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `fk_course_enrollments_cohort` FOREIGN KEY (`cohort_id`) REFERENCES `course_cohorts` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

try {
    foreach ($schemaQueries as $q) {
        $conn->exec($q);
    }
} catch (PDOException $e) {
    error_log('admin_courses schema error: ' . $e->getMessage());
    die('Could not prepare the courses tables. Check the database user permissions.');
}

// ============================================================================
// HELPERS
// ============================================================================

const COURSE_STATUSES = ['draft', 'published', 'archived'];
const COHORT_STATUSES = ['draft', 'published', 'cancelled'];
const ITEM_TABLES = ['content' => 'contents', 'quiz' => 'quizzes', 'exam' => 'monthly_exams'];
const ITEM_LABELS = ['content' => 'Lesson', 'quiz' => 'Quiz', 'exam' => 'Exam'];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function makeSlug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'course';
}

function uniqueSlug(PDO $conn, string $title, int $ignoreId = 0): string
{
    $base = makeSlug($title);
    $slug = $base;
    $n = 2;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM courses WHERE slug = ? AND id <> ?");
    while (true) {
        $stmt->execute([$slug, $ignoreId]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}

function parseDate(string $value): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$d || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $d;
}

/** Day 1 = start date, so the last day of a N-day course is start + (N-1). */
function cohortEndDate(string $startDate, int $durationDays): DateTimeImmutable
{
    return (new DateTimeImmutable($startDate))->modify('+' . max(0, $durationDays - 1) . ' days');
}

/** Returns [label, css-class] derived from the dates + the manual status. */
function cohortPhase(array $cohort, int $durationDays, DateTimeImmutable $today): array
{
    if ($cohort['status'] === 'draft') {
        return ['Draft', 'muted'];
    }
    if ($cohort['status'] === 'cancelled') {
        return ['Cancelled', 'danger'];
    }

    $start = new DateTimeImmutable($cohort['start_date']);
    $end = cohortEndDate($cohort['start_date'], $durationDays);
    $deadline = new DateTimeImmutable($cohort['enrollment_deadline']);

    if ($today > $end) {
        return ['Completed', 'muted'];
    }
    if ($today < $start) {
        return $today <= $deadline ? ['Enrolling', 'brand'] : ['Enrollment closed', 'gold'];
    }
    return $today <= $deadline ? ['Running · still enrolling', 'accent'] : ['Running', 'accent'];
}

// ============================================================================
// POST ACTIONS (Post/Redirect/Get)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Invalid or expired request token. Go back, refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    $courseIdPost = (int)($_POST['course_id'] ?? 0);
    $back = $courseIdPost > 0 ? 'admin_courses.php?course_id=' . $courseIdPost : 'admin_courses.php';

    try {
        switch ($action) {

            case 'save_course': {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $duration = (int)($_POST['duration_days'] ?? 0);
                $status = (string)($_POST['status'] ?? 'draft');

                if ($title === '' || mb_strlen($title) > 255) {
                    flash('error', 'Course title is required (max 255 characters).');
                    break;
                }
                if ($duration < 1 || $duration > 730) {
                    flash('error', 'Duration must be between 1 and 730 days.');
                    break;
                }
                if (!in_array($status, COURSE_STATUSES, true)) {
                    $status = 'draft';
                }

                if ($id > 0) {
                    $stmt = $conn->prepare("SELECT COALESCE(MAX(GREATEST(unlock_day, COALESCE(due_day, 0))), 0) FROM course_items WHERE course_id = ?");
                    $stmt->execute([$id]);
                    $maxDay = (int)$stmt->fetchColumn();
                    if ($duration < $maxDay) {
                        flash('error', "Duration can't be shorter than $maxDay days — the schedule already uses day $maxDay. Move or remove those items first.");
                        break;
                    }
                    $stmt = $conn->prepare("UPDATE courses SET title = ?, description = ?, duration_days = ?, status = ? WHERE id = ?");
                    $stmt->execute([$title, $description !== '' ? $description : null, $duration, $status, $id]);
                    flash('success', 'Course updated.');
                    $back = 'admin_courses.php?course_id=' . $id;
                } else {
                    $slug = uniqueSlug($conn, $title);
                    $stmt = $conn->prepare("INSERT INTO courses (title, slug, description, duration_days, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$title, $slug, $description !== '' ? $description : null, $duration, $status, $userId]);
                    $newId = (int)$conn->lastInsertId();
                    flash('success', 'Course created. Now add its schedule and a cohort.');
                    $back = 'admin_courses.php?course_id=' . $newId;
                }
                break;
            }

            case 'delete_course': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("
                    SELECT COUNT(*) FROM course_enrollments en
                    JOIN course_cohorts h ON h.id = en.cohort_id
                    WHERE h.course_id = ?
                ");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash('error', 'This course has enrolled students, so it cannot be deleted. Set its status to Archived instead.');
                    $back = 'admin_courses.php?course_id=' . $id;
                    break;
                }
                $conn->prepare("DELETE FROM courses WHERE id = ?")->execute([$id]);
                flash('success', 'Course deleted.');
                $back = 'admin_courses.php';
                break;
            }

            case 'add_item': {
                $type = (string)($_POST['item_type'] ?? '');
                $itemId = (int)($_POST['item_id'] ?? 0);
                $unlockDay = (int)($_POST['unlock_day'] ?? 0);
                $dueRaw = trim((string)($_POST['due_day'] ?? ''));
                $dueDay = ($dueRaw !== '' && $type !== 'content') ? (int)$dueRaw : null;

                if (!isset(ITEM_TABLES[$type]) || $itemId <= 0) {
                    flash('error', 'Choose a lesson, quiz or exam from the list.');
                    break;
                }

                $stmt = $conn->prepare("SELECT duration_days FROM courses WHERE id = ?");
                $stmt->execute([$courseIdPost]);
                $duration = $stmt->fetchColumn();
                if ($duration === false) {
                    flash('error', 'Course not found.');
                    $back = 'admin_courses.php';
                    break;
                }
                $duration = (int)$duration;

                if ($unlockDay < 1 || $unlockDay > $duration) {
                    flash('error', "Unlock day must be between 1 and $duration.");
                    break;
                }
                if ($dueDay !== null && ($dueDay < $unlockDay || $dueDay > $duration)) {
                    flash('error', "Due day must be between the unlock day ($unlockDay) and $duration.");
                    break;
                }

                $table = ITEM_TABLES[$type]; // constant map — safe to interpolate
                $stmt = $conn->prepare("SELECT COUNT(*) FROM `$table` WHERE id = ?");
                $stmt->execute([$itemId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    flash('error', 'That item no longer exists.');
                    break;
                }

                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_items WHERE course_id = ?");
                $stmt->execute([$courseIdPost]);
                $sortOrder = (int)$stmt->fetchColumn();

                try {
                    $stmt = $conn->prepare("INSERT INTO course_items (course_id, item_type, item_id, unlock_day, due_day, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$courseIdPost, $type, $itemId, $unlockDay, $dueDay, $sortOrder]);
                    flash('success', 'Added to the schedule.');
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        flash('error', 'That item is already in this course schedule.');
                    } else {
                        throw $e;
                    }
                }
                break;
            }

            case 'delete_item': {
                $itemRowId = (int)($_POST['id'] ?? 0);
                $conn->prepare("DELETE FROM course_items WHERE id = ? AND course_id = ?")->execute([$itemRowId, $courseIdPost]);
                flash('success', 'Removed from the schedule.');
                break;
            }

            case 'save_cohort': {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $start = parseDate((string)($_POST['start_date'] ?? ''));
                $deadline = parseDate((string)($_POST['enrollment_deadline'] ?? ''));
                $capacityRaw = trim((string)($_POST['capacity'] ?? ''));
                $capacity = $capacityRaw === '' ? null : (int)$capacityRaw;
                $status = (string)($_POST['status'] ?? 'draft');
                if (!in_array($status, COHORT_STATUSES, true)) {
                    $status = 'draft';
                }

                if ($name === '' || mb_strlen($name) > 255) {
                    flash('error', 'Cohort name is required.');
                    break;
                }
                if (!$start || !$deadline) {
                    flash('error', 'Enter a valid start date and enrollment deadline.');
                    break;
                }
                if ($capacity !== null && $capacity < 1) {
                    flash('error', 'Capacity must be at least 1, or left empty for unlimited.');
                    break;
                }

                $stmt = $conn->prepare("SELECT duration_days FROM courses WHERE id = ?");
                $stmt->execute([$courseIdPost]);
                $duration = $stmt->fetchColumn();
                if ($duration === false) {
                    flash('error', 'Course not found.');
                    $back = 'admin_courses.php';
                    break;
                }
                $end = cohortEndDate($start->format('Y-m-d'), (int)$duration);
                if ($deadline > $end) {
                    flash('error', 'The enrollment deadline cannot be after the cohort ends (' . $end->format('d M Y') . ').');
                    break;
                }

                if ($id > 0) {
                    if ($capacity !== null) {
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM course_enrollments WHERE cohort_id = ? AND status <> 'dropped'");
                        $stmt->execute([$id]);
                        $enrolled = (int)$stmt->fetchColumn();
                        if ($capacity < $enrolled) {
                            flash('error', "Capacity can't be below the $enrolled students already enrolled.");
                            break;
                        }
                    }
                    $stmt = $conn->prepare("UPDATE course_cohorts SET name = ?, start_date = ?, enrollment_deadline = ?, capacity = ?, status = ? WHERE id = ? AND course_id = ?");
                    $stmt->execute([$name, $start->format('Y-m-d'), $deadline->format('Y-m-d'), $capacity, $status, $id, $courseIdPost]);
                    flash('success', 'Cohort updated.');
                } else {
                    $stmt = $conn->prepare("INSERT INTO course_cohorts (course_id, name, start_date, enrollment_deadline, capacity, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$courseIdPost, $name, $start->format('Y-m-d'), $deadline->format('Y-m-d'), $capacity, $status]);
                    flash('success', 'Cohort created.');
                }
                break;
            }

            case 'set_cohort_status': {
                $id = (int)($_POST['id'] ?? 0);
                $status = (string)($_POST['status'] ?? '');
                if (in_array($status, COHORT_STATUSES, true)) {
                    $conn->prepare("UPDATE course_cohorts SET status = ? WHERE id = ? AND course_id = ?")->execute([$status, $id, $courseIdPost]);
                    flash('success', 'Cohort status changed to ' . $status . '.');
                }
                break;
            }

            case 'delete_cohort': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_enrollments WHERE cohort_id = ?");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash('error', 'This cohort has enrolled students, so it cannot be deleted. Set it to Cancelled instead.');
                    break;
                }
                $conn->prepare("DELETE FROM course_cohorts WHERE id = ? AND course_id = ?")->execute([$id, $courseIdPost]);
                flash('success', 'Cohort deleted.');
                break;
            }

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $e) {
        error_log('admin_courses error: ' . $e->getMessage());
        flash('error', 'A database error occurred. Nothing was saved.');
    }

    redirectTo($back);
}

// ============================================================================
// LOAD DATA FOR DISPLAY
// ============================================================================

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$today = new DateTimeImmutable('today');
$courseId = (int)($_GET['course_id'] ?? 0);
$course = null;

if ($courseId > 0) {
    $stmt = $conn->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$courseId]);
    $course = $stmt->fetch() ?: null;
    if (!$course) {
        flash('error', 'Course not found.');
        redirectTo('admin_courses.php');
    }
}

$courses = [];
$items = [];
$cohorts = [];
$itemOptions = ['content' => [], 'quiz' => [], 'exam' => []];

if (!$course) {
    $courses = $conn->query("
        SELECT c.*,
            (SELECT COUNT(*) FROM course_items i WHERE i.course_id = c.id) AS items_count,
            (SELECT COUNT(*) FROM course_cohorts h WHERE h.course_id = c.id) AS cohorts_count,
            (SELECT COUNT(*) FROM course_enrollments en JOIN course_cohorts h2 ON h2.id = en.cohort_id WHERE h2.course_id = c.id) AS enrolled_count
        FROM courses c
        ORDER BY c.created_at DESC, c.id DESC
    ")->fetchAll();
} else {
    $stmt = $conn->prepare("SELECT * FROM course_items WHERE course_id = ? ORDER BY unlock_day ASC, sort_order ASC, id ASC");
    $stmt->execute([$courseId]);
    $items = $stmt->fetchAll();

    // Resolve titles of the scheduled items (constant table map — safe to interpolate)
    $titles = ['content' => [], 'quiz' => [], 'exam' => []];
    foreach (ITEM_TABLES as $type => $table) {
        $ids = array_values(array_unique(array_map(
            static fn($row) => (int)$row['item_id'],
            array_filter($items, static fn($row) => $row['item_type'] === $type)
        )));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("SELECT id, title FROM `$table` WHERE id IN ($in)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $titles[$type][(int)$row['id']] = (string)$row['title'];
            }
        }
    }

    // Options for the "add to schedule" picker
    $itemOptions['content'] = $conn->query("SELECT id, title FROM contents WHERE status = 'published' ORDER BY title LIMIT 2000")->fetchAll();
    $itemOptions['quiz'] = $conn->query("SELECT id, title FROM quizzes WHERE status = 'published' ORDER BY title LIMIT 2000")->fetchAll();
    $itemOptions['exam'] = $conn->query("SELECT id, title FROM monthly_exams WHERE status = 'published' ORDER BY title LIMIT 2000")->fetchAll();

    $stmt = $conn->prepare("
        SELECT h.*,
            (SELECT COUNT(*) FROM course_enrollments en WHERE en.cohort_id = h.id AND en.status <> 'dropped') AS enrolled_count
        FROM course_cohorts h
        WHERE h.course_id = ?
        ORDER BY h.start_date DESC, h.id DESC
    ");
    $stmt->execute([$courseId]);
    $cohorts = $stmt->fetchAll();
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $course ? e($course['title']) . ' — ' : ''; ?>Courses — MyIslam Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #f5f7f5; --surface: #ffffff; --surface-2: #f9fafb; --surface-3: #eef2f7;
            --line: #e2e5e2; --text: #1f2937; --text-secondary: #4b5563; --muted: #6b7280;
            --brand: #0E4A34; --brand-light: #3E7A5E; --brand-soft: rgba(14, 74, 52, 0.08);
            --gold: #9C7A2E; --gold-soft: rgba(156, 122, 46, 0.12);
            --accent: #3E6E91; --accent-soft: rgba(62, 110, 145, 0.12);
            --danger: #b91c1c; --danger-soft: rgba(185, 28, 28, 0.08);
            --white: #ffffff;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.12);
            --radius-sm: 12px; --radius-md: 16px; --radius-lg: 20px;
        }
        body.dark-mode {
            --bg: #0f1720; --surface: #18212b; --surface-2: #1d2833; --surface-3: #22303c;
            --line: #304150; --text: #edf2f7; --text-secondary: #cbd5e1; --muted: #94a3b8;
            --brand: #4C9172; --brand-light: #7fcaa4; --brand-soft: rgba(76, 145, 114, 0.16);
            --gold: #C9A85E; --gold-soft: rgba(201, 168, 94, 0.16);
            --accent: #6FA0C4; --accent-soft: rgba(111, 160, 196, 0.16);
            --danger: #ef4444; --danger-soft: rgba(239, 68, 68, 0.12);
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg); color: var(--text); line-height: 1.5;
            padding: 24px; min-height: 100vh;
        }
        a { color: inherit; text-decoration: none; }
        button, input, select, textarea { font: inherit; color: inherit; }
        button { cursor: pointer; border: none; background: none; }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid var(--gold); outline-offset: 2px;
        }

        .container { max-width: 1200px; margin: 0 auto; display: grid; gap: 20px; }

        .page-header {
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
            padding-bottom: 16px; border-bottom: 1px solid var(--line);
        }
        .back-link { font-weight: 700; color: var(--brand); font-size: 14px; }
        .back-link:hover { color: var(--gold); }
        .page-title { font-size: clamp(22px, 4vw, 28px); font-weight: 800; flex: 1; min-width: 200px; }
        .page-title small { display: block; font-size: 13px; font-weight: 500; color: var(--muted); margin-top: 2px; }
        .icon-btn {
            width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--line);
            background: var(--surface); display: inline-flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .icon-btn:hover { border-color: var(--gold); }

        .card {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius-lg); padding: clamp(16px, 4vw, 24px);
        }
        .card h2 {
            font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 800;
            color: var(--gold); margin-bottom: 14px;
            display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
        }

        .btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px;
            border-radius: 999px; font-weight: 700; font-size: 13px;
            background: var(--surface); border: 1px solid var(--line); color: var(--text-secondary);
            transition: all .2s;
        }
        .btn:hover { border-color: var(--brand); color: var(--brand); transform: translateY(-1px); }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: var(--white); }
        .btn-primary:hover { color: var(--white); filter: brightness(1.1); }
        .btn-danger { color: var(--danger); background: var(--danger-soft); border-color: transparent; }
        .btn-danger:hover { border-color: var(--danger); color: var(--danger); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .flash { padding: 12px 16px; border-radius: var(--radius-sm); font-weight: 600; font-size: 14px; border: 1px solid transparent; }
        .flash.success { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }
        .flash.error { background: var(--danger-soft); color: var(--danger); border-color: var(--danger); }

        .table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius-md); }
        table { width: 100%; border-collapse: collapse; min-width: 640px; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--line); font-size: 14px; vertical-align: middle; }
        th { background: var(--surface-2); color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        tr:last-child td { border-bottom: none; }
        td.actions { white-space: nowrap; }
        td.actions form { display: inline; }
        .empty { text-align: center; color: var(--muted); padding: 28px 12px; }

        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .badge.brand { background: var(--brand-soft); color: var(--brand); }
        .badge.gold { background: var(--gold-soft); color: var(--gold); }
        .badge.accent { background: var(--accent-soft); color: var(--accent); }
        .badge.danger { background: var(--danger-soft); color: var(--danger); }
        .badge.muted { background: var(--surface-3); color: var(--muted); }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 4px; }
        .stat { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--gold); border-radius: var(--radius-md); padding: 12px 14px; }
        .stat span { display: block; font-size: 12px; color: var(--muted); }
        .stat strong { font-size: 20px; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; align-items: end; }
        .field { display: grid; gap: 4px; }
        .field.wide { grid-column: 1 / -1; }
        .field label { font-size: 12px; font-weight: 700; color: var(--text-secondary); }
        .field small { font-size: 11px; color: var(--muted); }
        input[type="text"], input[type="number"], input[type="date"], select, textarea {
            width: 100%; padding: 10px 12px; border-radius: 10px;
            border: 1px solid var(--line); background: var(--surface); font-size: 14px;
        }
        textarea { min-height: 84px; resize: vertical; }

        .timeline-day { font-weight: 800; color: var(--brand); white-space: nowrap; }
        .type-lesson { background: var(--brand-soft); color: var(--brand); }
        .type-quiz { background: var(--gold-soft); color: var(--gold); }
        .type-exam { background: var(--accent-soft); color: var(--accent); }

        dialog {
            border: 1px solid var(--line); border-radius: var(--radius-lg); background: var(--surface);
            color: var(--text); padding: 24px; width: min(560px, 94vw); box-shadow: var(--shadow-lg); margin: auto;
        }
        dialog::backdrop { background: rgba(15, 23, 32, 0.55); backdrop-filter: blur(3px); }
        dialog h3 { margin-bottom: 16px; font-size: 18px; }
        .dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

        @media (max-width: 640px) { body { padding: 14px; } }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">
        <a href="<?php echo $course ? 'admin_courses.php' : 'dashboard.php'; ?>" class="back-link">
            ← <?php echo $course ? 'All courses' : 'Back to Dashboard'; ?>
        </a>
        <h1 class="page-title">
            🎓 <?php echo $course ? e($course['title']) : 'Courses'; ?>
            <small><?php echo $course ? 'Schedule and cohorts' : 'Create courses, plan their schedule and open cohorts'; ?></small>
        </h1>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Toggle dark mode">🌙</button>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?php echo e($flash[0]); ?>" role="status"><?php echo e($flash[1]); ?></div>
    <?php endif; ?>

<?php if (!$course): ?>
    <!-- ======================= COURSE LIST ======================= -->
    <section class="card">
        <h2>
            <span>All courses</span>
            <button class="btn btn-primary" type="button" data-open="courseDialog">＋ New course</button>
        </h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Length</th>
                        <th>Schedule</th>
                        <th>Cohorts</th>
                        <th>Students</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$courses): ?>
                    <tr><td colspan="7" class="empty">No courses yet. Click “New course” to create the first one.</td></tr>
                <?php endif; ?>
                <?php foreach ($courses as $c):
                    $badge = $c['status'] === 'published' ? 'brand' : ($c['status'] === 'archived' ? 'muted' : 'gold');
                ?>
                    <tr>
                        <td><a href="admin_courses.php?course_id=<?php echo (int)$c['id']; ?>"><strong><?php echo e($c['title']); ?></strong></a></td>
                        <td><?php echo (int)$c['duration_days']; ?> days</td>
                        <td><?php echo (int)$c['items_count']; ?> items</td>
                        <td><?php echo (int)$c['cohorts_count']; ?></td>
                        <td><?php echo (int)$c['enrolled_count']; ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo e(ucfirst((string)$c['status'])); ?></span></td>
                        <td class="actions"><a class="btn btn-sm" href="admin_courses.php?course_id=<?php echo (int)$c['id']; ?>">Manage →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php else:
    $duration = (int)$course['duration_days'];
    $totalEnrolled = array_sum(array_map(static fn($h) => (int)$h['enrolled_count'], $cohorts));
    $statusBadge = $course['status'] === 'published' ? 'brand' : ($course['status'] === 'archived' ? 'muted' : 'gold');
?>
    <!-- ======================= COURSE DETAIL ======================= -->
    <section class="card">
        <h2>
            <span>Overview</span>
            <span>
                <button class="btn btn-sm" type="button" data-open="courseDialog" data-edit-course>✎ Edit course</button>
                <form method="post" style="display:inline" data-confirm="Delete this course and its whole schedule? This cannot be undone.">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete_course">
                    <input type="hidden" name="id" value="<?php echo (int)$course['id']; ?>">
                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            </span>
        </h2>
        <div class="stats">
            <div class="stat"><span>Status</span><strong><span class="badge <?php echo $statusBadge; ?>"><?php echo e(ucfirst((string)$course['status'])); ?></span></strong></div>
            <div class="stat"><span>Length</span><strong><?php echo $duration; ?> days</strong></div>
            <div class="stat"><span>Schedule items</span><strong><?php echo count($items); ?></strong></div>
            <div class="stat"><span>Cohorts</span><strong><?php echo count($cohorts); ?></strong></div>
            <div class="stat"><span>Students enrolled</span><strong><?php echo $totalEnrolled; ?></strong></div>
        </div>
        <?php if (!empty($course['description'])): ?>
            <p style="margin-top:12px;color:var(--text-secondary);"><?php echo nl2br(e($course['description'])); ?></p>
        <?php endif; ?>
    </section>

    <!-- Schedule -->
    <section class="card">
        <h2><span>Schedule · Day 1 = the day the cohort starts</span></h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Unlocks</th><th>Type</th><th>Item</th><th>Due</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="5" class="empty">The schedule is empty. Add lessons, quizzes and exams below.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $it):
                    $label = ITEM_LABELS[$it['item_type']] ?? $it['item_type'];
                    $typeClass = $it['item_type'] === 'content' ? 'type-lesson' : ($it['item_type'] === 'quiz' ? 'type-quiz' : 'type-exam');
                    $title = $titles[$it['item_type']][(int)$it['item_id']] ?? null;
                ?>
                    <tr>
                        <td class="timeline-day">Day <?php echo (int)$it['unlock_day']; ?></td>
                        <td><span class="badge <?php echo $typeClass; ?>"><?php echo e($label); ?></span></td>
                        <td>
                            <?php if ($title !== null): ?>
                                <?php echo e($title); ?>
                            <?php else: ?>
                                <span class="badge danger">Missing item #<?php echo (int)$it['item_id']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($it['item_type'] === 'content'): ?>
                                <span style="color:var(--muted)">—</span>
                            <?php elseif ($it['due_day'] !== null): ?>
                                Day <?php echo (int)$it['due_day']; ?>
                            <?php else: ?>
                                <span style="color:var(--muted)">Until course ends</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <form method="post" data-confirm="Remove this item from the schedule?">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                                <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" style="margin-top:18px;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_item">
            <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="itemType">Type</label>
                    <select id="itemType" name="item_type" required>
                        <option value="content">Lesson (content)</option>
                        <option value="quiz">Quiz</option>
                        <option value="exam">Exam</option>
                    </select>
                </div>
                <div class="field">
                    <label for="itemSearch">Search</label>
                    <input type="text" id="itemSearch" placeholder="Filter the list…" autocomplete="off">
                </div>
                <div class="field wide">
                    <label for="itemId">Item <small id="itemCount"></small></label>
                    <select id="itemId" name="item_id" required></select>
                </div>
                <div class="field">
                    <label for="unlockDay">Unlocks on day</label>
                    <input type="number" id="unlockDay" name="unlock_day" min="1" max="<?php echo $duration; ?>" value="1" required>
                </div>
                <div class="field" id="dueField">
                    <label for="dueDay">Due on day</label>
                    <input type="number" id="dueDay" name="due_day" min="1" max="<?php echo $duration; ?>" placeholder="Optional">
                    <small>Empty = open until the course ends</small>
                </div>
                <div class="field">
                    <button class="btn btn-primary" type="submit">＋ Add to schedule</button>
                </div>
            </div>
            <small style="display:block;margin-top:8px;color:var(--muted);">Only published lessons, quizzes and exams are listed. Nothing is copied — the schedule points at the existing items.</small>
        </form>
    </section>

    <!-- Cohorts -->
    <section class="card">
        <h2>
            <span>Cohorts</span>
            <button class="btn btn-primary" type="button" id="newCohortBtn">＋ New cohort</button>
        </h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Cohort</th><th>Dates</th><th>Enroll until</th><th>Students</th><th>Phase</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (!$cohorts): ?>
                    <tr><td colspan="6" class="empty">No cohorts yet. Create one with a start date so students can enroll.</td></tr>
                <?php endif; ?>
                <?php foreach ($cohorts as $h):
                    [$phaseLabel, $phaseClass] = cohortPhase($h, $duration, $today);
                    $endDate = cohortEndDate($h['start_date'], $duration);
                    $cohortData = [
                        'id' => (int)$h['id'],
                        'name' => $h['name'],
                        'start_date' => $h['start_date'],
                        'enrollment_deadline' => $h['enrollment_deadline'],
                        'capacity' => $h['capacity'] !== null ? (int)$h['capacity'] : '',
                        'status' => $h['status'],
                    ];
                ?>
                    <tr>
                        <td><strong><?php echo e($h['name']); ?></strong></td>
                        <td><?php echo e(date('d M Y', strtotime($h['start_date']))); ?> → <?php echo e($endDate->format('d M Y')); ?></td>
                        <td><?php echo e(date('d M Y', strtotime($h['enrollment_deadline']))); ?></td>
                        <td><?php echo (int)$h['enrolled_count']; ?><?php echo $h['capacity'] !== null ? ' / ' . (int)$h['capacity'] : ''; ?></td>
                        <td><span class="badge <?php echo $phaseClass; ?>"><?php echo e($phaseLabel); ?></span></td>
                        <td class="actions">
                            <?php if ($h['status'] === 'draft'): ?>
                                <form method="post">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="set_cohort_status">
                                    <input type="hidden" name="status" value="published">
                                    <input type="hidden" name="id" value="<?php echo (int)$h['id']; ?>">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <button class="btn btn-sm btn-primary" type="submit">Publish</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-primary" href="admin_course_cohort.php?cohort_id=<?php echo (int)$h['id']; ?>">Students &amp; schedule</a>
                            <button class="btn btn-sm" type="button" data-edit-cohort="<?php echo e(json_encode($cohortData, $jsonFlags)); ?>">Edit</button>
                            <form method="post" data-confirm="Delete this cohort?">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_cohort">
                                <input type="hidden" name="id" value="<?php echo (int)$h['id']; ?>">
                                <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <small style="display:block;margin-top:10px;color:var(--muted);">
            Draft cohorts are hidden from students. The phase (Enrolling / Running / Completed) follows the dates automatically.
        </small>
    </section>

    <!-- Cohort dialog -->
    <dialog id="cohortDialog">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_cohort">
            <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
            <input type="hidden" name="id" id="cohortId" value="0">
            <h3 id="cohortDialogTitle">New cohort</h3>
            <div class="form-grid">
                <div class="field wide">
                    <label for="cohortName">Cohort name</label>
                    <input type="text" id="cohortName" name="name" maxlength="255" placeholder="e.g. January 2027 intake" required>
                </div>
                <div class="field">
                    <label for="cohortStart">Start date (Day 1)</label>
                    <input type="date" id="cohortStart" name="start_date" required>
                </div>
                <div class="field">
                    <label for="cohortDeadline">Enroll until</label>
                    <input type="date" id="cohortDeadline" name="enrollment_deadline" required>
                </div>
                <div class="field">
                    <label for="cohortCapacity">Capacity</label>
                    <input type="number" id="cohortCapacity" name="capacity" min="1" placeholder="Unlimited">
                </div>
                <div class="field">
                    <label for="cohortStatus">Status</label>
                    <select id="cohortStatus" name="status">
                        <option value="draft">Draft (hidden)</option>
                        <option value="published">Published</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="dialog-actions">
                <button class="btn" type="button" data-close>Cancel</button>
                <button class="btn btn-primary" type="submit">Save cohort</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

    <!-- Course dialog (create / edit) -->
    <dialog id="courseDialog">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_course">
            <input type="hidden" name="id" id="courseIdField" value="0">
            <?php if ($course): ?><input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>"><?php endif; ?>
            <h3 id="courseDialogTitle">New course</h3>
            <div class="form-grid">
                <div class="field wide">
                    <label for="courseTitle">Title</label>
                    <input type="text" id="courseTitle" name="title" maxlength="255" required>
                </div>
                <div class="field wide">
                    <label for="courseDescription">Description</label>
                    <textarea id="courseDescription" name="description"></textarea>
                </div>
                <div class="field">
                    <label for="courseDuration">Length (days)</label>
                    <input type="number" id="courseDuration" name="duration_days" min="1" max="730" value="30" required>
                </div>
                <div class="field">
                    <label for="courseStatus">Status</label>
                    <select id="courseStatus" name="status">
                        <option value="draft">Draft (hidden)</option>
                        <option value="published">Published</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
            </div>
            <div class="dialog-actions">
                <button class="btn" type="button" data-close>Cancel</button>
                <button class="btn btn-primary" type="submit">Save course</button>
            </div>
        </form>
    </dialog>
</div>

<script>
(function () {
    // ---- Dark mode (same 'theme' key as the student dashboard) ----
    const body = document.body;
    const themeToggle = document.getElementById('themeToggle');
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

    // ---- Confirm before destructive forms ----
    document.querySelectorAll('form[data-confirm]').forEach(f => {
        f.addEventListener('submit', e => {
            if (!confirm(f.dataset.confirm)) e.preventDefault();
        });
    });

    // ---- Dialogs ----
    document.querySelectorAll('dialog').forEach(d => {
        d.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => d.close()));
        d.addEventListener('click', e => { if (e.target === d) d.close(); });
    });

    const courseDialog = document.getElementById('courseDialog');
    document.querySelectorAll('[data-open="courseDialog"]').forEach(btn => {
        btn.addEventListener('click', () => {
            const editing = btn.hasAttribute('data-edit-course');
            document.getElementById('courseDialogTitle').textContent = editing ? 'Edit course' : 'New course';
            <?php if ($course): ?>
            const current = <?php echo json_encode([
                'id' => (int)$course['id'],
                'title' => $course['title'],
                'description' => (string)($course['description'] ?? ''),
                'duration_days' => (int)$course['duration_days'],
                'status' => $course['status'],
            ], $jsonFlags); ?>;
            if (editing) {
                document.getElementById('courseIdField').value = current.id;
                document.getElementById('courseTitle').value = current.title;
                document.getElementById('courseDescription').value = current.description;
                document.getElementById('courseDuration').value = current.duration_days;
                document.getElementById('courseStatus').value = current.status;
            }
            <?php endif; ?>
            courseDialog.showModal();
        });
    });

    <?php if ($course): ?>
    // ---- Cohort dialog ----
    const cohortDialog = document.getElementById('cohortDialog');
    function openCohort(data) {
        document.getElementById('cohortDialogTitle').textContent = data ? 'Edit cohort' : 'New cohort';
        document.getElementById('cohortId').value = data ? data.id : 0;
        document.getElementById('cohortName').value = data ? data.name : '';
        document.getElementById('cohortStart').value = data ? data.start_date : '';
        document.getElementById('cohortDeadline').value = data ? data.enrollment_deadline : '';
        document.getElementById('cohortCapacity').value = data ? data.capacity : '';
        document.getElementById('cohortStatus').value = data ? data.status : 'draft';
        cohortDialog.showModal();
    }
    document.getElementById('newCohortBtn').addEventListener('click', () => openCohort(null));
    document.querySelectorAll('[data-edit-cohort]').forEach(btn => {
        btn.addEventListener('click', () => openCohort(JSON.parse(btn.dataset.editCohort)));
    });

    // ---- Schedule picker ----
    const options = <?php echo json_encode($itemOptions, $jsonFlags); ?>;
    const typeSel = document.getElementById('itemType');
    const itemSel = document.getElementById('itemId');
    const search = document.getElementById('itemSearch');
    const count = document.getElementById('itemCount');
    const dueField = document.getElementById('dueField');

    function renderItems() {
        const q = search.value.trim().toLowerCase();
        const list = (options[typeSel.value] || []).filter(o => !q || String(o.title).toLowerCase().includes(q));
        itemSel.innerHTML = '';
        if (!list.length) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'Nothing found';
            itemSel.appendChild(opt);
        }
        list.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.id;
            opt.textContent = o.title;
            itemSel.appendChild(opt);
        });
        count.textContent = '(' + list.length + ')';
        dueField.style.display = typeSel.value === 'content' ? 'none' : '';
    }
    typeSel.addEventListener('change', () => { search.value = ''; renderItems(); });
    search.addEventListener('input', renderItems);
    renderItems();
    <?php endif; ?>
})();
</script>
</body>
</html>