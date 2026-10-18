<?php
declare(strict_types=1);

/**
 * Helpers for the Semester Exam system.
 * Include this AFTER course_helpers.php.
 *
 * Points-based model: semesterExamSubmit() writes raw earned/possible points
 * into enrollment_course_grades, not percentages.
 *
 * Option A model: each semester exam is tied to exactly one subject
 * (semester_exams.course_subject_id is NOT NULL). No fan-out — one exam,
 * one subject, one row in enrollment_course_grades.
 *
 * Course scoping:
 *   - semester_exam_attempts.course_id / .enrollment_id are populated on
 *     every INSERT so that per-course filtering (analytics, retakes, admin
 *     reports) does not need to JOIN through exams/divisions.
 *   - semesterExamCanTake() scopes its attempt count by course_id when the
 *     caller passes one, so a user can never be locked out of an exam in
 *     course A by an attempt recorded against the same exam in course B.
 */

function semesterExamForDivision(
    PDO $conn,
    int $divisionId,
    int $courseSubjectId = 0,
    string $examType = 'final'
): ?array {
    $sql = "
        SELECT se.*
        FROM semester_exams se
        WHERE se.division_id = ?
          AND se.exam_type = ?
          AND se.status = 'published'
    ";
    $params = [$divisionId, $examType];

    if ($courseSubjectId > 0) {
        $sql .= " AND se.course_subject_id = ?";
        $params[] = $courseSubjectId;
    }

    $sql .= " ORDER BY se.id DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Resolve (course_id, enrollment_id) for a user against a given exam.
 * Used internally by semesterExamStartAttempt() when the caller does not
 * pass them explicitly, and safe to call standalone from other pages.
 *
 * Returns ['course_id' => int, 'enrollment_id' => int] (0 when unknown).
 */
function semesterExamResolveScope(PDO $conn, int $examId, int $userId): array
{
    $courseId     = 0;
    $enrollmentId = 0;

    try {
        $stmt = $conn->prepare("
            SELECT cd.course_id
            FROM semester_exams se
            JOIN course_divisions cd ON cd.id = se.division_id
            WHERE se.id = ?
            LIMIT 1
        ");
        $stmt->execute([$examId]);
        $courseId = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('semesterExamResolveScope course lookup: ' . $e->getMessage());
    }

    if ($courseId > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT id FROM course_enrollments
                WHERE user_id = ? AND course_id = ? AND status IN ('active','completed')
                ORDER BY started_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $courseId]);
            $enrollmentId = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('semesterExamResolveScope enrollment lookup: ' . $e->getMessage());
        }
    }

    return ['course_id' => $courseId, 'enrollment_id' => $enrollmentId];
}

function semesterExamCanTake(
    PDO $conn,
    int $examId,
    int $userId,
    ?int $courseId = null
): array {
    $stmt = $conn->prepare("SELECT * FROM semester_exams WHERE id = ? LIMIT 1");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch();
    if (!$exam) return [false, 'Exam not found.'];
    if ($exam['status'] !== 'published') return [false, 'Exam is not published.'];
    if ((int)$exam['allow_access'] !== 1) return [false, 'Exam is not available.'];

    $now = new DateTimeImmutable('now');
    if (!empty($exam['start_date']) && new DateTimeImmutable($exam['start_date']) > $now) {
        return [false, 'Exam has not started yet.'];
    }
    if (!empty($exam['end_date']) && new DateTimeImmutable($exam['end_date']) < $now) {
        return [false, 'Exam has already closed.'];
    }

    // Course-scoped attempt count when a course context is known.
    if ($courseId !== null && $courseId > 0) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM semester_exam_attempts
            WHERE exam_id = ? AND user_id = ? AND course_id = ?
              AND status IN ('completed','started')
        ");
        $stmt->execute([$examId, $userId, $courseId]);
    } else {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM semester_exam_attempts
            WHERE exam_id = ? AND user_id = ?
              AND status IN ('completed','started')
        ");
        $stmt->execute([$examId, $userId]);
    }
    $taken = (int)$stmt->fetchColumn();

    if ($taken >= (int)$exam['max_attempts']) {
        return [false, 'Maximum attempts reached.'];
    }

    return [true, ''];
}

/**
 * Create a new 'started' attempt row.
 *
 * @param int $courseId      Optional — resolved from exam's division if 0.
 * @param int $enrollmentId  Optional — resolved from (user, course) if 0.
 */
function semesterExamStartAttempt(
    PDO $conn,
    int $examId,
    int $userId,
    int $courseId = 0,
    int $enrollmentId = 0
): int {
    // Resolve scope if the caller didn't supply it.
    if ($courseId <= 0 || $enrollmentId <= 0) {
        $resolved = semesterExamResolveScope($conn, $examId, $userId);
        if ($courseId <= 0)     $courseId     = (int)$resolved['course_id'];
        if ($enrollmentId <= 0) $enrollmentId = (int)$resolved['enrollment_id'];
    }

    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(attempt_number), 0) FROM semester_exam_attempts
        WHERE exam_id = ? AND user_id = ?
    ");
    $stmt->execute([$examId, $userId]);
    $nextNum = (int)$stmt->fetchColumn() + 1;

    $stmt = $conn->prepare("
        INSERT INTO semester_exam_attempts
            (user_id, course_id, enrollment_id, exam_id, attempt_number, status, started_at)
        VALUES (?, ?, ?, ?, ?, 'started', NOW())
    ");
    $stmt->execute([
        $userId,
        $courseId     > 0 ? $courseId     : null,
        $enrollmentId > 0 ? $enrollmentId : null,
        $examId,
        $nextNum,
    ]);

    return (int)$conn->lastInsertId();
}

function semesterExamSubmit(PDO $conn, int $attemptId, array $answers): array
{
    $stmt = $conn->prepare("SELECT * FROM semester_exam_attempts WHERE id = ?");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
    if (!$attempt || $attempt['status'] !== 'started') {
        return ['ok' => false, 'message' => 'Invalid attempt.'];
    }

    $examId = (int)$attempt['exam_id'];
    $userId = (int)$attempt['user_id'];

    $stmt = $conn->prepare("SELECT * FROM semester_exams WHERE id = ?");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch();
    if (!$exam) return ['ok' => false, 'message' => 'Exam not found.'];

    $stmt = $conn->prepare("
        SELECT q.* FROM semester_exam_questions q
        WHERE q.exam_id = ? ORDER BY q.sort_order ASC, q.id ASC
    ");
    $stmt->execute([$examId]);
    $questions = $stmt->fetchAll();

    $totalPoints = 0;
    $earned = 0;
    $correct = 0;

    foreach ($questions as $q) {
        $points = (int)$q['points'];
        $totalPoints += $points;

        if ($q['question_type'] === 'multiple_choice' || $q['question_type'] === 'true_false') {
            $optStmt = $conn->prepare("
                SELECT id FROM semester_exam_options
                WHERE question_id = ? AND is_correct = 1 LIMIT 1
            ");
            $optStmt->execute([(int)$q['id']]);
            $correctOpt = (int)$optStmt->fetchColumn();

            if (isset($answers[(int)$q['id']]) && (int)$answers[(int)$q['id']] === $correctOpt) {
                $earned += $points;
                $correct++;
            }
        }
    }

    $percentage = $totalPoints > 0 ? round(($earned / $totalPoints) * 100, 2) : 0;
    $passed = $percentage >= (int)$exam['passing_score'] ? 1 : 0;

    $conn->prepare("
        UPDATE semester_exam_attempts
        SET total_questions = ?, correct_answers = ?,
            score = ?, total_points = ?,
            percentage = ?, passed = ?,
            status = 'completed', completed_at = NOW(),
            answers_json = ?
        WHERE id = ?
    ")->execute([
        count($questions), $correct,
        $earned, $totalPoints,
        $percentage, $passed,
        json_encode($answers),
        $attemptId
    ]);

    // ----------------------------------------------------------------------
    // Academy grade-book sync — points-based
    //
    // Option A model: each semester exam is tied to exactly one subject
    // (semester_exams.course_subject_id IS NOT NULL). We write to that one
    // subject only; no fan-out.
    // ----------------------------------------------------------------------
    $courseSubjectId = (int)($exam['course_subject_id'] ?? 0);
    $divisionId      = (int)($exam['division_id'] ?? 0);

    if ($courseSubjectId > 0 && $divisionId > 0) {
        // Map course_subjects.id → (course_id, subject_id)
        $stmt = $conn->prepare("
            SELECT course_id, subject_id FROM course_subjects WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$courseSubjectId]);
        $cs = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cs) {
            $courseId  = (int)$cs['course_id'];
            $subjectId = (int)$cs['subject_id'];
            $kind = ($exam['exam_type'] === 'supplementary') ? 'supplementary' : 'final';

            if (function_exists('academyRecordAttempt')) {
                $sync = academyRecordAttempt(
                    $conn,
                    $userId,
                    $kind,
                    $courseId,
                    $divisionId,
                    $subjectId,
                    (float)$earned,
                    (float)$totalPoints
                );
                if (empty($sync['ok'])) {
                    error_log('semesterExamSubmit academy sync rejected: '
                        . ($sync['reason'] ?? 'unknown')
                        . " (exam={$examId}, user={$userId}, kind={$kind})");
                }
            } else {
                error_log('semesterExamSubmit: academyRecordAttempt() not defined.');
            }
        } else {
            error_log("semesterExamSubmit: course_subjects.id={$courseSubjectId} not found; exam={$examId}");
        }
    }

    return [
        'ok' => true,
        'percentage' => $percentage,
        'passed' => (bool)$passed,
        'score' => $earned,
        'total_points' => $totalPoints,
    ];
}