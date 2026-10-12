<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';

// ============================================================================
// Configuration
// ============================================================================
define('UPLOAD_DIR', __DIR__ . '/../uploads/questions/');
define('UPLOAD_URL', '../uploads/questions/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'A';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters ?: 'A';
}

function columnExists(PDO $conn, string $tableName, string $columnName): bool
{
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

function handleImageUpload(string $fieldName = 'image_file'): array
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => null, 'error' => null];
    }

    $file = $_FILES[$fieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];
        return ['success' => false, 'path' => null, 'error' => $errors[$file['error']] ?? 'Unknown upload error'];
    }

    if (($file['size'] ?? 0) > MAX_FILE_SIZE) {
        return ['success' => false, 'path' => null, 'error' => 'File size exceeds 5MB limit'];
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return ['success' => false, 'path' => null, 'error' => 'Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS)];
    }

    $newName = uniqid('qimg_', true) . '.' . $ext;
    $relativePath = 'uploads/questions/' . $newName;
    $fullPath = UPLOAD_DIR . $newName;

    if (!move_uploaded_file((string)$file['tmp_name'], $fullPath)) {
        return ['success' => false, 'path' => null, 'error' => 'Failed to save uploaded file'];
    }

    return ['success' => true, 'path' => $relativePath, 'error' => null];
}

function getAdminUser(PDO $conn, int $userId): ?array
{
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

function getQuizById(PDO $conn, int $quizId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT
                q.*,
                s.name AS subject_name,
                s.icon AS subject_icon,
                c.name AS category_name
            FROM quizzes q
            LEFT JOIN subjects s ON s.id = q.subject_id
            LEFT JOIN categories c ON c.id = q.category_id
            WHERE q.id = ?
            LIMIT 1
        ");
        $stmt->execute([$quizId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("getQuizById: " . $e->getMessage());
        return null;
    }
}

function getQuizQuestions(PDO $conn, int $quizId): array
{
    try {
        $stmt = $conn->prepare("
            SELECT
                qq.id,
                qq.quiz_id,
                qq.question_text,
                qq.question_type,
                qq.points,
                qq.negative_points,
                qq.difficulty,
                qq.explanation,
                qq.image_path,
                qq.sort_order,
                qq.created_at,
                qq.expected_answer,
                qq.keywords_json
            FROM quiz_questions qq
            WHERE qq.quiz_id = ?
            ORDER BY qq.sort_order ASC, qq.id ASC
        ");
        $stmt->execute([$quizId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($questions as &$question) {
            $opt = $conn->prepare("
                SELECT
                    id,
                    question_id,
                    option_text,
                    image_path,
                    is_correct,
                    sort_order,
                    created_at
                FROM quiz_options
                WHERE question_id = ?
                ORDER BY sort_order ASC, id ASC
            ");
            $opt->execute([(int)$question['id']]);
            $question['options'] = $opt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Convert keywords_json to comma-separated string for display
            $question['keywords_display'] = '';
            if (!empty($question['keywords_json'])) {
                $kw = json_decode($question['keywords_json'], true);
                if (is_array($kw)) {
                    $question['keywords_display'] = implode(', ', array_column($kw, 'word'));
                }
            }
        }

        return $questions;
    } catch (PDOException $e) {
        error_log("getQuizQuestions: " . $e->getMessage());
        return [];
    }
}

function getQuestionById(PDO $conn, int $questionId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, quiz_id, question_text, question_type, points, negative_points, difficulty, explanation, image_path, sort_order, expected_answer, keywords_json
            FROM quiz_questions
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$questionId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$question) return null;

        $opt = $conn->prepare("
            SELECT id, question_id, option_text, image_path, is_correct, sort_order
            FROM quiz_options
            WHERE question_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $opt->execute([$questionId]);
        $question['options'] = $opt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Convert keywords_json to comma-separated string for form pre-fill
        $question['keywords'] = '';
        if (!empty($question['keywords_json'])) {
            $kw = json_decode($question['keywords_json'], true);
            if (is_array($kw)) {
                $question['keywords'] = implode(', ', array_column($kw, 'word'));
            }
        }

        return $question;
    } catch (PDOException $e) {
        error_log("getQuestionById: " . $e->getMessage());
        return null;
    }
}

function deleteQuestion(PDO $conn, int $questionId): bool
{
    try {
        $stmt = $conn->prepare("SELECT image_path FROM quiz_questions WHERE id = ?");
        $stmt->execute([$questionId]);
        $questionImage = $stmt->fetchColumn();

        $stmt = $conn->prepare("SELECT image_path FROM quiz_options WHERE question_id = ?");
        $stmt->execute([$questionId]);
        $optionImages = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $conn->beginTransaction();

        $stmt = $conn->prepare("DELETE FROM quiz_options WHERE question_id = ?");
        $stmt->execute([$questionId]);

        $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE id = ?");
        $stmt->execute([$questionId]);

        $conn->commit();

        $allImages = array_filter(array_merge([$questionImage ?: null], $optionImages));
        foreach ($allImages as $imagePath) {
            $fullPath = __DIR__ . '/../' . ltrim((string)$imagePath, '/');
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        return true;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("deleteQuestion: " . $e->getMessage());
        return false;
    }
}

function updateQuizTotalPoints(PDO $conn, int $quizId): void
{
    $stmt = $conn->prepare("
        UPDATE quizzes
        SET total_points = (
            SELECT COALESCE(SUM(points), 0)
            FROM quiz_questions
            WHERE quiz_id = ?
        )
        WHERE id = ?
    ");
    $stmt->execute([$quizId, $quizId]);
}

function removeOldQuestionImage(?string $oldPath): void
{
    if (!$oldPath) return;
    $fullPath = __DIR__ . '/../' . ltrim((string)$oldPath, '/');
    if (file_exists($fullPath)) {
        @unlink($fullPath);
    }
}

function validateQuestionData(array $post): array
{
    $questionText = trim((string)($post['question_text'] ?? ''));
    $questionType = trim((string)($post['question_type'] ?? 'multiple_choice'));
    $points = isset($post['points']) ? (int)$post['points'] : 1;
    $negativePoints = isset($post['negative_points']) ? (int)$post['negative_points'] : 0;
    $difficulty = trim((string)($post['difficulty'] ?? 'medium'));
    $sortOrder = isset($post['sort_order']) ? (int)$post['sort_order'] : 0;
    $explanation = trim((string)($post['explanation'] ?? ''));
    $expectedAnswer = trim((string)($post['expected_answer'] ?? ''));
    $keywordsInput = trim((string)($post['keywords'] ?? ''));
    $correctOptionMc = trim((string)($post['correct_option_mc'] ?? ''));
    $correctOptionTf = strtolower(trim((string)($post['correct_option_tf'] ?? 'true')));
    $rawOptions = $post['options'] ?? [];

    if ($questionText === '') {
        throw new Exception('Question text is required.');
    }

    if (!in_array($questionType, ['multiple_choice', 'true_false', 'explanation'], true)) {
        $questionType = 'multiple_choice';
    }

    if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
        $difficulty = 'medium';
    }

    if ($points < 1) $points = 1;
    if ($negativePoints < 0) $negativePoints = 0;
    if ($sortOrder < 0) $sortOrder = 0;

    $normalizedOptions = [];

    if ($questionType === 'multiple_choice') {
        $validKeys = ['A', 'B', 'C', 'D'];
        foreach ($validKeys as $key) {
            $normalizedOptions[$key] = trim((string)($rawOptions[$key] ?? ''));
        }

        $nonEmptyOptions = array_filter($normalizedOptions, static fn($value) => $value !== '');
        if (count($nonEmptyOptions) < 2) {
            throw new Exception('Provide at least two non-empty options for a multiple choice question.');
        }

        if (!in_array($correctOptionMc, $validKeys, true)) {
            throw new Exception('Please select a valid correct option.');
        }

        if (($normalizedOptions[$correctOptionMc] ?? '') === '') {
            throw new Exception('The selected correct option must not be empty.');
        }
    }

    if ($questionType === 'true_false') {
        if (!in_array($correctOptionTf, ['true', 'false'], true)) {
            $correctOptionTf = 'true';
        }
    }

    // Process keywords for explanation questions
    $keywordsJson = null;
    if ($questionType === 'explanation' && $keywordsInput !== '') {
        $keywordsArray = [];
        $parts = explode(',', $keywordsInput);
        foreach ($parts as $part) {
            $word = trim($part);
            if ($word !== '') {
                $keywordsArray[] = ['word' => $word, 'weight' => 1];
            }
        }
        $keywordsJson = json_encode($keywordsArray);
    }

    return [
        'question_text' => $questionText,
        'question_type' => $questionType,
        'points' => $points,
        'negative_points' => $negativePoints,
        'difficulty' => $difficulty,
        'sort_order' => $sortOrder,
        'explanation' => $explanation !== '' ? $explanation : null,
        'expected_answer' => $expectedAnswer !== '' ? $expectedAnswer : null,
        'keywords_json' => $keywordsJson,
        'correct_option_mc' => $correctOptionMc,
        'correct_option_tf' => $correctOptionTf,
        'options' => $normalizedOptions,
    ];
}

// ============================================================================
// Auth / DB
// ============================================================================
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn instanceof PDO) {
    die('Unable to connect to database.');
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$admin = getAdminUser($conn, $userId);
if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Insufficient privileges.');
}

$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
if ($quizId <= 0) {
    die('Invalid quiz ID.');
}

$quiz = getQuizById($conn, $quizId);
if (!$quiz) {
    die('Quiz not found.');
}

$message = '';
$messageType = 'success';
$editingQuestion = null;

// ============================================================================
// Actions
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_question') {
        $questionId = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;

        try {
            $data = validateQuestionData($_POST);
            $uploadResult = handleImageUpload('image_file');

            if (!$uploadResult['success']) {
                throw new Exception('Image upload failed: ' . $uploadResult['error']);
            }

            $imagePath = $uploadResult['path'] ?? null;

            $oldQuestion = null;
            if ($questionId > 0) {
                $oldQuestion = getQuestionById($conn, $questionId);
                if (!$oldQuestion || (int)$oldQuestion['quiz_id'] !== $quizId) {
                    throw new Exception('Question not found for this quiz.');
                }
            }

            $conn->beginTransaction();

            if ($questionId > 0) {
                $sql = "
                    UPDATE quiz_questions
                    SET
                        question_text = ?,
                        question_type = ?,
                        points = ?,
                        negative_points = ?,
                        difficulty = ?,
                        explanation = ?,
                        expected_answer = ?,
                        keywords_json = ?,
                        sort_order = ?
                ";
                $params = [
                    $data['question_text'],
                    $data['question_type'],
                    $data['points'],
                    $data['negative_points'],
                    $data['difficulty'],
                    $data['explanation'],
                    $data['expected_answer'],
                    $data['keywords_json'],
                    $data['sort_order'],
                ];

                if ($imagePath !== null) {
                    $sql .= ", image_path = ? ";
                    $params[] = $imagePath;
                } elseif (isset($_POST['clear_image']) && $_POST['clear_image'] === '1') {
                    $sql .= ", image_path = NULL ";
                }

                $sql .= " WHERE id = ? AND quiz_id = ? ";
                $params[] = $questionId;
                $params[] = $quizId;

                $stmt = $conn->prepare($sql);
                $stmt->execute($params);

                $stmt = $conn->prepare("DELETE FROM quiz_options WHERE question_id = ?");
                $stmt->execute([$questionId]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO quiz_questions
                    (
                        quiz_id,
                        question_text,
                        question_type,
                        points,
                        negative_points,
                        difficulty,
                        explanation,
                        expected_answer,
                        keywords_json,
                        image_path,
                        sort_order
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $quizId,
                    $data['question_text'],
                    $data['question_type'],
                    $data['points'],
                    $data['negative_points'],
                    $data['difficulty'],
                    $data['explanation'],
                    $data['expected_answer'],
                    $data['keywords_json'],
                    $imagePath,
                    $data['sort_order'],
                ]);
                $questionId = (int)$conn->lastInsertId();
            }

            if ($data['question_type'] === 'true_false') {
                $trueFalseOptions = [
                    ['key' => 'true', 'text' => 'True', 'sort' => 1],
                    ['key' => 'false', 'text' => 'False', 'sort' => 2],
                ];

                $stmt = $conn->prepare("
                    INSERT INTO quiz_options
                    (question_id, option_text, image_path, is_correct, sort_order)
                    VALUES (?, ?, NULL, ?, ?)
                ");

                foreach ($trueFalseOptions as $row) {
                    $stmt->execute([
                        $questionId,
                        $row['text'],
                        strtolower($data['correct_option_tf']) === $row['key'] ? 1 : 0,
                        $row['sort'],
                    ]);
                }
            } elseif ($data['question_type'] === 'multiple_choice') {
                $validKeys = ['A', 'B', 'C', 'D'];
                $insertOption = $conn->prepare("
                    INSERT INTO quiz_options
                    (question_id, option_text, image_path, is_correct, sort_order)
                    VALUES (?, ?, NULL, ?, ?)
                ");

                $sort = 1;
                foreach ($validKeys as $key) {
                    $text = trim((string)$data['options'][$key]);
                    if ($text === '') {
                        continue;
                    }

                    $isCorrect = ($key === $data['correct_option_mc']) ? 1 : 0;
                    $insertOption->execute([
                        $questionId,
                        $text,
                        $isCorrect,
                        $sort,
                    ]);
                    $sort++;
                }
            }

            updateQuizTotalPoints($conn, $quizId);
            $conn->commit();

            if ($oldQuestion) {
                if ($imagePath !== null && !empty($oldQuestion['image_path'])) {
                    removeOldQuestionImage((string)$oldQuestion['image_path']);
                }
                if (isset($_POST['clear_image']) && $_POST['clear_image'] === '1' && !empty($oldQuestion['image_path'])) {
                    removeOldQuestionImage((string)$oldQuestion['image_path']);
                }
            }

            $message = 'Question saved successfully.';
            $messageType = 'success';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("save_question: " . $e->getMessage());
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to save question.';
            $messageType = 'danger';
        }
    }

    if ($action === 'delete_question') {
        $questionId = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;
        if ($questionId > 0) {
            if (deleteQuestion($conn, $questionId)) {
                updateQuizTotalPoints($conn, $quizId);
                $message = 'Question deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Unable to delete question.';
                $messageType = 'danger';
            }
        }
    }
}

if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $editingQuestion = getQuestionById($conn, (int)$_GET['edit']);
}

$questions = getQuizQuestions($conn, $quizId);

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

$quizMode = strtolower((string)($quiz['mode'] ?? 'lesson'));
if (!in_array($quizMode, ['lesson', 'competition', 'self'], true)) {
    $quizMode = 'lesson';
}
$backTab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : $quizMode;

// ============================================================================
// Pre-fill editor values
// ============================================================================
$existingOptions = $editingQuestion['options'] ?? [];
$optionMap = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
$correctKey = 'A';
$explanationValue = (string)($editingQuestion['explanation'] ?? '');
$expectedAnswerValue = (string)($editingQuestion['expected_answer'] ?? '');
$keywordsValue = (string)($editingQuestion['keywords'] ?? '');

if (!empty($existingOptions) && (($editingQuestion['question_type'] ?? 'multiple_choice') === 'multiple_choice')) {
    $keys = array_keys($optionMap);
    foreach ($existingOptions as $index => $opt) {
        if (!isset($keys[$index])) break;
        $key = $keys[$index];
        $optionMap[$key] = (string)($opt['option_text'] ?? '');
        if ((int)($opt['is_correct'] ?? 0) === 1) {
            $correctKey = $key;
        }
    }
}

if (($editingQuestion['question_type'] ?? '') === 'true_false' && !empty($existingOptions)) {
    foreach ($existingOptions as $opt) {
        if ((int)($opt['is_correct'] ?? 0) === 1) {
            $correctKey = strtolower((string)($opt['option_text'] ?? 'true')) === 'false' ? 'false' : 'true';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Quiz Questions - MyIslam</title>
<style>
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
.main-content{padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px);max-width:1500px;margin:0 auto}
.page-stack{display:grid;gap:24px}
.section-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px;transition:.2s}
.section-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none}
.hero-card h1{font-size:32px;margin-bottom:8px;font-weight:700}
.hero-card p{color:var(--text-secondary);font-size:16px}
.form-grid{display:grid;grid-template-columns:1fr 1.35fr;gap:24px}
.form-card h3,.table-card h3{font-size:12px;margin-bottom:16px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:700}
.form-fields{display:grid;gap:14px}
.form-row{display:grid;gap:8px}
.form-row label{font-size:14px;font-weight:700}
.form-input,.form-textarea,.form-select{width:100%;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:12px 14px;outline:none}
.form-input:focus,.form-textarea:focus,.form-select:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.form-textarea{min-height:130px;resize:vertical}
.inline-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.option-grid{display:grid;gap:10px}
.action-row{display:flex;gap:12px;flex-wrap:wrap}
.btn{padding:12px 16px;border-radius:14px;font-weight:700;border:none;cursor:pointer;transition:.2s}
.btn.primary{background:var(--brand);color:#fff}
.btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
.btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
.btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.btn:hover{transform:translateY(-2px)}
.notice{padding:14px 16px;border-radius:14px;border:1px solid var(--line);background:var(--surface-2)}
.notice.success{border-color:#86efac;background:rgba(34,197,94,.12)}
.notice.danger{border-color:#fca5a5;background:rgba(239,68,68,.12)}
.question-list{display:grid;gap:12px}
.question-item{padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px}
.question-top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}
.question-title{font-size:16px;font-weight:800}
.question-meta{font-size:12px;color:var(--muted);line-height:1.7}
.option-list{display:grid;gap:8px;margin-top:12px}
.option-item{padding:10px 12px;background:var(--surface);border:1px solid var(--line);border-radius:12px;font-size:13px}
.option-item.correct{border-color:#86efac;background:rgba(34,197,94,.08)}
.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700}
.badge.lesson{background:#d1fae5;color:#065f46}
.badge.competition{background:#fef3c7;color:#92400e}
.badge.self{background:#ede9fe;color:#5b21b6}
.badge.explanation{background:#fce7f3;color:#be185d}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}

#trueFalseOptions, #explanationOptions{display:none}
#trueFalseOptions.show, #explanationOptions.show{display:grid}
#multipleChoiceOptions.hide{display:none}
.image-preview{margin-top:8px;max-width:200px;border-radius:8px;border:1px solid var(--line)}
.image-preview img{width:100%;height:auto;border-radius:8px}
.file-input-wrapper{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.file-input-wrapper input[type="file"]{flex:1}
.clear-image-btn{background:var(--danger);color:#fff;padding:6px 12px;border-radius:6px;font-size:12px}
.question-image{max-width:150px;margin-top:8px}
.question-image img{width:100%;border-radius:8px}
@media (max-width:1200px){.form-grid{grid-template-columns:1fr}}
@media (max-width:950px){.header-top-nav{display:none}}
@media (max-width:768px){
:root{--header-height:64px;--footer-height:80px}
.main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}
.section-card{padding:16px}.hero-card h1{font-size:24px}.footer-grid,.inline-row{grid-template-columns:1fr;gap:12px}.question-top{flex-direction:column}.footer-action{min-height:64px}
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
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">🌙</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <button class="icon-btn" aria-label="Notifications">🔔</button>

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
                <h1>❓ Manage Quiz Questions</h1>
                <p>
                    Quiz: <strong><?php echo e($quiz['title'] ?? 'Unknown Quiz'); ?></strong>
                    <?php if (!empty($quiz['subject_name'])): ?>
                        — Subject: <strong><?php echo e($quiz['subject_name']); ?></strong>
                    <?php endif; ?>
                    — <span class="badge <?php echo e($quizMode); ?>"><?php echo e(ucfirst($quizMode)); ?> Mode</span>
                </p>
            </section>

            <?php if ($message !== ''): ?>
                <div class="notice <?php echo $messageType === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo e($message); ?>
                </div>
            <?php endif; ?>

            <div class="form-grid">
                <section class="section-card form-card">
                    <h3><?php echo $editingQuestion ? 'Edit Question' : 'Add Question'; ?></h3>

                    <form method="POST" class="form-fields" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_question">
                        <input type="hidden" name="question_id" value="<?php echo (int)($editingQuestion['id'] ?? 0); ?>">
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo MAX_FILE_SIZE; ?>">

                        <div class="form-row">
                            <label for="question_text">Question Text</label>
                            <textarea id="question_text" name="question_text" class="form-textarea" required placeholder="Write the full question here"><?php echo e($editingQuestion['question_text'] ?? ''); ?></textarea>
                        </div>

                        <div class="inline-row">
                            <div class="form-row">
                                <label for="question_type">Question Type</label>
                                <select id="question_type" name="question_type" class="form-select">
                                    <option value="multiple_choice" <?php echo (($editingQuestion['question_type'] ?? 'multiple_choice') === 'multiple_choice') ? 'selected' : ''; ?>>Multiple Choice</option>
                                    <option value="true_false" <?php echo (($editingQuestion['question_type'] ?? '') === 'true_false') ? 'selected' : ''; ?>>True / False</option>
                                    <option value="explanation" <?php echo (($editingQuestion['question_type'] ?? '') === 'explanation') ? 'selected' : ''; ?>>Explanation (Open-ended)</option>
                                </select>
                            </div>

                            <div class="form-row">
                                <label for="difficulty">Difficulty</label>
                                <select id="difficulty" name="difficulty" class="form-select">
                                    <option value="easy" <?php echo (($editingQuestion['difficulty'] ?? 'medium') === 'easy') ? 'selected' : ''; ?>>Easy</option>
                                    <option value="medium" <?php echo (($editingQuestion['difficulty'] ?? 'medium') === 'medium') ? 'selected' : ''; ?>>Medium</option>
                                    <option value="hard" <?php echo (($editingQuestion['difficulty'] ?? 'medium') === 'hard') ? 'selected' : ''; ?>>Hard</option>
                                </select>
                            </div>
                        </div>

                        <div class="inline-row">
                            <div class="form-row">
                                <label for="points">Points</label>
                                <input type="number" id="points" name="points" class="form-input" min="1" value="<?php echo e((string)($editingQuestion['points'] ?? '1')); ?>">
                            </div>

                            <div class="form-row">
                                <label for="negative_points">Negative Points</label>
                                <input type="number" id="negative_points" name="negative_points" class="form-input" min="0" value="<?php echo e((string)($editingQuestion['negative_points'] ?? '0')); ?>">
                            </div>
                        </div>

                        <div class="inline-row">
                            <div class="form-row">
                                <label for="sort_order">Sort Order</label>
                                <input type="number" id="sort_order" name="sort_order" class="form-input" min="0" value="<?php echo e((string)($editingQuestion['sort_order'] ?? '0')); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <label for="image_file">Question Image (Optional)</label>
                            <div class="file-input-wrapper">
                                <input type="file" id="image_file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewImage(this)">
                                <?php if ($editingQuestion && !empty($editingQuestion['image_path'])): ?>
                                    <button type="button" class="clear-image-btn" onclick="clearExistingImage()">Remove Image</button>
                                    <input type="hidden" name="clear_image" id="clear_image" value="0">
                                <?php endif; ?>
                            </div>
                            <div id="imagePreviewContainer" class="image-preview" style="<?php echo ($editingQuestion && !empty($editingQuestion['image_path'])) ? '' : 'display:none;'; ?>">
                                <?php if ($editingQuestion && !empty($editingQuestion['image_path'])): ?>
                                    <img src="../<?php echo e($editingQuestion['image_path']); ?>" alt="Question image">
                                <?php endif; ?>
                                <img id="imagePreview" src="#" alt="Preview" style="display:none;">
                            </div>
                            <small style="color: var(--muted);">Max size: 5MB. Allowed: JPG, PNG, GIF, WEBP</small>
                        </div>

                        <div id="multipleChoiceOptions" class="<?php echo (($editingQuestion['question_type'] ?? 'multiple_choice') !== 'multiple_choice') ? 'hide' : ''; ?>">
                            <div class="form-row">
                                <label>Answer Options</label>
                                <div class="option-grid">
                                    <input type="text" name="options[A]" class="form-input" placeholder="Option A" value="<?php echo e($optionMap['A']); ?>">
                                    <input type="text" name="options[B]" class="form-input" placeholder="Option B" value="<?php echo e($optionMap['B']); ?>">
                                    <input type="text" name="options[C]" class="form-input" placeholder="Option C" value="<?php echo e($optionMap['C']); ?>">
                                    <input type="text" name="options[D]" class="form-input" placeholder="Option D" value="<?php echo e($optionMap['D']); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <label for="correct_option_mc">Correct Option</label>
                                <select id="correct_option_mc" name="correct_option_mc" class="form-select">
                                    <option value="A" <?php echo $correctKey === 'A' ? 'selected' : ''; ?>>Option A</option>
                                    <option value="B" <?php echo $correctKey === 'B' ? 'selected' : ''; ?>>Option B</option>
                                    <option value="C" <?php echo $correctKey === 'C' ? 'selected' : ''; ?>>Option C</option>
                                    <option value="D" <?php echo $correctKey === 'D' ? 'selected' : ''; ?>>Option D</option>
                                </select>
                            </div>
                        </div>

                        <div id="trueFalseOptions" class="<?php echo (($editingQuestion['question_type'] ?? 'multiple_choice') === 'true_false') ? 'show' : ''; ?>">
                            <div class="form-row">
                                <label for="correct_option_tf">Correct Answer</label>
                                <select id="correct_option_tf" name="correct_option_tf" class="form-select">
                                    <option value="true" <?php echo $correctKey === 'true' ? 'selected' : ''; ?>>True</option>
                                    <option value="false" <?php echo $correctKey === 'false' ? 'selected' : ''; ?>>False</option>
                                </select>
                            </div>
                        </div>

                        <div id="explanationOptions" class="<?php echo (($editingQuestion['question_type'] ?? 'multiple_choice') === 'explanation') ? 'show' : ''; ?>">
                            <div class="form-row">
                                <label for="expected_answer">Expected Answer (Model Answer)</label>
                                <textarea id="expected_answer" name="expected_answer" class="form-textarea" placeholder="Provide the ideal answer for grading"><?php echo e($expectedAnswerValue); ?></textarea>
                            </div>
                            <div class="form-row">
                                <label for="keywords">Keywords (comma separated, optional)</label>
                                <input type="text" id="keywords" name="keywords" class="form-input" placeholder="e.g., prayer, salah, five times" value="<?php echo e($keywordsValue); ?>">
                                <small style="color: var(--muted);">These keywords help auto‑grading. Leave empty to use similarity only.</small>
                            </div>
                            <div class="form-row">
                                <label for="explanation">Explanation / Feedback (shown after submission)</label>
                                <textarea id="explanation" name="explanation" class="form-textarea" placeholder="Optional feedback shown to the student"><?php echo e($explanationValue); ?></textarea>
                            </div>
                        </div>

                        <div class="action-row">
                            <button type="submit" class="btn primary"><?php echo $editingQuestion ? 'Update Question' : 'Save Question'; ?></button>
                            <?php if ($editingQuestion): ?>
                                <a href="admin_quiz_questions.php?quiz_id=<?php echo (int)$quizId; ?>&tab=<?php echo e($backTab); ?>" class="btn secondary">Cancel Edit</a>
                            <?php endif; ?>
                            <a href="admin_quizzes.php?edit=<?php echo (int)$quizId; ?>&tab=<?php echo e($backTab); ?>" class="btn warn">Back to Quiz</a>
                        </div>
                    </form>
                </section>

                <section class="section-card table-card">
                    <h3>All Questions</h3>

                    <?php if (!empty($questions)): ?>
                        <div class="question-list">
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="question-item">
                                    <div class="question-top">
                                        <div>
                                            <div class="question-title">
                                                Q<?php echo $index + 1; ?>. <?php echo e($question['question_text'] ?? 'Untitled Question'); ?>
                                                <?php if (($question['question_type'] ?? '') === 'explanation'): ?>
                                                    <span class="badge explanation">Explanation</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="question-meta">
                                                Type: <?php echo e(ucfirst(str_replace('_', ' ', (string)($question['question_type'] ?? 'multiple_choice')))); ?><br>
                                                Difficulty: <?php echo e(ucfirst((string)($question['difficulty'] ?? 'medium'))); ?><br>
                                                Points: <?php echo (int)($question['points'] ?? 0); ?> |
                                                Negative: <?php echo (int)($question['negative_points'] ?? 0); ?> |
                                                Sort: <?php echo (int)($question['sort_order'] ?? 0); ?>
                                            </div>
                                            <?php if (!empty($question['image_path'])): ?>
                                                <div class="question-image">
                                                    <img src="../<?php echo e($question['image_path']); ?>" alt="Question image" loading="lazy">
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="action-row">
                                            <a href="admin_quiz_questions.php?quiz_id=<?php echo (int)$quizId; ?>&edit=<?php echo (int)$question['id']; ?>&tab=<?php echo e($backTab); ?>" class="btn secondary">Edit</a>
                                            <form method="POST" onsubmit="return confirm('Delete this question?');">
                                                <input type="hidden" name="action" value="delete_question">
                                                <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                                <button type="submit" class="btn danger">Delete</button>
                                            </form>
                                        </div>
                                    </div>

                                    <?php if (($question['question_type'] ?? '') !== 'explanation' && !empty($question['options'])): ?>
                                        <div class="option-list">
                                            <?php foreach ($question['options'] as $opt): ?>
                                                <div class="option-item <?php echo ((int)($opt['is_correct'] ?? 0) === 1) ? 'correct' : ''; ?>">
                                                    <?php echo e((string)($opt['option_text'] ?? '')); ?>
                                                    <?php if ((int)($opt['is_correct'] ?? 0) === 1): ?>
                                                        <strong> — Correct Answer</strong>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="question-meta" style="margin-top:12px;">
                                        <?php if (($question['question_type'] ?? '') === 'explanation'): ?>
                                            <strong>Expected Answer:</strong>
                                            <?php echo !empty($question['expected_answer']) ? nl2br(e((string)$question['expected_answer'])) : 'No expected answer provided.'; ?>
                                            <?php if (!empty($question['keywords_display'])): ?>
                                                <br><strong>Keywords:</strong> <?php echo e($question['keywords_display']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($question['explanation'])): ?>
                                                <br><strong>Feedback:</strong> <?php echo nl2br(e((string)$question['explanation'])); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <strong>Explanation / Feedback:</strong>
                                            <?php echo !empty($question['explanation']) ? nl2br(e((string)$question['explanation'])) : 'No explanation provided.'; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">No questions added yet for this quiz.</div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>

    
</div>

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

    const questionType = document.getElementById('question_type');
    const multipleChoiceOptions = document.getElementById('multipleChoiceOptions');
    const trueFalseOptions = document.getElementById('trueFalseOptions');
    const explanationOptions = document.getElementById('explanationOptions');
    const correctOptionMc = document.getElementById('correct_option_mc');
    const correctOptionTf = document.getElementById('correct_option_tf');

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

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        body.classList.add('dark-mode');
        if (themeToggle) themeToggle.textContent = '☀';
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            themeToggle.textContent = isDark ? '☀' : '🌙';
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
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
            if (!profileArea.contains(e.target)) {
                profileMenu.classList.remove('show');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function toggleQuestionTypeFields() {
        if (!questionType) return;
        const type = questionType.value;

        if (multipleChoiceOptions) multipleChoiceOptions.classList.toggle('hide', type !== 'multiple_choice');
        if (trueFalseOptions) trueFalseOptions.classList.toggle('show', type === 'true_false');
        if (explanationOptions) explanationOptions.classList.toggle('show', type === 'explanation');

        if (correctOptionMc) correctOptionMc.disabled = type !== 'multiple_choice';
        if (correctOptionTf) correctOptionTf.disabled = type !== 'true_false';
    }

    questionType?.addEventListener('change', toggleQuestionTypeFields);
    toggleQuestionTypeFields();

    window.previewImage = function(input) {
        const container = document.getElementById('imagePreviewContainer');
        const preview = document.getElementById('imagePreview');
        const existingImg = container ? container.querySelector('img:not(#imagePreview)') : null;

        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                if (!preview || !container) return;
                preview.src = e.target.result;
                preview.style.display = 'block';
                if (existingImg) existingImg.style.display = 'none';
                container.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        } else {
            if (preview) preview.style.display = 'none';
            if (existingImg) existingImg.style.display = '';
            if (!existingImg && container) container.style.display = 'none';
        }
    };

    window.clearExistingImage = function() {
        const clearInput = document.getElementById('clear_image');
        const container = document.getElementById('imagePreviewContainer');
        const preview = document.getElementById('imagePreview');
        const existingImg = container ? container.querySelector('img:not(#imagePreview)') : null;
        const fileInput = document.getElementById('image_file');

        if (clearInput) clearInput.value = '1';
        if (existingImg) existingImg.style.display = 'none';
        if (preview) preview.style.display = 'none';
        if (fileInput) fileInput.value = '';
    };
})();
</script>
</body>
</html>