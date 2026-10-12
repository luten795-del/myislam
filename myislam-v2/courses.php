<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = $ctx['userId'];

$today = new DateTimeImmutable('today');
$todayStr = $today->format('Y-m-d');
$tablesReady = courseTablesExist($conn);

// ============================================================================
// POST: enroll / leave
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!courseCsrfValid()) {
        http_response_code(419);
        exit('Invalid or expired request token. Go back, refresh the page and try again.');
    }
    if (!$tablesReady) {
        courseFlash('error', 'Courses are not available yet.');
        courseRedirect('courses.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $cohortId = (int)($_POST['cohort_id'] ?? 0);
    $redirect = 'courses.php';

    try {
        if ($action === 'enroll' && $cohortId > 0) {
            $conn->beginTransaction();

            // Lock the cohort row so two students can't take the last seat together
            $stmt = $conn->prepare("
                SELECT h.*, c.duration_days, c.status AS course_status, c.id AS the_course_id
                FROM course_cohorts h
                JOIN courses c ON c.id = h.course_id
                WHERE h.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$cohortId]);
            $cohort = $stmt->fetch();

            $error = null;
            if (!$cohort || $cohort['status'] !== 'published' || $cohort['course_status'] !== 'published') {
                $error = 'This cohort is not open for enrollment.';
            } elseif ($todayStr > $cohort['enrollment_deadline']) {
                $error = 'Enrollment for this cohort has closed.';
            } elseif ($today > courseCohortEnd($cohort['start_date'], (int)$cohort['duration_days'])) {
                $error = 'This cohort has already finished.';
            }

            $existing = null;
            if ($error === null) {
                $stmt = $conn->prepare("SELECT id, status FROM course_enrollments WHERE cohort_id = ? AND user_id = ?");
                $stmt->execute([$cohortId, $userId]);
                $existing = $stmt->fetch() ?: null;
                if ($existing && $existing['status'] !== 'dropped') {
                    $error = 'You are already enrolled in this cohort.';
                    $redirect = 'course.php?cohort_id=' . $cohortId;
                }
            }

            if ($error === null) {
                // One active run of the same course at a time
                $stmt = $conn->prepare("
                    SELECT COUNT(*) FROM course_enrollments en
                    JOIN course_cohorts h ON h.id = en.cohort_id
                    WHERE en.user_id = ? AND en.status = 'active' AND h.course_id = ? AND h.id <> ?
                ");
                $stmt->execute([$userId, (int)$cohort['the_course_id'], $cohortId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $error = 'You are already enrolled in another cohort of this course.';
                }
            }

            if ($error === null && $cohort['capacity'] !== null) {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM course_enrollments WHERE cohort_id = ? AND status <> 'dropped'");
                $stmt->execute([$cohortId]);
                if ((int)$stmt->fetchColumn() >= (int)$cohort['capacity']) {
                    $error = 'Sorry, this cohort is full.';
                }
            }

            if ($error !== null) {
                $conn->rollBack();
                courseFlash('error', $error);
            } else {
                if ($existing) {
                    $stmt = $conn->prepare("UPDATE course_enrollments SET status = 'active', enrolled_at = NOW(), completed_at = NULL WHERE id = ?");
                    $stmt->execute([(int)$existing['id']]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO course_enrollments (cohort_id, user_id, status) VALUES (?, ?, 'active')");
                    $stmt->execute([$cohortId, $userId]);
                }
                $conn->commit();
                courseFlash('success', 'You are enrolled! Your lessons will open on schedule.');
                $redirect = 'course.php?cohort_id=' . $cohortId;
            }
        } elseif ($action === 'leave' && $cohortId > 0) {
            $stmt = $conn->prepare("UPDATE course_enrollments SET status = 'dropped' WHERE cohort_id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$cohortId, $userId]);
            courseFlash('info', 'You have left the course. You can re-join while enrollment is still open.');
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('courses.php error: ' . $e->getMessage());
        courseFlash('error', 'Something went wrong. Please try again.');
    }

    courseRedirect($redirect);
}

// ============================================================================
// DATA
// ============================================================================

$myCourses = [];
$available = [];

if ($tablesReady) {
    try {
        // My enrollments (not dropped, cohort visible to students)
        $stmt = $conn->prepare("
            SELECT en.status AS enrollment_status,
                   h.id AS cohort_id, h.name AS cohort_name, h.start_date, h.enrollment_deadline, h.status AS cohort_status,
                   c.id AS course_id, c.title, c.description, c.duration_days
            FROM course_enrollments en
            JOIN course_cohorts h ON h.id = en.cohort_id
            JOIN courses c ON c.id = h.course_id
            WHERE en.user_id = ? AND en.status <> 'dropped' AND h.status <> 'draft'
            ORDER BY h.start_date DESC
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $items = courseLoadItems($conn, (int)$row['course_id'], $userId);
            $row['progress'] = courseProgressSummary($items);
            $myCourses[] = $row;
        }

        // Cohorts I can still join
        $stmt = $conn->prepare("
            SELECT h.id AS cohort_id, h.name AS cohort_name, h.start_date, h.enrollment_deadline, h.capacity,
                   c.id AS course_id, c.title, c.description, c.duration_days,
                   (SELECT COUNT(*) FROM course_enrollments en WHERE en.cohort_id = h.id AND en.status <> 'dropped') AS enrolled_count,
                   (SELECT en2.status FROM course_enrollments en2 WHERE en2.cohort_id = h.id AND en2.user_id = ? LIMIT 1) AS my_status
            FROM course_cohorts h
            JOIN courses c ON c.id = h.course_id
            WHERE h.status = 'published' AND c.status = 'published'
              AND h.enrollment_deadline >= ?
              AND DATE_ADD(h.start_date, INTERVAL (c.duration_days - 1) DAY) >= ?
            ORDER BY h.start_date ASC
        ");
        $stmt->execute([$userId, $todayStr, $todayStr]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['my_status'] === null || $row['my_status'] === 'dropped') {
                $available[] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log('courses.php data error: ' . $e->getMessage());
    }
}

$flash = courseTakeFlash();

// ============================================================================
// SIDEBAR DATA (built from the data above - no extra queries)
// ============================================================================

// Left "Schedule": the next dates from the courses I'm following
$scheduleEvents = [];
foreach ($myCourses as $m) {
    $mStart = new DateTimeImmutable($m['start_date']);
    $mEnd = courseCohortEnd($m['start_date'], (int)$m['duration_days']);
    $scheduleEvents[] = ['date' => $mStart, 'title' => $m['title'], 'what' => 'Starts'];
    $scheduleEvents[] = ['date' => $mEnd, 'title' => $m['title'], 'what' => 'Ends'];
}
$scheduleEvents = array_values(array_filter($scheduleEvents, static fn(array $ev): bool => $ev['date'] >= $today));
usort($scheduleEvents, static fn(array $a, array $b): int => $a['date'] <=> $b['date']);
$scheduleEvents = array_slice($scheduleEvents, 0, 6);

// Right "Enrollment deadlines": soonest first
$deadlines = $available;
usort($deadlines, static fn(array $a, array $b): int => strcmp((string)$a['enrollment_deadline'], (string)$b['enrollment_deadline']));
$deadlines = array_slice($deadlines, 0, 6);

// Small gradient tiles used as course "thumbnails"
$tileGradients = [
    'linear-gradient(135deg,#0E4A34,#3E7A5E)',
    'linear-gradient(135deg,#1a9aaf,#0e6d80)',
    'linear-gradient(135deg,#9C7A2E,#c9a85e)',
    'linear-gradient(135deg,#3E6E91,#6FA0C4)',
    'linear-gradient(135deg,#6C5CC0,#9285DB)',
];
$tileIcons = ['📖', '🕌', '📚', '🌙', '✨'];

$quickLinks = [
    ['subjects.php', '📚', 'Subjects'],
    ['quizzes.php', '📝', 'Quizzes'],
    ['monthly_exams.php', '📅', 'Monthly exams'],
    ['leaderboard.php', '🏆', 'Leaderboard'],
    ['adhkaar.php', '📿', 'Adhkaar'],
    ['multimedia.php', '🖼️', 'Multimedia'],
];

// ============================================================================
// PAGE
// ============================================================================

courseShellStart($ctx, 'Courses');
?>
<style>
/* Scoped to .zc so nothing here can leak into the rest of the site.
   Every colour falls back to a sensible value if a theme variable is missing. */
.zc{--zc-brand:var(--brand,#0E4A34);--zc-accent:#1a9aaf;--zc-orange:#e8731a;--zc-surface:var(--surface,#fff);--zc-surface2:var(--surface-2,#f6f7f6);--zc-line:var(--line,#dde2df);--zc-text:var(--text,#1f2937);--zc-muted:var(--muted,#6b7280);}
.zc *{box-sizing:border-box}
.zc-layout{display:grid;grid-template-columns:250px minmax(0,1fr) 270px;gap:14px;align-items:start;max-width:1280px;margin:0 auto}
.zc-main{min-width:0}

.zc-panel{background:var(--zc-surface);border:1px solid var(--zc-line);border-radius:4px;margin-bottom:14px;overflow:hidden}
.zc-panel-head{display:flex;align-items:center;gap:8px;padding:9px 12px;background:var(--zc-surface2);border-bottom:1px solid var(--zc-line);font-size:12.5px;font-weight:700;color:var(--zc-text)}
.zc-ico{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:3px;background:var(--zc-orange);color:#fff;font-size:11px;flex-shrink:0}
.zc-panel-body{padding:10px 12px;font-size:12.5px;color:var(--zc-text)}
.zc-muted{color:var(--zc-muted)}
.zc-sub{font-size:11px;font-weight:700;color:var(--zc-orange);margin-bottom:6px}

.zc-list{list-style:none;margin:0;padding:0}
.zc-list li{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid var(--zc-line);font-size:12px}
.zc-list li:last-child{border-bottom:0}
.zc-list .t{color:var(--zc-accent);font-weight:600;min-width:0}
.zc-list .v{color:var(--zc-orange);font-weight:700;white-space:nowrap}
.zc-date{display:flex;gap:10px;align-items:center;padding:7px 0;border-bottom:1px solid var(--zc-line)}
.zc-date:last-child{border-bottom:0}
.zc-date .d{flex-shrink:0;width:44px;text-align:center;border:1px solid var(--zc-line);border-radius:4px;overflow:hidden;line-height:1.1}
.zc-date .d b{display:block;font-size:15px;padding:3px 0 1px}
.zc-date .d i{display:block;font-style:normal;font-size:9.5px;font-weight:700;text-transform:uppercase;background:var(--zc-brand);color:#fff;padding:2px 0}
.zc-date .w{font-size:12px;min-width:0}
.zc-date .w span{display:block;color:var(--zc-muted);font-size:11px}

.zc-links a{display:flex;align-items:center;gap:9px;padding:8px 4px;border-bottom:1px solid var(--zc-line);font-size:12.5px;color:var(--zc-text);text-decoration:none}
.zc-links a:last-child{border-bottom:0}
.zc-links a:hover{color:var(--zc-accent)}
.zc-btn-wide{display:block;text-align:center;padding:9px 10px;margin-top:10px;border-radius:3px;background:var(--zc-accent);color:#fff;font-weight:700;font-size:12.5px;text-decoration:none}
.zc-btn-wide.green{background:#7ab929}
.zc-btn-wide:hover{filter:brightness(1.07)}

/* hero */
.zc-hero{position:relative;min-height:150px;border-radius:4px;overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:26px 20px;color:#fff;margin-bottom:12px;background:linear-gradient(rgba(10,40,30,.72),rgba(10,40,30,.72)),url('/uploads/subjects-page-hero.png') center/cover no-repeat,#0E4A34}
.zc-hero h1{margin:0 0 6px;font-size:24px;font-weight:700;letter-spacing:.2px;color:#fff}
.zc-hero p{margin:0;font-size:13px;opacity:.92;max-width:520px}
.zc-intro{text-align:center;font-size:13px;line-height:1.7;color:var(--zc-text);padding:0 6px 14px;margin:0}

.zc-flash{margin-bottom:12px}
.zc-heading{margin:16px 2px 10px;padding-bottom:6px;border-bottom:2px solid var(--zc-accent);font-size:15px;color:var(--zc-text);display:flex;justify-content:space-between;align-items:center;gap:8px}
.zc-heading small{font-size:11px;font-weight:600;color:var(--zc-muted)}

/* course rows (like the subject rows in the reference) */
.zc-row{display:grid;grid-template-columns:minmax(0,1fr) 88px;gap:14px;padding:14px 14px 14px 16px;margin-bottom:10px;background:var(--zc-surface);border:1px solid var(--zc-line);border-left:4px solid var(--zc-accent);border-radius:4px}
.zc-row:hover{box-shadow:0 2px 10px rgba(0,0,0,.07)}
.zc-row-top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
.zc-title{margin:0;font-size:16px;font-weight:700;color:var(--zc-accent)}
.zc-title a{color:inherit;text-decoration:none}
.zc-title a:hover{text-decoration:underline}
.zc-desc{margin:6px 0 8px;font-size:12.5px;line-height:1.6;color:var(--zc-muted)}
.zc-meta{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:11.5px;color:var(--zc-muted);margin:6px 0 8px}
.zc-bar{height:7px;border-radius:99px;background:var(--zc-line);overflow:hidden}
.zc-bar i{display:block;height:100%;background:var(--zc-brand);border-radius:99px}
.zc-bar-meta{display:flex;justify-content:space-between;font-size:11px;color:var(--zc-muted);margin-top:3px}
.zc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}
.zc-actions form{margin:0}
.zc-thumb{align-self:start;width:88px;height:64px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:28px;color:#fff;box-shadow:0 2px 6px rgba(0,0,0,.18)}
.zc-empty{padding:14px;text-align:center;font-size:13px;color:var(--zc-muted);background:var(--zc-surface);border:1px dashed var(--zc-line);border-radius:4px;margin-bottom:10px}
.zc-tag{display:inline-block;font-size:10.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:var(--zc-surface2);border:1px solid var(--zc-line);color:var(--zc-muted);white-space:nowrap}

@media (max-width:1100px){
  .zc-layout{grid-template-columns:minmax(0,1fr)}
  .zc-main{order:1}.zc-left{order:2}.zc-right{order:3}
}
@media (max-width:520px){
  .zc-row{grid-template-columns:minmax(0,1fr)}
  .zc-thumb{display:none}
  .zc-hero h1{font-size:20px}
}
</style>

<div class="zc">
<div class="zc-layout">

    <!-- ============ LEFT COLUMN ============ -->
    <aside class="zc-left">
        <div class="zc-panel">
            <div class="zc-panel-head"><span class="zc-ico">📅</span> Schedule</div>
            <div class="zc-panel-body">
                <?php if (!$scheduleEvents): ?>
                    <span class="zc-muted">No upcoming dates yet.</span>
                <?php else: foreach ($scheduleEvents as $ev): ?>
                    <div class="zc-date">
                        <div class="d"><b><?php echo e($ev['date']->format('d')); ?></b><i><?php echo e($ev['date']->format('M')); ?></i></div>
                        <div class="w"><?php echo e($ev['title']); ?><span><?php echo e($ev['what']); ?></span></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="zc-panel">
            <div class="zc-panel-head"><span class="zc-ico">★</span> Progress report</div>
            <div class="zc-panel-body">
                <div class="zc-sub">Current progress</div>
                <?php if (!$myCourses): ?>
                    <span class="zc-muted">Enroll in a course to see your progress here.</span>
                <?php else: ?>
                    <ul class="zc-list">
                        <?php foreach ($myCourses as $m): $pp = $m['progress']; ?>
                            <li>
                                <span class="t"><?php echo e($m['title']); ?></span>
                                <span class="v"><?php echo (int)$pp['done']; ?> / <?php echo (int)$pp['total']; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="zc-panel">
            <div class="zc-panel-head"><span class="zc-ico">🎓</span> Certificates</div>
            <div class="zc-panel-body">
                <p class="zc-muted" style="margin:0 0 4px;">Finished a course or an exam? Your certificates are kept together in one place.</p>
                <a class="zc-btn-wide green" href="my_certificates.php">My certificates</a>
            </div>
        </div>
    </aside>

    <!-- ============ CENTER COLUMN ============ -->
    <div class="zc-main">
        <section class="zc-hero">
            <h1>Courses</h1>
            <p>We welcome you to this great opportunity to learn and share knowledge.</p>
        </section>
        <p class="zc-intro">Follow a guided course with a fixed schedule, together with a cohort of students. You can still study any subject freely from the dashboard at any time.</p>

        <?php if ($flash): ?>
            <div class="flash zc-flash <?php echo e($flash[0]); ?>" role="status"><?php echo e($flash[1]); ?></div>
        <?php endif; ?>

        <h2 class="zc-heading">My courses <small><?php echo count($myCourses); ?></small></h2>
        <?php if (!$myCourses): ?>
            <div class="zc-empty">You haven't enrolled in any course yet. Pick one below to get started.</div>
        <?php else: foreach ($myCourses as $i => $m):
            $duration = (int)$m['duration_days'];
            $end = courseCohortEnd($m['start_date'], $duration);
            [$phaseLabel, $phaseClass] = courseCohortPhase(
                ['status' => $m['cohort_status'], 'start_date' => $m['start_date'], 'enrollment_deadline' => $m['enrollment_deadline']],
                $duration,
                $today
            );
            $p = $m['progress'];
            $canLeave = $m['enrollment_status'] === 'active' && $m['cohort_status'] === 'published' && $today <= $end;
            $desc = trim((string)($m['description'] ?? ''));
        ?>
            <article class="zc-row">
                <div>
                    <div class="zc-row-top">
                        <h3 class="zc-title"><a href="course.php?cohort_id=<?php echo (int)$m['cohort_id']; ?>"><?php echo e($m['title']); ?></a></h3>
                        <span class="badge <?php echo e($phaseClass); ?>"><?php echo e($phaseLabel); ?></span>
                    </div>
                    <?php if ($desc !== ''): ?>
                        <p class="zc-desc"><?php echo e(mb_strlen($desc) > 160 ? mb_substr($desc, 0, 160) . '…' : $desc); ?></p>
                    <?php endif; ?>
                    <div class="zc-meta">
                        <span>👥 <?php echo e($m['cohort_name']); ?></span>
                        <span>📅 <?php echo e((new DateTimeImmutable($m['start_date']))->format('d M Y')); ?> → <?php echo e($end->format('d M Y')); ?></span>
                    </div>
                    <div class="zc-bar"><i style="width: <?php echo (int)$p['pct']; ?>%"></i></div>
                    <div class="zc-bar-meta"><span><?php echo (int)$p['done']; ?> of <?php echo (int)$p['total']; ?> completed</span><span><?php echo (int)$p['pct']; ?>%</span></div>
                    <div class="zc-actions">
                        <a class="btn btn-primary" href="course.php?cohort_id=<?php echo (int)$m['cohort_id']; ?>">Open course →</a>
                        <?php if ($canLeave): ?>
                            <form method="post" data-confirm="Leave this course? You can re-join only while enrollment is still open.">
                                <?php echo courseCsrfField(); ?>
                                <input type="hidden" name="action" value="leave">
                                <input type="hidden" name="cohort_id" value="<?php echo (int)$m['cohort_id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Leave</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="zc-thumb" style="background: <?php echo $tileGradients[$i % count($tileGradients)]; ?>" aria-hidden="true"><?php echo $tileIcons[$i % count($tileIcons)]; ?></div>
            </article>
        <?php endforeach; endif; ?>

        <h2 class="zc-heading">Open for enrollment <small><?php echo count($available); ?></small></h2>
        <?php if (!$tablesReady): ?>
            <div class="zc-empty">Courses are not available yet. Please check back soon.</div>
        <?php elseif (!$available): ?>
            <div class="zc-empty">No course is open for enrollment right now. New cohorts will appear here.</div>
        <?php else: foreach ($available as $i => $a):
            $duration = (int)$a['duration_days'];
            $start = new DateTimeImmutable($a['start_date']);
            $end = courseCohortEnd($a['start_date'], $duration);
            $deadline = new DateTimeImmutable($a['enrollment_deadline']);
            $hasCapacity = $a['capacity'] !== null;
            $seatsLeft = $hasCapacity ? max(0, (int)$a['capacity'] - (int)$a['enrolled_count']) : null;
            $full = $hasCapacity && $seatsLeft === 0;
            $started = $today >= $start;
            $desc = trim((string)($a['description'] ?? ''));
        ?>
            <article class="zc-row">
                <div>
                    <div class="zc-row-top">
                        <h3 class="zc-title"><?php echo e($a['title']); ?></h3>
                        <span class="badge <?php echo $started ? 'accent' : 'brand'; ?>"><?php echo $started ? 'Started' : 'Starts ' . e($start->format('d M')); ?></span>
                    </div>
                    <?php if ($desc !== ''): ?>
                        <p class="zc-desc"><?php echo e(mb_strlen($desc) > 180 ? mb_substr($desc, 0, 180) . '…' : $desc); ?></p>
                    <?php endif; ?>
                    <div class="zc-meta">
                        <span>👥 <?php echo e($a['cohort_name']); ?></span>
                        <span>📅 <?php echo e($start->format('d M Y')); ?> → <?php echo e($end->format('d M Y')); ?></span>
                        <span>⏳ <?php echo $duration; ?> days</span>
                        <span>🗓️ Enroll until <?php echo e($deadline->format('d M Y')); ?></span>
                        <?php if ($hasCapacity): ?>
                            <span>💺 <?php echo $full ? 'Full' : $seatsLeft . ' seats left'; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="zc-actions">
                        <?php if ($full): ?>
                            <button class="btn" type="button" disabled>Cohort is full</button>
                        <?php else: ?>
                            <form method="post">
                                <?php echo courseCsrfField(); ?>
                                <input type="hidden" name="action" value="enroll">
                                <input type="hidden" name="cohort_id" value="<?php echo (int)$a['cohort_id']; ?>">
                                <button class="btn btn-primary" type="submit"><?php echo $a['my_status'] === 'dropped' ? 'Re-join' : 'Enroll'; ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="zc-thumb" style="background: <?php echo $tileGradients[($i + 2) % count($tileGradients)]; ?>" aria-hidden="true"><?php echo $tileIcons[($i + 2) % count($tileIcons)]; ?></div>
            </article>
        <?php endforeach; endif; ?>
    </div>

    <!-- ============ RIGHT COLUMN ============ -->
    <aside class="zc-right">
        <div class="zc-panel">
            <div class="zc-panel-head"><span class="zc-ico">🔗</span> Quick access</div>
            <div class="zc-panel-body zc-links">
                <?php foreach ($quickLinks as [$href, $icon, $label]): ?>
                    <a href="<?php echo e($href); ?>"><span><?php echo $icon; ?></span> <?php echo e($label); ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="zc-panel">
            <div class="zc-panel-head"><span class="zc-ico">🗓️</span> Enrollment deadlines</div>
            <div class="zc-panel-body">
                <?php if (!$deadlines): ?>
                    <span class="zc-muted">Nothing open right now.</span>
                <?php else: ?>
                    <ul class="zc-list">
                        <?php foreach ($deadlines as $d): ?>
                            <li>
                                <span class="t"><?php echo e($d['title']); ?></span>
                                <span class="v"><?php echo e((new DateTimeImmutable($d['enrollment_deadline']))->format('d M')); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="zc-muted" style="margin:8px 0 0;font-size:11px;">All dates are shown in the server's time zone.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="zc-panel">
            <div class="zc-panel-head"><span class="zc-ico">📖</span> Study freely</div>
            <div class="zc-panel-body">
                <p class="zc-muted" style="margin:0;">Not ready to join a cohort? Every subject and lesson is still open to you any time.</p>
                <a class="zc-btn-wide" href="subjects.php">Browse subjects</a>
            </div>
        </div>
    </aside>

</div>
</div>
<?php
courseShellEnd();