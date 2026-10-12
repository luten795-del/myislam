<?php
declare(strict_types=1);

/**
 * Admin functions for content management
 */

require_once __DIR__ . '/../../config/database.php';

/**
 * Check if user is admin
 */
function isAdmin(): bool {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['owner', 'admin', 'sheikh'], true);
}

/**
 * Require admin access
 */
function requireAdmin(): void {
    if (!isAdmin()) {
        header('Location: ../login.php?error=unauthorized');
        exit;
    }
}

/**
 * Create slug from string
 */
function createSlug(string $string): string {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    $string = trim($string, '-');

    return $string !== '' ? $string : 'item-' . time();
}

/**
 * Create unique content slug
 */
function createUniqueContentSlug(PDO $conn, string $title, ?int $excludeId = null): string {
    $baseSlug = createSlug($title);
    $slug = $baseSlug;
    $counter = 1;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM contents WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM contents WHERE slug = ?");
            $stmt->execute([$slug]);
        }

        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

// ============================================================================
// SUBJECT FUNCTIONS
// ============================================================================

function getAllSubjects(PDO $conn): array {
    try {
        $stmt = $conn->prepare("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM categories WHERE subject_id = s.id) AS category_count,
                   (SELECT COUNT(*) FROM contents WHERE subject_id = s.id) AS content_count
            FROM subjects s 
            ORDER BY s.sort_order ASC, s.name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getAllSubjects error: " . $e->getMessage());
        return [];
    }
}

function getSubject(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("SELECT * FROM subjects WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("getSubject error: " . $e->getMessage());
        return null;
    }
}

function createSubject(PDO $conn, array $data): array {
    try {
        $slug = createSlug($data['name']);

        $stmt = $conn->prepare("
            INSERT INTO subjects (name, slug, description, icon, sort_order, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            trim((string)$data['name']),
            $slug,
            $data['description'] ?? null,
            $data['icon'] ?? null,
            (int)($data['sort_order'] ?? 0),
            (int)($data['is_active'] ?? 1),
        ]);

        return [
            'success' => true,
            'id' => (int)$conn->lastInsertId(),
            'message' => 'Subject created successfully'
        ];
    } catch (PDOException $e) {
        error_log("createSubject error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateSubject(PDO $conn, int $id, array $data): array {
    try {
        $slug = createSlug($data['name']);

        $stmt = $conn->prepare("
            UPDATE subjects
            SET name = ?, slug = ?, description = ?, icon = ?, sort_order = ?, is_active = ?
            WHERE id = ?
        ");

        $stmt->execute([
            trim((string)$data['name']),
            $slug,
            $data['description'] ?? null,
            $data['icon'] ?? null,
            (int)($data['sort_order'] ?? 0),
            (int)($data['is_active'] ?? 1),
            $id
        ]);

        return ['success' => true, 'message' => 'Subject updated successfully'];
    } catch (PDOException $e) {
        error_log("updateSubject error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteSubject(PDO $conn, int $id): array {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM contents WHERE subject_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && (int)$result['count'] > 0) {
            return ['success' => false, 'message' => 'Cannot delete: Subject has ' . $result['count'] . ' content items'];
        }

        $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->execute([$id]);

        return ['success' => true, 'message' => 'Subject deleted successfully'];
    } catch (PDOException $e) {
        error_log("deleteSubject error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// ============================================================================
// CATEGORY FUNCTIONS
// ============================================================================

function getCategory(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("getCategory error: " . $e->getMessage());
        return null;
    }
}

function getCategoriesBySubject(PDO $conn, int $subjectId): array {
    try {
        $stmt = $conn->prepare("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM contents WHERE category_id = c.id) AS content_count
            FROM categories c
            WHERE c.subject_id = ?
            ORDER BY c.sort_order ASC, c.name ASC
        ");
        $stmt->execute([$subjectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getCategoriesBySubject error: " . $e->getMessage());
        return [];
    }
}

function getAllCategories(PDO $conn, ?int $subjectId = null): array {
    try {
        $sql = "
            SELECT c.*, s.name AS subject_name,
                   (SELECT COUNT(*) FROM contents WHERE category_id = c.id) AS content_count
            FROM categories c
            LEFT JOIN subjects s ON c.subject_id = s.id
            WHERE 1=1
        ";
        $params = [];

        if ($subjectId && $subjectId > 0) {
            $sql .= " AND c.subject_id = ?";
            $params[] = $subjectId;
        }

        $sql .= " ORDER BY s.name ASC, c.sort_order ASC, c.name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getAllCategories error: " . $e->getMessage());
        return [];
    }
}

function createCategory(PDO $conn, array $data): array {
    try {
        $slug = createSlug($data['name']);

        $stmt = $conn->prepare("
            INSERT INTO categories (subject_id, name, slug, description, icon, sort_order, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            (int)$data['subject_id'],
            trim((string)$data['name']),
            $slug,
            $data['description'] ?? null,
            $data['icon'] ?? null,
            (int)($data['sort_order'] ?? 0),
            (int)($data['is_active'] ?? 1),
        ]);

        return [
            'success' => true,
            'id' => (int)$conn->lastInsertId(),
            'message' => 'Category created successfully'
        ];
    } catch (PDOException $e) {
        error_log("createCategory error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateCategory(PDO $conn, int $id, array $data): array {
    try {
        $slug = createSlug($data['name']);

        $stmt = $conn->prepare("
            UPDATE categories
            SET subject_id = ?, name = ?, slug = ?, description = ?, icon = ?, sort_order = ?, is_active = ?
            WHERE id = ?
        ");

        $stmt->execute([
            (int)$data['subject_id'],
            trim((string)$data['name']),
            $slug,
            $data['description'] ?? null,
            $data['icon'] ?? null,
            (int)($data['sort_order'] ?? 0),
            (int)($data['is_active'] ?? 1),
            $id
        ]);

        return ['success' => true, 'message' => 'Category updated successfully'];
    } catch (PDOException $e) {
        error_log("updateCategory error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteCategory(PDO $conn, int $id): array {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM contents WHERE category_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && (int)$result['count'] > 0) {
            return ['success' => false, 'message' => 'Cannot delete: Category has ' . $result['count'] . ' content items'];
        }

        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);

        return ['success' => true, 'message' => 'Category deleted successfully'];
    } catch (PDOException $e) {
        error_log("deleteCategory error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// ============================================================================
// CONTENT FUNCTIONS
// ============================================================================

function getAllContent(PDO $conn, ?int $subjectId = null, ?int $categoryId = null, string $status = 'all'): array {
    try {
        $sql = "
            SELECT c.*, s.name AS subject_name, cat.name AS category_name,
                   u.full_name AS author_name
            FROM contents c
            LEFT JOIN subjects s ON c.subject_id = s.id
            LEFT JOIN categories cat ON c.category_id = cat.id
            LEFT JOIN users u ON c.created_by = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($subjectId) {
            $sql .= " AND c.subject_id = ?";
            $params[] = $subjectId;
        }

        if ($categoryId) {
            $sql .= " AND c.category_id = ?";
            $params[] = $categoryId;
        }

        if ($status !== 'all') {
            $sql .= " AND c.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY c.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getAllContent error: " . $e->getMessage());
        return [];
    }
}

function getContent(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT c.*, s.name AS subject_name, cat.name AS category_name,
                   u.full_name AS author_name, u2.full_name AS approver_name
            FROM contents c
            LEFT JOIN subjects s ON c.subject_id = s.id
            LEFT JOIN categories cat ON c.category_id = cat.id
            LEFT JOIN users u ON c.created_by = u.id
            LEFT JOIN users u2 ON c.approved_by = u2.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("getContent error: " . $e->getMessage());
        return null;
    }
}

function createContent(PDO $conn, array $data, int $userId): array {
    try {
        if (empty($data['subject_id'])) {
            return ['success' => false, 'message' => 'Subject is required'];
        }

        if (empty($data['category_id'])) {
            return ['success' => false, 'message' => 'Category is required'];
        }

        if (empty(trim((string)($data['title'] ?? '')))) {
            return ['success' => false, 'message' => 'Title is required'];
        }

        if (empty(trim((string)($data['body'] ?? '')))) {
            return ['success' => false, 'message' => 'Main content is required'];
        }

        $slug = createUniqueContentSlug($conn, (string)$data['title']);

        $stmt = $conn->prepare("
            INSERT INTO contents (
                subject_id, category_id, title, slug,
                short_description, body, arabic_text, translation,
                reference_text, source_name, tags, status,
                created_by, sort_order, is_featured, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, NOW()
            )
        ");

        $stmt->execute([
            (int)$data['subject_id'],
            (int)$data['category_id'],
            trim((string)$data['title']),
            $slug,
            $data['short_description'] ?? null,
            trim((string)$data['body']),
            $data['arabic_text'] ?? null,
            $data['translation'] ?? null,
            $data['reference_text'] ?? null,
            $data['source_name'] ?? null,
            $data['tags'] ?? null,
            $data['status'] ?? 'draft',
            $userId,
            (int)($data['sort_order'] ?? 0),
            (int)($data['is_featured'] ?? 0)
        ]);

        return [
            'success' => true,
            'id' => (int)$conn->lastInsertId(),
            'message' => 'Content created successfully'
        ];
    } catch (PDOException $e) {
        error_log("createContent error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateContent(PDO $conn, int $id, array $data): array {
    try {
        if (empty($data['subject_id'])) {
            return ['success' => false, 'message' => 'Subject is required'];
        }

        if (empty($data['category_id'])) {
            return ['success' => false, 'message' => 'Category is required'];
        }

        if (empty(trim((string)($data['title'] ?? '')))) {
            return ['success' => false, 'message' => 'Title is required'];
        }

        if (empty(trim((string)($data['body'] ?? '')))) {
            return ['success' => false, 'message' => 'Main content is required'];
        }

        $slug = createUniqueContentSlug($conn, (string)$data['title'], $id);

        $stmt = $conn->prepare("
            UPDATE contents SET
                subject_id = ?, category_id = ?,
                title = ?, slug = ?, short_description = ?, body = ?,
                arabic_text = ?, translation = ?, reference_text = ?,
                source_name = ?, tags = ?, status = ?,
                sort_order = ?, is_featured = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            (int)$data['subject_id'],
            (int)$data['category_id'],
            trim((string)$data['title']),
            $slug,
            $data['short_description'] ?? null,
            trim((string)$data['body']),
            $data['arabic_text'] ?? null,
            $data['translation'] ?? null,
            $data['reference_text'] ?? null,
            $data['source_name'] ?? null,
            $data['tags'] ?? null,
            $data['status'] ?? 'draft',
            (int)($data['sort_order'] ?? 0),
            (int)($data['is_featured'] ?? 0),
            $id
        ]);

        return ['success' => true, 'message' => 'Content updated successfully'];
    } catch (PDOException $e) {
        error_log("updateContent error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function publishContent(PDO $conn, int $id, int $userId): array {
    try {
        $stmt = $conn->prepare("
            UPDATE contents
            SET status = 'published',
                approved_by = ?,
                published_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId, $id]);

        return ['success' => true, 'message' => 'Content published successfully'];
    } catch (PDOException $e) {
        error_log("publishContent error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteContent(PDO $conn, int $id): array {
    try {
        $stmt = $conn->prepare("DELETE FROM contents WHERE id = ?");
        $stmt->execute([$id]);

        return ['success' => true, 'message' => 'Content deleted successfully'];
    } catch (PDOException $e) {
        error_log("deleteContent error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// ============================================================================
// QUIZ FUNCTIONS
// ============================================================================

function createQuiz(PDO $conn, array $data, int $userId): array {
    try {
        $slug = createSlug($data['title']);

        $stmt = $conn->prepare("
            INSERT INTO quizzes (
                title, slug, description, subject_id, category_id,
                difficulty, time_limit_minutes, total_points, max_attempts,
                status, created_by, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");

        $stmt->execute([
            trim((string)$data['title']),
            $slug,
            $data['description'] ?? null,
            $data['subject_id'] ?? null,
            $data['category_id'] ?? null,
            $data['difficulty'],
            $data['time_limit_minutes'] ?? null,
            $data['total_points'] ?? 0,
            $data['max_attempts'] ?? 1,
            $data['status'] ?? 'draft',
            $userId
        ]);

        return [
            'success' => true,
            'id' => (int)$conn->lastInsertId(),
            'message' => 'Quiz created successfully'
        ];
    } catch (PDOException $e) {
        error_log("createQuiz error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateQuiz(PDO $conn, int $id, array $data): array {
    try {
        $slug = createSlug($data['title']);

        $stmt = $conn->prepare("
            UPDATE quizzes
            SET title = ?, slug = ?, description = ?, subject_id = ?, category_id = ?,
                difficulty = ?, time_limit_minutes = ?, total_points = ?, max_attempts = ?,
                status = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            trim((string)$data['title']),
            $slug,
            $data['description'] ?? null,
            $data['subject_id'] ?? null,
            $data['category_id'] ?? null,
            $data['difficulty'],
            $data['time_limit_minutes'] ?? null,
            $data['total_points'] ?? 0,
            $data['max_attempts'] ?? 1,
            $data['status'] ?? 'draft',
            $id
        ]);

        return ['success' => true, 'message' => 'Quiz updated successfully'];
    } catch (PDOException $e) {
        error_log("updateQuiz error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function getQuiz(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT q.*, s.name as subject_name
            FROM quizzes q
            LEFT JOIN subjects s ON q.subject_id = s.id
            WHERE q.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("getQuiz error: " . $e->getMessage());
        return null;
    }
}

function getAllQuizzes(PDO $conn): array {
    try {
        $stmt = $conn->prepare("
            SELECT q.*, s.name as subject_name,
                   (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) as questions_count,
                   (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id) as attempts_count
            FROM quizzes q
            LEFT JOIN subjects s ON q.subject_id = s.id
            ORDER BY q.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getAllQuizzes error: " . $e->getMessage());
        return [];
    }
}

function deleteQuiz(PDO $conn, int $id): array {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM quiz_attempts WHERE quiz_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && (int)$result['count'] > 0) {
            return ['success' => false, 'message' => 'Cannot delete: Quiz has ' . $result['count'] . ' attempts'];
        }

        $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
        $stmt->execute([$id]);

        $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?");
        $stmt->execute([$id]);

        return ['success' => true, 'message' => 'Quiz deleted successfully'];
    } catch (PDOException $e) {
        error_log("deleteQuiz error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function publishQuiz(PDO $conn, int $id, int $userId): array {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM quiz_questions WHERE quiz_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || (int)$result['count'] === 0) {
            return ['success' => false, 'message' => 'Cannot publish: Quiz has no questions'];
        }

        $stmt = $conn->prepare("
            UPDATE quizzes
            SET status = 'published', published_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        return ['success' => true, 'message' => 'Quiz published successfully'];
    } catch (PDOException $e) {
        error_log("publishQuiz error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function getQuizQuestions(PDO $conn, int $quizId): array {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM quiz_questions
            WHERE quiz_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$quizId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getQuizQuestions error: " . $e->getMessage());
        return [];
    }
}

function getQuestionOptions(PDO $conn, int $questionId): array {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM quiz_options
            WHERE question_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$questionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getQuestionOptions error: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// UPLOAD & STATS FUNCTIONS
// ============================================================================

function uploadImage(array $file, string $type = 'content'): array {
    $targetDir = __DIR__ . '/../../uploads/images/';

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes, true)) {
        return ['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP'];
    }

    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File too large. Max size: 5MB'];
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $targetPath = $targetDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [
            'success' => true,
            'path' => 'uploads/images/' . $filename,
            'message' => 'Image uploaded successfully'
        ];
    }

    return ['success' => false, 'message' => 'Failed to upload image'];
}

function getAdminStats(PDO $conn): array {
    try {
        $stats = [];

        $stmt = $conn->query("SELECT COUNT(*) as count FROM subjects WHERE is_active = 1");
        $stats['subjects'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $stmt = $conn->query("
            SELECT status, COUNT(*) as count
            FROM contents
            GROUP BY status
        ");
        $stats['content_by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->query("SELECT COUNT(*) as count FROM contents");
        $stats['total_content'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
        $stats['users'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $stmt = $conn->query("SELECT COUNT(*) as count FROM quizzes");
        $stats['quizzes'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $stmt = $conn->query("SELECT COUNT(*) as count FROM categories");
        $stats['categories'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        return $stats;
    } catch (PDOException $e) {
        error_log("getAdminStats error: " . $e->getMessage());
        return [];
    }
}
?>