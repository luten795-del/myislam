<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/database.php';
// ========== ADDED: Include notification functions ==========
require_once __DIR__ . '/../includes/notifications.php';
// ===========================================================

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

// Handle file uploads
function handleMediaUpload(array $file, string $type): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = [
        'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'],
        'video'    => ['mp4', 'webm', 'ogg', 'mov'],
        'audio'    => ['mp3', 'wav', 'ogg', 'm4a'],
        'document' => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'rtf']
    ];
    
    if (!isset($allowed[$type]) || !in_array($ext, $allowed[$type])) {
        return null;
    }
    
    $uploadDir = __DIR__ . '/../uploads/media/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $filename = uniqid('media_', true) . '.' . $ext;
    $destination = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'uploads/media/' . $filename;
    }
    return null;
}

// Generate slug from title
function generateSlug(string $title): string {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    $slug = trim($slug, '-');
    return $slug ?: 'media';
}

// Turn a pasted YouTube/Vimeo link (or a full <iframe> embed snippet) into
// a clean, embeddable URL. Accepts watch?v=, youtu.be/, shorts/, and
// already-embed YouTube links, plus vimeo.com links; anything else is left
// as-is so a direct embed URL from another host still works.
function normalizeVideoEmbedUrl(string $url): string {
    $url = trim($url);
    if ($url === '') return '';

    // If the admin pasted a full <iframe> embed snippet, pull the src out of it.
    if (preg_match('/src=["\']([^"\']+)["\']/', $url, $m)) {
        $url = $m[1];
    }

    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }

    return $url;
}

// Extract a YouTube video id from an embed/watch URL, for building a
// thumbnail preview - returns null for non-YouTube URLs.
function extractYoutubeId(string $url): ?string {
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
        return $m[1];
    }
    return null;
}

// Ensure unique slug
function uniqueSlug(PDO $conn, string $baseSlug, ?int $excludeId = null): string {
    $slug = $baseSlug;
    $counter = 1;
    while (true) {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT id FROM media WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM media WHERE slug = ?");
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
    
    if ($action === 'save_media') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $mediaType = $_POST['media_type'] ?? 'image';
        $subjectId = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? (int)$_POST['subject_id'] : null;
        $status = $_POST['status'] ?? 'published';
        $categoryIds = $_POST['category_ids'] ?? [];
        $tagNames = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));
        
        if ($title === '') {
            $response = ['success' => false, 'message' => 'Title is required.'];
        } else {
            try {
                $conn->beginTransaction();
                
                $slug = generateSlug($title);
                $slug = uniqueSlug($conn, $slug, $mediaId > 0 ? $mediaId : null);
                
                $filePath = $_POST['existing_file_path'] ?? '';
                $fileSize = $_POST['existing_file_size'] ?? null;
                $duration = !empty($_POST['duration']) ? (int)$_POST['duration'] : null;

                // A video can either be an uploaded file OR a YouTube/Vimeo
                // URL (not both) - the "video_source" radio in the form
                // tells us which one the admin means to use right now.
                $videoSource = $_POST['video_source'] ?? 'upload';
                $videoUrl = null;
                if ($mediaType === 'video' && $videoSource === 'url' && !empty($_POST['video_url'])) {
                    $videoUrl = normalizeVideoEmbedUrl(trim($_POST['video_url']));
                    // A URL-sourced video has no local file - clear any
                    // previously-uploaded file so the two don't coexist.
                    $filePath = '';
                    $fileSize = null;
                }
                
                // Handle new file upload
                if ($videoUrl === null && isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
                    $uploadedPath = handleMediaUpload($_FILES['media_file'], $mediaType);
                    if ($uploadedPath) {
                        $filePath = $uploadedPath;
                        $fileSize = $_FILES['media_file']['size'];
                    }
                }
                
                if (empty($filePath) && empty($videoUrl) && $mediaId === 0) {
                    $response = ['success' => false, 'message' => 'Please upload a media file or enter a video URL.'];
                    $conn->rollBack();
                    goto send_response;
                }
                
                if ($mediaId > 0) {
                    $stmt = $conn->prepare("UPDATE media SET title=?, slug=?, description=?, media_type=?, file_path=?, video_url=?, file_size=?, duration=?, subject_id=?, status=? WHERE id=?");
                    $stmt->execute([$title, $slug, $description, $mediaType, $filePath ?: null, $videoUrl, $fileSize, $duration, $subjectId, $status, $mediaId]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO media (title, slug, description, media_type, file_path, video_url, file_size, duration, subject_id, status, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$title, $slug, $description, $mediaType, $filePath ?: null, $videoUrl, $fileSize, $duration, $subjectId, $status, $userId]);
                    $mediaId = $conn->lastInsertId();
                }
                
                // Update category relations
                $conn->prepare("DELETE FROM media_category_relations WHERE media_id = ?")->execute([$mediaId]);
                foreach ($categoryIds as $catId) {
                    $conn->prepare("INSERT INTO media_category_relations (media_id, category_id) VALUES (?, ?)")->execute([$mediaId, (int)$catId]);
                }
                
                $conn->commit();
                
                // ========== ADDED: Notify users if media is published ==========
                if ($status === 'published') {
                    $notifyTitle = "New Media: " . $title;
                    $notifyMessage = $description ?: "A new {$mediaType} has been added to the library.";
                    $notifyLink = "/media.php?id=" . $mediaId; // Adjust to actual media view page
                    createNotificationForAll($conn, 'multimedia', $notifyTitle, $notifyMessage, $notifyLink, 'user');
                }
                // =============================================================
                
                $response = ['success' => true, 'message' => 'Media saved successfully.'];
            } catch (PDOException $e) {
                $conn->rollBack();
                $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
            }
        }
        send_response:
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
    }
    
    if ($action === 'delete_media') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        if ($mediaId > 0) {
            $stmt = $conn->prepare("DELETE FROM media WHERE id = ?");
            $stmt->execute([$mediaId]);
            $response = ['success' => true, 'message' => 'Media deleted.'];
        } else {
            $response = ['success' => false, 'message' => 'Invalid media ID.'];
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
    }
    
    if ($action === 'save_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '📁');
        if ($name === '') {
            $response = ['success' => false, 'message' => 'Category name required.'];
        } else {
            $slug = generateSlug($name);
            if ($catId > 0) {
                $stmt = $conn->prepare("UPDATE media_categories SET name=?, slug=?, icon=? WHERE id=?");
                $stmt->execute([$name, $slug, $icon, $catId]);
            } else {
                $stmt = $conn->prepare("INSERT INTO media_categories (name, slug, icon) VALUES (?, ?, ?)");
                $stmt->execute([$name, $slug, $icon]);
            }
            $response = ['success' => true, 'message' => 'Category saved.'];
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
    }
    
    if ($action === 'delete_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $conn->prepare("DELETE FROM media_categories WHERE id = ?")->execute([$catId]);
        $response = ['success' => true, 'message' => 'Category deleted.'];
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
    }
}

// Fetch data for display
$mediaList = $conn->query("
    SELECT m.*, 
           (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM media_category_relations mcr 
            JOIN media_categories c ON c.id = mcr.category_id WHERE mcr.media_id = m.id) AS categories
    FROM media m
    ORDER BY m.created_at DESC
")->fetchAll();

$categories = $conn->query("SELECT * FROM media_categories ORDER BY sort_order, name")->fetchAll();
$subjects = $conn->query("SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name")->fetchAll();

// AJAX endpoint to fetch media for editing
if (isset($_GET['ajax']) && isset($_GET['edit_media'])) {
    $editId = (int)$_GET['edit_media'];
    $stmt = $conn->prepare("SELECT * FROM media WHERE id = ?");
    $stmt->execute([$editId]);
    $media = $stmt->fetch();
    if ($media) {
        $catStmt = $conn->prepare("SELECT category_id FROM media_category_relations WHERE media_id = ?");
        $catStmt->execute([$editId]);
        $media['category_ids'] = array_column($catStmt->fetchAll(), 'category_id');
        header('Content-Type: application/json');
        echo json_encode($media);
        exit;
    }
    http_response_code(404);
    exit;
}

function formatSize($bytes): string {
    if (!$bytes) return '';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function getFileIcon($type): string {
    return [
        'image'    => 'fa-image',
        'video'    => 'fa-video',
        'audio'    => 'fa-headphones',
        'document' => 'fa-file-alt'
    ][$type] ?? 'fa-file';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Multimedia – MyIslam</title>
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
            background: var(--bg-page);
            color: var(--text-dark);
            line-height: 1.6;
            padding: 24px 32px;
            min-height: 100vh;
        }
        .container{max-width: 1400px; margin: 0 auto;}
        .page-header{
            display: flex; align-items: center; flex-wrap: wrap; gap: 20px 30px;
            margin-bottom: 32px; padding-bottom: 20px; border-bottom: 1px solid var(--border);
        }
        .back-link{
            display: flex; align-items: center; gap: 8px; text-decoration: none;
            color: var(--primary); font-weight: 600; transition: all 0.2s;
        }
        .back-link:hover{color: var(--primary-light);}
        .page-title{font-size: 28px; font-weight: 700;}
        .action-bar{
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px; flex-wrap: wrap; gap: 16px;
        }
        .btn{
            display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px;
            border-radius: 40px; font-weight: 600; font-size: 15px; border: none;
            cursor: pointer; transition: all 0.2s; text-decoration: none;
            background: var(--surface); color: var(--text-dark); border: 1px solid var(--border);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .btn-primary{
            background: var(--primary); color: white; border: none;
            box-shadow: 0 8px 16px -4px rgba(45,106,79,0.25);
        }
        .btn-primary:hover{background: var(--primary-light); transform: translateY(-2px);}
        .btn-danger{background: #fff1f0; color: var(--danger); border-color: #ffccc7;}
        .btn-danger:hover{background: #ffe7e5;}
        .btn-sm{padding: 8px 16px; font-size: 13px;}
        
        .tabs{display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 1px solid var(--border);}
        .tab{
            padding: 12px 24px; background: none; border: none; font-weight: 600;
            color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .tab.active{color: var(--primary); border-bottom-color: var(--primary);}
        .tab:hover{color: var(--primary);}
        
        .table-wrapper{
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow-x: auto;
        }
        table{width: 100%; border-collapse: collapse;}
        th, td{padding: 16px; text-align: left; border-bottom: 1px solid var(--border);}
        th{background: var(--surface-2); font-weight: 600; color: var(--text-light); font-size: 13px;}
        tr:last-child td{border-bottom: none;}
        .media-thumb{width: 60px; height: 60px; border-radius: 10px; object-fit: cover; background: var(--surface-2);}
        .badge{
            display: inline-block; padding: 4px 10px; border-radius: 40px;
            font-size: 12px; font-weight: 500; background: var(--surface-2);
        }
        .badge.image{background: #e0f2fe; color: #0369a1;}
        .badge.video{background: #fce7f3; color: #be185d;}
        .badge.audio{background: #fef3c7; color: #b45309;}
        .badge.document{background: #e6e6fa; color: #4b0082;}
        .actions{display: flex; gap: 8px;}
        
        .categories-grid{
            display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px; margin-top: 20px;
        }
        .category-card{
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm);
            padding: 16px; display: flex; align-items: center; justify-content: space-between;
        }
        .category-info{display: flex; align-items: center; gap: 12px;}
        .category-icon{font-size: 24px;}
        
        .modal-overlay{
            display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.4); backdrop-filter: blur(6px);
            align-items: center; justify-content: center; z-index: 1000;
        }
        .modal{
            background: var(--surface); border-radius: 24px; width: 90%; max-width: 700px;
            max-height: 85vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
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
        .form-group label{font-weight: 600; font-size: 14px;}
        .form-control{
            padding: 12px 16px; border: 1px solid var(--border); border-radius: 14px;
            background: var(--surface-2); font-size: 15px; transition: all 0.2s;
        }
        .form-control:focus{outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); background: white;}
        .checkbox-group{display: flex; flex-wrap: wrap; gap: 12px; margin-top: 8px;}
        .checkbox-item{display: flex; align-items: center; gap: 8px;}
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
        }
        .toast.success{border-left-color: var(--success);}
        .toast.error{border-left-color: var(--danger);}
        .toast i{font-size: 22px;}
        .toast-message{flex:1; font-weight: 500;}
        .toast-close{cursor: pointer; opacity: 0.6;}
        @keyframes slideIn{from{transform: translateX(100%); opacity:0;}to{transform: translateX(0); opacity:1;}}
        .loading-spinner{display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin 0.8s linear infinite;}
        @keyframes spin{to{transform: rotate(360deg);}}
        
        @media (max-width:768px){
            body{padding:16px;}
            .page-header{flex-direction:column; align-items:flex-start;}
            .modal{width:95%;}
        }
    </style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>

<div class="container">
    <div class="page-header">
        <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h1 class="page-title"><i class="fas fa-photo-video"></i> Multimedia Library</h1>
    </div>
    
    <div class="tabs">
        <button class="tab active" data-tab="media">Media Items</button>
        <button class="tab" data-tab="categories">Categories</button>
    </div>
    
    <!-- Media Tab -->
    <div id="mediaTab">
        <div class="action-bar">
            <button class="btn btn-primary" id="addMediaBtn"><i class="fas fa-plus"></i> Add New Media</button>
            <div>
                <input type="text" id="searchMedia" class="form-control" placeholder="Search media..." style="width:250px;">
            </div>
        </div>
        
        <div class="table-wrapper">
            <table id="mediaTable">
                <thead>
                    <tr>
                        <th>Thumb</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Categories</th>
                        <th>Size</th>
                        <th>Views</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mediaList as $media): ?>
                    <?php $ytId = !empty($media['video_url']) ? extractYoutubeId($media['video_url']) : null; ?>
                    <tr data-title="<?= e(strtolower($media['title'])) ?>" data-type="<?= $media['media_type'] ?>">
                        <td>
                            <?php if ($media['thumbnail_path']): ?>
                                <img src="../<?= e($media['thumbnail_path']) ?>" class="media-thumb" alt="">
                            <?php elseif ($media['media_type'] === 'image'): ?>
                                <img src="../<?= e($media['file_path']) ?>" class="media-thumb" alt="">
                            <?php elseif ($ytId): ?>
                                <img src="https://img.youtube.com/vi/<?= e($ytId) ?>/default.jpg" class="media-thumb" alt="">
                            <?php else: ?>
                                <div class="media-thumb" style="display:flex;align-items:center;justify-content:center;">
                                    <i class="fas <?= getFileIcon($media['media_type']) ?> fa-2x" style="color:var(--text-muted);"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= e($media['title']) ?></strong></td>
                        <td>
                            <span class="badge <?= $media['media_type'] ?>"><?= ucfirst($media['media_type']) ?></span>
                            <?php if (!empty($media['video_url'])): ?>
                                <span class="badge" style="background:#fee2e2;color:#be185d;"><i class="fab fa-youtube"></i> URL</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($media['categories'] ?? '—') ?></td>
                        <td><?= formatSize($media['file_size']) ?></td>
                        <td><?= $media['view_count'] ?></td>
                        <td><span class="badge"><?= ucfirst($media['status']) ?></span></td>
                        <td class="actions">
                            <button class="btn btn-sm edit-media-btn" data-id="<?= $media['id'] ?>"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-danger delete-media-btn" data-id="<?= $media['id'] ?>"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Categories Tab -->
    <div id="categoriesTab" style="display:none;">
        <div class="action-bar">
            <button class="btn btn-primary" id="addCategoryBtn"><i class="fas fa-plus"></i> Add Category</button>
        </div>
        <div class="categories-grid" id="categoriesGrid">
            <?php foreach ($categories as $cat): ?>
            <div class="category-card">
                <div class="category-info">
                    <span class="category-icon"><?= e($cat['icon'] ?? '📁') ?></span>
                    <span><?= e($cat['name']) ?></span>
                </div>
                <div class="actions">
                    <button class="btn btn-sm edit-category-btn" data-id="<?= $cat['id'] ?>" data-name="<?= e($cat['name']) ?>" data-icon="<?= e($cat['icon'] ?? '📁') ?>"><i class="fas fa-edit"></i></button>
                    <button class="btn btn-sm btn-danger delete-category-btn" data-id="<?= $cat['id'] ?>"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Media Modal -->
<div class="modal-overlay" id="mediaModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="mediaModalTitle">Add Media</h2>
            <button class="modal-close" id="closeMediaModal">&times;</button>
        </div>
        <form id="mediaForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_media">
            <input type="hidden" name="media_id" id="media_id" value="0">
            <input type="hidden" name="existing_file_path" id="existing_file_path" value="">
            <input type="hidden" name="existing_file_size" id="existing_file_size" value="">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Media Type *</label>
                        <select name="media_type" id="media_type" class="form-control" required>
                            <option value="image">Image</option>
                            <option value="video">Video</option>
                            <option value="audio">Audio</option>
                            <option value="document">Document</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="title" id="media_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="media_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group" id="videoSourceGroup" style="display:none;">
                        <label>Video Source</label>
                        <div class="checkbox-group" style="margin-top:0;">
                            <label class="checkbox-item"><input type="radio" name="video_source" value="upload" id="videoSourceUpload" checked> Upload a file</label>
                            <label class="checkbox-item"><input type="radio" name="video_source" value="url" id="videoSourceUrl"> YouTube / Vimeo URL</label>
                        </div>
                    </div>
                    <div class="form-group" id="mediaFileGroup">
                        <label id="mediaFileLabel">Media File <?= $mediaId ?? '' ? '' : '*' ?></label>
                        <input type="file" name="media_file" id="media_file" class="form-control" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.rtf">
                        <small id="currentFileDisplay"></small>
                    </div>
                    <div class="form-group" id="videoUrlGroup" style="display:none;">
                        <label>YouTube / Vimeo URL</label>
                        <input type="text" name="video_url" id="video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                        <small style="color:var(--text-muted);">Paste a normal watch link, a youtu.be link, or an embed code - it's converted automatically.</small>
                        <div id="youtubePreviewWrap" style="display:none;margin-top:12px;border-radius:14px;overflow:hidden;background:#000;aspect-ratio:16/9;">
                            <iframe id="youtubePreviewFrame" style="width:100%;height:100%;border:0;" allowfullscreen></iframe>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Duration (seconds, for video/audio)</label>
                        <input type="number" name="duration" id="duration" class="form-control" min="0">
                    </div>
                    <div class="form-group">
                        <label>Subject (optional)</label>
                        <select name="subject_id" id="subject_id" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Categories</label>
                        <div class="checkbox-group">
                            <?php foreach ($categories as $cat): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="category_ids[]" value="<?= $cat['id'] ?>"> <?= e($cat['name']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Tags (comma separated)</label>
                        <input type="text" name="tags" id="tags" class="form-control" placeholder="e.g., quran, lecture, makkah">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="draft">Draft</option>
                            <option value="published" selected>Published</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" id="cancelMediaModal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveMediaBtn">Save Media</button>
            </div>
        </form>
    </div>
</div>

<!-- Category Modal -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal" style="max-width:450px;">
        <div class="modal-header">
            <h2 id="categoryModalTitle">Add Category</h2>
            <button class="modal-close" id="closeCategoryModal">&times;</button>
        </div>
        <form id="categoryForm">
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="category_id" id="category_id" value="0">
            <div class="modal-body">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" id="category_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Icon (emoji)</label>
                    <input type="text" name="icon" id="category_icon" class="form-control" placeholder="📁" value="📁">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" id="cancelCategoryModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Toast
    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i><span class="toast-message">${message}</span><span class="toast-close" onclick="this.parentElement.remove()">&times;</span>`;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
    }
    
    // Tab switching
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            const tabName = tab.dataset.tab;
            document.getElementById('mediaTab').style.display = tabName === 'media' ? 'block' : 'none';
            document.getElementById('categoriesTab').style.display = tabName === 'categories' ? 'block' : 'none';
        });
    });
    
    // Media Modal
    const mediaModal = document.getElementById('mediaModal');
    const mediaForm = document.getElementById('mediaForm');
    const addMediaBtn = document.getElementById('addMediaBtn');
    const closeMediaModal = document.getElementById('closeMediaModal');
    const cancelMediaModal = document.getElementById('cancelMediaModal');

    // ---- Video source (upload vs YouTube/Vimeo URL) + live preview ----
    const mediaTypeSelect = document.getElementById('media_type');
    const videoSourceGroup = document.getElementById('videoSourceGroup');
    const mediaFileGroup = document.getElementById('mediaFileGroup');
    const videoUrlGroup = document.getElementById('videoUrlGroup');
    const videoUrlInput = document.getElementById('video_url');
    const youtubePreviewWrap = document.getElementById('youtubePreviewWrap');
    const youtubePreviewFrame = document.getElementById('youtubePreviewFrame');

    function extractYoutubeEmbed(url) {
        if (!url) return null;
        const m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
        if (m) return 'https://www.youtube.com/embed/' + m[1];
        const v = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        if (v) return 'https://player.vimeo.com/video/' + v[1];
        return null;
    }

    function updatePreview() {
        const embed = extractYoutubeEmbed(videoUrlInput.value.trim());
        if (embed) {
            youtubePreviewFrame.src = embed;
            youtubePreviewWrap.style.display = 'block';
        } else {
            youtubePreviewFrame.src = '';
            youtubePreviewWrap.style.display = 'none';
        }
    }
    videoUrlInput.addEventListener('input', updatePreview);

    function applyVideoSourceVisibility() {
        const isVideo = mediaTypeSelect.value === 'video';
        videoSourceGroup.style.display = isVideo ? 'block' : 'none';
        const wantsUrl = isVideo && document.getElementById('videoSourceUrl').checked;
        mediaFileGroup.style.display = wantsUrl ? 'none' : 'block';
        videoUrlGroup.style.display = wantsUrl ? 'block' : 'none';
        document.getElementById('media_file').required = false; // always optional - checked server-side
        if (!wantsUrl) { youtubePreviewWrap.style.display = 'none'; }
    }
    mediaTypeSelect.addEventListener('change', applyVideoSourceVisibility);
    document.querySelectorAll('input[name="video_source"]').forEach(radio => {
        radio.addEventListener('change', () => { applyVideoSourceVisibility(); updatePreview(); });
    });
    applyVideoSourceVisibility(); // sync visibility with whatever the browser restored on load

    function resetMediaForm() {
        mediaForm.reset();
        document.getElementById('media_id').value = '0';
        document.getElementById('existing_file_path').value = '';
        document.getElementById('existing_file_size').value = '';
        document.getElementById('currentFileDisplay').textContent = '';
        document.getElementById('mediaModalTitle').textContent = 'Add Media';
        document.getElementById('videoSourceUpload').checked = true;
        videoUrlInput.value = '';
        document.querySelectorAll('input[name="category_ids[]"]').forEach(cb => cb.checked = false);
        applyVideoSourceVisibility();
        updatePreview();
    }
    
    addMediaBtn.addEventListener('click', () => {
        resetMediaForm();
        mediaModal.style.display = 'flex';
    });
    
    [closeMediaModal, cancelMediaModal].forEach(btn => btn.addEventListener('click', () => {
        mediaModal.style.display = 'none';
    }));
    
    mediaModal.addEventListener('click', (e) => { if (e.target === mediaModal) mediaModal.style.display = 'none'; });
    
    // Edit media
    document.querySelectorAll('.edit-media-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            try {
                const resp = await fetch(`?ajax=1&edit_media=${id}`);
                const data = await resp.json();
                document.getElementById('media_id').value = data.id;
                document.getElementById('media_type').value = data.media_type;
                document.getElementById('media_title').value = data.title;
                document.getElementById('media_description').value = data.description || '';
                document.getElementById('existing_file_path').value = data.file_path || '';
                document.getElementById('existing_file_size').value = data.file_size;
                document.getElementById('duration').value = data.duration || '';
                document.getElementById('subject_id').value = data.subject_id || '';
                document.getElementById('status').value = data.status;
                document.getElementById('currentFileDisplay').textContent = data.file_path ? `Current: ${data.file_path.split('/').pop()}` : '';
                videoUrlInput.value = data.video_url || '';
                document.getElementById('videoSourceUrl').checked = !!data.video_url;
                document.getElementById('videoSourceUpload').checked = !data.video_url;
                document.querySelectorAll('input[name="category_ids[]"]').forEach(cb => {
                    cb.checked = data.category_ids && data.category_ids.includes(parseInt(cb.value));
                });
                document.getElementById('mediaModalTitle').textContent = 'Edit Media';
                applyVideoSourceVisibility();
                updatePreview();
                mediaModal.style.display = 'flex';
            } catch(e) {
                showToast('Failed to load media', 'error');
            }
        });
    });
    
    // Delete media
    document.querySelectorAll('.delete-media-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Delete this media item?')) return;
            const id = btn.dataset.id;
            const formData = new FormData();
            formData.append('action', 'delete_media');
            formData.append('media_id', id);
            try {
                const resp = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const data = await resp.json();
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 1000);
            } catch { showToast('Error', 'error'); }
        });
    });
    
    // Media form submit
    mediaForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('saveMediaBtn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span> Saving...';
        try {
            const resp = await fetch('', { method: 'POST', body: new FormData(mediaForm), headers: {'X-Requested-With': 'XMLHttpRequest'} });
            const data = await resp.json();
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) { mediaModal.style.display = 'none'; setTimeout(() => location.reload(), 1000); }
        } catch { showToast('Error', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = originalText; }
    });
    
    // Category Modal
    const categoryModal = document.getElementById('categoryModal');
    const categoryForm = document.getElementById('categoryForm');
    const addCategoryBtn = document.getElementById('addCategoryBtn');
    const closeCategoryModal = document.getElementById('closeCategoryModal');
    const cancelCategoryModal = document.getElementById('cancelCategoryModal');
    
    addCategoryBtn.addEventListener('click', () => {
        document.getElementById('category_id').value = '0';
        document.getElementById('category_name').value = '';
        document.getElementById('category_icon').value = '📁';
        document.getElementById('categoryModalTitle').textContent = 'Add Category';
        categoryModal.style.display = 'flex';
    });
    
    [closeCategoryModal, cancelCategoryModal].forEach(btn => btn.addEventListener('click', () => categoryModal.style.display = 'none'));
    categoryModal.addEventListener('click', (e) => { if (e.target === categoryModal) categoryModal.style.display = 'none'; });
    
    document.querySelectorAll('.edit-category-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('category_id').value = btn.dataset.id;
            document.getElementById('category_name').value = btn.dataset.name;
            document.getElementById('category_icon').value = btn.dataset.icon;
            document.getElementById('categoryModalTitle').textContent = 'Edit Category';
            categoryModal.style.display = 'flex';
        });
    });
    
    document.querySelectorAll('.delete-category-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Delete this category?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_category');
            formData.append('category_id', btn.dataset.id);
            try {
                const resp = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const data = await resp.json();
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 1000);
            } catch { showToast('Error', 'error'); }
        });
    });
    
    categoryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = categoryForm.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span> Saving...';
        try {
            const resp = await fetch('', { method: 'POST', body: new FormData(categoryForm), headers: {'X-Requested-With': 'XMLHttpRequest'} });
            const data = await resp.json();
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) { categoryModal.style.display = 'none'; setTimeout(() => location.reload(), 1000); }
        } catch { showToast('Error', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = originalText; }
    });
    
    // Search filter
    document.getElementById('searchMedia').addEventListener('input', (e) => {
        const term = e.target.value.toLowerCase();
        document.querySelectorAll('#mediaTable tbody tr').forEach(row => {
            const title = row.dataset.title || '';
            row.style.display = title.includes(term) ? '' : 'none';
        });
    });
</script>
</body>
</html>