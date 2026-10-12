<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/admin_auth.php';

header('Content-Type: application/json');

$conn = db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        // Get question
        $stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE id = ?");
        $stmt->execute([$id]);
        $question = $stmt->fetch();
        
        if ($question) {
            // Get options
            $stmt = $conn->prepare("SELECT * FROM quiz_options WHERE question_id = ? ORDER BY sort_order");
            $stmt->execute([$id]);
            $options = $stmt->fetchAll();
            
            // Find correct option for multiple choice
            $correct_answer = null;
            if ($question['question_type'] === 'multiple_choice') {
                foreach ($options as $index => $opt) {
                    if ($opt['is_correct']) {
                        $correct_answer = $index;
                        break;
                    }
                }
            } elseif ($question['question_type'] === 'true_false') {
                $correct_answer = $options[0]['is_correct'] ? 'true' : 'false';
            }
            
            echo json_encode([
                'id' => $question['id'],
                'question_text' => $question['question_text'],
                'question_type' => $question['question_type'],
                'points' => $question['points'],
                'options' => $options,
                'correct_answer' => $correct_answer
            ]);
        } else {
            echo json_encode(['error' => 'Question not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>