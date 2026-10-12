<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = $ctx['userId'];

$today = new DateTimeImmutable('today');

if (!courseTablesExist($conn)) {
    courseRedirect('courses.php');
}

// ============================================================================
// Which cohort? (default: the one featured on the dashboard)
// ============================================================================

$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) {
    try {
        $featured = courseGetDashboardCard($conn, $userId, $today);
    } catch (Throwable $e) {
        $featured = null;
    }
    if (!$featured) {
        courseFlash('info', 'Enroll in a course to see its weekly schedule.');
        courseRedirect('courses.php');
    }
    $cohortId = (int)$featured['cohort_id'];
}

$cohort = courseLoadEnrolledCohort($conn, $cohortId, $userId);
if (!$cohort) {
    courseFlash('error', "You're not enrolled in that course.");
    courseRedirect('courses.php');
}

$duration = (int)$cohort['duration_days'];
$startDate = (string)$cohort['start_date'];
$start = new DateTimeImmutable($startDate);
$end = courseCohortEnd($startDate, $duration);
$cancelled = $cohort['cohort_status'] === 'cancelled';
$notStarted = $today < $start;

// ============================================================================
// Data
// ============================================================================

$data = courseLoadScheduleItems($conn, (int)$cohort['course_id'], $userId);
$items = $data['items'];
$subjects = $data['subjects'];

// Index items by the day on which they show up in the grid
$byDay = [];   // day => subject_id => list of [item, note]
$examStartDays = [];
$examEndDays = [];
$examWindowDays = [];
foreach ($items as $it) {
    $info = courseItemState($it, $startDate, $duration, $today, $cancelled);
    $it['state'] = $info['state'];
    $it['unlock'] = $info['unlock'];
    $it['due'] = $info['due'];

    $byDay[$it['unlock_day']][$it['subject_id']][] = [$it, $it['type'] === 'content' ? '' : 'Opens'];

    if ($it['type'] !== 'content') {
        $examStartDays[$it['unlock_day']] = true;
        $lastDay = $it['due_day'] ?? $it['unlock_day'];
        for ($d = $it['unlock_day']; $d <= $lastDay; $d++) {
            $examWindowDays[$d] = true;
        }
        if ($it['due_day'] !== null) {
            $examEndDays[$it['due_day']] = true;
            if ($it['due_day'] !== $it['unlock_day']) {
                $byDay[$it['due_day']][$it['subject_id']][] = [$it, 'Due'];
            }
        }
    }
}

// Weeks (week 1 = days 1-7 of the cohort)
$totalWeeks = max(1, (int)ceil($duration / 7));
if ($notStarted) {
    $currentWeek = 1;
} elseif ($today > $end) {
    $currentWeek = $totalWeeks;
} else {
    $currentWeek = (int)floor($start->diff($today)->days / 7) + 1;
}
$week = isset($_GET['week']) ? (int)$_GET['week'] : $currentWeek;
$week = max(1, min($totalWeeks, $week));

$firstDay = ($week - 1) * 7 + 1;
$lastDay = min($duration, $week * 7);

// Grades report: per subject, graded quizzes/exams only
$report = [];
foreach ($items as $it) {
    if ($it['type'] === 'content') {
        continue;
    }
    $sid = $it['subject_id'];
    $report[$sid] ??= ['name' => $subjects[$sid] ?? 'General', 'count' => 0, 'sum' => 0.0, 'passed' => 0, 'failed' => 0];
    if ($it['grade'] !== null) {
        $report[$sid]['count']++;
        $report[$sid]['sum'] += (float)$it['grade'];
        if ($it['passing'] !== null && $it['grade'] >= $it['passing']) {
            $report[$sid]['passed']++;
        } else {
            $report[$sid]['failed']++;
        }
    }
}

[$phaseLabel, $phaseClass] = courseCohortPhase(
    ['status' => $cohort['cohort_status'], 'start_date' => $startDate, 'enrollment_deadline' => $cohort['enrollment_deadline']],
    $duration,
    $today
);

function scheduleUrl(int $cohortId, int $week): string
{
    return 'schedule.php?cohort_id=' . $cohortId . '&week=' . $week;
}

/** Renders one item chip inside a grid cell. */
function scheduleChip(array $it, string $note): string
{
    $state = $it['state'];
    $isExam = $it['type'] !== 'content';
    $icon = $it['type'] === 'content' ? '📘' : ($it['type'] === 'quiz' ? '📝' : '📅');
    $link = ($state === 'open' || $state === 'done') ? courseItemLink($it['type'], $it['item_id']) : null;

    $html = '<div class="chip ' . e($state) . ($isExam ? ' is-exam' : '') . '">';
    $title = '<span class="chip-icon" aria-hidden="true">' . $icon . '</span><span class="chip-title">' . e($it['title']) . '</span>';
    $html .= $link ? '<a href="' . e($link) . '">' . $title . '</a>' : '<span class="chip-plain">' . $title . '</span>';

    $meta = [];
    if ($note !== '') {
        $meta[] = $note;
    }
    if ($state === 'done') {
        $meta[] = '✅ Completed';
    } elseif ($state === 'locked') {
        $meta[] = '🔒 Locked';
    } elseif ($state === 'missed') {
        $meta[] = '⛔ Missed';
    }
    if ($meta) {
        $html .= '<div class="chip-meta">' . e(implode(' · ', $meta)) . '</div>';
    }

    if ($isExam && $it['grade'] !== null) {
        $passed = $it['passing'] !== null && $it['grade'] >= $it['passing'];
        $html .= '<div class="grade-line ' . ($passed ? 'pass' : 'fail') . '">'
            . ($passed ? 'Passed' : 'Failed') . ' · ' . (int)round($it['grade']) . '%</div>';
    }
    return $html . '</div>';
}

// Week pagination (windowed when the course is long)
$pages = [];
if ($totalWeeks <= 12) {
    $pages = range(1, $totalWeeks);
} else {
    $pages = array_unique(array_merge([1], range(max(1, $week - 2), min($totalWeeks, $week + 2)), [$totalWeeks]));
    sort($pages);
}

courseShellStart($ctx, 'Schedule', 'schedule');
?>
<style>
    .sch-hero-grid { display: flex; flex-wrap: wrap; gap: 14px 28px; margin-top: 14px; }
    .sch-period span { display: block; font-size: 12px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
    .sch-period strong { font-size: 15px; }
    .sch-period small { display: block; color: var(--muted); font-size: 12px; }

    .countdown { display: grid; grid-template-columns: repeat(4, minmax(64px, 96px)); gap: 10px; margin-top: 14px; }
    .countdown div { background: var(--gold); color: var(--white); border-radius: var(--radius-md); padding: 12px 6px; text-align: center; }
    .countdown strong { display: block; font-size: clamp(22px, 5vw, 32px); line-height: 1.1; font-variant-numeric: tabular-nums; }
    .countdown span { font-size: 11px; opacity: .9; text-transform: uppercase; letter-spacing: .5px; }
    body.dark-mode .countdown div { color: #0f1720; }

    .sch-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
    .sch-toggle { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; font-size: 13px; color: var(--text-secondary); cursor: pointer; }
    .sch-toggle input { width: 18px; height: 18px; accent-color: var(--brand); }
    .pager { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .pager a, .pager span.gap {
        min-width: 34px; height: 34px; padding: 0 8px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
        border: 1px solid var(--line); background: var(--surface); font-size: 13px; font-weight: 700; color: var(--text-secondary);
    }
    .pager a:hover { border-color: var(--brand); color: var(--brand); }
    .pager a.active { background: var(--brand); border-color: var(--brand); color: var(--white); }
    .pager span.gap { border: none; background: none; }
    .week-nav { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }

    .sch-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius-md); }
    table.sch-table { width: 100%; border-collapse: collapse; min-width: 720px; }
    .sch-table th { background: var(--brand); color: var(--white); padding: 12px 10px; font-size: 13px; text-align: center; border-left: 1px solid rgba(255,255,255,.15); }
    body.dark-mode .sch-table th { color: #0f1720; }
    .sch-table th.sch-date-h { text-align: left; width: 170px; }
    .sch-table td { border-top: 1px solid var(--line); border-left: 1px solid var(--line); padding: 10px; vertical-align: top; background: var(--surface); }
    .sch-table td:first-child { border-left: none; }
    .sch-table tr.is-today td { background: var(--gold-soft); }
    .sch-table td.sch-date { background: var(--surface-2); }
    .sch-table tr.is-today td.sch-date { background: var(--gold-soft); }
    .sch-table td.sch-date.exam-window { border-left: 4px solid var(--accent); }

    .sch-day { font-weight: 800; font-size: 15px; }
    .sch-day small { font-weight: 600; color: var(--muted); margin-left: 4px; }
    .sch-greg { font-size: 13px; color: var(--text-secondary); }
    .sch-hijri { font-size: 12px; color: var(--gold); font-weight: 600; }
    .tag { display: inline-block; margin-top: 6px; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; }
    .tag.start { background: var(--brand-soft); color: var(--brand); }
    .tag.end { background: var(--danger-soft); color: var(--danger); }

    .chip { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--brand); border-radius: 10px; padding: 8px 10px; margin-bottom: 8px; font-size: 13px; }
    .chip:last-child { margin-bottom: 0; }
    .chip.is-exam { border-left-color: var(--gold); }
    .chip a { display: flex; gap: 6px; font-weight: 700; }
    .chip a:hover { color: var(--brand); }
    .chip-plain { display: flex; gap: 6px; font-weight: 700; }
    .chip-title { overflow-wrap: anywhere; }
    .chip-meta { margin-top: 3px; font-size: 11px; color: var(--muted); font-weight: 600; }
    .chip.locked { opacity: .65; }
    .chip.missed .chip-title { text-decoration: line-through; color: var(--muted); }
    .chip.done { border-left-color: var(--brand-light); }
    .grade-line { display: none; margin-top: 6px; font-size: 12px; font-weight: 800; border-radius: 8px; padding: 3px 8px; }
    body.show-grades .grade-line { display: inline-block; }
    .grade-line.pass { background: var(--brand-soft); color: var(--brand); }
    .grade-line.fail { background: var(--danger-soft); color: var(--danger); }

    .sch-footer { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; font-size: 12px; color: var(--muted); font-weight: 600; margin-top: 12px; }

    .report-table { width: 100%; border-collapse: collapse; }
    .report-table th, .report-table td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); font-size: 14px; }
    .report-table th { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }
    .report-table tr:last-child td { border-bottom: none; }
    .mini-bar { height: 8px; width: 120px; border-radius: 999px; background: var(--surface-3); overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 8px; }
    .mini-bar > div { height: 100%; background: linear-gradient(90deg, var(--brand-light), var(--brand)); }

    @media (max-width: 768px) {
        .sch-wrap { border: none; overflow: visible; }
        table.sch-table, .sch-table tbody, .sch-table tr, .sch-table td { display: block; min-width: 0; width: 100%; }
        .sch-table thead { display: none; }
        .sch-table tr { border: 1px solid var(--line); border-radius: var(--radius-md); margin-bottom: 12px; overflow: hidden; }
        .sch-table td { border-left: none; }
        .sch-table td.sch-cell::before { content: attr(data-label); display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: var(--gold); margin-bottom: 6px; }
        .sch-table td.sch-cell.empty { display: none; }
    }
</style>

        <section class="card page-hero">
            <a class="back-link" href="course.php?cohort_id=<?php echo (int)$cohortId; ?>">← Back to course</a>
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                <h1>🗓️ Schedule of lessons and exams</h1>
                <span class="badge <?php echo e($phaseClass); ?>"><?php echo e($phaseLabel); ?></span>
            </div>
            <p><?php echo e($cohort['title']); ?> · 👥 <?php echo e($cohort['cohort_name']); ?></p>
            <div class="sch-hero-grid">
                <div class="sch-period">
                    <span>Study period starts</span>
                    <strong><?php echo e($start->format('d / m / Y')); ?></strong>
                    <small><?php echo e(courseHijriShort($start)); ?> AH</small>
                </div>
                <div class="sch-period">
                    <span>Study period ends</span>
                    <strong><?php echo e($end->format('d / m / Y')); ?></strong>
                    <small><?php echo e(courseHijriShort($end)); ?> AH</small>
                </div>
            </div>

            <?php if ($notStarted && !$cancelled): ?>
                <p style="margin-top:16px;font-weight:700;">⏳ Your study period begins in:</p>
                <div class="countdown" id="countdown" data-target="<?php echo e($start->format('Y-m-d')); ?>T00:00:00+03:00" aria-live="off">
                    <div><strong id="cdDays">0</strong><span>Days</span></div>
                    <div><strong id="cdHours">0</strong><span>Hours</span></div>
                    <div><strong id="cdMins">0</strong><span>Minutes</span></div>
                    <div><strong id="cdSecs">0</strong><span>Seconds</span></div>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($cancelled): ?>
            <div class="flash error">This cohort has been cancelled. Please contact the administrator.</div>
        <?php endif; ?>

        <section class="card">
            <div class="sch-toolbar">
                <label class="sch-toggle"><input type="checkbox" id="toggleGrades"> Show grades</label>
                <nav class="pager" aria-label="Weeks">
                    <?php $prev = 0; foreach ($pages as $pg):
                        if ($prev && $pg - $prev > 1): ?><span class="gap">…</span><?php endif; ?>
                        <a href="<?php echo e(scheduleUrl($cohortId, (int)$pg)); ?>" class="<?php echo $pg === $week ? 'active' : ''; ?>" <?php echo $pg === $week ? 'aria-current="page"' : ''; ?> title="Week <?php echo (int)$pg; ?>"><?php echo (int)$pg; ?></a>
                    <?php $prev = $pg; endforeach; ?>
                </nav>
            </div>
            <p style="margin-top:10px;color:var(--text-secondary);font-size:14px;">
                <strong>Week <?php echo $week; ?> of <?php echo $totalWeeks; ?></strong>
                · Days <?php echo $firstDay; ?>–<?php echo $lastDay; ?>
            </p>
        </section>

        <section class="card">
            <div class="week-nav" style="margin-bottom:12px;">
                <?php if ($week > 1): ?>
                    <a class="btn btn-sm" href="<?php echo e(scheduleUrl($cohortId, $week - 1)); ?>">↑ Previous week</a>
                <?php else: ?><span></span><?php endif; ?>
                <?php if ($week !== $currentWeek): ?>
                    <a class="btn btn-sm btn-gold" href="<?php echo e(scheduleUrl($cohortId, $currentWeek)); ?>">Go to current week</a>
                <?php endif; ?>
            </div>

            <?php if (!$items): ?>
                <p class="empty">The schedule for this course hasn't been published yet.</p>
            <?php else: ?>
            <div class="sch-wrap">
                <table class="sch-table">
                    <thead>
                        <tr>
                            <th class="sch-date-h">Date</th>
                            <?php foreach ($subjects as $sid => $sname): ?>
                                <th><?php echo e($sname); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php for ($day = $firstDay; $day <= $lastDay; $day++):
                        $date = courseDayDate($startDate, $day);
                        $isToday = $date == $today;
                    ?>
                        <tr class="<?php echo $isToday ? 'is-today' : ''; ?>">
                            <td class="sch-date <?php echo isset($examWindowDays[$day]) ? 'exam-window' : ''; ?>">
                                <div class="sch-day"><?php echo e($date->format('l')); ?> <small>Day <?php echo $day; ?></small></div>
                                <div class="sch-greg"><?php echo e($date->format('d / m / Y')); ?></div>
                                <div class="sch-hijri"><?php echo e(courseHijriShort($date)); ?></div>
                                <?php if ($isToday): ?><span class="badge brand" style="margin-top:6px;">Today</span><?php endif; ?>
                                <?php if (isset($examStartDays[$day])): ?><div><span class="tag start">Start exam</span></div><?php endif; ?>
                                <?php if (isset($examEndDays[$day])): ?><div><span class="tag end">End exam</span></div><?php endif; ?>
                            </td>
                            <?php foreach ($subjects as $sid => $sname):
                                $cell = $byDay[$day][$sid] ?? [];
                            ?>
                                <td class="sch-cell <?php echo $cell ? '' : 'empty'; ?>" data-label="<?php echo e($sname); ?>">
                                    <?php foreach ($cell as [$it, $note]) { echo scheduleChip($it, $note); } ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div class="week-nav" style="margin-top:14px;">
                <?php if ($week > 1): ?>
                    <a class="btn btn-sm" href="<?php echo e(scheduleUrl($cohortId, $week - 1)); ?>">↑ Previous week</a>
                <?php else: ?><span></span><?php endif; ?>
                <?php if ($week < $totalWeeks): ?>
                    <a class="btn btn-sm btn-primary" href="<?php echo e(scheduleUrl($cohortId, $week + 1)); ?>">Next week ↓</a>
                <?php endif; ?>
            </div>
            <div class="sch-footer">
                <span>All times are Makkah time (GMT+3) — same as Dar es Salaam</span>
                <span>🕒 <span id="clock">--:--:--</span></span>
            </div>
            <?php endif; ?>
        </section>

        <?php if ($report): ?>
        <section class="card">
            <h2>Grades report</h2>
            <div style="overflow-x:auto;">
                <table class="report-table">
                    <thead><tr><th>Subject</th><th>Graded</th><th>Average</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($report as $r):
                        $avg = $r['count'] > 0 ? $r['sum'] / $r['count'] : null;
                    ?>
                        <tr>
                            <td><strong><?php echo e($r['name']); ?></strong></td>
                            <td><?php echo (int)$r['count']; ?></td>
                            <td>
                                <?php if ($avg !== null): ?>
                                    <span class="mini-bar"><div style="width: <?php echo (int)round($avg); ?>%"></div></span><?php echo (int)round($avg); ?>%
                                <?php else: ?>
                                    <span style="color:var(--muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['passed'] > 0): ?><span class="badge brand"><?php echo (int)$r['passed']; ?> passed</span><?php endif; ?>
                                <?php if ($r['failed'] > 0): ?><span class="badge danger"><?php echo (int)$r['failed']; ?> failed</span><?php endif; ?>
                                <?php if ($r['count'] === 0): ?><span class="badge muted">No results yet</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

<script>
(function () {
    // Show / hide grades (remembered between visits)
    const toggle = document.getElementById('toggleGrades');
    if (localStorage.getItem('scheduleGrades') === '1') {
        toggle.checked = true;
        document.body.classList.add('show-grades');
    }
    toggle.addEventListener('change', () => {
        document.body.classList.toggle('show-grades', toggle.checked);
        localStorage.setItem('scheduleGrades', toggle.checked ? '1' : '0');
    });

    // Makkah time (GMT+3) clock
    const clock = document.getElementById('clock');
    function tick() {
        if (!clock) return;
        clock.textContent = new Date().toLocaleTimeString('en-US', { timeZone: 'Asia/Riyadh', hour12: true });
    }
    tick();
    setInterval(tick, 1000);

    // Countdown to the cohort's first day (00:00, GMT+3)
    const cd = document.getElementById('countdown');
    if (cd) {
        const target = new Date(cd.dataset.target).getTime();
        const els = {
            d: document.getElementById('cdDays'), h: document.getElementById('cdHours'),
            m: document.getElementById('cdMins'), s: document.getElementById('cdSecs')
        };
        function update() {
            let diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
            els.d.textContent = Math.floor(diff / 86400); diff %= 86400;
            els.h.textContent = Math.floor(diff / 3600); diff %= 3600;
            els.m.textContent = Math.floor(diff / 60);
            els.s.textContent = diff % 60;
            if (target - Date.now() <= 0 && !sessionStorage.getItem('cdReloaded')) {
                sessionStorage.setItem('cdReloaded', '1');
                location.reload();
            }
        }
        update();
        setInterval(update, 1000);
    }
})();
</script>
<?php
courseShellEnd();