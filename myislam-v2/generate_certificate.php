<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/tcpdf/tcpdf.php';

$code = $_GET['code'] ?? '';
if (!$code) die('Invalid code.');

$conn = db();
$stmt = $conn->prepare("
    SELECT c.*, u.full_name, s.name AS subject_name
    FROM certificates c
    JOIN users u ON u.id = c.user_id
    LEFT JOIN subjects s ON s.id = c.subject_id
    WHERE c.certificate_code = ?
");
$stmt->execute([$code]);
$cert = $stmt->fetch();
if (!$cert) die('Certificate not found.');

$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);
$pdf->AddPage();

// Border image (optional)
$imagePath = __DIR__ . '/assets/certificate_border.png';
if (file_exists($imagePath)) {
    $pdf->Image($imagePath, 0, 0, 297, 210);
}

// Title
$pdf->SetFont('dejavusans', 'B', 36);
$pdf->SetTextColor(22, 101, 52);
$pdf->Cell(0, 30, 'Certificate of Completion', 0, 1, 'C');

// Name
$pdf->SetFont('dejavusans', '', 24);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 20, 'This certifies that', 0, 1, 'C');
$pdf->SetFont('dejavusans', 'B', 30);
$pdf->Cell(0, 20, $cert['full_name'], 0, 1, 'C');

// Course
$item = $cert['subject_name'] ?? 'All Subjects';
$pdf->SetFont('dejavusans', '', 20);
$pdf->Cell(0, 15, 'has successfully completed the', 0, 1, 'C');
$pdf->SetFont('dejavusans', 'B', 22);
$pdf->Cell(0, 15, $item, 0, 1, 'C');

// Date
$pdf->SetFont('dejavusans', '', 16);
$pdf->Cell(0, 20, 'Issued on: ' . date('F d, Y', strtotime($cert['completed_at'])), 0, 1, 'C');

// QR code
$style = ['border' => 2];
$pdf->write2DBarcode(
    'https://yourdomain.com/verify.php?code=' . $cert['certificate_code'],
    'QRCODE,L',
    120, 170, 40, 40,
    $style
);
$pdf->SetFont('dejavusans', '', 9);
$pdf->Text(122, 215, 'Verify: yourdomain.com/verify.php?code=' . $cert['certificate_code']);

$pdf->Output('certificate.pdf', 'I');