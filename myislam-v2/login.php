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

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tr(string $key): string {
    global $I18N, $lang;
    return $I18N[$lang][$key] ?? $I18N['en'][$key] ?? $key;
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
        $error = tr('enter_credentials');
    } else {
        $conn = db();
        if (!$conn) {
            $error = tr('database_retry');
        } else {
            $result = loginUser($conn, $username, $password);
            if ($result['success']) {
                session_regenerate_id(true);
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
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(tr('login')) ?> - MyIslam</title>
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
            width: 100%; max-width: 880px;
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
                <span class="ico">🔐</span>
                <div>
                    <h2><?= e(tr('login')) ?></h2>
                    <small><?= e(tr('login_continue')) ?></small>
                </div>
            </div>
            <div class="auth-body">
                <div role="alert" aria-live="polite">
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?= e($error) ?></div>
                    <?php endif; ?>
                </div>
                <form method="POST" id="loginForm" novalidate>
                    <div class="form-group">
                        <label for="username"><?= e(tr('username')) ?></label>
                        <input type="text" id="username" name="username" class="form-control"
                               value="<?= e($username) ?>" placeholder="<?= e(tr('enter_username')) ?>"
                               autocomplete="username" autofocus required>
                    </div>
                    <div class="form-group">
                        <label for="password"><?= e(tr('password')) ?></label>
                        <div class="password-field">
                            <input type="password" id="password" name="password" class="form-control"
                                   placeholder="<?= e(tr('enter_password')) ?>" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" id="passwordToggle" aria-label="<?= e(tr('show_password')) ?>" aria-pressed="false"><?= e(tr('show')) ?></button>
                        </div>
                    </div>
                    <div class="checkbox-row">
                        <label class="checkbox-label">
                            <input type="checkbox" name="remember_me" <?= $remember_me ? 'checked' : '' ?>>
                            <?= e(tr('remember_me')) ?>
                        </label>
                    </div>
                    <button type="submit" class="btn-primary" id="submitBtn"><?= e(tr('sign_in')) ?></button>
                    <div class="switch-link">
                        <?= e(tr('no_account')) ?> <a href="register.php"><?= e(tr('create_account')) ?></a>
                    </div>
                </form>
            </div>
        </section>
    </div>
    <a class="lang-toggle-btn" href="?lang=<?= $lang === 'sw' ? 'en' : 'sw' ?>"><?= $lang === 'sw' ? 'EN' : 'SW' ?></a>
    <button class="theme-toggle-btn" id="themeToggle" type="button" aria-label="Toggle dark mode">🌙</button>
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