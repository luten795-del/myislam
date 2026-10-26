<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/course_helpers.php';
require_once __DIR__ . '/../includes/course_grades.php';

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
// LOAD DIVISION + COURSE
// ============================================================================

$divisionId = (int)($_GET['division_id'] ?? $_POST['division_id'] ?? 0);
if ($divisionId <= 0) {
    flash('error', 'Division not found.');
    redirectTo('admin_courses.php');
}

$stmt = $conn->prepare("
    SELECT d.*, c.title AS course_title, c.id AS course_id
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
$today = new DateTimeImmutable('today');
$dStart = new DateTimeImmutable((string)$division['start_date']);
$dEnd = new DateTimeImmutable((string)$division['end_date']);
$isEnded = $today > $dEnd;

// ============================================================================
// POINTS MODEL HELPERS — mirror what course.php uses so the preview matches
// what the student sees on the course page.
// ============================================================================

if (!function_exists('division_grade_from_percent')) {
    /** @return array{0:string,1:string,2:bool} [letter, remark, is_pass] */
    function division_grade_from_percent(float $percent, int $passingScore = 60): array
    {
        $p    = max(0.0, min(100.0, $percent));
        $pass = $p >= $passingScore;
        if ($pass) {
            if ($p >= min(100, $passingScore + 20)) return ['A', 'Excellent', true];
            if ($p >= min(100, $passingScore + 10)) return ['B', 'Very good', true];
            return ['C', 'Good', true];
        }
        if ($p >= max(0, $passingScore - 10)) return ['D', 'Near pass', false];
        return ['F', 'Fail', false];
    }
}

/**
 * Compute the current points ratio for a subject's grade row, WITHOUT firing
 * any write. final_score / supplementary_score are stored as POINTS (not %).
 *
 * @return array{earned:float, budget:int, percent:float, has_any:bool}
 */
function division_subject_points_ratio(?array $gradeRow, array $subjectCfg): array
{
    $quiz  = (int)($subjectCfg['quiz_total_points']  ?? 0);
    $exam  = (int)($subjectCfg['exam_total_points']  ?? 0);
    $final = (int)($subjectCfg['final_total_points'] ?? 0);
    $budget = $quiz + $exam + $final;

    if (!$gradeRow) {
        return ['earned' => 0.0, 'budget' => $budget, 'percent' => 0.0, 'has_any' => false];
    }

    $weekly  = (float)($gradeRow['weekly_total']  ?? 0);
    $monthly = (float)($gradeRow['monthly_total'] ?? 0);
    $finalPts = $gradeRow['final_score'] !== null ? (float)$gradeRow['final_score'] : null;
    $suppPts  = ((int)($gradeRow['supplementary_attempted'] ?? 0) === 1 && $gradeRow['supplementary_score'] !== null)
        ? (float)$gradeRow['supplementary_score']
        : null;
    $finalSlot = ($finalPts === null && $suppPts === null)
        ? 0.0
        : max($finalPts ?? 0.0, $suppPts ?? 0.0);

    $earned = $weekly + $monthly + $finalSlot;
    $earnedClamped = $budget > 0 ? min($earned, (float)$budget) : 0.0;
    $percent = $budget > 0 ? round($earnedClamped / $budget * 100, 1) : 0.0;

    $hasAny = $weekly > 0 || $monthly > 0 || $finalPts !== null || $suppPts !== null;

    return ['earned' => $earned, 'budget' => $budget, 'percent' => $percent, 'has_any' => $hasAny];
}

// ============================================================================
// POST: Close Division
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Invalid or expired request token. Go back, refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    $back = 'admin_division_results.php?division_id=' . $divisionId;

    try {
        switch ($action) {
            case 'close_division': {
                if (!$isEnded) {
                    flash('error', 'You can only close a division after it has ended (' . $dEnd->format('d M Y') . ').');
                    break;
                }

                // Idempotency — bail if already closed
                $stmt = $conn->prepare("SELECT COUNT(*) FROM enrollment_division_history WHERE division_id = ?");
                $stmt->execute([$divisionId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    flash('error', 'This division has already been closed.');
                    break;
                }

                // Load subjects of this course
                $stmt = $conn->prepare("
                    SELECT cs.*, s.name AS subject_name
                    FROM course_subjects cs
                    JOIN subjects s ON s.id = cs.subject_id
                    WHERE cs.course_id = ?
                    ORDER BY cs.sort_order ASC
                ");
                $stmt->execute([$courseId]);
                $subjects = $stmt->fetchAll();

                if (!$subjects) {
                    flash('error', 'This course has no subjects to grade.');
                    break;
                }

                // Load active enrollments in this division
                $stmt = $conn->prepare("
                    SELECT en.id AS enrollment_id, en.user_id
                    FROM course_enrollments en
                    WHERE en.course_id = ?
                      AND en.current_division_id = ?
                      AND en.status = 'active'
                ");
                $stmt->execute([$courseId, $divisionId]);
                $enrollments = $stmt->fetchAll();

                if (!$enrollments) {
                    flash('error', 'No active students are enrolled in this division.');
                    break;
                }

                $conn->beginTransaction();

                $totalStudents = 0;
                $totalPassed = 0;
                $totalFailed = 0;

                // Prepare reusable statements
                $seedRow = $conn->prepare("
                    INSERT INTO enrollment_course_grades
                        (enrollment_id, division_id, subject_id)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE enrollment_id = enrollment_id
                ");
                $forceFinalAttempted = $conn->prepare("
                    UPDATE enrollment_course_grades
                    SET final_attempted = 1
                    WHERE enrollment_id = ? AND division_id = ? AND subject_id = ?
                      AND final_attempted = 0
                ");
                $setDivStatus = $conn->prepare("
                    UPDATE course_enrollments
                    SET division_status = ?
                    WHERE id = ?
                ");

                try {
                    foreach ($enrollments as $enr) {
                        $enrId = (int)$enr['enrollment_id'];

                        // ------------------------------------------------------
                        // For each subject, ensure the grade row exists and force
                        // a decision through the canonical points-based helper.
                        //
                        // academyComputeSubjectGrade() bails with status
                        // 'in_progress' when final_attempted = 0. When an admin
                        // closes a division manually, they are declaring the
                        // division "done" — so we flip final_attempted = 1 first,
                        // which lets the helper compute (earned / budget) × 100
                        // and set the final status. Missing quiz/exam points stay
                        // 0; the student simply loses those points.
                        // ------------------------------------------------------
                        foreach ($subjects as $sub) {
                            $sid = (int)$sub['subject_id'];
                            $seedRow->execute([$enrId, $divisionId, $sid]);
                            $forceFinalAttempted->execute([$enrId, $divisionId, $sid]);
                            academyComputeSubjectGrade($conn, $enrId, $divisionId, $sid);
                        }

                        // ------------------------------------------------------
                        // academyCheckDivisionPass() recomputes the average,
                        // writes enrollment_division_history, and fires the
                        // division + course certificates when warranted.
                        // ------------------------------------------------------
                        $divPassed = academyCheckDivisionPass($conn, $enrId, $divisionId);

                        $setDivStatus->execute([$divPassed ? 'passed' : 'failed', $enrId]);

                        $totalStudents++;
                        if ($divPassed) $totalPassed++; else $totalFailed++;
                    }

                    $conn->commit();
                    flash('success', "Division closed. $totalStudents students graded · $totalPassed passed · $totalFailed failed.");
                } catch (Throwable $innerEx) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    throw $innerEx;
                }
                break;
            }

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('admin_division_results error: ' . $e->getMessage());
        flash('error', 'A database error occurred. Nothing was saved.');
    }

    redirectTo($back);
}

// ============================================================================
// LOAD DATA FOR DISPLAY
// ============================================================================

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Is this division already closed?
$stmt = $conn->prepare("SELECT COUNT(*) FROM enrollment_division_history WHERE division_id = ?");
$stmt->execute([$divisionId]);
$isClosed = (int)$stmt->fetchColumn() > 0;

// Active students in this division
$stmt = $conn->prepare("
    SELECT en.id AS enrollment_id, en.status AS enrollment_status, en.division_status,
           u.id AS user_id, u.username, u.email, u.full_name
    FROM course_enrollments en
    JOIN users u ON u.id = en.user_id
    WHERE en.course_id = ?
      AND en.current_division_id = ?
      AND en.status = 'active'
    ORDER BY u.username ASC
");
$stmt->execute([$courseId, $divisionId]);
$enrolledStudents = $stmt->fetchAll();

// Subjects of this course
$stmt = $conn->prepare("
    SELECT cs.*, s.name AS subject_name
    FROM course_subjects cs
    JOIN subjects s ON s.id = cs.subject_id
    WHERE cs.course_id = ?
    ORDER BY cs.sort_order ASC
");
$stmt->execute([$courseId]);
$courseSubjects = $stmt->fetchAll();

// Existing history rows (if already closed)
$historyRows = [];
if ($isClosed) {
    $stmt = $conn->prepare("
        SELECT h.*, u.username
        FROM enrollment_division_history h
        JOIN course_enrollments en ON en.id = h.enrollment_id
        JOIN users u ON u.id = en.user_id
        WHERE h.division_id = ?
        ORDER BY h.status ASC, u.username ASC
    ");
    $stmt->execute([$divisionId]);
    $historyRows = $stmt->fetchAll();
}

// Per-student, per-subject grade rows for the preview table
$gradeMap = []; // [enrollmentId][subjectId] = grade row
$stmt = $conn->prepare("
    SELECT ecg.* FROM enrollment_course_grades ecg
    JOIN course_enrollments en ON en.id = ecg.enrollment_id
    WHERE en.course_id = ? AND ecg.division_id = ?
");
$stmt->execute([$courseId, $divisionId]);
foreach ($stmt->fetchAll() as $r) {
    $gradeMap[(int)$r['enrollment_id']][(int)$r['subject_id']] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results — <?php echo e($division['name']); ?> — MyIslam Admin</title>
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
        body { font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; padding: 24px; min-height: 100vh; }
        a { color: inherit; text-decoration: none; }
        button { cursor: pointer; border: none; background: none; font: inherit; color: inherit; }
        button, input { font: inherit; color: inherit; }
        a:focus-visible, button:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
        .container { max-width: 1300px; margin: 0 auto; display: grid; gap: 20px; }

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
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn[disabled] { opacity: .55; cursor: not-allowed; transform: none; }

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

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .stat { background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid var(--gold); border-radius: var(--radius-md); padding: 12px 14px; }
        .stat span { display: block; font-size: 12px; color: var(--muted); }
        .stat strong { font-size: 20px; }

        .table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius-md); }
        table.data { width: 100%; border-collapse: collapse; min-width: 760px; }
        table.data th, table.data td { padding: 11px 14px; text-align: left; border-bottom: 1px solid var(--line); font-size: 14px; vertical-align: middle; }
        table.data th { background: var(--surface-2); color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        table.data tr:last-child td { border-bottom: none; }
        table.data td.grade { font-weight: 700; }
        table.data td.grade.pass { color: var(--success); }
        table.data td.grade.fail { color: var(--danger); }
        table.data td.grade.pending { color: var(--muted); }
        table.data td.grade .lt {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px; height: 20px; border-radius: 50%;
            font-size: 10px; font-weight: 800; color: #fff; margin-left: 4px;
        }
        table.data td.grade .lt.A { background: #15803d; }
        table.data td.grade .lt.B { background: var(--accent); }
        table.data td.grade .lt.C { background: var(--gold); }
        table.data td.grade .lt.D { background: #c2410c; }
        table.data td.grade .lt.F { background: var(--danger); }
        table.data td.grade .lt.none { background: transparent; color: var(--muted); }
        .empty { text-align: center; color: var(--muted); padding: 26px 12px; }

        .close-banner {
            background: var(--gold-soft); border: 1px solid var(--gold);
            border-radius: var(--radius-md); padding: 16px;
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        }
        .close-banner .info { flex: 1; min-width: 240px; font-size: 14px; }
        .close-banner h3 { font-size: 16px; font-weight: 800; color: var(--gold); margin-bottom: 4px; }

        .closed-banner {
            background: var(--brand-soft); border: 1px solid var(--brand);
            border-radius: var(--radius-md); padding: 16px;
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        }
        .closed-banner .info { flex: 1; min-width: 240px; }
        .closed-banner h3 { font-size: 16px; font-weight: 800; color: var(--brand); margin-bottom: 4px; }

        @media (max-width: 640px) { body { padding: 14px; } }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">
        <a href="admin_courses.php?course_id=<?php echo $courseId; ?>" class="back-link">← <?php echo e($division['course_title']); ?></a>
        <h1 class="page-title">
            📊 Results — <?php echo e($division['name']); ?>
            <small><?php echo e($dStart->format('d M Y')); ?> → <?php echo e($dEnd->format('d M Y')); ?></small>
        </h1>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Toggle dark mode">🌙</button>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?php echo e($flash[0]); ?>" role="status"><?php echo e($flash[1]); ?></div>
    <?php endif; ?>

    <!-- Summary stats -->
    <section class="card">
        <div class="stats">
            <div class="stat"><span>Students graded</span><strong><?php echo count($enrolledStudents); ?></strong></div>
            <div class="stat"><span>Subjects</span><strong><?php echo count($courseSubjects); ?></strong></div>
            <div class="stat"><span>Passmark</span><strong>≥ <?php echo (int)$division['passing_score']; ?>%</strong></div>
            <div class="stat"><span>Status</span><strong><span class="badge <?php echo $isClosed ? 'brand' : ($isEnded ? 'gold' : 'accent'); ?>"><?php echo $isClosed ? 'Closed' : ($isEnded ? 'Ready to close' : 'Running'); ?></span></strong></div>
        </div>
    </section>

    <!-- Close action -->
    <?php if (!$isClosed): ?>
        <section class="card">
            <div class="close-banner">
                <div class="info">
                    <h3>⚠️ Close this division</h3>
                    <p>
                        Closing will:
                    </p>
                    <ul style="margin:6px 0 0 20px;font-size:13px;color:var(--text-secondary);">
                        <li>Compute each subject's grade as <strong>(points earned) ÷ (subject total mark) × 100</strong>, capped at 100</li>
                        <li>Mark each subject as <strong>passed</strong> or <strong>failed</strong> (≥ <?php echo (int)$division['passing_score']; ?>%)</li>
                        <li>Mark each student as passing or failing the whole division</li>
                        <li>Fire division and course certificates where applicable</li>
                    </ul>
                    <p style="margin-top:8px;font-size:12px;color:var(--danger);">
                        <strong>This cannot be undone.</strong>
                        Any subject without attempts will be treated as having earned 0 points — the student loses those points.
                    </p>
                    <?php if (!$isEnded): ?>
                        <p style="margin-top:8px;font-size:13px;color:var(--muted);">
                            You can only close after <strong><?php echo e($dEnd->format('d M Y')); ?></strong>.
                        </p>
                    <?php endif; ?>
                </div>
                <form method="post" data-confirm="Close this division and compute final grades? This cannot be undone.">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="close_division">
                    <input type="hidden" name="division_id" value="<?php echo $divisionId; ?>">
                    <button class="btn btn-primary" type="submit" <?php if (!$isEnded) echo 'disabled'; ?>>Close division</button>
                </form>
            </div>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="closed-banner">
                <div class="info">
                    <h3>✅ Division closed</h3>
                    <p>This division has been graded. Results are shown below.</p>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Results table -->
    <section class="card">
        <h2>
            <span><?php echo $isClosed ? 'Final grades' : 'Points preview'; ?></span>
            <span class="badge brand"><?php echo count($enrolledStudents); ?> students</span>
        </h2>

        <?php if (!$enrolledStudents): ?>
            <p class="empty">No active students are currently enrolled in this division.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <?php foreach ($courseSubjects as $sub):
                                $subjTotal = (int)$sub['quiz_total_points']
                                           + (int)$sub['exam_total_points']
                                           + (int)$sub['final_total_points'];
                            ?>
                                <th>
                                    <?php echo e($sub['subject_name']); ?>
                                    <br><small style="font-weight:500;">
                                        ≥ <?php echo (int)$sub['passing_score']; ?>% ·
                                        <?php echo (int)$subjTotal; ?> pts
                                    </small>
                                </th>
                            <?php endforeach; ?>
                            <th>Average</th>
                            <th>Overall</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($enrolledStudents as $s):
                        $enrId = (int)$s['enrollment_id'];
                        $gradeSum = 0.0;
                        $gradeCount = 0;
                        $subjectsPassed = 0;
                        $subjectsFailed = 0;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo e($s['username']); ?></strong>
                                <br><small style="color:var(--muted);"><?php echo e($s['email']); ?></small>
                            </td>
                            <?php foreach ($courseSubjects as $sub):
                                $sid = (int)$sub['subject_id'];
                                $row = $gradeMap[$enrId][$sid] ?? null;

                                // When the division is closed, read the stored course_grade.
                                // Before closing, compute the SAME points ratio that
                                // course.php displays so the preview matches exactly.
                                if ($isClosed) {
                                    $grade = ($row && $row['course_grade'] !== null)
                                        ? (float)$row['course_grade']
                                        : null;
                                    $hasAny = $grade !== null;
                                } else {
                                    $ratio = division_subject_points_ratio($row, $sub);
                                    $grade  = $ratio['has_any'] ? $ratio['percent'] : null;
                                    $hasAny = $ratio['has_any'];
                                }

                                $passing = (int)$sub['passing_score'];
                                [$letter, $remark, $letterPass] = division_grade_from_percent(
                                    $grade ?? 0.0,
                                    $passing
                                );

                                if ($grade === null) {
                                    $cls = 'pending';
                                    $label = '—';
                                    $letterShow = '';
                                } else {
                                    if ($grade >= $passing) {
                                        $cls = 'pass';
                                        if ($isClosed) $subjectsPassed++;
                                    } else {
                                        $cls = 'fail';
                                        if ($isClosed) $subjectsFailed++;
                                    }
                                    $label = (int)round($grade) . '%';
                                    $letterShow = $letter;
                                }

                                if ($grade !== null) {
                                    $gradeSum += $grade;
                                    $gradeCount++;
                                }
                            ?>
                                <td class="grade <?php echo $cls; ?>">
                                    <?php echo e($label); ?>
                                    <?php if ($letterShow !== ''): ?>
                                        <span class="lt <?php echo e($letterShow); ?>" title="<?php echo e($remark); ?>"><?php echo e($letterShow); ?></span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td><strong><?php echo $gradeCount > 0 ? (int)round($gradeSum / $gradeCount) . '%' : '—'; ?></strong></td>
                            <td>
                                <?php if ($isClosed):
                                    $overall = ($subjectsFailed === 0 && $subjectsPassed > 0) ? 'passed' : 'failed';
                                ?>
                                    <span class="badge <?php echo $overall === 'passed' ? 'success' : 'danger'; ?>"><?php echo e(ucfirst($overall)); ?></span>
                                <?php else: ?>
                                    <span class="badge muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top:12px;font-size:12px;color:var(--muted);">
                <?php if ($isClosed): ?>
                    Final grades shown. A student passes a subject if their grade (points earned ÷ subject total mark × 100) ≥ the subject's passmark.
                <?php else: ?>
                    Preview using the <strong>live points ratio</strong> — the exact same formula the student sees on their course page.
                    Missing quiz/exam points stay 0; the final computation happens when you close the division.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </section>

    <!-- History summary (if closed) -->
    <?php if ($isClosed && $historyRows): ?>
        <section class="card">
            <h2><span>Division history</span></h2>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Status</th>
                            <th>Average</th>
                            <th>Subjects passed</th>
                            <th>Subjects failed</th>
                            <th>Closed at</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historyRows as $h): ?>
                        <tr>
                            <td><strong><?php echo e($h['username']); ?></strong></td>
                            <td><span class="badge <?php echo $h['status'] === 'passed' ? 'success' : 'danger'; ?>"><?php echo e(ucfirst($h['status'])); ?></span></td>
                            <td><?php echo (int)round((float)$h['average_grade']); ?>%</td>
                            <td><?php echo (int)$h['courses_passed']; ?></td>
                            <td><?php echo (int)$h['courses_failed']; ?></td>
                            <td><?php echo e(date('d M Y H:i', strtotime((string)$h['completed_at']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <a class="btn" href="admin_courses.php?course_id=<?php echo $courseId; ?>">← Back to course</a>
        <a class="btn" href="admin_course_schedule.php?division_id=<?php echo $divisionId; ?>">🗓️ Schedule</a>
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

    document.querySelectorAll('form[data-confirm]').forEach(f => {
        f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
    });
})();
</script>
</body>
</html>