<?php
declare(strict_types=1);

/**
 * Shared helpers for the student side of the Courses module.
 * Used by courses.php, course.php, user_dashboard.php, admin pages.
 *
 * Division-based model:
 *   Course → course_divisions (with real dates) → course_schedule (per-week grid).
 *   Student's enrolment starts at the next upcoming division.
 *   The student's timetable is the SAME for every student in the same division.
 *
 * Grade model (points-based — must stay in sync with includes/course_grades.php):
 *   Every attempt writes TWO numbers into enrollment_course_grades:
 *     * earned   → weekly_total / monthly_total / final_score
 *     * possible → weekly_possible / monthly_possible
 *   Subject budget = quiz_total_points + exam_total_points + final_total_points
 *   grade = earned / budget × 100, pass/fail only after the final is attempted.
 */

require_once __DIR__ . '/course_grades.php';

const COURSE_ITEM_TABLES = ['content' => 'contents', 'quiz' => 'quizzes', 'exam' => 'monthly_exams'];
const COURSE_ITEM_LABELS = ['content' => 'Lesson', 'quiz' => 'Quiz', 'exam' => 'Exam'];
const COURSE_WEEKDAYS = [
    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
    4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
];

// ============================================================================
// TABLE EXISTENCE
// ============================================================================

/** True once admin_courses.php has created the course tables. */
function courseTablesExist(PDO $conn): bool
{
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('courses', 'course_divisions', 'course_subjects', 'course_schedule', 'course_enrollments')
        ");
        return (int)$stmt->fetchColumn() === 5;
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================================
// DATE HELPERS
// ============================================================================

function courseDayDate(string $startDate, int $day): DateTimeImmutable
{
    return (new DateTimeImmutable($startDate))->modify('+' . max(0, $day - 1) . ' days');
}

function courseCohortEnd(string $startDate, int $durationDays): DateTimeImmutable
{
    return courseDayDate($startDate, $durationDays);
}

/** Week number (1..N) inside a division, given a date. Returns 0 if before start. */
function courseWeekOfDivision(string $divisionStart, DateTimeImmutable $when): int
{
    $start = new DateTimeImmutable($divisionStart);
    if ($when < $start) {
        return 0;
    }
    $days = $start->diff($when)->days;
    return (int)floor($days / 7) + 1;
}

// ============================================================================
// DIVISION LOOKUPS
// ============================================================================

/**
 * Find the next upcoming division for a course, given "today".
 * Returns null if there is no division starting today or later.
 */
function courseFindNextDivision(PDO $conn, int $courseId, ?DateTimeImmutable $today = null): ?array
{
    $today = $today ?? new DateTimeImmutable('today');

    $stmt = $conn->prepare("
        SELECT * FROM course_divisions
        WHERE course_id = ? AND start_date >= ?
        ORDER BY start_date ASC, number ASC
        LIMIT 1
    ");
    $stmt->execute([$courseId, $today->format('Y-m-d')]);
    return $stmt->fetch() ?: null;
}

/**
 * Find the division that currently contains "today" (start_date <= today <= end_date).
 */
function courseFindCurrentDivision(PDO $conn, int $courseId, ?DateTimeImmutable $today = null): ?array
{
    $today = $today ?? new DateTimeImmutable('today');
    $todayStr = $today->format('Y-m-d');

    $stmt = $conn->prepare("
        SELECT * FROM course_divisions
        WHERE course_id = ? AND start_date <= ? AND end_date >= ?
        ORDER BY start_date DESC
        LIMIT 1
    ");
    $stmt->execute([$courseId, $todayStr, $todayStr]);
    return $stmt->fetch() ?: null;
}

/**
 * Load all divisions of a course ordered by number.
 */
function courseLoadDivisions(PDO $conn, int $courseId): array
{
    $stmt = $conn->prepare("
        SELECT * FROM course_divisions
        WHERE course_id = ?
        ORDER BY number ASC
    ");
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

/**
 * Find the next division that starts AFTER the given division.
 * Used for auto-advancement.
 */
function courseFindNextDivisionAfter(PDO $conn, int $courseId, int $afterDivisionId): ?array
{
    // Load the current division to know its start_date
    $stmt = $conn->prepare("SELECT start_date FROM course_divisions WHERE id = ?");
    $stmt->execute([$afterDivisionId]);
    $currentStart = $stmt->fetchColumn();
    if ($currentStart === false) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT * FROM course_divisions
        WHERE course_id = ? AND start_date > ?
        ORDER BY start_date ASC, number ASC
        LIMIT 1
    ");
    $stmt->execute([$courseId, $currentStart]);
    return $stmt->fetch() ?: null;
}

/**
 * Ensure an enrollment has a current division attached.
 *
 * Called on every courseLoadEnrollment(). When a student just enrolled
 * (or was migrated with current_division_id = NULL), this finds the
 * division that's running today — or, if none is running, the next
 * upcoming one — and attaches it.
 *
 * Idempotent: once current_division_id is set, this is a no-op.
 * Returns the division id it ends up pointing at, or null if the course
 * genuinely has no divisions.
 */
function courseEnsureCurrentDivision(PDO $conn, int $enrollmentId): ?int
{
    $stmt = $conn->prepare("
        SELECT id, course_id, current_division_id, starting_division_id
        FROM course_enrollments
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$enrollmentId]);
    $en = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$en) {
        return null;
    }

    // Already attached — nothing to do.
    if (!empty($en['current_division_id'])) {
        return (int)$en['current_division_id'];
    }

    $courseId = (int)$en['course_id'];

    // 1. Prefer the division currently running.
    $div = courseFindCurrentDivision($conn, $courseId);
    $divisionId = $div ? (int)$div['id'] : 0;

    // 2. Otherwise, take the next upcoming one.
    if ($divisionId <= 0) {
        try {
            $stmt = $conn->prepare("
                SELECT id FROM course_divisions
                WHERE course_id = ? AND start_date > CURDATE()
                ORDER BY start_date ASC, number ASC
                LIMIT 1
            ");
            $stmt->execute([$courseId]);
            $divisionId = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('courseEnsureCurrentDivision lookup: ' . $e->getMessage());
        }
    }

    // 3. Nothing to attach — course has no divisions at all.
    if ($divisionId <= 0) {
        return null;
    }

    try {
        // Set current_division_id always; set starting_division_id only if
        // it's never been set, so we preserve the student's original entry point.
        $stmt = $conn->prepare("
            UPDATE course_enrollments
            SET current_division_id  = :cur,
                starting_division_id = COALESCE(starting_division_id, :start)
            WHERE id = :id
              AND current_division_id IS NULL
        ");
        $stmt->execute([
            'cur'   => $divisionId,
            'start' => $divisionId,
            'id'    => $enrollmentId,
        ]);
    } catch (PDOException $e) {
        error_log('courseEnsureCurrentDivision update: ' . $e->getMessage());
        return null;
    }

    return $divisionId;
}

// ============================================================================
// COURSE-SUBJECT LOOKUPS
// ============================================================================

/**
 * Load the subjects attached to a course, in order, with their icons and names.
 */
function courseLoadCourseSubjects(PDO $conn, int $courseId): array
{
    $stmt = $conn->prepare("
        SELECT cs.*,
               s.name,
               s.slug,
               s.icon,
               s.image_path,
               s.description AS subject_description,
               s.sheikh_name,
               s.markaz
        FROM course_subjects cs
        JOIN subjects s ON s.id = cs.subject_id
        WHERE cs.course_id = ?
        ORDER BY cs.sort_order ASC, s.name ASC
    ");
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

// ============================================================================
// SCHEDULE LOOKUPS
// ============================================================================

/**
 * Compute the visible/unlock window for a scheduled item.
 *
 * Returns [unlockAt, lockAt|null]:
 *   - unlockAt: DateTimeImmutable — earliest moment the item is clickable
 *   - lockAt:   DateTimeImmutable|null — latest moment, null means "no end"
 *
 * Rules:
 *   - Base: division.start_date + (week-1)*7 days + (weekday-1) days + start_time
 *   - Lesson (content): no override — opens at scheduled time, stays open
 *   - Quiz:   if quizzes.competition_start_at/end_at set, use those
 *   - Exam:   if monthly_exams.start_date/end_date set, use those
 */
function courseItemWindow(
    array $scheduleRow,
    array $itemData,
    DateTimeImmutable $divStart,
    string $itemType
): array {
    $week = max(1, (int)($scheduleRow['week_number'] ?? 1));
    $wd   = max(1, min(7, (int)($scheduleRow['weekday'] ?? 1)));
    $time = !empty($scheduleRow['start_time']) ? (string)$scheduleRow['start_time'] : '00:00:00';

    $unlock = $divStart
        ->modify('+' . (($week - 1) * 7 + ($wd - 1)) . ' days')
        ->setTime(
            (int)substr($time, 0, 2),
            (int)substr($time, 3, 2),
            (int)substr($time, 6, 2)
        );

    $lockAt = null;
    $tz = new DateTimeZone('Africa/Dar_es_Salaam');

    if ($itemType === 'quiz') {
        if (!empty($itemData['competition_start_at']) && !empty($itemData['competition_end_at'])) {
            $unlock = new DateTimeImmutable((string)$itemData['competition_start_at'], $tz);
            $lockAt = new DateTimeImmutable((string)$itemData['competition_end_at'], $tz);
        }
    } elseif ($itemType === 'exam') {
        if (!empty($itemData['start_date']) && !empty($itemData['end_date'])) {
            $unlock = new DateTimeImmutable((string)$itemData['start_date'], $tz);
            $lockAt = new DateTimeImmutable((string)$itemData['end_date'], $tz);
        }
    }

    return [$unlock, $lockAt];
}

/**
 * Load the timetable for a division as a nested array:
 *   [
 *     weekNumber => [
 *        weekday => [
 *           [
 *             'id'         => schedule row id,
 *             'subject_id' => int,
 *             'item_type'  => 'content'|'quiz'|'exam',
 *             'item_id'    => int,
 *             'title'      => resolved title,
 *             'start_time' => 'HH:MM',
 *             'duration'   => int|null,
 *             'link'       => student-facing link,
 *             'subject_name' => ...,
 *             'subject_icon' => ...,
 *             'unlock_at'  => DateTimeImmutable,
 *             'lock_at'    => DateTimeImmutable|null,
 *             'visible'    => bool,
 *             'is_future'  => bool,
 *             'is_past'    => bool,
 *           ]
 *         ]
 *     ]
 *   ]
 *
 * If $week is provided (>0), only that week is returned.
 */
function courseLoadDivisionTimetable(PDO $conn, int $divisionId, int $week = 0): array
{
    $sql = "
        SELECT cs.*, sub.name AS subject_name, sub.icon AS subject_icon
        FROM course_schedule cs
        JOIN subjects sub ON sub.id = cs.subject_id
        WHERE cs.division_id = ?
    ";
    $params = [$divisionId];
    if ($week > 0) {
        $sql .= " AND cs.week_number = ?";
        $params[] = $week;
    }
    $sql .= " ORDER BY cs.week_number ASC, cs.weekday ASC, cs.start_time ASC, cs.sort_order ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [];
    }

    // Resolve titles for content/quiz/exam (batch per type)
    $idsByType = ['content' => [], 'quiz' => [], 'exam' => []];
    foreach ($rows as $r) {
        if (isset($idsByType[$r['item_type']])) {
            $idsByType[$r['item_type']][] = (int)$r['item_id'];
        }
    }
    $titles = ['content' => [], 'quiz' => [], 'exam' => []];
    foreach (COURSE_ITEM_TABLES as $type => $table) {
        $ids = array_values(array_unique($idsByType[$type]));
        if (!$ids) {
            continue;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $conn->prepare("SELECT id, title FROM `$table` WHERE id IN ($in)");
        $s->execute($ids);
        foreach ($s->fetchAll() as $row) {
            $titles[$type][(int)$row['id']] = (string)$row['title'];
        }
    }

    // ---- Batch-load window data for the underlying items ----
    // We need the timing columns (competition window for quizzes, date range
    // for exams) so we can compute each item's visible window.
    $windowsByItem = ['content' => [], 'quiz' => [], 'exam' => []];

    // Quizzes: competition_start_at / competition_end_at
    if (!empty($idsByType['quiz'])) {
        $in = implode(',', array_fill(0, count($idsByType['quiz']), '?'));
        try {
            $s = $conn->prepare("
                SELECT id, competition_start_at, competition_end_at
                FROM quizzes WHERE id IN ($in)
            ");
            $s->execute(array_values(array_unique($idsByType['quiz'])));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $windowsByItem['quiz'][(int)$row['id']] = $row;
            }
        } catch (PDOException $e) {
            error_log('courseLoadDivisionTimetable quiz window: ' . $e->getMessage());
        }
    }

    // Exams: try monthly_exams, then semester_exams
    if (!empty($idsByType['exam'])) {
        $in = implode(',', array_fill(0, count($idsByType['exam']), '?'));

        try {
            $s = $conn->prepare("
                SELECT id, start_date, end_date
                FROM monthly_exams WHERE id IN ($in)
            ");
            $s->execute(array_values(array_unique($idsByType['exam'])));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $windowsByItem['exam'][(int)$row['id']] = $row;
            }
        } catch (PDOException $e) {
            error_log('courseLoadDivisionTimetable monthly window: ' . $e->getMessage());
        }

        try {
            $s = $conn->prepare("
                SELECT id, start_date, end_date
                FROM semester_exams WHERE id IN ($in)
            ");
            $s->execute(array_values(array_unique($idsByType['exam'])));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                // Semester wins over monthly when both exist for the same id
                $windowsByItem['exam'][(int)$row['id']] = $row;
            }
        } catch (PDOException $e) {
            error_log('courseLoadDivisionTimetable semester window: ' . $e->getMessage());
        }
    }

    // Division start date — needed as the base for the unlock calculation
    $divStartStmt = $conn->prepare("SELECT start_date FROM course_divisions WHERE id = ?");
    $divStartStmt->execute([$divisionId]);
    $divStartRaw = (string)$divStartStmt->fetchColumn();
    $divStart = $divStartRaw !== ''
        ? new DateTimeImmutable($divStartRaw, new DateTimeZone('Africa/Dar_es_Salaam'))
        : new DateTimeImmutable('today', new DateTimeZone('Africa/Dar_es_Salaam'));

    $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Dar_es_Salaam'));

    // Group by week → weekday
    $grouped = [];
    foreach ($rows as $r) {
        $wk = (int)$r['week_number'];
        $wd = (int)$r['weekday'];

        $type = (string)$r['item_type'];
        $itemId = (int)$r['item_id'];

        $itemData = $windowsByItem[$type][$itemId] ?? [];

        [$unlockAt, $lockAt] = courseItemWindow($r, $itemData, $divStart, $type);

        $isFuture = ($now < $unlockAt);
        $isPast   = ($lockAt !== null && $now > $lockAt);
        $visible  = (!$isFuture && !$isPast);

        $grouped[$wk][$wd][] = [
            'id' => (int)$r['id'],
            'subject_id' => (int)$r['subject_id'],
            'subject_name' => (string)$r['subject_name'],
            'subject_icon' => (string)($r['subject_icon'] ?: '📖'),
            'item_type' => $type,
            'item_id' => $itemId,
            'title' => $titles[$type][$itemId] ?? '(missing item)',
            'start_time' => $r['start_time'] !== null ? substr((string)$r['start_time'], 0, 5) : null,
            'duration' => $r['duration_minutes'] !== null ? (int)$r['duration_minutes'] : null,
            'link' => courseItemLink($type, $itemId),
            // ---- visibility window ----
            'unlock_at' => $unlockAt,
            'lock_at'   => $lockAt,
            'visible'   => $visible,
            'is_future' => $isFuture,
            'is_past'   => $isPast,
        ];
    }
    return $grouped;
}

// ============================================================================
// ENROLLMENT LOOKUPS
// ============================================================================

/**
 * Load a student's enrollment in a course, including current division details.
 * Returns null if not enrolled (or dropped).
 */
function courseLoadEnrollment(PDO $conn, int $userId, int $courseId): ?array
{
    $stmt = $conn->prepare("
        SELECT en.*,
               c.title AS course_title,
               c.description AS course_description,
               c.division_type,
               c.division_label,
               cd.id            AS current_div_id,
               cd.number        AS current_div_number,
               cd.name          AS current_div_name,
               cd.start_date    AS current_div_start,
               cd.end_date      AS current_div_end,
               cd.passing_score AS current_div_passing,
               sd.id            AS start_div_id,
               sd.number        AS start_div_number,
               sd.name          AS start_div_name,
               sd.start_date    AS start_div_start,
               sd.end_date      AS start_div_end
        FROM course_enrollments en
        JOIN courses c ON c.id = en.course_id
        LEFT JOIN course_divisions cd ON cd.id = en.current_division_id
        LEFT JOIN course_divisions sd ON sd.id = en.starting_division_id
        WHERE en.user_id = ? AND en.course_id = ?
          AND en.status IN ('active','completed')
        LIMIT 1
    ");
    $stmt->execute([$userId, $courseId]);
    $en = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // If this enrollment has no current division yet but the course does,
    // attach it now. This is what lets a fresh enrollment immediately see
    // its running (or next upcoming) division on course.php,
    // course_subject.php, timetable.php, etc.
    if ($en && empty($en['current_div_id'])) {
        $attached = courseEnsureCurrentDivision($conn, (int)$en['id']);
        if ($attached !== null) {
            // Re-read so the returned row reflects the newly attached division.
            $stmt->execute([$userId, $courseId]);
            $en = $stmt->fetch(PDO::FETCH_ASSOC) ?: $en;
        }
    }

    return $en;
}

/**
 * Load ALL active enrollments of a student, with course + division info.
 * Used on the student's course list page.
 */
function courseLoadMyEnrollments(PDO $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT en.*,
               c.title AS course_title,
               c.description AS course_description,
               c.duration_days,
               c.division_type,
               c.division_label,
               cd.id            AS current_div_id,
               cd.number        AS current_div_number,
               cd.name          AS current_div_name,
               cd.start_date    AS current_div_start,
               cd.end_date      AS current_div_end,
               sd.id            AS start_div_id,
               sd.number        AS start_div_number,
               sd.name          AS start_div_name,
               sd.start_date    AS start_div_start,
               sd.end_date      AS start_div_end,
               (SELECT COUNT(*) FROM course_divisions WHERE course_id = c.id) AS total_divisions
        FROM course_enrollments en
        JOIN courses c ON c.id = en.course_id
        LEFT JOIN course_divisions cd ON cd.id = en.current_division_id
        LEFT JOIN course_divisions sd ON sd.id = en.starting_division_id
        WHERE en.user_id = ?
          AND en.status IN ('active','completed')
        ORDER BY en.started_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// ============================================================================
// PROGRESS
// ============================================================================

/**
 * Compute how far a student is within their current division.
 * Returns:
 *   [
 *     'week'          => int,      // current week (0 if before start, N if after end)
 *     'total_weeks'   => int,
 *     'pct'           => int,      // 0-100
 *     'days_elapsed'  => int,
 *     'days_remaining'=> int,
 *     'state'         => 'pending'|'in_progress'|'ended'|'completed',
 *   ]
 */
function courseComputeDivisionProgress(array $division, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $start = new DateTimeImmutable((string)$division['start_date']);
    $end = new DateTimeImmutable((string)$division['end_date']);

    $totalDays = $start->diff($end)->days + 1;
    $totalWeeks = max(1, (int)ceil($totalDays / 7));

    if ($today < $start) {
        return [
            'week' => 0,
            'total_weeks' => $totalWeeks,
            'pct' => 0,
            'days_elapsed' => 0,
            'days_remaining' => $start->diff($today)->days,
            'state' => 'pending',
        ];
    }
    if ($today > $end) {
        return [
            'week' => $totalWeeks,
            'total_weeks' => $totalWeeks,
            'pct' => 100,
            'days_elapsed' => $totalDays,
            'days_remaining' => 0,
            'state' => 'ended',
        ];
    }

    $daysElapsed = $start->diff($today)->days;
    $week = (int)floor($daysElapsed / 7) + 1;
    return [
        'week' => $week,
        'total_weeks' => $totalWeeks,
        'pct' => (int)round(($daysElapsed / $totalDays) * 100),
        'days_elapsed' => $daysElapsed + 1,
        'days_remaining' => $today->diff($end)->days,
        'state' => 'in_progress',
    ];
}

// ============================================================================
// GRADES
// ============================================================================

/**
 * Load per-subject grades for a student in a division.
 * Returns: subject_id => row from enrollment_course_grades (or a blank default row)
 */
function courseLoadStudentSubjectGrades(PDO $conn, int $enrollmentId, int $divisionId): array
{
    $stmt = $conn->prepare("
        SELECT ecg.*, s.name AS subject_name, s.icon AS subject_icon
        FROM enrollment_course_grades ecg
        JOIN subjects s ON s.id = ecg.subject_id
        WHERE ecg.enrollment_id = ? AND ecg.division_id = ?
        ORDER BY s.name ASC
    ");
    $stmt->execute([$enrollmentId, $divisionId]);
    $rows = $stmt->fetchAll();

    $bySubject = [];
    foreach ($rows as $r) {
        $bySubject[(int)$r['subject_id']] = $r;
    }
    return $bySubject;
}

/**
 * Compute the FINAL grade for one subject.
 *
 * Points model (must match academyComputeSubjectGrade()):
 *   earned = weekly_total + monthly_total + final_slot
 *   budget = quiz_total_points + exam_total_points + final_total_points
 *   grade  = earned / budget × 100
 *
 * final_slot = max(final_score, supplementary_score) when either is present.
 *
 * Returns null until the final (or supplementary) has been attempted.
 *
 * @param array $gradeRow  Row from enrollment_course_grades
 * @param array $subject   Row from course_subjects (needs *_total_points)
 */
function courseComputeGrade(array $gradeRow, array $subject): ?float
{
    $finalAttempted = (int)($gradeRow['final_attempted'] ?? 0);
    $suppAttempted  = (int)($gradeRow['supplementary_attempted'] ?? 0);
    $suppScore      = $gradeRow['supplementary_score'] ?? null;

    // Pass/fail only fires once the final (or a recorded supplementary) exists.
    if ($finalAttempted !== 1 && !($suppAttempted === 1 && $suppScore !== null)) {
        return null;
    }

    $weeklyEarned  = (float)($gradeRow['weekly_total']  ?? 0);
    $monthlyEarned = (float)($gradeRow['monthly_total'] ?? 0);

    $final = isset($gradeRow['final_score']) && $gradeRow['final_score'] !== null
        ? (float)$gradeRow['final_score']
        : null;
    $supp = ($suppAttempted === 1 && $suppScore !== null)
        ? (float)$suppScore
        : null;

    $finalSlot = null;
    if ($final !== null || $supp !== null) {
        $finalSlot = max($final ?? 0.0, $supp ?? 0.0);
    }

    $earned = $weeklyEarned + $monthlyEarned + ($finalSlot ?? 0.0);

    // No magic defaults — mirrors academyComputeSubjectGrade().
    $budget = (int)($subject['quiz_total_points']  ?? 0)
            + (int)($subject['exam_total_points']  ?? 0)
            + (int)($subject['final_total_points'] ?? 0);

    if ($budget <= 0) {
        return null;
    }

    return round(min(100.0, max(0.0, ($earned / $budget) * 100.0)), 2);
}

/**
 * Compute the PROJECTED grade for one subject.
 *
 * If the final hasn't been attempted, scale the student's accuracy on the
 * weekly + monthly buckets onto the FULL budget (including the final bucket).
 *
 * If nothing has been attempted, returns null.
 * If the final HAS been attempted, returns the real grade.
 *
 * @param array $gradeRow  Row from enrollment_course_grades
 * @param array $subject   Row from course_subjects
 */
function courseComputeProjectedGrade(array $gradeRow, array $subject): ?float
{
    $finalAttempted = (int)($gradeRow['final_attempted'] ?? 0);
    $suppAttempted  = (int)($gradeRow['supplementary_attempted'] ?? 0);
    $suppScore      = $gradeRow['supplementary_score'] ?? null;

    // Final is in — just use the real grade.
    if ($finalAttempted === 1 || ($suppAttempted === 1 && $suppScore !== null)) {
        return courseComputeGrade($gradeRow, $subject);
    }

    $budget = (int)($subject['quiz_total_points']  ?? 0)
            + (int)($subject['exam_total_points']  ?? 0)
            + (int)($subject['final_total_points'] ?? 0);
    if ($budget <= 0) {
        return null;
    }

    $weeklyEarned    = (float)($gradeRow['weekly_total']     ?? 0);
    $monthlyEarned   = (float)($gradeRow['monthly_total']    ?? 0);
    $weeklyPossible  = (float)($gradeRow['weekly_possible']  ?? 0);
    $monthlyPossible = (float)($gradeRow['monthly_possible'] ?? 0);

    $earnedSoFar    = $weeklyEarned + $monthlyEarned;
    $attemptedTotal = $weeklyPossible + $monthlyPossible;

    // Nothing attempted yet — no basis for a projection.
    if ($attemptedTotal <= 0) {
        return null;
    }

    $accuracy  = $earnedSoFar / $attemptedTotal;
    $projected = $accuracy * $budget;

    return round(min(100.0, max(0.0, ($projected / $budget) * 100.0)), 2);
}

// ============================================================================
// GRADE RECORDING — deprecated shim + helpers
// ============================================================================

/**
 * DEPRECATED — use academyRecordScheduled() / academyRecordAttempt() directly.
 *
 * Kept as a thin compatibility shim so old quiz / monthly-exam / semester-exam
 * pages keep working. Converts the old percentage argument into real points by
 * looking up the item's own total, then forwards to the single points-based
 * writer in includes/course_grades.php.
 *
 * Old signature:  courseRecordAttempt($conn, $type, $itemId, $userId, $percentage)
 * New model:      earned / possible raw points, written to weekly
 *
 * @param string $type  'quiz' | 'monthly_exam' | 'semester_exam' | 'semester_supplementary_exam'
 * @param int    $itemId
 * @param int    $userId
 * @param float  $percentage  Score 0-100 (legacy contract)
 */
function courseRecordAttempt(
    PDO $conn,
    string $type,
    int $itemId,
    int $userId,
    float $percentage
): void {
    // Ensure the points-based writer is available.
    if (!function_exists('academyRecordScheduled') || !function_exists('academyRecordAttempt')) {
        $helpers = __DIR__ . '/course_grades.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
    }
    if (!function_exists('academyRecordScheduled')) {
        error_log('courseRecordAttempt: course_grades.php not loaded, cannot record attempt');
        return;
    }

    $pct = max(0.0, min(100.0, $percentage));

    try {
        if ($type === 'quiz') {
            // academyRecordScheduled() looks up course/division/subject itself
            // and routes quizzes → weekly bucket.
            $total = courseLookupItemTotal($conn, 'quizzes', $itemId, 'total_points');
            if ($total === null) {
                error_log("courseRecordAttempt: quiz {$itemId} has no total_points; skipping");
                return;
            }
            $earned = round($pct / 100.0 * $total, 2);

            academyRecordScheduled($conn, $userId, 'quiz', $itemId, $earned, (float)$total);
            return;
        }

        if ($type === 'monthly_exam') {
            $total = courseLookupItemTotal($conn, 'monthly_exams', $itemId, 'total_points');
            if ($total === null) {
                error_log("courseRecordAttempt: monthly_exam {$itemId} has no total_points; skipping");
                return;
            }
            $earned = round($pct / 100.0 * $total, 2);

            academyRecordScheduled($conn, $userId, 'exam', $itemId, $earned, (float)$total);
            return;
        }

        if ($type === 'semester_exam' || $type === 'semester_supplementary_exam') {
            courseRecordSemesterExam($conn, $type, $itemId, $userId, $pct);
            return;
        }

        error_log("courseRecordAttempt: unknown type '{$type}'");
    } catch (Throwable $e) {
        error_log('courseRecordAttempt error: ' . $e->getMessage());
    }
}

/**
 * Look up an item's total_points column, returning null if missing or NULL.
 * Guards against columns that don't exist on older installs.
 */
function courseLookupItemTotal(PDO $conn, string $table, int $itemId, string $column): ?float
{
    try {
        $stmt = $conn->prepare("SELECT `$column` FROM `$table` WHERE id = ? LIMIT 1");
        $stmt->execute([$itemId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) {
            return null;
        }
        return (float)$val;
    } catch (PDOException $e) {
        error_log("courseLookupItemTotal {$table}.{$column}: " . $e->getMessage());
        return null;
    }
}

/**
 * Semester / supplementary exams are applied across every subject in the
 * division (or just the mapped subject if course_subject_id is set).
 * Forwards each subject to academyRecordAttempt() with the correct kind.
 */
/**
 * Semester / supplementary exams are tied to exactly one subject
 * (semester_exams.course_subject_id is NOT NULL). This helper looks up
 * that subject and forwards a single attempt to academyRecordAttempt().
 */
function courseRecordSemesterExam(
    PDO $conn,
    string $type,
    int $itemId,
    int $userId,
    float $pct
): void {
    // Load the exam row
    $stmt = $conn->prepare("
        SELECT division_id, course_subject_id, total_points
        FROM semester_exams
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$itemId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam || empty($exam['division_id'])) {
        return;
    }

    $divisionId = (int)$exam['division_id'];
    $total      = isset($exam['total_points']) && $exam['total_points'] !== null
        ? (float)$exam['total_points']
        : null;

    // Course id (from the division)
    $stmt = $conn->prepare("SELECT course_id FROM course_divisions WHERE id = ?");
    $stmt->execute([$divisionId]);
    $courseId = (int)$stmt->fetchColumn();
    if ($courseId <= 0) {
        return;
    }

    // Find the student's enrollment
    $stmt = $conn->prepare("
        SELECT id FROM course_enrollments
        WHERE course_id = ? AND user_id = ? AND status IN ('active','completed')
        LIMIT 1
    ");
    $stmt->execute([$courseId, $userId]);
    $enrollmentId = (int)$stmt->fetchColumn();
    if ($enrollmentId <= 0) {
        return;
    }

    // If total_points is unknown, we cannot convert % → points. Fall back to
    // a sane default so the write still happens (better than dropping the
    // attempt silently). 100 is the natural ceiling for a "final_total_points"
    // style bucket; the budget comparison still works.
    if ($total === null || $total <= 0) {
        $total = 100.0;
    }

    $earned = round($pct / 100.0 * $total, 2);

    // Option A: each semester exam belongs to exactly one subject.
    if (empty($exam['course_subject_id'])) {
        error_log("courseRecordSemesterExam: exam {$itemId} has no course_subject_id");
        return;
    }

    $stmt = $conn->prepare("SELECT subject_id FROM course_subjects WHERE id = ?");
    $stmt->execute([(int)$exam['course_subject_id']]);
    $subjectId = (int)$stmt->fetchColumn();
    if ($subjectId <= 0) {
        error_log("courseRecordSemesterExam: course_subjects.id={$exam['course_subject_id']} not found");
        return;
    }

    $kind = ($type === 'semester_exam') ? 'final' : 'supplementary';

    academyRecordAttempt(
        $conn,
        $userId,
        $kind,
        $courseId,
        $divisionId,
        $subjectId,
        $earned,
        $total
    );
}

// ============================================================================
// ENROLLMENT (student joins a course)
// ============================================================================

/**
 * Enrolls a student into a course.
 *   - Finds the next upcoming division
 *   - Sets started_at = that division's start_date
 *   - Sets starting_division_id AND current_division_id = that division
 *   - Sets division_status = 'pending' (until the division starts)
 *
 * Returns [success, message, enrollmentId|null]
 */
function courseEnrollStudent(PDO $conn, int $userId, int $courseId): array
{
    $today = new DateTimeImmutable('today');

    // 1. Verify course is published
    $stmt = $conn->prepare("SELECT id, title, status FROM courses WHERE id = ?");
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();
    if (!$course || $course['status'] !== 'published') {
        return [false, 'This course is not open for enrollment.', null];
    }

    // 2. Find the next upcoming division
    $nextDiv = courseFindNextDivision($conn, $courseId, $today);
    if (!$nextDiv) {
        return [false, 'This course has no upcoming intake. Please check back later.', null];
    }

    // 3. Check existing enrollment
    $stmt = $conn->prepare("SELECT id, status FROM course_enrollments WHERE course_id = ? AND user_id = ?");
    $stmt->execute([$courseId, $userId]);
    $existing = $stmt->fetch();

    if ($existing && $existing['status'] !== 'dropped') {
        return [false, 'You are already enrolled in this course.', (int)$existing['id']];
    }

    // 4. Insert or reactivate
    try {
        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE course_enrollments
                SET status = 'active',
                    starting_division_id = ?,
                    current_division_id = ?,
                    started_at = ?,
                    current_semester = 1,
                    division_status = 'pending',
                    enrolled_at = NOW(),
                    completed_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([
                (int)$nextDiv['id'],
                (int)$nextDiv['id'],
                $nextDiv['start_date'] . ' 00:00:00',
                (int)$existing['id'],
            ]);
            return [true, 'Re-enrolled. Your first semester begins on ' . date('d M Y', strtotime($nextDiv['start_date'])) . '.', (int)$existing['id']];
        } else {
            $stmt = $conn->prepare("
                INSERT INTO course_enrollments
                    (course_id, starting_division_id, current_division_id, user_id,
                     started_at, current_semester, division_status, status)
                VALUES (?, ?, ?, ?, ?, 1, 'pending', 'active')
            ");
            $stmt->execute([
                $courseId,
                (int)$nextDiv['id'],
                (int)$nextDiv['id'],
                $userId,
                $nextDiv['start_date'] . ' 00:00:00',
            ]);
            return [true, 'Enrolled! Your first semester begins on ' . date('d M Y', strtotime($nextDiv['start_date'])) . '.', (int)$conn->lastInsertId()];
        }
    } catch (PDOException $e) {
        error_log('courseEnrollStudent error: ' . $e->getMessage());
        return [false, 'A database error occurred. Please try again.', null];
    }
}

/**
 * Auto-advance a student if their current division has ended AND they passed.
 * Called on page load from course.php.
 *
 * Returns true if the enrollment was updated.
 */
function courseCheckAdvancement(PDO $conn, int $enrollmentId): bool
{
    $today = new DateTimeImmutable('today');

    $stmt = $conn->prepare("
        SELECT en.*, cd.end_date AS current_div_end, cd.number AS current_div_number
        FROM course_enrollments en
        LEFT JOIN course_divisions cd ON cd.id = en.current_division_id
        WHERE en.id = ?
    ");
    $stmt->execute([$enrollmentId]);
    $en = $stmt->fetch();
    if (!$en) {
        return false;
    }

    if (!$en['current_division_id'] || !$en['current_div_end']) {
        return false;
    }

    $divEnd = new DateTimeImmutable((string)$en['current_div_end']);

    if ($today <= $divEnd) {
        return false;
    }

    if ($en['division_status'] !== 'passed') {
        return false;
    }

    $nextDiv = courseFindNextDivisionAfter($conn, (int)$en['course_id'], (int)$en['current_division_id']);
    if (!$nextDiv) {
        $conn->prepare("
            UPDATE course_enrollments
            SET status = 'completed',
                division_status = 'completed',
                completed_at = NOW()
            WHERE id = ?
        ")->execute([$enrollmentId]);
        return true;
    }

    $conn->prepare("
        UPDATE course_enrollments
        SET current_division_id = ?,
            current_semester = current_semester + 1,
            division_status = 'pending'
        WHERE id = ?
    ")->execute([(int)$nextDiv['id'], $enrollmentId]);

    return true;
}

// ============================================================================
// ADMIN (roster) — kept for backward compatibility
// ============================================================================

/**
 * The old roster loader — kept because admin pages still use it.
 * Now uses the new per-student model (current_division_id / division_status).
 */
function courseLoadRoster(PDO $conn, int $courseId): array
{
    $stmt = $conn->prepare("
        SELECT en.id              AS enrollment_id,
               en.status          AS enrollment_status,
               en.division_status,
               en.started_at,
               en.current_semester,
               en.starting_division_id,
               en.current_division_id,
               u.id               AS user_id,
               u.username,
               u.email,
               u.full_name,
               sd.name            AS starting_division_name,
               cd.name            AS current_division_name
        FROM course_enrollments en
        JOIN users u ON u.id = en.user_id
        LEFT JOIN course_divisions sd ON sd.id = en.starting_division_id
        LEFT JOIN course_divisions cd ON cd.id = en.current_division_id
        WHERE en.course_id = ?
        ORDER BY FIELD(en.status, 'active', 'completed', 'dropped'), en.started_at DESC
    ");
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

// ============================================================================
// DISPLAY HELPERS
// ============================================================================

function courseItemLink(string $type, int $itemId): string
{
    switch ($type) {
        case 'quiz':
            return 'quizzes.php?id=' . $itemId;
        case 'exam':
            return 'monthly_exams.php';
        default:
            return 'content.php?id=' . $itemId;
    }
}

/**
 * Hijri date as "dd / mm / yyyy" (Umm al-Qura when intl exists, else tabular).
 */
function courseHijriShort(DateTimeImmutable $date): string
{
    if (class_exists('IntlDateFormatter')) {
        try {
            $noon = new DateTime($date->format('Y-m-d') . ' 12:00:00', new DateTimeZone('Asia/Riyadh'));
            $fmt = new IntlDateFormatter(
                'en_US@calendar=islamic-umalqura',
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'Asia/Riyadh',
                IntlDateFormatter::TRADITIONAL,
                'dd / MM / yyyy'
            );
            $out = $fmt->format($noon);
            if (!empty($out)) {
                return $out;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }

    $y = (int)$date->format('Y');
    $m = (int)$date->format('n');
    $d = (int)$date->format('j');

    $a = intdiv(14 - $m, 12);
    $y2 = $y + 4800 - $a;
    $m2 = $m + 12 * $a - 3;
    $jd = $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4) - intdiv($y2, 100) + intdiv($y2, 400) - 32045;

    $l = $jd - 1948440 + 10632;
    $n = (int)floor(($l - 1) / 10631);
    $l = $l - 10631 * $n + 354;
    $j = (int)floor((10985 - $l) / 5316) * (int)floor((50 * $l) / 17719)
        + (int)floor($l / 5670) * (int)floor((43 * $l) / 15238);
    $l = $l - (int)floor((30 - $j) / 15) * (int)floor((17719 * $j) / 50)
        - (int)floor($j / 16) * (int)floor((15238 * $j) / 43) + 29;
    $hMonth = (int)floor((24 * $l) / 709);
    $hDay = $l - (int)floor((709 * $hMonth) / 24);
    $hYear = 30 * $n + $j - 30;

    return sprintf('%02d / %02d / %04d', $hDay, $hMonth, $hYear);
}