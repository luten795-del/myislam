<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notifications.php';

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'A';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters ?: 'A';
}

function make_slug(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim((string)$text, '-');
    return $text !== '' ? $text : 'quiz';
}

function tableExists(PDO $conn, string $tableName): bool {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ");
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("tableExists: " . $e->getMessage());
        return false;
    }
}

function columnExists(PDO $conn, string $tableName, string $columnName): bool {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("columnExists: " . $e->getMessage());
        return false;
    }
}

// ========== ADDED: Enhanced explanation grading (copied from monthly exam) ==========
function evaluateExplanationAnswer(
    string $studentAnswer,
    string $expectedAnswer,
    int $maxPoints,
    array $keywords = [],
    float $keywordThreshold = 0.6,
    float $similarityThreshold = 70.0
): array {
    $normalize = function(string $text): string {
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    };

    $student = $normalize($studentAnswer);
    $expected = $normalize($expectedAnswer);

    if (empty($student)) {
        return [
            'is_correct'      => false,
            'points_earned'   => 0,
            'feedback'        => 'No answer provided.',
            'score_breakdown' => ['keywords_matched' => 0, 'similarity' => 0]
        ];
    }

    if (empty($expected) && empty($keywords)) {
        similar_text($student, $expected, $similarity);
        $isCorrect = $similarity >= $similarityThreshold;
        $points = $isCorrect ? $maxPoints : 0;
        return [
            'is_correct'      => $isCorrect,
            'points_earned'   => $points,
            'feedback'        => $isCorrect ? 'Answer accepted (similarity).' : 'Answer does not match expected response.',
            'score_breakdown' => ['similarity' => round($similarity, 1)]
        ];
    }

    $keywordScore = 0;
    $totalWeight = 0;
    $matchedKeywords = [];

    if (empty($keywords) && !empty($expected)) {
        $words = preg_split('/[,\s]+/', $expected);
        foreach (array_unique($words) as $word) {
            if (strlen($word) > 2) {
                $keywords[] = ['word' => $word, 'weight' => 1];
            }
        }
    }

    foreach ($keywords as $kw) {
        $word = is_array($kw) ? ($kw['word'] ?? $kw) : $kw;
        $weight = isset($kw['weight']) ? (float)$kw['weight'] : 1.0;
        $totalWeight += $weight;

        if (strpos($word, ' ') !== false) {
            if (strpos($student, $word) !== false) {
                $keywordScore += $weight;
                $matchedKeywords[] = $word;
            }
        } else {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $student)) {
                $keywordScore += $weight;
                $matchedKeywords[] = $word;
            }
        }
    }

    $keywordPercent = $totalWeight > 0 ? ($keywordScore / $totalWeight) : 0;

    if ($keywordPercent >= $keywordThreshold) {
        return [
            'is_correct'      => true,
            'points_earned'   => $maxPoints,
            'feedback'        => sprintf('Correct. Matched %d%% of required keywords (%s).', round($keywordPercent * 100), implode(', ', $matchedKeywords)),
            'score_breakdown' => [
                'keywords_matched' => round($keywordPercent * 100, 1),
                'matched_list'     => $matchedKeywords
            ]
        ];
    }

    similar_text($student, $expected, $similarity);
    if ($similarity >= $similarityThreshold) {
        return [
            'is_correct'      => true,
            'points_earned'   => $maxPoints,
            'feedback'        => sprintf('Answer accepted (similarity %.1f%%).', $similarity),
            'score_breakdown' => [
                'keywords_matched' => round($keywordPercent * 100, 1),
                'similarity'       => round($similarity, 1)
            ]
        ];
    }

    $feedback = sprintf(
        'Incorrect. Expected keywords: %s. Your answer similarity: %.1f%%.',
        implode(', ', array_column($keywords, 'word')),
        $similarity
    );
    return [
        'is_correct'      => false,
        'points_earned'   => 0,
        'feedback'        => $feedback,
        'score_breakdown' => [
            'keywords_matched' => round($keywordPercent * 100, 1),
            'similarity'       => round($similarity, 1)
        ]
    ];
}
// ================================================================================

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn instanceof PDO) {
    die('Unable to connect to database.');
}

function getAdminUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, profile_image, role, is_active, created_at
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;
        
        $allowedRoles = ['owner', 'admin', 'sheikh'];
        if (!in_array(strtolower((string)($user['role'] ?? '')), $allowedRoles, true)) {
            return null;
        }

        return $user;
    } catch (PDOException $e) {
        error_log("getAdminUser: " . $e->getMessage());
        return null;
    }
}

function getSubjects(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT id, name, slug, icon, description, is_active, sort_order
            FROM subjects
            WHERE is_active = 1
            ORDER BY sort_order ASC, name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("getSubjects: " . $e->getMessage());
        return [];
    }
}

function getCategories(PDO $conn, ?int $subjectId = null): array {
    try {
        $sql = "
            SELECT c.id, c.subject_id, c.name, c.slug, c.description, c.icon, 
                   c.is_active, c.sort_order, s.name as subject_name
            FROM categories c
            LEFT JOIN subjects s ON s.id = c.subject_id
            WHERE c.is_active = 1
        ";
        $params = [];
        if ($subjectId !== null) {
            $sql .= " AND c.subject_id = ?";
            $params[] = $subjectId;
        }
        $sql .= " ORDER BY s.name ASC, c.sort_order ASC, c.name ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("getCategories: " . $e->getMessage());
        return [];
    }
}

function getContents(PDO $conn, ?int $categoryId = null): array {
    try {
        $sql = "
            SELECT c.id, c.title, c.slug, c.subject_id, c.category_id, c.status,
                   s.name as subject_name, s.icon as subject_icon,
                   cat.name as category_name
            FROM contents c
            LEFT JOIN subjects s ON s.id = c.subject_id
            LEFT JOIN categories cat ON cat.id = c.category_id
            WHERE c.status = 'published'
        ";
        $params = [];
        if ($categoryId !== null) {
            $sql .= " AND c.category_id = ?";
            $params[] = $categoryId;
        }
        $sql .= " ORDER BY c.title ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("getContents: " . $e->getMessage());
        return [];
    }
}

function getQuizzes(PDO $conn, ?string $mode = null): array {
    try {
        $hasQuestions = tableExists($conn, 'quiz_questions');
        $hasAttempts = tableExists($conn, 'quiz_attempts');
        $hasContentId = columnExists($conn, 'quizzes', 'content_id');

        $questionCountSql = $hasQuestions
            ? "(SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id)"
            : "0";

        $attemptCountSql = $hasAttempts
            ? "(SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id)"
            : "0";

        $contentJoin = $hasContentId
            ? "LEFT JOIN contents ct ON ct.id = q.content_id"
            : "";
        $contentFields = $hasContentId
            ? ", ct.title as content_title, ct.slug as content_slug"
            : "";

        $whereClause = "";
        if ($mode !== null) {
            $whereClause = "WHERE q.mode = " . $conn->quote($mode);
        }

        $stmt = $conn->query("
            SELECT
                q.*,
                s.name AS subject_name,
                s.icon AS subject_icon,
                c.name AS category_name,
                u.full_name AS creator_name,
                {$questionCountSql} AS total_questions,
                {$attemptCountSql} AS total_attempts
                {$contentFields}
            FROM quizzes q
            LEFT JOIN subjects s ON s.id = q.subject_id
            LEFT JOIN categories c ON c.id = q.category_id
            LEFT JOIN users u ON u.id = q.created_by
            {$contentJoin}
            {$whereClause}
            ORDER BY q.created_at DESC, q.id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("getQuizzes: " . $e->getMessage());
        return [];
    }
}

function getQuizById(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("getQuizById: " . $e->getMessage());
        return null;
    }
}

function quizSlugExists(PDO $conn, string $slug, ?int $excludeId = null): bool {
    try {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT id FROM quizzes WHERE slug = ? AND id != ? LIMIT 1");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM quizzes WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
        }
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        error_log("quizSlugExists: " . $e->getMessage());
        return false;
    }
}

function uniqueQuizSlug(PDO $conn, string $baseSlug, ?int $excludeId = null): string {
    $baseSlug = $baseSlug !== '' ? $baseSlug : 'quiz';
    $slug = $baseSlug;
    $counter = 1;

    while (quizSlugExists($conn, $slug, $excludeId)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }

    return $slug;
}

function getQuizStatistics(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_quizzes,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_quizzes,
                SUM(CASE WHEN mode = 'lesson' THEN 1 ELSE 0 END) as lesson_quizzes,
                SUM(CASE WHEN mode = 'lesson' AND status = 'published' THEN 1 ELSE 0 END) as active_lesson,
                SUM(CASE WHEN mode = 'competition' THEN 1 ELSE 0 END) as competition_quizzes,
                SUM(CASE WHEN mode = 'competition' AND status = 'published' THEN 1 ELSE 0 END) as active_competition,
                SUM(CASE WHEN mode = 'self' THEN 1 ELSE 0 END) as self_quizzes,
                SUM(CASE WHEN mode = 'self' AND status = 'published' THEN 1 ELSE 0 END) as active_self
            FROM quizzes
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("getQuizStatistics: " . $e->getMessage());
        return [];
    }
}

$admin = getAdminUser($conn, $userId);
if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Insufficient privileges.');
}

$message = '';
$messageType = 'success';
$editingQuiz = null;
$activeTab = $_GET['tab'] ?? 'lesson';

if (!in_array($activeTab, ['lesson', 'competition', 'self', 'all'])) {
    $activeTab = 'lesson';
}

$hasContentIdColumn = columnExists($conn, 'quizzes', 'content_id');
$hasAllowAccessColumn = columnExists($conn, 'quizzes', 'allow_access');

// ========== AJAX HANDLERS ==========
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// AJAX endpoint for fetching a quiz (edit)
if ($isAjax && isset($_GET['edit'])) {
    header('Content-Type: application/json');
    $editId = (int)$_GET['edit'];
    if ($editId <= 0) {
        echo json_encode(['error' => 'Invalid quiz ID']);
        exit;
    }
    $editQuiz = getQuizById($conn, $editId);
    if ($editQuiz) {
        echo json_encode($editQuiz);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Quiz not found']);
    }
    exit;
}

// NEW: AJAX endpoint for testing explanation grading (admin preview)
if ($isAjax && isset($_GET['test_grading'])) {
    header('Content-Type: application/json');
    $studentAnswer = $_POST['student_answer'] ?? '';
    $expectedAnswer = $_POST['expected_answer'] ?? '';
    $maxPoints = (int)($_POST['max_points'] ?? 1);
    $keywordsRaw = $_POST['keywords'] ?? '';
    $keywords = [];
    if ($keywordsRaw) {
        $keywords = json_decode($keywordsRaw, true);
        if (!is_array($keywords)) $keywords = [];
    }
    $result = evaluateExplanationAnswer($studentAnswer, $expectedAnswer, $maxPoints, $keywords);
    echo json_encode($result);
    exit;
}

// Process POST actions (save, toggle, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_quiz') {
        $quizId = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
        $mode = strtolower(trim((string)($_POST['mode'] ?? 'lesson')));
        
        // Common fields
        $title = trim((string)($_POST['title'] ?? ''));
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $difficulty = strtolower(trim((string)($_POST['difficulty'] ?? 'medium')));
        $timeLimit = isset($_POST['time_limit_minutes']) && $_POST['time_limit_minutes'] !== '' ? (int)$_POST['time_limit_minutes'] : null;
        $totalPoints = isset($_POST['total_points']) ? (int)$_POST['total_points'] : 0;
        $status = strtolower(trim((string)($_POST['status'] ?? 'draft')));
        $allowAccess = isset($_POST['allow_access']) ? 1 : 0;
        
        $maxAttempts = isset($_POST['max_attempts']) ? (int)$_POST['max_attempts'] : 3;
        $unlimitedAttempts = isset($_POST['unlimited_attempts']) ? 1 : 0;
        $passingScore = isset($_POST['passing_score']) ? (int)$_POST['passing_score'] : 70;
        $hintsEnabled = isset($_POST['hints_enabled']) ? 1 : 0;
        
        $subjectId = null;
        $categoryId = null;
        $contentId = null;
        
        if ($mode === 'lesson') {
            $subjectId = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? (int)$_POST['subject_id'] : null;
            $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
            $contentId = isset($_POST['content_id']) && $_POST['content_id'] !== '' ? (int)$_POST['content_id'] : null;
            if (!$contentId) {
                $message = 'Please select a content for lesson quiz.';
                $messageType = 'danger';
            }
        } elseif ($mode === 'competition') {
            $subjectId = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? (int)$_POST['subject_id'] : null;
            $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
            $maxAttempts = 1;
            $unlimitedAttempts = 0;
        } elseif ($mode === 'self') {
            $subjectId = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? (int)$_POST['subject_id'] : null;
            $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
            if (!$subjectId) {
                $message = 'Please select a subject for self quiz.';
                $messageType = 'danger';
            }
        }
        
        $competitionStartAt = trim((string)($_POST['competition_start_at'] ?? ''));
        $competitionEndAt = trim((string)($_POST['competition_end_at'] ?? ''));
        $prizeDescription = trim((string)($_POST['prize_description'] ?? ''));

        if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) $difficulty = 'medium';
        if (!in_array($status, ['draft', 'published', 'archived'], true)) $status = 'draft';
        if (!in_array($mode, ['lesson', 'competition', 'self'], true)) $mode = 'lesson';
        if ($passingScore < 0 || $passingScore > 100) $passingScore = 70;
        if ($maxAttempts < 1) $maxAttempts = 1;
        if ($totalPoints < 0) $totalPoints = 0;

        $competitionStartValue = null;
        $competitionEndValue = null;
        if ($mode === 'competition') {
            if ($competitionStartAt !== '') {
                $competitionStartValue = date('Y-m-d H:i:s', strtotime($competitionStartAt));
            }
            if ($competitionEndAt !== '') {
                $competitionEndValue = date('Y-m-d H:i:s', strtotime($competitionEndAt));
            }
            if ($competitionStartValue && $competitionEndValue && strtotime($competitionEndValue) < strtotime($competitionStartValue)) {
                $message = 'Competition end date must be after start date.';
                $messageType = 'danger';
            }
        }

        $slug = make_slug($slugInput !== '' ? $slugInput : $title);

        if ($message === '' && $title === '') {
            $message = 'Quiz title is required.';
            $messageType = 'danger';
        } else {
            $slug = uniqueQuizSlug($conn, $slug, $quizId > 0 ? $quizId : null);

            try {
                $publishedAt = ($status === 'published') ? date('Y-m-d H:i:s') : null;

                $commonFields = [
                    'subject_id' => $subjectId,
                    'category_id' => $categoryId,
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description ?: null,
                    'difficulty' => $difficulty,
                    'time_limit_minutes' => $timeLimit,
                    'total_points' => $totalPoints,
                    'status' => $status,
                    'mode' => $mode,
                    'passing_score' => $passingScore,
                    'hints_enabled' => $hintsEnabled,
                    'max_attempts' => $maxAttempts,
                    'unlimited_attempts' => $unlimitedAttempts,
                    'competition_start_at' => $mode === 'competition' ? $competitionStartValue : null,
                    'competition_end_at' => $mode === 'competition' ? $competitionEndValue : null,
                    'prize_description' => $mode === 'competition' ? ($prizeDescription ?: null) : null,
                ];

                if ($hasContentIdColumn && $mode === 'lesson') {
                    $commonFields['content_id'] = $contentId;
                }

                if ($hasAllowAccessColumn) {
                    $commonFields['allow_access'] = $allowAccess;
                }

                if ($quizId > 0) {
                    $setParts = [];
                    $params = [];
                    foreach ($commonFields as $field => $value) {
                        $setParts[] = "$field = ?";
                        $params[] = $value;
                    }
                    $setParts[] = "published_at = ?";
                    $params[] = $publishedAt;
                    $setParts[] = "updated_at = NOW()";
                    $params[] = $quizId;

                    $stmt = $conn->prepare("UPDATE quizzes SET " . implode(', ', $setParts) . " WHERE id = ?");
                    $stmt->execute($params);
                    $message = ucfirst($mode) . ' quiz updated successfully.';
                } else {
                    $fields = array_keys($commonFields);
                    $placeholders = array_fill(0, count($fields), '?');
                    
                    $fields = array_merge($fields, ['created_by', 'published_at', 'created_at', 'updated_at']);
                    $placeholders = array_merge($placeholders, ['?', '?', 'NOW()', 'NOW()']);
                    
                    $values = array_values($commonFields);
                    $values[] = $userId;
                    $values[] = $publishedAt;

                    $stmt = $conn->prepare("
                        INSERT INTO quizzes (" . implode(', ', $fields) . ")
                        VALUES (" . implode(', ', $placeholders) . ")
                    ");
                    $stmt->execute($values);
                    
                    $newQuizId = (int)$conn->lastInsertId();
                    if ($status === 'published') {
                        $notifyTitle = "Quiz: " . $title;
                        $notifyMessage = "A new quiz is available. Test your knowledge now!";
                        $quizLink = "myislam/quizzes.php?id=" . ($quizId > 0 ? $quizId : $newQuizId);
                        createNotificationForAll($conn, 'quiz', $notifyTitle, $notifyMessage, $quizLink, 'user');
                    }
                    $message = ucfirst($mode) . ' quiz created successfully.';
                    $redirectUrl = 'admin_quiz_questions.php?quiz_id=' . $newQuizId . '&created=1';
                }
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("save_quiz: " . $e->getMessage());
                $message = 'Unable to save quiz: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $messageType === 'success',
                'message' => $message,
                'redirect' => $redirectUrl ?? null
            ]);
            exit;
        }
    }

    if ($action === 'toggle_status') {
        $quizId = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
        if ($quizId > 0) {
            try {
                $stmt = $conn->prepare("SELECT status FROM quizzes WHERE id = ?");
                $stmt->execute([$quizId]);
                $currentStatus = $stmt->fetchColumn();
                
                $newStatus = ($currentStatus === 'published') ? 'draft' : 'published';
                $publishedAt = ($newStatus === 'published') ? date('Y-m-d H:i:s') : null;
                
                $stmt = $conn->prepare("
                    UPDATE quizzes 
                    SET status = ?, published_at = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newStatus, $publishedAt, $quizId]);

                if ($newStatus === 'published') {
                    $stmt = $conn->prepare("SELECT title FROM quizzes WHERE id = ?");
                    $stmt->execute([$quizId]);
                    $quizTitle = $stmt->fetchColumn();
                    if ($quizTitle) {
                        $notifyTitle = "Quiz Now Live: " . $quizTitle;
                        $notifyMessage = "A quiz has been published. Check it out!";
                        $quizLink = "myislam/quizzes.php?id=" . $quizId;
                        createNotificationForAll($conn, 'quiz', $notifyTitle, $notifyMessage, $quizLink, 'user');
                    }
                }

                $message = 'Quiz status updated to ' . $newStatus . '.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Unable to update status: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }

    if ($action === 'toggle_access') {
        $quizId = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
        if ($quizId > 0 && $hasAllowAccessColumn) {
            try {
                $stmt = $conn->prepare("UPDATE quizzes SET allow_access = IF(allow_access = 1, 0, 1), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$quizId]);

                $stmt = $conn->prepare("SELECT allow_access, title FROM quizzes WHERE id = ?");
                $stmt->execute([$quizId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $newValue = (int)($row['allow_access'] ?? 0);

                $message = 'Access for "' . ($row['title'] ?? 'this quiz') . '" is now ' . ($newValue === 1 ? 'enabled - users can access it' : 'locked - users cannot access it') . '.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("toggle_access: " . $e->getMessage());
                $message = 'Unable to update access setting: ' . $e->getMessage();
                $messageType = 'danger';
                $newValue = null;
            }
        } else {
            $message = $hasAllowAccessColumn ? 'Invalid quiz.' : 'Access lock is not available - run the migration first.';
            $messageType = 'danger';
            $newValue = null;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $messageType === 'success',
                'message' => $message,
                'new_value' => $newValue
            ]);
            exit;
        }
    }

    if ($action === 'delete_quiz') {
        $quizId = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
        if ($quizId > 0) {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ?");
                $stmt->execute([$quizId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $message = 'Cannot delete quiz with attempts. Archive it instead.';
                    $messageType = 'danger';
                } else {
                    if (tableExists($conn, 'quiz_questions')) {
                        $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
                        $stmt->execute([$quizId]);
                    }
                    $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?");
                    $stmt->execute([$quizId]);
                    $message = 'Quiz deleted.';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Unable to delete quiz.';
                $messageType = 'danger';
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }
}

$subjects = getSubjects($conn);
$allCategories = getCategories($conn);
$allContents = getContents($conn);
$allQuizzes = getQuizzes($conn);
$lessonQuizzes = array_filter($allQuizzes, fn($q) => ($q['mode'] ?? '') === 'lesson');
$competitionQuizzes = array_filter($allQuizzes, fn($q) => ($q['mode'] ?? '') === 'competition');
$selfQuizzes = array_filter($allQuizzes, fn($q) => ($q['mode'] ?? '') === 'self');
$stats = getQuizStatistics($conn);

$totalLesson = (int)($stats['lesson_quizzes'] ?? 0);
$activeLesson = (int)($stats['active_lesson'] ?? 0);
$totalCompetition = (int)($stats['competition_quizzes'] ?? 0);
$activeCompetition = (int)($stats['active_competition'] ?? 0);
$totalSelf = (int)($stats['self_quizzes'] ?? 0);
$activeSelf = (int)($stats['active_self'] ?? 0);

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => true],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false, 'icon' => '🗂️'],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false, 'icon' => '📘'],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => true, 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false, 'icon' => '👥'],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false, 'icon' => '📢'],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false, 'icon' => '⚙️'],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false, 'icon' => '🚪'],
];

$categoriesJson = json_encode($allCategories);
$contentsJson = json_encode($allContents);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Quizzes - MyIslam</title>
<style>
/* ... (existing CSS unchanged) ... */
*{box-sizing:border-box;margin:0;padding:0}
:root{
--bg:#f5f7f5;--surface:#ffffff;--surface-2:#f9fafb;--surface-3:#eef2f7;--line:#e5e7eb;
--text:#1f2937;--text-secondary:#4b5563;--muted:#6b7280;--brand:#166534;--brand-soft:#e7f3eb;
--accent:#3b82f6;--warning:#f59e0b;--danger:#ef4444;--success:#10b981;--header-bg:#e1f0e7;
--header-height:72px;--footer-height:92px;
--shadow-sm:0 1px 2px 0 rgb(0 0 0 / 0.05);--shadow-md:0 4px 6px -1px rgb(0 0 0 / 0.1);--shadow-lg:0 10px 15px -3px rgb(0 0 0 / 0.1);
--radius-sm:12px;--radius-md:16px;--radius-lg:20px;
}
body.dark-mode{
--bg:#0f1720;--surface:#18212b;--surface-2:#1d2833;--surface-3:#22303c;--line:#304150;
--text:#edf2f7;--text-secondary:#cbd5e1;--muted:#94a3b8;--brand:#7dd3a7;--brand-soft:rgba(125,211,167,0.12);
--accent:#60a5fa;--header-bg:#13202a;
}
body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5}
a{text-decoration:none;color:inherit} button,input,textarea,select{font:inherit}
.app-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:var(--header-height);background:var(--header-bg);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;backdrop-filter:blur(10px);gap:18px}
.header-left,.header-right{display:flex;align-items:center;gap:12px}
.header-left{flex:1;min-width:0}
.header-top-nav{display:flex;align-items:center;gap:8px;overflow-x:auto}
.header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-size:13px;font-weight:700;transition:.2s}
.header-top-link:hover,.header-top-link.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
.menu-toggle,.icon-btn,.lang-btn,.brand-box{height:44px;min-width:44px;border-radius:var(--radius-sm);background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;transition:.2s;cursor:pointer}
.menu-toggle:hover,.icon-btn:hover,.lang-btn:hover,.profile-btn:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
.menu-toggle{font-size:22px}.lang-btn{min-width:56px;padding:0 14px;font-weight:600}
.brand-box{font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none;white-space:nowrap}
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
.sidebar-overlay{display:none;position:fixed;top:var(--header-height);left:0;width:100%;height:calc(100vh - var(--header-height));background:rgba(0,0,0,.5);z-index:1100;backdrop-filter:blur(4px)}
.sidebar-overlay.show{display:block}
.sidebar{position:fixed;top:var(--header-height);left:-100%;width:320px;height:calc(100vh - var(--header-height));background:var(--surface);border-right:1px solid var(--line);padding:24px 20px;z-index:1200;transition:left .3s ease;overflow-y:auto}
.sidebar.open{left:0}
.sidebar-title{font-size:12px;color:var(--muted);text-transform:uppercase;margin-bottom:20px;font-weight:700;letter-spacing:1px;padding:0 8px}
.subject-list{display:grid;gap:8px}
.subject-link{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--radius-md);background:var(--surface-2);border:1px solid transparent;font-size:14px;font-weight:500;transition:all .2s}
.subject-link:hover{background:var(--brand-soft);border-color:var(--brand);transform:translateX(4px)}
.subject-link.active{background:var(--brand-soft);color:var(--brand);border-color:var(--brand)}
.main-content{padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px);max-width:1450px;margin:0 auto}
.page-stack{display:grid;gap:24px}
.section-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px;transition:.2s}
.section-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none;display:flex;justify-content:space-between;align-items:center}
.hero-card h1{font-size:32px;margin-bottom:8px;font-weight:700}
.hero-card p{color:var(--text-secondary);font-size:16px}
.tab-nav{display:flex;gap:8px;margin-bottom:20px;border-bottom:1px solid var(--line);padding-bottom:12px;flex-wrap:wrap}
.tab-btn{padding:10px 20px;border-radius:var(--radius-sm);background:transparent;border:none;color:var(--muted);font-weight:600;cursor:pointer;transition:.2s}
.tab-btn:hover{background:var(--surface-2);color:var(--text)}
.tab-btn.active{background:var(--brand);color:white}
.tab-btn.lesson.active{background:#10b981}
.tab-btn.competition.active{background:#f59e0b}
.tab-btn.self.active{background:#8b5cf6}
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:16px;margin-bottom:24px}
.stat-card{text-align:center;padding:16px}
.stat-value{font-size:28px;font-weight:800;margin-bottom:4px}
.stat-card.lesson .stat-value{color:#10b981}
.stat-card.competition .stat-value{color:#f59e0b}
.stat-card.self .stat-value{color:#8b5cf6}
.stat-label{font-size:12px;color:var(--muted)}
.filters-row{display:grid;grid-template-columns:1fr 180px 150px;gap:12px;margin-bottom:16px}
.search-wrap{position:relative}
.search-input{width:100%;height:44px;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:0 16px 0 42px;outline:none}
.search-input:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:16px}
.quiz-list{display:grid;gap:10px}
.quiz-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px}
.quiz-left{display:flex;align-items:flex-start;gap:12px;flex:1}
.quiz-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.quiz-icon.lesson{background:#d1fae5;color:#10b981}
.quiz-icon.competition{background:#fef3c7;color:#f59e0b}
.quiz-icon.self{background:#ede9fe;color:#8b5cf6}
.quiz-info{flex:1}
.quiz-title{font-size:15px;font-weight:700;margin-bottom:4px}
.quiz-meta{font-size:11px;color:var(--muted);line-height:1.6}
.quiz-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.badge{display:inline-flex;align-items:center;justify-content:center;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:700}
.badge.lesson{background:#d1fae5;color:#065f46}
.badge.competition{background:#fef3c7;color:#92400e}
.badge.self{background:#ede9fe;color:#5b21b6}
.badge.published{background:#dcfce7;color:#166534}
.badge.draft{background:#f3f4f6;color:#4b5563}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.footer-actions{position:fixed;left:0;right:0;bottom:0;background:var(--surface);border-top:1px solid var(--line);padding:12px 24px;backdrop-filter:blur(10px);z-index:1000}
.footer-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;max-width:1200px;margin:0 auto}
.footer-action{background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius-md);min-height:70px;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:8px;transition:.2s}
.footer-action:hover{transform:translateY(-4px);border-color:var(--brand);background:var(--brand-soft)}
.footer-action .icon{font-size:24px}
.tab-content{display:none}
.tab-content.active{display:block}
.btn{padding:10px 16px;border-radius:14px;font-weight:600;border:none;cursor:pointer;transition:.2s;font-size:13px;display:inline-flex;align-items:center;gap:6px}
.btn.primary{background:var(--brand);color:#fff}
.btn.lesson{background:#10b981;color:#fff}
.btn.competition{background:#f59e0b;color:#fff}
.btn.self{background:#8b5cf6;color:#fff}
.btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
.btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
.btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.btn:hover{transform:translateY(-2px)}

/* Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center}
.modal{background:var(--surface);border-radius:var(--radius-lg);max-width:700px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid var(--line)}
.modal-header{padding:24px 24px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.modal-header h2{font-size:20px;font-weight:700}
.modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted)}
.modal-body{padding:24px}
.form-fields{display:grid;gap:14px}
.form-row{display:grid;gap:8px}
.form-row label{font-size:14px;font-weight:700}
.form-input,.form-textarea,.form-select{width:100%;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:12px 14px;outline:none}
.form-input:focus,.form-textarea:focus,.form-select:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.form-textarea{min-height:100px;resize:vertical}
.inline-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.action-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
.checkbox-group{display:flex;flex-wrap:wrap;gap:16px;padding:8px 0}
.checkbox-item{display:flex;align-items:center;gap:8px}
.mode-specific{display:none}
.mode-specific.show{display:block}

/* Toast */
.toast-container{position:fixed;top:80px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px}
.toast{padding:16px 20px;border-radius:var(--radius-md);background:var(--success);color:#fff;box-shadow:var(--shadow-lg);font-weight:500;display:flex;align-items:center;gap:10px;animation:slideIn 0.3s ease;max-width:400px}
.toast.success{background:#10b981}
.toast.danger{background:#ef4444}
.toast .close-toast{margin-left:auto;cursor:pointer;font-size:20px;opacity:0.8}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}

@media (max-width:1250px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
@media (max-width:950px){.header-top-nav{display:none}.filters-row{grid-template-columns:1fr}}
@media (max-width:768px){
:root{--header-height:64px;--footer-height:80px}
.main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}
.section-card{padding:16px}.hero-card h1{font-size:24px}.stats-grid{grid-template-columns:repeat(2,1fr)}.quiz-item{flex-direction:column}
}
</style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="brand-box">🛠️ Admin</div>
        <nav class="header-top-nav">
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
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle">
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
                <a href="admin_settings.php">⚙️ Settings</a>
                <a href="../logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">🛠️ Admin Panel</div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon']); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-stack">
            <section class="hero-card section-card">
                <div>
                    <h1>📝 Quiz Management</h1>
                    <p>Lesson (content-specific) • Competition (multi-subject) • Self (subject-based practice)</p>
                </div>
                <button class="btn primary" id="createQuizBtn">➕ Create Quiz</button>
            </section>

            <div class="stats-grid">
                <div class="section-card stat-card lesson">
                    <div class="stat-value"><?php echo $totalLesson; ?></div>
                    <div class="stat-label">📖 Lesson Quizzes</div>
                </div>
                <div class="section-card stat-card lesson">
                    <div class="stat-value"><?php echo $activeLesson; ?></div>
                    <div class="stat-label">Active Lessons</div>
                </div>
                <div class="section-card stat-card competition">
                    <div class="stat-value"><?php echo $totalCompetition; ?></div>
                    <div class="stat-label">🏆 Competitions</div>
                </div>
                <div class="section-card stat-card competition">
                    <div class="stat-value"><?php echo $activeCompetition; ?></div>
                    <div class="stat-label">Active Competitions</div>
                </div>
                <div class="section-card stat-card self">
                    <div class="stat-value"><?php echo $totalSelf; ?></div>
                    <div class="stat-label">🔍 Self Quizzes</div>
                </div>
                <div class="section-card stat-card self">
                    <div class="stat-value"><?php echo $activeSelf; ?></div>
                    <div class="stat-label">Active Self</div>
                </div>
            </div>

            <div class="tab-nav">
                <button class="tab-btn lesson <?php echo $activeTab === 'lesson' ? 'active' : ''; ?>" data-tab="lesson">📖 Lesson</button>
                <button class="tab-btn competition <?php echo $activeTab === 'competition' ? 'active' : ''; ?>" data-tab="competition">🏆 Competition</button>
                <button class="tab-btn self <?php echo $activeTab === 'self' ? 'active' : ''; ?>" data-tab="self">🔍 Self</button>
                <button class="tab-btn <?php echo $activeTab === 'all' ? 'active' : ''; ?>" data-tab="all">🗂️ All</button>
            </div>

            <?php foreach (['lesson', 'competition', 'self'] as $mode): ?>
            <div class="tab-content <?php echo $activeTab === $mode ? 'active' : ''; ?>" id="tab-<?php echo $mode; ?>">
                <section class="section-card">
                    <div class="filters-row">
                        <div class="search-wrap">
                            <span class="search-icon">🔍</span>
                            <input type="text" id="<?php echo $mode; ?>Search" class="search-input" placeholder="Search...">
                        </div>
                        <select id="<?php echo $mode; ?>SubjectFilter" class="form-select">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo strtolower(e($subject['name'] ?? '')); ?>"><?php echo e($subject['name'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="<?php echo $mode; ?>StatusFilter" class="form-select">
                            <option value="">All Status</option>
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>

                    <div class="quiz-list" id="<?php echo $mode; ?>List">
                        <?php 
                        $quizzesForMode = $mode === 'lesson' ? $lessonQuizzes : ($mode === 'competition' ? $competitionQuizzes : $selfQuizzes);
                        foreach ($quizzesForMode as $quiz): 
                            $quizStatus = strtolower((string)($quiz['status'] ?? 'draft'));
                            $searchBlob = strtolower(($quiz['title'] ?? '') . ' ' . ($quiz['subject_name'] ?? '') . ' ' . ($quiz['content_title'] ?? ''));
                        ?>
                            <div class="quiz-item <?php echo $mode; ?>-quiz-item" data-search="<?php echo e($searchBlob); ?>"
                                 data-subject="<?php echo strtolower(e($quiz['subject_name'] ?? '')); ?>"
                                 data-status="<?php echo e($quizStatus); ?>"
                                 data-quiz-id="<?php echo (int)$quiz['id']; ?>">
                                <div class="quiz-left">
                                    <div class="quiz-icon <?php echo $mode; ?>"><?php echo $mode === 'lesson' ? '📖' : ($mode === 'competition' ? '🏆' : '🔍'); ?></div>
                                    <div class="quiz-info">
                                        <div class="quiz-title"><?php echo e($quiz['title'] ?? 'Untitled'); ?></div>
                                        <div class="quiz-meta">
                                            <?php if ($mode === 'lesson' && !empty($quiz['content_title'])): ?>
                                                Content: <?php echo e($quiz['content_title']); ?> |
                                            <?php endif; ?>
                                            Subject: <?php echo e($quiz['subject_name'] ?? 'Any'); ?> |
                                            <?php echo ucfirst($quiz['difficulty'] ?? 'medium'); ?> |
                                            Q: <?php echo (int)($quiz['total_questions'] ?? 0); ?> |
                                            Attempts: <?php echo (int)($quiz['total_attempts'] ?? 0); ?>
                                            <?php if ($mode === 'competition' && !empty($quiz['competition_start_at'])): ?>
                                                <br>📅 <?php echo e(date('M d, Y', strtotime((string)$quiz['competition_start_at']))); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="quiz-actions">
                                    <span class="badge <?php echo $mode; ?>"><?php echo ucfirst($mode); ?></span>
                                    <span class="badge <?php echo e($quizStatus); ?>"><?php echo e(ucfirst($quizStatus)); ?></span>
                                    <?php $isAccessLocked = isset($quiz['allow_access']) && (int)$quiz['allow_access'] === 0; ?>
                                    <?php if ($isAccessLocked): ?>
                                        <span class="badge danger" title="Users cannot access this quiz">🔒 Access Locked</span>
                                    <?php endif; ?>
                                    <button class="btn secondary edit-quiz-btn" data-quiz-id="<?php echo (int)$quiz['id']; ?>">✏️ Edit</button>
                                    <a href="admin_quiz_questions.php?quiz_id=<?php echo (int)$quiz['id']; ?>" class="btn warn">❓ Questions</a>
                                    <button class="btn secondary toggle-status-btn" data-quiz-id="<?php echo (int)$quiz['id']; ?>"><?php echo $quizStatus === 'published' ? '📄 Unpublish' : '🚀 Publish'; ?></button>
                                    <button class="btn secondary toggle-access-btn" data-quiz-id="<?php echo (int)$quiz['id']; ?>"><?php echo $isAccessLocked ? '🔓 Unlock Access' : '🔒 Lock Access'; ?></button>
                                    <?php if ((int)($quiz['total_attempts'] ?? 0) === 0): ?>
                                    <button class="btn danger delete-quiz-btn" data-quiz-id="<?php echo (int)$quiz['id']; ?>">🗑️ Delete</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($quizzesForMode)): ?>
                            <div class="empty-state">No <?php echo $mode; ?> quizzes yet. Click "Create Quiz" to add one.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <?php endforeach; ?>

            <!-- All Quizzes Tab -->
            <div class="tab-content <?php echo $activeTab === 'all' ? 'active' : ''; ?>" id="tab-all">
                <section class="section-card">
                    <div class="filters-row">
                        <div class="search-wrap">
                            <span class="search-icon">🔍</span>
                            <input type="text" id="allSearch" class="search-input" placeholder="Search all quizzes...">
                        </div>
                        <select id="allModeFilter" class="form-select">
                            <option value="">All Modes</option>
                            <option value="lesson">Lesson</option>
                            <option value="competition">Competition</option>
                            <option value="self">Self</option>
                        </select>
                        <select id="allStatusFilter" class="form-select">
                            <option value="">All Status</option>
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>

                    <div class="quiz-list" id="allList">
                        <?php foreach ($allQuizzes as $quiz): 
                            $mode = $quiz['mode'] ?? 'lesson';
                            $status = strtolower((string)($quiz['status'] ?? 'draft'));
                            $searchBlob = strtolower($mode . ' ' . ($quiz['title'] ?? '') . ' ' . ($quiz['subject_name'] ?? '') . ' ' . ($quiz['content_title'] ?? ''));
                        ?>
                            <div class="quiz-item all-quiz-item" data-search="<?php echo e($searchBlob); ?>"
                                 data-mode="<?php echo e($mode); ?>" data-status="<?php echo e($status); ?>"
                                 data-quiz-id="<?php echo (int)$quiz['id']; ?>">
                                <div class="quiz-left">
                                    <div class="quiz-icon <?php echo $mode; ?>"><?php echo $mode === 'lesson' ? '📖' : ($mode === 'competition' ? '🏆' : '🔍'); ?></div>
                                    <div class="quiz-info">
                                        <div class="quiz-title"><?php echo e($quiz['title'] ?? 'Untitled'); ?></div>
                                        <div class="quiz-meta">
                                            Mode: <?php echo ucfirst($mode); ?> |
                                            <?php if ($mode === 'lesson' && !empty($quiz['content_title'])): ?>
                                                Content: <?php echo e($quiz['content_title']); ?> |
                                            <?php endif; ?>
                                            Subject: <?php echo e($quiz['subject_name'] ?? 'Any'); ?> |
                                            Status: <?php echo ucfirst($status); ?> |
                                            Q: <?php echo (int)($quiz['total_questions'] ?? 0); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="quiz-actions">
                                    <span class="badge <?php echo $mode; ?>"><?php echo ucfirst($mode); ?></span>
                                    <?php $isAccessLockedAll = isset($quiz['allow_access']) && (int)$quiz['allow_access'] === 0; ?>
                                    <?php if ($isAccessLockedAll): ?>
                                        <span class="badge danger" title="Users cannot access this quiz">🔒 Access Locked</span>
                                    <?php endif; ?>
                                    <button class="btn secondary edit-quiz-btn" data-quiz-id="<?php echo (int)$quiz['id']; ?>">✏️ Edit</button>
                                    <a href="admin_quiz_questions.php?quiz_id=<?php echo (int)$quiz['id']; ?>" class="btn warn">❓ Questions</a>
                                    <button class="btn secondary toggle-access-btn" data-quiz-id="<?php echo (int)$quiz['id']; ?>"><?php echo $isAccessLockedAll ? '🔓 Unlock Access' : '🔒 Lock Access'; ?></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="empty-state" id="allEmptyState" style="display:none;">No matching quizzes found.</div>
                </section>
            </div>
        </div>
    </main>

    <nav class="footer-actions">
        <div class="footer-grid">
            <a href="dashboard.php" class="footer-action"><span class="icon">📊</span><span>Dashboard</span></a>
            <a href="admin_subjects.php" class="footer-action"><span class="icon">📚</span><span>Subjects</span></a>
            <a href="admin_quiz_questions.php" class="footer-action"><span class="icon">❓</span><span>Questions</span></a>
            <a href="../logout.php" class="footer-action"><span class="icon">🚪</span><span>Logout</span></a>
        </div>
    </nav>
</div>

<!-- Quiz Modal -->
<div class="modal-overlay" id="quizModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Create New Quiz</h2>
            <button class="modal-close" id="closeModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="quizForm">
                <input type="hidden" name="action" value="save_quiz">
                <input type="hidden" name="quiz_id" id="quiz_id" value="0">
                
                <div class="form-row">
                    <label for="mode">Quiz Mode *</label>
                    <select id="mode" name="mode" class="form-select" required>
                        <option value="lesson">📖 Lesson Quiz (attached to a content)</option>
                        <option value="competition">🏆 Competition (multi-subject, with dates)</option>
                        <option value="self">🔍 Self Quiz (subject-based practice)</option>
                    </select>
                </div>

                <!-- Subject (visible for lesson/self, optional for competition) -->
                <div class="form-row mode-field" data-mode="lesson,self,competition">
                    <label for="subject_id">Subject <span class="required-label"></span></label>
                    <select id="subject_id" name="subject_id" class="form-select">
                        <option value="">Select subject</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo (int)$subject['id']; ?>"><?php echo e(($subject['icon'] ?? '📚') . ' ' . ($subject['name'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Category (visible for lesson, optional for others) -->
                <div class="form-row mode-field" data-mode="lesson,self,competition">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" class="form-select">
                        <option value="">No category</option>
                    </select>
                </div>

                <!-- Content (lesson only) -->
                <div class="form-row mode-field" data-mode="lesson">
                    <label for="content_id">Content *</label>
                    <select id="content_id" name="content_id" class="form-select">
                        <option value="">Select content</option>
                    </select>
                </div>

                <div class="form-row">
                    <label for="title">Quiz Title *</label>
                    <input type="text" id="title" name="title" class="form-input" required>
                </div>

                <div class="form-row">
                    <label for="slug">Slug</label>
                    <input type="text" id="slug" name="slug" class="form-input" placeholder="auto-generated">
                </div>

                <div class="form-row">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-textarea" rows="2"></textarea>
                </div>

                <div class="inline-row">
                    <div class="form-row">
                        <label for="difficulty">Difficulty</label>
                        <select id="difficulty" name="difficulty" class="form-select">
                            <option value="easy">Easy</option>
                            <option value="medium" selected>Medium</option>
                            <option value="hard">Hard</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                </div>

                <div class="inline-row">
                    <div class="form-row">
                        <label for="total_points">Total Points</label>
                        <input type="number" id="total_points" name="total_points" class="form-input" value="0" min="0">
                    </div>
                    <div class="form-row">
                        <label for="time_limit_minutes">Time Limit (mins)</label>
                        <input type="number" id="time_limit_minutes" name="time_limit_minutes" class="form-input" placeholder="Optional">
                    </div>
                </div>

                <div class="inline-row">
                    <div class="form-row">
                        <label for="passing_score">Passing Score (%)</label>
                        <input type="number" id="passing_score" name="passing_score" class="form-input" value="70" min="0" max="100">
                    </div>
                    <div class="form-row mode-field" data-mode="lesson,self">
                        <label for="max_attempts">Max Attempts</label>
                        <input type="number" id="max_attempts" name="max_attempts" class="form-input" value="3" min="1">
                    </div>
                </div>

                <!-- Competition specific fields -->
                <div class="mode-field" data-mode="competition">
                    <div class="inline-row">
                        <div class="form-row">
                            <label for="competition_start_at">🏁 Start Date</label>
                            <input type="datetime-local" id="competition_start_at" name="competition_start_at" class="form-input">
                        </div>
                        <div class="form-row">
                            <label for="competition_end_at">🏁 End Date</label>
                            <input type="datetime-local" id="competition_end_at" name="competition_end_at" class="form-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="prize_description">Prize Description</label>
                        <textarea id="prize_description" name="prize_description" class="form-textarea" rows="2"></textarea>
                    </div>
                </div>

                <div class="checkbox-group">
                    <label class="checkbox-item">
                        <input type="checkbox" name="hints_enabled" value="1"> Enable Hints
                    </label>
                    <label class="checkbox-item mode-field" data-mode="lesson,self">
                        <input type="checkbox" name="unlimited_attempts" value="1"> Unlimited Attempts
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="allow_access" value="1" checked> 🔓 Allow Access (uncheck to lock this quiz from all users)
                    </label>
                </div>

                <div class="action-row">
                    <button type="submit" class="btn primary" id="saveQuizBtn">Save Quiz</button>
                    <button type="button" class="btn secondary" id="cancelModalBtn">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toast
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <span>${type === 'success' ? '✅' : '❌'}</span>
        <span>${message}</span>
        <span class="close-toast" onclick="this.parentElement.remove()">&times;</span>
    `;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}

// Data
const categoriesData = <?php echo $categoriesJson; ?>;
const contentsData = <?php echo $contentsJson; ?>;
let editingQuizData = null;

// DOM elements
const modal = document.getElementById('quizModal');
const createBtn = document.getElementById('createQuizBtn');
const closeModalBtn = document.getElementById('closeModalBtn');
const cancelModalBtn = document.getElementById('cancelModalBtn');
const quizForm = document.getElementById('quizForm');
const modalTitle = document.getElementById('modalTitle');
const modeSelect = document.getElementById('mode');
const subjectSelect = document.getElementById('subject_id');
const categorySelect = document.getElementById('category_id');
const contentSelect = document.getElementById('content_id');

// Helper: update field visibility based on mode
function updateModeFields() {
    const mode = modeSelect.value;
    document.querySelectorAll('.mode-field').forEach(el => {
        const modes = el.dataset.mode.split(',');
        el.style.display = modes.includes(mode) ? '' : 'none';
    });
    // Update required labels
    const subjectLabel = document.querySelector('label[for="subject_id"] .required-label');
    if (subjectLabel) {
        if (mode === 'lesson' || mode === 'self') {
            subjectLabel.textContent = '*';
            subjectSelect.required = true;
        } else {
            subjectLabel.textContent = '(Optional)';
            subjectSelect.required = false;
        }
    }
    const categoryLabel = document.querySelector('label[for="category_id"]');
    if (categoryLabel) {
        if (mode === 'lesson') {
            categoryLabel.innerHTML = 'Category *';
            categorySelect.required = true;
        } else {
            categoryLabel.innerHTML = 'Category (Optional)';
            categorySelect.required = false;
        }
    }
    // Max attempts for competition is 1
    if (mode === 'competition') {
        document.getElementById('max_attempts').value = 1;
        document.getElementById('max_attempts').readOnly = true;
    } else {
        document.getElementById('max_attempts').readOnly = false;
    }
    // Cascade updates
    filterCategories();
    filterContents();
}

// Cascading filters
function filterCategories() {
    const subjectId = subjectSelect.value;
    const mode = modeSelect.value;
    const currentVal = categorySelect.value;
    categorySelect.innerHTML = '<option value="">' + (mode === 'lesson' ? 'Select category' : 'No category') + '</option>';
    categoriesData.filter(c => !subjectId || c.subject_id == subjectId).forEach(c => {
        const option = document.createElement('option');
        option.value = c.id;
        option.textContent = c.name;
        if (c.id == currentVal) option.selected = true;
        categorySelect.appendChild(option);
    });
}

function filterContents() {
    const categoryId = categorySelect.value;
    const currentVal = contentSelect.value;
    contentSelect.innerHTML = '<option value="">Select content</option>';
    contentsData.filter(c => !categoryId || c.category_id == categoryId).forEach(c => {
        const option = document.createElement('option');
        option.value = c.id;
        option.textContent = c.title + ' (' + (c.subject_name || '') + ')';
        if (c.id == currentVal) option.selected = true;
        contentSelect.appendChild(option);
    });
}

// Modal open
function openModal(data = null) {
    editingQuizData = data;
    if (data) {
        modalTitle.textContent = 'Edit Quiz';
        document.getElementById('quiz_id').value = data.id || 0;
        modeSelect.value = data.mode || 'lesson';
        updateModeFields();
        subjectSelect.value = data.subject_id || '';
        filterCategories();
        setTimeout(() => {
            categorySelect.value = data.category_id || '';
            filterContents();
            setTimeout(() => {
                contentSelect.value = data.content_id || '';
            }, 50);
        }, 50);
        document.getElementById('title').value = data.title || '';
        document.getElementById('slug').value = data.slug || '';
        document.getElementById('description').value = data.description || '';
        document.getElementById('difficulty').value = data.difficulty || 'medium';
        document.getElementById('status').value = data.status || 'draft';
        document.getElementById('total_points').value = data.total_points || 0;
        document.getElementById('time_limit_minutes').value = data.time_limit_minutes || '';
        document.getElementById('passing_score').value = data.passing_score || 70;
        document.getElementById('max_attempts').value = data.max_attempts || 3;
        document.querySelector('[name="hints_enabled"]').checked = data.hints_enabled == 1;
        document.querySelector('[name="unlimited_attempts"]').checked = data.unlimited_attempts == 1;
        document.querySelector('[name="allow_access"]').checked = data.allow_access == null || data.allow_access == 1;
        if (data.mode === 'competition') {
            document.getElementById('competition_start_at').value = data.competition_start_at ? data.competition_start_at.replace(' ', 'T').slice(0,16) : '';
            document.getElementById('competition_end_at').value = data.competition_end_at ? data.competition_end_at.replace(' ', 'T').slice(0,16) : '';
            document.getElementById('prize_description').value = data.prize_description || '';
        }
    } else {
        modalTitle.textContent = 'Create New Quiz';
        quizForm.reset();
        document.getElementById('quiz_id').value = '0';
        modeSelect.value = 'lesson';
        updateModeFields();
        categorySelect.innerHTML = '<option value="">Select category</option>';
        contentSelect.innerHTML = '<option value="">Select content</option>';
        document.getElementById('max_attempts').value = 3;
        document.getElementById('passing_score').value = 70;
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

// Event listeners
createBtn.addEventListener('click', () => openModal());
closeModalBtn.addEventListener('click', closeModal);
cancelModalBtn.addEventListener('click', closeModal);
modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

modeSelect.addEventListener('change', updateModeFields);
subjectSelect.addEventListener('change', () => { filterCategories(); filterContents(); });
categorySelect.addEventListener('change', filterContents);

// Auto slug
document.getElementById('title').addEventListener('input', function() {
    const slugInput = document.getElementById('slug');
    if (!slugInput.value || slugInput.value === makeSlug(slugInput.dataset.old || '')) {
        slugInput.value = makeSlug(this.value);
        slugInput.dataset.old = this.value;
    }
});
function makeSlug(t) { return t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }

// Form submit AJAX
quizForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(quizForm);
    try {
        const response = await fetch('admin_quizzes.php', {
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const result = await response.json();
        showToast(result.message, result.success ? 'success' : 'danger');
        if (result.success) {
            closeModal();
            if (result.redirect) {
                window.location.href = result.redirect;
            } else {
                setTimeout(() => location.reload(), 1000);
            }
        }
    } catch (err) {
        showToast('An error occurred', 'danger');
    }
});

// Edit buttons
document.querySelectorAll('.edit-quiz-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.quizId;
        try {
            const response = await fetch(`admin_quizzes.php?edit=${id}&ajax=1`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const data = await response.json();
            if (data.error) {
                showToast(data.error, 'danger');
            } else {
                openModal(data);
            }
        } catch (err) {
            showToast('Failed to load quiz', 'danger');
        }
    });
});

// Toggle status
document.querySelectorAll('.toggle-status-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.quizId;
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('quiz_id', id);
        try {
            const response = await fetch('admin_quizzes.php', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            showToast(result.message, result.success ? 'success' : 'danger');
            if (result.success) setTimeout(() => location.reload(), 1000);
        } catch (err) {
            showToast('An error occurred', 'danger');
        }
    });
});

// Toggle access lock
document.querySelectorAll('.toggle-access-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.quizId;
        const formData = new FormData();
        formData.append('action', 'toggle_access');
        formData.append('quiz_id', id);
        try {
            const response = await fetch('admin_quizzes.php', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            showToast(result.message, result.success ? 'success' : 'danger');
            if (result.success) setTimeout(() => location.reload(), 1000);
        } catch (err) {
            showToast('An error occurred', 'danger');
        }
    });
});

// Delete quiz
document.querySelectorAll('.delete-quiz-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!confirm('Delete this quiz?')) return;
        const id = btn.dataset.quizId;
        const formData = new FormData();
        formData.append('action', 'delete_quiz');
        formData.append('quiz_id', id);
        try {
            const response = await fetch('admin_quizzes.php', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            showToast(result.message, result.success ? 'success' : 'danger');
            if (result.success) setTimeout(() => location.reload(), 1000);
        } catch (err) {
            showToast('An error occurred', 'danger');
        }
    });
});

// Filters (same as before)
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

    const tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.tab;
            tabBtns.forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + tab).classList.add('active');
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        });
    });

    function openSidebar() { sidebar?.classList.add('open'); sidebarOverlay?.classList.add('show'); body.style.overflow = 'hidden'; }
    function closeSidebar() { sidebar?.classList.remove('open'); sidebarOverlay?.classList.remove('show'); body.style.overflow = ''; }
    menuToggle?.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    sidebarOverlay?.addEventListener('click', closeSidebar);

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') { body.classList.add('dark-mode'); if(themeToggle) themeToggle.textContent = '☀'; }
    themeToggle?.addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        const isDark = body.classList.contains('dark-mode');
        themeToggle.textContent = isDark ? '☀' : '🌙';
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });

    let currentLang = localStorage.getItem('language') || 'EN';
    if (langToggle) { langToggle.textContent = currentLang; }
    langToggle?.addEventListener('click', () => {
        currentLang = currentLang === 'EN' ? 'SW' : 'EN';
        langToggle.textContent = currentLang;
        localStorage.setItem('language', currentLang);
    });

    profileToggle?.addEventListener('click', e => { e.stopPropagation(); profileMenu?.classList.toggle('show'); });
    document.addEventListener('click', e => { if (!profileArea?.contains(e.target)) profileMenu?.classList.remove('show'); });

    function setupFilters(listId, searchId, subjectId, statusId) {
        const search = document.getElementById(searchId);
        const subject = document.getElementById(subjectId);
        const status = document.getElementById(statusId);
        const items = document.querySelectorAll(listId + ' .quiz-item');
        function apply() {
            const query = (search?.value || '').trim().toLowerCase();
            const subj = (subject?.value || '').trim().toLowerCase();
            const stat = (status?.value || '').trim().toLowerCase();
            items.forEach(item => {
                const s = item.dataset.search || '';
                const sub = item.dataset.subject || '';
                const st = item.dataset.status || '';
                item.style.display = (!query || s.includes(query)) && (!subj || sub === subj) && (!stat || st === stat) ? '' : 'none';
            });
        }
        search?.addEventListener('input', apply);
        subject?.addEventListener('change', apply);
        status?.addEventListener('change', apply);
    }

    setupFilters('#lessonList', 'lessonSearch', 'lessonSubjectFilter', 'lessonStatusFilter');
    setupFilters('#competitionList', 'competitionSearch', 'competitionSubjectFilter', 'competitionStatusFilter');
    setupFilters('#selfList', 'selfSearch', 'selfSubjectFilter', 'selfStatusFilter');

    const allSearch = document.getElementById('allSearch');
    const allMode = document.getElementById('allModeFilter');
    const allStatus = document.getElementById('allStatusFilter');
    const allItems = document.querySelectorAll('#allList .all-quiz-item');
    const allEmpty = document.getElementById('allEmptyState');
    function filterAll() {
        const query = (allSearch?.value || '').trim().toLowerCase();
        const mode = (allMode?.value || '').trim().toLowerCase();
        const status = (allStatus?.value || '').trim().toLowerCase();
        let visible = 0;
        allItems.forEach(item => {
            const s = item.dataset.search || '';
            const m = item.dataset.mode || '';
            const st = item.dataset.status || '';
            const show = (!query || s.includes(query)) && (!mode || m === mode) && (!status || st === status);
            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (allEmpty) allEmpty.style.display = visible ? 'none' : 'block';
    }
    allSearch?.addEventListener('input', filterAll);
    allMode?.addEventListener('change', filterAll);
    allStatus?.addEventListener('change', filterAll);
})();
</script>
</body>
</html>