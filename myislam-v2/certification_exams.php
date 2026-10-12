<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();
require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/certificate_functions.php';
require_once __DIR__ . '/includes/auth_functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$conn = db();

// ----------- HELPERS -----------
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : 'U';
}


/**
 * Returns true when a user has passed a specific subject certification level.
 */
function certListHasPassedSubjectLevel(PDO $conn, int $userId, int $subjectId, int $level): bool {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM certification_exam_attempts a
            INNER JOIN certification_exams e ON e.id = a.exam_id
            WHERE a.user_id = ?
              AND e.certification_type = 'subject'
              AND e.subject_id = ?
              AND e.level = ?
              AND a.passed = 1
        ");
        $stmt->execute([$userId, $subjectId, $level]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('certListHasPassedSubjectLevel: ' . $e->getMessage());
        return false;
    }
}

/**
 * Returns true when a user has passed a specific general certification level.
 */
function certListHasPassedGeneralLevel(PDO $conn, int $userId, int $level): bool {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM certification_exam_attempts a
            INNER JOIN certification_exams e ON e.id = a.exam_id
            WHERE a.user_id = ?
              AND e.certification_type = 'general'
              AND e.level = ?
              AND a.passed = 1
        ");
        $stmt->execute([$userId, $level]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('certListHasPassedGeneralLevel: ' . $e->getMessage());
        return false;
    }
}

/**
 * Returns true only when the user has passed this certification level in every active subject.
 */
function certListHasPassedAllSubjectsAtLevel(PDO $conn, int $userId, int $level): bool {
    try {
        $subjects = $conn->query("SELECT id FROM subjects WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$subjects) {
            return false;
        }

        foreach ($subjects as $subject) {
            if (!certListHasPassedSubjectLevel($conn, $userId, (int)$subject['id'], $level)) {
                return false;
            }
        }

        return true;
    } catch (Throwable $e) {
        error_log('certListHasPassedAllSubjectsAtLevel: ' . $e->getMessage());
        return false;
    }
}

/**
 * Builds the prerequisite lock state for the certification listing page.
 * This mirrors the hard lock that must also exist in take_certification_exam.php.
 */
function certListPrerequisiteStatus(PDO $conn, int $userId, string $type, ?int $subjectId, int $level): array {
    $level = max(1, $level);

    if ($type === 'subject') {
        if ($level <= 1) {
            return ['ok' => true, 'reason' => ''];
        }

        $previousLevel = $level - 1;
        if (!certListHasPassedSubjectLevel($conn, $userId, (int)$subjectId, $previousLevel)) {
            return [
                'ok' => false,
                'reason' => "Pass Level {$previousLevel} for this subject before taking Level {$level}."
            ];
        }

        return ['ok' => true, 'reason' => ''];
    }

    if ($type === 'general') {
        if ($level > 1) {
            $previousLevel = $level - 1;
            if (!certListHasPassedGeneralLevel($conn, $userId, $previousLevel)) {
                return [
                    'ok' => false,
                    'reason' => "Pass General Level {$previousLevel} before taking General Level {$level}."
                ];
            }
        }

        if (!certListHasPassedAllSubjectsAtLevel($conn, $userId, $level)) {
            return [
                'ok' => false,
                'reason' => "Pass Level {$level} in all active subjects before taking General Level {$level}."
            ];
        }

        return ['ok' => true, 'reason' => ''];
    }

    return ['ok' => true, 'reason' => ''];
}

/**
 * Determines which exam (if any) should be offered for a given level.
 * Exams within a level are taken in strict order of attempt_sequence.
 * Once an exam is passed, the level is permanently locked.
 * After a failed/abandoned attempt, a cooldown is applied before the next exam becomes available.
 * No exam can be retaken.
 */
function getLevelStatus(PDO $conn, int $userId, array $exams, int $level): array {
    $now = new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam'));

    // Sort exams by attempt_sequence (1,2,3...)
    usort($exams, fn($a, $b) => (int)$a['attempt_sequence'] - (int)$b['attempt_sequence']);

    if (empty($exams)) {
        return ['exam' => null, 'can_start' => false, 'status_text' => 'No Exam', 'badge_class' => 'badge-locked', 'reason' => ''];
    }

    $maxAttempts = max((int)($exams[0]['max_attempts_per_level'] ?? 3), 1);
    $retryDelayHours = max((int)($exams[0]['retry_delay_hours'] ?? 24), 0);

    // Fetch all attempts for this user across all exams of this level
    $examIds = array_map(fn($e) => (int)$e['id'], $exams);
    if (empty($examIds)) {
        return ['exam' => null, 'can_start' => false, 'status_text' => 'No Exam', 'badge_class' => 'badge-locked', 'reason' => ''];
    }
    $placeholders = implode(',', array_fill(0, count($examIds), '?'));
    $stmt = $conn->prepare("SELECT a.* FROM certification_exam_attempts a WHERE a.user_id = ? AND a.exam_id IN ($placeholders) ORDER BY a.id ASC");
    $stmt->execute(array_merge([$userId], $examIds));
    $allAttempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 1. Check if the level is already passed
    foreach ($allAttempts as $att) {
        if ($att['passed'] == 1) {
            return ['exam' => null, 'can_start' => false, 'status_text' => '🏆 Passed', 'badge_class' => 'badge-passed', 'reason' => 'Congratulations. You have passed this certification level. You are not allowed to retake this exam.'];
        }
    }

    // 2. Count existing attempts and find the highest attempted sequence
    $attemptCount = count($allAttempts);
    $highestAttemptedSeq = 0;
    $lastAttempt = null;
    foreach ($allAttempts as $att) {
        foreach ($exams as $e) {
            if ((int)$e['id'] === (int)$att['exam_id']) {
                $seq = (int)$e['attempt_sequence'];
                if ($seq > $highestAttemptedSeq) $highestAttemptedSeq = $seq;
                break;
            }
        }
    }
    if ($attemptCount > 0) {
        $lastAttempt = end($allAttempts);
    }

    // 3. Max attempts reached?
    if ($attemptCount >= $maxAttempts) {
        return ['exam' => null, 'can_start' => false, 'status_text' => '❌ Attempts Used', 'badge_class' => 'badge-locked', 'reason' => "You have used all {$maxAttempts} attempts for this level."];
    }

    // 4. Active (started) attempt – only that exam can be resumed
    $activeAttempt = null;
    foreach ($allAttempts as $att) {
        if ($att['status'] === 'started') {
            $activeAttempt = $att;
            break;
        }
    }
    if ($activeAttempt) {
        $activeExam = null;
        foreach ($exams as $e) {
            if ((int)$e['id'] === (int)$activeAttempt['exam_id']) {
                $activeExam = $e;
                break;
            }
        }
        if ($activeExam) {
            $windowActive = true;
            if (!empty($activeExam['start_date']) && !empty($activeExam['end_date'])) {
                $start = new DateTime($activeExam['start_date'], new DateTimeZone('Africa/Dar_es_Salaam'));
                $end   = new DateTime($activeExam['end_date'], new DateTimeZone('Africa/Dar_es_Salaam'));
                $windowActive = ($now >= $start && $now <= $end);
            }
            if ($windowActive) {
                if (isset($activeExam['allow_access']) && (int)$activeExam['allow_access'] === 0) {
                    return ['exam' => $activeExam, 'can_start' => false, 'status_text' => '🚫 Unavailable', 'badge_class' => 'badge-locked', 'reason' => 'This exam has been made unavailable by the admin. Please check back later.'];
                }
                return ['exam' => $activeExam, 'can_start' => true, 'status_text' => '▶ Resume', 'badge_class' => 'badge-available', 'reason' => ''];
            } else {
                return ['exam' => $activeExam, 'can_start' => false, 'status_text' => '🔒 Ended', 'badge_class' => 'badge-locked', 'reason' => 'Exam window has ended.'];
            }
        }
    }

    // 5. Cooldown from a failed/abandoned/terminated last attempt
    if ($lastAttempt && in_array($lastAttempt['status'], ['completed', 'abandoned', 'terminated'], true) && (int)$lastAttempt['passed'] === 0) {
        $completionTime = !empty($lastAttempt['completed_at']) ? new DateTime($lastAttempt['completed_at'], new DateTimeZone('Africa/Dar_es_Salaam')) : null;
        if (!$completionTime && !empty($lastAttempt['started_at'])) {
            $completionTime = new DateTime($lastAttempt['started_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
        }
        if ($completionTime && $retryDelayHours > 0) {
            $unlockTime = clone $completionTime;
            $unlockTime->modify('+' . $retryDelayHours . ' hours');
            if ($now < $unlockTime) {
                $remaining = $now->diff($unlockTime);
                $hoursLeft = $remaining->h + ($remaining->days * 24);
                $minutesLeft = $remaining->i;
                $cooldownStr = "{$hoursLeft}h {$minutesLeft}m";
                $nextSeq = $highestAttemptedSeq + 1;
                $nextExam = null;
                foreach ($exams as $e) {
                    if ((int)$e['attempt_sequence'] === $nextSeq) {
                        $nextExam = $e;
                        break;
                    }
                }
                if ($nextExam) {
                    $unlockLabel = $unlockTime->format('M d, Y H:i');
                    if ($lastAttempt['status'] === 'terminated') {
                        $reason = 'Your previous attempt was terminated due to exam rule violations. Your next attempt unlocks at ' . $unlockLabel . ' (' . $cooldownStr . ' remaining).';
                    } elseif ($lastAttempt['status'] === 'abandoned') {
                        $reason = 'Your previous attempt was not completed. Your next attempt unlocks at ' . $unlockLabel . ' (' . $cooldownStr . ' remaining).';
                    } else {
                        $reason = 'You failed to reach the passing score. Please go back to the lessons and review. Your next attempt unlocks at ' . $unlockLabel . ' (' . $cooldownStr . ' remaining).';
                    }
                    return ['exam' => $nextExam, 'can_start' => false, 'status_text' => '⏳ Cooldown', 'badge_class' => 'badge-cooldown', 'reason' => $reason, 'cooldown_remaining' => $cooldownStr, 'unlock_at' => $unlockTime->format('c')];
                }
                return ['exam' => null, 'can_start' => false, 'status_text' => 'No Exam', 'badge_class' => 'badge-locked', 'reason' => 'No further exams available'];
            }
        }
    }

    // 6. No active attempt, cooldown expired (or never started) → offer the next exam in sequence
    $nextSeq = $highestAttemptedSeq + 1;
    $nextExam = null;
    foreach ($exams as $e) {
        if ((int)$e['attempt_sequence'] === $nextSeq) {
            $nextExam = $e;
            break;
        }
    }
    if (!$nextExam) {
        return ['exam' => null, 'can_start' => false, 'status_text' => 'No Exam', 'badge_class' => 'badge-locked', 'reason' => ''];
    }

    // Check exam window for the next exam
    $windowActive = true;
    $windowUpcoming = false;
    $windowEnded = false;
    $windowLabel = '';
    if (!empty($nextExam['start_date']) && !empty($nextExam['end_date'])) {
        $start = new DateTime($nextExam['start_date'], new DateTimeZone('Africa/Dar_es_Salaam'));
        $end   = new DateTime($nextExam['end_date'], new DateTimeZone('Africa/Dar_es_Salaam'));
        $windowActive = ($now >= $start && $now <= $end);
        $windowUpcoming = ($now < $start);
        $windowEnded = ($now > $end);
        $windowLabel = date('M d, Y H:i', strtotime($nextExam['start_date'])) . ' - ' . date('M d, Y H:i', strtotime($nextExam['end_date']));
    }

    if (!$windowActive) {
        if ($windowUpcoming) {
            return ['exam' => $nextExam, 'can_start' => false, 'status_text' => '⏳ Upcoming', 'badge_class' => 'badge-locked', 'reason' => 'Opens ' . date('M d, Y H:i', strtotime($nextExam['start_date']))];
        } else {
            return ['exam' => $nextExam, 'can_start' => false, 'status_text' => '🔒 Ended', 'badge_class' => 'badge-locked', 'reason' => 'Window ended.'];
        }
    }

    // Admin access lock - overrides everything else (window, prerequisites,
    // attempts, cooldown) since it's meant as an unconditional block.
    if (isset($nextExam['allow_access']) && (int)$nextExam['allow_access'] === 0) {
        return ['exam' => $nextExam, 'can_start' => false, 'status_text' => '🚫 Unavailable', 'badge_class' => 'badge-locked', 'reason' => 'This exam has been made unavailable by the admin. Please check back later.', 'window_label' => $windowLabel];
    }

    // Everything is clear – user can start this exam
    return [
        'exam'          => $nextExam,
        'can_start'     => true,
        'status_text'   => 'Available',
        'badge_class'   => 'badge-available',
        'reason'        => '',
        'window_label'  => $windowLabel,
        'attempt_number'=> $nextSeq,
        'max_attempts'  => $maxAttempts
    ];
}

// ----------------------------------------
// Fetch user for header
$stmt = $conn->prepare("SELECT full_name, email, profile_image, role, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$dbUser = $stmt->fetch();
if (!$dbUser) { session_destroy(); header('Location: login.php'); exit; }
$user = [
    'name'         => $dbUser['full_name'] ?? 'User',
    'email'        => $dbUser['email'] ?? '',
    'picture'      => $dbUser['profile_image'] ?? '',
    'role'         => $dbUser['role'] ?? 'user',
    'member_since' => date('F Y', strtotime($dbUser['created_at'] ?? 'now')),
];
$unreadCount = 0;
try { $unreadCount = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId AND is_read = 0")->fetchColumn(); } catch (Throwable $e) {}

// Fetch all subjects
$allSubjects = $conn->query("SELECT id, name, icon FROM subjects WHERE is_active = 1 ORDER BY sort_order")->fetchAll();

// Fetch published certification exams, including the new attempt_sequence column
$examsRaw = $conn->query("SELECT * FROM certification_exams WHERE status = 'published' ORDER BY certification_type, subject_id, level, attempt_sequence")->fetchAll();

// Group by track (type + subject_id) → level → exams
$grouped = [];
foreach ($examsRaw as $e) {
    $key = $e['certification_type'] . '_' . ($e['subject_id'] ?? 0);
    $lvl = (int)$e['level'];
    if (!isset($grouped[$key][$lvl])) {
        $grouped[$key][$lvl] = [];
    }
    $grouped[$key][$lvl][] = $e;
}

$selectedSubject = $_GET['subject'] ?? 'all';
$subject = null;
$tracks = [];

// ---- General Certificate Track ----
if ($selectedSubject === 'all' || $selectedSubject === 'general') {
    $overallProgress = getOverallProgress($conn, $userId);
    $genKey = 'general_0';
    $levels = [];
    for ($lvl = 1; $lvl <= 4; $lvl++) {
        $exams = $grouped[$genKey][$lvl] ?? [];
        $threshold = getProgressThreshold($lvl);
        $unlocked = $overallProgress >= $threshold;
        $status = getLevelStatus($conn, $userId, $exams, $lvl);
        $prerequisite = certListPrerequisiteStatus($conn, $userId, 'general', null, $lvl);
        if (!$prerequisite['ok'] && (($status['status_text'] ?? '') !== '🏆 Passed')) {
            $unlocked = false;
            $status['can_start'] = false;
            $status['status_text'] = '🔒 Locked';
            $status['badge_class'] = 'badge-locked';
            $status['reason'] = $prerequisite['reason'];
        }
        $levels[] = [
            'level'     => $lvl,
            'exam'      => $status['exam'],
            'unlocked'  => $unlocked,
            'progress'  => $overallProgress,
            'threshold' => $threshold,
            'status'    => $status,
        ];
    }
    $tracks[] = ['type' => 'general', 'title' => '🌍 General Certificate (All Subjects)', 'levels' => $levels];
}

// ---- Subject Tracks ----
$displaySubjects = $allSubjects;
if ($selectedSubject !== 'all' && is_numeric($selectedSubject)) {
    $displaySubjects = array_filter($allSubjects, fn($s) => (int)$s['id'] === (int)$selectedSubject);
}
if ($selectedSubject === 'all' || is_numeric($selectedSubject)) {
    foreach ($displaySubjects as $s) {
        $subjProgress = getSubjectProgress($conn, $userId, (int)$s['id']);
        $subjKey = 'subject_' . $s['id'];
        $levels = [];
        for ($lvl = 1; $lvl <= 4; $lvl++) {
            $exams = $grouped[$subjKey][$lvl] ?? [];
            $threshold = getProgressThreshold($lvl);
            $unlocked = $subjProgress >= $threshold;
            $status = getLevelStatus($conn, $userId, $exams, $lvl);
            $prerequisite = certListPrerequisiteStatus($conn, $userId, 'subject', (int)$s['id'], $lvl);
            if (!$prerequisite['ok'] && (($status['status_text'] ?? '') !== '🏆 Passed')) {
                $unlocked = false;
                $status['can_start'] = false;
                $status['status_text'] = '🔒 Locked';
                $status['badge_class'] = 'badge-locked';
                $status['reason'] = $prerequisite['reason'];
            }
            $levels[] = [
                'level'     => $lvl,
                'exam'      => $status['exam'],
                'unlocked'  => $unlocked,
                'progress'  => $subjProgress,
                'threshold' => $threshold,
                'status'    => $status,
            ];
        }
        $tracks[] = ['type' => 'subject', 'subject_id' => (int)$s['id'], 'title' => ($s['icon'] ?? '📚') . ' ' . $s['name'], 'levels' => $levels];
    }
}
$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist yet – ignore
} catch (Throwable $e) {
    error_log('Error fetching unread notifications count: ' . $e->getMessage());
}
$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'user_dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'subjects.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'quizzes.php', 'active' => false],
    ['name' => 'Monthly Exams', 'link' => 'monthly_exams.php', 'active' => false],
    ['name' => 'Leaderboard', 'link' => 'leaderboard.php', 'active' => false],
    ['name' => 'Multimedia', 'link' => 'multimedia.php', 'active' => false],
    ['name' => 'Notifications', 'link' => 'notification.php', 'active' => false],
    ['name' => 'My Certificates', 'link' => 'my_certificates.php', 'active' => false],
    ['name' => 'Help Center', 'link' => 'help_center.php', 'active' => false],
];

// Sidebar navigation
$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Quizzes', 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Monthly Exams', 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
    ['name' => 'Certification Exams', 'link' => 'certification_exams.php', 'active' => true, 'icon' => '🎓'],
    ['name' => 'My Certificates', 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
    ['name' => 'Leaderboard', 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => 'Multimedia', 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => 'Notifications', 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => 'Help Center', 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Certification Exams – MyIslam</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #f5f7f5;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --surface-3: #eef2f7;
            --line: #e2e5e2;
            --text: #1f2937;
            --text-secondary: #4b5563;
            --muted: #6b7280;

            --brand: #0E4A34;
            --brand-dark: #0A3626;
            --brand-light: #3E7A5E;
            --brand-soft: rgba(14, 74, 52, 0.08);

            --gold: #9C7A2E;
            --gold-soft: rgba(156, 122, 46, 0.10);

            --accent: #3E6E91;
            --accent-soft: rgba(62, 110, 145, 0.10);

            --white: #ffffff;
            --warning: #b45309;
            --danger: #b91c1c;
            --danger-soft: rgba(185, 28, 28, 0.08);
            --success: #0E4A34;

            --header-bg: #ffffff;
            --header-height: 72px;
            --overlay-dim: rgba(15, 23, 32, 0.45);
            --glass-bg-strong: rgba(255, 255, 255, 0.92);
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 20px;
        }

        /* ===== Base Body (Light Mode) ===== */
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            padding-bottom: 24px;
            position: relative;
        }

        /* ===== Dark Mode ===== */
        body.dark-mode {
            --bg: #0f1720;
            --surface: #18212b;
            --surface-2: #1d2833;
            --surface-3: #22303c;
            --line: #304150;
            --text: #edf2f7;
            --text-secondary: #cbd5e1;
            --muted: #94a3b8;

            --brand: #4C9172;
            --brand-dark: #6bb590;
            --brand-light: #7fcaa4;
            --brand-soft: rgba(76, 145, 114, 0.16);

            --gold: #C9A85E;
            --gold-soft: rgba(201, 168, 94, 0.16);

            --accent: #6FA0C4;
            --accent-soft: rgba(111, 160, 196, 0.16);

            --danger: #ef4444;
            --danger-soft: rgba(239, 68, 68, 0.14);
            --header-bg: #13202a;
            --overlay-dim: rgba(0, 0, 0, 0.6);
            --glass-bg-strong: rgba(24, 33, 43, 0.92);
        }

        /* ===== Background Pattern Overlay ===== */
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.025' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
        }

        body.dark-mode::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }

        a { text-decoration: none; color: inherit; }
        button { font: inherit; border: none; background: none; cursor: pointer; }

        a:focus-visible, button:focus-visible, .subject-link:focus-visible {
            outline: 2px solid var(--gold);
            outline-offset: 2px;
            border-radius: 6px;
        }

        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            background: var(--brand);
            color: var(--white);
            padding: 10px 16px;
            border-radius: 0 0 8px 0;
            z-index: 2000;
            font-weight: 700;
        }
        .skip-link:focus { left: 0; }

        /* ===== Header (matches dashboard) ===== */
        .app-header {
            position: sticky; top: 0; z-index: 1000;
            height: var(--header-height);
            background: var(--header-bg);
            border-bottom: 1px solid var(--line);
            box-shadow: var(--shadow-sm);
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 24px; gap: 18px;
        }

        .header-left, .header-right { display: flex; align-items: center; gap: 12px; }
        .header-left { flex: 1; min-width: 0; }

        .header-top-nav {
            display: flex; align-items: center; gap: 8px; flex-wrap: nowrap;
            overflow-x: auto; scrollbar-width: thin; padding-bottom: 2px;
        }

        .header-top-link {
            height: 42px; padding: 0 14px; border-radius: 999px;
            background: var(--surface); border: 1px solid var(--line);
            display: inline-flex; align-items: center; justify-content: center;
            white-space: nowrap; font-size: 13px; font-weight: 700; transition: all 0.2s;
            color: var(--text-secondary);
        }

        .header-top-link:hover, .header-top-link.active {
            background: var(--brand-soft); border-color: var(--brand); color: var(--brand);
        }

        .menu-toggle, .icon-btn, .lang-btn, .brand-box {
            height: 40px; min-width: 30px; border-radius: 10px;
            background: var(--surface); border: 1px solid var(--line);
            display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
        }
        .menu-toggle { width: 50px; font-size: 14px; }

        .menu-toggle:hover, .icon-btn:hover, .lang-btn:hover {
            transform: translateY(-2px); box-shadow: var(--shadow-sm);
            border-color: var(--gold); color: var(--gold);
        }

        .lang-btn { min-width: 30px; padding: 0 8px; font-weight: 600; color: var(--brand); }
        .brand-box { font-size: 22px; font-weight: 700; color: var(--brand); background: transparent; border: none; white-space: nowrap; }
        .icon-btn { font-size: 18px; color: var(--text); position: relative; }

        .profile-area { position: relative; }
        .profile-btn {
            width: 44px; height: 44px; border-radius: 50%; overflow: hidden;
            background: var(--surface); border: 2px solid var(--brand);
            cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center;
            color: var(--brand); font-weight: 700;
        }
        .profile-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }
        .profile-btn img { width: 100%; height: 100%; object-fit: cover; }

        .profile-menu {
            position: absolute; top: 56px; right: 0; width: 280px;
            background: var(--glass-bg-strong);
            backdrop-filter: blur(12px);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg); padding: 16px; display: none;
            box-shadow: var(--shadow-lg); z-index: 1001;
        }
        .profile-menu.show { display: block; }

        .profile-menu-top {
            display: flex; gap: 12px; padding-bottom: 10px; margin-bottom: 10px;
            border-bottom: 1px solid var(--line);
        }
        .profile-menu-top img, .profile-avatar-fallback {
            width: 56px; height: 56px; border-radius: 50%;
        }
        .profile-avatar-fallback {
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--brand-soft); color: var(--brand); font-weight: 700; font-size: 16px;
        }
        .profile-menu-top h4 { font-size: 16px; margin-bottom: 4px; font-weight: bold; color: var(--text); }
        .profile-menu-top p { font-size: 12px; color: var(--text-secondary); font-weight: 600; }

        .profile-menu a {
            display: block; padding: 10px 12px; border-radius: 10px; font-weight: 700;
            font-size: 14px; transition: background 0.2s; margin: 4px 0; color: var(--text);
        }
        .profile-menu a:hover { background: var(--brand-soft); color: var(--brand); }

        .notification-badge {
            position: absolute; top: -4px; right: -4px;
            background: var(--danger); color: var(--white);
            font-size: 10px; font-weight: 700; min-width: 18px; height: 18px;
            border-radius: 999px; display: flex; align-items: center; justify-content: center;
            padding: 0 4px; border: 2px solid var(--surface);
        }

        /* ===== Sidebar (matches dashboard) ===== */
        .sidebar-overlay {
            display: none; position: fixed; top: var(--header-height); left: 0;
            width: 100%; height: calc(100vh - var(--header-height));
            background: var(--overlay-dim); z-index: 1100; backdrop-filter: blur(2px);
        }
        .sidebar-overlay.show { display: block; }

        .sidebar {
            position: fixed; top: var(--header-height); left: -100%;
            width: min(320px, 85vw); height: calc(100vh - var(--header-height));
            background: var(--glass-bg-strong);
            backdrop-filter: blur(12px);
            border-right: 1px solid var(--line);
            padding: 24px 20px; z-index: 1200; transition: left 0.3s ease; overflow-y: auto;
        }
        .sidebar.open { left: 0; }

        .sidebar-title {
            font-size: 12px; color: var(--muted); text-transform: uppercase;
            margin-bottom: 20px; font-weight: 700; letter-spacing: 1px; padding: 0 8px;
        }

        .subject-list { display: grid; gap: 8px; }
        .subject-link {
            display: flex; align-items: center; gap: 12px; padding: 12px 16px;
            border-radius: var(--radius-md); background: var(--surface-2);
            border: 1px solid var(--line); font-size: 14px; font-weight: 500; transition: all 0.2s;
            color: var(--text);
        }
        .subject-link:hover { background: var(--brand-soft); border-color: var(--brand); transform: translateX(4px); }
        .subject-link.active { background: var(--brand-soft); color: var(--brand); border-color: var(--brand); }

        .sidebar-toggle-btn {
            position: fixed; top: var(--header-height); left: 0; z-index: 1300;
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px;
            background: var(--surface); border: 1px solid var(--line);
            border-left: none; border-top: none; border-radius: 0 0 8px 0;
            font-size: 16px; cursor: pointer; color: var(--text); transition: all 0.2s;
            box-shadow: var(--shadow-sm); padding: 0; margin: 0;
        }
        .sidebar-toggle-btn:hover { background: var(--brand-soft); color: var(--brand); }

        /* ===== Page content ===== */
        .main-content { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .page-title { font-size: 28px; font-weight: 800; margin-bottom: 16px; color: var(--brand); }

        .filter-row { margin-bottom: 24px; }
        .filter-select {
            padding: 10px 16px; border: 1px solid var(--line); border-radius: 10px;
            font-size: 16px; background: var(--surface); color: var(--text); cursor: pointer;
        }
        .filter-select:hover { border-color: var(--gold); }

        .track-section {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }
        .track-title { font-size: 22px; font-weight: 700; margin-bottom: 12px; color: var(--text); }

        .level-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }
        .level-card {
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 16px;
            text-align: center;
            background: var(--surface-2);
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        }
        .level-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); border-color: var(--brand); }
        .level-card h3 { font-size: 18px; margin-bottom: 8px; color: var(--text); }
        .level-card p { font-size: 13px; color: var(--muted); margin-bottom: 8px; }

        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 10px;
            font-size: 12px; font-weight: 700;
        }
        .badge-locked { background: var(--surface-3); color: var(--muted); }
        .badge-passed { background: var(--brand-soft); color: var(--brand); }
        .badge-available { background: var(--accent-soft); color: var(--accent); }
        .badge-cooldown { background: var(--gold-soft); color: var(--gold); }

        .btn-sm {
            padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 13px;
            display: inline-block; margin-top: 8px; transition: all 0.2s;
        }
        .btn-primary { background: var(--brand); color: var(--white); }
        .btn-primary:hover { background: var(--brand-dark); }
        .btn-outline { background: transparent; color: var(--brand); border: 1px solid var(--brand); }
        .btn-outline:hover { background: var(--brand-soft); }

        .empty-state { text-align: center; padding: 40px; color: var(--muted); }
        .status-text { font-weight: 700; }
        .attempt-info { font-size: 12px; color: var(--muted); margin-top: 4px; }

        @media (max-width: 1200px) {
            .header-top-nav { max-width: 48vw; }
        }
        @media (max-width: 900px) {
            .header-top-nav { display: none; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 16px; }
            .app-header { padding: 12px 16px; }
        }
    </style>
</head>
<body>

<a href="#mainContent" class="skip-link">Skip to main content</a>

<header class="app-header">
    <div class="header-left">
        <a href="user_dashboard.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
            <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px;">
            <span style="font-size:22px;font-weight:1000;color:var(--brand);">MyIslam</span>
        </a>

        <nav class="header-top-nav" aria-label="Main navigation">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>">
                    <?php echo e($navItem['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="Notifications">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>

        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if (!empty($user['picture'])): ?>
                    <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials((string)$user['name'])); ?>
                <?php endif; ?>
            </button>

            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['picture'])): ?>
                        <img src="<?php echo e($user['picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)$user['name'])); ?></div>
                    <?php endif; ?>

                    <div>
                        <h4><?php echo e((string)$user['name']); ?></h4>
                        <p><?php echo e((string)$user['email']); ?></p>
                        <p style="font-size: 10px;">Member since <?php echo e((string)$user['member_since']); ?></p>
                    </div>
                </div>

                <a href="profile.php">👤 My Profile</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>
<button class="sidebar-toggle-btn" id="menuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">☰</button>

<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">📚 Navigation</div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon']); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>

            <?php if (!empty($allSubjects)): ?>
                <div class="sidebar-title" style="margin-top:24px;">📖 Subjects</div>
                <?php foreach ($allSubjects as $s): ?>
                    <?php
                        $link = !empty($s['slug'])
                            ? 'subject.php?slug=' . urlencode((string)$s['slug'])
                            : 'subject.php?id=' . (int)$s['id'];
                        $isCurrent = $subject && (int)$subject['id'] === (int)$s['id'];
                    ?>
                    <a href="<?php echo e($link); ?>" class="subject-link <?php echo $isCurrent ? 'active' : ''; ?>">
                        <span><?php echo e($s['icon'] ?? '📚'); ?></span>
                        <span><?php echo e($s['name'] ?? 'Untitled'); ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
    </aside>

<main class="main-content" id="mainContent">
    <h1 class="page-title">📜 Certification Exams</h1>
    <p style="margin-bottom:20px;color:var(--text-secondary)">
        Complete the required lessons to unlock each level. You have multiple attempts per level; each attempt uses a different exam.
    </p>

    <div class="filter-row">
        <select class="filter-select" onchange="window.location.href='?subject=' + this.value">
            <option value="all" <?= $selectedSubject === 'all' ? 'selected' : '' ?>>All Tracks</option>
            <option value="general" <?= $selectedSubject === 'general' ? 'selected' : '' ?>>🌍 General (All Subjects)</option>
            <?php foreach ($allSubjects as $s): ?>
                <option value="<?= $s['id'] ?>" <?= (int)$selectedSubject === (int)$s['id'] ? 'selected' : '' ?>>
                    <?= $s['icon'] ?? '📚' ?> <?= e($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (empty($tracks)): ?>
        <div class="empty-state">No certification exams have been published yet.</div>
    <?php else: ?>
        <?php foreach ($tracks as $track): ?>
            <section class="track-section">
                <h2 class="track-title"><?= htmlspecialchars($track['title']) ?></h2>
                <div class="level-grid">
                    <?php foreach ($track['levels'] as $lvlData): 
                        $status = $lvlData['status'] ?? [];
                        $exam = $lvlData['exam'];
                        $isUnlocked = $lvlData['unlocked'];
                        $progress = $lvlData['progress'];
                        $threshold = $lvlData['threshold'];
                        $canStart = $status['can_start'] ?? false;
                        $statusText = $status['status_text'] ?? '';
                        $badgeClass = $status['badge_class'] ?? 'badge-locked';
                        $reason = $status['reason'] ?? '';
                        $cooldown = $status['cooldown_remaining'] ?? '';
                        $windowLabel = $status['window_label'] ?? '';
                        $attemptNumber = $status['attempt_number'] ?? null;
                        $maxAttempts = $status['max_attempts'] ?? 0;
                        $showButton = $canStart && $exam && $isUnlocked;
                    ?>
                        <div class="level-card">
                            <h3>Level <?= $lvlData['level'] ?></h3>
                            <p>Requires <?= $threshold ?>% progress</p>
                            <p style="font-size:12px;">Your progress: <?= $progress ?>%</p>
                            <?php if ($attemptNumber && $maxAttempts): ?>
                                <p class="attempt-info">Attempt <?= $attemptNumber ?> of <?= $maxAttempts ?></p>
                            <?php endif; ?>
                            <?php if ($exam && !empty($windowLabel)): ?>
                                <p style="font-size:11px;color:var(--muted)">🕒 <?= $windowLabel ?></p>
                            <?php endif; ?>
                            <?php if ($statusText): ?>
                                <span class="badge <?= $badgeClass ?> status-text"><?= $statusText ?></span>
                            <?php endif; ?>
                            <?php if ($reason): ?>
                                <p style="font-size:12px;color:var(--muted);margin-top:4px;"><?= htmlspecialchars($reason) ?></p>
                            <?php endif; ?>
                            <?php if ($cooldown): ?>
                                <p style="font-size:12px;color:var(--warning);margin-top:2px;">Remaining: <?= $cooldown ?></p>
                            <?php endif; ?>
                            <?php if ($showButton): ?>
                                <a href="take_certification_exam.php?exam_id=<?= $exam['id'] ?>" class="btn-sm btn-primary">
                                    <?= $statusText === '▶ Resume' ? '▶ Resume Exam' : '▶ Start Exam' ?>
                                </a>
                            <?php elseif ($exam && !$isUnlocked): ?>
                                <span class="badge badge-locked">🔒 Locked</span>
                                <?php if (!$reason): ?>
                                    <p style="font-size:12px;color:var(--muted);margin-top:4px;">
                                        <?php if ($track['type'] === 'subject' && (int)$lvlData['level'] > 1): ?>
                                            Pass Level <?= (int)$lvlData['level'] - 1 ?> first for this subject.
                                        <?php elseif ($track['type'] === 'general'): ?>
                                            Pass the required subject levels first.
                                        <?php else: ?>
                                            Complete the required lesson progress first.
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</div>

<script>
    const body = document.body;
    document.getElementById('menuToggle').addEventListener('click', ()=>{
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    });
    document.getElementById('sidebarOverlay').addEventListener('click', ()=>{
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
    });
    const themeToggle = document.getElementById('themeToggle');
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') { body.classList.add('dark-mode'); themeToggle.textContent='☀'; }
    themeToggle.addEventListener('click',()=>{
        body.classList.toggle('dark-mode');
        const isDark = body.classList.contains('dark-mode');
        themeToggle.textContent = isDark ? '☀' : '🌙';
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });
</script>
</body>
</html>