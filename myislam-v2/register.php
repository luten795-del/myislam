<?php
declare(strict_types=1);
session_start();

// ?lang=en|sw switches the page language (same switch as login.php)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'sw'], true)) {
    $_SESSION['language'] = $_GET['lang'];
    setcookie('language', $_GET['lang'], time() + 86400 * 365, '/');
}
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}
$lang = ($_SESSION['language'] ?? 'en') === 'sw' ? 'sw' : 'en';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/translations.php';

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ---------- Academy panel text (self-contained, en/sw) ----------
$AUTH_UI = [
    'en' => [
        'tag'     => 'Welcome to',
        'title'   => 'MyIslam Academy',
        'tagline' => 'Structured Islamic learning with courses, weekly quizzes, exams and certificates.',
        'f1'      => 'Structured courses and weekly timetable',
        'f2'      => 'Weekly quizzes and monthly exams',
        'f3'      => 'Performance reports and certificates',
    ],
    'sw' => [
        'tag'     => 'Karibu kwenye',
        'title'   => 'Akademia ya MyIslam',
        'tagline' => 'Masomo ya Kiislamu kwa mpangilio: kozi, maswali ya kila wiki, mitihani na vyeti.',
        'f1'      => 'Kozi zenye mpangilio na ratiba ya wiki',
        'f2'      => 'Maswali ya kila wiki na mitihani ya kila mwezi',
        'f3'      => 'Ripoti ya ufaulu na vyeti',
    ],
];
$UI = $AUTH_UI[$lang] ?? $AUTH_UI['en'];

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
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('register')) ?> - MyIslam</title>
    <style>
        /* ===== MYISLAM ACADEMY — AUTH LAYOUT (shared by login + register) ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg:#f5f7f5; --surface:#ffffff; --surface-2:#f9fafb; --surface-3:#eef2f7;
            --line:#e2e5e2; --text:#1f2937; --text-secondary:#4b5563; --muted:#6b7280;
            --brand:#0E4A34; --brand-dark:#0A3626; --brand-light:#3E7A5E; --brand-soft:rgba(14,74,52,.08);
            --gold:#9C7A2E; --gold-soft:rgba(156,122,46,.12);
            --danger:#b91c1c; --success:#15803d;
            --shadow-lg:0 10px 30px rgba(0,0,0,.12);
        }
        body.dark-mode {
            --bg:#0f1720; --surface:#18212b; --surface-2:#1d2833; --surface-3:#22303c;
            --line:#304150; --text:#edf2f7; --text-secondary:#cbd5e1; --muted:#94a3b8;
            --brand:#4C9172; --brand-dark:#6bb590; --brand-light:#7fcaa4; --brand-soft:rgba(76,145,114,.16);
            --gold:#C9A85E; --gold-soft:rgba(201,168,94,.16);
            --danger:#ef4444; --success:#4ade80;
        }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg); color: var(--text); line-height: 1.5;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 16px 16px 80px;
        }
        body::before {
            content: ""; position: fixed; inset: 0; pointer-events: none; z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
        }
        body.dark-mode::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }
        a { color: inherit; }
        a:focus-visible, button:focus-visible, input:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }

        /* Two-pane card: academy panel | form */
        .auth {
            display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr);
            width: 100%; max-width: 960px;
            background: var(--surface); border: 1px solid var(--line); border-radius: 8px;
            overflow: hidden; box-shadow: var(--shadow-lg);
            animation: authIn .35s ease;
        }
        @keyframes authIn { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

        /* Academy panel */
        .auth-hero {
            position: relative; color: #fff; text-align: center;
            padding: 32px 26px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;
            background:
                linear-gradient(rgba(10,40,30,.70), rgba(10,40,30,.86)),
                url('/uploads/subjects-page-hero.png') center/cover no-repeat,
                #0E4A34;
        }
        .auth-logo {
            width: 64px; height: 64px; border-radius: 50%; object-fit: cover; padding: 2px;
            border: 2px solid rgba(255,255,255,.85); background: rgba(255,255,255,.12);
        }
        .auth-tag { font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #D9BC72; }
        .auth-hero h1 { font-size: 26px; font-weight: 800; text-shadow: 1px 2px 4px rgba(0,0,0,.35); line-height: 1.15; }
        .auth-hero p { font-size: 13px; opacity: .93; max-width: 320px; }
        .auth-features { list-style: none; display: grid; gap: 8px; margin-top: 6px; width: 100%; max-width: 320px; text-align: left; }
        .auth-features li {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px; border-radius: 8px; font-size: 12.5px; font-weight: 600;
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22);
        }
        .auth-features .ic { font-size: 16px; flex-shrink: 0; }

        /* Form panel */
        .auth-panel { display: flex; flex-direction: column; min-width: 0; }
        .auth-head {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 22px; background: var(--surface-2); border-bottom: 1px solid var(--line);
        }
        .auth-head .ico {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; border-radius: 5px; background: var(--brand); color: #fff; font-size: 13px; flex-shrink: 0;
        }
        .auth-head h2 { font-size: 15px; font-weight: 800; color: var(--text); }
        .auth-head small { display: block; font-size: 11.5px; color: var(--muted); font-weight: 500; margin-top: 1px; }
        .auth-body { padding: 22px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: end; }
        .form-group { margin-bottom: 16px; min-width: 0; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 700; font-size: 12.5px; color: var(--text-secondary); }
        .form-control {
            width: 100%; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px;
            background: var(--surface-2); color: var(--text); font-size: 14.5px; transition: border-color .15s, box-shadow .15s;
        }
        .form-control::placeholder { color: var(--muted); opacity: .8; }
        .form-control:focus { outline: none; border-color: var(--brand); background: var(--surface); box-shadow: 0 0 0 3px var(--brand-soft); }
        .password-field { position: relative; }
        .password-field .form-control { padding-right: 62px; }
        .password-toggle {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            border: none; background: transparent; color: var(--brand); font-size: 12px; font-weight: 800; cursor: pointer; padding: 4px 6px;
        }
        .form-hint { display: block; margin-top: 4px; color: var(--muted); font-size: 11.5px; }

        .checkbox-row { display: flex; align-items: center; margin-bottom: 18px; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text-secondary); }
        .checkbox-label input { width: 17px; height: 17px; accent-color: var(--brand); }

        .btn-primary {
            display: block; width: 100%; padding: 12px 16px; margin-top: 6px;
            background: var(--brand); color: #fff; border: 2px solid var(--brand); border-radius: 999px;
            font-size: 14.5px; font-weight: 800; cursor: pointer; transition: filter .15s, transform .15s;
        }
        .btn-primary:hover { filter: brightness(1.1); transform: translateY(-1px); }
        .btn-primary:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        .switch-link { text-align: center; margin-top: 16px; padding-top: 14px; border-top: 1px dashed var(--line); font-size: 13px; color: var(--text-secondary); }
        .switch-link a { color: var(--brand); font-weight: 800; text-decoration: none; }
        .switch-link a:hover { text-decoration: underline; }

        .alert { padding: 11px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; font-weight: 600; border: 1px solid transparent; }
        .alert-error, .alert:not(.alert-success) { background: rgba(185,28,28,.08); color: var(--danger); border-color: var(--danger); }
        .alert-success { background: rgba(21,128,61,.08); color: var(--success); border-color: var(--success); }

        .error-message { color: var(--danger); font-size: 12px; margin-top: 4px; display: block; }
        .password-strength { margin-top: 2px; }
        .strength-bar { height: 5px; background: var(--surface-3); border-radius: 4px; overflow: hidden; }
        .strength-fill { height: 100%; width: 0%; transition: width .3s, background .3s; }
        .strength-text { font-size: 12px; color: var(--muted); margin-top: 4px; min-height: 16px; }

        .theme-toggle-btn, .lang-toggle-btn {
            position: fixed; bottom: 16px; height: 42px; background: var(--surface); border: 1px solid var(--brand);
            color: var(--brand); font-weight: 800; display: flex; align-items: center; justify-content: center;
            box-shadow: var(--shadow-lg); z-index: 999; cursor: pointer; text-decoration: none;
        }
        .theme-toggle-btn { right: 16px; width: 42px; border-radius: 50%; font-size: 19px; color: var(--text); }
        .lang-toggle-btn  { left: 16px; min-width: 42px; padding: 0 14px; border-radius: 21px; font-size: 13px; }

        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }

        /* Mobile: compact academy banner on top, form below */
        @media (max-width: 760px) {
            body { padding: 10px 10px 72px; align-items: flex-start; }
            .auth { grid-template-columns: minmax(0, 1fr); max-width: 520px; }
            .auth-hero { padding: 16px 14px; gap: 6px; }
            .auth-logo { width: 44px; height: 44px; }
            .auth-hero h1 { font-size: 20px; }
            .auth-hero p { font-size: 12px; }
            .auth-features { display: none; }
            .auth-head { padding: 10px 14px; }
            .auth-body { padding: 16px 14px; }
        }
        @media (max-width: 560px) {
            .form-row { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>
    <div class="auth">
        <aside class="auth-hero">
            <img class="auth-logo" src="includes/logo.png" alt="" onerror="this.style.display='none'">
            <div class="auth-tag">🎓 <?= e($UI['tag']) ?></div>
            <h1><?= e($UI['title']) ?></h1>
            <p><?= e($UI['tagline']) ?></p>
            <ul class="auth-features">
                <li><span class="ic">🗓️</span><?= e($UI['f1']) ?></li>
                <li><span class="ic">📝</span><?= e($UI['f2']) ?></li>
                <li><span class="ic">🏅</span><?= e($UI['f3']) ?></li>
            </ul>
        </aside>

        <section class="auth-panel">
            <div class="auth-head">
                <span class="ico">📝</span>
                <div>
                    <h2><?= e(t('register')) ?></h2>
                    <small><?= e(t('create_account_intro')) ?></small>
                </div>
            </div>
            <div class="auth-body">
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

                    <div class="switch-link">
                        <?= e(t('already_account')) ?> <a href="login.php"><?= e(t('sign_in')) ?></a>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <a class="lang-toggle-btn" href="?lang=<?= $lang === 'sw' ? 'en' : 'sw' ?>"><?= $lang === 'sw' ? 'EN' : 'SW' ?></a>
    <button class="theme-toggle-btn" id="themeToggle" type="button" title="Toggle dark mode">🌙</button>

    <script>
        // Dark mode toggle
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