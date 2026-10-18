<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = $ctx['userId'];

$today = new DateTimeImmutable('today');

// ----------------------------------------------------------------------------
// Determine which course to show
// ----------------------------------------------------------------------------
$courseId = (int)($_GET['course_id'] ?? 0);
$legacyCohortId = (int)($_GET['cohort_id'] ?? 0);

if ($courseId <= 0 && $legacyCohortId > 0) {
    $stmt = $conn->prepare("SELECT course_id FROM course_cohorts WHERE id = ?");
    $stmt->execute([$legacyCohortId]);
    $courseId = (int)$stmt->fetchColumn();
}
if ($courseId <= 0 || !courseTablesExist($conn)) {
    courseRedirect('courses.php');
}

// ----------------------------------------------------------------------------
// Load enrollment
// ----------------------------------------------------------------------------
$en = courseLoadEnrollment($conn, $userId, $courseId);
// Fetch the course cover image (if courseLoadEnrollment doesn't already)
$courseImagePath = null;
try {
    $stmt = $conn->prepare("SELECT image_path FROM courses WHERE id = ? LIMIT 1");
    $stmt->execute([$courseId]);
    $courseImagePath = $stmt->fetchColumn() ?: null;
} catch (PDOException $e) {
    error_log('course.php image_path: ' . $e->getMessage());
}
if (!$en) {
    courseFlash('error', "You're not enrolled in that course.");
    courseRedirect('courses.php');
}

// Auto-advance if a division ended and the student passed
$advanced = courseCheckAdvancement($conn, (int)$en['id']);
if ($advanced) {
    $en = courseLoadEnrollment($conn, $userId, $courseId);
    if (!$en) {
        courseRedirect('courses.php');
    }
}

// ----------------------------------------------------------------------------
// Basics
// ----------------------------------------------------------------------------
$divisionType = (string)($en['division_type'] ?? 'none');
$divisionLabel = (string)($en['division_label'] ?? 'Division');

$curDiv = null;
if (!empty($en['current_div_id'])) {
    $curDiv = [
        'id' => (int)$en['current_div_id'],
        'number' => (int)$en['current_div_number'],
        'name' => (string)$en['current_div_name'],
        'start_date' => (string)$en['current_div_start'],
        'end_date' => (string)$en['current_div_end'],
        'passing_score' => (int)$en['current_div_passing'],
    ];
}

$progress = ['week' => 0, 'total_weeks' => 0, 'pct' => 0, 'state' => 'pending', 'days_elapsed' => 0, 'days_remaining' => 0];
if ($curDiv) {
    $progress = courseComputeDivisionProgress($curDiv, $today);
}

$requestedWeek = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$showWeek = $progress['week'] > 0 ? $progress['week'] : 1;
if ($requestedWeek >= 1 && $requestedWeek <= $progress['total_weeks']) {
    $showWeek = $requestedWeek;
}

// ----------------------------------------------------------------------------
// Subjects + grades
// ----------------------------------------------------------------------------
$courseSubjects = courseLoadCourseSubjects($conn, $courseId);

$gradeBySubject = [];
$scheduleCountBySubject = [];
if ($curDiv) {
    $stmt = $conn->prepare("
        SELECT ecg.* FROM enrollment_course_grades ecg
        WHERE ecg.enrollment_id = ? AND ecg.division_id = ?
    ");
    $stmt->execute([(int)$en['id'], $curDiv['id']]);
    foreach ($stmt->fetchAll() as $r) {
        $gradeBySubject[(int)$r['subject_id']] = $r;
    }

    $stmt = $conn->prepare("
        SELECT subject_id, item_type, COUNT(*) AS n
        FROM course_schedule
        WHERE division_id = ?
        GROUP BY subject_id, item_type
    ");
    $stmt->execute([$curDiv['id']]);
    $scheduleCountBySubject = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $scheduleCountBySubject[(int)$r['subject_id']][$r['item_type']] = (int)$r['n'];
    }
}

// ----------------------------------------------------------------------------
// Pre-load available semester exams
//
// Scoped to this course explicitly so that if two courses ever share a
// division (Option A normally prevents this), the current course's exams
// still win. The additional AND cs.course_id = ? keeps the query defensive.
// ----------------------------------------------------------------------------
$examsBySubject = ['final' => [], 'supplementary' => []];
if ($curDiv) {
    try {
        $eStmt = $conn->prepare("
            SELECT se.id, se.exam_type, se.title, se.passing_score, cs.subject_id
            FROM semester_exams se
            JOIN course_subjects cs ON cs.id = se.course_subject_id
            WHERE se.division_id = ?
              AND cs.course_id = ?
              AND se.status = 'published'
              AND se.allow_access = 1
              AND (se.start_date IS NULL OR se.start_date <= NOW())
              AND (se.end_date   IS NULL OR se.end_date   >= NOW())
            ORDER BY se.exam_type ASC, se.id DESC
        ");
        $eStmt->execute([$curDiv['id'], $courseId]);
        foreach ($eStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
            $examsBySubject[$ex['exam_type']][(int)$ex['subject_id']] = [
                'id' => (int)$ex['id'],
                'title' => (string)$ex['title'],
                'passing_score' => (int)$ex['passing_score'],
            ];
        }
    } catch (PDOException $e) {
        error_log('course.php exam lookup error: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------------------
// Letter grade + remark   A 80-100 · B 70-79 · C 60-69 · D 50-59 · below 50 = F
// ----------------------------------------------------------------------------
if (!function_exists('zadLetterGrade')) {
    /** @return array{0:string,1:string} [letter, remark] */
    function zadLetterGrade(float $percent): array
    {
        if ($percent >= 80) return ['A', 'Excellent'];
        if ($percent >= 70) return ['B', 'Very good'];
        if ($percent >= 60) return ['C', 'Good'];
        if ($percent >= 50) return ['D', 'Pass'];
        return ['F', 'Fail'];
    }
}
// false = grade + remark are live: they follow the points earned so far and change as points grow
// true  = grade + remark appear only once the final exam (UE or supplementary) is done
const ZAD_GRADE_AFTER_FINAL_ONLY = false;

// ----------------------------------------------------------------------------
// Build subject cards — points-based
//
// NOTE: All grade values here come from enrollment_course_grades, which is
// per-enrollment (enrollment_id, division_id, subject_id). No course_id scoping
// is needed here — the upstream grade-book sync (academyRecordScheduled /
// academyRecordAttempt) already writes per-course/per-enrollment.
// ----------------------------------------------------------------------------
$subjectCards = [];
foreach ($courseSubjects as $cs) {
    $sid = (int)$cs['subject_id'];
    $grade = $gradeBySubject[$sid] ?? null;

    $projected = null;
    $final = null;
    $status = 'in_progress';
    $finalTaken = false;
    $suppTaken  = false;

    $quizBudget  = (int)($cs['quiz_total_points']  ?? 0);
    $examBudget  = (int)($cs['exam_total_points']  ?? 0);
    $finalBudget = (int)($cs['final_total_points'] ?? 0);

    if ($grade) {
        $projected = courseComputeProjectedGrade($grade, $cs);
        $final = courseComputeGrade($grade, $cs);
        $status = (string)$grade['status'];
        $finalTaken = (int)$grade['final_attempted'] === 1;
        $suppTaken  = (int)$grade['supplementary_attempted'] === 1;

        $weeklyEarned  = (float)$grade['weekly_total'];
        $monthlyEarned = (float)$grade['monthly_total'];
        $finalEarned   = $grade['final_score'] !== null ? (float)$grade['final_score'] : null;
        $suppEarned    = ((int)$grade['supplementary_attempted'] === 1 && $grade['supplementary_score'] !== null)
            ? (float)$grade['supplementary_score']
            : null;

        if ($finalEarned === null && $suppEarned === null) {
            $finalSlot = null;
        } else {
            $finalSlot = max($finalEarned ?? 0.0, $suppEarned ?? 0.0);
        }

        $totalEarned = $weeklyEarned + $monthlyEarned + ($finalSlot ?? 0.0);
    } else {
        $weeklyEarned  = 0.0;
        $monthlyEarned = 0.0;
        $finalEarned   = null;
        $suppEarned    = null;
        $finalSlot     = null;
        $totalEarned   = 0.0;
    }

    $totalBudget = $quizBudget + $examBudget + $finalBudget;

    $finalExam   = $examsBySubject['final'][$sid]         ?? null;
    $suppExam    = $examsBySubject['supplementary'][$sid] ?? null;

    $canTakeFinal = $finalExam && !$finalTaken;
    $canTakeSupp  = $suppExam && $finalTaken && $status === 'failed' && !$suppTaken;

    $subjectCards[] = [
        'id'            => $sid,
        'name'          => $cs['name'],
        'icon'          => $cs['icon'] ?: '📖',
        'passing_score' => (int)$cs['passing_score'],

        'points' => [
            'weekly_earned'   => $weeklyEarned,
            'weekly_budget'   => $quizBudget,
            'monthly_earned'  => $monthlyEarned,
            'monthly_budget'  => $examBudget,
            'final_earned'    => $finalEarned,
            'final_budget'    => $finalBudget,
            'total_earned'    => $totalEarned,
            'total_budget'    => $totalBudget,
            'percent'         => $totalBudget > 0 ? round($totalEarned / $totalBudget * 100, 1) : 0.0,
        ],

        'final_taken' => $finalTaken,
        'supp_taken'  => $suppTaken,
        'projected'   => $projected,
        'final'       => $final,
        'status'      => $status,

        'final_exam'      => $finalExam,
        'supp_exam'       => $suppExam,
        'can_take_final'  => $canTakeFinal,
        'can_take_supp'   => $canTakeSupp,

        'image_path'  => $cs['image_path'] ?? null,
        'sheikh_name' => $cs['sheikh_name'] ?? null,
        'markaz'      => $cs['markaz'] ?? null,
    ];
}

// Supplementary warning banner
$suppWarnings = [];
foreach ($subjectCards as $sc) {
    if ($sc['can_take_supp']) {
        $suppWarnings[] = $sc['name'];
    }
}

// Timetable
$timetable = [];
if ($curDiv && $progress['state'] === 'in_progress') {
    $loaded = courseLoadDivisionTimetable($conn, (int)$curDiv['id'], $showWeek);
    $timetable = $loaded[$showWeek] ?? [];
}

// Next item
$nextItem = null;
if ($timetable && $progress['state'] === 'in_progress') {
    $todayWeekday = (int)$today->format('N');
    $todayTime = $today->format('H:i:s');
    foreach ($timetable as $wd => $items) {
        foreach ($items as $it) {
            if ($wd > $todayWeekday || ($wd === $todayWeekday && ($it['start_time'] === null || $it['start_time'] >= $todayTime))) {
                $nextItem = $it;
                break 2;
            }
        }
    }
}

// Certificate availability
$courseCompleted = ($en['status'] === 'completed');

$flash = courseTakeFlash();

courseShellStart($ctx, (string)$en['course_title']);
// Resolve the course image to a browser URL, or fall back to academy default
$heroImageUrl = '/uploads/myislam-academyc.png';

if (!empty($courseImagePath)) {
    $candidate = trim((string)$courseImagePath);

    if (preg_match('~^(https?:)?//~i', $candidate)) {
        // Absolute URL — use as-is
        $heroImageUrl = $candidate;
    } else {
        $relative = '/' . ltrim($candidate, '/');
        $abs = ($_SERVER['DOCUMENT_ROOT'] ?? '') . $relative;

        if (is_file($abs)) {
            $heroImageUrl = $relative;
        } else {
            // Try relative to course.php's own folder (subfolder installs)
            $abs2 = __DIR__ . $relative;
            if (is_file($abs2)) {
                $heroImageUrl = $relative;
            }
        }
    }
}
?>

<style>
    /* ========================================================================
       GLOBAL WIDTH SAFETY
       ======================================================================== */
    html, body { max-width: 100%; overflow-x: hidden; }
    .zad-grid, .zad-grid *, .zad-grid *::before, .zad-grid *::after { box-sizing: border-box; }

    .zad-grid, .zad-grid *,
    .card, .card *,
    .subjects-grid, .subjects-grid *,
    .subject-card, .subject-card *,
    .zd-week, .zd-week *,
    .zd-day, .zd-day *,
    .cert-card, .cert-card *,
    .course-hero, .course-hero * {
        min-width: 0;
    }

    .subject-card .name,
    .zd-subject,
    .zd-title,
    .cert-card .cert-meta h4,
    .cert-card .cert-meta p,
    .next-item .title,
    .next-item .sub,
    .course-hero h1,
    .course-hero .meta {
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    /* ============ HERO ============ */
   .course-hero {
    position: relative;
    border-radius: var(--radius-lg);
    padding: clamp(16px, 3.5vw, 26px);
    color: #fff;
    overflow: hidden;
    margin-bottom: 14px;
    width: 100%;
    background:
        linear-gradient(rgba(10,40,30,.45), rgba(10,40,30,.75)),
        url('<?php echo e($heroImageUrl); ?>') center/cover no-repeat,
        #0E4A34;
}
    .course-hero > * { position: relative; z-index: 1; }

    /* Hero title — GOLD, bigger (bulletproof) */
.course-hero h1,
.course-hero .rtl-title {
    color: #D9BC72 !important;
    font-size: 28px !important;
    font-weight: 900 !important;
    margin: 0 auto;
    letter-spacing: .4px;
    line-height: 1.2;
    text-shadow: 0 2px 8px rgba(0,0,0,.65), 0 1px 2px rgba(0,0,0,.45);
    text-align: center;
    direction: rtl;
    unicode-bidi: isolate;
    display: block;
    width: 100%;
}
    @media (max-width: 400px) {
        .course-hero h1,
        .course-hero .rtl-title {
            font-size: clamp(22px, 6vw, 28px) !important;
        }
    }

    .course-hero .meta {
        margin-top: 6px;
        font-size: clamp(11px, 2.8vw, 12.5px);
        color: rgba(255,255,255,.92);
        text-shadow: 0 1px 3px rgba(0,0,0,.5);
        line-height: 1.5;
    }

    /* ============ HERO BADGES — 5px radius ============ */
    .course-hero .badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: space-between;
        align-items: center;
        margin-top: 12px;
    }
    .course-hero .badges .badge {
        background: rgba(255,255,255,.14);
        border: 1px solid rgba(255,255,255,.32);
        color: #fff;
        font-size: 11px;
        padding: 4px 10px;
        text-shadow: 0 1px 2px rgba(0,0,0,.4);
        text-decoration: none;
        white-space: nowrap;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        border-radius: 5px;
    }
    .course-hero .badges .badge.gold-transparent {
        background: rgba(217, 188, 114, .18);
        border: 1px solid rgba(217, 188, 114, .65);
        color: #F0D896;
        text-shadow: 0 1px 2px rgba(0, 0, 0, .45);
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
    }
    .course-hero .badges .badge.gold-transparent:hover {
        background: rgba(217, 188, 114, .30);
        border-color: #D9BC72;
        color: #fff;
    }
    .course-hero .badges .badge.gold { background: rgba(217,188,114,.95); color: #1a1a1a; border-color: #D9BC72; text-shadow: none; }
    .course-hero .badges .badge.brand { background: rgba(127,202,164,.9); color: #0A3626; border-color: #7fcaa4; text-shadow: none; }
    .course-hero .badges .badge.danger { background: rgba(239,68,68,.9); color: #fff; border-color: #ef4444; }
    .course-hero .badges .badge.muted { background: rgba(255,255,255,.12); color: rgba(255,255,255,.85); }

    .course-hero .progress-bar {
        margin-top: 12px;
        height: 8px;
        border-radius: 5px;
        background: rgba(255,255,255,.25);
        overflow: hidden;
    }
    .course-hero .progress-bar > div {
        height: 100%;
        background: linear-gradient(90deg, #D9BC72, #f0d98e);
        border-radius: 999px;
    }
    .course-hero .progress-meta {
        margin-top: 5px;
        font-size: 11px;
        font-weight: 700;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 4px;
        color: rgba(255,255,255,.92);
        text-shadow: 0 1px 3px rgba(0,0,0,.45);
    }

    /* ============ GENERIC CARD ============ */
    .card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius-md);
        padding: 14px;
        margin-bottom: 12px;
        width: 100%;
        overflow: hidden;
    }
    .card > h2 {
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--gold);
        margin: 0 0 10px;
        padding-bottom: 6px;
        border-bottom: 1px solid var(--line);
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }

    .waiting-card {
        background: var(--gold-soft);
        border: 1px solid var(--gold);
        border-radius: var(--radius-lg);
        padding: clamp(16px, 3vw, 24px);
        text-align: center;
        margin-bottom: 14px;
        width: 100%;
    }
    .waiting-card .big {
        font-size: clamp(32px, 9vw, 54px);
        font-weight: 800;
        color: var(--gold);
        line-height: 1;
        margin: 6px 0;
    }
    .waiting-card h2 { font-size: clamp(14px, 3.5vw, 16px); color: var(--text); margin: 0; line-height: 1.3; }
    .waiting-card p { margin-top: 6px; color: var(--text-secondary); font-size: clamp(11px, 2.8vw, 13px); line-height: 1.5; }

    .next-item {
        background: var(--brand-soft);
        border: 1px solid var(--brand);
        border-left: 4px solid var(--brand);
        border-radius: var(--radius-md);
        padding: 11px 13px;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        width: 100%;
    }
    .next-item .badge-next {
        background: var(--brand);
        color: #fff;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        padding: 3px 8px;
        border-radius: 5px;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .next-item .info { flex: 1 1 160px; min-width: 0; }
    .next-item .title { font-weight: 800; font-size: 13.5px; line-height: 1.3; direction: rtl; text-align: right; unicode-bidi: isolate; }
    .next-item .sub { font-size: 11px; color: var(--text-secondary); margin-top: 2px; line-height: 1.4; }
    .next-item .btn { flex-shrink: 0; }

    /* ============ SUBJECTS GRID ============ */
    .subjects-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(240px, 100%), 1fr));
        gap: 10px;
    }
    .subject-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-left: 4px solid var(--brand);
        border-radius: 5px;
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-width: 0;
        overflow: hidden;
    }
    .subject-card.sc-1 { border-left-color: var(--accent); }
    .subject-card.sc-2 { border-left-color: var(--gold); }
    .subject-card.sc-3 { border-left-color: #6C5CC0; }
    .subject-card.sc-4 { border-left-color: #A8456B; }
    .subject-card.sc-5 { border-left-color: #1F7A7A; }
    .subject-card .head {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
        direction: rtl;
    }
    .subject-card .icon { font-size: 20px; line-height: 1; flex-shrink: 0; }

    /* Subject title — GOLD, bulletproof */
    .subject-card .name,
    .subject-card.has-bg .name {
        font-weight: 800;
        font-size: 20px;
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: #D9BC72 !important;
        text-shadow: 0 1px 3px rgba(0, 0, 0, .45);
        text-align: right;
        direction: rtl;
        unicode-bidi: isolate;
    }

    /* Subject badge */
    .subject-card .grade-badge {
        font-size: 10.5px;
        font-weight: 800;
        padding: 3px 9px;
        border-radius: 5px;
        white-space: nowrap;
        flex-shrink: 0;
        border: 1px solid transparent;
        direction: ltr;
        unicode-bidi: isolate;
        letter-spacing: .2px;
    }
    .subject-card .grade-badge.pass {
        background: rgba(127, 202, 164, .18);
        border-color: rgba(127, 202, 164, .55);
        color: #2f7a55;
    }
    .subject-card .grade-badge.fail {
        background: rgba(239, 68, 68, .14);
        border-color: rgba(239, 68, 68, .5);
        color: #b91c1c;
    }
    .subject-card .grade-badge.pending {
        background: rgba(217, 188, 114, .18);
        border-color: rgba(217, 188, 114, .6);
        color: #8a6b21;
    }
    body.dark-mode .subject-card .grade-badge.pass    { color: #7fcaa4; }
    body.dark-mode .subject-card .grade-badge.fail    { color: #ef4444; }
    body.dark-mode .subject-card .grade-badge.pending { color: #D9BC72; }

    .subject-card.has-bg .grade-badge {
        background: rgba(0, 0, 0, .35);
        border-color: rgba(255, 255, 255, .3);
        color: #fff;
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
    }

    .subject-card .stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 5px;
        font-size: 10.5px;
        color: var(--text-secondary);
    }
    .subject-card .stat {
        padding: 5px 7px;
        background: var(--surface-2);
        border-radius: 7px;
        text-align: center;
        line-height: 1.25;
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .subject-card .stat strong {
        display: block;
        font-size: 12px;
        color: var(--text);
        margin-top: 1px;
        overflow-wrap: anywhere;
    }
    .subject-card .grade-bar {
        height: 5px;
        border-radius: 999px;
        background: var(--surface-3);
        overflow: hidden;
    }
    .subject-card .grade-bar > div {
        height: 100%;
        background: linear-gradient(90deg, var(--brand-light), var(--brand));
    }

    /* ============ TIMETABLE WIDGET ============ */
    .zd-week { display: grid; gap: 8px; }
    .zd-day {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 10px;
        overflow: hidden;
        min-width: 0;
    }
    .zd-day.today { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-soft); }
    .zd-day-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 7px 10px;
        background: var(--surface-2);
        border-bottom: 1px solid var(--line);
        font-size: 11px;
        font-weight: 800;
        color: var(--brand);
        text-transform: uppercase;
        letter-spacing: .3px;
        gap: 6px;
        min-width: 0;
    }
    .zd-day-head > span:first-child {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }
    .zd-day-head .today-pill {
        background: var(--brand);
        color: #fff;
        font-size: 8.5px;
        font-weight: 800;
        padding: 1px 7px;
        border-radius: 5px;
        margin-left: 5px;
        letter-spacing: .3px;
    }
    .zd-day-head .zd-count {
        font-size: 9.5px;
        font-weight: 700;
        color: var(--muted);
        background: var(--surface-3);
        padding: 1px 7px;
        border-radius: 5px;
        text-transform: none;
        letter-spacing: 0;
        flex-shrink: 0;
    }
    .zd-items { display: grid; }
    .zd-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 7px;
        padding: 8px 10px;
        border-bottom: 1px solid var(--line);
        text-decoration: none;
        color: inherit;
        transition: background .15s;
        min-width: 0;
    }
    .zd-item:last-child { border-bottom: none; }
    .zd-item:hover { background: var(--surface-2); }
    .zd-time {
        font-weight: 800;
        font-size: 10.5px;
        color: var(--brand);
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
        grid-row: span 2;
        align-self: start;
        padding-top: 1px;
        flex-shrink: 0;
    }
    .zd-item.type-quiz .zd-time { color: var(--gold); }
    .zd-item.type-exam .zd-time { color: var(--accent); }
    .zd-body { min-width: 0; direction: rtl; text-align: right; unicode-bidi: isolate; }
    .zd-subject {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        font-weight: 700;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
        direction: rtl;
    }
    .zd-subject .icon { font-size: 12px; flex-shrink: 0; }
    .zd-subject > span:last-child {
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }
    .zd-title {
        font-size: 10.5px;
        color: var(--muted);
        margin-top: 1px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
        text-align: right;
    }
    .zd-empty { padding: 8px 10px; font-size: 11px; color: var(--muted); font-style: italic; }
    .zd-week-empty { padding: 24px 14px; text-align: center; color: var(--muted); font-size: 12px; }
    .zd-week-empty .emoji { font-size: 28px; margin-bottom: 6px; }
    .zd-footer { margin-top: 10px; text-align: center; }
    .zd-footer a {
        display: inline-block;
        font-size: 11.5px;
        font-weight: 700;
        color: var(--brand);
        padding: 7px 12px;
        border-radius: 5px;
        border: 1px solid var(--line);
        background: var(--surface);
        text-decoration: none;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .zd-footer a:hover { border-color: var(--brand); color: var(--gold); }

    /* ============ TIMETABLE — item-type badge ============ */
    .zd-subject .nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 1 1 auto; }
    .zd-type {
        flex-shrink: 0 !important;
        margin-left: auto;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .3px;
        padding: 2px 8px;
        border-radius: 5px;
        border: 1px solid transparent;
        background: rgba(14, 74, 52, .12);
        color: var(--brand);
        overflow: visible !important;
    }
    .zd-type.t-quiz {
        background: rgba(217, 188, 114, .20);
        border-color: rgba(217, 188, 114, .55);
        color: #8a6b21;
    }
    .zd-type.t-exam {
        background: rgba(62, 110, 145, .16);
        border-color: rgba(62, 110, 145, .5);
        color: #3E6E91;
    }
    body.dark-mode .zd-type          { background: rgba(127, 202, 164, .15); color: #7fcaa4; }
    body.dark-mode .zd-type.t-quiz   { color: #D9BC72; }
    body.dark-mode .zd-type.t-exam   { color: #6FA0C4; }

    /* ============ CERT CARDS ============ */
    .cert-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 10px;
        transition: transform .2s, box-shadow .2s;
        min-width: 0;
        width: 100%;
    }
    .cert-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.08); }
    .cert-card.locked { opacity: .72; }
    .cert-card.locked:hover { transform: none; box-shadow: none; }
    .cert-preview {
        position: relative;
        height: 100px;
        border-radius: 8px;
        margin: 8px 8px 0;
        overflow: hidden;
        border: 3px double var(--gold);
        background:
            linear-gradient(135deg, rgba(255,247,219,.88), rgba(255,255,255,.96) 38%, rgba(231,243,235,.86)),
            #fff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 8px;
        text-align: center;
        min-width: 0;
    }
    .cert-preview::before {
        content: "MyIslam";
        position: absolute;
        inset: auto auto 6px 50%;
        transform: translateX(-50%) rotate(-18deg);
        font-size: 26px;
        font-weight: 900;
        color: rgba(22,101,52,.08);
        white-space: nowrap;
        pointer-events: none;
    }
    .cert-preview > * { position: relative; z-index: 1; }
    .cert-preview .cp-medallion {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: var(--brand);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        border: 2px solid var(--gold);
        margin-bottom: 4px;
    }
    .cert-preview .cp-platform {
        font-size: 7px;
        font-weight: 800;
        color: var(--brand);
        letter-spacing: .14em;
        text-transform: uppercase;
    }
    .cert-preview .cp-title {
        font-size: 10.5px;
        font-weight: 800;
        color: var(--brand-dark);
        line-height: 1.05;
        margin-top: 2px;
    }
    .cert-preview .cp-seal {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: radial-gradient(circle, #fff 0 34%, var(--gold-soft) 35% 58%, var(--gold) 59% 100%);
        border: 2px solid var(--brand);
        margin-top: 6px;
    }
    .cert-preview .cp-lock {
        position: absolute;
        inset: 0;
        background: rgba(255,255,255,.75);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 3;
        border-radius: inherit;
    }
    .cert-preview .cp-lock .emoji { font-size: 22px; margin-bottom: 3px; }
    .cert-preview .cp-lock .text {
        font-size: 9px;
        font-weight: 800;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .08em;
    }
    .cert-card .cert-meta { padding: 8px 12px 10px; }
    .cert-card .cert-meta h4 { font-size: 12px; font-weight: 800; color: var(--text); margin: 0 0 3px; }
    .cert-card .cert-meta p { font-size: 10.5px; color: var(--muted); line-height: 1.4; margin: 0 0 6px; }
    .cert-card .cert-meta .cert-date {
        font-size: 9.5px;
        font-weight: 700;
        color: var(--brand);
        margin-bottom: 5px;
        display: block;
    }
    .cert-card .cert-action {
        display: block;
        width: 100%;
        text-align: center;
        padding: 7px 10px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 800;
        text-decoration: none;
        border: 1px solid var(--line);
        background: var(--surface-2);
        color: var(--text);
        transition: all .2s;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .cert-card .cert-action:hover { border-color: var(--brand); color: var(--brand); }
    .cert-card .cert-action.primary {
        background: linear-gradient(135deg, var(--brand), var(--brand-dark));
        color: #fff;
        border-color: var(--brand);
    }
    .cert-card .cert-action.gold {
        background: linear-gradient(135deg, #D9BC72, #a88435);
        color: #1a1a1a;
        border-color: #a88435;
    }
    .cert-card .cert-action.disabled {
        background: var(--surface-3);
        color: var(--muted);
        border-style: dashed;
        pointer-events: none;
        cursor: not-allowed;
    }

    /* ============ 3-COLUMN GRID ============ */
    .zad-grid {
        display: grid;
        grid-template-columns: 260px minmax(0, 1fr) 340px;
        gap: 14px;
        align-items: start;
        width: 100%;
    }
    @media (max-width: 1200px) {
        .zad-grid { grid-template-columns: 240px minmax(0, 1fr); }
        .zad-timetable { grid-column: 1 / -1; }
    }
    @media (max-width: 800px) {
        .zad-grid { grid-template-columns: minmax(0, 1fr); }
        .zad-timetable { grid-column: auto; }
        .zad-main { order: -1; }
    }

    /* ============ SUBJECT CARD BG VARIANT ============ */
    .subject-card-link {
        display: flex;
        flex-direction: column;
        gap: 10px;
        flex: 1;
        min-width: 0;
        width: 100%;
    }
    .zd-item.zd-locked { opacity: .6; cursor: not-allowed; background: var(--surface-2); }
    .zd-item.zd-locked:hover { background: var(--surface-2); }
    .subject-card.has-bg {
        background-size: cover, cover;
        background-position: center, center;
        background-repeat: no-repeat, no-repeat;
        color: #fff;
    }
    .subject-card.has-bg .stat { background: rgba(255,255,255,.15); color: rgba(255,255,255,.9); }
    .subject-card.has-bg .stat strong { color: #fff; }

    /* ============ MOBILE ORDER (≤ 800px) ============ */
    @media (max-width: 800px) {
        .zad-grid {
            display: flex !important;
            flex-direction: column;
            gap: 12px;
        }
        .zad-grid > aside,
        .zad-grid > .zad-main {
            display: contents !important;
        }
        .zad-grid > .zad-timetable { display: block; width: 100%; }
        .zad-main > * { order: 0; }
        .zad-timetable { order: 10; }
        .zad-grid > aside > .card:nth-child(1) { order: 20; }
        .zad-grid > aside > .card:nth-child(2) { order: 99; }
        .zad-grid > aside > .card { width: 100%; }
    }

    /* ============ MOBILE FINE-TUNING ============ */
    @media (max-width: 640px) {
        .course-hero { padding: 14px 12px; margin-bottom: 10px; border-radius: 10px; }
        .course-hero .badges { gap: 4px; }
        .course-hero .badges .badge { font-size: 10px; padding: 3px 8px; }
        .course-hero .progress-meta { font-size: 10px; }

        .card { padding: 10px; margin-bottom: 8px; }
        .card > h2 { font-size: 11.5px; margin-bottom: 8px; padding-bottom: 5px; }

        .subjects-grid { grid-template-columns: minmax(0, 1fr); gap: 8px; }
        .subject-card { padding: 10px; }
        .subject-card .head { gap: 6px; flex-wrap: wrap; }
        .subject-card .icon { font-size: 18px; }
        .subject-card .name,
        .subject-card.has-bg .name { font-size: 18px; white-space: normal; }
        .subject-card .grade-badge { font-size: 10.5px; padding: 2px 8px; }
        .subject-card .stats { gap: 4px; }
        .subject-card .stat { padding: 5px 5px; font-size: 10px; }
        .subject-card .stat strong { font-size: 11px; }

        .zd-week { gap: 6px; }
        .zd-day-head { padding: 6px 9px; font-size: 10.5px; }
        .zd-item { padding: 7px 9px; gap: 6px; }
        .zd-time { font-size: 10px; }
        .zd-subject { font-size: 11.5px; }
        .zd-title { font-size: 10px; }

        .next-item { padding: 10px 12px; gap: 8px; }
        .next-item .info { flex: 1 1 100%; }
        .next-item .title { font-size: 12.5px; }
        .next-item .sub { font-size: 10.5px; }
        .next-item .btn { width: 100%; text-align: center; }

        .cert-preview { height: 90px; }
        .cert-preview::before { font-size: 22px; }

        .cert-card .cert-meta { padding: 8px 10px 10px; }
        .cert-card .cert-meta h4 { font-size: 12px; }
        .cert-card .cert-meta p { font-size: 10.5px; }
        .cert-card .cert-action { font-size: 11px; padding: 7px 8px; }

        .waiting-card { padding: 16px 12px; }
        .waiting-card .big { font-size: clamp(28px, 12vw, 44px); }
    }

    @media (max-width: 400px) {
        .course-hero .meta { font-size: 11px; }

        .subject-card .icon { font-size: 16px; }
        .subject-card .name,
        .subject-card.has-bg .name { font-size: 17px; }
        .subject-card .grade-badge { font-size: 9.5px; padding: 2px 6px; }
        .subject-card .stat { font-size: 9.5px; padding: 4px 4px; }
        .subject-card .stat strong { font-size: 10.5px; }

        .zd-subject { font-size: 11px; }
        .zd-title { font-size: 9.5px; }

        .cert-preview { height: 82px; }
        .cert-preview::before { font-size: 20px; }
        .cert-preview .cp-medallion { width: 22px; height: 22px; font-size: 10px; }
        .cert-preview .cp-title { font-size: 9.5px; }
    }

    @media (max-width: 340px) {
        .course-hero .badges .badge { font-size: 9.5px; padding: 2px 6px; }
        .subject-card .stats { grid-template-columns: 1fr; }
        .cert-card .cert-meta h4 { font-size: 11px; }
        .cert-card .cert-action { font-size: 10.5px; }
    }

    /* ============ TIMETABLE — mobile day tabs ============ */
    .zd-tabs { display: none; }
    .zd-item { box-shadow: inset 3px 0 0 var(--brand); align-items: start; }
    .zd-item.type-quiz { box-shadow: inset 3px 0 0 var(--gold); }
    .zd-item.type-exam { box-shadow: inset 3px 0 0 var(--accent); }
    .zd-time { min-width: 40px; text-align: center; line-height: 1.25; padding-left: 2px; }

    @media (max-width: 800px) {
        .zad-timetable { padding: 12px; }
        .zd-tabs {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 4px;
            margin-bottom: 10px;
        }
        .zd-tab {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1px;
            min-height: 44px;
            padding: 5px 0;
            border-radius: 5px;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 800;
            line-height: 1.1;
            cursor: pointer;
        }
        .zd-tab .n { font-size: 10px; font-weight: 700; color: var(--muted); }
        .zd-tab.has-items .n { color: var(--brand); }
        .zd-tab.today { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-soft); }
        .zd-tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
        .zd-tab.active .n { color: rgba(255,255,255,.88); }

        .zd-week.js .zd-day { display: none; }
        .zd-week.js .zd-day.is-active { display: block; }

        .zd-day-head { padding: 9px 12px; font-size: 12px; }
        .zd-item { padding: 11px 12px; gap: 10px; min-height: 52px; }
        .zd-time { font-size: 12px; min-width: 44px; }
        .zd-subject { font-size: 13px; }
        .zd-title {
            font-size: 11.5px;
            white-space: normal;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .zd-empty { padding: 16px 12px; text-align: center; font-size: 12px; }
    }

    @media (max-width: 400px) {
        .zd-tabs { gap: 3px; }
        .zd-tab { font-size: 10.5px; min-height: 42px; }
        .zd-item { padding: 10px; gap: 8px; }
        .zd-time { min-width: 38px; font-size: 11.5px; }
        .zd-subject { font-size: 12.5px; }
        .zd-type { font-size: 8.5px; padding: 1px 6px; }
    }

    /* ============ GRADES CARD ============ */
    .grade-row { direction: rtl; }
    .grade-row .grade-row-name { text-align: right; unicode-bidi: isolate; min-width: 0; overflow-wrap: anywhere; }
    .grade-row .grade-badge {
        direction: ltr;
        unicode-bidi: isolate;
        flex-shrink: 0;
        border-radius: 5px;
        padding: 3px 9px;
        font-size: 10.5px;
        font-weight: 800;
        border: 1px solid transparent;
    }
    .grade-row .grade-badge.pass {
        background: rgba(127, 202, 164, .18);
        border-color: rgba(127, 202, 164, .55);
        color: #2f7a55;
    }
    .grade-row .grade-badge.fail {
        background: rgba(239, 68, 68, .14);
        border-color: rgba(239, 68, 68, .5);
        color: #b91c1c;
    }
    .grade-row .grade-badge.pending {
        background: rgba(217, 188, 114, .18);
        border-color: rgba(217, 188, 114, .6);
        color: #8a6b21;
    }
    body.dark-mode .grade-row .grade-badge.pass    { color: #7fcaa4; }
    body.dark-mode .grade-row .grade-badge.fail    { color: #ef4444; }
    body.dark-mode .grade-row .grade-badge.pending { color: #D9BC72; }

    /* ============ GRADES TABLE: Subject | Points | Grade | Remark | Status ============ */
    .grade-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .grade-table { width: 100%; border-collapse: collapse; table-layout: fixed; direction: rtl; font-size: 11px; }
    .grade-table th {
        font-size: 8.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0;
        color: var(--muted); padding: 6px 2px; text-align: center;
        border-bottom: 2px solid var(--line); white-space: nowrap;
    }
    .grade-table td {
        padding: 7px 3px; text-align: center; vertical-align: middle;
        border-bottom: 1px dashed var(--line); line-height: 1.25; min-width: 0;
    }
    .grade-table tbody tr:last-child td { border-bottom: 0; }
    .grade-table th:first-child, .grade-table td:first-child { text-align: right; padding-right: 4px; }
    .grade-table .gt-subject { font-weight: 700; font-size: 11.5px; overflow-wrap: anywhere; unicode-bidi: isolate; }
    .grade-table .gt-num { direction: ltr; unicode-bidi: isolate; font-weight: 800; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .grade-table .gt-of { color: var(--muted); font-weight: 600; font-size: 9.5px; }
    .grade-table .gt-remark { font-size: 9.5px; font-weight: 700; color: var(--text-secondary); overflow-wrap: normal; word-break: normal; }
    .grade-table .grade-badge {
        display: inline-block; direction: ltr; unicode-bidi: isolate;
        border-radius: 5px; padding: 2px 5px; font-size: 9px; font-weight: 800;
        border: 1px solid transparent; white-space: nowrap; max-width: 100%;
    }
    .grade-table .grade-badge.pass    { background: rgba(127,202,164,.18); border-color: rgba(127,202,164,.55); color: #2f7a55; }
    .grade-table .grade-badge.fail    { background: rgba(239,68,68,.14);  border-color: rgba(239,68,68,.5);   color: #b91c1c; }
    .grade-table .grade-badge.pending { background: rgba(217,188,114,.18); border-color: rgba(217,188,114,.6); color: #8a6b21; }
    body.dark-mode .grade-table .grade-badge.pass    { color: #7fcaa4; }
    body.dark-mode .grade-table .grade-badge.fail    { color: #ef4444; }
    body.dark-mode .grade-table .grade-badge.pending { color: #D9BC72; }

    .gt-letter {
        display: inline-flex; align-items: center; justify-content: center;
        width: 22px; height: 22px; border-radius: 50%;
        font-size: 11px; font-weight: 800; color: #fff; background: var(--muted);
    }
    .gt-letter.l-A { background: #15803d; }
    .gt-letter.l-B { background: var(--accent); }
    .gt-letter.l-C { background: var(--gold); }
    .gt-letter.l-D { background: #c2410c; }
    .gt-letter.l-F { background: #b91c1c; }
    .gt-letter.l-none { background: transparent; color: var(--muted); }

    .grade-legend {
        display: flex; flex-wrap: wrap; gap: 3px 10px; margin-top: 8px; padding-top: 7px;
        border-top: 1px dashed var(--line); font-size: 9.5px; color: var(--muted); font-weight: 600;
    }
    .grade-legend b { color: var(--text); }

    @media (max-width: 400px) {
        .grade-table { font-size: 10.5px; }
        .grade-table .gt-subject { font-size: 11px; }
        .grade-table .gt-remark { font-size: 9px; }
        .grade-table .grade-badge { font-size: 8.5px; padding: 2px 4px; }
    }

    /* Section cards: 5px corners */
    .zad-grid .card { border-radius: 5px; }
</style>

<?php if ($flash): ?>
    <div class="flash <?php echo e($flash[0]); ?>" role="status"><?php echo e($flash[1]); ?></div>
<?php endif; ?>

<?php if (!empty($suppWarnings)): ?>
    <section class="card" style="border-color:var(--danger);background:rgba(220,38,38,.06);margin-bottom:12px;">
        <h2 style="color:var(--danger);margin-bottom:6px;">⚠️ Supplementary Exam Available</h2>
        <p style="font-size:13px;color:var(--text-secondary);margin:0;">
            You did not pass <strong><?php echo e(implode(', ', $suppWarnings)); ?></strong>.
            Take the supplementary exam to recover.
        </p>
    </section>
<?php endif; ?>

<?php if ($progress['state'] === 'pending' && $curDiv):
    $waitStart = new DateTimeImmutable($curDiv['start_date']);
    $daysToStart = (int)$today->diff($waitStart)->days;
?>
    <section class="waiting-card">
        <h2>Your first <?php echo e(strtolower($divisionLabel)); ?> starts soon</h2>
        <div class="big"><?php echo $daysToStart; ?></div>
        <p>
            day<?php echo $daysToStart === 1 ? '' : 's'; ?> until <strong><?php echo e($curDiv['name']); ?></strong> begins on
            <strong><?php echo e($waitStart->format('l, d F Y')); ?></strong>.
        </p>
        <p style="font-size:11.5px;color:var(--muted);margin-top:12px;">
            You've been enrolled. When the division starts, your timetable will appear here automatically.
        </p>
    </section>
<?php endif; ?>

<div class="zad-grid">

    <!-- LEFT COLUMN -->
    <aside>
        <?php if ($subjectCards): ?>
        <section class="card">
            <h2>📊 Performance report</h2>
            <div class="grade-wrap">
                <table class="grade-table">
                    <colgroup>
                        <col style="width:27%"><col style="width:16%"><col style="width:13%"><col style="width:24%"><col style="width:20%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Points</th>
                            <th>Grade</th>
                            <th>Remark</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($subjectCards as $s):
                        $pts      = $s['points'];
                        $earned   = (int)round($pts['total_earned']);
                        $budget   = (int)$pts['total_budget'];
                        $decided  = ($s['final_taken'] || $s['supp_taken']);   // final exam (UE or supp) done

                        // status: only pass / fail once the final exam is done
                        $statusCls = 'pending';
                        $statusTxt = 'In progress';
                        if ($decided && $s['status'] === 'passed') { $statusCls = 'pass'; $statusTxt = 'Pass'; }
                        elseif ($decided && $s['status'] === 'failed') { $statusCls = 'fail'; $statusTxt = 'Fail'; }

                        // grade + remark from the points percentage
                        // live: shown as soon as the student has points, and recalculated on every page load
                        $showGrade = $budget > 0
                            && (float)$pts['total_earned'] > 0
                            && (!ZAD_GRADE_AFTER_FINAL_ONLY || $decided);
                        $letter = '—';
                        $remark = '—';
                        if ($showGrade) {
                            [$letter, $remark] = zadLetterGrade((float)$pts['percent']);
                            // below 50% before the final exam is not a fail yet
                            if ($letter === 'F' && !$decided) {
                                $remark = 'Below pass';
                            }
                        }
                    ?>
                        <tr>
                            <td class="gt-subject" dir="rtl"><?php echo e($s['icon']); ?> <?php echo e($s['name']); ?></td>
                            <td class="gt-num"><?php echo $earned; ?><span class="gt-of">/<?php echo $budget; ?></span></td>
                            <td><span class="gt-letter l-<?php echo $showGrade ? e($letter) : 'none'; ?>"><?php echo e($letter); ?></span></td>
                            <td class="gt-remark"><?php echo e($remark); ?></td>
                            <td><span class="grade-badge <?php echo $statusCls; ?>"><?php echo e($statusTxt); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="grade-legend">
                <span><b>A</b> 80–100 Excellent</span>
                <span><b>B</b> 70–79 Very good</span>
                <span><b>C</b> 60–69 Good</span>
                <span><b>D</b> 50–59 Pass</span>
            </div>
        </section>
        <?php endif; ?>

        <section class="card" style="margin-bottom:12px;">
            <h2>🎓 Certificates</h2>

            <?php
            $issuedCerts = [];
            try {
                $stmtC = $conn->prepare("
                    SELECT id, certificate_code, certification_type, title, issued_at
                    FROM certificates
                    WHERE user_id = ?
                      AND (subject_id IS NULL OR subject_id IN (
                          SELECT subject_id FROM course_subjects WHERE course_id = ?
                      ))
                      AND certification_type IN ('course','division','subject')
                    ORDER BY issued_at DESC
                    LIMIT 5
                ");
                $stmtC->execute([$userId, $courseId]);
                foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $issuedCerts[$c['certification_type']] = $c;
                }
            } catch (PDOException $e) {
                error_log('course.php issued certificates query: ' . $e->getMessage());
            }

            $hasCourseCert   = isset($issuedCerts['course']);
            $hasDivisionCert = isset($issuedCerts['division']);
            ?>

            <div class="cert-card">
                <div class="cert-preview">
                    <div class="cp-medallion">📜</div>
                    <div class="cp-platform">MyIslam Academy</div>
                    <div class="cp-title">Certificate of<br>Enrollment</div>
                    <div class="cp-seal"></div>
                </div>
                <div class="cert-meta">
                    <h4>Certificate of Enrollment</h4>
                    <p>Confirms your registration in this course.</p>
                    <a class="cert-action primary"
                       href="enrollment_certificate.php?course_id=<?php echo (int)$courseId; ?>&amp;print=1"
                       target="_blank" rel="noopener">
                        📄 View certificate →
                    </a>
                </div>
            </div>

            <?php if ($courseCompleted && $hasCourseCert): ?>
                <div class="cert-card">
                    <div class="cert-preview">
                        <div class="cp-medallion">🏆</div>
                        <div class="cp-platform">MyIslam Academy</div>
                        <div class="cp-title">Certificate of<br>Completion</div>
                        <div class="cp-seal"></div>
                    </div>
                    <div class="cert-meta">
                        <h4>Certificate of Completion</h4>
                        <p>You successfully passed every subject in every division.</p>
                        <?php if (!empty($issuedCerts['course']['issued_at'])): ?>
                            <span class="cert-date">✅ Earned <?php echo e(date('d M Y', strtotime($issuedCerts['course']['issued_at']))); ?></span>
                        <?php endif; ?>
                        <a class="cert-action gold"
                           href="completion_certificate.php?course_id=<?php echo (int)$courseId; ?>&amp;print=1"
                           target="_blank" rel="noopener">
                            🎓 View certificate →
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="cert-card locked">
                    <div class="cert-preview">
                        <div class="cp-lock">
                            <div class="emoji">🔒</div>
                            <div class="text">Locked</div>
                        </div>
                        <div class="cp-medallion">🏆</div>
                        <div class="cp-platform">MyIslam Academy</div>
                        <div class="cp-title">Certificate of<br>Completion</div>
                        <div class="cp-seal"></div>
                    </div>
                    <div class="cert-meta">
                        <h4>Certificate of Completion</h4>
                        <p>Pass every subject in every division to unlock.</p>
                        <span class="cert-action disabled">🔒 Locked</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasDivisionCert): ?>
                <div class="cert-card">
                    <div class="cert-preview">
                        <div class="cp-medallion">🎓</div>
                        <div class="cp-platform">MyIslam Academy</div>
                        <div class="cp-title">Division<br>Certificate</div>
                        <div class="cp-seal"></div>
                    </div>
                    <div class="cert-meta">
                        <h4>Division Certificate</h4>
                        <p><?php echo e((string)($issuedCerts['division']['title'] ?? 'Semester completed')); ?></p>
                        <?php if (!empty($issuedCerts['division']['issued_at'])): ?>
                            <span class="cert-date">✅ Earned <?php echo e(date('d M Y', strtotime($issuedCerts['division']['issued_at']))); ?></span>
                        <?php endif; ?>
                        <a class="cert-action"
                           href="certificate_view.php?code=<?php echo e((string)$issuedCerts['division']['certificate_code']); ?>"
                           target="_blank" rel="noopener">
                            📄 View certificate →
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <p style="font-size:10.5px;color:var(--muted);margin-top:6px;line-height:1.45;text-align:center;">
                Enrollment certifies registration. Completion and division certificates are issued automatically on passing.
            </p>
        </section>
    </aside>

    <!-- MIDDLE COLUMN -->
    <div class="zad-main" style="min-width:0;">

        <section class="course-hero">
            <h1 class="rtl-title" dir="rtl"><?php echo e($en['course_title']); ?></h1>
            <div class="meta">
                <?php if ($curDiv): ?>
                    <?php echo e($curDiv['name']); ?>
                    <?php if ($progress['state'] === 'pending'): ?>
                        · Starts <?php echo e((new DateTimeImmutable($curDiv['start_date']))->format('d M Y')); ?>
                    <?php else: ?>
                        · Week <?php echo (int)$progress['week']; ?> of <?php echo (int)$progress['total_weeks']; ?>
                        · <?php echo e((new DateTimeImmutable($curDiv['start_date']))->format('d M Y')); ?>
                        → <?php echo e((new DateTimeImmutable($curDiv['end_date']))->format('d M Y')); ?>
                    <?php endif; ?>
                <?php else: ?>
                    Self-paced course
                <?php endif; ?>
            </div>
            <div class="badges">
                <?php if ($progress['state'] === 'pending'): ?>
                    <span class="badge gold-transparent">⏳ Waiting to start</span>
                <?php elseif ($progress['state'] === 'in_progress'): ?>
                    <span class="badge gold-transparent">📚 In progress</span>
                <?php elseif ($progress['state'] === 'ended'): ?>
                    <span class="badge muted">🏁 Division ended</span>
                <?php endif; ?>

                <?php if ($en['division_status'] === 'passed'): ?>
                    <span class="badge brand">✅ Passed</span>
                <?php elseif ($en['division_status'] === 'failed'): ?>
                    <span class="badge danger">⚠️ Not passed</span>
                <?php endif; ?>

                <?php if ($en['status'] === 'completed'): ?>
                    <span class="badge brand">🎉 Course completed</span>
                <?php endif; ?>

                <a class="badge gold-transparent" href="timetable.php?course_id=<?php echo $courseId; ?>">
                    🗓️ Full timetable
                </a>
            </div>
            <?php if ($progress['state'] === 'in_progress'): ?>
                <div class="progress-bar"><div style="width: <?php echo (int)$progress['pct']; ?>%"></div></div>
                <div class="progress-meta">
                    <span><?php echo (int)$progress['days_elapsed']; ?> days done</span>
                    <span><?php echo (int)$progress['pct']; ?>%</span>
                    <span><?php echo (int)$progress['days_remaining']; ?> days remaining</span>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($subjectCards): ?>
        <section class="card">
            <h2>📚 Your subjects in this <?php echo e(strtolower($divisionLabel)); ?></h2>
            <div class="subjects-grid">
                <?php foreach ($subjectCards as $i => $s):
                    $pts = $s['points'];
                    $gradeClass = 'pending';
                    $gradeLabel = '— / ' . (int)$pts['total_budget'] . ' pts';

                    if ($s['status'] === 'passed') {
                        $gradeClass = 'pass';
                        $gradeLabel = (int)round($pts['total_earned']) . ' / ' . (int)$pts['total_budget'] . ' pts';
                    } elseif ($s['status'] === 'failed') {
                        $gradeClass = 'fail';
                        $gradeLabel = (int)round($pts['total_earned']) . ' / ' . (int)$pts['total_budget'] . ' pts';
                    } elseif ($s['projected'] !== null) {
                        $gradeLabel = '~' . (int)round($pts['total_earned']) . ' / ' . (int)$pts['total_budget'] . ' pts';
                    }

                    $cardCta = null;
                    if (!empty($s['can_take_final']) && !empty($s['final_exam'])) {
                        $cardCta = [
                            'href'  => 'take_semester_exam.php?exam_id=' . (int)$s['final_exam']['id'],
                            'label' => '🎓 Take final exam →',
                            'style' => 'background:var(--brand);border-color:var(--brand);color:#fff;',
                        ];
                    } elseif (!empty($s['can_take_supp']) && !empty($s['supp_exam'])) {
                        $cardCta = [
                            'href'  => 'take_semester_exam.php?exam_id=' . (int)$s['supp_exam']['id'],
                            'label' => '📚 Take supplementary →',
                            'style' => 'background:var(--gold);border-color:var(--gold);color:#fff;',
                        ];
                    }
                ?>
                    <div class="subject-card sc-<?php echo $i % 6; ?> has-bg"
                         <?php if (!empty($s['image_path'])): ?>
                         style="background-image: linear-gradient(rgba(10,40,30,.55), rgba(10,40,30,.75)), url('<?php echo e($s['image_path']); ?>');"
                         <?php endif; ?>>
                        <a class="subject-card-link"
                           href="course_subject.php?course_id=<?php echo (int)$courseId; ?>&subject_id=<?php echo (int)$s['id']; ?>"
                           style="text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:inherit;">

                            <div class="head" dir="rtl">
                                <span class="icon"><?php echo e($s['icon']); ?></span>
                                <span class="name" dir="rtl"><?php echo e($s['name']); ?></span>
                                <span class="grade-badge <?php echo $gradeClass; ?>"><?php echo e($gradeLabel); ?></span>
                            </div>

                            <div class="stats">
                                <div class="stat">
                                    Weekly
                                    <strong><?php echo (int)round($pts['weekly_earned']); ?> / <?php echo (int)$pts['weekly_budget']; ?> pts</strong>
                                </div>
                                <div class="stat">
                                    Monthly
                                    <strong><?php echo (int)round($pts['monthly_earned']); ?> / <?php echo (int)$pts['monthly_budget']; ?> pts</strong>
                                </div>
                                <div class="stat">
                                    Final
                                    <strong>
                                        <?php if ($s['final_taken'] || $pts['final_earned'] !== null): ?>
                                            <?php echo (int)round($pts['final_earned'] ?? 0); ?> / <?php echo (int)$pts['final_budget']; ?> pts
                                        <?php else: ?>
                                            — / <?php echo (int)$pts['final_budget']; ?> pts
                                        <?php endif; ?>
                                    </strong>
                                </div>
                                <div class="stat">
                                    Total
                                    <strong><?php echo (int)round($pts['total_earned']); ?> / <?php echo (int)$pts['total_budget']; ?> pts</strong>
                                </div>
                            </div>

                            <?php if ($pts['total_budget'] > 0): ?>
                                <div class="grade-bar">
                                    <div style="width: <?php echo min(100, (int)round($pts['percent'])); ?>%"></div>
                                </div>
                                <div style="font-size:10.5px;color:var(--muted);font-weight:700;text-align:right;margin-top:2px;">
                                    <?php echo number_format($pts['percent'], 1); ?>% (pass ≥ <?php echo (int)$s['passing_score']; ?>%)
                                </div>
                            <?php endif; ?>
                        </a>

                        <?php if ($cardCta): ?>
                            <a class="btn btn-sm"
                               style="display:block;text-align:center;margin-top:8px;font-weight:700;padding:8px 12px;border-radius:10px;font-size:12px;<?php echo $cardCta['style']; ?>"
                               href="<?php echo e($cardCta['href']); ?>">
                                <?php echo $cardCta['label']; ?>
                            </a>
                        <?php elseif ($s['final_taken'] && !$s['supp_taken'] && $s['status'] === 'passed'): ?>
                            <div style="margin-top:8px;font-size:11.5px;color:var(--brand);font-weight:700;text-align:center;padding:4px;">
                                ✅ Final passed
                            </div>
                        <?php elseif ($s['final_taken'] && $s['supp_taken']): ?>
                            <div style="margin-top:8px;font-size:11.5px;color:var(--muted);font-weight:700;text-align:center;padding:4px;">
                                All attempts used
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

    </div>

    <!-- RIGHT COLUMN -->
    <section class="card zad-timetable">

        <h2>🗓️ This week</h2>

        <?php if ($nextItem):
            $hasWindowMeta = array_key_exists('visible', $nextItem);
            $nextVisible   = $hasWindowMeta ? !empty($nextItem['visible']) : true;

            $nextHref = $nextItem['link'] ?? '#';
            if (($nextItem['item_type'] ?? '') === 'content') {
                $nextHref = 'course_subject.php?course_id=' . (int)$courseId
                          . '&subject_id='        . (int)($nextItem['subject_id'] ?? 0)
                          . '&lesson='            . (int)($nextItem['item_id'] ?? 0);
            }
        ?>
            <div class="next-item" style="margin-bottom:12px;">
                <span class="badge-next">Up next</span>
                <div class="info">
                    <div class="title" dir="rtl"><?php echo e($nextItem['subject_icon']); ?> <?php echo e($nextItem['title']); ?></div>
                    <div class="sub">
                        <?php echo e($nextItem['subject_name']); ?>
                        · <?php echo e(COURSE_ITEM_LABELS[$nextItem['item_type']]); ?>
                        <?php if ($nextItem['start_time']): ?> · <?php echo e(substr((string)$nextItem['start_time'], 0, 5)); ?><?php endif; ?>
                    </div>
                </div>
                <?php if ($nextVisible): ?>
                    <a class="btn btn-primary btn-sm" href="<?php echo e($nextHref); ?>">Open →</a>
                <?php else: ?>
                    <span class="btn btn-sm"
                          style="background:var(--surface-3);color:var(--muted);border:1px dashed var(--line);cursor:not-allowed;">
                        🔒 Opens
                        <?php
                        if (!empty($nextItem['unlock_at']) && $nextItem['unlock_at'] instanceof DateTimeInterface) {
                            echo e($nextItem['unlock_at']->format('D d M, H:i'));
                        } else {
                            echo e('soon');
                        }
                        ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        $ttDayLabels = [
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
            4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
        ];
        $widgetItems = $timetable ?? [];
        $widgetTotal = 0;
        foreach ($widgetItems as $day) { $widgetTotal += count($day); }
        $todayWd = (int)$today->format('N');
        $fmtT = static fn($t) => ($t === null || $t === '') ? '—' : substr((string)$t, 0, 5);
        $ttShort = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'];

        $defaultDay = 1;
        if ($progress['state'] === 'in_progress') {
            $defaultDay = $todayWd;
        } else {
            foreach ($ttDayLabels as $wdk => $_n) {
                if (!empty($widgetItems[$wdk])) { $defaultDay = $wdk; break; }
            }
        }
        ?>

        <?php if ($widgetTotal === 0): ?>
            <div class="zd-week-empty">
                <div class="emoji">🗓️</div>
                <div>No classes this week.</div>
            </div>
        <?php else: ?>
            <div class="zd-tabs" role="tablist" aria-label="Day of the week">
                <?php foreach ($ttShort as $wdk => $short):
                    $cnt = count($widgetItems[$wdk] ?? []);
                    $tabToday = ($wdk === $todayWd && $progress['state'] === 'in_progress');
                ?>
                    <button type="button" role="tab"
                            class="zd-tab<?php echo $cnt > 0 ? ' has-items' : ''; ?><?php echo $tabToday ? ' today' : ''; ?>"
                            data-day="<?php echo $wdk; ?>">
                        <?php echo e($short); ?>
                        <span class="n"><?php echo $cnt > 0 ? $cnt : '·'; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="zd-week" data-default="<?php echo (int)$defaultDay; ?>">
                <?php foreach ($ttDayLabels as $wd => $dayName):
                    $dayItems = $widgetItems[$wd] ?? [];
                    $isToday = ($wd === $todayWd
                                && $progress['state'] === 'in_progress');
                ?>
                    <div class="zd-day <?php echo $isToday ? 'today' : ''; ?>" data-day="<?php echo (int)$wd; ?>">
                        <div class="zd-day-head">
                            <span>
                                <?php echo e($dayName); ?>
                                <?php if ($isToday): ?>
                                    <span class="today-pill">Today</span>
                                <?php endif; ?>
                            </span>
                            <span class="zd-count"><?php echo count($dayItems); ?></span>
                        </div>
                        <?php if (!$dayItems): ?>
                            <div class="zd-empty">No classes scheduled</div>
                        <?php else: ?>
                            <div class="zd-items">
                                <?php foreach ($dayItems as $it):
                                    $type = (string)$it['item_type'];
                                    $endTime = null;
                                    if (!empty($it['start_time']) && !empty($it['duration'])) {
                                        $parts = explode(':', $it['start_time']);
                                        if (count($parts) >= 2) {
                                            $total = (int)$parts[0] * 60 + (int)$parts[1] + (int)$it['duration'];
                                            $total %= (24 * 60);
                                            $endTime = str_pad((string)intdiv($total, 60), 2, '0', STR_PAD_LEFT)
                                                     . ':' . str_pad((string)($total % 60), 2, '0', STR_PAD_LEFT);
                                        }
                                    }
                                    $visible = array_key_exists('visible', $it) ? !empty($it['visible']) : true;

                                    $zdHref  = $it['link'] ?? '#';
                                    if ($type === 'content') {
                                        $zdHref = 'course_subject.php?course_id=' . (int)$courseId
                                                . '&subject_id='        . (int)($it['subject_id'] ?? 0)
                                                . '&lesson='            . (int)($it['item_id'] ?? 0);
                                    }

                                    if ($visible): ?>
                                        <a class="zd-item type-<?php echo e($type); ?>" href="<?php echo e($zdHref); ?>">
                                            <span class="zd-time">
                                                <?php echo e($fmtT($it['start_time'] ?? null)); ?>
                                                <?php if ($endTime): ?>
                                                    <br><span style="opacity:.55;font-weight:600;"><?php echo e($endTime); ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="zd-body" dir="rtl">
                                                <span class="zd-subject">
                                                    <span class="icon"><?php echo e($it['subject_icon']); ?></span>
                                                    <span class="nm"><?php echo e($it['subject_name']); ?></span>
                                                    <span class="zd-type t-<?php echo e($type); ?>"><?php echo e(COURSE_ITEM_LABELS[$type] ?? ucfirst($type)); ?></span>
                                                </span>
                                                <span class="zd-title"><?php echo e($it['title']); ?></span>
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <div class="zd-item zd-locked type-<?php echo e($type); ?>" aria-disabled="true">
                                            <span class="zd-time">
                                                🔒 <?php echo e($fmtT($it['start_time'] ?? null)); ?>
                                                <?php if ($endTime): ?>
                                                    <br><span style="opacity:.55;font-weight:600;"><?php echo e($endTime); ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="zd-body" dir="rtl">
                                                <span class="zd-subject">
                                                    <span class="icon"><?php echo e($it['subject_icon']); ?></span>
                                                    <span class="nm"><?php echo e($it['subject_name']); ?></span>
                                                    <span class="zd-type t-<?php echo e($type); ?>"><?php echo e(COURSE_ITEM_LABELS[$type] ?? ucfirst($type)); ?></span>
                                                </span>
                                                <span class="zd-title">
                                                    <?php echo e($it['title']); ?>
                                                    <span style="color:var(--muted);font-weight:600;">
                                                        ·
                                                        <?php if (!empty($it['unlock_at']) && $it['unlock_at'] instanceof DateTimeInterface): ?>
                                                            opens <?php echo e($it['unlock_at']->format('D d M, H:i')); ?>
                                                        <?php else: ?>
                                                            locked
                                                        <?php endif; ?>
                                                    </span>
                                                </span>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="zd-footer">
            <a href="timetable.php?course_id=<?php echo (int)$courseId; ?>">
                🗓️ Full timetable →
            </a>
        </div>
    </section>
</div>

<script>
(function () {
    var week = document.querySelector('.zd-week');
    if (!week) return;
    var days = week.querySelectorAll('.zd-day');
    var tabs = document.querySelectorAll('.zd-tab');
    week.classList.add('js');

    function show(d) {
        days.forEach(function (x) { x.classList.toggle('is-active', x.getAttribute('data-day') === d); });
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-day') === d;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () { show(t.getAttribute('data-day')); });
    });
    show(week.getAttribute('data-default') || '1');
})();
</script>

<?php
courseShellEnd();