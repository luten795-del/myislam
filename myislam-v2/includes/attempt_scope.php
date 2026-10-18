<?php
declare(strict_types=1);

/**
 * Resolve which course enrollment an attempt should be attributed to.
 * Priority:
 *   1. If division_id is given, find the enrollment for that division.
 *   2. Else, if course_id is given directly, find the user's active enrollment for it.
 *   3. Else, if the quiz/exam is attached to a course via its own course_id column,
 *      use that.
 *
 * Returns ['course_id' => int|null, 'enrollment_id' => int|null]
 */
function resolveAttemptScope(PDO $conn, int $userId, array $context = []): array
{
    $divisionId = (int)($context['division_id'] ?? 0);
    $courseId   = (int)($context['course_id'] ?? 0);
    $quizId     = (int)($context['quiz_id'] ?? 0);
    $examId     = (int)($context['exam_id'] ?? 0);

    // If a quiz is passed, try to derive the course from quizzes.course_id
    if ($courseId <= 0 && $quizId > 0) {
        try {
            $stmt = $conn->prepare("SELECT course_id FROM quizzes WHERE id = ? LIMIT 1");
            $stmt->execute([$quizId]);
            $courseId = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {}
    }

    // 1. Try by division
    if ($divisionId > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT e.id AS enrollment_id, e.course_id
                FROM course_enrollments e
                JOIN course_divisions cd ON cd.course_id = e.course_id
                WHERE e.user_id = ? AND cd.id = ?
                  AND e.status IN ('active','completed')
                ORDER BY e.started_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $divisionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['course_id' => (int)$row['course_id'], 'enrollment_id' => (int)$row['enrollment_id']];
            }
        } catch (PDOException $e) {}
    }

    // 2. Try by course
    if ($courseId > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT id AS enrollment_id, course_id
                FROM course_enrollments
                WHERE user_id = ? AND course_id = ?
                  AND status IN ('active','completed')
                ORDER BY started_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $courseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['course_id' => (int)$row['course_id'], 'enrollment_id' => (int)$row['enrollment_id']];
            }
        } catch (PDOException $e) {}
    }

    return ['course_id' => null, 'enrollment_id' => null];
}