<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = $ctx['userId'];

$today = new DateTimeImmutable('today');

$courseId = (int)($_GET['course_id'] ?? 0);

if ($courseId <= 0 || !courseTablesExist($conn)) {
    courseRedirect('courses.php');
}

$stmt = $conn->prepare("
    SELECT en.*, c.title AS course_title, c.division_label, c.division_type
    FROM course_enrollments en
    JOIN courses c ON c.id = en.course_id
    WHERE en.user_id = ? AND en.course_id = ?
      AND en.status IN ('active','completed')
    LIMIT 1
");
$stmt->execute([$userId, $courseId]);
$en = $stmt->fetch();
if (!$en) {
    courseFlash('error', 'You are not enrolled in that course.');
    courseRedirect('courses.php');
}

// All divisions must be passed
$stmt = $conn->prepare("
    SELECT
      (SELECT COUNT(*) FROM course_divisions WHERE course_id = ?) AS total_divisions,
      (SELECT COUNT(*) FROM enrollment_division_history h
         WHERE h.enrollment_id = ? AND h.status = 'passed'
      ) AS passed_divisions
");
$stmt->execute([$courseId, (int)$en['id']]);
$stats = $stmt->fetch();
$totalDivs = (int)($stats['total_divisions'] ?? 0);
$passedDivs = (int)($stats['passed_divisions'] ?? 0);

if ($totalDivs === 0 || $passedDivs < $totalDivs) {
    courseFlash('error', 'A certificate of completion is only available after you pass the entire course.');
    courseRedirect('course.php?course_id=' . $courseId);
}

$stmt = $conn->prepare("
    SELECT d.id, d.number, d.name, d.start_date, d.end_date,
           h.status AS division_status, h.average_grade, h.courses_passed, h.courses_failed,
           h.completed_at
    FROM course_divisions d
    LEFT JOIN enrollment_division_history h
        ON h.division_id = d.id AND h.enrollment_id = ?
    WHERE d.course_id = ?
    ORDER BY d.number ASC
");
$stmt->execute([(int)$en['id'], $courseId]);
$divisions = $stmt->fetchAll();

$gradeSum = 0.0; $gradeCount = 0; $firstStart = null; $lastEnd = null;
foreach ($divisions as $d) {
    if ($d['average_grade'] !== null) { $gradeSum += (float)$d['average_grade']; $gradeCount++; }
    if ($firstStart === null) $firstStart = new DateTimeImmutable((string)$d['start_date']);
    $lastEnd = new DateTimeImmutable((string)$d['end_date']);
}
$overallAvg = $gradeCount > 0 ? round($gradeSum / $gradeCount, 1) : null;

$issued = new DateTimeImmutable('today');
$divisionLabel = (string)($en['division_label'] ?: 'Division');

$studentName = $ctx['user']['name'];
try {
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $fullName = trim((string)$stmt->fetchColumn());
    if ($fullName !== '') $studentName = $fullName;
} catch (PDOException $e) {}

$reference = sprintf('CC-%d-%d', $courseId, (int)$en['id']);

$hijriNumeric = courseHijriShort($issued);
$hijriParts = array_map('trim', explode('/', $hijriNumeric));
$hijriMonths = [
    1 => "Muḥarram", 2 => "Ṣafar", 3 => "Rabīʿ al-Awwal", 4 => "Rabīʿ ath-Thānī",
    5 => "Jumādā al-Ūlā", 6 => "Jumādā ath-Thāniyah", 7 => "Rajab", 8 => "Shaʿbān",
    9 => "Ramaḍān", 10 => "Shawwāl", 11 => "Dhū al-Qaʿdah", 12 => "Dhū al-Ḥijjah",
];
$hijriDateLong = trim(($hijriParts[0] ?? '') . ' ' . ($hijriMonths[(int)($hijriParts[1] ?? 0)] ?? '') . ' ' . ($hijriParts[2] ?? ''));

$verifyUrl = 'https://myislam.academy/verify.php?ref=' . urlencode($reference);
$qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&bgcolor=FFFFFF&color=8a6a25&data=' . urlencode($verifyUrl);

$autoPrint = isset($_GET['print']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Completion — <?php echo e($studentName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cormorant+Garamond:wght@400;500;600;700&family=Dancing+Script:wght@500;700&family=Reem+Kufi:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page { size: A4 portrait; margin: 0; }
        body { background: #1a1a1a; font-family: 'Cormorant Garamond', Georgia, serif; color: #2b2416; padding: 24px 16px; min-height: 100vh; }
        .toolbar { max-width: 830px; margin: 0 auto 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; font-family: system-ui, sans-serif; }
        .toolbar button, .toolbar a { padding: 12px 22px; border-radius: 999px; border: 1px solid #d4b46a; background: linear-gradient(135deg, #d4b46a, #a88435); color: #1a1a1a; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; box-shadow: 0 4px 12px rgba(212, 180, 106, 0.3); }
        .toolbar a { background: transparent; color: #d4b46a; }
        .toolbar p { flex-basis: 100%; font-size: 12px; color: #999; }
        .cert-page { width: 210mm; height: 297mm; margin: 0 auto; background: radial-gradient(ellipse at 50% 0%, #fdfaf0 0%, #f8f1dc 60%, #ede2c4 100%); position: relative; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,.6), 0 0 0 1px rgba(212, 180, 106, .3); }
        .frame-outer { position: absolute; inset: 4mm; pointer-events: none; z-index: 1; }
        .frame-outer svg { width: 100%; height: 100%; }
        .corner-medallion { position: absolute; width: 32mm; height: 32mm; pointer-events: none; z-index: 2; }
        .corner-medallion svg { width: 100%; height: 100%; }
        .corner-medallion.tl { top: 6mm; left: 6mm; }
        .corner-medallion.tr { top: 6mm; right: 6mm; transform: scaleX(-1); }
        .corner-medallion.bl { bottom: 6mm; left: 6mm; transform: scaleY(-1); }
        .corner-medallion.br { bottom: 6mm; right: 6mm; transform: scale(-1, -1); }
        .band { position: absolute; left: 12mm; right: 12mm; height: 12mm; z-index: 2; pointer-events: none; }
        .band.top { top: 12mm; }
        .band.bottom { bottom: 12mm; transform: scaleY(-1); }
        .band svg { width: 100%; height: 100%; }
        .cert-content { position: relative; z-index: 3; padding: 32mm 22mm 30mm; height: 100%; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .emblem { width: 24mm; height: 24mm; margin-bottom: 2mm; display: flex; align-items: center; justify-content: center; position: relative; }
        .emblem img { width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(138, 106, 37, 0.35)); }
        .emblem::before, .emblem::after { content: ""; position: absolute; inset: -3mm; border: 1px solid #a88435; border-radius: 50%; opacity: .4; }
        .emblem::after { inset: -5mm; border-style: dashed; opacity: .3; }
        .academy-ar { font-family: 'Amiri', serif; font-size: 24px; color: #8a6a25; direction: rtl; line-height: 1.2; }
        .academy-en { font-family: 'Reem Kufi', serif; font-size: 12px; letter-spacing: 10px; color: #8a6a25; font-weight: 600; margin-top: 1mm; padding-left: 10px; }
        .bismillah { font-family: 'Amiri', serif; font-size: 20px; color: #0b2545; direction: rtl; margin: 6mm 0 2mm; opacity: .85; }
        h1.cert-title { font-family: 'Cormorant Garamond', serif; font-size: 42px; font-weight: 700; color: #0b2545; letter-spacing: 1px; line-height: 1.1; margin-bottom: 2mm; }
        .title-ornament { display: flex; align-items: center; justify-content: center; gap: 4mm; margin: 3mm 0 5mm; }
        .title-ornament .line { width: 22mm; height: 1px; background: linear-gradient(to right, transparent, #a88435, transparent); }
        .title-ornament .diamond { width: 8px; height: 8px; background: #a88435; transform: rotate(45deg); }
        .certify-line { font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 16px; color: #5a4715; }
        .student-name { font-family: 'Dancing Script', cursive; font-size: 46px; color: #a88435; line-height: 1.15; padding: 2mm 10mm 3mm; margin: 4mm 0 2mm; position: relative; max-width: 100%; overflow-wrap: anywhere; text-shadow: 0 1px 0 rgba(255,255,255,.8), 0 2px 8px rgba(168, 132, 53, 0.25); }
        .student-name::before, .student-name::after { content: ""; position: absolute; bottom: 0; height: 1px; width: 30%; background: linear-gradient(to right, transparent, #a88435, transparent); }
        .student-name::before { left: 0; }
        .student-name::after { right: 0; }
        .cert-body { font-family: 'Cormorant Garamond', serif; font-size: 15px; line-height: 1.7; color: #2b2416; max-width: 155mm; margin: 3mm auto 0; }
        .cert-body strong { color: #0b2545; font-weight: 700; }
        .divisions-table { width: 100%; max-width: 150mm; margin: 5mm auto 3mm; border-collapse: collapse; font-family: 'Cormorant Garamond', serif; font-size: 13px; }
        .divisions-table th { background: rgba(168, 132, 53, 0.12); color: #5a4715; font-weight: 700; padding: 2mm 3mm; text-align: left; border-bottom: 1px solid #a88435; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .divisions-table td { padding: 1.6mm 3mm; border-bottom: 1px dashed rgba(168, 132, 53, 0.35); color: #2b2416; }
        .divisions-table tr:last-child td { border-bottom: none; }
        .divisions-table .grade-pass { color: #0E4A34; font-weight: 700; }
        .divisions-table .date { font-size: 11px; color: #8a6a25; }
        .final-grade-badge { display: inline-flex; align-items: center; gap: 3mm; padding: 3mm 8mm; border-radius: 999px; background: rgba(168, 132, 53, 0.15); border: 1px solid #a88435; color: #5a4715; font-family: 'Reem Kufi', serif; font-weight: 700; margin-top: 4mm; }
        .final-grade-badge .label { font-size: 11px; letter-spacing: 1px; text-transform: uppercase; color: #8a6a25; }
        .final-grade-badge .value { font-size: 20px; color: #0b2545; }
        .bottom-section { margin-top: auto; padding-top: 5mm; width: 100%; display: grid; grid-template-columns: 1fr auto 1fr; gap: 8mm; align-items: end; }
        .sig-block { text-align: center; }
        .sig-block .title { font-family: 'Reem Kufi', serif; font-size: 10px; letter-spacing: 2px; color: #0b2545; text-transform: uppercase; font-weight: 600; margin-bottom: 6mm; }
        .sig-block .signature { font-family: 'Dancing Script', cursive; font-size: 26px; color: #0b2545; line-height: 1; margin-bottom: 1mm; }
        .sig-block .line { border-top: 1px solid #0b2545; margin: 0 auto; width: 42mm; padding-top: 1.5mm; font-family: 'Cormorant Garamond', serif; font-size: 11px; font-style: italic; color: #5a4715; }
        .seal-block { text-align: center; display: flex; flex-direction: column; align-items: center; gap: 2mm; }
        .seal-circle { width: 26mm; height: 26mm; border-radius: 50%; background: radial-gradient(circle at 35% 30%, #f9e8b6 0%, #e6c878 45%, #b8944a 100%); display: flex; align-items: center; justify-content: center; position: relative; box-shadow: inset 0 0 0 1px #a88435, inset 0 0 0 2.5mm #b8944a, inset 0 0 0 3mm #8a6a25, 0 4px 12px rgba(138, 106, 37, 0.4); }
        .seal-circle::before { content: ""; position: absolute; inset: 3.5mm; border-radius: 50%; border: 1px dashed #5a4715; opacity: .5; }
        .seal-inner { font-family: 'Reem Kufi', serif; font-size: 8px; color: #5a4715; font-weight: 700; text-align: center; line-height: 1.2; letter-spacing: 1px; position: relative; z-index: 1; }
        .seal-inner .top { font-family: 'Amiri', serif; font-size: 11px; margin-bottom: 1mm; }
        .seal-inner .mid { font-size: 9px; font-weight: 800; color: #8a6a25; letter-spacing: 1.5px; margin: 1mm 0; }
        .seal-inner .year { font-family: 'Cormorant Garamond', serif; font-size: 12px; font-weight: 700; letter-spacing: 2px; }
        .seal-ar { font-family: 'Amiri', serif; font-size: 16px; color: #8a6a25; direction: rtl; margin-top: 1mm; }
        .ref-block { text-align: right; font-family: 'Cormorant Garamond', serif; font-size: 12px; color: #4b3d1e; line-height: 1.6; }
        .ref-block .label { font-family: 'Reem Kufi', serif; font-size: 9px; letter-spacing: 1.5px; color: #0b2545; text-transform: uppercase; font-weight: 600; }
        .ref-block .value { font-size: 13px; font-weight: 600; color: #5a4715; }
        .ref-block .code { font-family: 'Cormorant Garamond', serif; font-size: 14px; font-weight: 700; color: #8a6a25; letter-spacing: 1px; margin-bottom: 3mm; }
        .ref-block .qr { width: 22mm; height: 22mm; margin-left: auto; margin-top: 2mm; border: 1px solid #a88435; padding: 1.2mm; background: #fff; border-radius: 2px; }
        .ref-block .qr img { width: 100%; height: 100%; display: block; }
        .arabic-footer { position: absolute; bottom: 20mm; left: 0; right: 0; text-align: center; font-family: 'Amiri', serif; font-size: 16px; color: #8a6a25; direction: rtl; opacity: .9; z-index: 3; }
        @media print { body { background: #fff; padding: 0; } .toolbar { display: none; } .cert-page { box-shadow: none; margin: 0; page-break-inside: avoid; } }
    </style>
</head>
<body>

    <div class="toolbar">
        <button type="button" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <a href="course.php?course_id=<?php echo $courseId; ?>">← Back to course</a>
        <p>Tip: choose "Save as PDF" and turn ON "Background graphics".</p>
    </div>

    <div class="cert-page">
        <div class="frame-outer">
            <svg viewBox="0 0 800 1130" preserveAspectRatio="none">
                <defs>
                    <pattern id="arabesque" x="0" y="0" width="40" height="40" patternUnits="userSpaceOnUse">
                        <path d="M20 0 L40 20 L20 40 L0 20 Z" fill="none" stroke="#a88435" stroke-width="0.5" opacity="0.4"/>
                        <circle cx="20" cy="20" r="3" fill="none" stroke="#a88435" stroke-width="0.5" opacity="0.5"/>
                    </pattern>
                </defs>
                <rect x="6" y="6" width="788" height="1118" fill="none" stroke="#8a6a25" stroke-width="3"/>
                <rect x="20" y="20" width="760" height="1090" fill="none" stroke="#a88435" stroke-width="1"/>
                <rect x="10" y="10" width="780" height="1110" fill="url(#arabesque)" opacity="0.35"/>
            </svg>
        </div>

        <?php foreach (['tl', 'tr', 'bl', 'br'] as $pos): ?>
            <div class="corner-medallion <?php echo $pos; ?>">
                <svg viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="30" fill="none" stroke="#a88435" stroke-width="1.5"/>
                    <circle cx="50" cy="50" r="22" fill="none" stroke="#8a6a25" stroke-width="0.8"/>
                    <circle cx="50" cy="50" r="14" fill="none" stroke="#a88435" stroke-width="0.6" stroke-dasharray="2,2"/>
                    <circle cx="50" cy="50" r="6" fill="#a88435" opacity="0.7"/>
                    <?php for ($i = 0; $i < 8; $i++): $angle = $i * 45; ?>
                        <line x1="50" y1="50" x2="<?php echo 50 + 30 * cos(deg2rad($angle)); ?>" y2="<?php echo 50 + 30 * sin(deg2rad($angle)); ?>" stroke="#a88435" stroke-width="0.6" opacity="0.7"/>
                    <?php endfor; ?>
                    <?php for ($i = 0; $i < 12; $i++): $angle = $i * 30; ?>
                        <ellipse cx="<?php echo 50 + 38 * cos(deg2rad($angle)); ?>" cy="<?php echo 50 + 38 * sin(deg2rad($angle)); ?>" rx="3" ry="5" fill="none" stroke="#8a6a25" stroke-width="0.5" opacity="0.6" transform="rotate(<?php echo $angle; ?> <?php echo 50 + 38 * cos(deg2rad($angle)); ?> <?php echo 50 + 38 * sin(deg2rad($angle)); ?>)"/>
                    <?php endfor; ?>
                </svg>
            </div>
        <?php endforeach; ?>

        <div class="band top">
            <svg viewBox="0 0 800 60" preserveAspectRatio="none">
                <defs>
                    <pattern id="palm" x="0" y="0" width="40" height="60" patternUnits="userSpaceOnUse">
                        <path d="M20 5 Q10 20 20 35 Q30 20 20 5 Z" fill="none" stroke="#a88435" stroke-width="0.8"/>
                        <path d="M20 35 Q15 45 20 55 Q25 45 20 35 Z" fill="none" stroke="#a88435" stroke-width="0.8"/>
                        <circle cx="20" cy="30" r="1.5" fill="#8a6a25"/>
                    </pattern>
                </defs>
                <line x1="0" y1="3" x2="800" y2="3" stroke="#8a6a25" stroke-width="0.8"/>
                <line x1="0" y1="57" x2="800" y2="57" stroke="#8a6a25" stroke-width="0.8"/>
                <rect x="0" y="8" width="800" height="44" fill="url(#palm)"/>
            </svg>
        </div>
        <div class="band bottom">
            <svg viewBox="0 0 800 60" preserveAspectRatio="none">
                <defs>
                    <pattern id="palm2" x="0" y="0" width="40" height="60" patternUnits="userSpaceOnUse">
                        <path d="M20 5 Q10 20 20 35 Q30 20 20 5 Z" fill="none" stroke="#a88435" stroke-width="0.8"/>
                        <path d="M20 35 Q15 45 20 55 Q25 45 20 35 Z" fill="none" stroke="#a88435" stroke-width="0.8"/>
                        <circle cx="20" cy="30" r="1.5" fill="#8a6a25"/>
                    </pattern>
                </defs>
                <line x1="0" y1="3" x2="800" y2="3" stroke="#8a6a25" stroke-width="0.8"/>
                <line x1="0" y1="57" x2="800" y2="57" stroke="#8a6a25" stroke-width="0.8"/>
                <rect x="0" y="8" width="800" height="44" fill="url(#palm2)"/>
            </svg>
        </div>

        <div class="cert-content">
            <div class="emblem">
                <img src="includes/logo.png" alt="MyIslam Learning Academy" onerror="this.style.display='none'">
            </div>

            <div class="academy-ar">أكاديمية مَـي إِسْلَام</div>
            <div class="academy-en">MYISLAM ACADEMY</div>

            <div class="bismillah">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</div>

            <h1 class="cert-title">Certificate of Completion</h1>
            <div class="title-ornament">
                <span class="line"></span>
                <span class="diamond"></span>
                <span class="line"></span>
            </div>

            <p class="certify-line">MyIslam Learning Academy hereby certifies that</p>

            <div class="student-name"><?php echo e($studentName); ?></div>

            <p class="cert-body">
                has successfully completed the entire course
                <strong><?php echo e($en['course_title']); ?></strong>,
                from <strong><?php echo e(($firstStart ?? $today)->format('d F Y')); ?></strong>
                to <strong><?php echo e(($lastEnd ?? $today)->format('d F Y')); ?></strong>,
                passing all <?php echo (int)$totalDivs; ?> <?php echo e(strtolower($divisionLabel)); ?><?php echo $totalDivs === 1 ? '' : 's'; ?>.
            </p>

            <?php if ($divisions): ?>
                <table class="divisions-table">
                    <thead>
                        <tr>
                            <th><?php echo e($divisionLabel); ?></th>
                            <th style="text-align: center;">Period</th>
                            <th style="text-align: right;">Average</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($divisions as $d):
                        $avg = $d['average_grade'] !== null ? (float)$d['average_grade'] : null;
                    ?>
                        <tr>
                            <td><strong><?php echo e($d['name']); ?></strong></td>
                            <td style="text-align: center;" class="date">
                                <?php echo e(date('M Y', strtotime((string)$d['start_date']))); ?> –
                                <?php echo e(date('M Y', strtotime((string)$d['end_date']))); ?>
                            </td>
                            <td style="text-align: right;" class="grade-pass">
                                <?php echo $avg !== null ? (int)round($avg) . '%' : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($overallAvg !== null): ?>
                <div class="final-grade-badge">
                    <span class="label">Overall Average</span>
                    <span class="value"><?php echo (int)round($overallAvg); ?>%</span>
                </div>
            <?php endif; ?>

            <div class="bottom-section">
                <div class="sig-block">
                    <div class="title">Director of Education</div>
                    <div class="signature">MyIslam</div>
                    <div class="line">Authorized Signature</div>
                </div>

                <div class="seal-block">
                    <div class="seal-circle">
                        <div class="seal-inner">
                            <div class="top">ﷲ</div>
                            <div class="mid">MYISLAM</div>
                            <div class="year"><?php echo $issued->format('Y'); ?></div>
                        </div>
                    </div>
                    <div class="seal-ar">الله أكبر</div>
                </div>

                <div class="ref-block">
                    <div class="label">Date of Issuance</div>
                    <div class="value"><?php echo e($issued->format('d F Y')); ?></div>
                    <div style="font-style:italic;font-size:11px;color:#8a6a25;margin-bottom:3mm;"><?php echo e($hijriDateLong); ?> AH</div>

                    <div class="label">Certificate Number</div>
                    <div class="code"><?php echo e($reference); ?></div>

                    <div class="qr"><img src="<?php echo e($qrSrc); ?>" alt="Verify"></div>
                </div>
            </div>
        </div>

        <div class="arabic-footer">وَمَا تَوْفِيقِي إِلَّا بِاللَّهِ عَلَيْهِ تَوَكَّلْتُ وَإِلَيْهِ أُنِيبُ</div>
    </div>

    <?php if ($autoPrint): ?>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 700); });</script>
    <?php endif; ?>
</body>
</html>