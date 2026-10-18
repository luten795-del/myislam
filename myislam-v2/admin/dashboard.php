<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/database.php';

// installErrorHandlers() is optional infrastructure -- if error_logger.php
// isn't present (or doesn't define the function), the whole admin panel
// shouldn't fatal-error over it. Degrade gracefully instead.
$errorLoggerPath = __DIR__ . '/error_logger.php';
if (file_exists($errorLoggerPath)) {
    require_once $errorLoggerPath;
}
if (function_exists('installErrorHandlers')) {
    installErrorHandlers();
}

// Restrict access to admin/owner/developer only
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

// Fetch current user role
$roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$roleStmt->execute([$userId]);
$currentUserRole = $roleStmt->fetchColumn();

$allowedRoles = ['admin', 'owner', 'developer'];
if (!in_array($currentUserRole, $allowedRoles)) {
    die('Access denied. Administrator privileges required.');
}

$adminStmt = $conn->prepare("SELECT id, full_name, email, profile_image, role, is_active FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
$adminStmt->execute([$userId]);
$admin = $adminStmt->fetch() ?: [];

// -----------------------------------------------------------------------
// Chart period resolution: day / week / month (default) / year / custom,
// each resolved to a concrete date range plus the right bucket granularity
// so the chart always renders a sensible number of points.
// -----------------------------------------------------------------------
function resolveChartRange(string $period, ?string $customStart, ?string $customEnd): array {
    $now = new DateTime('now');

    switch ($period) {
        case 'day':
            $start = (clone $now)->setTime(0, 0, 0);
            $end = (clone $now)->setTime(23, 59, 59);
            return ['start' => $start, 'end' => $end, 'granularity' => 'hour', 'period' => 'day'];

        case 'week':
            $start = (clone $now)->modify('-6 days')->setTime(0, 0, 0);
            $end = (clone $now)->setTime(23, 59, 59);
            return ['start' => $start, 'end' => $end, 'granularity' => 'day', 'period' => 'week'];

        case 'year':
            $start = (clone $now)->modify('first day of january this year')->setTime(0, 0, 0);
            $end = (clone $now)->setTime(23, 59, 59);
            return ['start' => $start, 'end' => $end, 'granularity' => 'month', 'period' => 'year'];

        case 'custom':
            try {
                $start = $customStart ? new DateTime($customStart . ' 00:00:00') : (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
                $end = $customEnd ? new DateTime($customEnd . ' 23:59:59') : (clone $now)->setTime(23, 59, 59);
                if ($start > $end) [$start, $end] = [$end, $start];
            } catch (Throwable $e) {
                $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
                $end = (clone $now)->setTime(23, 59, 59);
            }
            $spanDays = (int)$start->diff($end)->days;
            $granularity = $spanDays <= 1 ? 'hour' : ($spanDays <= 62 ? 'day' : 'month');
            return ['start' => $start, 'end' => $end, 'granularity' => $granularity, 'period' => 'custom'];

        case 'month':
        default:
            $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
            $end = (clone $now)->setTime(23, 59, 59);
            return ['start' => $start, 'end' => $end, 'granularity' => 'day', 'period' => 'month'];
    }
}

/** Builds the ordered list of empty buckets (key => label) for a range/granularity. */
function buildChartBuckets(DateTime $start, DateTime $end, string $granularity): array {
    $buckets = [];
    if ($granularity === 'hour') {
        $cursor = clone $start;
        while ($cursor <= $end) {
            $buckets[$cursor->format('Y-m-d H:00')] = $cursor->format('g A');
            $cursor->modify('+1 hour');
        }
    } elseif ($granularity === 'month') {
        $cursor = (clone $start)->modify('first day of this month');
        $endMonth = (clone $end)->modify('first day of this month');
        while ($cursor <= $endMonth) {
            $buckets[$cursor->format('Y-m')] = $cursor->format('M Y');
            $cursor->modify('+1 month');
        }
    } else { // day
        $cursor = clone $start;
        while ($cursor->format('Y-m-d') <= $end->format('Y-m-d')) {
            $buckets[$cursor->format('Y-m-d')] = $cursor->format('M j');
            $cursor->modify('+1 day');
        }
    }
    return $buckets;
}

/** The MySQL DATE_FORMAT expression matching a bucket granularity's key format. */
function chartGroupExpr(string $column, string $granularity): string {
    return match ($granularity) {
        'hour'  => "DATE_FORMAT({$column}, '%Y-%m-%d %H:00')",
        'month' => "DATE_FORMAT({$column}, '%Y-%m')",
        default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
    };
}

// -----------------------------------------------------------------------
// AJAX: chart data for the Dashboard line graphs. Returns JSON and exits
// before any HTML is rendered.
// -----------------------------------------------------------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');

    if ($_GET['ajax'] === 'system_series') {
        $period = in_array($_GET['period'] ?? 'month', ['day', 'week', 'month', 'year', 'custom'], true) ? $_GET['period'] : 'month';
        $range = resolveChartRange($period, $_GET['start'] ?? null, $_GET['end'] ?? null);
        $startSql = $range['start']->format('Y-m-d H:i:s');
        $endSql = $range['end']->format('Y-m-d H:i:s');
        $buckets = buildChartBuckets($range['start'], $range['end'], $range['granularity']);
        $lessonExpr = chartGroupExpr('completed_at', $range['granularity']);
        $quizExpr = chartGroupExpr('completed_at', $range['granularity']);
        $loginExpr = chartGroupExpr('created_at', $range['granularity']);

        $stmt = $conn->prepare("
            SELECT {$lessonExpr} AS d, COUNT(*) AS c
            FROM user_content_progress
            WHERE is_completed = 1 AND completed_at BETWEEN ? AND ?
            GROUP BY d
        ");
        $stmt->execute([$startSql, $endSql]);
        $lessonsByBucket = array_column($stmt->fetchAll(), 'c', 'd');

        $stmt = $conn->prepare("
            SELECT {$quizExpr} AS d, COUNT(*) AS c
            FROM quiz_attempts
            WHERE status = 'completed' AND completed_at BETWEEN ? AND ?
            GROUP BY d
        ");
        $stmt->execute([$startSql, $endSql]);
        $quizzesByBucket = array_column($stmt->fetchAll(), 'c', 'd');

        $stmt = $conn->prepare("
            SELECT {$loginExpr} AS d, COUNT(*) AS c
            FROM user_activity_logs
            WHERE action = 'login' AND created_at BETWEEN ? AND ?
            GROUP BY d
        ");
        $stmt->execute([$startSql, $endSql]);
        $loginsByBucket = array_column($stmt->fetchAll(), 'c', 'd');

        // Bucket keys from PHP (Y-m-d H:00 / Y-m / Y-m-d) vs MySQL's DATE_FORMAT
        // output need to line up exactly -- both use the same format strings above.
        $labels = [];
        $lessons = [];
        $quizzes = [];
        $logins = [];
        foreach ($buckets as $key => $label) {
            $labels[] = $label;
            $lessons[] = (int)($lessonsByBucket[$key] ?? 0);
            $quizzes[] = (int)($quizzesByBucket[$key] ?? 0);
            $logins[] = (int)($loginsByBucket[$key] ?? 0);
        }

        echo json_encode(['labels' => $labels, 'lessons' => $lessons, 'quizzes' => $quizzes, 'logins' => $logins, 'period' => $range['period']]);
        exit;
    }

    if ($_GET['ajax'] === 'user_series') {
        $targetUser = (int)($_GET['user_id'] ?? 0);
        $period = in_array($_GET['period'] ?? 'month', ['day', 'week', 'month', 'year', 'custom'], true) ? $_GET['period'] : 'month';
        $range = resolveChartRange($period, $_GET['start'] ?? null, $_GET['end'] ?? null);

        if ($targetUser <= 0) {
            echo json_encode(['labels' => [], 'lessons_cumulative' => [], 'quiz_avg' => [], 'period' => $range['period']]);
            exit;
        }

        $startSql = $range['start']->format('Y-m-d H:i:s');
        $endSql = $range['end']->format('Y-m-d H:i:s');
        $buckets = buildChartBuckets($range['start'], $range['end'], $range['granularity']);
        $lessonExpr = chartGroupExpr('completed_at', $range['granularity']);
        $quizExpr = chartGroupExpr('completed_at', $range['granularity']);

        $stmt = $conn->prepare("
            SELECT {$lessonExpr} AS d, COUNT(*) AS c
            FROM user_content_progress
            WHERE user_id = ? AND is_completed = 1 AND completed_at BETWEEN ? AND ?
            GROUP BY d
        ");
        $stmt->execute([$targetUser, $startSql, $endSql]);
        $lessonsByBucket = array_column($stmt->fetchAll(), 'c', 'd');

        $stmt = $conn->prepare("
            SELECT {$quizExpr} AS d, AVG(percentage) AS avg_pct
            FROM quiz_attempts
            WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?
            GROUP BY d
        ");
        $stmt->execute([$targetUser, $startSql, $endSql]);
        $quizAvgByBucket = array_column($stmt->fetchAll(), 'avg_pct', 'd');

        // Starting point for the cumulative line: lessons completed before this window.
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM user_content_progress
            WHERE user_id = ? AND is_completed = 1 AND completed_at < ?
        ");
        $stmt->execute([$targetUser, $startSql]);
        $running = (int)$stmt->fetchColumn();

        $labels = [];
        $lessonsCumulative = [];
        $quizAvg = [];
        foreach ($buckets as $key => $label) {
            $labels[] = $label;
            $running += (int)($lessonsByBucket[$key] ?? 0);
            $lessonsCumulative[] = $running;
            $quizAvg[] = isset($quizAvgByBucket[$key]) ? round((float)$quizAvgByBucket[$key], 1) : null;
        }

        echo json_encode(['labels' => $labels, 'lessons_cumulative' => $lessonsCumulative, 'quiz_avg' => $quizAvg, 'period' => $range['period']]);
        exit;
    }

    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

// Helper functions
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

function fmtDate($d): string {
    return $d ? date('M d, Y H:i', strtotime($d)) : '—';
}

function progressBar($percent): string {
    $p = max(0, min(100, (int)$percent));
    return "<div style='background:#e5e7eb; height:8px; border-radius:4px; width:100px; display:inline-block;'><div style='background:#166534; height:8px; border-radius:4px; width:{$p}%;'></div></div> " . $p . '%';
}

/**
 * Resolves a coarse (city/region/country) location for an IP address.
 * This is IP-based geolocation ONLY — it is accurate to roughly a city at
 * best and cannot give street-level position, speed, or precise GPS. That
 * data simply doesn't exist for a normal web request; getting it would
 * require the browser to explicitly prompt the visitor for permission,
 * which this admin tool intentionally does not do on their behalf.
 * Results are cached in `ip_geo_cache` so we don't hit the external API
 * repeatedly for the same address.
 */
function resolveIpLocation(PDO $conn, ?string $ip): array {
    $ip = trim((string)$ip);
    $unknown = ['country' => null, 'region' => null, 'city' => null, 'is_private' => false];
    if ($ip === '') return $unknown;

    // Private / local addresses will never resolve to a real location.
    if ($ip === '::1' || $ip === '127.0.0.1' || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $ip)) {
        return ['country' => 'Local/Private', 'region' => null, 'city' => null, 'is_private' => true];
    }

    try {
        $stmt = $conn->prepare("SELECT country, region, city, is_private FROM ip_geo_cache WHERE ip_address = ? LIMIT 1");
        $stmt->execute([$ip]);
        $cached = $stmt->fetch();
        if ($cached) {
            return [
                'country' => $cached['country'],
                'region' => $cached['region'],
                'city' => $cached['city'],
                'is_private' => (bool)$cached['is_private'],
            ];
        }
    } catch (Throwable $ex) {
        // ip_geo_cache table may not exist yet if the migration hasn't run.
        return $unknown;
    }

    // Not cached — try a lookup. Fails silently (returns unknown) if the
    // server has no outbound internet access or the API is unreachable.
    $result = $unknown;
    try {
        $context = stream_context_create(['http' => ['timeout' => 2]]);
        $raw = @file_get_contents(
            "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,regionName,city",
            false,
            $context
        );
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && ($data['status'] ?? '') === 'success') {
                $result = [
                    'country' => $data['country'] ?? null,
                    'region' => $data['regionName'] ?? null,
                    'city' => $data['city'] ?? null,
                    'is_private' => false,
                ];
            }
        }
    } catch (Throwable $ex) {
        // Keep $unknown.
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO ip_geo_cache (ip_address, country, region, city, is_private, fetched_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE country = VALUES(country), region = VALUES(region), city = VALUES(city), fetched_at = NOW()
        ");
        $stmt->execute([$ip, $result['country'], $result['region'], $result['city'], (int)$result['is_private']]);
    } catch (Throwable $ex) {
        // Cache table missing — non-fatal, just skip caching.
    }

    return $result;
}

function formatLocation(array $loc): string {
    if (!empty($loc['is_private'])) return 'Local/Private';
    $parts = array_filter([$loc['city'] ?? null, $loc['region'] ?? null, $loc['country'] ?? null]);
    return $parts ? implode(', ', $parts) : 'Unknown';
}

/**
 * Flags accounts that have logged activity from more than one distinct
 * IP + user-agent combination in the given window — a proxy for "multiple
 * devices/browsers accessing this account", not physical GPS tracking.
 */
function detectMultiDeviceUsers(PDO $conn, int $days = 30): array {
    try {
        $stmt = $conn->prepare("
            SELECT user_id, COUNT(DISTINCT CONCAT(ip_address, '|', user_agent)) AS device_count
            FROM user_activity_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY user_id
            HAVING device_count > 1
            ORDER BY device_count DESC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/** Rough OS + browser label parsed from a User-Agent string. */
function parseUserAgent(?string $ua): array {
    $ua = (string)$ua;
    if ($ua === '') return ['os' => 'Unknown OS', 'browser' => 'Unknown browser'];

    $os = 'Unknown OS';
    if (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Mac OS') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    $browser = 'Unknown browser';
    if (stripos($ua, 'Edg/') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'Chrome') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Safari') !== false) $browser = 'Safari';

    return ['os' => $os, 'browser' => $browser];
}

/**
 * Full Security tab data: for every user active in the window, groups their
 * activity into "device sessions" (distinct IP + user-agent), and flags
 * whether those devices were used CONCURRENTLY (two different devices active
 * within a few minutes of each other -- e.g. account shared/logged in on two
 * phones at once) versus just at DIFFERENT times (e.g. same person's phone
 * and laptop, used on separate occasions -- normal and not inherently
 * suspicious). Also attaches total time spent in the platform (summed from
 * tracked lesson time) so admins can see engagement alongside device count.
 */
function getSecurityOverview(PDO $conn, int $days = 30, int $concurrentWindowMinutes = 10): array {
    try {
        $stmt = $conn->prepare("
            SELECT user_id, ip_address, user_agent, created_at
            FROM user_activity_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND ip_address IS NOT NULL AND ip_address != ''
            ORDER BY user_id, created_at ASC
        ");
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }

    if (!$rows) return [];

    // Group raw hits per user, and per (user, device fingerprint)
    $byUser = [];
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $fingerprint = $r['ip_address'] . '|' . $r['user_agent'];
        $byUser[$uid]['hits'][] = ['fp' => $fingerprint, 'ip' => $r['ip_address'], 'ua' => $r['user_agent'], 'at' => $r['created_at']];
        if (!isset($byUser[$uid]['devices'][$fingerprint])) {
            $byUser[$uid]['devices'][$fingerprint] = [
                'ip_address' => $r['ip_address'],
                'user_agent' => $r['user_agent'],
                'first_seen' => $r['created_at'],
                'last_seen' => $r['created_at'],
                'hit_count' => 0,
            ];
        }
        $byUser[$uid]['devices'][$fingerprint]['last_seen'] = $r['created_at'];
        $byUser[$uid]['devices'][$fingerprint]['hit_count']++;
    }

    // Total time spent per user (from tracked lesson time -- the only
    // existing, explicit time-on-platform measurement in the schema)
    $timeSpent = [];
    try {
        $stmt = $conn->query("SELECT user_id, SUM(time_spent_seconds) AS total FROM user_content_progress GROUP BY user_id");
        foreach ($stmt->fetchAll() as $r) {
            $timeSpent[(int)$r['user_id']] = (int)$r['total'];
        }
    } catch (Throwable $ex) {
        // leave empty -- shown as "—" per user below
    }

    $userIds = array_keys($byUser);
    $userInfo = [];
    if ($userIds) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE id IN ($placeholders)");
        $stmt->execute($userIds);
        foreach ($stmt->fetchAll() as $u) {
            $userInfo[(int)$u['id']] = $u;
        }
    }

    $overview = [];
    foreach ($byUser as $uid => $data) {
        $devices = array_values($data['devices']);
        if (count($devices) < 2) continue; // only single-device users are uninteresting here

        // Concurrency check: scan time-sorted hits for any pair from DIFFERENT
        // devices within the concurrent-window threshold of each other.
        $hits = $data['hits']; // already sorted by created_at from the query
        $concurrent = false;
        for ($i = 1, $n = count($hits); $i < $n; $i++) {
            if ($hits[$i]['fp'] === $hits[$i - 1]['fp']) continue; // same device, not a switch
            $t1 = strtotime((string)$hits[$i - 1]['at']);
            $t2 = strtotime((string)$hits[$i]['at']);
            if ($t1 !== false && $t2 !== false && abs($t2 - $t1) <= ($concurrentWindowMinutes * 60)) {
                $concurrent = true;
                break;
            }
        }

        usort($devices, fn($a, $b) => strtotime((string)$b['last_seen']) <=> strtotime((string)$a['last_seen']));

        $overview[] = [
            'user_id' => $uid,
            'user' => $userInfo[$uid] ?? ['full_name' => "User #$uid", 'email' => '', 'role' => ''],
            'devices' => $devices,
            'device_count' => count($devices),
            'concurrent' => $concurrent,
            'total_time_spent' => $timeSpent[$uid] ?? 0,
        ];
    }

    usort($overview, function ($a, $b) {
        if ($a['concurrent'] !== $b['concurrent']) return $b['concurrent'] <=> $a['concurrent'];
        return $b['device_count'] <=> $a['device_count'];
    });

    return $overview;
}

/**
 * Computes each user's current consecutive-day activity streak from their
 * distinct activity-log dates (login, lesson progress, quiz attempts, etc.
 * all land in user_activity_logs / user_content_progress).
 */
function computeTopStreaks(PDO $conn, int $limit = 5): array {
    try {
        $rows = $conn->query("
            SELECT user_id, activity_date FROM (
                SELECT user_id, DATE(created_at) AS activity_date FROM user_activity_logs
                UNION
                SELECT user_id, DATE(last_accessed_at) AS activity_date FROM user_content_progress WHERE last_accessed_at IS NOT NULL
                UNION
                SELECT user_id, DATE(completed_at) AS activity_date FROM quiz_attempts WHERE completed_at IS NOT NULL
            ) combined
            ORDER BY user_id, activity_date
        ")->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }

    $byUser = [];
    foreach ($rows as $r) {
        $byUser[(int)$r['user_id']][] = $r['activity_date'];
    }

    $streaks = [];
    foreach ($byUser as $uid => $dates) {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $best = 1;
        $current = 1;
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $prev = new DateTime($dates[$i - 1]);
            $curr = new DateTime($dates[$i]);
            $diff = (int)$prev->diff($curr)->days;
            if ($diff === 1) {
                $current++;
                $best = max($best, $current);
            } else {
                $current = 1;
            }
        }
        if (count($dates) > 0) {
            $streaks[$uid] = $best;
        }
    }
    arsort($streaks);
    return array_slice($streaks, 0, $limit, true);
}

// Inserts a row and, if the table's `id` column has no AUTO_INCREMENT/default
// (MySQL error 1364 "Field 'id' doesn't have a default value"), retries with
// id = MAX(id)+1. Same workaround used elsewhere in this project for tables
// like user_content_progress. Run this once to fix the root cause instead:
//   ALTER TABLE audit_logs MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
//   ALTER TABLE user_activity_logs MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
function insertRow(PDO $conn, string $table, array $data): void
{
    $build = function (array $row) use ($table): string {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($row)));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        return "INSERT INTO `{$table}` ({$cols}) VALUES ({$marks})";
    };
    try {
        $conn->prepare($build($data))->execute(array_values($data));
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "'id' doesn't have a default value") === false) {
            throw $e;
        }
        $nextId = (int)$conn->query("SELECT COALESCE(MAX(id), 0) + 1 FROM `{$table}`")->fetchColumn();
        $data = ['id' => $nextId] + $data;
        $conn->prepare($build($data))->execute(array_values($data));
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // --- Impersonation & User Management ---
    if ($action === 'impersonate' && isset($_POST['user_id'])) {
        $targetId = (int)$_POST['user_id'];
        $_SESSION['impersonator_id'] = $userId;
        $_SESSION['user_id'] = $targetId;
        insertRow($conn, 'user_activity_logs', [
            'user_id' => $userId, 'action' => 'impersonate',
            'description' => "Admin impersonated user ID $targetId", 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        insertRow($conn, 'audit_logs', [
            'actor_user_id' => $userId, 'action' => 'impersonate', 'target_type' => 'user',
            'target_id' => $targetId, 'details' => "Started impersonating user #{$targetId}", 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        header('Location: ../user_dashboard.php');
        exit;
    }
    
    if ($action === 'reset_password' && isset($_POST['user_id'])) {
        $targetId = (int)$_POST['user_id'];
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $targetId]);
            insertRow($conn, 'audit_logs', [
                'actor_user_id' => $userId, 'action' => 'reset_password', 'target_type' => 'user',
                'target_id' => $targetId, 'details' => 'Password reset by admin', 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password reset successfully.'];
        }
        header('Location: automation.php?tab=users');
        exit;
    }
    
    // Legacy per-user quick-reset modal (Users tab) now shares the same
    // audited, full-category path as the Reset Data tab below -- it used to
    // have its own separate handler here that (a) never wrote to audit_logs,
    // so quick resets were untracked, and (b) didn't cover certificates or
    // certification exam attempts. See 'bulk_reset_progress' below, which
    // this now redirects into.
    if ($action === 'reset_user_progress' && isset($_POST['user_id'])) {
        $legacyMap = [
            'overall'     => ['progress', 'quiz_attempts', 'monthly_exams', 'leaderboard'],
            'quizzes'     => ['quiz_attempts'],
            'exams'       => ['monthly_exams'],
            'lessons'     => ['progress'],
            'leaderboard' => ['leaderboard'],
        ];
        $_POST['categories'] = $legacyMap[$_POST['reset_type'] ?? 'overall'] ?? ['progress'];
        $_POST['scope'] = 'one';
        $_POST['target_user_id'] = $_POST['user_id'];
        $_POST['confirm_text'] = 'RESET'; // the quick modal already has its own JS confirm()
        $_POST['_return_tab'] = 'users';
        $action = 'bulk_reset_progress';
        // falls through to the bulk_reset_progress handler below
    }
    
    if ($action === 'return_to_admin') {
        if (isset($_SESSION['impersonator_id'])) {
            $_SESSION['user_id'] = $_SESSION['impersonator_id'];
            unset($_SESSION['impersonator_id']);
        }
        header('Location: automation.php');
        exit;
    }

    // --- Bulk / Database-wide Progress Reset (merged from admin_reset_user_progress.php) ---
    if ($action === 'bulk_reset_progress') {
        $confirm = trim((string)($_POST['confirm_text'] ?? ''));
        $scope = trim((string)($_POST['scope'] ?? 'all'));
        $targetUserId = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
        $resetAutoIncrement = isset($_POST['reset_autoincrement']) && $_POST['reset_autoincrement'] === '1';
        $returnTab = in_array($_POST['_return_tab'] ?? '', ['users', 'reset'], true) ? $_POST['_return_tab'] : 'reset';

        $resetCategories = [
            'progress'      => ['label' => 'Content Progress',            'table' => 'user_content_progress'],
            'quiz_attempts' => ['label' => 'Quiz Attempts & Answers',     'table' => 'quiz_attempts'],
            'monthly_exams' => ['label' => 'Monthly Exam Attempts',       'table' => 'monthly_exam_attempts'],
            'leaderboard'   => ['label' => 'Leaderboard Stats',           'table' => 'leaderboard_stats'],
            'cert_attempts' => ['label' => 'Certification Exam Attempts','table' => 'certification_exam_attempts'],
            'certificates'  => ['label' => 'Certificates',                'table' => 'certificates'],
        ];

        $selectedCategories = $_POST['categories'] ?? [];
        if (!is_array($selectedCategories)) $selectedCategories = [];
        $selectedCategories = array_values(array_intersect($selectedCategories, array_keys($resetCategories)));

        if ($confirm !== 'RESET') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Type RESET exactly (all caps) to confirm this action.'];
            header("Location: automation.php?tab={$returnTab}");
            exit;
        }
        if (empty($selectedCategories)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Select at least one data category to reset.'];
            header("Location: automation.php?tab={$returnTab}");
            exit;
        }
        if ($scope === 'one' && $targetUserId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Choose a user for a single-user reset.'];
            header("Location: automation.php?tab={$returnTab}");
            exit;
        }

        try {
            $conn->beginTransaction();
            $deleted = [];
            $touchedTables = [];

            $doQuizAttempts = in_array('quiz_attempts', $selectedCategories, true);
            $plainCategories = array_diff($selectedCategories, ['quiz_attempts']);

            foreach ($plainCategories as $catKey) {
                $table = $resetCategories[$catKey]['table'];
                $touchedTables[] = $table;
                if ($scope === 'one') {
                    $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE user_id = ?");
                    $stmt->execute([$targetUserId]);
                    $deleted[$catKey] = $stmt->rowCount();
                } else {
                    $deleted[$catKey] = $conn->exec("DELETE FROM `{$table}`");
                }
            }

            if ($doQuizAttempts) {
                $touchedTables[] = 'quiz_attempts';
                $touchedTables[] = 'quiz_attempt_answers';
                if ($scope === 'one') {
                    $stmt = $conn->prepare("
                        DELETE qaa FROM quiz_attempt_answers qaa
                        INNER JOIN quiz_attempts qa ON qa.id = qaa.attempt_id
                        WHERE qa.user_id = ?
                    ");
                    $stmt->execute([$targetUserId]);
                    $deleted['quiz_answers'] = $stmt->rowCount();

                    $stmt = $conn->prepare("DELETE FROM quiz_attempts WHERE user_id = ?");
                    $stmt->execute([$targetUserId]);
                    $deleted['quiz_attempts'] = $stmt->rowCount();
                } else {
                    $deleted['quiz_answers'] = $conn->exec("DELETE FROM quiz_attempt_answers");
                    $deleted['quiz_attempts'] = $conn->exec("DELETE FROM quiz_attempts");
                }
            }

            if ($resetAutoIncrement) {
                foreach (array_unique($touchedTables) as $table) {
                    $count = (int)$conn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                    if ($count === 0) {
                        $conn->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
                    }
                }
            }

            // Audit trail for this destructive action.
            $summaryParts = [];
            foreach ($deleted as $k => $v) {
                $summaryParts[] = "{$k}: {$v}";
            }
            $summary = implode(', ', $summaryParts);
            insertRow($conn, 'audit_logs', [
                'actor_user_id' => $userId, 'action' => 'bulk_reset_progress', 'target_type' => 'user',
                'target_id' => $scope === 'one' ? $targetUserId : 0,
                'details' => ($scope === 'one' ? "Single user #{$targetUserId}: " : "ALL USERS: ") . $summary,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            $conn->commit();
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => ($scope === 'one' ? "Reset complete for user #{$targetUserId}. " : 'Database-wide reset complete. ') . $summary
            ];
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Reset failed: ' . $ex->getMessage()];
        }
        header("Location: automation.php?tab={$returnTab}");
        exit;
    }
    
    // --- Notifications ---
    if ($action === 'send_notification') {
        $type = $_POST['type'] ?? 'system';
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $link = $_POST['link'] ?? null;
        $target = $_POST['target'] ?? 'all';
        $targetUserId = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
        
        if ($target === 'all') {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link) SELECT id, ?, ?, ?, ? FROM users WHERE is_active = 1");
            $stmt->execute([$type, $title, $message, $link]);
        } else {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$targetUserId, $type, $title, $message, $link]);
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Notification sent.'];
        header('Location: automation.php?tab=notifications');
        exit;
    }
    
    // --- Media Management ---
    if ($action === 'delete_media' && isset($_POST['media_id'])) {
        $mediaId = (int)$_POST['media_id'];
        $stmt = $conn->prepare("SELECT file_path FROM media WHERE id = ?");
        $stmt->execute([$mediaId]);
        $filePath = $stmt->fetchColumn();
        if ($filePath && file_exists($filePath)) {
            unlink($filePath);
        }
        $conn->prepare("DELETE FROM media WHERE id = ?")->execute([$mediaId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Media deleted.'];
        header('Location: automation.php?tab=media');
        exit;
    }
    
    // --- Announcements ---
    if ($action === 'create_announcement') {
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $audience = $_POST['audience_role'] ?? 'all';
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        $startsAt = $_POST['starts_at'] ?: null;
        $endsAt = $_POST['ends_at'] ?: null;
        $stmt = $conn->prepare("INSERT INTO announcements (title, message, audience_role, is_published, starts_at, ends_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $message, $audience, $isPublished, $startsAt, $endsAt, $userId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Announcement created.'];
        header('Location: automation.php?tab=announcements');
        exit;
    }
    if ($action === 'update_announcement' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $audience = $_POST['audience_role'] ?? 'all';
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        $startsAt = $_POST['starts_at'] ?: null;
        $endsAt = $_POST['ends_at'] ?: null;
        $stmt = $conn->prepare("UPDATE announcements SET title=?, message=?, audience_role=?, is_published=?, starts_at=?, ends_at=?, updated_by=? WHERE id=?");
        $stmt->execute([$title, $message, $audience, $isPublished, $startsAt, $endsAt, $userId, $id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Announcement updated.'];
        header('Location: automation.php?tab=announcements');
        exit;
    }
    if ($action === 'delete_announcement' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $conn->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Announcement deleted.'];
        header('Location: automation.php?tab=announcements');
        exit;
    }
    
    // --- Help Articles ---
    if ($action === 'create_help_category') {
        $name = $_POST['name'] ?? '';
        $slug = $_POST['slug'] ?? strtolower(str_replace(' ', '-', $name));
        $icon = $_POST['icon'] ?? '❓';
        $stmt = $conn->prepare("INSERT INTO help_categories (name, slug, icon) VALUES (?, ?, ?)");
        $stmt->execute([$name, $slug, $icon]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Help category created.'];
        header('Location: automation.php?tab=help');
        exit;
    }
    if ($action === 'create_help_article') {
        $categoryId = (int)$_POST['category_id'];
        $question = $_POST['question'] ?? '';
        $answer = $_POST['answer'] ?? '';
        $slug = $_POST['slug'] ?? strtolower(str_replace(' ', '-', $question));
        $stmt = $conn->prepare("INSERT INTO help_articles (category_id, question, answer, slug) VALUES (?, ?, ?, ?)");
        $stmt->execute([$categoryId, $question, $answer, $slug]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Help article created.'];
        header('Location: automation.php?tab=help');
        exit;
    }
    if ($action === 'delete_help_article' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $conn->prepare("DELETE FROM help_articles WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Help article deleted.'];
        header('Location: automation.php?tab=help');
        exit;
    }
    
    // --- Support Tickets ---
    if ($action === 'respond_ticket' && isset($_POST['ticket_id'])) {
        $ticketId = (int)$_POST['ticket_id'];
        $response = $_POST['admin_response'] ?? '';
        $status = $_POST['status'] ?? 'in_progress';
        $stmt = $conn->prepare("UPDATE support_tickets SET admin_response = ?, status = ?, responded_by = ?, responded_at = NOW() WHERE id = ?");
        $stmt->execute([$response, $status, $userId, $ticketId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Response sent.'];
        header('Location: automation.php?tab=support');
        exit;
    }
    
    // --- System Settings ---
    if ($action === 'update_settings') {
        $siteName = $_POST['site_name'] ?? 'MyIslam';
        $defaultLang = $_POST['default_language'] ?? 'EN';
        $defaultTheme = $_POST['default_theme'] ?? 'light';
        $allowReg = isset($_POST['allow_user_registration']) ? 1 : 0;
        $enableAnn = isset($_POST['enable_announcements']) ? 1 : 0;
        $enableLead = isset($_POST['enable_leaderboard']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE admin_settings SET site_name=?, default_language=?, default_theme=?, allow_user_registration=?, enable_announcements=?, enable_leaderboard=? WHERE id=1");
        $stmt->execute([$siteName, $defaultLang, $defaultTheme, $allowReg, $enableAnn, $enableLead]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Settings updated.'];
        header('Location: automation.php?tab=settings');
        exit;
    }
}

// Get flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Determine active tab
$tab = $_GET['tab'] ?? 'dashboard';
$allowedTabs = [
    'dashboard','users','quizzes','exams','logs','leaderboards','contents',
    'media','announcements','help','support','settings','audit','notifications','categories','subjects','reset','health','security'
];
$tab = in_array($tab, $allowedTabs) ? $tab : 'dashboard';

// --- Data Fetching based on tab ---
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = $conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$totalContents = $conn->query("SELECT COUNT(*) FROM contents WHERE status = 'published'")->fetchColumn();
$totalSubjects = $conn->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
$totalQuizzes = $conn->query("SELECT COUNT(*) FROM quizzes WHERE status = 'published'")->fetchColumn();
$totalExams = $conn->query("SELECT COUNT(*) FROM monthly_exams WHERE status = 'published'")->fetchColumn();
$avgProgress = $conn->query("SELECT AVG(progress_percent) FROM user_content_progress")->fetchColumn();
$avgProgress = $avgProgress ? round((float)$avgProgress, 1) : 0;

// Does the users table have a `username` column? (checked once so the pages
// never crash on a schema without it - the username simply shows as "-")
$hasUsername = false;
try {
    $hasUsername = (bool)$conn->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch();
} catch (Throwable $e) {
    $hasUsername = false;
}
$usernameSelect = $hasUsername ? 'u.username' : 'NULL';

// Users tab
$users = [];
if ($tab === 'users') {
    $userStmt = $conn->query("
        SELECT u.id, u.full_name, {$usernameSelect} AS username, u.email, u.role, u.is_active,
               (SELECT COUNT(*) FROM user_content_progress WHERE user_id = u.id) AS lessons_started,
               (SELECT COUNT(*) FROM user_content_progress WHERE user_id = u.id AND is_completed = 1) AS lessons_completed,
               (SELECT COUNT(*) FROM quiz_attempts WHERE user_id = u.id) AS quizzes_taken,
               (SELECT AVG(percentage) FROM quiz_attempts WHERE user_id = u.id AND status = 'completed') AS quiz_avg,
               (SELECT COUNT(*) FROM monthly_exam_attempts WHERE user_id = u.id) AS exams_taken,
               (SELECT SUM(total_points) FROM leaderboard_stats WHERE user_id = u.id) AS leaderboard_points
        FROM users u
        ORDER BY u.id
    ");
    $users = $userStmt->fetchAll();
}

// Quizzes tab
$quizzes = [];
if ($tab === 'quizzes') {
    $quizzes = $conn->query("
        SELECT q.*, s.name AS subject_name,
               (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) AS question_count,
               (SELECT COUNT(DISTINCT user_id) FROM quiz_attempts WHERE quiz_id = q.id) AS participants,
               (SELECT MAX(percentage) FROM quiz_attempts WHERE quiz_id = q.id AND status = 'completed') AS top_score
        FROM quizzes q
        LEFT JOIN subjects s ON q.subject_id = s.id
        WHERE q.status = 'published'
        ORDER BY q.created_at DESC
    ")->fetchAll();
}

// Exams tab
$exams = [];
if ($tab === 'exams') {
    $exams = $conn->query("
        SELECT me.*, s.name AS subject_name,
               (SELECT COUNT(*) FROM monthly_exam_questions WHERE exam_id = me.id) AS question_count,
               (SELECT COUNT(DISTINCT user_id) FROM monthly_exam_attempts WHERE exam_id = me.id) AS participants,
               (SELECT MAX(percentage) FROM monthly_exam_attempts WHERE exam_id = me.id AND status = 'completed') AS top_score
        FROM monthly_exams me
        LEFT JOIN subjects s ON me.subject_id = s.id
        WHERE me.status = 'published'
        ORDER BY me.start_date DESC
    ")->fetchAll();
}

// Quiz/Exam detail view (with images)
$detailType = $_GET['detail'] ?? '';
$detailId = (int)($_GET['id'] ?? 0);
$detailData = null;
if ($detailType && $detailId) {
    if ($detailType === 'quiz') {
        $stmt = $conn->prepare("SELECT q.*, s.name AS subject_name FROM quizzes q LEFT JOIN subjects s ON q.subject_id = s.id WHERE q.id = ?");
        $stmt->execute([$detailId]);
        $detailData = $stmt->fetch();
        $stmt = $conn->prepare("
            SELECT qq.*
            FROM quiz_questions qq WHERE qq.quiz_id = ? ORDER BY qq.sort_order
        ");
        $stmt->execute([$detailId]);
        $questions = $stmt->fetchAll();
        foreach ($questions as &$q) {
            $optStmt = $conn->prepare("SELECT option_text, image_path, is_correct FROM quiz_options WHERE question_id = ? ORDER BY sort_order");
            $optStmt->execute([$q['id']]);
            $q['options'] = $optStmt->fetchAll();
        }
        $stmt = $conn->prepare("
            SELECT u.full_name, qa.percentage, qa.score, qa.total_points, qa.completed_at
            FROM quiz_attempts qa
            JOIN users u ON qa.user_id = u.id
            WHERE qa.quiz_id = ? AND qa.status = 'completed'
            ORDER BY qa.percentage DESC
        ");
        $stmt->execute([$detailId]);
        $participants = $stmt->fetchAll();
    } elseif ($detailType === 'exam') {
        $stmt = $conn->prepare("SELECT me.*, s.name AS subject_name FROM monthly_exams me LEFT JOIN subjects s ON me.subject_id = s.id WHERE me.id = ?");
        $stmt->execute([$detailId]);
        $detailData = $stmt->fetch();
        $stmt = $conn->prepare("
            SELECT q.*, e.expected_answer, e.explanation
            FROM monthly_exam_questions q
            LEFT JOIN monthly_exam_question_explanations e ON q.id = e.question_id
            WHERE q.exam_id = ? ORDER BY q.sort_order
        ");
        $stmt->execute([$detailId]);
        $questions = $stmt->fetchAll();
        foreach ($questions as &$q) {
            if ($q['question_type'] !== 'explanation') {
                $optStmt = $conn->prepare("SELECT option_text, image_path, is_correct FROM monthly_exam_question_options WHERE question_id = ? ORDER BY sort_order");
                $optStmt->execute([$q['id']]);
                $q['options'] = $optStmt->fetchAll();
            } else {
                $q['options'] = [];
            }
        }
        $stmt = $conn->prepare("
            SELECT u.full_name, mea.percentage, mea.score, mea.total_points, mea.completed_at
            FROM monthly_exam_attempts mea
            JOIN users u ON mea.user_id = u.id
            WHERE mea.exam_id = ? AND mea.status = 'completed'
            ORDER BY mea.percentage DESC
        ");
        $stmt->execute([$detailId]);
        $participants = $stmt->fetchAll();
    }
}

// Media tab
$mediaItems = [];
$mediaStats = ['total' => 0, 'images' => 0, 'videos' => 0, 'audio' => 0, 'docs' => 0];
if ($tab === 'media') {
    $mediaItems = $conn->query("
        SELECT m.*, u.full_name AS uploaded_by_name
        FROM media m
        LEFT JOIN users u ON m.uploaded_by = u.id
        ORDER BY m.created_at DESC
    ")->fetchAll();
    $mediaStats['total'] = count($mediaItems);
    foreach ($mediaItems as $item) {
        if ($item['media_type'] === 'image') $mediaStats['images']++;
        elseif ($item['media_type'] === 'video') $mediaStats['videos']++;
        elseif ($item['media_type'] === 'audio') $mediaStats['audio']++;
        elseif ($item['media_type'] === 'document') $mediaStats['docs']++;
    }
}

// Announcements tab
$announcements = [];
if ($tab === 'announcements') {
    $announcements = $conn->query("
        SELECT a.*, u.full_name AS created_by_name
        FROM announcements a
        LEFT JOIN users u ON a.created_by = u.id
        ORDER BY a.created_at DESC
    ")->fetchAll();
}

// Help tab
$helpCategories = [];
$helpArticles = [];
if ($tab === 'help') {
    $helpCategories = $conn->query("SELECT * FROM help_categories ORDER BY sort_order")->fetchAll();
    $helpArticles = $conn->query("
        SELECT ha.*, hc.name AS category_name
        FROM help_articles ha
        JOIN help_categories hc ON ha.category_id = hc.id
        ORDER BY ha.category_id, ha.sort_order
    ")->fetchAll();
}

// Support tab
$tickets = [];
if ($tab === 'support') {
    $tickets = $conn->query("
        SELECT st.*, u.full_name AS user_name
        FROM support_tickets st
        JOIN users u ON st.user_id = u.id
        ORDER BY st.created_at DESC
    ")->fetchAll();
}

// Settings tab
$settings = [];
if ($tab === 'settings') {
    $settings = $conn->query("SELECT * FROM admin_settings WHERE id = 1")->fetch();
}

// Audit Logs tab
$auditLogs = [];
if ($tab === 'audit') {
    $auditLogs = $conn->query("
        SELECT al.*, u.full_name AS actor_name
        FROM audit_logs al
        LEFT JOIN users u ON al.actor_user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 200
    ")->fetchAll();
    // Resolve each distinct IP once, not once per row.
    $distinctIps = array_unique(array_filter(array_column($auditLogs, 'ip_address')));
    $ipLocations = [];
    foreach ($distinctIps as $ip) {
        $ipLocations[$ip] = resolveIpLocation($conn, $ip);
    }
}

// Logs tab (user_activity_logs)
$logs = [];
$logsMultiDeviceUserIds = [];
if ($tab === 'logs') {
    $limit = 200;
    $logs = $conn->query("
        SELECT l.*, u.full_name 
        FROM user_activity_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        ORDER BY l.created_at DESC 
        LIMIT $limit
    ")->fetchAll();
    $distinctIps = array_unique(array_filter(array_column($logs, 'ip_address')));
    $ipLocations = $ipLocations ?? [];
    foreach ($distinctIps as $ip) {
        if (!isset($ipLocations[$ip])) {
            $ipLocations[$ip] = resolveIpLocation($conn, $ip);
        }
    }
    $logsMultiDeviceUserIds = array_column(detectMultiDeviceUsers($conn, 30), 'device_count', 'user_id');
}

// System Health tab
$healthChecks = [];
$recentSystemErrors = [];
$errorStats = ['error' => 0, 'warning' => 0, 'notice' => 0];
if ($tab === 'health') {
    // DB round-trip latency
    $t0 = microtime(true);
    try {
        $conn->query("SELECT 1")->fetchColumn();
        $dbLatencyMs = round((microtime(true) - $t0) * 1000, 1);
        $healthChecks[] = ['label' => 'Database connection', 'ok' => true, 'detail' => "{$dbLatencyMs} ms round-trip"];
    } catch (Throwable $ex) {
        $healthChecks[] = ['label' => 'Database connection', 'ok' => false, 'detail' => $ex->getMessage()];
    }

    $healthChecks[] = ['label' => 'PHP version', 'ok' => version_compare(PHP_VERSION, '8.0', '>='), 'detail' => PHP_VERSION];

    try {
        $mysqlVersion = $conn->query("SELECT VERSION()")->fetchColumn();
        $healthChecks[] = ['label' => 'MySQL/MariaDB version', 'ok' => true, 'detail' => (string)$mysqlVersion];
    } catch (Throwable $ex) {
        $healthChecks[] = ['label' => 'MySQL/MariaDB version', 'ok' => false, 'detail' => 'Could not read version'];
    }

    $uploadsDir = __DIR__ . '/../uploads';
    $healthChecks[] = [
        'label' => 'Uploads directory writable',
        'ok' => is_dir($uploadsDir) && is_writable($uploadsDir),
        'detail' => is_dir($uploadsDir) ? ($uploadsDir . (is_writable($uploadsDir) ? ' (writable)' : ' (NOT writable)')) : "{$uploadsDir} not found"
    ];

    $freeBytes = @disk_free_space(__DIR__);
    $totalBytes = @disk_total_space(__DIR__);
    if ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0) {
        $freeGb = round($freeBytes / 1073741824, 1);
        $pctFree = round(($freeBytes / $totalBytes) * 100, 1);
        $healthChecks[] = ['label' => 'Disk space free', 'ok' => $pctFree > 10, 'detail' => "{$freeGb} GB free ({$pctFree}%)"];
    }

    $requiredExtensions = ['pdo_mysql', 'mbstring', 'json', 'gd'];
    foreach ($requiredExtensions as $ext) {
        $healthChecks[] = ['label' => "PHP extension: {$ext}", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'Loaded' : 'Missing'];
    }

    $errorLogPath = ini_get('error_log');
    if ($errorLogPath && is_readable($errorLogPath)) {
        $healthChecks[] = ['label' => 'PHP error log', 'ok' => true, 'detail' => $errorLogPath];
    } else {
        $healthChecks[] = ['label' => 'PHP error log', 'ok' => false, 'detail' => 'Not configured or not readable from this script'];
    }

    // Recently logged application errors (requires system_errors table + includes/error_logger.php wired into app entry points)
    try {
        $recentSystemErrors = $conn->query("
            SELECT * FROM system_errors ORDER BY created_at DESC LIMIT 100
        ")->fetchAll();
        foreach ($recentSystemErrors as $err) {
            $lvl = strtolower($err['level'] ?? 'error');
            if (isset($errorStats[$lvl])) $errorStats[$lvl]++;
        }
    } catch (Throwable $ex) {
        $recentSystemErrors = [];
    }

    // Tail native PHP error log if readable
    $errorLogTail = [];
    if ($errorLogPath && is_readable($errorLogPath)) {
        $lines = @file($errorLogPath, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            $errorLogTail = array_slice($lines, -40);
        }
    }
}

// Reset tab (merged from admin_reset_user_progress.php)
$resetStats = [];
$resetUsersList = [];
if ($tab === 'reset') {
    $resetUsersList = $conn->query("SELECT u.id, u.full_name, {$usernameSelect} AS username, u.email FROM users u WHERE u.is_active = 1 ORDER BY u.full_name")->fetchAll();
    $resetTableMap = [
        'progress'      => 'user_content_progress',
        'quiz_attempts' => 'quiz_attempts',
        'monthly_exams' => 'monthly_exam_attempts',
        'leaderboard'   => 'leaderboard_stats',
        'cert_attempts' => 'certification_exam_attempts',
        'certificates'  => 'certificates',
    ];
    $resetStats['users_with_progress'] = (int)$conn->query("SELECT COUNT(DISTINCT user_id) FROM user_content_progress")->fetchColumn();
    foreach ($resetTableMap as $key => $table) {
        try {
            $resetStats[$key] = (int)$conn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (Throwable $ex) {
            $resetStats[$key] = 0;
        }
    }
}

// Security tab: device sessions, concurrent-usage flags, total time spent
$securityOverview = [];
$securityWindowDays = 30;
if ($tab === 'security') {
    $securityWindowDays = max(1, min(90, (int)($_GET['days'] ?? 30)));
    $securityOverview = getSecurityOverview($conn, $securityWindowDays);
}

// Dashboard podiums (top participator / streak / reader) + secondary stats
$podiumTopParticipator = $podiumTopReader = [];
$podiumTopStreaks = [];
$podiumOtherAngles = [];
if ($tab === 'dashboard') {
    $podiumTopParticipator = $conn->query("
        SELECT u.id, u.full_name, SUM(ls.total_points) AS points
        FROM leaderboard_stats ls
        JOIN users u ON ls.user_id = u.id
        GROUP BY ls.user_id
        ORDER BY points DESC
        LIMIT 3
    ")->fetchAll();

    $podiumTopReader = $conn->query("
        SELECT u.id, u.full_name,
               COUNT(*) AS lessons_completed,
               COALESCE(SUM(ucp.time_spent_seconds), 0) AS total_seconds
        FROM user_content_progress ucp
        JOIN users u ON ucp.user_id = u.id
        WHERE ucp.is_completed = 1
        GROUP BY ucp.user_id
        ORDER BY lessons_completed DESC, total_seconds DESC
        LIMIT 3
    ")->fetchAll();

    $streakData = computeTopStreaks($conn, 3);
    if (!empty($streakData)) {
        $ids = array_keys($streakData);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $namesById = [];
        foreach ($stmt->fetchAll() as $row) {
            $namesById[(int)$row['id']] = $row['full_name'];
        }
        foreach ($streakData as $uid => $days) {
            $podiumTopStreaks[] = ['id' => $uid, 'full_name' => $namesById[$uid] ?? "User #$uid", 'streak_days' => $days];
        }
    }

    // A few extra angles for the "other" stats row
    $podiumOtherAngles['most_quizzes_passed'] = $conn->query("
        SELECT u.full_name, COUNT(*) AS c
        FROM quiz_attempts qa JOIN users u ON qa.user_id = u.id
        WHERE qa.passed = 1
        GROUP BY qa.user_id ORDER BY c DESC LIMIT 1
    ")->fetch();

    $podiumOtherAngles['highest_quiz_avg'] = $conn->query("
        SELECT u.full_name, AVG(qa.percentage) AS avg_pct
        FROM quiz_attempts qa JOIN users u ON qa.user_id = u.id
        WHERE qa.status = 'completed'
        GROUP BY qa.user_id HAVING COUNT(*) >= 3
        ORDER BY avg_pct DESC LIMIT 1
    ")->fetch();

    $podiumOtherAngles['most_active_today'] = $conn->query("
        SELECT u.full_name, COUNT(*) AS c
        FROM user_activity_logs l JOIN users u ON l.user_id = u.id
        WHERE DATE(l.created_at) = CURDATE()
        GROUP BY l.user_id ORDER BY c DESC LIMIT 1
    ")->fetch();

    $dashboardSecurityOverview = getSecurityOverview($conn, 30);
}

// Leaderboards tab
$topOverall = $topLesson = $topCompetition = $topSelf = $topExams = [];
if ($tab === 'leaderboards') {
    $topOverall = $conn->query("
        SELECT u.full_name, SUM(ls.total_points) AS points, u.id
        FROM leaderboard_stats ls
        JOIN users u ON ls.user_id = u.id
        GROUP BY ls.user_id
        ORDER BY points DESC
        LIMIT 20
    ")->fetchAll();
    
    $topLesson = $conn->query("
        SELECT u.full_name, ls.total_points, u.id
        FROM leaderboard_stats ls
        JOIN users u ON ls.user_id = u.id
        WHERE ls.mode = 'lesson'
        ORDER BY ls.total_points DESC
        LIMIT 20
    ")->fetchAll();
    
    $topCompetition = $conn->query("
        SELECT u.full_name, ls.total_points, u.id
        FROM leaderboard_stats ls
        JOIN users u ON ls.user_id = u.id
        WHERE ls.mode = 'competition'
        ORDER BY ls.total_points DESC
        LIMIT 20
    ")->fetchAll();
    
    $topSelf = $conn->query("
        SELECT u.full_name, SUM(qa.score) AS points, u.id
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        JOIN users u ON qa.user_id = u.id
        WHERE qa.status = 'completed' AND q.mode = 'self'
        GROUP BY qa.user_id
        ORDER BY points DESC
        LIMIT 20
    ")->fetchAll();
    
    $topExams = $conn->query("
        SELECT u.full_name, SUM(mea.score) AS points, u.id
        FROM monthly_exam_attempts mea
        JOIN users u ON mea.user_id = u.id
        WHERE mea.status = 'completed'
        GROUP BY mea.user_id
        ORDER BY points DESC
        LIMIT 20
    ")->fetchAll();
}

// Content tab (subjects with lessons)
$subjects = [];
if ($tab === 'contents') {
    $subjects = $conn->query("
        SELECT s.*, 
               (SELECT COUNT(*) FROM contents WHERE subject_id = s.id AND status = 'published') AS lesson_count,
               (SELECT AVG(progress_percent) FROM user_content_progress WHERE subject_id = s.id) AS avg_progress
        FROM subjects s
        ORDER BY s.sort_order
    ")->fetchAll();
}

// Subjects tab (detailed subjects)
$allSubjects = [];
if ($tab === 'subjects') {
    $allSubjects = $conn->query("SELECT * FROM subjects ORDER BY sort_order, name")->fetchAll();
}

// Categories tab
$categories = [];
if ($tab === 'categories') {
    $categories = $conn->query("
        SELECT c.*, s.name AS subject_name
        FROM categories c
        JOIN subjects s ON c.subject_id = s.id
        ORDER BY s.name, c.sort_order
    ")->fetchAll();
}

// Notifications tab
$recentNotifications = [];
$allUsersForSelect = $conn->query("SELECT u.id, u.full_name, {$usernameSelect} AS username FROM users u WHERE u.is_active = 1 ORDER BY u.full_name")->fetchAll();
if ($tab === 'notifications') {
    $recentNotifications = $conn->query("
        SELECT n.*, u.full_name AS user_name
        FROM notifications n
        JOIN users u ON n.user_id = u.id
        ORDER BY n.created_at DESC
        LIMIT 50
    ")->fetchAll();
}

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => true],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Monthly Exams', 'link' => 'admin_monthly_exams.php', 'active' => false],
     ['name' => 'Certification Exams', 'link' => 'certification_exams.php', 'active' => false],    
        ['name' => 'Courses', 'link' => 'admin_courses.php', 'active' => false],

    ['name' => 'Multmedia', 'link' => 'admin_multmedia.php', 'active' => false],
    ['name' => 'adhkaar', 'link' => 'admin_adhkaar.php', 'active' => false],
     ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false],
      ['name' => 'Reset User Progress', 'link' => 'admin_reset_user_progress.php', 'active' => false],    
   
     ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false],
    ['name' => 'Help Center', 'link' => 'admin_help_center.php', 'active' => false],
       
      ['name' => 'Automation', 'link' => 'automation.php', 'active' => false],
  
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Developer Console – MyIslam</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <script>
        // Apply saved theme before first paint so there's no flash.
        // Dark is the default (same as admin_contents.php); only an explicit
        // 'light' choice switches it. Shares the 'theme' key with the other admin pages.
        (function() {
            try {
                const saved = localStorage.getItem('theme') || localStorage.getItem('adminTheme');
                if (saved === 'light') document.documentElement.classList.add('light-init');
            } catch (e) {}
        })();
    </script>
    <style>
        /* ==========================================================
           Shared admin theme - identical to admin_contents.php
           (dark by default, light via body.light-mode)
           ========================================================== */
        *{box-sizing:border-box;margin:0;padding:0}

        :root{
            --bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;--line:#304150;
            --text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;
            --brand:#4C9172;--brand-soft:rgba(76,145,114,.16);
            --gold:#C9A85E;--gold-soft:rgba(201,168,94,.16);
            --accent:#6FA0C4;--warning:#f59e0b;--danger:#ef4444;--success:#10b981;
            --danger-soft:rgba(239,68,68,.12);--warning-soft:rgba(245,158,11,.12);
            --success-soft:rgba(16,185,129,.12);--accent-soft:rgba(111,160,196,.16);
            --header-bg:#13202a;--header-height:72px;--footer-height:92px;
            --shadow-sm:0 1px 2px 0 rgb(0 0 0 / .3);--shadow-md:0 4px 6px -1px rgb(0 0 0 / .4);--shadow-lg:0 10px 15px -3px rgb(0 0 0 / .5);
            --radius-sm:12px;--radius-md:16px;--radius-lg:20px;
        }
        body.dark-mode{
            --bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;--line:#304150;
            --text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;
            --brand:#4C9172;--brand-soft:rgba(76,145,114,.16);
            --gold:#C9A85E;--gold-soft:rgba(201,168,94,.16);--accent:#6FA0C4;--accent-soft:rgba(111,160,196,.16);
            --header-bg:#13202a;
        }
        html.light-init, body.light-mode{
            --bg:#f5f7f5;--surface:#ffffff;--surface-2:#f9fafb;--surface-3:#eef2f7;--line:#e5e7eb;
            --text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;
            --brand:#166534;--brand-soft:#e7f3eb;
            --gold:#9C7A2E;--gold-soft:rgba(156,122,46,.10);
            --accent:#3b82f6;--accent-soft:rgba(59,130,246,.12);
            --header-bg:#e1f0e7;
            --shadow-sm:0 1px 2px 0 rgb(0 0 0 / .05);--shadow-md:0 4px 6px -1px rgb(0 0 0 / .1);--shadow-lg:0 10px 15px -3px rgb(0 0 0 / .1);
        }

        body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5}
        a{text-decoration:none;color:inherit}
        button,input,textarea,select{font:inherit}

        /* ---------- Header ---------- */
        .app-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:var(--header-height);background:var(--header-bg);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;backdrop-filter:blur(10px);gap:18px}
        .header-left,.header-right{display:flex;align-items:center;gap:12px}
        .header-left{flex:1;min-width:0}
        .header-top-nav{display:flex;align-items:center;gap:8px;overflow-x:auto}
        .header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-size:13px;font-weight:700;transition:.2s}
        .header-top-link:hover,.header-top-link.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
        .menu-toggle,.icon-btn,.lang-btn,.brand-box{height:44px;min-width:44px;border-radius:var(--radius-sm);background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;transition:.2s;cursor:pointer;color:var(--text)}
        .menu-toggle:hover,.icon-btn:hover,.lang-btn:hover,.profile-btn:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
        .menu-toggle{font-size:22px}
        .lang-btn{min-width:56px;padding:0 14px;font-weight:600}
        .brand-box{font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none;white-space:nowrap;cursor:default}
        .profile-area{position:relative}
        .profile-btn{width:44px;height:44px;border-radius:50%;overflow:hidden;background:var(--surface);border:2px solid var(--brand);cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center;color:var(--brand);font-weight:700}
        .profile-btn img{width:100%;height:100%;object-fit:cover}
        .profile-menu{position:absolute;top:56px;right:0;width:280px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:16px;display:none;box-shadow:var(--shadow-lg);z-index:1001}
        .profile-menu.show{display:block}
        .profile-menu-top{display:flex;gap:12px;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--line)}
        .profile-menu-top img,.profile-avatar-fallback{width:56px;height:56px;border-radius:50%}
        .profile-avatar-fallback{display:inline-flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:700;font-size:16px}
        .profile-menu-top h4{font-size:16px;margin-bottom:4px;font-weight:600}
        .profile-menu-top p{font-size:12px;color:var(--muted)}
        .profile-menu a{display:block;padding:10px 12px;border-radius:var(--radius-sm);font-size:14px;transition:background .2s;margin:4px 0}
        .profile-menu a:hover{background:var(--brand-soft);color:var(--brand)}

        /* ---------- Sidebar (slide-out drawer, same as admin_contents) ---------- */
        .sidebar-overlay{display:none;position:fixed;top:var(--header-height);left:0;width:100%;height:calc(100vh - var(--header-height));background:rgba(0,0,0,.5);z-index:1100;backdrop-filter:blur(4px)}
        .sidebar-overlay.show{display:block}
        .sidebar{position:fixed;top:var(--header-height);left:-100%;width:320px;height:calc(100vh - var(--header-height));background:var(--surface);border-right:1px solid var(--line);padding:24px 20px;z-index:1200;transition:left .3s ease;overflow-y:auto}
        .sidebar.open{left:0}
        .sidebar-title{font-size:12px;color:var(--muted);text-transform:uppercase;margin-bottom:20px;font-weight:700;letter-spacing:1px;padding:0 8px}
        .sidebar-nav{display:grid;gap:8px}
        .nav-section-label{font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700;letter-spacing:1px;padding:14px 8px 2px}
        .sidebar-nav a{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--radius-md);background:var(--surface-2);border:1px solid transparent;font-size:14px;font-weight:500;color:var(--text);transition:all .2s}
        .sidebar-nav a:hover{background:var(--brand-soft);border-color:var(--brand);transform:translateX(4px)}
        .sidebar-nav a.active{background:var(--brand-soft);color:var(--brand);border-color:var(--brand)}
        .sidebar-nav a .ico{width:20px;text-align:center}

        /* ---------- Page layout ---------- */
        .admin-shell{display:block;min-height:100vh}
        .main{max-width:1600px;width:100%;margin:0 auto;padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px)}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
        h1{font-size:28px;font-weight:700;display:flex;align-items:center;gap:10px;color:var(--text)}
        .page-subtitle{color:var(--text-secondary);font-size:14px;margin-top:2px}

        /* ---------- Cards ---------- */
        .card,.section-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px;margin-bottom:24px;transition:.2s}
        .card:hover,.section-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
        .card h3{color:var(--text);margin-bottom:14px;font-size:17px;font-weight:700;display:flex;align-items:center;gap:8px}
        .card .card-desc{color:var(--muted);font-size:13px;margin:-8px 0 14px}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
        .stat-card{background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius-lg);padding:20px;text-align:center}
        .stat-value{font-size:32px;font-weight:800;color:var(--brand);margin-bottom:8px}
        .stat-label{font-size:13px;color:var(--muted)}
        .hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px}
        .empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
        .flash{background:var(--success);color:#fff;padding:14px 20px;border-radius:var(--radius-md);margin-bottom:20px;font-weight:600;box-shadow:var(--shadow-md)}
        .flash.error,.flash.danger{background:var(--danger)}

        /* ---------- Buttons ---------- */
        .btn{padding:12px 20px;border-radius:14px;font-weight:700;font-size:14px;border:1px solid transparent;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:8px;background:var(--brand);color:#fff;text-decoration:none;margin:2px}
        .btn:hover{transform:translateY(-2px);filter:brightness(1.08)}
        .btn.primary{background:var(--brand);color:#fff}
        .btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
        .btn.warn,.btn-warning{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
        .btn.danger,.btn-danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
        .btn.danger:hover,.btn-danger:hover,.btn.warn:hover,.btn-warning:hover{filter:none;background:rgba(239,68,68,.2)}
        .btn-sm{padding:6px 12px;font-size:12px}
        .btn-outline{background:transparent;color:var(--brand);border:1px solid var(--brand)}
        .btn-outline:hover{background:var(--brand-soft);color:var(--brand);filter:none}
        .theme-toggle{display:flex;align-items:center;gap:8px;background:var(--surface-2);border:1px solid var(--line);border-radius:40px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;color:var(--text)}
        .theme-toggle:hover{background:var(--brand-soft)}

        /* ---------- Forms ---------- */
        .form-fields{display:grid;gap:14px}
        .form-row,.form-group{display:grid;gap:8px;margin-bottom:16px}
        .form-row{margin-bottom:0}
        label{display:block;font-size:14px;font-weight:700}
        input,select,textarea{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);font-size:14px;outline:none}
        input:focus,select:focus,textarea:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08);outline:none}
        input[type="checkbox"],input[type="radio"]{width:18px;height:18px;padding:0;accent-color:var(--brand)}
        textarea{min-height:80px;resize:vertical}
        .form-input,.form-textarea,.form-select{width:100%}
        .inline-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .check-row,.checkbox-row{display:flex;align-items:center;gap:10px;padding-top:6px}
        .check-row input,.checkbox-row input{width:18px;height:18px;flex-shrink:0}
        .action-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
        .flex{display:flex;gap:16px;flex-wrap:wrap}

        /* ---------- Tables ---------- */
        table{width:100%;border-collapse:collapse;background:var(--surface);border-radius:var(--radius-md);overflow:hidden}
        th,td{padding:12px 15px;text-align:left;border-bottom:1px solid var(--line);font-size:13.5px}
        th{background:var(--brand-soft);font-weight:700;color:var(--brand);text-transform:uppercase;font-size:11.5px;letter-spacing:.3px}
        table tbody tr:nth-child(even){background:var(--surface-2)}
        table tbody tr:hover{background:var(--brand-soft)}

        /* ---------- Badges ---------- */
        .badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;background:var(--brand-soft);color:var(--brand)}
        .badge.danger,.badge-danger{background:var(--danger-soft);color:var(--danger)}
        .badge-success{background:var(--success-soft);color:var(--success)}
        .badge-warning{background:var(--warning-soft);color:var(--warning)}
        .badge-info{background:var(--accent-soft);color:var(--accent)}

        /* ---------- Modals ---------- */
        .modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center}
        .modal{background:var(--surface);border-radius:var(--radius-lg);max-width:500px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid var(--line);padding:24px}
        .modal-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--line)}
        .modal-header h2,.modal-header h3{font-size:20px;font-weight:700}
        .modal-close,.close-btn{background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted);padding:0 8px;width:auto}
        .modal-body{padding:0}

        /* ---------- Toasts ---------- */
        .toast-container{position:fixed;top:80px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px}
        .toast{padding:16px 20px;border-radius:var(--radius-md);background:var(--success);color:#fff;box-shadow:var(--shadow-lg);font-weight:500;display:flex;align-items:center;gap:10px;animation:slideIn .3s ease;max-width:400px}
        .toast.success{background:#10b981}
        .toast.danger{background:#ef4444}
        .toast .close-toast{margin-left:auto;cursor:pointer;font-size:20px;opacity:.8}
        @keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}

        /* ==========================================================
           Dashboard-specific widgets (restyled with the shared tokens)
           ========================================================== */
        .log-entry{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:13px;padding:10px 0;border-bottom:1px solid var(--line)}
        .log-entry .log-meta{color:var(--muted);font-size:12px}
        .podium-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-bottom:24px}
        .podium-card{background:var(--surface);border-radius:var(--radius-lg);padding:22px;position:relative;overflow:hidden;border:1px solid var(--line);transition:.2s}
        .podium-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
        .podium-card::before{content:'';position:absolute;top:0;left:0;right:0;height:5px}
        .podium-card.gold::before{background:linear-gradient(90deg,#fbbf24,#f59e0b)}
        .podium-card.silver::before{background:linear-gradient(90deg,#a78bfa,#7c3aed)}
        .podium-card.bronze::before{background:linear-gradient(90deg,#38bdf8,#0284c7)}
        .podium-icon{font-size:32px;margin-bottom:8px}
        .podium-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin-bottom:10px}
        .podium-name{font-size:19px;font-weight:800;color:var(--text);margin-bottom:4px}
        .podium-metric{font-size:14px;color:var(--brand);font-weight:700}
        .podium-empty{color:var(--muted);font-size:14px;padding:8px 0}
        .angle-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
        .angle-card{background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius-md);padding:16px 18px}
        .angle-label{font-size:12px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-bottom:4px}
        .angle-value{font-size:16px;font-weight:700;color:var(--brand)}
        .chart-card canvas{max-height:320px}
        .chart-toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px}
        .chart-toolbar select{width:auto;min-width:220px}
        .period-filter{display:flex;gap:6px;flex-wrap:wrap}
        .period-pill{padding:6px 14px;border-radius:999px;background:var(--surface-2);border:1px solid var(--line);color:var(--text);font-size:13px;font-weight:600;cursor:pointer;transition:all .15s}
        .period-pill.active{background:var(--brand);border-color:var(--brand);color:#fff}
        .period-pill:hover:not(.active){background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
        .custom-range-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap}
        .custom-range-row input[type="date"]{width:auto;padding:8px 12px}
        .device-pill{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;padding:2px 9px;border-radius:20px;background:var(--warning-soft);color:var(--warning);font-weight:700}
        .device-pill-danger{background:var(--danger-soft);color:var(--danger)}
        .security-warning-banner{display:flex;gap:16px;background:var(--danger-soft);border:1px solid rgba(239,68,68,.35);border-radius:var(--radius-lg);padding:20px;margin-bottom:22px}
        .security-warning-icon{font-size:32px;flex-shrink:0}
        .security-warning-body h3{color:var(--danger);margin-bottom:6px}
        .security-warning-body p{color:var(--text-secondary);font-size:14px;line-height:1.6}
        .device-session-row{border:1px solid var(--line);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:10px;background:var(--surface-2)}
        .device-session-row.concurrent{border-color:var(--danger);background:var(--danger-soft)}
        .device-session-user{font-weight:700;font-size:15px}
        .device-session-meta{font-size:12px;color:var(--muted);margin-top:2px}
        .device-session-item{font-size:13px;padding:8px 0;border-top:1px dashed var(--line);margin-top:8px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}
        .impersonate-bar{background:var(--warning);color:#000;padding:8px 20px;border-radius:40px;display:inline-block;margin-bottom:16px;font-weight:600}
        .impersonate-bar a{margin-left:16px;color:#000;text-decoration:underline}
        .reset-stat{background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius-md);padding:14px;text-align:center}
        .reset-stat .value{font-size:24px;font-weight:800;color:var(--brand)}
        .reset-stat .label{font-size:12px;color:var(--muted)}
        .question-img{max-width:200px;max-height:150px;margin:8px 0;border-radius:8px}
        .option-img{max-width:100px;max-height:80px;margin-left:8px;vertical-align:middle}

        /* ---------- Responsive (same breakpoints as admin_contents) ---------- */
        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:950px){.header-top-nav{display:none}}
        @media(max-width:768px){
            :root{--header-height:64px;--footer-height:80px}
            .main{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}
            .card,.section-card{padding:16px}
            .stats-grid,.inline-row{grid-template-columns:1fr;gap:12px}
            h1{font-size:24px}
        }
    </style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>
    <header class="app-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
            <div class="brand-box">🛠️ Admin</div>
            <nav class="header-top-nav" aria-label="Admin navigation">
                <?php foreach ($mainNavigation as $navItem): ?>
                    <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>">
                        <?php echo e($navItem['name']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="header-right">
            <button class="icon-btn" id="themeToggle" type="button" aria-label="Dark mode">🌙</button>
            <button class="lang-btn" id="langToggle" type="button">EN</button>
            <button class="icon-btn" type="button" aria-label="Notifications">🔔</button>

            <div class="profile-area" id="profileArea">
                <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                    <?php if (!empty($admin['profile_image'])): ?>
                        <img src="<?php echo e($admin['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <?php echo e(initials((string)$admin['full_name'])); ?>
                    <?php endif; ?>
                </button>

                <div class="profile-menu" id="profileMenu">
                    <div class="profile-menu-top">
                        <?php if (!empty($admin['profile_image'])): ?>
                            <img src="<?php echo e($admin['profile_image']); ?>" alt="Profile">
                        <?php else: ?>
                            <div class="profile-avatar-fallback"><?php echo e(initials((string)$admin['full_name'])); ?></div>
                        <?php endif; ?>
                        <div>
                            <h4><?php echo e($admin['full_name'] ?? 'Admin'); ?></h4>
                            <p><?php echo e($admin['email'] ?? ''); ?></p>
                            <p style="font-size:10px;">Role: <?php echo e(ucfirst((string)($admin['role'] ?? 'admin'))); ?></p>
                        </div>
                    </div>
                    <a href="../profile.php">👤 My Profile</a>
                    <a href="admin_settings.php">⚙️ Admin Settings</a>
                    <a href="../logout.php">🚪 Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="admin-shell">
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-title">🛠️ Developer Console</div>
            <nav class="sidebar-nav">
                <div class="nav-section-label">Overview</div>
                <a href="?tab=dashboard" class="<?= $tab=='dashboard'?'active':'' ?>"><span class="ico">📊</span> Dashboard</a>

                <div class="nav-section-label">People</div>
                <a href="?tab=users" class="<?= $tab=='users'?'active':'' ?>"><span class="ico">👥</span> Users</a>
                <a href="?tab=leaderboards" class="<?= $tab=='leaderboards'?'active':'' ?>"><span class="ico">🏆</span> Leaderboards</a>

                <div class="nav-section-label">Content</div>
                <a href="?tab=quizzes" class="<?= $tab=='quizzes'?'active':'' ?>"><span class="ico">📝</span> Quizzes</a>
                <a href="?tab=exams" class="<?= $tab=='exams'?'active':'' ?>"><span class="ico">📅</span> Exams</a>
                <a href="?tab=contents" class="<?= $tab=='contents'?'active':'' ?>"><span class="ico">📚</span> Content</a>
                <a href="?tab=subjects" class="<?= $tab=='subjects'?'active':'' ?>"><span class="ico">📖</span> Subjects</a>
                <a href="?tab=categories" class="<?= $tab=='categories'?'active':'' ?>"><span class="ico">🏷️</span> Categories</a>
                <a href="?tab=media" class="<?= $tab=='media'?'active':'' ?>"><span class="ico">🖼️</span> Media</a>

                <div class="nav-section-label">Communication</div>
                <a href="?tab=notifications" class="<?= $tab=='notifications'?'active':'' ?>"><span class="ico">🔔</span> Notifications</a>
                <a href="?tab=announcements" class="<?= $tab=='announcements'?'active':'' ?>"><span class="ico">📢</span> Announcements</a>
                <a href="?tab=help" class="<?= $tab=='help'?'active':'' ?>"><span class="ico">❓</span> Help</a>
                <a href="?tab=support" class="<?= $tab=='support'?'active':'' ?>"><span class="ico">🎫</span> Support</a>

                <div class="nav-section-label">System</div>
                <a href="?tab=settings" class="<?= $tab=='settings'?'active':'' ?>"><span class="ico">⚙️</span> Settings</a>
                <a href="?tab=audit" class="<?= $tab=='audit'?'active':'' ?>"><span class="ico">🔍</span> Audit</a>
                <a href="?tab=logs" class="<?= $tab=='logs'?'active':'' ?>"><span class="ico">📋</span> Logs</a>
                <a href="?tab=security" class="<?= $tab=='security'?'active':'' ?>"><span class="ico">🛡️</span> Security</a>
                <a href="?tab=reset" class="<?= $tab=='reset'?'active':'' ?>"><span class="ico">🧹</span> Data Reset</a>
                <a href="?tab=health" class="<?= $tab=='health'?'active':'' ?>"><span class="ico">🩺</span> System Health</a>
            </nav>
        </aside>

        <div class="main">
    <?php if (isset($_SESSION['impersonator_id'])): ?>
    <div class="impersonate-bar">
        ⚠️ You are impersonating user #<?= $_SESSION['user_id'] ?>.
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="return_to_admin">
            <button type="submit" class="btn btn-sm">Return to Admin</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($flash): ?>
    <div class="flash <?= $flash['type'] ?? '' ?>"><?= e($flash['message'] ?? '') ?></div>
    <?php endif; ?>
    
    <!-- Dashboard Tab -->
    <?php if ($tab === 'dashboard'): ?>

    <div class="card">
        <h3>🏆 Top Performers</h3>
        <div class="podium-row">
            <div class="podium-card gold">
                <div class="podium-icon">🥇</div>
                <div class="podium-title">Top Participator</div>
                <?php if (!empty($podiumTopParticipator[0])): ?>
                    <div class="podium-name"><?= e($podiumTopParticipator[0]['full_name']) ?></div>
                    <div class="podium-metric"><?= number_format((float)$podiumTopParticipator[0]['points']) ?> pts</div>
                <?php else: ?>
                    <div class="podium-empty">No leaderboard activity yet.</div>
                <?php endif; ?>
            </div>
            <div class="podium-card silver">
                <div class="podium-icon">🔥</div>
                <div class="podium-title">Longest Day Streak</div>
                <?php if (!empty($podiumTopStreaks[0])): ?>
                    <div class="podium-name"><?= e($podiumTopStreaks[0]['full_name']) ?></div>
                    <div class="podium-metric"><?= (int)$podiumTopStreaks[0]['streak_days'] ?> day<?= $podiumTopStreaks[0]['streak_days'] == 1 ? '' : 's' ?> in a row</div>
                <?php else: ?>
                    <div class="podium-empty">No streak data yet.</div>
                <?php endif; ?>
            </div>
            <div class="podium-card bronze">
                <div class="podium-icon">📖</div>
                <div class="podium-title">Most Reader</div>
                <?php if (!empty($podiumTopReader[0])): ?>
                    <div class="podium-name"><?= e($podiumTopReader[0]['full_name']) ?></div>
                    <div class="podium-metric"><?= (int)$podiumTopReader[0]['lessons_completed'] ?> lessons completed</div>
                <?php else: ?>
                    <div class="podium-empty">No completed lessons yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (count($podiumTopParticipator) > 1 || count($podiumTopStreaks) > 1 || count($podiumTopReader) > 1): ?>
        <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Rank</th><th>Top Participator</th><th>Longest Streak</th><th>Most Reader</th></tr></thead>
                <tbody>
                <?php for ($i = 0; $i < 3; $i++): ?>
                <tr>
                    <td><?= ['🥇 1st','🥈 2nd','🥉 3rd'][$i] ?></td>
                    <td><?= isset($podiumTopParticipator[$i]) ? e($podiumTopParticipator[$i]['full_name']) . ' — ' . number_format((float)$podiumTopParticipator[$i]['points']) . ' pts' : '—' ?></td>
                    <td><?= isset($podiumTopStreaks[$i]) ? e($podiumTopStreaks[$i]['full_name']) . ' — ' . (int)$podiumTopStreaks[$i]['streak_days'] . 'd' : '—' ?></td>
                    <td><?= isset($podiumTopReader[$i]) ? e($podiumTopReader[$i]['full_name']) . ' — ' . (int)$podiumTopReader[$i]['lessons_completed'] . ' lessons' : '—' ?></td>
                </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card chart-card">
        <div class="chart-toolbar">
            <h3 style="margin-bottom:0;">📈 System Performance</h3>
            <div class="period-filter" data-chart="system">
                <button type="button" class="period-pill" data-period="day">Day</button>
                <button type="button" class="period-pill" data-period="week">Week</button>
                <button type="button" class="period-pill active" data-period="month">Month</button>
                <button type="button" class="period-pill" data-period="year">Year</button>
                <button type="button" class="period-pill" data-period="custom">Custom</button>
            </div>
        </div>
        <div class="custom-range-row" id="systemCustomRange" style="display:none;">
            <input type="date" id="systemCustomStart"> <span>to</span> <input type="date" id="systemCustomEnd">
            <button type="button" class="btn btn-sm" id="systemCustomApply">Apply</button>
        </div>
        <p class="card-desc">Lessons completed, quizzes taken, and logins across the whole platform. <span id="systemPeriodLabel">This month.</span></p>
        <canvas id="systemChart" height="90"></canvas>
    </div>

    <div class="card chart-card">
        <div class="chart-toolbar">
            <h3 style="margin-bottom:0;">👤 Individual User Progress</h3>
            <select id="userChartSelect">
                <option value="">Select a user…</option>
                <?php foreach ($allUsersForSelect as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?><?= !empty($u['username']) ? ' (@' . e($u['username']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="chart-toolbar" style="margin-top:-6px;">
            <div class="period-filter" data-chart="user">
                <button type="button" class="period-pill" data-period="day">Day</button>
                <button type="button" class="period-pill" data-period="week">Week</button>
                <button type="button" class="period-pill active" data-period="month">Month</button>
                <button type="button" class="period-pill" data-period="year">Year</button>
                <button type="button" class="period-pill" data-period="custom">Custom</button>
            </div>
        </div>
        <div class="custom-range-row" id="userCustomRange" style="display:none;">
            <input type="date" id="userCustomStart"> <span>to</span> <input type="date" id="userCustomEnd">
            <button type="button" class="btn btn-sm" id="userCustomApply">Apply</button>
        </div>
        <p class="card-desc">Cumulative lessons completed and quiz average for the selected user. <span id="userPeriodLabel">This month.</span></p>
        <canvas id="userChart" height="90"></canvas>
        <p id="userChartEmpty" class="podium-empty">Pick a user above to see their progress trend.</p>
    </div>

    <div class="card">
        <h3>📐 Other Angles</h3>
        <div class="angle-row">
            <div class="angle-card">
                <div class="angle-label">Most Quizzes Passed</div>
                <div class="angle-value"><?= !empty($podiumOtherAngles['most_quizzes_passed']) ? e($podiumOtherAngles['most_quizzes_passed']['full_name']) . ' (' . (int)$podiumOtherAngles['most_quizzes_passed']['c'] . ')' : '—' ?></div>
            </div>
            <div class="angle-card">
                <div class="angle-label">Highest Quiz Average (min. 3 attempts)</div>
                <div class="angle-value"><?= !empty($podiumOtherAngles['highest_quiz_avg']) ? e($podiumOtherAngles['highest_quiz_avg']['full_name']) . ' (' . round((float)$podiumOtherAngles['highest_quiz_avg']['avg_pct']) . '%)' : '—' ?></div>
            </div>
            <div class="angle-card">
                <div class="angle-label">Most Active Today</div>
                <div class="angle-value"><?= !empty($podiumOtherAngles['most_active_today']) ? e($podiumOtherAngles['most_active_today']['full_name']) . ' (' . (int)$podiumOtherAngles['most_active_today']['c'] . ' actions)' : '—' ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($dashboardSecurityOverview)):
        $concurrentCount = count(array_filter($dashboardSecurityOverview, fn($r) => $r['concurrent']));
        $sequentialCount = count($dashboardSecurityOverview) - $concurrentCount;
    ?>
    <div class="security-warning-banner">
        <div class="security-warning-icon">🛡️</div>
        <div class="security-warning-body">
            <h3>Multi-Device Activity Detected <span style="font-weight:400;font-size:13px;color:var(--text-light);">(last 30 days)</span></h3>
            <p>
                <?php if ($concurrentCount > 0): ?>
                    <strong style="color:var(--danger);"><?= $concurrentCount ?> account<?= $concurrentCount === 1 ? '' : 's' ?></strong> showed devices switching within minutes of each other (possible simultaneous/shared login).
                <?php endif; ?>
                <?php if ($sequentialCount > 0): ?>
                    <?= $concurrentCount > 0 ? ' Additionally, ' : '' ?><strong><?= $sequentialCount ?> account<?= $sequentialCount === 1 ? '' : 's' ?></strong> used multiple devices at different times (often just phone + laptop — not inherently suspicious).
                <?php endif; ?>
            </p>
            <div class="flex" style="margin-top:10px;">
                <?php foreach (array_slice($dashboardSecurityOverview, 0, 8) as $row): ?>
                    <span class="device-pill<?= $row['concurrent'] ? ' device-pill-danger' : '' ?>">
                        <?= $row['concurrent'] ? '⚠️' : '👤' ?> <?= e($row['user']['full_name']) ?> — <?= $row['device_count'] ?> devices
                    </span>
                <?php endforeach; ?>
            </div>
            <a href="?tab=security" class="btn btn-sm" style="margin-top:12px;">🛡️ Review in Security Tab →</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid">
        <div class="stat-card"><div class="stat-value"><?= $totalUsers ?></div><div class="stat-label">Total Users</div></div>
        <div class="stat-card"><div class="stat-value"><?= $activeUsers ?></div><div class="stat-label">Active Users</div></div>
        <div class="stat-card"><div class="stat-value"><?= $totalSubjects ?></div><div class="stat-label">Subjects</div></div>
        <div class="stat-card"><div class="stat-value"><?= $totalContents ?></div><div class="stat-label">Lessons</div></div>
        <div class="stat-card"><div class="stat-value"><?= $totalQuizzes ?></div><div class="stat-label">Quizzes</div></div>
        <div class="stat-card"><div class="stat-value"><?= $totalExams ?></div><div class="stat-label">Monthly Exams</div></div>
        <div class="stat-card"><div class="stat-value"><?= $avgProgress ?>%</div><div class="stat-label">Avg Lesson Progress</div></div>
    </div>
    
    <div class="card">
        <h3>⚡ Quick Actions</h3>
        <div class="flex" style="margin-top:16px;">
            <a href="?tab=users" class="btn">Manage Users</a>
            <a href="?tab=quizzes" class="btn btn-outline">View Quizzes</a>
            <a href="?tab=exams" class="btn btn-outline">View Exams</a>
            <a href="?tab=logs" class="btn btn-outline">View Logs</a>
            <a href="?tab=reset" class="btn btn-outline">Data Reset</a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Users Tab -->
    <?php if ($tab === 'users'): ?>
    <div class="card">
        <h3>User Management</h3>
        <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Lessons</th><th>Quizzes</th><th>Exams</th><th>Points</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= !empty($u['username']) ? '@' . e($u['username']) : '—' ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge <?= $u['role']==='admin'?'badge-warning':'' ?>"><?= $u['role'] ?></span></td>
                    <td><?= $u['lessons_completed'] ?>/<?= $u['lessons_started'] ?></td>
                    <td><?= $u['quizzes_taken'] ?> (<?= $u['quiz_avg'] !== null ? round((float)$u['quiz_avg']) . '%' : '—' ?>)</td>
                    <td><?= $u['exams_taken'] ?></td>
                    <td><?= number_format((float)($u['leaderboard_points']??0)) ?></td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Impersonate this user?')">
                            <input type="hidden" name="action" value="impersonate">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button class="btn btn-sm btn-outline">👤 Impersonate</button>
                        </form>
                        <button class="btn btn-sm btn-outline" onclick="openResetModal(<?= $u['id'] ?>, '<?= e($u['full_name']) ?>')">🔄 Reset</button>
                        <button class="btn btn-sm btn-outline" onclick="openPasswordModal(<?= $u['id'] ?>, '<?= e($u['full_name']) ?>')">🔑 Password</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Quizzes Tab -->
    <?php if ($tab === 'quizzes'): ?>
    <div class="card">
        <h3>Published Quizzes</h3>
        <table>
            <thead><tr><th>ID</th><th>Title</th><th>Subject</th><th>Questions</th><th>Participants</th><th>Top Score</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($quizzes as $q): ?>
            <tr>
                <td><?= $q['id'] ?></td>
                <td><?= e($q['title']) ?></td>
                <td><?= e($q['subject_name']??'—') ?></td>
                <td><?= $q['question_count'] ?></td>
                <td><?= $q['participants'] ?></td>
                <td><?= $q['top_score'] ? round((float)$q['top_score']).'%' : '—' ?></td>
                <td><a href="?tab=quizzes&detail=quiz&id=<?= $q['id'] ?>" class="btn btn-sm">🔍 Details</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Exams Tab -->
    <?php if ($tab === 'exams'): ?>
    <div class="card">
        <h3>Monthly Exams</h3>
        <table>
            <thead><tr><th>ID</th><th>Title</th><th>Subject</th><th>Questions</th><th>Participants</th><th>Top Score</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($exams as $e): ?>
            <tr>
                <td><?= $e['id'] ?></td>
                <td><?= e($e['title']) ?></td>
                <td><?= e($e['subject_name']??'—') ?></td>
                <td><?= $e['question_count'] ?></td>
                <td><?= $e['participants'] ?></td>
                <td><?= $e['top_score'] ? round((float)$e['top_score']).'%' : '—' ?></td>
                <td><a href="?tab=exams&detail=exam&id=<?= $e['id'] ?>" class="btn btn-sm">🔍 Details</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Quiz/Exam Detail View (with images) -->
    <?php if ($detailType && $detailData): ?>
    <div class="card">
        <h3><?= $detailType === 'quiz' ? 'Quiz' : 'Exam' ?> Details: <?= e($detailData['title']) ?></h3>
        <p><strong>Subject:</strong> <?= e($detailData['subject_name']??'General') ?> | <strong>Questions:</strong> <?= count($questions) ?></p>
        
        <h4>Questions & Correct Answers</h4>
        <?php foreach ($questions as $idx => $q): ?>
        <div style="background:var(--surface-2); padding:16px; border-radius:8px; margin-bottom:12px;">
            <p><strong>Q<?= $idx+1 ?>:</strong> <?= e($q['question_text']) ?></p>
            <?php if (!empty($q['image_path'])): ?>
                <img src="<?= e($q['image_path']) ?>" class="question-img" alt="Question image">
            <?php endif; ?>
            
            <?php if ($detailType === 'quiz'): ?>
                <p><span style="color:var(--brand);">✓ Correct:</span> 
                    <?php 
                    $correctTexts = [];
                    foreach ($q['options'] as $opt) {
                        if ($opt['is_correct']) $correctTexts[] = $opt['option_text'];
                    }
                    echo e(implode(' / ', $correctTexts) ?: 'N/A');
                    ?>
                </p>
                <div style="margin-top:8px;"><strong>Options:</strong>
                    <ul style="list-style:none; padding-left:0;">
                    <?php foreach ($q['options'] as $opt): ?>
                        <li style="margin-bottom:4px;">
                            <?= $opt['is_correct'] ? '✅' : '❌' ?> <?= e($opt['option_text']) ?>
                            <?php if (!empty($opt['image_path'])): ?>
                                <img src="<?= e($opt['image_path']) ?>" class="option-img" alt="Option image">
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <?php if ($q['question_type'] !== 'explanation'): ?>
                    <p><span style="color:var(--brand);">✓ Correct:</span> 
                        <?php 
                        $correctTexts = [];
                        foreach ($q['options'] as $opt) {
                            if ($opt['is_correct']) $correctTexts[] = $opt['option_text'];
                        }
                        echo e(implode(' / ', $correctTexts) ?: 'N/A');
                        ?>
                    </p>
                    <div><strong>Options:</strong>
                        <ul style="list-style:none;">
                        <?php foreach ($q['options'] as $opt): ?>
                            <li><?= $opt['is_correct'] ? '✅' : '❌' ?> <?= e($opt['option_text']) ?>
                                <?php if (!empty($opt['image_path'])): ?>
                                    <img src="<?= e($opt['image_path']) ?>" class="option-img" alt="Option image">
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <p><span style="color:var(--brand);">Expected Answer:</span> <?= e($q['expected_answer'] ?? 'N/A') ?></p>
                <?php endif; ?>
                <?php if (!empty($q['explanation'])): ?>
                    <p><small>💡 <?= e($q['explanation']) ?></small></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        
        <h4>Participants & Scores</h4>
        <table>
            <thead><tr><th>User</th><th>Score</th><th>Percentage</th><th>Completed</th></tr></thead>
            <tbody>
            <?php foreach ($participants as $p): ?>
            <tr>
                <td><?= e($p['full_name']) ?></td>
                <td><?= $p['score'] ?>/<?= $p['total_points'] ?></td>
                <td><?= round((float)$p['percentage']) ?>%</td>
                <td><?= fmtDate($p['completed_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <a href="?tab=<?= $tab ?>" class="btn" style="margin-top:16px;">← Back</a>
    </div>
    <?php endif; ?>
    
    <!-- Content Tab (Subjects with lessons) -->
    <?php if ($tab === 'contents'): ?>
    <div class="card">
        <h3>Subjects & Lesson Progress</h3>
        <table>
            <thead><tr><th>Subject</th><th>Lessons</th><th>Avg Progress</th></tr></thead>
            <tbody>
            <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?= e($s['name']) ?> <?= e($s['icon'] ?? '') ?></td>
                <td><?= $s['lesson_count'] ?></td>
                <td><?= progressBar($s['avg_progress'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Subjects Tab -->
    <?php if ($tab === 'subjects'): ?>
    <div class="card">
        <h3>All Subjects</h3>
        <table>
            <thead><tr><th>ID</th><th>Name</th><th>Slug</th><th>Icon</th><th>Active</th></tr></thead>
            <tbody>
            <?php foreach ($allSubjects as $s): ?>
            <tr>
                <td><?= $s['id'] ?></td>
                <td><?= e($s['name']) ?></td>
                <td><?= e($s['slug']) ?></td>
                <td><?= e($s['icon'] ?? '—') ?></td>
                <td><?= $s['is_active'] ? '✅' : '❌' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Categories Tab -->
    <?php if ($tab === 'categories'): ?>
    <div class="card">
        <h3>Categories</h3>
        <table>
            <thead><tr><th>ID</th><th>Name</th><th>Subject</th><th>Slug</th><th>Icon</th><th>Active</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
            <tr>
                <td><?= $c['id'] ?></td>
                <td><?= e($c['name']) ?></td>
                <td><?= e($c['subject_name']) ?></td>
                <td><?= e($c['slug']) ?></td>
                <td><?= e($c['icon'] ?? '—') ?></td>
                <td><?= $c['is_active'] ? '✅' : '❌' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Leaderboards Tab -->
    <?php if ($tab === 'leaderboards'): ?>
    <div class="grid" style="grid-template-columns:1fr 1fr;">
        <div class="card">
            <h4>🏆 Overall Leaderboard</h4>
            <ol style="margin-left:20px;">
                <?php foreach ($topOverall as $u): ?>
                <li><?= e($u['full_name']) ?> — <?= number_format((float)$u['points']) ?> pts</li>
                <?php endforeach; ?>
            </ol>
        </div>
        <div class="card">
            <h4>📝 Lesson Mode</h4>
            <ol><?php foreach ($topLesson as $u): ?><li><?= e($u['full_name']) ?> — <?= number_format((float)$u['total_points']) ?> pts</li><?php endforeach; ?></ol>
        </div>
        <div class="card">
            <h4>🏁 Competition Mode</h4>
            <ol><?php foreach ($topCompetition as $u): ?><li><?= e($u['full_name']) ?> — <?= number_format((float)$u['total_points']) ?> pts</li><?php endforeach; ?></ol>
        </div>
        <div class="card">
            <h4>🔍 Self Mode</h4>
            <ol><?php foreach ($topSelf as $u): ?><li><?= e($u['full_name']) ?> — <?= number_format((float)$u['points']) ?> pts</li><?php endforeach; ?></ol>
        </div>
        <div class="card">
            <h4>📅 Monthly Exams</h4>
            <ol><?php foreach ($topExams as $u): ?><li><?= e($u['full_name']) ?> — <?= number_format((float)$u['points']) ?> pts</li><?php endforeach; ?></ol>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Media Tab -->
    <?php if ($tab === 'media'): ?>
    <div class="card">
        <h3>Media Library</h3>
        <div class="grid" style="margin-bottom:20px;">
            <div class="stat-card"><div class="stat-value"><?= $mediaStats['total'] ?></div><div class="stat-label">Total</div></div>
            <div class="stat-card"><div class="stat-value"><?= $mediaStats['images'] ?></div><div class="stat-label">Images</div></div>
            <div class="stat-card"><div class="stat-value"><?= $mediaStats['videos'] ?></div><div class="stat-label">Videos</div></div>
            <div class="stat-card"><div class="stat-value"><?= $mediaStats['audio'] ?></div><div class="stat-label">Audio</div></div>
            <div class="stat-card"><div class="stat-value"><?= $mediaStats['docs'] ?></div><div class="stat-label">Documents</div></div>
        </div>
        <table>
            <thead><tr><th>ID</th><th>Title</th><th>Type</th><th>Uploaded By</th><th>Views</th><th>Downloads</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($mediaItems as $m): ?>
            <tr>
                <td><?= $m['id'] ?></td>
                <td><?= e($m['title']) ?></td>
                <td><?= $m['media_type'] ?></td>
                <td><?= e($m['uploaded_by_name']??'—') ?></td>
                <td><?= $m['view_count'] ?></td>
                <td><?= $m['download_count'] ?></td>
                <td><?= fmtDate($m['created_at']) ?></td>
                <td>
                    <a href="<?= e($m['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline">View</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this media?')">
                        <input type="hidden" name="action" value="delete_media">
                        <input type="hidden" name="media_id" value="<?= $m['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Notifications Tab -->
    <?php if ($tab === 'notifications'): ?>
    <div class="card">
        <h3>Send Notification</h3>
        <form method="post">
            <input type="hidden" name="action" value="send_notification">
            <div class="grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" required>
                        <option value="system">System</option>
                        <option value="announcement">Announcement</option>
                        <option value="quiz">Quiz</option>
                        <option value="exam">Exam</option>
                        <option value="lesson">Lesson</option>
                        <option value="multimedia">Multimedia</option>
                        <option value="leaderboard">Leaderboard</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Target</label>
                    <select name="target" id="notifTarget" required>
                        <option value="all">All Users</option>
                        <option value="single">Single User</option>
                    </select>
                </div>
            </div>
            <div class="form-group" id="singleUserSelect" style="display:none;">
                <label>Select User</label>
                <select name="target_user_id">
                    <option value="">-- Select User --</option>
                    <?php foreach ($allUsersForSelect as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?><?= !empty($u['username']) ? ' (@' . e($u['username']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Title</label>
                <input type="text" name="title" required>
            </div>
            <div class="form-group">
                <label>Message</label>
                <textarea name="message" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label>Link (optional)</label>
                <input type="text" name="link" placeholder="/page.php">
            </div>
            <button type="submit" class="btn">Send Notification</button>
        </form>
        
        <h3 style="margin-top:30px;">Recent Notifications</h3>
        <table>
            <thead><tr><th>User</th><th>Type</th><th>Title</th><th>Message</th><th>Sent</th><th>Read</th></tr></thead>
            <tbody>
            <?php foreach ($recentNotifications as $n): ?>
            <tr>
                <td><?= e($n['user_name']) ?></td>
                <td><?= $n['type'] ?></td>
                <td><?= e($n['title']) ?></td>
                <td><?= e(substr($n['message'], 0, 50)) ?>…</td>
                <td><?= fmtDate($n['created_at']) ?></td>
                <td><?= $n['is_read'] ? '✅' : '❌' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Announcements Tab -->
    <?php if ($tab === 'announcements'): ?>
    <div class="card">
        <h3>Announcements</h3>
        <button class="btn" onclick="document.getElementById('newAnnouncementForm').style.display='block'">+ New Announcement</button>
        <div id="newAnnouncementForm" style="display:none; margin-top:20px; padding:16px; background:var(--primary-soft); border-radius:8px;">
            <form method="post">
                <input type="hidden" name="action" value="create_announcement">
                <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
                <div class="form-group"><label>Message</label><textarea name="message" rows="3" required></textarea></div>
                <div class="form-group"><label>Audience</label>
                    <select name="audience_role">
                        <option value="all">All</option><option value="owner">Owner</option><option value="admin">Admin</option>
                        <option value="sheikh">Sheikh</option><option value="user">User</option>
                    </select>
                </div>
                <div class="form-group"><label><input type="checkbox" name="is_published" checked> Published</label></div>
                <div class="form-group"><label>Starts At</label><input type="datetime-local" name="starts_at"></div>
                <div class="form-group"><label>Ends At</label><input type="datetime-local" name="ends_at"></div>
                <button type="submit" class="btn">Create</button>
                <button type="button" class="btn btn-outline" onclick="this.closest('#newAnnouncementForm').style.display='none'">Cancel</button>
            </form>
        </div>
        <table style="margin-top:20px;">
            <thead><tr><th>ID</th><th>Title</th><th>Audience</th><th>Published</th><th>Created By</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($announcements as $a): ?>
            <tr>
                <td><?= $a['id'] ?></td>
                <td><?= e($a['title']) ?></td>
                <td><?= $a['audience_role'] ?></td>
                <td><?= $a['is_published'] ? '✅' : '❌' ?></td>
                <td><?= e($a['created_by_name']??'—') ?></td>
                <td>
                    <button class="btn btn-sm btn-outline" onclick="editAnnouncement(<?= htmlspecialchars(json_encode($a)) ?>)">Edit</button>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="action" value="delete_announcement">
                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Help Tab -->
    <?php if ($tab === 'help'): ?>
    <div class="card">
        <h3>Help Categories</h3>
        <form method="post" style="margin-bottom:20px;">
            <input type="hidden" name="action" value="create_help_category">
            <div class="flex"><input type="text" name="name" placeholder="Category name" required><input type="text" name="slug" placeholder="Slug"><input type="text" name="icon" placeholder="Icon (emoji)"><button class="btn">Add Category</button></div>
        </form>
        <ul><?php foreach ($helpCategories as $cat): ?><li><?= e($cat['name']) ?> (<?= e($cat['slug']) ?>)</li><?php endforeach; ?></ul>
        
        <h3 style="margin-top:30px;">Help Articles</h3>
        <form method="post">
            <input type="hidden" name="action" value="create_help_article">
            <div class="grid" style="grid-template-columns:1fr 1fr;">
                <select name="category_id" required>
                    <option value="">Select Category</option>
                    <?php foreach ($helpCategories as $cat): ?><option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="question" placeholder="Question" required>
                <input type="text" name="slug" placeholder="Slug">
                <textarea name="answer" placeholder="Answer" rows="3"></textarea>
                <button class="btn">Add Article</button>
            </div>
        </form>
        <table style="margin-top:20px;">
            <thead><tr><th>ID</th><th>Category</th><th>Question</th><th>Views</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($helpArticles as $ha): ?>
            <tr>
                <td><?= $ha['id'] ?></td>
                <td><?= e($ha['category_name']) ?></td>
                <td><?= e($ha['question']) ?></td>
                <td><?= $ha['view_count'] ?></td>
                <td>
                    <form method="post" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="action" value="delete_help_article">
                        <input type="hidden" name="id" value="<?= $ha['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Support Tab -->
    <?php if ($tab === 'support'): ?>
    <div class="card">
        <h3>Support Tickets</h3>
        <?php foreach ($tickets as $t): ?>
        <div style="border:1px solid var(--border); border-radius:8px; padding:16px; margin-bottom:16px;">
            <p><strong>#<?= $t['id'] ?> - <?= e($t['subject']) ?></strong> by <?= e($t['user_name']) ?> (<?= $t['status'] ?>)</p>
            <p><?= nl2br(e($t['message'])) ?></p>
            <?php if ($t['admin_response']): ?>
                <p style="background:var(--brand-soft); padding:8px; border-radius:8px;"><strong>Response:</strong> <?= nl2br(e($t['admin_response'])) ?></p>
            <?php endif; ?>
            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="action" value="respond_ticket">
                <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                <textarea name="admin_response" placeholder="Write response..." rows="2" style="margin-bottom:8px;"></textarea>
                <select name="status">
                    <option value="in_progress" <?= $t['status']=='in_progress'?'selected':'' ?>>In Progress</option>
                    <option value="resolved" <?= $t['status']=='resolved'?'selected':'' ?>>Resolved</option>
                    <option value="closed" <?= $t['status']=='closed'?'selected':'' ?>>Closed</option>
                </select>
                <button class="btn btn-sm">Send Response</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Settings Tab -->
    <?php if ($tab === 'settings'): ?>
    <div class="card">
        <h3>System Settings</h3>
        <form method="post">
            <input type="hidden" name="action" value="update_settings">
            <div class="form-group"><label>Site Name</label><input type="text" name="site_name" value="<?= e($settings['site_name']??'MyIslam') ?>"></div>
            <div class="form-group"><label>Default Language</label><input type="text" name="default_language" value="<?= e($settings['default_language']??'EN') ?>"></div>
            <div class="form-group"><label>Default Theme</label><select name="default_theme"><option value="light" <?= ($settings['default_theme']??'')=='light'?'selected':'' ?>>Light</option><option value="dark" <?= ($settings['default_theme']??'')=='dark'?'selected':'' ?>>Dark</option></select></div>
            <div class="form-group"><label><input type="checkbox" name="allow_user_registration" <?= ($settings['allow_user_registration']??1)?'checked':'' ?>> Allow User Registration</label></div>
            <div class="form-group"><label><input type="checkbox" name="enable_announcements" <?= ($settings['enable_announcements']??1)?'checked':'' ?>> Enable Announcements</label></div>
            <div class="form-group"><label><input type="checkbox" name="enable_leaderboard" <?= ($settings['enable_leaderboard']??1)?'checked':'' ?>> Enable Leaderboard</label></div>
            <button type="submit" class="btn">Save Settings</button>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- Audit Logs Tab -->
    <?php if ($tab === 'audit'): ?>
    <div class="card">
        <h3>🔍 Audit Logs (Detailed)</h3>
        <p style="color:var(--text-light); margin-bottom:14px; font-size:13px;">Location is resolved from IP address (city-level accuracy at best) — not precise GPS.</p>
        <?php foreach ($auditLogs as $log): ?>
        <?php $loc = $ipLocations[$log['ip_address']] ?? null; ?>
        <div class="log-entry">
            <strong><?= fmtDate($log['created_at']) ?></strong>
            [<?= e($log['actor_name']??'System') ?>]
            <?= e($log['action']) ?> on <?= e($log['target_type']) ?> #<?= (int)$log['target_id'] ?>
            <?= $log['details'] ? ' — ' . e($log['details']) : '' ?>
            <span class="log-meta">IP: <?= e($log['ip_address']) ?></span>
            <?php if ($loc): ?><span class="geo-pill">📍 <?= e(formatLocation($loc)) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($auditLogs)): ?><p class="podium-empty">No audit log entries yet.</p><?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Logs Tab (User Activity) -->
    <?php if ($tab === 'logs'): ?>
    <div class="card">
        <h3>📋 Recent User Activity Logs</h3>
        <p style="color:var(--text-light); margin-bottom:14px; font-size:13px;">Location is resolved from IP address (city-level accuracy at best) — not precise GPS.</p>
        <?php foreach ($logs as $log): ?>
        <?php $loc = $ipLocations[$log['ip_address']] ?? null; ?>
        <div class="log-entry">
            <strong><?= fmtDate($log['created_at']) ?></strong>
            [<?= e($log['full_name']??'System') ?>]
            <?= e($log['action']) ?> — <?= e($log['description']) ?>
            <span class="log-meta">IP: <?= e($log['ip_address']) ?></span>
            <?php if ($loc): ?><span class="geo-pill">📍 <?= e(formatLocation($loc)) ?></span><?php endif; ?>
            <?php if (isset($logsMultiDeviceUserIds[$log['user_id']])): ?>
                <span class="device-pill">🖥️ <?= (int)$logsMultiDeviceUserIds[$log['user_id']] ?> devices (30d)</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><p class="podium-empty">No activity log entries yet.</p><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Security Tab: device sessions, concurrent-usage detection, time spent -->
    <?php if ($tab === 'security'): ?>
    <div class="card">
        <h3>🛡️ Security &amp; Device Activity</h3>
        <p style="color:var(--text-light); margin-top:8px;">
            Sessions are grouped by device fingerprint (IP address + browser). An account is flagged
            <span class="concurrent-flag" style="margin-left:0;">⚠ CONCURRENT</span> when it switched between two
            different devices within <?= $securityWindowDays >= 1 ? '10 minutes' : '' ?> — a possible sign of a
            shared/simultaneous login. A <span class="sequential-flag" style="margin-left:0;">DIFFERENT TIMES</span>
            flag just means the same person likely used more than one device (e.g. phone then laptop) — normal
            behaviour, not by itself a concern.
        </p>
        <form method="get" style="margin-top:14px;display:flex;gap:10px;align-items:center;">
            <input type="hidden" name="tab" value="security">
            <label for="sec_days" style="margin:0;">Window:</label>
            <select name="days" id="sec_days" onchange="this.form.submit()" style="width:auto;">
                <option value="7" <?= $securityWindowDays===7?'selected':'' ?>>Last 7 days</option>
                <option value="30" <?= $securityWindowDays===30?'selected':'' ?>>Last 30 days</option>
                <option value="90" <?= $securityWindowDays===90?'selected':'' ?>>Last 90 days</option>
            </select>
        </form>
    </div>

    <?php if (empty($securityOverview)): ?>
    <div class="card"><p class="podium-empty">No accounts with multiple devices in this window.</p></div>
    <?php endif; ?>

    <?php foreach ($securityOverview as $row): ?>
    <div class="device-session-row<?= $row['concurrent'] ? ' concurrent' : '' ?>">
        <div class="device-session-user">
            <?= e($row['user']['full_name']) ?>
            <span style="font-weight:400;color:var(--text-light);font-size:13px;">(<?= e($row['user']['email']) ?>)</span>
            <?php if ($row['concurrent']): ?>
                <span class="concurrent-flag">⚠ Concurrent</span>
            <?php else: ?>
                <span class="sequential-flag">Different Times</span>
            <?php endif; ?>
        </div>
        <div class="device-session-meta">
            User ID #<?= (int)$row['user_id'] ?> · Role: <?= e($row['user']['role']) ?>
            · <?= $row['device_count'] ?> distinct devices
            · Total time in system:
            <?php
                $secs = (int)$row['total_time_spent'];
                echo $secs > 0 ? e(sprintf('%dh %dm', intdiv($secs, 3600), intdiv($secs % 3600, 60))) : '—';
            ?>
        </div>

        <?php foreach ($row['devices'] as $d):
            $parsed = parseUserAgent($d['user_agent']);
            $loc = resolveIpLocation($conn, $d['ip_address']);
            $geoLabel = formatLocation($loc);
        ?>
        <div class="device-session-item">
            <span>
                🌐 <strong><?= e($d['ip_address']) ?></strong>
                &nbsp;·&nbsp; <?= e($parsed['browser']) ?> on <?= e($parsed['os']) ?>
                &nbsp;·&nbsp; <?= (int)$d['hit_count'] ?> action<?= (int)$d['hit_count'] === 1 ? '' : 's' ?>
            </span>
            <span>
                <?php if ($geoLabel && $geoLabel !== 'Unknown'): ?><span class="geo-pill">📍 <?= e($geoLabel) ?></span><?php endif; ?>
                &nbsp; first: <?= fmtDate($d['first_seen']) ?> &nbsp;·&nbsp; last: <?= fmtDate($d['last_seen']) ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Data Reset Tab (merged from admin_reset_user_progress.php) -->
    <?php if ($tab === 'reset'): ?>
    <div class="card">
        <h3>📊 Current Data Volumes</h3>
        <div class="reset-grid">
            <div class="reset-stat"><div class="value"><?= (int)($resetStats['users_with_progress']??0) ?></div><div class="label">Users With Progress</div></div>
            <div class="reset-stat"><div class="value"><?= (int)($resetStats['progress']??0) ?></div><div class="label">Content Progress Rows</div></div>
            <div class="reset-stat"><div class="value"><?= (int)($resetStats['quiz_attempts']??0) ?></div><div class="label">Quiz Attempts</div></div>
            <div class="reset-stat"><div class="value"><?= (int)($resetStats['monthly_exams']??0) ?></div><div class="label">Monthly Exam Attempts</div></div>
            <div class="reset-stat"><div class="value"><?= (int)($resetStats['leaderboard']??0) ?></div><div class="label">Leaderboard Rows</div></div>
            <div class="reset-stat"><div class="value"><?= (int)($resetStats['cert_attempts']??0) ?></div><div class="label">Cert. Exam Attempts</div></div>
            <div class="reset-stat"><div class="value"><?= (int)($resetStats['certificates']??0) ?></div><div class="label">Certificates</div></div>
        </div>
    </div>

    <div class="danger-zone">
        <h3>⚠️ Danger Zone — Progress Reset</h3>
        <p style="margin-bottom:14px;">This permanently deletes the data types you select below, either for one user or for <strong>every user in the database</strong>. This does not touch quiz/exam question content — only attempt/progress records. This cannot be undone; consider a database backup first.</p>

        <form method="post" onsubmit="return confirm('This will permanently delete the selected data. Are you absolutely sure?');">
            <input type="hidden" name="action" value="bulk_reset_progress">

            <div class="form-group">
                <label for="reset_scope">Reset Scope</label>
                <select name="scope" id="reset_scope" onchange="document.getElementById('resetUserField').style.display = this.value === 'one' ? 'block' : 'none';">
                    <option value="all">⚠️ All users (entire database)</option>
                    <option value="one">Single user</option>
                </select>
            </div>

            <div class="form-group" id="resetUserField" style="display:none;">
                <label for="reset_target_user_id">Select User</label>
                <select name="target_user_id" id="reset_target_user_id">
                    <option value="">-- Choose a user --</option>
                    <?php foreach ($resetUsersList as $ru): ?>
                        <option value="<?= (int)$ru['id'] ?>"><?= e($ru['full_name']) ?><?= !empty($ru['username']) ? ' (@' . e($ru['username']) . ')' : '' ?> - <?= e($ru['email']) ?> [ID: <?= (int)$ru['id'] ?>]</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Select What to Reset</label>
                <div style="border:1px solid var(--border); border-radius:12px; padding:12px 16px;">
                    <div class="checkbox-row"><input type="checkbox" name="categories[]" value="progress" id="cat_progress"><label for="cat_progress" style="font-weight:normal;">Content Progress</label></div>
                    <div class="checkbox-row"><input type="checkbox" name="categories[]" value="quiz_attempts" id="cat_quiz"><label for="cat_quiz" style="font-weight:normal;">Quiz Attempts &amp; Answers</label></div>
                    <div class="checkbox-row"><input type="checkbox" name="categories[]" value="monthly_exams" id="cat_exams"><label for="cat_exams" style="font-weight:normal;">Monthly Exam Attempts</label></div>
                    <div class="checkbox-row"><input type="checkbox" name="categories[]" value="leaderboard" id="cat_lb"><label for="cat_lb" style="font-weight:normal;">Leaderboard Stats</label></div>
                    <div class="checkbox-row"><input type="checkbox" name="categories[]" value="cert_attempts" id="cat_cert_att"><label for="cat_cert_att" style="font-weight:normal;">Certification Exam Attempts</label></div>
                    <div class="checkbox-row"><input type="checkbox" name="categories[]" value="certificates" id="cat_certs"><label for="cat_certs" style="font-weight:normal;">Certificates</label></div>
                </div>
            </div>

            <div class="form-group">
                <label><input type="checkbox" name="reset_autoincrement" value="1" style="width:auto; margin-right:6px;"> Reset AUTO_INCREMENT to 1 on emptied tables (only applies when resetting for all users)</label>
            </div>

            <div class="form-group">
                <label for="confirm_text">Type <strong>RESET</strong> (all caps) to confirm</label>
                <input type="text" name="confirm_text" id="confirm_text" placeholder="RESET" required autocomplete="off">
            </div>

            <button type="submit" class="btn btn-danger">🗑️ Execute Reset</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- System Health / Error Detection Tab -->
    <?php if ($tab === 'health'): ?>
    <div class="card">
        <h3>🩺 System Diagnostics</h3>
        <p class="card-desc">Live checks run each time this page loads.</p>
        <?php foreach ($healthChecks as $check): ?>
        <div class="health-row">
            <span><span class="health-dot <?= $check['ok'] ? 'ok' : 'bad' ?>"></span> <?= e($check['label']) ?></span>
            <span style="color:var(--text-light);"><?= e($check['detail']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="grid" style="margin-bottom:22px;">
        <div class="stat-card" style="background:var(--danger-soft);"><div class="stat-value" style="color:var(--danger);"><?= (int)$errorStats['error'] ?></div><div class="stat-label">Errors (last 100)</div></div>
        <div class="stat-card" style="background:var(--warning-soft);"><div class="stat-value" style="color:var(--warning);"><?= (int)$errorStats['warning'] ?></div><div class="stat-label">Warnings (last 100)</div></div>
        <div class="stat-card" style="background:var(--accent-soft);"><div class="stat-value" style="color:var(--accent);"><?= (int)$errorStats['notice'] ?></div><div class="stat-label">Notices (last 100)</div></div>
    </div>

    <div class="card">
        <h3>🧾 Recently Logged Application Errors</h3>
        <p class="card-desc">Captured automatically by <code>includes/error_logger.php</code>. Wire <code>installErrorHandlers()</code> into other entry-point scripts (login.php, content.php, quiz_submit.php, etc.) to capture errors from the whole site here, not just the admin panel.</p>
        <?php if (empty($recentSystemErrors)): ?>
            <p class="podium-empty">No application errors logged yet — good sign, or the error_logger isn't wired into other pages yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Location</th><th>User</th></tr></thead>
                <tbody>
                <?php foreach ($recentSystemErrors as $err): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= fmtDate($err['created_at']) ?></td>
                    <td><span class="badge <?= $err['level']==='error'?'badge-danger':($err['level']==='warning'?'badge-warning':'badge-info') ?>"><?= e($err['level']) ?></span></td>
                    <td><?= e($err['message']) ?></td>
                    <td style="color:var(--text-light); font-size:12px;"><?= e(($err['file'] ? basename((string)$err['file']) : '') . ($err['line'] ? ':' . $err['line'] : '')) ?> <?= $err['context'] ? '· ' . e($err['context']) : '' ?></td>
                    <td><?= $err['user_id'] ? '#' . (int)$err['user_id'] : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($errorLogTail)): ?>
    <div class="card">
        <h3>📄 PHP Error Log (last <?= count($errorLogTail) ?> lines)</h3>
        <div class="error-console"><?php foreach ($errorLogTail as $line): ?><?= e($line) ?>
<?php endforeach; ?></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    </div>
</div>

<!-- Modals for Reset & Password -->
<div class="modal-overlay" id="resetModal">
    <div class="modal">
        <div class="modal-header"><h3>Reset User Progress</h3><button class="close-btn" onclick="closeModal()">&times;</button></div>
        <form method="post">
            <input type="hidden" name="action" value="reset_user_progress">
            <input type="hidden" name="user_id" id="resetUserId">
            <p>User: <span id="resetUserName"></span></p>
            <div class="form-group">
                <label>Reset Type</label>
                <select name="reset_type">
                    <option value="overall">Overall (All progress)</option>
                    <option value="quizzes">Quizzes only</option>
                    <option value="exams">Exams only</option>
                    <option value="lessons">Lessons only</option>
                    <option value="leaderboard">Leaderboard stats only</option>
                </select>
            </div>
            <button type="submit" class="btn btn-danger">Confirm Reset</button>
        </form>
    </div>
</div>

<div class="modal-overlay" id="passwordModal">
    <div class="modal">
        <div class="modal-header"><h3>Reset Password</h3><button class="close-btn" onclick="closeModal()">&times;</button></div>
        <form method="post">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="pwUserId">
            <p>User: <span id="pwUserName"></span></p>
            <div class="form-group">
                <label>New Password (min 6 chars)</label>
                <input type="password" name="new_password" required minlength="6">
            </div>
            <button type="submit" class="btn btn-warning">Reset Password</button>
        </form>
    </div>
</div>

<script>
(function() {
    const body = document.body;
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('themeToggle');
    const langToggle = document.getElementById('langToggle');
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    const profileArea = document.getElementById('profileArea');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (sidebarOverlay) sidebarOverlay.classList.add('show');
        body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        body.style.overflow = '';
    }

    function applyTheme(isDark) {
        body.classList.toggle('dark-mode', isDark);
        body.classList.toggle('light-mode', !isDark);
        if (toggle) {
            toggle.textContent = isDark ? '☀' : '🌙';
            toggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        }
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            if (sidebar && sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (sidebar && sidebar.classList.contains('open')) closeSidebar();
            if (profileMenu && profileMenu.classList.contains('show')) {
                profileMenu.classList.remove('show');
                profileToggle?.setAttribute('aria-expanded', 'false');
            }
        }
    });

    const savedTheme = localStorage.getItem('theme') || localStorage.getItem('adminTheme');
    applyTheme(savedTheme !== 'light');   // dark by default

    if (toggle) {
        toggle.addEventListener('click', () => {
            const isDark = !body.classList.contains('dark-mode');
            applyTheme(isDark);
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            localStorage.setItem('adminTheme', isDark ? 'dark' : 'light');
            document.documentElement.classList.remove('light-init');
        });
    }

    let currentLang = localStorage.getItem('language') || 'EN';
    if (langToggle) {
        langToggle.textContent = currentLang;
        langToggle.addEventListener('click', () => {
            currentLang = currentLang === 'EN' ? 'SW' : 'EN';
            langToggle.textContent = currentLang;
            localStorage.setItem('language', currentLang);
        });
    }

    if (profileToggle && profileMenu) {
        profileToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = profileMenu.classList.contains('show');
            profileMenu.classList.toggle('show', !isOpen);
            profileToggle.setAttribute('aria-expanded', String(!isOpen));
        });

        document.addEventListener('click', function(e) {
            if (profileArea && !profileArea.contains(e.target)) {
                profileMenu.classList.remove('show');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }
})();

<?php if ($tab === 'dashboard'): ?>
// ===== Dashboard line charts (period-aware: day/week/month/year/custom) =====
(function() {
    const chartColors = { lessons: '#4C9172', quizzes: '#0284c7', logins: '#f59e0b', cumulative: '#4C9172', quizAvg: '#7c3aed' };
    // Chart axis text / grid lines follow the active theme (read on page load)
    if (window.Chart) {
        const cs = getComputedStyle(document.body);
        Chart.defaults.color = cs.getPropertyValue('--muted').trim() || '#94a3b8';
        Chart.defaults.borderColor = cs.getPropertyValue('--line').trim() || '#304150';
    }
    const periodLabels = { day: 'Today.', week: 'Last 7 days.', month: 'This month.', year: 'This year.', custom: 'Custom range.' };

    let systemChartInstance = null;
    let userChartInstance = null;
    let systemPeriod = 'month';
    let userPeriod = 'month';

    function buildUrl(base, params) {
        const q = new URLSearchParams(params);
        return `automation.php?ajax=${base}&${q.toString()}`;
    }

    function loadSystemChart(period, start, end) {
        const params = { period };
        if (period === 'custom' && start && end) { params.start = start; params.end = end; }
        fetch(buildUrl('system_series', params))
            .then(r => r.json())
            .then(data => {
                const ctx = document.getElementById('systemChart');
                if (!ctx) return;
                const label = document.getElementById('systemPeriodLabel');
                if (label) label.textContent = period === 'custom' && start && end ? `${start} to ${end}.` : (periodLabels[period] || '');
                if (systemChartInstance) systemChartInstance.destroy();
                systemChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [
                            { label: 'Lessons Completed', data: data.lessons, borderColor: chartColors.lessons, backgroundColor: chartColors.lessons + '22', tension: 0.35, fill: true },
                            { label: 'Quizzes Taken', data: data.quizzes, borderColor: chartColors.quizzes, backgroundColor: chartColors.quizzes + '22', tension: 0.35, fill: true },
                            { label: 'Logins', data: data.logins, borderColor: chartColors.logins, backgroundColor: chartColors.logins + '22', tension: 0.35, fill: true }
                        ]
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { position: 'bottom' } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });
            })
            .catch(() => {});
    }

    function loadUserChart(uid, period, start, end) {
        const userChartCanvas = document.getElementById('userChart');
        const userChartEmpty = document.getElementById('userChartEmpty');
        if (!uid) {
            if (userChartInstance) { userChartInstance.destroy(); userChartInstance = null; }
            userChartCanvas.style.display = 'none';
            userChartEmpty.style.display = 'block';
            return;
        }
        const params = { user_id: uid, period };
        if (period === 'custom' && start && end) { params.start = start; params.end = end; }
        fetch(buildUrl('user_series', params))
            .then(r => r.json())
            .then(data => {
                userChartCanvas.style.display = 'block';
                userChartEmpty.style.display = 'none';
                const label = document.getElementById('userPeriodLabel');
                if (label) label.textContent = period === 'custom' && start && end ? `${start} to ${end}.` : (periodLabels[period] || '');
                if (userChartInstance) userChartInstance.destroy();
                userChartInstance = new Chart(userChartCanvas, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [
                            { label: 'Lessons Completed (cumulative)', data: data.lessons_cumulative, borderColor: chartColors.cumulative, backgroundColor: chartColors.cumulative + '22', tension: 0.35, fill: true, yAxisID: 'y' },
                            { label: 'Quiz Average %', data: data.quiz_avg, borderColor: chartColors.quizAvg, backgroundColor: 'transparent', tension: 0.35, spanGaps: true, yAxisID: 'y1' }
                        ]
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Lessons' }, ticks: { precision: 0 } },
                            y1: { beginAtZero: true, max: 100, position: 'right', title: { display: true, text: 'Quiz Avg %' }, grid: { drawOnChartArea: false } }
                        }
                    }
                });
            })
            .catch(() => {});
    }

    // Wire up period pills for a given chart ("system" or "user")
    function wirePeriodFilter(chartName, onChange) {
        const wrap = document.querySelector(`.period-filter[data-chart="${chartName}"]`);
        const customRow = document.getElementById(chartName + 'CustomRange');
        if (!wrap) return;
        wrap.querySelectorAll('.period-pill').forEach(pill => {
            pill.addEventListener('click', function() {
                wrap.querySelectorAll('.period-pill').forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                const period = this.dataset.period;
                if (period === 'custom') {
                    customRow.style.display = 'flex';
                    return; // wait for Apply click
                }
                customRow.style.display = 'none';
                onChange(period, null, null);
            });
        });
    }

    wirePeriodFilter('system', (period) => { systemPeriod = period; loadSystemChart(period); });
    wirePeriodFilter('user', (period) => {
        userPeriod = period;
        const uid = document.getElementById('userChartSelect')?.value;
        loadUserChart(uid, period);
    });

    const systemApply = document.getElementById('systemCustomApply');
    if (systemApply) {
        systemApply.addEventListener('click', () => {
            const start = document.getElementById('systemCustomStart').value;
            const end = document.getElementById('systemCustomEnd').value;
            if (!start || !end) return;
            systemPeriod = 'custom';
            loadSystemChart('custom', start, end);
        });
    }

    const userApply = document.getElementById('userCustomApply');
    if (userApply) {
        userApply.addEventListener('click', () => {
            const start = document.getElementById('userCustomStart').value;
            const end = document.getElementById('userCustomEnd').value;
            const uid = document.getElementById('userChartSelect')?.value;
            if (!start || !end) return;
            userPeriod = 'custom';
            loadUserChart(uid, 'custom', start, end);
        });
    }

    // Initial loads: both charts default to Month, per their active pills
    loadSystemChart('month');

    const userSelect = document.getElementById('userChartSelect');
    const userChartCanvas = document.getElementById('userChart');
    if (userSelect) {
        userSelect.addEventListener('change', function() {
            loadUserChart(this.value, userPeriod);
        });
        userChartCanvas.style.display = 'none';
    }
})();
<?php endif; ?>

function openResetModal(id, name) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUserName').textContent = name;
    document.getElementById('resetModal').style.display = 'flex';
}
function openPasswordModal(id, name) {
    document.getElementById('pwUserId').value = id;
    document.getElementById('pwUserName').textContent = name;
    document.getElementById('passwordModal').style.display = 'flex';
}
function closeModal() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
}
window.onclick = function(e) {
    if (e.target.classList.contains('modal-overlay')) closeModal();
}

// Toggle single user select in notifications
const targetSelect = document.getElementById('notifTarget');
const singleUserDiv = document.getElementById('singleUserSelect');
if (targetSelect) {
    targetSelect.addEventListener('change', function() {
        singleUserDiv.style.display = this.value === 'single' ? 'block' : 'none';
    });
}

// Edit announcement
function editAnnouncement(data) {
    const form = document.createElement('form');
    form.method = 'post';
    form.innerHTML = `
        <input type="hidden" name="action" value="update_announcement">
        <input type="hidden" name="id" value="${data.id}">
        <div style="margin:10px"><label>Title</label><input name="title" value="${data.title.replace(/"/g,'&quot;')}" required></div>
        <div style="margin:10px"><label>Message</label><textarea name="message" rows="3">${data.message.replace(/</g,'&lt;')}</textarea></div>
        <div style="margin:10px"><label>Audience</label>
            <select name="audience_role">
                <option value="all" ${data.audience_role=='all'?'selected':''}>All</option>
                <option value="owner" ${data.audience_role=='owner'?'selected':''}>Owner</option>
                <option value="admin" ${data.audience_role=='admin'?'selected':''}>Admin</option>
                <option value="sheikh" ${data.audience_role=='sheikh'?'selected':''}>Sheikh</option>
                <option value="user" ${data.audience_role=='user'?'selected':''}>User</option>
            </select>
        </div>
        <div style="margin:10px"><label><input type="checkbox" name="is_published" ${data.is_published=='1'?'checked':''}> Published</label></div>
        <div style="margin:10px"><label>Starts At</label><input type="datetime-local" name="starts_at" value="${data.starts_at ? data.starts_at.replace(' ','T') : ''}"></div>
        <div style="margin:10px"><label>Ends At</label><input type="datetime-local" name="ends_at" value="${data.ends_at ? data.ends_at.replace(' ','T') : ''}"></div>
        <button type="submit" class="btn">Update</button>
        <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
    `;
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.style.display = 'flex';
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.appendChild(form);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    overlay.onclick = (e) => { if(e.target === overlay) overlay.remove(); };
}
</script>
</body>
</html>