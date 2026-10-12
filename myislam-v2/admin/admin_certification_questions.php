<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_functions.php';

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ---- Admin authentication ----
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();
if (!$conn) die('Database connection error.');

function getAdminUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("SELECT id, full_name, email, profile_image, role, is_active FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return ($user && in_array(strtolower($user['role'] ?? ''), ['admin','owner','developer'])) ? $user : null;
    } catch (PDOException $e) {
        return null;
    }
}

function handleImageUpload(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    if (!in_array($ext, $allowed)) return null;
    
    $uploadDir = __DIR__ . '/../uploads/exams/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $filename = uniqid('cert_', true) . '.' . $ext;
    $destination = $uploadDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'uploads/exams/' . $filename;
    }
    return null;
}

// ---- CSRF Token ----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ---- Admin check ----
$admin = getAdminUser($conn, $userId);
if (!$admin) {
    http_response_code(403);
    exit('Access denied.');
}

// ---- Exam ID ----
$examId = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$stmt = $conn->prepare("SELECT * FROM certification_exams WHERE id = ?");
$stmt->execute([$examId]);
$exam = $stmt->fetch();
if (!$exam) die('Exam not found.');

// ========== Process POST actions (AJAX) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // Verify CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response = ['success' => false, 'message' => 'Invalid security token.'];
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        die('Invalid security token.');
    }

    if ($action === 'save_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $text = trim($_POST['question_text'] ?? '');
        $type = $_POST['question_type'] ?? 'multiple_choice';
        $points = (int)($_POST['points'] ?? 1);
        $expl = trim($_POST['explanation'] ?? '');
        $expectedAnswer = trim($_POST['correct_answer_text'] ?? '');
        $keywordsInput = trim($_POST['keywords'] ?? '');
        $imagePath = $_POST['image_path'] ?? '';
        $difficulty = $_POST['difficulty'] ?? null; // optional

        // Handle question image upload
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $uploaded = handleImageUpload($_FILES['question_image']);
            if ($uploaded) $imagePath = $uploaded;
        }

        if ($text === '') {
            $response = ['success' => false, 'message' => 'Question text required.'];
        } else {
            try {
                $conn->beginTransaction();

                // Insert/update core question
                if ($qid > 0) {
                    $stmt = $conn->prepare("UPDATE certification_exam_questions SET question_text=?, question_type=?, points=?, image_path=?, explanation=?, expected_answer=NULL, keywords_json=NULL WHERE id=? AND exam_id=?");
                    $stmt->execute([$text, $type, $points, $imagePath ?: null, $expl ?: null, $qid, $examId]);
                } else {
                    $maxOrder = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM certification_exam_questions WHERE exam_id = ?");
                    $maxOrder->execute([$examId]);
                    $sortOrder = (int)$maxOrder->fetchColumn();
                    $stmt = $conn->prepare("INSERT INTO certification_exam_questions (exam_id, question_text, question_type, points, image_path, explanation, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$examId, $text, $type, $points, $imagePath ?: null, $expl ?: null, $sortOrder]);
                    $qid = $conn->lastInsertId();
                }

                // Clear existing options (FK cascade may delete, but we do it explicitly)
                $conn->prepare("DELETE FROM certification_exam_question_options WHERE question_id = ?")->execute([$qid]);

                // Process keywords for explanation
                $keywordsJson = null;
                if ($type === 'explanation' && $keywordsInput !== '') {
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

                // Handle explanation specific fields
                if ($type === 'explanation') {
                    $stmt = $conn->prepare("UPDATE certification_exam_questions SET expected_answer = ?, keywords_json = ? WHERE id = ?");
                    $stmt->execute([$expectedAnswer ?: null, $keywordsJson, $qid]);
                } else {
                    // Clear explanation fields for non-explanation types
                    $conn->prepare("UPDATE certification_exam_questions SET expected_answer = NULL, keywords_json = NULL WHERE id = ?")->execute([$qid]);
                }

                // Handle options for multiple_choice and true_false
                if ($type === 'multiple_choice' && isset($_POST['options'])) {
                    $correctIdx = (int)($_POST['correct_option_index'] ?? -1);
                    $optTexts = $_POST['options'] ?? [];
                    $optImagePaths = $_POST['option_image_path'] ?? [];
                    $optImages = $_FILES['option_image'] ?? [];

                    foreach ($optTexts as $idx => $optText) {
                        $optText = trim($optText);
                        if ($optText === '') continue;
                        $isCorrect = ($idx === $correctIdx) ? 1 : 0;
                        $optImagePath = $optImagePaths[$idx] ?? '';

                        if (!empty($optImages['name'][$idx]) && $optImages['error'][$idx] === UPLOAD_ERR_OK) {
                            $file = [
                                'name'     => $optImages['name'][$idx],
                                'type'     => $optImages['type'][$idx],
                                'tmp_name' => $optImages['tmp_name'][$idx],
                                'error'    => $optImages['error'][$idx],
                                'size'     => $optImages['size'][$idx],
                            ];
                            $uploaded = handleImageUpload($file);
                            if ($uploaded) $optImagePath = $uploaded;
                        }

                        $conn->prepare("INSERT INTO certification_exam_question_options (question_id, option_text, image_path, is_correct, sort_order) VALUES (?, ?, ?, ?, ?)")
                             ->execute([$qid, $optText, $optImagePath ?: null, $isCorrect, $idx]);
                    }
                } elseif ($type === 'true_false') {
                    $correct = strtolower($_POST['correct_answer'] ?? 'true') === 'true' ? 1 : 0;
                    $conn->prepare("INSERT INTO certification_exam_question_options (question_id, option_text, is_correct, sort_order) VALUES (?, 'True', ?, 0), (?, 'False', ?, 1)")
                         ->execute([$qid, $correct, $qid, 1 - $correct]);
                }

                $conn->commit();
                $response = ['success' => true, 'message' => 'Question saved successfully.'];
            } catch (PDOException $e) {
                $conn->rollBack();
                $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
    }

    if ($action === 'delete_question') {
        $qid = (int)$_POST['question_id'];
        $conn->prepare("DELETE FROM certification_exam_questions WHERE id = ? AND exam_id = ?")->execute([$qid, $examId]);
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'Question deleted.']);
            exit;
        }
        header("Location: admin_certification_questions.php?exam_id=$examId");
        exit;
    }

    if ($action === 'update_points') {
        $total = $conn->prepare("SELECT SUM(points) FROM certification_exam_questions WHERE exam_id = ?");
        $total->execute([$examId]);
        $total = (int)$total->fetchColumn();
        // Not all certification_exams tables have a total_points column, so we skip updating it.
        // But we can return the total for display.
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'Total points calculated: ' . $total . '.']);
            exit;
        }
    }
}

// ========== Fetch questions with options ==========
$stmt = $conn->prepare("
    SELECT q.*
    FROM certification_exam_questions q
    WHERE q.exam_id = ?
    ORDER BY q.sort_order, q.id
");
$stmt->execute([$examId]);
$questions = $stmt->fetchAll();

// ---------- AJAX endpoint for editing a question ----------
if (isset($_GET['edit']) && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $editId = (int)$_GET['edit'];
    
    $stmt = $conn->prepare("SELECT * FROM certification_exam_questions WHERE id = ? AND exam_id = ?");
    $stmt->execute([$editId, $examId]);
    $q = $stmt->fetch();

    if (!$q) {
        http_response_code(404);
        echo json_encode(['error' => 'Question not found.']);
        exit;
    }

    // Options
    $opts = $conn->prepare("SELECT * FROM certification_exam_question_options WHERE question_id = ? ORDER BY sort_order");
    $opts->execute([$editId]);
    $q['options'] = $opts->fetchAll();

    // True / false
    if ($q['question_type'] === 'true_false') {
        $stmt = $conn->prepare("SELECT is_correct FROM certification_exam_question_options WHERE question_id = ? AND option_text = 'True'");
        $stmt->execute([$editId]);
        $q['correct_answer'] = $stmt->fetchColumn() ? 'true' : 'false';
    } else {
        $q['correct_answer'] = '';
    }

    // Keywords (safe conversion)
    $q['keywords'] = '';
    if (!empty($q['keywords_json'])) {
        $kw = json_decode($q['keywords_json'], true);
        if (is_array($kw)) {
            $q['keywords'] = implode(', ', array_column($kw, 'word'));
        }
    }

    // Ensure every key the JS expects is present
    $q['expected_answer'] = $q['expected_answer'] ?? '';
    $q['explanation']    = $q['explanation']    ?? '';
    $q['image_path']     = $q['image_path']     ?? '';
    $q['points']         = (int)($q['points']   ?? 0);
    $q['question_type']  = $q['question_type']  ?? 'explanation';

    echo json_encode($q);
    exit;
}

// Total points
$totalStmt = $conn->prepare("SELECT SUM(points) FROM certification_exam_questions WHERE exam_id = ?");
$totalStmt->execute([$examId]);
$totalPoints = $totalStmt->fetchColumn() ?: 0;

// Self-script for AJAX
$selfScript = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certification Exam Questions – <?= e($exam['title']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{
            --primary: #2d6a4f; --primary-light: #40916c; --primary-soft: #d8f3dc;
            --danger: #e63946; --success: #2a9d8f; --warning: #e9c46a;
            --text-dark: #1b4332; --text-light: #52796f; --text-muted: #6c757d;
            --bg-page: #f4f7f2; --surface: #ffffff; --surface-2: #f8faf8;
            --border: #e0e7e0; --shadow: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.02);
            --shadow-lg: 0 20px 30px -10px rgba(0,0,0,0.1);
            --radius: 16px; --radius-sm: 12px;
        }
        body{
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-page);
            color: var(--text-dark);
            line-height: 1.6;
            padding: 24px 32px;
            min-height: 100vh;
        }
        .container{max-width: 1200px; margin: 0 auto;}
        .page-header{
            display: flex; align-items: center; flex-wrap: wrap; gap: 20px 30px;
            margin-bottom: 32px; padding-bottom: 20px; border-bottom: 1px solid var(--border);
        }
        .back-link{
            display: flex; align-items: center; gap: 8px; text-decoration: none;
            color: var(--primary); font-weight: 600; transition: all 0.2s;
            padding: 8px 0;
        }
        .back-link:hover{color: var(--primary-light);}
        .page-title{font-size: 28px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-dark);}
        .exam-meta{
            margin-left: auto; display: flex; gap: 24px;
            background: var(--surface); padding: 10px 24px; border-radius: 40px;
            box-shadow: var(--shadow); border: 1px solid var(--border);
        }
        .exam-meta span{display: flex; align-items: center; gap: 8px;}
        .exam-meta i{color: var(--primary);}
        .action-bar{
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px;
        }
        .btn{
            display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px;
            border-radius: 40px; font-weight: 600; font-size: 15px; border: none;
            cursor: pointer; transition: all 0.2s ease; text-decoration: none;
            background: var(--surface); color: var(--text-dark); border: 1px solid var(--border);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .btn-primary{
            background: var(--primary); color: white; border: none;
            box-shadow: 0 8px 16px -4px rgba(45,106,79,0.25);
        }
        .btn-primary:hover{background: var(--primary-light); transform: translateY(-2px);}
        .btn-outline{background: transparent; border: 1px solid var(--border);}
        .btn-outline:hover{background: var(--surface-2); border-color: var(--primary);}
        .btn-danger{background: #fff1f0; color: var(--danger); border-color: #ffccc7;}
        .btn-danger:hover{background: #ffe7e5;}
        .btn-sm{padding: 8px 16px; font-size: 13px;}
        .questions-container{display: flex; flex-direction: column; gap: 16px;}
        .question-card{
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); padding: 20px 24px;
            box-shadow: var(--shadow); transition: all 0.2s;
        }
        .question-card:hover{box-shadow: var(--shadow-lg); border-color: var(--primary-soft);}
        .question-header{display: flex; align-items: flex-start; gap: 16px;}
        .question-number{
            display: flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; background: var(--primary-soft);
            color: var(--primary); border-radius: 10px; font-weight: 700;
            font-size: 16px; flex-shrink: 0;
        }
        .question-content{flex:1;}
        .question-text-row{display: flex; align-items: flex-start; gap: 20px;}
        .question-text{font-size: 16px; font-weight: 600; margin-bottom: 8px; flex: 1;}
        .question-image{
            max-width: 160px; max-height: 100px; border-radius: 10px;
            border: 1px solid var(--border); object-fit: cover; margin-left: auto;
        }
        .badge{
            background: var(--surface-2); padding: 4px 10px; border-radius: 40px;
            font-size: 12px; font-weight: 500; color: var(--text-light);
            border: 1px solid var(--border); display: inline-block; margin-right: 8px;
        }
        .badge-primary{background: var(--primary-soft); color: var(--primary); border-color: var(--primary-light);}
        .question-meta{
            display: flex; gap: 16px; margin: 10px 0 6px; color: var(--text-light);
            font-size: 13px; flex-wrap: wrap;
        }
        .options-list{display: grid; grid-template-columns: repeat(2,1fr); gap: 8px 20px; margin: 10px 0 0;}
        .option-item{display: flex; align-items: center; gap: 6px; font-size: 14px;}
        .option-correct{color: var(--success); font-weight: 600;}
        .option-normal{color: var(--text-light);}
        .explanation-box{
            background: var(--surface-2); border-radius: 10px; padding: 12px 16px;
            margin-top: 12px; border-left: 4px solid var(--primary); font-size: 13px;
        }
        .card-actions{
            display: flex; gap: 10px; margin-top: 16px; justify-content: flex-end;
            border-top: 1px dashed var(--border); padding-top: 14px;
        }
        .empty-state{
            text-align: center; padding: 60px 20px; background: var(--surface);
            border-radius: var(--radius); border: 1px solid var(--border);
            color: var(--text-muted);
        }
        .empty-state i{font-size: 48px; margin-bottom: 16px; opacity: 0.5;}
        .modal-overlay{
            display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.4); backdrop-filter: blur(6px);
            align-items: center; justify-content: center; z-index: 1000;
        }
        .mode-pill.certification {
    background: #f5f3ff;
    color: #5b21b6;
}
        .modal{
            background: var(--surface); border-radius: 24px; width: 90%; max-width: 750px;
            max-height: 85vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            border: 1px solid var(--border); padding: 0;
        }
        .modal-header{
            padding: 24px 28px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .modal-header h2{font-size: 22px; font-weight: 700;}
        .modal-close{background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-muted);}
        .modal-body{padding: 28px;}
        .form-grid{display: grid; gap: 20px;}
        .form-group{display: flex; flex-direction: column; gap: 8px;}
        .form-group label{font-weight: 600; font-size: 14px; color: var(--text-dark);}
        .form-control{
            padding: 12px 16px; border: 1px solid var(--border); border-radius: 14px;
            background: var(--surface-2); font-size: 15px; transition: all 0.2s;
        }
        .form-control:focus{outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); background: white;}
        .options-container{background: var(--surface-2); border-radius: 16px; padding: 18px;}
        .option-row{display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;}
        .option-row input[type="text"]{flex:1; min-width: 200px;}
        .option-row img{max-width: 40px; max-height: 40px; border-radius: 6px; margin-left: 8px;}
        .modal-footer{
            padding: 20px 28px; border-top: 1px solid var(--border);
            display: flex; justify-content: flex-end; gap: 12px;
        }
        .toast-container{
            position: fixed; top: 24px; right: 24px; z-index: 2000;
            display: flex; flex-direction: column; gap: 12px; max-width: 380px;
        }
        .toast{
            background: var(--surface); border-radius: 16px; padding: 16px 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-left: 6px solid;
            display: flex; align-items: center; gap: 14px; animation: slideIn 0.3s;
            border: 1px solid var(--border);
        }
        .toast.success{border-left-color: var(--success);}
        .toast.error{border-left-color: var(--danger);}
        .toast i{font-size: 22px;}
        .toast.success i{color: var(--success);}
        .toast.error i{color: var(--danger);}
        .toast-message{flex:1; font-weight: 500;}
        .toast-close{cursor: pointer; opacity: 0.6;}
        @keyframes slideIn{from{transform: translateX(100%); opacity:0;}to{transform: translateX(0); opacity:1;}}
        .loading-spinner{display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin 0.8s linear infinite;}
        @keyframes spin{to{transform: rotate(360deg);}}
        @media (max-width:768px){
            body{padding:16px;}
            .page-header{flex-direction: column; align-items: flex-start;}
            .exam-meta{margin-left:0; width:100%;}
            .options-list{grid-template-columns:1fr;}
            .modal{width:95%;}
        }
    </style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>

<div class="container">
    <div class="page-header">
        <a href="certification_exams.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Exams</a>
        <h1 class="page-title"><?= e($exam['title']) ?></h1>
        <div class="exam-meta">
            <span><i class="fas fa-layer-group"></i> Level <?= $exam['level'] ?></span>
            <span><i class="fas fa-star"></i> <?= $totalPoints ?> total pts</span>
        </div>
    </div>

    <div class="action-bar">
        <button class="btn btn-primary" id="addQuestionBtn"><i class="fas fa-plus"></i> Add New Question</button>
        <form id="recalculateForm" method="POST" style="display:inline">
            <input type="hidden" name="action" value="update_points">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <button type="submit" class="btn btn-outline" onclick="return confirm('Recalculate total points for this exam?');"><i class="fas fa-calculator"></i> Recalculate Points</button>
        </form>
    </div>

    <div class="questions-container" id="questionsList">
        <?php if (empty($questions)): ?>
            <div class="empty-state">
                <i class="fas fa-file-alt"></i>
                <h3>No questions yet</h3>
                <p>Click "Add New Question" to start building your exam.</p>
            </div>
        <?php else: ?>
            <?php foreach ($questions as $index => $q): 
                $optsStmt = $conn->prepare("SELECT * FROM certification_exam_question_options WHERE question_id = ? ORDER BY sort_order");
                $optsStmt->execute([$q['id']]);
                $opts = $optsStmt->fetchAll();
                $questionNumber = $index + 1;
                $displayType = $q['question_type'] ?: 'explanation';
                $keywordsDisplay = '';
               $keywordsDisplay = '';
if (!empty($q['keywords_json'])) {
    $kw = json_decode($q['keywords_json'], true);
    if (is_array($kw)) {
        $keywordsDisplay = implode(', ', array_column($kw, 'word'));
    }
}
            ?>
            <div class="question-card" data-question-id="<?= $q['id'] ?>">
                <div class="question-header">
                    <div class="question-number"><?= $questionNumber ?></div>
                    <div class="question-content">
                        <div class="question-text-row">
                            <div class="question-text">
                                <?= e($q['question_text']) ?>
                                <span class="badge badge-primary"><?= $q['points'] ?> pts</span>
                                <span class="badge"><?= ucfirst(str_replace('_', ' ', $displayType)) ?></span>
                            </div>
                            <?php if (!empty($q['image_path'])): ?>
                                <img src="../<?= e($q['image_path']) ?>" class="question-image" alt="Question image">
                            <?php endif; ?>
                        </div>

                        <div class="question-meta">
                            <?php if ($displayType === 'multiple_choice'): ?>
                                <?php foreach ($opts as $o): ?>
                                    <span class="<?= $o['is_correct'] ? 'option-correct' : 'option-normal' ?>">
                                        <i class="fas <?= $o['is_correct'] ? 'fa-check-circle' : 'fa-circle' ?>"></i> <?= e($o['option_text']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php elseif ($displayType === 'true_false'): ?>
                                <?php $correct = !empty($opts) && $opts[0]['is_correct'] ? 'True' : 'False'; ?>
                                <span><i class="fas fa-check-circle" style="color:var(--success);"></i> Correct: <?= $correct ?></span>
                            <?php else: ?>
                                <span><i class="fas fa-pencil-alt"></i> Expected: <?= e($q['expected_answer'] ?? 'Not set') ?></span>
                                <?php if ($keywordsDisplay): ?>
                                    <span><i class="fas fa-tags"></i> Keywords: <?= e($keywordsDisplay) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($q['explanation'])): ?>
                        <div class="explanation-box">
                            <strong><i class="fas fa-info-circle"></i> Explanation:</strong> <?= nl2br(e($q['explanation'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-actions">
                    <button class="btn btn-outline btn-sm edit-question-btn" data-id="<?= $q['id'] ?>"><i class="fas fa-edit"></i> Edit</button>
                    <button class="btn btn-danger btn-sm delete-question-btn" data-id="<?= $q['id'] ?>"><i class="fas fa-trash-alt"></i> Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Question Modal -->
<div class="modal-overlay" id="questionModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Question</h2>
            <button class="modal-close" id="closeModalBtn">&times;</button>
        </div>
        <form id="questionForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_question">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="question_id" id="question_id" value="0">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Question Type</label>
                        <select name="question_type" id="qtype" class="form-control">
                            <option value="multiple_choice">Multiple Choice</option>
                            <option value="true_false">True/False</option>
                            <option value="explanation">Explanation</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Question Text</label>
                        <textarea name="question_text" id="question_text" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Question Image (optional)</label>
                        <input type="file" name="question_image" id="question_image" accept="image/*" class="form-control">
                        <input type="hidden" name="image_path" id="image_path" value="">
                        <div style="margin-top:8px;">
                            <input type="text" id="image_url_input" class="form-control" placeholder="Or paste image URL">
                        </div>
                        <div id="imagePreviewContainer" style="margin-top:12px;"></div>
                    </div>
                    <div class="form-group">
                        <label>Points</label>
                        <input type="number" name="points" id="points" class="form-control" value="1" min="1">
                    </div>

                    <!-- Multiple Choice Options -->
                    <div id="mcOptions" style="display:none;">
                        <div class="options-container">
                            <label style="font-weight:600; margin-bottom:12px; display:block;">Options (select correct answer)</label>
                            <div id="optionsContainer"></div>
                            <button type="button" class="btn btn-outline btn-sm" id="addOptionBtn" style="margin-top:12px;"><i class="fas fa-plus"></i> Add Option</button>
                        </div>
                    </div>

                    <!-- True/False -->
                    <div id="tfAnswer" style="display:none;">
                        <div class="form-group">
                            <label>Correct Answer</label>
                            <select name="correct_answer" id="correct_answer" class="form-control">
                                <option value="true">True</option>
                                <option value="false">False</option>
                            </select>
                        </div>
                    </div>

                    <!-- Explanation -->
                    <div id="expAnswer" style="display:none;">
                        <div class="form-group">
                            <label>Correct / Expected Answer</label>
                            <textarea name="correct_answer_text" id="correct_answer_text" class="form-control" placeholder="Enter the expected answer"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Keywords (comma separated, optional)</label>
                            <input type="text" name="keywords" id="keywords" class="form-control" placeholder="e.g., prayer, salah, five times">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Explanation (optional feedback)</label>
                        <textarea name="explanation" id="explanation" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelModalBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveQuestionBtn">Save Question</button>
            </div>
        </form>
    </div>
</div>

<script>
// Toast system
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i><span class="toast-message">${message}</span><span class="toast-close" onclick="this.parentElement.remove()">&times;</span>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}

// Modal elements
const modal = document.getElementById('questionModal');
const modalTitle = document.getElementById('modalTitle');
const closeModalBtn = document.getElementById('closeModalBtn');
const cancelModalBtn = document.getElementById('cancelModalBtn');
const addQuestionBtn = document.getElementById('addQuestionBtn');
const questionForm = document.getElementById('questionForm');
const qtypeSelect = document.getElementById('qtype');
const mcDiv = document.getElementById('mcOptions');
const tfDiv = document.getElementById('tfAnswer');
const expDiv = document.getElementById('expAnswer');
const optionsContainer = document.getElementById('optionsContainer');
const saveBtn = document.getElementById('saveQuestionBtn');

function updateTypeUI() {
    const type = qtypeSelect.value;
    mcDiv.style.display = type === 'multiple_choice' ? 'block' : 'none';
    tfDiv.style.display = type === 'true_false' ? 'block' : 'none';
    expDiv.style.display = type === 'explanation' ? 'block' : 'none';
}
qtypeSelect.addEventListener('change', updateTypeUI);

function createOptionRow(index = null, text = '', imagePath = '') {
    const idx = index !== null ? index : optionsContainer.children.length;
    const div = document.createElement('div');
    div.className = 'option-row';
    div.dataset.index = idx;
    
    const imagePreviewHtml = imagePath 
        ? `<img src="../${imagePath}" alt="Option image">` 
        : '';
    
    div.innerHTML = `
        <input type="radio" name="correct_option_index" value="${idx}" style="width:20px; height:20px;">
        <input type="text" name="options[]" class="form-control" placeholder="Option ${idx+1}" value="${text.replace(/"/g, '&quot;')}">
        <input type="file" name="option_image[]" accept="image/*" style="display:none;" class="option-file-input">
        <input type="hidden" name="option_image_path[]" value="${imagePath}">
        <button type="button" class="btn btn-outline btn-sm option-img-btn" title="Upload image"><i class="fas fa-image"></i></button>
        ${imagePreviewHtml}
        <button type="button" class="btn btn-outline btn-sm option-remove-btn" title="Remove option"><i class="fas fa-times"></i></button>
    `;
    
    const fileInput = div.querySelector('.option-file-input');
    div.querySelector('.option-img-btn').addEventListener('click', () => fileInput.click());
    div.querySelector('.option-remove-btn').addEventListener('click', () => { 
        div.remove(); 
        reindexOptions(); 
    });
    
    return div;
}

function reindexOptions() {
    Array.from(optionsContainer.children).forEach((child, i) => {
        child.dataset.index = i;
        child.querySelector('input[type="radio"]').value = i;
        child.querySelector('input[type="text"]').placeholder = `Option ${i+1}`;
    });
}

document.getElementById('addOptionBtn').addEventListener('click', () => optionsContainer.appendChild(createOptionRow()));

function resetModal() {
    questionForm.reset();
    document.getElementById('question_id').value = '0';
    document.getElementById('image_path').value = '';
    document.getElementById('image_url_input').value = '';
    document.getElementById('imagePreviewContainer').innerHTML = '';
    document.getElementById('question_image').value = '';
    optionsContainer.innerHTML = '';
    optionsContainer.appendChild(createOptionRow(0));
    optionsContainer.appendChild(createOptionRow(1));
    updateTypeUI();
    modalTitle.textContent = 'Add New Question';
}

addQuestionBtn.addEventListener('click', () => { resetModal(); modal.style.display = 'flex'; });
[closeModalBtn, cancelModalBtn].forEach(btn => btn.addEventListener('click', () => modal.style.display = 'none'));
modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });

const imageUrlInput = document.getElementById('image_url_input');
const imagePathHidden = document.getElementById('image_path');
const previewContainer = document.getElementById('imagePreviewContainer');

imageUrlInput.addEventListener('input', function() {
    imagePathHidden.value = this.value;
    updateImagePreview(this.value);
});

document.getElementById('question_image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = (ev) => updateImagePreview(ev.target.result, true);
        reader.readAsDataURL(file);
    }
});

function updateImagePreview(src, isBlob = false) {
    previewContainer.innerHTML = '';
    if (!src) return;
    const img = document.createElement('img');
    img.src = isBlob ? src : (src.startsWith('http') ? src : '../' + src);
    img.style.maxWidth = '100%'; img.style.maxHeight = '180px'; img.style.borderRadius = '12px';
    previewContainer.appendChild(img);
}

async function loadQuestionForEdit(id) {
    try {
        const res = await fetch(`?exam_id=<?= $examId ?>&edit=${id}&ajax=1`);
        if (!res.ok) throw new Error(`Server error ${res.status}`);
        const q = await res.json();
        if (q.error) throw new Error(q.error);

        // Set all form fields with fallbacks
        document.getElementById('question_id').value    = q.id              ?? '';
        document.getElementById('question_text').value  = q.question_text   ?? '';
        qtypeSelect.value                               = q.question_type   ?? 'multiple_choice';
        document.getElementById('points').value         = q.points          ?? 1;
        document.getElementById('explanation').value    = q.explanation     ?? '';
        document.getElementById('image_path').value     = q.image_path      ?? '';
        imageUrlInput.value                             = q.image_path      ?? '';
        updateImagePreview(q.image_path ?? '');

        // Reset file input
        document.getElementById('question_image').value = '';

        // Options
        optionsContainer.innerHTML = '';
        if (Array.isArray(q.options) && q.options.length) {
            q.options.forEach((opt, idx) => {
                const row = createOptionRow(idx, opt.option_text ?? '', opt.image_path ?? '');
                if (opt.is_correct) {
                    row.querySelector('input[type="radio"]').checked = true;
                }
                optionsContainer.appendChild(row);
            });
        } else {
            optionsContainer.appendChild(createOptionRow(0));
            optionsContainer.appendChild(createOptionRow(1));
        }

        // Explanation fields
        document.getElementById('correct_answer_text').value = q.expected_answer ?? '';
        document.getElementById('keywords').value            = q.keywords         ?? '';
        document.getElementById('correct_answer').value      = q.correct_answer   ?? 'true';

        updateTypeUI();
        modalTitle.textContent = 'Edit Question';
        modal.style.display = 'flex';
    } catch (err) {
        showToast(err.message, 'error');
        // Do not open modal if data couldn't be loaded
    }
}

function attachEditListeners() {
    document.querySelectorAll('.edit-question-btn').forEach(btn => {
        btn.removeEventListener('click', handleEdit);
        btn.addEventListener('click', handleEdit);
    });
}

function handleEdit(e) {
    loadQuestionForEdit(e.target.closest('button').dataset.id);
}

function attachDeleteListeners() {
    document.querySelectorAll('.delete-question-btn').forEach(btn => {
        btn.removeEventListener('click', handleDelete);
        btn.addEventListener('click', handleDelete);
    });
}

async function handleDelete(e) {
    const btn = e.target.closest('button');
    if (!confirm('Delete this question?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_question');
    formData.append('csrf_token', '<?= e($csrfToken) ?>');
    formData.append('question_id', btn.dataset.id);
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 1000);
    } catch { showToast('Error deleting question', 'error'); }
}

attachEditListeners();
attachDeleteListeners();

questionForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';
    
    const formData = new FormData(questionForm);
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) { 
            modal.style.display = 'none'; 
            setTimeout(() => location.reload(), 1000); 
        }
    } catch { 
        showToast('Error saving question', 'error'); 
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
});

document.getElementById('recalculateForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const btn = e.target.querySelector('button');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span> Calculating...';
    
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 1000);
    } catch { 
        showToast('Error updating points', 'error'); 
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.style.display === 'flex') modal.style.display = 'none'; });
</script>
</body>
</html>