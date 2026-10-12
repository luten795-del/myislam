<?php
// includes/material_functions.php

function getCategoryMaterials(PDO $conn, int $categoryId): array {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM category_materials 
            WHERE category_id = ? AND is_active = 1 
            ORDER BY sort_order ASC, created_at DESC
        ");
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getCategoryMaterials: " . $e->getMessage());
        return [];
    }
}

function incrementMaterialView(PDO $conn, int $materialId, int $userId): void {
    try {
        $conn->prepare("UPDATE category_materials SET view_count = view_count + 1 WHERE id = ?")->execute([$materialId]);
        
        // Track user view
        $stmt = $conn->prepare("
            INSERT INTO user_material_progress (user_id, material_id, last_viewed_at, view_count) 
            VALUES (?, ?, NOW(), 1) 
            ON DUPLICATE KEY UPDATE 
            view_count = view_count + 1, 
            last_viewed_at = NOW()
        ");
        $stmt->execute([$userId, $materialId]);
    } catch (Throwable $e) {}
}

function getFileIcon(string $fileType): string {
    $icons = [
        'pdf' => '📄',
        'word' => '📝',
        'excel' => '📊',
        'powerpoint' => '📽️',
        'image' => '🖼️',
        'other' => '📁'
    ];
    return $icons[$fileType] ?? '📁';
}

function getFileBadgeClass(string $fileType): string {
    $classes = [
        'pdf' => 'badge-pdf',
        'word' => 'badge-word',
        'excel' => 'badge-excel',
        'powerpoint' => 'badge-ppt',
        'image' => 'badge-image',
        'other' => 'badge-other'
    ];
    return $classes[$fileType] ?? 'badge-other';
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
?>