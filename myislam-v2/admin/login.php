<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_functions.php';

// If already logged in as admin, redirect to admin dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['owner', 'admin', 'sheikh'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        $conn = db();
        if (!$conn) {
            $error = 'Database connection error';
        } else {
            // Check if user exists and is admin
            $stmt = $conn->prepare("
                SELECT id, full_name, email, password, role, is_active, is_verified, profile_image 
                FROM users 
                WHERE email = ? AND role IN ('owner', 'admin', 'sheikh')
            ");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();
            
            if (!$admin) {
                $error = 'Invalid admin credentials';
            } elseif (!$admin['is_active']) {
                $error = 'Your admin account has been deactivated';
            } elseif (!password_verify($password, $admin['password'])) {
                $error = 'Invalid admin credentials';
            } else {
                // Set admin session
                $_SESSION['user_id'] = $admin['id'];
                $_SESSION['user_name'] = $admin['full_name'];
                $_SESSION['user_email'] = $admin['email'];
                $_SESSION['user_role'] = $admin['role'];
                $_SESSION['user_picture'] = $admin['profile_image'] ?? null;
                $_SESSION['is_admin'] = true;
                
                // Update last login
                $stmt = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                $stmt->execute([$admin['id']]);
                
                // Log admin login
                $stmt = $conn->prepare("
                    INSERT INTO user_activity_logs (user_id, action, description, ip_address, user_agent, created_at) 
                    VALUES (?, 'admin_login', 'Admin logged in', ?, ?, NOW())
                ");
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                $stmt->execute([$admin['id'], $ip, $agent]);
                
                header('Location: dashboard.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - MyIslam</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #0f2b3d 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 420px;
            width: 100%;
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f2b3d 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .login-header .admin-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 10px;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 14px;
            margin-top: 10px;
        }

        .login-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #1e3a5f;
            box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1e3a5f 0%, #0f2b3d 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30, 58, 95, 0.3);
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
            font-size: 14px;
        }

        .back-link a {
            color: #1e3a5f;
            text-decoration: none;
            font-weight: 600;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
        }

        .info-box {
            margin-top: 20px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 10px;
            font-size: 12px;
            text-align: center;
            color: #6b7280;
        }

        @media (max-width: 480px) {
            .login-body {
                padding: 20px;
            }
        }
    </style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Admin Portal</h1>
            <div class="admin-badge">🔒 Secure Access</div>
            <p>Please login to access the administration panel</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($email); ?>" placeholder="admin@myislam.com">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Enter your password">
                </div>
                
                <button type="submit" class="btn-login">Login to Admin Panel</button>
                
                <div class="back-link">
                    <a href="../login.php">← Back to User Login</a>
                </div>
                
                <div class="info-box">
                    <strong>ℹ️ Admin Access Only</strong><br>
                    This area is restricted to administrators only.
                </div>
            </form>
        </div>
    </div>
</body>
</html>