<?php
declare(strict_types=1);

/**
 * includes/course_grades.php
 * ---------------------------------------------------------------------------
 * ACADEMY grade book — POINTS-BASED model.
 *
 * Public API
 *   academyRecordAttempt()         kind = 'weekly' | 'monthly' | 'final' | 'supplementary'
 *   academyRecordScheduled()       convenience wrapper for a scheduled quiz / monthly exam
 *   academyComputeSubjectGrade()   returns null until final attempted (Q4 = option 2)
 *   academyCheckDivisionPass()
 *   academyCheckCoursePass()
 *
 * Buckets:
 *   weekly        = competition quiz scheduled as item_type 'quiz'
 *   monthly       = monthly exam scheduled as item_type 'exam'
 *   final         = semester_exams.exam_type = 'final'
 *   supplementary = semester_exams.exam_type = 'supplementary'
 *
 * Points model:
 *   Every attempt passes in (earned, possible). We accumulate raw points
 *   into weekly_total / monthly_total (earned) and weekly_possible /
 *   monthly_possible (available). The subject's budget (from course_subjects)
 *   is the denominator the UI uses to compute progress.
 *
 *   final_score / semester_exam_score: store the RAW POINTS earned on the
 *   final exam, NOT a percentage.
 *   supplementary_score / semester_supplementary_score: same.
 *
 * Pass/fail fires only when final_attempted = 1 (Q4 = option 2).
 */

/** Active enrollment id for (user, course), or 0. */
function academyFindEnrollmentId(PDO $conn, int $userId, int $courseId): int
{
    $stmt = $conn->prepare("
        SELECT id FROM course_enrollments
        WHERE user_id = ? AND course_id = ? AND status = 'active'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$userId, $courseId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Record one academy attempt into enrollment_course_grades and cascade.
 *
 * @param float $earned    Points scored
 * @param float $possible  Points available
 * @return array{ok:bool, reason?:string, grade?:?float, status?:string,
 *               division_passed?:bool, course_passed?:bool}
 */
function academyRecordAttempt(
    PDO $conn,
    int $userId,
    string $kind,
    int $courseId,
    int $divisionId,
    int $subjectId,
    float $earned,
    float $possible
): array {
    if (!in_array($kind, ['weekly', 'monthly', 'final', 'supplementary'], true)) {
        return ['ok' => false, 'reason' => 'bad_kind'];
    }
    $earned   = max(0.0, $earned);
    $possible = max(0.0, $possible);

    // Division must belong to the course
    $stmt = $conn->prepare("SELECT COUNT(*) FROM course_divisions WHERE id = ? AND course_id = ?");
    $stmt->execute([$divisionId, $courseId]);
    if ((int)$stmt->fetchColumn() === 0) {
        return ['ok' => false, 'reason' => 'division_mismatch'];
    }

    // Subject config (budgets + pass mark)
    $stmt = $conn->prepare("SELECT * FROM course_subjects WHERE course_id = ? AND subject_id = ? LIMIT 1");
    $stmt->execute([$courseId, $subjectId]);
    $cfg = $stmt->fetch();
    if (!$cfg) {
        return ['ok' => false, 'reason' => 'subject_not_in_course'];
    }

    $enrollmentId = academyFindEnrollmentId($conn, $userId, $courseId);
    if ($enrollmentId <= 0) {
        return ['ok' => false, 'reason' => 'not_enrolled'];
    }

    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }

    try {
        // Ensure the grade row exists
        $conn->prepare("
            INSERT INTO enrollment_course_grades (enrollment_id, division_id, subject_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE enrollment_id = enrollment_id
        ")->execute([$enrollmentId, $divisionId, $subjectId]);

        $key = [$enrollmentId, $divisionId, $subjectId];

        // Write the right bucket — raw points, not percentages
        switch ($kind) {
            case 'weekly':
                $conn->prepare("
                    UPDATE enrollment_course_grades
                    SET weekly_total    = weekly_total + ?,
                        weekly_possible = weekly_possible + ?,
                        weekly_count    = weekly_count + 1
                    WHERE enrollment_id = ? AND division_id = ? AND subject_id = ?
                ")->execute(array_merge([$earned, $possible], $key));
                break;

            case 'monthly':
                $conn->prepare("
                    UPDATE enrollment_course_grades
                    SET monthly_total    = monthly_total + ?,
                        monthly_possible = monthly_possible + ?,
                        monthly_count    = monthly_count + 1
                    WHERE enrollment_id = ? AND division_id = ? AND subject_id = ?
                ")->execute(array_merge([$earned, $possible], $key));
                break;

            case 'final':
                // Keep the best final attempt (in raw points)
                $conn->prepare("
                    UPDATE enrollment_course_grades
                    SET final_score         = GREATEST(COALESCE(final_score, 0), ?),
                        semester_exam_score = GREATEST(COALESCE(semester_exam_score, 0), ?),
                        final_attempted     = 1
                    WHERE enrollment_id = ? AND division_id = ? AND subject_id = ?
                ")->execute(array_merge([$earned, $earned], $key));
                break;

            case 'supplementary':
                // Cap at the subject's supplementary_max_score if set
                $cap = (int)($cfg['supplementary_max_score'] ?? 90);
                $capped = min($earned, (float)$cap);
                $conn->prepare("
                    UPDATE enrollment_course_grades
                    SET supplementary_score          = GREATEST(COALESCE(supplementary_score, 0), ?),
                        semester_supplementary_score = GREATEST(COALESCE(semester_supplementary_score, 0), ?),
                        supplementary_attempted      = 1
                    WHERE enrollment_id = ? AND division_id = ? AND subject_id = ?
                ")->execute(array_merge([$capped, $capped], $key));
                break;
        }

        // Recompute grade + status
        $res = academyComputeSubjectGrade($conn, $enrollmentId, $divisionId, $subjectId);

        // Cascade division/course pass
        $divPassed = false;
        $coursePassed = false;
        if ($res['status'] !== 'in_progress') {
            $divPassed = academyCheckDivisionPass($conn, $enrollmentId, $divisionId);
            if ($divPassed) {
                $coursePassed = academyCheckCoursePass($conn, $enrollmentId);
            }
        }

        if ($ownTx) {
            $conn->commit();
        }

        return [
            'ok' => true,
            'grade' => $res['grade'],
            'status' => $res['status'],
            'division_passed' => $divPassed,
            'course_passed' => $coursePassed,
        ];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('academyRecordAttempt error: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'db_error'];
    }
}

/**
 * Convenience for scheduled items. Returns null when the item is NOT academy.
 *
 * @param string $itemType 'quiz' (→ weekly) | 'exam' (→ monthly)
 */
function academyRecordScheduled(
    PDO $conn,
    int $userId,
    string $itemType,
    int $itemId,
    float $earned,
    float $possible
): ?array {
    $table = $itemType === 'quiz' ? 'quizzes' : ($itemType === 'exam' ? 'monthly_exams' : null);
    if ($table === null) {
        return null;
    }

    $stmt = $conn->prepare("SELECT course_id, division_id FROM `$table` WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item || empty($item['course_id']) || empty($item['division_id'])) {
        return null;
    }

    // The schedule decides the subject
    $stmt = $conn->prepare("
        SELECT subject_id FROM course_schedule
        WHERE course_id = ? AND division_id = ? AND item_type = ? AND item_id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$item['course_id'], (int)$item['division_id'], $itemType, $itemId]);
    $subjectId = (int)$stmt->fetchColumn();
    if ($subjectId <= 0) {
        return null;
    }

    return academyRecordAttempt(
        $conn,
        $userId,
        $itemType === 'quiz' ? 'weekly' : 'monthly',
        (int)$item['course_id'],
        (int)$item['division_id'],
        $subjectId,
        $earned,
        $possible
    );
}

/**
 * Recompute course_grade + status for one subject row.
 *
 * Points model:
 *   earned = weekly_total + monthly_total + final_slot
 *   total  = quiz_total_points + exam_total_points + final_total_points
 *   grade  = earned / total × 100
 *
 * final_slot = max(final_score, supplementary_score) if either attempted.
 *
 * Status:
 *   in_progress — until final_attempted = 1 (Q4 = option 2)
 *   passed/failed — once final attempted
 *
 * @return array{grade:?float, status:string}
 */
function academyComputeSubjectGrade(PDO $conn, int $enrollmentId, int $divisionId, int $subjectId): array
{
    $stmt = $conn->prepare("
        SELECT g.*,
               cs.passing_score,
               cs.quiz_total_points,
               cs.exam_total_points,
               cs.final_total_points
        FROM enrollment_course_grades g
        JOIN course_enrollments e ON e.id = g.enrollment_id
        JOIN course_subjects cs ON cs.course_id = e.course_id AND cs.subject_id = g.subject_id
        WHERE g.enrollment_id = ? AND g.division_id = ? AND g.subject_id = ?
        LIMIT 1
    ");
    $stmt->execute([$enrollmentId, $divisionId, $subjectId]);
    $g = $stmt->fetch();
    if (!$g) {
        return ['grade' => null, 'status' => 'in_progress'];
    }

    $weeklyEarned  = (float)$g['weekly_total'];
    $monthlyEarned = (float)$g['monthly_total'];

    $final     = $g['final_score'] !== null ? (float)$g['final_score'] : null;
    $supp      = ((int)$g['supplementary_attempted'] === 1 && $g['supplementary_score'] !== null)
        ? (float)$g['supplementary_score'] : null;

    // Final slot = the higher of the two, if either exists
    $finalSlot = null;
    if ($final !== null || $supp !== null) {
        $finalSlot = max($final ?? 0.0, $supp ?? 0.0);
    }

    // Pass/fail only fires once the final has been attempted (Q4 = option 2)
    if ((int)$g['final_attempted'] !== 1 && $supp === null) {
        $conn->prepare("
            UPDATE enrollment_course_grades
            SET course_grade = NULL, status = 'in_progress', passed_at = NULL
            WHERE enrollment_id = ? AND division_id = ? AND subject_id = ?
        ")->execute([$enrollmentId, $divisionId, $subjectId]);
        return ['grade' => null, 'status' => 'in_progress'];
    }

    $earned = $weeklyEarned + $monthlyEarned + ($finalSlot ?? 0.0);
    $budget = (int)$g['quiz_total_points']
            + (int)$g['exam_total_points']
            + (int)$g['final_total_points'];

    if ($budget <= 0) {
        return ['grade' => null, 'status' => 'in_progress'];
    }

    $grade = round(($earned / $budget) * 100.0, 2);
    $grade = min(100.0, max(0.0, $grade));

    $status = $grade >= (float)$g['passing_score'] ? 'passed' : 'failed';

    $conn->prepare("
        UPDATE enrollment_course_grades
        SET course_grade = ?,
            status = ?,
            passed_at = CASE
                WHEN ? = 'passed' THEN COALESCE(passed_at, NOW())
                ELSE NULL
            END
        WHERE enrollment_id = ? AND division_id = ? AND subject_id = ?
    ")->execute([$grade, $status, $status, $enrollmentId, $divisionId, $subjectId]);

    return ['grade' => $grade, 'status' => $status];
}

/**
 * Division result. Fires when every course subject has a decided status.
 */
function academyCheckDivisionPass(PDO $conn, int $enrollmentId, int $divisionId): bool
{
    $stmt = $conn->prepare("
        SELECT e.user_id, e.course_id, c.title AS course_title, d.name AS division_name
        FROM course_enrollments e
        JOIN courses c ON c.id = e.course_id
        JOIN course_divisions d ON d.id = ? AND d.course_id = e.course_id
        WHERE e.id = ?
    ");
    $stmt->execute([$divisionId, $enrollmentId]);
    $ctx = $stmt->fetch();
    if (!$ctx) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT cs.subject_id,
               COALESCE(g.status, 'in_progress') AS status,
               g.course_grade
        FROM course_subjects cs
        LEFT JOIN enrollment_course_grades g
               ON g.subject_id = cs.subject_id
              AND g.enrollment_id = ?
              AND g.division_id = ?
        WHERE cs.course_id = ?
    ");
    $stmt->execute([$enrollmentId, $divisionId, (int)$ctx['course_id']]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return false;
    }

    $passed = 0;
    $failed = 0;
    $pending = 0;
    $sum = 0.0;
    $n = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'passed') {
            $passed++;
        } elseif ($r['status'] === 'failed') {
            $failed++;
        } else {
            $pending++;
        }
        if ($r['course_grade'] !== null) {
            $sum += (float)$r['course_grade'];
            $n++;
        }
    }

    if ($pending > 0) {
        return false;
    }

    $allPassed = ($failed === 0);
    $avg = $n > 0 ? round($sum / $n, 2) : null;

    $conn->prepare("
        INSERT INTO enrollment_division_history
            (enrollment_id, division_id, status, average_grade, courses_passed, courses_failed, completed_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            average_grade = VALUES(average_grade),
            courses_passed = VALUES(courses_passed),
            courses_failed = VALUES(courses_failed),
            completed_at = NOW()
    ")->execute([$enrollmentId, $divisionId, $allPassed ? 'passed' : 'failed', $avg, $passed, $failed]);

    if ($allPassed) {
        academyAwardCertificate(
            $conn,
            (int)$ctx['user_id'],
            'division',
            'MYI-DIV-' . $enrollmentId . '-' . $divisionId,
            'Certificate of Completion — ' . $ctx['division_name'],
            $ctx['course_title'] . ' · ' . $ctx['division_name'] . ' — all subjects passed'
                . ($avg !== null ? ' (average ' . $avg . '%)' : '')
        );
    }

    return $allPassed;
}

/**
 * Course result: every division must be 'passed'.
 */
function academyCheckCoursePass(PDO $conn, int $enrollmentId): bool
{
    $stmt = $conn->prepare("
        SELECT e.user_id, e.course_id, c.title AS course_title
        FROM course_enrollments e
        JOIN courses c ON c.id = e.course_id
        WHERE e.id = ?
    ");
    $stmt->execute([$enrollmentId]);
    $ctx = $stmt->fetch();
    if (!$ctx) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN h.status = 'passed' THEN 1 ELSE 0 END) AS passed,
               AVG(h.average_grade) AS avg_grade
        FROM course_divisions d
        LEFT JOIN enrollment_division_history h
               ON h.division_id = d.id AND h.enrollment_id = ?
        WHERE d.course_id = ?
    ");
    $stmt->execute([$enrollmentId, (int)$ctx['course_id']]);
    $r = $stmt->fetch();

    $total = (int)($r['total'] ?? 0);
    $passed = (int)($r['passed'] ?? 0);
    if ($total === 0 || $passed < $total) {
        return false;
    }

    $conn->prepare("
        UPDATE course_enrollments
        SET status = 'completed', division_status = 'completed', completed_at = COALESCE(completed_at, NOW())
        WHERE id = ?
    ")->execute([$enrollmentId]);

    $avg = $r['avg_grade'] !== null ? round((float)$r['avg_grade'], 2) : null;
    academyAwardCertificate(
        $conn,
        (int)$ctx['user_id'],
        'course',
        'MYI-CRS-' . $enrollmentId,
        'Course Certificate — ' . $ctx['course_title'],
        'Successfully passed every subject in every division of ' . $ctx['course_title']
            . ($avg !== null ? ' (overall average ' . $avg . '%)' : '')
    );

    return true;
}

/**
 * Insert a division/course certificate once.
 */
function academyAwardCertificate(
    PDO $conn,
    int $userId,
    string $type,
    string $code,
    string $title,
    string $summary
): void {
    if (!in_array($type, ['division', 'course'], true)) {
        return;
    }
    try {
        $conn->prepare("
            INSERT IGNORE INTO certificates
                (user_id, subject_id, certificate_code, certification_type,
                 title, achievement_summary, completed_at, status)
            VALUES (?, NULL, ?, ?, ?, ?, NOW(), 'active')
        ")->execute([$userId, $code, $type, $title, $summary]);
    } catch (PDOException $e) {
        error_log('academyAwardCertificate error: ' . $e->getMessage());
    }
}