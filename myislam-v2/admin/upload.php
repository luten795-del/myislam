<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }

header('Content-Type: application/json');

$file = $_FILES['image'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
if (!in_array($ext, $allowed)) {
    echo json_encode(['error' => 'Invalid file type']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/exams/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$filename = uniqid('exam_', true) . '.' . $ext;
$destination = $uploadDir . $filename;
if (move_uploaded_file($file['tmp_name'], $destination)) {
    echo json_encode(['path' => 'uploads/exams/' . $filename]);
} else {
    echo json_encode(['error' => 'Upload failed']);
}