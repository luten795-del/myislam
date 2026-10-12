<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = $ctx['userId'];

$today = new DateTimeImmutable('today');
$cohortId = (int)($_GET['cohort_id'] ?? 0);

if ($cohortId <= 0 || !courseTablesExist($conn)) {
    courseRedirect('courses.php');
}

// ============================================================================
// Load the cohort + make sure THIS student is enrolled in it
// ============================================================================

$row = courseLoadEnrolledCohort($conn, $cohortId, $userId);
if (!$row) {
    courseFlash('error', "You're not enrolled in that course.");
    courseRedirect('courses.php');
}

$duration = (int)$row['duration_days'];
$startDate = (string)$row['start_date'];
$start = new DateTimeImmutable($startDate);
$end = courseCohortEnd($startDate, $duration);
$cancelled = $row['cohort_status'] === 'cancelled';
$notStarted = $today < $start;

[$phaseLabel, $phaseClass] = courseCohortPhase(
    ['status' => $row['cohort_status'], 'start_date' => $startDate, 'enrollment_deadline' => $row['enrollment_deadline']],
    $duration,
    $today
);

$data = courseLoadScheduleItems($conn, (int)$row['course_id'], $userId);
$items = $data['items'];
$subjectNames = $data['subjects']; // id => name (0 = General), already ordered
$summary = courseProgressSummary($items);

// Everything finished → mark the enrollment completed (once)
if ($row['enrollment_status'] === 'active' && !$cancelled && $summary['total'] > 0 && $summary['done'] === $summary['total']) {
    try {
        $conn->prepare("UPDATE course_enrollments SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'active'")
            ->execute([(int)$row['enrollment_id']]);
        $row['enrollment_status'] = 'completed';
    } catch (PDOException $e) {
        error_log('course.php completion error: ' . $e->getMessage());
    }
}

// State of every item, then group by subject and by day
$next = null;
$bySubject = [];
$groups = [];
foreach ($items as $it) {
    $info = courseItemState($it, $startDate, $duration, $today, $cancelled);
    $it['state'] = $info['state'];
    $it['unlock'] = $info['unlock'];
    $it['due'] = $info['due'];
    if ($next === null && $it['state'] === 'open') {
        $next = $it;
    }
    $bySubject[$it['subject_id']][] = $it;
    $groups[$it['unlock_day']][] = $it;
}

// Subject details for the cards (description, picture, teacher)
$subjectMeta = [];
$realIds = array_values(array_filter(array_keys($subjectNames), static fn($id) => $id > 0));
if ($realIds) {
    $in = implode(',', array_fill(0, count($realIds), '?'));
    $s = $conn->prepare("SELECT id, name, slug, description, image_path, sheikh_name FROM subjects WHERE id IN ($in)");
    $s->execute($realIds);
    foreach ($s->fetchAll() as $m) {
        $subjectMeta[(int)$m['id']] = $m;
    }
}

// Per-subject numbers: progress, average grade, next open item
$cards = [];
foreach ($subjectNames as $sid => $sname) {
    $list = $bySubject[$sid] ?? [];
    if (!$list) {
        continue;
    }
    $done = 0;
    $gradeSum = 0.0;
    $gradeCount = 0;
    $nextItem = null;
    foreach ($list as $it) {
        if ($it['done']) {
            $done++;
        }
        if ($it['type'] !== 'content' && $it['grade'] !== null) {
            $gradeSum += (float)$it['grade'];
            $gradeCount++;
        }
        if ($nextItem === null && $it['state'] === 'open') {
            $nextItem = $it;
        }
    }
    $cards[] = [
        'id' => $sid,
        'name' => $sname,
        'meta' => $subjectMeta[$sid] ?? null,
        'total' => count($list),
        'done' => $done,
        'pct' => (int)round(($done / count($list)) * 100),
        'avg' => $gradeCount > 0 ? (int)round($gradeSum / $gradeCount) : null,
        'next' => $nextItem,
    ];
}

// "This week" block
$totalWeeks = max(1, (int)ceil($duration / 7));
if ($notStarted) {
    $currentWeek = 1;
} elseif ($today > $end) {
    $currentWeek = $totalWeeks;
} else {
    $currentWeek = (int)floor($start->diff($today)->days / 7) + 1;
}
$weekFirst = ($currentWeek - 1) * 7 + 1;
$weekLast = min($duration, $currentWeek * 7);
$weekDays = [];
foreach ($bySubject as $sid => $list) {
    foreach ($list as $it) {
        if ($it['unlock_day'] >= $weekFirst && $it['unlock_day'] <= $weekLast) {
            $weekDays[$it['unlock_day']][] = $it + ['subject_name' => $subjectNames[$sid] ?? 'General'];
        }
    }
}
ksort($weekDays);

// Certificate: available while enrolled in a cohort that isn't cancelled
$canCertificate = !$cancelled;

$flash = courseTakeFlash();

$welcome = trim((string)($row['description'] ?? ''));
if ($welcome === '') {
    $welcome = 'Welcome to this course. We ask Allah to grant you sincerity in seeking knowledge, and the ability to apply what you study in your words and your actions. Follow the schedule, finish each lesson on time, and may Allah grant you success.';
}

courseShellStart($ctx, (string)$row['title']);
?>
<style>
    :root { --c-purple: #6C5CC0; --c-rose: #A8456B; --c-teal: #1F7A7A; }
    body.dark-mode { --c-purple: #9285DB; --c-rose: #D07A9B; --c-teal: #4DB3B3; }

    .zad-grid { display: grid; grid-template-columns: 250px minmax(0, 1fr) 270px; gap: 18px; align-items: start; }
    .zad-left, .zad-right, .zad-center { display: grid; gap: 16px; min-width: 0; }

    .zblock { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-md); overflow: hidden; }
    .zblock-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--line); background: var(--surface-2); font-size: 13px; font-weight: 800; }
    .zblock-icon { width: 24px; height: 24px; border-radius: 6px; background: var(--gold); color: var(--white); display: inline-flex; align-items: center; justify-content: center; font-size: 13px; }
    body.dark-mode .zblock-icon { color: #0f1720; }
    .zblock-body { padding: 12px; font-size: 13px; }

    .zlink-block { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px; font-weight: 800; font-size: 14px; }
    .zlink-block:hover { background: var(--brand-soft); color: var(--brand); }

    .grades-title { color: var(--gold); font-weight: 800; font-size: 12px; margin-bottom: 8px; }
    .grade-row { display: flex; justify-content: space-between; gap: 8px; padding: 5px 0; border-bottom: 1px dashed var(--line); font-size: 12px; }
    .grade-row:last-child { border-bottom: none; }
    .grade-row a { font-weight: 700; color: var(--gold); }
    .grade-row a:hover { color: var(--brand); }
    .grade-row span { color: var(--danger); font-weight: 800; white-space: nowrap; }
    .grade-row span.ok { color: var(--brand); }
    .grade-row span.none { color: var(--muted); }

    .cert-thumb { aspect-ratio: 297 / 210; border: 2px solid var(--gold); outline: 1px solid var(--gold); outline-offset: -6px; border-radius: 4px; background: var(--surface-2); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 10px; gap: 4px; margin-bottom: 10px; }
    .cert-thumb strong { font-size: 13px; color: var(--brand); font-family: Georgia, 'Times New Roman', serif; }
    .cert-thumb small { font-size: 9px; color: var(--muted); }
    .cert-thumb em { font-size: 11px; color: var(--text); border-bottom: 1px solid var(--gold); padding: 0 12px; font-style: normal; font-weight: 700; }
    .cert-thumb .seal { width: 26px; height: 26px; border-radius: 50%; background: var(--gold-soft); border: 2px solid var(--gold); }
    .zbtn { display: block; width: 100%; text-align: center; padding: 9px 10px; border-radius: 4px; font-weight: 800; font-size: 12px; color: var(--white); margin-top: 8px; }
    .zbtn.blue { background: var(--accent); }
    .zbtn.green { background: var(--brand); }
    .zbtn:hover { filter: brightness(1.1); }
    .zbtn.disabled { opacity: .5; pointer-events: none; }
    body.dark-mode .zbtn { color: #0f1720; }

    /* Centre */
    .zad-banner { position: relative; border-radius: var(--radius-md); overflow: hidden; min-height: 150px; background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%); color: #fff; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 22px 18px; }
    .zad-banner::before { content: ""; position: absolute; inset: 0; background: url('/uploads/subjects-page-hero.png') center / cover; opacity: .55; }
    .zad-banner::after { content: ""; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(14,74,52,.7), rgba(10,54,38,.55)); }
    .zad-banner > * { position: relative; z-index: 1; }
    .zad-banner h1 { font-size: clamp(22px, 4vw, 30px); font-weight: 800; text-shadow: 1px 1px 3px rgba(0,0,0,.5); }
    .zad-banner p { margin-top: 4px; font-size: 14px; opacity: .95; text-shadow: 1px 1px 2px rgba(0,0,0,.5); }
    .zad-banner .banner-badges { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }
    .zad-banner .banner-progress { width: min(380px, 90%); margin-top: 14px; }
    .zad-banner .banner-progress .bar { height: 8px; border-radius: 999px; background: rgba(255,255,255,.3); overflow: hidden; }
    .zad-banner .banner-progress .bar > div { height: 100%; background: #D9BC72; border-radius: 999px; }
    .zad-banner .banner-progress small { display: block; margin-top: 4px; font-size: 12px; font-weight: 700; }

    .welcome { text-align: justify; font-size: 14px; color: var(--text-secondary); line-height: 1.7; padding: 0 4px; }

    .subj-card { --sc: var(--brand); display: flex; gap: 14px; align-items: flex-start; background: var(--surface); border: 1px solid var(--line); border-left: 4px solid var(--sc); border-radius: var(--radius-md); padding: 14px 16px; }
    .subj-card.sc-1 { --sc: var(--accent); } .subj-card.sc-2 { --sc: var(--gold); } .subj-card.sc-3 { --sc: var(--c-purple); }
    .subj-card.sc-4 { --sc: var(--c-rose); } .subj-card.sc-5 { --sc: var(--c-teal); }
    .subj-main { flex: 1; min-width: 0; }
    .subj-name { font-size: 17px; font-weight: 800; color: var(--sc); }
    .subj-name a:hover { text-decoration: underline; }
    .subj-teacher { font-size: 12px; color: var(--muted); font-weight: 600; margin-top: 1px; }
    .subj-desc { margin-top: 6px; font-size: 13px; color: var(--text-secondary); white-space: pre-line; }
    .subj-progress { margin-top: 10px; }
    .subj-progress .bar { height: 7px; border-radius: 999px; background: var(--surface-3); overflow: hidden; }
    .subj-progress .bar > div { height: 100%; background: var(--sc); border-radius: 999px; }
    .subj-progress .meta { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; font-size: 11px; color: var(--muted); font-weight: 700; margin-top: 4px; }
    .subj-next { margin-top: 8px; font-size: 12px; }
    .subj-next a { font-weight: 800; color: var(--sc); }
    .subj-thumb { width: 84px; height: 62px; border-radius: 6px; object-fit: cover; flex-shrink: 0; border: 1px solid var(--line); background: var(--surface-3); display: flex; align-items: center; justify-content: center; font-size: 26px; }

    /* Right column */
    .week-day { padding: 6px 12px; background: var(--surface-2); border-top: 1px solid var(--line); font-weight: 800; font-size: 12px; color: var(--text); }
    .week-day:first-of-type { border-top: none; }
    .week-row { display: flex; justify-content: space-between; gap: 10px; padding: 6px 12px; font-size: 12px; border-top: 1px solid var(--line); }
    .week-row .when { color: var(--muted); white-space: nowrap; font-weight: 600; }
    .week-row .what { text-align: right; font-weight: 700; overflow-wrap: anywhere; }
    .week-row .what small { display: block; color: var(--muted); font-weight: 600; }
    .week-row a:hover { color: var(--brand); }
    .week-foot { padding: 10px 12px; border-top: 1px solid var(--line); font-size: 11px; color: var(--muted); font-weight: 600; }

    details.daylist summary { cursor: pointer; font-weight: 800; font-size: 13px; color: var(--gold); text-transform: uppercase; letter-spacing: 1px; }
    details.daylist[open] summary { margin-bottom: 14px; }

    @media (max-width: 1100px) {
        .zad-grid { grid-template-columns: 1fr; }
        .zad-center { order: 1; } .zad-left { order: 2; } .zad-right { order: 3; }
    }
    @media (max-width: 560px) {
        .subj-card { flex-direction: column-reverse; }
        .subj-thumb { width: 100%; height: 120px; }
    }
</style>

<?php if ($flash): ?>
    <div class="flash <?php echo e($flash[0]); ?>" role="status"><?php echo e($flash[1]); ?></div>
<?php endif; ?>

<div class="zad-grid">

    <!-- ===================== LEFT ===================== -->
    <aside class="zad-left" aria-label="Course tools">
        <div class="zblock">
            <a class="zlink-block" href="schedule.php?cohort_id=<?php echo (int)$cohortId; ?>">
                <span style="display:flex;align-items:center;gap:8px;"><span class="zblock-icon">📅</span> Schedule</span>
                <span aria-hidden="true">›</span>
            </a>
        </div>

        <div class="zblock">
            <div class="zblock-head"><span class="zblock-icon">★</span> Grades report</div>
            <div class="zblock-body">
                <div class="grades-title">Current course grades:</div>
                <?php if (!$cards): ?>
                    <p style="color:var(--muted);">No graded items yet.</p>
                <?php endif; ?>
                <?php foreach ($cards as $c): ?>
                    <div class="grade-row">
                        <a href="schedule.php?cohort_id=<?php echo (int)$cohortId; ?>"><?php echo e($c['name']); ?></a>
                        <?php if ($c['avg'] === null): ?>
                            <span class="none">— / 100</span>
                        <?php else: ?>
                            <span class="<?php echo $c['avg'] >= 50 ? 'ok' : ''; ?>"><?php echo (int)$c['avg']; ?> / 100</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="zblock">
            <div class="zblock-head"><span class="zblock-icon">🎓</span> Certificate of Enrollment</div>
            <div class="zblock-body">
                <div class="cert-thumb" aria-hidden="true">
                    <small>MyIslam Learning Academy</small>
                    <strong>Certificate of Enrollment</strong>
                    <em><?php echo e($ctx['user']['name']); ?></em>
                    <small><?php echo e($row['title']); ?></small>
                    <span class="seal"></span>
                </div>
                <p style="color:var(--text-secondary);font-size:12px;">In order to generate your Enrollment Certificate correctly, please make sure your profile data is correct in our system by clicking on the Edit Personal Profile button below.</p>
                <a class="zbtn blue" href="profile.php">Edit Personal Profile</a>
                <a class="zbtn green <?php echo $canCertificate ? '' : 'disabled'; ?>" href="enrollment_certificate.php?cohort_id=<?php echo (int)$cohortId; ?>&amp;print=1" target="_blank" rel="noopener">Download certificate of enrollment!</a>
            </div>
        </div>
    </aside>

    <!-- ===================== CENTRE ===================== -->
    <section class="zad-center">
        <div class="zad-banner">
            <h1><?php echo e($row['title']); ?></h1>
            <p><?php echo e($row['cohort_name']); ?> · <?php echo e($start->format('d M Y')); ?> → <?php echo e($end->format('d M Y')); ?></p>
            <div class="banner-badges">
                <span class="badge <?php echo e($phaseClass); ?>"><?php echo e($phaseLabel); ?></span>
                <?php if ($row['enrollment_status'] === 'completed'): ?><span class="badge brand">Completed 🎉</span><?php endif; ?>
            </div>
            <div class="banner-progress">
                <div class="bar"><div style="width: <?php echo (int)$summary['pct']; ?>%"></div></div>
                <small><?php echo (int)$summary['done']; ?> of <?php echo (int)$summary['total']; ?> completed · <?php echo (int)$summary['pct']; ?>%</small>
            </div>
        </div>

        <p class="welcome"><?php echo nl2br(e($welcome)); ?></p>

        <?php if ($cancelled): ?>
            <div class="flash error">This cohort has been cancelled. Please contact the administrator.</div>
        <?php elseif ($notStarted): ?>
            <div class="flash info">
                This cohort starts on <?php echo e($start->format('l, d M Y')); ?>
                (in <?php echo (int)$today->diff($start)->days; ?> day<?php echo $today->diff($start)->days === 1 ? '' : 's'; ?>).
                Items unlock on their scheduled day.
            </div>
        <?php elseif ($row['enrollment_status'] === 'completed'): ?>
            <div class="flash success">🎉 You have completed every item in this course. Masha'Allah!</div>
        <?php endif; ?>

        <?php if ($next): ?>
            <div class="zblock" style="border-color:var(--gold);background:var(--gold-soft);">
                <div class="zblock-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:14px;">
                    <span style="font-weight:800;color:var(--gold);">▶ Up next</span>
                    <span style="flex:1;min-width:160px;font-weight:700;"><?php echo e($next['title']); ?>
                        <small style="color:var(--muted);font-weight:600;">· <?php echo e(COURSE_ITEM_LABELS[$next['type']]); ?><?php if ($next['type'] !== 'content'): ?> · due <?php echo e($next['due']->format('d M')); ?><?php endif; ?></small>
                    </span>
                    <a class="btn btn-sm btn-primary" href="<?php echo e(courseItemLink($next['type'], $next['item_id'])); ?>"><?php echo $next['type'] === 'content' ? 'Open lesson' : 'Start'; ?> →</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$cards): ?>
            <p class="empty">The schedule for this course hasn't been published yet.</p>
        <?php endif; ?>

        <?php foreach ($cards as $i => $c):
            $meta = $c['meta'];
            $desc = $meta ? trim((string)($meta['description'] ?? '')) : 'Other lessons, quizzes and exams of this course.';
            if (mb_strlen($desc) > 220) {
                $desc = mb_substr($desc, 0, 220) . '…';
            }
            $img = $meta ? trim((string)($meta['image_path'] ?? '')) : '';
            $teacher = $meta ? trim((string)($meta['sheikh_name'] ?? '')) : '';
        ?>
            <article class="subj-card sc-<?php echo $i % 6; ?>">
                <div class="subj-main">
                    <div class="subj-name">
                        <?php if ($meta && !empty($meta['slug'])): ?>
                            <a href="subject.php?slug=<?php echo e(urlencode((string)$meta['slug'])); ?>"><?php echo e($c['name']); ?></a>
                        <?php else: ?>
                            <?php echo e($c['name']); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($teacher !== ''): ?><div class="subj-teacher">🎙️ <?php echo e($teacher); ?></div><?php endif; ?>
                    <?php if ($desc !== ''): ?><p class="subj-desc"><?php echo e($desc); ?></p><?php endif; ?>
                    <div class="subj-progress">
                        <div class="bar"><div style="width: <?php echo (int)$c['pct']; ?>%"></div></div>
                        <div class="meta">
                            <span><?php echo (int)$c['done']; ?> of <?php echo (int)$c['total']; ?> completed</span>
                            <?php if ($c['avg'] !== null): ?><span>Average <?php echo (int)$c['avg']; ?>%</span><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($c['next']): ?>
                        <div class="subj-next">Next: <a href="<?php echo e(courseItemLink($c['next']['type'], $c['next']['item_id'])); ?>"><?php echo e($c['next']['title']); ?> →</a></div>
                    <?php endif; ?>
                </div>
                <?php if ($img !== ''): ?>
                    <img class="subj-thumb" src="<?php echo e($img); ?>" alt="" loading="lazy">
                <?php else: ?>
                    <div class="subj-thumb" aria-hidden="true">📚</div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if ($groups): ?>
        <details class="card daylist">
            <summary>Day-by-day list</summary>
            <div style="display:grid;gap:14px;">
                <?php foreach ($groups as $day => $dayItems):
                    $dayDate = courseDayDate($startDate, (int)$day);
                    $isToday = $dayDate == $today;
                ?>
                    <div class="day-group">
                        <div class="day-header">
                            <span>Day <?php echo (int)$day; ?> <?php if ($isToday): ?><span class="badge brand">Today</span><?php endif; ?></span>
                            <small><?php echo e($dayDate->format('D, d M Y')); ?></small>
                        </div>
                        <?php foreach ($dayItems as $it):
                            $state = $it['state'];
                            $icon = $state === 'done' ? '✅' : ($state === 'open' ? '🔓' : ($state === 'missed' ? '⛔' : '🔒'));
                        ?>
                            <div class="item-row <?php echo e($state); ?>">
                                <div class="item-icon" aria-hidden="true"><?php echo $icon; ?></div>
                                <div class="item-main">
                                    <div class="item-title"><?php echo e($it['title']); ?></div>
                                    <div class="item-sub">
                                        <span class="badge <?php echo $it['type'] === 'content' ? 'brand' : ($it['type'] === 'quiz' ? 'gold' : 'accent'); ?>"><?php echo e(COURSE_ITEM_LABELS[$it['type']]); ?></span>
                                        <?php if ($state === 'done'): ?>· Completed
                                        <?php elseif ($state === 'locked'): ?>· <?php echo $cancelled ? 'Unavailable' : 'Opens ' . e($it['unlock']->format('D, d M')); ?>
                                        <?php elseif ($state === 'missed'): ?>· Missed — closed <?php echo e($it['due']->format('d M Y')); ?>
                                        <?php elseif ($it['type'] !== 'content'): ?>· Due <?php echo e($it['due']->format('d M Y')); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($state === 'open'): ?>
                                    <a class="btn btn-sm btn-primary" href="<?php echo e(courseItemLink($it['type'], $it['item_id'])); ?>"><?php echo $it['type'] === 'content' ? 'Open' : 'Start'; ?></a>
                                <?php elseif ($state === 'done'): ?>
                                    <a class="btn btn-sm" href="<?php echo e(courseItemLink($it['type'], $it['item_id'])); ?>">Review</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </section>

    <!-- ===================== RIGHT ===================== -->
    <aside class="zad-right" aria-label="This week">
        <div class="zblock">
            <div class="zblock-head"><span class="zblock-icon">🗓️</span> This week · <?php echo $currentWeek; ?>/<?php echo $totalWeeks; ?></div>
            <?php if (!$weekDays): ?>
                <div class="zblock-body" style="color:var(--muted);">Nothing is scheduled this week.</div>
            <?php endif; ?>
            <?php foreach ($weekDays as $day => $list):
                $d = courseDayDate($startDate, (int)$day);
            ?>
                <div class="week-day"><?php echo e($d->format('l')); ?> · <?php echo e($d->format('d M')); ?><?php if ($d == $today): ?> · Today<?php endif; ?></div>
                <?php foreach ($list as $it):
                    $linked = $it['state'] === 'open' || $it['state'] === 'done';
                ?>
                    <div class="week-row">
                        <span class="when">Day <?php echo (int)$day; ?></span>
                        <span class="what">
                            <?php if ($linked): ?><a href="<?php echo e(courseItemLink($it['type'], $it['item_id'])); ?>"><?php endif; ?>
                            <?php echo e($it['subject_name']); ?>
                            <?php if ($linked): ?></a><?php endif; ?>
                            <small><?php echo $it['type'] === 'content' ? '📘' : ($it['type'] === 'quiz' ? '📝' : '📅'); ?> <?php echo e($it['title']); ?><?php echo $it['state'] === 'done' ? ' ✅' : ''; ?></small>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <div class="week-foot"><a href="schedule.php?cohort_id=<?php echo (int)$cohortId; ?>" style="color:var(--brand);font-weight:800;">Full weekly schedule →</a></div>
        </div>

        <div class="zblock">
            <div class="zblock-head"><span class="zblock-icon">💬</span> Need help?</div>
            <div class="zblock-body">
                <p style="color:var(--text-secondary);">Stuck on a lesson, an exam or your account? Our team is here to help.</p>
                <a class="zbtn blue" href="help_center.php">Visit the help center</a>
            </div>
        </div>
    </aside>
</div>
<?php
courseShellEnd();