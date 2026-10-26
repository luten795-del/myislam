<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = (int)$ctx['userId'];

$tablesReady = courseTablesExist($conn);

// ============================================================================
// AJAX — details of ONE weekly-quiz attempt. Weekly quiz rows now open exactly like
// exam rows: per-question answers + the top participants of that quiz.
// ============================================================================
if (($_GET['ajax'] ?? '') === 'weekly_detail') {
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $out = ['ok' => false, 'message' => 'Attempt not found.'];

    try {
        $attemptId = (int)($_GET['attempt_id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT qa.id, qa.quiz_id, qa.score, qa.total_points, qa.answers_json, q.title
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            WHERE qa.id = ? AND qa.user_id = ? AND qa.status = 'completed'
            LIMIT 1
        ");
        $stmt->execute([$attemptId, $userId]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($att) {
            // ---- questions: stored answers first, answers_json as a fallback ----
            $questions = [];
            $qs = $conn->prepare("
                SELECT qq.question_text, qq.explanation,
                       qaa.selected_answer_text, qaa.is_correct,
                       (SELECT o.option_text FROM quiz_options o
                         WHERE o.question_id = qq.id AND o.is_correct = 1
                         ORDER BY o.sort_order, o.id LIMIT 1) AS correct_text
                FROM quiz_attempt_answers qaa
                JOIN quiz_questions qq ON qq.id = qaa.question_id
                WHERE qaa.attempt_id = ?
                ORDER BY qq.sort_order ASC, qq.id ASC
            ");
            $qs->execute([$attemptId]);
            foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $questions[] = [
                    'question_text'  => $h($r['question_text']),
                    'user_answer'    => $r['selected_answer_text'] !== null ? $h($r['selected_answer_text']) : '',
                    'correct_answer' => $h($r['correct_text'] ?? ''),
                    'is_correct'     => (int)$r['is_correct'] === 1,
                    'feedback'       => !empty($r['explanation']) ? $h($r['explanation']) : '',
                ];
            }
            if (!$questions && !empty($att['answers_json'])) {
                $decoded = json_decode((string)$att['answers_json'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $r) {
                        if (!is_array($r)) continue;
                        $questions[] = [
                            'question_text'  => $h($r['question_text'] ?? ''),
                            'user_answer'    => $h($r['user_answer_text'] ?? ''),
                            'correct_answer' => $h($r['correct_answer_text'] ?? ''),
                            'is_correct'     => !empty($r['is_correct']),
                            'feedback'       => !empty($r['explanation']) ? $h($r['explanation']) : '',
                        ];
                    }
                }
            }

            // ---- participants: everyone who completed this quiz, ranked by points ----
            $ps = $conn->prepare("
                SELECT u.id, u.username, u.full_name, u.profile_image,
                       MAX(qa.score) AS best_pts,
                       MAX(qa.score * 100 / NULLIF(qa.total_points, 0)) AS best_pct
                FROM quiz_attempts qa
                JOIN users u ON u.id = qa.user_id
                WHERE qa.quiz_id = ? AND qa.status = 'completed' AND u.is_active = 1
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_pts DESC, u.id ASC
            ");
            $ps->execute([(int)$att['quiz_id']]);
            $all = $ps->fetchAll(PDO::FETCH_ASSOC);

            $rank = 0; $seen = 0; $prev = null; $myRank = null; $parts = [];
            foreach ($all as $r) {
                $seen++;
                if ($prev === null || (float)$r['best_pts'] < $prev) { $rank = $seen; $prev = (float)$r['best_pts']; }
                $isMe = ((int)$r['id'] === $userId);
                if ($isMe) $myRank = $rank;
                $name = $r['username'] ?: ($r['full_name'] ?: 'User');
                if ($rank <= 10 || $isMe) {
                    $parts[] = [
                        'rank'          => $rank,
                        'is_me'         => $isMe,
                        'profile_image' => $r['profile_image'] ? $h($r['profile_image']) : '',
                        'initials'      => $h(strtoupper(mb_substr((string)$name, 0, 2))),
                        'display_name'  => $isMe ? 'You' : $h($name),
                        'best_pct'      => round((float)$r['best_pct'], 1),
                        'points'        => (int)round((float)$r['best_pts']),
                    ];
                }
            }

            $out = [
                'ok'        => true,
                'attempt'   => ['my_rank' => $myRank],
                'meta'      => ['icon' => '📝', 'title' => $h($att['title'])],
                'questions' => $questions,
                'participants' => $parts,
            ];
        }
    } catch (Throwable $e) {
        error_log('my_results weekly_detail: ' . $e->getMessage());
        $out = ['ok' => false, 'message' => 'Unable to load details.'];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// FILTERS
// ============================================================================
$filter = (string)($_GET['filter'] ?? 'all');
$allowedFilters = ['all', 'weekly', 'monthly', 'final', 'supplementary', 'passed', 'failed'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$courseFilter = (int)($_GET['course_id'] ?? 0);

// ============================================================================
// USER'S ENROLLMENTS — single source of truth for "which courses may this
// user see results from?" Only active + completed enrollments count.
// ============================================================================
$userEnrollments = [];   // course_id => enrollment_id
if ($tablesReady) {
    try {
        $stmt = $conn->prepare("
            SELECT id, course_id
            FROM course_enrollments
            WHERE user_id = ? AND status IN ('active','completed')
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $userEnrollments[(int)$r['course_id']] = (int)$r['id'];
        }
    } catch (PDOException $e) {
        error_log('my_results enrollments: ' . $e->getMessage());
    }
}

$enrolledCourseIds = array_keys($userEnrollments);

// A course filter only makes sense for a course the student is enrolled in.
if ($courseFilter > 0 && !isset($userEnrollments[$courseFilter])) {
    $courseFilter = 0;
}

// ============================================================================
// LOAD ATTEMPTS — weekly + monthly + semester (enrollment-scoped)
// ============================================================================
$attempts = [];

if ($tablesReady && $enrolledCourseIds) {
    // --- Weekly quizzes ---
    try {
        $stmt = $conn->prepare("
            SELECT
                'weekly'           AS source,
                qa.id              AS attempt_id,
                qa.quiz_id         AS exam_id,
                'quiz'             AS exam_type,
                q.title            AS title,
                qa.attempt_number  AS attempt_number,
                qa.total_questions,
                qa.correct_answers,
                qa.score           AS score,
                qa.total_points    AS total_points,
                qa.percentage      AS percentage,
                qa.passed          AS passed,
                qa.status          AS status,
                qa.started_at,
                qa.completed_at,
                c.id               AS course_id,
                c.title            AS course_title,
                c.image_path       AS course_image,
                s.name             AS subject_name,
                s.icon             AS subject_icon,
                q.division_id      AS division_id,
                qd.name            AS division_name
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            JOIN course_enrollments en
                 ON en.user_id = qa.user_id
                AND en.course_id = q.course_id
                AND en.status IN ('active','completed')
            LEFT JOIN courses c ON c.id = q.course_id
            LEFT JOIN course_divisions qd ON qd.id = q.division_id
            LEFT JOIN subjects s ON s.id = q.subject_id
            WHERE qa.user_id = ? AND qa.status = 'completed'
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attempts[] = $row;
        }
    } catch (PDOException $e) {
        error_log('my_results weekly query: ' . $e->getMessage());
    }

    // --- Monthly exams ---
    try {
        $stmt = $conn->prepare("
            SELECT
                'monthly'          AS source,
                mea.id             AS attempt_id,
                mea.exam_id        AS exam_id,
                'monthly'          AS exam_type,
                me.title           AS title,
                mea.attempt_number AS attempt_number,
                mea.total_questions,
                mea.correct_answers,
                mea.score          AS score,
                mea.total_points   AS total_points,
                mea.percentage     AS percentage,
                mea.passed         AS passed,
                mea.status         AS status,
                mea.started_at,
                mea.completed_at,
                c.id               AS course_id,
                c.title            AS course_title,
                c.image_path       AS course_image,
                s.name             AS subject_name,
                s.icon             AS subject_icon,
                cd.id              AS division_id,
                cd.name            AS division_name
            FROM monthly_exam_attempts mea
            JOIN monthly_exams me ON me.id = mea.exam_id
            LEFT JOIN course_divisions cd ON cd.id = me.division_id
            JOIN course_enrollments en
                 ON en.user_id = mea.user_id
                AND en.course_id = mea.course_id
                AND en.status IN ('active','completed')
            LEFT JOIN courses c ON c.id = mea.course_id
            LEFT JOIN subjects s ON s.id = me.subject_id
            WHERE mea.user_id = ? AND mea.status = 'completed'
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attempts[] = $row;
        }
    } catch (PDOException $e) {
        error_log('my_results monthly query: ' . $e->getMessage());
    }

    // --- Semester exams ---
    try {
        $stmt = $conn->prepare("
            SELECT
                'semester'         AS source,
                sea.id             AS attempt_id,
                sea.exam_id        AS exam_id,
                se.exam_type       AS exam_type,
                se.title           AS title,
                sea.attempt_number AS attempt_number,
                sea.total_questions,
                sea.correct_answers,
                sea.score          AS score,
                sea.total_points   AS total_points,
                sea.percentage     AS percentage,
                sea.passed         AS passed,
                sea.status         AS status,
                sea.started_at,
                sea.completed_at,
                c.id               AS course_id,
                c.title            AS course_title,
                c.image_path       AS course_image,
                s.name             AS subject_name,
                s.icon             AS subject_icon,
                cd.id              AS division_id,
                cd.name            AS division_name
            FROM semester_exam_attempts sea
            JOIN semester_exams se ON se.id = sea.exam_id
            LEFT JOIN course_divisions cd ON cd.id = se.division_id
            JOIN course_enrollments en
                 ON en.user_id = sea.user_id
                AND en.course_id = sea.course_id
                AND en.status IN ('active','completed')
            LEFT JOIN courses c ON c.id = sea.course_id
            LEFT JOIN course_subjects cs ON cs.id = se.course_subject_id
            LEFT JOIN subjects s ON s.id = cs.subject_id
            WHERE sea.user_id = ? AND sea.status = 'completed'
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attempts[] = $row;
        }
    } catch (PDOException $e) {
        error_log('my_results semester query: ' . $e->getMessage());
    }

    usort($attempts, static function ($a, $b) {
        return strtotime((string)($b['completed_at'] ?? '')) <=> strtotime((string)($a['completed_at'] ?? ''));
    });

    if ($filter !== 'all') {
        $attempts = array_values(array_filter($attempts, static function ($row) use ($filter) {
            if ($filter === 'passed') return ((int)$row['passed']) === 1;
            if ($filter === 'failed') return ((int)$row['passed']) === 0;
            if ($filter === 'weekly') return $row['source'] === 'weekly';
            if ($filter === 'monthly') return $row['source'] === 'monthly';
            if ($filter === 'final') return $row['source'] === 'semester' && $row['exam_type'] === 'final';
            if ($filter === 'supplementary') return $row['exam_type'] === 'supplementary';
            return true;
        }));
    }

    if ($courseFilter > 0) {
        $attempts = array_values(array_filter($attempts, static function ($row) use ($courseFilter) {
            return (int)($row['course_id'] ?? 0) === $courseFilter;
        }));
    }
}

// ============================================================================
// COURSE FILTER DROPDOWN
// ============================================================================
$myCoursesForFilter = [];
if ($tablesReady && $enrolledCourseIds) {
    try {
        $in = implode(',', array_fill(0, count($enrolledCourseIds), '?'));
        $stmt = $conn->prepare("
            SELECT id, title, image_path
            FROM courses
            WHERE id IN ($in)
            ORDER BY title ASC
        ");
        $stmt->execute($enrolledCourseIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $myCoursesForFilter[(int)$r['id']] = [
                'id'    => (int)$r['id'],
                'title' => (string)$r['title'],
                'image' => (string)($r['image_path'] ?? ''),
            ];
        }
    } catch (PDOException $e) {
        error_log('my_results course_filter query: ' . $e->getMessage());
    }
}

// ============================================================================
// DIVISION HISTORY + SUBJECT GRADES PER COURSE
// ============================================================================
$divisionHistoryByCourse = [];
$subjectGradesByCourse   = [];
$divisionLabels          = [];

if ($tablesReady) {
    $enrIds = array_values($userEnrollments);
    if ($enrIds) {
        $enrIn = implode(',', array_fill(0, count($enrIds), '?'));

        try {
            $stmt = $conn->prepare("
                SELECT h.*, cd.name AS division_name, cd.number AS division_number, cd.course_id
                FROM enrollment_division_history h
                JOIN course_divisions cd ON cd.id = h.division_id
                WHERE h.enrollment_id IN ($enrIn)
                ORDER BY cd.number ASC, h.completed_at DESC
            ");
            $stmt->execute($enrIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $cid = (int)$h['course_id'];
                $did = (int)$h['division_id'];
                $divisionHistoryByCourse[$cid][$did] = $h;
                $divisionLabels[$did] = [
                    'name'      => (string)$h['division_name'],
                    'number'    => (int)$h['division_number'],
                    'course_id' => $cid,
                ];
            }
        } catch (PDOException $e) {
            error_log('my_results division_history: ' . $e->getMessage());
        }

        // Subject grades + their course_subjects budget (needed for the POINTS model)
        try {
            $stmt = $conn->prepare("
                SELECT g.*, cd.name AS division_name, cd.number AS division_number, cd.course_id,
                       s.name AS subject_name, s.icon AS subject_icon,
                       cs.quiz_total_points   AS cs_quiz,
                       cs.exam_total_points   AS cs_exam,
                       cs.final_total_points  AS cs_final,
                       cs.passing_score       AS cs_passing,
                       cs.supplementary_max_score AS cs_supp_max
                FROM enrollment_course_grades g
                JOIN course_divisions cd ON cd.id = g.division_id
                JOIN course_enrollments en ON en.id = g.enrollment_id
                JOIN subjects s ON s.id = g.subject_id
                LEFT JOIN course_subjects cs
                       ON cs.course_id = cd.course_id
                      AND cs.subject_id = g.subject_id
                WHERE g.enrollment_id IN ($enrIn)
                ORDER BY cd.number ASC, s.sort_order ASC, s.name ASC
            ");
            $stmt->execute($enrIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $cid = (int)$g['course_id'];
                $did = (int)$g['division_id'];
                $sid = (int)$g['subject_id'];
                $subjectGradesByCourse[$cid][$did][$sid] = $g;
                if (!isset($divisionLabels[$did])) {
                    $divisionLabels[$did] = [
                        'name'      => (string)$g['division_name'],
                        'number'    => (int)$g['division_number'],
                        'course_id' => $cid,
                    ];
                }
            }
        } catch (PDOException $e) {
            error_log('my_results subject_grades: ' . $e->getMessage());
        }
    }
}

// ============================================================================
// POINTS MODEL — helper. Given a subject grade row, compute:
//   subject_total_mark = quiz_total_points + exam_total_points + final_total_points
//   points_earned      = weekly_total + monthly_total + max(final_score, supp_score)
//   percent            = min(points_earned, subject_total_mark) / subject_total_mark * 100
//   letter/remark      = derived from percent + passing_score
// All in raw points. final_score/supplementary_score are points, not percent.
// ============================================================================
if (!function_exists('mr_grade_from_percent')) {
    /**
     * Fixed bands: A 80-100 Excellent · B 70-79 Very good · C 60-69 Good · D 50-59 Pass · F below 50 Fail.
     * @return array{0:string,1:string,2:bool} [letter, remark, reaches_pass_mark]
     */
    function mr_grade_from_percent(float $percent, int $passingScore = 50): array
    {
        $p    = max(0.0, min(100.0, $percent));
        $pass = $p >= $passingScore;
        if ($p >= 80) return ['A', 'Excellent', $pass];
        if ($p >= 70) return ['B', 'Very good', $pass];
        if ($p >= 60) return ['C', 'Good',      $pass];
        if ($p >= 50) return ['D', $pass ? 'Pass' : 'Below pass', $pass];
        return ['F', 'Fail', $pass];
    }
}

if (!function_exists('mr_compute_subject')) {
    /**
     * @return array{earned:float, total:int, percent:float, letter:string, remark:string, is_pass:bool}
     */
    function mr_compute_subject(array $g): array
    {
        $quiz  = (int)($g['cs_quiz']  ?? 0);
        $exam  = (int)($g['cs_exam']  ?? 0);
        $final = (int)($g['cs_final'] ?? 0);
        $totalMark = $quiz + $exam + $final;
        $passing   = (int)($g['cs_passing'] ?? 50);

        // every bucket is capped at its own budget; the supplementary at its own cap
        $weekly  = min((float)($g['weekly_total']  ?? 0), (float)$quiz);
        $monthly = min((float)($g['monthly_total'] ?? 0), (float)$exam);
        $suppCap = (int)($g['cs_supp_max'] ?? 0);
        if ($suppCap <= 0 || $suppCap > $final) { $suppCap = $final; }

        $finalPts = $g['final_score'] !== null ? min((float)$g['final_score'], (float)$final) : null;
        $suppPts  = ((int)($g['supplementary_attempted'] ?? 0) === 1 && $g['supplementary_score'] !== null)
            ? min((float)$g['supplementary_score'], (float)$suppCap)
            : null;
        $finalSlot = ($finalPts === null && $suppPts === null)
            ? 0.0
            : max($finalPts ?? 0.0, $suppPts ?? 0.0);

        $earned = $weekly + $monthly + $finalSlot;
        $earnedClamped = $totalMark > 0 ? min($earned, (float)$totalMark) : 0.0;
        $percent = $totalMark > 0 ? round($earnedClamped / $totalMark * 100, 1) : 0.0;

        $decided = ((int)($g['final_attempted'] ?? 0) === 1)
                || ((int)($g['supplementary_attempted'] ?? 0) === 1);
        $status  = (string)($g['status'] ?? 'in_progress');
        $isPass  = $decided && ($status === 'passed' || ($status !== 'failed' && $percent >= $passing));

        [$letter, $remark] = mr_grade_from_percent($percent, $passing);
        if (!$decided && $letter === 'F') {
            $remark = 'Below pass';          // under 50 before the final exam is not a fail yet
        }

        return [
            'earned'  => $earned,
            'total'   => $totalMark,
            'percent' => $percent,
            'letter'  => $letter,
            'remark'  => $remark,
            'is_pass' => $isPass,
            'decided' => $decided,
        ];
    }
}

// ============================================================================
// GROUP BY COURSE (attempts only)
// ============================================================================
$byCourse = [];
foreach ($attempts as $a) {
    $cid = (int)($a['course_id'] ?? 0);
    if ($cid <= 0) continue;
    $key = 'c' . $cid;

    if (!isset($byCourse[$key])) {
        $byCourse[$key] = [
            'course_id'     => $cid,
            'course_title'  => (string)($a['course_title'] ?? ''),
            'course_image'  => (string)($a['course_image'] ?? ''),
            'attempts'      => [],
            'passed'        => 0,
            'failed'        => 0,
            'total'         => 0,
            'pct_sum'       => 0.0,
            'points_earned' => 0,
            'points_possible' => 0,
            'by_division'   => [],
        ];
    }

    $byCourse[$key]['attempts'][] = $a;
    $byCourse[$key]['total']++;
    $byCourse[$key]['pct_sum'] += (float)$a['percentage'];
    $byCourse[$key]['points_earned'] += (int)($a['score'] ?? 0);
    $byCourse[$key]['points_possible'] += (int)($a['total_points'] ?? 0);
    if ((int)$a['passed'] === 1) $byCourse[$key]['passed']++;
    else $byCourse[$key]['failed']++;

    $did = (int)($a['division_id'] ?? 0);
    $byCourse[$key]['by_division'][$did > 0 ? $did : 0][] = $a;   // 0 = not tied to a division
}

uasort($byCourse, function ($a, $b) {
    $aTime = strtotime((string)($a['attempts'][0]['completed_at'] ?? '0'));
    $bTime = strtotime((string)($b['attempts'][0]['completed_at'] ?? '0'));
    return $bTime <=> $aTime;
});

$filters = [
    'all'           => 'All',
    'weekly'        => 'Weekly',
    'monthly'       => 'Monthly',
    'final'         => 'Final',
    'supplementary' => 'Supplementary',
    'passed'        => 'Passed',
    'failed'        => 'Failed',
];

$flash = courseTakeFlash();

// ============================================================================
// HERO CONTEXT
// ============================================================================
$heroImageUrl = '/uploads/myislam-academyc.png';
$heroTitle    = 'My results';
$heroSubtitle = 'Weekly quizzes, monthly exams, and finals — grouped by course.';

$heroCourse = null;
if ($courseFilter > 0 && isset($myCoursesForFilter[$courseFilter])) {
    // the selected course always drives the hero, whatever the type filter leaves in the list
    $heroCourse = [
        'title' => $myCoursesForFilter[$courseFilter]['title'],
        'image' => $myCoursesForFilter[$courseFilter]['image'],
    ];
} elseif (!empty($byCourse)) {
    $firstGroup = reset($byCourse);
    $heroCourse = [
        'title' => $firstGroup['course_title'],
        'image' => $firstGroup['course_image'],
    ];
}

if ($heroCourse && $heroCourse['title'] !== '') {
    $heroTitle = 'My results — ' . $heroCourse['title'];
    $heroSubtitle = 'Weekly quizzes, monthly exams, and finals in this course.';

    $imgCandidate = trim((string)$heroCourse['image']);
    if ($imgCandidate !== '') {
        if (preg_match('~^(https?:)?//~i', $imgCandidate)) {
            $heroImageUrl = $imgCandidate;
        } else {
            $rel = '/' . ltrim($imgCandidate, '/');
            if (is_file(($_SERVER['DOCUMENT_ROOT'] ?? '') . $rel) || is_file(__DIR__ . $rel)) {
                $heroImageUrl = $rel;
            }
        }
    }
}

function mr_course_image(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '') return null;
    if (preg_match('~^(https?:)?//~i', $path)) return $path;
    $rel = '/' . ltrim($path, '/');
    if (is_file(($_SERVER['DOCUMENT_ROOT'] ?? '') . $rel) || is_file(__DIR__ . $rel)) {
        return $rel;
    }
    return null;
}

courseShellStart($ctx, 'My results');
?>
<style>
.mr {
    --mr-brand: var(--brand, #0E4A34);
    --mr-gold: var(--gold, #9C7A2E);
    --mr-danger: var(--danger, #b91c1c);
    --mr-surface: var(--surface, #fff);
    --mr-surface-2: var(--surface-2, #f9fafb);
    --mr-line: var(--line, #e2e5e2);
    --mr-text: var(--text, #1f2937);
    --mr-muted: var(--muted, #6b7280);
    max-width: 1100px;
    margin: 0 auto;
    display: grid;
    gap: 12px;
}
.mr * { box-sizing: border-box; }

.mr-hero {
    position: relative;
    overflow: hidden;
    border-radius: 10px;
    padding: clamp(22px, 4vw, 32px) clamp(16px, 4vw, 28px);
    color: #fff;
    min-height: 160px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    background:
        linear-gradient(rgba(10,40,30,.45), rgba(10,40,30,.75)),
        url('<?php echo e($heroImageUrl); ?>') center/cover no-repeat,
        #0E4A34;
    width: 100%;
}
.mr-hero > * { position: relative; z-index: 1; }
.mr-hero h1 {
    margin: 0 0 8px;
    font-size: clamp(22px, 4.5vw, 32px);
    font-weight: 900;
    color: #D9BC72;
    letter-spacing: .3px;
    text-shadow: 0 2px 8px rgba(0,0,0,.7), 0 1px 2px rgba(0,0,0,.5);
    line-height: 1.2;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.mr-hero p {
    margin: 0;
    font-size: clamp(11.5px, 2.8vw, 13px);
    color: rgba(255,255,255,.92);
    text-shadow: 0 1px 3px rgba(0,0,0,.6);
    max-width: 560px;
    line-height: 1.5;
}

.mr-filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    background: var(--mr-surface);
    border: 1px solid var(--mr-line);
    border-radius: 5px;
}
.mr-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    flex: 1;
    min-width: 0;
}
.mr-chip {
    display: inline-flex;
    align-items: center;
    padding: 5px 11px;
    border-radius: 5px;
    background: var(--mr-surface);
    border: 1px solid var(--mr-line);
    font-size: 11.5px;
    font-weight: 700;
    color: var(--mr-text);
    text-decoration: none;
    transition: all .15s;
    white-space: nowrap;
}
.mr-chip:hover { border-color: var(--mr-brand); color: var(--mr-brand); }
.mr-chip.active {
    background: var(--mr-brand);
    border-color: var(--mr-brand);
    color: #fff;
}

.mr-course-select {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
    margin-left: auto;
}
.mr-course-select label {
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--mr-muted);
    white-space: nowrap;
}
.mr-course-select select {
    padding: 5px 9px;
    border-radius: 5px;
    border: 1px solid var(--mr-line);
    background: var(--mr-surface);
    color: var(--mr-text);
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    max-width: 220px;
}
.mr-course-select select:hover { border-color: var(--mr-gold); }

@media (max-width: 640px) {
    .mr-filter-bar { padding: 6px 8px; gap: 4px; }
    .mr-chip { padding: 4px 9px; font-size: 11px; }
    .mr-course-select { margin-left: 0; width: 100%; }
    .mr-course-select select { flex: 1; max-width: none; }
}

.mr-course-group {
    background: var(--mr-surface);
    border: 1px solid var(--mr-line);
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.mr-course-group:last-child { margin-bottom: 0; }

.mr-course-banner {
    position: relative;
    padding: 14px 16px;
    color: #fff;
    background: linear-gradient(135deg, var(--mr-brand) 0%, var(--brand-dark, #0A3626) 100%);
    background-size: cover;
    background-position: center;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.mr-course-banner.has-image {
    background-size: cover, cover, cover;
    background-position: center, center, center;
    background-repeat: no-repeat, no-repeat, no-repeat;
}
.mr-course-banner > * { position: relative; z-index: 1; }
.mr-course-banner .info { flex: 1; min-width: 0; }
.mr-course-banner h2 {
    margin: 0 0 3px;
    font-size: clamp(14px, 3vw, 16px);
    font-weight: 800;
    color: #D9BC72;
    text-shadow: 0 1px 3px rgba(0,0,0,.55);
    line-height: 1.25;
    overflow-wrap: anywhere;
}
.mr-course-banner .banner-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 10px;
    font-size: 11px;
    color: rgba(255,255,255,.9);
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0,0,0,.5);
}
.mr-course-banner .banner-meta span {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    white-space: nowrap;
}
.mr-course-banner .banner-stats {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.mr-course-banner .bst {
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.35);
    border-radius: 5px;
    padding: 4px 9px;
    text-align: center;
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
    font-size: 10px;
    font-weight: 800;
    color: #fff;
    min-width: 42px;
}
.mr-course-banner .bst .num {
    display: block;
    font-size: 13px;
    font-weight: 900;
    line-height: 1.1;
}
.mr-course-banner .bst.pass .num { color: #b6f0c9; }
.mr-course-banner .bst.fail .num { color: #ffb4b4; }
.mr-course-banner .bst.avg  .num { color: #F0D896; }
.mr-course-banner .bst.pts  .num { color: #F0D896; }

.mr-course-attempts {
    padding: 10px;
    display: grid;
    gap: 6px;
}

.mr-div-section {
    padding: 12px 14px;
    border-bottom: 1px solid var(--mr-line);
    background: var(--mr-surface-2);
}
.mr-div-section:last-child { border-bottom: none; }
.mr-div-title {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 8px;
    font-size: 12.5px;
    font-weight: 800;
    color: var(--mr-text);
}
.mr-div-title .badge-div {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 5px;
    background: var(--mr-brand);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .3px;
    text-transform: uppercase;
}
.mr-div-title .badge-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 5px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
}
.mr-div-title .badge-status.passed { background: rgba(21,128,61,.12); color: #15803d; }
.mr-div-title .badge-status.failed { background: rgba(185,28,28,.10); color: var(--mr-danger); }
.mr-div-title .badge-status.pending { background: var(--mr-surface-3, #eef2f7); color: var(--mr-muted); }
.mr-div-title .avg {
    margin-left: auto;
    font-size: 11px;
    color: var(--mr-muted);
    font-weight: 700;
}

/* POINTS model subject cards */
.mr-subject-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 6px;
}
.mr-subject-row {
    display: grid;
    grid-template-columns: 18px minmax(0, 1fr) auto;
    gap: 8px;
    align-items: center;
    padding: 8px 10px;
    background: var(--mr-surface);
    border: 1px solid var(--mr-line);
    border-radius: 6px;
    font-size: 11.5px;
    min-width: 0;
}
.mr-subject-row .s-icon { font-size: 13px; flex-shrink: 0; }
.mr-subject-row .s-body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.mr-subject-row .s-name {
    font-weight: 700;
    color: var(--mr-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mr-subject-row .s-points {
    font-size: 10px;
    color: var(--mr-muted);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.mr-subject-row .s-grade {
    flex-shrink: 0;
    font-weight: 800;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    display: inline-flex;
    gap: 4px;
    align-items: baseline;
}
.mr-subject-row .s-grade .lt {
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: .2px;
}
.mr-subject-row .s-grade.pass { background: rgba(21,128,61,.12); color: #15803d; }
.mr-subject-row .s-grade.fail { background: rgba(185,28,28,.10); color: var(--mr-danger); }
.mr-subject-row .s-grade.pending { background: var(--mr-surface-3, #eef2f7); color: var(--mr-muted); }

.mr-list { display: grid; gap: 6px; }
.mr-row {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
    padding: 10px 12px;
    background: var(--mr-surface);
    border: 1px solid var(--mr-line);
    border-left: 3px solid var(--mr-brand);
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    transition: transform .12s, box-shadow .12s, border-color .12s;
}
.mr-row:hover {
    border-color: var(--mr-brand);
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}
.mr-row.is-failed  { border-left-color: var(--mr-danger); }
.mr-row.is-supp    { border-left-color: var(--mr-gold); }
.mr-row.is-monthly { border-left-color: #3E6E91; }
.mr-row.is-weekly  { border-left-color: var(--mr-gold); }
.mr-row.expanded   { border-color: var(--mr-brand); }

.mr-icon {
    width: 34px; height: 34px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    background: var(--brand-soft, rgba(14,74,52,.08));
    color: var(--mr-brand);
}
.mr-row.is-failed  .mr-icon { background: rgba(185,28,28,.10); color: var(--mr-danger); }
.mr-row.is-supp    .mr-icon { background: rgba(156,122,46,.12); color: var(--mr-gold); }
.mr-row.is-monthly .mr-icon { background: rgba(62,110,145,.10); color: #3E6E91; }
.mr-row.is-weekly  .mr-icon { background: rgba(156,122,46,.12); color: var(--mr-gold); }

.mr-body { min-width: 0; }
.mr-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--mr-text);
    margin-bottom: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mr-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 10px;
    font-size: 10.5px;
    color: var(--mr-muted);
    font-weight: 600;
}
.mr-meta span {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
}

.mr-right {
    text-align: right;
    white-space: nowrap;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
}
.mr-score {
    font-size: 14px;
    font-weight: 800;
    line-height: 1.1;
    color: var(--mr-brand);
}
.mr-score.fail { color: var(--mr-danger); }
.mr-score small {
    font-size: 10px;
    color: var(--mr-muted);
    font-weight: 700;
    margin-left: 1px;
}
.mr-pill {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.mr-pill.pass { background: var(--brand-soft, rgba(14,74,52,.10)); color: var(--mr-brand); }
.mr-pill.fail { background: rgba(185,28,28,.10); color: var(--mr-danger); }

.mr-pct {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--mr-muted);
}

.mr-empty {
    padding: 30px 16px;
    text-align: center;
    color: var(--mr-muted);
    font-size: 12.5px;
    background: var(--mr-surface);
    border: 1px dashed var(--mr-line);
    border-radius: 10px;
    line-height: 1.6;
}
.mr-empty .em { font-size: 34px; margin-bottom: 8px; display: block; }

.mr-detail {
    margin: 2px 0 10px;
    padding: 14px 16px;
    background: var(--mr-surface-2, #f9fafb);
    border: 1px solid var(--mr-line);
    border-radius: 10px;
    animation: mrSlide .18s ease-out;
}
@keyframes mrSlide {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
.mr-detail-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px dashed var(--mr-line);
}
.mr-detail-head h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 800;
    color: var(--mr-text);
}
.mr-detail-close {
    background: transparent;
    border: 1px solid var(--mr-line);
    border-radius: 999px;
    padding: 4px 12px;
    font-size: 11px;
    font-weight: 700;
    color: var(--mr-muted);
    cursor: pointer;
}
.mr-detail-close:hover { border-color: var(--mr-danger); color: var(--mr-danger); }

.mr-detail-loading {
    padding: 24px 8px;
    text-align: center;
    color: var(--mr-muted);
    font-size: 12px;
}

.mr-detail-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    gap: 16px;
}
@media (max-width: 720px) {
    .mr-detail-grid { grid-template-columns: 1fr; }
}

.mr-section-title {
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: var(--mr-muted);
    margin-bottom: 8px;
}

.mr-q {
    padding: 10px 12px;
    background: var(--mr-surface);
    border: 1px solid var(--mr-line);
    border-left: 3px solid var(--mr-brand);
    border-radius: 8px;
    margin-bottom: 6px;
}
.mr-q.wrong { border-left-color: var(--mr-danger); }
.mr-q-head {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--mr-text);
    margin-bottom: 6px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}
.mr-q-head .num {
    background: var(--mr-surface-2, #f9fafb);
    border-radius: 999px;
    padding: 1px 7px;
    font-size: 10px;
    flex-shrink: 0;
    color: var(--mr-muted);
    font-weight: 800;
}
.mr-q-line {
    display: flex;
    gap: 6px;
    font-size: 11.5px;
    margin-bottom: 2px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}
.mr-q-line .k { color: var(--mr-muted); font-weight: 700; flex-shrink: 0; min-width: 62px; }
.mr-q-line .val { color: var(--mr-text); font-weight: 600; }
.mr-q-line .val.good { color: var(--mr-brand); }
.mr-q-line .val.bad  { color: var(--mr-danger); }
.mr-q-feedback {
    font-size: 11px;
    color: var(--mr-muted);
    font-style: italic;
    margin-top: 4px;
}

.mr-parts { display: grid; gap: 4px; }
.mr-part {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 9px;
    background: var(--mr-surface);
    border: 1px solid var(--mr-line);
    border-radius: 8px;
    font-size: 11.5px;
}
.mr-part.is-me {
    border-color: var(--mr-brand);
    background: var(--brand-soft, rgba(14,74,52,.06));
}
.mr-part-rank {
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--mr-surface-2, #f9f9f9);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800; color: var(--mr-muted);
    flex-shrink: 0;
}
.mr-part.rank-1 .mr-part-rank { background: #C6A64B; color: #fff; }
.mr-part.rank-2 .mr-part-rank { background: #8FD3E8; color: #0f1720; }
.mr-part.rank-3 .mr-part-rank { background: #6C5CC0; color: #fff; }
.mr-part-avatar {
    width: 26px; height: 26px; border-radius: 50%;
    background: linear-gradient(135deg, var(--mr-brand), var(--brand-dark, #0A3626));
    color: #fff; font-weight: 800; font-size: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
}
.mr-part-avatar img { width: 100%; height: 100%; object-fit: cover; }
.mr-part-name {
    flex: 1; min-width: 0;
    font-weight: 700; color: var(--mr-text);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.mr-part-score {
    font-weight: 800; color: var(--mr-gold); white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.mr-rank-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    background: var(--mr-gold);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

@media (max-width: 640px) {
    .mr-hero { padding: 20px 14px; min-height: 130px; }
    .mr-hero h1 { font-size: 20px; }

    .mr-course-banner { padding: 12px 14px; }
    .mr-course-banner h2 { font-size: 14px; }
    .mr-course-banner .bst { padding: 3px 7px; font-size: 9px; min-width: 38px; }
    .mr-course-banner .bst .num { font-size: 12px; }
    .mr-course-attempts { padding: 8px; }
}
@media (max-width: 560px) {
    .mr-row {
        grid-template-columns: 32px minmax(0, 1fr);
        padding: 9px 10px;
        gap: 8px;
    }
    .mr-icon { width: 30px; height: 30px; font-size: 13px; }
    .mr-right {
        grid-column: 1 / -1;
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        padding-top: 5px;
        border-top: 1px dashed var(--mr-line);
        margin-top: 3px;
    }
    .mr-title { font-size: 12.5px; }
    .mr-score { font-size: 13px; }
    .mr-meta span { max-width: 130px; }
    .mr-q-line .k { min-width: 55px; }
}
@media (max-width: 400px) {
    .mr-hero { padding: 16px 12px; min-height: 110px; }
    .mr-hero h1 { font-size: 17px; }
}
@media (max-width: 380px) {
    .mr-course-banner .bst { min-width: 34px; padding: 2px 6px; font-size: 8.5px; }
    .mr-course-banner .bst .num { font-size: 11px; }
}
</style>

<div class="mr">

    <?php if ($flash): ?>
        <div class="flash <?php echo e($flash[0]); ?>" role="status"><?php echo e($flash[1]); ?></div>
    <?php endif; ?>

    <section class="mr-hero">
        <h1><?php echo e($heroTitle); ?></h1>
        <p><?php echo e($heroSubtitle); ?></p>
    </section>

    <?php if (!$enrolledCourseIds): ?>
        <div class="mr-empty">
            <span class="em">📚</span>
            You are not enrolled in any course yet.<br>
            <a href="courses.php" style="color:var(--mr-brand);font-weight:700;text-decoration:underline;">Browse courses →</a>
        </div>
    <?php else: ?>

    <section class="mr-filter-bar">
        <div class="mr-filters">
            <?php foreach ($filters as $key => $label):
                $isActive = ($filter === $key);
                $qs = [];
                if ($key !== 'all') $qs[] = 'filter=' . urlencode($key);
                if ($courseFilter > 0) $qs[] = 'course_id=' . (int)$courseFilter;
                $href = 'my_results.php' . ($qs ? '?' . implode('&', $qs) : '');
            ?>
                <a class="mr-chip <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo e($href); ?>">
                    <?php echo e($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (count($myCoursesForFilter) > 1): ?>
            <div class="mr-course-select">
                <label for="mrCourseFilter">Course:</label>
                <select id="mrCourseFilter" onchange="location.href = this.value;">
                    <?php
                    $allQs = [];
                    if ($filter !== 'all') $allQs[] = 'filter=' . urlencode($filter);
                    $allHref = 'my_results.php' . ($allQs ? '?' . implode('&', $allQs) : '');
                    ?>
                    <option value="<?php echo e($allHref); ?>" <?php echo $courseFilter === 0 ? 'selected' : ''; ?>>
                        All courses
                    </option>
                    <?php foreach ($myCoursesForFilter as $c):
                        $optQs = [];
                        if ($filter !== 'all') $optQs[] = 'filter=' . urlencode($filter);
                        $optQs[] = 'course_id=' . (int)$c['id'];
                        $optHref = 'my_results.php?' . implode('&', $optQs);
                    ?>
                        <option value="<?php echo e($optHref); ?>" <?php echo $courseFilter === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo e(mb_strimwidth($c['title'], 0, 40, '…')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!$attempts && empty($subjectGradesByCourse) && empty($divisionHistoryByCourse)): ?>
        <div class="mr-empty">
            <span class="em">📭</span>
            <?php if ($courseFilter > 0 || $filter !== 'all'): ?>
                No attempts match the current filter.
            <?php else: ?>
                You haven't taken any quiz or exam yet.<br>
                Your results will appear here once you submit one.
            <?php endif; ?>
        </div>
    <?php else: ?>

        <?php
        $courseGroupIds = [];
        foreach ($byCourse as $group) {
            if ($group['course_id'] > 0) $courseGroupIds[$group['course_id']] = true;
        }
        foreach ($subjectGradesByCourse as $cid => $_) {
            if (isset($userEnrollments[$cid])) $courseGroupIds[$cid] = true;
        }
        foreach ($divisionHistoryByCourse as $cid => $_) {
            if (isset($userEnrollments[$cid])) $courseGroupIds[$cid] = true;
        }
        if ($courseFilter > 0) {
            foreach ($courseGroupIds as $cid => $_) {
                if ($cid !== $courseFilter) unset($courseGroupIds[$cid]);
            }
        }

        $courseMeta = [];
        foreach ($byCourse as $group) {
            if ($group['course_id'] > 0) {
                $courseMeta[$group['course_id']] = [
                    'title' => $group['course_title'],
                    'image' => $group['course_image'],
                ];
            }
        }
        foreach ($myCoursesForFilter as $cid => $m) {
            if (!isset($courseMeta[$cid])) {
                $courseMeta[$cid] = ['title' => $m['title'], 'image' => $m['image'] ?? ''];
            }
        }

        $courseGroupOrder = array_keys($courseGroupIds);
        usort($courseGroupOrder, function ($a, $b) use ($byCourse) {
            $aTime = 0; $bTime = 0;
            foreach ($byCourse as $g) {
                if ($g['course_id'] === $a) $aTime = strtotime((string)($g['attempts'][0]['completed_at'] ?? '0'));
                if ($g['course_id'] === $b) $bTime = strtotime((string)($g['attempts'][0]['completed_at'] ?? '0'));
            }
            return $bTime <=> $aTime;
        });
        ?>

        <?php foreach ($courseGroupOrder as $cid):
            $meta = $courseMeta[$cid] ?? ['title' => 'Course #' . $cid, 'image' => ''];
            $group = $byCourse['c' . $cid] ?? null;
            $groupAttempts = $group['attempts'] ?? [];
            $groupPassed = $group['passed'] ?? 0;
            $groupFailed = $group['failed'] ?? 0;
            $groupTotal  = $group['total'] ?? 0;
            $groupPts    = $group['points_earned'] ?? 0;
            // share of the points available in the listed attempts (derived from points)
            $groupPossible = (int)($group['points_possible'] ?? 0);
            $groupAvg    = $groupPossible > 0 ? ($groupPts / $groupPossible * 100) : 0;
            $imgUrl      = mr_course_image($meta['image']);

            $divs = [];
            foreach (($group['by_division'] ?? []) as $did => $rows) {
                $divs[$did] = $rows;
            }
            foreach (($subjectGradesByCourse[$cid] ?? []) as $did => $_) {
                if (!isset($divs[$did])) $divs[$did] = [];
            }
            foreach (($divisionHistoryByCourse[$cid] ?? []) as $did => $_) {
                if (!isset($divs[$did])) $divs[$did] = [];
            }
            ksort($divs);
        ?>
            <section class="mr-course-group">
                <div class="mr-course-banner <?php echo $imgUrl ? 'has-image' : ''; ?>"
                     style="background-image: linear-gradient(rgba(10,40,30,.55), rgba(10,40,30,.82))<?php echo $imgUrl ? ", url('" . e($imgUrl) . "')" : ', linear-gradient(135deg, var(--mr-brand) 0%, #0A3626 100%)'; ?>; background-size: cover; background-position: center;">
                    <div class="info">
                        <h2><?php echo e($meta['title']); ?></h2>
                        <div class="banner-meta">
                            <span>📝 <?php echo (int)$groupTotal; ?> attempt<?php echo $groupTotal === 1 ? '' : 's'; ?></span>
                            <span>🎯 <?php echo number_format((int)$groupPts); ?><?php echo $groupPossible > 0 ? ' / ' . number_format($groupPossible) : ''; ?> pts</span>
                        </div>
                    </div>
                    <div class="banner-stats">
                        <div class="bst pass"><span class="num"><?php echo (int)$groupPassed; ?></span>Pass</div>
                        <div class="bst fail"><span class="num"><?php echo (int)$groupFailed; ?></span>Fail</div>
                        <div class="bst avg"><span class="num"><?php echo number_format($groupAvg, 0); ?>%</span>Avg</div>
                        <div class="bst pts"><span class="num"><?php echo number_format((int)$groupPts); ?></span>Pts</div>
                    </div>
                </div>

                <?php if (!empty($divs)): ?>
                    <?php foreach ($divs as $did => $divAttempts):
                        $divInfo  = $did === 0
                            ? ['name' => 'Other attempts', 'number' => 0]
                            : ($divisionLabels[$did] ?? ['name' => 'Division #' . $did, 'number' => $did]);
                        $hist     = $divisionHistoryByCourse[$cid][$did] ?? null;
                        $subjects = $subjectGradesByCourse[$cid][$did] ?? [];

                        $divStatus = $hist ? (string)$hist['status'] : 'pending';
                        $divAvg = ($hist && $hist['average_grade'] !== null) ? (float)$hist['average_grade'] : null;
                    ?>
                        <div class="mr-div-section">
                            <div class="mr-div-title">
                                <span class="badge-div">📚 <?php echo e($divInfo['name']); ?></span>
                                <span class="badge-status <?php echo e($divStatus === 'passed' ? 'passed' : ($divStatus === 'failed' ? 'failed' : 'pending')); ?>">
                                    <?php
                                    if ($divStatus === 'passed') echo 'Passed';
                                    elseif ($divStatus === 'failed') echo 'Failed';
                                    else echo 'In progress';
                                    ?>
                                </span>
                                <?php if ($divAvg !== null): ?>
                                    <span class="avg">Avg: <?php echo number_format($divAvg, 1); ?> pts</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($subjects)): ?>
                                <div class="mr-subject-grid" style="margin-bottom:8px;">
                                    <?php foreach ($subjects as $sid => $sg):
                                        $calc = mr_compute_subject($sg);
                                        $gradeCls = $calc['decided']
                                            ? ($calc['is_pass'] ? 'pass' : 'fail')
                                            : 'pending';
                                    ?>
                                        <div class="mr-subject-row">
                                            <span class="s-icon"><?php echo e($sg['subject_icon'] ?: '📖'); ?></span>
                                            <span class="s-body">
                                                <span class="s-name" title="<?php echo e($sg['subject_name']); ?>"><?php echo e($sg['subject_name']); ?></span>
                                                <span class="s-points">
                                                    <?php echo (int)round($calc['earned']); ?> / <?php echo (int)$calc['total']; ?> pts
                                                    · <?php echo number_format($calc['percent'], 1); ?>%
                                                </span>
                                            </span>
                                            <span class="s-grade <?php echo $gradeCls; ?>">
                                                <span class="lt"><?php echo e($calc['letter']); ?></span>
                                                <span><?php echo e($calc['remark']); ?></span>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($divAttempts)): ?>
                                <div class="mr-list">
                                    <?php foreach ($divAttempts as $a):
                                        $passed = ((int)$a['passed']) === 1;
                                        $source = (string)$a['source'];
                                        $examType = (string)$a['exam_type'];
                                        $rowClass = ''; $icon = '🎓'; $typeLabel = 'Final exam';
                                        if ($source === 'weekly') { $rowClass = 'is-weekly'; $icon = '📝'; $typeLabel = 'Weekly quiz'; }
                                        elseif ($source === 'monthly') { $rowClass = 'is-monthly'; $icon = '📅'; $typeLabel = 'Monthly exam'; }
                                        elseif ($examType === 'supplementary') { $rowClass = 'is-supp'; $icon = '📚'; $typeLabel = 'Supplementary'; }
                                        elseif (!$passed) { $rowClass = 'is-failed'; }
                                        $score    = (int)$a['score'];
                                        $totalPts = (int)$a['total_points'];
                                        $pct      = (float)$a['percentage'];
                                        $completedAt = !empty($a['completed_at']) ? strtotime((string)$a['completed_at']) : null;
                                        $dateLabel = $completedAt ? date('M d, Y', $completedAt) : '—';
                                    ?>
                                        <div class="mr-row <?php echo $rowClass; ?>"
                                             data-attempt-row
                                             data-source="<?php echo e($source); ?>"
                                             data-attempt-id="<?php echo (int)$a['attempt_id']; ?>"
                                             data-exam-id="<?php echo (int)$a['exam_id']; ?>"
                                             role="button"
                                             tabindex="0">
                                            <div class="mr-icon"><?php echo $icon; ?></div>
                                            <div class="mr-body">
                                                <div class="mr-title"><?php echo e($a['title'] ?: 'Untitled'); ?></div>
                                                <div class="mr-meta">
                                                    <?php if (!empty($a['subject_name'])): ?>
                                                        <span><?php echo e($a['subject_icon'] ?: '📖'); ?> <?php echo e($a['subject_name']); ?></span>
                                                    <?php endif; ?>
                                                    <span>🏷️ <?php echo e($typeLabel); ?></span>
                                                    <span>📅 <?php echo e($dateLabel); ?></span>
                                                </div>
                                            </div>
                                            <div class="mr-right">
                                                <div class="mr-score <?php echo $passed ? '' : 'fail'; ?>">
                                                    <?php echo $score; ?><small> / <?php echo $totalPts; ?></small>
                                                </div>
                                                <span class="mr-pct"><?php echo number_format($pct, 1); ?>%</span>
                                                <span class="mr-pill <?php echo $passed ? 'pass' : 'fail'; ?>">
                                                    <?php echo $passed ? 'Pass' : 'Fail'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mr-course-attempts mr-list">
                        <?php foreach ($groupAttempts as $a):
                            $passed = ((int)$a['passed']) === 1;
                            $source = (string)$a['source'];
                            $examType = (string)$a['exam_type'];
                            $rowClass = ''; $icon = '🎓'; $typeLabel = 'Final exam';
                            if ($source === 'weekly') { $rowClass = 'is-weekly'; $icon = '📝'; $typeLabel = 'Weekly quiz'; }
                            elseif ($source === 'monthly') { $rowClass = 'is-monthly'; $icon = '📅'; $typeLabel = 'Monthly exam'; }
                            elseif ($examType === 'supplementary') { $rowClass = 'is-supp'; $icon = '📚'; $typeLabel = 'Supplementary'; }
                            elseif (!$passed) { $rowClass = 'is-failed'; }
                            $score    = (int)$a['score'];
                            $totalPts = (int)$a['total_points'];
                            $pct      = (float)$a['percentage'];
                            $completedAt = !empty($a['completed_at']) ? strtotime((string)$a['completed_at']) : null;
                            $dateLabel = $completedAt ? date('M d, Y', $completedAt) : '—';
                        ?>
                            <div class="mr-row <?php echo $rowClass; ?>"
                                 data-attempt-row
                                 data-source="<?php echo e($source); ?>"
                                 data-attempt-id="<?php echo (int)$a['attempt_id']; ?>"
                                 data-exam-id="<?php echo (int)$a['exam_id']; ?>"
                                 role="button"
                                 tabindex="0">
                                <div class="mr-icon"><?php echo $icon; ?></div>
                                <div class="mr-body">
                                    <div class="mr-title"><?php echo e($a['title'] ?: 'Untitled'); ?></div>
                                    <div class="mr-meta">
                                        <?php if (!empty($a['subject_name'])): ?>
                                            <span><?php echo e($a['subject_icon'] ?: '📖'); ?> <?php echo e($a['subject_name']); ?></span>
                                        <?php endif; ?>
                                        <span>🏷️ <?php echo e($typeLabel); ?></span>
                                        <span>📅 <?php echo e($dateLabel); ?></span>
                                    </div>
                                </div>
                                <div class="mr-right">
                                    <div class="mr-score <?php echo $passed ? '' : 'fail'; ?>">
                                        <?php echo $score; ?><small> / <?php echo $totalPts; ?></small>
                                    </div>
                                    <span class="mr-pct"><?php echo number_format($pct, 1); ?>%</span>
                                    <span class="mr-pill <?php echo $passed ? 'pass' : 'fail'; ?>">
                                        <?php echo $passed ? 'Pass' : 'Fail'; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

    <?php endif; ?>
    <?php endif; // $enrolledCourseIds ?>

</div>

<script>
(function () {
    document.addEventListener('click', function (e) {
        const row = e.target.closest('[data-attempt-row]');
        if (!row) return;

        const wasOpen = row.classList.contains('expanded');
        document.querySelectorAll('.mr-detail').forEach(d => d.remove());
        document.querySelectorAll('.mr-row.expanded').forEach(r => r.classList.remove('expanded'));

        if (wasOpen) return;

        row.classList.add('expanded');

        const detail = document.createElement('div');
        detail.className = 'mr-detail';
        detail.innerHTML = '<div class="mr-detail-loading">Loading…</div>';
        row.insertAdjacentElement('afterend', detail);

        const source = row.dataset.source;
        const attemptId = row.dataset.attemptId;

        const detailUrl = (source === 'weekly')
            ? 'my_results.php?ajax=weekly_detail&attempt_id=' + encodeURIComponent(attemptId)
            : 'my_results_detail.php?source=' + encodeURIComponent(source)
              + '&attempt_id=' + encodeURIComponent(attemptId);

        fetch(detailUrl, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (data) {
                if (!data.ok) {
                    detail.innerHTML = '<div class="mr-detail-loading">' + (data.message || 'Unable to load details.') + '</div>';
                    return;
                }

                const a = data.attempt;
                const meta = data.meta;
                const rankLabel = a.my_rank ? '#' + a.my_rank : '—';

                const head =
                    '<div class="mr-detail-head">'
                  +   '<h3>' + meta.icon + ' ' + meta.title + '</h3>'
                  +   '<div style="display:flex;gap:6px;align-items:center;">'
                  +     '<span class="mr-rank-badge">Your rank ' + rankLabel + '</span>'
                  +     '<button type="button" class="mr-detail-close" data-close>✕ Close</button>'
                  +   '</div>'
                  + '</div>';

                let qHtml = '<div class="mr-detail-loading">No per-question detail available.</div>';
                if (data.questions && data.questions.length) {
                    qHtml = '';
                    data.questions.forEach(function (q, i) {
                        const wrong = q.is_correct ? '' : 'wrong';
                        qHtml += '<div class="mr-q ' + wrong + '">'
                              +   '<div class="mr-q-head">'
                              +     '<span class="num">#' + (i + 1) + '</span>'
                              +     '<span>' + q.question_text + '</span>'
                              +   '</div>'
                              +   '<div class="mr-q-line">'
                              +     '<span class="k">Your answer:</span>'
                              +     '<span class="val ' + (q.is_correct ? 'good' : 'bad') + '">'
                              +       (q.user_answer || '<em>—</em>')
                              +     '</span>'
                              +   '</div>';
                        if (!q.is_correct && q.correct_answer) {
                            qHtml += '<div class="mr-q-line">'
                                  +   '<span class="k">Correct:</span>'
                                  +   '<span class="val good">' + q.correct_answer + '</span>'
                                  + '</div>';
                        }
                        if (q.feedback) {
                            qHtml += '<div class="mr-q-feedback">💡 ' + q.feedback + '</div>';
                        }
                        qHtml += '</div>';
                    });
                }

                let pHtml = '<div class="mr-detail-loading">No participants yet.</div>';
                if (data.participants && data.participants.length) {
                    pHtml = '<div class="mr-parts">';
                    data.participants.forEach(function (p) {
                        const cls = p.is_me ? ' is-me' : '';
                        const rankCls = 'rank-' + p.rank;
                        const avatar = p.profile_image
                            ? '<img src="' + p.profile_image + '" alt="">'
                            : p.initials;
                        pHtml += '<div class="mr-part ' + rankCls + cls + '">'
                              +   '<div class="mr-part-rank">#' + p.rank + '</div>'
                              +   '<div class="mr-part-avatar">' + avatar + '</div>'
                              +   '<div class="mr-part-name">' + p.display_name + '</div>'
                              +   '<div class="mr-part-score">'
                              +     (p.points !== undefined ? p.points + ' pts' : p.best_pct.toFixed(1) + '%')
                              +   '</div>'
                              + '</div>';
                    });
                    pHtml += '</div>';
                }

                detail.innerHTML = head
                    + '<div class="mr-detail-grid">'
                    +   '<div><div class="mr-section-title">📝 Questions</div>' + qHtml + '</div>'
                    +   '<div><div class="mr-section-title">🏆 Top participants</div>' + pHtml + '</div>'
                    + '</div>';

                detail.querySelector('[data-close]').addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    detail.remove();
                    row.classList.remove('expanded');
                });
            })
            .catch(function () {
                detail.innerHTML = '<div class="mr-detail-loading">Network error.</div>';
            });
    });

    document.addEventListener('keydown', function (e) {
        const row = e.target.closest('[data-attempt-row]');
        if (row && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            row.click();
        }
    });
})();
</script>

<?php
courseShellEnd();