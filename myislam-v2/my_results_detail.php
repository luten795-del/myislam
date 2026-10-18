<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = (int)$ctx['userId'];

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$source    = (string)($_GET['source'] ?? '');
$attemptId = (int)($_GET['attempt_id'] ?? 0);

if ($attemptId <= 0 || !in_array($source, ['weekly', 'monthly', 'semester'], true)) {
    jsonOut(['ok' => false, 'message' => 'Invalid request.']);
}

try {
    $attempt = null;
    $questions = [];
    $topParticipants = [];
    $itemMeta = [];
    $itemIdForParticipants = 0;
    $myPct = 0.0;
    $attemptCourseId = 0;   // NEW: course scope for participant queries

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

        $itemIdForParticipants = (int)$attempt['quiz_id'];
        $myPct = (float)$attempt['percentage'];
        // quiz_attempts has no course_id column today; leave scope at 0 so
        // the participant query falls back to the un-scoped branch.
        $attemptCourseId = 0;

        $itemMeta = [
            'type'  => 'weekly',
            'title' => (string)$attempt['title'],
            'label' => 'Weekly quiz',
            'icon'  => '📝',
        ];

        $decoded = json_decode((string)($attempt['answers_json'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $r) {
                $questions[] = normalizeResultRow($r);
            }
        }

        if (!$questions) {
            $questions = rebuildQuizQuestions($conn, (int)$attempt['quiz_id']);
        }

        // Course scope for participants — try the quizzes row (may be NULL),
        // then fall back to nothing so legacy attempts stay visible.
        try {
            $qStmt = $conn->prepare("SELECT course_id FROM quizzes WHERE id = ? LIMIT 1");
            $qStmt->execute([$itemIdForParticipants]);
            $attemptCourseId = (int)$qStmt->fetchColumn();
        } catch (PDOException $e) {
            $attemptCourseId = 0;
        }

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(qa.percentage) AS best_pct, MAX(qa.score) AS best_score
                FROM quiz_attempts qa
                JOIN users u ON u.id = qa.user_id
                WHERE qa.quiz_id = ?
                  AND qa.status = 'completed'
                  AND u.is_active = 1
                  AND (qa.course_id = ? OR qa.course_id IS NULL)
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_pct DESC, best_score DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(qa.percentage) AS best_pct, MAX(qa.score) AS best_score
                FROM quiz_attempts qa
                JOIN users u ON u.id = qa.user_id
                WHERE qa.quiz_id = ? AND qa.status = 'completed' AND u.is_active = 1
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_pct DESC, best_score DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants]);
        }
        $topParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id, MAX(percentage) AS b
                    FROM quiz_attempts
                    WHERE quiz_id = ?
                      AND status = 'completed'
                      AND (course_id = ? OR course_id IS NULL)
                    GROUP BY user_id
                    HAVING MAX(percentage) > ?
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId, $myPct]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id, MAX(percentage) AS b
                    FROM quiz_attempts
                    WHERE quiz_id = ? AND status = 'completed'
                    GROUP BY user_id
                    HAVING MAX(percentage) > ?
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $myPct]);
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

        $itemIdForParticipants = (int)$attempt['exam_id'];
        $myPct = (float)$attempt['percentage'];
        // NEW: read course scope from the attempt row itself
        $attemptCourseId = (int)($attempt['course_id'] ?? 0);

        $itemMeta = [
            'type'  => 'monthly',
            'title' => (string)$attempt['title'],
            'label' => 'Monthly exam',
            'icon'  => '📅',
        ];

        $decoded = json_decode((string)($attempt['answers_json'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $r) {
                $questions[] = normalizeResultRow($r);
            }
        }

        if (!$questions) {
            $questions = rebuildMonthlyQuestions($conn, $itemIdForParticipants);
        }

        // Scoped when the attempt row carries a course_id; tolerant of legacy NULL rows.
        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(mea.percentage) AS best_pct, MAX(mea.score) AS best_score
                FROM monthly_exam_attempts mea
                JOIN users u ON u.id = mea.user_id
                WHERE mea.exam_id = ?
                  AND mea.status = 'completed'
                  AND u.is_active = 1
                  AND (mea.course_id = ? OR mea.course_id IS NULL)
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_pct DESC, best_score DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(mea.percentage) AS best_pct, MAX(mea.score) AS best_score
                FROM monthly_exam_attempts mea
                JOIN users u ON u.id = mea.user_id
                WHERE mea.exam_id = ? AND mea.status = 'completed' AND u.is_active = 1
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_pct DESC, best_score DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants]);
        }
        $topParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id, MAX(percentage) AS b
                    FROM monthly_exam_attempts
                    WHERE exam_id = ?
                      AND status = 'completed'
                      AND (course_id = ? OR course_id IS NULL)
                    GROUP BY user_id
                    HAVING MAX(percentage) > ?
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId, $myPct]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id, MAX(percentage) AS b
                    FROM monthly_exam_attempts
                    WHERE exam_id = ? AND status = 'completed'
                    GROUP BY user_id
                    HAVING MAX(percentage) > ?
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $myPct]);
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

        $itemIdForParticipants = (int)$attempt['exam_id'];
        $myPct = (float)$attempt['percentage'];
        $attemptCourseId = (int)($attempt['course_id'] ?? 0);

        $itemMeta = [
            'type'  => 'semester',
            'title' => (string)$attempt['title'],
            'label' => $attempt['exam_type'] === 'supplementary' ? 'Supplementary' : 'Final exam',
            'icon'  => $attempt['exam_type'] === 'supplementary' ? '📚' : '🎓',
        ];

        $decoded = json_decode((string)($attempt['answers_json'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $r) {
                $questions[] = normalizeResultRow($r);
            }
        }

        if (!$questions) {
            $questions = rebuildSemesterQuestions($conn, $itemIdForParticipants);
        }

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(sea.percentage) AS best_pct, MAX(sea.score) AS best_score
                FROM semester_exam_attempts sea
                JOIN users u ON u.id = sea.user_id
                WHERE sea.exam_id = ?
                  AND sea.status = 'completed'
                  AND u.is_active = 1
                  AND (sea.course_id = ? OR sea.course_id IS NULL)
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_pct DESC, best_score DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId]);
        } else {
            $stmt = $conn->prepare("
                SELECT u.id AS user_id, u.username, u.full_name, u.profile_image,
                       MAX(sea.percentage) AS best_pct, MAX(sea.score) AS best_score
                FROM semester_exam_attempts sea
                JOIN users u ON u.id = sea.user_id
                WHERE sea.exam_id = ? AND sea.status = 'completed' AND u.is_active = 1
                GROUP BY u.id, u.username, u.full_name, u.profile_image
                ORDER BY best_pct DESC, best_score DESC, u.id ASC
                LIMIT 10
            ");
            $stmt->execute([$itemIdForParticipants]);
        }
        $topParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($attemptCourseId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id, MAX(percentage) AS b
                    FROM semester_exam_attempts
                    WHERE exam_id = ?
                      AND status = 'completed'
                      AND (course_id = ? OR course_id IS NULL)
                    GROUP BY user_id
                    HAVING MAX(percentage) > ?
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $attemptCourseId, $myPct]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) + 1 FROM (
                    SELECT user_id, MAX(percentage) AS b
                    FROM semester_exam_attempts
                    WHERE exam_id = ? AND status = 'completed'
                    GROUP BY user_id
                    HAVING MAX(percentage) > ?
                ) x
            ");
            $stmt->execute([$itemIdForParticipants, $myPct]);
        }
        $myRank = (int)$stmt->fetchColumn();
    }

    // Format participants
    foreach ($topParticipants as $i => &$p) {
        $p['rank'] = $i + 1;
        $p['is_me'] = ((int)$p['user_id'] === $userId);
        $name = $p['username'] ?: ($p['full_name'] ?: 'User');
        $p['display_name'] = $p['is_me'] ? 'You' : $name;
        $p['initials'] = strtoupper(substr($name, 0, 2));
        $p['best_pct'] = round((float)$p['best_pct'], 1);
    }
    unset($p);

    jsonOut([
        'ok' => true,
        'attempt' => [
            'id'          => $attemptId,
            'score'       => (int)$attempt['score'],
            'total'       => (int)$attempt['total_points'],
            'percentage'  => round((float)$attempt['percentage'], 1),
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