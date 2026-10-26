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
    $courseId = (int)($_POST['course_id'] ?? 0);

    try {
        if ($action === 'enroll' && $courseId > 0) {
            [$ok, $message, $enrollmentId] = courseEnrollStudent($conn, $userId, $courseId);
            courseFlash($ok ? 'success' : 'error', $message);
            if ($ok) {
                courseRedirect('course.php?course_id=' . $courseId);
            }
        } elseif ($action === 'leave' && $courseId > 0) {
            $conn->prepare("
                UPDATE course_enrollments
                SET status = 'dropped', completed_at = NOW()
                WHERE course_id = ? AND user_id = ? AND status = 'active'
            ")->execute([$courseId, $userId]);
            courseFlash('info', 'You have left the course. You can re-join whenever a new division is announced.');
        }
    } catch (PDOException $e) {
        error_log('courses.php action error: ' . $e->getMessage());
        courseFlash('error', 'Something went wrong. Please try again.');
    }

    courseRedirect('courses.php');
}

// ============================================================================
// DATA — enrollments + available
// ============================================================================

$myEnrollments = [];
$available = [];

if ($tablesReady) {
    try {
        $myEnrollments = courseLoadMyEnrollments($conn, $userId);

        if ($myEnrollments) {
            $ids = array_column($myEnrollments, 'course_id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("SELECT id, image_path FROM courses WHERE id IN ($in)");
            $stmt->execute($ids);
            $imageMap = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $imageMap[(int)$r['id']] = (string)($r['image_path'] ?? '');
            }
            foreach ($myEnrollments as &$en) {
                $en['_course_image'] = $imageMap[(int)$en['course_id']] ?? '';
            }
            unset($en);
        }

        foreach ($myEnrollments as &$en) {
            $progress = ['week' => 0, 'total_weeks' => 0, 'pct' => 0, 'state' => 'pending', 'days_remaining' => 0];
            if (!empty($en['current_div_start'])) {
                $progress = courseComputeDivisionProgress([
                    'start_date' => $en['current_div_start'],
                    'end_date'   => $en['current_div_end'],
                ], $today);
            }
            $en['_progress'] = $progress;

            $en['_avg_grade']   = null;
            $en['_div_status']  = null;
            $en['_exams_ready'] = 0;

            if (!empty($en['current_div_id'])) {
                $enrollId       = (int)$en['id'];
                $divId          = (int)$en['current_div_id'];
                $courseIdForRow = (int)$en['course_id'];

                // ------------------------------------------------------------------
                // POINTS model (same as course.php):
                //   subject mark   = quiz_total_points + exam_total_points + final_total_points  (normally 100)
                //   points earned  = weekly + monthly + best(final, supplementary)   — each part capped at its budget
                //   _avg_grade     = average points earned per subject in this division
                // ------------------------------------------------------------------
                try {
                    $gStmt = $conn->prepare("
                        SELECT
                            AVG(
                                LEAST(
                                    LEAST(g.weekly_total,  cs.quiz_total_points)
                                  + LEAST(g.monthly_total, cs.exam_total_points)
                                  + LEAST(
                                        GREATEST(
                                            COALESCE(g.final_score, 0),
                                            CASE WHEN g.supplementary_attempted = 1
                                                 THEN LEAST(COALESCE(g.supplementary_score, 0), cs.supplementary_max_score)
                                                 ELSE 0 END
                                        ),
                                        cs.final_total_points
                                    ),
                                    cs.quiz_total_points + cs.exam_total_points + cs.final_total_points
                                )
                            ) AS avg_points,
                            COUNT(*) AS graded
                        FROM enrollment_course_grades g
                        JOIN course_subjects cs
                             ON cs.course_id = ?
                            AND cs.subject_id = g.subject_id
                        WHERE g.enrollment_id = ? AND g.division_id = ?
                    ");
                    $gStmt->execute([$courseIdForRow, $enrollId, $divId]);
                    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
                    if ($gRow && (int)$gRow['graded'] > 0 && $gRow['avg_points'] !== null) {
                        $en['_avg_grade'] = round((float)$gRow['avg_points'], 1);
                    }
                } catch (PDOException $e) {
                    error_log('courses.php avg_points error: ' . $e->getMessage());
                }

                try {
                    $hStmt = $conn->prepare("
                        SELECT status FROM enrollment_division_history
                        WHERE enrollment_id = ? AND division_id = ?
                        LIMIT 1
                    ");
                    $hStmt->execute([$enrollId, $divId]);
                    $hStatus = $hStmt->fetchColumn();
                    if ($hStatus) {
                        $en['_div_status'] = (string)$hStatus;
                    }
                } catch (PDOException $e) {
                    error_log('courses.php div_status error: ' . $e->getMessage());
                }

                try {
                    $eStmt = $conn->prepare("
                        SELECT COUNT(*)
                        FROM semester_exams se
                        JOIN course_subjects cs ON cs.id = se.course_subject_id
                        WHERE se.division_id = ?
                          AND cs.course_id    = ?
                          AND se.status       = 'published'
                          AND se.allow_access = 1
                          AND (se.start_date IS NULL OR se.start_date <= NOW())
                          AND (se.end_date   IS NULL OR se.end_date   >= NOW())
                          AND (
                                (
                                    se.exam_type = 'final'
                                    AND NOT EXISTS (
                                        SELECT 1 FROM enrollment_course_grades g
                                        WHERE g.enrollment_id = ?
                                          AND g.division_id   = ?
                                          AND g.subject_id    = cs.subject_id
                                          AND g.final_attempted = 1
                                    )
                                )
                                OR
                                (
                                    se.exam_type = 'supplementary'
                                    AND EXISTS (
                                        SELECT 1 FROM enrollment_course_grades g
                                        WHERE g.enrollment_id = ?
                                          AND g.division_id   = ?
                                          AND g.subject_id    = cs.subject_id
                                          AND g.final_attempted = 1
                                          AND g.status = 'failed'
                                          AND g.supplementary_attempted = 0
                                    )
                                )
                          )
                    ");
                    $eStmt->execute([
                        $divId,
                        $courseIdForRow,
                        $enrollId, $divId,
                        $enrollId, $divId,
                    ]);
                    $en['_exams_ready'] = (int)$eStmt->fetchColumn();
                } catch (PDOException $e) {
                    error_log('courses.php exams_ready error: ' . $e->getMessage());
                }
            }
        }
        unset($en);

        $stmt = $conn->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM course_divisions d WHERE d.course_id = c.id AND d.start_date > ?) AS upcoming_divisions,
                   (SELECT MIN(d.start_date) FROM course_divisions d WHERE d.course_id = c.id AND d.start_date > ?) AS next_start_date,
                   (SELECT COUNT(*) FROM course_subjects cs WHERE cs.course_id = c.id) AS subjects_count,
                   (SELECT COUNT(*) FROM course_enrollments en WHERE en.course_id = c.id AND en.status <> 'dropped') AS enrolled_count,
                   (SELECT en.status
                      FROM course_enrollments en
                      WHERE en.course_id = c.id AND en.user_id = ?
                      ORDER BY (en.status = 'active') DESC, en.id DESC
                      LIMIT 1) AS my_status
            FROM courses c
            WHERE c.status = 'published'
            ORDER BY c.created_at DESC, c.id DESC
        ");
        $stmt->execute([$todayStr, $todayStr, $userId]);

        foreach ($stmt->fetchAll() as $c) {
            if ($c['my_status'] !== null && $c['my_status'] !== 'dropped') {
                continue;
            }
            $available[] = $c;
        }

    } catch (PDOException $e) {
        error_log('courses.php data error: ' . $e->getMessage());
    }
}

// ============================================================================
// ACADEMY DASHBOARD
// ============================================================================

$academyRank        = 0;
$academyTotalUsers  = 0;
$academyAvgProgress = null;
$academyEarnedPts   = 0.0;
$academyPassedSubj  = 0;
$academyFailedSubj  = 0;
$academyInProgress  = 0;
$academyPassedDivs  = 0;
$academyFailedDivs  = 0;

$academyPodium      = [];
$academyScoreboard  = [];

if ($tablesReady) {
    // Academy attempts only (weekly competition quizzes tied to a course + monthly + semester exams).
    // Everything is summed in POINTS; percentages are not used for ranking.
    $perUserSql = "
        SELECT
            user_id,
            COUNT(*)          AS attempts,
            SUM(score)        AS earned_pts,
            SUM(total_points) AS possible_pts
        FROM (
            SELECT user_id, score, total_points FROM quiz_attempts
             WHERE status = 'completed' AND course_id IS NOT NULL
            UNION ALL
            SELECT user_id, score, total_points FROM monthly_exam_attempts WHERE status = 'completed'
            UNION ALL
            SELECT user_id, score, total_points FROM semester_exam_attempts WHERE status = 'completed'
        ) AS all_attempts
        GROUP BY user_id
    ";

    try {
        $ownStmt = $conn->prepare("SELECT attempts, earned_pts FROM ($perUserSql) AS u WHERE user_id = ?");
        $ownStmt->execute([$userId]);
        $own = $ownStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $academyEarnedPts = (float)($own['earned_pts'] ?? 0);

        // Subject results straight from the grade book (course_grade is in points out of the subject mark)
        try {
            $gbStmt = $conn->prepare("
                SELECT
                    AVG(g.course_grade)                                   AS avg_grade,
                    SUM(CASE WHEN g.status = 'passed'      THEN 1 ELSE 0 END) AS n_passed,
                    SUM(CASE WHEN g.status = 'failed'      THEN 1 ELSE 0 END) AS n_failed,
                    SUM(CASE WHEN g.status = 'in_progress' THEN 1 ELSE 0 END) AS n_pending
                FROM enrollment_course_grades g
                JOIN course_enrollments e ON e.id = g.enrollment_id
                WHERE e.user_id = ?
            ");
            $gbStmt->execute([$userId]);
            $gb = $gbStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $academyAvgProgress = ($gb['avg_grade'] ?? null) !== null ? round((float)$gb['avg_grade'], 1) : null;
            $academyPassedSubj  = (int)($gb['n_passed']  ?? 0);
            $academyFailedSubj  = (int)($gb['n_failed']  ?? 0);
            $academyInProgress  = (int)($gb['n_pending'] ?? 0);
        } catch (PDOException $e) {
            error_log('courses.php grade-book summary: ' . $e->getMessage());
        }

        try {
            $divStmt = $conn->prepare("
                SELECT
                    SUM(CASE WHEN h.status = 'passed' THEN 1 ELSE 0 END) AS passed_divs,
                    SUM(CASE WHEN h.status = 'failed' THEN 1 ELSE 0 END) AS failed_divs
                FROM enrollment_division_history h
                JOIN course_enrollments e ON e.id = h.enrollment_id
                WHERE e.user_id = ?
            ");
            $divStmt->execute([$userId]);
            $divRow = $divStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $academyPassedDivs = (int)($divRow['passed_divs'] ?? 0);
            $academyFailedDivs = (int)($divRow['failed_divs'] ?? 0);
        } catch (PDOException $e) {}

        $totalStmt = $conn->query("SELECT COUNT(*) FROM ($perUserSql) AS u WHERE attempts > 0");
        $academyTotalUsers = (int)$totalStmt->fetchColumn();

        // Rank = by total points earned
        if ($academyEarnedPts > 0 && $academyTotalUsers > 0) {
            $rankStmt = $conn->prepare("SELECT COUNT(*) + 1 FROM ($perUserSql) AS u WHERE earned_pts > ?");
            $rankStmt->execute([$academyEarnedPts]);
            $academyRank = (int)$rankStmt->fetchColumn();
        }

        $podiumStmt = $conn->query("
            SELECT
                u.id AS user_id, u.username, u.full_name, u.email, u.profile_image,
                agg.earned_pts, agg.attempts
            FROM ($perUserSql) AS agg
            JOIN users u ON u.id = agg.user_id
            WHERE u.is_active = 1 AND agg.attempts > 0
            ORDER BY agg.earned_pts DESC, agg.attempts ASC, u.id ASC
            LIMIT 3
        ");
        $academyPodium = $podiumStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($academyPodium as $i => &$row) {
            $row['rank']            = $i + 1;
            $row['earned_pts']      = (int)round((float)$row['earned_pts']);
            $row['graded_subjects'] = (int)$row['attempts'];
            $row['display_name']    = $row['username'] ?: ($row['full_name'] ?: explode('@', (string)$row['email'])[0]);
            $row['is_me']           = ((int)$row['user_id'] === $userId);
        }
        unset($row);

        $sbStmt = $conn->prepare("
            SELECT e.course_id, c.title AS course_title
            FROM course_enrollments e
            JOIN courses c ON c.id = e.course_id
            WHERE e.user_id = ? AND e.status IN ('active','completed')
        ");
        $sbStmt->execute([$userId]);
        $enrolledCourses = $sbStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($enrolledCourses as $ec) {
            $courseId = (int)$ec['course_id'];

            $stuStmt = $conn->prepare("
                SELECT DISTINCT e.user_id
                FROM course_enrollments e
                WHERE e.course_id = ? AND e.status IN ('active','completed')
            ");
            $stuStmt->execute([$courseId]);
            $studentIds = array_map('intval', $stuStmt->fetchAll(PDO::FETCH_COLUMN));
            if (!$studentIds) {
                $academyScoreboard[] = [
                    'course_id' => $courseId,
                    'course_title' => (string)$ec['course_title'],
                    'rank' => null,
                    'students_in_course' => 0,
                    'avg_grade' => null,
                ];
                continue;
            }

            $in = implode(',', array_fill(0, count($studentIds), '?'));

            $courseAggSql = "
                SELECT user_id, SUM(score) AS pts, COUNT(*) AS attempts
                FROM (
                    SELECT qa.user_id, qa.score
                    FROM quiz_attempts qa
                    JOIN quizzes q ON q.id = qa.quiz_id
                    WHERE qa.status = 'completed' AND q.course_id = ? AND qa.user_id IN ($in)
                    UNION ALL
                    SELECT mea.user_id, mea.score
                    FROM monthly_exam_attempts mea
                    JOIN monthly_exams me ON me.id = mea.exam_id
                    WHERE mea.status = 'completed' AND me.course_id = ? AND mea.user_id IN ($in)
                    UNION ALL
                    SELECT sea.user_id, sea.score
                    FROM semester_exam_attempts sea
                    JOIN semester_exams se ON se.id = sea.exam_id
                    JOIN course_divisions cd ON cd.id = se.division_id
                    WHERE sea.status = 'completed' AND cd.course_id = ? AND sea.user_id IN ($in)
                ) AS course_attempts
                GROUP BY user_id
            ";
            $params = array_merge(
                [$courseId], $studentIds,
                [$courseId], $studentIds,
                [$courseId], $studentIds
            );

            try {
                $aggStmt = $conn->prepare($courseAggSql);
                $aggStmt->execute($params);
                $courseRows = $aggStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $myAvg = null;
                $myRank = null;
                $studentsWithAttempts = count($courseRows);

                usort($courseRows, static fn($a, $b) => ((float)$b['pts']) <=> ((float)$a['pts']));

                foreach ($courseRows as $i => $row) {
                    if ((int)$row['user_id'] === $userId) {
                        $myAvg  = (int)round((float)$row['pts']);   // my points in this course
                        $myRank = $i + 1;
                        break;
                    }
                }

                $academyScoreboard[] = [
                    'course_id' => $courseId,
                    'course_title' => (string)$ec['course_title'],
                    'rank' => $myRank,
                    'students_in_course' => $studentsWithAttempts,
                    'avg_grade' => $myAvg,
                ];
            } catch (PDOException $e) {
                error_log('courses.php scoreboard course: ' . $e->getMessage());
                $academyScoreboard[] = [
                    'course_id' => $courseId,
                    'course_title' => (string)$ec['course_title'],
                    'rank' => null,
                    'students_in_course' => 0,
                    'avg_grade' => null,
                ];
            }
        }

    } catch (PDOException $e) {
        error_log('courses.php academy dashboard error: ' . $e->getMessage());
    }
}

// ============================================================================
// PERFORMANCE GRAPH
// ============================================================================
$perfPoints = [];
$perfMax    = 0;

if ($tablesReady) {
    $collected = [];

    $collectFrom = function(string $sql, array $params, string $sourceLabel) use ($conn, &$collected): void {
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = strtotime((string)($r['completed_at'] ?? ''));
                if (!$ts) continue;
                $pct = (float)($r['pct'] ?? 0);   // score / total_points, derived from the points
                if ($pct <= 0 && (float)($r['score'] ?? 0) == 0) continue;
                $collected[] = [
                    'ts'    => $ts,
                    'pct'   => max(0.0, min(100.0, $pct)),
                    'label' => date('M d', $ts),
                ];
            }
        } catch (PDOException $e) {
            error_log("courses.php perf collect ($sourceLabel): " . $e->getMessage());
        }
    };

    $collectFrom("SELECT completed_at, score, ROUND(score / NULLIF(total_points, 0) * 100, 1) AS pct FROM quiz_attempts WHERE user_id = ? AND status = 'completed' AND course_id IS NOT NULL AND completed_at IS NOT NULL", [$userId], 'weekly');
    $collectFrom("SELECT completed_at, score, ROUND(score / NULLIF(total_points, 0) * 100, 1) AS pct FROM monthly_exam_attempts WHERE user_id = ? AND status = 'completed' AND completed_at IS NOT NULL", [$userId], 'monthly');
    $collectFrom("SELECT completed_at, score, ROUND(score / NULLIF(total_points, 0) * 100, 1) AS pct FROM semester_exam_attempts WHERE user_id = ? AND status = 'completed' AND completed_at IS NOT NULL", [$userId], 'semester');

    usort($collected, static fn($a, $b) => $a['ts'] <=> $b['ts']);
    if (count($collected) > 12) $collected = array_slice($collected, -12);

    foreach ($collected as $c) {
        $perfPoints[] = ['label' => $c['label'], 'score' => round($c['pct'], 1)];
        $perfMax = max($perfMax, $c['pct']);
    }
}

$perfPath   = '';
$perfArea   = '';
$perfDots   = [];
$perfWidth  = 240;
$perfHeight = 80;
$perfPad    = 6;

if (count($perfPoints) >= 1) {
    $plotW = $perfWidth - 2 * $perfPad;
    $plotH = $perfHeight - 2 * $perfPad;
    $n     = count($perfPoints);

    foreach ($perfPoints as $i => $pt) {
        $x = ($n === 1) ? $perfWidth / 2 : $perfPad + ($i / ($n - 1)) * $plotW;
        $y = $perfPad + (1 - ($pt['score'] / 100)) * $plotH;
        $perfDots[] = [
            'x' => round($x, 2), 'y' => round($y, 2),
            'score' => $pt['score'], 'label' => $pt['label'],
        ];
    }

    if ($n >= 2) {
        $perfPath = 'M ' . $perfDots[0]['x'] . ' ' . $perfDots[0]['y'];
        for ($i = 1; $i < $n; $i++) {
            $perfPath .= ' L ' . $perfDots[$i]['x'] . ' ' . $perfDots[$i]['y'];
        }
        $perfArea = $perfPath
                  . ' L ' . $perfDots[$n - 1]['x'] . ' ' . ($perfHeight - $perfPad)
                  . ' L ' . $perfDots[0]['x'] . ' ' . ($perfHeight - $perfPad) . ' Z';
    }
}

// ---- Timetable chooser ----
$myCoursesForTimetable = [];
foreach ($myEnrollments as $en) {
    if (empty($en['current_div_id'])) continue;
    $myCoursesForTimetable[] = [
        'course_id'    => (int)$en['course_id'],
        'course_title' => (string)$en['course_title'],
        'div_name'     => (string)($en['current_div_name'] ?? ''),
    ];
}
$timetableCount = count($myCoursesForTimetable);

// ---- Exam summary ----
$examSummary = ['taken' => 0, 'passed' => 0, 'failed' => 0, 'points' => 0, 'possible' => 0];

if ($tablesReady) {
    foreach ([
        'quiz_attempts'           => 'Weekly quizzes',
        'monthly_exam_attempts'   => 'Monthly exams',
        'semester_exam_attempts'  => 'Semester exams',
    ] as $table => $label) {
        try {
            $stmt = $conn->prepare("
                SELECT
                    COUNT(*) AS taken,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) AS passed,
                    SUM(CASE WHEN passed = 0 THEN 1 ELSE 0 END) AS failed,
                    COALESCE(SUM(score), 0)        AS pts,
                    COALESCE(SUM(total_points), 0) AS possible
                FROM `$table`
                WHERE user_id = ? AND status = 'completed'" . ($table === 'quiz_attempts' ? " AND course_id IS NOT NULL" : '') . "
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $examSummary['taken']  += (int)($row['taken']  ?? 0);
            $examSummary['passed'] += (int)($row['passed'] ?? 0);
            $examSummary['failed'] += (int)($row['failed'] ?? 0);

            $examSummary['points']   += (float)($row['pts'] ?? 0);
            $examSummary['possible'] += (float)($row['possible'] ?? 0);
        } catch (PDOException $e) {
            error_log("courses.php exam summary ($label): " . $e->getMessage());
        }
    }
}

$flash = courseTakeFlash();

$tileGradients = [
    'linear-gradient(135deg,#0E4A34,#3E7A5E)',
    'linear-gradient(135deg,#1a9aaf,#0e6d80)',
    'linear-gradient(135deg,#9C7A2E,#c9a85e)',
    'linear-gradient(135deg,#3E6E91,#6FA0C4)',
    'linear-gradient(135deg,#6C5CC0,#9285DB)',
];
$tileIcons = ['📖', '🕌', '📚', '🌙', '✨'];

function course_image_url(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '') return null;
    if (preg_match('~^(https?:)?//~i', $path)) return $path;
    $abs = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . ltrim($path, '/');
    if (is_file($abs)) return '/' . ltrim($path, '/');
    return null;
}

$bookStoreUrl  = 'library.php';
$bookStoreLive = file_exists(__DIR__ . '/library.php');
$bookStoreBg   = '/uploads/library-bg.jpg';
$bookStoreBgOk = file_exists(($_SERVER['DOCUMENT_ROOT'] ?? '') . $bookStoreBg);

courseShellStart($ctx, 'Courses');
?>
<style>
.zc { --zc-brand: var(--brand, #0E4A34); --zc-accent: #1a9aaf; --zc-orange: #e8731a;
      --zc-surface: var(--surface, #fff); --zc-surface2: var(--surface-2, #f6f7f6);
      --zc-line: var(--line, #dde2df); --zc-text: var(--text, #1f2937); --zc-muted: var(--muted, #6b7280); }
.zc * { box-sizing: border-box; }
.zc-layout { display: grid; grid-template-columns: 260px minmax(0, 1fr) 280px; gap: 16px; align-items: start; max-width: 1320px; margin: 0 auto; }
.zc-main { min-width: 0; }

.zc-panel { background: var(--zc-surface); border: 1px solid var(--zc-line); border-radius: 6px; margin-bottom: 14px; overflow: hidden; }
.zc-panel-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: var(--zc-surface2); border-bottom: 1px solid var(--zc-line); font-size: 12.5px; font-weight: 700; color: var(--zc-text); }
.zc-ico { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 3px; background: var(--zc-orange); color: #fff; font-size: 11px; flex-shrink: 0; }
.zc-panel-body { padding: 12px; font-size: 12.5px; color: var(--zc-text); }
.zc-muted { color: var(--zc-muted); }

.zc-hero {
    position: relative;
    min-height: 200px;
    border-radius: 6px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 26px 20px;
    color: #fff;
    margin-bottom: 14px;
    background: linear-gradient(rgba(10,40,30,.40),rgba(10,40,30,.62)),url('/uploads/myislam-academyc.png') center/cover no-repeat,#0E4A34;
}
.zc-hero h1 { margin: 0 0 6px; font-size: 24px; font-weight: 700; color: #fff; }
.zc-hero p { margin: 0; font-size: 13px; opacity: .92; max-width: 560px; }
.zc-intro { text-align: center; font-size: 13px; line-height: 1.7; color: var(--zc-text); padding: 0 6px 4px; margin: 0; }
.zc-heading { margin: 6px 2px 10px; padding-bottom: 6px; border-bottom: 2px solid var(--zc-accent); font-size: 15px; color: #9C7A2E; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.zc-heading small { font-size: 11px; font-weight: 600; color: var(--zc-muted); }

.zc-row {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 120px;
    gap: 14px;
    padding: 16px;
    margin-bottom: 12px;
    background: var(--zc-surface);
    border: 1px solid var(--zc-line);
    border-left: 4px solid var(--zc-accent);
    border-radius: 6px;
    overflow: hidden;
}
.zc-row:hover { box-shadow: 0 2px 10px rgba(0,0,0,.07); }
.zc-row-top { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }

.zc-row.has-cover {
    color: #fff;
    border-left-width: 4px;
}
.zc-row.has-cover::before {
    content: "";
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-image: var(--cover-url);
    z-index: 0;
}
.zc-row.has-cover::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(10,40,30,.55), rgba(10,40,30,.85));
    z-index: 1;
}
.zc-row.has-cover > * { position: relative; z-index: 2; }
.zc-row.has-cover .zc-title,
.zc-row.has-cover .zc-title a { color: #D9BC72 !important; text-shadow: 0 1px 3px rgba(0,0,0,.6); }
.zc-row.has-cover .zc-desc { color: rgba(255,255,255,.85); }
.zc-row.has-cover .zc-meta { color: rgba(255,255,255,.82); }
.zc-row.has-cover .zc-bar { background: rgba(255,255,255,.2); }
.zc-row.has-cover .zc-bar i { background: #D9BC72; }
.zc-row.has-cover .zc-bar-meta { color: rgba(255,255,255,.82); }
.zc-row.has-cover .zc-badge.brand,
.zc-row.has-cover .zc-badge.gold,
.zc-row.has-cover .zc-badge.accent,
.zc-row.has-cover .zc-badge.muted {
    background: rgba(255,255,255,.18);
    color: #fff;
    border: 1px solid rgba(255,255,255,.35);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
}
.zc-row.has-cover .zc-badge.danger {
    background: rgba(239,68,68,.9);
    color: #fff;
    border: 1px solid #ef4444;
}
.zc-row.has-cover .zc-thumb {
    background: rgba(255,255,255,.12) !important;
    border: 1px solid rgba(255,255,255,.3);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
}

.zc-title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #9C7A2E !important;
    text-align: right;
    direction: rtl;
}
.zc-title a { color: inherit; text-decoration: none; }
.zc-title a:hover { text-decoration: underline; color: #7a5f24 !important; }

.zc-desc { margin: 6px 0 8px; font-size: 12.5px; line-height: 1.6; color: var(--zc-muted); }
.zc-meta { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 11.5px; color: var(--zc-muted); margin: 6px 0 8px; }
.zc-bar { height: 7px; border-radius: 99px; background: var(--zc-line); overflow: hidden; }
.zc-bar i { display: block; height: 100%; background: var(--zc-brand); border-radius: 99px; }
.zc-bar-meta { display: flex; justify-content: space-between; font-size: 11px; color: var(--zc-muted); margin-top: 3px; }

.zc-actions {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 14px;
    text-align: center;
}
.zc-actions form { margin: 0; }
.zc-actions .btn { min-width: 130px; text-align: center; border-radius: 5px; }

.zc-thumb {
    align-self: stretch;
    width: 120px;
    min-height: 80px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    color: #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,.18);
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    overflow: hidden;
}
.zc-empty { padding: 16px; text-align: center; font-size: 13px; color: var(--zc-muted); background: var(--zc-surface); border: 1px dashed var(--zc-line); border-radius: 6px; margin-bottom: 12px; }

.zc-badge { display: inline-block; padding: 3px 10px; border-radius: 5px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.zc-badge.brand { background: rgba(14,74,52,.1); color: #0E4A34; }
.zc-badge.gold { background: rgba(156,122,46,.12); color: #9C7A2E; }
.zc-badge.accent { background: rgba(62,110,145,.12); color: #3E6E91; }
.zc-badge.danger { background: rgba(185,28,28,.08); color: #b91c1c; }
.zc-badge.muted { background: rgba(0,0,0,.05); color: #6b7280; }

body.dark-mode .zc-badge.brand { background: rgba(76,145,114,.16); color: #7fcaa4; }
body.dark-mode .zc-badge.gold { background: rgba(201,168,94,.16); color: #C9A85E; }
body.dark-mode .zc-badge.accent { background: rgba(111,160,196,.16); color: #6FA0C4; }
body.dark-mode .zc-badge.danger { background: rgba(239,68,68,.12); color: #ef4444; }
body.dark-mode .zc-badge.muted { background: rgba(255,255,255,.05); color: #94a3b8; }

.zc-heading { color: #9C7A2E !important; border-bottom-color: #9C7A2E !important; }
.zc-tt-title { color: #9C7A2E !important; font-weight: 700; }
.zc-tt-sub { color: #7a5f24 !important; font-weight: 600; }
.zc-scoreboard-table tbody th,
.zc-scoreboard-table tbody th a { color: #9C7A2E !important; font-weight: 800; }
.zc-scoreboard-table tbody th a:hover { color: #7a5f24 !important; }

body.dark-mode .zc-title,
body.dark-mode .zc-title a,
body.dark-mode .zc-heading,
body.dark-mode .zc-tt-title,
body.dark-mode .zc-scoreboard-table tbody th,
body.dark-mode .zc-scoreboard-table tbody th a { color: #C9A85E !important; }
body.dark-mode .zc-tt-sub { color: #a8946a !important; }
body.dark-mode .zc-heading { border-bottom-color: #C9A85E !important; }

.zc-rank-row { display: flex; flex-wrap: nowrap; align-items: center; justify-content: center; gap: 16px; }
.zc-rank-item { flex: 1; min-width: 0; text-align: center; }
.zc-rank-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; font-weight: 700; color: var(--zc-muted); margin-bottom: 8px; }
.zc-rank-value { font-size: clamp(24px, 6vw, 36px); font-weight: 800; color: var(--gold, #9C7A2E); line-height: 1.1; }
.zc-rank-sub { font-size: 11px; color: var(--muted); margin-top: 4px; }
.zc-rank-divider { width: 1px; align-self: stretch; background: var(--zc-line); flex-shrink: 0; }
@media (max-width: 400px) {
    .zc-rank-row { gap: 8px; }
    .zc-rank-label { font-size: 10px; }
}

.zc-podium-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: end; gap: 6px; margin-top: 14px; }
.zc-podium-card { min-width: 0; text-align: center; padding: 10px 4px 10px; border-radius: 12px; border: 1px solid var(--zc-line); position: relative; transition: transform .2s; margin-top: 20px; display: flex; flex-direction: column; align-items: center; overflow: hidden; }
.zc-podium-card:hover { transform: translateY(-2px); }

.zc-podium-first  { order: 2; margin-top: 0; padding-top: 14px; padding-bottom: 14px; box-shadow: 0 4px 10px -2px rgba(0,0,0,.10); }
.zc-podium-second { order: 3; }
.zc-podium-third  { order: 1; }

.zc-podium-first  { background: linear-gradient(160deg, rgba(198,166,75,.14) 0%, var(--surface) 100%); border-color: #C6A64B; }
.zc-podium-second { background: linear-gradient(160deg, rgba(143,211,232,.16) 0%, var(--surface) 100%); border-color: #8FD3E8; }
.zc-podium-third  { background: linear-gradient(160deg, rgba(108,92,192,.14) 0%, var(--surface) 100%); border-color: #6C5CC0; }

.zc-podium-you { box-shadow: 0 0 0 2px var(--zc-brand), 0 4px 10px -2px rgba(0,0,0,.10); }

.zc-podium-avatar { width: 40px; height: 40px; border-radius: 50%; margin: 0 auto 6px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #fff; background: linear-gradient(135deg, var(--zc-brand) 0%, var(--brand-dark, #0A3626) 100%); overflow: hidden; border: 2px solid var(--surface); box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); flex-shrink: 0; }
.zc-podium-first .zc-podium-avatar  { width: 48px; height: 48px; border-color: #C6A64B; font-size: 14px; }
.zc-podium-second .zc-podium-avatar { border-color: #8FD3E8; }
.zc-podium-third .zc-podium-avatar  { border-color: #6C5CC0; }
.zc-podium-avatar img { width: 100%; height: 100%; object-fit: cover; }

.zc-podium-badge { display: inline-flex; align-items: center; gap: 2px; font-size: 9px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; margin-bottom: 6px; color: #fff; white-space: nowrap; max-width: 100%; }
.zc-podium-first .zc-podium-badge  { background: #C6A64B; }
.zc-podium-second .zc-podium-badge { background: #8FD3E8; color: #0f1720; }
.zc-podium-third .zc-podium-badge  { background: #6C5CC0; }

.zc-podium-name { font-size: 11px; font-weight: 700; color: var(--zc-text); line-height: 1.2; white-space: normal; overflow-wrap: anywhere; word-break: break-word; max-width: 100%; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.zc-podium-points { font-size: 10px; color: var(--zc-muted); margin-top: 3px; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }

@media (max-width: 380px) {
    .zc-podium-grid { gap: 4px; }
    .zc-podium-card { padding: 8px 3px 8px; margin-top: 16px; border-radius: 10px; }
    .zc-podium-first { padding-top: 10px; padding-bottom: 10px; margin-top: 0; }
    .zc-podium-second, .zc-podium-third { margin-top: 16px; }
    .zc-podium-avatar { width: 34px; height: 34px; font-size: 10px; margin-bottom: 5px; }
    .zc-podium-first .zc-podium-avatar { width: 40px; height: 40px; font-size: 12px; }
    .zc-podium-badge { font-size: 8px; padding: 2px 6px; letter-spacing: .1px; }
    .zc-podium-name { font-size: 10px; }
    .zc-podium-points { font-size: 9px; }
}

.zc-scoreboard-wrap { overflow-x: auto; border: 1px solid var(--zc-line); border-radius: 12px; margin-top: 8px; -webkit-overflow-scrolling: touch; }
.zc-scoreboard-table { width: 100%; border-collapse: collapse; background: var(--surface); table-layout: fixed; min-width: 0; }
.zc-scoreboard-table thead th {
    background: var(--gold-soft, rgba(156,122,46,.12));
    color: var(--gold, #9C7A2E);
    font-size: 10.5px;
    font-weight: 800;
    text-align: center;
    padding: 8px 4px;
    white-space: nowrap;
    border-bottom: 2px solid var(--gold, #9C7A2E);
    letter-spacing: .3px;
}
.zc-scoreboard-table thead th:nth-child(1) { width: 16%; }
.zc-scoreboard-table thead th:nth-child(2) { width: 20%; }
.zc-scoreboard-table thead th:nth-child(3) { width: 20%; }
.zc-scoreboard-table thead th:nth-child(4) {
    width: 44%;
    text-align: right;
    padding-right: 8px;
    background: var(--surface);
    color: var(--text-secondary);
    border-bottom: 2px solid var(--zc-line);
    direction: rtl;
}
.zc-scoreboard-table tbody th {
    text-align: right;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--zc-text);
    padding: 9px 8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    direction: rtl;
}
.zc-scoreboard-table tbody th a { color: inherit; text-decoration: none; }
.zc-scoreboard-table tbody td {
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    color: var(--zc-text);
    padding: 9px 4px;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.zc-scoreboard-table tbody tr { border-bottom: 1px dashed var(--zc-line); background: var(--surface); }
.zc-scoreboard-table tbody tr:last-child { border-bottom: 0; }
.zc-scoreboard-table tbody tr:nth-child(even) { background: rgba(128,128,128,.06); }

@media (max-width: 500px) {
    .zc-scoreboard-table thead th { font-size: 9.5px; padding: 6px 3px; }
    .zc-scoreboard-table tbody th { font-size: 11px; padding: 8px 6px; }
    .zc-scoreboard-table tbody td { font-size: 11px; padding: 8px 3px; }
}

.zc-right-card { background: var(--surface); border: 1px solid var(--zc-line); border-radius: 6px; margin-bottom: 14px; overflow: hidden; }
.zc-right-card:hover { border-color: var(--zc-brand); }
.zc-right-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: var(--zc-surface2); border-bottom: 1px solid var(--zc-line); font-size: 12.5px; font-weight: 700; color: var(--zc-text); }
.zc-right-head .zc-ico { background: var(--zc-brand); }
.zc-right-head-link { margin-left: auto; font-size: 11px; color: var(--zc-brand); font-weight: 700; text-decoration: none; }
.zc-right-head-link:hover { text-decoration: underline; }
.zc-right-body { padding: 12px; font-size: 12.5px; color: var(--zc-text); }

.zc-perf-svg { width: 100%; height: auto; display: block; overflow: visible; }
.zc-perf-empty { padding: 18px 8px; text-align: center; color: var(--zc-muted); font-size: 11.5px; line-height: 1.5; }
.zc-perf-meta { display: flex; justify-content: space-between; gap: 8px; font-size: 10.5px; color: var(--muted); margin-top: 4px; font-weight: 600; }
.zc-perf-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; background: var(--gold-soft); color: var(--gold); font-size: 10.5px; font-weight: 800; }

.zc-tt-list { display: grid; gap: 6px; }
.zc-tt-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; background: var(--surface-2); border: 1px solid var(--zc-line); text-decoration: none; color: var(--zc-text); font-size: 12px; font-weight: 600; transition: all .15s; }
.zc-tt-item:hover { border-color: var(--zc-brand); background: var(--brand-soft); color: var(--zc-brand); }
.zc-tt-item .zc-tt-icon { font-size: 14px; flex-shrink: 0; }
.zc-tt-item .zc-tt-body { min-width: 0; flex: 1; text-align: right; }
.zc-tt-item .zc-tt-title { display: block; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: right; direction: rtl; }
.zc-tt-item .zc-tt-sub { display: block; font-size: 10.5px; color: var(--muted); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: right; direction: rtl; }
.zc-tt-empty { padding: 14px 8px; text-align: center; color: var(--zc-muted); font-size: 11.5px; line-height: 1.5; }
.zc-tt-cta { display: block; text-align: center; padding: 9px 12px; border-radius: 8px; background: var(--zc-brand); color: #fff; font-weight: 800; font-size: 12px; text-decoration: none; margin-top: 8px; }
.zc-tt-cta:hover { filter: brightness(1.08); }

.zc-exam-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
.zc-exam-stat { padding: 10px 8px; border-radius: 8px; background: var(--surface-2); border: 1px solid var(--zc-line); text-align: center; }
.zc-exam-stat .v { font-size: 18px; font-weight: 800; line-height: 1.1; color: var(--zc-brand); }
.zc-exam-stat .v.gold { color: var(--gold); }
.zc-exam-stat .v.danger { color: var(--danger); }
.zc-exam-stat .l { font-size: 10px; text-transform: uppercase; letter-spacing: .4px; font-weight: 700; color: var(--muted); margin-top: 3px; }
.zc-exam-badge { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-radius: 8px; background: var(--surface-2); border: 1px solid var(--zc-line); font-size: 11px; font-weight: 700; color: var(--muted); margin-bottom: 8px; }
.zc-exam-badge strong { color: var(--zc-text); font-size: 13px; }
.zc-exam-cta { display: block; text-align: center; padding: 9px 12px; border-radius: 8px; background: var(--gold); color: #fff; font-weight: 800; font-size: 12px; text-decoration: none; }
.zc-exam-cta:hover { filter: brightness(1.08); }

@media (max-width: 1100px) {
    .zc-layout { grid-template-columns: minmax(0, 1fr); gap: 0; }
    .zc-main, .zc-left, .zc-right { display: contents; }
    .zc-layout > *, .zc-main > *, .zc-left > *, .zc-right > * { min-width: 0; }

    .zc-m-timetable    { order: 10; }
    .zc-m-examresults  { order: 11; }
    .zc-m-progress     { order: 12; }
    .zc-m-podium       { order: 13; }
    .zc-m-scoreboard   { order: 14; }
    .zc-m-performance  { order: 20; }
    .zc-m-library      { order: 30; }
}
@media (max-width: 520px) {
    .zc-row { grid-template-columns: minmax(0, 1fr); }
    .zc-thumb { display: none; }
}

@media (max-width: 640px) {
    .zc-right-card .zc-right-body { padding: 10px 10px 12px; }
    .zc-right-card .zc-right-head { padding: 8px 10px; font-size: 12px; }
    .zc-right-head-link { font-size: 10px; }
    .zc-exam-grid { grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 8px; }
    .zc-exam-stat { padding: 7px 4px; border-radius: 6px; }
    .zc-exam-stat .v { font-size: 15px; }
    .zc-exam-stat .l { font-size: 9px; margin-top: 1px; letter-spacing: .2px; }
    .zc-exam-badge { padding: 6px 10px; font-size: 10.5px; margin-bottom: 6px; border-radius: 6px; }
    .zc-exam-badge strong { font-size: 11.5px; }
    .zc-exam-cta { padding: 7px 10px; font-size: 11px; border-radius: 6px; }
    .zc-tt-empty { padding: 12px 6px; font-size: 11px; line-height: 1.4; }
}

@media (max-width: 400px) {
    .zc-exam-stat { padding: 6px 3px; }
    .zc-exam-stat .v { font-size: 14px; }
    .zc-exam-stat .l { font-size: 8.5px; }
    .zc-exam-cta { font-size: 10.5px; padding: 6px 8px; }
    .zc-right-card .zc-right-body { padding: 8px 8px 10px; }
}

.zc-right-head,
.zc-panel-head {
    color: var(--gold) !important;
    font-weight: 800;
    letter-spacing: .5px;
    border-bottom-color: var(--gold-soft) !important;
}
.zc-panel-head .zc-ico,
.zc-right-head .zc-ico { background: var(--gold) !important; color: #fff !important; }
.zc-right-head-link { color: var(--muted) !important; font-weight: 600 !important; }
.zc-right-head-link:hover { color: var(--gold) !important; text-decoration: underline; }
</style>

<div class="zc">
<div class="zc-layout">

    <!-- ============ LEFT COLUMN ============ -->
    <aside class="zc-left">

        <div class="zc-panel zc-m-progress">
            <div class="zc-panel-head"><span class="zc-ico">📊</span> My progress</div>
            <div class="zc-panel-body">
                <div class="zc-rank-row">
                    <div class="zc-rank-item">
                        <div class="zc-rank-label">🏅 Rank</div>
                        <div class="zc-rank-value">
                            <?php echo $academyRank > 0 ? '#' . $academyRank : '--'; ?>
                        </div>
                        <div class="zc-rank-sub">
                            of <?php echo $academyTotalUsers > 0 ? (int)$academyTotalUsers : '--'; ?>
                        </div>
                    </div>
                    <div class="zc-rank-divider"></div>
                    <div class="zc-rank-item">
                        <div class="zc-rank-label">📈 Avg grade</div>
                        <div class="zc-rank-value">
                            <?php echo $academyAvgProgress !== null ? (int)round($academyAvgProgress) . '<small style="font-size:.5em;font-weight:700;"> pts</small>' : '--'; ?>
                        </div>
                        <div class="zc-rank-sub">
                            <?php echo $academyPassedSubj; ?> passed ·
                            <?php echo $academyFailedSubj; ?> failed ·
                            <?php echo $academyInProgress; ?> in progress
                        </div>
                    </div>
                </div>

                <?php if ($academyEarnedPts > 0): ?>
                    <div style="margin-top:12px;padding-top:10px;border-top:1px dashed var(--zc-line);font-size:11.5px;color:var(--muted);text-align:center;">
                        <?php echo (int)round($academyEarnedPts); ?> total points earned
                    </div>
                <?php endif; ?>

                <?php if ($academyPassedDivs > 0 || $academyFailedDivs > 0): ?>
                    <div style="margin-top:6px;font-size:11.5px;color:var(--muted);text-align:center;">
                        <?php if ($academyPassedDivs > 0): ?>
                            ✅ <?php echo $academyPassedDivs; ?> division<?php echo $academyPassedDivs === 1 ? '' : 's'; ?> passed
                        <?php endif; ?>
                        <?php if ($academyFailedDivs > 0): ?>
                            <?php if ($academyPassedDivs > 0): ?> · <?php endif; ?>
                            ❌ <?php echo $academyFailedDivs; ?> division<?php echo $academyFailedDivs === 1 ? '' : 's'; ?> failed
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($academyPodium)): ?>
        <div class="zc-panel zc-m-podium">
            <div class="zc-panel-head"><span class="zc-ico">🏆</span> Top students</div>
            <div class="zc-panel-body" style="padding:6px 10px 14px;">
                <div class="zc-podium-grid">
                    <?php foreach ($academyPodium as $p):
                        $rank = (int)$p['rank'];
                        $tierClass = $rank === 1 ? 'zc-podium-first' : ($rank === 2 ? 'zc-podium-second' : 'zc-podium-third');
                        $tierLabel = $rank === 1 ? '🥇' : ($rank === 2 ? '💎' : '🔷');
                        $displayName = $p['is_me'] ? 'You' : $p['display_name'];
                    ?>
                        <div class="zc-podium-card <?php echo $tierClass; ?><?php echo $p['is_me'] ? ' zc-podium-you' : ''; ?>">
                            <div class="zc-podium-badge">#<?php echo $rank; ?> · <?php echo $tierLabel; ?></div>
                            <div class="zc-podium-avatar">
                                <?php if (!empty($p['profile_image'])): ?>
                                    <img src="<?php echo e($p['profile_image']); ?>" alt="<?php echo e($displayName); ?>">
                                <?php else: ?>
                                    <?php echo e(strtoupper(substr((string)$displayName, 0, 2))); ?>
                                <?php endif; ?>
                            </div>
                            <div class="zc-podium-name" title="<?php echo e($displayName); ?>">
                                <?php echo e($displayName); ?>
                            </div>
                            <div class="zc-podium-points">
                                <?php echo (int)$p['earned_pts']; ?> pts ·
                                <?php echo (int)$p['graded_subjects']; ?> exam<?php echo (int)$p['graded_subjects'] === 1 ? '' : 's'; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($academyScoreboard)): ?>
        <div class="zc-panel zc-m-scoreboard">
            <div class="zc-panel-head"><span class="zc-ico">📋</span> My scoreboard</div>
            <div class="zc-panel-body" style="padding:6px 10px 14px;">
                <div class="zc-scoreboard-wrap">
                    <table class="zc-scoreboard-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Students</th>
                                <th>Points</th>
                                <th>Course</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($academyScoreboard as $row):
                                $rankDisplay = $row['rank'] !== null ? '#' . (int)$row['rank'] : '—';
                                $avgDisplay = $row['avg_grade'] !== null
                                    ? (int)$row['avg_grade'] . ' pts'
                                    : '—';
                            ?>
                                <tr>
                                    <td><?php echo e($rankDisplay); ?></td>
                                    <td><?php echo (int)$row['students_in_course']; ?></td>
                                    <td><?php echo e($avgDisplay); ?></td>
                                    <th scope="row" title="<?php echo e((string)$row['course_title']); ?>">
                                        <a href="course.php?course_id=<?php echo (int)$row['course_id']; ?>">
                                            <?php echo e(mb_strimwidth((string)$row['course_title'], 0, 16, '…')); ?>
                                        </a>
                                    </th>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </aside>

    <!-- ============ CENTER COLUMN ============ -->
    <div class="zc-main">
        <section class="zc-hero">
            <h1>Courses</h1>
            <p>We welcome you to this great opportunity to learn and share knowledge.</p>
        </section>
        <p class="zc-intro">
            Enrol in any course. Your study begins at the <strong>start of the next division</strong>.
            You'll receive the full timetable when it begins.
        </p>

        <?php if ($flash): ?>
            <div class="flash <?php echo e($flash[0]); ?>" style="margin-bottom:12px;"><?php echo e($flash[1]); ?></div>
        <?php endif; ?>

        <h2 class="zc-heading">
            <span>My courses</span>
            <small><?php echo count($myEnrollments); ?></small>
        </h2>

        <?php if (!$myEnrollments): ?>
            <div class="zc-empty">You haven't enrolled in any course yet. Pick one below to get started.</div>
        <?php else: foreach ($myEnrollments as $i => $en):
            $p = $en['_progress'];
            $curStart = !empty($en['current_div_start']) ? new DateTimeImmutable($en['current_div_start']) : null;
            $curEnd = !empty($en['current_div_end']) ? new DateTimeImmutable($en['current_div_end']) : null;

            if ($p['state'] === 'pending' && $curStart) {
                $daysToStart = (int)$today->diff($curStart)->days;
                $badgeCls = 'gold';
                $badgeLabel = 'Starts in ' . $daysToStart . ' day' . ($daysToStart === 1 ? '' : 's');
            } elseif ($p['state'] === 'in_progress') {
                $badgeCls = 'accent';
                $badgeLabel = 'Week ' . $p['week'] . ' of ' . $p['total_weeks'];
            } elseif ($p['state'] === 'ended') {
                $badgeCls = 'muted';
                $badgeLabel = 'Ended';
            } else {
                $badgeCls = 'muted';
                $badgeLabel = 'Waiting';
            }

            $desc = trim((string)($en['course_description'] ?? ''));
            $coverUrl = course_image_url($en['_course_image'] ?? null);
        ?>
            <article class="zc-row <?php echo $coverUrl ? 'has-cover' : ''; ?>"
                     <?php if ($coverUrl): ?>style="--cover-url: url('<?php echo e($coverUrl); ?>');"<?php endif; ?>>
                <div>
                    <div class="zc-row-top">
                        <span class="zc-badge <?php echo $badgeCls; ?>"><?php echo e($badgeLabel); ?></span>

                        <h3 class="zc-title">
                            <a href="course.php?course_id=<?php echo (int)$en['course_id']; ?>">
                                <?php echo e($en['course_title']); ?>
                            </a>
                        </h3>
                    </div>

                    <?php if ($desc !== ''): ?>
                        <p class="zc-desc"><?php echo e(mb_strlen($desc) > 160 ? mb_substr($desc, 0, 160) . '…' : $desc); ?></p>
                    <?php endif; ?>

                    <div class="zc-meta">
                        <?php if (!empty($en['current_div_name'])): ?>
                            <span>📚 <?php echo e($en['current_div_name']); ?></span>
                        <?php endif; ?>
                        <?php if ($curStart && $curEnd): ?>
                            <span>📅 <?php echo e($curStart->format('d M Y')); ?> → <?php echo e($curEnd->format('d M Y')); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($en['total_divisions'])): ?>
                            <span>🏫 <?php echo (int)$en['total_divisions']; ?> division<?php echo (int)$en['total_divisions'] === 1 ? '' : 's'; ?> total</span>
                        <?php endif; ?>

                        <?php if ($en['_avg_grade'] !== null): ?>
                            <span title="Average points per subject this division">
                                📊 Avg: <strong><?php echo e((string)$en['_avg_grade']); ?> pts</strong>
                            </span>
                        <?php endif; ?>
                        <?php if ($en['_div_status'] === 'passed'): ?>
                            <span style="color:var(--brand);font-weight:700;">✅ Division passed</span>
                        <?php elseif ($en['_div_status'] === 'failed'): ?>
                            <span style="color:var(--danger);font-weight:700;">❌ Division failed</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($en['_exams_ready'] > 0): ?>
                        <div style="margin-top:10px;padding:8px 12px;border-radius:10px;background:var(--gold-soft);border:1px solid var(--gold);font-size:13px;font-weight:700;">
                            🎓 <?php echo (int)$en['_exams_ready']; ?>
                            exam<?php echo (int)$en['_exams_ready'] === 1 ? '' : 's'; ?>
                            ready to take this division
                        </div>
                    <?php endif; ?>

                    <?php if ($p['state'] === 'in_progress'): ?>
                        <div class="zc-bar"><i style="width: <?php echo (int)$p['pct']; ?>%"></i></div>
                        <div class="zc-bar-meta">
                            <span><?php echo (int)$p['days_elapsed']; ?> days done</span>
                            <span><?php echo (int)$p['pct']; ?>%</span>
                            <span><?php echo (int)$p['days_remaining']; ?> days left</span>
                        </div>
                    <?php endif; ?>

                    <div class="zc-actions">
                        <?php
                        if ($en['_exams_ready'] > 0) {
                            $ctaText = '🎓 Take exam →';
                            $ctaStyle = 'background:var(--gold);border-color:var(--gold);color:#fff;';
                        } else {
                            $ctaText = 'Open course →';
                            $ctaStyle = '';
                        }
                        ?>
                        <a class="btn btn-primary btn-sm" style="<?php echo $ctaStyle; ?>" href="course.php?course_id=<?php echo (int)$en['course_id']; ?>">
                            <?php echo $ctaText; ?>
                        </a>
                    </div>
                </div>
                <?php if ($coverUrl): ?>
                    <div class="zc-thumb" style="background-image: url('<?php echo e($coverUrl); ?>');" aria-hidden="true"></div>
                <?php else: ?>
                    <div class="zc-thumb" style="background: <?php echo $tileGradients[$i % count($tileGradients)]; ?>" aria-hidden="true"><?php echo $tileIcons[$i % count($tileIcons)]; ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; endif; ?>

        <h2 class="zc-heading">
            <span>Open for enrollment</span>
            <small><?php echo count($available); ?></small>
        </h2>

        <?php if (!$tablesReady): ?>
            <div class="zc-empty">Courses are not available yet.</div>
        <?php elseif (!$available): ?>
            <div class="zc-empty">No course is open for enrollment right now.</div>
        <?php else: foreach ($available as $i => $c):
            $hasUpcoming = (int)$c['upcoming_divisions'] > 0;
            $nextStart = $c['next_start_date'] ? new DateTimeImmutable($c['next_start_date']) : null;
            $daysToStart = $nextStart ? (int)$today->diff($nextStart)->days : 0;

            $divisionType = (string)($c['division_type'] ?? 'none');
            $divisionLabel = (string)($c['division_label'] ?? 'division');
            $desc = trim((string)($c['description'] ?? ''));
            $coverUrl = course_image_url($c['image_path'] ?? null);
        ?>
            <article class="zc-row <?php echo $coverUrl ? 'has-cover' : ''; ?>"
                     <?php if ($coverUrl): ?>style="--cover-url: url('<?php echo e($coverUrl); ?>');"<?php endif; ?>>
                <div>
                    <div class="zc-row-top">
                        <?php if ($hasUpcoming): ?>
                            <span class="zc-badge brand">Starts <?php echo e($nextStart->format('d M')); ?></span>
                        <?php else: ?>
                            <span class="zc-badge muted">No intake scheduled</span>
                        <?php endif; ?>
                        <h3 class="zc-title"><?php echo e($c['title']); ?></h3>
                    </div>

                    <?php if ($desc !== ''): ?>
                        <p class="zc-desc"><?php echo e(mb_strlen($desc) > 180 ? mb_substr($desc, 0, 180) . '…' : $desc); ?></p>
                    <?php endif; ?>

                    <div class="zc-meta">
                        <span>📚 <?php echo (int)$c['subjects_count']; ?> subject<?php echo (int)$c['subjects_count'] === 1 ? '' : 's'; ?></span>
                        <span>👥 <?php echo (int)$c['enrolled_count']; ?> enrolled</span>
                        <?php if ($hasUpcoming): ?>
                            <span>🚀 First session in <?php echo $daysToStart; ?> day<?php echo $daysToStart === 1 ? '' : 's'; ?></span>
                        <?php endif; ?>
                        <?php if ($divisionType !== 'none'): ?>
                            <span>🎯 By <?php echo e($divisionType); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="zc-actions">
                        <?php if ($hasUpcoming): ?>
                            <form method="post" data-confirm="Enrol in this course? Your study begins at the start of the next <?php echo e(strtolower($divisionLabel)); ?>.">
                                <?php echo courseCsrfField(); ?>
                                <input type="hidden" name="action" value="enroll">
                                <input type="hidden" name="course_id" value="<?php echo (int)$c['id']; ?>">
                                <button class="btn btn-primary btn-sm" type="submit">Enrol</button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-sm" type="button" disabled title="No upcoming intake">No intake</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($coverUrl): ?>
                    <div class="zc-thumb" style="background-image: url('<?php echo e($coverUrl); ?>');" aria-hidden="true"></div>
                <?php else: ?>
                    <div class="zc-thumb" style="background: <?php echo $tileGradients[($i + 2) % count($tileGradients)]; ?>" aria-hidden="true"><?php echo $tileIcons[($i + 2) % count($tileIcons)]; ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; endif; ?>
    </div>

    <!-- ============ RIGHT COLUMN ============ -->
    <aside class="zc-right">

        <div class="zc-right-card zc-m-performance">
            <div class="zc-right-head">
                <span class="zc-ico">📈</span>
                Performance
                <?php if (count($perfPoints) >= 2): ?>
                    <span class="zc-right-head-link" style="color: var(--muted); font-weight: 600;">
                        <?php echo count($perfPoints); ?> day<?php echo count($perfPoints) === 1 ? '' : 's'; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="zc-right-body">
                <?php if (count($perfPoints) >= 1):
                    $lastPt = end($perfPoints);
                    reset($perfPoints);
                    $firstPt = $perfPoints[0];
                    $singlePoint = (count($perfPoints) === 1);
                ?>
                    <svg class="zc-perf-svg" viewBox="0 0 <?php echo $perfWidth; ?> <?php echo $perfHeight; ?>" preserveAspectRatio="none" role="img" aria-label="Progress trend">
                        <line x1="<?php echo $perfPad; ?>" y1="<?php echo $perfHeight - $perfPad; ?>" x2="<?php echo $perfWidth - $perfPad; ?>" y2="<?php echo $perfHeight - $perfPad; ?>" stroke="var(--zc-line)" stroke-width="1" />

                        <?php if (!$singlePoint): ?>
                            <path d="<?php echo e($perfArea); ?>" fill="var(--zc-brand)" opacity="0.08" />
                            <?php for ($i = 0; $i < count($perfDots) - 1; $i++):
                                $p1 = $perfDots[$i];
                                $p2 = $perfDots[$i + 1];
                                $delta = $p2['score'] - $p1['score'];
                                if ($delta > 0.05) $color = 'var(--gold, #9C7A2E)';
                                elseif ($delta < -0.05) $color = 'var(--danger, #b91c1c)';
                                else $color = 'var(--zc-brand)';
                            ?>
                                <line x1="<?php echo $p1['x']; ?>" y1="<?php echo $p1['y']; ?>" x2="<?php echo $p2['x']; ?>" y2="<?php echo $p2['y']; ?>" stroke="<?php echo $color; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            <?php endfor; ?>
                        <?php endif; ?>

                        <?php foreach ($perfDots as $d): ?>
                            <circle cx="<?php echo $d['x']; ?>" cy="<?php echo $d['y']; ?>" r="<?php echo $singlePoint ? 4 : 2.5; ?>" fill="var(--zc-brand)" stroke="var(--surface)" stroke-width="1.5">
                                <title><?php echo e($d['label'] . ': ' . number_format($d['score'], 1) . '%'); ?></title>
                            </circle>
                        <?php endforeach; ?>
                    </svg>

                    <div class="zc-perf-meta">
                        <span><?php echo e($firstPt['label']); ?></span>
                        <span class="zc-perf-badge"><?php echo number_format((float)$lastPt['score'], 1); ?>%</span>
                        <span><?php echo e($lastPt['label']); ?></span>
                    </div>

                    <div style="display:flex;gap:10px;justify-content:center;margin-top:6px;font-size:10px;color:var(--muted);font-weight:600;">
                        <span><span style="display:inline-block;width:14px;height:2px;background:var(--zc-brand);vertical-align:middle;margin-right:3px;"></span>Same</span>
                        <span><span style="display:inline-block;width:14px;height:2px;background:var(--gold,#9C7A2E);vertical-align:middle;margin-right:3px;"></span>Rising</span>
                        <span><span style="display:inline-block;width:14px;height:2px;background:var(--danger,#b91c1c);vertical-align:middle;margin-right:3px;"></span>Falling</span>
                    </div>

                    <?php if ($singlePoint): ?>
                        <div style="text-align:center;font-size:10.5px;color:var(--muted);margin-top:4px;font-weight:600;">
                            Take another quiz or exam to draw the line →
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="zc-perf-empty">
                        📈 No quiz or exam activity yet.<br>
                        Take one and your trend will appear here.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="zc-right-card zc-m-timetable">
            <div class="zc-right-head">
                <span class="zc-ico">🗓️</span>
                Timetable
            </div>
            <div class="zc-right-body">
                <?php if ($timetableCount === 0): ?>
                    <div class="zc-tt-empty">
                        🗓️ Enrol in a course to see your weekly timetable.
                    </div>
                <?php elseif ($timetableCount === 1):
                    $only = $myCoursesForTimetable[0];
                ?>
                    <div class="zc-tt-list">
                        <a class="zc-tt-item" href="timetable.php?course_id=<?php echo (int)$only['course_id']; ?>">
                            <span class="zc-tt-icon">📘</span>
                            <span class="zc-tt-body">
                                <span class="zc-tt-title"><?php echo e($only['course_title']); ?></span>
                                <span class="zc-tt-sub"><?php echo e($only['div_name']); ?></span>
                            </span>
                            <span style="color: var(--zc-muted); font-weight: 800;">→</span>
                        </a>
                    </div>
                    <a class="zc-tt-cta" href="timetable.php?course_id=<?php echo (int)$only['course_id']; ?>">
                        Open full timetable →
                    </a>
                <?php else: ?>
                    <p style="font-size: 11.5px; color: var(--muted); margin: 0 0 8px; font-weight: 600;">
                        Pick a course:
                    </p>
                    <div class="zc-tt-list">
                        <?php foreach ($myCoursesForTimetable as $c): ?>
                            <a class="zc-tt-item" href="timetable.php?course_id=<?php echo (int)$c['course_id']; ?>">
                                <span class="zc-tt-icon">📘</span>
                                <span class="zc-tt-body">
                                    <span class="zc-tt-title"><?php echo e($c['course_title']); ?></span>
                                    <span class="zc-tt-sub"><?php echo e($c['div_name']); ?></span>
                                </span>
                                <span style="color: var(--zc-muted); font-weight: 800;">→</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="zc-right-card zc-m-examresults">
            <div class="zc-right-head">
                <span class="zc-ico">🎓</span>
                Exam results
                <a class="zc-right-head-link" href="my_results.php">View all →</a>
            </div>
            <div class="zc-right-body">
                <?php if ($examSummary['taken'] === 0): ?>
                    <div class="zc-tt-empty">
                        🎓 No exams taken yet.<br>
                        Complete a monthly or final exam to see your results.
                    </div>
                <?php else: ?>
                    <div class="zc-exam-grid">
                        <div class="zc-exam-stat">
                            <div class="v"><?php echo (int)$examSummary['taken']; ?></div>
                            <div class="l">Taken</div>
                        </div>
                        <div class="zc-exam-stat">
                            <div class="v gold">
                                <?php echo $examSummary['possible'] > 0 ? (int)round($examSummary['points']) : '—'; ?>
                            </div>
                            <div class="l">Points</div>
                        </div>
                        <div class="zc-exam-stat">
                            <div class="v"><?php echo (int)$examSummary['passed']; ?></div>
                            <div class="l">Passed</div>
                        </div>
                        <div class="zc-exam-stat">
                            <div class="v danger"><?php echo (int)$examSummary['failed']; ?></div>
                            <div class="l">Failed</div>
                        </div>
                    </div>

                    <?php if ($examSummary['passed'] > 0 || $examSummary['failed'] > 0):
                        $total = max(1, $examSummary['passed'] + $examSummary['failed']);
                        $passRate = round(($examSummary['passed'] / $total) * 100);
                    ?>
                        <div class="zc-exam-badge">
                            <span>Pass rate</span>
                            <strong><?php echo $passRate; ?>%</strong>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <a class="zc-exam-cta" href="my_results.php">
                    📄 View detailed results →
                </a>
            </div>
        </div>

        <?php if ($bookStoreLive): ?>
            <a class="zc-books zc-m-library <?php echo $bookStoreBgOk ? 'has-bg' : 'no-bg'; ?>"
               href="<?php echo e($bookStoreUrl); ?>"
               style="display:block;border-radius:6px;overflow:hidden;min-height:170px;text-decoration:none;color:#fff;position:relative;
                      <?php echo $bookStoreBgOk
                            ? "background-image:linear-gradient(180deg, rgba(0,0,0,.2) 0%, rgba(0,0,0,.82) 100%), url('" . e($bookStoreBg) . "');background-size:cover,cover;background-position:center,center;"
                            : 'background:linear-gradient(180deg,rgba(0,0,0,.25) 0%,rgba(0,0,0,.85) 100%),linear-gradient(135deg,#0E4A34,#051810);'; ?>">
                <div style="position:relative;z-index:1;padding:16px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;height:100%;">
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#D9BC72;margin-bottom:4px;">📖 MyIslam Library</div>
                    <h3 style="font-size:17px;font-weight:800;margin:0 0 4px;color:#fff;">Books &amp; Resources</h3>
                    <p style="font-size:11.5px;opacity:.9;margin:0 0 10px;line-height:1.5;">Tafsir, hadith, fiqh, seerah — free to read.</p>
                    <span style="display:inline-block;padding:6px 12px;background:#D9BC72;color:#1a1a1a;border-radius:5px;font-size:11px;font-weight:800;width:fit-content;">Browse library →</span>
                </div>
            </a>
        <?php else: ?>
            <div class="zc-m-library" style="display:block;border-radius:6px;overflow:hidden;min-height:170px;color:#fff;position:relative;margin-bottom:14px;
                        <?php echo $bookStoreBgOk
                              ? "background-image:linear-gradient(180deg, rgba(0,0,0,.2) 0%, rgba(0,0,0,.82) 100%), url('" . e($bookStoreBg) . "');background-size:cover,cover;background-position:center,center;"
                              : 'background:linear-gradient(180deg,rgba(0,0,0,.25) 0%,rgba(0,0,0,.85) 100%),linear-gradient(135deg,#0E4A34,#051810);'; ?>">
                <div style="position:relative;z-index:1;padding:16px;display:flex;flex-direction:column;justify-content:flex-end;height:100%;">
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#D9BC72;margin-bottom:4px;">📖 MyIslam Library</div>
                    <h3 style="font-size:17px;font-weight:800;margin:0 0 4px;color:#fff;">Books &amp; Resources</h3>
                    <p style="font-size:11.5px;opacity:.9;margin:0 0 10px;line-height:1.5;">Tafsir, hadith, fiqh, seerah — free to read.</p>
                    <span style="display:inline-block;padding:6px 12px;background:rgba(255,255,255,.15);color:rgba(255,255,255,.75);border-radius:999px;font-size:11px;font-weight:800;width:fit-content;">🔒 Coming soon</span>
                </div>
            </div>
        <?php endif; ?>

    </aside>
</div>
</div>

<?php
courseShellEnd();