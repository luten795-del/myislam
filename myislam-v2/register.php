<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/translations.php';

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit;
}

$error = '';
$success = '';
$full_name = '';
$username = '';


// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');

    $username = trim($_POST['username'] ?? '');
  
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Basic validation
    if ($password !== $confirm_password) {
        $error = t('password_mismatch');
    } elseif (strlen($password) < 6) {
        $error = t('password_minimum');
    } elseif (empty($full_name) || empty($username)) {
        $error = t('required_fields');
   
    } elseif (preg_match('/\s/', $username)) {
        // No spaces allowed in username
        $error = t('username_spaces');
    } else {
        $conn = db();

        if (!$conn) {
            $error = t('database_registration_error');
        } else {
            // Check if username already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = t('username_taken');
            } else {
                // Check if email already exists
               
                    // Call registerUser with correct parameter order:
                    // full_name, email, username, password, role, whatsapp
                    $result = registerUser($conn, $full_name, $username, $password, 'user');

                    if ($result['success']) {
                        $success = $result['message'];
                        $full_name =  $username =  '';
                        // Redirect to login after 2 seconds
                        header("refresh:2;url=login.php");
                    } else {
                        $error = $result['message'];
                    }
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
    <title><?= e(t('register')) ?> - MyIslam</title>
    <style>
        /* ===== MYISLAM DESIGN SYSTEM ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #f5f7f5;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --line: #e5e7eb;
            --text: #1f2937;
            --text-secondary: #4b5563;
            --muted: #6b7280;
            --brand: #166534;
            --brand-dark: #14532d;
            --brand-soft: #e7f3eb;
            --danger: #dc2626;
            --success: #10b981;
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.12);
            --radius-lg: 20px;
        }

        body.dark-mode {
            --bg: #0f1720;
            --surface: #18212b;
            --surface-2: #1d2833;
            --line: #304150;
            --text: #edf2f7;
            --text-secondary: #cbd5e1;
            --muted: #94a3b8;
            --brand: #7dd3a7;
            --brand-dark: #6bb590;
            --brand-soft: rgba(125, 211, 167, 0.12);
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none; z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%23166534' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
        }

        body.dark-mode::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%237dd3a7' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }

        .register-card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 480px;
            padding: 32px;
            border: 1px solid var(--brand);
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .brand-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: var(--brand);
            margin-bottom: 6px;
        }

        .brand-header p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--surface-2);
            color: var(--text);
            font-size: 15px;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--brand);
            background: var(--surface);
            box-shadow: 0 0 0 4px var(--brand-soft);
        }

        .form-control.error {
            border-color: var(--danger);
        }

        .password-field { position: relative; }
        .password-field .form-control { padding-right: 64px; }
        .password-toggle {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            border: none; background: transparent; color: var(--brand); font-size: 13px;
            font-weight: 700; cursor: pointer; padding: 4px 6px;
        }

        .form-hint {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        a:focus-visible, button:focus-visible, input:focus-visible {
            outline: 2px solid var(--brand);
            outline-offset: 2px;
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }

        .error-message {
            color: var(--danger);
            font-size: 12px;
            margin-top: 4px;
            display: block;
        }

        .password-strength {
            margin-top: 8px;
        }

        .strength-bar {
            height: 5px;
            background: var(--surface-2);
            border-radius: 4px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s, background 0.3s;
        }

        .strength-text {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: var(--brand);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(22, 101, 52, 0.2);
            margin-top: 8px;
        }

        .btn-primary:hover {
            background: var(--brand-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(22, 101, 52, 0.25);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .login-link a {
            color: var(--brand);
            font-weight: 600;
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .theme-toggle-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--brand);
            color: var(--text);
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-lg);
            z-index: 999;
        }

        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; gap: 0; }
        }
        @media (max-width: 480px) {
            .register-card { padding: 24px; }
        }
    </style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>
    <div class="register-card">
        <div class="brand-header">
            <h1>MyIslam</h1>
            <p><?= e(t('create_account_intro')) ?></p>
        </div>

        <div role="alert" aria-live="polite">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?> <?= e(t('redirect_sign_in')) ?></div>
            <?php endif; ?>
        </div>

        <form method="POST" id="registerForm" novalidate <?= $success ? ' style="opacity:0.5;pointer-events:none;"' : '' ?>>
            <div class="form-row">
                <div class="form-group">
                    <label for="full_name"><?= e(t('full_name')) ?> *</label>
                    <input type="text" id="full_name" name="full_name" class="form-control"
                           value="<?= htmlspecialchars($full_name) ?>" autocomplete="name"
                            required>
                </div>
                <div class="form-group">
                    <label for="username"><?= e(t('username')) ?> * (<?= e(t('username_spaces')) ?>)</label>
                    <input type="text" id="username" name="username" class="form-control"
                           value="<?= htmlspecialchars($username) ?>"
                           pattern="\S+"
                           title="Username must not contain spaces"
                           oninput="this.value = this.value.replace(/\s/g, '')"
                           autocomplete="username"
                           required>
                </div>
            </div>



            <div class="form-row">
                <div class="form-group">
                    <label for="password"><?= e(t('password')) ?> *</label>
                    <div class="password-field">
                        <input type="password" id="password" name="password" class="form-control"
                               placeholder="<?= e(t('min_password')) ?>" autocomplete="new-password" required>
                           <button type="button" class="password-toggle" data-target="password" aria-label="<?= e(t('show_password')) ?>" aria-pressed="false"><?= e(t('show')) ?></button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="confirm_password"><?= e(t('confirm_password')) ?> *</label>
                    <div class="password-field">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                               placeholder="<?= e(t('reenter_password')) ?>" autocomplete="new-password" required>
                           <button type="button" class="password-toggle" data-target="confirm_password" aria-label="<?= e(t('show_password')) ?>" aria-pressed="false"><?= e(t('show')) ?></button>
                    </div>
                </div>
            </div>

            <div class="password-strength">
                <div class="strength-bar">
                    <div class="strength-fill" id="strengthFill"></div>
                </div>
                <div class="strength-text" id="strengthText"></div>
            </div>
            <span class="error-message" id="passwordError"></span>
            <span class="error-message" id="confirmError"></span>

            <button type="submit" class="btn-primary" id="submitBtn"><?= e(t('create_account_button')) ?></button>

            <div class="login-link">
                <?= e(t('already_account')) ?> <a href="login.php"><?= e(t('sign_in')) ?></a>
            </div>
        </form>
    </div>

    <button class="theme-toggle-btn" id="themeToggle" title="Toggle dark mode">🌙</button>

    <script>
        // Dark mode toggle
        (function() {
            const body = document.body;
            const toggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                body.classList.add('dark-mode');
                toggle.textContent = '☀';
            }
            toggle.addEventListener('click', () => {
                body.classList.toggle('dark-mode');
                const isDark = body.classList.contains('dark-mode');
                toggle.textContent = isDark ? '☀' : '🌙';
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            });
        })();

        // Password show/hide toggles
        document.querySelectorAll('.password-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const field = document.getElementById(btn.dataset.target);
                const showing = field.type === 'text';
                field.type = showing ? 'password' : 'text';
                btn.textContent = showing ? 'Show' : 'Hide';
                btn.setAttribute('aria-pressed', String(!showing));
                btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            });
        });

        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');
        const confirmInput = document.getElementById('confirm_password');
        const confirmError = document.getElementById('confirmError');

        function checkStrength(pwd) {
            let strength = 0;
            if (pwd.length >= 6) strength++;
            if (pwd.length >= 8) strength++;
            if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) strength++;
            if (/\d/.test(pwd)) strength++;
            if (/[^a-zA-Z\d]/.test(pwd)) strength++;
            return strength;
        }

        passwordInput.addEventListener('input', function() {
            const pwd = this.value;
            const strength = checkStrength(pwd);
            let width = '0%';
            let color = '#e5e7eb';
            let text = '';

            if (pwd.length === 0) {
                text = '';
            } else if (strength <= 1) {
                width = '25%'; color = '#dc2626'; text = 'Weak';
            } else if (strength === 2) {
                width = '50%'; color = '#f59e0b'; text = 'Fair';
            } else if (strength === 3) {
                width = '75%'; color = '#10b981'; text = 'Good';
            } else {
                width = '100%'; color = '#166534'; text = 'Strong';
            }

            strengthFill.style.width = width;
            strengthFill.style.backgroundColor = color;
            strengthText.textContent = text;

            if (pwd.length > 0 && pwd.length < 6) {
                document.getElementById('passwordError').textContent = 'Password must be at least 6 characters';
            } else {
                document.getElementById('passwordError').textContent = '';
            }

            if (confirmInput.value && confirmInput.value !== pwd) {
                confirmError.textContent = 'Passwords do not match';
            } else {
                confirmError.textContent = '';
            }
        });

        confirmInput.addEventListener('input', function() {
            if (this.value !== passwordInput.value) {
                confirmError.textContent = 'Passwords do not match';
            } else {
                confirmError.textContent = '';
            }
        });

        // Form submission loading
        const form = document.getElementById('registerForm');
        const submitBtn = document.getElementById('submitBtn');
        form.addEventListener('submit', () => {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating account...';
        });
    </script>
</body>
</html>