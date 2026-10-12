<?php
declare(strict_types=1);
session_start();

// ---------- Error reporting: log only, don't leak to the page ----------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';

// ---------- Already logged in? Go straight to the dashboard ----------
if (isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit;
}

// ============================================================================
// SMALL HELPERS (this file is standalone - it can't rely on helpers defined
// in user_dashboard.php, since nobody's logged in and that file never loads)
// ============================================================================

if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'U';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $letters = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : 'U';
    }
}

// ============================================================================
// REAL DATA FOR THE LANDING PAGE - same tables/columns the logged-in app
// uses (subjects.php, content.php, quizzes.php), just without any
// per-user progress since nobody's logged in yet.
// ============================================================================
$conn = db();

$stats = [
    'books' => 0,
    'lessons' => 0,
    'quizzes' => 0,
    'exams' => 0,
    'students' => 0,
];
$featuredSubjects = [];

if ($conn instanceof PDO) {
    try {
        $stats['books'] = (int)$conn->query("SELECT COUNT(*) FROM subjects WHERE is_active = 1")->fetchColumn();
        $stats['lessons'] = (int)$conn->query("SELECT COUNT(*) FROM contents WHERE status = 'published'")->fetchColumn();
        $stats['quizzes'] = (int)$conn->query("SELECT COUNT(*) FROM quizzes WHERE status = 'published'")->fetchColumn();
        $certExamCount = (int)$conn->query("SELECT COUNT(*) FROM certification_exams WHERE status = 'published'")->fetchColumn();
        $monthlyExamCount = (int)$conn->query("SELECT COUNT(*) FROM monthly_exams WHERE status = 'published'")->fetchColumn();
        $stats['exams'] = $certExamCount + $monthlyExamCount;
        $stats['students'] = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND is_active = 1")->fetchColumn();

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
        // Leave stats/subjects as safe empty defaults - the page still
        // renders fine, just without live numbers/cards.
    }
}

// mode counts for the "ways to learn" section
$selfQuizCount = 0;
$competitionQuizCount = 0;
if ($conn instanceof PDO) {
    try {
        $selfQuizCount = (int)$conn->query("SELECT COUNT(*) FROM quizzes WHERE status = 'published' AND mode = 'self'")->fetchColumn();
        $competitionQuizCount = (int)$conn->query("SELECT COUNT(*) FROM quizzes WHERE status = 'published' AND mode = 'competition'")->fetchColumn();
    } catch (PDOException $ex) {
        error_log('index.php mode counts error: ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Fetch sheikh names for the Masheikh Zetu section.
// GROUP BY sheikh_name dedupes in case multiple subjects share the same sheikh.
// FETCH_COLUMN returns a flat array of strings, not rows.
// ---------------------------------------------------------------------------
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
        // Fail silently - the section just won't render.
    }
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyIslam - Jifunze Misingi ya Uislamu</title>
    <meta name="description" content="Jukwaa la bure la kujifunza Uislamu - masomo ya mwingiliano, maswali ya kujipima na ya ushindani, na mitihani ya vyeti. Inapatikana hata bila mtandao.">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --bg:#f5f7f5; --surface:#ffffff; --surface-2:#f9fafb; --line:#e5e7eb;
            --text:#1f2937; --text-secondary:#4b5563; --muted:#6b7280;
            --brand:#166534; --brand-dark:#14532d; --brand-soft:#e7f3eb;
            --gold:#9C7A2E; --gold-soft:rgba(156,122,46,0.10);
            --danger:#dc2626; --shadow-lg:0 20px 40px rgba(0,0,0,0.12);
            --radius-lg:20px; --radius-md:16px; --radius-sm:10px;
        }
        body.dark-mode {
            --bg:#0f1720; --surface:#18212b; --surface-2:#1d2833; --line:#304150;
            --text:#edf2f7; --text-secondary:#cbd5e1; --muted:#94a3b8;
            --brand:#7dd3a7; --brand-dark:#6bb590; --brand-soft:rgba(125,211,167,0.12);
            --gold:#C9A85E; --gold-soft:rgba(201,168,94,0.12);
        }
        body {
            font-family:system-ui,-apple-system,sans-serif; background:var(--bg); color:var(--text);
            min-height:100vh; line-height:1.6;
        }
        body::before {
            content:""; position:fixed; top:0; left:0; width:100%; height:100%;
            pointer-events:none; z-index:-1;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%23166534' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            background-repeat:repeat;
        }
        a { color:inherit; }
        .wrap { max-width:1180px; margin:0 auto; padding:0 24px; }

        /* ---------- Nav ---------- */
        .site-nav {
            display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between;
            padding:16px 24px; background:var(--surface); border-bottom:1px solid var(--line);
            position:sticky; top:0; z-index:100;
        }
        .site-nav .wrap { display:flex; align-items:center; justify-content:space-between; width:100%; padding:0; }
        .brand { display:flex; align-items:center; gap:10px; text-decoration:none; }
        .brand img { height:40px; width:40px; border-radius:50%; object-fit:cover; }
        .brand span { font-size:20px; font-weight:800; color:var(--brand); }
        .nav-links { display:flex; align-items:center; gap:28px; }
        .nav-links a { text-decoration:none; color:var(--text-secondary); font-weight:600; font-size:14px; }
        .nav-links a:hover { color:var(--brand); }
        .nav-actions { display:flex; align-items:center; gap:10px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:10px 20px; border-radius:12px; font-weight:700; font-size:14px; text-decoration:none; cursor:pointer; border:none; }
        .btn-primary { background:var(--brand); color:#fff; box-shadow:0 4px 12px rgba(22,101,52,0.2); }
        .btn-primary:hover { background:var(--brand-dark); }
        .btn-outline { background:transparent; color:var(--brand); border:1px solid var(--brand); }
        .btn-outline:hover { background:var(--brand-soft); }
        .theme-toggle-btn {
            width:38px; height:38px; border-radius:50%; background:var(--surface-2);
            border:1px solid var(--line); font-size:16px; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
        }
        .mobile-nav-toggle { display:none; background:none; border:none; font-size:24px; color:var(--brand); cursor:pointer; }
        .mobile-nav-menu {
            display:none; flex-direction:column; background:var(--surface); border-top:1px solid var(--line);
            padding:8px 24px 16px; width:100%; flex-basis:100%;
        }
        .mobile-nav-menu.open { display:flex; }
        .mobile-nav-menu a {
            padding:12px 0; text-decoration:none; color:var(--text); font-weight:600; font-size:15px;
            border-bottom:1px solid var(--line);
        }
        .mobile-nav-menu a:last-child { border-bottom:none; }

        /* ---------- Hero ---------- */
        .hero { padding:64px 0 40px; }
        .hero-grid { display:grid; grid-template-columns:1.1fr 0.9fr; gap:40px; align-items:center; }
        .hero-logo { height:300px; width:300px; border-radius:50%; object-fit:cover; margin-bottom:20px; box-shadow:0 4px 14px rgba(0,0,0,0.15); }
        .hero h1 { font-size:44px; line-height:1.15; font-weight:800; color:var(--text); margin-bottom:16px; }
        .hero h1 .accent { color:var(--gold); }
        .hero p.lead { font-size:17px; color:var(--text-secondary); margin-bottom:28px; max-width:520px; }
        .hero-actions { display:flex; gap:14px; flex-wrap:wrap; }
        .hero-visual { display:flex; align-items:center; justify-content:center; }

        /* ---------- Laptop mockup (hero visual) ---------- */
        .laptop-mockup { width:100%; max-width:460px; }
        .laptop-screen {
            background:#1a1f26; border-radius:14px 14px 4px 4px; padding:8px 8px 0;
            box-shadow:var(--shadow-lg); border:1px solid #2a3038;
        }
        .laptop-browser-bar { display:flex; align-items:center; gap:6px; padding:6px 8px 8px; }
        .laptop-browser-bar .dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
        .dot-red { background:#ff5f57; } .dot-yellow { background:#febc2e; } .dot-green { background:#28c840; }
        .laptop-url {
            margin-left:8px; background:#2a3038; color:#9aa4b2; font-size:10px; padding:3px 10px;
            border-radius:6px; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        }
        .laptop-screen-inner {
            background:#0f1720; border-radius:4px 4px 0 0; padding:14px; display:flex; gap:10px;
            overflow:hidden;
        }
        .mini-subject-card {
            flex:1; min-width:0; border-radius:10px; padding:12px 10px; color:#fff;
            display:flex; flex-direction:column; gap:6px; box-shadow:inset 0 0 0 1px rgba(255,255,255,0.08);
        }
        .mini-card-top { display:flex; align-items:center; justify-content:space-between; font-size:14px; }
        .mini-card-pct { font-size:10px; background:rgba(255,255,255,0.15); padding:1px 6px; border-radius:20px; }
        .mini-card-title {
            font-size:11px; font-weight:700; direction:rtl; line-height:1.3; height:2.6em; overflow:hidden;
        }
        .mini-card-meta { font-size:8.5px; opacity:0.75; }
        .mini-card-progress { height:4px; background:rgba(255,255,255,0.15); border-radius:4px; overflow:hidden; }
        .mini-card-progress-fill { height:100%; background:#C9A85E; }
        .mini-card-btn {
            margin-top:2px; background:#166534; color:#fff; font-size:8.5px; font-weight:700;
            text-align:center; padding:5px 4px; border-radius:6px;
        }
        .laptop-base {
            height:14px; background:linear-gradient(to bottom, #d8dadd, #b7bcc2); border-radius:0 0 10px 10px;
            position:relative; margin:0 -6px;
        }
        .laptop-notch {
            position:absolute; top:0; left:50%; transform:translateX(-50%);
            width:70px; height:5px; background:#9aa0a6; border-radius:0 0 6px 6px;
        }

        /* ---------- Stats bar ---------- */
        .stats-bar {
            background:var(--surface); border:1px solid var(--line); border-radius:var(--radius-lg);
            margin-top:8px; padding:28px 24px; display:grid; grid-template-columns:repeat(5, 1fr); gap:16px;
            box-shadow:0 8px 24px rgba(0,0,0,0.04);
        }
        .stat-item { text-align:center; }
        .stat-item .num { font-size:28px; font-weight:800; color:var(--brand); }
        .stat-item .label { font-size:13px; color:var(--muted); margin-top:2px; }

        /* ---------- Section headings ---------- */
        .section { padding:56px 0; }
        .section-head { text-align:center; max-width:620px; margin:0 auto 36px; }
        .section-head .eyebrow { color:var(--gold); font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.06em; }
        .section-head h2 { font-size:30px; font-weight:800; margin:8px 0 10px; }
        .section-head p { color:var(--text-secondary); font-size:15px; }

        /* ---------- Books grid ---------- */
        .books-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:20px; }
        .book-card {
            background:var(--surface); border:1px solid var(--line); border-radius:var(--radius-md);
            overflow:hidden; text-decoration:none; color:var(--text); transition:transform 0.15s ease, box-shadow 0.15s ease;
            display:flex; flex-direction:column;
        }
        .book-card:hover { transform:translateY(-4px); box-shadow:0 12px 24px rgba(0,0,0,0.1); }
        .book-cover {
            height:140px; background-size:cover; background-position:center;
            background-color:var(--brand-soft); display:flex; align-items:center; justify-content:center; font-size:40px;
        }
        .book-body { padding:16px; flex:1; display:flex; flex-direction:column; gap:8px; }
        .book-title { font-size:16px; font-weight:700; direction:rtl; text-align:right; }
        .book-desc { font-size:12.5px; color:var(--muted); flex:1; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .book-meta { font-size:11.5px; color:var(--brand); font-weight:600; }
        .empty-note { text-align:center; color:var(--muted); padding:20px; }

        /* ---------- Feature cards ---------- */
        .features-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:20px; }
        .feature-card {
            background:var(--surface); border:1px solid var(--line); border-radius:var(--radius-md);
            padding:24px 20px; text-align:center;
        }
        .feature-card .icon-wrap {
            width:56px; height:56px; border-radius:16px; background:var(--brand-soft); color:var(--brand);
            display:flex; align-items:center; justify-content:center; font-size:26px; margin:0 auto 14px;
        }
        .feature-card h3 { font-size:16px; font-weight:700; margin-bottom:6px; }
        .feature-card p { font-size:13px; color:var(--muted); margin-bottom:10px; }
        .feature-card .count { font-size:13px; font-weight:700; color:var(--gold); }

        /* ---------- Offline callout ---------- */
        .offline-banner {
            background:var(--brand-soft); border:1px solid var(--brand); border-radius:var(--radius-lg);
            padding:28px 32px; display:flex; align-items:center; gap:20px; flex-wrap:wrap;
        }
        .offline-banner .icon { font-size:36px; }
        .offline-banner h3 { font-size:18px; font-weight:800; color:var(--brand); margin-bottom:4px; }
        .offline-banner p { font-size:14px; color:var(--text-secondary); }

        /* ---------- Masheikh ---------- */
        .masheikh-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(min(220px, 100%), 1fr));
            gap:16px;
        }
        .masheikh-card {
            display:flex;
            align-items:center;
            gap:14px;
            padding:16px;
            background:var(--surface);
            border:1px solid var(--line);
            border-radius:var(--radius-md);
            transition:transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
            min-width:0;
        }
        .masheikh-card:hover {
            transform:translateY(-3px);
            box-shadow:0 10px 20px rgba(0,0,0,0.08);
            border-color:var(--brand);
        }
        .masheikh-avatar {
            width:48px;
            height:48px;
            border-radius:50%;
            flex-shrink:0;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:800;
            font-size:16px;
            color:#fff;
            background:var(--gold);
            border:2px solid var(--surface);
            box-shadow:0 2px 6px rgba(0,0,0,0.08);
        }
        .masheikh-info {
            min-width:0;
            flex:1 1 0;
        }
        .masheikh-name {
            font-size:14px;
            font-weight:700;
            color:var(--text);
            line-height:1.3;
            white-space:normal;
            overflow-wrap:anywhere;
            word-break:break-word;
        }
       

        /* ---------- How it works ---------- */
        .steps { display:grid; grid-template-columns:repeat(3, 1fr); gap:24px; }
        .step { text-align:center; }
        .step .num-badge {
            width:40px; height:40px; border-radius:50%; background:var(--brand); color:#fff;
            display:flex; align-items:center; justify-content:center; font-weight:800; margin:0 auto 14px;
        }
        .step h4 { font-size:15px; font-weight:700; margin-bottom:6px; }
        .step p { font-size:13px; color:var(--muted); }

        /* ---------- CTA ---------- */
        .cta-banner {
            background:linear-gradient(135deg, var(--brand), var(--brand-dark)); border-radius:var(--radius-lg);
            padding:44px 32px; text-align:center; color:#fff;
        }
        .cta-banner h2 { font-size:26px; font-weight:800; margin-bottom:10px; }
        .cta-banner p { opacity:0.9; margin-bottom:22px; }
        .cta-banner .btn-primary { background:#fff; color:var(--brand); }
        .cta-banner .btn-primary:hover { background:#f0f0f0; }

        /* ---------- Footer ---------- */
        .site-footer { border-top:1px solid var(--line); padding:28px 0; text-align:center; color:var(--muted); font-size:13px; }

        .theme-toggle-fab {
            position:fixed; bottom:20px; right:20px; width:44px; height:44px; border-radius:50%;
            background:var(--surface); border:1px solid var(--brand); font-size:20px; cursor:pointer;
            display:flex; align-items:center; justify-content:center; box-shadow:var(--shadow-lg); z-index:999;
        }

        @media (max-width:900px) {
            .nav-links { display:none; }
            .mobile-nav-toggle { display:block; }
            .nav-login-btn { display:none; } /* already in the mobile dropdown menu */
            .hero-grid { grid-template-columns:1fr; }
            .stats-bar { grid-template-columns:repeat(2, 1fr); }
            .books-grid { grid-template-columns:repeat(2, 1fr); }
            .features-grid { grid-template-columns:repeat(2, 1fr); }
            .steps { grid-template-columns:1fr; }
            .hero h1 { font-size:32px; }
            .laptop-mockup { max-width:100%; }
        }
        @media (max-width:480px) {
            .stats-bar { grid-template-columns:repeat(2, 1fr); }
            .books-grid { grid-template-columns:1fr 1fr; }
            .features-grid { grid-template-columns:1fr; }
            .masheikh-grid { grid-template-columns:1fr; }
        }
        @media (prefers-reduced-motion: reduce) { * { animation:none !important; transition:none !important; } }
        .footer-meta {
    text-align: center; font-size: 12px; padding: 20px; margin-top: 20px;
    border-top: 1px solid var(--line); font-weight: 700;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
}
.footer-meta .brand-text {
    color: var(--brand);
    font-weight: 700;
}
.footer-meta .gold-text {
    color: var(--gold);
    font-weight: 700;
}
    </style>
    <?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<nav class="site-nav">
    <div class="wrap">
        <a href="index.php" class="brand">
            <img src="includes/logo.png" alt="MyIslam">
            <span>MyIslam</span>
        </a>
        <div class="nav-links">
            <a href="#books">Vitabu</a>
            <a href="#learning">Kujifunza</a>
            <a href="#masheikh">Masheikh</a>
            <a href="#how-it-works">Inavyofanya Kazi</a>
        </div>
        <div class="nav-actions">
            <button type="button" class="theme-toggle-btn" id="themeToggle" aria-label="Badilisha mandhari">🌙</button>
            <a href="login.php" class="btn btn-outline nav-login-btn">Ingia</a>
            <a href="register.php" class="btn btn-primary">Anza Sasa</a>
            <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Fungua menyu" aria-expanded="false" aria-controls="mobileNavMenu">☰</button>
        </div>
    </div>
    <div class="mobile-nav-menu" id="mobileNavMenu">
        <a href="#books">Vitabu</a>
        <a href="#learning">Kujifunza</a>
        <a href="#masheikh">Masheikh</a>
        <a href="#how-it-works">Inavyofanya Kazi</a>
        <a href="login.php">Ingia</a>
    </div>
</nav>

<header class="hero">
    <div class="wrap">
        <div class="hero-grid">
            <div style="display:flex; justify-content:center; align-items:center;">
                                <img src="includes/logo.png" alt="MyIslam" class="hero-logo">

            </div>
            <div>
                <h1>Jifunze <span class="accent">Misingi</span> ya Uislamu, kwa Ufaham Wa Wema Waliotangulia(Salaf)</h1>
                <p class="lead">Masomo yaliyopangwa kwa Tafsiri Ya Lugha Ya Kiswahili, maswali na Mitihani mbalimbali — bure, na yanapatikana hata bila mtandao.</p>
                <div class="hero-actions">
                    <a href="register.php" class="btn btn-primary">Anza Kujifunza Bure</a>
                    <a href="login.php" class="btn btn-outline">Nina Akaunti Tayari</a>
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
        </div>
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
                <h3>Jifunze Popote  Ulipo - Hata Bila Bando</h3>
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
                <p>Jisajili kwa chini ya dakika moja </p>
            </div>
            <div class="step">
                <div class="num-badge">2</div>
                <h4>Chagua Kitabu</h4>
                <p>Chagua kutoka maktaba yetu </p>
            </div>
            <div class="step">
                <div class="num-badge">3</div>
                <h4>Fanya Maswali Na Mitihan </h4>
                <p>Fanya maswali na mitihan mbalimbali </p>
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

        // Tapping any link in the menu (including in-page anchors) closes it.
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