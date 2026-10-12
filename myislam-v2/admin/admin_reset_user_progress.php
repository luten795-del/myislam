<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';

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

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$conn = db();
if (!$conn instanceof PDO) {
    exit('Database connection failed.');
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT id, full_name, email, profile_image, role, is_active
    FROM users
    WHERE id = ? AND is_active = 1
    LIMIT 1
");
$stmt->execute([$userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied.');
}

$allowedRoles = ['owner', 'admin', 'sheikh'];
if (!in_array(strtolower((string)($admin['role'] ?? '')), $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. Insufficient privileges.');
}

// Fetch all active users for dropdown
$usersList = [];
try {
    $stmt = $conn->query("SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name");
    $usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $usersList = [];
}

$message = '';
$messageType = 'success';

// Available reset categories and their SQL mappings
$categories = [
    'progress'           => ['label' => 'Content Progress',         'table' => 'user_content_progress',      'key' => 'user_id'],
    'quiz_attempts'      => ['label' => 'Quiz Attempts & Answers',  'table' => 'quiz_attempts',              'key' => 'user_id'],
    'monthly_exams'      => ['label' => 'Monthly Exam Attempts',    'table' => 'monthly_exam_attempts',      'key' => 'user_id'],
    'leaderboard'        => ['label' => 'Leaderboard Stats',        'table' => 'leaderboard_stats',          'key' => 'user_id'],
    'cert_attempts'      => ['label' => 'Certification Exam Attempts','table' => 'certification_exam_attempts','key' => 'user_id'],
    'certificates'       => ['label' => 'Certificates',             'table' => 'certificates',               'key' => 'user_id'],
];

// Stats initialisation
$stats = [];
foreach ($categories as $key => $cat) {
    $stats[$key] = 0;
}
$stats['users_with_progress'] = 0;

try {
    $stats['users_with_progress'] = (int)$conn->query("SELECT COUNT(DISTINCT user_id) FROM user_content_progress")->fetchColumn();
    $stats['progress']           = (int)$conn->query("SELECT COUNT(*) FROM user_content_progress")->fetchColumn();
    $stats['quiz_attempts']      = (int)$conn->query("SELECT COUNT(*) FROM quiz_attempts")->fetchColumn();
    // Include separate count for answers (but they are automatically cleaned via quiz_attempts FK, we won't show separately)
    $stats['monthly_exams']      = (int)$conn->query("SELECT COUNT(*) FROM monthly_exam_attempts")->fetchColumn();
    $stats['leaderboard']        = (int)$conn->query("SELECT COUNT(*) FROM leaderboard_stats")->fetchColumn();
    $stats['cert_attempts']      = (int)$conn->query("SELECT COUNT(*) FROM certification_exam_attempts")->fetchColumn();
    $stats['certificates']       = (int)$conn->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
} catch (Throwable $e) {
    // keep defaults
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $confirm = trim((string)($_POST['confirm_text'] ?? ''));
    $scope = trim((string)($_POST['scope'] ?? 'all'));
    $targetUserId = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
    $resetAutoIncrement = isset($_POST['reset_autoincrement']) && $_POST['reset_autoincrement'] === '1';

    // Selected categories (checkboxes)
    $selectedCategories = $_POST['categories'] ?? [];
    if (!is_array($selectedCategories)) $selectedCategories = [];
    // Only keep valid category keys
    $selectedCategories = array_intersect($selectedCategories, array_keys($categories));

    if ($action === 'reset_progress') {
        if ($confirm !== 'RESET') {
            $message = 'Type RESET exactly to confirm.';
            $messageType = 'danger';
        } elseif (empty($selectedCategories)) {
            $message = 'Please select at least one category to reset.';
            $messageType = 'danger';
        } else {
            try {
                $conn->beginTransaction();

                $deleted = [];
                $totalDeleted = 0;

                // Special handling for quiz attempts: first delete answers, then attempts
                $delQuizAttempts = false;
                if (in_array('quiz_attempts', $selectedCategories)) {
                    $delQuizAttempts = true;
                    // remove from array to handle separately
                    $selectedCategories = array_diff($selectedCategories, ['quiz_attempts']);
                }

                foreach ($selectedCategories as $catKey) {
                    $cat = $categories[$catKey];
                    $table = $cat['table'];
                    $keyColumn = $cat['key'];

                    if ($scope === 'one' && $targetUserId > 0) {
                        $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE `{$keyColumn}` = ?");
                        $stmt->execute([$targetUserId]);
                    } else {
                        $stmt = $conn->exec("DELETE FROM `{$table}`");
                    }
                    $deleted[$catKey] = ($scope === 'one' && $targetUserId > 0) ? $stmt->rowCount() : $stmt;
                }

                // Now delete quiz attempts (and cascade answers if FK is properly set, but we explicitly delete answers first for safety)
                if ($delQuizAttempts) {
                    $deleted['quiz_attempts'] = 0;
                    $deleted['quiz_answers'] = 0;
                    if ($scope === 'one' && $targetUserId > 0) {
                        // First delete answers via join
                        $stmt = $conn->prepare("
                            DELETE qaa FROM quiz_attempt_answers qaa
                            INNER JOIN quiz_attempts qa ON qa.id = qaa.attempt_id
                            WHERE qa.user_id = ?
                        ");
                        $stmt->execute([$targetUserId]);
                        $deleted['quiz_answers'] = $stmt->rowCount();

                        // Then delete attempts
                        $stmt = $conn->prepare("DELETE FROM quiz_attempts WHERE user_id = ?");
                        $stmt->execute([$targetUserId]);
                        $deleted['quiz_attempts'] = $stmt->rowCount();
                    } else {
                        $deleted['quiz_answers'] = (int)$conn->exec("DELETE FROM quiz_attempt_answers");
                        $deleted['quiz_attempts'] = (int)$conn->exec("DELETE FROM quiz_attempts");
                    }
                }

                // Build summary message
                $summaryParts = [];
                foreach ($categories as $catKey => $cat) {
                    if (isset($deleted[$catKey]) && $deleted[$catKey] > 0) {
                        $summaryParts[] = "{$cat['label']}: {$deleted[$catKey]}";
                    } elseif ($catKey === 'quiz_attempts' && $delQuizAttempts) {
                        $summaryParts[] = "Quiz Attempts: {$deleted['quiz_attempts']}, Answers: {$deleted['quiz_answers']}";
                    }
                }
                $summary = implode(', ', $summaryParts) ?: '0 rows';
                if ($scope === 'one') {
                    $message = "Reset for user ID {$targetUserId}: {$summary}.";
                } else {
                    $message = "Full reset: {$summary}.";
                }

                // Reset auto-increment if requested
                if ($resetAutoIncrement) {
                    $tablesToReset = [];
                    foreach ($selectedCategories as $catKey) {
                        $tablesToReset[] = $categories[$catKey]['table'];
                    }
                    if ($delQuizAttempts) {
                        $tablesToReset[] = 'quiz_attempts';
                        $tablesToReset[] = 'quiz_attempt_answers';
                    }
                    $tablesToReset = array_unique($tablesToReset);
                    foreach ($tablesToReset as $table) {
                        $count = $conn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                        if ($count == 0) {
                            $conn->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
                        }
                    }
                    $message .= " AUTO_INCREMENT reset for emptied tables.";
                }

                $conn->commit();
                $messageType = 'success';

                // Refresh stats
                $stats['users_with_progress'] = (int)$conn->query("SELECT COUNT(DISTINCT user_id) FROM user_content_progress")->fetchColumn();
                $stats['progress'] = (int)$conn->query("SELECT COUNT(*) FROM user_content_progress")->fetchColumn();
                $stats['quiz_attempts'] = (int)$conn->query("SELECT COUNT(*) FROM quiz_attempts")->fetchColumn();
                $stats['monthly_exams'] = (int)$conn->query("SELECT COUNT(*) FROM monthly_exam_attempts")->fetchColumn();
                $stats['leaderboard'] = (int)$conn->query("SELECT COUNT(*) FROM leaderboard_stats")->fetchColumn();
                $stats['cert_attempts'] = (int)$conn->query("SELECT COUNT(*) FROM certification_exam_attempts")->fetchColumn();
                $stats['certificates'] = (int)$conn->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $message = 'Reset failed: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset User Progress – MyIslam Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
--bg:#f5f7f5;--surface:#fff;--surface2:#f9fafb;--line:#e5e7eb;--text:#1f2937;--muted:#6b7280;
--brand:#166534;--brand-soft:#e7f3eb;--danger:#dc2626;--danger-soft:#fee2e2;--success:#10b981;
--success-soft:#d1fae5;--warning:#f59e0b;--radius:18px;--shadow:0 10px 20px rgba(0,0,0,.06);
}
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text);padding:24px}
.wrapper{max-width:1000px;margin:0 auto}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden}
.card-head{padding:22px 24px;background:linear-gradient(135deg,var(--brand-soft),var(--surface));border-bottom:1px solid var(--line)}
.card-body{padding:24px}
h1{font-size:30px;color:var(--brand);margin-bottom:8px}
p{line-height:1.6}
.notice{padding:14px 16px;border-radius:14px;margin-bottom:16px;font-weight:600}
.notice.success{background:var(--success-soft);color:#065f46;border:1px solid #86efac}
.notice.danger{background:var(--danger-soft);color:#991b1b;border:1px solid #fca5a5}
.grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin:18px 0}
.stat{background:var(--surface2);border:1px solid var(--line);border-radius:16px;padding:18px}
.stat .label{font-size:13px;color:var(--muted);margin-bottom:6px}
.stat .value{font-size:28px;font-weight:800}
.warn-box{background:var(--danger-soft);border:1px solid #fca5a5;color:#7f1d1d;padding:18px;border-radius:16px;margin-bottom:18px}
.form-grid{display:grid;gap:14px}
label{font-weight:700;font-size:14px}
input,select{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--surface2);font:inherit}
.checkbox-group{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.checkbox-group input{width:auto}
.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}
.btn{padding:12px 18px;border:none;border-radius:14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.btn.secondary{background:var(--surface2);color:var(--text);border:1px solid var(--line)}
.btn.danger{background:var(--danger);color:#fff}
small{color:var(--muted)}
.profile{display:flex;align-items:center;gap:12px;margin-top:8px}
.avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:800;border:2px solid var(--brand)}
a{color:var(--brand);text-decoration:none}
@media (max-width:1000px){.grid{grid-template-columns:repeat(3,1fr)}}
@media (max-width:700px){.grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:500px){.grid{grid-template-columns:1fr}body{padding:16px}}
</style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="card-head">
            <h1>Reset User Progress</h1>
            <p>Select the type of progress and the user(s) to reset. 
            <br><strong>Note:</strong> This does <em>not</em> alter exam structure (questions, options, explanations).</p>
            <div class="profile">
                <div class="avatar">
                    <?php if (!empty($admin['profile_image'])): ?>
                        <img src="<?php echo e($admin['profile_image']); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    <?php else: ?>
                        <?php echo e(initials((string)$admin['full_name'])); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?php echo e($admin['full_name'] ?? 'Admin'); ?></strong><br>
                    <small><?php echo e($admin['email'] ?? ''); ?> · <?php echo e(ucfirst((string)($admin['role'] ?? 'admin'))); ?></small>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if ($message !== ''): ?>
                <div class="notice <?php echo $messageType === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo e($message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="grid">
                <div class="stat">
                    <div class="label">Users With Progress</div>
                    <div class="value"><?php echo (int)$stats['users_with_progress']; ?></div>
                </div>
                <div class="stat">
                    <div class="label">Content Progress</div>
                    <div class="value"><?php echo (int)$stats['progress']; ?></div>
                </div>
                <div class="stat">
                    <div class="label">Quiz Attempts</div>
                    <div class="value"><?php echo (int)$stats['quiz_attempts']; ?></div>
                </div>
                <div class="stat">
                    <div class="label">Monthly Exams</div>
                    <div class="value"><?php echo (int)$stats['monthly_exams']; ?></div>
                </div>
                <div class="stat">
                    <div class="label">Leaderboard Stats</div>
                    <div class="value"><?php echo (int)$stats['leaderboard']; ?></div>
                </div>
                <div class="stat">
                    <div class="label">Cert. Exam Attempts</div>
                    <div class="value"><?php echo (int)$stats['cert_attempts']; ?></div>
                </div>
                <div class="stat">
                    <div class="label">Certificates</div>
                    <div class="value"><?php echo (int)$stats['certificates']; ?></div>
                </div>
            </div>

            <div class="warn-box">
                <strong>⚠️ Warning:</strong> This permanently deletes the selected data types.
                <ul style="margin-top:8px; padding-left:20px;">
                    <li><strong>Quiz Attempts & Answers</strong> – removes both quiz attempts and stored answers.</li>
                    <li>Other categories are cleared directly from their respective tables.</li>
                </ul>
            </div>

            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="reset_progress">

                <!-- Scope -->
                <div>
                    <label for="scope">Reset Scope</label>
                    <select name="scope" id="scope" onchange="toggleUserField(this.value)">
                        <option value="all">All users</option>
                        <option value="one">Single user</option>
                    </select>
                </div>

                <div id="userField" style="display:none;">
                    <label for="target_user_id">Select User</label>
                    <select name="target_user_id" id="target_user_id">
                        <option value="">-- Choose a user --</option>
                        <?php foreach ($usersList as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>">
                                <?php echo e($u['full_name']); ?> (<?php echo e($u['email']); ?>) [ID: <?php echo (int)$u['id']; ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Categories checkboxes -->
                <fieldset style="border:1px solid var(--line); border-radius:12px; padding:16px;">
                    <legend style="font-weight:700; padding:0 8px;">Select What to Reset</legend>
                    <?php foreach ($categories as $key => $cat): ?>
                        <div class="checkbox-group">
                            <input type="checkbox" name="categories[]" id="cat_<?php echo $key; ?>" value="<?php echo $key; ?>">
                            <label for="cat_<?php echo $key; ?>" style="font-weight:normal;"><?php echo e($cat['label']); ?></label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>

                <!-- Auto increment -->
                <div class="checkbox-group">
                    <input type="checkbox" name="reset_autoincrement" id="reset_autoincrement" value="1">
                    <label for="reset_autoincrement" style="font-weight:normal;">Reset AUTO_INCREMENT to 1 on emptied tables</label>
                </div>

                <!-- Confirmation -->
                <div>
                    <label for="confirm_text">Type <strong>RESET</strong> to confirm</label>
                    <input type="text" name="confirm_text" id="confirm_text" placeholder="RESET" required>
                </div>

                <div class="actions">
                    <button type="submit" class="btn danger" onclick="return confirm('Are you absolutely sure you want to reset the selected progress?');">
                        Execute Reset
                    </button>
                    <a href="dashboard.php" class="btn secondary">Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleUserField(value) {
    const field = document.getElementById('userField');
    const select = document.getElementById('target_user_id');
    if (value === 'one') {
        field.style.display = 'block';
        select.required = true;
    } else {
        field.style.display = 'none';
        select.required = false;
        select.value = '';
    }
}
</script>
</body>
</html>