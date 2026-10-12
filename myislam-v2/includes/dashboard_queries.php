<?php
declare(strict_types=1);

/**
 * Dashboard queries helper functions
 * All functions required for the user dashboard
 */

/**
 * Get current user data
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return array|null User data or null if not found
 */
function getCurrentUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT 
                id, 
                full_name, 
                email, 
                profile_image, 
                role, 
                is_active,
                is_verified,
                last_login_at,
                created_at
            FROM users 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Set default profile image if none exists
            if (empty($user['profile_image'])) {
                $user['profile_image'] = 'https://via.placeholder.com/80x80.png?text=' . urlencode(substr($user['full_name'], 0, 1));
            }
        }
        
        return $user ?: null;
    } catch (PDOException $e) {
        error_log("Error getting current user: " . $e->getMessage());
        return null;
    }
}

/**
 * Get active announcement for user
 * @param PDO $conn Database connection
 * @param string $audienceRole User role to target
 * @return array|null Announcement data or null
 */
function getActiveAnnouncement(PDO $conn, string $audienceRole = 'all'): ?array {
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            SELECT 
                id, 
                title, 
                message, 
                audience_role,
                created_at
            FROM announcements 
            WHERE is_published = 1 
            AND (audience_role = 'all' OR audience_role = ?)
            AND (starts_at IS NULL OR starts_at <= ?)
            AND (ends_at IS NULL OR ends_at >= ?)
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$audienceRole, $now, $now]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("Error getting announcement: " . $e->getMessage());
        return null;
    }
}

/**
 * Get overall user progress across all content
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return array Progress data with total_items, completed_items, overall_progress
 */
function getOverallProgress(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(DISTINCT content_id) as total_items,
                COUNT(DISTINCT CASE WHEN is_completed = 1 THEN content_id END) as completed_items,
                AVG(progress_percent) as avg_progress
            FROM user_content_progress 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return [
                'total_items' => 0,
                'completed_items' => 0,
                'overall_progress' => 0
            ];
        }
        
        // Calculate overall progress percentage
        if ($result['total_items'] > 0) {
            $result['overall_progress'] = round(($result['completed_items'] / $result['total_items']) * 100);
        } else {
            $result['overall_progress'] = 0;
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error getting overall progress: " . $e->getMessage());
        return [
            'total_items' => 0,
            'completed_items' => 0,
            'overall_progress' => 0
        ];
    }
}

/**
 * Get leaderboard stats for user
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return array|null Leaderboard stats with rank_position
 */
function getLeaderboardStats(PDO $conn, int $userId): ?array {
    try {
        // First, get user's stats
        $stmt = $conn->prepare("
            SELECT 
                user_id,
                total_points,
                quizzes_taken,
                quizzes_completed,
                total_correct_answers,
                total_wrong_answers,
                average_score,
                best_score
            FROM leaderboard_stats 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $stats = $stmt->fetch();
        
        if (!$stats) {
            return null;
        }
        
        // Calculate rank position
        $stmt = $conn->prepare("
            SELECT COUNT(*) + 1 as rank_position
            FROM leaderboard_stats 
            WHERE total_points > (SELECT total_points FROM leaderboard_stats WHERE user_id = ?)
        ");
        $stmt->execute([$userId]);
        $rank = $stmt->fetch();
        
        $stats['rank_position'] = $rank ? (int)$rank['rank_position'] : 1;
        
        return $stats;
    } catch (PDOException $e) {
        error_log("Error getting leaderboard stats: " . $e->getMessage());
        return null;
    }
}

/**
 * Get today's reminder for user
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return array|null Reminder data
 */
function getTodayReminder(PDO $conn, int $userId): ?array {
    try {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare("
            SELECT 
                id, 
                title, 
                message, 
                remind_at,
                related_subject_id,
                related_content_id
            FROM user_reminders 
            WHERE user_id = ? 
            AND status = 'pending'
            AND DATE(remind_at) = ?
            AND remind_at <= ?
            ORDER BY remind_at ASC 
            LIMIT 1
        ");
        $stmt->execute([$userId, $today, $now]);
        $reminder = $stmt->fetch();
        
        // If no reminder for today, return a motivational message
        if (!$reminder) {
            return ['message' => 'No reminders scheduled for today. Set a goal to stay on track!'];
        }
        
        return $reminder;
    } catch (PDOException $e) {
        error_log("Error getting reminder: " . $e->getMessage());
        return ['message' => 'Stay consistent with your learning goals today!'];
    }
}

/**
 * Get resume state for user (where they left off)
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return array|null Resume state data
 */
function getResumeState(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT 
                resume_type,
                resume_label,
                last_subject_id,
                last_category_id,
                last_subcategory_id,
                last_content_id,
                last_quiz_id,
                last_seen_at
            FROM user_learning_state 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $state = $stmt->fetch();
        
        // If no resume state, return default message
        if (!$state) {
            return ['resume_label' => 'Start your first lesson today!'];
        }
        
        // If resume_label is empty, create a meaningful label
        if (empty($state['resume_label'])) {
            if ($state['last_content_id']) {
                $state['resume_label'] = 'Continue your last lesson';
            } elseif ($state['last_quiz_id']) {
                $state['resume_label'] = 'Resume your last quiz';
            } else {
                $state['resume_label'] = 'Continue learning';
            }
        }
        
        return $state;
    } catch (PDOException $e) {
        error_log("Error getting resume state: " . $e->getMessage());
        return ['resume_label' => 'Continue your learning journey'];
    }
}

/**
 * Get user learning streak
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return int Number of consecutive days with activity
 */
function getUserStreak(PDO $conn, int $userId): int {
    try {
        // Get distinct dates of activity in the last 30 days
        $stmt = $conn->prepare("
            SELECT DISTINCT DATE(last_accessed_at) as activity_date
            FROM user_content_progress 
            WHERE user_id = ? 
            AND last_accessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY activity_date DESC
        ");
        $stmt->execute([$userId]);
        $dates = $stmt->fetchAll();
        
        if (empty($dates)) {
            return 0;
        }
        
        // Calculate consecutive days
        $streak = 0;
        $current_date = date('Y-m-d');
        
        foreach ($dates as $index => $date) {
            $activity_date = $date['activity_date'];
            $expected_date = date('Y-m-d', strtotime("-{$index} days", strtotime($current_date)));
            
            if ($activity_date === $expected_date) {
                $streak++;
            } else {
                break;
            }
        }
        
        return $streak;
    } catch (PDOException $e) {
        error_log("Error getting streak: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get today's progress percentage
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return int Progress percentage for today
 */
function getTodayProgress(PDO $conn, int $userId): int {
    try {
        $today = date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT 
                COUNT(CASE WHEN is_completed = 1 THEN 1 END) as completed,
                COUNT(*) as total
            FROM user_content_progress 
            WHERE user_id = ? 
            AND DATE(last_accessed_at) = ?
        ");
        $stmt->execute([$userId, $today]);
        $result = $stmt->fetch();
        
        if ($result && $result['total'] > 0) {
            return (int)round(($result['completed'] / $result['total']) * 100);
        }
        
        // Check if user has any activity today
        $stmt = $conn->prepare("
            SELECT COUNT(*) as has_activity
            FROM user_content_progress 
            WHERE user_id = ? 
            AND DATE(last_accessed_at) = ?
            AND is_started = 1
        ");
        $stmt->execute([$userId, $today]);
        $activity = $stmt->fetch();
        
        if ($activity && $activity['has_activity'] > 0) {
            return 50; // Started but not completed anything
        }
        
        return 0;
    } catch (PDOException $e) {
        error_log("Error getting today's progress: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get all active subjects
 * @param PDO $conn Database connection
 * @return array List of subjects
 */
function getSubjects(PDO $conn): array {
    try {
        $stmt = $conn->prepare("
            SELECT 
                id, 
                name, 
                slug, 
                description, 
                icon, 
                sort_order 
            FROM subjects 
            WHERE is_active = 1 
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting subjects: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user's recent activities
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @param int $limit Number of records to return
 * @return array Recent activities
 */
function getRecentActivities(PDO $conn, int $userId, int $limit = 5): array {
    try {
        $stmt = $conn->prepare("
            SELECT 
                action,
                description,
                created_at
            FROM user_activity_logs 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting recent activities: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user's completed quizzes
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @param int $limit Number of records to return
 * @return array Completed quizzes
 */
function getCompletedQuizzes(PDO $conn, int $userId, int $limit = 3): array {
    try {
        $stmt = $conn->prepare("
            SELECT 
                q.id,
                q.title,
                qa.score,
                qa.correct_answers,
                qa.total_questions,
                qa.completed_at
            FROM quiz_attempts qa
            JOIN quizzes q ON qa.quiz_id = q.id
            WHERE qa.user_id = ? 
            AND qa.status = 'completed'
            ORDER BY qa.completed_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting completed quizzes: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user's achievements/badges (if you have an achievements table)
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return array Achievements
 */
function getUserAchievements(PDO $conn, int $userId): array {
    try {
        // This is a placeholder - create an achievements table if needed
        $achievements = [];
        
        // Check for streak achievement
        $streak = getUserStreak($conn, $userId);
        if ($streak >= 7) {
            $achievements[] = [
                'name' => '7-Day Streak',
                'icon' => '🔥',
                'description' => 'Maintained a 7-day learning streak'
            ];
        }
        if ($streak >= 30) {
            $achievements[] = [
                'name' => 'Monthly Warrior',
                'icon' => '⚔️',
                'description' => '30-day learning streak achieved'
            ];
        }
        
        // Check for quiz achievements
        $quizzes = getCompletedQuizzes($conn, $userId, 100);
        $perfect_scores = 0;
        foreach ($quizzes as $quiz) {
            if ($quiz['score'] >= 90) {
                $perfect_scores++;
            }
        }
        
        if ($perfect_scores >= 5) {
            $achievements[] = [
                'name' => 'Quiz Master',
                'icon' => '📚',
                'description' => 'Scored 90%+ on 5 quizzes'
            ];
        }
        
        return $achievements;
    } catch (Exception $e) {
        error_log("Error getting achievements: " . $e->getMessage());
        return [];
    }
}

/**
 * Update user's learning state (where they left off)
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @param string $resumeType Type ('content' or 'quiz')
 * @param string $resumeLabel Label for resume button
 * @param int|null $contentId Content ID if applicable
 * @param int|null $quizId Quiz ID if applicable
 * @return bool Success status
 */
function updateLearningState(PDO $conn, int $userId, string $resumeType, string $resumeLabel, ?int $contentId = null, ?int $quizId = null): bool {
    try {
        $stmt = $conn->prepare("
            INSERT INTO user_learning_state 
            (user_id, resume_type, resume_label, last_content_id, last_quiz_id, last_seen_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            resume_type = VALUES(resume_type),
            resume_label = VALUES(resume_label),
            last_content_id = VALUES(last_content_id),
            last_quiz_id = VALUES(last_quiz_id),
            last_seen_at = VALUES(last_seen_at),
            updated_at = VALUES(updated_at)
        ");
        return $stmt->execute([$userId, $resumeType, $resumeLabel, $contentId, $quizId]);
    } catch (PDOException $e) {
        error_log("Error updating learning state: " . $e->getMessage());
        return false;
    }
}

/**
 * Record user activity (when they view content)
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @param int $contentId Content ID
 * @param int $subjectId Subject ID
 * @param int $progressPercent Progress percentage (0-100)
 * @return bool Success status
 */
function recordContentProgress(PDO $conn, int $userId, int $contentId, int $subjectId, int $progressPercent = 0): bool {
    try {
        $isStarted = $progressPercent > 0 ? 1 : 0;
        $isCompleted = $progressPercent >= 100 ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare("
            INSERT INTO user_content_progress 
            (user_id, content_id, subject_id, progress_percent, is_started, is_completed, last_accessed_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            progress_percent = GREATEST(progress_percent, VALUES(progress_percent)),
            is_started = CASE WHEN VALUES(is_started) = 1 THEN 1 ELSE is_started END,
            is_completed = CASE WHEN VALUES(is_completed) = 1 THEN 1 ELSE is_completed END,
            last_accessed_at = VALUES(last_accessed_at),
            updated_at = VALUES(updated_at),
            time_spent_seconds = time_spent_seconds + 30
        ");
        
        return $stmt->execute([$userId, $contentId, $subjectId, $progressPercent, $isStarted, $isCompleted, $now]);
    } catch (PDOException $e) {
        error_log("Error recording content progress: " . $e->getMessage());
        return false;
    }
}

/**
 * Get dashboard statistics summary
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return array Dashboard statistics
 */
function getDashboardStats(PDO $conn, int $userId): array {
    return [
        'overall_progress' => getOverallProgress($conn, $userId),
        'streak_days' => getUserStreak($conn, $userId),
        'today_progress' => getTodayProgress($conn, $userId),
        'total_quizzes' => count(getCompletedQuizzes($conn, $userId, 100)),
        'achievements' => getUserAchievements($conn, $userId),
        'recent_activity' => getRecentActivities($conn, $userId, 3)
    ];
}
?>