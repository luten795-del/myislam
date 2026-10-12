<?php
declare(strict_types=1);

/**
 * Authentication helper functions
 */

/**
 * Register a new user
 * @param PDO $conn Database connection
 * @param string $full_name User's full name
 * @param string $username Unique username (used for login)
 * @param string $password User's password
 * @param string $role User role (default: 'user')
 * @return array ['success' => bool, 'message' => string, 'user_id' => int|null]
 */
function registerUser(
    PDO $conn,
    string $full_name,
    string $username,
    string $password,
    string $role = 'user'
): array {
    try {
      
        
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Username already registered', 'user_id' => null];
        }
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user
        $sql = "INSERT INTO users (full_name, username, password, role, is_active, is_verified, created_at) 
                VALUES (?, ?, ?, ?, 1, 1, NOW())";
        
        // DEBUG: Log the SQL and parameters
        error_log("SQL: " . $sql);
        error_log("Params: full_name=" . $full_name . ", username=" . $username . ", role=" . $role);
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$full_name, $username, $hashed_password, $role]);
        $user_id = (int)$conn->lastInsertId();
        
        return [
            'success' => true,
            'message' => 'Registration successful! Please login.',
            'user_id' => $user_id
        ];
    } catch (PDOException $e) {
        // Return the actual error message
        error_log("Registration PDO Error: " . $e->getMessage());
        error_log("Error Code: " . $e->getCode());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'user_id' => null];
    }
}

/**
 * Login user by username
 */
function loginUserByUsername(PDO $conn, string $username, string $password): array {
    try {
        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'Username and password are required', 'user' => null];
        }
        $stmt = $conn->prepare("SELECT id, full_name, username, password, role, is_active, is_verified, profile_image FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        return verifyLogin($user, $password, $conn);
    } catch (PDOException $e) {
        error_log("Login by username error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error. Please try again.', 'user' => null];
    }
}

/**
 * Login user by email (kept for compatibility but email is optional)
 */
function loginUserByEmail(PDO $conn, string $email, string $password): array {
    try {
        if (empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Email and password are required', 'user' => null];
        }
        $stmt = $conn->prepare("SELECT id, full_name, email, password, role, is_active, is_verified, profile_image FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        return verifyLogin($user, $password, $conn);
    } catch (PDOException $e) {
        error_log("Login by email error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error. Please try again.', 'user' => null];
    }
}

/**
 * Verify login credentials
 */
function verifyLogin($user, string $password, PDO $conn): array {
    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password', 'user' => null];
    }
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid username or password', 'user' => null];
    }
    if (isset($user['is_verified']) && !$user['is_verified']) {
        return ['success' => false, 'message' => 'Please verify your account before logging in', 'user' => null];
    }
    $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
    try {
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, 'login', 'User logged in', ?, ?)");
        $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown']);
    } catch (Exception $e) {}
    unset($user['password']);
    return ['success' => true, 'message' => 'Login successful', 'user' => $user];
}

/**
 * Legacy login function (tries username first, then email)
 */
function loginUser(PDO $conn, string $login, string $password): array {
    // Try username first
    $result = loginUserByUsername($conn, $login, $password);
    if ($result['success']) {
        return $result;
    }
    // If username fails, try email
    return loginUserByEmail($conn, $login, $password);
}

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current logged in user
 */
function getCurrentUser(PDO $conn): ?array {
    if (!isLoggedIn()) return null;
    try {
        $stmt = $conn->prepare("SELECT id, full_name, username, role, profile_image, is_active, is_verified, last_login_at FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("Get current user error: " . $e->getMessage());
        return null;
    }
}

/**
 * Logout user
 */
function logoutUser(): void {
    if (isset($_SESSION['user_id'])) {
        try {
            $conn = db();
            if ($conn) {
                $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, 'logout', 'User logged out', ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown']);
            }
        } catch (Exception $e) {}
    }
    $_SESSION = [];
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
}

/**
 * Require user to be logged in
 */
function requireLogin(string $redirect_url = 'login.php'): void {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirect_url);
        exit;
    }
}

/**
 * Require specific user role
 */
function requireRole($required_roles, string $redirect_url = 'user_dashboard.php'): void {
    requireLogin();
    if (!isset($_SESSION['user_role'])) {
        header('Location: ' . $redirect_url);
        exit;
    }
    $roles = is_array($required_roles) ? $required_roles : [$required_roles];
    if (!in_array($_SESSION['user_role'], $roles)) {
        header('Location: ' . $redirect_url);
        exit;
    }
}