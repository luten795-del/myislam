<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/includes/translations.php';
require_once __DIR__ . '/config/database.php';

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$conn = db();

// Fetch user's tickets
$stmt = $conn->prepare("
    SELECT * FROM support_tickets 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$tickets = $stmt->fetchAll();

// If viewing a single ticket (optional modal detail)
$selectedTicket = null;
if (isset($_GET['id'])) {
    $ticketId = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT * FROM support_tickets 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$ticketId, $userId]);
    $selectedTicket = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Support Tickets – MyIslam</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{
            --primary:#166534;--primary-dark:#14532d;--primary-light:#22c55e;--primary-soft:#e7f3eb;
            --accent:#f59e0b;--danger:#dc2626;--success:#10b981;--text:#1f2937;--text-light:#6b7280;
            --bg:#f9fafb;--surface:#ffffff;--surface-2:#f3f4f6;--border:#e5e7eb;
            --shadow-sm:0 1px 2px rgba(0,0,0,0.05);--shadow-md:0 6px 16px rgba(0,0,0,0.08);
            --radius-sm:10px;--radius-md:14px;--radius-lg:20px;
        }
        body{
            font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);
            min-height:100vh;line-height:1.6;padding:20px 16px;
        }
        .container{max-width:900px;margin:0 auto}
        .page-header{
            display:flex;align-items:center;gap:16px;margin-bottom:24px;flex-wrap:wrap;
        }
        .back-link{
            display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--text-light);
            font-weight:500;font-size:14px;transition:color 0.2s;
        }
        .back-link:hover{color:var(--primary)}
        .page-title{font-size:28px;font-weight:700;color:var(--text);letter-spacing:-0.01em}
        
        .ticket-list{display:grid;gap:16px}
        .ticket-card{
            background:var(--surface);border-radius:var(--radius-md);padding:18px 20px;
            border:1px solid var(--border);box-shadow:var(--shadow-sm);
        }
        .ticket-header{
            display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;
        }
        .ticket-subject{font-size:18px;font-weight:700}
        .ticket-status{
            padding:4px 12px;border-radius:40px;font-size:12px;font-weight:600;background:var(--surface-2);
        }
        .ticket-status.open{background:#fef3c7;color:#92400e}
        .ticket-status.in_progress{background:#dbeafe;color:#1e40af}
        .ticket-status.resolved{background:#d1fae5;color:#065f46}
        .ticket-status.closed{background:#f3f4f6;color:#4b5563}
        .ticket-meta{
            display:flex;gap:20px;font-size:13px;color:var(--text-light);margin-bottom:12px;
        }
        .ticket-message{
            background:var(--surface-2);padding:14px 16px;border-radius:var(--radius-sm);
            margin-bottom:16px;font-size:14px;
        }
        .ticket-response{
            border-left:4px solid var(--primary);background:var(--primary-soft);
            padding:14px 16px;border-radius:0 var(--radius-sm) var(--radius-sm) 0;
            margin-top:12px;
        }
        .response-header{font-weight:600;margin-bottom:8px;color:var(--primary-dark)}
        .empty-state{text-align:center;padding:60px 20px;color:var(--text-light)}
        .empty-state i{font-size:48px;margin-bottom:16px;opacity:0.5}
        .btn{
            display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:40px;
            font-weight:600;font-size:14px;text-decoration:none;background:var(--primary);
            color:#fff;box-shadow:0 4px 10px rgba(22,101,52,0.2);border:none;cursor:pointer;
            transition:all 0.2s;
        }
        .btn-secondary{background:var(--surface-2);color:var(--text);border:1px solid var(--border);box-shadow:none}
        .btn:hover{transform:translateY(-2px)}
        .actions{margin-top:16px;display:flex;gap:12px}
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <a href="help_center.php" class="back-link"><i class="fas fa-arrow-left"></i> Help Center</a>
        <h1 class="page-title"><i class="fas fa-ticket-alt" style="color:var(--primary);"></i> My Support Tickets</h1>
    </div>
    
    <?php if (empty($tickets)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No tickets submitted yet</h3>
            <p>When you need help, submit a ticket from the Help Center.</p>
            <div style="margin-top:24px;">
                <a href="help_center.php" class="btn">Go to Help Center</a>
            </div>
        </div>
    <?php else: ?>
        <div class="ticket-list">
            <?php foreach ($tickets as $ticket): ?>
                <div class="ticket-card">
                    <div class="ticket-header">
                        <span class="ticket-subject">#<?= $ticket['id'] ?> – <?= e($ticket['subject']) ?></span>
                        <span class="ticket-status <?= $ticket['status'] ?>"><?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?></span>
                    </div>
                    <div class="ticket-meta">
                        <span><i class="far fa-calendar-alt"></i> Submitted: <?= date('M d, Y h:i A', strtotime($ticket['created_at'])) ?></span>
                        <span><i class="fas fa-flag"></i> Priority: <?= ucfirst($ticket['priority']) ?></span>
                    </div>
                    <div class="ticket-message">
                        <strong>Your message:</strong><br>
                        <?= nl2br(e($ticket['message'])) ?>
                    </div>
                    <?php if (!empty($ticket['admin_response'])): ?>
                        <div class="ticket-response">
                            <div class="response-header"><i class="fas fa-reply"></i> Admin Response (<?= date('M d, Y', strtotime($ticket['responded_at'] ?? '')) ?>)</div>
                            <?= nl2br(e($ticket['admin_response'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="actions">
            <a href="help_center.php" class="btn btn-secondary"><i class="fas fa-plus"></i> Submit New Ticket</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>