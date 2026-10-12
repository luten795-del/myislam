<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_functions.php';

// Log admin logout
if (isset($_SESSION['user_id']) && isset($_SESSION['is_admin'])) {
    try {
        $conn = db();
        if ($conn) {
            $stmt = $conn->prepare("
                INSERT INTO user_activity_logs (user_id, action, description, ip_address, user_agent, created_at) 
                VALUES (?, 'admin_logout', 'Admin logged out', ?, ?, NOW())
            ");
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $stmt->execute([$_SESSION['user_id'], $ip, $agent]);
        }
    } catch (Exception $e) {
        error_log("Logout error: " . $e->getMessage());
    }
}

// Destroy session
$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}
session_destroy();

// Redirect to admin login
header('Location: login.php');
exit;
?>