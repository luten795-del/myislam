<?php
/**
 * Admin Authentication Helper
 * Include this at the top of all admin pages
 */

// Check if user is logged in and is admin
function checkAdminAuth(): void {
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        header('Location: login.php');
        exit;
    }
    
    // Check if user has admin role
    $adminRoles = ['owner', 'admin', 'sheikh'];
    if (!in_array($_SESSION['user_role'], $adminRoles)) {
        header('Location: ../user_dashboard.php?error=unauthorized');
        exit;
    }
    
    // Optional: Check if session is still valid in database
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
        // Session expired after 1 hour
        session_unset();
        session_destroy();
        header('Location: login.php?error=session_expired');
        exit;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
}

// Call the check on every admin page
checkAdminAuth();
?>