<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/admin_auth.php';

header('Content-Type: application/json');

$conn = db();

if (!$conn instanceof PDO) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    exit;
}

function columnExists(PDO $conn, string $tableName, string $columnName): bool
{
    static $cache = [];

    $key = $tableName . '.' . $columnName;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        $cache[$key] = ((int)$stmt->fetchColumn() > 0);
        return $cache[$key];
    } catch (PDOException $e) {
        error_log('columnExists error: ' . $e->getMessage());
        $cache[$key] = false;
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

$quizId = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
$questionId = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;
$questionText = trim((string)($_POST['question_text'] ?? ''));
$questionType = trim((string)($_POST['question_type'] ?? 'multiple_choice'));
$points = isset($_POST['points']) ? (int)$_POST['points'] : 1;
$negativePoints = isset($_POST['negative_points']) ? (int)$_POST['negative_points'] : 0;
$difficulty = trim((string)($_POST['difficulty'] ?? 'medium'));
$sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
$explanation = trim((string)($_POST['explanation'] ?? ''));
$imagePath = trim((string)($_POST['image_path'] ?? ''));

if ($quizId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid quiz ID'
    ]);
    exit;
}

if ($questionText === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Question text is required'
    ]);
    exit;
}

if (!in_array($questionType, ['multiple_choice', 'true_false'], true)) {
    $questionType = 'multiple_choice';
}

if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
    $difficulty = 'medium';
}

if ($points < 1) {
    $points = 1;
}

if ($negativePoints < 0) {
    $negativePoints = 0;
}

try {
    $conn->beginTransaction();

    if ($questionId > 0) {
        $sql = "
            UPDATE quiz_questions
            SET
                question_text = ?,
                question_type = ?,
                points = ?
        ";

        $params = [
            $questionText,
            $questionType,
            $points
        ];

        if (columnExists($conn, 'quiz_questions', 'negative_points')) {
            $sql .= ", negative_points = ? ";
            $params[] = $negativePoints;
        }

        if (columnExists($conn, 'quiz_questions', 'difficulty')) {
            $sql .= ", difficulty = ? ";
            $params[] = $difficulty;
        }

        if (columnExists($conn, 'quiz_questions', 'sort_order')) {
            $sql .= ", sort_order = ? ";
            $params[] = $sortOrder;
        }

        if (columnExists($conn, 'quiz_questions', 'explanation')) {
            $sql .= ", explanation = ? ";
            $params[] = ($explanation !== '') ? $explanation : null;
        }

        if (columnExists($conn, 'quiz_questions', 'image_path')) {
            $sql .= ", image_path = ? ";
            $params[] = ($imagePath !== '') ? $imagePath : null;
        }

        $sql .= " WHERE id = ? AND quiz_id = ? ";
        $params[] = $questionId;
        $params[] = $quizId;

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $checkStmt = $conn->prepare("
                SELECT id
                FROM quiz_questions
                WHERE id = ? AND quiz_id = ?
                LIMIT 1
            ");
            $checkStmt->execute([$questionId, $quizId]);
            if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Question not found for this quiz');
            }
        }

        $stmt = $conn->prepare("DELETE FROM quiz_options WHERE question_id = ?");
        $stmt->execute([$questionId]);
    } else {
        $columns = [
            'quiz_id',
            'question_text',
            'question_type',
            'points'
        ];

        $placeholders = ['?', '?', '?', '?'];
        $params = [$quizId, $questionText, $questionType, $points];

        if (columnExists($conn, 'quiz_questions', 'negative_points')) {
            $columns[] = 'negative_points';
            $placeholders[] = '?';
            $params[] = $negativePoints;
        }

        if (columnExists($conn, 'quiz_questions', 'difficulty')) {
            $columns[] = 'difficulty';
            $placeholders[] = '?';
            $params[] = $difficulty;
        }

        if (columnExists($conn, 'quiz_questions', 'sort_order')) {
            if ($sortOrder > 0) {
                $columns[] = 'sort_order';
                $placeholders[] = '?';
                $params[] = $sortOrder;
            } else {
                $nextSortStmt = $conn->prepare("
                    SELECT COALESCE(MAX(sort_order), 0) + 1
                    FROM quiz_questions
                    WHERE quiz_id = ?
                ");
                $nextSortStmt->execute([$quizId]);
                $nextSortOrder = (int)$nextSortStmt->fetchColumn();

                $columns[] = 'sort_order';
                $placeholders[] = '?';
                $params[] = $nextSortOrder;
            }
        }

        if (columnExists($conn, 'quiz_questions', 'explanation')) {
            $columns[] = 'explanation';
            $placeholders[] = '?';
            $params[] = ($explanation !== '') ? $explanation : null;
        }

        if (columnExists($conn, 'quiz_questions', 'image_path')) {
            $columns[] = 'image_path';
            $placeholders[] = '?';
            $params[] = ($imagePath !== '') ? $imagePath : null;
        }

        if (columnExists($conn, 'quiz_questions', 'created_at')) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        $stmt = $conn->prepare("
            INSERT INTO quiz_questions (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ");
        $stmt->execute($params);

        $questionId = (int)$conn->lastInsertId();
    }

    if ($questionType === 'multiple_choice') {
        $options = $_POST['options'] ?? [];
        $correctOption = (string)($_POST['correct_option'] ?? '');

        $sort = 1;
        $insertedOptions = 0;
        $hasCorrect = false;

        foreach ($options as $key => $optionText) {
            $optionText = trim((string)$optionText);
            if ($optionText === '') {
                $sort++;
                continue;
            }

            $isCorrect = ((string)$key === $correctOption) ? 1 : 0;
            if ($isCorrect === 1) {
                $hasCorrect = true;
            }

            $optionColumns = ['question_id', 'option_text', 'is_correct', 'sort_order'];
            $optionPlaceholders = ['?', '?', '?', '?'];
            $optionParams = [$questionId, $optionText, $isCorrect, $sort];

            if (columnExists($conn, 'quiz_options', 'created_at')) {
                $optionColumns[] = 'created_at';
                $optionPlaceholders[] = 'NOW()';
            }

            $stmt = $conn->prepare("
                INSERT INTO quiz_options (" . implode(', ', $optionColumns) . ")
                VALUES (" . implode(', ', $optionPlaceholders) . ")
            ");
            $stmt->execute($optionParams);

            $insertedOptions++;
            $sort++;
        }

        if ($insertedOptions < 2) {
            throw new RuntimeException('Multiple choice questions need at least 2 options');
        }

        if (!$hasCorrect) {
            throw new RuntimeException('Please select the correct option');
        }
    } elseif ($questionType === 'true_false') {
        $correctAnswer = trim((string)($_POST['correct_option'] ?? ($_POST['tf_correct'] ?? 'true')));
        if (!in_array($correctAnswer, ['true', 'false'], true)) {
            $correctAnswer = 'true';
        }

        $trueFalseOptions = [
            ['text' => 'True', 'correct' => ($correctAnswer === 'true' ? 1 : 0), 'sort' => 1],
            ['text' => 'False', 'correct' => ($correctAnswer === 'false' ? 1 : 0), 'sort' => 2],
        ];

        foreach ($trueFalseOptions as $row) {
            $optionColumns = ['question_id', 'option_text', 'is_correct', 'sort_order'];
            $optionPlaceholders = ['?', '?', '?', '?'];
            $optionParams = [$questionId, $row['text'], $row['correct'], $row['sort']];

            if (columnExists($conn, 'quiz_options', 'created_at')) {
                $optionColumns[] = 'created_at';
                $optionPlaceholders[] = 'NOW()';
            }

            $stmt = $conn->prepare("
                INSERT INTO quiz_options (" . implode(', ', $optionColumns) . ")
                VALUES (" . implode(', ', $optionPlaceholders) . ")
            ");
            $stmt->execute($optionParams);
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Question saved successfully',
        'question_id' => $questionId
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('save_question.php error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>