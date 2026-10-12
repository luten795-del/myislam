<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters ?: 'U';
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn instanceof PDO) {
    die('Unable to connect to database.');
}

function getCurrentUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, profile_image, role, is_active, created_at
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("getCurrentUser: " . $e->getMessage());
        return null;
    }
}

$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : 'overall';
$allowedModes = ['overall', 'lesson', 'self', 'competition', 'exam', 'overall_competition'];
if (!in_array($mode, $allowedModes)) $mode = 'overall';

$timeFilter = isset($_GET['time']) ? strtolower(trim((string)$_GET['time'])) : 'all_time';
$allowedTimeFilters = ['today', 'this_week', 'this_month', 'all_time'];
if (!in_array($timeFilter, $allowedTimeFilters)) $timeFilter = 'all_time';

// A specific quiz to show results for, instead of points combined across
// every quiz. 0 means "no specific quiz" - fall back to the normal
// aggregate leaderboard (mode/time filters apply as before).
$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;

function getDateCondition(string $timeFilter): array {
    $now = new DateTime();
    $condition = '';
    $params = [];
    switch ($timeFilter) {
        case 'today': $condition = "completed_at >= ?"; $params[] = $now->format('Y-m-d 00:00:00'); break;
        case 'this_week': $startOfWeek = (clone $now)->modify('monday this week')->setTime(0,0,0); $condition = "completed_at >= ?"; $params[] = $startOfWeek->format('Y-m-d H:i:s'); break;
        case 'this_month': $startOfMonth = (clone $now)->modify('first day of this month')->setTime(0,0,0); $condition = "completed_at >= ?"; $params[] = $startOfMonth->format('Y-m-d H:i:s'); break;
        default: $condition = "1=1";
    }
    return [$condition, $params];
}

function getLeaderboardData(PDO $conn, string $mode, string $timeFilter): array {
    [$dateCondition, $dateParams] = getDateCondition($timeFilter);
    
    $quizSelect = "
        SELECT 
            qa.user_id,
            q.mode AS source_mode,
            'quiz' AS source_type,
            SUM(qa.score) AS total_points,
            COUNT(*) AS attempts_count,
            SUM(CASE WHEN qa.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(qa.correct_answers) AS total_correct,
            SUM(qa.total_questions - qa.correct_answers) AS total_wrong,
            AVG(qa.percentage) AS avg_score,
            MAX(qa.percentage) AS best_score,
            SUM(TIMESTAMPDIFF(SECOND, qa.started_at, qa.completed_at)) AS total_time_seconds
        FROM quiz_attempts qa
        JOIN quizzes q ON q.id = qa.quiz_id
        WHERE qa.status IN ('completed')
          AND qa.completed_at IS NOT NULL
          AND $dateCondition
    ";
    
    $examSelect = "
        SELECT 
            mea.user_id,
            'exam' AS source_mode,
            'exam' AS source_type,
            SUM(mea.score) AS total_points,
            COUNT(*) AS attempts_count,
            SUM(CASE WHEN mea.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(mea.correct_answers) AS total_correct,
            SUM(mea.total_questions - mea.correct_answers) AS total_wrong,
            AVG(mea.percentage) AS avg_score,
            MAX(mea.percentage) AS best_score,
            SUM(TIMESTAMPDIFF(SECOND, mea.started_at, mea.completed_at)) AS total_time_seconds
        FROM monthly_exam_attempts mea
        WHERE mea.status IN ('completed', 'abandoned')
          AND mea.completed_at IS NOT NULL
          AND $dateCondition
    ";
    
    $quizWhere = '';
    $examWhere = '';
    $params = $dateParams;
    
    switch ($mode) {
        case 'lesson': $quizWhere = " AND q.mode = 'lesson'"; $examWhere = " AND 1=0"; break;
        case 'self': $quizWhere = " AND q.mode = 'self'"; $examWhere = " AND 1=0"; break;
        case 'competition': $quizWhere = " AND q.mode = 'competition'"; $examWhere = " AND 1=0"; break;
        case 'exam': $quizWhere = " AND 1=0"; $examWhere = ""; break;
        case 'overall_competition': $quizWhere = " AND q.mode = 'competition'"; $examWhere = ""; break;
        default: $quizWhere = ""; $examWhere = "";
    }
    
    $quizSQL = $quizSelect . $quizWhere . " GROUP BY qa.user_id, q.mode";
    $examSQL = $examSelect . $examWhere . " GROUP BY mea.user_id";
    
    $finalSQL = "
        SELECT 
            user_id,
            MAX(source_mode) AS mode,
            SUM(total_points) AS total_points,
            SUM(attempts_count) AS quizzes_taken,
            SUM(completed_count) AS quizzes_completed,
            SUM(total_correct) AS total_correct_answers,
            SUM(total_wrong) AS total_wrong_answers,
            AVG(avg_score) AS average_score,
            MAX(best_score) AS best_score,
            SUM(total_time_seconds) AS total_time_seconds,
            u.username,
            u.full_name,
            u.email,
            u.profile_image
        FROM (
            ($quizSQL)
            UNION ALL
            ($examSQL)
        ) AS combined
        LEFT JOIN users u ON u.id = combined.user_id
        WHERE u.is_active = 1
        GROUP BY user_id, u.username, u.full_name, u.email, u.profile_image
        ORDER BY total_points DESC, best_score DESC, quizzes_completed DESC, total_time_seconds ASC
    ";
    
    try {
        $stmt = $conn->prepare($finalSQL);
        $allParams = array_merge($params, $params);
        $stmt->execute($allParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $index => &$row) {
            $row['rank_position'] = $index + 1;
            $row['display_name'] = resolveDisplayName($row);
        }
        unset($row);
        return $rows;
    } catch (PDOException $e) {
        error_log("Leaderboard query error: " . $e->getMessage());
        return [];
    }
}

// Username first (this is what participants should be identified by across
// the leaderboard), falling back to full_name, then to the local part of
// their email, for the users who signed up before usernames were added.
function resolveDisplayName(array $row): string {
    if (!empty($row['username'])) {
        return (string)$row['username'];
    }
    if (!empty($row['full_name'])) {
        return (string)$row['full_name'];
    }
    $emailParts = explode('@', (string)($row['email'] ?? ''));
    return !empty($emailParts[0]) ? $emailParts[0] : 'User';
}

// ============================================================================
// PER-QUIZ LEADERBOARD - results for one specific quiz, so a user can see
// exactly how they and everyone else performed on that particular quiz,
// instead of only ever seeing points combined across every quiz.
// ============================================================================
function getQuizList(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT id, title, mode
            FROM quizzes
            WHERE status = 'published'
            ORDER BY title ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("getQuizList: " . $e->getMessage());
        return [];
    }
}

function getQuizLeaderboardData(PDO $conn, int $quizId): array {
    try {
        // One row per user: their BEST attempt on this quiz (highest
        // percentage, then highest score, then earliest completion as the
        // tiebreaker) - a user who retried a quiz should be ranked by their
        // best showing, not have every attempt counted separately.
        $stmt = $conn->prepare("
            SELECT
                qa.user_id,
                SUM(qa.score) AS total_points,
                COUNT(*) AS quizzes_taken,
                SUM(CASE WHEN qa.status = 'completed' THEN 1 ELSE 0 END) AS quizzes_completed,
                SUM(qa.correct_answers) AS total_correct_answers,
                SUM(qa.total_questions - qa.correct_answers) AS total_wrong_answers,
                MAX(qa.percentage) AS best_score,
                AVG(qa.percentage) AS average_score,
                SUM(TIMESTAMPDIFF(SECOND, qa.started_at, qa.completed_at)) AS total_time_seconds,
                u.username,
                u.full_name,
                u.email,
                u.profile_image
            FROM quiz_attempts qa
            LEFT JOIN users u ON u.id = qa.user_id
            WHERE qa.quiz_id = ?
              AND qa.status = 'completed'
              AND qa.completed_at IS NOT NULL
              AND u.is_active = 1
            GROUP BY qa.user_id, u.username, u.full_name, u.email, u.profile_image
            ORDER BY best_score DESC, total_points DESC, total_time_seconds ASC
        ");
        $stmt->execute([$quizId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $index => &$row) {
            $row['rank_position'] = $index + 1;
            $row['display_name'] = resolveDisplayName($row);
        }
        unset($row);
        return $rows;
    } catch (PDOException $e) {
        error_log("getQuizLeaderboardData: " . $e->getMessage());
        return [];
    }
}

function getUserOverallCompetitionRank(PDO $conn, int $userId): int {
    try {
        $stmt = $conn->prepare("
            SELECT user_id, SUM(score) AS total_points
            FROM (
                SELECT qa.user_id, qa.score FROM quiz_attempts qa
                JOIN quizzes q ON q.id = qa.quiz_id
                WHERE q.mode = 'competition' AND qa.status IN ('completed','abandoned')
                UNION ALL
                SELECT mea.user_id, mea.score FROM monthly_exam_attempts mea
                WHERE mea.status IN ('completed','abandoned')
            ) AS comp
            GROUP BY user_id ORDER BY total_points DESC
        ");
        $stmt->execute();
        $rank = 0; $currentRank = 0; $lastPoints = null;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $currentRank++;
            if ($lastPoints !== $row['total_points']) { $rank = $currentRank; $lastPoints = $row['total_points']; }
            if ((int)$row['user_id'] === $userId) return $rank;
        }
        return 0;
    } catch (PDOException $e) { return 0; }
}

function formatDuration(int $seconds): string {
    if ($seconds <= 0) return '0s';
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    if ($hours > 0) return "{$hours}h {$minutes}m {$secs}s";
    if ($minutes > 0) return "{$minutes}m {$secs}s";
    return "{$secs}s";
}

$user = getCurrentUser($conn, $userId);
if (!$user) { session_destroy(); header('Location: login.php?error=account_disabled'); exit; }

$quizList = getQuizList($conn);
$selectedQuiz = null;
if ($quizId > 0) {
    foreach ($quizList as $q) {
        if ((int)$q['id'] === $quizId) { $selectedQuiz = $q; break; }
    }
    // Someone edited the URL with a quiz id that doesn't exist / isn't
    // published - fall back to the normal aggregate view instead of
    // silently showing an empty page.
    if ($selectedQuiz === null) { $quizId = 0; }
}

$rows = $quizId > 0
    ? getQuizLeaderboardData($conn, $quizId)
    : getLeaderboardData($conn, $mode, $timeFilter);

$overallCompRank = getUserOverallCompetitionRank($conn, $userId);

$totalParticipants = count($rows);
$topPoints = !empty($rows) ? (float)($rows[0]['total_points'] ?? 0) : 0;
$currentUserRow = array_filter($rows, fn($r) => (int)$r['user_id'] === $userId);
$currentUserRank = !empty($currentUserRow) ? (int)(reset($currentUserRow)['rank_position'] ?? 0) : 0;
$currentUserPoints = !empty($currentUserRow) ? (float)(reset($currentUserRow)['total_points'] ?? 0) : 0;

// Top 3 get the podium treatment (only when there are enough participants
// to make a real podium); everyone else - or everyone, if there aren't at
// least 3 - shows in the table below as usual.
$podiumRows = count($rows) >= 3 ? array_slice($rows, 0, 3) : [];
$tableRows = $podiumRows ? array_slice($rows, 3) : $rows;

$modeLabels = [
    'overall' => 'Overall', 'lesson' => 'Lesson', 'self' => 'Self Practice',
    'competition' => 'Weekly Competition', 'exam' => 'Monthly Exam',
    'overall_competition' => 'All Competitions'
];
$modeLabel = $selectedQuiz ? $selectedQuiz['title'] : ($modeLabels[$mode] ?? ucfirst($mode));
$timeLabels = ['today' => 'Today', 'this_week' => 'This Week', 'this_month' => 'This Month', 'all_time' => 'All Time'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
<title>Leaderboard - MyIslam</title>
<style>
/* ===== RESET & BASE ===== */
*{box-sizing:border-box;margin:0;padding:0}

/* ===== CSS VARIABLES ===== */
:root{
    --bg:#f5f7f5;
    --surface:#ffffff;
    --surface-2:#f9fafb;
    --surface-3:#eef2f7;
    --line:#e2e5e2;
    --text:#1f2937;
    --text-secondary:#4b5563;
    --muted:#6b7280;
    --brand:#0E4A34;
    --brand-dark:#0A3626;
    --brand-light:#3E7A5E;
    --brand-soft:rgba(14, 74, 52, 0.08);
    --gold:#9C7A2E;
    --gold-soft:rgba(156, 122, 46, 0.10);
    --accent:#3E6E91;
    --accent-soft:rgba(62, 110, 145, 0.10);
    --header-bg:#ffffff;
    --header-height:72px;
    --shadow-sm:0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md:0 4px 6px -1px rgb(0 0 0 / 0.08);
    --shadow-lg:0 10px 15px -3px rgb(0 0 0 / 0.1);
    --radius-sm:12px;
    --radius-md:16px;
    --radius-lg:20px;
    --danger:#dc2626;
    --success:#0E4A34;
    --white:#ffffff;
    --rank-gold:#C6A64B;
    --rank-gold-soft:rgba(198, 166, 75, 0.14);
    --rank-diamond:#8FD3E8;
    --rank-diamond-soft:rgba(143, 211, 232, 0.16);
    --rank-tanzanite:#6C5CC0;
    --rank-tanzanite-soft:rgba(108, 92, 192, 0.14);
    --max-width:1600px;
    --content-width:95%;
    --text-base:16px;
    --text-lg:18px;
    --text-xl:22px;
    --text-2xl:28px;
    --text-sm:14px;
    --text-xs:12px;
}

/* ===== DARK MODE ===== */
body.dark-mode{
    --bg:#0f1720;
    --surface:#18212b;
    --surface-2:#1d2833;
    --surface-3:#22303c;
    --line:#304150;
    --text:#edf2f7;
    --text-secondary:#cbd5e1;
    --muted:#94a3b8;
    --brand:#4C9172;
    --brand-dark:#6bb590;
    --brand-light:#7fcaa4;
    --brand-soft:rgba(76, 145, 114, 0.16);
    --gold:#C9A85E;
    --gold-soft:rgba(201, 168, 94, 0.16);
    --accent:#6FA0C4;
    --accent-soft:rgba(111, 160, 196, 0.16);
    --header-bg:#13202a;
    --danger:#ef4444;
    --rank-gold:#D9BC72;
    --rank-gold-soft:rgba(217, 188, 114, 0.18);
    --rank-diamond:#A9E4F2;
    --rank-diamond-soft:rgba(169, 228, 242, 0.18);
    --rank-tanzanite:#9285DB;
    --rank-tanzanite-soft:rgba(146, 133, 219, 0.18);
}

/* ===== BODY ===== */
body {
    font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.65;
    font-size: var(--text-base);
    -webkit-text-size-adjust: 100%;
}

body.dark-mode {
    background: var(--bg);
}

body::before {
    content: "";
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    pointer-events: none; z-index: -1;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.025' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
    background-repeat: repeat;
}
body.dark-mode::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
}

a{text-decoration:none;color:inherit} button,input{font:inherit}

/* ===== HEADER ===== */
.app-header {
    position: sticky; top: 0; z-index: 1000;
    height: var(--header-height);
    background: var(--header-bg);
    border-bottom: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 24px; backdrop-filter: blur(10px); gap: 18px;
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
.menu-toggle{width:50px;}
.menu-toggle:hover, .icon-btn:hover, .lang-btn:hover, .profile-btn:hover {
    transform: translateY(-2px); box-shadow: var(--shadow-sm);
}
.menu-toggle { font-size: 14px; }
.icon-btn{font-size:18px; color:var(--text); position:relative;}
.lang-btn { min-width:30px; padding:0 8px; font-weight:600; color:var(--brand);}
.brand-box { font-size:22px; font-weight:700; color:var(--brand); background:transparent; border:none; white-space:nowrap; }

/* ===== PROFILE ===== */
.profile-area { position: relative; }
.profile-btn {
    width:44px; height:44px; border-radius:50%; overflow:hidden;
    background:var(--surface); border:2px solid var(--brand);
    cursor:pointer; padding:0; display:flex; align-items:center; justify-content:center;
    color:var(--brand); font-weight:700;
}
.profile-btn img { width:100%; height:100%; object-fit:cover; }
.profile-menu {
    position:absolute; top:56px; right:0; width:280px;
    background:var(--glass-bg-strong); backdrop-filter:blur(12px);
    border:1px solid var(--line); border-radius:var(--radius-lg);
    padding:16px; display:none; box-shadow:var(--shadow-lg); z-index:1001;
}
.profile-menu.show { display:block; }
.profile-menu-top {
    display:flex; gap:12px; padding-bottom:10px; margin-bottom:10px;
    border-bottom:1px solid var(--line);
}
.profile-menu-top img, .profile-avatar-fallback {
    width:56px; height:56px; border-radius:50%;
}
.profile-avatar-fallback {
    display:inline-flex; align-items:center; justify-content:center;
    background:var(--brand-soft); color:var(--brand); font-weight:700; font-size:16px;
}
.profile-menu-top h4 { font-size:16px; margin-bottom:4px; font-weight:bold; color:var(--text); }
.profile-menu-top p { font-size:12px; color:var(--text-secondary); font-weight:600; }
.profile-menu a {
    display:block; padding:10px 12px; border-radius:10px; font-weight:700;
    font-size:14px; transition:background 0.2s; margin:4px 0; color:var(--text);
}
.profile-menu a:hover { background:var(--brand-soft); color:var(--brand); }
.notification-badge {
    position:absolute; top:-4px; right:-4px;
    background:var(--danger); color:white;
    font-size:10px; font-weight:700; min-width:18px; height:18px;
    border-radius:999px; display:flex; align-items:center; justify-content:center;
    padding:0 4px; border:2px solid var(--surface);
}

/* ===== SIDEBAR ===== */
.sidebar-overlay {
    display:none; position:fixed; top:var(--header-height); left:0;
    width:100%; height:calc(100vh - var(--header-height));
    background:var(--overlay-dim); z-index:1100; backdrop-filter:blur(2px);
}
.sidebar-overlay.show { display:block; }
.sidebar {
    position:fixed; top:var(--header-height); left:-100%;
    width:min(320px,85vw); height:calc(100vh - var(--header-height));
    background:var(--glass-bg-strong); backdrop-filter:blur(12px);
    border-right:1px solid var(--line); padding:24px 20px;
    z-index:1200; transition:left 0.3s ease; overflow-y:scroll;
    overscroll-behavior:contain; -webkit-overflow-scrolling:touch; scrollbar-gutter:stable;
}
.sidebar.open { left:0; }
.sidebar-title {
    font-size:12px; color:var(--muted); text-transform:uppercase;
    margin-bottom:20px; font-weight:700; letter-spacing:1px; padding:0 8px;
}
.subject-list { display:grid; gap:8px; }
.subject-link {
    display:flex; align-items:center; gap:12px; padding:12px 16px;
    border-radius:var(--radius-md); background:var(--surface-2);
    border:1px solid var(--line); font-size:14px; font-weight:500; transition:all 0.2s;
    color:var(--text);
}
.subject-link:hover { background:var(--brand-soft); border-color:var(--brand); transform:translateX(4px); }
.subject-link.active {
    background:var(--brand); color:var(--white); border-color:var(--brand-dark);
    box-shadow:inset 4px 0 0 var(--gold), 0 4px 12px rgba(14,74,52,.18);
    font-weight:800;
}
.sidebar-toggle-btn {
    position:fixed; top:var(--header-height); left:0; z-index:1300;
    display:inline-flex; align-items:center; justify-content:center;
    width:36px; height:36px; background:var(--surface);
    border:1px solid var(--line); border-left:none; border-top:none;
    border-radius:0 0 8px 0; font-size:16px; cursor:pointer;
    color:var(--text); transition:all 0.2s; box-shadow:var(--shadow-sm);
    padding:0; margin:0;
}
.sidebar-toggle-btn:hover { background:var(--brand-soft); color:var(--brand); }

/* ===== MAIN CONTENT - WIDER ===== */
.main-content {
    max-width: var(--max-width);
    width: var(--content-width);
    margin: 0 auto;
    padding: 24px 16px 20px;
}
.page-stack{display:grid;gap:20px;}

/* ===== SECTION CARDS ===== */
.section-card{
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
}
.section-card:hover{
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* ===== HERO CARD ===== */
.hero-card{
    background: linear-gradient(135deg, var(--brand-soft) 0%, var(--surface) 100%);
    border: 1px solid var(--line);
    padding: 20px 20px;
}
.hero-card h1{
    font-size: var(--text-2xl);
    margin-bottom: 6px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.hero-card p{color:var(--text-secondary); font-size:var(--text-base);}
.comp-rank-badge{
    background:var(--brand); color:white;
    padding:4px 14px; border-radius:999px;
    font-size:13px; font-weight:700;
}

/* ===== FILTER BAR (dropdowns, matches dashboard .filter-select pattern) ===== */
.filter-bar{
    display:flex;
    gap:16px;
    flex-wrap:wrap;
    align-items:center;
}
.filter-select-wrap{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.filter-select-label{
    font-size:12px;
    font-weight:700;
    color:var(--text-secondary);
    white-space:nowrap;
}
.filter-select{
    padding:9px 12px;
    border-radius:9px;
    border:1px solid var(--line);
    background:var(--surface);
    color:var(--text);
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    min-width:170px;
}
.filter-select:hover{border-color:var(--brand);}
.filter-select:focus-visible{outline:2px solid var(--brand); outline-offset:2px;}

/* ===== STATS ===== */
.stats-wrapper{
    padding:0;
    margin:0;
    background:transparent;
    border:none;
}
.stats-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
}
.stat-card{
    text-align:center;
    padding:16px 12px;
    background:var(--surface);
    border:1px solid var(--line);
    border-radius:var(--radius-md);
    transition:all 0.2s;
}
.stat-card:hover{
    transform:translateY(-2px);
    box-shadow:var(--shadow-sm);
}
.stat-icon{font-size:24px;margin-bottom:4px;}
.stat-value{font-size:24px;font-weight:800;color:var(--gold);margin-bottom:2px;}
.stat-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.3px;font-weight:600;}

/* ===== PODIUM (matches quizzes.php's top-3 podium pattern) ===== */
.period-label{
    display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase;
    letter-spacing:0.5px; color:var(--muted); background:var(--surface-3);
    border-radius:999px; padding:3px 10px; margin-left:6px; vertical-align:middle;
}
.podium-grid{
    display:grid; grid-template-columns:repeat(3,minmax(0,1fr));
    align-items:start; gap:10px; margin-top:8px;
}
.podium-card{
    min-width:0; text-align:center; padding:20px 14px 16px;
    border-radius:var(--radius-lg); border:1px solid var(--line);
    position:relative; transition:transform 0.2s; margin-top:40px;
}
.podium-card:hover{transform:translateY(-4px);}
.podium-first{order:2; margin-top:0;}
.podium-second{order:3; margin-top:40px;}
.podium-third{order:1; margin-top:40px;}
.podium-first{
    background:linear-gradient(160deg, var(--rank-gold-soft) 0%, var(--surface) 100%);
    border-color:var(--rank-gold); padding-top:26px; padding-bottom:22px; box-shadow:var(--shadow-lg);
}
.podium-first:hover{transform:translateY(-8px);}
.podium-second{
    background:linear-gradient(160deg, var(--rank-diamond-soft) 0%, var(--surface) 100%);
    border-color:var(--rank-diamond);
}
.podium-third{
    background:linear-gradient(160deg, var(--rank-tanzanite-soft) 0%, var(--surface) 100%);
    border-color:var(--rank-tanzanite);
}
.podium-avatar{
    width:64px; height:64px; border-radius:50%; margin:0 auto 10px;
    display:flex; align-items:center; justify-content:center;
    font-weight:800; font-size:18px; color:var(--white);
    background:linear-gradient(135deg, var(--brand-light) 0%, var(--brand) 100%);
    overflow:hidden; border:3px solid var(--surface); box-shadow:var(--shadow-sm);
}
.podium-first .podium-avatar{width:76px; height:76px; border-color:var(--rank-gold);}
.podium-second .podium-avatar{border-color:var(--rank-diamond);}
.podium-third .podium-avatar{border-color:var(--rank-tanzanite);}
.podium-avatar img{width:100%; height:100%; object-fit:cover;}
.podium-badge{
    display:inline-flex; align-items:center; gap:4px;
    font-size:11px; font-weight:800; letter-spacing:0.5px; text-transform:uppercase;
    padding:4px 12px; border-radius:999px; margin-bottom:10px; color:var(--white); white-space:nowrap;
}
.podium-first .podium-badge{background:var(--rank-gold);}
.podium-second .podium-badge{background:var(--rank-diamond); color:#0f1720;}
.podium-third .podium-badge{background:var(--rank-tanzanite);}
.podium-name{
    font-size:14px; font-weight:700; color:var(--text);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.podium-points{font-size:13px; color:var(--muted); margin-top:2px;}
.podium-you{box-shadow:0 0 0 2px var(--brand), var(--shadow-lg);}
@media (max-width:560px){
    .podium-grid{gap:6px;}
    .podium-card{padding:12px 6px 10px; margin-top:26px;}
    .podium-first{padding-top:16px; padding-bottom:14px; margin-top:0;}
    .podium-second,.podium-third{margin-top:26px;}
    .podium-avatar{width:44px; height:44px; font-size:13px; margin-bottom:6px;}
    .podium-first .podium-avatar{width:54px; height:54px;}
    .podium-badge{font-size:8px; padding:3px 6px; margin-bottom:6px;}
    .podium-name{font-size:11px;}
    .podium-points{font-size:10px;}
}
@media (max-width:380px){ .podium-badge span.badge-label{display:none;} }

/* ===== SEARCH ===== */
.search-wrap{position:relative;max-width:400px;}
.search-input{
    width:100%; height:46px; border-radius:var(--radius-md);
    border:1px solid var(--line); background:var(--surface-2);
    color:var(--text); padding:0 16px 0 44px; outline:none;
    transition:all .2s; font-size:14px;
}
.search-input:focus{border-color:var(--brand); background:var(--surface); box-shadow:0 0 0 3px rgba(14,74,52,.08);}
.search-icon{position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:16px;}

/* ===== LEADERBOARD TABLE (matches dashboard .leaderboard-table pattern) ===== */
.leaderboard-table-wrap{
    overflow-x:auto;
    padding:0;
    border:1px solid var(--line);
    border-radius:var(--radius-lg);
    -webkit-overflow-scrolling:touch;
}
.leaderboard-table{
    width:100%;
    border-collapse:collapse;
    background:var(--surface);
    min-width:900px;
}
.leaderboard-table thead th{
    background:var(--gold-soft);
    color:var(--gold);
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:0.5px;
    text-align:left;
    padding:12px 14px;
    white-space:nowrap;
    font-weight:800;
    border-bottom:2px solid var(--gold);
    position:sticky;
    top:0;
}
.leaderboard-row td{
    background:var(--surface);
    padding:12px 14px;
    border-bottom:1px dashed var(--line);
    white-space:nowrap;
    transition:background 0.2s;
    font-size:14px;
    color:var(--text);
}
.leaderboard-table tbody tr:last-child td{border-bottom:0;}
.leaderboard-table tbody tr:nth-child(even) td{background:var(--surface-2);}
.leaderboard-row:hover td{background:var(--surface-3);}
.leaderboard-row.current-user td{background:var(--brand-soft); box-shadow:inset 2px 0 0 var(--brand);}

.rank-badge{
    display:inline-flex; align-items:center; justify-content:center;
    min-width:36px; height:36px; border-radius:999px;
    background:var(--surface); border:1px solid var(--line);
    font-weight:800; padding:0 10px; font-size:14px;
    color:var(--text);
}
.user-cell{display:flex; align-items:center; gap:10px;}
.user-avatar, .user-avatar-fallback{
    width:36px; height:36px; border-radius:50%; flex-shrink:0;
}
.user-avatar{object-fit:cover;}
.user-avatar-fallback{
    display:inline-flex; align-items:center; justify-content:center;
    background:var(--brand-soft); color:var(--brand); font-weight:700; font-size:14px;
}
.user-name{font-weight:600;}

/* ===== EMPTY STATE ===== */
.empty-state{text-align:center;padding:50px 20px;color:var(--muted);}
.empty-state .emoji{font-size:50px;margin-bottom:12px;}
.search-empty-state{text-align:center;padding:30px 20px;color:var(--muted);}

/* ===== RESPONSIVE ===== */
@media (max-width:1400px){
    .main-content{width:98%;padding:20px 12px;}
}
@media (max-width:1200px){
    .header-top-nav{max-width:48vw;}
}
@media (max-width:992px){
    .stats-grid{grid-template-columns:repeat(2,1fr);}
}
@media (max-width:900px){
    .header-top-nav{display:none;}
    .main-content{width:100%;padding:16px 10px;}
}
@media (max-width:768px){
    :root{
        --header-height:64px;
        --text-base:15px;
        --text-lg:16px;
        --text-xl:18px;
        --text-2xl:22px;
        --text-sm:13px;
        --text-xs:11px;
    }
    .main-content{padding:12px 8px 20px;width:100%;}
    .section-card{padding:14px 16px;}
    .hero-card{padding:16px 18px;}
    .hero-card h1{font-size:var(--text-2xl);}
    .filter-bar{flex-direction:column;align-items:stretch;gap:10px;}
    .filter-select-wrap{width:100%;}
    .filter-select{width:100%;}
    .stats-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
    .stat-value{font-size:20px;}
    .leaderboard-table{min-width:700px;}
    .rank-badge{min-width:32px;height:32px;font-size:12px;}
    .user-avatar,.user-avatar-fallback{width:32px;height:32px;}
    .leaderboard-row td{padding:10px 8px;font-size:13px;}
}
@media (max-width:480px){
    :root{
        --text-base:14px;
        --text-lg:15px;
        --text-xl:17px;
        --text-2xl:20px;
        --text-sm:12px;
        --text-xs:10px;
    }
    .filter-select{font-size:12px; padding:8px 10px;}
    .stat-value{font-size:18px;}
    .stat-icon{font-size:20px;}
    .leaderboard-table{min-width:600px;}
    .rank-badge{min-width:28px;height:28px;font-size:11px;padding:0 6px;}
    .user-avatar,.user-avatar-fallback{width:28px;height:28px;font-size:12px;}
}
@media print{
    .header,.sidebar-toggle-btn,.sidebar-overlay,.sidebar{display:none !important;}
    .section-card{box-shadow:none !important;transform:none !important;}
}
.filter-toggle,
.stats-toggle {
    width: 100%;
    min-height: 50px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 0 16px;

    background: var(--surface);
    color: var(--brand);

    border: 1px solid var(--line);
    border-radius: 10px;

    cursor: pointer;
    font-size: 16px;
    font-weight: 700;
}

.filter-content,
#leaderboardStatsGrid {
    display: none;
    margin-top: 16px;
}

.filter-content.show {
    display: block;
}

#leaderboardStatsGrid.show {
    display: grid;
}
@media(min-width:768px){
    .type{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}}
</style>
</head>
<body>

<!-- ===== HEADER ===== -->
<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="<?php echo e(t('open_navigation')); ?>" title="<?php echo e(t('open_navigation')); ?>" aria-expanded="false" aria-controls="sidebar">☰</button>
        <div class="brand-box">🏆 MyIslam</div>
        <nav class="header-top-nav">
            <a href="user_dashboard.php" class="header-top-link"><?php echo e(t('dashboard')); ?></a>
            <a href="subjects.php" class="header-top-link"><?php echo e(t('subjects')); ?></a>
            <a href="quizzes.php" class="header-top-link"><?php echo e(t('quizzes')); ?></a>
            <a href="monthly_exams.php" class="header-top-link"><?php echo e(t('monthly_exams')); ?></a>
            <a href="certification_exams.php" class="header-top-link"><?php echo e(t('certification')); ?></a>
            <a href="my_certificates.php" class="header-top-link"><?php echo e(t('my_certificates')); ?></a>
            <a href="leaderboard.php" class="header-top-link active"><?php echo e(t('leaderboard')); ?></a>
            <a href="multimedia.php" class="header-top-link"><?php echo e(t('multimedia')); ?></a>
            <a href="notification.php" class="header-top-link"><?php echo e(t('notifications')); ?></a>
            <a href="help_center.php" class="header-top-link"><?php echo e(t('help_center')); ?></a>
            <a href="logout.php" class="header-top-link"><?php echo e(t('logout')); ?></a>
        </nav>
    </div>
    <div class="header-right">
        <button type="button" class="icon-btn" id="themeToggle" aria-label="<?php echo e(t('dark_mode')); ?>" title="<?php echo e(t('dark_mode')); ?>">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="<?php echo e(t('notifications_label')); ?>" title="<?php echo e(t('notifications_label')); ?>">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="<?php echo e(t('open_profile')); ?>" aria-expanded="false">
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="<?php echo e($user['profile_image']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials((string)$user['full_name'])); ?>
                <?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($user['profile_image'])): ?>
                        <img src="<?php echo e($user['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)$user['full_name'])); ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?php echo e($user['full_name'] ?? 'User'); ?></h4>
                        <p><?php echo e($user['email'] ?? ''); ?></p>
                        <p style="font-size:10px;"><?php echo e(t('member_since')); ?> <?php echo !empty($user['created_at']) ? e(date('F Y', strtotime($user['created_at']))) : 'recently'; ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 <?php echo e(t('profile')); ?></a>
                <a href="settings.php">⚙️ <?php echo e(t('settings')); ?></a>
                <a href="logout.php">🚪 <?php echo e(t('logout')); ?></a>
            </div>
        </div>
    </div>
</header>

<!-- ===== SIDEBAR ===== -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-title">📚 <?php echo e(t('navigation')); ?></div>
    <nav class="subject-list">
        <a href="user_dashboard.php" class="subject-link"><span>📊</span><span><?php echo e(t('dashboard')); ?></span></a>
        <a href="subjects.php" class="subject-link"><span>📚</span><span><?php echo e(t('subjects')); ?></span></a>
        <a href="quizzes.php" class="subject-link"><span>📝</span><span><?php echo e(t('quizzes')); ?></span></a>
        <a href="monthly_exams.php" class="subject-link"><span>📅</span><span><?php echo e(t('monthly_exams')); ?></span></a>
        <a href="certification_exams.php" class="subject-link"><span>🎓</span><span><?php echo e(t('certification')); ?></span></a>
        <a href="my_certificates.php" class="subject-link"><span>🎓</span><span><?php echo e(t('my_certificates')); ?></span></a>
        <a href="leaderboard.php" class="subject-link active"><span>🏆</span><span><?php echo e(t('leaderboard')); ?></span></a>
        <a href="multimedia.php" class="subject-link"><span>🖼️</span><span><?php echo e(t('multimedia')); ?></span></a>
        <a href="notification.php" class="subject-link"><span>🔔</span><span><?php echo e(t('notifications')); ?></span></a>
        <a href="help_center.php" class="subject-link"><span>❓</span><span><?php echo e(t('help_center')); ?></span></a>
        <a href="logout.php" class="subject-link"><span>🚪</span><span><?php echo e(t('logout')); ?></span></a>
    </nav>
</aside>

<!-- ===== MAIN CONTENT ===== -->
<main class="main-content">
    <div class="page-stack">
        <!-- Hero -->
        <section class="hero-card section-card">
            <h1>
              MATOKEO 
                <?php if ($overallCompRank > 0): ?>
                    <span class="comp-rank-badge"><?php echo e(t('overall_competition_rank')); ?> #<?php echo $overallCompRank; ?></span>
                <?php endif; ?>
            </h1>
        </section>

        <!-- Filters -->
      <!-- Filters - Open / Close -->
       <div class="type">
<section class="section-card">

    <button type="button" class="filter-toggle" id="leaderboardFilterToggle">
        <span>🔎 Filters</span>
        <span id="leaderboardFilterIcon">+</span>
    </button>

    <div class="filter-content" id="leaderboardFilterContent">

        <div class="filter-bar">

            <div class="filter-select-wrap">
                <label for="quizFilterSelect" class="filter-select-label">📝 Quiz</label>

                <select id="quizFilterSelect" class="filter-select">
                    <option value="0" <?php echo $quizId === 0 ? 'selected' : ''; ?>>
                        All Quizzes (combined)
                    </option>

                    <?php foreach ($quizList as $q): ?>
                        <option
                            value="<?php echo (int)$q['id']; ?>"
                            <?php echo $quizId === (int)$q['id'] ? 'selected' : ''; ?>
                        >
                            <?php echo e($q['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($quizId === 0): ?>

                <div class="filter-select-wrap">
                    <label for="modeFilterSelect" class="filter-select-label">🏆 Mode</label>

                    <select
                        id="modeFilterSelect"
                        class="filter-select"
                        data-base-time="<?php echo e($timeFilter); ?>"
                    >
                        <option value="overall" <?php echo $mode === 'overall' ? 'selected' : ''; ?>>
                            Overall
                        </option>
                        <option value="lesson" <?php echo $mode === 'lesson' ? 'selected' : ''; ?>>
                            Lesson
                        </option>
                        <option value="self" <?php echo $mode === 'self' ? 'selected' : ''; ?>>
                            Self
                        </option>
                        <option value="competition" <?php echo $mode === 'competition' ? 'selected' : ''; ?>>
                            <?php echo e(t('weekly_competition')); ?>
                        </option>
                        <option value="exam" <?php echo $mode === 'exam' ? 'selected' : ''; ?>>
                            <?php echo e(t('monthly_exam')); ?>
                        </option>
                        <option value="overall_competition" <?php echo $mode === 'overall_competition' ? 'selected' : ''; ?>>
                            <?php echo e(t('all_competitions')); ?>
                        </option>
                    </select>
                </div>

                <div class="filter-select-wrap">
                    <label for="timeFilterSelect" class="filter-select-label">📅 Period</label>

                    <select
                        id="timeFilterSelect"
                        class="filter-select"
                        data-base-mode="<?php echo e($mode); ?>"
                    >
                        <option value="today" <?php echo $timeFilter === 'today' ? 'selected' : ''; ?>>
                            Today
                        </option>
                        <option value="this_week" <?php echo $timeFilter === 'this_week' ? 'selected' : ''; ?>>
                            This Week
                        </option>
                        <option value="this_month" <?php echo $timeFilter === 'this_month' ? 'selected' : ''; ?>>
                            This Month
                        </option>
                        <option value="all_time" <?php echo $timeFilter === 'all_time' ? 'selected' : ''; ?>>
                            All Time
                        </option>
                    </select>
                </div>

            <?php endif; ?>

        </div>

    </div>

</section>

        <!-- Stats -->
       <!-- Stats - Open / Close -->
<div class="stats-wrapper section-card">

    <button type="button" class="stats-toggle" id="leaderboardStatsToggle">
        <span>📊 Statistics</span>
        <span id="leaderboardStatsIcon">+</span>
    </button>

    <div class="stats-grid" id="leaderboardStatsGrid">

        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-value"><?php echo $totalParticipants; ?></div>
            <div class="stat-label">
                <?php echo e(t('participants')); ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">🥇</div>
            <div class="stat-value">
                <?php echo number_format($topPoints, 0); ?>
            </div>
            <div class="stat-label">
                <?php echo $quizId > 0 ? 'Top Score' : e(t('top_points')); ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📍</div>
            <div class="stat-value">
                <?php echo $currentUserRank > 0 ? '#' . $currentUserRank : '--'; ?>
            </div>
            <div class="stat-label">
                <?php echo e(t('your_rank')); ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">⭐</div>
            <div class="stat-value">
                <?php echo number_format($currentUserPoints, 0); ?>
            </div>
            <div class="stat-label">
                <?php echo $quizId > 0 ? 'Your Score' : e(t('your_points')); ?>
            </div>
        </div>

    </div>

</div>

     <!-- Search -->
        <section class="section-card">
            <div class="search-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="leaderboardSearchInput" class="search-input" placeholder="<?php echo e(t('search_leaderboard')); ?>">
            </div>
        </section>
           

        <!-- Top Ranked Participants Podium -->
        <?php if (!empty($podiumRows)): ?>
        <section class="section-card">
            <h3 style="margin-bottom:6px;">🏆 Top Ranked <span class="period-label"><?php echo e($modeLabel); ?><?php echo $quizId === 0 ? ' · ' . e($timeLabels[$timeFilter]) : ''; ?></span></h3>
            <div class="podium-grid">
                <?php foreach ($podiumRows as $participant):
                    $rank = (int)($participant['rank_position'] ?? 0);
                    $tierClass = $rank === 1 ? 'podium-first' : ($rank === 2 ? 'podium-second' : 'podium-third');
                    $tierLabel = $rank === 1 ? '🥇 Gold' : ($rank === 2 ? '💎 Diamond' : '🔷 Tanzanite');
                    $isCurrentUser = (int)($participant['user_id'] ?? 0) === $userId;
                    $pName = $isCurrentUser ? 'You' : ($participant['display_name'] ?? 'User');
                    $pPicture = $participant['profile_image'] ?? '';
                    $pPoints = (float)($participant['total_points'] ?? 0);
                ?>
                    <div class="podium-card <?php echo $tierClass; ?><?php echo $isCurrentUser ? ' podium-you' : ''; ?>">
                        <div class="podium-badge">#<?php echo $rank; ?> <span class="badge-label">· <?php echo e($tierLabel); ?></span></div>
                        <div class="podium-avatar">
                            <?php if (!empty($pPicture)): ?>
                                <img src="<?php echo e($pPicture); ?>" alt="<?php echo e($pName); ?>">
                            <?php else: ?>
                                <?php echo e(initials($pName)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="podium-name"><?php echo e($pName); ?></div>
                        <div class="podium-points"><?php echo $quizId > 0 ? number_format((float)($participant['best_score'] ?? 0), 1) . '%' : number_format($pPoints, 0) . ' pts'; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

   
         </div>

        <!-- Table: whoever isn't already shown in the podium above -->
        <?php if (!empty($rows)): ?>
          <?php if (!empty($tableRows)): ?>
            <?php if (!empty($podiumRows)): ?>
                <h3 style="margin:4px 2px;color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:0.5px;">Remaining Participants</h3>
            <?php endif; ?>
            <section class="leaderboard-table-wrap section-card" id="leaderboardTableWrap">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>User</th>
                            <th><?php echo e(t('points')); ?></th>
                            <th><?php echo e(t('best_score')); ?></th>
                            <th>Attempts</th>
                            <th>Completed</th>
                            <th>Correct</th>
                            <th>Wrong</th>
                            <th><?php echo e(t('avg_score')); ?></th>
                            <th>Total Time</th>
                        </tr>
                    </thead>
                    <tbody id="leaderboardBody">
                        <?php foreach ($tableRows as $row): ?>
                            <?php
                            $isCurrentUser = (int)$row['user_id'] === $userId;
                            $searchBlob = strtolower(($row['rank_position'] ?? '') . ' ' . ($row['display_name'] ?? '') . ' ' . ($row['total_points'] ?? ''));
                            ?>
                            <tr class="leaderboard-row leaderboard-search-item <?php echo $isCurrentUser ? 'current-user' : ''; ?>" data-search="<?php echo e($searchBlob); ?>">
                                <td><span class="rank-badge"><?php echo (int)$row['rank_position']; ?></span></td>
                                <td>
                                    <div class="user-cell">
                                        <?php if (!empty($row['profile_image'])): ?>
                                            <img src="<?php echo e($row['profile_image']); ?>" alt="Avatar" class="user-avatar">
                                        <?php else: ?>
                                            <div class="user-avatar-fallback"><?php echo e(initials((string)($row['display_name'] ?? 'U'))); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <span class="user-name"><?php echo e($row['display_name'] ?? 'Unknown User'); ?></span>
                                            <?php if ($isCurrentUser): ?>
                                                <span style="font-size:10px;color:var(--brand);font-weight:800;margin-left:4px;">(You)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><strong><?php echo number_format((float)($row['total_points'] ?? 0), 0); ?></strong></td>
                                <td><?php echo number_format((float)($row['best_score'] ?? 0), 1); ?>%</td>
                                <td><?php echo (int)($row['quizzes_taken'] ?? 0); ?></td>
                                <td><?php echo (int)($row['quizzes_completed'] ?? 0); ?></td>
                                <td><?php echo (int)($row['total_correct_answers'] ?? 0); ?></td>
                                <td><?php echo (int)($row['total_wrong_answers'] ?? 0); ?></td>
                                <td><?php echo number_format((float)($row['average_score'] ?? 0), 1); ?>%</td>
                                <td><?php echo e(formatDuration((int)($row['total_time_seconds'] ?? 0))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <section class="search-empty-state section-card" id="leaderboardEmptyState" style="display:none;">
                <div class="emoji">🔎</div>
                <h3 style="margin-bottom:8px;"><?php echo e(t('no_matching_participant')); ?></h3>
                <p>Try another search term.</p>
            </section>
          <?php endif; // !empty($tableRows) - if empty here, everyone already fit on the podium above, nothing more to show ?>
        <?php else: ?>
            <section class="section-card empty-state">
                <div class="emoji">🏆</div>
                <h3 style="margin-bottom:8px;"><?php echo e(t('no_leaderboard_data')); ?></h3>
                <p>
                    <?php if ($quizId > 0): ?>
                        No one has completed this quiz yet.
                    <?php else: ?>
                        <?php echo e(t('no_leaderboard_records')); ?> <?php echo e($modeLabel); ?> mode (<?php echo e($timeLabels[$timeFilter]); ?>).
                    <?php endif; ?>
                </p>
            </section>
        <?php endif; ?>
    </div>
</main>

<!-- ===== SCRIPTS ===== -->
<script>
(function() {
    const body = document.body;
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const themeToggle = document.getElementById('themeToggle');
    const langToggle = document.getElementById('langToggle');
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    const profileArea = document.getElementById('profileArea');
    const leaderboardSearchInput = document.getElementById('leaderboardSearchInput');
    const leaderboardItems = document.querySelectorAll('.leaderboard-search-item');
    const leaderboardEmptyState = document.getElementById('leaderboardEmptyState');
    const leaderboardTableWrap = document.getElementById('leaderboardTableWrap');

    function openSidebar() { if (sidebar) sidebar.classList.add('open'); if (sidebarOverlay) sidebarOverlay.classList.add('show'); body.style.overflow = 'hidden'; }
    function closeSidebar() { if (sidebar) sidebar.classList.remove('open'); if (sidebarOverlay) sidebarOverlay.classList.remove('show'); body.style.overflow = ''; }

    if (menuToggle) menuToggle.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (sidebar && sidebar.classList.contains('open')) closeSidebar();
            if (profileMenu && profileMenu.classList.contains('show')) { profileMenu.classList.remove('show'); profileToggle?.setAttribute('aria-expanded', 'false'); }
        }
    });

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') { body.classList.add('dark-mode'); if (themeToggle) themeToggle.textContent = '☀'; }
    if (themeToggle) {
        themeToggle.addEventListener('click', () => { body.classList.toggle('dark-mode'); const isDark = body.classList.contains('dark-mode'); themeToggle.textContent = isDark ? '☀' : '🌙'; localStorage.setItem('theme', isDark ? 'dark' : 'light'); });
    }

    let currentLang = localStorage.getItem('language') || 'EN';
    if (langToggle) {
        langToggle.textContent = currentLang;
        langToggle.addEventListener('click', () => { currentLang = currentLang === 'EN' ? 'SW' : 'EN'; langToggle.textContent = currentLang; localStorage.setItem('language', currentLang); });
    }

    if (profileToggle && profileMenu && profileArea) {
        profileToggle.addEventListener('click', function(e) { e.stopPropagation(); const isOpen = profileMenu.classList.contains('show'); profileMenu.classList.toggle('show', !isOpen); profileToggle.setAttribute('aria-expanded', String(!isOpen)); });
        document.addEventListener('click', function(e) { if (!profileArea.contains(e.target)) { profileMenu.classList.remove('show'); profileToggle.setAttribute('aria-expanded', 'false'); } });
    }

    function applyLeaderboardSearch() {
        const query = (leaderboardSearchInput?.value || '').trim().toLowerCase();
        let visible = 0;
        leaderboardItems.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            const show = query === '' || haystack.includes(query);
            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (leaderboardTableWrap) leaderboardTableWrap.style.display = visible === 0 ? 'none' : 'block';
        if (leaderboardEmptyState) leaderboardEmptyState.style.display = visible === 0 ? 'block' : 'none';
    }

    if (leaderboardSearchInput) leaderboardSearchInput.addEventListener('input', applyLeaderboardSearch);

    // Mode / period dropdown filters navigate on change (mirrors dashboard filter-select)
    const modeFilterSelect = document.getElementById('modeFilterSelect');
    const timeFilterSelect = document.getElementById('timeFilterSelect');

    if (modeFilterSelect) {
        modeFilterSelect.addEventListener('change', () => {
            const time = modeFilterSelect.getAttribute('data-base-time') || 'this_month';
            window.location.href = '?mode=' + encodeURIComponent(modeFilterSelect.value) + '&time=' + encodeURIComponent(time);
        });
    }

    if (timeFilterSelect) {
        timeFilterSelect.addEventListener('change', () => {
            const mode = timeFilterSelect.getAttribute('data-base-mode') || 'overall';
            window.location.href = '?mode=' + encodeURIComponent(mode) + '&time=' + encodeURIComponent(timeFilterSelect.value);
        });
    }

    // Quiz dropdown: "All Quizzes" (0) goes back to the normal combined
    // leaderboard; picking a specific quiz shows just that quiz's results.
    const quizFilterSelect = document.getElementById('quizFilterSelect');
    if (quizFilterSelect) {
        quizFilterSelect.addEventListener('change', () => {
            const quizId = quizFilterSelect.value;
            window.location.href = quizId === '0' ? window.location.pathname : '?quiz_id=' + encodeURIComponent(quizId);
        });
    }

    document.querySelectorAll('img').forEach(img => img.addEventListener('error', function(){ this.style.display = 'none'; }));
})();
const leaderboardFilterToggle = document.getElementById('leaderboardFilterToggle');
const leaderboardFilterContent = document.getElementById('leaderboardFilterContent');
const leaderboardFilterIcon = document.getElementById('leaderboardFilterIcon');

leaderboardFilterToggle.addEventListener('click', function () {

    leaderboardFilterContent.classList.toggle('show');

    if (leaderboardFilterContent.classList.contains('show')) {
        leaderboardFilterIcon.textContent = '−';
    } else {
        leaderboardFilterIcon.textContent = '+';
    }

});


const leaderboardStatsToggle = document.getElementById('leaderboardStatsToggle');
const leaderboardStatsGrid = document.getElementById('leaderboardStatsGrid');
const leaderboardStatsIcon = document.getElementById('leaderboardStatsIcon');

leaderboardStatsToggle.addEventListener('click', function () {

    leaderboardStatsGrid.classList.toggle('show');

    if (leaderboardStatsGrid.classList.contains('show')) {
        leaderboardStatsIcon.textContent = '−';
    } else {
        leaderboardStatsIcon.textContent = '+';
    }

});
</script>
</body>
</html>