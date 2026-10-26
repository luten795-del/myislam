<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = (int)$ctx['userId'];

$today = new DateTimeImmutable('today');
$now   = new DateTimeImmutable('now');

// ---------------------------------------------------------------------------
// HELPERS — color / section rendering (mirrors content.php)
// ---------------------------------------------------------------------------

function isUntouchedDefaultColorPair(?string $textColor, ?string $bgColor): bool
{
    $normalize = static fn(?string $c) => $c ? strtolower(trim($c)) : '';
    return $normalize($textColor) === '#1f2937' && $normalize($bgColor) === '#ffffff';
}

function hasCustomTextColor(?string $textColor, ?string $bgColor): bool
{
    if (!$textColor || $textColor === '') return false;
    if (isUntouchedDefaultColorPair($textColor, $bgColor)) return false;
    return true;
}

function needsDarkModeTextFallback(?string $textColor, ?string $bgColor): bool
{
    if (!hasCustomTextColor($textColor, $bgColor)) return false;
    if ($bgColor && $bgColor !== '') return false;
    return isColorDark($textColor);
}

function isColorDark(string $color): bool
{
    $color = strtolower(trim($color));
    if ($color === '') return false;
    if ($color === 'black') return true;
    if ($color === 'white') return false;
    if ($color[0] !== '#') return false;
    $hex = ltrim($color, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return false;
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance < 0.35;
}

// ---------------------------------------------------------------------------
// INPUTS
// ---------------------------------------------------------------------------
$courseId  = (int)($_GET['course_id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$lessonId  = (int)($_GET['lesson'] ?? 0);
$partParam = max(1, (int)($_GET['part'] ?? 1));

if ($courseId <= 0 || $subjectId <= 0) {
    courseFlash('error', 'Invalid subject link.');
    courseRedirect('courses.php');
}

// ---------------------------------------------------------------------------
// ENROLLMENT + COURSE
// ---------------------------------------------------------------------------
$en = courseLoadEnrollment($conn, $userId, $courseId);
if (!$en) {
    courseFlash('error', "You're not enrolled in that course.");
    courseRedirect('courses.php');
}

if (empty($en['current_div_id'])) {
    courseFlash('info', 'This course has no active division yet.');
    courseRedirect('course.php?course_id=' . $courseId);
}

$currentEnrollmentId = (int)$en['id'];

$curDiv = [
    'id'            => (int)$en['current_div_id'],
    'number'        => (int)$en['current_div_number'],
    'name'          => (string)$en['current_div_name'],
    'start_date'    => (string)$en['current_div_start'],
    'end_date'      => (string)$en['current_div_end'],
    'passing_score' => (int)$en['current_div_passing'],
];

// ---------------------------------------------------------------------------
// SUBJECT
// ---------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT cs.id AS course_subject_id,
           cs.subject_id,
           cs.passing_score,
           cs.quiz_total_points,
           cs.exam_total_points,
           cs.final_total_points,
           cs.supplementary_max_score,
           s.name,
           s.slug AS subject_slug,
           s.icon,
           s.description,
           s.image_path,
           s.markaz_image_path,
           s.sheikh_name,
           s.markaz,
           s.type AS subject_type
    FROM course_subjects cs
    JOIN subjects s ON s.id = cs.subject_id
    WHERE cs.course_id = ? AND cs.subject_id = ?
    LIMIT 1
");
$stmt->execute([$courseId, $subjectId]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$subject) {
    courseFlash('error', 'That subject is not part of this course.');
    courseRedirect('course.php?course_id=' . $courseId);
}

$subjectSlug = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)($subject['name'] ?? 'subject')));

$quizBudget  = (int)($subject['quiz_total_points']  ?? 0);
$examBudget  = (int)($subject['exam_total_points']  ?? 0);
$finalBudget = (int)($subject['final_total_points'] ?? 0);
$totalBudget = $quizBudget + $examBudget + $finalBudget;

// ---------------------------------------------------------------------------
// GRADE ROW
// ---------------------------------------------------------------------------
$gradeRow = null;
try {
    $stmt = $conn->prepare("
        SELECT * FROM enrollment_course_grades
        WHERE enrollment_id = ? AND division_id = ? AND subject_id = ?
        LIMIT 1
    ");
    $stmt->execute([$currentEnrollmentId, $curDiv['id'], $subjectId]);
    $gradeRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log('course_subject.php grade row: ' . $e->getMessage());
}

$projected = null;
$finalGrade = null;
$gradeStatus = 'in_progress';

$points = [
    'weekly_earned'   => 0.0,
    'monthly_earned'  => 0.0,
    'final_earned'    => null,
    'supp_earned'     => null,
    'final_slot'      => null,
    'total_earned'    => 0.0,
    'total_budget'    => $totalBudget,
    'percent'         => 0.0,
];

if ($gradeRow) {
    $projected = function_exists('courseComputeProjectedGrade')
        ? courseComputeProjectedGrade($gradeRow, $subject)
        : null;
    $finalGrade = function_exists('courseComputeGrade')
        ? courseComputeGrade($gradeRow, $subject)
        : null;
    $gradeStatus = (string)($gradeRow['status'] ?? 'in_progress');

    $points['weekly_earned']  = (float)$gradeRow['weekly_total'];
    $points['monthly_earned'] = (float)$gradeRow['monthly_total'];
    $points['final_earned']   = $gradeRow['final_score'] !== null ? (float)$gradeRow['final_score'] : null;
    $points['supp_earned']    = ((int)$gradeRow['supplementary_attempted'] === 1 && $gradeRow['supplementary_score'] !== null)
        ? (float)$gradeRow['supplementary_score']
        : null;

    if ($points['final_earned'] === null && $points['supp_earned'] === null) {
        $points['final_slot'] = null;
    } else {
        $points['final_slot'] = max($points['final_earned'] ?? 0.0, $points['supp_earned'] ?? 0.0);
    }

    $points['total_earned'] = $points['weekly_earned'] + $points['monthly_earned'] + ($points['final_slot'] ?? 0.0);
    $points['percent'] = $totalBudget > 0
        ? round($points['total_earned'] / $totalBudget * 100, 1)
        : 0.0;
}

// ---------------------------------------------------------------------------
// HELPERS — window + attempt resolution (course-scoped, tolerant of legacy NULL rows)
// ---------------------------------------------------------------------------

function subjectItemWindow(array $row, array $itemData, DateTimeImmutable $divStart, string $type): array {
    $week = max(1, (int)($row['week_number'] ?? 1));
    $wd   = max(1, min(7, (int)($row['weekday'] ?? 1)));
    $time = !empty($row['start_time']) ? (string)$row['start_time'] : '00:00:00';

    $hh = (int)substr($time, 0, 2);
    $mm = (int)substr($time, 3, 2);
    $ss = strlen($time) >= 8 ? (int)substr($time, 6, 2) : 0;

    $unlock = $divStart
        ->modify('+' . (($week - 1) * 7 + ($wd - 1)) . ' days')
        ->setTime($hh, $mm, $ss);

    $lockAt = null;
    $tz = new DateTimeZone('Africa/Dar_es_Salaam');

    if ($type === 'quiz') {
        if (!empty($itemData['competition_start_at']) && !empty($itemData['competition_end_at'])) {
            $unlock = new DateTimeImmutable((string)$itemData['competition_start_at'], $tz);
            $lockAt = new DateTimeImmutable((string)$itemData['competition_end_at'], $tz);
        }
    } elseif ($type === 'exam') {
        if (!empty($itemData['start_date']) && !empty($itemData['end_date'])) {
            $unlock = new DateTimeImmutable((string)$itemData['start_date'], $tz);
            $lockAt = new DateTimeImmutable((string)$itemData['end_date'], $tz);
        }
    }

    return [$unlock, $lockAt];
}

function subjectQuizAttemptState(
    PDO $conn,
    int $userId,
    int $enrollmentId,
    int $courseId,
    int $quizId,
    array $quizRow,
    DateTimeImmutable $now,
    DateTimeImmutable $unlockAt,
    ?DateTimeImmutable $lockAt
): array {
    $windowState = 'live';
    if ($lockAt === null) $windowState = 'no_window';
    elseif ($now < $unlockAt) $windowState = 'upcoming';
    elseif ($now > $lockAt) $windowState = 'ended';

    $isCompetition = (($quizRow['mode'] ?? '') === 'competition');
    $attempted = false;
    $score = $total = $pct = null;
    $passed = null;
    $attemptId = null;

    $scopeSql = "
        AND (course_id = :course_id OR course_id IS NULL)
        AND (enrollment_id = :enrollment_id OR enrollment_id IS NULL)
    ";

    try {
        if ($isCompetition && $lockAt !== null) {
            $stmt = $conn->prepare("
                SELECT id, score, total_points, percentage, passed, status
                FROM quiz_attempts
                WHERE user_id = :user_id
                  AND quiz_id = :quiz_id
                  AND status = 'completed'
                  AND completed_at BETWEEN :start_at AND :end_at
                  {$scopeSql}
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':user_id'       => $userId,
                ':quiz_id'       => $quizId,
                ':start_at'      => $unlockAt->format('Y-m-d H:i:s'),
                ':end_at'        => $lockAt->format('Y-m-d H:i:s'),
                ':course_id'     => $courseId,
                ':enrollment_id' => $enrollmentId,
            ]);
        } else {
            $stmt = $conn->prepare("
                SELECT id, score, total_points, percentage, passed, status
                FROM quiz_attempts
                WHERE user_id = :user_id
                  AND quiz_id = :quiz_id
                  AND status = 'completed'
                  {$scopeSql}
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':user_id'       => $userId,
                ':quiz_id'       => $quizId,
                ':course_id'     => $courseId,
                ':enrollment_id' => $enrollmentId,
            ]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $attempted = true;
            $attemptId = (int)$row['id'];
            $score     = (int)$row['score'];
            $total     = (int)$row['total_points'];
            $pct       = (float)$row['percentage'];
            $passed    = ((int)$row['passed'] === 1);
        }
    } catch (PDOException $e) {
        error_log('subjectQuizAttemptState: ' . $e->getMessage());
    }

    return [
        'window_state' => $windowState, 'attempted' => $attempted,
        'score' => $score, 'total' => $total, 'pct' => $pct, 'passed' => $passed,
        'attempt_id' => $attemptId, 'unlock_at' => $unlockAt, 'lock_at' => $lockAt,
    ];
}

function subjectExamAttemptState(
    PDO $conn,
    int $userId,
    int $enrollmentId,
    int $courseId,
    int $examId,
    array $examRow,
    DateTimeImmutable $now,
    DateTimeImmutable $unlockAt,
    ?DateTimeImmutable $lockAt
): array {
    $windowState = 'live';
    if ($lockAt === null) $windowState = 'no_window';
    elseif ($now < $unlockAt) $windowState = 'upcoming';
    elseif ($now > $lockAt) $windowState = 'ended';

    $source = (string)($examRow['_source'] ?? 'monthly');
    $table  = ($source === 'semester') ? 'semester_exam_attempts' : 'monthly_exam_attempts';

    $attempted = false;
    $score = $total = $pct = null;
    $passed = null;
    $attemptId = null;

    $scopeSql = "
        AND (course_id = :course_id OR course_id IS NULL)
        AND (enrollment_id = :enrollment_id OR enrollment_id IS NULL)
    ";

    try {
        if ($lockAt !== null) {
            $stmt = $conn->prepare("
                SELECT id, score, total_points, percentage, passed, status
                FROM `$table`
                WHERE user_id = :user_id
                  AND exam_id = :exam_id
                  AND status = 'completed'
                  AND completed_at BETWEEN :start_at AND :end_at
                  {$scopeSql}
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':user_id'       => $userId,
                ':exam_id'       => $examId,
                ':start_at'      => $unlockAt->format('Y-m-d H:i:s'),
                ':end_at'        => $lockAt->format('Y-m-d H:i:s'),
                ':course_id'     => $courseId,
                ':enrollment_id' => $enrollmentId,
            ]);
        } else {
            $stmt = $conn->prepare("
                SELECT id, score, total_points, percentage, passed, status
                FROM `$table`
                WHERE user_id = :user_id
                  AND exam_id = :exam_id
                  AND status = 'completed'
                  {$scopeSql}
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':user_id'       => $userId,
                ':exam_id'       => $examId,
                ':course_id'     => $courseId,
                ':enrollment_id' => $enrollmentId,
            ]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $attempted = true;
            $attemptId = (int)$row['id'];
            $score     = (int)$row['score'];
            $total     = (int)$row['total_points'];
            $pct       = (float)$row['percentage'];
            $passed    = ((int)$row['passed'] === 1);
        }
    } catch (PDOException $e) {
        error_log('subjectExamAttemptState: ' . $e->getMessage());
    }

    return [
        'window_state' => $windowState, 'attempted' => $attempted,
        'score' => $score, 'total' => $total, 'pct' => $pct, 'passed' => $passed,
        'attempt_id' => $attemptId, 'unlock_at' => $unlockAt, 'lock_at' => $lockAt,
    ];
}

// ---------------------------------------------------------------------------
// SCHEDULE
// ---------------------------------------------------------------------------
$scheduleRows = [];
try {
    $stmt = $conn->prepare("
        SELECT cs.id AS schedule_id, cs.week_number, cs.weekday, cs.start_time,
               cs.duration_minutes, cs.item_type, cs.item_id
        FROM course_schedule cs
        WHERE cs.division_id = ? AND cs.subject_id = ?
        ORDER BY cs.week_number ASC, cs.weekday ASC, cs.start_time ASC, cs.sort_order ASC
    ");
    $stmt->execute([$curDiv['id'], $subjectId]);
    $scheduleRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('course_subject.php schedule: ' . $e->getMessage());
}

$contentIds = $quizIds = $examIds = [];
foreach ($scheduleRows as $r) {
    $id = (int)$r['item_id'];
    if ($r['item_type'] === 'content') $contentIds[] = $id;
    elseif ($r['item_type'] === 'quiz') $quizIds[] = $id;
    elseif ($r['item_type'] === 'exam') $examIds[] = $id;
}

$contentMap = [];
if ($contentIds) {
    $in = implode(',', array_fill(0, count($contentIds), '?'));
    $stmt = $conn->prepare("SELECT id, title, slug, status, audio_path, video_path, video_url FROM contents WHERE id IN ($in)");
    $stmt->execute($contentIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $contentMap[(int)$c['id']] = $c;
}

$quizMap = [];
if ($quizIds) {
    $in = implode(',', array_fill(0, count($quizIds), '?'));
    $stmt = $conn->prepare("
        SELECT id, title, slug, mode, status, total_points,
               competition_start_at, competition_end_at, passing_score
        FROM quizzes WHERE id IN ($in)
    ");
    $stmt->execute($quizIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) $quizMap[(int)$q['id']] = $q;
}

$examMap = [];
if ($examIds) {
    $in = implode(',', array_fill(0, count($examIds), '?'));
    $stmt = $conn->prepare("SELECT id, title, slug, status, start_date, end_date, passing_score FROM monthly_exams WHERE id IN ($in)");
    $stmt->execute($examIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) $examMap[(int)$e['id']] = $e + ['_source' => 'monthly'];

    try {
        $stmt = $conn->prepare("SELECT id, title, status, exam_type, start_date, end_date, passing_score FROM semester_exams WHERE id IN ($in)");
        $stmt->execute($examIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) $examMap[(int)$e['id']] = $e + ['_source' => 'semester'];
    } catch (PDOException $e) {}
}

$completedContentIds = [];
if ($contentIds) {
    try {
        $in = implode(',', array_fill(0, count($contentIds), '?'));
        $stmt = $conn->prepare("
            SELECT content_id FROM user_content_progress
            WHERE user_id = ? AND content_id IN ($in) AND is_completed = 1
        ");
        $stmt->execute(array_merge([$userId], $contentIds));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) $completedContentIds[(int)$cid] = true;
    } catch (PDOException $e) {}
}

// ---------------------------------------------------------------------------
// BUILD ITEM LIST
// ---------------------------------------------------------------------------
$weekdayNames = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
$divStart = new DateTimeImmutable($curDiv['start_date'], new DateTimeZone('Africa/Dar_es_Salaam'));

$items = [];
foreach ($scheduleRows as $row) {
    $type = (string)$row['item_type'];
    $id   = (int)$row['item_id'];

    $title = '(missing)';
    $link  = null;
    $source = null;

    if ($type === 'content') {
        $c = $contentMap[$id] ?? null;
        if ($c) {
            $title = (string)$c['title'];
            $link  = 'course_subject.php?course_id=' . $courseId . '&subject_id=' . $subjectId . '&lesson=' . $id;
            $source = $c;
        }
    } elseif ($type === 'quiz') {
        $q = $quizMap[$id] ?? null;
        if ($q) {
            $title = (string)$q['title'];
            $link  = !empty($q['slug'])
                ? 'admin/quiz_take.php?slug=' . urlencode((string)$q['slug'])
                : 'admin/quiz_take.php?id=' . $id;
            $source = $q;
        }
    } elseif ($type === 'exam') {
        $e = $examMap[$id] ?? null;
        if ($e) {
            $title = (string)$e['title'];
            $link  = (($e['_source'] ?? '') === 'semester')
                ? 'take_semester_exam.php?exam_id=' . $id
                : 'take_monthly_exam.php?exam_id=' . $id;
            $source = $e;
        }
    }

    [$unlockAt, $lockAt] = subjectItemWindow($row, $source ?? [], $divStart, $type);

    $visible = ($now >= $unlockAt) && ($lockAt === null || $now <= $lockAt);
    $isDone  = ($type === 'content' && isset($completedContentIds[$id]));

    $itemState = null;
    $mark = null;

    if ($type === 'quiz') {
        $itemState = subjectQuizAttemptState(
            $conn, $userId, $currentEnrollmentId, $courseId,
            $id, $source ?? [], $now, $unlockAt, $lockAt
        );
        $visible = true;
        $mark = (int)($source['total_points'] ?? 0);
    } elseif ($type === 'exam') {
        $itemState = subjectExamAttemptState(
            $conn, $userId, $currentEnrollmentId, $courseId,
            $id, $source ?? [], $now, $unlockAt, $lockAt
        );
        $visible = true;

        if (($source['_source'] ?? '') === 'monthly') {
            try {
                $stmt = $conn->prepare("SELECT COALESCE(SUM(points), 0) FROM monthly_exam_questions WHERE exam_id = ?");
                $stmt->execute([$id]);
                $mark = (int)$stmt->fetchColumn();
            } catch (PDOException $e) {
                $mark = 0;
            }
        } else {
            $mark = 0;
        }
    }

    $items[] = [
        'schedule_id' => (int)$row['schedule_id'],
        'week' => (int)$row['week_number'],
        'weekday' => (int)$row['weekday'],
        'weekday_name' => $weekdayNames[(int)$row['weekday']] ?? '—',
        'time' => $row['start_time'] ? substr((string)$row['start_time'], 0, 5) : '',
        'type' => $type,
        'item_id' => $id,
        'title' => $title,
        'link' => $link,
        'visible' => $visible,
        'is_done' => $isDone,
        'unlock_at' => $unlockAt,
        'lock_at' => $lockAt,
        'item_state' => $itemState,
        'mark' => $mark,
    ];
}

$visibleItems = array_values(array_filter($items, fn($i) => $i['visible']));
$tocItems = array_values(array_filter($visibleItems, fn($i) => $i['title'] !== '(missing)'));

$visibleByWeek = [];
foreach ($visibleItems as $it) $visibleByWeek[$it['week']][] = $it;
ksort($visibleByWeek);

// ---------------------------------------------------------------------------
// LESSON READER
// ---------------------------------------------------------------------------
$readingLesson = null;
$readingParts = [];
$readingCurrentPart = null;
$readingPartSections = [];
$readingDisplayMode = 'sections';
$readingCombinedHtml = '';
$readingHasRTL = false;
$readingHasLTR = false;
$readingReadingTime = 1;

if ($lessonId > 0) {
    $allowedLessonIds = array_column(
        array_filter($visibleItems, fn($i) => $i['type'] === 'content'),
        'item_id'
    );
    if (!in_array($lessonId, $allowedLessonIds, true)) {
        courseFlash('error', 'That lesson is not currently available.');
        courseRedirect('course_subject.php?course_id=' . $courseId . '&subject_id=' . $subjectId);
    }

    try {
        $stmt = $conn->prepare("
            SELECT c.*, cat.name AS category_name
            FROM contents c
            LEFT JOIN categories cat ON cat.id = c.category_id
            WHERE c.id = ? AND c.status = 'published'
            LIMIT 1
        ");
        $stmt->execute([$lessonId]);
        $readingLesson = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('course_subject.php lesson: ' . $e->getMessage());
    }

    if (!$readingLesson) {
        courseFlash('error', 'That lesson is not available.');
        courseRedirect('course_subject.php?course_id=' . $courseId . '&subject_id=' . $subjectId);
    }

    try {
        $stmt = $conn->prepare("
            SELECT id, content_id, part_number, title,
                   arabic_body, arabic_body_html,
                   swahili_body, swahili_body_html,
                   section_order, audio_path, video_path, video_url, sort_order, display_mode
            FROM content_parts
            WHERE content_id = ?
            ORDER BY part_number ASC
        ");
        $stmt->execute([$lessonId]);
        $readingParts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($readingParts as &$partData) {
            foreach (['arabic_body','arabic_body_html','swahili_body','swahili_body_html'] as $f) {
                if (!empty($partData[$f])) {
                    $partData[$f] = html_entity_decode($partData[$f], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            if (!isset($partData['display_mode'])) {
                $partData['display_mode'] = 'sections';
            }
        }
        unset($partData);
    } catch (PDOException $e) {
        error_log('course_subject.php parts: ' . $e->getMessage());
    }

    if (!$readingParts) {
        $readingParts = [[
            'id' => 0,
            'part_number' => 1,
            'title' => $readingLesson['title'] ?? 'Lesson',
            'arabic_body' => $readingLesson['arabic_text'] ?? '',
            'arabic_body_html' => null,
            'swahili_body' => $readingLesson['body'] ?? '',
            'swahili_body_html' => $readingLesson['body_html'] ?? '',
            'section_order' => 'swahili_first',
            'audio_path' => $readingLesson['audio_path'] ?? null,
            'video_path' => $readingLesson['video_path'] ?? null,
            'video_url' => $readingLesson['video_url'] ?? null,
            'display_mode' => 'sections',
        ]];
    }

    $totalParts = count($readingParts);
    $currentPartNum = min(max(1, $partParam), $totalParts);
    $readingCurrentPart = $readingParts[$currentPartNum - 1];
    $readingDisplayMode = (string)($readingCurrentPart['display_mode'] ?? 'sections');
    if (!in_array($readingDisplayMode, ['sections', 'combined'], true)) {
        $readingDisplayMode = 'sections';
    }

    if ((int)($readingCurrentPart['id'] ?? 0) > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT id, part_id, section_number,
                       content, content_html,
                       language, direction, sort_order,
                       text_color, background_color
                FROM part_sections
                WHERE part_id = ?
                ORDER BY sort_order ASC, section_number ASC
            ");
            $stmt->execute([(int)$readingCurrentPart['id']]);
            $readingPartSections = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($readingPartSections as &$sec) {
                foreach (['content','content_html'] as $f) {
                    if (!empty($sec[$f])) {
                        $sec[$f] = html_entity_decode($sec[$f], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
            }
            unset($sec);
        } catch (PDOException $e) {
            error_log('course_subject.php sections: ' . $e->getMessage());
        }
    }

    if (!$readingPartSections) {
        $defaultContent = '';
        $dir = 'ltr';
        $lang = 'swahili';
        if (!empty($readingCurrentPart['arabic_body_html'])) {
            $defaultContent = $readingCurrentPart['arabic_body_html'];
            $dir = 'rtl'; $lang = 'arabic';
        } elseif (!empty($readingCurrentPart['swahili_body_html'])) {
            $defaultContent = $readingCurrentPart['swahili_body_html'];
        } elseif (!empty($readingCurrentPart['arabic_body'])) {
            $defaultContent = nl2br(htmlspecialchars((string)$readingCurrentPart['arabic_body'], ENT_QUOTES, 'UTF-8'));
            $dir = 'rtl'; $lang = 'arabic';
        } elseif (!empty($readingCurrentPart['swahili_body'])) {
            $defaultContent = nl2br(htmlspecialchars((string)$readingCurrentPart['swahili_body'], ENT_QUOTES, 'UTF-8'));
        }

        $readingPartSections = [[
            'id' => 0,
            'section_number' => 1,
            'content' => $defaultContent,
            'content_html' => $defaultContent,
            'language' => $lang,
            'direction' => $dir,
            'sort_order' => 1,
            'text_color' => null,
            'background_color' => null,
        ]];
    }

    $renderedSections = [];
    foreach ($readingPartSections as $section) {
        $direction  = $section['direction'] ?? 'ltr';
        $contentHtml = !empty($section['content_html'])
            ? $section['content_html']
            : nl2br(htmlspecialchars((string)($section['content'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $textColor = $section['text_color'] ?? null;
        $bgColor   = $section['background_color'] ?? null;

        if ($direction === 'rtl') $readingHasRTL = true; else $readingHasLTR = true;

        $renderedSections[] = [
            'direction' => $direction,
            'content' => $contentHtml,
            'language' => $section['language'] ?? 'swahili',
            'section_number' => (int)($section['section_number'] ?? 0),
            'raw_content' => $section['content'] ?? '',
            'text_color' => $textColor,
            'background_color' => $bgColor,
        ];
    }

    foreach ($renderedSections as $rsec) {
        $dir = $rsec['direction'];
        $sectionContent = $rsec['content'];
        $textColor = $rsec['text_color'] ?? null;
        $bgColor   = $rsec['background_color'] ?? null;

        $style = '';
        if ($textColor && $textColor !== '') $style .= 'color: ' . $textColor . ';';
        if ($bgColor && $bgColor !== '' && !isUntouchedDefaultColorPair($textColor, $bgColor)) {
            $style .= 'background-color: ' . $bgColor . ';';
        }
        $styleAttr = $style ? ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"' : '';
        $hasTextColor = hasCustomTextColor($textColor, $bgColor);
        $colorFlagClass = $hasTextColor ? ' has-custom-text-color' : ' no-custom-text-color';
        $darkModeFallbackClass = needsDarkModeTextFallback($textColor, $bgColor) ? ' dark-mode-safe-text' : '';

        if ($dir === 'rtl') {
            $readingCombinedHtml .= '<span class="mixed-rtl' . $colorFlagClass . $darkModeFallbackClass . '" dir="rtl" lang="ar"' . $styleAttr . '>' . $sectionContent . '</span> ';
        } else {
            $readingCombinedHtml .= '<span class="mixed-ltr' . $colorFlagClass . $darkModeFallbackClass . '" dir="ltr" lang="sw"' . $styleAttr . '>' . $sectionContent . '</span> ';
        }
    }

    $allText = '';
    foreach ($readingPartSections as $s) $allText .= ($s['content'] ?? '') . ' ';
    $wordCount = str_word_count(strip_tags($allText));
    $readingReadingTime = max(1, (int)ceil($wordCount / 200));

    if (empty($readingCurrentPart['video_path'])) $readingCurrentPart['video_path'] = $readingLesson['video_path'] ?? null;
    if (empty($readingCurrentPart['video_url']))  $readingCurrentPart['video_url']  = $readingLesson['video_url']  ?? null;
    if (empty($readingCurrentPart['audio_path'])) $readingCurrentPart['audio_path'] = $readingLesson['audio_path'] ?? null;
}

// ---------------------------------------------------------------------------
// LEFT COLUMN — weekly + monthly scores
// ---------------------------------------------------------------------------
$weeklyScores = [];
try {
    $stmt = $conn->prepare("
        SELECT q.id, q.title, q.total_points, q.competition_start_at, q.competition_end_at,
               cs.week_number, cs.weekday,
               qa.score, qa.total_points AS attempt_total,
               qa.percentage, qa.passed, qa.completed_at
        FROM course_schedule cs
        JOIN quizzes q ON q.id = cs.item_id
        LEFT JOIN quiz_attempts qa
               ON qa.quiz_id = q.id
              AND qa.user_id = :user_id
              AND qa.status = 'completed'
              AND (qa.course_id = :course_id OR qa.course_id IS NULL)
              AND (qa.enrollment_id = :enrollment_id OR qa.enrollment_id IS NULL)
              AND ((q.competition_start_at IS NULL)
                   OR (qa.completed_at BETWEEN q.competition_start_at AND q.competition_end_at))
        WHERE cs.division_id = :division_id
          AND cs.subject_id = :subject_id
          AND cs.item_type = 'quiz'
        ORDER BY cs.week_number ASC, cs.weekday ASC, cs.start_time ASC
    ");
    $stmt->execute([
        ':user_id'       => $userId,
        ':course_id'     => $courseId,
        ':enrollment_id' => $currentEnrollmentId,
        ':division_id'   => $curDiv['id'],
        ':subject_id'    => $subjectId,
    ]);
    $weeklyScores = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('course_subject.php weeklyScores: ' . $e->getMessage());
}

$monthlyScores = [];
try {
    $stmt = $conn->prepare("
        SELECT me.id, me.title, mea.score, mea.total_points AS attempt_total,
               mea.percentage, mea.passed, mea.completed_at, me.start_date, me.end_date,
               cs.week_number, cs.weekday
        FROM course_schedule cs
        JOIN monthly_exams me ON me.id = cs.item_id
        LEFT JOIN monthly_exam_attempts mea
               ON mea.exam_id = me.id
              AND mea.user_id = :user_id
              AND mea.status = 'completed'
              AND (mea.course_id = :course_id OR mea.course_id IS NULL)
              AND (mea.enrollment_id = :enrollment_id OR mea.enrollment_id IS NULL)
              AND ((me.start_date IS NULL)
                   OR (mea.completed_at BETWEEN me.start_date AND me.end_date))
        WHERE cs.division_id = :division_id
          AND cs.subject_id = :subject_id
          AND cs.item_type = 'exam'
        ORDER BY cs.week_number ASC, cs.weekday ASC, cs.start_time ASC
    ");
    $stmt->execute([
        ':user_id'       => $userId,
        ':course_id'     => $courseId,
        ':enrollment_id' => $currentEnrollmentId,
        ':division_id'   => $curDiv['id'],
        ':subject_id'    => $subjectId,
    ]);
    $monthlyScores = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('course_subject.php monthlyScores: ' . $e->getMessage());
}

$examMarks = [];
if ($monthlyScores) {
    $ids = array_map(fn($m) => (int)$m['id'], $monthlyScores);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $conn->prepare("
            SELECT exam_id, COALESCE(SUM(points), 0) AS total
            FROM monthly_exam_questions WHERE exam_id IN ($in) GROUP BY exam_id
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $examMarks[(int)$row['exam_id']] = (int)$row['total'];
    } catch (PDOException $e) {}
}

// ============================================================================
// EXAM RESULTS
// ============================================================================
$courseExamResults = [];
try {
    $enrId = $currentEnrollmentId;

    try {
        $stmt = $conn->prepare("
            SELECT 'weekly' AS source, qa.id AS attempt_id, qa.quiz_id AS exam_id,
                   'quiz' AS exam_type, q.title, qa.attempt_number,
                   qa.score, qa.total_points, qa.percentage, qa.passed, qa.completed_at
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            WHERE qa.user_id = ? AND qa.status = 'completed'
              AND q.course_id = ?
              AND q.subject_id = ?
              AND (qa.enrollment_id = ? OR qa.enrollment_id IS NULL)
        ");
        $stmt->execute([$userId, $courseId, $subjectId, $enrId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $courseExamResults[] = $row;
    } catch (PDOException $e) {
        error_log('course_subject.php exam results weekly: ' . $e->getMessage());
    }

    try {
        $stmt = $conn->prepare("
            SELECT 'monthly' AS source, mea.id AS attempt_id, mea.exam_id AS exam_id,
                   'monthly' AS exam_type, me.title, mea.attempt_number,
                   mea.score, mea.total_points, mea.percentage, mea.passed, mea.completed_at
            FROM monthly_exam_attempts mea
            JOIN monthly_exams me ON me.id = mea.exam_id
            WHERE mea.user_id = ? AND mea.status = 'completed'
              AND me.course_id = ?
              AND me.subject_id = ?
              AND (mea.enrollment_id = ? OR mea.enrollment_id IS NULL)
        ");
        $stmt->execute([$userId, $courseId, $subjectId, $enrId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $courseExamResults[] = $row;
    } catch (PDOException $e) {
        error_log('course_subject.php exam results monthly: ' . $e->getMessage());
    }

    try {
        $stmt = $conn->prepare("
            SELECT 'semester' AS source, sea.id AS attempt_id, sea.exam_id AS exam_id,
                   se.exam_type, se.title, sea.attempt_number,
                   sea.score, sea.total_points, sea.percentage, sea.passed, sea.completed_at
            FROM semester_exam_attempts sea
            JOIN semester_exams se ON se.id = sea.exam_id
            JOIN course_subjects cs ON cs.id = se.course_subject_id
            WHERE sea.user_id = ? AND sea.status = 'completed'
              AND cs.course_id = ?
              AND cs.subject_id = ?
              AND (sea.enrollment_id = ? OR sea.enrollment_id IS NULL)
        ");
        $stmt->execute([$userId, $courseId, $subjectId, $enrId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $courseExamResults[] = $row;
    } catch (PDOException $e) {
        error_log('course_subject.php exam results semester: ' . $e->getMessage());
    }

    usort($courseExamResults, static function ($a, $b) {
        return strtotime((string)($b['completed_at'] ?? '')) <=> strtotime((string)($a['completed_at'] ?? ''));
    });
} catch (Throwable $e) {
    error_log('course_subject.php exam results outer: ' . $e->getMessage());
}

// ============================================================================
// Top scorers per attempt — course + subject scoped
// ============================================================================
$topScorersByAttempt = [];
if ($courseExamResults) {
    $attemptKeys = [];
    foreach ($courseExamResults as $r) {
        $key = $r['source'] . ':' . (int)$r['exam_id'];
        if (!isset($attemptKeys[$key])) {
            $attemptKeys[$key] = ['source' => $r['source'], 'exam_id' => (int)$r['exam_id']];
        }
    }

    foreach ($attemptKeys as $k => $a) {
        try {
            if ($a['source'] === 'weekly') {
                $stmt = $conn->prepare("
                    SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                           MAX(qa.score) AS best_points,
                           MAX(qa.total_points) AS best_total
                    FROM quiz_attempts qa
                    JOIN users u ON u.id = qa.user_id
                    JOIN quizzes q ON q.id = qa.quiz_id
                    WHERE qa.quiz_id = ?
                      AND qa.status = 'completed'
                      AND u.is_active = 1
                      AND q.course_id = ?
                      AND q.subject_id = ?
                    GROUP BY u.id, u.username, u.full_name, u.profile_image
                    ORDER BY best_points DESC, best_total DESC, u.id ASC
                    LIMIT 5
                ");
                $stmt->execute([$a['exam_id'], $courseId, $subjectId]);
            } elseif ($a['source'] === 'monthly') {
                $stmt = $conn->prepare("
                    SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                           MAX(mea.score) AS best_points,
                           MAX(mea.total_points) AS best_total
                    FROM monthly_exam_attempts mea
                    JOIN users u ON u.id = mea.user_id
                    JOIN monthly_exams me ON me.id = mea.exam_id
                    WHERE mea.exam_id = ?
                      AND mea.status = 'completed'
                      AND u.is_active = 1
                      AND me.course_id = ?
                      AND me.subject_id = ?
                    GROUP BY u.id, u.username, u.full_name, u.profile_image
                    ORDER BY best_points DESC, best_total DESC, u.id ASC
                    LIMIT 5
                ");
                $stmt->execute([$a['exam_id'], $courseId, $subjectId]);
            } else {
                $stmt = $conn->prepare("
                    SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                           MAX(sea.score) AS best_points,
                           MAX(sea.total_points) AS best_total
                    FROM semester_exam_attempts sea
                    JOIN users u ON u.id = sea.user_id
                    JOIN semester_exams se ON se.id = sea.exam_id
                    JOIN course_subjects cs ON cs.id = se.course_subject_id
                    WHERE sea.exam_id = ?
                      AND sea.status = 'completed'
                      AND u.is_active = 1
                      AND cs.course_id = ?
                      AND cs.subject_id = ?
                    GROUP BY u.id, u.username, u.full_name, u.profile_image
                    ORDER BY best_points DESC, best_total DESC, u.id ASC
                    LIMIT 5
                ");
                $stmt->execute([$a['exam_id'], $courseId, $subjectId]);
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rank = 1;
            $topScorersByAttempt[$k] = [];
            foreach ($rows as $row) {
                $name = trim((string)($row['username'] ?: ($row['full_name'] ?: 'User')));
                $topScorersByAttempt[$k][] = [
                    'rank'         => $rank++,
                    'display_name' => ((int)$row['user_id'] === $userId) ? 'You' : $name,
                    'initials'     => strtoupper(mb_substr($name, 0, 2)),
                    'is_me'        => ((int)$row['user_id'] === $userId),
                    'points'       => (int)$row['best_points'],
                    'budget'       => (int)$row['best_total'],
                ];
            }
        } catch (PDOException $e) {
            error_log('course_subject.php top scorers ' . $k . ': ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
// MARKAZ IMAGE
// ---------------------------------------------------------------------------
$sheikhBgPath = '';
$sheikhBgOk   = false;
$docRoot      = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$uploadsRoot  = $docRoot !== '' ? $docRoot . '/uploads' : '';

if (!empty($subject['markaz_image_path'])) {
    $candidate = trim((string)$subject['markaz_image_path']);
    if ($candidate !== '' && $docRoot !== '') {
        $abs = $docRoot . '/' . ltrim($candidate, '/');
        if (is_file($abs)) {
            $sheikhBgPath = '/' . ltrim($candidate, '/');
            $sheikhBgOk   = true;
        }
    }
}

if (!$sheikhBgOk) {
    $slugifyBg = static fn(string $v): string => trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($v)), '-');
    $markazCandidates = [];
    foreach ([
        (string)($subject['subject_slug'] ?? ''),
        (string)($subject['markaz'] ?? ''),
        $subjectSlug,
    ] as $cand) {
        $cand = $slugifyBg($cand);
        if ($cand !== '' && !in_array($cand, $markazCandidates, true)) $markazCandidates[] = $cand;
    }
    foreach ($markazCandidates as $base) {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $try = '/uploads/markaz/' . $base . '.' . $ext;
            if ($docRoot !== '' && is_file($docRoot . $try)) {
                $sheikhBgPath = $try;
                $sheikhBgOk   = true;
                break 2;
            }
        }
    }
}

$heroBg = '';
if (!empty($subject['image_path'])) {
    $candidate = trim((string)$subject['image_path']);
    if ($candidate !== '' && $docRoot !== '') {
        $abs = $docRoot . '/' . ltrim($candidate, '/');
        if (is_file($abs)) $heroBg = '/' . ltrim($candidate, '/');
    }
}
if ($heroBg === '' && $sheikhBgOk) $heroBg = $sheikhBgPath;

$sheikhAvatar = !empty($subject['image_path']) ? (string)$subject['image_path'] : null;

$passMark    = (int)($subject['passing_score'] ?? 70);
$isProjected = ($finalGrade === null && $projected !== null);

courseShellStart($ctx, ($subject['name'] ?? 'Subject') . ' — ' . ($en['course_title'] ?? 'Course'));
?>

<style>
    /* ============ LAYOUT ============ */
    .zs-layout {
        display: grid;
        grid-template-columns: 300px minmax(0, 1fr) 300px;
        gap: 12px;
        align-items: start;
    }
    .zs-left, .zs-right, .zs-main { min-width: 0; }

    @media (max-width: 1250px) {
        .zs-layout { grid-template-columns: 280px minmax(0, 1fr); }
        .zs-right  { grid-column: 1 / -1; }
    }
    @media (max-width: 900px) {
        .zs-layout { grid-template-columns: minmax(0, 1fr); }
        .zs-left, .zs-right { grid-column: auto; }
        .zs-main { order: -1; }
    }

    /* ============ HERO ============ */
    .zs-hero {
        background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
        color: #fff;
        border-radius: 5px;
        padding: clamp(14px, 3vw, 20px);
        position: relative;
        overflow: hidden;
        margin-bottom: 10px;
    }
    .zs-hero::before {
        content: "";
        position: absolute; inset: 0;
        background: url('/uploads/subjects-page-hero.png') center/cover;
        opacity: .22;
    }
    .zs-hero > * { position: relative; z-index: 1; }
    .zs-hero .back {
        display: block; width: fit-content; margin-bottom: 4px;
        color: rgba(255,255,255,.85); font-size: 12.5px;
        text-decoration: none; font-weight: 700;
    }
    .zs-hero .back:hover { color: #fff; }
    .zs-hero h1 {
        font-size: clamp(20px, 4vw, 26px); font-weight: 800;
        margin: 0 0 3px; text-shadow: 1px 2px 4px rgba(0,0,0,.35);
    }
    .zs-hero .meta {
        font-size: 12.5px; opacity: .94;
        text-shadow: 1px 1px 3px rgba(0,0,0,.3);
    }
    .zs-hero.has-subject-bg {
        background-size: cover, cover;
        background-position: center, center;
        background-repeat: no-repeat, no-repeat;
    }
    .zs-hero.has-subject-bg::before { display: none; }
    .zs-hero .zs-hero-title {
        direction: rtl;
        unicode-bidi: isolate;
        text-align: right;
        display: block;
        width: 100%;
        margin: 0 0 4px;
        color: #D9BC72 !important;
        text-shadow: 0 2px 8px rgba(0,0,0,.7), 0 1px 2px rgba(0,0,0,.5);
    }

    /* ============ PANELS ============ */
    .zs-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 5px; margin-bottom: 10px; overflow: hidden; }
    .zs-panel-head { padding: 8px 12px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; color: var(--gold); border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .zs-panel-head small { font-size: 10.5px; font-weight: 600; color: var(--muted); text-transform: none; letter-spacing: 0; }
    .zs-panel-head[data-subject-scorers-toggle] { cursor: pointer; user-select: none; }
    .zs-panel-head[data-subject-scorers-toggle]:hover { background: var(--surface-2); }
    .zs-panel-body { padding: 10px 12px; }

    /* ============ SCORE LISTS ============ */
    .zs-score-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px solid var(--line); font-size: 13px; }
    .zs-score-row:last-child { border-bottom: none; }
    .zs-score-row .t { color: var(--text); font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
    .zs-score-row .v { font-weight: 800; white-space: nowrap; }
    .zs-score-row .v.pass { color: var(--brand); }
    .zs-score-row .v.fail { color: var(--danger); }
    .zs-score-row .v.pending { color: var(--muted); }
    .zs-score-empty { font-size: 12.5px; color: var(--muted); text-align: center; padding: 8px 0; }

    /* ============ OVERALL ============ */
    .zs-overall { text-align: center; padding: 4px 0 2px; }
    .zs-overall .pts-line { font-size: 15px; font-weight: 700; color: var(--text); }
    .zs-overall .pts-line strong { color: var(--brand); font-weight: 800; font-size: 18px; }
    .zs-overall .pts-raw { margin-top: 2px; font-size: 11px; color: var(--muted); font-weight: 600; }
    .zs-overall .pts-budget { margin-top: 2px; font-size: 10.5px; color: var(--muted); font-weight: 600; }
    .zs-overall .progress { margin: 8px 0 4px; height: 8px; border-radius: 5px; background: var(--surface-3); overflow: hidden; }
    .zs-overall .progress > div { height: 100%; background: linear-gradient(90deg, var(--brand-light), var(--brand)); border-radius: 5px; }
    .zs-overall .status { margin-top: 8px; font-size: 12px; font-weight: 700; }
    .zs-overall .status.pass { color: var(--brand); }
    .zs-overall .status.fail { color: var(--danger); }
    .zs-overall .status.pending { color: var(--muted); }
    .zs-overall .proj-note { margin-top: 4px; font-size: 10.5px; color: var(--muted); font-style: italic; }

    /* ============ CERT ============ */
    .zs-cert { background: var(--surface); border: 1px solid var(--line); border-radius: 5px; overflow: hidden; margin-bottom: 10px; }
    .zs-cert-preview { position: relative; height: 84px; border-radius: 5px; margin: 6px 6px 0; overflow: hidden; border: 3px double var(--gold); background: linear-gradient(135deg, rgba(255,247,219,.88), rgba(255,255,255,.96) 38%, rgba(231,243,235,.86)), #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 6px; text-align: center; }
    .zs-cert-preview::before { content: "MyIslam"; position: absolute; inset: auto auto 6px 50%; transform: translateX(-50%) rotate(-18deg); font-size: 24px; font-weight: 900; color: rgba(22,101,52,.08); white-space: nowrap; pointer-events: none; }
    .zs-cert-preview > * { position: relative; z-index: 1; }
    .zs-cert-preview .medal { width: 26px; height: 26px; border-radius: 5px; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; border: 2px solid var(--gold); margin-bottom: 4px; }
    .zs-cert-preview .lbl { font-size: 7px; font-weight: 800; color: var(--brand); letter-spacing: .14em; text-transform: uppercase; }
    .zs-cert-preview .ttl { font-size: 10.5px; font-weight: 800; color: var(--brand-dark); line-height: 1.05; margin-top: 2px; }
    .zs-cert-meta { padding: 8px 10px 10px; }
    .zs-cert-meta h4 { font-size: 12.5px; font-weight: 800; margin: 0 0 3px; }
    .zs-cert-meta p { font-size: 10.5px; color: var(--muted); line-height: 1.4; margin: 0 0 6px; }
    .zs-cert-meta .btn { display: block; width: 100%; text-align: center; padding: 7px 10px; border-radius: 5px; font-size: 11.5px; font-weight: 700; text-decoration: none; border: 1px solid var(--brand); background: var(--brand); color: #fff; }

    /* ============ WEEK LIST ============ */
    .zs-week-group { margin-bottom: 12px; }
    .zs-week-head { display: flex; align-items: center; justify-content: space-between; font-size: 12px; font-weight: 800; color: var(--gold); text-transform: uppercase; letter-spacing: 1px; padding: 3px 2px; border-bottom: 2px solid var(--gold-soft); margin-bottom: 5px; }
    .zs-week-head .count { font-size: 10.5px; background: var(--surface-3); color: var(--muted); padding: 2px 8px; border-radius: 5px; letter-spacing: 0; }

    .zs-item { display: grid; grid-template-columns: 44px 1fr auto; align-items: center; gap: 12px; padding: 9px 12px; margin-bottom: 6px; background: var(--surface); border: 1px solid var(--line); border-radius: 5px; text-decoration: none; color: inherit; transition: transform .15s, box-shadow .15s, border-color .15s; }
    .zs-item:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.06); border-color: var(--brand); }
    .zs-item.current { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-soft); }
    .zs-item.locked { opacity: .7; cursor: not-allowed; }
    .zs-item.locked:hover { transform: none; box-shadow: none; border-color: var(--line); }
    .zs-item-icon { width: 40px; height: 40px; flex-shrink: 0; border-radius: 5px; display: flex; align-items: center; justify-content: center; font-size: 18px; background: var(--brand-soft); }
    .zs-item.type-quiz .zs-item-icon { background: var(--gold-soft); }
    .zs-item.type-exam .zs-item-icon { background: var(--accent-soft); }

    .zs-item-body { min-width: 0; }
    .zs-item-title { font-size: 14px; font-weight: 700; color: var(--text); line-height: 1.35; overflow-wrap: anywhere; }
    .zs-item-meta { font-size: 11.5px; color: var(--muted); margin-top: 2px; display: flex; flex-wrap: wrap; gap: 8px; font-weight: 600; }
    .zs-item-action { font-size: 12px; font-weight: 800; color: var(--brand); white-space: nowrap; text-align: right; }
    .zs-item:hover .zs-item-action { color: var(--gold); }
    .zs-item .done { color: var(--brand); font-weight: 800; font-size: 11px; background: var(--brand-soft); padding: 3px 8px; border-radius: 5px; }
    .zs-item-action small { color: var(--muted); font-weight: 600; }

    /* ============ BADGES ============ */
    .zs-quiz-badge { display: inline-block; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; padding: 2px 7px; border-radius: 5px; margin-left: 6px; vertical-align: middle; }
    .zs-quiz-badge.upcoming { background: var(--gold-soft); color: var(--gold); border: 1px solid var(--gold); }
    .zs-quiz-badge.live { background: var(--brand-soft); color: var(--brand); border: 1px solid var(--brand); }
    .zs-quiz-badge.attempted.pass { background: var(--brand-soft); color: var(--brand); }
    .zs-quiz-badge.attempted.fail { background: rgba(185,28,28,.10); color: var(--danger); }
    .zs-quiz-badge.ended { background: var(--surface-3); color: var(--muted); }

    .score-chip { display: inline-block; padding: 3px 8px; border-radius: 5px; font-weight: 800; font-size: 11px; }
    .score-chip.pass { background: var(--brand-soft); color: var(--brand); }
    .score-chip.fail { background: rgba(185,28,28,.10); color: var(--danger); }

    /* ============ READER ============ */
    .zs-reader-head { padding: 12px 14px; background: var(--surface-2); border-bottom: 1px solid var(--line); border-radius: 5px 5px 0 0; }
    .zs-reader-head .back-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 12.5px; }
    .zs-reader-head .back-row a { color: var(--brand); text-decoration: none; font-weight: 700; }
    .zs-reader-head .back-row a:hover { color: var(--gold); }
    .zs-reader-head h1 { font-size: clamp(20px, 3.5vw, 26px); font-weight: 800; margin: 0 0 4px; }
    .zs-reader-head .meta { font-size: 12px; color: var(--muted); display: flex; flex-wrap: wrap; gap: 12px; }
    .zs-reader-body { padding: 14px; }
    .zs-media { margin-bottom: 14px; }
    .zs-media video, .zs-media iframe { width: 100%; border-radius: 5px; border: 1px solid var(--line); display: block; }
    .zs-media iframe { aspect-ratio: 16 / 9; }
    .zs-media audio { width: 100%; }
    .zs-media-download { margin-top: 8px; text-align: center; }
    .zs-media-download .btn { padding: 6px 12px; font-size: 12px; border-radius: 5px; }

    .zs-sections-container { display: flex; flex-direction: column; gap: 10px; }
    .zs-section-display {
        padding: 12px 14px;
        border-radius: 5px;
        background: var(--surface-2);
        border: 1px solid var(--line);
        overflow-wrap: anywhere;
    }
    .zs-section-display[dir="rtl"] {
        text-align: right;
        font-family: 'Amiri', 'Traditional Arabic', serif;
        font-size: 1.15em;
        line-height: 1.9;
    }
    .zs-section-body { font-size: 14px; line-height: 1.7; }

    .zs-combined-content {
        padding: 12px 14px;
        border-radius: 5px;
        background: var(--surface-2);
        border: 1px solid var(--line);
        font-size: 14px;
        line-height: 1.75;
        overflow-wrap: anywhere;
    }
    .zs-combined-content .mixed-rtl {
        font-family: 'Amiri', 'Traditional Arabic', serif;
        font-size: 1.15em;
        line-height: 1.9;
    }
    body.dark-mode .zs-combined-content .no-custom-text-color,
    body.dark-mode .zs-section-display.no-custom-text-color {
        color: var(--text) !important;
    }
    body.dark-mode .zs-combined-content .dark-mode-safe-text,
    body.dark-mode .zs-section-display.dark-mode-safe-text {
        color: var(--text) !important;
    }

    .zs-part-nav { display: flex; justify-content: space-between; gap: 10px; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--line); }
    .zs-reader-note { margin-top: 10px; padding: 8px 12px; border-radius: 5px; background: var(--gold-soft); border: 1px solid var(--gold); font-size: 12px; color: var(--gold); font-weight: 600; text-align: center; }

    /* ============ RIGHT: SHEIKH CARD ============ */
    .zs-sheikh { position: relative; border-radius: 5px; overflow: hidden; min-height: 140px; display: flex; flex-direction: column; justify-content: flex-end; background: linear-gradient(180deg, rgba(0,0,0,.25) 0%, rgba(0,0,0,.85) 100%), linear-gradient(135deg, var(--brand-dark) 0%, #051810 100%); color: #fff; margin-bottom: 10px; }
    .zs-sheikh.has-bg { background-image: linear-gradient(180deg, rgba(0,0,0,.25) 0%, rgba(0,0,0,.88) 100%), url('<?php echo e($sheikhBgPath); ?>'); background-size: cover, cover; background-position: center, center; background-repeat: no-repeat, no-repeat; }
    .zs-sheikh-inner { position: relative; z-index: 1; padding: 12px 14px; }
    .zs-sheikh-tag { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #D9BC72; margin-bottom: 4px; }
    .zs-sheikh h3 { font-size: 15px; font-weight: 800; margin: 0 0 3px; color: #fff; line-height: 1.3; }
    .zs-sheikh .markaz { font-size: 11.5px; opacity: .9; font-weight: 600; }

    /* ============ RIGHT: TOC ============ */
    .zs-toc-list { display: grid; gap: 2px; }
    .zs-toc-item { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 5px; font-size: 12.5px; text-decoration: none; color: var(--text); font-weight: 600; line-height: 1.35; transition: background .15s; }
    .zs-toc-item:hover { background: var(--surface-2); color: var(--brand); }
    .zs-toc-item.current { background: var(--brand-soft); color: var(--brand); font-weight: 800; }
    .zs-toc-item .zs-toc-icon { width: 22px; height: 22px; flex-shrink: 0; border-radius: 5px; display: flex; align-items: center; justify-content: center; font-size: 11px; background: var(--brand-soft); }
    .zs-toc-item.type-quiz .zs-toc-icon { background: var(--gold-soft); }
    .zs-toc-item.type-exam .zs-toc-icon { background: var(--accent-soft); }
    .zs-toc-item .zs-toc-body { min-width: 0; }
    .zs-toc-item .zs-toc-title { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .zs-toc-item .zs-toc-meta { display: block; font-size: 10px; color: var(--muted); font-weight: 600; }
    .zs-toc-empty { padding: 16px 12px; text-align: center; font-size: 12px; color: var(--muted); }

    /* ============ EXAM RESULTS CARD ============ */
    .er-subject {
        border: 1px solid var(--line);
        border-radius: 5px;
        overflow: hidden;
        margin-bottom: 6px;
        background: var(--surface);
    }
    .er-subject:last-child { margin-bottom: 0; }
    .er-subject-head {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 9px 12px;
        background: var(--surface-2);
        border-bottom: 1px solid var(--line);
        flex-wrap: wrap;
        cursor: pointer;
        user-select: none;
        transition: background .12s;
    }
    .er-subject-head:hover { background: var(--surface-3); }
    .er-subject .er-list { display: none; }
    .er-subject.open .er-list { display: grid; }
    .er-subject.open .er-subject-head { border-bottom-color: var(--line); }
    .er-subject:not(.open) .er-subject-head { border-bottom-color: transparent; }

    .er-caret {
        margin-left: auto;
        font-size: 11px;
        color: var(--muted);
        transition: transform .15s;
        flex-shrink: 0;
    }
    .er-subject.open .er-caret { transform: rotate(180deg); }

    .er-subject-head .er-ico { font-size: 16px; flex-shrink: 0; }
    .er-subject-head .er-title {
        font-weight: 800;
        font-size: 13px;
        color: var(--text);
        direction: rtl;
        text-align: right;
        unicode-bidi: isolate;
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .er-subject-head .er-summary {
        font-size: 10.5px;
        color: var(--muted);
        font-weight: 700;
        white-space: nowrap;
    }

    .er-list { display: grid; }
    .er-row {
        display: grid;
        grid-template-columns: 32px minmax(0, 1fr) auto;
        gap: 10px;
        align-items: center;
        padding: 9px 12px;
        border-bottom: 1px solid var(--line);
        border-left: 3px solid var(--brand);
        background: var(--surface);
        cursor: pointer;
        transition: background .12s;
        text-decoration: none;
        color: inherit;
    }
    .er-row:last-child { border-bottom: none; }
    .er-row:hover { background: var(--surface-2); }
    .er-row.is-weekly  { border-left-color: var(--gold); }
    .er-row.is-monthly { border-left-color: #3E6E91; }
    .er-row.is-semester{ border-left-color: var(--brand); }
    .er-row.is-supp    { border-left-color: var(--gold); }
    .er-row.is-failed  { border-left-color: var(--danger); }
    .er-row.expanded   { background: var(--brand-soft); }

    .er-icon {
        width: 30px; height: 30px;
        border-radius: 5px;
        display: flex; align-items: center; justify-content: center;
        font-size: 14px;
        background: rgba(14,74,52,.08);
        color: var(--brand);
    }
    .er-row.is-weekly  .er-icon { background: rgba(156,122,46,.12); color: var(--gold); }
    .er-row.is-monthly .er-icon { background: rgba(62,110,145,.10); color: #3E6E91; }
    .er-row.is-supp    .er-icon { background: rgba(156,122,46,.12); color: var(--gold); }
    .er-row.is-failed  .er-icon { background: rgba(185,28,28,.10); color: var(--danger); }

    .er-body { min-width: 0; direction: rtl; text-align: right; }
    .er-title-row {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--text);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        margin-bottom: 2px;
        direction: rtl;
        unicode-bidi: isolate;
    }
    .er-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 2px 10px;
        font-size: 10px;
        color: var(--muted);
        font-weight: 600;
        direction: rtl;
        justify-content: flex-end;
    }
    .er-meta span {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 180px;
    }

    .er-right {
        text-align: right;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 2px;
        white-space: nowrap;
    }
    .er-score {
        font-size: 13px;
        font-weight: 800;
        color: var(--brand);
        line-height: 1.1;
    }
    .er-score.fail { color: var(--danger); }
    .er-score small { font-size: 9.5px; color: var(--muted); font-weight: 700; margin-left: 1px; }
    .er-pct { font-size: 10px; font-weight: 700; color: var(--muted); }
    .er-pill {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 999px;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
    }
    .er-pill.pass { background: rgba(14,74,52,.10); color: var(--brand); }
    .er-pill.fail { background: rgba(185,28,28,.10); color: var(--danger); }

    .er-detail {
        padding: 10px 12px;
        background: var(--surface-2);
        border-top: 1px dashed var(--line);
        font-size: 11.5px;
        color: var(--text-secondary);
        display: none;
    }
    .er-detail.open { display: block; }
    .er-detail-loading { padding: 14px 4px; text-align: center; color: var(--muted); }

    .er-detail .er-q {
        padding: 8px 10px;
        background: var(--surface);
        border: 1px solid var(--line);
        border-left: 3px solid var(--brand);
        border-radius: 5px;
        margin-bottom: 5px;
    }
    .er-detail .er-q.wrong { border-left-color: var(--danger); }
    .er-q-head {
        display: flex;
        gap: 5px;
        align-items: flex-start;
        font-size: 11.5px;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 4px;
        overflow-wrap: anywhere;
    }
    .er-q-head .num {
        background: var(--surface-2);
        border-radius: 999px;
        padding: 1px 6px;
        font-size: 9.5px;
        color: var(--muted);
        font-weight: 800;
        flex-shrink: 0;
    }
    .er-q-line {
        display: flex;
        gap: 6px;
        font-size: 10.5px;
        margin-bottom: 2px;
        overflow-wrap: anywhere;
    }
    .er-q-line .k { color: var(--muted); font-weight: 700; flex-shrink: 0; min-width: 58px; }
    .er-q-line .val { color: var(--text); font-weight: 600; }
    .er-q-line .val.good { color: var(--brand); }
    .er-q-line .val.bad  { color: var(--danger); }

    /* RTL ONLY on questions / answers */
    .er-detail .er-q,
    .er-detail .er-q-head,
    .er-detail .er-q-line {
        direction: rtl;
        text-align: right;
        unicode-bidi: isolate;
    }
    .er-detail .er-q-line .k,
    .er-detail .er-q-line .val {
        text-align: right;
    }

    .er-detail-wrap {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
    }

    .er-parts {
        display: grid;
        gap: 3px;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed var(--line);
    }
    .er-parts-title {
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: var(--muted);
        margin-bottom: 4px;
    }
    .er-part {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 5px 8px;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 5px;
        font-size: 11px;
    }
    .er-part.is-me { border-color: var(--brand); background: var(--brand-soft); }
    .er-part-rank {
        width: 18px; height: 18px; border-radius: 50%;
        background: var(--surface-2); color: var(--muted);
        display: flex; align-items: center; justify-content: center;
        font-size: 9px; font-weight: 800; flex-shrink: 0;
    }
    .er-part:nth-child(2) .er-part-rank { background: #C6A64B; color: #fff; }
    .er-part:nth-child(3) .er-part-rank { background: #8FD3E8; color: #0f1720; }
    .er-part:nth-child(4) .er-part-rank { background: #6C5CC0; color: #fff; }
    .er-part-name { flex: 1; min-width: 0; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .er-part-score { font-weight: 800; color: var(--gold); font-variant-numeric: tabular-nums; }

    .er-empty {
        padding: 22px 14px;
        text-align: center;
        color: var(--muted);
        font-size: 12.5px;
        background: var(--surface);
        border: 1px dashed var(--line);
        border-radius: 5px;
        line-height: 1.55;
    }
    .er-empty .em { font-size: 28px; display: block; margin-bottom: 6px; }

    /* ============ MOBILE ORDER ============ */
    @media (max-width: 900px) {
        .zs-layout { display: flex !important; flex-direction: column; gap: 8px; }
        .zs-main, .zs-left, .zs-right { display: contents !important; }
        .zs-main > *                { order: 3; }
        .zs-main > .zs-hero         { order: 1; }
        .zs-right > .zs-sheikh      { order: 2; }
        .zs-right > .zs-panel       { order: 4; }
        .zs-left > .zs-panel        { order: 5; }
        .zs-left > .zs-cert         { order: 99; }
        .zs-layout > *, .zs-main > *, .zs-left > *, .zs-right > * { width: 100%; min-width: 0; }
        .zs-hero, .zs-panel, .zs-sheikh, .zs-cert { margin-bottom: 0; }
    }

    @media (max-width: 640px) {
        .zs-hero { padding: 12px; border-radius: 8px; }
        .zs-hero h1 { font-size: 19px; }
        .zs-hero .meta { font-size: 11.5px; }
        .zs-panel-head { padding: 7px 10px; font-size: 11px; }
        .zs-panel-body { padding: 8px 10px; }
        .zs-sheikh { min-height: 110px; }
        .zs-sheikh-inner { padding: 10px 12px; }
        .zs-sheikh h3 { font-size: 14px; }
        .zs-cert-preview { height: 70px; }
        .zs-item { padding: 8px 10px; margin-bottom: 5px; }
        .zs-week-group { margin-bottom: 8px; }
        .zs-reader-head { padding: 10px 12px; }
        .zs-reader-body { padding: 10px; }
        .zs-section-display, .zs-combined-content { padding: 10px 12px; }
    }

    @media (max-width: 900px) {
        .zs-layout { display: flex !important; flex-direction: column; gap: 8px; }
        .zs-main, .zs-left, .zs-right { display: contents !important; }

        .zs-main > .zs-hero                          { order: 1; width: 100%; }
        .zs-right > .zs-sheikh                       { order: 2; width: 100%; }

        .zs-main > *:not(.zs-hero):not(.er-section):not(.scorers-section) { order: 3; width: 100%; }
        .zs-main > .er-section                       { order: 7; width: 100%; }
        .zs-main > .scorers-section                  { order: 8; width: 100%; }

        .zs-left > .zs-panel:nth-of-type(1)          { order: 4; width: 100%; } /* Weekly quizzes */
        .zs-left > .zs-panel:nth-of-type(2)          { order: 5; width: 100%; } /* Monthly exams  */
        .zs-left > .zs-panel:nth-of-type(3)          { order: 9; width: 100%; } /* Overall        */

        .zs-right > .zs-panel                        { order: 6; width: 100%; } /* TOC            */
        .zs-left > .zs-cert                          { order: 10; width: 100%; } /* Certificates   */

        .zs-layout > *, .zs-main > *, .zs-left > *, .zs-right > * { width: 100%; min-width: 0; }
        .zs-hero, .zs-panel, .zs-sheikh, .zs-cert { margin-bottom: 0; }
    }

    /* ============================================================
       MOBILE FIT
       ============================================================ */
    html, body { max-width: 100%; overflow-x: hidden; }
    .zs-layout, .zs-layout *,
    .zs-panel, .zs-panel *,
    .zs-main, .zs-main *,
    .zs-left, .zs-left *,
    .zs-right, .zs-right * { box-sizing: border-box; min-width: 0; }

    .zs-hero h1,
    .zs-hero .meta,
    .zs-item-title,
    .zs-item-meta,
    .zs-toc-title,
    .zs-toc-meta,
    .zs-score-row .t,
    .er-title-row,
    .er-meta span,
    .zs-reader-head h1,
    .zs-section-body,
    .zs-combined-content { overflow-wrap: anywhere; word-break: break-word; }

    .zs-media video, .zs-media iframe, .zs-media audio,
    .zs-hero, .zs-sheikh, .zs-cert-preview { max-width: 100%; }

    .zs-layout { width: 100%; }

    @media (max-width: 900px) {
        .zs-layout { display: flex !important; flex-direction: column; gap: 10px; padding: 0; width: 100%; }
        .zs-main, .zs-left, .zs-right { display: contents !important; }

        .zs-main > .zs-hero                          { order: 1; width: 100%; }
        .zs-right > .zs-sheikh                       { order: 2; width: 100%; }
        .zs-main > *:not(.zs-hero):not(.er-section):not(.scorers-section) { order: 3; width: 100%; }
        .zs-left > .zs-panel:nth-of-type(1)          { order: 4; width: 100%; }
        .zs-left > .zs-panel:nth-of-type(2)          { order: 5; width: 100%; }
        .zs-right > .zs-panel                        { order: 6; width: 100%; }
        .zs-main > .er-section                       { order: 7; width: 100%; }
        .zs-main > .scorers-section                  { order: 8; width: 100%; }
        .zs-left > .zs-panel:nth-of-type(3)          { order: 9; width: 100%; }
        .zs-left > .zs-cert                          { order: 10; width: 100%; }

        .zs-hero, .zs-panel, .zs-sheikh, .zs-cert { margin-bottom: 0; }

        .zs-item      { padding: 12px 12px; gap: 10px; min-height: 64px; }
        .zs-item-icon { width: 44px; height: 44px; font-size: 20px; }
        .zs-item-title { font-size: 15px; line-height: 1.35; }
        .zs-item-meta  { font-size: 12px; gap: 6px 10px; }
        .zs-item-action { font-size: 13px; }

        .zs-toc-item { padding: 10px; font-size: 13.5px; min-height: 44px; }
        .zs-toc-item .zs-toc-icon { width: 26px; height: 26px; font-size: 12px; }

        .zs-score-row { padding: 9px 0; font-size: 13.5px; }

        .er-row { padding: 11px 12px; min-height: 56px; }
        .er-icon { width: 32px; height: 32px; font-size: 15px; }
        .er-title-row { font-size: 13px; }
        .er-meta { font-size: 11px; }
        .er-score { font-size: 14px; }
        .er-pct   { font-size: 11px; }

        .zs-panel .btn,
        .zs-cert-meta .btn,
        .zs-media-download .btn,
        .zs-part-nav .btn {
            min-height: 44px;
            padding: 10px 14px;
            font-size: 13px;
            border-radius: 6px;
        }
    }

    @media (max-width: 640px) {
        .zs-layout { gap: 8px; }
        .zs-hero { padding: 14px 12px; border-radius: 10px; margin-bottom: 0; }
        .zs-hero h1 { font-size: clamp(18px, 5.6vw, 22px); line-height: 1.25; }
        .zs-hero .meta { font-size: 12px; line-height: 1.4; display: flex; flex-wrap: wrap; gap: 4px 10px; }
        .zs-hero .back { font-size: 12px; }

        .zs-panel { border-radius: 8px; }
        .zs-panel-head { padding: 10px 12px; font-size: 12px; }
        .zs-panel-head small { font-size: 10.5px; }
        .zs-panel-body { padding: 10px 12px; }

        .zs-sheikh { border-radius: 8px; min-height: 130px; }
        .zs-sheikh-inner { padding: 12px 14px; }
        .zs-sheikh h3 { font-size: 15px; }
        .zs-sheikh .markaz { font-size: 12px; }

        .zs-week-head { font-size: 12px; padding: 4px 2px; }
        .zs-item { grid-template-columns: 40px minmax(0, 1fr); padding: 10px 12px; gap: 10px; }
        .zs-item-icon { width: 40px; height: 40px; font-size: 18px; grid-row: span 2; }
        .zs-item-title { font-size: 14px; }
        .zs-item-action { grid-column: 2; text-align: left; font-size: 12.5px; margin-top: 2px; }
        .zs-item-meta { font-size: 11.5px; }

        .zs-score-row { font-size: 13px; gap: 8px; }
        .zs-score-row .v { font-size: 12.5px; }

        .zs-overall .pts-line { font-size: 14px; }
        .zs-overall .pts-line strong { font-size: 18px; }
        .zs-overall .pts-raw  { font-size: 11px; }
        .zs-overall .pts-budget { font-size: 10.5px; }
        .zs-overall .status { font-size: 12.5px; }

        .zs-cert-preview { height: 84px; }
        .zs-cert-preview .ttl { font-size: 10.5px; }
        .zs-cert-meta { padding: 10px 12px; }
        .zs-cert-meta h4 { font-size: 13px; }
        .zs-cert-meta p { font-size: 11.5px; }

        .zs-toc-item { padding: 10px; font-size: 13px; }
        .zs-toc-item .zs-toc-icon { width: 24px; height: 24px; font-size: 11px; }
        .zs-toc-meta { font-size: 10.5px; }

        .zs-reader-head { padding: 12px; }
        .zs-reader-head .back-row { font-size: 12px; gap: 6px; flex-wrap: wrap; }
        .zs-reader-head h1 { font-size: 20px; line-height: 1.3; }
        .zs-reader-head .meta { font-size: 11.5px; gap: 6px 10px; }
        .zs-reader-body { padding: 12px; }

        .zs-section-display,
        .zs-combined-content { padding: 12px; font-size: 14.5px; line-height: 1.7; }
        .zs-section-display[dir="rtl"] { font-size: 1.2em; line-height: 1.9; }
        .zs-combined-content .mixed-rtl { font-size: 1.2em; }

        .zs-part-nav { flex-direction: column; gap: 8px; }
        .zs-part-nav > div { width: 100%; }
        .zs-part-nav .btn { width: 100%; text-align: center; }

        .zs-reader-note { font-size: 12px; padding: 10px 12px; }

        .er-section .er-subject-head { padding: 10px 12px; }
        .er-subject-head .er-title  { font-size: 13px; }
        .er-subject-head .er-summary{ font-size: 10.5px; }
        .er-row { grid-template-columns: 32px minmax(0, 1fr); padding: 10px 12px; gap: 8px; }
        .er-icon { width: 32px; height: 32px; font-size: 14px; grid-row: span 2; }
        .er-right {
            grid-column: 2;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            gap: 8px;
            text-align: left;
            margin-top: 4px;
        }
        .er-score { font-size: 13.5px; }
        .er-pill { font-size: 9.5px; }
        .er-detail { padding: 10px 12px; }
        .er-part { font-size: 11.5px; padding: 6px 8px; }

        .zs-quiz-badge { font-size: 9.5px; padding: 2px 6px; margin-left: 4px; }
        .score-chip { font-size: 11px; padding: 3px 7px; }
    }

    @media (max-width: 400px) {
        .zs-hero { padding: 12px 10px; }
        .zs-hero h1 { font-size: 18px; }
        .zs-hero .meta { font-size: 11.5px; }

        .zs-panel-head { padding: 9px 10px; }
        .zs-panel-body { padding: 9px 10px; }

        .zs-item { padding: 9px 10px; gap: 8px; }
        .zs-item-title { font-size: 13.5px; }
        .zs-item-meta  { font-size: 11px; }
        .zs-item-action{ font-size: 12px; }

        .zs-score-row { font-size: 12.5px; }
        .zs-score-row .v { font-size: 12px; }

        .zs-toc-item { font-size: 12.5px; padding: 9px; }

        .zs-reader-head h1 { font-size: 18px; }
        .zs-reader-head .meta { font-size: 11px; }

        .er-title-row { font-size: 12px; }
        .er-meta span { max-width: 140px; }
        .er-score { font-size: 13px; }
    }

    @media (max-width: 340px) {
        .zs-hero h1 { font-size: 17px; }
        .zs-item { grid-template-columns: 36px minmax(0, 1fr); }
        .zs-item-icon { width: 36px; height: 36px; font-size: 16px; }
        .zs-item-title { font-size: 13px; }
        .zs-score-row { font-size: 12px; }
        .er-title-row { font-size: 11.5px; }
    }
</style>

<div class="zs-layout">

    <!-- LEFT COLUMN -->
    <aside class="zs-left">

        <div class="zs-panel">
            <div class="zs-panel-head">
                <span>📝 Weekly quizzes</span>
                <small>points</small>
            </div>
            <div class="zs-panel-body">
                <?php if (!$weeklyScores): ?>
                    <div class="zs-score-empty">No weekly quizzes scheduled.</div>
                <?php else: ?>
                    <?php foreach ($weeklyScores as $w):
                        $hasAttempt = $w['completed_at'] !== null;
                        $passed = $hasAttempt && (int)$w['passed'] === 1;
                        $cls = $hasAttempt ? ($passed ? 'pass' : 'fail') : 'pending';
                        $earned = $hasAttempt ? (int)$w['score'] : 0;
                        $avail  = $hasAttempt ? (int)$w['attempt_total'] : (int)($w['total_points'] ?? 0);
                    ?>
                        <div class="zs-score-row">
                            <span class="t"><?php echo e(mb_strimwidth((string)$w['title'], 0, 22, '…')); ?></span>
                            <span class="v <?php echo $cls; ?>">
                                <?php echo $hasAttempt ? $earned : '—'; ?> / <?php echo $avail; ?> pts
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="zs-panel">
            <div class="zs-panel-head">
                <span>📅 Monthly exams</span>
                <small>points</small>
            </div>
            <div class="zs-panel-body">
                <?php if (!$monthlyScores): ?>
                    <div class="zs-score-empty">No monthly exams scheduled.</div>
                <?php else: ?>
                    <?php foreach ($monthlyScores as $m):
                        $hasAttempt = $m['completed_at'] !== null;
                        $passed = $hasAttempt && (int)$m['passed'] === 1;
                        $cls = $hasAttempt ? ($passed ? 'pass' : 'fail') : 'pending';
                        $earned = $hasAttempt ? (int)$m['score'] : 0;
                        $avail  = $hasAttempt ? (int)$m['attempt_total'] : (int)($examMarks[(int)$m['id']] ?? 0);
                    ?>
                        <div class="zs-score-row">
                            <span class="t"><?php echo e(mb_strimwidth((string)$m['title'], 0, 22, '…')); ?></span>
                            <span class="v <?php echo $cls; ?>">
                                <?php echo $hasAttempt ? $earned : '—'; ?> / <?php echo $avail; ?> pts
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="zs-panel">
            <div class="zs-panel-head">
                <span>📊 Overall</span>
                <small>points / budget</small>
            </div>
            <div class="zs-panel-body">
                <?php if ($totalBudget <= 0): ?>
                    <div class="zs-score-empty">No points budget configured yet.</div>
                <?php elseif ($points['total_earned'] <= 0 && $gradeRow === null): ?>
                    <div class="zs-score-empty">Not started yet.</div>
                <?php else:
                    $earnedPts = $points['total_earned'];
                    $percent   = $points['percent'];
                ?>
                    <div class="zs-overall">
                        <div class="pts-line">
                            <strong><?php echo (int)round($earnedPts); ?></strong>
                            / <?php echo (int)$totalBudget; ?> pts
                        </div>
                        <div class="pts-raw">
                            <?php echo number_format($percent, 1); ?>%
                            <?php echo $isProjected ? '(projected)' : '(final)'; ?>
                        </div>
                        <div class="progress"><div style="width: <?php echo min(100, (int)round($percent)); ?>%"></div></div>
                        <div class="pts-budget">
                            <?php echo (int)$quizBudget; ?> weekly
                            + <?php echo (int)$examBudget; ?> monthly
                            + <?php echo (int)$finalBudget; ?> final
                        </div>
                        <div class="status <?php echo $gradeStatus === 'passed' ? 'pass' : ($gradeStatus === 'failed' ? 'fail' : 'pending'); ?>">
                            <?php if ($gradeStatus === 'passed'): ?>
                                ✅ Passed (≥ <?php echo (int)$passMark; ?>%)
                            <?php elseif ($gradeStatus === 'failed'): ?>
                                ❌ Not passed (needs ≥ <?php echo (int)$passMark; ?>%)
                            <?php else: ?>
                                ⏳ In progress (needs ≥ <?php echo (int)$passMark; ?>%)
                            <?php endif; ?>
                        </div>
                        <?php if ($isProjected): ?>
                            <div class="proj-note">Final exam not yet taken — projected.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="zs-cert">
            <div class="zs-cert-preview">
                <div class="medal">📜</div>
                <div class="lbl">MyIslam</div>
                <div class="ttl">Certificate of Enrollment</div>
            </div>
            <div class="zs-cert-meta">
                <h4>Enrollment</h4>
                <p>Confirms your registration.</p>
                <a class="btn"
                   href="enrollment_certificate.php?course_id=<?php echo $courseId; ?>&amp;print=1"
                   target="_blank" rel="noopener">
                    📄 View →
                </a>
            </div>
        </div>
    </aside>

    <!-- MIDDLE COLUMN -->
    <div class="zs-main">

        <section class="zs-hero<?php echo $heroBg !== '' ? ' has-subject-bg' : ''; ?>"
                 <?php if ($heroBg !== ''): ?>style="background-image: linear-gradient(rgba(10,40,30,.55), rgba(10,40,30,.78)), url('<?php echo e($heroBg); ?>');"<?php endif; ?>>
            <h1 class="zs-hero-title" dir="rtl"><?php echo e((string)($subject['icon'] ?: '📖')); ?> <?php echo e((string)$subject['name']); ?></h1>
            <a class="back" href="course.php?course_id=<?php echo $courseId; ?>">
                ← Back to <?php echo e((string)$en['course_title']); ?>
            </a>
            <div class="meta">
                <?php echo e($curDiv['name']); ?>
                · <?php echo e((string)($en['course_title'] ?? '')); ?>
                · Pass ≥ <?php echo (int)($subject['passing_score'] ?? 70); ?>%
            </div>
        </section>

        <?php if ($readingLesson && $readingCurrentPart): ?>
            <!-- LESSON READER -->
            <?php
            $backUrl = 'course_subject.php?course_id=' . $courseId . '&subject_id=' . $subjectId;
            $rTotalParts = count($readingParts);
            $rCurPartNum = (int)($readingCurrentPart['part_number'] ?? 1);
            $rModeLabel = ($readingDisplayMode === 'combined') ? 'Combined Paragraph' : 'Separate Sections';
            $rModeIcon  = ($readingDisplayMode === 'combined') ? '📄' : '📋';
            ?>
            <section class="zs-panel" style="padding:0;">
                <div class="zs-reader-head">
                    <div class="back-row">
                        <a href="<?php echo e($backUrl); ?>">← Back to subject</a>
                        <?php if ($rTotalParts > 1): ?>
                            <span style="color:var(--muted);font-weight:600;font-size:12px;">
                                Part <?php echo $rCurPartNum; ?> of <?php echo $rTotalParts; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h1><?php echo e((string)($readingCurrentPart['title'] ?? $readingLesson['title'])); ?></h1>
                    <div class="meta">
                        <span>📖 <?php echo (int)$readingReadingTime; ?> min read</span>
                        <span><?php echo $rModeIcon; ?> <?php echo e($rModeLabel); ?></span>
                        <span>📑 <?php echo count($renderedSections); ?> section<?php echo count($renderedSections) === 1 ? '' : 's'; ?></span>
                        <?php if (!empty($readingLesson['category_name'])): ?>
                            <span>🏷️ <?php echo e((string)$readingLesson['category_name']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="zs-reader-body">
                    <?php if (!empty($readingCurrentPart['video_path'])): ?>
                        <div class="zs-media">
                            <?php
                            $vp = (string)$readingCurrentPart['video_path'];
                            $vext = strtolower(pathinfo($vp, PATHINFO_EXTENSION));
                            $vmime = $vext === 'webm' ? 'video/webm' : ($vext === 'ogg' ? 'video/ogg' : 'video/mp4');
                            ?>
                            <video controls preload="metadata">
                                <source src="<?php echo e($vp); ?>" type="<?php echo $vmime; ?>">
                            </video>
                            <div class="zs-media-download">
                                <a href="<?php echo e($vp); ?>" download class="btn btn-secondary">⬇️ Download video</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($readingCurrentPart['video_url'])): ?>
                        <div class="zs-media">
                            <iframe src="<?php echo e((string)$readingCurrentPart['video_url']); ?>"
                                    frameborder="0"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                    allowfullscreen></iframe>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($readingCurrentPart['audio_path'])): ?>
                        <div class="zs-media">
                            <?php
                            $ap = (string)$readingCurrentPart['audio_path'];
                            $aext = strtolower(pathinfo($ap, PATHINFO_EXTENSION));
                            $amime = $aext === 'ogg' ? 'audio/ogg' : ($aext === 'wav' ? 'audio/wav' : ($aext === 'm4a' ? 'audio/mp4' : 'audio/mpeg'));
                            ?>
                            <audio controls preload="metadata">
                                <source src="<?php echo e($ap); ?>" type="<?php echo $amime; ?>">
                            </audio>
                            <div class="zs-media-download">
                                <a href="<?php echo e($ap); ?>" download class="btn btn-secondary">⬇️ Download audio</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($readingDisplayMode === 'combined'): ?>
                        <div class="zs-combined-content">
                            <?php echo $readingCombinedHtml; ?>
                        </div>
                    <?php else: ?>
                        <div class="zs-sections-container">
                            <?php foreach ($renderedSections as $rIdx => $rsec):
                                $rIsRTL = ($rsec['direction'] ?? 'ltr') === 'rtl';
                                $rDirAttr = $rIsRTL ? 'rtl' : 'ltr';
                                $rLangClass = $rIsRTL ? 'rtl' : 'ltr';
                                $rTextColor = $rsec['text_color'] ?? null;
                                $rBgColor   = $rsec['background_color'] ?? null;

                                $rStyle = '';
                                if ($rTextColor && $rTextColor !== '') $rStyle .= 'color: ' . $rTextColor . ';';
                                $rIsDefaultBgPair = isUntouchedDefaultColorPair($rTextColor, $rBgColor);
                                if ($rBgColor && $rBgColor !== '' && !$rIsDefaultBgPair) {
                                    $rStyle .= 'background-color: ' . $rBgColor . ';';
                                }
                                $rHasTextColor = hasCustomTextColor($rTextColor, $rBgColor);
                                $rColorFlagClass = $rHasTextColor ? ' has-custom-text-color' : ' no-custom-text-color';
                                $rDarkModeFallbackClass = needsDarkModeTextFallback($rTextColor, $rBgColor) ? ' dark-mode-safe-text' : '';
                            ?>
                                <div class="zs-section-display <?php echo $rLangClass . $rColorFlagClass . $rDarkModeFallbackClass; ?>"
                                     dir="<?php echo $rDirAttr; ?>"
                                     <?php if ($rStyle !== ''): ?>style="<?php echo htmlspecialchars($rStyle, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                                    <div class="zs-section-body <?php echo $rIsRTL ? 'arabic-font' : ''; ?>">
                                        <?php echo $rsec['content']; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($rTotalParts > 1): ?>
                        <div class="zs-part-nav">
                            <div>
                                <?php if ($rCurPartNum > 1): ?>
                                    <a class="btn btn-secondary"
                                       href="course_subject.php?course_id=<?php echo $courseId; ?>&subject_id=<?php echo $subjectId; ?>&lesson=<?php echo $lessonId; ?>&part=<?php echo $rCurPartNum - 1; ?>">
                                        ← Previous part
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if ($rCurPartNum < $rTotalParts): ?>
                                    <a class="btn btn-primary"
                                       href="course_subject.php?course_id=<?php echo $courseId; ?>&subject_id=<?php echo $subjectId; ?>&lesson=<?php echo $lessonId; ?>&part=<?php echo $rCurPartNum + 1; ?>">
                                        Next part →
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="zs-reader-note">
                        📖 Reading only — quizzes and exams are scheduled separately.
                    </div>
                </div>
            </section>

        <?php else: ?>
            <!-- WEEK LIST -->
            <?php if (!$visibleByWeek): ?>
                <section class="zs-panel">
                    <div class="zs-panel-body" style="text-align:center;padding:36px 18px;">
                        <div style="font-size:40px;margin-bottom:10px;">📅</div>
                        <h2 style="font-size:15px;font-weight:800;color:var(--text);margin-bottom:6px;">
                            Nothing scheduled yet
                        </h2>
                        <p style="color:var(--muted);font-size:12.5px;">
                            Your lessons, quizzes, and exams will appear here as their dates arrive.
                        </p>
                    </div>
                </section>
            <?php else: ?>
                <?php foreach ($visibleByWeek as $weekNum => $weekItems): ?>
                    <div class="zs-week-group">
                        <div class="zs-week-head">
                            <span>📅 Week <?php echo (int)$weekNum; ?></span>
                            <span class="count"><?php echo count($weekItems); ?> item<?php echo count($weekItems) === 1 ? '' : 's'; ?></span>
                        </div>

                        <?php foreach ($weekItems as $it):
                            $type = $it['type'];
                            if ($type === 'content') { $icon = '📖'; $typeLabel = 'Lesson'; }
                            elseif ($type === 'quiz') { $icon = '📝'; $typeLabel = 'Weekly quiz'; }
                            else { $icon = '🎓'; $typeLabel = 'Monthly exam'; }

                            $isCurrent = ($type === 'content' && (int)$it['item_id'] === $lessonId);
                            $metaBits = [$it['weekday_name']];
                            if ($it['time']) $metaBits[] = $it['time'];
                            $metaBits[] = $typeLabel;

                            $qs = $it['item_state'] ?? null;
                            $mark = $it['mark'] ?? null;

                            $badgeHtml = '';
                            $actionHtml = '';
                            $cardLocked = false;

                            if (($type === 'quiz' || $type === 'exam') && $qs) {
                                $state     = $qs['window_state'];
                                $attempted = $qs['attempted'];

                                if ($state === 'upcoming') {
                                    $badgeHtml  = '<span class="zs-quiz-badge upcoming">⏳ Upcoming</span>';
                                    $actionHtml = 'Opens ' . $qs['unlock_at']->format('d M, H:i');
                                    $cardLocked = true;
                                } elseif ($state === 'ended') {
                                    $badgeHtml = '<span class="zs-quiz-badge ended">🏁 Ended</span>';
                                    if ($attempted) {
                                        $actionHtml = '<span class="done">✅ '
                                                    . (int)$qs['score'] . '/' . (int)$qs['total'] . ' pts'
                                                    . '</span>';
                                    } else {
                                        $actionHtml = '<span style="color:var(--muted);font-weight:700;">Missed</span>';
                                        $cardLocked = true;
                                    }
                                } else {
                                    if ($attempted) {
                                        $pass = $qs['passed'] ? 'pass' : 'fail';
                                        $badgeHtml = '<span class="zs-quiz-badge attempted ' . $pass . '">✅ Attempted</span>';
                                        $actionHtml = '<span class="score-chip ' . $pass . '">'
                                                    . (int)$qs['score'] . '/' . (int)$qs['total'] . ' pts'
                                                    . '</span>';
                                    } else {
                                        $badgeHtml = '<span class="zs-quiz-badge live">📝 Open</span>';
                                        $actionHtml = 'Take →';
                                        if ($mark) {
                                            $actionHtml .= ' <small>(' . (int)$mark . ' pts)</small>';
                                        }
                                    }
                                }
                            } else {
                                if ($type === 'content' && $it['is_done']) {
                                    $actionHtml = '<span class="done">✅ Read</span>';
                                } elseif ($type === 'content') {
                                    $actionHtml = 'Read →';
                                } else {
                                    $actionHtml = 'Take →';
                                }
                            }
                        ?>
                            <a class="zs-item type-<?php echo e($type); ?> <?php echo $isCurrent ? 'current' : ''; ?> <?php echo $cardLocked ? 'locked' : ''; ?>"
                               href="<?php echo e((string)($it['link'] ?? '#')); ?>">
                                <div class="zs-item-icon"><?php echo $icon; ?></div>
                                <div class="zs-item-body">
                                    <div class="zs-item-title">
                                        <?php echo e($it['title']); ?>
                                        <?php echo $badgeHtml; ?>
                                    </div>
                                    <div class="zs-item-meta">
                                        <?php foreach ($metaBits as $mb): ?>
                                            <span><?php echo e($mb); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="zs-item-action"><?php echo $actionHtml; ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ========================================================= -->
        <!-- EXAM RESULTS (this subject) -->
        <!-- ========================================================= -->
        <section class="zs-panel er-section" style="margin-top:10px;">
            <div class="zs-panel-head">
                <span>📝 Exam results</span>
                <small>points</small>
            </div>
            <div class="zs-panel-body">
                <?php if (!$courseExamResults): ?>
                    <div class="er-empty">
                        <span class="em">📭</span>
                        No attempts yet.<br>
                        Your quiz and exam results for this subject will appear here.
                    </div>
                <?php else:
                    $summaryWeekly  = 0;
                    $summaryMonthly = 0;
                    $summaryFinal   = 0;
                    $summarySupp    = 0;
                    foreach ($courseExamResults as $rr) {
                        if ($rr['source'] === 'weekly')       $summaryWeekly++;
                        elseif ($rr['source'] === 'monthly')  $summaryMonthly++;
                        elseif (($rr['exam_type'] ?? '') === 'supplementary') $summarySupp++;
                        else                                  $summaryFinal++;
                    }
                    $summaryParts = [];
                    if ($summaryWeekly)  $summaryParts[] = $summaryWeekly  . ' quiz'   . ($summaryWeekly  === 1 ? '' : 'zes');
                    if ($summaryMonthly) $summaryParts[] = $summaryMonthly . ' monthly';
                    if ($summaryFinal)   $summaryParts[] = $summaryFinal   . ' final';
                    if ($summarySupp)    $summaryParts[] = $summarySupp    . ' supp';
                    $summaryText = implode(' · ', $summaryParts);
                ?>
                    <div class="er-subject open" data-er-group>
                        <div dir="rtl" class="er-subject-head"
                             data-er-toggle
                             role="button"
                             tabindex="0"
                             aria-expanded="true">
                            <span class="er-ico"><?php echo e((string)($subject['icon'] ?: '📖')); ?></span>
                            <span class="er-title"><?php echo e((string)$subject['name']); ?></span>
                            <span class="er-summary"><?php echo e($summaryText); ?></span>
                            <span class="er-caret" aria-hidden="true">▾</span>
                        </div>

                        <div dir="ltr" class="er-list">
                            <?php foreach ($courseExamResults as $r):
                                $passed   = ((int)$r['passed']) === 1;
                                $source   = (string)$r['source'];
                                $examType = (string)$r['exam_type'];

                                $rowCls = '';
                                $icon   = '🎓';
                                $typeLabel = 'Final exam';
                                if ($source === 'weekly') {
                                    $rowCls = 'is-weekly'; $icon = '📝'; $typeLabel = 'Weekly quiz';
                                } elseif ($source === 'monthly') {
                                    $rowCls = 'is-monthly'; $icon = '📅'; $typeLabel = 'Monthly exam';
                                } elseif ($examType === 'supplementary') {
                                    $rowCls = 'is-supp'; $icon = '📚'; $typeLabel = 'Supplementary';
                                } elseif (!$passed) {
                                    $rowCls = 'is-failed';
                                }

                                $score    = (int)$r['score'];
                                $totalPts = (int)$r['total_points'];
                                $pct      = (float)$r['percentage'];
                                $completedAt = !empty($r['completed_at']) ? strtotime((string)$r['completed_at']) : null;
                                $dateLabel = $completedAt ? date('M d, Y · H:i', $completedAt) : '—';

                                $attemptKey = $source . ':' . (int)$r['exam_id'];
                                $topList = $topScorersByAttempt[$attemptKey] ?? [];
                            ?>
                                <div class="er-row <?php echo $rowCls; ?>"
                                     data-er-row
                                     data-source="<?php echo e($source); ?>"
                                     data-attempt-id="<?php echo (int)$r['attempt_id']; ?>"
                                     role="button" tabindex="0">
                                    <div class="er-icon"><?php echo $icon; ?></div>
                                    <div  class="er-body">
                                        <div dir="ltr" class="er-title-row"><?php echo e($r['title'] ?: 'Untitled'); ?></div>
                                        <div class="er-meta">
                                            <span>🏷️ <?php echo e($typeLabel); ?></span>
                                            <span>🔁 #<?php echo (int)$r['attempt_number']; ?></span>
                                            <span>📅 <?php echo e($dateLabel); ?></span>
                                        </div>
                                    </div>
                                    <div class="er-right">
                                        <div class="er-score <?php echo $passed ? '' : 'fail'; ?>">
                                            <?php echo $score; ?><small> / <?php echo $totalPts; ?></small>
                                        </div>
                                        <span class="er-pct"><?php echo number_format($pct, 1); ?>%</span>
                                        <span class="er-pill <?php echo $passed ? 'pass' : 'fail'; ?>">
                                            <?php echo $passed ? 'Pass' : 'Fail'; ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Per-attempt top scorers + questions (hidden until expanded) -->
                                <div class="er-detail" data-er-detail>
                                                                        <div class="er-detail-loading" data-er-questions>Loading questions…</div>

                                    <?php if ($topList): ?>
                                        <div class="er-parts">
                                            <div class="er-parts-title">Top scorers (this attempt)</div>
                                            <?php foreach ($topList as $p): ?>
                                                <div class="er-part <?php echo $p['is_me'] ? 'is-me' : ''; ?>">
                                                    <div class="er-part-rank">#<?php echo (int)$p['rank']; ?></div>
                                                    <div class="er-part-name"><?php echo e($p['display_name']); ?></div>
                                                    <div class="er-part-score">
                                                        <?php echo (int)$p['points']; ?> / <?php echo (int)$p['budget']; ?> pts
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ========================================================= -->
        <!-- SUBJECT-WIDE TOP SCORERS -->
        <!-- ========================================================= -->
        <section class="zs-panel scorers-section"
                 data-subject-scorers
                 data-subject-id="<?php echo (int)$subjectId; ?>"
                 data-course-id="<?php echo (int)$courseId; ?>"
                 style="margin-top:10px;">
            <div class="zs-panel-head" data-subject-scorers-toggle
                 role="button" tabindex="0" aria-expanded="false">
                <span>🏆 Top scorers (this subject)</span>
                <small>points / course total</small>
            </div>
            <div class="zs-panel-body" data-subject-scorers-body style="display:none;">
                <div class="er-detail-loading">Open to see top scorers…</div>
            </div>
        </section>

    </div>

    <!-- RIGHT COLUMN -->
    <aside class="zs-right">

        <div class="zs-sheikh <?php echo $sheikhBgOk ? 'has-bg' : ''; ?>">
            <div class="zs-sheikh-inner">
                <div class="zs-sheikh-tag">📖 Teacher</div>
                <h3><?php echo e((string)($subject['sheikh_name'] ?: 'Sheikh (not set)')); ?></h3>
                <?php if (!empty($subject['markaz'])): ?>
                    <div class="markaz">🕌 <?php echo e((string)$subject['markaz']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="zs-panel">
            <div class="zs-panel-head">📋 Table of contents</div>
            <div class="zs-panel-body" style="padding:6px;">
                <?php if (!$tocItems): ?>
                    <div class="zs-toc-empty">Items appear here as they unlock.</div>
                <?php else: ?>
                    <div class="zs-toc-list">
                        <?php foreach ($tocItems as $it):
                            $type = $it['type'];
                            if ($type === 'content') { $icon = '📖'; }
                            elseif ($type === 'quiz') { $icon = '📝'; }
                            else { $icon = '🎓'; }

                            $isCurrent = ($type === 'content' && (int)$it['item_id'] === $lessonId);
                            $tocMeta = 'Week ' . (int)$it['week'] . ' · ' . $it['weekday_name'];

                            $qs = $it['item_state'] ?? null;
                            $tocBadge = '';
                            if (($type === 'quiz' || $type === 'exam') && $qs) {
                                $state = $qs['window_state'];
                                if ($qs['attempted']) {
                                    $pass = $qs['passed'] ? 'pass' : 'fail';
                                    $tocBadge = '<span class="zs-quiz-badge attempted ' . $pass . '">'
                                              . (int)$qs['score'] . '/' . (int)$qs['total'] . '</span>';
                                } elseif ($state === 'upcoming') {
                                    $tocBadge = '<span class="zs-quiz-badge upcoming">⏳</span>';
                                } elseif ($state === 'ended') {
                                    $tocBadge = '<span class="zs-quiz-badge ended">🏁</span>';
                                } else {
                                    $tocBadge = '<span class="zs-quiz-badge live">📝</span>';
                                }
                            }
                        ?>
                            <a class="zs-toc-item type-<?php echo e($type); ?> <?php echo $isCurrent ? 'current' : ''; ?>"
                               href="<?php echo e((string)($it['link'] ?? '#')); ?>">
                                <span class="zs-toc-icon"><?php echo $icon; ?></span>
                                <span class="zs-toc-body">
                                    <span class="zs-toc-title">
                                        <?php echo e($it['title']); ?>
                                        <?php echo $tocBadge; ?>
                                    </span>
                                    <span class="zs-toc-meta"><?php echo e($tocMeta); ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </aside>

</div>

<script>
(function () {
    /* ---- Accordion for the single subject group ---- */
    function toggleGroup(head) {
        var group = head.closest('.er-subject');
        if (!group) return;
        var willOpen = !group.classList.contains('open');
        group.classList.toggle('open', willOpen);
        head.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    document.addEventListener('click', function (e) {
        var h = e.target.closest('[data-er-toggle]');
        if (!h) return;
        if (e.target.closest('[data-er-row]')) return;
        toggleGroup(h);
    });

    /* ---- Attempt detail: fetch questions and append below top scorers ---- */
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function renderQuestions(box, data) {
        if (!data || !data.ok) {
            box.innerHTML = '<div class="er-detail-loading">'
                + escapeHtml((data && data.message) || 'Unable to load questions.')
                + '</div>';
            return;
        }
        if (!Array.isArray(data.questions) || !data.questions.length) {
            box.innerHTML = '<div class="er-detail-loading">No per-question detail.</div>';
            return;
        }
        var html = '';
        data.questions.forEach(function (q, i) {
            var wrong = q.is_correct ? '' : 'wrong';
            html += '<div class="er-q ' + wrong + '">'
                + '<div class="er-q-head"><span class="num">#' + (i + 1) + '</span>'
                + '<span>' + escapeHtml(q.question_text) + '</span></div>'
                + '<div class="er-q-line"><span class="k">Your answer:</span>'
                + '<span class="val ' + (q.is_correct ? 'good' : 'bad') + '">'
                + (q.user_answer ? escapeHtml(q.user_answer) : '<em>—</em>') + '</span></div>';
            if (!q.is_correct && q.correct_answer) {
                html += '<div class="er-q-line"><span class="k">Correct:</span>'
                    + '<span class="val good">' + escapeHtml(q.correct_answer) + '</span></div>';
            }
            if (q.feedback) {
                html += '<div style="font-size:10.5px;color:var(--muted);font-style:italic;margin-top:4px;">💡 '
                    + escapeHtml(q.feedback) + '</div>';
            }
            html += '</div>';
        });
        box.innerHTML = html;
    }

    document.addEventListener('click', function (e) {
        var row = e.target.closest('[data-er-row]');
        if (!row) return;

        var detail = row.nextElementSibling;
        if (!detail || !detail.classList.contains('er-detail')) return;

        var wasOpen = row.classList.contains('expanded');

        document.querySelectorAll('.er-row.expanded').forEach(function (r) {
            if (r !== row) r.classList.remove('expanded');
        });
        document.querySelectorAll('.er-detail.open').forEach(function (d) {
            if (d !== detail) { d.classList.remove('open'); }
        });

        if (wasOpen) {
            row.classList.remove('expanded');
            detail.classList.remove('open');
            return;
        }

        row.classList.add('expanded');
        detail.classList.add('open');

        var qBox = detail.querySelector('[data-er-questions]');
        if (!qBox || qBox.dataset.loaded === '1') return;

        qBox.dataset.loaded = '1';
        qBox.innerHTML = '<div class="er-detail-loading">Loading questions…</div>';

        var source = row.dataset.source;
        var attemptId = row.dataset.attemptId;

        fetch('my_results_detail.php?source=' + encodeURIComponent(source)
              + '&attempt_id=' + encodeURIComponent(attemptId),
              { credentials: 'same-origin' })
            .then(async function (r) {
                var text = await r.text();
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status + ' — ' + text.slice(0, 300));
                }
                try { return JSON.parse(text); }
                catch (e) { throw new Error('Bad JSON: ' + text.slice(0, 300)); }
            })
            .then(function (data) { renderQuestions(qBox, data); })
            .catch(function (err) {
                qBox.innerHTML = '<div class="er-detail-loading" style="color:var(--danger);text-align:left;padding:10px;">'
                    + 'Error: ' + String(err.message).replace(/</g, '&lt;') + '</div>';
                console.error(err);
            });
    });

    /* ---- Subject-wide top scorers ---- */
    function renderSubjectScorers(box, data) {
        if (!box) return;
        if (!data || !data.ok) {
            box.innerHTML = '<div class="er-detail-loading">'
                + escapeHtml((data && data.message) || 'Could not load top scorers.')
                + '</div>';
            return;
        }
        if (!Array.isArray(data.participants) || !data.participants.length) {
            box.innerHTML = '<div class="er-detail-loading">No submissions yet.</div>';
            return;
        }
        var html = '<div class="er-parts" style="margin-top:0;padding-top:0;border-top:0;">';
        data.participants.forEach(function (p) {
            var pts = (p.points != null) ? (p.points | 0) : 0;
            var bdg = (p.budget != null) ? (p.budget | 0) : 0;
            var label = bdg > 0 ? (pts + ' / ' + bdg + ' pts') : (pts + ' pts');
            html += '<div class="er-part ' + (p.is_me ? 'is-me' : '') + '">'
                + '<div class="er-part-rank">#' + p.rank + '</div>'
                + '<div class="er-part-name">' + escapeHtml(p.display_name) + '</div>'
                + '<div class="er-part-score">' + label + '</div>'
                + '</div>';
        });
        html += '</div>';
        box.innerHTML = html;
    }

    function toggleSubjectScorers(head) {
        var panel = head.closest('[data-subject-scorers]');
        if (!panel) return;
        var body = panel.querySelector('[data-subject-scorers-body]');
        var willOpen = !panel.classList.contains('open');
        panel.classList.toggle('open', willOpen);
        head.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        body.style.display = willOpen ? '' : 'none';

        if (!willOpen || body.dataset.loaded === '1') return;

        body.dataset.loaded = '1';
        body.innerHTML = '<div class="er-detail-loading">Loading top scorers…</div>';

        var sid = panel.dataset.subjectId;
        var cid = panel.dataset.courseId;

        fetch('my_results_detail.php?source=subject'
              + '&subject_id=' + encodeURIComponent(sid)
              + '&course_id='  + encodeURIComponent(cid),
              { credentials: 'same-origin' })
            .then(async function (r) {
                var text = await r.text();
                if (!r.ok) throw new Error('HTTP ' + r.status + ' — ' + text.slice(0, 300));
                try { return JSON.parse(text); }
                catch (e) { throw new Error('Bad JSON: ' + text.slice(0, 300)); }
            })
            .then(function (data) { renderSubjectScorers(body, data); })
            .catch(function (err) {
                body.innerHTML = '<div class="er-detail-loading" style="color:var(--danger);text-align:left;padding:10px;">'
                    + 'Error: ' + String(err.message).replace(/</g, '&lt;') + '</div>';
                console.error(err);
            });
    }

    document.addEventListener('click', function (e) {
        var h = e.target.closest('[data-subject-scorers-toggle]');
        if (!h) return;
        toggleSubjectScorers(h);
    });
    document.addEventListener('keydown', function (e) {
        var h = e.target.closest('[data-subject-scorers-toggle]');
        if (!h) return;
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleSubjectScorers(h); }
    });
})();
</script>

<?php courseShellEnd(); ?>