<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $lang = $input['language'] ?? 'en';
    
    if (in_array($lang, ['en', 'sw'])) {
        $_SESSION['language'] = $lang;
        setcookie('language', $lang, time() + (86400 * 365), '/');
        echo json_encode(['success' => true, 'language' => $lang]);
        exit;
    }
}

echo json_encode(['success' => false]);