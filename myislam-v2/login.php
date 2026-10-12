<?php
declare(strict_types=1);
session_start();

// ---------- Language ----------
// A ?lang=xx link (the toggle button below) sets this directly, so the
// login screen can be switched without needing to visit another page
// first. Kept entirely self-contained in this file - no dependency on
// includes/translations.php or a t() function existing elsewhere.
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'sw'], true)) {
    $_SESSION['language'] = $_GET['lang'];
    setcookie('language', $_GET['lang'], time() + 86400 * 365, '/');
}
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}
$lang = ($_SESSION['language'] ?? 'en') === 'sw' ? 'sw' : 'en';

// ---------- Error reporting: log only, don't leak to the page ----------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';

// ============================================================================
// INLINE TRANSLATIONS (en/sw) - every string this page shows lives here,
// so the login page can never break because of a missing translation key
// or a problem in an external translations file.
// ============================================================================
$I18N = [
    'en' => [
        'login'               => 'Login',
        'login_continue'      => 'Login to continue your learning journey',
        'username'            => 'Username',
        'password'            => 'Password',
        'enter_username'      => 'Enter your username',
        'enter_password'      => 'Enter your password',
        'show'                => 'Show',
        'hide'                => 'Hide',
        'show_password'       => 'Show password',
        'hide_password'       => 'Hide password',
        'remember_me'         => 'Remember me',
        'sign_in'             => 'Sign In',
        'signing_in'          => 'Signing in...',
        'no_account'          => "Don't have an account?",
        'create_account'      => 'Create one now',
        'enter_credentials'   => 'Please enter your username and password.',
        'database_retry'      => 'Database connection failed. Please try again later.',
        'invalid_credentials' => 'Invalid username or password.',
    ],
    'sw' => [
        'login'               => 'Ingia',
        'login_continue'      => 'Ingia ili kuendelea na safari yako ya kujifunza',
        'username'            => 'Jina la mtumiaji',
        'password'            => 'Nenosiri',
        'enter_username'      => 'Ingiza jina lako la mtumiaji',
        'enter_password'      => 'Ingiza nenosiri lako',
        'show'                => 'Onyesha',
        'hide'                => 'Ficha',
        'show_password'       => 'Onyesha nenosiri',
        'hide_password'       => 'Ficha nenosiri',
        'remember_me'         => 'Nikumbuke',
        'sign_in'             => 'Ingia',
        'signing_in'          => 'Inaingia...',
        'no_account'          => 'Huna akaunti?',
        'create_account'      => 'Fungua sasa',
        'enter_credentials'   => 'Tafadhali ingiza jina lako la mtumiaji na nenosiri.',
        'database_retry'      => 'Muunganisho wa hifadhidata umeshindwa. Tafadhali jaribu tena baadaye.',
        'invalid_credentials' => 'Jina la mtumiaji au nenosiri sio sahihi.',
    ],
];

function tr(string $key): string {
    global $I18N, $lang;
    return $I18N[$lang][$key] ?? $I18N['en'][$key] ?? $key;
}

// ---------- Helper functions ----------
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function loginUser(PDO $conn, string $username, string $password): array {
    $stmt = $conn->prepare("
        SELECT id, full_name, username, email, role, profile_image, password
        FROM users
        WHERE username = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['success' => false, 'message' => tr('invalid_credentials')];
    }
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => tr('invalid_credentials')];
    }
    unset($user['password']);
    return ['success' => true, 'user' => $user];
}

// ---------- Already logged in? ----------
if (isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit;
}

$error = '';
$username = '';
$remember_me = false;

// ---------- Process login ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);

    if ($username === '' || $password === '') {
        $error = trim('enter_credentials');
    } else {
        $conn = db();
        if (!$conn) {
            $error = trim('database_retry');
        } else {
            $result = loginUser($conn, $username, $password);
            if ($result['success']) {
                $_SESSION['user_id']   = $result['user']['id'];
                $_SESSION['user_name'] = $result['user']['full_name'];
                $_SESSION['user_email']= $result['user']['email'];
                $_SESSION['user_role'] = $result['user']['role'];
                $_SESSION['user_picture'] = $result['user']['profile_image'] ?? null;

                if ($remember_me) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + 86400 * 30, '/', '', false, true);
                }
                $redirect = $_SESSION['redirect_after_login'] ?? 'user_dashboard.php';
                unset($_SESSION['redirect_after_login']);
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(tr('login')) ?> - MyIslam</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --bg:#f5f7f5; --surface:#ffffff; --surface-2:#f9fafb; --line:#e5e7eb;
            --text:#1f2937; --text-secondary:#4b5563; --muted:#6b7280;
            --brand:#166534; --brand-dark:#14532d; --brand-soft:#e7f3eb;
            --danger:#dc2626; --shadow-lg:0 20px 40px rgba(0,0,0,0.12);
            --radius-lg:20px;
        }
        body.dark-mode {
            --bg:#0f1720; --surface:#18212b; --surface-2:#1d2833; --line:#304150;
            --text:#edf2f7; --text-secondary:#cbd5e1; --muted:#94a3b8;
            --brand:#7dd3a7; --brand-dark:#6bb590; --brand-soft:rgba(125,211,167,0.12);
        }
        body {
            font-family:system-ui,-apple-system,sans-serif; background:var(--bg); color:var(--text);
            min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
        }
        body::before {
            content:""; position:fixed; top:0; left:0; width:100%; height:100%;
            pointer-events:none; z-index:-1;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%23166534' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat:repeat;
        }
        .login-card {
            background:var(--surface); border-radius:var(--radius-lg); box-shadow:var(--shadow-lg);
            width:100%; max-width:420px; padding:32px; border:1px solid var(--brand);
        }
        .brand-header { text-align:center; margin-bottom:28px; }
        .brand-header h1 { font-size:32px; font-weight:700; color:var(--brand); }
        .brand-header p { color:var(--text-secondary); font-size:14px; }
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; margin-bottom:6px; font-weight:600; font-size:14px; }
        .form-control {
            width:100%; padding:14px 16px; border:1px solid var(--line); border-radius:14px;
            background:var(--surface-2); color:var(--text); font-size:15px;
        }
        .form-control:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 4px var(--brand-soft); }
        .password-field { position:relative; }
        .password-field .form-control { padding-right:64px; }
        .password-toggle {
            position:absolute; right:10px; top:50%; transform:translateY(-50%);
            border:none; background:transparent; color:var(--brand); font-size:13px;
            font-weight:700; cursor:pointer; padding:4px 6px;
        }
        a:focus-visible, button:focus-visible, input:focus-visible {
            outline:2px solid var(--brand); outline-offset:2px;
        }
        @media (prefers-reduced-motion: reduce) { * { animation:none !important; transition:none !important; } }
        .checkbox-row { display:flex; align-items:center; margin-bottom:24px; }
        .checkbox-label { display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; color:var(--text-secondary); }
        .checkbox-label input { width:18px; height:18px; accent-color:var(--brand); }
        .btn-primary {
            width:100%; padding:14px; background:var(--brand); color:#fff; border:none;
            border-radius:14px; font-size:16px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(22,101,52,0.2);
        }
        .btn-primary:hover { background:var(--brand-dark); }
        .btn-primary:disabled { opacity:0.6; cursor:not-allowed; }
        .register-link { text-align:center; margin-top:20px; font-size:14px; color:var(--text-secondary); }
        .register-link a { color:var(--brand); font-weight:600; text-decoration:none; }
        .alert {
            padding:14px 16px; border-radius:14px; margin-bottom:20px; font-size:14px;
            background:#fee2e2; color:#991b1b; border-left:4px solid var(--danger);
        }
        .theme-toggle-btn {
            position:fixed; bottom:20px; right:20px; width:44px; height:44px; border-radius:50%;
            background:var(--surface); border:1px solid var(--brand); font-size:20px; cursor:pointer;
            display:flex; align-items:center; justify-content:center; box-shadow:var(--shadow-lg); z-index:999;
        }
        .lang-toggle-btn {
            position:fixed; bottom:20px; left:20px; min-width:44px; height:44px; padding:0 14px; border-radius:22px;
            background:var(--surface); border:1px solid var(--brand); color:var(--brand); font-size:14px; font-weight:700;
            display:flex; align-items:center; justify-content:center; box-shadow:var(--shadow-lg); z-index:999;
            text-decoration:none;
        }
        @media (max-width:480px) { .login-card { padding:24px; } }
    </style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>
    <div class="login-card">
        <div class="brand-header">
            <h1>MyIslam</h1>
            <p><?= htmlspecialchars(tr('login_continue')) ?></p>
        </div>
        <div role="alert" aria-live="polite">
            <?php if ($error): ?>
                <div class="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        </div>
        <form method="POST" id="loginForm" novalidate>
            <div class="form-group">
                <label for="username"><?= htmlspecialchars(tr('username')) ?></label>
                <input type="text" id="username" name="username" class="form-control"
                       value="<?= htmlspecialchars($username) ?>" placeholder="<?= htmlspecialchars(tr('enter_username')) ?>"
                       autocomplete="username" autofocus required>
            </div>
            <div class="form-group">
                <label for="password"><?= htmlspecialchars(tr('password')) ?></label>
                <div class="password-field">
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="<?= htmlspecialchars(tr('enter_password')) ?>" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" id="passwordToggle" aria-label="<?= htmlspecialchars(tr('show_password')) ?>" aria-pressed="false"><?= htmlspecialchars(tr('show')) ?></button>
                </div>
            </div>
            <div class="checkbox-row">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember_me" <?= $remember_me ? 'checked' : '' ?>>
                    <?= htmlspecialchars(tr('remember_me')) ?>
                </label>
            </div>
            <button type="submit" class="btn-primary" id="submitBtn"><?= htmlspecialchars(tr('sign_in')) ?></button>
            <div class="register-link">
                <?= htmlspecialchars(tr('no_account')) ?> <a href="register.php"><?= htmlspecialchars(tr('create_account')) ?></a>
            </div>
        </form>
    </div>
    <a class="lang-toggle-btn" href="?lang=<?= $lang === 'sw' ? 'en' : 'sw' ?>"><?= $lang === 'sw' ? 'EN' : 'SW' ?></a>
    <button class="theme-toggle-btn" id="themeToggle">🌙</button>
    <script>
        (function() {
            const body = document.body, toggle = document.getElementById('themeToggle');
            if (localStorage.getItem('theme') === 'dark') { body.classList.add('dark-mode'); toggle.textContent = '☀'; }
            toggle.addEventListener('click', () => {
                body.classList.toggle('dark-mode');
                const isDark = body.classList.contains('dark-mode');
                toggle.textContent = isDark ? '☀' : '🌙';
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            });
        })();
        const pwField = document.getElementById('password');
        const pwToggle = document.getElementById('passwordToggle');
        const LABEL_SHOW = <?= json_encode(tr('show')) ?>;
        const LABEL_HIDE = <?= json_encode(tr('hide')) ?>;
        const ARIA_SHOW  = <?= json_encode(tr('show_password')) ?>;
        const ARIA_HIDE  = <?= json_encode(tr('hide_password')) ?>;
        pwToggle.addEventListener('click', () => {
            const showing = pwField.type === 'text';
            pwField.type = showing ? 'password' : 'text';
            pwToggle.textContent = showing ? LABEL_SHOW : LABEL_HIDE;
            pwToggle.setAttribute('aria-pressed', String(!showing));
            pwToggle.setAttribute('aria-label', showing ? ARIA_SHOW : ARIA_HIDE);
        });

        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.textContent = <?= json_encode(tr('signing_in')) ?>;
        });
    </script>
</body>
</html>