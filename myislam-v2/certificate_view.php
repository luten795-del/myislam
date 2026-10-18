<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/includes/translations.php';
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/certificate_functions.php';

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Fetch exact categories for a specific subject from the real database schema:
 * categories(id, subject_id, name, slug, description, icon, is_active, sort_order)
 */
function getSubjectCategories(PDO $conn, int $subjectId, int $limit = 18): array {
    if ($subjectId <= 0) {
        return [];
    }

    try {
        $stmt = $conn->prepare("
            SELECT name
            FROM categories
            WHERE subject_id = ?
              AND is_active = 1
            ORDER BY sort_order ASC, name ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$subjectId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $categories = [];

        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $categories[] = $name;
            }
        }

        return array_values(array_unique($categories));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Fetch grouped categories for a general certificate.
 */
function getGeneralCategoryGroups(PDO $conn, int $limitPerSubject = 3, int $maxSubjects = 8): array {
    try {
        $stmt = $conn->query("
            SELECT id, name
            FROM subjects
            WHERE is_active = 1
            ORDER BY sort_order ASC, name ASC
            LIMIT {$maxSubjects}
        ");
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $groups = [];

    foreach ($subjects as $subject) {
        $subjectId = (int)($subject['id'] ?? 0);
        $subjectName = trim((string)($subject['name'] ?? 'Subject'));

        if ($subjectId <= 0) {
            continue;
        }

        $categories = getSubjectCategories($conn, $subjectId, $limitPerSubject);

        if (!empty($categories)) {
            $groups[] = [
                'subject_name' => $subjectName,
                'categories' => $categories,
            ];
        }
    }

    return $groups;
}

$conn = db();

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
$certificate = null;

if ($code !== '') {
    $certificate = getCertificateByCode($conn, $code);
}

if (!$certificate) {
    http_response_code(404);
}

$isActive = $certificate && ($certificate['status'] ?? '') === 'active';

$studentName = strtoupper(trim((string)($certificate['full_name'] ?? 'Unknown Student')));
$certType = (string)($certificate['certification_type'] ?? '');
$subjectName = (string)($certificate['subject_name'] ?? '');
$title = (string)($certificate['title'] ?? 'Certificate of Achievement');
$summary = (string)($certificate['achievement_summary'] ?? '');

$subjectId = !empty($certificate['subject_id']) ? (int)$certificate['subject_id'] : 0;

$issuedAt = !empty($certificate['issued_at'])
    ? date('F d, Y', strtotime((string)$certificate['issued_at']))
    : '';

$completedAt = !empty($certificate['completed_at'])
    ? date('F d, Y', strtotime((string)$certificate['completed_at']))
    : '';

switch ($certType) {
    case 'general':
        $displayTrack = 'General Certification - All Subjects';
        break;
    case 'course':
        $displayTrack = 'Course Certificate - Full Course Completion';
        break;
    case 'division':
        $displayTrack = 'Division Certificate - Semester Completed';
        break;
    case 'subject':
    default:
        $displayTrack = $subjectName ? $subjectName . ' Subject Certification' : 'Subject Certification';
}

$verifyUrl = '';
if ($certificate) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $verifyUrl = $scheme . '://' . $host . $base . '/verify_certificate.php?code=' . urlencode((string)$certificate['certificate_code']);
}

$subjectCategories = [];
$generalCategoryGroups = [];

if ($certificate) {
    if ($certType === 'subject') {
        $subjectCategories = getSubjectCategories($conn, $subjectId, 18);
    } elseif ($certType === 'general') {
        $generalCategoryGroups = getGeneralCategoryGroups($conn, 3, 8);
    } elseif ($certType === 'division' || $certType === 'course') {
        // Division and course certificates span multiple subjects.
        // Reuse the general grouping (all subjects in the course/division)
        // so the certificate page renders subject summaries.
        $generalCategoryGroups = getGeneralCategoryGroups($conn, 3, 8);
    }
}

$directorName = 'Al Sheikh Hussein Ibrahim';
$directorTitle = 'Director General';
$directorSignature = 'Hussein Ibrahim';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $certificate ? e($title) : 'Certificate Not Found' ?> - MyIslam</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        @page {
            size: A4 landscape;
            margin: 8mm;
        }

        :root {
            --bg: #f5f7f5;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #e2e5e2;

            --brand: #0E4A34;
            --brand-dark: #0A3626;
            --gold: #9C7A2E;
            --gold-soft: #fff7db;
            --danger: #b91c1c;

            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08);
            --shadow: 0 24px 60px rgba(15, 23, 42, 0.14);
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
        }

        body.dark-mode {
            --bg: #0f1720;
            --surface: #18212b;
            --surface-2: #1d2833;
            --ink: #edf2f7;
            --muted: #94a3b8;
            --line: #304150;
            --brand: #4C9172;
            --brand-dark: #6bb590;
            --gold: #C9A85E;
            --gold-soft: rgba(201, 168, 94, 0.14);
            --danger: #ef4444;
        }

        /* The certificate itself always renders on a light/print-safe
           background — a certificate that goes dark in dark mode would be
           a broken PDF/print experience, so only the surrounding chrome
           (header, buttons, not-found state) responds to the theme. */

        body {
            font-family: Georgia, 'Times New Roman', serif;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(22, 101, 52, .12), transparent 28%),
                radial-gradient(circle at bottom right, rgba(201, 154, 46, .16), transparent 32%),
                var(--bg);
            color: var(--ink);
            padding-bottom: 24px;
            transition: background-color .2s, color .2s;
        }

        body.dark-mode {
            background:
                radial-gradient(circle at top left, rgba(76, 145, 114, .10), transparent 28%),
                radial-gradient(circle at bottom right, rgba(201, 168, 94, .10), transparent 32%),
                var(--bg);
        }

        a { text-decoration: none; }

        .app-header {
            position: sticky; top: 0; z-index: 100;
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            box-shadow: var(--shadow-sm);
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 24px; margin-bottom: 18px;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
        }
        .brand-box { font-size: 20px; font-weight: 700; color: var(--brand); }
        .header-actions { display: flex; align-items: center; gap: 10px; }
        .icon-btn {
            height: 40px; min-width: 40px; padding: 0 12px; border-radius: var(--radius-sm);
            background: var(--surface-2); border: 1px solid var(--line);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; color: var(--ink); font-weight: 600; transition: all .2s;
        }
        .icon-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); border-color: var(--gold); color: var(--gold); }

        .page {
            max-width: 1120px;
            margin: 0 auto;
            padding: 0 18px;
        }

        .top-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--brand);
            background: var(--brand);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            transition: all .2s;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); filter: brightness(1.05); }

        .btn.secondary {
            background: var(--surface);
            color: var(--brand);
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            padding: 11px 16px;
        }
        .btn.secondary:hover { border-color: var(--gold); color: var(--gold); }

        .not-found {
            max-width: 720px;
            margin: 90px auto;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 40px;
            text-align: center;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
        }

        .not-found h1 {
            color: var(--danger);
            margin-bottom: 12px;
        }

        .certificate-wrap {
            background: var(--surface);
            border-radius: 26px;
            padding: 14px;
            box-shadow: var(--shadow);
        }

        .certificate {
            position: relative;
            min-height: 690px;
            border: 7px double var(--gold);
            border-radius: 20px;
            padding: 27px 36px;
            overflow: hidden;
            background:
                linear-gradient(135deg, rgba(255, 247, 219, .88), rgba(255, 255, 255, .96) 38%, rgba(231, 243, 235, .86)),
                #fff;
        }

        .certificate::before {
            content: "MyIslam";
            position: absolute;
            inset: auto auto 38px 50%;
            transform: translateX(-50%) rotate(-18deg);
            font-size: 108px;
            font-weight: 900;
            color: rgba(22, 101, 52, .052);
            white-space: nowrap;
            pointer-events: none;
        }

        .cert-header {
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .brand-mark {
            width: 62px;
            height: 62px;
            margin: 0 auto 8px;
            border-radius: 50%;
            background: var(--brand);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 29px;
            border: 4px solid var(--gold);
            box-shadow: 0 10px 24px rgba(22, 101, 52, .22);
        }

        .platform {
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            font-weight: 800;
            color: var(--brand);
            letter-spacing: .16em;
            text-transform: uppercase;
            font-size: 12px;
        }

        .cert-title {
            margin-top: 6px;
            font-size: clamp(30px, 4.4vw, 49px);
            color: var(--brand-dark);
            line-height: 1.02;
        }

        .subtitle {
            margin-top: 6px;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            color: var(--muted);
            font-size: 13px;
        }

        .cert-body {
            position: relative;
            z-index: 1;
            text-align: center;
            margin-top: 20px;
        }

        .presented {
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            color: var(--muted);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .student-name {
            display: inline-block;
            font-size: clamp(36px, 5vw, 59px);
            color: #111827;
            border-bottom: 3px solid var(--gold);
            padding: 0 24px 7px;
            margin-bottom: 13px;
            line-height: 1.06;
            font-weight: 700;
            text-transform: capitalize;
        }

        .achievement {
            max-width: 830px;
            margin: 0 auto;
            font-size: 17px;
            line-height: 1.42;
            color: #374151;
        }

        .track {
            display: inline-block;
            margin-top: 12px;
            padding: 8px 19px;
            border-radius: 10px;
            background: var(--gold-soft);
            border: 1px solid var(--gold);
            color: #7a550f;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            font-weight: 800;
            font-size: 13px;
        }

        .status-pill {
            display: inline-block;
            margin-top: 8px;
            padding: 5px 13px;
            border-radius: 10px;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            font-size: 11px;
            font-weight: 800;
            background: <?= $isActive ? '#d1fae5' : '#fee2e2' ?>;
            color: <?= $isActive ? '#065f46' : '#991b1b' ?>;
        }

        .categories-section {
            position: relative;
            z-index: 1;
            max-width: 920px;
            margin: 15px auto 0;
            background: rgba(255,255,255,.72);
            border: 1px solid rgba(201,154,46,.50);
            border-radius: 10px;
            padding: 11px 14px;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            text-align: left;
        }

        .categories-section h3 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--brand-dark);
            text-align: center;
            margin-bottom: 8px;
        }

        .category-list {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 6px 10px;
            list-style: none;
        }

        .category-list li,
        .category-chip {
            position: relative;
            padding: 5px 8px 5px 20px;
            border-radius: 10px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            font-size: 11px;
            font-weight: 700;
            color: #374151;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .category-list li::before,
        .category-chip::before {
            content: "✓";
            position: absolute;
            left: 8px;
            top: 5px;
            color: var(--brand);
            font-weight: 900;
        }

        .general-categories {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }

        .general-group {
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 10px;
            padding: 8px;
            min-width: 0;
        }

        .general-group strong {
            display: block;
            font-size: 11px;
            color: var(--brand-dark);
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .general-group .category-chip {
            display: block;
            margin-bottom: 4px;
        }

        .no-categories {
            text-align: center;
            font-size: 11px;
            color: var(--muted);
            font-weight: 700;
        }

        .cert-footer {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr 120px 1fr;
            gap: 24px;
            align-items: end;
            margin-top: 17px;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
        }

        .footer-block {
            text-align: center;
        }

        .footer-line {
            border-top: 2px solid #111827;
            padding-top: 7px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
        }

        .footer-line strong {
            color: #111827;
            font-size: 12px;
        }

        .signature-script {
            font-family: "Brush Script MT", "Segoe Script", "Lucida Handwriting", cursive;
            font-size: 25px;
            line-height: 1;
            color: #111827;
            margin-bottom: 7px;
            white-space: nowrap;
            transform: rotate(-2deg);
            display: inline-block;
        }

        .seal {
            width: 94px;
            height: 94px;
            margin: 0 auto;
            border-radius: 50%;
            background:
                radial-gradient(circle, #fff 0 34%, var(--gold-soft) 35% 58%, var(--gold) 59% 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand-dark);
            font-weight: 900;
            text-align: center;
            border: 3px solid var(--brand);
            font-size: 10px;
            line-height: 1.15;
        }

        .meta-card {
            margin-top: 10px;
            background: #ffffffd9;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 9px 12px;
            display: grid;
            grid-template-columns: 1.1fr 2.4fr 0.8fr 0.7fr;
            gap: 10px;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
        }

        .meta-card div {
            color: var(--muted);
            font-size: 10px;
            min-width: 0;
        }

        .meta-card strong {
            display: block;
            color: var(--ink);
            font-size: 11px;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 900px) {
            body { padding: 12px; }
            .certificate { padding: 24px 16px; }
            .category-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .general-categories { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .cert-footer { grid-template-columns: 1fr; }
            .meta-card { grid-template-columns: 1fr; }
            .top-actions { flex-direction: column; align-items: stretch; }
            .btn { width: 100%; }
        }

        @media print {
            html,
            body {
                width: 297mm;
                height: 210mm;
                padding: 0;
                margin: 0;
                background: white;
                overflow: hidden;
            }

            .top-actions { display: none; }
            .app-header { display: none; }

            .page {
                width: 100%;
                max-width: none;
                margin: 0;
            }

            .certificate-wrap {
                box-shadow: none;
                border-radius: 0;
                padding: 0;
                width: 100%;
                height: 100%;
            }

            .certificate {
                width: 100%;
                height: 194mm;
                min-height: 194mm;
                border-radius: 0;
                padding: 15mm 18mm 8mm;
                page-break-after: avoid;
                page-break-inside: avoid;
                overflow: hidden;
            }

            .brand-mark {
                width: 52px;
                height: 52px;
                font-size: 24px;
                margin-bottom: 5px;
            }

            .platform { font-size: 10px; }
            .cert-title { font-size: 35px; }
            .subtitle { font-size: 10px; }
            .cert-body { margin-top: 11px; }

            .student-name {
                font-size: 42px;
                margin-bottom: 8px;
            }

            .achievement {
                font-size: 14px;
                line-height: 1.3;
            }

            .track {
                margin-top: 7px;
                padding: 6px 14px;
                font-size: 10px;
            }

            .status-pill {
                margin-top: 5px;
                font-size: 9px;
            }

            .categories-section {
                margin-top: 9px;
                padding: 7px 9px;
            }

            .categories-section h3 {
                font-size: 9px;
                margin-bottom: 5px;
            }

            .category-list {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 4px 7px;
            }

            .category-list li,
            .category-chip {
                font-size: 8.5px;
                padding: 3px 6px 3px 15px;
            }

            .category-list li::before,
            .category-chip::before {
                left: 6px;
                top: 3px;
            }

            .general-categories {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 5px;
            }

            .general-group { padding: 5px; }

            .general-group strong {
                font-size: 8.5px;
                margin-bottom: 3px;
            }

            .cert-footer {
                margin-top: 9px;
                grid-template-columns: 1fr 84px 1fr;
            }

            .seal {
                width: 70px;
                height: 70px;
                font-size: 8px;
            }

            .signature-script {
                font-size: 20px;
                margin-bottom: 5px;
            }

            .footer-line {
                font-size: 8.5px;
                padding-top: 4px;
            }

            .footer-line strong { font-size: 9.5px; }

            .meta-card {
                margin-top: 6px;
                padding: 6px 8px;
                grid-template-columns: 1.1fr 2.4fr .8fr .7fr;
            }

            .meta-card div { font-size: 7.5px; }
            .meta-card strong { font-size: 8.5px; }
        }
    </style>
</head>
<body>
<header class="app-header">
    <a href="my_certificates.php" class="brand-box">MyIslam</a>
    <div class="header-actions">
        <button type="button" class="icon-btn" id="themeToggle" title="Toggle dark mode">🌙</button>
    </div>
</header>

<div class="page">
    <?php if (!$certificate): ?>
        <div class="not-found">
            <h1>Certificate Not Found</h1>
            <p>The certificate code is invalid, missing, or no longer exists.</p>
            <p style="margin-top:14px;color:var(--muted);">Code checked: <strong><?= e($code ?: 'No code provided') ?></strong></p>
            <div style="margin-top:24px;">
                <a class="btn secondary" href="my_certificates.php">Back to My Certificates</a>
            </div>
        </div>
    <?php else: ?>
        

        <div class="certificate-wrap">
            <section class="certificate">
                <div class="cert-header">
                    <div class="brand-mark">📜</div>
                    <div class="platform">MyIslam Learning Platform</div>
                    <h1 class="cert-title"><?= e($title) ?></h1>
                    <p class="subtitle">This certificate is awarded for verified learning achievement and final certification success.</p>
                </div>

                <div class="cert-body">
                    <p class="presented">Presented to</p>
                    <div class="student-name"><?= e($studentName) ?></div>

                    <p class="achievement">
                        <?= e($summary ?: 'Successfully completed the required certification pathway and passed the final certification examination.') ?>
                    </p>

                    <div class="track"><?= e($displayTrack) ?></div>
                    <br>
                    <span class="status-pill"><?= $isActive ? 'Valid Certificate' : 'Revoked Certificate' ?></span>
                </div>

                <div class="categories-section">
                    <h3>Subject Categories / Skills Gained</h3>

                    <?php if ($certType === 'subject'): ?>
                        <?php if (!empty($subjectCategories)): ?>
                            <ul class="category-list">
                                <?php foreach ($subjectCategories as $category): ?>
                                    <li title="<?= e($category) ?>"><?= e($category) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="no-categories">No active categories found for this subject.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!empty($generalCategoryGroups)): ?>
                            <div class="general-categories">
                                <?php foreach ($generalCategoryGroups as $group): ?>
                                    <div class="general-group">
                                        <strong title="<?= e($group['subject_name']) ?>"><?= e($group['subject_name']) ?></strong>
                                        <?php foreach ($group['categories'] as $category): ?>
                                            <span class="category-chip" title="<?= e($category) ?>"><?= e($category) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-categories">No active subject categories found.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="cert-footer">
                    <div class="footer-block">
                        <div class="footer-line">
                            Completed<br>
                            <strong><?= e($completedAt) ?></strong>
                        </div>
                    </div>

                    <div class="footer-block">
                        <div class="seal">
                            VERIFIED<br>ACHIEVEMENT<br>AWARD
                        </div>
                    </div>

                    <div class="footer-block">
                        <div class="signature-script"><?= e($directorSignature) ?></div>
                        <div class="footer-line">
                            <?= e($directorName) ?><br>
                            <strong><?= e($directorTitle) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="meta-card">
                    <div>
                        Certificate Code
                        <strong><?= e($certificate['certificate_code']) ?></strong>
                    </div>
                    <div>
                        Verification Link
                        <strong><?= e($verifyUrl) ?></strong>
                    </div>
                    <div>
                        Certificate Type
                        <strong><?= e(ucfirst((string)$certType)) ?></strong>
                    </div>
                    <div>
                        Status
                        <strong><?= e(ucfirst((string)$certificate['status'])) ?></strong>
                    </div>
                </div>
            </section>

        </div>
        <div class="top-actions">
            <a class="btn secondary" href="my_certificates.php">← My Certificates</a>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a class="btn secondary" href="<?= e($verifyUrl) ?>" target="_blank">Verify Certificate</a>
                <button class="btn" onclick="window.print()">Print / Save PDF</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function() {
    const body = document.body;
    const themeToggle = document.getElementById('themeToggle');

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        body.classList.add('dark-mode');
        if (themeToggle) themeToggle.textContent = '☀';
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            themeToggle.textContent = isDark ? '☀' : '🌙';
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }
})();
</script>
</body>
</html>