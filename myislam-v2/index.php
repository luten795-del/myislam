<?php
declare(strict_types=1);
session_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';

// ---------- Language ----------
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'sw'], true)) {
    $_SESSION['language'] = $_GET['lang'];
    setcookie('language', $_GET['lang'], time() + 86400 * 365, '/');
}
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'sw';
}
$lang = ($_SESSION['language'] ?? 'sw') === 'en' ? 'en' : 'sw';

// ---------- Already logged in? Go to dashboard ----------
if (isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit;
}

// ============================================================================
// HELPERS
// ============================================================================
if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') return 'U';
        $parts = preg_split('/\s+/', $name) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= strtoupper(substr($part, 0, 1));
        }
        return $letters !== '' ? $letters : 'U';
    }
}

// ============================================================================
// LIVE DATA
// ============================================================================
$conn = db();

$stats = [
    'books'     => 0,
    'lessons'   => 0,
    'quizzes'   => 0,
    'exams'     => 0,
    'students'  => 0,
    'courses'   => 0,
];
$featuredSubjects = [];

if ($conn instanceof PDO) {
    try {
        $stats['books']    = (int)$conn->query("SELECT COUNT(*) FROM subjects WHERE is_active = 1")->fetchColumn();
        $stats['lessons']  = (int)$conn->query("SELECT COUNT(*) FROM contents WHERE status = 'published'")->fetchColumn();
        $stats['quizzes']  = (int)$conn->query("SELECT COUNT(*) FROM quizzes WHERE status = 'published'")->fetchColumn();

        $certExamCount    = (int)$conn->query("SELECT COUNT(*) FROM certification_exams WHERE status = 'published'")->fetchColumn();
        $monthlyExamCount = (int)$conn->query("SELECT COUNT(*) FROM monthly_exams WHERE status = 'published'")->fetchColumn();
        $stats['exams']   = $certExamCount + $monthlyExamCount;

        $stats['students'] = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND is_active = 1")->fetchColumn();

        try {
            $stats['courses'] = (int)$conn->query("SELECT COUNT(*) FROM courses WHERE status = 'published'")->fetchColumn();
        } catch (PDOException $exCourses) {
            $stats['courses'] = 0;
        }

        $stmt = $conn->query("
            SELECT
                s.id, s.name, s.slug, s.description, s.icon, s.image_path,
                COUNT(DISTINCT cat.id) AS total_categories,
                COUNT(DISTINCT c.id) AS total_contents
            FROM subjects s
            LEFT JOIN categories cat ON cat.subject_id = s.id AND cat.is_active = 1
            LEFT JOIN contents c ON c.subject_id = s.id AND c.status = 'published'
            WHERE s.is_active = 1
            GROUP BY s.id, s.name, s.slug, s.description, s.icon, s.image_path
            ORDER BY s.sort_order ASC, s.name ASC
            LIMIT 8
        ");
        $featuredSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        error_log('index.php landing page data error: ' . $ex->getMessage());
    }
}

// ---------- Mode counts ----------
$selfQuizCount = 0;
$competitionQuizCount = 0;
if ($conn instanceof PDO) {
    try {
        $selfQuizCount        = (int)$conn->query("SELECT COUNT(*) FROM quizzes WHERE status = 'published' AND mode = 'self'")->fetchColumn();
        $competitionQuizCount = (int)$conn->query("SELECT COUNT(*) FROM quizzes WHERE status = 'published' AND mode = 'competition'")->fetchColumn();
    } catch (PDOException $ex) {
        error_log('index.php mode counts error: ' . $ex->getMessage());
    }
}

// ---------- Masheikh ----------
$masheikh = [];
if ($conn instanceof PDO) {
    try {
        $stmt = $conn->prepare("
            SELECT sheikh_name
            FROM subjects
            WHERE sheikh_name IS NOT NULL AND sheikh_name <> ''
            GROUP BY sheikh_name
            ORDER BY sheikh_name ASC
        ");
        $stmt->execute();
        $masheikh = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $ex) {
        error_log('index.php masheikh fetch error: ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0E4A34">
    <title>MyIslam — Jifunze Misingi ya Uislamu</title>
    <meta name="description" content="Jukwaa la bure la kujifunza Uislamu — masomo ya mwingiliano, maswali ya kujipima na ya ushindani, na mitihani ya vyeti. Inapatikana hata bila mtandao.">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #f5f7f5;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --surface-3: #eef2f7;
            --line: #e2e5e2;
            --text: #1f2937;
            --text-secondary: #4b5563;
            --muted: #6b7280;

            --brand: #0E4A34;
            --brand-dark: #0A3626;
            --brand-light: #3E7A5E;
            --brand-soft: rgba(14, 74, 52, 0.08);

            --gold: #9C7A2E;
            --gold-soft: rgba(156, 122, 46, 0.12);
            --gold-title: #D9BC72;

            --accent: #3E6E91;
            --danger: #b91c1c;

            --shadow-sm: 0 1px 2px rgba(0,0,0,.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,.08);
            --shadow-lg: 0 10px 30px rgba(0,0,0,.12);

            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 20px;
            --radius-pill: 999px;
        }
        body.dark-mode {
            --bg: #0f1720;
            --surface: #18212b;
            --surface-2: #1d2833;
            --surface-3: #22303c;
            --line: #304150;
            --text: #edf2f7;
            --text-secondary: #cbd5e1;
            --muted: #94a3b8;

            --brand: #4C9172;
            --brand-dark: #6bb590;
            --brand-light: #7fcaa4;
            --brand-soft: rgba(76, 145, 114, 0.16);

            --gold: #C9A85E;
            --gold-soft: rgba(201, 168, 94, 0.16);
            --gold-title: #D9BC72;

            --accent: #6FA0C4;
            --danger: #ef4444;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.55;
        }
        body::before {
            content: "";
            position: fixed; inset: 0;
            pointer-events: none; z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
        }
        body.dark-mode::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%234C9172' fill-opacity='0.05' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
        }

        a { color: inherit; text-decoration: none; }
        button { font: inherit; cursor: pointer; border: none; background: none; color: inherit; }
        a:focus-visible, button:focus-visible {
            outline: 2px solid var(--gold); outline-offset: 2px; border-radius: 6px;
        }

        .wrap { max-width: 1180px; margin: 0 auto; padding: 0 20px; }

        /* ============ NAV ============ */
        .site-nav {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            padding: 10px 20px;
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            position: sticky; top: 0; z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .site-nav .wrap {
            display: flex; align-items: center; justify-content: space-between;
            width: 100%; padding: 0;
        }
        .brand {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 4px 12px 4px 4px;
            border-radius: var(--radius-pill);
            background: var(--surface-2);
            border: 1px solid var(--line);
        }
        .brand img {
            height: 34px; width: 34px;
            border-radius: 50%; object-fit: cover;
            border: 2px solid var(--brand);
            padding: 1px;
            background: #fff;
        }
        .brand span {
            font-size: 18px; font-weight: 900;
            color: var(--brand);
            letter-spacing: .3px;
        }
        .nav-links { display: flex; align-items: center; gap: 22px; }
        .nav-links a {
            color: var(--text-secondary); font-weight: 700; font-size: 13.5px;
            transition: color .15s;
        }
        .nav-links a:hover { color: var(--brand); }
        .nav-actions { display: flex; align-items: center; gap: 8px; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 9px 20px;
            border-radius: var(--radius-pill);
            font-weight: 800; font-size: 13.5px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: filter .15s, background .15s, transform .15s;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-outline { background: transparent; color: var(--brand); border-color: var(--brand); }
        .btn-outline:hover { background: var(--brand-soft); }
        .btn-gold { background: var(--gold-title); border-color: var(--gold-title); color: #1a1a1a; }
        .btn-gold:hover { filter: brightness(1.05); }
        .btn-light { background: transparent; border-color: rgba(255,255,255,.7); color: #fff; }
        .btn-light:hover { background: rgba(255,255,255,.14); }

        .theme-toggle-btn,
        .lang-toggle-btn {
            height: 36px; padding: 0 10px;
            border-radius: var(--radius-pill);
            background: var(--surface-2);
            border: 1px solid var(--line);
            color: var(--text);
            font-size: 12.5px; font-weight: 800;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all .15s;
        }
        .theme-toggle-btn { width: 36px; padding: 0; font-size: 15px; }
        .theme-toggle-btn:hover, .lang-toggle-btn:hover {
            background: var(--brand-soft); border-color: var(--brand); color: var(--brand);
        }
        .mobile-nav-toggle {
            display: none;
            background: none; border: none;
            font-size: 24px; color: var(--brand); cursor: pointer;
        }
        .mobile-nav-menu {
            display: none; flex-direction: column;
            background: var(--surface); border-top: 1px solid var(--line);
            padding: 6px 0 10px; width: 100%; flex-basis: 100%; margin-top: 8px;
        }
        .mobile-nav-menu.open { display: flex; }
        .mobile-nav-menu a {
            padding: 11px 4px;
            color: var(--text); font-weight: 700; font-size: 14.5px;
            border-bottom: 1px solid var(--line);
        }
        .mobile-nav-menu a:last-child { border-bottom: none; }

        /* ============ HERO ============ */
        .hero-wrap { padding: 18px 0 0; }
        .hero {
            position: relative; overflow: hidden;
            border-radius: var(--radius-md);
            color: #fff;
            padding: 40px 36px;
            display: grid; grid-template-columns: 1.15fr .85fr;
            gap: 32px; align-items: center;
            background:
                linear-gradient(rgba(10,40,30,.74), rgba(10,40,30,.88)),
                url('/uploads/subjects-page-hero.png') center/cover no-repeat,
                #0E4A34;
        }
        .hero::before {
            content: "MyIslam";
            position: absolute;
            inset: auto auto 6% 4%;
            transform: rotate(-15deg);
            font-family: 'Amiri', serif;
            font-size: clamp(60px, 9vw, 120px);
            font-weight: 900;
            color: rgba(255,255,255,.05);
            white-space: nowrap;
            pointer-events: none;
        }
        .hero > * { position: relative; z-index: 1; }

        .hero-brand {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 6px 14px 6px 6px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: var(--radius-pill);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            margin-bottom: 14px;
        }
        .hero-logo {
            height: 36px; width: 36px;
            border-radius: 50%; object-fit: cover;
            border: 2px solid var(--gold-title);
            padding: 2px;
            background: #fff;
        }
        .hero-brand-text { display: flex; flex-direction: column; line-height: 1.1; }
        .hero-brand-text strong { font-size: 14px; font-weight: 900; color: #fff; }
        .hero-brand-text span {
            font-size: 9.5px; font-weight: 700; letter-spacing: 1px;
            text-transform: uppercase; color: var(--gold-title);
            margin-top: 2px;
        }

        .hero-tag {
            display: inline-block;
            font-size: 11px; font-weight: 800; letter-spacing: 1px;
            text-transform: uppercase; color: var(--gold-title);
            margin-bottom: 8px;
        }
        .hero h1 {
            font-size: clamp(24px, 3.8vw, 38px);
            line-height: 1.15; font-weight: 800;
            margin-bottom: 14px;
            text-shadow: 0 2px 8px rgba(0,0,0,.4), 0 1px 2px rgba(0,0,0,.3);
        }
        .hero h1 .accent { color: var(--gold-title); }
        .hero p.lead {
            font-size: 15px; opacity: .94;
            margin-bottom: 22px; max-width: 520px;
        }
        .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .hero-visual { display: flex; align-items: center; justify-content: center; min-width: 0; }

        /* ============ LAPTOP MOCKUP ============ */
        .laptop-mockup { width: 100%; max-width: 440px; }
        .laptop-screen {
            background: #1a1f26;
            border-radius: 12px 12px 4px 4px;
            padding: 7px 7px 0;
            box-shadow: var(--shadow-lg);
            border: 1px solid #2a3038;
        }
        .laptop-browser-bar { display: flex; align-items: center; gap: 6px; padding: 5px 8px 7px; }
        .laptop-browser-bar .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .dot-red { background: #ff5f57; } .dot-yellow { background: #febc2e; } .dot-green { background: #28c840; }
        .laptop-screen-inner {
            background: #0f1720;
            border-radius: 4px 4px 0 0;
            padding: 12px;
            display: flex; gap: 8px;
            overflow: hidden;
        }
        .mini-subject-card {
            flex: 1; min-width: 0;
            border-radius: 8px;
            padding: 10px 8px;
            color: #fff;
            display: flex; flex-direction: column; gap: 5px;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.08);
        }
        .mini-card-top { display: flex; align-items: center; justify-content: space-between; font-size: 14px; }
        .mini-card-pct { font-size: 10px; background: rgba(255,255,255,.15); padding: 1px 6px; border-radius: 20px; }
        .mini-card-title {
            font-size: 11px; font-weight: 700;
            direction: rtl; line-height: 1.3;
            height: 2.6em; overflow: hidden;
        }
        .mini-card-meta { font-size: 8.5px; opacity: .75; }
        .mini-card-progress { height: 4px; background: rgba(255,255,255,.15); border-radius: 4px; overflow: hidden; }
        .mini-card-progress-fill { height: 100%; background: var(--gold-title); }
        .mini-card-btn {
            margin-top: 2px;
            background: var(--brand);
            color: #fff;
            font-size: 8.5px; font-weight: 700;
            text-align: center;
            padding: 5px 4px; border-radius: 6px;
        }
        .laptop-base {
            height: 12px;
            background: linear-gradient(to bottom, #d8dadd, #b7bcc2);
            border-radius: 0 0 10px 10px;
            position: relative;
            margin: 0 -6px;
        }
        .laptop-notch {
            position: absolute;
            top: 0; left: 50%; transform: translateX(-50%);
            width: 70px; height: 5px;
            background: #9aa0a6;
            border-radius: 0 0 6px 6px;
        }

        /* ============ STATS BAR ============ */
        .stats-bar {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            margin-top: 14px;
            padding: 16px 12px;
            display: grid; grid-template-columns: repeat(5, 1fr);
            gap: 10px;
        }
        .stat-item { text-align: center; }
        .stat-item .num {
            font-size: 24px; font-weight: 800;
            color: var(--gold-title);
            line-height: 1.15;
        }
        .stat-item .label { font-size: 12px; color: var(--muted); font-weight: 700; }

        /* ============ SECTIONS ============ */
        .section { padding: 40px 0; }
        .section-head { text-align: center; max-width: 640px; margin: 0 auto 24px; }
        .section-head .eyebrow {
            color: var(--gold); font-weight: 800; font-size: 11.5px;
            text-transform: uppercase; letter-spacing: .08em;
        }
        .section-head h2 {
            font-size: clamp(20px, 3vw, 25px); font-weight: 800;
            margin: 6px 0 8px; color: var(--text);
        }
        .section-head p { color: var(--text-secondary); font-size: 14px; }

        /* ============ ACADEMY SECTION ============ */
        .academy {
            position: relative; overflow: hidden;
            border-radius: var(--radius-md);
            color: #fff;
            padding: 28px;
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 24px; align-items: center;
            background:
                linear-gradient(rgba(10,40,30,.62), rgba(10,40,30,.84)),
                url('/uploads/myislam-academyc.png') center/cover no-repeat,
                linear-gradient(135deg, #0E4A34, #051810);
        }
        .academy .tag {
            font-size: 11px; font-weight: 800; letter-spacing: 1px;
            text-transform: uppercase; color: var(--gold-title);
        }
        .academy h2 {
            font-size: clamp(20px, 3.2vw, 27px); font-weight: 800;
            margin: 6px 0 10px;
            text-shadow: 1px 2px 4px rgba(0,0,0,.3);
        }
        .academy p.lead { font-size: 14.5px; opacity: .94; margin-bottom: 16px; }
        .academy-chips { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 18px; }
        .academy-chips span {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 10px; border-radius: var(--radius-sm);
            font-size: 12.5px; font-weight: 700;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.22);
        }
        .track-cards { display: grid; gap: 12px; min-width: 0; }
        .track-card {
            background: var(--surface); color: var(--text);
            border: 1px solid var(--line);
            border-left: 4px solid var(--brand);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
        }
        .track-card.academy-track { border-left-color: var(--gold); }
        .track-card h3 { font-size: 15px; font-weight: 800; margin-bottom: 4px; color: var(--brand); }
        .track-card.academy-track h3 { color: var(--gold); }
        .track-card p { font-size: 12.5px; color: var(--text-secondary); }

        /* ============ BOOKS GRID ============ */
        .books-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        .book-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            overflow: hidden;
            color: var(--text);
            transition: transform .15s, box-shadow .15s, border-color .15s;
            display: flex; flex-direction: column;
        }
        .book-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: var(--brand);
        }
        .book-cover {
            height: 120px;
            background-size: cover; background-position: center;
            background-color: var(--brand-soft);
            display: flex; align-items: center; justify-content: center;
            font-size: 38px;
        }
        .book-body { padding: 12px 14px; flex: 1; display: flex; flex-direction: column; gap: 6px; }
        .book-title { font-size: 15px; font-weight: 800; direction: rtl; text-align: right; }
        .book-desc {
            font-size: 12px; color: var(--muted); flex: 1;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
        }
        .book-meta { font-size: 11.5px; color: var(--brand); font-weight: 700; }
        .empty-note { text-align: center; color: var(--muted); padding: 20px; }

        /* ============ FEATURE CARDS ============ */
        .features-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        .feature-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 18px 14px;
            text-align: center;
        }
        .feature-card .icon-wrap {
            width: 48px; height: 48px;
            border-radius: var(--radius-sm);
            background: var(--brand-soft); color: var(--brand);
            display: flex; align-items: center; justify-content: center;
            font-size: 23px; margin: 0 auto 10px;
        }
        .feature-card h3 { font-size: 14.5px; font-weight: 800; margin-bottom: 5px; }
        .feature-card p { font-size: 12.5px; color: var(--muted); margin-bottom: 8px; }
        .feature-card .count { font-size: 12.5px; font-weight: 800; color: var(--gold); }

        /* ============ OFFLINE ============ */
        .offline-banner {
            background: var(--brand-soft);
            border: 1px solid var(--brand);
            border-radius: var(--radius-md);
            padding: 18px 22px;
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .offline-banner .icon { font-size: 30px; }
        .offline-banner h3 { font-size: 16px; font-weight: 800; color: var(--brand); margin-bottom: 3px; }
        .offline-banner p { font-size: 13.5px; color: var(--text-secondary); }

        /* ============ MASHEIKH ============ */
        .masheikh-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(220px, 100%), 1fr));
            gap: 12px;
        }
        .masheikh-card {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            transition: transform .15s, box-shadow .15s, border-color .15s;
            min-width: 0;
        }
        .masheikh-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--brand);
        }
        .masheikh-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 15px;
            color: #fff;
            background: var(--gold);
            border: 2px solid var(--surface);
        }
        .masheikh-info { min-width: 0; flex: 1 1 0; }
        .masheikh-name {
            font-size: 13.5px; font-weight: 700; color: var(--text);
            line-height: 1.3;
            overflow-wrap: anywhere; word-break: break-word;
        }

        /* ============ STEPS ============ */
        .steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .step {
            text-align: center;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 18px 14px;
        }
        .step .num-badge {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--brand); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800;
            margin: 0 auto 10px;
        }
        .step h4 { font-size: 14.5px; font-weight: 800; margin-bottom: 4px; }
        .step p { font-size: 12.5px; color: var(--muted); }

        /* ============ CTA ============ */
        .cta-banner {
            border-radius: var(--radius-md);
            padding: 32px 24px;
            text-align: center;
            color: #fff;
            background:
                linear-gradient(rgba(10,40,30,.78), rgba(10,40,30,.9)),
                url('/uploads/subjects-page-hero.png') center/cover no-repeat,
                var(--brand);
        }
        .cta-banner h2 { font-size: clamp(19px, 3vw, 23px); font-weight: 800; margin-bottom: 8px; }
        .cta-banner p { opacity: .92; margin-bottom: 16px; font-size: 14px; }

        /* ============ FOOTER ============ */
        .site-footer { padding: 8px 0 28px; text-align: center; color: var(--muted); font-size: 12.5px; }
        .footer-meta {
            text-align: center;
            font-size: 12px;
            padding: 14px;
            margin: 0 0 12px;
            font-weight: 700;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
        }
        .footer-meta .brand-text { color: var(--brand); font-weight: 800; }
        .footer-meta .gold-text  { color: var(--gold); font-weight: 800; }

        .theme-toggle-fab {
            position: fixed; bottom: 20px; right: 20px;
            width: 44px; height: 44px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--brand);
            font-size: 20px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            box-shadow: var(--shadow-lg);
            z-index: 999;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 900px) {
            .nav-links { display: none; }
            .nav-login-btn { display: none; }
            .mobile-nav-toggle { display: block; }
            .hero { grid-template-columns: 1fr; padding: 22px 18px; gap: 18px; }
            .hero h1 { font-size: 26px; }
            .hero p.lead { font-size: 14px; margin-bottom: 16px; }
            .hero-logo { height: 30px; width: 30px; }
            .academy { grid-template-columns: 1fr; padding: 20px 16px; gap: 16px; }
            .academy h2 { font-size: 22px; }
            .books-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .features-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .steps { grid-template-columns: 1fr; gap: 10px; }
            .section { padding: 26px 0; }
            .section-head { margin-bottom: 16px; }
            .section-head h2 { font-size: 21px; }
        }
        @media (max-width: 760px) {
            .hero-visual { display: none; }
            .stats-bar { display: flex; flex-wrap: wrap; justify-content: center; padding: 12px 8px; row-gap: 10px; }
            .stat-item { flex: 0 0 33.33%; }
            .stat-item .num { font-size: 21px; }
        }
        @media (max-width: 480px) {
            .wrap { padding: 0 12px; }
            .site-nav { padding: 8px 12px; }
            .hero-wrap { padding-top: 10px; }
            .academy-chips { grid-template-columns: 1fr; }
            .features-grid { grid-template-columns: 1fr; }
            .masheikh-grid { grid-template-columns: 1fr; }
            .book-cover { height: 96px; }
            .btn { padding: 9px 16px; }
            .nav-actions .btn-primary { padding: 8px 14px; font-size: 12.5px; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }
    </style>
    <?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<nav class="site-nav">
    <div class="wrap">
        <a href="index.php" class="brand">
            <img src="includes/logo.png" alt="MyIslam" onerror="this.style.display='none'">
            <span>MyIslam</span>
        </a>

        <div class="nav-links">
            <a href="#academy">Akademia</a>
            <a href="#books">Vitabu</a>
            <a href="#learning">Kujifunza</a>
            <a href="#masheikh">Masheikh</a>
            <a href="#how-it-works">Inavyofanya Kazi</a>
        </div>

        <div class="nav-actions">
            <a href="?lang=<?= $lang === 'sw' ? 'en' : 'sw' ?>"
               class="lang-toggle-btn"
               aria-label="Switch language">
                🌐 <?= $lang === 'sw' ? 'EN' : 'SW' ?>
            </a>
            <button type="button" class="theme-toggle-btn" id="themeToggle"
                    aria-label="Badilisha mandhari">🌙</button>
            <a href="login.php" class="btn btn-outline nav-login-btn">Ingia</a>
            <a href="register.php" class="btn btn-primary">Anza Sasa</a>
            <button type="button" class="mobile-nav-toggle" id="mobileNavToggle"
                    aria-label="Fungua menyu" aria-expanded="false" aria-controls="mobileNavMenu">☰</button>
        </div>
    </div>
    <div class="mobile-nav-menu" id="mobileNavMenu">
        <a href="#academy">Akademia</a>
        <a href="#books">Vitabu</a>
        <a href="#learning">Kujifunza</a>
        <a href="#masheikh">Masheikh</a>
        <a href="#how-it-works">Inavyofanya Kazi</a>
        <a href="login.php">Ingia</a>
    </div>
</nav>

<header class="hero-wrap">
    <div class="wrap">
        <section class="hero">
            <div class="hero-text">
                <div class="hero-brand">
                    <img src="includes/logo.png" alt="" class="hero-logo"
                         onerror="this.style.display='none'">
                    <div class="hero-brand-text">
                        <strong>MyIslam</strong>
                        <span>🎓 Academy &amp; Library</span>
                    </div>
                </div>
                <div class="hero-tag">🎓 Akademia &amp; Maktaba ya Bure</div>
                <h1>Jifunze <span class="accent">Misingi</span> ya Uislamu, kwa Ufaham Wa Wema Waliotangulia(Salaf)</h1>
                <p class="lead">Masomo yaliyopangwa kwa Tafsiri Ya Lugha Ya Kiswahili, maswali na Mitihani mbalimbali — bure, na yanapatikana hata bila mtandao.</p>
                <div class="hero-actions">
                    <a href="register.php" class="btn btn-gold">Anza Kujifunza Bure</a>
                    <a href="login.php" class="btn btn-light">Nina Akaunti Tayari</a>
                </div>
            </div>
            <div class="hero-visual">
                <div class="laptop-mockup">
                    <div class="laptop-screen">
                        <div class="laptop-browser-bar">
                            <span class="dot dot-red"></span><span class="dot dot-yellow"></span><span class="dot dot-green"></span>
                        </div>
                        <div class="laptop-screen-inner">
                            <?php
                                $heroPreviewSubjects = array_slice($featuredSubjects, 0, 3);
                                $previewGradients = [
                                    'linear-gradient(160deg, #14532d, #0f1720)',
                                    'linear-gradient(160deg, #7c2d12, #1c0f0a)',
                                    'linear-gradient(160deg, #1e3a5f, #0f1720)',
                                ];
                            ?>
                            <?php if (!empty($heroPreviewSubjects)): ?>
                                <?php foreach ($heroPreviewSubjects as $i => $subj): ?>
                                    <div class="mini-subject-card" style="background:<?php echo $previewGradients[$i % count($previewGradients)]; ?>;">
                                        <div class="mini-card-top">
                                            <span class="mini-card-icon"><?php echo htmlspecialchars($subj['icon'] ?? '📚'); ?></span>
                                            <span class="mini-card-pct">0%</span>
                                        </div>
                                        <div class="mini-card-title"><?php echo htmlspecialchars($subj['name'] ?? ''); ?></div>
                                        <div class="mini-card-meta"><?php echo (int)($subj['total_contents'] ?? 0); ?> masomo · <?php echo (int)($subj['total_categories'] ?? 0); ?> makundi</div>
                                        <div class="mini-card-progress"><div class="mini-card-progress-fill" style="width:<?php echo 8 + ($i * 14); ?>%;"></div></div>
                                        <div class="mini-card-btn">Fungua Somo</div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach (['Al-Uswuwl Ath-Thalaathah', 'Al-Qawaaid Al-Arba', 'Kitaab At-Tawhiyd'] as $i => $name): ?>
                                    <div class="mini-subject-card" style="background:<?php echo $previewGradients[$i % count($previewGradients)]; ?>;">
                                        <div class="mini-card-top"><span class="mini-card-icon">📚</span><span class="mini-card-pct">0%</span></div>
                                        <div class="mini-card-title"><?php echo htmlspecialchars($name); ?></div>
                                        <div class="mini-card-meta">Masomo · Makundi</div>
                                        <div class="mini-card-progress"><div class="mini-card-progress-fill" style="width:<?php echo 8 + ($i * 14); ?>%;"></div></div>
                                        <div class="mini-card-btn">Fungua Somo</div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="laptop-base"><div class="laptop-notch"></div></div>
                </div>
            </div>
        </section>
    </div>
</header>

<div class="wrap">
    <div class="stats-bar">
        <div class="stat-item">
            <div class="num"><?php echo number_format($stats['books']); ?></div>
            <div class="label">Vitabu</div>
        </div>
        <div class="stat-item">
            <div class="num"><?php echo number_format($stats['lessons']); ?></div>
            <div class="label">Masomo</div>
        </div>
        <div class="stat-item">
            <div class="num"><?php echo number_format($stats['quizzes']); ?></div>
            <div class="label">Maswali</div>
        </div>
        <div class="stat-item">
            <div class="num"><?php echo number_format($stats['exams']); ?></div>
            <div class="label">Mitihani</div>
        </div>
        <div class="stat-item">
            <div class="num"><?php echo number_format($stats['students']); ?></div>
            <div class="label">Wanafunzi</div>
        </div>
    </div>
</div>

<section class="section" id="academy" style="padding-bottom:8px;">
    <div class="wrap">
        <div class="academy">
            <div>
                <div class="tag">🎓 Kozi zenye Mpangilio</div>
                <h2>Akademia ya MyIslam</h2>
                <p class="lead">Jiunge na kozi zenye mpangilio kamili: ratiba ya kila wiki, maswali ya wiki, mitihani ya kila mwezi na mtihani wa mwisho wa muhula, ripoti ya ufaulu na vyeti.<?php if ($stats['courses'] > 0): ?> Kozi <?php echo number_format($stats['courses']); ?> zinapatikana sasa.<?php endif; ?></p>
                <div class="academy-chips">
                    <span>🗓️ Ratiba ya kila wiki</span>
                    <span>✍️ Maswali ya wiki</span>
                    <span>🎓 Mitihani ya mwezi na muhula</span>
                    <span>🏅 Ripoti ya ufaulu na vyeti</span>
                </div>
                <a href="register.php" class="btn btn-gold">Jiunge na Akademia</a>
            </div>
            <div class="track-cards">
                <div class="track-card">
                    <h3>📚 Kujifunza Mwenyewe</h3>
                    <p>Soma vitabu na masomo kwa kasi yako, fanya maswali ya somo, na upate cheti cha kitabu ukimaliza.</p>
                </div>
                <div class="track-card academy-track">
                    <h3>🎓 Akademia</h3>
                    <p>Fuata kozi yenye ratiba, fanya maswali ya wiki na mitihani, ufaulu kila somo ili upate cheti cha kozi.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="books">
    <div class="wrap">
        <div class="section-head">
            <div class="eyebrow">Maktaba Yetu</div>
            <h2>Vitabu Vinavyo Fundishwa</h2>
            <p>Nakala halisi kutoka maktaba yetu</p>
        </div>

        <?php if (!empty($featuredSubjects)): ?>
            <div class="books-grid">
                <?php foreach ($featuredSubjects as $subject): ?>
                    <?php
                        $subjectSlug = (string)($subject['slug'] ?? '');
                        $subjectId = (int)($subject['id'] ?? 0);
                        $targetPage = $subjectSlug !== ''
                            ? 'subject.php?slug=' . urlencode($subjectSlug)
                            : 'subject.php?id=' . $subjectId;
                        $loginRedirectUrl = 'login.php?redirect=' . urlencode($targetPage);
                        $coverImage = $subject['image_path'] ?? null;
                        $icon = $subject['icon'] ?? '📚';
                        $description = trim((string)($subject['description'] ?? ''));
                    ?>
                    <a href="<?php echo htmlspecialchars($loginRedirectUrl); ?>" class="book-card">
                        <div class="book-cover"<?php if ($coverImage): ?> style="background-image:url('<?php echo htmlspecialchars($coverImage); ?>');"<?php endif; ?>>
                            <?php if (!$coverImage): ?><?php echo htmlspecialchars($icon); ?><?php endif; ?>
                        </div>
                        <div class="book-body">
                            <div class="book-title"><?php echo htmlspecialchars($subject['name'] ?? 'Bila jina'); ?></div>
                            <?php if ($description !== ''): ?>
                                <div class="book-desc"><?php echo htmlspecialchars($description); ?></div>
                            <?php endif; ?>
                            <div class="book-meta"><?php echo (int)($subject['total_contents'] ?? 0); ?> masomo · <?php echo (int)($subject['total_categories'] ?? 0); ?> sehemu</div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-note">Vitabu vitaonekana hapa mara baada ya kuchapishwa.</p>
        <?php endif; ?>
    </div>
</section>

<section class="section" id="learning" style="background:var(--surface-2);">
    <div class="wrap">
        <div class="section-head">
            <div class="eyebrow">Njia za Kujifunza</div>
            <h2>Zaidi ya Kusoma Tu</h2>
            <p>Kila kitabu kinakuja na duruus mbalimbali za masheikh wa ahli sunnah(salafiyun).</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="icon-wrap">📖</div>
                <h3>Masomo Kwa Mpangilio Mzuri</h3>
                <p>Utakao mwezesha mwanafunzi kusoma hatua kwa hatua na vizuri</p>
                <div class="count"><?php echo number_format($stats['lessons']); ?> yanapatikana</div>
            </div>
            <div class="feature-card">
                <div class="icon-wrap">✍️</div>
                <h3>Maswali ya somo husika</h3>
                <p>Jaribu ufahamu wako baada ya kila somo</p>
                <div class="count"><?php echo number_format($selfQuizCount); ?> yanapatikana</div>
            </div>
            <div class="feature-card">
                <div class="icon-wrap">🏆</div>
                <h3>Maswali ya Wiki</h3>
                <p>Mitihan rasmi ya Wiki</p>
                <div class="count"><?php echo number_format($competitionQuizCount); ?> yanapatikana</div>
            </div>
            <div class="feature-card">
                <div class="icon-wrap">🎓</div>
                <h3>Mitihani ya Kila Mwezi</h3>
                <p>Mitihani rasmi Ya Mwezi</p>
                <div class="count"><?php echo number_format($stats['exams']); ?> yanapatikana</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="offline-banner">
            <div class="icon">📥</div>
            <div>
                <h3>Jifunze Popote Ulipo — Hata Bila Bando</h3>
                <p>Masomo uliyofungua huhifadhiwa moja kwa moja kwa kusoma bila Bando, hivyo huhitaji kila wakati kusoma duruus zetu</p>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($masheikh)): ?>
<section class="section" id="masheikh">
    <div class="wrap">
        <div class="section-head">
            <div class="eyebrow">Walimu Wetu</div>
            <h2>Masheikh Zetu</h2>
        </div>
        <div class="masheikh-grid">
            <?php foreach ($masheikh as $name): ?>
                <div class="masheikh-card">
                    <div class="masheikh-avatar"><?php echo htmlspecialchars(initials((string)$name)); ?></div>
                    <div class="masheikh-info">
                        <div class="masheikh-name"><?php echo htmlspecialchars((string)$name); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section" id="how-it-works">
    <div class="wrap">
        <div class="section-head">
            <div class="eyebrow">Kuanza</div>
            <h2>Inavyofanya Kazi</h2>
        </div>
        <div class="steps">
            <div class="step">
                <div class="num-badge">1</div>
                <h4>Fungua Akaunti Bure</h4>
                <p>Jisajili kwa chini ya dakika moja</p>
            </div>
            <div class="step">
                <div class="num-badge">2</div>
                <h4>Chagua Kitabu</h4>
                <p>Chagua kutoka maktaba yetu</p>
            </div>
            <div class="step">
                <div class="num-badge">3</div>
                <h4>Fanya Maswali Na Mitihan</h4>
                <p>Fanya maswali na mitihan mbalimbali</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="cta-banner">
            <h2>Uko Tayari Kuanza Kujifunza?</h2>
            <p>Jiunge na wanafunzi <?php echo number_format($stats['students']); ?> wanaojifunza tayari kwenye MyIslam.</p>
            <a href="register.php" class="btn btn-primary">Jisajili Sasa</a>
        </div>
    </div>
</section>

<footer class="site-footer">
    <div class="footer-meta">
        <div class="brand-text">MyIslam</div>
        <div class="gold-text">#Manhaj Salaf</div>
    </div>
    <div class="wrap">
        &copy; <?php echo date('Y'); ?> MyIslam. Haki zote zimehifadhiwa.
    </div>
</footer>

<button type="button" class="theme-toggle-fab" id="themeToggleFab" aria-hidden="true" style="display:none;">🌙</button>

<script>
    (function() {
        const body = document.body;
        const toggles = [document.getElementById('themeToggle'), document.getElementById('themeToggleFab')].filter(Boolean);
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            toggles.forEach(t => t.textContent = '☀');
        }
        toggles.forEach(toggle => {
            toggle.addEventListener('click', () => {
                body.classList.toggle('dark-mode');
                const isDark = body.classList.contains('dark-mode');
                toggles.forEach(t => t.textContent = isDark ? '☀' : '🌙');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            });
        });
    })();

    (function() {
        const menuToggle = document.getElementById('mobileNavToggle');
        const menu = document.getElementById('mobileNavMenu');
        if (!menuToggle || !menu) return;

        function closeMenu() {
            menu.classList.remove('open');
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.textContent = '☰';
        }
        function openMenu() {
            menu.classList.add('open');
            menuToggle.setAttribute('aria-expanded', 'true');
            menuToggle.textContent = '✕';
        }

        menuToggle.addEventListener('click', () => {
            menu.classList.contains('open') ? closeMenu() : openMenu();
        });

        menu.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', closeMenu);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeMenu();
        });
    })();
</script>

</body>
</html>