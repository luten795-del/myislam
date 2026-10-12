<?php
declare(strict_types=1);

function getCurrentUser(PDO $conn, int $userId): ?array {
    $stmt = $conn->prepare("SELECT id, full_name, email, profile_image, role, created_at FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user && empty($user['profile_image'])) {
        $user['profile_image'] = 'https://via.placeholder.com/80x80.png?text=' . urlencode(substr($user['full_name'], 0, 1));
    }
    return $user ?: null;
}

function getActiveAnnouncement(PDO $conn, string $role = 'user'): ?array {
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        SELECT id, title, message, created_at
        FROM announcements
        WHERE is_published = 1
        AND (audience_role = 'all' OR audience_role = ?)
        AND (starts_at IS NULL OR starts_at <= ?)
        AND (ends_at IS NULL OR ends_at >= ?)
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$role, $now, $now]);
    return $stmt->fetch() ?: null;
}

function getOverallProgress(PDO $conn, int $userId): array {
    $stmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT content_id) as total_items,
            COUNT(DISTINCT CASE WHEN is_completed = 1 THEN content_id END) as completed_items,
            SUM(time_spent_seconds) as total_time_spent
        FROM user_content_progress WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    if (!$result || $result['total_items'] == 0) {
        return ['total_items' => 0, 'completed_items' => 0, 'total_time_spent' => 0, 'overall_progress' => 0];
    }
    $result['overall_progress'] = round(($result['completed_items'] / $result['total_items']) * 100);
    return $result;
}

function getLeaderboardStats(PDO $conn, int $userId): ?array {
    $stmt = $conn->prepare("SELECT user_id, total_points, quizzes_taken, quizzes_completed, average_score FROM leaderboard_stats WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch();
    if (!$stats) return null;

    $stmt = $conn->prepare("SELECT COUNT(*) + 1 as rank_position FROM leaderboard_stats WHERE total_points > (SELECT total_points FROM leaderboard_stats WHERE user_id = ?)");
    $stmt->execute([$userId]);
    $rank = $stmt->fetch();
    $stats['rank_position'] = $rank ? (int)$rank['rank_position'] : 1;

    $stmt = $conn->prepare("SELECT COUNT(*) as total_participants FROM leaderboard_stats");
    $stmt->execute();
    $total = $stmt->fetch();
    $stats['total_participants'] = $total ? (int)$total['total_participants'] : 0;
    return $stats;
}

function getTodayReminder(PDO $conn, int $userId): array {
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT message FROM user_reminders
        WHERE user_id = ? AND status = 'pending' AND DATE(remind_at) = ? AND remind_at <= NOW()
        ORDER BY remind_at ASC LIMIT 1
    ");
    $stmt->execute([$userId, $today]);
    $reminder = $stmt->fetch();
    if ($reminder) return $reminder;

    $motivational = [
        '\"The best among you are those who learn the Quran and teach it.\" - Prophet Muhammad (PBUH)',
        '\"Seeking knowledge is an obligation upon every Muslim.\" - Prophet Muhammad (PBUH)',
        'Set a small goal for today. Consistency is key!',
        'Every day is a new opportunity to learn.',
        'Your consistency inspires others. Keep going!'
    ];
    return ['message' => $motivational[array_rand($motivational)], 'is_motivational' => true];
}

function getResumeState(PDO $conn, int $userId): array {
    $stmt = $conn->prepare("
        SELECT resume_label, resume_type, last_content_id, last_quiz_id
        FROM user_learning_state WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $state = $stmt->fetch();
    if ($state && !empty($state['resume_label'])) return $state;

    // Fallback: last accessed content
    $stmt = $conn->prepare("
        SELECT c.title
        FROM user_content_progress ucp
        JOIN contents c ON ucp.content_id = c.id
        WHERE ucp.user_id = ? AND ucp.last_accessed_at IS NOT NULL
        ORDER BY ucp.last_accessed_at DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $last = $stmt->fetch();
    if ($last) return ['resume_label' => $last['title'], 'resume_type' => 'content', 'last_content_id' => $last['id'] ?? null];

    return ['resume_label' => 'Start your first lesson today!', 'resume_type' => 'content'];
}

function getUserStreak(PDO $conn, int $userId): int {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT DATE(created_at)) as streak
        FROM user_activity_logs
        WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return (int)($result['streak'] ?? 0);
}

function getTodayProgress(PDO $conn, int $userId): int {
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total, COUNT(CASE WHEN is_completed = 1 THEN 1 END) as completed
        FROM user_content_progress
        WHERE user_id = ? AND DATE(last_accessed_at) = ?
    ");
    $stmt->execute([$userId, $today]);
    $result = $stmt->fetch();
    if ($result && $result['total'] > 0) return min(100, round(($result['completed'] / $result['total']) * 100));

    $stmt = $conn->prepare("SELECT COUNT(*) FROM user_activity_logs WHERE user_id = ? AND DATE(created_at) = ?");
    $stmt->execute([$userId, $today]);
    return $stmt->fetchColumn() > 0 ? 10 : 0;
}

function getSubjects(PDO $conn, int $userId = 0): array {
    $stmt = $conn->prepare("SELECT id, name, slug, description, icon FROM subjects WHERE is_active = 1 ORDER BY sort_order ASC");
    $stmt->execute();
    $subjects = $stmt->fetchAll();

    if ($userId > 0) {
        foreach ($subjects as &$subject) {
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT content_id) as total, COUNT(DISTINCT CASE WHEN is_completed = 1 THEN content_id END) as completed
                FROM user_content_progress WHERE user_id = ? AND subject_id = ?
            ");
            $stmt->execute([$userId, $subject['id']]);
            $prog = $stmt->fetch();
            if ($prog && $prog['total'] > 0) {
                $subject['progress'] = round(($prog['completed'] / $prog['total']) * 100);
            } else {
                $subject['progress'] = 0;
            }
        }
    }
    return $subjects;
}

function getRecentActivities(PDO $conn, int $userId, int $limit = 5): array {
    $stmt = $conn->prepare("SELECT action, description, created_at FROM user_activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function getUpcomingQuizzes(PDO $conn, int $userId, int $limit = 3): array {
    $stmt = $conn->prepare("
        SELECT q.id, q.title, q.difficulty, q.time_limit_minutes
        FROM quizzes q
        WHERE q.status = 'published'
        AND (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id AND user_id = ? AND status = 'completed') < q.max_attempts
        ORDER BY q.created_at DESC LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}
?>