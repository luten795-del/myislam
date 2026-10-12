<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/admin_functions.php';

header('Content-Type: application/json');

$subjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if ($subjectId > 0) {
    $conn = db();
    $categories = getCategoriesBySubject($conn, $subjectId);
    echo json_encode($categories);
} else {
    echo json_encode([]);
}
?>