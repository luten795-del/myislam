<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/config/database.php';

// ---------------------------------------------------------------------------
// AUTH — admin only
// ---------------------------------------------------------------------------
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$conn = db();
if (!$conn instanceof PDO) {
    die('Unable to connect to database.');
}

$stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$allowedRoles = ['owner','admin'];
if (!$row || !in_array(strtolower((string)($row['role'] ?? '')), $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. Admin only.');
}

// ---------------------------------------------------------------------------
// DRY RUN vs APPLY
// ---------------------------------------------------------------------------
$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// ---------------------------------------------------------------------------
// COLLECT WHAT NEEDS TO CHANGE
// ---------------------------------------------------------------------------
// For every course_schedule row, find the linked item (quiz or monthly exam)
// and compare its stored course_id/division_id/week_number to the schedule.
// Any mismatch = a row we want to fix.

$sql = "
    SELECT
        cs.id           AS schedule_id,
        cs.course_id,
        cs.division_id,
        cs.week_number,
        cs.item_type,
        cs.item_id,

        q.id            AS q_id,
        q.title         AS q_title,
        q.course_id     AS q_course_id,
        q.division_id   AS q_division_id,
        q.week_number   AS q_week_number,

        me.id           AS me_id,
        me.title        AS me_title,
        me.course_id    AS me_course_id,
        me.division_id  AS me_division_id,
        me.week_number  AS me_week_number
    FROM course_schedule cs

    LEFT JOIN quizzes q
           ON cs.item_type = 'quiz' AND q.id = cs.item_id

    LEFT JOIN monthly_exams me
           ON cs.item_type = 'exam' AND me.id = cs.item_id

    WHERE cs.item_type IN ('quiz','exam')
    ORDER BY cs.id ASC
";

$rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$toFixQuizzes  = [];
$toFixExams    = [];
$alreadyOk     = 0;
$itemMissing   = 0;

foreach ($rows as $r) {
    $schedCourse   = (int)$r['course_id'];
    $schedDivision = (int)$r['division_id'];
    $schedWeek     = (int)$r['week_number'];

    if ($r['item_type'] === 'quiz') {
        if ($r['q_id'] === null) {
            $itemMissing++;
            continue;
        }
        $qCourse   = $r['q_course_id']   !== null ? (int)$r['q_course_id']   : null;
        $qDivision = $r['q_division_id'] !== null ? (int)$r['q_division_id'] : null;
        $qWeek     = $r['q_week_number'] !== null ? (int)$r['q_week_number'] : null;

        $needsFix = ($qCourse !== $schedCourse)
                 || ($qDivision !== $schedDivision)
                 || ($qWeek !== $schedWeek);

        if ($needsFix) {
            $toFixQuizzes[] = [
                'quiz_id'    => (int)$r['q_id'],
                'title'      => (string)$r['q_title'],
                'course_id'  => $schedCourse,
                'division_id'=> $schedDivision,
                'week_number'=> $schedWeek,
                'old'        => [
                    'course_id'   => $qCourse,
                    'division_id' => $qDivision,
                    'week_number' => $qWeek,
                ],
            ];
        } else {
            $alreadyOk++;
        }
    } else { // exam
        if ($r['me_id'] === null) {
            $itemMissing++;
            continue;
        }
        $meCourse   = $r['me_course_id']   !== null ? (int)$r['me_course_id']   : null;
        $meDivision = $r['me_division_id'] !== null ? (int)$r['me_division_id'] : null;
        $meWeek     = $r['me_week_number'] !== null ? (int)$r['me_week_number'] : null;

        $needsFix = ($meCourse !== $schedCourse)
                 || ($meDivision !== $schedDivision)
                 || ($meWeek !== $schedWeek);

        if ($needsFix) {
            $toFixExams[] = [
                'exam_id'    => (int)$r['me_id'],
                'title'      => (string)$r['me_title'],
                'course_id'  => $schedCourse,
                'division_id'=> $schedDivision,
                'week_number'=> $schedWeek,
                'old'        => [
                    'course_id'   => $meCourse,
                    'division_id' => $meDivision,
                    'week_number' => $meWeek,
                ],
            ];
        } else {
            $alreadyOk++;
        }
    }
}

// ---------------------------------------------------------------------------
// APPLY
// ---------------------------------------------------------------------------
$applied = ['quizzes' => 0, 'exams' => 0];
$errors  = [];

if ($apply) {
    $conn->beginTransaction();
    try {
        $updQuiz = $conn->prepare("
            UPDATE quizzes
            SET course_id = ?, division_id = ?, week_number = ?
            WHERE id = ?
        ");
        foreach ($toFixQuizzes as $fix) {
            $updQuiz->execute([
                $fix['course_id'],
                $fix['division_id'],
                $fix['week_number'],
                $fix['quiz_id'],
            ]);
            $applied['quizzes']++;
        }

        $updExam = $conn->prepare("
            UPDATE monthly_exams
            SET course_id = ?, division_id = ?, week_number = ?
            WHERE id = ?
        ");
        foreach ($toFixExams as $fix) {
            $updExam->execute([
                $fix['course_id'],
                $fix['division_id'],
                $fix['week_number'],
                $fix['exam_id'],
            ]);
            $applied['exams']++;
        }

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Backfill Academy Links</title>
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#f5f7f5;--surface:#fff;--line:#e5e7eb;--text:#1f2937;--muted:#6b7280;--brand:#166534;--danger:#b91c1c;--warning:#b45309}
    body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);line-height:1.5;padding:32px 20px;min-height:100vh}
    .container{max-width:1100px;margin:0 auto;display:grid;gap:22px}
    h1{font-size:26px;font-weight:800}
    .card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:24px}
    .stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
    .stat{padding:16px;border:1px solid var(--line);border-radius:12px;text-align:center}
    .stat .val{font-size:26px;font-weight:800;margin-bottom:4px}
    .stat .lbl{font-size:12px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px}
    .stat.ok .val{color:var(--brand)}
    .stat.fix .val{color:var(--warning)}
    .stat.miss .val{color:var(--danger)}
    .btn{padding:12px 20px;border-radius:999px;font-weight:700;font-size:14px;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
    .btn-primary{background:var(--brand);color:#fff}
    .btn-secondary{background:#eef2f7;color:var(--text);border:1px solid var(--line)}
    .btn-danger{background:var(--danger);color:#fff}
    .btn-row{display:flex;gap:12px;flex-wrap:wrap}
    table{width:100%;border-collapse:collapse;font-size:13.5px}
    th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--line);vertical-align:top}
    th{background:#eef2f7;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
    tr:last-child td{border-bottom:none}
    code{background:#eef2f7;padding:2px 6px;border-radius:4px;font-size:12.5px}
    .empty{text-align:center;padding:32px 12px;color:var(--muted);font-weight:600}
    .flash{padding:12px 16px;border-radius:12px;font-weight:600;font-size:14px;border:1px solid transparent}
    .flash.success{background:rgba(34,197,94,.12);color:#15803d;border-color:#86efac}
    .flash.danger{background:rgba(239,68,68,.12);color:#b91c1c;border-color:#fca5a5}
    .muted{color:var(--muted);font-size:13px}
</style>
</head>
<body>
<div class="container">

    <h1>🔗 Backfill Academy Links</h1>
    <p class="muted">
        Scans <code>course_schedule</code> and stamps <code>course_id</code>,
        <code>division_id</code>, <code>week_number</code> on every quiz and
        monthly exam that was scheduled before the link existed.
        Safe to re-run — only touches rows that are actually missing or wrong.
    </p>

    <?php if (!empty($errors)): ?>
        <div class="flash danger">
            <strong>Errors:</strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($apply && empty($errors)): ?>
        <div class="flash success">
            ✅ Applied.
            Quizzes updated: <strong><?php echo (int)$applied['quizzes']; ?></strong>,
            exams updated: <strong><?php echo (int)$applied['exams']; ?></strong>.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="stat-grid">
            <div class="stat ok">
                <div class="val"><?php echo (int)$alreadyOk; ?></div>
                <div class="lbl">Already linked</div>
            </div>
            <div class="stat fix">
                <div class="val"><?php echo count($toFixQuizzes); ?></div>
                <div class="lbl">Quizzes to fix</div>
            </div>
            <div class="stat fix">
                <div class="val"><?php echo count($toFixExams); ?></div>
                <div class="lbl">Exams to fix</div>
            </div>
            <div class="stat miss">
                <div class="val"><?php echo (int)$itemMissing; ?></div>
                <div class="lbl">Missing items</div>
            </div>
        </div>

        <div class="btn-row" style="margin-top:20px;">
            <?php if (!$apply): ?>
                <a class="btn btn-secondary" href="?apply=0">🔄 Re-scan</a>
                <?php if ((count($toFixQuizzes) + count($toFixExams)) > 0): ?>
                    <a class="btn btn-primary"
                       href="?apply=1"
                       onclick="return confirm('Apply all <?php echo count($toFixQuizzes) + count($toFixExams); ?> fixes? This updates the database.');">
                        ✅ Apply all fixes
                    </a>
                <?php else: ?>
                    <span class="muted" style="align-self:center;">Nothing to fix — all scheduled items are already linked.</span>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn btn-secondary" href="?">🔍 Scan again (dry run)</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($toFixQuizzes) > 0): ?>
        <div class="card">
            <h2 style="font-size:16px;margin-bottom:12px;">Quizzes to fix (<?php echo count($toFixQuizzes); ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>Title</th>
                        <th>Current → Target</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($toFixQuizzes as $q): ?>
                        <tr>
                            <td>#<?php echo (int)$q['quiz_id']; ?></td>
                            <td><?php echo htmlspecialchars($q['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <div class="muted">course:
                                    <?php echo $q['old']['course_id'] === null ? 'NULL' : (int)$q['old']['course_id']; ?>
                                    → <strong><?php echo (int)$q['course_id']; ?></strong>
                                </div>
                                <div class="muted">division:
                                    <?php echo $q['old']['division_id'] === null ? 'NULL' : (int)$q['old']['division_id']; ?>
                                    → <strong><?php echo (int)$q['division_id']; ?></strong>
                                </div>
                                <div class="muted">week:
                                    <?php echo $q['old']['week_number'] === null ? 'NULL' : (int)$q['old']['week_number']; ?>
                                    → <strong><?php echo (int)$q['week_number']; ?></strong>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (count($toFixExams) > 0): ?>
        <div class="card">
            <h2 style="font-size:16px;margin-bottom:12px;">Monthly exams to fix (<?php echo count($toFixExams); ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>Title</th>
                        <th>Current → Target</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($toFixExams as $e): ?>
                        <tr>
                            <td>#<?php echo (int)$e['exam_id']; ?></td>
                            <td><?php echo htmlspecialchars($e['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <div class="muted">course:
                                    <?php echo $e['old']['course_id'] === null ? 'NULL' : (int)$e['old']['course_id']; ?>
                                    → <strong><?php echo (int)$e['course_id']; ?></strong>
                                </div>
                                <div class="muted">division:
                                    <?php echo $e['old']['division_id'] === null ? 'NULL' : (int)$e['old']['division_id']; ?>
                                    → <strong><?php echo (int)$e['division_id']; ?></strong>
                                </div>
                                <div class="muted">week:
                                    <?php echo $e['old']['week_number'] === null ? 'NULL' : (int)$e['old']['week_number']; ?>
                                    → <strong><?php echo (int)$e['week_number']; ?></strong>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (count($toFixQuizzes) === 0 && count($toFixExams) === 0 && !$apply): ?>
        <div class="card">
            <div class="empty">Nothing to backfill — every scheduled item is already linked.</div>
        </div>
    <?php endif; ?>

    <p class="muted" style="text-align:center;">
        ⚠️ Delete this file from the server once you have run it successfully.
    </p>

</div>
</body>
</html>