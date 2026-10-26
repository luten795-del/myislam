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
// AUTH (admin only)
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
// TABLES (created on first run; safe no-op if they already exist)
// ============================================================================

$schemaQueries = [
    "CREATE TABLE IF NOT EXISTS `courses` (
      `id` int NOT NULL AUTO_INCREMENT,
      `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `description` text COLLATE utf8mb4_unicode_ci,
      `image_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `division_type` enum('none','weeks','months','terms','semesters','years','custom') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
      `division_label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `duration_days` int NOT NULL DEFAULT 30,
      `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
      `created_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `slug` (`slug`),
      KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `course_divisions` (
      `id` int NOT NULL AUTO_INCREMENT,
      `course_id` int NOT NULL,
      `number` int NOT NULL,
      `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `start_date` date NOT NULL,
      `end_date` date NOT NULL,
      `passing_score` tinyint NOT NULL DEFAULT 60,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_course_number` (`course_id`,`number`),
      KEY `idx_course_start` (`course_id`,`start_date`),
      CONSTRAINT `fk_divisions_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `course_subjects` (
      `id` int NOT NULL AUTO_INCREMENT,
      `course_id` int NOT NULL,
      `subject_id` int NOT NULL,
      `sort_order` int NOT NULL DEFAULT 0,
      `passing_score` tinyint NOT NULL DEFAULT 60,
      `weekly_weight` tinyint NOT NULL DEFAULT 14,
      `monthly_weight` tinyint NOT NULL DEFAULT 16,
      `final_weight` tinyint NOT NULL DEFAULT 70,
      `supplementary_weight` tinyint NOT NULL DEFAULT 60,
      `supplementary_max_score` tinyint NOT NULL DEFAULT 90,
      `weekly_exam_count` tinyint NOT NULL DEFAULT 7,
      `monthly_exam_count` tinyint NOT NULL DEFAULT 2,
      `final_exam_count` tinyint NOT NULL DEFAULT 1,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_course_subject` (`course_id`,`subject_id`),
      CONSTRAINT `fk_coursesubj_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_coursesubj_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `course_schedule` (
      `id` int NOT NULL AUTO_INCREMENT,
      `course_id` int NOT NULL,
      `division_id` int NOT NULL,
      `subject_id` int NOT NULL,
      `item_type` enum('content','quiz','exam') COLLATE utf8mb4_unicode_ci NOT NULL,
      `item_id` int NOT NULL,
      `week_number` int NOT NULL,
      `weekday` tinyint NOT NULL,
      `start_time` time DEFAULT NULL,
      `duration_minutes` int DEFAULT NULL,
      `sort_order` int NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_schedule_item` (`course_id`,`item_type`,`item_id`),
      KEY `idx_division_week_day` (`division_id`,`week_number`,`weekday`,`start_time`),
      CONSTRAINT `fk_schedule_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_schedule_division` FOREIGN KEY (`division_id`) REFERENCES `course_divisions` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_schedule_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
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
      `course_id` int DEFAULT NULL,
      `starting_division_id` int DEFAULT NULL,
      `current_division_id` int DEFAULT NULL,
      `cohort_id` int DEFAULT NULL,
      `user_id` int NOT NULL,
      `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `current_semester` tinyint NOT NULL DEFAULT 1,
      `division_status` enum('pending','in_progress','passed','failed','completed','dropped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
      `repeat_mode` enum('auto','repeat_failed_only','repeat_full_semester') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'repeat_full_semester',
      `status` enum('active','completed','dropped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
      `enrolled_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `completed_at` timestamp NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_course_user` (`course_id`,`user_id`),
      KEY `idx_cohort_id` (`cohort_id`),
      KEY `idx_starting_division` (`starting_division_id`),
      KEY `idx_current_division` (`current_division_id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `fk_course_enrollments_cohort` FOREIGN KEY (`cohort_id`) REFERENCES `course_cohorts` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_enroll_starting_division` FOREIGN KEY (`starting_division_id`) REFERENCES `course_divisions` (`id`) ON DELETE SET NULL,
      CONSTRAINT `fk_enroll_current_division` FOREIGN KEY (`current_division_id`) REFERENCES `course_divisions` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

try {
    foreach ($schemaQueries as $q) {
        $conn->exec($q);
    }

    // Safe migration — add image_path if the courses table already existed without it
    try {
        $check = $conn->query("SHOW COLUMNS FROM `courses` LIKE 'image_path'");
        if ($check && $check->rowCount() === 0) {
            $conn->exec("ALTER TABLE `courses` ADD COLUMN `image_path` VARCHAR(500) NULL AFTER `description`");
        }
    } catch (PDOException $e) {
        error_log('admin_courses image_path migration: ' . $e->getMessage());
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
const DIVISION_TYPES = ['none', 'weeks', 'months', 'terms', 'semesters', 'years', 'custom'];
const DIVISION_LABEL_DEFAULTS = [
    'none' => '',
    'weeks' => 'Week',
    'months' => 'Month',
    'terms' => 'Term',
    'semesters' => 'Semester',
    'years' => 'Year',
    'custom' => 'Stage',
];

const COURSE_UPLOAD_DIR = 'uploads/courses/';

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

function weeksBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    $days = $start->diff($end)->days;
    return max(1, (int)ceil(($days + 1) / 7));
}

function cohortPhase(array $cohort, DateTimeImmutable $today): array
{
    if ($cohort['status'] === 'draft') {
        return ['Draft', 'muted'];
    }
    if ($cohort['status'] === 'cancelled') {
        return ['Cancelled', 'danger'];
    }
    $start = new DateTimeImmutable($cohort['start_date']);
    $end = new DateTimeImmutable($cohort['end_date'] ?? $cohort['start_date']);
    if ($today > $end) {
        return ['Completed', 'muted'];
    }
    if ($today < $start) {
        return ['Upcoming', 'brand'];
    }
    return ['Running', 'accent'];
}

/**
 * Handle a course cover upload.
 * Returns the relative path (e.g. "uploads/courses/abc.jpg") on success,
 * the existing path if no new file was uploaded,
 * or null if the admin cleared the image.
 */
function handleCourseCover(?string $existingPath, bool $clearImage, ?string &$error): ?string
{
    $error = null;

    if ($clearImage) {
        if (!empty($existingPath)) {
            $abs = __DIR__ . '/../' . ltrim($existingPath, '/');
            if (is_file($abs)) @unlink($abs);
        }
        return null;
    }

    if (empty($_FILES['cover_file']['tmp_name']) || !is_uploaded_file($_FILES['cover_file']['tmp_name'])) {
        return $existingPath;
    }

    $f = $_FILES['cover_file'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        $error = 'Cover upload failed (error ' . $f['error'] . ').';
        return $existingPath;
    }

    $maxBytes = 4 * 1024 * 1024;
    if ((int)$f['size'] > $maxBytes) {
        $error = 'Cover image must be 4 MB or smaller.';
        return $existingPath;
    }

    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        $error = 'Cover must be JPG, PNG, WEBP, or GIF.';
        return $existingPath;
    }

    $uploadsRoot = __DIR__ . '/../uploads';
    $dir = $uploadsRoot . '/courses';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $base = pathinfo($f['name'], PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9._-]/', '-', $base) ?: 'cover';
    $base = trim($base, '-');
    $fname = $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $fname;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        $error = 'Could not save the uploaded image.';
        return $existingPath;
    }

    if (!empty($existingPath)) {
        $old = __DIR__ . '/../' . ltrim($existingPath, '/');
        if (str_starts_with($old, $uploadsRoot) && is_file($old)) {
            @unlink($old);
        }
    }

    return COURSE_UPLOAD_DIR . $fname;
}

/**
 * Sum points of every scheduled item for a subject, grouped by bucket.
 * Mirrors what course.php / admin_course_schedule.php use to validate budgets.
 *
 * @return array{quiz:int, exam:int, quiz_count:int, exam_count:int}
 */
function getScheduledPointsByBucket(PDO $conn, int $courseId, int $subjectId): array
{
    $out = ['quiz' => 0, 'exam' => 0, 'quiz_count' => 0, 'exam_count' => 0];
    try {
        $stmt = $conn->prepare("
            SELECT cs.item_type,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(
                       CASE cs.item_type
                           WHEN 'quiz' THEN (SELECT total_points FROM quizzes WHERE id = cs.item_id)
                           WHEN 'exam' THEN (SELECT total_points FROM monthly_exams WHERE id = cs.item_id)
                           ELSE 0
                       END
                   ), 0) AS pts
            FROM course_schedule cs
            WHERE cs.course_id = ? AND cs.subject_id = ?
              AND cs.item_type IN ('quiz','exam')
            GROUP BY cs.item_type
        ");
        $stmt->execute([$courseId, $subjectId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = (string)$r['item_type'];
            if ($t === 'quiz') {
                $out['quiz'] += (int)$r['pts'];
                $out['quiz_count'] += (int)$r['cnt'];
            } elseif ($t === 'exam') {
                $out['exam'] += (int)$r['pts'];
                $out['exam_count'] += (int)$r['cnt'];
            }
        }
    } catch (PDOException $e) {
        error_log('getScheduledPointsByBucket: ' . $e->getMessage());
    }
    return $out;
}

// ============================================================================
// POST ACTIONS
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
                $divisionType = (string)($_POST['division_type'] ?? 'none');
                $divisionLabel = trim((string)($_POST['division_label'] ?? ''));
                $status = (string)($_POST['status'] ?? 'draft');
                $clearImage = !empty($_POST['clear_cover']);

                if ($title === '' || mb_strlen($title) > 255) {
                    flash('error', 'Course title is required (max 255 characters).');
                    break;
                }
                if (!in_array($divisionType, DIVISION_TYPES, true)) {
                    $divisionType = 'none';
                }
                if (!in_array($status, COURSE_STATUSES, true)) {
                    $status = 'draft';
                }

                if ($divisionType === 'none') {
                    $divisionLabel = null;
                } elseif ($divisionLabel === '') {
                    $divisionLabel = DIVISION_LABEL_DEFAULTS[$divisionType] ?? 'Division';
                } else {
                    $divisionLabel = mb_substr($divisionLabel, 0, 100);
                }

                // Load existing image (for edit mode)
                $existingCover = null;
                $oldDivisionType = null;
                if ($id > 0) {
                    $stmt = $conn->prepare("SELECT image_path, division_type FROM courses WHERE id = ?");
                    $stmt->execute([$id]);
                    $existingRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    $existingCover = $existingRow ? ($existingRow['image_path'] ?: null) : null;
                    $oldDivisionType = $existingRow ? (string)$existingRow['division_type'] : null;
                }

                $uploadError = null;
                $coverPath = handleCourseCover($existingCover, $clearImage, $uploadError);
                if ($uploadError !== null) {
                    flash('error', $uploadError);
                    break;
                }

                if ($id > 0) {
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM course_divisions WHERE course_id = ?");
                    $stmt->execute([$id]);
                    $existingDivisions = (int)$stmt->fetchColumn();

                    if ($existingDivisions > 0 && $divisionType === 'none') {
                        flash('error', "This course already has $existingDivisions divisions. Delete them first if you want to remove divisions.");
                        break;
                    }

                    // Prevent division-type change while divisions exist —
                    // otherwise existing division names/labels would become
                    // misleading (e.g. "Semester 1" but type is now "weeks").
                    if ($existingDivisions > 0 && $oldDivisionType !== null
                        && $divisionType !== $oldDivisionType) {
                        flash('error',
                            'Cannot change division type while this course has existing divisions. '
                            . 'Delete the divisions first if you want to switch to '
                            . $divisionType . '.');
                        break;
                    }

                    $stmt = $conn->prepare("
                        UPDATE courses
                        SET title = ?, description = ?, image_path = ?,
                            division_type = ?, division_label = ?, status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $title,
                        $description !== '' ? $description : null,
                        $coverPath ?: null,
                        $divisionType,
                        $divisionLabel,
                        $status,
                        $id
                    ]);
                    flash('success', 'Course updated.');
                    $back = 'admin_courses.php?course_id=' . $id;
                } else {
                    $slug = uniqueSlug($conn, $title);
                    $stmt = $conn->prepare("
                        INSERT INTO courses
                            (title, slug, description, image_path,
                             division_type, division_label, status, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $title,
                        $slug,
                        $description !== '' ? $description : null,
                        $coverPath ?: null,
                        $divisionType,
                        $divisionLabel,
                        $status,
                        $userId
                    ]);
                    $newId = (int)$conn->lastInsertId();
                    flash('success', 'Course created. Now define its divisions.');
                    $back = 'admin_courses.php?course_id=' . $newId;
                }
                break;
            }

            case 'delete_course': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_enrollments WHERE course_id = ?");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash('error', 'This course has enrolled students, so it cannot be deleted. Set its status to Archived instead.');
                    $back = 'admin_courses.php?course_id=' . $id;
                    break;
                }

                $stmt = $conn->prepare("SELECT image_path FROM courses WHERE id = ?");
                $stmt->execute([$id]);
                $oldCover = $stmt->fetchColumn() ?: null;
                if (!empty($oldCover)) {
                    $abs = __DIR__ . '/../' . ltrim((string)$oldCover, '/');
                    $uploadsRoot = __DIR__ . '/../uploads';
                    if (str_starts_with($abs, $uploadsRoot) && is_file($abs)) {
                        @unlink($abs);
                    }
                }

                $conn->prepare("DELETE FROM courses WHERE id = ?")->execute([$id]);
                flash('success', 'Course deleted.');
                $back = 'admin_courses.php';
                break;
            }

            // ----------------------------------------------------------------
            // DIVISIONS
            // ----------------------------------------------------------------
            case 'add_division_row': {
                $stmt = $conn->prepare("SELECT COALESCE(MAX(number), 0) FROM course_divisions WHERE course_id = ?");
                $stmt->execute([$courseIdPost]);
                $nextNumber = (int)$stmt->fetchColumn() + 1;

                $defaultLabel = 'Division';
                $stmt = $conn->prepare("SELECT division_label FROM courses WHERE id = ?");
                $stmt->execute([$courseIdPost]);
                $lbl = (string)$stmt->fetchColumn();
                if ($lbl !== '') {
                    $defaultLabel = $lbl;
                }

                $today = date('Y-m-d');
                $stmt = $conn->prepare("
                    INSERT INTO course_divisions (course_id, number, name, start_date, end_date, passing_score)
                    VALUES (?, ?, ?, ?, ?, 60)
                ");
                $stmt->execute([$courseIdPost, $nextNumber, $defaultLabel . ' ' . $nextNumber, $today, $today]);

                flash('success', 'New division row added. Edit its dates and press Save.');
                break;
            }

            case 'save_divisions': {
                $ids = $_POST['div_id'] ?? [];
                $numbers = $_POST['div_number'] ?? [];
                $names = $_POST['div_name'] ?? [];
                $starts = $_POST['div_start'] ?? [];
                $ends = $_POST['div_end'] ?? [];
                $passScores = $_POST['div_pass'] ?? [];

                if (!is_array($ids)) {
                    flash('error', 'Invalid form data.');
                    break;
                }

                $errors = [];
                $rows = [];
                $seenRanges = [];

                foreach ($ids as $i => $id) {
                    $id = (int)$id;
                    $num = (int)($numbers[$i] ?? 0);
                    $name = trim((string)($names[$i] ?? ''));
                    $startRaw = (string)($starts[$i] ?? '');
                    $endRaw = (string)($ends[$i] ?? '');
                    $pass = (int)($passScores[$i] ?? 60);

                    if ($id <= 0) continue;

                    if ($name === '') {
                        $errors[] = "Row $num: name is required.";
                        continue;
                    }
                    $start = parseDate($startRaw);
                    $end = parseDate($endRaw);
                    if (!$start || !$end) {
                        $errors[] = "Row $num: invalid dates.";
                        continue;
                    }
                    if ($start > $end) {
                        $errors[] = "Row $num: start date must be before end date.";
                        continue;
                    }
                    if ($pass < 1 || $pass > 100) {
                        $errors[] = "Row $num: passing score must be 1–100.";
                        continue;
                    }

                    foreach ($seenRanges as $sr) {
                        if ($start <= $sr['end'] && $end >= $sr['start']) {
                            $errors[] = "Row $num overlaps with row {$sr['num']}.";
                            break 1;
                        }
                    }
                    $seenRanges[] = ['num' => $num, 'start' => $start, 'end' => $end];

                    $rows[] = [
                        'id' => $id,
                        'number' => $num,
                        'name' => $name,
                        'start' => $start->format('Y-m-d'),
                        'end' => $end->format('Y-m-d'),
                        'pass' => $pass,
                    ];
                }

                if ($errors) {
                    flash('error', implode(' ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? ' …' : ''));
                    break;
                }

                $conn->beginTransaction();
                $stmt = $conn->prepare("
                    UPDATE course_divisions
                    SET number = ?, name = ?, start_date = ?, end_date = ?, passing_score = ?
                    WHERE id = ? AND course_id = ?
                ");
                foreach ($rows as $r) {
                    $stmt->execute([$r['number'], $r['name'], $r['start'], $r['end'], $r['pass'], $r['id'], $courseIdPost]);
                }
                $conn->commit();
                flash('success', count($rows) . ' division(s) saved.');
                break;
            }

            case 'delete_division': {
                $divId = (int)($_POST['division_id'] ?? 0);
                if ($divId <= 0) {
                    flash('error', 'Invalid division.');
                    break;
                }

                $stmt = $conn->prepare("
                    SELECT COUNT(*) FROM course_enrollments
                    WHERE starting_division_id = ? OR current_division_id = ?
                ");
                $stmt->execute([$divId, $divId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash('error', 'This division has enrolled students and cannot be deleted. Cancel the enrollments first.');
                    break;
                }

                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_schedule WHERE division_id = ?");
                $stmt->execute([$divId]);
                $scheduledCount = (int)$stmt->fetchColumn();
                if ($scheduledCount > 0) {
                    flash('error', "This division has $scheduledCount scheduled items. Delete the schedule first.");
                    break;
                }

                $conn->prepare("DELETE FROM course_divisions WHERE id = ? AND course_id = ?")->execute([$divId, $courseIdPost]);
                flash('success', 'Division deleted.');
                break;
            }

            // ----------------------------------------------------------------
            // SUBJECTS
            // ----------------------------------------------------------------
            case 'add_course_subject': {
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                if ($subjectId <= 0) {
                    flash('error', 'Choose a subject from the library.');
                    break;
                }

                $def = $conn->query("SELECT * FROM academy_grading_defaults WHERE id = 1")->fetch()
                    ?: [
                        'passing_score' => 60,
                        'supplementary_max_score' => 90,
                        'weekly_exam_count' => 7,
                        'monthly_exam_count' => 2,
                        'final_exam_count' => 1,
                    ];

                $stmt = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND is_active = 1");
                $stmt->execute([$subjectId]);
                if (!$stmt->fetchColumn()) {
                    flash('error', 'That subject does not exist or is inactive.');
                    break;
                }

                $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM course_subjects WHERE course_id = ?");
                $stmt->execute([$courseIdPost]);
                $nextSort = (int)$stmt->fetchColumn() + 1;

                try {
                    $stmt = $conn->prepare("
                        INSERT INTO course_subjects
                            (course_id, subject_id, sort_order,
                             passing_score, supplementary_max_score,
                             weekly_exam_count, monthly_exam_count, final_exam_count,
                             quiz_total_points, exam_total_points, final_total_points)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 30, 20, 50)
                    ");
                    $stmt->execute([
                        $courseIdPost, $subjectId, $nextSort,
                        (int)$def['passing_score'],
                        (int)$def['supplementary_max_score'],
                        (int)$def['weekly_exam_count'],
                        (int)$def['monthly_exam_count'],
                        (int)$def['final_exam_count'],
                    ]);
                    flash('success', 'Subject added to the course. Now tune its points budget.');
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        flash('error', 'That subject is already in this course.');
                    } else {
                        throw $e;
                    }
                }
                break;
            }

            case 'save_course_subject': {
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                if ($subjectId <= 0) {
                    flash('error', 'Invalid subject.');
                    break;
                }

                $pass       = (int)($_POST['passing_score']          ?? 60);
                $quizTotal  = (int)($_POST['quiz_total_points']      ?? 30);
                $examTotal  = (int)($_POST['exam_total_points']      ?? 20);
                $finalTotal = (int)($_POST['final_total_points']     ?? 50);
                $wec        = (int)($_POST['weekly_exam_count']      ?? 7);
                $mec        = (int)($_POST['monthly_exam_count']     ?? 2);
                $fec        = (int)($_POST['final_exam_count']       ?? 1);
                $sms        = (int)($_POST['supplementary_max_score'] ?? 90);

                $errors = [];
                if ($pass < 1 || $pass > 100)              $errors[] = 'Passmark must be 1–100.';
                if ($quizTotal < 0 || $quizTotal > 999)    $errors[] = 'Quiz points must be 0–999.';
                if ($examTotal < 0 || $examTotal > 999)    $errors[] = 'Exam points must be 0–999.';
                if ($finalTotal < 0 || $finalTotal > 999)  $errors[] = 'Final points must be 0–999.';
                if (($quizTotal + $examTotal + $finalTotal) <= 0) {
                    $errors[] = 'Total points budget must be greater than zero.';
                }
                if ($wec < 0 || $wec > 52)    $errors[] = 'Weekly exam count 0–52.';
                if ($mec < 0 || $mec > 12)    $errors[] = 'Monthly exam count 0–12.';
                if ($fec < 0 || $fec > 5)     $errors[] = 'Final exam count 0–5.';
                if ($sms < 1 || $sms > 999)   $errors[] = 'Supplementary max score 1–999.';

                if ($errors) {
                    flash('error', implode(' ', $errors));
                    break;
                }

                // If we're shrinking the budget, warn (but don't block) when
                // it's below the points already scheduled for this subject.
                // The schedule enforcement layer prevents adding more; but the
                // admin may legitimately want to reduce a budget for future
                // subjects — so we only alert.
                $scheduled = getScheduledPointsByBucket($conn, $courseIdPost, $subjectId);
                $warnings = [];
                if ($scheduled['quiz'] > $quizTotal) {
                    $warnings[] = 'weekly budget (' . $quizTotal . ' pts) is below already-scheduled quizzes ('
                        . $scheduled['quiz'] . ' pts)';
                }
                if ($scheduled['exam'] > $examTotal) {
                    $warnings[] = 'monthly budget (' . $examTotal . ' pts) is below already-scheduled exams ('
                        . $scheduled['exam'] . ' pts)';
                }

                $stmt = $conn->prepare("
                    UPDATE course_subjects
                    SET passing_score           = ?,
                        quiz_total_points       = ?,
                        exam_total_points       = ?,
                        final_total_points      = ?,
                        weekly_exam_count       = ?,
                        monthly_exam_count      = ?,
                        final_exam_count        = ?,
                        supplementary_max_score = ?
                    WHERE course_id = ? AND subject_id = ?
                ");
                $stmt->execute([
                    $pass,
                    $quizTotal, $examTotal, $finalTotal,
                    $wec, $mec, $fec,
                    $sms,
                    $courseIdPost, $subjectId
                ]);

                if ($warnings) {
                    flash('error', 'Grading saved, but notice: ' . implode('; ', $warnings) . '.');
                } else {
                    flash('success', 'Grading configuration saved.');
                }
                break;
            }

            case 'remove_course_subject': {
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                if ($subjectId <= 0) {
                    flash('error', 'Invalid subject.');
                    break;
                }

                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_schedule WHERE course_id = ? AND subject_id = ?");
                $stmt->execute([$courseIdPost, $subjectId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash('error', 'This subject has scheduled items. Delete the schedule first.');
                    break;
                }

                $stmt = $conn->prepare("
                    SELECT COUNT(*) FROM enrollment_course_grades ecg
                    JOIN course_enrollments en ON en.id = ecg.enrollment_id
                    WHERE en.course_id = ? AND ecg.subject_id = ?
                ");
                $stmt->execute([$courseIdPost, $subjectId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash('error', 'This subject has student grades recorded. Cannot remove.');
                    break;
                }

                $conn->prepare("DELETE FROM course_subjects WHERE course_id = ? AND subject_id = ?")
                     ->execute([$courseIdPost, $subjectId]);
                flash('success', 'Subject removed from the course.');
                break;
            }

            // ----------------------------------------------------------------
            case 'remove_student': {
                $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
                if ($enrollmentId > 0) {
                    $conn->prepare("DELETE FROM course_enrollments WHERE id = ? AND course_id = ?")
                         ->execute([$enrollmentId, $courseIdPost]);
                    flash('success', 'Student removed from the course.');
                }
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
                    flash('error', 'Group name is required.');
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

                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE course_cohorts SET name = ?, start_date = ?, enrollment_deadline = ?, capacity = ?, status = ? WHERE id = ? AND course_id = ?");
                    $stmt->execute([$name, $start->format('Y-m-d'), $deadline->format('Y-m-d'), $capacity, $status, $id, $courseIdPost]);
                    flash('success', 'Group updated.');
                } else {
                    $stmt = $conn->prepare("INSERT INTO course_cohorts (course_id, name, start_date, enrollment_deadline, capacity, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$courseIdPost, $name, $start->format('Y-m-d'), $deadline->format('Y-m-d'), $capacity, $status]);
                    flash('success', 'Group created.');
                }
                break;
            }

            case 'set_cohort_status': {
                $id = (int)($_POST['id'] ?? 0);
                $status = (string)($_POST['status'] ?? '');
                if (in_array($status, COHORT_STATUSES, true)) {
                    $conn->prepare("UPDATE course_cohorts SET status = ? WHERE id = ? AND course_id = ?")->execute([$status, $id, $courseIdPost]);
                    flash('success', 'Group status changed to ' . $status . '.');
                }
                break;
            }

            case 'delete_cohort': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_enrollments WHERE cohort_id = ?");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash('error', 'This group has enrolled students, so it cannot be deleted. Set it to Cancelled instead.');
                    break;
                }
                $conn->prepare("DELETE FROM course_cohorts WHERE id = ? AND course_id = ?")->execute([$id, $courseIdPost]);
                flash('success', 'Group deleted.');
                break;
            }

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('admin_courses error: ' . $e->getMessage());
        flash('error', 'A database error occurred. Nothing was saved.');
    }

    redirectTo($back);
}

// ============================================================================
// LOAD DATA
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
$cohorts = [];
$enrolledStudents = [];
$divisions = [];
$courseSubjects = [];
$allSubjects = [];
$scheduledUsage = []; // [subject_id] => ['quiz'=>pts,'exam'=>pts,'quiz_count'=>n,'exam_count'=>n]

if (!$course) {
    $courses = $conn->query("
        SELECT c.*,
            (SELECT COUNT(*) FROM course_subjects cs WHERE cs.course_id = c.id) AS subjects_count,
            (SELECT COUNT(*) FROM course_divisions d WHERE d.course_id = c.id) AS divisions_count,
            (SELECT COUNT(*) FROM course_enrollments en WHERE en.course_id = c.id) AS enrolled_count
        FROM courses c
        ORDER BY c.created_at DESC, c.id DESC
    ")->fetchAll();
} else {
    $stmt = $conn->prepare("SELECT * FROM course_divisions WHERE course_id = ? ORDER BY number ASC");
    $stmt->execute([$courseId]);
    $divisions = $stmt->fetchAll();

    $stmt = $conn->prepare("
        SELECT cs.*, s.name, s.slug, s.icon
        FROM course_subjects cs
        JOIN subjects s ON s.id = cs.subject_id
        WHERE cs.course_id = ?
        ORDER BY cs.sort_order ASC, s.name ASC
    ");
    $stmt->execute([$courseId]);
    $courseSubjects = $stmt->fetchAll();

    // Scheduled points per subject — for the live "used / budget" badge
    foreach ($courseSubjects as $cs) {
        $scheduledUsage[(int)$cs['subject_id']] = getScheduledPointsByBucket(
            $conn,
            $courseId,
            (int)$cs['subject_id']
        );
    }

    $allSubjects = $conn->query("
        SELECT id, name, slug, icon
        FROM subjects
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    ")->fetchAll();

    $stmt = $conn->prepare("
        SELECT h.*,
            (SELECT COUNT(*) FROM course_enrollments en WHERE en.cohort_id = h.id AND en.status <> 'dropped') AS enrolled_count
        FROM course_cohorts h
        WHERE h.course_id = ?
        ORDER BY h.start_date DESC, h.id DESC
    ");
    $stmt->execute([$courseId]);
    $cohorts = $stmt->fetchAll();

    $stmt = $conn->prepare("
        SELECT en.id              AS enrollment_id,
               en.status          AS enrollment_status,
               en.started_at,
               en.current_semester,
               en.division_status,
               en.starting_division_id,
               en.current_division_id,
               en.cohort_id,
               h.name             AS cohort_name,
               u.id               AS user_id,
               u.username,
               u.email,
               u.full_name
        FROM course_enrollments en
        JOIN users u ON u.id = en.user_id
        LEFT JOIN course_cohorts h ON h.id = en.cohort_id
        WHERE en.course_id = ?
        ORDER BY FIELD(en.status, 'active', 'completed', 'dropped'), en.started_at DESC
    ");
    $stmt->execute([$courseId]);
    $enrolledStudents = $stmt->fetchAll();
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
            --success: #15803d; --success-soft: rgba(21, 128, 61, 0.08);
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
            --success: #4ade80; --success-soft: rgba(74, 222, 128, 0.12);
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
        .btn-gold { background: var(--gold-soft); color: var(--gold); border-color: transparent; }
        .btn-gold:hover { border-color: var(--gold); color: var(--gold); }
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
        .badge.success { background: var(--success-soft); color: var(--success); }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 4px; }
        .stat { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--gold); border-radius: var(--radius-md); padding: 12px 14px; }
        .stat span { display: block; font-size: 12px; color: var(--muted); }
        .stat strong { font-size: 20px; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; align-items: end; }
        .field { display: grid; gap: 4px; }
        .field.wide { grid-column: 1 / -1; }
        .field label { font-size: 12px; font-weight: 700; color: var(--text-secondary); }
        .field small { display: block; font-size: 11px; color: var(--muted); margin-top: 2px; line-height: 1.3; }
        input[type="text"], input[type="number"], input[type="date"], input[type="time"], input[type="file"], select, textarea {
            width: 100%; padding: 10px 12px; border-radius: 10px;
            border: 1px solid var(--line); background: var(--surface); font-size: 14px;
        }
        textarea { min-height: 84px; resize: vertical; }

        .cover-uploader {
            display: flex; gap: 14px; align-items: flex-start;
            padding: 12px; background: var(--surface-2); border-radius: var(--radius-md);
            border: 1px dashed var(--line);
            transition: border-color .15s;
        }
        .cover-uploader:focus-within { border-color: var(--gold); }
        .cover-preview {
            flex-shrink: 0; width: 100px; height: 100px; border-radius: 10px;
            background: var(--surface-3) center/cover no-repeat;
            border: 1px solid var(--line);
            display: flex; align-items: center; justify-content: center;
            color: var(--muted); font-size: 32px; overflow: hidden;
        }
        .cover-preview img { width: 100%; height: 100%; object-fit: cover; }
        .cover-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
        .cover-body label { font-size: 12px; font-weight: 700; color: var(--text-secondary); }
        .cover-body .hint { font-size: 11px; color: var(--muted); line-height: 1.3; }
        .cover-body .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
        .cover-clear-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; font-size: 12px; font-weight: 700;
            border-radius: 999px; border: 1px solid var(--line);
            background: var(--surface); color: var(--danger); cursor: pointer;
        }
        .cover-clear-btn:hover { border-color: var(--danger); }

        .div-table { min-width: 900px; }
        .div-table th, .div-table td { padding: 8px 10px; }
        .div-table input[type="text"],
        .div-table input[type="date"],
        .div-table input[type="number"] { padding: 6px 8px; font-size: 13px; border-radius: 8px; }
        .div-table .num-cell { width: 44px; font-weight: 800; color: var(--brand); text-align: center; }
        .div-table .weeks-cell { color: var(--muted); font-size: 12px; font-weight: 700; white-space: nowrap; }
        .div-table .actions-cell { text-align: right; }
        .div-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px; flex-wrap: wrap; }

        .subject-config {
            border: 1px solid var(--line); border-radius: var(--radius-md);
            padding: 14px; background: var(--surface-2);
        }
        .subject-config-header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 10px; flex-wrap: wrap; margin-bottom: 12px;
        }
        .subject-config-header .name-wrap {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .subject-config-header .name {
            font-size: 16px; font-weight: 800; color: var(--brand);
        }
        .subject-config-grid {
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        }
        .usage-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }
        .usage-badge.ok      { background: var(--success-soft); color: var(--success); }
        .usage-badge.warn    { background: var(--gold-soft);    color: var(--gold); }
        .usage-badge.over    { background: var(--danger-soft);  color: var(--danger); }
        .usage-badge.muted   { background: var(--surface-3);    color: var(--muted); }

        .div-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: var(--brand-soft); border: 1px solid var(--brand); color: var(--brand); font-weight: 700; font-size: 12px; }

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
            <small>
                <?php echo $course
                    ? 'Divisions, subjects and weekly schedule'
                    : 'Create courses, divide them, pick subjects, and design their schedule'; ?>
            </small>
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
                        <th style="width:64px;">Cover</th>
                        <th>Course</th>
                        <th>Division</th>
                        <th>Divisions</th>
                        <th>Subjects</th>
                        <th>Students</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$courses): ?>
                    <tr><td colspan="8" class="empty">No courses yet. Click "New course" to create the first one.</td></tr>
                <?php endif; ?>
                <?php foreach ($courses as $c):
                    $badge = $c['status'] === 'published' ? 'brand' : ($c['status'] === 'archived' ? 'muted' : 'gold');
                    $dt = (string)($c['division_type'] ?? 'none');
                    $cover = trim((string)($c['image_path'] ?? ''));
                    $coverOk = $cover !== '' && is_file(__DIR__ . '/../' . ltrim($cover, '/'));
                ?>
                    <tr>
                        <td>
                            <div style="width:44px;height:44px;border-radius:8px;overflow:hidden;background:linear-gradient(135deg,#0E4A34,#3E7A5E);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;">
                                <?php if ($coverOk): ?>
                                    <img src="../<?php echo e($cover); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    📖
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><a href="admin_courses.php?course_id=<?php echo (int)$c['id']; ?>"><strong><?php echo e($c['title']); ?></strong></a></td>
                        <td>
                            <?php if ($dt === 'none'): ?>
                                <span style="color:var(--muted)">—</span>
                            <?php else: ?>
                                <?php echo e(ucfirst($dt)); ?>
                                <?php if (!empty($c['division_label'])): ?>
                                    <br><small style="color:var(--muted)"><?php echo e($c['division_label']); ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$c['divisions_count']; ?></td>
                        <td><?php echo (int)$c['subjects_count']; ?></td>
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
    $statusBadge = $course['status'] === 'published' ? 'brand' : ($course['status'] === 'archived' ? 'muted' : 'gold');
    $divisionType = (string)($course['division_type'] ?? 'none');
    $divisionLabel = (string)($course['division_label'] ?? '');
    $currentCover = trim((string)($course['image_path'] ?? ''));
    $currentCoverOk = $currentCover !== '' && is_file(__DIR__ . '/../' . ltrim($currentCover, '/'));

    $statusCounts = ['active' => 0, 'completed' => 0, 'dropped' => 0];
    foreach ($enrolledStudents as $es) {
        if (isset($statusCounts[$es['enrollment_status']])) {
            $statusCounts[$es['enrollment_status']]++;
        }
    }
?>
    <!-- ======================= OVERVIEW ======================= -->
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

        <?php if ($currentCoverOk): ?>
            <div style="display:flex;gap:16px;align-items:center;margin-bottom:16px;padding:12px;background:var(--surface-2);border-radius:var(--radius-md);border:1px solid var(--line);">
                <div style="width:100px;height:100px;border-radius:10px;overflow:hidden;flex-shrink:0;border:1px solid var(--line);">
                    <img src="../<?php echo e($currentCover); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--gold);margin-bottom:4px;">Cover image</div>
                    <p style="font-size:13px;color:var(--text-secondary);margin:0;overflow-wrap:anywhere;"><?php echo e($currentCover); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat"><span>Status</span><strong><span class="badge <?php echo $statusBadge; ?>"><?php echo e(ucfirst((string)$course['status'])); ?></span></strong></div>
            <div class="stat">
                <span>Division type</span>
                <strong style="font-size:14px;">
                    <?php if ($divisionType === 'none'): ?>
                        No divisions
                    <?php else: ?>
                        <?php echo e(ucfirst($divisionType)); ?>
                        <?php if ($divisionLabel !== ''): ?>
                            <br><small style="color:var(--muted);font-size:12px;"><?php echo e($divisionLabel); ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </strong>
            </div>
            <div class="stat"><span>Divisions defined</span><strong><?php echo count($divisions); ?></strong></div>
            <div class="stat"><span>Subjects</span><strong><?php echo count($courseSubjects); ?></strong></div>
            <div class="stat"><span>Students enrolled</span><strong><?php echo count($enrolledStudents); ?></strong></div>
        </div>
        <?php if (!empty($course['description'])): ?>
            <p style="margin-top:12px;color:var(--text-secondary);"><?php echo nl2br(e($course['description'])); ?></p>
        <?php endif; ?>
    </section>

    <!-- ======================= DIVISIONS EDITOR ======================= -->
    <section class="card">
        <h2>
            <span><?php echo $divisionType === 'none' ? 'Divisions (course is one single run)' : e($divisionLabel ?: 'Division') . 's'; ?></span>
            <?php if ($divisionType !== 'none'): ?>
                <span class="badge brand"><?php echo count($divisions); ?> defined</span>
            <?php endif; ?>
        </h2>

        <?php if ($divisionType === 'none'): ?>
            <p class="empty">
                This course is not divided.
                <br>Edit the course to pick a division type (Semesters, Terms, Weeks, etc.).
            </p>
        <?php else: ?>
            <p style="color:var(--text-secondary);font-size:13px;margin-bottom:14px;">
                Define the real dates of each <?php echo e(strtolower($divisionLabel ?: 'division')); ?> and its pass score.
                When a student enrols, their study begins at the start of the next upcoming <?php echo e(strtolower($divisionLabel ?: 'division')); ?>.
                Dates must not overlap.
            </p>

            <form method="post" id="divisionsForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_divisions">
                <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">

                <div class="table-wrap">
                    <table class="div-table">
                        <thead>
                            <tr>
                                <th style="width:44px;">#</th>
                                <th>Name</th>
                                <th style="width:150px;">Start date</th>
                                <th style="width:150px;">End date</th>
                                <th style="width:90px;">Weeks</th>
                                <th style="width:90px;">Pass %</th>
                                <th style="width:160px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$divisions): ?>
                            <tr><td colspan="7" class="empty">No <?php echo e(strtolower($divisionLabel ?: 'divisions')); ?> defined yet. Click “＋ Add row” below to start.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($divisions as $d):
                            $dStart = new DateTimeImmutable((string)$d['start_date']);
                            $dEnd = new DateTimeImmutable((string)$d['end_date']);
                            $w = weeksBetween($dStart, $dEnd);

                            if ($today > $dEnd) {
                                $dPhase = ['Completed', 'muted'];
                            } elseif ($today < $dStart) {
                                $dPhase = ['Upcoming', 'brand'];
                            } else {
                                $dPhase = ['Running', 'accent'];
                            }
                        ?>
                            <tr data-div-row>
                                <td class="num-cell">
                                    <?php echo (int)$d['number']; ?>
                                    <input type="hidden" name="div_id[]" value="<?php echo (int)$d['id']; ?>">
                                    <input type="hidden" name="div_number[]" value="<?php echo (int)$d['number']; ?>">
                                </td>
                                <td>
                                    <input type="text" name="div_name[]" maxlength="255"
                                           value="<?php echo e($d['name']); ?>" required>
                                </td>
                                <td>
                                    <input type="date" name="div_start[]"
                                           value="<?php echo e($dStart->format('Y-m-d')); ?>" required>
                                </td>
                                <td>
                                    <input type="date" name="div_end[]"
                                           value="<?php echo e($dEnd->format('Y-m-d')); ?>" required>
                                </td>
                                <td class="weeks-cell" data-weeks-cell><?php echo $w; ?> weeks</td>
                                <td>
                                    <input type="number" name="div_pass[]" min="1" max="100"
                                           value="<?php echo (int)($d['passing_score'] ?? 60); ?>" required>
                                </td>
                                <td class="actions-cell">
                                    <span class="badge <?php echo $dPhase[1]; ?>" style="margin-right:6px;"><?php echo e($dPhase[0]); ?></span>
                                    <a class="btn btn-sm btn-primary" href="admin_course_schedule.php?division_id=<?php echo (int)$d['id']; ?>" title="Design the weekly schedule">🗓️ Schedule</a>
                                    <a class="btn btn-sm" href="admin_division_results.php?division_id=<?php echo (int)$d['id']; ?>" title="View results and close the division">📊 Results</a>
                                    <button class="btn btn-sm btn-danger" type="button"
                                            data-delete-div="<?php echo (int)$d['id']; ?>"
                                            title="Delete this division">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="div-actions">
                    <button class="btn btn-gold" type="submit"
                            formaction="admin_courses.php?course_id=<?php echo (int)$course['id']; ?>&do=add"
                            formmethod="post"
                            name="action" value="add_division_row"
                            title="Add a new empty division row">
                        ＋ Add row
                    </button>
                    <button class="btn btn-primary" type="submit">
                        💾 Save all divisions
                    </button>
                </div>
                <p style="margin-top:10px;color:var(--muted);font-size:12px;">
                    ⚠️ Deleting a division removes only its structure — you can't delete a division that already has enrolled students or scheduled items.
                </p>
            </form>

            <form method="post" id="deleteDivisionForm" style="display:none;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete_division">
                <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                <input type="hidden" name="division_id" id="deleteDivisionId" value="0">
            </form>
        <?php endif; ?>
    </section>

    <!-- ======================= SUBJECTS IN THIS COURSE ======================= -->
    <section class="card">
        <h2>
            <span>Subjects in this course</span>
            <span class="badge brand"><?php echo count($courseSubjects); ?></span>
        </h2>

        <p style="color:var(--text-secondary);font-size:13px;margin-bottom:14px;">
            Pick which subjects from the library belong to this course, and configure the point budget for each one.
            A subject's overall grade is
            <strong>(points earned) ÷ (points budget) × 100</strong>, capped at 100.
            Scheduled quizzes count against <em>Weekly points</em>, monthly exams against <em>Monthly points</em>,
            and the final exam against <em>Final points</em>.
        </p>

        <?php
        $usedSubjectIds = array_map(fn($cs) => (int)$cs['subject_id'], $courseSubjects);
        $availableSubjects = array_filter($allSubjects, fn($s) => !in_array((int)$s['id'], $usedSubjectIds, true));
        ?>

        <?php if ($availableSubjects): ?>
            <form method="post" style="margin-bottom:20px;padding-bottom:20px;border-bottom:1px dashed var(--line);">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_course_subject">
                <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                    <div class="field" style="flex:1;min-width:260px;">
                        <label for="subjectToAdd">Add a subject</label>
                        <select id="subjectToAdd" name="subject_id" required>
                            <option value="">— Choose a subject —</option>
                            <?php foreach ($availableSubjects as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>">
                                    <?php echo e(($s['icon'] ?: '📖') . ' ' . $s['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit">＋ Add subject</button>
                </div>
                <small style="display:block;margin-top:6px;color:var(--muted);">
                    New subjects start with a 100-point budget split <strong>30 · 20 · 50</strong> (Weekly · Monthly · Final).
                    Tune each bucket right after adding.
                </small>
            </form>
        <?php elseif (empty($allSubjects)): ?>
            <p class="empty">No subjects exist in your academy yet. Create them in Subjects first.</p>
        <?php else: ?>
            <p class="empty">All available subjects are already in this course.</p>
        <?php endif; ?>

        <?php if (!$courseSubjects): ?>
            <p class="empty">No subjects added to this course yet.</p>
        <?php else: ?>
            <div style="display:grid;gap:14px;">
                <?php foreach ($courseSubjects as $cs):
                    $sid = (int)$cs['subject_id'];
                    $cfg = [
                        'passing_score'           => (int)($cs['passing_score']           ?? 60),
                        'quiz_total_points'       => (int)($cs['quiz_total_points']       ?? 30),
                        'exam_total_points'       => (int)($cs['exam_total_points']       ?? 20),
                        'final_total_points'      => (int)($cs['final_total_points']      ?? 50),
                        'supplementary_max_score' => (int)($cs['supplementary_max_score'] ?? 90),
                        'weekly_exam_count'       => (int)($cs['weekly_exam_count']       ?? 7),
                        'monthly_exam_count'      => (int)($cs['monthly_exam_count']      ?? 2),
                        'final_exam_count'        => (int)($cs['final_exam_count']        ?? 1),
                    ];
                    $pointsBudget = $cfg['quiz_total_points'] + $cfg['exam_total_points'] + $cfg['final_total_points'];

                    // Scheduled vs. budget status
                    $usage = $scheduledUsage[$sid] ?? ['quiz' => 0, 'exam' => 0, 'quiz_count' => 0, 'exam_count' => 0];
                    $quizUsed = (int)$usage['quiz'];
                    $examUsed = (int)$usage['exam'];

                    // Determine the badge
                    $usageClass = 'ok';
                    $usageTitle = 'All scheduled items fit within budget';
                    if ($quizUsed > $cfg['quiz_total_points'] || $examUsed > $cfg['exam_total_points']) {
                        $usageClass = 'over';
                        $usageTitle = 'Scheduled items exceed the current budget — reduce them or raise the budget';
                    } elseif (
                        ($cfg['quiz_total_points'] > 0 && $quizUsed === $cfg['quiz_total_points']) ||
                        ($cfg['exam_total_points'] > 0 && $examUsed === $cfg['exam_total_points'])
                    ) {
                        $usageClass = 'warn';
                        $usageTitle = 'Scheduled items use the full budget — no more room in that bucket';
                    } elseif ($quizUsed === 0 && $examUsed === 0) {
                        $usageClass = 'muted';
                        $usageTitle = 'Nothing scheduled yet in this subject';
                    }
                ?>
                    <form method="post" class="subject-config" data-subject-form>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="save_course_subject">
                        <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                        <input type="hidden" name="subject_id" value="<?php echo $sid; ?>">

                        <div class="subject-config-header">
                            <div class="name-wrap">
                                <span style="font-size:20px;"><?php echo e($cs['icon'] ?: '📖'); ?></span>
                                <span class="name"><?php echo e($cs['name']); ?></span>
                                <span class="badge <?php echo $pointsBudget >= 1 ? 'success' : 'danger'; ?>" data-points-badge>
                                    budget: <?php echo $pointsBudget; ?> pts
                                </span>
                                <span class="usage-badge <?php echo $usageClass; ?>" title="<?php echo e($usageTitle); ?>">
                                    📅 <?php echo $quizUsed; ?>/<?php echo $cfg['quiz_total_points']; ?> quiz
                                    · <?php echo $examUsed; ?>/<?php echo $cfg['exam_total_points']; ?> exam
                                </span>
                            </div>
                            <div>
                                <button type="submit"
                                        formaction="admin_courses.php?course_id=<?php echo (int)$course['id']; ?>&do=remove"
                                        formmethod="post"
                                        name="action" value="remove_course_subject"
                                        class="btn btn-sm btn-danger"
                                        onclick="return confirm('Remove this subject from the course?');">
                                    ✕ Remove
                                </button>
                            </div>
                        </div>

                        <div class="form-grid subject-config-grid">
                            <div class="field">
                                <label>Passmark %</label>
                                <input type="number" name="passing_score" min="1" max="100"
                                       value="<?php echo $cfg['passing_score']; ?>" required>
                                <small>Student passes if overall grade ≥ this.</small>
                            </div>
                            <div class="field">
                                <label>Weekly (quiz) total points</label>
                                <input type="number" name="quiz_total_points" min="0" max="999"
                                       value="<?php echo $cfg['quiz_total_points']; ?>"
                                       data-points-input required>
                                <small>Ceiling for all scheduled quizzes combined.</small>
                            </div>
                            <div class="field">
                                <label>Monthly (exam) total points</label>
                                <input type="number" name="exam_total_points" min="0" max="999"
                                       value="<?php echo $cfg['exam_total_points']; ?>"
                                       data-points-input required>
                                <small>Ceiling for all scheduled monthly exams combined.</small>
                            </div>
                            <div class="field">
                                <label>Final exam total points</label>
                                <input type="number" name="final_total_points" min="0" max="999"
                                       value="<?php echo $cfg['final_total_points']; ?>"
                                       data-points-input required>
                                <small>Point value of the final exam.</small>
                            </div>
                            <div class="field">
                                <label>Weekly exam count</label>
                                <input type="number" name="weekly_exam_count" min="0" max="52"
                                       value="<?php echo $cfg['weekly_exam_count']; ?>">
                                <small>Used only for display. Doesn't affect grading.</small>
                            </div>
                            <div class="field">
                                <label>Monthly exam count</label>
                                <input type="number" name="monthly_exam_count" min="0" max="12"
                                       value="<?php echo $cfg['monthly_exam_count']; ?>">
                                <small>Used only for display.</small>
                            </div>
                            <div class="field">
                                <label>Final exam count</label>
                                <input type="number" name="final_exam_count" min="0" max="5"
                                       value="<?php echo $cfg['final_exam_count']; ?>">
                                <small>Used only for display.</small>
                            </div>
                            <div class="field">
                                <label>Supplementary max score</label>
                                <input type="number" name="supplementary_max_score" min="1" max="999"
                                       value="<?php echo $cfg['supplementary_max_score']; ?>">
                                <small>Cap on the supplementary's raw score.</small>
                            </div>
                        </div>

                        <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                            <button type="submit" class="btn btn-primary btn-sm">💾 Save config</button>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ======================= ENROLLED STUDENTS ======================= -->
    <section class="card">
        <h2>
            <span>Enrolled students</span>
            <span class="badge brand"><?php echo count($enrolledStudents); ?> total</span>
        </h2>

        <div class="stats" style="margin-bottom:16px;">
            <div class="stat"><span>Active</span><strong><?php echo $statusCounts['active']; ?></strong></div>
            <div class="stat"><span>Completed</span><strong><?php echo $statusCounts['completed']; ?></strong></div>
            <div class="stat"><span>Dropped</span><strong><?php echo $statusCounts['dropped']; ?></strong></div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Started</th>
                        <th>Starting division</th>
                        <th>Current division</th>
                        <th>Division status</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$enrolledStudents): ?>
                    <tr><td colspan="7" class="empty">No students enrolled yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($enrolledStudents as $es):
                    $esStart = new DateTimeImmutable((string)$es['started_at']);
                    $esBadge = $es['enrollment_status'] === 'active' ? 'brand' : ($es['enrollment_status'] === 'completed' ? 'accent' : 'muted');

                    $startDivName = '—';
                    $curDivName = '—';
                    foreach ($divisions as $d) {
                        if ((int)$d['id'] === (int)$es['starting_division_id']) {
                            $startDivName = $d['name'];
                        }
                        if ((int)$d['id'] === (int)$es['current_division_id']) {
                            $curDivName = $d['name'];
                        }
                    }

                    $divStatus = (string)($es['division_status'] ?? 'pending');
                    $divStatusBadge = 'muted';
                    if ($divStatus === 'in_progress') $divStatusBadge = 'accent';
                    elseif ($divStatus === 'passed') $divStatusBadge = 'success';
                    elseif ($divStatus === 'failed') $divStatusBadge = 'danger';
                    elseif ($divStatus === 'completed') $divStatusBadge = 'brand';
                ?>
                    <tr>
                        <td>
                            <strong><?php echo e($es['username']); ?></strong>
                            <br><span style="font-size:12px;color:var(--muted);"><?php echo e($es['email']); ?></span>
                        </td>
                        <td><?php echo e($esStart->format('d M Y')); ?></td>
                        <td><?php echo e($startDivName); ?></td>
                        <td><?php echo e($curDivName); ?></td>
                        <td><span class="badge <?php echo $divStatusBadge; ?>"><?php echo e(ucfirst($divStatus)); ?></span></td>
                        <td><span class="badge <?php echo $esBadge; ?>"><?php echo e(ucfirst($es['enrollment_status'])); ?></span></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-primary" href="admin_course_cohort.php?enrollment_id=<?php echo (int)$es['enrollment_id']; ?>">View →</a>
                            <form method="post" data-confirm="Remove this student's enrollment completely?">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="remove_student">
                                <input type="hidden" name="enrollment_id" value="<?php echo (int)$es['enrollment_id']; ?>">
                                <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit" title="Delete the enrollment record">✕</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ======================= GROUPS ======================= -->
    <section class="card">
        <h2>
            <span>Groups · optional labels</span>
            <button class="btn btn-primary" type="button" id="newCohortBtn">＋ New group</button>
        </h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Group</th><th>Reference dates</th><th>Deadline</th><th>Students</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (!$cohorts): ?>
                    <tr><td colspan="6" class="empty">No groups yet. Groups are optional.</td></tr>
                <?php endif; ?>
                <?php foreach ($cohorts as $h):
                    [$phaseLabel, $phaseClass] = cohortPhase($h, $today);
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
                        <td><?php echo e(date('d M Y', strtotime($h['start_date']))); ?> → <?php echo e(date('d M Y', strtotime($h['enrollment_deadline']))); ?></td>
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
                            <a class="btn btn-sm btn-primary" href="admin_course_cohort.php?cohort_id=<?php echo (int)$h['id']; ?>">Manage group</a>
                            <button class="btn btn-sm" type="button" data-edit-cohort="<?php echo e(json_encode($cohortData, $jsonFlags)); ?>">Edit</button>
                            <form method="post" data-confirm="Delete this group?">
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
    </section>

    <!-- Group dialog -->
    <dialog id="cohortDialog">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_cohort">
            <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
            <input type="hidden" name="id" id="cohortId" value="0">
            <h3 id="cohortDialogTitle">New group</h3>
            <div class="form-grid">
                <div class="field wide">
                    <label for="cohortName">Group name</label>
                    <input type="text" id="cohortName" name="name" maxlength="255" placeholder="e.g. January 2027 intake" required>
                </div>
                <div class="field">
                    <label for="cohortStart">Reference start date</label>
                    <input type="date" id="cohortStart" name="start_date" required>
                </div>
                <div class="field">
                    <label for="cohortDeadline">Reference deadline</label>
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
                <button class="btn btn-primary" type="submit">Save group</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

    <!-- ======================= COURSE DIALOG ======================= -->
    <dialog id="courseDialog">
        <form method="post" enctype="multipart/form-data">
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

                <div class="field wide">
                    <label>Cover image</label>
                    <div class="cover-uploader">
                        <div class="cover-preview" id="coverPreview">
                            <span>📖</span>
                        </div>
                        <div class="cover-body">
                            <label for="coverFileInput">Choose a cover (JPG, PNG, WEBP, GIF · max 4 MB)</label>
                            <input type="file" id="coverFileInput" name="cover_file" accept="image/jpeg,image/png,image/webp,image/gif">
                            <div class="hint" id="coverHint">No image selected — a gradient tile will be shown instead.</div>
                            <input type="hidden" name="clear_cover" id="clearCoverField" value="0">
                            <div class="actions">
                                <button type="button" class="cover-clear-btn" id="clearCoverBtn" style="display:none;">
                                    ✕ Remove current image
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="courseDivisionType">How is this course divided?</label>
                    <select id="courseDivisionType" name="division_type">
                        <option value="none">No divisions (single run)</option>
                        <option value="weeks">By weeks</option>
                        <option value="months">By months</option>
                        <option value="terms">By terms</option>
                        <option value="semesters">By semesters</option>
                        <option value="years">By years</option>
                        <option value="custom">Custom divisions</option>
                    </select>
                    <small id="divisionTypeHint">Divisions are the blocks a student progresses through.</small>
                </div>
                <div class="field" id="divisionLabelField">
                    <label for="courseDivisionLabel">What do you call each division?</label>
                    <input type="text" id="courseDivisionLabel" name="division_label" maxlength="100" placeholder="e.g. Semester">
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
    // ---- Dark mode ----
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

    // ---- Course dialog ----
    const courseDialog = document.getElementById('courseDialog');
    const divisionTypeSel = document.getElementById('courseDivisionType');
    const divisionLabelField = document.getElementById('divisionLabelField');
    const divisionLabelInput = document.getElementById('courseDivisionLabel');
    const divisionTypeHint = document.getElementById('divisionTypeHint');

    const coverInput = document.getElementById('coverFileInput');
    const coverPreview = document.getElementById('coverPreview');
    const coverHint = document.getElementById('coverHint');
    const clearCoverBtn = document.getElementById('clearCoverBtn');
    const clearCoverField = document.getElementById('clearCoverField');

    const DEFAULT_LABELS = {
        'none': '', 'weeks': 'Week', 'months': 'Month',
        'terms': 'Term', 'semesters': 'Semester',
        'years': 'Year', 'custom': 'Stage'
    };

    let currentCoverUrl = '';
    let currentDivisionType = null;

    function syncDivisionLabel() {
        const type = divisionTypeSel.value;
        if (type === 'none') {
            divisionLabelField.style.display = 'none';
        } else {
            divisionLabelField.style.display = '';
            if (!divisionLabelInput.value || Object.values(DEFAULT_LABELS).includes(divisionLabelInput.value)) {
                divisionLabelInput.value = DEFAULT_LABELS[type] || '';
            }
        }

        // Warn when the admin tries to change type while divisions exist.
        if (currentDivisionType !== null && currentDivisionType !== type && divisionsExist) {
            divisionTypeHint.textContent = '⚠️ This course already has divisions — the type cannot be changed.';
            divisionTypeHint.style.color = 'var(--danger)';
            divisionTypeSel.value = currentDivisionType;
        } else {
            divisionTypeHint.textContent = 'Divisions are the blocks a student progresses through.';
            divisionTypeHint.style.color = '';
        }
    }
    divisionTypeSel.addEventListener('change', syncDivisionLabel);

    let divisionsExist = <?php echo $course && count($divisions) > 0 ? 'true' : 'false'; ?>;

    function setCoverPreviewFromUrl(url) {
        currentCoverUrl = url || '';
        if (url) {
            coverPreview.innerHTML = '<img src="' + url + '" alt="">';
            coverHint.textContent = 'Current image will be replaced if you choose a new file.';
            clearCoverBtn.style.display = '';
        } else {
            coverPreview.innerHTML = '<span>📖</span>';
            coverHint.textContent = 'No image selected — a gradient tile will be shown instead.';
            clearCoverBtn.style.display = 'none';
        }
        clearCoverField.value = '0';
    }

    coverInput.addEventListener('change', () => {
        const f = coverInput.files && coverInput.files[0];
        if (!f) {
            setCoverPreviewFromUrl(currentCoverUrl);
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            coverPreview.innerHTML = '<img src="' + e.target.result + '" alt="">';
            coverHint.textContent = f.name + ' · ' + Math.round(f.size / 1024) + ' KB — not saved yet.';
            clearCoverBtn.style.display = '';
            clearCoverField.value = '0';
        };
        reader.readAsDataURL(f);
    });

    if (clearCoverBtn) {
        clearCoverBtn.addEventListener('click', () => {
            coverInput.value = '';
            setCoverPreviewFromUrl('');
            clearCoverField.value = '1';
            coverHint.textContent = 'Image will be removed when you save.';
        });
    }

    document.querySelectorAll('[data-open="courseDialog"]').forEach(btn => {
        btn.addEventListener('click', () => {
            const editing = btn.hasAttribute('data-edit-course');
            document.getElementById('courseDialogTitle').textContent = editing ? 'Edit course' : 'New course';
            clearCoverField.value = '0';
            coverInput.value = '';

            <?php if ($course): ?>
            const current = <?php echo json_encode([
                'id' => (int)$course['id'],
                'title' => $course['title'],
                'description' => (string)($course['description'] ?? ''),
                'division_type' => (string)($course['division_type'] ?? 'none'),
                'division_label' => (string)($course['division_label'] ?? ''),
                'status' => $course['status'],
                'cover_url' => $currentCoverOk ? ('../' . ltrim($currentCover, '/')) : '',
            ], $jsonFlags); ?>;
            if (editing) {
                document.getElementById('courseIdField').value = current.id;
                document.getElementById('courseTitle').value = current.title;
                document.getElementById('courseDescription').value = current.description;
                document.getElementById('courseDivisionType').value = current.division_type;
                document.getElementById('courseDivisionLabel').value = current.division_label;
                document.getElementById('courseStatus').value = current.status;
                setCoverPreviewFromUrl(current.cover_url);
                currentDivisionType = current.division_type;
            } else {
                document.getElementById('courseIdField').value = '0';
                document.getElementById('courseTitle').value = '';
                document.getElementById('courseDescription').value = '';
                document.getElementById('courseDivisionType').value = 'none';
                document.getElementById('courseDivisionLabel').value = '';
                document.getElementById('courseStatus').value = 'draft';
                setCoverPreviewFromUrl('');
                currentDivisionType = null;
            }
            <?php else: ?>
            document.getElementById('courseIdField').value = '0';
            document.getElementById('courseTitle').value = '';
            document.getElementById('courseDescription').value = '';
            document.getElementById('courseDivisionType').value = 'none';
            document.getElementById('courseDivisionLabel').value = '';
            document.getElementById('courseStatus').value = 'draft';
            setCoverPreviewFromUrl('');
            currentDivisionType = null;
            <?php endif; ?>

            syncDivisionLabel();
            courseDialog.showModal();
        });
    });

    <?php if ($course && $divisionType !== 'none'): ?>
    // ---- Division: live weeks computation ----
    const divisionForm = document.getElementById('divisionsForm');
    if (divisionForm) {
        function updateWeeksCount() {
            const rows = divisionForm.querySelectorAll('tr[data-div-row]');
            rows.forEach(row => {
                const start = row.querySelector('input[name="div_start[]"]');
                const end = row.querySelector('input[name="div_end[]"]');
                const cell = row.querySelector('[data-weeks-cell]');
                if (!start || !end || !cell) return;
                if (!start.value || !end.value) { cell.textContent = '—'; return; }
                const s = new Date(start.value + 'T00:00:00');
                const e = new Date(end.value + 'T00:00:00');
                if (isNaN(s) || isNaN(e) || e < s) { cell.textContent = '⚠️'; return; }
                const days = Math.floor((e - s) / 86400000) + 1;
                cell.textContent = Math.ceil(days / 7) + ' weeks';
            });
        }
        divisionForm.addEventListener('input', e => {
            if (e.target.matches('input[name="div_start[]"], input[name="div_end[]"]')) {
                updateWeeksCount();
            }
        });
        updateWeeksCount();

        divisionForm.querySelectorAll('[data-delete-div]').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.deleteDiv;
                if (!confirm('Delete this division? This cannot be undone.')) return;
                document.getElementById('deleteDivisionId').value = id;
                document.getElementById('deleteDivisionForm').submit();
            });
        });
    }
    <?php endif; ?>

    // ---- Subject form: live points budget summary ----
    document.querySelectorAll('[data-subject-form]').forEach(form => {
        const pointsInputs = form.querySelectorAll('[data-points-input]');
        const badge = form.querySelector('[data-points-badge]');

        function updatePointsSum() {
            let sum = 0;
            pointsInputs.forEach(inp => {
                sum += parseInt(inp.value || '0', 10) || 0;
            });
            if (!badge) return;
            if (sum >= 1) {
                badge.textContent = 'budget: ' + sum + ' pts';
                badge.classList.remove('danger');
                badge.classList.add('success');
            } else {
                badge.textContent = 'budget: 0 pts ⚠️';
                badge.classList.remove('success');
                badge.classList.add('danger');
            }
        }
        pointsInputs.forEach(inp => inp.addEventListener('input', updatePointsSum));
        updatePointsSum();
    });

    <?php if ($course): ?>
    // ---- Group dialog ----
    const cohortDialog = document.getElementById('cohortDialog');
    function openCohort(data) {
        document.getElementById('cohortDialogTitle').textContent = data ? 'Edit group' : 'New group';
        document.getElementById('cohortId').value = data ? data.id : 0;
        document.getElementById('cohortName').value = data ? data.name : '';
        document.getElementById('cohortStart').value = data ? data.start_date : '';
        document.getElementById('cohortDeadline').value = data ? data.enrollment_deadline : '';
        document.getElementById('cohortCapacity').value = data ? data.capacity : '';
        document.getElementById('cohortStatus').value = data ? data.status : 'draft';
        cohortDialog.showModal();
    }
    const newCohortBtn = document.getElementById('newCohortBtn');
    if (newCohortBtn) newCohortBtn.addEventListener('click', () => openCohort(null));
    document.querySelectorAll('[data-edit-cohort]').forEach(btn => {
        btn.addEventListener('click', () => openCohort(JSON.parse(btn.dataset.editCohort)));
    });
    <?php endif; ?>
})();
</script>
</body>
</html>