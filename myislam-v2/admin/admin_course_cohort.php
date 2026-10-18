<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/course_helpers.php';

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ============================================================================
// AUTH (admin only — same rule as admin_courses.php)
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

if (!courseTablesExist($conn)) {
    header('Location: admin_courses.php');
    exit;
}

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
// MODE: per-student view OR legacy cohort view
// ============================================================================

$enrollmentId = (int)($_GET['enrollment_id'] ?? 0);
$cohortId     = (int)($_GET['cohort_id'] ?? $_POST['cohort_id'] ?? 0);

// ---------------------------------------------------------
// New mode: view a single student's schedule
// ---------------------------------------------------------
if ($enrollmentId > 0) {
    $stmt = $conn->prepare("
        SELECT en.id              AS enrollment_id,
               en.status          AS enrollment_status,
               en.started_at,
               en.current_semester,
               en.enrolled_at,
               en.completed_at,
               c.id               AS course_id,
               c.title            AS course_title,
               c.duration_days,
               u.id               AS user_id,
               u.username,
               u.email,
               u.full_name
        FROM course_enrollments en
        JOIN courses c ON c.id = en.course_id
        JOIN users   u ON u.id = en.user_id
        WHERE en.id = ?
    ");
    $stmt->execute([$enrollmentId]);
    $enrollment = $stmt->fetch();
    if (!$enrollment) {
        flash('error', 'Enrollment not found.');
        redirectTo('admin_courses.php');
    }

    $courseId  = (int)$enrollment['course_id'];
    $studentId = (int)$enrollment['user_id'];
    $duration  = (int)$enrollment['duration_days'];
    $start     = new DateTimeImmutable((string)$enrollment['started_at']);
    $end       = courseCohortEnd((string)$enrollment['started_at'], $duration);
    $today     = new DateTimeImmutable('today');
    $startDate = (string)$enrollment['started_at'];

    // Which week?
    $totalWeeks = max(1, (int)ceil($duration / 7));
    if ($today < $start) {
        $currentWeek = 1;
    } elseif ($today > $end) {
        $currentWeek = $totalWeeks;
    } else {
        $currentWeek = (int)floor($start->diff($today)->days / 7) + 1;
    }
    $week = isset($_GET['week']) ? (int)$_GET['week'] : $currentWeek;
    $week = max(1, min($totalWeeks, $week));
    $firstDay = ($week - 1) * 7 + 1;
    $lastDay  = min($duration, $week * 7);

    if ($totalWeeks <= 12) {
        $pages = range(1, $totalWeeks);
    } else {
        $pages = array_unique(array_merge([1], range(max(1, $week - 2), min($totalWeeks, $week + 2)), [$totalWeeks]));
        sort($pages);
    }

    // Schedule items (as this student sees them)
    $data = courseLoadScheduleItems($conn, $courseId, $studentId);
    $items = $data['items'];
    $subjects = $data['subjects'];
    $summary = courseProgressSummary($items);

    // Group items by day+subject and add state
    $byDay = [];
    foreach ($items as $it) {
        $info = courseItemState($it, $startDate, $duration, $today);
        $it['state']  = $info['state'];
        $it['unlock'] = $info['unlock'];
        $it['due']    = $info['due'];
        $byDay[$it['unlock_day']][$it['subject_id']][] = $it;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    // Render the per-student page and exit
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e($enrollment['username']); ?> — Student schedule — MyIslam Admin</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            :root {
                --bg: #f5f7f5; --surface: #ffffff; --surface-2: #f9fafb; --surface-3: #eef2f7;
                --line: #e2e5e2; --text: #1f2937; --text-secondary: #4b5563; --muted: #6b7280;
                --brand: #0E4A34; --brand-light: #3E7A5E; --brand-soft: rgba(14, 74, 52, 0.08);
                --gold: #9C7A2E; --gold-soft: rgba(156, 122, 46, 0.12);
                --accent: #3E6E91; --accent-soft: rgba(62, 110, 145, 0.12);
                --danger: #b91c1c; --danger-soft: rgba(185, 28, 28, 0.08); --white: #ffffff;
                --radius-sm: 12px; --radius-md: 16px; --radius-lg: 20px;
            }
            body.dark-mode {
                --bg: #0f1720; --surface: #18212b; --surface-2: #1d2833; --surface-3: #22303c;
                --line: #304150; --text: #edf2f7; --text-secondary: #cbd5e1; --muted: #94a3b8;
                --brand: #4C9172; --brand-light: #7fcaa4; --brand-soft: rgba(76, 145, 114, 0.16);
                --gold: #C9A85E; --gold-soft: rgba(201, 168, 94, 0.16);
                --accent: #6FA0C4; --accent-soft: rgba(111, 160, 196, 0.16);
                --danger: #ef4444; --danger-soft: rgba(239, 68, 68, 0.12);
            }
            body { font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; padding: 24px; min-height: 100vh; }
            a { color: inherit; text-decoration: none; }
            button { cursor: pointer; border: none; background: none; font: inherit; color: inherit; }
            .container { max-width: 1280px; margin: 0 auto; display: grid; gap: 20px; }

            .page-header { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding-bottom: 16px; border-bottom: 1px solid var(--line); }
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
            .btn-sm { padding: 5px 11px; font-size: 12px; }

            .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
            .badge.brand { background: var(--brand-soft); color: var(--brand); }
            .badge.gold { background: var(--gold-soft); color: var(--gold); }
            .badge.accent { background: var(--accent-soft); color: var(--accent); }
            .badge.danger { background: var(--danger-soft); color: var(--danger); }
            .badge.muted { background: var(--surface-3); color: var(--muted); }

            .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
            .stat { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--gold); border-radius: var(--radius-md); padding: 12px 14px; }
            .stat span { display: block; font-size: 12px; color: var(--muted); }
            .stat strong { font-size: 19px; }
            .stat small { display: block; color: var(--gold); font-size: 12px; font-weight: 600; }

            .progress-track { height: 12px; border-radius: 999px; background: var(--surface-3); overflow: hidden; }
            .progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--brand-light), var(--brand)); }

            .pager { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 14px; }
            .pager a, .pager span.gap { min-width: 34px; height: 34px; padding: 0 8px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--line); background: var(--surface); font-size: 13px; font-weight: 700; color: var(--text-secondary); }
            .pager a:hover { border-color: var(--brand); color: var(--brand); }
            .pager a.active { background: var(--brand); border-color: var(--brand); color: var(--white); }
            .pager span.gap { border: none; background: none; }

            .sch-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius-md); }
            table.sch-table { width: 100%; border-collapse: collapse; min-width: 760px; }
            .sch-table th { background: var(--brand); color: var(--white); padding: 12px 10px; font-size: 13px; text-align: center; border-left: 1px solid rgba(255,255,255,.15); }
            body.dark-mode .sch-table th { color: #0f1720; }
            .sch-table th.sch-date-h { text-align: left; width: 170px; }
            .sch-table td { border-top: 1px solid var(--line); border-left: 1px solid var(--line); padding: 10px; vertical-align: top; background: var(--surface); }
            .sch-table td:first-child { border-left: none; }
            .sch-table tr.is-today td { background: var(--gold-soft); }
            .sch-table td.sch-date { background: var(--surface-2); }
            .sch-table tr.is-today td.sch-date { background: var(--gold-soft); }
            .sch-day { font-weight: 800; font-size: 15px; }
            .sch-day small { font-weight: 600; color: var(--muted); margin-left: 4px; }
            .sch-greg { font-size: 13px; color: var(--text-secondary); }
            .sch-hijri { font-size: 12px; color: var(--gold); font-weight: 600; }
            .chip { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--brand); border-radius: 10px; padding: 8px 10px; margin-bottom: 8px; font-size: 13px; }
            .chip:last-child { margin-bottom: 0; }
            .chip.is-exam { border-left-color: var(--gold); }
            .chip.is-done { border-left-color: var(--brand); opacity: .75; }
            .chip.is-locked { border-left-color: var(--muted); opacity: .6; }
            .chip.is-missed { border-left-color: var(--danger); opacity: .7; }
            .chip-title { font-weight: 700; overflow-wrap: anywhere; }
            .chip-meta { margin-top: 3px; font-size: 11px; color: var(--muted); font-weight: 600; }

            .empty { text-align: center; color: var(--muted); padding: 26px 12px; }

            @media (max-width: 640px) { body { padding: 14px; } }
        </style>
    </head>
    <body>
    <div class="container">

        <div class="page-header">
            <a href="admin_courses.php?course_id=<?php echo $courseId; ?>" class="back-link">← <?php echo e($enrollment['course_title']); ?></a>
            <h1 class="page-title">
                👤 <?php echo e((string)($enrollment['full_name'] ?: $enrollment['username'])); ?>
                <small><?php echo e($enrollment['email']); ?> · <?php echo e($enrollment['course_title']); ?></small>
            </h1>
            <button class="icon-btn" id="themeToggle" type="button" aria-label="Toggle dark mode">🌙</button>
        </div>

        <?php if ($flash): ?>
            <div class="flash <?php echo e($flash[0]); ?>" role="status" style="padding:12px 16px;border-radius:12px;font-weight:600;">
                <?php echo e($flash[1]); ?>
            </div>
        <?php endif; ?>

        <section class="card">
            <div class="stats">
                <div class="stat"><span>Status</span><strong><span class="badge <?php echo $enrollment['enrollment_status'] === 'active' ? 'brand' : ($enrollment['enrollment_status'] === 'completed' ? 'accent' : 'muted'); ?>"><?php echo e(ucfirst((string)$enrollment['enrollment_status'])); ?></span></strong></div>
                <div class="stat"><span>Enrolled on</span><strong><?php echo e($start->format('d M Y')); ?></strong><small><?php echo e(courseHijriShort($start)); ?> AH</small></div>
                <div class="stat"><span>Ends on</span><strong><?php echo e($end->format('d M Y')); ?></strong><small><?php echo e(courseHijriShort($end)); ?> AH</small></div>
                <div class="stat"><span>Current semester</span><strong><?php echo (int)$enrollment['current_semester']; ?></strong></div>
                <div class="stat"><span>Progress</span><strong><?php echo (int)$summary['done']; ?> / <?php echo (int)$summary['total']; ?></strong><small><?php echo (int)$summary['pct']; ?>%</small></div>
            </div>
            <div class="progress-track" style="margin-top:14px;">
                <div class="progress-fill" style="width: <?php echo (int)$summary['pct']; ?>%"></div>
            </div>
        </section>

        <section class="card">
            <h2>
                <span>Week <?php echo $week; ?> of <?php echo $totalWeeks; ?> · Days <?php echo $firstDay; ?>–<?php echo $lastDay; ?> — this student's own calendar</span>
                <a class="btn btn-sm" href="admin_courses.php?course_id=<?php echo $courseId; ?>">✎ Edit schedule</a>
            </h2>

            <nav class="pager" aria-label="Weeks">
                <?php $prev = 0; foreach ($pages as $pg):
                    if ($prev && $pg - $prev > 1): ?><span class="gap">…</span><?php endif; ?>
                    <a href="?enrollment_id=<?php echo $enrollmentId; ?>&week=<?php echo (int)$pg; ?>" class="<?php echo $pg === $week ? 'active' : ''; ?>" title="Week <?php echo (int)$pg; ?>"><?php echo (int)$pg; ?></a>
                <?php $prev = $pg; endforeach; ?>
            </nav>

            <?php if (!$items): ?>
                <p class="empty">No published items are scheduled for this course yet.</p>
            <?php else: ?>
            <div class="sch-wrap">
                <table class="sch-table">
                    <thead>
                        <tr>
                            <th class="sch-date-h">Date</th>
                            <?php foreach ($subjects as $sname): ?><th><?php echo e($sname); ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php for ($day = $firstDay; $day <= $lastDay; $day++):
                        $date = courseDayDate($startDate, $day);
                        $isToday = $date == $today;
                    ?>
                        <tr class="<?php echo $isToday ? 'is-today' : ''; ?>">
                            <td class="sch-date">
                                <div class="sch-day"><?php echo e($date->format('l')); ?> <small>Day <?php echo $day; ?></small></div>
                                <div class="sch-greg"><?php echo e($date->format('d / m / Y')); ?></div>
                                <div class="sch-hijri"><?php echo e(courseHijriShort($date)); ?></div>
                                <?php if ($isToday): ?><span class="badge brand" style="margin-top:6px;">Today</span><?php endif; ?>
                            </td>
                            <?php foreach ($subjects as $sid => $sname): ?>
                                <td>
                                    <?php foreach (($byDay[$day][$sid] ?? []) as $it):
                                        $chipClass = 'chip';
                                        if ($it['type'] !== 'content') $chipClass .= ' is-exam';
                                        if ($it['state'] === 'done')     $chipClass .= ' is-done';
                                        if ($it['state'] === 'locked')   $chipClass .= ' is-locked';
                                        if ($it['state'] === 'missed')   $chipClass .= ' is-missed';
                                        $stateLabels = ['done' => '✅ Done', 'open' => '🔓 Open', 'locked' => '🔒 Locked', 'missed' => '⛔ Missed'];
                                    ?>
                                        <div class="<?php echo $chipClass; ?>">
                                            <div class="chip-title">
                                                <?php echo $it['type'] === 'content' ? '📘' : ($it['type'] === 'quiz' ? '📝' : '📅'); ?>
                                                <?php echo e($it['title']); ?>
                                            </div>
                                            <div class="chip-meta">
                                                <?php echo e(COURSE_ITEM_LABELS[$it['type']]); ?>
                                                · <?php echo $stateLabels[$it['state']] ?? ''; ?>
                                                <?php if ($it['type'] !== 'content'): ?>
                                                    · due <?php echo e($it['due']->format('d M')); ?>
                                                <?php endif; ?>
                                                <?php if ($it['type'] !== 'content' && $it['grade'] !== null): ?>
                                                    · best <?php echo (int)round((float)$it['grade']); ?>%
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <p style="margin-top:10px;font-size:12px;color:var(--muted);">
                This is the schedule this student sees. Day 1 = the day they enrolled.
                Their calendar shifts automatically if they leave and re-join.
            </p>
        </section>

        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <a class="btn" href="admin_courses.php?course_id=<?php echo $courseId; ?>">← Back to course</a>
        </div>
    </div>

    <script>
    (function () {
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
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// LEGACY MODE: cohort-based view (kept for backward compatibility)
// ============================================================================

$stmt = $conn->prepare("
    SELECT h.*, c.title AS course_title, c.duration_days
    FROM course_cohorts h
    JOIN courses c ON c.id = h.course_id
    WHERE h.id = ?
");
$stmt->execute([$cohortId]);
$cohort = $stmt->fetch();
if (!$cohort) {
    flash('error', 'Group not found.');
    redirectTo('admin_courses.php');
}

$courseId = (int)$cohort['course_id'];
$duration = (int)$cohort['duration_days'];
$startDate = (string)$cohort['start_date'];
$start = new DateTimeImmutable($startDate);
$end = courseCohortEnd($startDate, $duration);
$today = new DateTimeImmutable('today');
$tab = (($_GET['tab'] ?? '') === 'students') ? 'students' : 'schedule';

// ============================================================================
// POST ACTIONS (legacy cohort mode)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Invalid or expired request token. Go back, refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    $back = 'admin_course_cohort.php?cohort_id=' . $cohortId . '&tab=students';

    try {
        switch ($action) {
            case 'add_student': {
                // Now adds the student to the COURSE (cohort_id becomes a label)
                $ident = trim((string)($_POST['identifier'] ?? ''));
                if ($ident === '') {
                    flash('error', 'Enter a username or email.');
                    break;
                }
                $stmt = $conn->prepare("SELECT id, username FROM users WHERE is_active = 1 AND (username = ? OR email = ?) LIMIT 1");
                $stmt->execute([$ident, $ident]);
                $student = $stmt->fetch();
                if (!$student) {
                    flash('error', 'No active user found with that username or email.');
                    break;
                }
                // Check existing enrollment in this COURSE
                $stmt = $conn->prepare("SELECT id, status FROM course_enrollments WHERE course_id = ? AND user_id = ?");
                $stmt->execute([$courseId, (int)$student['id']]);
                $existing = $stmt->fetch();
                if ($existing && $existing['status'] !== 'dropped') {
                    flash('error', $student['username'] . ' is already enrolled in this course.');
                    break;
                }
                if ($existing) {
                    // Re-activate with cohort label + fresh start
                    $conn->prepare("
                        UPDATE course_enrollments
                        SET status = 'active',
                            cohort_id = ?,
                            started_at = NOW(),
                            current_semester = 1,
                            enrolled_at = NOW(),
                            completed_at = NULL
                        WHERE id = ?
                    ")->execute([$cohortId, (int)$existing['id']]);
                } else {
                    $conn->prepare("
                        INSERT INTO course_enrollments (course_id, cohort_id, user_id, started_at, current_semester, status)
                        VALUES (?, ?, ?, NOW(), 1, 'active')
                    ")->execute([$courseId, $cohortId, (int)$student['id']]);
                }
                flash('success', 'Added ' . $student['username'] . ' to the course.');
                break;
            }

            case 'set_status': {
                $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
                $status = (string)($_POST['status'] ?? '');
                if (!in_array($status, ['active', 'completed', 'dropped'], true)) {
                    break;
                }
                $conn->prepare("
                    UPDATE course_enrollments
                    SET status = ?, completed_at = " . ($status === 'completed' ? 'NOW()' : 'NULL') . "
                    WHERE id = ?
                ")->execute([$status, $enrollmentId]);
                flash('success', 'Student status changed to ' . $status . '.');
                break;
            }

            case 'remove_student': {
                $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
                $conn->prepare("DELETE FROM course_enrollments WHERE id = ?")->execute([$enrollmentId]);
                flash('success', 'Student removed from the course.');
                break;
            }

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $e) {
        error_log('admin_course_cohort error: ' . $e->getMessage());
        flash('error', 'A database error occurred. Nothing was saved.');
    }
    redirectTo($back);
}

// ============================================================================
// DATA (legacy view)
// ============================================================================

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$data = courseLoadScheduleItems($conn, $courseId, 0);
$items = $data['items'];
$subjects = $data['subjects'];

/** Roster with per-student progress — set-based, not N+1. */
function loadRoster(PDO $conn, int $cohortId, array $items): array
{
    $stmt = $conn->prepare("
        SELECT en.id AS enrollment_id, en.user_id, en.status, en.started_at, en.enrolled_at, en.completed_at,
               u.username, u.email
        FROM course_enrollments en
        JOIN users u ON u.id = en.user_id
        WHERE en.cohort_id = ?
        ORDER BY FIELD(en.status, 'active', 'completed', 'dropped'), u.username
    ");
    $stmt->execute([$cohortId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return [];
    }

    $userIds = array_map(static fn($r) => (int)$r['user_id'], $rows);
    $idsByType = ['content' => [], 'quiz' => [], 'exam' => []];
    foreach ($items as $it) {
        $idsByType[$it['type']][] = (int)$it['item_id'];
    }

    $done = [];
    $grades = [];
    $uIn = implode(',', array_fill(0, count($userIds), '?'));

    $queries = [
        'content' => "SELECT DISTINCT user_id, content_id AS item_id, NULL AS best FROM user_content_progress WHERE is_completed = 1 AND user_id IN ($uIn) AND content_id IN (%s)",
        'quiz' => "SELECT user_id, quiz_id AS item_id, MAX(percentage) AS best FROM quiz_attempts WHERE status = 'completed' AND user_id IN ($uIn) AND quiz_id IN (%s) GROUP BY user_id, quiz_id",
        'exam' => "SELECT user_id, exam_id AS item_id, MAX(percentage) AS best FROM monthly_exam_attempts WHERE status = 'completed' AND user_id IN ($uIn) AND exam_id IN (%s) GROUP BY user_id, exam_id",
    ];

    foreach ($queries as $type => $sqlTemplate) {
        $ids = array_values(array_unique($idsByType[$type]));
        if (!$ids) {
            continue;
        }
        $sql = sprintf($sqlTemplate, implode(',', array_fill(0, count($ids), '?')));
        $s = $conn->prepare($sql);
        $s->execute(array_merge($userIds, $ids));
        foreach ($s->fetchAll() as $r) {
            $uid = (int)$r['user_id'];
            $done[$uid] = ($done[$uid] ?? 0) + 1;
            if ($r['best'] !== null) {
                $grades[$uid][] = (float)$r['best'];
            }
        }
    }

    $total = count($items);
    foreach ($rows as &$r) {
        $uid = (int)$r['user_id'];
        $r['done'] = $done[$uid] ?? 0;
        $r['total'] = $total;
        $r['pct'] = $total > 0 ? (int)round(($r['done'] / $total) * 100) : 0;
        $r['avg_grade'] = !empty($grades[$uid]) ? array_sum($grades[$uid]) / count($grades[$uid]) : null;
    }
    unset($r);
    return $rows;
}

$roster = ($tab === 'students' || ($_GET['export'] ?? '') === 'csv') ? loadRoster($conn, $cohortId, $items) : [];

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'group-' . $cohortId . '-students.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Username', 'Email', 'Status', 'Started', 'Completed items', 'Total items', 'Progress %', 'Average grade %']);
    foreach ($roster as $r) {
        fputcsv($out, [
            $r['username'], $r['email'], $r['status'], $r['started_at'],
            $r['done'], $r['total'], $r['pct'],
            $r['avg_grade'] !== null ? round($r['avg_grade'], 1) : '',
        ]);
    }
    fclose($out);
    exit;
}

$stmt = $conn->prepare("SELECT status, COUNT(*) AS n FROM course_enrollments WHERE cohort_id = ? GROUP BY status");
$stmt->execute([$cohortId]);
$counts = ['active' => 0, 'completed' => 0, 'dropped' => 0];
foreach ($stmt->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['n'];
}
$enrolledNow = $counts['active'] + $counts['completed'];

[$phaseLabel, $phaseClass] = courseCohortPhase(
    ['status' => $cohort['status'], 'start_date' => $startDate, 'enrollment_deadline' => $cohort['enrollment_deadline']],
    $duration,
    $today
);

$byDay = [];
foreach ($items as $it) {
    $byDay[$it['unlock_day']][$it['subject_id']][] = $it;
}

$totalWeeks = max(1, (int)ceil($duration / 7));
if ($today < $start) {
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

if ($totalWeeks <= 12) {
    $pages = range(1, $totalWeeks);
} else {
    $pages = array_unique(array_merge([1], range(max(1, $week - 2), min($totalWeeks, $week + 2)), [$totalWeeks]));
    sort($pages);
}

function cohortUrl(int $cohortId, string $tab, int $week = 0): string
{
    return 'admin_course_cohort.php?cohort_id=' . $cohortId . '&tab=' . $tab . ($week > 0 ? '&week=' . $week : '');
}

function adminChip(array $it, string $note): string
{
    $isExam = $it['type'] !== 'content';
    $icon = $it['type'] === 'content' ? '📘' : ($it['type'] === 'quiz' ? '📝' : '📅');
    $meta = [COURSE_ITEM_LABELS[$it['type']]];
    if ($note !== '') {
        $meta[] = $note;
    }
    if ($isExam && $it['passing'] !== null) {
        $meta[] = 'pass ≥ ' . (int)$it['passing'] . '%';
    }
    return '<div class="chip' . ($isExam ? ' is-exam' : '') . '"><div class="chip-title">' . $icon . ' ' . e($it['title']) . '</div>'
        . '<div class="chip-meta">' . e(implode(' · ', $meta)) . '</div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($cohort['name']); ?> — Group — MyIslam Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #f5f7f5; --surface: #ffffff; --surface-2: #f9fafb; --surface-3: #eef2f7;
            --line: #e2e5e2; --text: #1f2937; --text-secondary: #4b5563; --muted: #6b7280;
            --brand: #0E4A34; --brand-light: #3E7A5E; --brand-soft: rgba(14, 74, 52, 0.08);
            --gold: #9C7A2E; --gold-soft: rgba(156, 122, 46, 0.12);
            --accent: #3E6E91; --accent-soft: rgba(62, 110, 145, 0.12);
            --danger: #b91c1c; --danger-soft: rgba(185, 28, 28, 0.08); --white: #ffffff;
            --radius-sm: 12px; --radius-md: 16px; --radius-lg: 20px;
        }
        body.dark-mode {
            --bg: #0f1720; --surface: #18212b; --surface-2: #1d2833; --surface-3: #22303c;
            --line: #304150; --text: #edf2f7; --text-secondary: #cbd5e1; --muted: #94a3b8;
            --brand: #4C9172; --brand-light: #7fcaa4; --brand-soft: rgba(76, 145, 114, 0.16);
            --gold: #C9A85E; --gold-soft: rgba(201, 168, 94, 0.16);
            --accent: #6FA0C4; --accent-soft: rgba(111, 160, 196, 0.16);
            --danger: #ef4444; --danger-soft: rgba(239, 68, 68, 0.12);
        }
        body { font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; padding: 24px; min-height: 100vh; }
        a { color: inherit; text-decoration: none; }
        button, input, select { font: inherit; color: inherit; }
        button { cursor: pointer; border: none; background: none; }
        a:focus-visible, button:focus-visible, input:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
        .container { max-width: 1280px; margin: 0 auto; display: grid; gap: 20px; }

        .page-header { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding-bottom: 16px; border-bottom: 1px solid var(--line); }
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
        .btn-sm { padding: 5px 11px; font-size: 12px; }

        .flash { padding: 12px 16px; border-radius: var(--radius-sm); font-weight: 600; font-size: 14px; border: 1px solid transparent; }
        .flash.success { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }
        .flash.error { background: var(--danger-soft); color: var(--danger); border-color: var(--danger); }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge.brand { background: var(--brand-soft); color: var(--brand); }
        .badge.gold { background: var(--gold-soft); color: var(--gold); }
        .badge.accent { background: var(--accent-soft); color: var(--accent); }
        .badge.danger { background: var(--danger-soft); color: var(--danger); }
        .badge.muted { background: var(--surface-3); color: var(--muted); }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .stat { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--gold); border-radius: var(--radius-md); padding: 12px 14px; }
        .stat span { display: block; font-size: 12px; color: var(--muted); }
        .stat strong { font-size: 19px; }
        .stat small { display: block; color: var(--gold); font-size: 12px; font-weight: 600; }

        .tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .tab { padding: 10px 18px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); font-weight: 700; font-size: 13px; color: var(--text-secondary); }
        .tab.active { background: var(--brand); border-color: var(--brand); color: var(--white); }

        .pager { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .pager a, .pager span.gap { min-width: 34px; height: 34px; padding: 0 8px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--line); background: var(--surface); font-size: 13px; font-weight: 700; color: var(--text-secondary); }
        .pager a:hover { border-color: var(--brand); color: var(--brand); }
        .pager a.active { background: var(--brand); border-color: var(--brand); color: var(--white); }
        .pager span.gap { border: none; background: none; }

        .sch-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius-md); }
        table.sch-table { width: 100%; border-collapse: collapse; min-width: 760px; }
        .sch-table th { background: var(--brand); color: var(--white); padding: 12px 10px; font-size: 13px; text-align: center; border-left: 1px solid rgba(255,255,255,.15); }
        body.dark-mode .sch-table th { color: #0f1720; }
        .sch-table th.sch-date-h { text-align: left; width: 170px; }
        .sch-table td { border-top: 1px solid var(--line); border-left: 1px solid var(--line); padding: 10px; vertical-align: top; background: var(--surface); }
        .sch-table td:first-child { border-left: none; }
        .sch-table tr.is-today td { background: var(--gold-soft); }
        .sch-table td.sch-date { background: var(--surface-2); }
        .sch-table tr.is-today td.sch-date { background: var(--gold-soft); }
        .sch-day { font-weight: 800; font-size: 15px; }
        .sch-day small { font-weight: 600; color: var(--muted); margin-left: 4px; }
        .sch-greg { font-size: 13px; color: var(--text-secondary); }
        .sch-hijri { font-size: 12px; color: var(--gold); font-weight: 600; }
        .chip { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--brand); border-radius: 10px; padding: 8px 10px; margin-bottom: 8px; font-size: 13px; }
        .chip:last-child { margin-bottom: 0; }
        .chip.is-exam { border-left-color: var(--gold); }
        .chip-title { font-weight: 700; overflow-wrap: anywhere; }
        .chip-meta { margin-top: 3px; font-size: 11px; color: var(--muted); font-weight: 600; }

        .table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius-md); }
        table.data { width: 100%; border-collapse: collapse; min-width: 760px; }
        table.data th, table.data td { padding: 11px 14px; text-align: left; border-bottom: 1px solid var(--line); font-size: 14px; vertical-align: middle; }
        table.data th { background: var(--surface-2); color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        table.data tr:last-child td { border-bottom: none; }
        td.actions { white-space: nowrap; }
        td.actions form { display: inline; }
        .mini-bar { height: 8px; width: 110px; border-radius: 999px; background: var(--surface-3); overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 8px; }
        .mini-bar > div { height: 100%; background: linear-gradient(90deg, var(--brand-light), var(--brand)); }
        .empty { text-align: center; color: var(--muted); padding: 26px 12px; }

        .toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 14px; }
        .toolbar form { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
        .field { display: grid; gap: 4px; }
        .field label { font-size: 12px; font-weight: 700; color: var(--text-secondary); }
        input[type="text"] { padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); font-size: 14px; min-width: 240px; }

        @media (max-width: 640px) { body { padding: 14px; } }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">
        <a href="admin_courses.php?course_id=<?php echo $courseId; ?>" class="back-link">← <?php echo e($cohort['course_title']); ?></a>
        <h1 class="page-title">
            👥 <?php echo e($cohort['name']); ?>
            <small>Optional group · <?php echo e($cohort['course_title']); ?> · <?php echo e($start->format('d M Y')); ?> → <?php echo e($end->format('d M Y')); ?></small>
        </h1>
        <span class="badge <?php echo e($phaseClass); ?>"><?php echo e($phaseLabel); ?></span>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Toggle dark mode">🌙</button>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?php echo e($flash[0]); ?>" role="status"><?php echo e($flash[1]); ?></div>
    <?php endif; ?>

    <section class="card">
        <div class="stats">
            <div class="stat"><span>Group size</span><strong><?php echo $enrolledNow; ?></strong></div>
            <div class="stat"><span>Active</span><strong><?php echo $counts['active']; ?></strong></div>
            <div class="stat"><span>Completed</span><strong><?php echo $counts['completed']; ?></strong></div>
            <div class="stat"><span>Dropped</span><strong><?php echo $counts['dropped']; ?></strong></div>
            <div class="stat"><span>Created</span><strong><?php echo e($start->format('d / m / Y')); ?></strong><small><?php echo e(courseHijriShort($start)); ?> AH</small></div>
        </div>
    </section>

    <nav class="tabs" aria-label="Group sections">
        <a class="tab <?php echo $tab === 'schedule' ? 'active' : ''; ?>" href="<?php echo e(cohortUrl($cohortId, 'schedule')); ?>">🗓️ Group calendar</a>
        <a class="tab <?php echo $tab === 'students' ? 'active' : ''; ?>" href="<?php echo e(cohortUrl($cohortId, 'students')); ?>">🎓 Students (<?php echo $enrolledNow; ?>)</a>
    </nav>

<?php if ($tab === 'schedule'): ?>
    <section class="card">
        <h2>
            <span>Week <?php echo $week; ?> of <?php echo $totalWeeks; ?> · Days <?php echo $firstDay; ?>–<?php echo $lastDay; ?></span>
            <a class="btn btn-sm" href="admin_courses.php?course_id=<?php echo $courseId; ?>">✎ Edit schedule</a>
        </h2>

        <nav class="pager" aria-label="Weeks" style="margin-bottom:14px;">
            <?php $prev = 0; foreach ($pages as $pg):
                if ($prev && $pg - $prev > 1): ?><span class="gap">…</span><?php endif; ?>
                <a href="<?php echo e(cohortUrl($cohortId, 'schedule', (int)$pg)); ?>" class="<?php echo $pg === $week ? 'active' : ''; ?>" title="Week <?php echo (int)$pg; ?>"><?php echo (int)$pg; ?></a>
            <?php $prev = $pg; endforeach; ?>
        </nav>

        <?php if (!$items): ?>
            <p class="empty">No published items are scheduled for this course yet.</p>
        <?php else: ?>
        <div class="sch-wrap">
            <table class="sch-table">
                <thead>
                    <tr>
                        <th class="sch-date-h">Date</th>
                        <?php foreach ($subjects as $sname): ?><th><?php echo e($sname); ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php for ($day = $firstDay; $day <= $lastDay; $day++):
                    $date = courseDayDate($startDate, $day);
                    $isToday = $date == $today;
                ?>
                    <tr class="<?php echo $isToday ? 'is-today' : ''; ?>">
                        <td class="sch-date">
                            <div class="sch-day"><?php echo e($date->format('l')); ?> <small>Day <?php echo $day; ?></small></div>
                            <div class="sch-greg"><?php echo e($date->format('d / m / Y')); ?></div>
                            <div class="sch-hijri"><?php echo e(courseHijriShort($date)); ?></div>
                            <?php if ($isToday): ?><span class="badge brand" style="margin-top:6px;">Today</span><?php endif; ?>
                        </td>
                        <?php foreach ($subjects as $sid => $sname): ?>
                            <td>
                                <?php foreach (($byDay[$day][$sid] ?? []) as $it) { echo adminChip($it, ''); } ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p style="margin-top:10px;font-size:12px;color:var(--muted);">
            Group calendar reference only. Every student actually follows their own calendar from the day they enrolled.
        </p>
    </section>

<?php else: ?>
    <section class="card">
        <h2><span>Students</span><a class="btn btn-sm" href="admin_course_cohort.php?cohort_id=<?php echo $cohortId; ?>&export=csv">⬇ Export CSV</a></h2>

        <div class="toolbar">
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_student">
                <input type="hidden" name="cohort_id" value="<?php echo $cohortId; ?>">
                <div class="field">
                    <label for="identifier">Add a student (username or email)</label>
                    <input type="text" id="identifier" name="identifier" placeholder="e.g. abuu_thanawiy" required>
                </div>
                <button class="btn btn-primary" type="submit">＋ Add</button>
            </form>
            <div class="field" style="margin-left:auto;">
                <label for="filter">Filter list</label>
                <input type="text" id="filter" placeholder="Search by name or email…" autocomplete="off">
            </div>
        </div>
        <p style="margin:-4px 0 12px;font-size:12px;color:var(--muted);">Admins can add students beyond the group's capacity.</p>

        <div class="table-wrap">
            <table class="data" id="rosterTable">
                <thead>
                    <tr><th>Student</th><th>Started</th><th>Status</th><th>Progress</th><th>Avg grade</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (!$roster): ?>
                    <tr><td colspan="6" class="empty">No students in this group yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($roster as $r):
                    $badge = $r['status'] === 'active' ? 'brand' : ($r['status'] === 'completed' ? 'accent' : 'muted');
                ?>
                    <tr data-search="<?php echo e(strtolower($r['username'] . ' ' . $r['email'])); ?>">
                        <td><strong><?php echo e($r['username']); ?></strong><br><span style="font-size:12px;color:var(--muted);"><?php echo e($r['email']); ?></span></td>
                        <td><?php echo e(date('d M Y', strtotime((string)$r['started_at']))); ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo e(ucfirst((string)$r['status'])); ?></span></td>
                        <td>
                            <span class="mini-bar"><div style="width: <?php echo (int)$r['pct']; ?>%"></div></span>
                            <?php echo (int)$r['done']; ?>/<?php echo (int)$r['total']; ?> · <?php echo (int)$r['pct']; ?>%
                        </td>
                        <td><?php echo $r['avg_grade'] !== null ? (int)round($r['avg_grade']) . '%' : '<span style="color:var(--muted)">—</span>'; ?></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-primary" href="admin_course_cohort.php?enrollment_id=<?php echo (int)$r['enrollment_id']; ?>">View schedule</a>
                            <?php if ($r['status'] === 'active'): ?>
                                <form method="post"><?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="completed">
                                    <input type="hidden" name="enrollment_id" value="<?php echo (int)$r['enrollment_id']; ?>"><input type="hidden" name="cohort_id" value="<?php echo $cohortId; ?>">
                                    <button class="btn btn-sm" type="submit">Mark completed</button></form>
                            <?php endif; ?>
                            <?php if ($r['status'] === 'completed'): ?>
                                <form method="post"><?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="active">
                                    <input type="hidden" name="enrollment_id" value="<?php echo (int)$r['enrollment_id']; ?>"><input type="hidden" name="cohort_id" value="<?php echo $cohortId; ?>">
                                    <button class="btn btn-sm" type="submit">Reopen</button></form>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'dropped'): ?>
                                <form method="post" data-confirm="Drop this student from the course? Their progress is kept."><?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="dropped">
                                    <input type="hidden" name="enrollment_id" value="<?php echo (int)$r['enrollment_id']; ?>"><input type="hidden" name="cohort_id" value="<?php echo $cohortId; ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">Drop</button></form>
                            <?php else: ?>
                                <form method="post"><?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="active">
                                    <input type="hidden" name="enrollment_id" value="<?php echo (int)$r['enrollment_id']; ?>"><input type="hidden" name="cohort_id" value="<?php echo $cohortId; ?>">
                                    <button class="btn btn-sm btn-primary" type="submit">Reactivate</button></form>
                            <?php endif; ?>
                            <form method="post" data-confirm="Remove this student's enrollment record completely?"><?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="remove_student">
                                <input type="hidden" name="enrollment_id" value="<?php echo (int)$r['enrollment_id']; ?>"><input type="hidden" name="cohort_id" value="<?php echo $cohortId; ?>">
                                <button class="btn btn-sm btn-danger" type="submit" title="Delete the enrollment record">✕</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="margin-top:10px;font-size:12px;color:var(--muted);">
            Progress counts published lessons, quizzes and exams the student has completed.
            "View schedule" opens a per-student view of the calendar <em>they</em> see.
        </p>
    </section>
<?php endif; ?>

</div>

<script>
(function () {
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

    document.querySelectorAll('form[data-confirm]').forEach(f => {
        f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
    });

    const filter = document.getElementById('filter');
    if (filter) {
        filter.addEventListener('input', () => {
            const q = filter.value.trim().toLowerCase();
            document.querySelectorAll('#rosterTable tbody tr[data-search]').forEach(tr => {
                tr.style.display = !q || tr.dataset.search.includes(q) ? '' : 'none';
            });
        });
    }
})();
</script>
</body>
</html>