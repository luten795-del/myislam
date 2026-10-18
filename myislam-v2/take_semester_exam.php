<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/course_layout.php';
require_once __DIR__ . '/includes/semester_exam_helpers.php';
require_once __DIR__ . '/includes/course_grades.php';

// ---------------------------------------------------------------------------
// BOOT
// ---------------------------------------------------------------------------
$ctx    = courseShellBoot();
$conn   = $ctx['conn'];
$userId = (int)$ctx['userId'];

$examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
if ($examId <= 0) {
    courseFlash('error', 'Exam not found.');
    courseRedirect('courses.php');
}

// ---------------------------------------------------------------------------
// LOAD EXAM + DIVISION + COURSE
// ---------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT se.*,
           cd.id         AS division_id,
           cd.name       AS division_name,
           cd.course_id  AS course_id,
           c.title       AS course_title,
           cs.subject_id AS subject_id,
           s.name        AS subject_name,
           s.icon        AS subject_icon
    FROM semester_exams se
    JOIN course_divisions cd ON cd.id = se.division_id
    JOIN courses c           ON c.id  = cd.course_id
    LEFT JOIN course_subjects cs ON cs.id = se.course_subject_id
    LEFT JOIN subjects s         ON s.id  = cs.subject_id
    WHERE se.id = ?
    LIMIT 1
");
$stmt->execute([$examId]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    courseFlash('error', 'Exam not found.');
    courseRedirect('courses.php');
}

$courseId = (int)$exam['course_id'];

// ---------------------------------------------------------------------------
// ENROLLMENT CHECK
// ---------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT id FROM course_enrollments
    WHERE user_id = ? AND course_id = ? AND status = 'active'
    LIMIT 1
");
$stmt->execute([$userId, $courseId]);
$enrollmentId = (int)$stmt->fetchColumn();

if ($enrollmentId <= 0) {
    courseFlash('error', 'You are not enrolled in this course.');
    courseRedirect('courses.php');
}

// ---------------------------------------------------------------------------
// POST: SUBMIT
// ---------------------------------------------------------------------------
$submitted = false;
$result    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {

    if (!courseCsrfValid()) {
        http_response_code(419);
        exit('Invalid or expired request token.');
    }

    // Re-check eligibility (belt & braces — user may have started an attempt in another tab).
    // Course-scoped so a stale attempt against another course cannot lock this one out.
    [$canTake, $reason] = semesterExamCanTake($conn, $examId, $userId, $courseId);
    if (!$canTake) {
        courseFlash('error', $reason);
        courseRedirect('course.php?course_id=' . $courseId);
    }

    // Start an attempt row (status = 'started').
    // Explicit scope — so the attempt row carries course_id + enrollment_id.
    $attemptId = semesterExamStartAttempt($conn, $examId, $userId, $courseId, $enrollmentId);

    // Collect answers: answers[question_id] = option_id
    $rawAnswers = $_POST['answers'] ?? [];
    $answers    = [];
    if (is_array($rawAnswers)) {
        foreach ($rawAnswers as $qid => $oid) {
            $qid = (int)$qid;
            $answers[$qid] = ($oid === '' || $oid === null) ? null : (int)$oid;
        }
    }

    // Score + save + sync to grade book (all inside semesterExamSubmit)
    $result    = semesterExamSubmit($conn, $attemptId, $answers);
    $submitted = true;
}

// ---------------------------------------------------------------------------
// PRE-FLIGHT for GET (show questions)
// ---------------------------------------------------------------------------
if (!$submitted) {
    [$canTake, $reason] = semesterExamCanTake($conn, $examId, $userId, $courseId);
    if (!$canTake) {
        courseFlash('error', $reason);
        courseRedirect('course.php?course_id=' . $courseId);
    }
}

// Load questions + options for the form
$questions = [];
if (!$submitted) {
    $stmt = $conn->prepare("
        SELECT id, question_text, question_type, points, sort_order, image_path
        FROM semester_exam_questions
        WHERE exam_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$examId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$questions) {
        courseFlash('error', 'This exam has no questions yet.');
        courseRedirect('course.php?course_id=' . $courseId);
    }

    // Load options for the choice-type questions
    $ids = array_map(fn($q) => (int)$q['id'], $questions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare("
        SELECT id, question_id, option_text, image_path, sort_order
        FROM semester_exam_options
        WHERE question_id IN ($placeholders)
        ORDER BY question_id ASC, sort_order ASC, id ASC
    ");
    $stmt->execute($ids);

    $optionsByQuestion = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
        $optionsByQuestion[(int)$opt['question_id']][] = $opt;
    }

    foreach ($questions as &$q) {
        $q['options'] = $optionsByQuestion[(int)$q['id']] ?? [];
    }
    unset($q);
}

// ---------------------------------------------------------------------------
// PAGE
// ---------------------------------------------------------------------------
courseShellStart($ctx, ($submitted ? 'Result — ' : 'Take — ') . $exam['title']);
?>

<style>
    .exam-wrap { max-width: 900px; margin: 0 auto; }
    .exam-hero {
        background: linear-gradient(135deg, var(--brand) 0%, #0A3626 100%);
        color: #fff; border-radius: var(--radius-lg);
        padding: clamp(20px, 4vw, 32px);
        margin-bottom: 20px;
        text-align: center;
    }
    .exam-hero h1 { font-size: clamp(20px, 4vw, 28px); margin: 0 0 6px; }
    .exam-hero .meta { font-size: 13px; opacity: .95; }
    .exam-hero .badges { margin-top: 12px; display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; }
    .exam-hero .badge { background: rgba(255,255,255,.2); color: #fff; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }

    .question-card {
        background: var(--surface); border: 1px solid var(--line);
        border-left: 4px solid var(--brand);
        border-radius: var(--radius-md);
        padding: 18px 20px; margin-bottom: 14px;
    }
    .question-num {
        display: inline-flex; align-items: center; justify-content: center;
        background: var(--brand); color: #fff;
        width: 32px; height: 32px; border-radius: 50%;
        font-weight: 800; font-size: 14px; margin-right: 10px;
        flex-shrink: 0;
    }
    .question-header {
        display: flex; align-items: flex-start; gap: 6px; margin-bottom: 12px;
    }
    .question-text {
        font-size: 15px; font-weight: 600; flex: 1; min-width: 0;
    }
    .question-points {
        font-size: 12px; color: var(--muted); font-weight: 700; white-space: nowrap;
    }
    .question-image {
        max-width: 100%; border-radius: 10px; margin: 8px 0;
        border: 1px solid var(--line);
    }
    .option-row {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 14px; margin-bottom: 6px;
        border: 1px solid var(--line); border-radius: 10px;
        cursor: pointer; transition: all .15s; background: var(--surface-2);
    }
    .option-row:hover { border-color: var(--brand); background: var(--brand-soft); }
    .option-row input[type="radio"] { flex-shrink: 0; accent-color: var(--brand); cursor: pointer; }
    .option-row span { font-size: 14px; }

    .submit-bar {
        position: sticky; bottom: 10px;
        background: var(--surface); border: 1px solid var(--line);
        padding: 14px 20px; border-radius: var(--radius-md);
        display: flex; justify-content: space-between; align-items: center;
        gap: 12px; flex-wrap: wrap;
        box-shadow: 0 -4px 12px rgba(0,0,0,.08);
        margin-top: 20px;
    }
    .submit-bar .hint { color: var(--muted); font-size: 13px; }

    /* Result screen */
    .result-card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--radius-lg); padding: 40px 24px;
        text-align: center; max-width: 520px; margin: 40px auto;
    }
    .result-card .big {
        font-size: 68px; font-weight: 800; line-height: 1;
        margin: 12px 0;
    }
    .result-card.pass .big { color: #16a34a; }
    .result-card.fail .big { color: #dc2626; }
    .result-card .label { color: var(--muted); font-size: 14px; }
    .result-card .actions { display: flex; gap: 10px; justify-content: center; margin-top: 24px; flex-wrap: wrap; }
</style>

<div class="exam-wrap">

<?php if ($submitted && is_array($result) && !empty($result['ok'])): ?>

    <div class="result-card <?php echo $result['passed'] ? 'pass' : 'fail'; ?>">
        <div class="label">Your score</div>
        <div class="big"><?php echo (int)round((float)$result['percentage']); ?>%</div>
        <div class="label">
            <?php echo (int)$result['score']; ?> / <?php echo (int)$result['total_points']; ?> points
        </div>
        <h2 style="margin:20px 0 8px;font-size:22px;">
            <?php if ($result['passed']): ?>
                🎉 Congratulations, you passed!
            <?php else: ?>
                You did not pass this attempt.
            <?php endif; ?>
        </h2>
        <p style="color:var(--text-secondary);font-size:14px;">
            <?php if ($result['passed']): ?>
                Your grade has been recorded. Check your course page for updated progress.
            <?php else: ?>
                Passing score is <?php echo (int)$exam['passing_score']; ?>%.
                <?php if ($exam['exam_type'] === 'final'): ?>
                    You may be eligible to take a supplementary exam.
                <?php endif; ?>
            <?php endif; ?>
        </p>
        <div class="actions">
            <a class="btn btn-primary" href="course.php?course_id=<?php echo $courseId; ?>">← Back to course</a>
        </div>
    </div>

<?php elseif ($submitted): ?>

    <div class="result-card fail">
        <div class="big">⚠️</div>
        <h2 style="color:#dc2626;">Submission problem</h2>
        <p style="color:var(--text-secondary);margin-top:8px;">
            <?php echo htmlspecialchars((string)($result['message'] ?? 'Please try again.'), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div class="actions">
            <a class="btn" href="take_semester_exam.php?exam_id=<?php echo $examId; ?>">Try again</a>
            <a class="btn" href="course.php?course_id=<?php echo $courseId; ?>">← Back to course</a>
        </div>
    </div>

<?php else: ?>

    <section class="exam-hero">
        <h1><?php echo htmlspecialchars($exam['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <div class="meta">
            <?php echo htmlspecialchars($exam['course_title'], ENT_QUOTES, 'UTF-8'); ?>
            ·
            <?php echo htmlspecialchars($exam['division_name'], ENT_QUOTES, 'UTF-8'); ?>
            <?php if (!empty($exam['subject_name'])): ?>
                · <?php echo htmlspecialchars(($exam['subject_icon'] ?? '📖') . ' ' . $exam['subject_name'], ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </div>
        <div class="badges">
            <span class="badge">
                <?php echo $exam['exam_type'] === 'supplementary' ? '📚 Supplementary Exam' : '🎓 Final Exam'; ?>
            </span>
            <span class="badge"><?php echo count($questions); ?> question<?php echo count($questions) === 1 ? '' : 's'; ?></span>
            <span class="badge">Pass: <?php echo (int)$exam['passing_score']; ?>%</span>
        </div>
    </section>

    <form method="post" id="examForm">
        <?php echo courseCsrfField(); ?>
        <input type="hidden" name="exam_id" value="<?php echo $examId; ?>">
        <input type="hidden" name="submit_exam" value="1">

        <?php foreach ($questions as $i => $q):
            $qid = (int)$q['id'];
            $qtype = (string)($q['question_type'] ?? 'multiple_choice');
        ?>
            <div class="question-card">
                <div class="question-header">
                    <span class="question-num"><?php echo $i + 1; ?></span>
                    <div class="question-text">
                        <?php echo nl2br(htmlspecialchars((string)$q['question_text'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                    <span class="question-points"><?php echo (int)$q['points']; ?> pt<?php echo (int)$q['points'] === 1 ? '' : 's'; ?></span>
                </div>

                <?php if (!empty($q['image_path'])): ?>
                    <?php
                    $imgSrc = (string)$q['image_path'];
                    // Normalize: relative paths become absolute-from-root
                    if (!preg_match('#^https?://#', $imgSrc) && $imgSrc[0] !== '/') {
                        $imgSrc = '/' . ltrim($imgSrc, '/');
                    }
                    ?>
                    <img class="question-image" src="<?php echo htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                <?php endif; ?>

                <?php if ($qtype === 'multiple_choice' || $qtype === 'true_false'): ?>
                    <?php if (!empty($q['options'])): ?>
                        <?php foreach ($q['options'] as $opt): ?>
                            <label class="option-row">
                                <input type="radio"
                                       name="answers[<?php echo $qid; ?>]"
                                       value="<?php echo (int)$opt['id']; ?>">
                                <span>
                                    <?php echo htmlspecialchars((string)$opt['option_text'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($opt['image_path'])): ?>
                                        <?php
                                        $optImg = (string)$opt['image_path'];
                                        if (!preg_match('#^https?://#', $optImg) && $optImg[0] !== '/') {
                                            $optImg = '/' . ltrim($optImg, '/');
                                        }
                                        ?>
                                        <br>
                                        <img src="<?php echo htmlspecialchars($optImg, ENT_QUOTES, 'UTF-8'); ?>"
                                             alt=""
                                             style="max-height:80px;margin-top:6px;border-radius:6px;border:1px solid var(--line);">
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:var(--muted);font-size:13px;">No options configured for this question.</p>
                    <?php endif; ?>
                <?php elseif ($qtype === 'explanation'): ?>
                    <p style="color:var(--muted);font-size:13px;">
                        (Written answers are not yet supported in semester exams.)
                    </p>
                <?php else: ?>
                    <p style="color:var(--danger);font-size:13px;">
                        Unknown question type: <?php echo htmlspecialchars($qtype, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="submit-bar">
            <span class="hint">
                Once submitted, you cannot change your answers.
            </span>
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Submit your exam now? You cannot change answers after this.');">
                ✅ Submit exam
            </button>
        </div>
    </form>

<?php endif; ?>
</div>

<?php
courseShellEnd();