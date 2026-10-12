<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/course_layout.php';

$ctx = courseShellBoot();
/** @var PDO $conn */
$conn = $ctx['conn'];
$userId = $ctx['userId'];

$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0 || !courseTablesExist($conn)) {
    courseRedirect('courses.php');
}

$row = courseLoadEnrolledCohort($conn, $cohortId, $userId);
if (!$row || $row['cohort_status'] === 'cancelled') {
    courseFlash('error', 'A certificate is not available for that course.');
    courseRedirect('courses.php');
}

// The name printed on the certificate: the profile's full name when it is
// filled in, otherwise the username. Set to false to always print the username.
const CERTIFICATE_USE_FULL_NAME = true;

$studentName = $ctx['user']['name'];
if (CERTIFICATE_USE_FULL_NAME) {
    try {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $fullName = trim((string)$stmt->fetchColumn());
        if ($fullName !== '') {
            $studentName = $fullName;
        }
    } catch (PDOException $e) {
        // keep the username
    }
}

$duration = (int)$row['duration_days'];
$start = new DateTimeImmutable((string)$row['start_date']);
$end = courseCohortEnd((string)$row['start_date'], $duration);
$issued = new DateTimeImmutable('today');
$reference = sprintf('CE-%d-%d-%d', (int)$row['cohort_id'], $userId, (int)$row['enrollment_id']);
$autoPrint = isset($_GET['print']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Enrollment — <?php echo e($studentName); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page { size: A4 landscape; margin: 0; }
        body { background: #e9edea; font-family: Georgia, 'Times New Roman', serif; color: #1f2937; padding: 20px; }
        .toolbar { max-width: 1050px; margin: 0 auto 14px; display: flex; gap: 10px; flex-wrap: wrap; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .toolbar button, .toolbar a { padding: 10px 18px; border-radius: 999px; border: 1px solid #0E4A34; background: #0E4A34; color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; }
        .toolbar a { background: #fff; color: #0E4A34; }
        .toolbar p { flex-basis: 100%; font-size: 12px; color: #4b5563; }

        .cert { position: relative; max-width: 1050px; margin: 0 auto; aspect-ratio: 297 / 210; background: #fffdf7; border: 10px solid #0E4A34; padding: 14px; box-shadow: 0 10px 30px rgba(0,0,0,.2); }
        .cert-inner { height: 100%; border: 2px solid #9C7A2E; display: flex; flex-direction: column; align-items: center; justify-content: space-between; text-align: center; padding: 3.2% 6%; background: radial-gradient(circle at 50% 0%, rgba(156,122,46,.08), transparent 60%); }
        .cert img.logo { height: clamp(48px, 8vw, 84px); width: clamp(48px, 8vw, 84px); border-radius: 50%; border: 2px solid #0E4A34; object-fit: cover; padding: 2px; }
        .org { font-size: clamp(12px, 1.7vw, 18px); letter-spacing: 3px; text-transform: uppercase; color: #0E4A34; font-weight: 700; }
        .bismillah { font-size: clamp(14px, 2vw, 22px); color: #9C7A2E; }
        h1 { font-size: clamp(26px, 5vw, 54px); color: #0E4A34; letter-spacing: 1px; }
        .lead { font-size: clamp(12px, 1.7vw, 18px); color: #4b5563; font-style: italic; }
        .name { font-size: clamp(24px, 4.4vw, 46px); font-weight: 700; color: #1f2937; border-bottom: 2px solid #9C7A2E; padding: 0 3%; min-width: 50%; overflow-wrap: anywhere; }
        .body { font-size: clamp(12px, 1.7vw, 18px); line-height: 1.7; max-width: 82%; }
        .body strong { color: #0E4A34; }
        .meta { width: 100%; display: flex; justify-content: space-between; align-items: flex-end; font-size: clamp(10px, 1.3vw, 14px); color: #4b5563; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .sig { text-align: center; min-width: 22%; }
        .sig .line { border-top: 1px solid #1f2937; margin-bottom: 4px; }
        .seal { width: clamp(46px, 7vw, 78px); height: clamp(46px, 7vw, 78px); border-radius: 50%; border: 3px double #9C7A2E; background: rgba(156,122,46,.12); display: flex; align-items: center; justify-content: center; color: #9C7A2E; font-weight: 700; font-size: clamp(9px, 1.2vw, 13px); text-align: center; line-height: 1.1; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .cert { max-width: none; width: 100vw; height: 100vh; aspect-ratio: auto; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <a href="course.php?cohort_id=<?php echo (int)$cohortId; ?>">← Back to course</a>
        <p>Tip: in the print window choose “Save as PDF” as the destination, and turn on “Background graphics” for the best result. Is your name wrong? Update your profile and open this page again.</p>
    </div>

    <div class="cert">
        <div class="cert-inner">
            <div>
                <img class="logo" src="includes/logo.png" alt="" onerror="this.style.display='none'">
                <div class="org">MyIslam Learning Academy</div>
            </div>

            <div>
                <div class="bismillah">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</div>
                <h1>Certificate of Enrollment</h1>
                <p class="lead">This is to certify that</p>
            </div>

            <div class="name"><?php echo e($studentName); ?></div>

            <p class="body">
                is enrolled in the course <strong><?php echo e($row['title']); ?></strong>
                (<?php echo e($row['cohort_name']); ?>), with a study period from
                <strong><?php echo e($start->format('d F Y')); ?></strong> (<?php echo e(courseHijriShort($start)); ?> AH)
                to <strong><?php echo e($end->format('d F Y')); ?></strong> (<?php echo e(courseHijriShort($end)); ?> AH).
                May Allah grant success and benefit in knowledge.
            </p>

            <div class="meta">
                <div class="sig">
                    <div><?php echo e($issued->format('d F Y')); ?> · <?php echo e(courseHijriShort($issued)); ?> AH</div>
                    <div class="line"></div>
                    Date of issue
                </div>
                <div class="seal">MyIslam<br>Academy</div>
                <div class="sig">
                    <div>&nbsp;</div>
                    <div class="line"></div>
                    Ref. <?php echo e($reference); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($autoPrint): ?>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
    <?php endif; ?>
</body>
</html>