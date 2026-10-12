<?php
declare(strict_types=1);

/**
 * Shared helpers for the student side of the Courses module.
 * Used by courses.php, course.php and user_dashboard.php.
 *
 * Day numbering: Day 1 = the cohort's start date.
 */

const COURSE_ITEM_TABLES = ['content' => 'contents', 'quiz' => 'quizzes', 'exam' => 'monthly_exams'];
const COURSE_ITEM_LABELS = ['content' => 'Lesson', 'quiz' => 'Quiz', 'exam' => 'Exam'];

/** True once admin_courses.php has created the four tables. */
function courseTablesExist(PDO $conn): bool
{
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('courses', 'course_items', 'course_cohorts', 'course_enrollments')
        ");
        return (int)$stmt->fetchColumn() === 4;
    } catch (PDOException $e) {
        return false;
    }
}

function courseDayDate(string $startDate, int $day): DateTimeImmutable
{
    return (new DateTimeImmutable($startDate))->modify('+' . max(0, $day - 1) . ' days');
}

function courseCohortEnd(string $startDate, int $durationDays): DateTimeImmutable
{
    return courseDayDate($startDate, $durationDays);
}

/** [label, css-class] derived from dates + the manual cohort status. */
function courseCohortPhase(array $cohort, int $durationDays, DateTimeImmutable $today): array
{
    if ($cohort['status'] === 'cancelled') {
        return ['Cancelled', 'danger'];
    }
    if ($cohort['status'] === 'draft') {
        return ['Draft', 'muted'];
    }

    $start = new DateTimeImmutable($cohort['start_date']);
    $end = courseCohortEnd($cohort['start_date'], $durationDays);
    $deadline = new DateTimeImmutable($cohort['enrollment_deadline']);

    if ($today > $end) {
        return ['Completed', 'muted'];
    }
    if ($today < $start) {
        return $today <= $deadline ? ['Enrolling', 'brand'] : ['Enrollment closed', 'gold'];
    }
    return ['Running', 'accent'];
}

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
 * The schedule of a course for one student: only published items, each with
 * its title and whether this student has already completed it.
 */
function courseLoadItems(PDO $conn, int $courseId, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT id, item_type, item_id, unlock_day, due_day
        FROM course_items
        WHERE course_id = ?
        ORDER BY unlock_day ASC, sort_order ASC, id ASC
    ");
    $stmt->execute([$courseId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return [];
    }

    $idsByType = ['content' => [], 'quiz' => [], 'exam' => []];
    foreach ($rows as $r) {
        if (isset($idsByType[$r['item_type']])) {
            $idsByType[$r['item_type']][] = (int)$r['item_id'];
        }
    }

    $titles = [];
    $done = [];
    foreach (COURSE_ITEM_TABLES as $type => $table) {
        $titles[$type] = [];
        $done[$type] = [];
        $ids = array_values(array_unique($idsByType[$type]));
        if (!$ids) {
            continue;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $s = $conn->prepare("SELECT id, title FROM `$table` WHERE status = 'published' AND id IN ($in)");
        $s->execute($ids);
        foreach ($s->fetchAll() as $t) {
            $titles[$type][(int)$t['id']] = (string)$t['title'];
        }

        if ($type === 'content') {
            $sql = "SELECT DISTINCT content_id AS item_id FROM user_content_progress WHERE user_id = ? AND is_completed = 1 AND content_id IN ($in)";
        } elseif ($type === 'quiz') {
            $sql = "SELECT DISTINCT quiz_id AS item_id FROM quiz_attempts WHERE user_id = ? AND status = 'completed' AND quiz_id IN ($in)";
        } else {
            $sql = "SELECT DISTINCT exam_id AS item_id FROM monthly_exam_attempts WHERE user_id = ? AND status = 'completed' AND exam_id IN ($in)";
        }
        $s = $conn->prepare($sql);
        $s->execute(array_merge([$userId], $ids));
        foreach ($s->fetchAll() as $d) {
            $done[$type][(int)$d['item_id']] = true;
        }
    }

    $items = [];
    foreach ($rows as $r) {
        $type = (string)$r['item_type'];
        $itemId = (int)$r['item_id'];
        if (!isset($titles[$type][$itemId])) {
            continue; // unpublished or deleted — hidden from students
        }
        $items[] = [
            'id' => (int)$r['id'],
            'type' => $type,
            'item_id' => $itemId,
            'unlock_day' => (int)$r['unlock_day'],
            'due_day' => $r['due_day'] !== null ? (int)$r['due_day'] : null,
            'title' => $titles[$type][$itemId],
            'done' => isset($done[$type][$itemId]),
        ];
    }
    return $items;
}

/** @return array{total:int,done:int,pct:int} */
function courseProgressSummary(array $items): array
{
    $total = count($items);
    $done = 0;
    foreach ($items as $it) {
        if ($it['done']) {
            $done++;
        }
    }
    return [
        'total' => $total,
        'done' => $done,
        'pct' => $total > 0 ? (int)round(($done / $total) * 100) : 0,
    ];
}

/**
 * State of one schedule item for a cohort today:
 * done | locked | open | missed. Lessons never become "missed".
 */
function courseItemState(array $item, string $startDate, int $durationDays, DateTimeImmutable $today, bool $cancelled = false): array
{
    $unlock = courseDayDate($startDate, $item['unlock_day']);
    $end = courseCohortEnd($startDate, $durationDays);
    $due = $item['due_day'] !== null ? courseDayDate($startDate, $item['due_day']) : $end;

    if ($item['done']) {
        $state = 'done';
    } elseif ($cancelled || $today < $unlock) {
        $state = 'locked';
    } elseif ($item['type'] !== 'content' && $today > $due) {
        $state = 'missed';
    } else {
        $state = 'open';
    }

    return ['state' => $state, 'unlock' => $unlock, 'due' => $due];
}

/**
 * The enrollment to feature on the student dashboard: the running cohort if
 * there is one, otherwise the soonest upcoming one. Null when nothing applies.
 */
function courseGetDashboardCard(PDO $conn, int $userId, DateTimeImmutable $today): ?array
{
    if (!courseTablesExist($conn)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT h.id AS cohort_id, h.name AS cohort_name, h.start_date,
               c.id AS course_id, c.title, c.duration_days
        FROM course_enrollments en
        JOIN course_cohorts h ON h.id = en.cohort_id
        JOIN courses c ON c.id = h.course_id
        WHERE en.user_id = ? AND en.status = 'active' AND h.status = 'published'
        ORDER BY h.start_date ASC
    ");
    $stmt->execute([$userId]);

    $pick = null;
    foreach ($stmt->fetchAll() as $row) {
        $start = new DateTimeImmutable($row['start_date']);
        $end = courseCohortEnd($row['start_date'], (int)$row['duration_days']);
        if ($today > $end) {
            continue;
        }
        $running = $today >= $start;
        if ($pick === null || ($running && !$pick['running'])) {
            $pick = $row + ['running' => $running, 'start' => $start];
        }
    }
    if ($pick === null) {
        return null;
    }

    $duration = (int)$pick['duration_days'];
    $items = courseLoadItems($conn, (int)$pick['course_id'], $userId);
    $summary = courseProgressSummary($items);

    $next = null;
    $openCount = 0;
    foreach ($items as $it) {
        $info = courseItemState($it, $pick['start_date'], $duration, $today);
        if ($info['state'] === 'open') {
            $openCount++;
            if ($next === null) {
                $next = $it;
            }
        }
    }

    return [
        'cohort_id' => (int)$pick['cohort_id'],
        'title' => (string)$pick['title'],
        'cohort_name' => (string)$pick['cohort_name'],
        'running' => (bool)$pick['running'],
        'day_number' => $pick['running'] ? (int)$pick['start']->diff($today)->days + 1 : 0,
        'duration_days' => $duration,
        'starts_in_days' => $pick['running'] ? 0 : (int)$today->diff($pick['start'])->days,
        'start_date' => (string)$pick['start_date'],
        'progress' => $summary,
        'open_count' => $openCount,
        'next_title' => $next['title'] ?? null,
        'next_link' => $next ? courseItemLink($next['type'], $next['item_id']) : null,
    ];
}

/** The cohort row for a student who is enrolled in it (active or completed), or null. */
function courseLoadEnrolledCohort(PDO $conn, int $cohortId, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT h.id AS cohort_id, h.name AS cohort_name, h.start_date, h.enrollment_deadline, h.status AS cohort_status,
               c.id AS course_id, c.title, c.description, c.duration_days,
               en.id AS enrollment_id, en.status AS enrollment_status
        FROM course_enrollments en
        JOIN course_cohorts h ON h.id = en.cohort_id
        JOIN courses c ON c.id = h.course_id
        WHERE h.id = ? AND en.user_id = ? AND en.status IN ('active', 'completed') AND h.status <> 'draft'
        LIMIT 1
    ");
    $stmt->execute([$cohortId, $userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Hijri date as "dd / mm / yyyy" (Umm al-Qura when the intl extension has it,
 * otherwise the tabular civil calendar — same fallback as the dashboard).
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
            // fall through to the tabular calendar
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

/**
 * Schedule items enriched for the weekly grid: each item gets its subject
 * (column), the pass mark, and the student's best grade (quizzes / exams).
 *
 * @return array{items:array,subjects:array<int,string>}  subjects: id => name (0 = General)
 */
function courseLoadScheduleItems(PDO $conn, int $courseId, int $userId): array
{
    $items = courseLoadItems($conn, $courseId, $userId);
    if (!$items) {
        return ['items' => [], 'subjects' => []];
    }

    $ids = ['content' => [], 'quiz' => [], 'exam' => []];
    foreach ($items as $it) {
        $ids[$it['type']][] = $it['item_id'];
    }

    $subjectOf = ['content' => [], 'quiz' => [], 'exam' => []];
    $passOf = ['quiz' => [], 'exam' => []];
    $gradeOf = ['quiz' => [], 'exam' => []];

    foreach (COURSE_ITEM_TABLES as $type => $table) {
        $list = array_values(array_unique($ids[$type]));
        if (!$list) {
            continue;
        }
        $in = implode(',', array_fill(0, count($list), '?'));
        $cols = $type === 'content' ? 'id, subject_id' : 'id, subject_id, passing_score';
        $s = $conn->prepare("SELECT $cols FROM `$table` WHERE id IN ($in)");
        $s->execute($list);
        foreach ($s->fetchAll() as $r) {
            $subjectOf[$type][(int)$r['id']] = $r['subject_id'] !== null ? (int)$r['subject_id'] : 0;
            if ($type !== 'content') {
                $passOf[$type][(int)$r['id']] = (int)$r['passing_score'];
            }
        }

        if ($type === 'quiz') {
            $sql = "SELECT quiz_id AS item_id, MAX(percentage) AS best FROM quiz_attempts WHERE user_id = ? AND status = 'completed' AND quiz_id IN ($in) GROUP BY quiz_id";
        } elseif ($type === 'exam') {
            $sql = "SELECT exam_id AS item_id, MAX(percentage) AS best FROM monthly_exam_attempts WHERE user_id = ? AND status = 'completed' AND exam_id IN ($in) GROUP BY exam_id";
        } else {
            continue;
        }
        $s = $conn->prepare($sql);
        $s->execute(array_merge([$userId], $list));
        foreach ($s->fetchAll() as $r) {
            $gradeOf[$type][(int)$r['item_id']] = $r['best'] !== null ? (float)$r['best'] : null;
        }
    }

    $subjectIds = [];
    foreach ($items as &$it) {
        $it['subject_id'] = $subjectOf[$it['type']][$it['item_id']] ?? 0;
        $it['passing'] = $passOf[$it['type']][$it['item_id']] ?? null;
        $it['grade'] = $gradeOf[$it['type']][$it['item_id']] ?? null;
        if ($it['subject_id'] > 0) {
            $subjectIds[$it['subject_id']] = true;
        }
    }
    unset($it);

    $subjects = [];
    if ($subjectIds) {
        $in = implode(',', array_fill(0, count($subjectIds), '?'));
        $s = $conn->prepare("SELECT id, name FROM subjects WHERE id IN ($in) ORDER BY sort_order ASC, name ASC");
        $s->execute(array_keys($subjectIds));
        foreach ($s->fetchAll() as $r) {
            $subjects[(int)$r['id']] = (string)$r['name'];
        }
    }
    foreach ($items as $it) {
        if ($it['subject_id'] === 0 || !isset($subjects[$it['subject_id']])) {
            $subjects[0] = 'General';
            break;
        }
    }
    foreach ($items as &$it) {
        if (!isset($subjects[$it['subject_id']])) {
            $it['subject_id'] = 0;
        }
    }
    unset($it);

    return ['items' => $items, 'subjects' => $subjects];
}