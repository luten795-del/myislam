<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/certificate_functions.php';

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$conn = db();

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
$certificate = null;

if ($code !== '') {
    $certificate = getCertificateByCode($conn, $code);
}

$status = 'not_found';

if ($certificate) {
    $status = (($certificate['status'] ?? '') === 'active') ? 'valid' : 'revoked';
}

$studentName = $certificate['full_name'] ?? '';
$studentName = mb_strtoupper($certificate['full_name'] ?? '');
$title = $certificate['title'] ?? '';
$certType = $certificate['certification_type'] ?? '';
$subjectName = $certificate['subject_name'] ?? '';
$issuedAt = !empty($certificate['issued_at']) ? date('F d, Y', strtotime((string)$certificate['issued_at'])) : '';
$completedAt = !empty($certificate['completed_at']) ? date('F d, Y', strtotime((string)$certificate['completed_at'])) : '';

$track = $certType === 'general'
    ? 'General Certification - All Subjects'
    : ($subjectName ? $subjectName . ' Subject Certification' : 'Subject Certification');

$viewUrl = '';
if ($certificate) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $viewUrl = $scheme . '://' . $host . $base . '/certificate_view.php?code=' . urlencode((string)$certificate['certificate_code']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Certificate - MyIslam</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #f5f7f5;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --line: #e5e7eb;
            --text: #1f2937;
            --muted: #6b7280;
            --brand: #166534;
            --brand-dark: #14532d;
            --success: #059669;
            --danger: #dc2626;
            --warning: #d97706;
            --shadow: 0 20px 50px rgba(15, 23, 42, .12);
        }

        body {
            min-height: 100vh;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(22, 101, 52, .12), transparent 30%),
                radial-gradient(circle at bottom right, rgba(217, 119, 6, .12), transparent 34%),
                var(--bg);
            color: var(--text);
            padding: 32px 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .verify-card {
            width: min(760px, 100%);
            background: transparent;
            border: 1px solid green;
            border-radius: 10px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            padding: 34px 34px 28px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: white;
            text-align: center;
        }

        .logo {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: rgba(255,255,255,.14);
            border: 3px solid green;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 34px;
        }

        .header h1 {
            font-size: clamp(26px, 5vw, 40px);
            margin-bottom: 8px;
        }

        .header p {
            opacity: .88;
        }

        .body {
            padding: 32px;
        }

        .status-box {
            border-radius: 10px;
            padding: 22px;
            text-align: center;
            border: 1px solid green;
            margin-bottom: 24px;
        }

        .status-valid {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }

        .status-revoked {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .status-not-found {
            background: #fffbeb;
            border-color: #fed7aa;
            color: #92400e;
        }

        .status-icon {
            font-size: 52px;
            margin-bottom: 10px;
        }

        .status-box h2 {
            font-size: 24px;
            margin-bottom: 6px;
        }

        .details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 20px;
        }

        .detail {
            background: var(--surface-2);
            border: 1px solid  green;
            border-radius: 10px;
            padding: 16px;
        }

        .detail span {
            display: block;
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .detail strong {
            display: block;
            font-size: 16px;
            overflow-wrap: anywhere;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 26px;
        }

        .btn {
            flex: 1;
            min-width: 180px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 18px;
            border-radius: 10px;
            border: 1px solid green;
            background: var(--brand);
            color: white;
            text-decoration: none;
            font-weight: 800;
            cursor: pointer;
        }

        .btn.secondary {
            background: white;
            color: var(--brand);
        }

        .search-box {
            margin-top: 24px;
            background: var(--surface-2);
            border: 1px solid green;
            border-radius: 10px;
            padding: 18px;
        }

        .search-box label {
            display: block;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .search-row {
            display: flex;
            gap: 10px;
        }

        .search-row input {
            flex: 1;
            min-width: 0;
            border: 1px solid green;
            border-radius: 10px;
            padding: 13px 16px;
            font: inherit;
        }

        .search-row button {
            border: none;
            white-space: nowrap;
        }

        @media (max-width: 640px) {
            body {
                align-items: flex-start;
                padding: 18px 12px;
            }

            .header,
            .body {
                padding: 24px 18px;
            }

            .details {
                grid-template-columns: 1fr;
            }

            .search-row {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .verify-card {
                box-shadow: none;
                border-radius: 0;
                border: none;
            }

            .actions,
            .search-box {
                display: none;
            }
        }
    </style>
</head>
<body>
    <main class="verify-card">
        <section class="header">
            <div class="logo">📜</div>
            <h1>Certificate Verification</h1>
            <p>MyIslam Learning Platform</p>
        </section>

        <section class="body">
            <?php if ($status === 'valid'): ?>
                <div class="status-box status-valid">
                    <div class="status-icon">✅</div>
                    <h2>Valid Certificate</h2>
                    <p>This certificate is active and verified by MyIslam.</p>
                </div>

                <div class="details">
                    <div class="detail">
                        <span>Certificate Holder</span>
                        <strong><?= e($studentName) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Certificate Title</span>
                        <strong><?= e($title) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Track</span>
                        <strong><?= e($track) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Certificate Code</span>
                        <strong><?= e($certificate['certificate_code']) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Completed On</span>
                        <strong><?= e($completedAt) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Issued On</span>
                        <strong><?= e($issuedAt) ?></strong>
                    </div>
                </div>

                <div class="actions">
                    <a class="btn" href="<?= e($viewUrl) ?>">View Certificate</a>
                    <button class="btn secondary" onclick="window.print()">Print Verification</button>
                </div>

            <?php elseif ($status === 'revoked'): ?>
                <div class="status-box status-revoked">
                    <div class="status-icon">⛔</div>
                    <h2>Revoked Certificate</h2>
                    <p>This certificate exists, but it is no longer active.</p>
                </div>

                <div class="details">
                    <div class="detail">
                        <span>Certificate Holder</span>
                        <strong><?= e($studentName) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Certificate Code</span>
                        <strong><?= e($certificate['certificate_code']) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Certificate Title</span>
                        <strong><?= e($title) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Status</span>
                        <strong>Revoked</strong>
                    </div>
                </div>

            <?php else: ?>
                <div class="status-box status-not-found">
                    <div class="status-icon">⚠️</div>
                    <h2>Certificate Not Found</h2>
                    <p>The certificate code is missing, invalid, or not registered in the system.</p>
                    <?php if ($code !== ''): ?>
                        <p style="margin-top:8px;">Code checked: <strong><?= e($code) ?></strong></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form class="search-box" method="GET" action="verify_certificate.php">
                <label for="code">Verify another certificate</label>
                <div class="search-row">
                    <input type="text" id="code" name="code" placeholder="Enter certificate code" value="<?= e($code) ?>" required>
                    <button class="btn" type="submit">Verify</button>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
