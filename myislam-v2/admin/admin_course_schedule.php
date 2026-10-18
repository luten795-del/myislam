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
// AUTH
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
// HELPERS
// ============================================================================

const ITEM_TABLES = ['content' => 'contents', 'quiz' => 'quizzes', 'exam' => 'monthly_exams'];
const ITEM_LABELS = ['content' => 'Lesson', 'quiz' => 'Quiz', 'exam' => 'Exam'];
const WEEKDAYS = [
    1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
    5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
];

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

// ============================================================================
// SCHEDULE SYNC HELPERS
// ============================================================================

/**
 * Force a scheduled quiz into competition mode AND stamp its academy link.
 * Called after inserting a quiz item into course_schedule.
 *
 * course_id / division_id / week_number on the quizzes row are what
 * quiz_submit.php checks to decide whether to feed the academy grade book.
 */
function syncQuizAsCompetition(
    PDO $conn,
    int $quizId,
    int $courseId,
    int $divisionId,
    int $weekNumber
): void {
    try {
        $conn->prepare("
            UPDATE quizzes
            SET mode        = 'competition',
                course_id   = ?,
                division_id = ?,
                week_number = ?
            WHERE id = ?
        ")->execute([$courseId, $divisionId, $weekNumber, $quizId]);
    } catch (PDOException $e) {
        error_log('syncQuizAsCompetition error: ' . $e->getMessage());
    }
}

/**
 * Clear a quiz's academy link when it is unscheduled.
 *
 * Safe: if the quiz is still referenced by ANOTHER schedule row (in a
 * different division of the same course), the link is left intact.
 */
function clearQuizScheduleLink(PDO $conn, int $quizId): void
{
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM course_schedule
            WHERE item_type = 'quiz' AND item_id = ?
        ");
        $stmt->execute([$quizId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return; // still scheduled elsewhere — leave the link alone
        }

        $conn->prepare("
            UPDATE quizzes
            SET course_id   = NULL,
                division_id = NULL,
                week_number = NULL
            WHERE id = ?
        ")->execute([$quizId]);
    } catch (PDOException $e) {
        error_log('clearQuizScheduleLink error: ' . $e->getMessage());
    }
}

/**
 * Link a monthly exam to its division and week.
 * Called after inserting an exam item into course_schedule.
 */
function syncMonthlyExamDivision(PDO $conn, int $examId, int $divisionId, int $weekNumber): void
{
    try {
        $conn->prepare("
            UPDATE monthly_exams
            SET division_id = ?, week_number = ?
            WHERE id = ?
        ")->execute([$divisionId, $weekNumber, $examId]);
    } catch (PDOException $e) {
        error_log('syncMonthlyExamDivision error: ' . $e->getMessage());
    }
}

/**
 * Clear a monthly exam's division/week link when unscheduled.
 *
 * Safe: if the exam is still referenced by ANOTHER schedule row, the
 * link is left intact.
 */
function clearMonthlyExamDivision(PDO $conn, int $examId): void
{
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM course_schedule
            WHERE item_type = 'exam' AND item_id = ?
        ");
        $stmt->execute([$examId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return; // still scheduled elsewhere
        }

        $conn->prepare("
            UPDATE monthly_exams
            SET division_id = NULL, week_number = NULL
            WHERE id = ?
        ")->execute([$examId]);
    } catch (PDOException $e) {
        error_log('clearMonthlyExamDivision error: ' . $e->getMessage());
    }
}

// ============================================================================
// BUDGET ENFORCEMENT HELPERS
// ============================================================================

/**
 * Sum the raw points of every scheduled quiz or exam already in a bucket
 * for this course+subject, excluding one schedule row id (used on update).
 *
 * Returns [count, points] for the bucket:
 *   $itemType 'quiz' → weekly bucket (budget = course_subjects.quiz_total_points)
 *   $itemType 'exam' → monthly bucket (budget = course_subjects.exam_total_points)
 */
function getScheduledBucketUsage(
    PDO $conn,
    int $courseId,
    int $subjectId,
    string $itemType,
    int $excludeScheduleId = 0
): array {
    if (!in_array($itemType, ['quiz', 'exam'], true)) {
        return [0, 0.0];
    }

    $table = $itemType === 'quiz' ? 'quizzes' : 'monthly_exams';

    $sql = "
        SELECT COUNT(*) AS cnt, COALESCE(SUM(i.total_points), 0) AS pts
        FROM course_schedule cs
        JOIN `$table` i ON i.id = cs.item_id
        WHERE cs.course_id = ?
          AND cs.subject_id = ?
          AND cs.item_type = ?
    ";
    $params = [$courseId, $subjectId, $itemType];

    if ($excludeScheduleId > 0) {
        $sql .= " AND cs.id <> ?";
        $params[] = $excludeScheduleId;
    }

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [(int)($row['cnt'] ?? 0), (float)($row['pts'] ?? 0)];
    } catch (PDOException $e) {
        error_log('getScheduledBucketUsage error: ' . $e->getMessage());
        return [0, 0.0];
    }
}

/**
 * Fetch a subject's point budget for a bucket, or null if unset.
 */
function getSubjectBucketBudget(PDO $conn, int $courseId, int $subjectId, string $itemType): ?int
{
    $column = $itemType === 'quiz'  ? 'quiz_total_points'
            : ($itemType === 'exam' ? 'exam_total_points'
            : null);
    if ($column === null) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT `$column` FROM course_subjects
            WHERE course_id = ? AND subject_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $subjectId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) {
            return null;
        }
        return (int)$val;
    } catch (PDOException $e) {
        error_log('getSubjectBucketBudget error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Validate a proposed schedule addition against the bucket budget.
 * Returns null if OK, or a human error message if it would exceed.
 *
 * @param int $excludeScheduleId  Row id to exclude from existing usage
 *                                (0 on insert; the row id on update)
 */
function validateScheduleBudget(
    PDO $conn,
    int $courseId,
    int $subjectId,
    string $itemType,
    int $itemId,
    int $excludeScheduleId = 0
): ?string {
    // Only quiz and exam items consume grade-book budget.
    if (!in_array($itemType, ['quiz', 'exam'], true)) {
        return null;
    }

    $budget = getSubjectBucketBudget($conn, $courseId, $subjectId, $itemType);
    if ($budget === null || $budget <= 0) {
        // No budget configured — allow. The grade book treats the subject
        // as ungraded (budget <= 0 → course_grade stays NULL).
        return null;
    }

    [$existingCount, $existingPts] = getScheduledBucketUsage(
        $conn, $courseId, $subjectId, $itemType, $excludeScheduleId
    );

    $table = $itemType === 'quiz' ? 'quizzes' : 'monthly_exams';
    try {
        $stmt = $conn->prepare("SELECT total_points FROM `$table` WHERE id = ? LIMIT 1");
        $stmt->execute([$itemId]);
        $newPts = (float)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('validateScheduleBudget lookup: ' . $e->getMessage());
        return null; // fail open — don't block the admin on a lookup error
    }

    $projected = $existingPts + $newPts;

    if ($projected > $budget) {
        $label = $itemType === 'quiz' ? 'weekly (quiz)' : 'monthly (exam)';
        return sprintf(
            'This would exceed the %s budget for this subject: '
            . 'already scheduled %s pts out of %d, adding %s pts would total %s. '
            . 'Increase the subject\'s budget in the course editor or pick a smaller item.',
            $label,
            number_format($existingPts, 2),
            $budget,
            number_format($newPts, 2),
            number_format($projected, 2)
        );
    }

    return null;
}

// ============================================================================
// LOAD DIVISION + COURSE
// ============================================================================

$divisionId = (int)($_GET['division_id'] ?? $_POST['division_id'] ?? 0);
if ($divisionId <= 0) {
    flash('error', 'Division not found.');
    redirectTo('admin_courses.php');
}

$stmt = $conn->prepare("
    SELECT d.*, c.title AS course_title, c.division_label, c.division_type, c.id AS course_id
    FROM course_divisions d
    JOIN courses c ON c.id = d.course_id
    WHERE d.id = ?
");
$stmt->execute([$divisionId]);
$division = $stmt->fetch();
if (!$division) {
    flash('error', 'Division not found.');
    redirectTo('admin_courses.php');
}

$courseId = (int)$division['course_id'];
$divisionLabel = (string)($division['division_label'] ?? 'Division');

// Compute weeks in this division
$dStart = new DateTimeImmutable((string)$division['start_date']);
$dEnd = new DateTimeImmutable((string)$division['end_date']);
$days = $dStart->diff($dEnd)->days + 1;
$totalWeeks = max(1, (int)ceil($days / 7));

// ============================================================================
// POST ACTIONS
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Invalid or expired request token. Go back, refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    $back = 'admin_course_schedule.php?division_id=' . $divisionId;
    if (!empty($_POST['week'])) {
        $back .= '&week=' . (int)$_POST['week'];
    }

    try {
        switch ($action) {

            // ----------------------------------------------------------------
            case 'add_schedule_item': {
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                $itemType = (string)($_POST['item_type'] ?? '');
                $itemId = (int)($_POST['item_id'] ?? 0);
                $weekNumber = (int)($_POST['week_number'] ?? 0);
                $weekday = (int)($_POST['weekday'] ?? 0);
                $timeRaw = trim((string)($_POST['start_time'] ?? ''));
                $durationRaw = trim((string)($_POST['duration_minutes'] ?? ''));
                $duration = $durationRaw === '' ? null : (int)$durationRaw;

                if ($subjectId <= 0) {
                    flash('error', 'Choose a subject.');
                    break;
                }
                if (!isset(ITEM_TABLES[$itemType]) || $itemId <= 0) {
                    flash('error', 'Choose a lesson, quiz or exam.');
                    break;
                }
                if ($weekNumber < 1 || $weekNumber > $totalWeeks) {
                    flash('error', "Week must be between 1 and $totalWeeks.");
                    break;
                }
                if ($weekday < 1 || $weekday > 7) {
                    flash('error', 'Invalid weekday.');
                    break;
                }

                // Verify the subject belongs to this course
                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_subjects WHERE course_id = ? AND subject_id = ?");
                $stmt->execute([$courseId, $subjectId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    flash('error', 'That subject is not part of this course.');
                    break;
                }

                // Verify the item exists AND belongs to the selected subject
                $table = ITEM_TABLES[$itemType];
                $stmt = $conn->prepare("SELECT subject_id FROM `$table` WHERE id = ?");
                $stmt->execute([$itemId]);
                $itemRow = $stmt->fetch();
                if (!$itemRow) {
                    flash('error', 'That item no longer exists.');
                    break;
                }
                if ((int)($itemRow['subject_id'] ?? 0) !== $subjectId) {
                    flash('error', 'The chosen item does not belong to the selected subject.');
                    break;
                }

                // Enforce the bucket budget (quizzes → weekly, exams → monthly)
                $budgetError = validateScheduleBudget($conn, $courseId, $subjectId, $itemType, $itemId);
                if ($budgetError !== null) {
                    flash('error', $budgetError);
                    break;
                }

                // Normalize time
                $startTime = null;
                if ($timeRaw !== '') {
                    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeRaw)) {
                        $startTime = $timeRaw . (strlen($timeRaw) === 5 ? ':00' : '');
                    } else {
                        flash('error', 'Invalid time format.');
                        break;
                    }
                }

                // Compute sort_order as max+1 in same cell
                $stmt = $conn->prepare("
                    SELECT COALESCE(MAX(sort_order), 0) FROM course_schedule
                    WHERE division_id = ? AND week_number = ? AND weekday = ?
                ");
                $stmt->execute([$divisionId, $weekNumber, $weekday]);
                $nextSort = (int)$stmt->fetchColumn() + 1;

                try {
                    $stmt = $conn->prepare("
                        INSERT INTO course_schedule
                            (course_id, division_id, subject_id, item_type, item_id,
                             week_number, weekday, start_time, duration_minutes, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $courseId, $divisionId, $subjectId, $itemType, $itemId,
                        $weekNumber, $weekday, $startTime, $duration, $nextSort,
                    ]);

                    // Sync the underlying item to its schedule slot
                    if ($itemType === 'quiz') {
                        syncQuizAsCompetition($conn, $itemId, $courseId, $divisionId, $weekNumber);
                    } elseif ($itemType === 'exam') {
                        syncMonthlyExamDivision($conn, $itemId, $divisionId, $weekNumber);
                    }

                    flash('success', 'Scheduled.');
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        flash('error', 'That item is already scheduled somewhere in this course.');
                    } else {
                        throw $e;
                    }
                }
                break;
            }

            // ----------------------------------------------------------------
            case 'delete_schedule_item': {
                $id = (int)($_POST['id'] ?? 0);

                // Load the item_type and item_id BEFORE deleting
                $stmt = $conn->prepare("
                    SELECT item_type, item_id FROM course_schedule
                    WHERE id = ? AND division_id = ?
                ");
                $stmt->execute([$id, $divisionId]);
                $row = $stmt->fetch();

                $conn->prepare("DELETE FROM course_schedule WHERE id = ? AND division_id = ?")
                     ->execute([$id, $divisionId]);

                // Unlink the underlying item (safe: checks other schedule rows)
                if ($row && $row['item_type'] === 'exam') {
                    clearMonthlyExamDivision($conn, (int)$row['item_id']);
                } elseif ($row && $row['item_type'] === 'quiz') {
                    clearQuizScheduleLink($conn, (int)$row['item_id']);
                }

                flash('success', 'Removed from schedule.');
                break;
            }

            // ----------------------------------------------------------------
            case 'update_schedule_item': {
                $id           = (int)($_POST['id'] ?? 0);
                $subjectId    = (int)($_POST['subject_id'] ?? 0);
                $itemType     = (string)($_POST['item_type'] ?? '');
                $itemId       = (int)($_POST['item_id'] ?? 0);
                $weekNumber   = (int)($_POST['week_number'] ?? 0);
                $weekday      = (int)($_POST['weekday'] ?? 0);
                $timeRaw      = trim((string)($_POST['start_time'] ?? ''));
                $durationRaw  = trim((string)($_POST['duration_minutes'] ?? ''));

                if ($id <= 0) {
                    flash('error', 'Invalid schedule row.');
                    break;
                }

                // Verify the row belongs to this division AND capture old item
                $stmt = $conn->prepare("
                    SELECT item_type, item_id FROM course_schedule
                    WHERE id = ? AND division_id = ?
                ");
                $stmt->execute([$id, $divisionId]);
                $oldRow = $stmt->fetch();
                if (!$oldRow) {
                    flash('error', 'That scheduled item was not found.');
                    break;
                }

                if ($subjectId <= 0) {
                    flash('error', 'Choose a subject.');
                    break;
                }
                if (!isset(ITEM_TABLES[$itemType]) || $itemId <= 0) {
                    flash('error', 'Choose a lesson, quiz or exam.');
                    break;
                }
                if ($weekNumber < 1 || $weekNumber > $totalWeeks) {
                    flash('error', "Week must be between 1 and $totalWeeks.");
                    break;
                }
                if ($weekday < 1 || $weekday > 7) {
                    flash('error', 'Invalid weekday.');
                    break;
                }

                // Verify subject belongs to this course
                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_subjects WHERE course_id = ? AND subject_id = ?");
                $stmt->execute([$courseId, $subjectId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    flash('error', 'That subject is not part of this course.');
                    break;
                }

                // Verify item exists and belongs to that subject
                $table = ITEM_TABLES[$itemType];
                $stmt = $conn->prepare("SELECT subject_id FROM `$table` WHERE id = ?");
                $stmt->execute([$itemId]);
                $itemRow = $stmt->fetch();
                if (!$itemRow) {
                    flash('error', 'That item no longer exists.');
                    break;
                }
                if ((int)($itemRow['subject_id'] ?? 0) !== $subjectId) {
                    flash('error', 'The chosen item does not belong to the selected subject.');
                    break;
                }

                // Enforce the bucket budget, excluding this row from usage
                $budgetError = validateScheduleBudget($conn, $courseId, $subjectId, $itemType, $itemId, $id);
                if ($budgetError !== null) {
                    flash('error', $budgetError);
                    break;
                }

                // Normalize time
                $startTime = null;
                if ($timeRaw !== '') {
                    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeRaw)) {
                        $startTime = $timeRaw . (strlen($timeRaw) === 5 ? ':00' : '');
                    } else {
                        flash('error', 'Invalid time format.');
                        break;
                    }
                }

                // Duration
                $duration = null;
                if ($durationRaw !== '') {
                    $duration = (int)$durationRaw;
                    if ($duration < 1) {
                        flash('error', 'Duration must be a positive number of minutes.');
                        break;
                    }
                }

                // Compute sort_order — max+1 in the (possibly new) cell,
                // ignoring this row itself in case the weekday/week changed.
                $stmt = $conn->prepare("
                    SELECT COALESCE(MAX(sort_order), 0)
                    FROM course_schedule
                    WHERE division_id = ? AND week_number = ? AND weekday = ?
                      AND id <> ?
                ");
                $stmt->execute([$divisionId, $weekNumber, $weekday, $id]);
                $nextSort = (int)$stmt->fetchColumn() + 1;

                try {
                    $conn->prepare("
                        UPDATE course_schedule
                        SET subject_id       = ?,
                            item_type        = ?,
                            item_id          = ?,
                            week_number      = ?,
                            weekday          = ?,
                            start_time       = ?,
                            duration_minutes = ?,
                            sort_order       = ?
                        WHERE id = ? AND division_id = ?
                    ")->execute([
                        $subjectId, $itemType, $itemId,
                        $weekNumber, $weekday,
                        $startTime, $duration,
                        $nextSort,
                        $id, $divisionId,
                    ]);

                    // If the underlying item changed, clear the OLD item's link
                    $itemChanged = ((string)$oldRow['item_type'] !== $itemType)
                                || ((int)$oldRow['item_id'] !== $itemId);
                    if ($itemChanged) {
                        if ($oldRow['item_type'] === 'quiz') {
                            clearQuizScheduleLink($conn, (int)$oldRow['item_id']);
                        } elseif ($oldRow['item_type'] === 'exam') {
                            clearMonthlyExamDivision($conn, (int)$oldRow['item_id']);
                        }
                    }

                    // Re-sync the NEW item's academy link
                    if ($itemType === 'quiz') {
                        syncQuizAsCompetition($conn, $itemId, $courseId, $divisionId, $weekNumber);
                    } elseif ($itemType === 'exam') {
                        syncMonthlyExamDivision($conn, $itemId, $divisionId, $weekNumber);
                    }

                    flash('success', 'Schedule item updated.');
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        flash('error', 'That item is already scheduled somewhere in this course.');
                    } else {
                        error_log('update_schedule_item error: ' . $e->getMessage());
                        flash('error', 'Could not update the scheduled item.');
                    }
                }
                break;
            }

            // ----------------------------------------------------------------
            case 'copy_schedule': {
                $sourceDivisionId = (int)($_POST['source_division_id'] ?? 0);
                $mode = (string)($_POST['copy_mode'] ?? 'replace');

                if ($sourceDivisionId <= 0 || $sourceDivisionId === $divisionId) {
                    flash('error', 'Invalid source division.');
                    break;
                }
                if (!in_array($mode, ['replace', 'append'], true)) {
                    $mode = 'replace';
                }

                // Verify source belongs to the same course
                $stmt = $conn->prepare("SELECT course_id FROM course_divisions WHERE id = ?");
                $stmt->execute([$sourceDivisionId]);
                $sourceCourseId = (int)$stmt->fetchColumn();
                if ($sourceCourseId !== $courseId) {
                    flash('error', 'The source division must belong to the same course.');
                    break;
                }

                // Load source rows
                $stmt = $conn->prepare("
                    SELECT subject_id, item_type, item_id, week_number, weekday,
                           start_time, duration_minutes, sort_order
                    FROM course_schedule
                    WHERE division_id = ?
                    ORDER BY week_number, weekday, start_time, sort_order
                ");
                $stmt->execute([$sourceDivisionId]);
                $sourceRows = $stmt->fetchAll();

                if (!$sourceRows) {
                    flash('error', 'The source division has no scheduled items.');
                    break;
                }

                // If replacing, gather the CURRENT exam/quiz item_ids BEFORE deleting
                $oldExamIds = [];
                $oldQuizIds = [];
                if ($mode === 'replace') {
                    $stmt = $conn->prepare("
                        SELECT item_type, item_id FROM course_schedule
                        WHERE division_id = ?
                          AND item_type IN ('quiz','exam')
                    ");
                    $stmt->execute([$divisionId]);
                    foreach ($stmt->fetchAll() as $r) {
                        if ($r['item_type'] === 'exam') $oldExamIds[] = (int)$r['item_id'];
                        else                         $oldQuizIds[] = (int)$r['item_id'];
                    }
                }

                $inserted = 0;
                $skipped = 0;
                $budgetSkipped = 0;
                $conn->beginTransaction();

                try {
                    if ($mode === 'replace') {
                        $conn->prepare("DELETE FROM course_schedule WHERE division_id = ?")
                             ->execute([$divisionId]);
                    }

                    $insert = $conn->prepare("
                        INSERT INTO course_schedule
                            (course_id, division_id, subject_id, item_type, item_id,
                             week_number, weekday, start_time, duration_minutes, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($sourceRows as $r) {
                        $wk = max(1, min($totalWeeks, (int)$r['week_number']));

                        // Skip if subject no longer in the course
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM course_subjects WHERE course_id = ? AND subject_id = ?");
                        $stmt->execute([$courseId, (int)$r['subject_id']]);
                        if ((int)$stmt->fetchColumn() === 0) {
                            $skipped++;
                            continue;
                        }

                        // Budget check BEFORE insert (excluding nothing — the row
                        // doesn't exist yet). If it would overflow, skip and count.
                        if (in_array($r['item_type'], ['quiz', 'exam'], true)) {
                            $budgetError = validateScheduleBudget(
                                $conn,
                                $courseId,
                                (int)$r['subject_id'],
                                (string)$r['item_type'],
                                (int)$r['item_id']
                            );
                            if ($budgetError !== null) {
                                $budgetSkipped++;
                                continue;
                            }
                        }

                        try {
                            $insert->execute([
                                $courseId,
                                $divisionId,
                                (int)$r['subject_id'],
                                $r['item_type'],
                                (int)$r['item_id'],
                                $wk,
                                (int)$r['weekday'],
                                $r['start_time'],
                                $r['duration_minutes'] !== null ? (int)$r['duration_minutes'] : null,
                                (int)$r['sort_order'],
                            ]);
                            $inserted++;
                        } catch (PDOException $e) {
                            if ($e->getCode() === '23000') {
                                $skipped++;
                                continue;
                            }
                            throw $e;
                        }
                    }

                    $conn->commit();

                    // 1) Clear links for anything that was unscheduled (replace mode)
                    foreach ($oldExamIds as $oldId) {
                        clearMonthlyExamDivision($conn, $oldId);
                    }
                    foreach ($oldQuizIds as $oldId) {
                        clearQuizScheduleLink($conn, $oldId);
                    }

                    // 2) Re-sync every exam in this division
                    $stmt = $conn->prepare("
                        SELECT item_id, week_number FROM course_schedule
                        WHERE division_id = ? AND item_type = 'exam'
                    ");
                    $stmt->execute([$divisionId]);
                    foreach ($stmt->fetchAll() as $ex) {
                        syncMonthlyExamDivision(
                            $conn, (int)$ex['item_id'], $divisionId, (int)$ex['week_number']
                        );
                    }

                    // 3) Re-sync every quiz in this division
                    $stmt = $conn->prepare("
                        SELECT item_id, week_number FROM course_schedule
                        WHERE division_id = ? AND item_type = 'quiz'
                    ");
                    $stmt->execute([$divisionId]);
                    foreach ($stmt->fetchAll() as $qz) {
                        syncQuizAsCompetition(
                            $conn, (int)$qz['item_id'], $courseId, $divisionId, (int)$qz['week_number']
                        );
                    }

                } catch (PDOException $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    throw $e;
                }

                $msg = "Copied $inserted item(s) from the source division.";
                if ($skipped > 0) {
                    $msg .= " $skipped skipped (duplicate or subject no longer in course).";
                }
                if ($budgetSkipped > 0) {
                    $msg .= " $budgetSkipped skipped (would exceed bucket budget).";
                }
                flash('success', $msg);
                break;
            }

            // ----------------------------------------------------------------
            case 'copy_week': {
                $sourceWeek = (int)($_POST['source_week'] ?? 0);
                $targetWeek = (int)($_POST['target_week'] ?? 0);
                $mode       = (string)($_POST['copy_week_mode'] ?? 'replace');

                if ($sourceWeek < 1 || $sourceWeek > $totalWeeks
                    || $targetWeek < 1 || $targetWeek > $totalWeeks
                    || $sourceWeek === $targetWeek
                ) {
                    flash('error', 'Invalid source or target week.');
                    break;
                }

                if (!in_array($mode, ['replace', 'append'], true)) {
                    $mode = 'replace';
                }

                // Load source rows for this week
                $stmt = $conn->prepare("
                    SELECT subject_id, item_type, item_id, weekday,
                           start_time, duration_minutes, sort_order
                    FROM course_schedule
                    WHERE division_id = ? AND week_number = ?
                    ORDER BY weekday ASC, start_time ASC, sort_order ASC
                ");
                $stmt->execute([$divisionId, $sourceWeek]);
                $sourceRows = $stmt->fetchAll();

                if (!$sourceRows) {
                    flash('error', "Week $sourceWeek has no scheduled items to copy.");
                    break;
                }

                // If replacing, capture the CURRENT target week's exam/quiz IDs
                $oldExamIds = [];
                $oldQuizIds = [];
                if ($mode === 'replace') {
                    $stmt = $conn->prepare("
                        SELECT item_type, item_id FROM course_schedule
                        WHERE division_id = ? AND week_number = ?
                          AND item_type IN ('quiz','exam')
                    ");
                    $stmt->execute([$divisionId, $targetWeek]);
                    foreach ($stmt->fetchAll() as $r) {
                        if ($r['item_type'] === 'exam') $oldExamIds[] = (int)$r['item_id'];
                        else                         $oldQuizIds[] = (int)$r['item_id'];
                    }
                }

                $inserted = 0;
                $skipped  = 0;
                $budgetSkipped = 0;
                $conn->beginTransaction();

                try {
                    if ($mode === 'replace') {
                        $conn->prepare("
                            DELETE FROM course_schedule
                            WHERE division_id = ? AND week_number = ?
                        ")->execute([$divisionId, $targetWeek]);
                    }

                    $insert = $conn->prepare("
                        INSERT INTO course_schedule
                            (course_id, division_id, subject_id, item_type, item_id,
                             week_number, weekday, start_time, duration_minutes, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($sourceRows as $r) {
                        // Budget check BEFORE insert
                        if (in_array($r['item_type'], ['quiz', 'exam'], true)) {
                            $budgetError = validateScheduleBudget(
                                $conn,
                                $courseId,
                                (int)$r['subject_id'],
                                (string)$r['item_type'],
                                (int)$r['item_id']
                            );
                            if ($budgetError !== null) {
                                $budgetSkipped++;
                                continue;
                            }
                        }

                        try {
                            $insert->execute([
                                $courseId,
                                $divisionId,
                                (int)$r['subject_id'],
                                $r['item_type'],
                                (int)$r['item_id'],
                                $targetWeek,
                                (int)$r['weekday'],
                                $r['start_time'],
                                $r['duration_minutes'] !== null ? (int)$r['duration_minutes'] : null,
                                (int)$r['sort_order'],
                            ]);
                            $inserted++;
                        } catch (PDOException $e) {
                            if ($e->getCode() === '23000') {
                                $skipped++;
                                continue;
                            }
                            throw $e;
                        }
                    }

                    $conn->commit();

                    // 1) Clear old exam/quiz links that were removed
                    foreach ($oldExamIds as $oldId) {
                        clearMonthlyExamDivision($conn, $oldId);
                    }
                    foreach ($oldQuizIds as $oldId) {
                        clearQuizScheduleLink($conn, $oldId);
                    }

                    // 2) Re-sync every exam in the target week
                    $stmt = $conn->prepare("
                        SELECT item_id FROM course_schedule
                        WHERE division_id = ? AND week_number = ? AND item_type = 'exam'
                    ");
                    $stmt->execute([$divisionId, $targetWeek]);
                    foreach ($stmt->fetchAll() as $ex) {
                        syncMonthlyExamDivision($conn, (int)$ex['item_id'], $divisionId, $targetWeek);
                    }

                    // 3) Re-sync every quiz in the target week
                    $stmt = $conn->prepare("
                        SELECT item_id FROM course_schedule
                        WHERE division_id = ? AND week_number = ? AND item_type = 'quiz'
                    ");
                    $stmt->execute([$divisionId, $targetWeek]);
                    foreach ($stmt->fetchAll() as $qz) {
                        syncQuizAsCompetition($conn, (int)$qz['item_id'], $courseId, $divisionId, $targetWeek);
                    }

                } catch (PDOException $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    throw $e;
                }

                $msg = "Copied $inserted item(s) from Week $sourceWeek to Week $targetWeek.";
                if ($skipped > 0) {
                    $msg .= " $skipped skipped (duplicate or missing item).";
                }
                if ($budgetSkipped > 0) {
                    $msg .= " $budgetSkipped skipped (would exceed bucket budget).";
                }
                flash('success', $msg);
                break;
            }

            default:
                flash('error', 'Unknown action.');
        }

    } catch (PDOException $e) {
        error_log('admin_course_schedule error: ' . $e->getMessage());
        flash('error', 'A database error occurred. Nothing was saved.');
    }
    redirectTo($back);
}

// ============================================================================
// LOAD DATA FOR DISPLAY
// ============================================================================

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$week = isset($_GET['week']) ? (int)$_GET['week'] : 1;
$week = max(1, min($totalWeeks, $week));

// Subjects in this course
$stmt = $conn->prepare("
    SELECT cs.subject_id, s.name, s.icon
    FROM course_subjects cs
    JOIN subjects s ON s.id = cs.subject_id
    WHERE cs.course_id = ?
    ORDER BY cs.sort_order ASC, s.name ASC
");
$stmt->execute([$courseId]);
$courseSubjects = $stmt->fetchAll();
$subjectMap = [];
foreach ($courseSubjects as $cs) {
    $subjectMap[(int)$cs['subject_id']] = [
        'name' => $cs['name'],
        'icon' => $cs['icon'] ?: '📖',
    ];
}

// Scheduled items for THIS week
$stmt = $conn->prepare("
    SELECT cs.*
    FROM course_schedule cs
    WHERE cs.division_id = ? AND cs.week_number = ?
    ORDER BY cs.weekday ASC, cs.start_time ASC, cs.sort_order ASC
");
$stmt->execute([$divisionId, $week]);
$rows = $stmt->fetchAll();

// Enrich with item titles
$schedule = [];
$itemTitles = ['content' => [], 'quiz' => [], 'exam' => []];
$idsByType = ['content' => [], 'quiz' => [], 'exam' => []];
foreach ($rows as $r) {
    $idsByType[$r['item_type']][] = (int)$r['item_id'];
}
foreach (ITEM_TABLES as $type => $table) {
    $ids = array_values(array_unique($idsByType[$type]));
    if (!$ids) continue;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = $conn->prepare("SELECT id, title FROM `$table` WHERE id IN ($in)");
    $s->execute($ids);
    foreach ($s->fetchAll() as $it) {
        $itemTitles[$type][(int)$it['id']] = (string)$it['title'];
    }
}

foreach ($rows as $r) {
    $wd = (int)$r['weekday'];
    $schedule[$wd][] = [
        'id' => (int)$r['id'],
        'subject_id' => (int)$r['subject_id'],
        'item_type' => $r['item_type'],
        'item_id' => (int)$r['item_id'],
        'title' => $itemTitles[$r['item_type']][(int)$r['item_id']] ?? '(missing item)',
        'time' => $r['start_time'],
        'duration' => $r['duration_minutes'] !== null ? (int)$r['duration_minutes'] : null,
    ];
}

// All available items for the picker (only published)
$pickerItems = [
    'content' => $conn->query("
        SELECT id, title, subject_id
        FROM contents
        WHERE status = 'published'
        ORDER BY title
        LIMIT 2000
    ")->fetchAll(),
    'quiz' => $conn->query("
        SELECT id, title, subject_id
        FROM quizzes
        WHERE status = 'published'
        ORDER BY title
        LIMIT 2000
    ")->fetchAll(),
    'exam' => $conn->query("
        SELECT id, title, subject_id
        FROM monthly_exams
        WHERE status = 'published'
        ORDER BY title
        LIMIT 2000
    ")->fetchAll(),
];

// Other divisions in the same course (for the copy dialog)
$stmt = $conn->prepare("
    SELECT d.id, d.number, d.name, d.start_date, d.end_date,
           (SELECT COUNT(*) FROM course_schedule cs WHERE cs.division_id = d.id) AS items_count
    FROM course_divisions d
    WHERE d.course_id = ? AND d.id <> ?
    ORDER BY d.number ASC
");
$stmt->execute([$courseId, $divisionId]);
$otherDivisions = $stmt->fetchAll();

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule — <?php echo e($division['name']); ?> — MyIslam Admin</title>
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
        button, input, select { font: inherit; color: inherit; }
        button { cursor: pointer; border: none; background: none; }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible {
            outline: 2px solid var(--gold); outline-offset: 2px;
        }

        .container { max-width: 1400px; margin: 0 auto; display: grid; gap: 20px; }

        .page-header {
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
            padding-bottom: 16px; border-bottom: 1px solid var(--line);
        }
        .back-link { font-weight: 700; color: var(--brand); font-size: 14px; }
        .back-link:hover { color: var(--gold); }
        .page-title { font-size: clamp(20px, 4vw, 26px); font-weight: 800; flex: 1; min-width: 220px; }
        .page-title small { display: block; font-size: 13px; font-weight: 500; color: var(--muted); margin-top: 2px; }
        .icon-btn { width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); font-size: 18px; }
        .icon-btn:hover { border-color: var(--gold); }

        .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: clamp(16px, 4vw, 24px); }
        .card h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 800; color: var(--gold); margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }

        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 999px; font-weight: 700; font-size: 13px; background: var(--surface); border: 1px solid var(--line); color: var(--text-secondary); transition: all .2s; }
        .btn:hover { border-color: var(--brand); color: var(--brand); transform: translateY(-1px); }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: var(--white); }
        .btn-primary:hover { color: var(--white); filter: brightness(1.1); }
        .btn-danger { color: var(--danger); background: var(--danger-soft); border-color: transparent; }
        .btn-danger:hover { border-color: var(--danger); color: var(--danger); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .flash { padding: 12px 16px; border-radius: var(--radius-sm); font-weight: 600; font-size: 14px; border: 1px solid transparent; }
        .flash.success { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }
        .flash.error { background: var(--danger-soft); color: var(--danger); border-color: var(--danger); }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge.brand { background: var(--brand-soft); color: var(--brand); }
        .badge.gold { background: var(--gold-soft); color: var(--gold); }
        .badge.accent { background: var(--accent-soft); color: var(--accent); }
        .badge.danger { background: var(--danger-soft); color: var(--danger); }
        .badge.muted { background: var(--surface-3); color: var(--muted); }
        .badge.success { background: var(--success-soft); color: var(--success); }

        .division-header { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; padding: 14px 16px; border-radius: var(--radius-md); background: var(--surface-2); border: 1px solid var(--line); }
        .division-header .title { font-weight: 800; font-size: 16px; color: var(--brand); }
        .division-header .dates { color: var(--muted); font-size: 13px; }
        .division-header .meta { display: flex; gap: 10px; margin-left: auto; flex-wrap: wrap; }

        /* Week navigation */
        .week-nav { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border-radius: var(--radius-md); background: var(--surface-2); border: 1px solid var(--line); }
        .week-nav .week-dots { display: flex; gap: 4px; flex-wrap: wrap; }
        .week-nav .week-dot {
            min-width: 34px; height: 34px; padding: 0 8px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid var(--line); background: var(--surface);
            font-size: 12px; font-weight: 700; color: var(--text-secondary);
        }
        .week-nav .week-dot:hover { border-color: var(--brand); color: var(--brand); }
        .week-nav .week-dot.active { background: var(--brand); border-color: var(--brand); color: var(--white); }
        .week-nav .nav-btn { padding: 8px 14px; font-size: 13px; }

        /* Grid */
        .grid-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface); }
        table.schedule-grid { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1100px; }
        .schedule-grid th {
            background: var(--surface-2); color: var(--text-secondary);
            padding: 12px 10px; font-size: 12px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .5px;
            border-bottom: 1px solid var(--line);
            text-align: center;
        }
        .schedule-grid th.week-col { width: 90px; text-align: left; padding-left: 16px; }
        .schedule-grid td {
            border-top: 1px solid var(--line);
            border-right: 1px solid var(--line);
            vertical-align: top;
            padding: 8px;
            min-width: 140px;
            height: 100px;
            background: var(--surface);
        }
        .schedule-grid td:first-child { border-left: none; background: var(--surface-2); border-right: 1px solid var(--line); }
        .schedule-grid td:last-child { border-right: none; }

        .week-label {
            font-weight: 800; font-size: 14px; color: var(--brand); padding: 6px 0;
        }
        .week-label small { display: block; font-weight: 500; color: var(--muted); font-size: 11px; }

        .cell-items { display: flex; flex-direction: column; gap: 6px; }

        .slot {
            display: flex; align-items: flex-start; gap: 6px;
            padding: 6px 8px; border-radius: 8px;
            background: var(--brand-soft); border: 1px solid var(--brand);
            font-size: 12px; line-height: 1.3; position: relative;
        }
        .slot.type-quiz { background: var(--gold-soft); border-color: var(--gold); }
        .slot.type-exam { background: var(--accent-soft); border-color: var(--accent); }
        .slot .slot-body { flex: 1; min-width: 0; }
        .slot .slot-time { font-weight: 800; color: var(--brand); font-size: 11px; }
        .slot.type-quiz .slot-time { color: var(--gold); }
        .slot.type-exam .slot-time { color: var(--accent); }
        .slot .slot-subject { font-weight: 700; overflow-wrap: anywhere; }
        .slot .slot-title { color: var(--text-secondary); font-size: 11px; overflow-wrap: anywhere; }
        .slot .slot-del {
            flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.05); color: var(--danger); font-size: 11px; font-weight: 800;
            opacity: .6;
        }
        .slot .slot-del:hover { opacity: 1; background: var(--danger-soft); }

        .add-slot-btn {
            display: block; width: 100%; padding: 8px;
            border: 1px dashed var(--line); border-radius: 8px;
            background: transparent; color: var(--muted);
            font-size: 14px; font-weight: 700;
            transition: all .2s;
        }
        .add-slot-btn:hover { border-color: var(--brand); color: var(--brand); background: var(--brand-soft); }

        /* Dialogs */
        dialog {
            border: 1px solid var(--line); border-radius: var(--radius-lg);
            background: var(--surface); color: var(--text);
            padding: 24px; width: min(540px, 94vw);
            box-shadow: var(--shadow-lg); margin: auto;
        }
        dialog::backdrop { background: rgba(15, 23, 32, 0.55); backdrop-filter: blur(3px); }
        dialog h3 { margin-bottom: 16px; font-size: 18px; }
        dialog h3 small { display: block; font-weight: 500; font-size: 13px; color: var(--muted); margin-top: 2px; }
        .dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-grid .wide { grid-column: 1 / -1; }
        .field { display: grid; gap: 4px; }
        .field label { font-size: 12px; font-weight: 700; color: var(--text-secondary); }
        .field small { font-size: 11px; color: var(--muted); }
        input[type="text"], input[type="number"], input[type="time"], select {
            width: 100%; padding: 10px 12px; border-radius: 10px;
            border: 1px solid var(--line); background: var(--surface); font-size: 14px;
        }

        .legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; color: var(--muted); }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; }

        /* Source division radio cards */
        .radio-card {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px;
            cursor: pointer; background: var(--surface);
            transition: all .2s;
        }
        .radio-card:hover { border-color: var(--brand); background: var(--brand-soft); }
        .radio-card input[type="radio"] { flex-shrink: 0; }
        .radio-card strong { color: var(--brand); }
        .radio-card small { color: var(--muted); }

        .info-banner {
            padding: 10px 12px; border-radius: 10px;
            background: var(--gold-soft); border: 1px solid var(--gold);
            font-size: 12px; color: var(--text-secondary);
        }

        @media (max-width: 720px) {
            body { padding: 14px; }
            .division-header .meta { margin-left: 0; width: 100%; }
        }

        .slot .slot-edit {
            flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.05); color: var(--brand); font-size: 11px; font-weight: 800;
            opacity: .7; border: none; cursor: pointer; padding: 0;
        }
        .slot .slot-edit:hover { opacity: 1; background: var(--brand-soft); }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">
        <a href="admin_courses.php?course_id=<?php echo $courseId; ?>" class="back-link">
            ← <?php echo e($division['course_title']); ?>
        </a>
        <h1 class="page-title">
            🗓️ Weekly schedule
            <small><?php echo e($division['name']); ?> · <?php echo e($dStart->format('d M Y')); ?> → <?php echo e($dEnd->format('d M Y')); ?></small>
        </h1>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Toggle dark mode">🌙</button>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?php echo e($flash[0]); ?>" role="status"><?php echo e($flash[1]); ?></div>
    <?php endif; ?>

    <section class="card">
        <div class="division-header">
            <div>
                <div class="title"><?php echo e($division['name']); ?></div>
                <div class="dates"><?php echo e($dStart->format('d M Y')); ?> → <?php echo e($dEnd->format('d M Y')); ?> · <?php echo $totalWeeks; ?> weeks · pass ≥ <?php echo (int)$division['passing_score']; ?>%</div>
            </div>
            <div class="meta">
                <span class="badge brand"><?php echo count($courseSubjects); ?> subject<?php echo count($courseSubjects) === 1 ? '' : 's'; ?></span>
            </div>
        </div>
    </section>

    <?php if (!$courseSubjects): ?>
        <section class="card">
            <p class="empty" style="text-align:center;color:var(--muted);padding:24px;">
                ⚠️ No subjects are attached to this course yet.
                <br>Go back to the course page and add subjects first, then return here to build the schedule.
            </p>
            <div style="text-align:center;margin-top:12px;">
                <a class="btn btn-primary" href="admin_courses.php?course_id=<?php echo $courseId; ?>">← Back to course</a>
            </div>
        </section>
    <?php else: ?>

    <!-- Week navigation -->
    <section class="card">
        <div class="week-nav">
            <a class="btn btn-sm nav-btn" href="?division_id=<?php echo $divisionId; ?>&week=<?php echo max(1, $week - 1); ?>" <?php if ($week <= 1) echo 'style="pointer-events:none;opacity:.4;"'; ?>>← Previous</a>

            <div class="week-dots">
                <?php
                if ($totalWeeks <= 20) {
                    $pageList = range(1, $totalWeeks);
                } else {
                    $pageList = array_unique(array_merge(
                        [1],
                        range(max(1, $week - 3), min($totalWeeks, $week + 3)),
                        [$totalWeeks]
                    ));
                    sort($pageList);
                }
                $prevPage = 0;
                foreach ($pageList as $pg):
                    if ($prevPage && $pg - $prevPage > 1): ?>
                        <span style="color:var(--muted);padding:0 4px;">…</span>
                    <?php endif; ?>
                    <a class="week-dot <?php echo $pg === $week ? 'active' : ''; ?>"
                       href="?division_id=<?php echo $divisionId; ?>&week=<?php echo (int)$pg; ?>"
                       title="Week <?php echo (int)$pg; ?>">
                        <?php echo (int)$pg; ?>
                    </a>
                <?php $prevPage = $pg; endforeach; ?>
            </div>

            <a class="btn btn-sm nav-btn" href="?division_id=<?php echo $divisionId; ?>&week=<?php echo min($totalWeeks, $week + 1); ?>" <?php if ($week >= $totalWeeks) echo 'style="pointer-events:none;opacity:.4;"'; ?>>Next →</a>

            <button class="btn btn-sm nav-btn" type="button" id="openCopyBtn" style="margin-left:auto;">📋 Copy schedule from…</button>
            <button class="btn btn-sm nav-btn" type="button" id="openWeekCopyBtn">📋 Copy this week →</button>
        </div>

        <div class="legend" style="margin-top:12px;">
            <span><i style="background:var(--brand);"></i> Lesson</span>
            <span><i style="background:var(--gold);"></i> Quiz</span>
            <span><i style="background:var(--accent);"></i> Exam</span>
        </div>
    </section>

    <!-- Grid -->
    <section class="card" style="padding:0;overflow:hidden;">
        <div class="grid-wrap">
            <table class="schedule-grid">
                <thead>
                    <tr>
                        <th class="week-col">Week</th>
                        <?php foreach (WEEKDAYS as $wd => $wdName): ?>
                            <th><?php echo e($wdName); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <div class="week-label">
                                Week <?php echo $week; ?>
                                <small><?php echo e($dStart->modify('+' . (($week - 1) * 7) . ' days')->format('d M')); ?></small>
                            </div>
                        </td>
                        <?php foreach (WEEKDAYS as $wd => $wdName):
                            $cellItems = $schedule[$wd] ?? [];
                        ?>
                            <td>
                                <div class="cell-items">
                                    <?php foreach ($cellItems as $it):
                                        $subj = $subjectMap[$it['subject_id']] ?? null;
                                        $subjLabel = $subj ? $subj['name'] : '(unknown subject)';
                                        $subjIcon = $subj ? $subj['icon'] : '📖';
                                        $typeClass = $it['item_type'] === 'content' ? '' : ($it['item_type'] === 'quiz' ? ' type-quiz' : ' type-exam');
                                        $timeLabel = $it['time'] ? substr($it['time'], 0, 5) : '';
                                    ?>
                                        <div class="slot<?php echo $typeClass; ?>">
                                            <div class="slot-body">
                                                <?php if ($timeLabel): ?>
                                                    <div class="slot-time">
                                                        <?php echo e($timeLabel); ?>
                                                        <?php if (!empty($it['duration'])): ?>
                                                            <span style="opacity:.7;font-weight:600;">
                                                                (<?php echo (int)$it['duration']; ?>m)
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="slot-subject"><?php echo $subjIcon; ?> <?php echo e($subjLabel); ?></div>
                                                <div class="slot-title"><?php echo e($it['title']); ?></div>
                                            </div>
                                            <div style="display:flex;flex-direction:column;gap:4px;">
                                                <button type="button"
                                                        class="slot-edit"
                                                        title="Edit"
                                                        data-edit-slot
                                                        data-id="<?php echo (int)$it['id']; ?>"
                                                        data-subject-id="<?php echo (int)$it['subject_id']; ?>"
                                                        data-item-type="<?php echo e($it['item_type'] ?? ''); ?>"
                                                        data-item-id="<?php echo (int)$it['item_id']; ?>"
                                                        data-week="<?php echo (int)$week; ?>"
                                                        data-weekday="<?php echo (int)($wd); ?>"
                                                        data-start="<?php
                                                            $__start = $it['time'] ?? '';
                                                            echo e($__start !== '' ? substr((string)$__start, 0, 5) : '');
                                                        ?>"
                                                        data-duration="<?php echo !empty($it['duration']) ? (int)$it['duration'] : ''; ?>">
                                                    ✎
                                                </button>
                                                <form method="post" onsubmit="return confirm('Remove this item?');" style="margin:0;">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_schedule_item">
                                                    <input type="hidden" name="division_id" value="<?php echo $divisionId; ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                                                    <input type="hidden" name="week" value="<?php echo $week; ?>">
                                                    <button type="submit" class="slot-del" title="Remove">✕</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <button type="button" class="add-slot-btn"
                                            data-open-add
                                            data-week="<?php echo $week; ?>"
                                            data-weekday="<?php echo $wd; ?>"
                                            data-weekday-name="<?php echo e($wdName); ?>"
                                            title="Add an item to this cell">
                                        ＋
                                    </button>
                                </div>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <?php endif; ?>

    <!-- Add / Edit dialog -->
    <dialog id="addDialog">
        <form method="post" id="addForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_schedule_item" id="addFormAction">
            <input type="hidden" name="id" id="editRowId" value="0">
            <input type="hidden" name="division_id" value="<?php echo $divisionId; ?>">
            <input type="hidden" name="week" id="addWeekBack" value="<?php echo $week; ?>">

            <h3>Add to schedule
                <small id="addContext">Week — · —</small>
            </h3>

            <div class="form-grid">
                <div class="field wide">
                    <label for="addSubject">Subject</label>
                    <select id="addSubject" name="subject_id" required>
                        <option value="">— Choose a subject —</option>
                        <?php foreach ($courseSubjects as $cs): ?>
                            <option value="<?php echo (int)$cs['subject_id']; ?>">
                                <?php echo e(($cs['icon'] ?: '📖') . ' ' . $cs['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="addType">Type</label>
                    <select id="addType" name="item_type" required>
                        <option value="content">Lesson</option>
                        <option value="quiz">Quiz</option>
                        <option value="exam">Exam</option>
                    </select>
                </div>

                <div class="field">
                    <label for="addSearch">Search</label>
                    <input type="text" id="addSearch" placeholder="Filter items…" autocomplete="off">
                </div>

                <div class="field wide">
                    <label for="addItem">Item <small id="addItemCount"></small></label>
                    <select id="addItem" name="item_id" required disabled>
                        <option value="">— Choose a subject first —</option>
                    </select>
                </div>

                <div class="field">
                    <label for="addTime">Start time</label>
                    <input type="time" id="addTime" name="start_time" step="60">
                    <small>Optional — shows on the student's timetable</small>
                </div>

                <div class="field">
                    <label for="addDuration">Duration (min)</label>
                    <input type="number" id="addDuration" name="duration_minutes" min="1" max="600" placeholder="Optional">
                </div>

                <div class="field">
                    <label for="addWeekday">Weekday</label>
                    <select id="addWeekday" name="weekday" required>
                        <option value="">— Choose —</option>
                        <?php foreach (WEEKDAYS as $wd => $wdName): ?>
                            <option value="<?php echo $wd; ?>"><?php echo e($wdName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="addWeekNum">Week number</label>
                    <input type="number" id="addWeekNum" name="week_number" min="1" max="<?php echo $totalWeeks; ?>" placeholder="e.g. 1" required>
                </div>
            </div>

            <div class="dialog-actions">
                <button type="button" class="btn" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </dialog>

    <!-- Copy from another division -->
    <dialog id="copyDialog">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="copy_schedule">
            <input type="hidden" name="division_id" value="<?php echo $divisionId; ?>">
            <h3>Copy schedule from another division
                <small>Items will be copied into <strong><?php echo e($division['name']); ?></strong></small>
            </h3>

            <?php if (!$otherDivisions): ?>
                <p style="padding:14px;border-radius:12px;background:var(--surface-2);border:1px solid var(--line);color:var(--muted);">
                    There are no other divisions in this course yet.
                    <br>Create another division first, then come back.
                </p>
                <div class="dialog-actions">
                    <button type="button" class="btn" data-close>Close</button>
                </div>
            <?php else: ?>
                <div class="field" style="margin-bottom:14px;">
                    <label>Source division</label>
                    <div style="display:grid;gap:8px;">
                        <?php foreach ($otherDivisions as $od): ?>
                            <label class="radio-card">
                                <input type="radio" name="source_division_id" value="<?php echo (int)$od['id']; ?>" required>
                                <span style="flex:1;">
                                    <strong><?php echo e($od['name']); ?></strong>
                                    <br><small>
                                        <?php echo e(date('d M Y', strtotime($od['start_date']))); ?> →
                                        <?php echo e(date('d M Y', strtotime($od['end_date']))); ?> ·
                                        <?php echo (int)$od['items_count']; ?> scheduled item<?php echo (int)$od['items_count'] === 1 ? '' : 's'; ?>
                                    </small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field" style="margin-bottom:14px;">
                    <label>Copy mode</label>
                    <div style="display:grid;gap:6px;">
                        <label class="radio-card">
                            <input type="radio" name="copy_mode" value="replace" checked>
                            <span><strong>Replace</strong> — clear current schedule, then copy</span>
                        </label>
                        <label class="radio-card">
                            <input type="radio" name="copy_mode" value="append">
                            <span><strong>Append</strong> — add to existing (duplicates skipped)</span>
                        </label>
                    </div>
                </div>

                <div class="info-banner">
                    <strong>Note:</strong> An item (lesson/quiz/exam) can only appear once per course. If the source division has items already scheduled elsewhere in this course, they will be skipped. Items that would exceed a subject's point budget are also skipped.
                </div>

                <div class="dialog-actions">
                    <button type="button" class="btn" data-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">📋 Copy schedule</button>
                </div>
            <?php endif; ?>
        </form>
    </dialog>

    <!-- Copy week dialog -->
    <dialog id="weekCopyDialog">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="copy_week">
            <input type="hidden" name="division_id" value="<?php echo $divisionId; ?>">
            <input type="hidden" name="source_week" value="<?php echo (int)$week; ?>">

            <h3>Copy Week <?php echo (int)$week; ?> to another week
                <small>Duplicates all items from this week into the target week.</small>
            </h3>

            <?php
            $sourceCount = 0;
            foreach ($schedule ?? [] as $dayItems) {
                $sourceCount += count($dayItems);
            }
            ?>
            <div class="info-banner" style="margin-bottom:14px;">
                <strong>Source:</strong> Week <?php echo (int)$week; ?>
                — <?php echo $sourceCount; ?> item<?php echo $sourceCount === 1 ? '' : 's'; ?>
            </div>

            <?php if ($sourceCount === 0): ?>
                <p style="padding:14px;border-radius:12px;background:var(--surface-2);border:1px solid var(--line);color:var(--muted);">
                    This week has no items to copy. Add something first.
                </p>
                <div class="dialog-actions">
                    <button type="button" class="btn" data-close>Close</button>
                </div>
            <?php else: ?>

                <div class="field" style="margin-bottom:14px;">
                    <label for="target_week">Target week</label>
                    <select id="target_week" name="target_week" required>
                        <option value="">— Choose target week —</option>
                        <?php for ($w = 1; $w <= $totalWeeks; $w++): ?>
                            <?php if ($w === $week) continue; ?>
                            <option value="<?php echo $w; ?>">Week <?php echo $w; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="field" style="margin-bottom:14px;">
                    <label>Copy mode</label>
                    <div style="display:grid;gap:6px;">
                        <label class="radio-card">
                            <input type="radio" name="copy_week_mode" value="replace" checked>
                            <span><strong>Replace</strong> — clear the target week first, then copy</span>
                        </label>
                        <label class="radio-card">
                            <input type="radio" name="copy_week_mode" value="append">
                            <span><strong>Append</strong> — add to target week (duplicates skipped)</span>
                        </label>
                    </div>
                </div>

                <div class="info-banner">
                    <strong>Note:</strong> Copies preserve weekday, time, duration, subject, and item. Only the week number changes. Quiz and exam links are re-synced after copying. Items that would exceed a subject's point budget are skipped.
                </div>

                <div class="dialog-actions">
                    <button type="button" class="btn" data-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">📋 Copy week</button>
                </div>
            <?php endif; ?>
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

    // ---- Dialog close helpers ----
    document.querySelectorAll('dialog').forEach(d => {
        d.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => d.close()));
        d.addEventListener('click', e => { if (e.target === d) d.close(); });
    });

    // ---- Add / Edit dialog ----
    const addDialog = document.getElementById('addDialog');
    if (addDialog) {
        const OPTIONS       = <?php echo json_encode($pickerItems, $jsonFlags); ?>;
        const form          = document.getElementById('addForm');
        const formAction    = document.getElementById('addFormAction');
        const editRowId     = document.getElementById('editRowId');
        const subjectSel    = document.getElementById('addSubject');
        const typeSel       = document.getElementById('addType');
        const itemSel       = document.getElementById('addItem');
        const search        = document.getElementById('addSearch');
        const count         = document.getElementById('addItemCount');
        const weekdaySel    = document.getElementById('addWeekday');
        const weekNumInput  = document.getElementById('addWeekNum');
        const timeInput     = document.getElementById('addTime');
        const durationInput = document.getElementById('addDuration');
        const dialogTitle   = addDialog.querySelector('h3');
        const dialogContext = document.getElementById('addContext');

        weekNumInput.max = <?php echo (int)$totalWeeks; ?>;

        function renderItems(selectedItemId) {
            const q         = search.value.trim().toLowerCase();
            const subjectId = String(subjectSel.value || '').trim();

            let list = (OPTIONS[typeSel.value] || []);

            if (subjectId !== '') {
                list = list.filter(o => String(o.subject_id || '') === subjectId);
            }
            if (q !== '') {
                list = list.filter(o => String(o.title).toLowerCase().includes(q));
            }

            itemSel.innerHTML = '';
            if (!list.length) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = subjectId === ''
                    ? '— Choose a subject first —'
                    : '— Nothing found —';
                itemSel.appendChild(opt);
            }
            list.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id;
                opt.textContent = o.title;
                if (selectedItemId && String(selectedItemId) === String(o.id)) {
                    opt.selected = true;
                }
                itemSel.appendChild(opt);
            });
            itemSel.disabled = (subjectId === '');
            count.textContent = '(' + list.length + ')';
        }

        function resetDialogForAdd() {
            dialogTitle.childNodes[0].nodeValue = 'Add to schedule ';
            formAction.value = 'add_schedule_item';
            editRowId.value  = '0';
            subjectSel.value = '';
            typeSel.value    = 'content';
            search.value     = '';
            timeInput.value  = '';
            durationInput.value = '';
            renderItems();
        }

        typeSel.addEventListener('change', () => { search.value = ''; renderItems(); });
        subjectSel.addEventListener('change', () => { search.value = ''; renderItems(); });
        search.addEventListener('input', renderItems);
        renderItems();

        document.querySelectorAll('[data-open-add]').forEach(btn => {
            btn.addEventListener('click', () => {
                resetDialogForAdd();
                const week        = btn.dataset.week;
                const weekday     = btn.dataset.weekday;
                const weekdayName = btn.dataset.weekdayName;
                weekdaySel.value   = weekday;
                weekNumInput.value = week;
                dialogContext.textContent = 'Week ' + week + ' · ' + weekdayName;
                addDialog.showModal();
            });
        });

        document.querySelectorAll('[data-edit-slot]').forEach(btn => {
            btn.addEventListener('click', () => {
                const id       = btn.dataset.id;
                const subjId   = btn.dataset.subjectId;
                const itemType = btn.dataset.itemType;
                const itemId   = btn.dataset.itemId;
                const week     = btn.dataset.week;
                const weekday  = btn.dataset.weekday;
                const start    = btn.dataset.start || '';
                const duration = btn.dataset.duration || '';

                dialogTitle.childNodes[0].nodeValue = 'Edit scheduled item ';
                formAction.value = 'update_schedule_item';
                editRowId.value  = id;

                subjectSel.value = subjId;
                typeSel.value    = itemType;
                search.value     = '';
                renderItems(itemId);

                weekdaySel.value   = weekday;
                weekNumInput.value = week;
                timeInput.value    = start;
                durationInput.value = duration;

                const subjLabel = subjectSel.options[subjectSel.selectedIndex]?.textContent || '';
                const itemLabel = itemSel.options[itemSel.selectedIndex]?.textContent || '';
                dialogContext.textContent = subjLabel.trim() + ' · ' + itemLabel.trim();

                addDialog.showModal();
            });
        });
    }

    // ---- Copy dialog ----
    const copyDialog = document.getElementById('copyDialog');
    if (copyDialog) {
        const openCopyBtn = document.getElementById('openCopyBtn');
        if (openCopyBtn) {
            openCopyBtn.addEventListener('click', () => copyDialog.showModal());
        }
    }

    // Week-copy dialog
    const weekCopyDialog = document.getElementById('weekCopyDialog');
    if (weekCopyDialog) {
        const openWeekCopyBtn = document.getElementById('openWeekCopyBtn');
        if (openWeekCopyBtn) {
            openWeekCopyBtn.addEventListener('click', () => weekCopyDialog.showModal());
        }
    }
})();
</script>
</body>
</html>