<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/database.php';

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Admin authentication
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
        return ($user && strtolower($user['role'] ?? '') === 'admin') ? $user : null;
    } catch (PDOException $e) {
        return null;
    }
}

$admin = getAdminUser($conn, $userId);
if (!$admin) {
    http_response_code(403);
    exit('Access denied.');
}

// Generate slug
function generateSlug(string $text): string {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    return trim($slug, '-') ?: 'item';
}

function uniqueSlug(PDO $conn, string $table, string $baseSlug, ?int $excludeId = null): string {
    $slug = $baseSlug;
    $counter = 1;
    while (true) {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT id FROM $table WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM $table WHERE slug = ?");
            $stmt->execute([$slug]);
        }
        if (!$stmt->fetch()) break;
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
    return $slug;
}

// Process AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    // ----- Categories -----
    if ($action === 'save_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '❓');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if ($name === '') {
            $response = ['success' => false, 'message' => 'Category name required.'];
        } else {
            $slug = generateSlug($name);
            $slug = uniqueSlug($conn, 'help_categories', $slug, $catId > 0 ? $catId : null);
            
            if ($catId > 0) {
                $stmt = $conn->prepare("UPDATE help_categories SET name=?, slug=?, icon=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $slug, $icon, $sortOrder, $isActive, $catId]);
            } else {
                $stmt = $conn->prepare("INSERT INTO help_categories (name, slug, icon, sort_order, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $slug, $icon, $sortOrder, $isActive]);
            }
            $response = ['success' => true, 'message' => 'Category saved.'];
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($response); exit; }
    }
    
    if ($action === 'delete_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $conn->prepare("DELETE FROM help_categories WHERE id = ?")->execute([$catId]);
        $response = ['success' => true, 'message' => 'Category deleted.'];
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($response); exit; }
    }
    
    // ----- Articles -----
    if ($action === 'save_article') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        $answer = trim($_POST['answer'] ?? '');
        $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if ($question === '' || $answer === '' || $categoryId <= 0) {
            $response = ['success' => false, 'message' => 'All fields are required.'];
        } else {
            $slug = generateSlug($question);
            $slug = uniqueSlug($conn, 'help_articles', $slug, $articleId > 0 ? $articleId : null);
            
            if ($articleId > 0) {
                $stmt = $conn->prepare("UPDATE help_articles SET category_id=?, question=?, answer=?, slug=?, is_featured=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$categoryId, $question, $answer, $slug, $isFeatured, $sortOrder, $isActive, $articleId]);
            } else {
                $stmt = $conn->prepare("INSERT INTO help_articles (category_id, question, answer, slug, is_featured, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$categoryId, $question, $answer, $slug, $isFeatured, $sortOrder, $isActive]);
            }
            $response = ['success' => true, 'message' => 'Article saved.'];
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($response); exit; }
    }
    
    if ($action === 'delete_article') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        $conn->prepare("DELETE FROM help_articles WHERE id = ?")->execute([$articleId]);
        $response = ['success' => true, 'message' => 'Article deleted.'];
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($response); exit; }
    }
    
    // ----- Tickets -----
    if ($action === 'update_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $status = $_POST['status'] ?? 'open';
        $responseText = trim($_POST['response'] ?? '');
        
        if ($ticketId > 0) {
            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, admin_response = ?, responded_by = ?, responded_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $responseText ?: null, $userId, $ticketId]);
            $conn->commit();
            $response = ['success' => true, 'message' => 'Ticket updated.'];
        } else {
            $response = ['success' => false, 'message' => 'Invalid ticket ID.'];
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($response); exit; }
    }
    
    if ($action === 'delete_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $conn->prepare("DELETE FROM support_tickets WHERE id = ?")->execute([$ticketId]);
        $response = ['success' => true, 'message' => 'Ticket deleted.'];
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($response); exit; }
    }
}

// Fetch data for display
$categories = $conn->query("SELECT * FROM help_categories ORDER BY sort_order, name")->fetchAll();
$articles = $conn->query("
    SELECT a.*, c.name as category_name 
    FROM help_articles a 
    JOIN help_categories c ON a.category_id = c.id 
    ORDER BY a.sort_order, a.question
")->fetchAll();
$tickets = $conn->query("
    SELECT t.*, u.full_name as user_name, u.email as user_email 
    FROM support_tickets t 
    JOIN users u ON t.user_id = u.id 
    ORDER BY FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), t.created_at DESC
")->fetchAll();

// AJAX endpoints for fetching single records
if (isset($_GET['ajax'])) {
    if (isset($_GET['category'])) {
        $id = (int)$_GET['category'];
        $stmt = $conn->prepare("SELECT * FROM help_categories WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch());
        exit;
    }
    if (isset($_GET['article'])) {
        $id = (int)$_GET['article'];
        $stmt = $conn->prepare("SELECT * FROM help_articles WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch());
        exit;
    }
    if (isset($_GET['ticket'])) {
        $id = (int)$_GET['ticket'];
        $stmt = $conn->prepare("SELECT t.*, u.full_name as user_name, u.email as user_email FROM support_tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch());
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Help Center – MyIslam</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{
            --primary: #2d6a4f; --primary-light: #40916c; --primary-soft: #d8f3dc;
            --danger: #e63946; --success: #2a9d8f; --warning: #e9c46a;
            --text-dark: #1b4332; --text-light: #52796f; --text-muted: #6c757d;
            --bg-page: #f4f7f2; --surface: #ffffff; --surface-2: #f8faf8;
            --border: #e0e7e0; --shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
            --shadow-lg: 0 20px 30px -10px rgba(0,0,0,0.1);
            --radius: 16px; --radius-sm: 12px;
        }
        body{
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-page); color: var(--text-dark);
            line-height: 1.6; padding: 24px 32px; min-height: 100vh;
        }
        .container{max-width: 1400px; margin: 0 auto;}
        .page-header{
            display: flex; align-items: center; flex-wrap: wrap; gap: 20px 30px;
            margin-bottom: 32px; padding-bottom: 20px; border-bottom: 1px solid var(--border);
        }
        .back-link{display: flex; align-items: center; gap: 8px; text-decoration: none; color: var(--primary); font-weight: 600;}
        .back-link:hover{color: var(--primary-light);}
        .page-title{font-size: 28px; font-weight: 700;}
        .action-bar{display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;}
        .btn{
            display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px;
            border-radius: 40px; font-weight: 600; font-size: 15px; border: none;
            cursor: pointer; transition: all 0.2s; background: var(--surface);
            color: var(--text-dark); border: 1px solid var(--border); box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .btn-primary{background: var(--primary); color: white; border: none; box-shadow: 0 8px 16px -4px rgba(45,106,79,0.25);}
        .btn-primary:hover{background: var(--primary-light); transform: translateY(-2px);}
        .btn-danger{background: #fff1f0; color: var(--danger); border-color: #ffccc7;}
        .btn-danger:hover{background: #ffe7e5;}
        .btn-sm{padding: 8px 16px; font-size: 13px;}
        
        .tabs{display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 1px solid var(--border);}
        .tab{padding: 12px 24px; background: none; border: none; font-weight: 600; color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s;}
        .tab.active{color: var(--primary); border-bottom-color: var(--primary);}
        .tab:hover{color: var(--primary);}
        
        .table-wrapper{background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); overflow-x: auto;}
        table{width: 100%; border-collapse: collapse;}
        th, td{padding: 16px; text-align: left; border-bottom: 1px solid var(--border);}
        th{background: var(--surface-2); font-weight: 600; color: var(--text-light); font-size: 13px;}
        tr:last-child td{border-bottom: none;}
        .badge{
            display: inline-block; padding: 4px 10px; border-radius: 40px; font-size: 12px; font-weight: 500; background: var(--surface-2);
        }
        .badge.open{background: #fef3c7; color: #92400e;}
        .badge.in_progress{background: #dbeafe; color: #1e40af;}
        .badge.resolved{background: #d1fae5; color: #065f46;}
        .badge.closed{background: #f3f4f6; color: #4b5563;}
        .actions{display: flex; gap: 8px;}
        
        .categories-grid{display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-top: 20px;}
        .category-card{background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; display: flex; align-items: center; justify-content: space-between;}
        .category-info{display: flex; align-items: center; gap: 12px;}
        .category-icon{font-size: 24px;}
        
        .modal-overlay{display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); backdrop-filter: blur(6px); align-items: center; justify-content: center; z-index: 1000;}
        .modal{background: var(--surface); border-radius: 24px; width: 90%; max-width: 700px; max-height: 85vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);}
        .modal-header{padding: 24px 28px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;}
        .modal-header h2{font-size: 22px; font-weight: 700;}
        .modal-close{background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-muted);}
        .modal-body{padding: 28px;}
        .form-grid{display: grid; gap: 20px;}
        .form-group{display: flex; flex-direction: column; gap: 8px;}
        .form-group label{font-weight: 600; font-size: 14px;}
        .form-control{padding: 12px 16px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-2); font-size: 15px;}
        .form-control:focus{outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); background: white;}
        .checkbox-group{display: flex; align-items: center; gap: 8px;}
        .modal-footer{padding: 20px 28px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;}
        .toast-container{position: fixed; top: 24px; right: 24px; z-index: 2000; display: flex; flex-direction: column; gap: 12px; max-width: 380px;}
        .toast{background: var(--surface); border-radius: 16px; padding: 16px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-left: 6px solid; display: flex; align-items: center; gap: 14px; animation: slideIn 0.3s;}
        .toast.success{border-left-color: var(--success);}
        .toast.error{border-left-color: var(--danger);}
        .toast i{font-size: 22px;}
        .toast-message{flex:1; font-weight: 500;}
        .toast-close{cursor: pointer; opacity: 0.6;}
        @keyframes slideIn{from{transform: translateX(100%); opacity:0;}to{transform: translateX(0); opacity:1;}}
        .loading-spinner{display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin 0.8s linear infinite;}
        @keyframes spin{to{transform: rotate(360deg);}}
        @media (max-width:768px){body{padding:16px;}.page-header{flex-direction:column; align-items:flex-start;}.modal{width:95%;}}
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>

<div class="container">
    <div class="page-header">
        <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h1 class="page-title"><i class="fas fa-life-ring"></i> Help Center Admin</h1>
    </div>
    
    <div class="tabs">
        <button class="tab active" data-tab="categories">Categories</button>
        <button class="tab" data-tab="articles">FAQ Articles</button>
        <button class="tab" data-tab="tickets">Support Tickets</button>
    </div>
    
    <!-- Categories Tab -->
    <div id="categoriesTab">
        <div class="action-bar">
            <button class="btn btn-primary" id="addCategoryBtn"><i class="fas fa-plus"></i> Add Category</button>
        </div>
        <div class="categories-grid" id="categoriesGrid">
            <?php foreach ($categories as $cat): ?>
            <div class="category-card">
                <div class="category-info">
                    <span class="category-icon"><?= e($cat['icon'] ?? '❓') ?></span>
                    <div>
                        <div style="font-weight:600;"><?= e($cat['name']) ?></div>
                        <div style="font-size:12px;color:var(--text-muted);"><?= $cat['is_active'] ? 'Active' : 'Inactive' ?></div>
                    </div>
                </div>
                <div class="actions">
                    <button class="btn btn-sm edit-category-btn" data-id="<?= $cat['id'] ?>"><i class="fas fa-edit"></i></button>
                    <button class="btn btn-sm btn-danger delete-category-btn" data-id="<?= $cat['id'] ?>"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Articles Tab -->
    <div id="articlesTab" style="display:none;">
        <div class="action-bar">
            <button class="btn btn-primary" id="addArticleBtn"><i class="fas fa-plus"></i> Add Article</button>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Question</th><th>Category</th><th>Featured</th><th>Status</th><th>Views</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($articles as $a): ?>
                    <tr>
                        <td><?= e($a['question']) ?></td>
                        <td><?= e($a['category_name']) ?></td>
                        <td><?= $a['is_featured'] ? '⭐ Yes' : '—' ?></td>
                        <td><span class="badge"><?= $a['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td><?= $a['view_count'] ?></td>
                        <td class="actions">
                            <button class="btn btn-sm edit-article-btn" data-id="<?= $a['id'] ?>"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-danger delete-article-btn" data-id="<?= $a['id'] ?>"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Tickets Tab -->
    <div id="ticketsTab" style="display:none;">
        <div class="table-wrapper">
            <table>
                <thead><tr><th>ID</th><th>User</th><th>Subject</th><th>Priority</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td>#<?= $t['id'] ?></td>
                        <td><?= e($t['user_name']) ?><br><small><?= e($t['user_email']) ?></small></td>
                        <td><?= e($t['subject']) ?></td>
                        <td><span class="badge"><?= ucfirst($t['priority']) ?></span></td>
                        <td><span class="badge <?= $t['status'] ?>"><?= ucfirst(str_replace('_', ' ', $t['status'])) ?></span></td>
                        <td><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                        <td class="actions">
                            <button class="btn btn-sm respond-ticket-btn" data-id="<?= $t['id'] ?>"><i class="fas fa-reply"></i></button>
                            <button class="btn btn-sm btn-danger delete-ticket-btn" data-id="<?= $t['id'] ?>"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal" style="max-width:450px;">
        <div class="modal-header"><h2 id="categoryModalTitle">Add Category</h2><button class="modal-close" id="closeCategoryModal">&times;</button></div>
        <form id="categoryForm">
            <input type="hidden" name="action" value="save_category"><input type="hidden" name="category_id" id="category_id" value="0">
            <div class="modal-body">
                <div class="form-group"><label>Name</label><input type="text" name="name" id="category_name" class="form-control" required></div>
                <div class="form-group"><label>Icon (emoji)</label><input type="text" name="icon" id="category_icon" class="form-control" value="❓"></div>
                <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" id="category_sort_order" class="form-control" value="0"></div>
                <div class="checkbox-group"><input type="checkbox" name="is_active" id="category_is_active" checked> <label for="category_is_active">Active</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn" id="cancelCategoryModal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>

<!-- Article Modal -->
<div class="modal-overlay" id="articleModal">
    <div class="modal">
        <div class="modal-header"><h2 id="articleModalTitle">Add Article</h2><button class="modal-close" id="closeArticleModal">&times;</button></div>
        <form id="articleForm">
            <input type="hidden" name="action" value="save_article"><input type="hidden" name="article_id" id="article_id" value="0">
            <div class="modal-body">
                <div class="form-group"><label>Category</label><select name="category_id" id="article_category_id" class="form-control" required><option value="">Select</option><?php foreach($categories as $c): ?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Question</label><input type="text" name="question" id="article_question" class="form-control" required></div>
                <div class="form-group"><label>Answer</label><textarea name="answer" id="article_answer" class="form-control" rows="6" required></textarea></div>
                <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" id="article_sort_order" class="form-control" value="0"></div>
                <div class="checkbox-group"><input type="checkbox" name="is_featured" id="article_is_featured"> <label for="article_is_featured">Featured</label></div>
                <div class="checkbox-group"><input type="checkbox" name="is_active" id="article_is_active" checked> <label for="article_is_active">Active</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn" id="cancelArticleModal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>

<!-- Ticket Response Modal -->
<div class="modal-overlay" id="ticketModal">
    <div class="modal">
        <div class="modal-header"><h2>Respond to Ticket</h2><button class="modal-close" id="closeTicketModal">&times;</button></div>
        <form id="ticketForm">
            <input type="hidden" name="action" value="update_ticket"><input type="hidden" name="ticket_id" id="ticket_id" value="0">
            <div class="modal-body">
                <div id="ticketInfo" style="background:var(--surface-2);padding:16px;border-radius:var(--radius-sm);margin-bottom:20px;"></div>
                <div class="form-group"><label>Status</label><select name="status" id="ticket_status" class="form-control"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select></div>
                <div class="form-group"><label>Response (visible to user)</label><textarea name="response" id="ticket_response" class="form-control" rows="4"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn" id="cancelTicketModal">Cancel</button><button type="submit" class="btn btn-primary">Update Ticket</button></div>
        </form>
    </div>
</div>

<script>
    function showToast(m,t='success'){const c=document.getElementById('toastContainer');const d=document.createElement('div');d.className=`toast ${t}`;d.innerHTML=`<i class="fas ${t==='success'?'fa-check-circle':'fa-exclamation-circle'}"></i><span class="toast-message">${m}</span><span class="toast-close" onclick="this.parentElement.remove()">&times;</span>`;c.appendChild(d);setTimeout(()=>d.remove(),5000);}
    
    // Tabs
    document.querySelectorAll('.tab').forEach(t=>t.addEventListener('click',()=>{document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));t.classList.add('active');['categories','articles','tickets'].forEach(id=>document.getElementById(id+'Tab').style.display=t.dataset.tab===id?'block':'none');}));
    
    // Category Modal
    const catModal=document.getElementById('categoryModal'),catForm=document.getElementById('categoryForm');
    document.getElementById('addCategoryBtn').addEventListener('click',()=>{catForm.reset();document.getElementById('category_id').value='0';document.getElementById('categoryModalTitle').textContent='Add Category';catModal.style.display='flex';});
    document.querySelectorAll('#closeCategoryModal, #cancelCategoryModal').forEach(b=>b.addEventListener('click',()=>catModal.style.display='none'));
    document.querySelectorAll('.edit-category-btn').forEach(b=>b.addEventListener('click',async()=>{const r=await fetch(`?ajax=1&category=${b.dataset.id}`);const d=await r.json();document.getElementById('category_id').value=d.id;document.getElementById('category_name').value=d.name;document.getElementById('category_icon').value=d.icon||'❓';document.getElementById('category_sort_order').value=d.sort_order;document.getElementById('category_is_active').checked=d.is_active==1;document.getElementById('categoryModalTitle').textContent='Edit Category';catModal.style.display='flex';}));
    document.querySelectorAll('.delete-category-btn').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Delete?'))return;const f=new FormData();f.append('action','delete_category');f.append('category_id',b.dataset.id);const r=await fetch('',{method:'POST',body:f,headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();showToast(d.message,d.success?'success':'error');if(d.success)setTimeout(()=>location.reload(),1000);}));
    catForm.addEventListener('submit',async(e)=>{e.preventDefault();const b=catForm.querySelector('button[type="submit"]');const o=b.innerHTML;b.disabled=true;b.innerHTML='<span class="loading-spinner"></span> Saving...';const r=await fetch('',{method:'POST',body:new FormData(catForm),headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();showToast(d.message,d.success?'success':'error');b.disabled=false;b.innerHTML=o;if(d.success){catModal.style.display='none';setTimeout(()=>location.reload(),1000);}});
    
    // Article Modal
    const artModal=document.getElementById('articleModal'),artForm=document.getElementById('articleForm');
    document.getElementById('addArticleBtn').addEventListener('click',()=>{artForm.reset();document.getElementById('article_id').value='0';document.getElementById('articleModalTitle').textContent='Add Article';artModal.style.display='flex';});
    document.querySelectorAll('#closeArticleModal, #cancelArticleModal').forEach(b=>b.addEventListener('click',()=>artModal.style.display='none'));
    document.querySelectorAll('.edit-article-btn').forEach(b=>b.addEventListener('click',async()=>{const r=await fetch(`?ajax=1&article=${b.dataset.id}`);const d=await r.json();document.getElementById('article_id').value=d.id;document.getElementById('article_category_id').value=d.category_id;document.getElementById('article_question').value=d.question;document.getElementById('article_answer').value=d.answer;document.getElementById('article_sort_order').value=d.sort_order;document.getElementById('article_is_featured').checked=d.is_featured==1;document.getElementById('article_is_active').checked=d.is_active==1;document.getElementById('articleModalTitle').textContent='Edit Article';artModal.style.display='flex';}));
    document.querySelectorAll('.delete-article-btn').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Delete?'))return;const f=new FormData();f.append('action','delete_article');f.append('article_id',b.dataset.id);const r=await fetch('',{method:'POST',body:f,headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();showToast(d.message,d.success?'success':'error');if(d.success)setTimeout(()=>location.reload(),1000);}));
    artForm.addEventListener('submit',async(e)=>{e.preventDefault();const b=artForm.querySelector('button[type="submit"]');const o=b.innerHTML;b.disabled=true;b.innerHTML='<span class="loading-spinner"></span> Saving...';const r=await fetch('',{method:'POST',body:new FormData(artForm),headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();showToast(d.message,d.success?'success':'error');b.disabled=false;b.innerHTML=o;if(d.success){artModal.style.display='none';setTimeout(()=>location.reload(),1000);}});
    
    // Ticket Modal
    const ticModal=document.getElementById('ticketModal'),ticForm=document.getElementById('ticketForm');
    document.querySelectorAll('.respond-ticket-btn').forEach(b=>b.addEventListener('click',async()=>{const r=await fetch(`?ajax=1&ticket=${b.dataset.id}`);const d=await r.json();document.getElementById('ticket_id').value=d.id;document.getElementById('ticket_status').value=d.status;document.getElementById('ticket_response').value=d.admin_response||'';document.getElementById('ticketInfo').innerHTML=`<strong>#${d.id} - ${d.subject}</strong><br><small>From: ${d.user_name} (${d.user_email})</small><br><small>Message: ${d.message}</small>`;ticModal.style.display='flex';}));
    document.querySelectorAll('#closeTicketModal, #cancelTicketModal').forEach(b=>b.addEventListener('click',()=>ticModal.style.display='none'));
    document.querySelectorAll('.delete-ticket-btn').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Delete?'))return;const f=new FormData();f.append('action','delete_ticket');f.append('ticket_id',b.dataset.id);const r=await fetch('',{method:'POST',body:f,headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();showToast(d.message,d.success?'success':'error');if(d.success)setTimeout(()=>location.reload(),1000);}));
    ticForm.addEventListener('submit',async(e)=>{e.preventDefault();const b=ticForm.querySelector('button[type="submit"]');const o=b.innerHTML;b.disabled=true;b.innerHTML='<span class="loading-spinner"></span> Updating...';const r=await fetch('',{method:'POST',body:new FormData(ticForm),headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();showToast(d.message,d.success?'success':'error');b.disabled=false;b.innerHTML=o;if(d.success){ticModal.style.display='none';setTimeout(()=>location.reload(),1000);}});
    
    // Close modals on overlay click
    document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',(e)=>{if(e.target===m)m.style.display='none';}));
</script>
</body>
</html>