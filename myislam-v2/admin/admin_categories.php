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
    return $text !== '' ? $text : 'category';
}

function formatFileSize(int $bytes): string {
    if ($bytes <= 0) return 'Unknown';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function getFileTypeFromExtension(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = [
        'pdf' => 'pdf',
        'doc' => 'word', 'docx' => 'word',
        'xls' => 'excel', 'xlsx' => 'excel',
        'ppt' => 'powerpoint', 'pptx' => 'powerpoint',
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image'
    ];
    return $types[$ext] ?? 'other';
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn) {
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
        $user = $stmt->fetch();

        if (!$user) return null;
        if (strtolower((string)($user['role'] ?? '')) !== 'admin') return null;

        return $user;
    } catch (PDOException $e) {
        error_log("getAdminUser: " . $e->getMessage());
        return null;
    }
}

function getSubjects(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT id, name, slug, icon, is_active
            FROM subjects
            ORDER BY sort_order ASC, name ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getSubjects: " . $e->getMessage());
        return [];
    }
}

function getCategories(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT
                c.id,
                c.subject_id,
                c.name,
                c.slug,
                c.description,
                c.sort_order,
                c.is_active,
                c.image_path,
                s.name AS subject_name,
                s.icon AS subject_icon,
                COUNT(DISTINCT ct.id) AS total_contents,
                COUNT(DISTINCT cm.id) AS total_materials
            FROM categories c
            LEFT JOIN subjects s ON s.id = c.subject_id
            LEFT JOIN contents ct ON ct.category_id = c.id
            LEFT JOIN category_materials cm ON cm.category_id = c.id
            GROUP BY
                c.id, c.subject_id, c.name, c.slug, c.description, c.sort_order, c.is_active, c.image_path,
                s.name, s.icon
            ORDER BY s.name ASC, c.sort_order ASC, c.name ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getCategories: " . $e->getMessage());
        return [];
    }
}

function getCategoryById(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT id, subject_id, name, slug, description, sort_order, is_active, image_path, lock_previous
            FROM categories
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getCategoryById: " . $e->getMessage());
        return null;
    }
}

function getCategoryMaterials(PDO $conn, int $categoryId): array {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM category_materials
            WHERE category_id = ?
            ORDER BY sort_order ASC, created_at DESC
        ");
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getCategoryMaterials: " . $e->getMessage());
        return [];
    }
}

function categorySlugExists(PDO $conn, string $slug, ?int $excludeId = null): bool {
    try {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE slug = ? AND id != ? LIMIT 1");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
        }
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        error_log("categorySlugExists: " . $e->getMessage());
        return false;
    }
}

function uniqueCategorySlug(PDO $conn, string $baseSlug, ?int $excludeId = null): string {
    $slug = $baseSlug !== '' ? $baseSlug : 'category';
    $counter = 1;

    while (categorySlugExists($conn, $slug, $excludeId)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }

    return $slug;
}

// Create materials table if not exists
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `category_materials` (
            `id` int NOT NULL AUTO_INCREMENT,
            `category_id` int NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text,
            `file_path` varchar(500) NOT NULL,
            `thumbnail_path` varchar(500) DEFAULT NULL,
            `file_type` enum('pdf','word','excel','powerpoint','image','other') NOT NULL DEFAULT 'pdf',
            `file_size` int DEFAULT NULL,
            `is_external_link` tinyint(1) NOT NULL DEFAULT '0',
            `external_url` varchar(500) DEFAULT NULL,
            `allow_download` tinyint(1) NOT NULL DEFAULT '1',
            `view_count` int NOT NULL DEFAULT '0',
            `download_count` int NOT NULL DEFAULT '0',
            `sort_order` int NOT NULL DEFAULT '0',
            `is_active` tinyint(1) NOT NULL DEFAULT '1',
            `created_by` int NOT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `category_id` (`category_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Add image_path column to categories if not exists
    $conn->exec("
        ALTER TABLE categories ADD COLUMN IF NOT EXISTS `image_path` VARCHAR(255) DEFAULT NULL AFTER `icon`
    ");
} catch (PDOException $e) {
    // Table or column might already exist
}

$admin = getAdminUser($conn, $userId);
if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Admins only.');
}

// ========== AJAX ENDPOINTS ==========
if (isset($_GET['ajax']) && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editCategory = getCategoryById($conn, $editId);
    if ($editCategory) {
        header('Content-Type: application/json');
        echo json_encode($editCategory);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Category not found']);
        exit;
    }
}

if (isset($_GET['ajax']) && isset($_GET['get_materials'])) {
    $categoryId = (int)$_GET['get_materials'];
    $materials = getCategoryMaterials($conn, $categoryId);
    header('Content-Type: application/json');
    echo json_encode($materials);
    exit;
}

if (isset($_GET['ajax']) && isset($_GET['get_material'])) {
    $materialId = (int)$_GET['get_material'];
    try {
        $stmt = $conn->prepare("SELECT * FROM category_materials WHERE id = ?");
        $stmt->execute([$materialId]);
        $material = $stmt->fetch();
        header('Content-Type: application/json');
        echo json_encode($material);
        exit;
    } catch (PDOException $e) {
        http_response_code(404);
        echo json_encode(['error' => 'Material not found']);
        exit;
    }
}

$message = '';
$messageType = 'success';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ========== MATERIALS MANAGEMENT ==========
    
    // Save material
    if ($action === 'save_material') {
        $materialId = isset($_POST['material_id']) ? (int)$_POST['material_id'] : 0;
        $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $isExternal = isset($_POST['is_external_link']) ? 1 : 0;
        $externalUrl = trim((string)($_POST['external_url'] ?? ''));
        $allowDownload = isset($_POST['allow_download']) ? 1 : 0;
        $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $filePath = '';
        $fileType = 'other';
        $fileSize = null;
        $thumbnailPath = $_POST['existing_thumbnail'] ?? null;
        
        // Handle file upload (main file)
        if (!$isExternal && isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/myislam/uploads/materials/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $originalName = basename($_FILES['material_file']['name']);
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $newFilename = uniqid('mat_') . '.' . $extension;
            $uploadPath = $uploadDir . $newFilename;
            
            if (move_uploaded_file($_FILES['material_file']['tmp_name'], $uploadPath)) {
                $filePath = '/myislam/uploads/materials/' . $newFilename;
                $fileType = getFileTypeFromExtension($originalName);
                $fileSize = $_FILES['material_file']['size'];
            } else {
                $message = 'Failed to upload file.';
                $messageType = 'danger';
            }
        } elseif ($materialId > 0 && !$isExternal) {
            // Keep existing file path for update
            $stmt = $conn->prepare("SELECT file_path, file_type, file_size FROM category_materials WHERE id = ?");
            $stmt->execute([$materialId]);
            $existing = $stmt->fetch();
            if ($existing) {
                $filePath = $existing['file_path'];
                $fileType = $existing['file_type'];
                $fileSize = $existing['file_size'];
            }
        }
        
        // Handle thumbnail upload
        if (isset($_FILES['material_thumbnail']) && $_FILES['material_thumbnail']['error'] === UPLOAD_ERR_OK && $_FILES['material_thumbnail']['size'] > 0) {
            $thumbUploadDir = $_SERVER['DOCUMENT_ROOT'] . '/myislam/uploads/materials/thumbnails/';
            if (!is_dir($thumbUploadDir)) {
                mkdir($thumbUploadDir, 0777, true);
            }
            
            $thumbExt = strtolower(pathinfo($_FILES['material_thumbnail']['name'], PATHINFO_EXTENSION));
            $allowedThumbExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($thumbExt, $allowedThumbExts)) {
                $thumbFilename = 'thumb_' . uniqid() . '.' . $thumbExt;
                $thumbUploadPath = $thumbUploadDir . $thumbFilename;
                
                if (move_uploaded_file($_FILES['material_thumbnail']['tmp_name'], $thumbUploadPath)) {
                    // Delete old thumbnail if exists
                    if ($materialId > 0 && !empty($thumbnailPath) && file_exists($_SERVER['DOCUMENT_ROOT'] . $thumbnailPath)) {
                        unlink($_SERVER['DOCUMENT_ROOT'] . $thumbnailPath);
                    }
                    $thumbnailPath = '/myislam/uploads/materials/thumbnails/' . $thumbFilename;
                }
            }
        }
        
        if ($title === '') {
            $message = 'Material title is required.';
            $messageType = 'danger';
        } elseif ($isExternal && $externalUrl === '') {
            $message = 'External URL is required for external links.';
            $messageType = 'danger';
        } elseif (!$isExternal && $filePath === '' && $materialId === 0) {
            $message = 'Please upload a file.';
            $messageType = 'danger';
        } else {
            try {
                if ($materialId > 0) {
                    // UPDATE existing material
                    if ($isExternal) {
                        $stmt = $conn->prepare("
                            UPDATE category_materials 
                            SET title = ?, description = ?, is_external_link = ?, external_url = ?, 
                                allow_download = ?, sort_order = ?, is_active = ?, thumbnail_path = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$title, $description, $isExternal, $externalUrl, $allowDownload, $sortOrder, $isActive, $thumbnailPath, $materialId]);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE category_materials 
                            SET title = ?, description = ?, file_path = ?, file_type = ?, file_size = ?,
                                is_external_link = ?, allow_download = ?, sort_order = ?, is_active = ?, thumbnail_path = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$title, $description, $filePath, $fileType, $fileSize, $isExternal, $allowDownload, $sortOrder, $isActive, $thumbnailPath, $materialId]);
                    }
                    $message = 'Material updated successfully.';
                } else {
                    // INSERT new material
                    if ($isExternal) {
                        $stmt = $conn->prepare("
                            INSERT INTO category_materials (category_id, title, description, is_external_link, external_url, allow_download, sort_order, is_active, created_by, thumbnail_path)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$categoryId, $title, $description, $isExternal, $externalUrl, $allowDownload, $sortOrder, $isActive, $userId, $thumbnailPath]);
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO category_materials (category_id, title, description, file_path, file_type, file_size, is_external_link, allow_download, sort_order, is_active, created_by, thumbnail_path)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$categoryId, $title, $description, $filePath, $fileType, $fileSize, $isExternal, $allowDownload, $sortOrder, $isActive, $userId, $thumbnailPath]);
                    }
                    $message = 'Material created successfully.';
                }
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("save_material: " . $e->getMessage());
                $message = 'Unable to save material. Error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }
    
    // Delete material
    if ($action === 'delete_material') {
        $materialId = isset($_POST['material_id']) ? (int)$_POST['material_id'] : 0;
        
        if ($materialId > 0) {
            try {
                // Get file paths to delete
                $stmt = $conn->prepare("SELECT file_path, thumbnail_path FROM category_materials WHERE id = ?");
                $stmt->execute([$materialId]);
                $material = $stmt->fetch();
                
                if ($material && !empty($material['file_path']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $material['file_path'])) {
                    unlink($_SERVER['DOCUMENT_ROOT'] . $material['file_path']);
                }
                if ($material && !empty($material['thumbnail_path']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $material['thumbnail_path'])) {
                    unlink($_SERVER['DOCUMENT_ROOT'] . $material['thumbnail_path']);
                }
                
                $stmt = $conn->prepare("DELETE FROM category_materials WHERE id = ?");
                $stmt->execute([$materialId]);
                $message = 'Material deleted successfully.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Unable to delete material.';
                $messageType = 'danger';
            }
        }
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }
    
    // Toggle material status
    if ($action === 'toggle_material') {
        $materialId = isset($_POST['material_id']) ? (int)$_POST['material_id'] : 0;
        
        if ($materialId > 0) {
            try {
                $conn->prepare("
                    UPDATE category_materials 
                    SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END 
                    WHERE id = ?
                ")->execute([$materialId]);
                $message = 'Material status updated.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Unable to update material status.';
                $messageType = 'danger';
            }
        }
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }
    
    // ========== CATEGORY MANAGEMENT ==========
    
    if ($action === 'save_category') {
        $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $slug = make_slug($slugInput !== '' ? $slugInput : $name);
        
        // Handle category image upload
        $categoryImage = $_POST['existing_image'] ?? null;
        
        if (isset($_FILES['category_image']) && $_FILES['category_image']['error'] === UPLOAD_ERR_OK && $_FILES['category_image']['size'] > 0) {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/myislam/uploads/categories/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['category_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $filename = 'category_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['category_image']['tmp_name'], $uploadDir . $filename)) {
                    // Delete old image if exists
                    if ($categoryId > 0 && !empty($categoryImage) && file_exists($_SERVER['DOCUMENT_ROOT'] . $categoryImage)) {
                        unlink($_SERVER['DOCUMENT_ROOT'] . $categoryImage);
                    }
                    $categoryImage = '/myislam/uploads/categories/' . $filename;
                }
            }
        }

        if ($subjectId <= 0) {
            $message = 'Please select a subject.';
            $messageType = 'danger';
        } elseif ($name === '') {
            $message = 'Category name is required.';
            $messageType = 'danger';
        } else {
            $slug = uniqueCategorySlug($conn, $slug, $categoryId > 0 ? $categoryId : null);

            try {
                $wasActive = false;
                if ($categoryId > 0) {
                    $prevStmt = $conn->prepare("SELECT is_active FROM categories WHERE id = ?");
                    $prevStmt->execute([$categoryId]);
                    $wasActive = (int)$prevStmt->fetchColumn() === 1;

                   $lockPrevious = isset($_POST['lock_previous']) ? 1 : 0;

                    $stmt = $conn->prepare("
                        UPDATE categories
                        SET subject_id = ?, name = ?, slug = ?, description = ?, sort_order = ?, is_active = ?, image_path = ?, lock_previous = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$subjectId, $name, $slug, $description, $sortOrder, $isActive, $categoryImage, $lockPrevious, $categoryId]);
                                        
                    if ($isActive && !$wasActive) {
                        $subjStmt = $conn->prepare("SELECT name FROM subjects WHERE id = ?");
                        $subjStmt->execute([$subjectId]);
                        $subjectName = $subjStmt->fetchColumn() ?: 'General';
                        
                        $notifyTitle = "Category Activated: " . $name;
                        $notifyMessage = "The category '{$name}' under subject '{$subjectName}' is now active.";
                        $notifyLink = "/categories.php?subject=" . urlencode($subjectName);
                        createNotificationForAll($conn, 'category', $notifyTitle, $notifyMessage, $notifyLink, ['admin', 'owner', 'sheikh']);
                    }
                    
                    $message = 'Category updated successfully.';
                } else {
                  $lockPrevious = isset($_POST['lock_previous']) ? 1 : 0;

                        $stmt = $conn->prepare("
                            INSERT INTO categories (subject_id, name, slug, description, sort_order, is_active, image_path, lock_previous)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$subjectId, $name, $slug, $description, $sortOrder, $isActive, $categoryImage, $lockPrevious]);
                                            
                    if ($isActive) {
                        $subjStmt = $conn->prepare("SELECT name FROM subjects WHERE id = ?");
                        $subjStmt->execute([$subjectId]);
                        $subjectName = $subjStmt->fetchColumn() ?: 'General';
                        
                        $notifyTitle = "New Category: " . $name;
                        $notifyMessage = "A new category '{$name}' has been added under subject '{$subjectName}'.";
                        $notifyLink = "/categories.php?subject=" . urlencode($subjectName);
                        createNotificationForAll($conn, 'category', $notifyTitle, $notifyMessage, $notifyLink, ['admin', 'owner', 'sheikh']);
                    }
                    
                    $message = 'Category created successfully.';
                }
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("save_category: " . $e->getMessage());
                $message = 'Unable to save category.';
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }

    if ($action === 'toggle_category') {
        $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

        if ($categoryId > 0) {
            try {
                $stmt = $conn->prepare("SELECT is_active, name, subject_id FROM categories WHERE id = ?");
                $stmt->execute([$categoryId]);
                $current = $stmt->fetch();
                $wasActive = ($current['is_active'] ?? 0) == 1;
                $categoryName = $current['name'] ?? '';
                $subjectId = $current['subject_id'] ?? 0;
                
                $stmt = $conn->prepare("
                    UPDATE categories
                    SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                    WHERE id = ?
                ");
                $stmt->execute([$categoryId]);
                
                if (!$wasActive) {
                    $subjStmt = $conn->prepare("SELECT name FROM subjects WHERE id = ?");
                    $subjStmt->execute([$subjectId]);
                    $subjectName = $subjStmt->fetchColumn() ?: 'General';
                    
                    $notifyTitle = "Category Activated: " . $categoryName;
                    $notifyMessage = "The category '{$categoryName}' under subject '{$subjectName}' is now active.";
                    $notifyLink = "/categories.php?subject=" . urlencode($subjectName);
                    createNotificationForAll($conn, 'category', $notifyTitle, $notifyMessage, $notifyLink, ['admin', 'owner', 'sheikh']);
                }
                
                $message = 'Category status updated.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("toggle_category: " . $e->getMessage());
                $message = 'Unable to update category status.';
                $messageType = 'danger';
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
    }

    if ($action === 'delete_category') {
        $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
        if ($categoryId > 0) {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM contents WHERE category_id = ?");
                $stmt->execute([$categoryId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $message = 'Cannot delete category with linked contents.';
                    $messageType = 'danger';
                } else {
                    // Get image path to delete
                    $stmt = $conn->prepare("SELECT image_path FROM categories WHERE id = ?");
                    $stmt->execute([$categoryId]);
                    $category = $stmt->fetch();
                    if ($category && !empty($category['image_path']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $category['image_path'])) {
                        unlink($_SERVER['DOCUMENT_ROOT'] . $category['image_path']);
                    }
                    
                    // Also delete materials and their files
                    $stmt = $conn->prepare("SELECT file_path, thumbnail_path FROM category_materials WHERE category_id = ?");
                    $stmt->execute([$categoryId]);
                    $materials = $stmt->fetchAll();
                    foreach ($materials as $material) {
                        if (!empty($material['file_path']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $material['file_path'])) {
                            unlink($_SERVER['DOCUMENT_ROOT'] . $material['file_path']);
                        }
                        if (!empty($material['thumbnail_path']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $material['thumbnail_path'])) {
                            unlink($_SERVER['DOCUMENT_ROOT'] . $material['thumbnail_path']);
                        }
                    }
                    
                    $stmt = $conn->prepare("DELETE FROM category_materials WHERE category_id = ?");
                    $stmt->execute([$categoryId]);
                    
                    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                    $stmt->execute([$categoryId]);
                    $message = 'Category deleted.';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Unable to delete category.';
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
$categories = getCategories($conn);

$totalCategories = count($categories);
$activeCategories = count(array_filter($categories, fn($c) => (int)($c['is_active'] ?? 0) === 1));
$linkedContents = array_sum(array_map(fn($c) => (int)($c['total_contents'] ?? 0), $categories));
$subjectsWithCategories = count(array_unique(array_map(fn($c) => (int)($c['subject_id'] ?? 0), $categories)));

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => true],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => true, 'icon' => '🗂️'],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => false, 'icon' => '📘'],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false, 'icon' => '👥'],
    ['name' => 'Announcements', 'link' => 'admin_announcements.php', 'active' => false, 'icon' => '📢'],
    ['name' => 'Settings', 'link' => 'admin_settings.php', 'active' => false, 'icon' => '⚙️'],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false, 'icon' => '🚪'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Categories - MyIslam</title>
<style>
/* ========== EXISTING CSS (keep all original styles) ========== */
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
.main-content{padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px);max-width:1400px;margin:0 auto}
.page-stack{display:grid;gap:24px}
.section-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px;transition:.2s}
.section-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none;display:flex;justify-content:space-between;align-items:center}
.hero-card h1{font-size:32px;margin-bottom:8px;font-weight:700}
.hero-card p{color:var(--text-secondary);font-size:16px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
.stat-card{text-align:center;padding:20px}
.stat-value{font-size:32px;font-weight:800;color:var(--brand);margin-bottom:8px}
.stat-label{font-size:13px;color:var(--muted)}
.filters-row{display:grid;grid-template-columns:1fr 220px;gap:12px;margin-bottom:16px}
.search-wrap{position:relative}
.search-input{width:100%;height:48px;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:0 16px 0 46px;outline:none}
.search-input:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:18px}
.categories-list{display:grid;gap:12px}
.category-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px}
.category-left{display:flex;align-items:flex-start;gap:12px;flex:1}
.category-icon{width:46px;height:46px;border-radius:14px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.category-name{font-size:16px;font-weight:800;margin-bottom:4px}
.category-meta{font-size:12px;color:var(--muted);line-height:1.6}
.category-actions{display:flex;gap:10px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;background:var(--brand-soft);color:var(--brand)}
.badge.danger{background:rgba(239,68,68,.12);color:var(--danger)}
.badge.neutral{background:var(--surface-3);color:var(--muted)}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.btn{padding:10px 16px;border-radius:14px;font-weight:600;border:none;cursor:pointer;transition:.2s;font-size:13px;display:inline-flex;align-items:center;gap:6px}
.btn.primary{background:var(--brand);color:#fff}
.btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
.btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
.btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.btn:hover{transform:translateY(-2px)}

/* Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center}
.modal{background:var(--surface);border-radius:var(--radius-lg);max-width:500px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid var(--line)}
.modal-large .modal { max-width: 700px; }
.modal-header{padding:24px 24px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.modal-header h2{font-size:20px;font-weight:700}
.modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted)}
.modal-body{padding:24px}
.form-fields{display:grid;gap:14px}
.form-row{display:grid;gap:8px}
.form-row label{font-size:14px;font-weight:700}
.form-input,.form-textarea,.form-select{width:100%;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:12px 14px;outline:none}
.form-input:focus,.form-textarea:focus,.form-select:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.form-textarea{min-height:80px;resize:vertical}
.check-row{display:flex;align-items:center;gap:10px;padding-top:6px}
.check-row input{width:18px;height:18px}
.action-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
.image-preview-container{display:none;margin-bottom:8px}
.image-preview{max-width:200px;max-height:120px;border-radius:8px;border:1px solid var(--line)}

/* Materials list in category row */
.category-materials-list {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--line);
}
.materials-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.materials-badge {
    font-size: 12px;
    background: var(--brand-soft);
    padding: 4px 10px;
    border-radius: 20px;
    color: var(--brand);
}
.materials-grid-small {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}
.material-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 20px;
    font-size: 11px;
}
.material-tag-pdf { border-left: 3px solid #dc2626; }
.material-tag-word { border-left: 3px solid #2563eb; }
.material-tag-excel { border-left: 3px solid #16a34a; }
.material-tag-powerpoint { border-left: 3px solid #ea580c; }
.material-tag-image { border-left: 3px solid #9333ea; }
.material-tag-other { border-left: 3px solid var(--muted); }

/* Toast */
.toast-container{position:fixed;top:80px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px}
.toast{padding:16px 20px;border-radius:var(--radius-md);background:var(--success);color:#fff;box-shadow:var(--shadow-lg);font-weight:500;display:flex;align-items:center;gap:10px;animation:slideIn 0.3s ease;max-width:400px}
.toast.success{background:#10b981}
.toast.danger{background:#ef4444}
.toast .close-toast{margin-left:auto;cursor:pointer;font-size:20px;opacity:0.8}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}

@media (max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:900px){.header-top-nav{display:none}.filters-row{grid-template-columns:1fr}}
@media (max-width:768px){
:root{--header-height:64px;--footer-height:80px}
.main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}
.section-card{padding:16px}.hero-card h1{font-size:24px}.stats-grid,.footer-grid{grid-template-columns:1fr;gap:12px}.category-item{flex-direction:column}.footer-action{min-height:64px}
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
                <div>
                    <h1>🗂️ Manage Categories</h1>
                    <p>Create, edit, filter, and activate or deactivate categories under each subject. Also manage books and documents for each category.</p>
                </div>
                <button class="btn primary" id="createCategoryBtn">➕ Create Category</button>
            </section>

            <div class="stats-grid">
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $totalCategories; ?></div>
                    <div class="stat-label">Total Categories</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $activeCategories; ?></div>
                    <div class="stat-label">Active Categories</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $subjectsWithCategories; ?></div>
                    <div class="stat-label">Subjects In Use</div>
                </div>
                <div class="section-card stat-card">
                    <div class="stat-value"><?php echo $linkedContents; ?></div>
                    <div class="stat-label">Linked Contents</div>
                </div>
            </div>

            <section class="section-card">
                <div class="filters-row">
                    <div class="search-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="categorySearchInput" class="search-input" placeholder="Search by name, subject, slug, description...">
                    </div>
                    <select id="subjectFilter" class="form-select">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo strtolower(e($subject['name'] ?? '')); ?>">
                                <?php echo e($subject['name'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!empty($categories)): ?>
                    <div class="categories-list" id="categoriesList">
                        <?php foreach ($categories as $category): ?>
                            <?php
                            $searchBlob = strtolower(
                                ($category['name'] ?? '') . ' ' .
                                ($category['slug'] ?? '') . ' ' .
                                ($category['description'] ?? '') . ' ' .
                                ($category['subject_name'] ?? '')
                            );
                            $subjectFilterValue = strtolower((string)($category['subject_name'] ?? ''));
                            $categoryImage = !empty($category['image_path']) ? $category['image_path'] : null;
                            ?>
                            <div class="category-item category-search-item"
                                 data-search="<?php echo e($searchBlob); ?>"
                                 data-subject="<?php echo e($subjectFilterValue); ?>"
                                 data-category-id="<?php echo (int)$category['id']; ?>">
                                <div class="category-left">
                                    <div class="category-icon"><?php echo e($category['subject_icon'] ?? '📚'); ?></div>
                                    <div style="flex:1">
                                        <div class="category-name"><?php echo e($category['name'] ?? 'Untitled'); ?></div>
                                        <div class="category-meta">
                                            Subject: <?php echo e($category['subject_name'] ?? 'Unknown'); ?><br>
                                            Slug: <?php echo e($category['slug'] ?? '--'); ?><br>
                                            Contents: <?php echo (int)($category['total_contents'] ?? 0); ?> |
                                            Materials: <?php echo (int)($category['total_materials'] ?? 0); ?> |
                                            Sort Order: <?php echo (int)($category['sort_order'] ?? 0); ?>
                                            <?php if ($categoryImage): ?>
                                                <br>📷 Has Background Image
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Materials List -->
                                        <div class="category-materials-list" id="materials-<?php echo (int)$category['id']; ?>">
                                            <div class="materials-header">
                                                <span class="materials-badge">📚 Materials (<?php echo (int)($category['total_materials'] ?? 0); ?>)</span>
                                                <button class="btn secondary add-material-btn" style="padding:4px 12px; font-size:11px;" data-category-id="<?php echo (int)$category['id']; ?>" data-category-name="<?php echo e($category['name']); ?>">
                                                    + Add Material
                                                </button>
                                            </div>
                                            <div class="materials-grid-small" id="materials-container-<?php echo (int)$category['id']; ?>">
                                                <span style="font-size:12px; color:var(--muted);">Loading materials...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="category-actions">
                                    <span class="badge neutral"><?php echo e($category['subject_name'] ?? 'Unknown'); ?></span>
                                    <span class="badge <?php echo (int)($category['is_active'] ?? 0) === 1 ? '' : 'danger'; ?>">
                                        <?php echo (int)($category['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <button class="btn secondary edit-category-btn" data-category-id="<?php echo (int)$category['id']; ?>">✏️ Edit</button>
                                    <button class="btn warn toggle-category-btn" data-category-id="<?php echo (int)$category['id']; ?>">
                                        <?php echo (int)($category['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                    <?php if ((int)($category['total_contents'] ?? 0) === 0 && (int)($category['total_materials'] ?? 0) === 0): ?>
                                    <button class="btn danger delete-category-btn" data-category-id="<?php echo (int)$category['id']; ?>">🗑️ Delete</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="emptySearchState" class="empty-state" style="display:none;">No matching categories found.</div>
                <?php else: ?>
                    <div class="empty-state">No categories found. Click "Create Category" to add one.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<!-- Category Modal -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Create New Category</h2>
            <button class="modal-close" id="closeModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="categoryForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="category_id" id="category_id" value="0">
                <input type="hidden" name="existing_image" id="existing_image" value="">

                <div class="form-row">
                    <label for="subject_id">Subject *</label>
                    <select id="subject_id" name="subject_id" class="form-select" required>
                        <option value="">Select subject</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo (int)$subject['id']; ?>">
                                <?php echo e(($subject['icon'] ?? '📚') . ' ' . ($subject['name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="name">Category Name *</label>
                    <input type="text" id="name" name="name" class="form-input" required placeholder="e.g. Purification">
                </div>

                <div class="form-row">
                    <label for="slug">Slug</label>
                    <input type="text" id="slug" name="slug" class="form-input" placeholder="auto-generated from name">
                </div>

                <div class="form-row">
                    <label for="sort_order">Sort Order</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-input" value="0" min="0">
                </div>
                <div class="form-row">
    <label class="check-row">
        <input type="checkbox" name="lock_previous" id="lock_previous" value="1">
        <span>🔒 Lock until previous category completed</span>
    </label>
    <small style="color:var(--muted); margin-top:4px; display:block;">
        When checked, users must complete ALL lessons in the previous category (by sort order) before accessing this category.
    </small>
</div>

                <div class="form-row">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-textarea" placeholder="Brief description"></textarea>
                </div>

                <div class="form-row">
                    <label>Category Background Image</label>
                    <div id="catImagePreviewContainer" class="image-preview-container">
                        <img id="catImagePreview" class="image-preview" src="" alt="Preview">
                    </div>
                    <input type="file" id="category_image" name="category_image" accept="image/*" style="padding:8px;" onchange="previewCategoryImage(this)">
                    <small style="color:var(--muted);">Recommended: 800x400px. This will appear as background in category cards on the frontend.</small>
                </div>

                <label class="check-row">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    <span>Active Category</span>
                </label>

                <div class="action-row">
                    <button type="submit" class="btn primary">Save Category</button>
                    <button type="button" class="btn secondary" id="cancelModalBtn">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Material Modal -->
<div class="modal-overlay" id="materialModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h2 id="materialModalTitle">Add Material</h2>
            <button class="modal-close" id="closeMaterialModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="materialForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_material">
                <input type="hidden" name="material_id" id="material_id" value="0">
                <input type="hidden" name="category_id" id="material_category_id" value="0">
                <input type="hidden" name="existing_thumbnail" id="existing_thumbnail" value="">

                <div class="form-row">
                    <label for="material_title">Title *</label>
                    <input type="text" id="material_title" name="title" class="form-input" required placeholder="e.g. Understanding Tawheed">
                </div>

                <div class="form-row">
                    <label for="material_description">Description</label>
                    <textarea id="material_description" name="description" class="form-textarea" placeholder="Brief description of this material"></textarea>
                </div>

                <div class="form-row">
                    <label class="check-row">
                        <input type="checkbox" name="is_external_link" id="is_external_link" value="1">
                        <span>External Link (URL)</span>
                    </label>
                </div>

                <div class="form-row" id="external_url_row" style="display:none;">
                    <label for="external_url">External URL</label>
                    <input type="url" id="external_url" name="external_url" class="form-input" placeholder="https://example.com/document.pdf">
                </div>

                <div class="form-row" id="file_upload_row">
                    <label for="material_file">File (PDF, Word, Excel, PPT, Image)</label>
                    <input type="file" id="material_file" name="material_file" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp">
                    <small style="color:var(--muted);">Supported formats: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, PNG, GIF</small>
                </div>

                <div class="form-row">
                    <label>Material Thumbnail/Background Image</label>
                    <div id="materialThumbPreviewContainer" class="image-preview-container">
                        <img id="materialThumbPreview" class="image-preview" src="" alt="Preview">
                    </div>
                    <input type="file" id="material_thumbnail" name="material_thumbnail" accept="image/*" style="padding:8px;" onchange="previewMaterialThumb(this)">
                    <small style="color:var(--muted);">Recommended: 300x200px. This will appear as background in material cards on the frontend.</small>
                </div>

                <div class="inline-row" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-row">
                        <label for="material_sort_order">Sort Order</label>
                        <input type="number" id="material_sort_order" name="sort_order" class="form-input" value="0" min="0">
                    </div>
                    <div class="form-row">
                        <label class="check-row" style="margin-top:24px;">
                            <input type="checkbox" name="allow_download" id="allow_download" checked>
                            <span>Allow Download</span>
                        </label>
                    </div>
                </div>

                <label class="check-row">
                    <input type="checkbox" name="is_active" id="material_is_active" checked>
                    <span>Active</span>
                </label>

                <div class="action-row">
                    <button type="submit" class="btn primary">Save Material</button>
                    <button type="button" class="btn secondary" id="cancelMaterialModalBtn">Cancel</button>
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

// Category Image Preview
function previewCategoryImage(input) {
    const preview = document.getElementById('catImagePreview');
    const container = document.getElementById('catImagePreviewContainer');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            container.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Material Thumbnail Preview
function previewMaterialThumb(input) {
    const preview = document.getElementById('materialThumbPreview');
    const container = document.getElementById('materialThumbPreviewContainer');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            container.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Load materials for a category
async function loadMaterials(categoryId) {
    const container = document.getElementById(`materials-container-${categoryId}`);
    if (!container) return;
    
    try {
        const response = await fetch(`admin_categories.php?ajax=1&get_materials=${categoryId}`);
        const materials = await response.json();
        
        if (materials.length === 0) {
            container.innerHTML = '<span style="font-size:12px; color:var(--muted);">No materials yet. Click "Add Material" to add books or documents.</span>';
            return;
        }
        
        let html = '';
        materials.forEach(m => {
            let icon = '';
            let typeClass = '';
            switch(m.file_type) {
                case 'pdf': icon = '📄'; typeClass = 'material-tag-pdf'; break;
                case 'word': icon = '📝'; typeClass = 'material-tag-word'; break;
                case 'excel': icon = '📊'; typeClass = 'material-tag-excel'; break;
                case 'powerpoint': icon = '📽️'; typeClass = 'material-tag-powerpoint'; break;
                case 'image': icon = '🖼️'; typeClass = 'material-tag-image'; break;
                default: icon = '📁'; typeClass = 'material-tag-other';
            }
            html += `
                <div class="material-tag ${typeClass}" data-material-id="${m.id}">
                    ${icon} ${escapeHtml(m.title.substring(0, 30))}${m.title.length > 30 ? '...' : ''}
                    <button class="edit-material-btn" data-material-id="${m.id}" style="background:none; border:none; cursor:pointer; margin-left:6px;" title="Edit">✏️</button>
                    <button class="delete-material-btn" data-material-id="${m.id}" style="background:none; border:none; cursor:pointer; color:var(--danger);" title="Delete">🗑️</button>
                </div>
            `;
        });
        container.innerHTML = html;
        
        // Attach event handlers to new buttons
        document.querySelectorAll('.edit-material-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const materialId = btn.dataset.materialId;
                await editMaterial(materialId);
            });
        });
        
        document.querySelectorAll('.delete-material-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                if (!confirm('Delete this material?')) return;
                const materialId = btn.dataset.materialId;
                await deleteMaterial(materialId);
            });
        });
        
    } catch (err) {
        console.error('Error loading materials:', err);
        container.innerHTML = '<span style="font-size:12px; color:var(--danger);">Error loading materials</span>';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Material CRUD operations
async function editMaterial(materialId) {
    try {
        const response = await fetch(`admin_categories.php?ajax=1&get_material=${materialId}`);
        const material = await response.json();
        
        if (material.error) {
            showToast(material.error, 'danger');
            return;
        }
        
        document.getElementById('materialModalTitle').textContent = 'Edit Material';
        document.getElementById('material_id').value = material.id;
        document.getElementById('material_category_id').value = material.category_id;
        document.getElementById('material_title').value = material.title;
        document.getElementById('material_description').value = material.description || '';
        document.getElementById('material_sort_order').value = material.sort_order || 0;
        document.getElementById('allow_download').checked = material.allow_download == 1;
        document.getElementById('material_is_active').checked = material.is_active == 1;
        
        // Load existing thumbnail
        document.getElementById('existing_thumbnail').value = material.thumbnail_path || '';
        const thumbPreview = document.getElementById('materialThumbPreview');
        const thumbContainer = document.getElementById('materialThumbPreviewContainer');
        if (material.thumbnail_path) {
            thumbPreview.src = material.thumbnail_path;
            thumbContainer.style.display = 'block';
        } else {
            thumbContainer.style.display = 'none';
        }
        
        if (material.is_external_link == 1) {
            document.getElementById('is_external_link').checked = true;
            document.getElementById('external_url_row').style.display = 'block';
            document.getElementById('file_upload_row').style.display = 'none';
            document.getElementById('external_url').value = material.external_url || '';
        } else {
            document.getElementById('is_external_link').checked = false;
            document.getElementById('external_url_row').style.display = 'none';
            document.getElementById('file_upload_row').style.display = 'block';
        }
        
        document.getElementById('materialModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
    } catch (err) {
        showToast('Failed to load material', 'danger');
    }
}

async function deleteMaterial(materialId) {
    const formData = new FormData();
    formData.append('action', 'delete_material');
    formData.append('material_id', materialId);
    
    try {
        const response = await fetch('admin_categories.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        showToast(result.message, result.success ? 'success' : 'danger');
        if (result.success) {
            setTimeout(() => location.reload(), 1000);
        }
    } catch (err) {
        showToast('Error deleting material', 'danger');
    }
}

// Load materials for all categories
document.querySelectorAll('.category-search-item').forEach(item => {
    const categoryId = item.dataset.categoryId;
    if (categoryId) {
        loadMaterials(categoryId);
    }
});

// Add material button handlers
document.querySelectorAll('.add-material-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const categoryId = btn.dataset.categoryId;
        const categoryName = btn.dataset.categoryName;
        
        document.getElementById('materialModalTitle').textContent = `Add Material to ${categoryName}`;
        document.getElementById('materialForm').reset();
        document.getElementById('material_id').value = '0';
        document.getElementById('material_category_id').value = categoryId;
        document.getElementById('is_external_link').checked = false;
        document.getElementById('external_url_row').style.display = 'none';
        document.getElementById('file_upload_row').style.display = 'block';
        document.getElementById('allow_download').checked = true;
        document.getElementById('material_is_active').checked = true;
        
        // Reset thumbnail preview
        document.getElementById('existing_thumbnail').value = '';
        document.getElementById('materialThumbPreviewContainer').style.display = 'none';
        document.getElementById('materialThumbPreview').src = '';
        
        document.getElementById('materialModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    });
});

// External link toggle
document.getElementById('is_external_link')?.addEventListener('change', function() {
    if (this.checked) {
        document.getElementById('external_url_row').style.display = 'block';
        document.getElementById('file_upload_row').style.display = 'none';
    } else {
        document.getElementById('external_url_row').style.display = 'none';
        document.getElementById('file_upload_row').style.display = 'block';
    }
});

// Material form submit
document.getElementById('materialForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(document.getElementById('materialForm'));
    
    try {
        const response = await fetch('admin_categories.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        showToast(result.message, result.success ? 'success' : 'danger');
        if (result.success) {
            closeMaterialModal();
            setTimeout(() => location.reload(), 1000);
        }
    } catch (err) {
        showToast('Error saving material', 'danger');
    }
});

// Category Modal functions
const categoryModal = document.getElementById('categoryModal');
const createBtn = document.getElementById('createCategoryBtn');
const closeModalBtn = document.getElementById('closeModalBtn');
const cancelModalBtn = document.getElementById('cancelModalBtn');
const categoryForm = document.getElementById('categoryForm');
const modalTitle = document.getElementById('modalTitle');

function openCategoryModal(data = null) {
    if (data) {
        modalTitle.textContent = 'Edit Category';
        document.getElementById('category_id').value = data.id || 0;
        document.getElementById('subject_id').value = data.subject_id || '';
        document.getElementById('name').value = data.name || '';
        document.getElementById('slug').value = data.slug || '';
        document.getElementById('sort_order').value = data.sort_order || 0;
        document.getElementById('description').value = data.description || '';
        document.getElementById('is_active').checked = data.is_active == 1;
        document.getElementById('lock_previous').checked = data.lock_previous == 1;
        // Handle image
        document.getElementById('existing_image').value = data.image_path || '';
        const preview = document.getElementById('catImagePreview');
        const previewContainer = document.getElementById('catImagePreviewContainer');
        if (data.image_path) {
            preview.src = data.image_path;
            previewContainer.style.display = 'block';
        } else {
            previewContainer.style.display = 'none';
        }
    } else {
        modalTitle.textContent = 'Create New Category';
        categoryForm.reset();
        document.getElementById('category_id').value = '0';
        document.getElementById('is_active').checked = true;
        document.getElementById('existing_image').value = '';
        document.getElementById('catImagePreviewContainer').style.display = 'none';
    }
    categoryModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeCategoryModal() {
    categoryModal.style.display = 'none';
    document.body.style.overflow = '';
}

createBtn.addEventListener('click', () => openCategoryModal());
closeModalBtn.addEventListener('click', closeCategoryModal);
cancelModalBtn.addEventListener('click', closeCategoryModal);
categoryModal.addEventListener('click', (e) => { if (e.target === categoryModal) closeCategoryModal(); });

// Material Modal functions
function closeMaterialModal() {
    document.getElementById('materialModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('closeMaterialModalBtn')?.addEventListener('click', closeMaterialModal);
document.getElementById('cancelMaterialModalBtn')?.addEventListener('click', closeMaterialModal);
document.getElementById('materialModal')?.addEventListener('click', (e) => { if (e.target === document.getElementById('materialModal')) closeMaterialModal(); });

// Auto slug
document.getElementById('name')?.addEventListener('input', function() {
    const slugInput = document.getElementById('slug');
    if (!slugInput.value || slugInput.value === makeSlug(slugInput.dataset.old || '')) {
        slugInput.value = makeSlug(this.value);
        slugInput.dataset.old = this.value;
    }
});
function makeSlug(t) { return t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }

// Category form submit AJAX
categoryForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(categoryForm);
    try {
        const response = await fetch('admin_categories.php', {
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const result = await response.json();
        showToast(result.message, result.success ? 'success' : 'danger');
        if (result.success) {
            closeCategoryModal();
            setTimeout(() => location.reload(), 1000);
        }
    } catch (err) {
        showToast('An error occurred', 'danger');
    }
});

// Edit category buttons
document.querySelectorAll('.edit-category-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.categoryId;
        try {
            const response = await fetch(`admin_categories.php?ajax=1&edit=${id}`);
            if (!response.ok) throw new Error('Failed to load');
            const data = await response.json();
            if (data.error) {
                showToast(data.error, 'danger');
            } else {
                openCategoryModal(data);
            }
        } catch (err) {
            showToast('Failed to load category', 'danger');
        }
    });
});

// Toggle category status
document.querySelectorAll('.toggle-category-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.categoryId;
        const formData = new FormData();
        formData.append('action', 'toggle_category');
        formData.append('category_id', id);
        try {
            const response = await fetch('admin_categories.php', {
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

// Delete category
document.querySelectorAll('.delete-category-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!confirm('Delete this category? All materials will also be deleted!')) return;
        const id = btn.dataset.categoryId;
        const formData = new FormData();
        formData.append('action', 'delete_category');
        formData.append('category_id', id);
        try {
            const response = await fetch('admin_categories.php', {
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

// Filters
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
    const categorySearchInput = document.getElementById('categorySearchInput');
    const subjectFilter = document.getElementById('subjectFilter');
    const categoryItems = document.querySelectorAll('.category-search-item');
    const emptySearchState = document.getElementById('emptySearchState');

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

    function applyFilters() {
        const query = (categorySearchInput?.value || '').trim().toLowerCase();
        const subjectValue = (subjectFilter?.value || '').trim().toLowerCase();
        let visible = 0;
        categoryItems.forEach(item => {
            const haystack = item.dataset.search || '';
            const itemSubject = item.dataset.subject || '';
            const show = (!query || haystack.includes(query)) && (!subjectValue || itemSubject === subjectValue);
            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (emptySearchState) emptySearchState.style.display = visible === 0 ? 'block' : 'none';
    }

    categorySearchInput?.addEventListener('input', applyFilters);
    subjectFilter?.addEventListener('change', applyFilters);
})();
</script>
</body>
</html>