<?php
declare(strict_types=1);
session_start();

// Include required files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';

// Check if db() function exists
if (!function_exists('db')) {
    // Fallback if database.php is not properly loaded
    function db() {
        try {
            $host = 'localhost';
            $port = '3307';
            $dbname = 'myislam';
            $username = 'root';
            $password = '';
            
            $conn = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $conn;
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
}

// Log the logout activity
if (isset($_SESSION['user_id'])) {
    try {
        $conn = db();
        if ($conn) {
            $stmt = $conn->prepare("
                INSERT INTO user_activity_logs (user_id, action, description, ip_address, user_agent, created_at) 
                VALUES (?, 'logout', 'User logged out', ?, ?, NOW())
            ");
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $stmt->execute([$_SESSION['user_id'], $ip_address, $user_agent]);
        }
    } catch (Exception $e) {
        error_log("Logout logging error: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = [];

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// Redirect to login page with success message
session_start(); // Start a new session for the message
$_SESSION['logout_message'] = 'You have been successfully logged out.';
header('Location: login.php');
exit;
?>