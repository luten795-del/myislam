<?php
declare(strict_types=1);

/**
 * Certificate helper functions
 */

function certificateCodeExists(PDO $conn, string $code): bool
{
    $stmt = $conn->prepare("
        SELECT id 
        FROM certificates 
        WHERE certificate_code = ? 
        LIMIT 1
    ");
    $stmt->execute([$code]);
    return (bool)$stmt->fetch();
}

function generateCertificateCode(PDO $conn): string
{
    do {
        $code = strtoupper('CERT-' . bin2hex(random_bytes(8)));
    } while (certificateCodeExists($conn, $code));

    return $code;
}

function hasActiveCertificate(PDO $conn, int $userId, string $type, ?int $subjectId = null): ?string
{
    $stmt = $conn->prepare("
        SELECT certificate_code
        FROM certificates
        WHERE user_id = ?
          AND certification_type = ?
          AND (
                subject_id = ?
                OR (subject_id IS NULL AND ? IS NULL)
              )
          AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$userId, $type, $subjectId, $subjectId]);

    $code = $stmt->fetchColumn();
    return $code ? (string)$code : null;
}

function issueCertificate(PDO $conn, int $userId, string $type, ?int $subjectId = null): string
{
    $existing = hasActiveCertificate($conn, $userId, $type, $subjectId);

    if ($existing) {
        return $existing;
    }

    $code = generateCertificateCode($conn);

    $title = $type === 'general'
        ? 'General Certificate of Achievement'
        : 'Subject Certificate of Achievement';

    $summary = $type === 'general'
        ? 'Successfully completed and passed all certification levels across all active subjects.'
        : 'Successfully completed and passed all certification levels for this subject.';

    $stmt = $conn->prepare("
        INSERT INTO certificates
            (
                user_id,
                subject_id,
                certificate_code,
                certification_type,
                title,
                achievement_summary,
                completed_at,
                issued_at,
                status
            )
        VALUES
            (?, ?, ?, ?, ?, ?, NOW(), NOW(), 'active')
    ");

    $stmt->execute([
        $userId,
        $subjectId,
        $code,
        $type,
        $title,
        $summary
    ]);

    return $code;
}

function getCertificateByCode(PDO $conn, string $code): ?array
{
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            u.full_name,
            u.email,
            s.name AS subject_name
        FROM certificates c
        JOIN users u ON u.id = c.user_id
        LEFT JOIN subjects s ON s.id = c.subject_id
        WHERE c.certificate_code = ?
        LIMIT 1
    ");
    $stmt->execute([$code]);

    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    return 
    $certificate ?: null;
}
function getProgressThreshold(int $level): float {
    return match ($level) {
        1 => 25,   // Level 1 unlocked at  25% progress
        2 => 50,   // Level 2 at 50%
        3 => 75,   // Level 3 at 75%
        4 => 100,  // Level 4 (final) at 100%
        default => 100,
    };
}
function getOverallProgress(PDO $conn, int $userId): float {
    $stmt = $conn->query("SELECT COUNT(*) FROM contents WHERE status = 'published'");
    $total = (int)$stmt->fetchColumn();
    if ($total === 0) return 0.0;

    $stmt = $conn->prepare("SELECT COUNT(*) FROM user_content_progress WHERE user_id = ? AND is_completed = 1");
    $stmt->execute([$userId]);
    $completed = (int)$stmt->fetchColumn();

    return round(($completed / $total) * 100, 2);
}
function getSubjectProgress(PDO $conn, int $userId, int $subjectId): float {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM contents
        WHERE subject_id = ? AND status = 'published'
    ");
    $stmt->execute([$subjectId]);
    $total = (int)$stmt->fetchColumn();
    if ($total === 0) return 0.0;

    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM user_content_progress ucp
        JOIN contents c ON ucp.content_id = c.id
        WHERE ucp.user_id = ? AND ucp.is_completed = 1 AND c.subject_id = ?
    ");
    $stmt->execute([$userId, $subjectId]);
    $completed = (int)$stmt->fetchColumn();

    return round(($completed / $total) * 100, 2);
}

function getUserCertificates(PDO $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            s.name AS subject_name
        FROM certificates c
        LEFT JOIN subjects s ON s.id = c.subject_id
        WHERE c.user_id = ?
        ORDER BY c.issued_at DESC
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}