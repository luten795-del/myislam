<?php
declare(strict_types=1);

// --- JSON endpoint safety bootstrap ---
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
date_default_timezone_set('Africa/Dar_es_Salaam');

header('Content-Type: application/json; charset=utf-8');

// Missing / expired session → return JSON, NOT a redirect to login.php
if (empty($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Login required.']);
    exit;
}

try {
    require_once __DIR__ . '/includes/course_layout.php';
    $ctx = courseShellBoot();
} catch (Throwable $e) {
    error_log('my_results_detail boot: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error.']);
    exit;
}

if (!isset($ctx['conn']) || !($ctx['conn'] instanceof PDO)) {
    echo json_encode(['ok' => false, 'message' => 'Database unavailable.']);
    exit;
}

$conn   = $ctx['conn'];
$userId = (int)$ctx['userId'];

function jsonOut(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$source    = (string)($_GET['source'] ?? '');
$attemptId = (int)($_GET['attempt_id'] ?? 0);

// source=subject needs subject_id + course_id; the others need attempt_id.
$isSubjectMode = ($source === 'subject');
if (!$isSubjectMode && ($attemptId <= 0 || !in_array($source, ['weekly', 'monthly', 'semester'], true))) {
    jsonOut(['ok' => false, 'message' => 'Invalid request.']);
}

/**
 * Assert the user owns the attempt. Uses the attempt's own enrollment_id as
 * the authoritative link (falls back to course_id ownership when NULL).
 * On failure emits JSON "Attempt not found." — do not leak existence.
 */
function assertAttemptOwner(PDO $conn, int $userId, ?int $enrollmentId, ?int $courseId): void
{
    try {
        if ($enrollmentId !== null && $enrollmentId > 0) {
            $stmt = $conn->prepare("
                SELECT 1 FROM course_enrollments
                WHERE id = ? AND user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$enrollmentId, $userId]);
            if ($stmt->fetchColumn()) return;

            jsonOut(['ok' => false, 'message' => 'Attempt not found.']);
        }

        if ($courseId !== null && $courseId > 0) {
            $stmt = $conn->prepare("
                SELECT 1 FROM course_enrollments
                WHERE user_id = ? AND course_id = ? AND status IN ('active','completed')
                LIMIT 1
            ");
            $stmt->execute([$userId, $courseId]);
            if (!$stmt->fetchColumn()) {
                jsonOut(['ok' => false, 'message' => 'Attempt not found.']);
            }
        }
    } catch (PDOException $e) {
        error_log('assertAttemptOwner: ' . $e->getMessage());
        jsonOut(['ok' => false, 'message' => 'Attempt not found.']);
    }
}

try {
    // ============================================================
    // SUBJECT MODE — top scorers for a whole subject (all types)
    // ============================================================
    if ($isSubjectMode) {
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $courseId  = (int)($_GET['course_id']  ?? 0);
        if ($subjectId <= 0 || $courseId <= 0) {
            jsonOut(['ok' => false, 'message' => 'Missing subject_id or course_id.']);
        }

        assertAttemptOwner($conn, $userId, null, $courseId);

        // Subject must belong to the course.
        try {
            $chk = $conn->prepare("
                SELECT 1 FROM course_subjects
                WHERE subject_id = ? AND course_id = ?
                LIMIT 1
            ");
            $chk->execute([$subjectId, $courseId]);
            if (!$chk->fetchColumn()) {
                jsonOut(['ok' => false, 'message' => 'Subject not in course.']);
            }
        } catch (PDOException $e) {
            jsonOut(['ok' => false, 'message' => 'Subject lookup failed.']);
        }

        // Total course budget — matches the "Division total / 600" denominator
        // shown on course.php so points everywhere are out of the same value.
        $courseBudget = 0;
        try {
            $bStmt = $conn->prepare("
                SELECT SUM(
                    COALESCE(quiz_total_points,0)
                  + COALESCE(exam_total_points,0)
                  + COALESCE(final_total_points,0)
                ) AS total_budget
                FROM course_subjects
                WHERE course_id = ?
            ");
            $bStmt->execute([$courseId]);
            $courseBudget = (int)round((float)$bStmt->fetchColumn());
        } catch (PDOException $e) {
            error_log('subject top scorers budget: ' . $e->getMessage());
        }

        // [FIX] Subject-mode aggregation:
        //   • No JOIN quizzes — the attempt row carries its own subject_id,
        //     course_id, division_id (populated at insert time). A quiz whose
        //     course_id is NULL (subject-level quiz, reused across courses)
        //     no longer silently drops every row.
        //   • No JOIN course_enrollments in the filter — historical attempts
        //     survive even if the student later drops.
        //   • Same for monthly and semester branches.
        $sql = "
            SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                   SUM(x.pts) AS total_points,
                   SUM(x.of)  AS total_attempt_points
            FROM (
                -- [FIX] Weekly quizzes — filter on attempt snapshot columns
                SELECT qa.user_id, qa.score AS pts, qa.total_points AS of
                FROM quiz_attempts qa
                WHERE qa.status = 'completed'
                  AND qa.course_id  = ?
                  AND qa.subject_id = ?

                UNION ALL

                -- [FIX] Monthly exams — filter on attempt snapshot columns
                SELECT mea.user_id, mea.score AS pts, mea.total_points AS of
                FROM monthly_exam_attempts mea
                WHERE mea.status = 'completed'
                  AND mea.course_id  = ?
                  AND mea.subject_id = ?

                UNION ALL

                -- [FIX] Semester exams — filter on attempt snapshot columns
                SELECT sea.user_id, sea.score AS pts, sea.total_points AS of
                FROM semester_exam_attempts sea
                WHERE sea.status = 'completed'
                  AND sea.course_id  = ?
                  AND sea.subject_id = ?
            ) x
            JOIN users u ON u.id = x.user_id AND u.is_active = 1
            GROUP BY u.id, u.username, u.full_name, u.profile_image
            ORDER BY total_points DESC, total_attempt_points DESC, u.id ASC
            LIMIT 10
        ";

        $participants = [];
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $courseId, $subjectId,
                $courseId, $subjectId,
                $courseId, $subjectId,
            ]);
            $rank = 1;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = trim((string)($r['username'] ?: ($r['full_name'] ?: 'User')));
                $points = (int)round((float)$r['total_points']);
                if ($courseBudget > 0) {
                    $points = min($points, $courseBudget);
                }
                $participants[] = [
                    'rank'         => $rank++,
                    'user_id'      => (int)$r['user_id'],
                    'display_name' => ((int)$r['user_id'] === $userId) ? 'You' : $name,
                    'initials'     => strtoupper(mb_substr($name, 0, 2)),
                    'is_me'        => ((int)$r['user_id'] === $userId),
                    'points'       => $points,
                    'budget'       => $courseBudget,
                ];
            }
        } catch (PDOException $e) {
            error_log('subject top scorers query: ' . $e->getMessage());
        }

        jsonOut([
            'ok' => true,
            'participants' => $participants,
            'subject' => [
                'id'     => $subjectId,
                'course' => $courseId,
                'budget' => $courseBudget,
            ],
        ]);
    }

    // ============================================================
    // ATTEMPT MODE (weekly / monthly / semester)
    // ============================================================
    $attempt = null;
    $questions = [];
    $topParticipants = [];
    $itemMeta = [];
    $itemIdForParticipants = 0;
    $myPct = 0.0;
    $attemptCourseId = 0;
    $myPoints = 0;
    $myTotal  = 0;

    // ============================================================
    // WEEKLY QUIZ
    // ============================================================
    if ($source === 'weekly') {
        $stmt = $conn->prepare("
            SELECT qa.*, q.title, q.slug, q.passing_score, q.subject_id, q.mode
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            WHERE qa.id = ? AND qa.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$attemptId, $userId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) jsonOut(['ok' => false, 'message' => 'Attempt not found.']);

        // Prefer the attempt's own course_id (snapshot at insert time).
        // Fall back to the quiz's course_id only if the attempt row is legacy.
        $attemptCourseId = (int)($attempt['course_id'] ?? 0);
        if ($attemptCourseId <= 0) {
            try {
                $qStmt = $conn->prepare("SELECT course_id FROM quizzes WHERE id = ? LIMIT 1");
                $qStmt->execute([(int)$attempt['quiz_id']]);
                $attemptCourseId = (int)$qStmt->fetchColumn();
            } catch (PDOException $e) {
                $attemptCourseId = 0;
            }
        }

        assertAttemptOwner(
            $conn,
            $userId,
            isset($attempt['enrollment_id']) ? (int)$attempt['enrollment_id'] : null,
            $attemptCourseId > 0 ? $attemptCourseId : null
        );

        $itemIdForParticipants = (int)$attempt['quiz_id'];
        $myPct    = (float)$attempt['percentage'];
        $myPoints = (int)$attempt['score'];
        $myTotal  = (int)$attempt['total_points'];

        $itemMeta = [
            'type'  => 'weekly',
            'title' => (string)$attempt['title'],
            'label' => 'Weekly quiz',
            'icon'  => '📝',
        ];

        $decoded = json_decode((string)($attempt['answers_json'] ?? '[]'), true);
        if (is_array($decoded) && !empty($decoded)) {
            $first = reset($decoded);
            if (is_array($first)) {
                foreach ($decoded as $r) {
                    if (is_array($r)) $questions[] = normalizeResultRow($r);
                }
            } else {
                $questions = rebuildQuestionsFromFlatMap($conn, 'weekly', $itemIdForParticipants, $decoded);
            }
        }
        if (!$questions) {
            $questions = rebuildQuizQuestions($conn, (int)$attempt['quiz_id']);
        }

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(qa.score)        AS best_points,
                       MAX(qa.total_points) AS best_total,
                       MAX(qa.percentage)   AS best_pct
                FROM quiz_attempts qa
                JOIN users u ON u.id = qa.user_id
                WHERE qa.quiz_id = ?
                  AND qa.status = 'completed'
                  AND u.is_active = 1
                  AND (qa.course_id = ? OR qa.course_id IS NULL)
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_points DESC, best_pct DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(qa.score)        AS best_points,
                       MAX(qa.total_points) AS best_total,
                       MAX(qa.percentage)   AS best_pct
                FROM quiz_attempts qa
                JOIN users u ON u.id = qa.user_id
                WHERE qa.quiz_id = ? AND qa.status = 'completed' AND u.is_active = 1
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_points DESC, best_pct DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants]);
        }
        $topParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id,
                           MAX(score) AS b,
                           MAX(percentage) AS bp
                    FROM quiz_attempts
                    WHERE quiz_id = ?
                      AND status = 'completed'
                      AND (course_id = ? OR course_id IS NULL)
                    GROUP BY user_id
                    HAVING (MAX(score) > ?) OR (MAX(score) = ? AND MAX(percentage) > ?)
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId, $myPoints, $myPoints, $myPct]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id,
                           MAX(score) AS b,
                           MAX(percentage) AS bp
                    FROM quiz_attempts
                    WHERE quiz_id = ? AND status = 'completed'
                    GROUP BY user_id
                    HAVING (MAX(score) > ?) OR (MAX(score) = ? AND MAX(percentage) > ?)
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $myPoints, $myPoints, $myPct]);
        }
        $myRank = (int)$stmt->fetchColumn();
    }

    // ============================================================
    // MONTHLY EXAM
    // ============================================================
    elseif ($source === 'monthly') {
        $stmt = $conn->prepare("
            SELECT mea.*, me.title, me.passing_score
            FROM monthly_exam_attempts mea
            JOIN monthly_exams me ON me.id = mea.exam_id
            WHERE mea.id = ? AND mea.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$attemptId, $userId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) jsonOut(['ok' => false, 'message' => 'Attempt not found.']);

        $attemptCourseId = (int)($attempt['course_id'] ?? 0);

        assertAttemptOwner(
            $conn,
            $userId,
            isset($attempt['enrollment_id']) ? (int)$attempt['enrollment_id'] : null,
            $attemptCourseId > 0 ? $attemptCourseId : null
        );

        $itemIdForParticipants = (int)$attempt['exam_id'];
        $myPct    = (float)$attempt['percentage'];
        $myPoints = (int)$attempt['score'];
        $myTotal  = (int)$attempt['total_points'];

        $itemMeta = [
            'type'  => 'monthly',
            'title' => (string)$attempt['title'],
            'label' => 'Monthly exam',
            'icon'  => '📅',
        ];

        $decoded = json_decode((string)($attempt['answers_json'] ?? '[]'), true);
        if (is_array($decoded) && !empty($decoded)) {
            $first = reset($decoded);
            if (is_array($first)) {
                foreach ($decoded as $r) {
                    if (is_array($r)) $questions[] = normalizeResultRow($r);
                }
            } else {
                $questions = rebuildQuestionsFromFlatMap($conn, 'monthly', $itemIdForParticipants, $decoded);
            }
        }
        if (!$questions) {
            $questions = rebuildMonthlyQuestions($conn, $itemIdForParticipants);
        }

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(mea.score)        AS best_points,
                       MAX(mea.total_points) AS best_total,
                       MAX(mea.percentage)   AS best_pct
                FROM monthly_exam_attempts mea
                JOIN users u ON u.id = mea.user_id
                WHERE mea.exam_id = ?
                  AND mea.status = 'completed'
                  AND u.is_active = 1
                  AND (mea.course_id = ? OR mea.course_id IS NULL)
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_points DESC, best_pct DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(mea.score)        AS best_points,
                       MAX(mea.total_points) AS best_total,
                       MAX(mea.percentage)   AS best_pct
                FROM monthly_exam_attempts mea
                JOIN users u ON u.id = mea.user_id
                WHERE mea.exam_id = ? AND mea.status = 'completed' AND u.is_active = 1
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_points DESC, best_pct DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants]);
        }
        $topParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id,
                           MAX(score) AS b,
                           MAX(percentage) AS bp
                    FROM monthly_exam_attempts
                    WHERE exam_id = ?
                      AND status = 'completed'
                      AND (course_id = ? OR course_id IS NULL)
                    GROUP BY user_id
                    HAVING (MAX(score) > ?) OR (MAX(score) = ? AND MAX(percentage) > ?)
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId, $myPoints, $myPoints, $myPct]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id,
                           MAX(score) AS b,
                           MAX(percentage) AS bp
                    FROM monthly_exam_attempts
                    WHERE exam_id = ? AND status = 'completed'
                    GROUP BY user_id
                    HAVING (MAX(score) > ?) OR (MAX(score) = ? AND MAX(percentage) > ?)
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $myPoints, $myPoints, $myPct]);
        }
        $myRank = (int)$stmt->fetchColumn();
    }

    // ============================================================
    // SEMESTER EXAM
    // ============================================================
    else {
        $stmt = $conn->prepare("
            SELECT sea.*, se.title, se.passing_score, se.exam_type
            FROM semester_exam_attempts sea
            JOIN semester_exams se ON se.id = sea.exam_id
            WHERE sea.id = ? AND sea.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$attemptId, $userId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) jsonOut(['ok' => false, 'message' => 'Attempt not found.']);

        $attemptCourseId = (int)($attempt['course_id'] ?? 0);

        assertAttemptOwner(
            $conn,
            $userId,
            isset($attempt['enrollment_id']) ? (int)$attempt['enrollment_id'] : null,
            $attemptCourseId > 0 ? $attemptCourseId : null
        );

        $itemIdForParticipants = (int)$attempt['exam_id'];
        $myPct    = (float)$attempt['percentage'];
        $myPoints = (int)$attempt['score'];
        $myTotal  = (int)$attempt['total_points'];

        $itemMeta = [
            'type'  => 'semester',
            'title' => (string)$attempt['title'],
            'label' => $attempt['exam_type'] === 'supplementary' ? 'Supplementary' : 'Final exam',
            'icon'  => $attempt['exam_type'] === 'supplementary' ? '📚' : '🎓',
        ];

        $decoded = json_decode((string)($attempt['answers_json'] ?? '[]'), true);
        if (is_array($decoded) && !empty($decoded)) {
            $first = reset($decoded);
            if (is_array($first)) {
                foreach ($decoded as $r) {
                    if (is_array($r)) $questions[] = normalizeResultRow($r);
                }
            } else {
                $questions = rebuildQuestionsFromFlatMap($conn, 'semester', $itemIdForParticipants, $decoded);
            }
        }
        if (!$questions) {
            $questions = rebuildSemesterQuestions($conn, $itemIdForParticipants);
        }

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(sea.score)        AS best_points,
                       MAX(sea.total_points) AS best_total,
                       MAX(sea.percentage)   AS best_pct
                FROM semester_exam_attempts sea
                JOIN users u ON u.id = sea.user_id
                WHERE sea.exam_id = ?
                  AND sea.status = 'completed'
                  AND u.is_active = 1
                  AND (sea.course_id = ? OR sea.course_id IS NULL)
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_points DESC, best_pct DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(sea.score)        AS best_points,
                       MAX(sea.total_points) AS best_total,
                       MAX(sea.percentage)   AS best_pct
                FROM semester_exam_attempts sea
                JOIN users u ON u.id = sea.user_id
                WHERE sea.exam_id = ? AND sea.status = 'completed' AND u.is_active = 1
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_points DESC, best_pct DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants]);
        }
        $topParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id,
                           MAX(score) AS b,
                           MAX(percentage) AS bp
                    FROM semester_exam_attempts
                    WHERE exam_id = ?
                      AND status = 'completed'
                      AND (course_id = ? OR course_id IS NULL)
                    GROUP BY user_id
                    HAVING (MAX(score) > ?) OR (MAX(score) = ? AND MAX(percentage) > ?)
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId, $myPoints, $myPoints, $myPct]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id,
                           MAX(score) AS b,
                           MAX(percentage) AS bp
                    FROM semester_exam_attempts
                    WHERE exam_id = ? AND status = 'completed'
                    GROUP BY user_id
                    HAVING (MAX(score) > ?) OR (MAX(score) = ? AND MAX(percentage) > ?)
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $myPoints, $myPoints, $myPct]);
        }
        $myRank = (int)$stmt->fetchColumn();
    }

    foreach ($topParticipants as $i => &$p) {
        $p['rank'] = $i + 1;
        $p['is_me'] = ((int)$p['user_id'] === $userId);
        $name = $p['username'] ?: ($p['full_name'] ?: 'User');
        $p['display_name'] = $p['is_me'] ? 'You' : $name;
        $p['initials'] = strtoupper(mb_substr($name, 0, 2));

        $p['points'] = (int)round((float)$p['best_points']);
        $p['budget'] = (int)round((float)$p['best_total']);
        $p['best_pct'] = round((float)$p['best_pct'], 1);
    }
    unset($p);

    jsonOut([
        'ok' => true,
        'attempt' => [
            'id'          => $attemptId,
            'score'       => $myPoints,
            'total'       => $myTotal,
            'percentage'  => round($myPct, 1),
            'passed'      => ((int)$attempt['passed'] === 1),
            'completed_at'=> (string)($attempt['completed_at'] ?? ''),
            'my_rank'     => $myRank,
        ],
        'meta' => $itemMeta,
        'questions' => $questions,
        'participants' => $topParticipants,
    ]);

} catch (Throwable $e) {
    error_log('my_results_detail error: ' . $e->getMessage());
    jsonOut(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ============================================================
// HELPERS
// ============================================================

function normalizeResultRow(array $r): array {
    $userAns = $r['user_answer_text']
            ?? $r['user_answer']
            ?? $r['selected_text']
            ?? '';

    $correctAns = $r['correct_answer_text']
               ?? $r['correct_answer']
               ?? $r['expected_answer']
               ?? '';

    return [
        'question_id'    => (int)($r['question_id'] ?? 0),
        'question_text'  => (string)($r['question_text'] ?? ''),
        'user_answer'    => is_scalar($userAns) ? (string)$userAns : '',
        'correct_answer' => is_scalar($correctAns) ? (string)$correctAns : '',
        'is_correct'     => !empty($r['is_correct']),
        'points_earned'  => (int)($r['points_earned'] ?? 0),
        'feedback'       => (string)($r['feedback'] ?? $r['explanation'] ?? ''),
    ];
}

function rebuildQuestionsFromFlatMap(PDO $conn, string $source, int $examId, array $map): array
{
    $out = [];

    $table       = 'monthly_exam_questions';
    $optionTable = 'monthly_exam_question_options';
    $explTable   = 'monthly_exam_question_explanations';
    $fkColumn    = 'exam_id';

    if ($source === 'semester') {
        $table       = 'semester_exam_questions';
        $optionTable = 'semester_exam_options';
        $explTable   = null;
    } elseif ($source === 'weekly') {
        $table       = 'quiz_questions';
        $optionTable = 'quiz_options';
        $explTable   = null;
        $fkColumn    = 'quiz_id';
    }

    try {
        $questionSelect = 'q.id, q.question_text, q.question_type, q.points';
        if ($source === 'weekly') {
            $questionSelect .= ', q.explanation';
        }
        if ($explTable !== null) {
            $stmt = $conn->prepare("
                SELECT {$questionSelect}, e.explanation
                FROM {$table} q
                LEFT JOIN {$explTable} e ON e.question_id = q.id
                WHERE q.{$fkColumn} = ?
                ORDER BY q.sort_order ASC, q.id ASC
            ");
        } else {
            $explanationCol = ($source === 'semester') ? ', q.explanation' : '';
            $stmt = $conn->prepare("
                SELECT {$questionSelect}{$explanationCol}
                FROM {$table} q
                WHERE q.{$fkColumn} = ?
                ORDER BY q.sort_order ASC, q.id ASC
            ");
        }
        $stmt->execute([$examId]);
        $qs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $optStmt = $conn->prepare("
            SELECT id, option_text, is_correct
            FROM {$optionTable}
            WHERE question_id = ?
            ORDER BY sort_order ASC, id ASC
        ");

        foreach ($qs as $q) {
            $qid = (int)$q['id'];
            $qtype = (string)($q['question_type'] ?? 'multiple_choice');
            $userAns = $map[$qid] ?? ($map[(string)$qid] ?? null);

            if ($qtype === 'explanation') {
                $expected = (string)($q['explanation'] ?? '');
                $text = is_scalar($userAns) ? (string)$userAns : '';
                $out[] = [
                    'question_id'    => $qid,
                    'question_text'  => (string)$q['question_text'],
                    'user_answer'    => $text,
                    'correct_answer' => $expected,
                    'is_correct'     => false,
                    'points_earned'  => 0,
                    'feedback'       => '',
                ];
                continue;
            }

            $optStmt->execute([$qid]);
            $options = $optStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $userOptId = $userAns !== null && is_scalar($userAns) ? (int)$userAns : 0;
            $correctOptId = 0;
            $correctText = '';
            $userText = '';

            foreach ($options as $opt) {
                if ((int)$opt['id'] === $userOptId) {
                    $userText = (string)$opt['option_text'];
                }
                if (!empty($opt['is_correct'])) {
                    $correctOptId = (int)$opt['id'];
                    $correctText = (string)$opt['option_text'];
                }
            }

            $isCorrect = ($userOptId > 0 && $userOptId === $correctOptId);
            $out[] = [
                'question_id'    => $qid,
                'question_text'  => (string)$q['question_text'],
                'user_answer'    => $userText,
                'correct_answer' => $correctText,
                'is_correct'     => $isCorrect,
                'points_earned'  => $isCorrect ? (int)($q['points'] ?? 0) : 0,
                'feedback'       => (string)($q['explanation'] ?? ''),
            ];
        }
    } catch (PDOException $e) {
        error_log('rebuildQuestionsFromFlatMap(' . $source . ') error: ' . $e->getMessage());
    }

    return $out;
}

function rebuildQuizQuestions(PDO $conn, int $quizId): array {
    try {
        $stmt = $conn->prepare("
            SELECT id, question_text, question_type, points, explanation
            FROM quiz_questions
            WHERE quiz_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$quizId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $correct = '';
            if ($q['question_type'] !== 'explanation') {
                $oStmt = $conn->prepare("
                    SELECT option_text FROM quiz_options
                    WHERE question_id = ? AND is_correct = 1 LIMIT 1
                ");
                $oStmt->execute([(int)$q['id']]);
                $correct = (string)$oStmt->fetchColumn();
            }
            $out[] = [
                'question_id'    => (int)$q['id'],
                'question_text'  => (string)$q['question_text'],
                'user_answer'    => '',
                'correct_answer' => $correct,
                'is_correct'     => false,
                'points_earned'  => 0,
                'feedback'       => (string)($q['explanation'] ?? ''),
            ];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

function rebuildMonthlyQuestions(PDO $conn, int $examId): array {
    try {
        $stmt = $conn->prepare("
            SELECT q.id, q.question_text, q.question_type, q.points,
                   e.explanation
            FROM monthly_exam_questions q
            LEFT JOIN monthly_exam_question_explanations e ON e.question_id = q.id
            WHERE q.exam_id = ?
            ORDER BY q.sort_order ASC, q.id ASC
        ");
        $stmt->execute([$examId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $correct = '';
            if ($q['question_type'] !== 'explanation') {
                $oStmt = $conn->prepare("
                    SELECT option_text FROM monthly_exam_question_options
                    WHERE question_id = ? AND is_correct = 1 LIMIT 1
                ");
                $oStmt->execute([(int)$q['id']]);
                $correct = (string)$oStmt->fetchColumn();
            }
            $out[] = [
                'question_id'    => (int)$q['id'],
                'question_text'  => (string)$q['question_text'],
                'user_answer'    => '',
                'correct_answer' => $correct,
                'is_correct'     => false,
                'points_earned'  => 0,
                'feedback'       => (string)($q['explanation'] ?? ''),
            ];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

function rebuildSemesterQuestions(PDO $conn, int $examId): array {
    try {
        $stmt = $conn->prepare("
            SELECT id, question_text, question_type, points, explanation
            FROM semester_exam_questions
            WHERE exam_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$examId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $correct = '';
            if ($q['question_type'] !== 'explanation') {
                $oStmt = $conn->prepare("
                    SELECT option_text FROM semester_exam_options
                    WHERE question_id = ? AND is_correct = 1 LIMIT 1
                ");
                $oStmt->execute([(int)$q['id']]);
                $correct = (string)$oStmt->fetchColumn();
            }
            $out[] = [
                'question_id'    => (int)$q['id'],
                'question_text'  => (string)$q['question_text'],
                'user_answer'    => '',
                'correct_answer' => $correct,
                'is_correct'     => false,
                'points_earned'  => 0,
                'feedback'       => (string)($q['explanation'] ?? ''),
            ];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}