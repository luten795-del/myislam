<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = (int)$ctx['userId'];

// ---------------------------------------------------------------------------
// WHICH COURSE?
// ---------------------------------------------------------------------------
$courseId = (int)($_GET['course_id'] ?? 0);

$myEnrollments = courseLoadMyEnrollments($conn, $userId);

if (!$myEnrollments) {
    courseFlash('info', 'Enroll in a course to see its weekly timetable.');
    courseRedirect('courses.php');
}

if ($courseId <= 0) {
    $courseId = (int)$myEnrollments[0]['course_id'];
}

$en = courseLoadEnrollment($conn, $userId, $courseId);
if (!$en) {
    courseFlash('error', "You're not enrolled in that course.");
    courseRedirect('timetable.php');
}

// ---------------------------------------------------------------------------
// COURSE COVER IMAGE
// ---------------------------------------------------------------------------
$courseImagePath = null;
try {
    $stmt = $conn->prepare("SELECT image_path FROM courses WHERE id = ? LIMIT 1");
    $stmt->execute([$courseId]);
    $courseImagePath = $stmt->fetchColumn() ?: null;
} catch (PDOException $e) {
    error_log('timetable.php image_path: ' . $e->getMessage());
}

$heroImageUrl = '/uploads/myislam-academyc.png';
if (!empty($courseImagePath)) {
    $candidate = trim((string)$courseImagePath);
    if (preg_match('~^(https?:)?//~i', $candidate)) {
        $heroImageUrl = $candidate;
    } else {
        $relative = '/' . ltrim($candidate, '/');
        if (is_file(($_SERVER['DOCUMENT_ROOT'] ?? '') . $relative) || is_file(__DIR__ . $relative)) {
            $heroImageUrl = $relative;
        }
    }
}

// ---------------------------------------------------------------------------
// DIVISION + WEEK CONTEXT
// ---------------------------------------------------------------------------
$today = new DateTimeImmutable('today');

$curDiv = null;
if (!empty($en['current_div_id'])) {
    $curDiv = [
        'id'            => (int)$en['current_div_id'],
        'number'        => (int)$en['current_div_number'],
        'name'          => (string)$en['current_div_name'],
        'start_date'    => (string)$en['current_div_start'],
        'end_date'      => (string)$en['current_div_end'],
        'passing_score' => (int)$en['current_div_passing'],
    ];
}

if (!$curDiv) {
    courseShellStart($ctx, 'Timetable — ' . $en['course_title']);
    ?>
    <style>
        .tt-no-div-card { background: var(--surface); border: 1px solid var(--line); border-radius: 5px; padding: 40px 20px; text-align: center; }
        .tt-no-div-card .emoji { font-size: 56px; margin-bottom: 12px; }
        .tt-no-div-card h1 { font-size: 22px; font-weight: 800; margin-bottom: 8px; }
        .tt-no-div-card p { color: var(--muted); font-size: 14px; max-width: 460px; margin: 0 auto 20px; line-height: 1.6; }
    </style>
    <section class="tt-no-div-card">
        <div class="emoji">🗓️</div>
        <h1>No division yet</h1>
        <p>You're enrolled in <strong><?php echo e($en['course_title']); ?></strong>, but a division hasn't been assigned yet. Check back once the next division starts.</p>
        <a class="btn btn-primary" href="course.php?course_id=<?php echo $courseId; ?>">← Back to course</a>
    </section>
    <?php courseShellEnd(); exit;
}

$progress = courseComputeDivisionProgress($curDiv, $today);
$totalWeeks = max(1, (int)$progress['total_weeks']);

$requestedWeek = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$showWeek = $progress['week'] > 0 ? (int)$progress['week'] : 1;
if ($requestedWeek >= 1 && $requestedWeek <= $totalWeeks) {
    $showWeek = $requestedWeek;
}

// ---------------------------------------------------------------------------
// LOAD TIMETABLE
// ---------------------------------------------------------------------------
$timetable = courseLoadDivisionTimetable($conn, $curDiv['id']);
$weekItems = $timetable[$showWeek] ?? [];

// ---------------------------------------------------------------------------
// DAY LABELS
// ---------------------------------------------------------------------------
$dayLabels = [
    1 => ['name' => 'Monday',    'short' => 'Mon'],
    2 => ['name' => 'Tuesday',   'short' => 'Tue'],
    3 => ['name' => 'Wednesday', 'short' => 'Wed'],
    4 => ['name' => 'Thursday',  'short' => 'Thu'],
    5 => ['name' => 'Friday',    'short' => 'Fri'],
    6 => ['name' => 'Saturday',  'short' => 'Sat'],
    7 => ['name' => 'Sunday',    'short' => 'Sun'],
];

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------
function ttEndTime(?string $start, ?int $durationMinutes): ?string {
    if ($start === null || $start === '') return null;
    if ($durationMinutes === null || $durationMinutes <= 0) return null;
    $parts = explode(':', $start);
    if (count($parts) < 2) return null;
    $h = (int)$parts[0];
    $m = (int)$parts[1];
    $total = ($h * 60 + $m + $durationMinutes) % (24 * 60);
    return str_pad((string)intdiv($total, 60), 2, '0', STR_PAD_LEFT)
         . ':' . str_pad((string)($total % 60), 2, '0', STR_PAD_LEFT);
}

courseShellStart($ctx, 'Timetable — ' . $en['course_title'] . ' — ' . $curDiv['name']);
?>

<style>
    /* ============================================================
       HERO — compact, course cover image, centered title
       ============================================================ */
    .tt-hero {
        position: relative;
        border-radius: 5px;
        overflow: hidden;
        padding: clamp(18px, 3.5vw, 24px) clamp(16px, 4vw, 24px);
        color: #fff;
        margin-bottom: 12px;
        min-height: 130px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        background:
            linear-gradient(rgba(10,40,30,.5), rgba(10,40,30,.78)),
            url('<?php echo e($heroImageUrl); ?>') center/cover no-repeat,
            #0E4A34;
        width: 100%;
    }
    .tt-hero > * { position: relative; z-index: 1; }
    .tt-hero h1 {
        font-size: clamp(18px, 4vw, 24px);
        font-weight: 900;
        margin: 0 0 6px;
        color: #D9BC72;
        letter-spacing: .3px;
        text-shadow: 0 2px 8px rgba(0,0,0,.7), 0 1px 2px rgba(0,0,0,.5);
        line-height: 1.2;
        max-width: 100%;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .tt-hero .tt-meta {
        font-size: clamp(11px, 2.6vw, 12px);
        color: rgba(255,255,255,.92);
        text-shadow: 0 1px 2px rgba(0,0,0,.6);
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 3px 10px;
        line-height: 1.5;
        max-width: 100%;
    }
    .tt-hero .tt-week-chip {
        display: inline-block;
        margin-top: 8px;
        padding: 3px 12px;
        border-radius: 5px;
        background: rgba(217,188,114,.22);
        border: 1px solid rgba(217,188,114,.55);
        color: #F0D896;
        font-size: 10.5px;
        font-weight: 800;
        letter-spacing: .4px;
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
    }

    /* ============================================================
       TOPBAR
       ============================================================ */
    .tt-topbar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        padding: 8px 10px;
        border-radius: 5px;
        background: var(--surface);
        border: 1px solid var(--line);
        margin-bottom: 12px;
    }
    .tt-topbar select {
        padding: 6px 10px;
        border-radius: 5px;
        border: 1px solid var(--line);
        background: var(--surface);
        color: var(--text);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        max-width: 220px;
    }
    .tt-topbar .tt-nav-btn {
        padding: 6px 11px;
        border-radius: 5px;
        background: var(--surface);
        border: 1px solid var(--line);
        font-weight: 700;
        font-size: 12px;
        color: var(--text-secondary);
        text-decoration: none;
        white-space: nowrap;
        transition: all .15s;
    }
    .tt-topbar .tt-nav-btn:hover { border-color: var(--gold); color: var(--gold); }
    .tt-topbar .tt-nav-btn.disabled { opacity: .3; pointer-events: none; }
    .tt-topbar .dots {
        display: flex;
        gap: 3px;
        flex-wrap: wrap;
        overflow: hidden;
        max-width: 100%;
    }
    .tt-topbar .dot {
        min-width: 26px;
        height: 26px;
        padding: 0 7px;
        border-radius: 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--line);
        background: var(--surface);
        font-size: 11px;
        font-weight: 700;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all .15s;
    }
    .tt-topbar .dot:hover { border-color: var(--gold); color: var(--gold); }
    .tt-topbar .dot.active { background: var(--gold); border-color: var(--gold); color: #fff; }
    .tt-topbar .label {
        margin-left: auto;
        font-weight: 800;
        color: var(--gold);
        font-size: 12px;
        letter-spacing: .3px;
        white-space: nowrap;
    }

    /* ============================================================
       DAY LIST
       ============================================================ */
    .tt-days { display: grid; gap: 10px; }

    .tt-day {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 5px;
        overflow: hidden;
        transition: border-color .15s;
    }
    .tt-day:hover { border-color: var(--gold); }
    .tt-day.empty { opacity: .7; }
    .tt-day.today {
        border-color: var(--gold);
        box-shadow: 0 0 0 2px var(--gold-soft);
    }

    .tt-day-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 8px 12px;
        background: var(--surface-2);
        border-bottom: 1px solid var(--line);
        font-weight: 800;
        font-size: 12px;
        color: var(--gold);
        text-transform: uppercase;
        letter-spacing: .5px;
    }
    .tt-day-header .nm {
        display: flex;
        align-items: center;
        gap: 6px;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .tt-day-header .today-pill {
        background: var(--gold);
        color: #fff;
        font-size: 9px;
        font-weight: 800;
        padding: 1px 7px;
        border-radius: 5px;
        letter-spacing: .3px;
        flex-shrink: 0;
    }
    .tt-day-header .count {
        font-size: 9.5px;
        font-weight: 700;
        color: var(--muted);
        background: var(--surface-3);
        padding: 1px 7px;
        border-radius: 5px;
        text-transform: none;
        letter-spacing: 0;
        white-space: nowrap;
        flex-shrink: 0;
    }

    /* ============================================================
       ITEM — badge above Open button, always
       ============================================================ */
    .tt-items { display: grid; }

    .tt-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 10px;
        align-items: center;
        padding: 9px 12px;
        border-bottom: 1px solid var(--line);
        min-width: 0;
        transition: background .15s;
    }
    .tt-item:last-child { border-bottom: none; }
    .tt-item:hover { background: var(--surface-2); }

    .tt-time {
        font-weight: 800;
        font-size: 11px;
        color: var(--brand);
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
        display: flex;
        flex-direction: column;
        line-height: 1.15;
        padding: 3px 6px;
        background: var(--brand-soft);
        border-radius: 5px;
        text-align: center;
        min-width: 52px;
        flex-shrink: 0;
    }
    .tt-time .s { font-size: 11.5px; font-weight: 800; }
    .tt-time .e { font-size: 9px; font-weight: 600; opacity: .7; }
    .tt-time.no-time {
        background: var(--surface-3);
        color: var(--muted);
    }
    .tt-item.type-quiz .tt-time { background: var(--gold-soft); color: var(--gold); }
    .tt-item.type-exam .tt-time { background: var(--accent-soft); color: var(--accent); }

    .tt-main {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
        line-height: 1.3;
    }
    .tt-subject {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 12.5px;
        font-weight: 800;
        color: var(--text);
        min-width: 0;
    }
    .tt-subject .icon { font-size: 13px; flex-shrink: 0; }
    .tt-subject .nm {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }
.tt-actions-stack {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    flex-shrink: 0;
}

    /* Far-right column: type badge stacked ABOVE the Open button — always */
    .tt-actions-stack {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 4px;
        flex-shrink: 0;
    }
    .tt-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 2px 7px;
        border-radius: 5px;
        font-size: 8.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .3px;
        white-space: nowrap;
        border: 1px solid transparent;
    }
    .tt-type-badge.lesson { background: rgba(14, 74, 52, .12); border-color: rgba(14, 74, 52, .35); color: var(--brand); }
    .tt-type-badge.quiz   { background: rgba(217, 188, 114, .2); border-color: rgba(217, 188, 114, .55); color: #8a6b21; }
    .tt-type-badge.exam   { background: rgba(62, 110, 145, .14); border-color: rgba(62, 110, 145, .5); color: #3E6E91; }
    body.dark-mode .tt-type-badge.lesson { color: #7fcaa4; }
    body.dark-mode .tt-type-badge.quiz   { color: #D9BC72; }
    body.dark-mode .tt-type-badge.exam   { color: #6FA0C4; }

    .tt-action {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 5px 11px;
        border-radius: 5px;
        background: var(--brand);
        color: #fff !important;
        font-weight: 800;
        font-size: 10.5px;
        text-decoration: none;
        white-space: nowrap;
        transition: filter .15s;
    }
    .tt-action:hover { filter: brightness(1.1); }
    .tt-action.gold { background: var(--gold); }
    .tt-action.accent { background: var(--accent); }

    .tt-empty-day {
        padding: 14px 12px;
        font-size: 11.5px;
        color: var(--muted);
        font-style: italic;
        text-align: center;
    }

    .tt-empty-week {
        padding: 50px 20px;
        text-align: center;
        color: var(--muted);
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 5px;
    }
    .tt-empty-week .emoji { font-size: 40px; margin-bottom: 10px; opacity: .6; }
    .tt-empty-week h2 { font-size: 15px; font-weight: 800; color: var(--text); margin-bottom: 6px; }
    .tt-empty-week p { font-size: 12.5px; margin-bottom: 16px; }

    /* ============================================================
       MOBILE — the badge-above-button stack NEVER changes
       ============================================================ */
    @media (max-width: 640px) {
        .tt-hero { padding: 16px 12px; min-height: 110px; margin-bottom: 10px; }
        .tt-hero h1 { font-size: 17px; }

        .tt-topbar { padding: 6px 8px; gap: 6px; margin-bottom: 10px; }
        .tt-topbar select { padding: 5px 8px; font-size: 11.5px; max-width: 160px; }
        .tt-topbar .tt-nav-btn { padding: 5px 8px; font-size: 11px; }
        .tt-topbar .dot { min-width: 24px; height: 24px; font-size: 10px; padding: 0 6px; }
        .tt-topbar .label { font-size: 11px; }

        .tt-day-header { padding: 7px 10px; font-size: 11px; }
        .tt-day-header .count { font-size: 9px; padding: 1px 6px; }
        .tt-day-header .today-pill { font-size: 8.5px; padding: 1px 6px; }

        .tt-item {
            grid-template-columns: 48px minmax(0, 1fr) auto;
            gap: 8px;
            padding: 8px 10px;
        }
        .tt-time { padding: 2px 5px; min-width: 48px; }
        .tt-time .s { font-size: 11px; }
        .tt-time .e { font-size: 8.5px; }
        .tt-subject { font-size: 12px; }
        .tt-title { font-size: 10.5px; }
.tt-actions-stack { gap: 10px; }
        .tt-type-badge { font-size: 8px; padding: 1px 6px; }
        .tt-action { padding: 4px 9px; font-size: 10px; }
    }

    /* Very narrow — keep the vertical stack, only shrink typography */
    @media (max-width: 400px) {
        .tt-hero { padding: 12px 10px; min-height: 100px; }
        .tt-hero h1 { font-size: 16px; }

        .tt-item {
            grid-template-columns: 44px minmax(0, 1fr) auto;
            gap: 6px;
            padding: 7px 9px;
        }
        .tt-time { min-width: 44px; padding: 2px 4px; }
        .tt-time .s { font-size: 10.5px; }
        .tt-subject { font-size: 11.5px; }
        .tt-title { font-size: 10px; }

        .tt-actions-stack { gap: 2px; }
        .tt-type-badge { font-size: 7.5px; padding: 1px 5px; }
        .tt-action { padding: 3px 8px; font-size: 9.5px; }
    }
</style>

<!-- ============ HERO ============ -->
<section class="tt-hero">
    <h1><?php echo e($en['course_title']); ?></h1>
    <div class="tt-meta">
        <span>📚 <?php echo e($curDiv['name']); ?></span>
        <span>📅 <?php echo e(date('d M Y', strtotime($curDiv['start_date']))); ?> → <?php echo e(date('d M Y', strtotime($curDiv['end_date']))); ?></span>
    </div>
    <span class="tt-week-chip">Week <?php echo $showWeek; ?> of <?php echo $totalWeeks; ?></span>
</section>

<!-- ============ TOPBAR ============ -->
<div class="tt-topbar">
    <?php if (count($myEnrollments) > 1): ?>
        <select id="coursePicker" onchange="location.href='timetable.php?course_id=' + this.value;">
            <?php foreach ($myEnrollments as $me): ?>
                <option value="<?php echo (int)$me['course_id']; ?>"
                    <?php echo ((int)$me['course_id'] === $courseId) ? 'selected' : ''; ?>>
                    <?php echo e($me['course_title']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <a class="tt-nav-btn <?php echo $showWeek <= 1 ? 'disabled' : ''; ?>"
       href="?course_id=<?php echo $courseId; ?>&week=<?php echo max(1, $showWeek - 1); ?>">← Prev</a>

    <div class="dots">
        <?php
        if ($totalWeeks <= 12) {
            $pageList = range(1, $totalWeeks);
        } else {
            $pageList = array_unique(array_merge(
                [1],
                range(max(1, $showWeek - 2), min($totalWeeks, $showWeek + 2)),
                [$totalWeeks]
            ));
            sort($pageList);
        }
        $prevPage = 0;
        foreach ($pageList as $pg):
            if ($prevPage && $pg - $prevPage > 1): ?>
                <span style="color:var(--muted);padding:0 3px;font-size:10px;">…</span>
            <?php endif; ?>
            <a class="dot <?php echo $pg === $showWeek ? 'active' : ''; ?>"
               href="?course_id=<?php echo $courseId; ?>&week=<?php echo (int)$pg; ?>">
                <?php echo (int)$pg; ?>
            </a>
        <?php $prevPage = $pg; endforeach; ?>
    </div>

    <a class="tt-nav-btn <?php echo $showWeek >= $totalWeeks ? 'disabled' : ''; ?>"
       href="?course_id=<?php echo $courseId; ?>&week=<?php echo min($totalWeeks, $showWeek + 1); ?>">Next →</a>

    <span class="label">Week <?php echo $showWeek; ?></span>
</div>

<!-- ============ TIMETABLE ============ -->
<div class="tt-days">
<?php
$totalItems = 0;
foreach ($weekItems as $day) { $totalItems += count($day); }
?>

<?php if ($totalItems === 0): ?>
    <div class="tt-empty-week">
        <div class="emoji">🗓️</div>
        <h2>No classes this week</h2>
        <p>Week <?php echo $showWeek; ?> has no items scheduled yet.</p>
        <a class="btn btn-primary" href="course.php?course_id=<?php echo $courseId; ?>">← Back to course</a>
    </div>
<?php else: ?>

    <?php foreach ($dayLabels as $wd => $lbl):
        $items = $weekItems[$wd] ?? [];
        $isToday = ((int)$today->format('N') === $wd
                    && $today >= new DateTimeImmutable($curDiv['start_date'])
                    && $today <= new DateTimeImmutable($curDiv['end_date']));
        $dayClass = $isToday ? 'tt-day today' : ($items ? 'tt-day' : 'tt-day empty');
    ?>
        <div class="<?php echo $dayClass; ?>">
            <div class="tt-day-header">
                <span class="nm">
                    <?php echo e($lbl['name']); ?>
                    <?php if ($isToday): ?>
                        <span class="today-pill">Today</span>
                    <?php endif; ?>
                </span>
                <span class="count">
                    <?php echo count($items); ?>
                    item<?php echo count($items) === 1 ? '' : 's'; ?>
                </span>
            </div>

            <?php if (!$items): ?>
                <div class="tt-empty-day">No classes scheduled</div>
            <?php else: ?>
                <div class="tt-items">
                    <?php foreach ($items as $it):
                        $type = (string)$it['item_type'];
                        $typeLabel = COURSE_ITEM_LABELS[$type] ?? ucfirst($type);
                        $endTime = ttEndTime($it['start_time'] ?? null, $it['duration'] ?? null);
                        $actionClass = $type === 'quiz' ? 'tt-action gold' : ($type === 'exam' ? 'tt-action accent' : 'tt-action');
                    ?>
                        <div class="tt-item type-<?php echo e($type); ?>">
                            <?php if ($it['start_time']): ?>
                                <div class="tt-time">
                                    <span class="s"><?php echo e($it['start_time']); ?></span>
                                    <?php if ($endTime): ?><span class="e"><?php echo e($endTime); ?></span><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="tt-time no-time"><span class="s">—</span></div>
                            <?php endif; ?>

                            <div class="tt-main">
                                <div class="tt-subject">
                                    <span class="icon"><?php echo e($it['subject_icon']); ?></span>
                                    <span class="nm"><?php echo e($it['subject_name']); ?></span>
                                </div>
                                <div class="tt-title"><?php echo e($it['title']); ?></div>
                            </div>

                            <div class="tt-actions-stack">
                                <span class="tt-type-badge <?php echo e($type); ?>"><?php echo e($typeLabel); ?></span>

                                <?php if (!empty($it['link'])): ?>
                                    <a class="<?php echo e($actionClass); ?>" href="<?php echo e($it['link']); ?>">Open →</a>
                                <?php else: ?>
                                    <span style="font-size:10px;color:var(--muted);">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<?php endif; ?>
</div>

<!-- Back link -->
<div style="margin-top:14px;text-align:center;">
    <a class="btn btn-secondary btn-sm" href="course.php?course_id=<?php echo $courseId; ?>">
        ← Back to course
    </a>
</div>

<?php
courseShellEnd();